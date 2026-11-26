<?php

namespace Tests\Feature\WhatsappPersonal;

use App\Models\Admin;
use App\Models\WhatsappConfig;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\PanelAgent\AgentePanelTestHelpers;
use Tests\TestCase;

/**
 * Lo compartido por las pruebas del número PERSONAL de WhatsApp en coexistencia (misión
 * wa-personal-coexistencia, 24/11/2026).
 *
 * El número personal es el WhatsApp Business App de Lucas, conectado a Kapso en la misma WABA del
 * institucional. Estas pruebas fijan el contrato de lo que el admin tiene que hacer con él:
 *
 *   - leer lo que entra (y lo que Lucas escribe desde la app: el «eco») SIN crear leads, tickets ni
 *     respuestas automáticas;
 *   - enviar desde ese número, nunca desde el institucional;
 *   - exponerlo únicamente a un agente (`es_agente = true`) bajo `api/admin/whatsapp-personal/*`.
 *
 * Reglas que valen para todas las pruebas de la carpeta:
 *
 * 1. **Nada sale a la red.** `Http::fake()` en el `setUp()` con un comodín que devuelve 200 vacío; cada
 *    prueba antepone el stub específico que necesita.
 * 2. **Las tablas nuevas se leen con `DB::table()`**, no con los modelos: se prueba el contrato de la
 *    TABLA (columnas del plan), no la forma del modelo Eloquent.
 * 3. **El propósito se escribe como literal `'personal'`** en los helpers de fixture, y sólo las pruebas
 *    de config (CA1) usan la constante: así un test falla por el comportamiento que falta y no porque el
 *    fixture no compila.
 * 4. **`DatabaseTransactions`**: nada de lo que se cree queda en la base. Ninguna prueba ejecuta DDL
 *    (en MySQL un DDL hace commit implícito y se llevaría puesto el aislamiento).
 */
abstract class BaseDelNumeroPersonal extends TestCase
{
    use DatabaseTransactions;
    use AgentePanelTestHelpers;

    /** `phone_number_id` del número institucional. */
    const PNI_PRINCIPAL = '1234567890';

    /** `phone_number_id` del número personal. */
    const PNI_PERSONAL = '3444622139001';

    /** Secreto con el que Kapso firma los webhooks del institucional. */
    const SECRETO_PRINCIPAL = 'secreto-del-principal-de-prueba';

    /** Secreto con el que Kapso firma los webhooks del personal. Distinto a propósito. */
    const SECRETO_PERSONAL = 'secreto-del-personal-de-prueba';

    /** Clave de Kapso del proyecto: la misma para los dos números (misma WABA). */
    const CLAVE_KAPSO = 'clave-kapso-del-proyecto-de-prueba';

    /** WABA compartida. */
    const WABA_ID = 'waba-de-prueba-987';

    /** Teléfono de un cliente argentino, ya normalizado. */
    const TEL_CLIENTE = '+5493412345678';

    /** Mismo teléfono tal como lo manda WhatsApp (sin `+`). */
    const TEL_CLIENTE_WA = '5493412345678';

    /** Número del negocio (el 3444622139) tal como figura en `message.from` de un eco. */
    const TEL_NEGOCIO_WA = '5493444622139';

    /** @var Admin|null Agente con el que se hacen los pedidos. */
    protected $agente = null;

    /** @var string|null Token Sanctum del agente. */
    protected $token_agente = null;

    /**
     * Nada sale a la red y las filas de `whatsapp_config` de la base de testing arrancan vacías
     * (dentro de la transacción) para que `getActive()` sea determinista.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakear_http();
        Queue::fake();
        \Illuminate\Support\Facades\Cache::flush();

        WhatsappConfig::query()->delete();
    }

    /**
     * Deja el reloj como estaba.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Fakea HTTP descartando lo que hubiera de antes (el fake acumula y gana el PRIMER stub que
     * matchea, así que se cambia la fábrica entera). El comodín va siempre y va último.
     *
     * @param array<string, mixed> $stubs Stubs por patrón de URL, en orden de prioridad.
     *
     * @return void
     */
    protected function fakear_http(array $stubs = []): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());

        if (! array_key_exists('*', $stubs)) {
            $stubs['*'] = Http::response([], 200);
        }

        Http::fake($stubs);
    }

    /**
     * URL de envío de Kapso para un `phone_number_id`.
     *
     * @param string $phone_number_id Número por el que sale.
     *
     * @return string
     */
    protected function url_de_envio(string $phone_number_id): string
    {
        return 'https://api.kapso.ai/meta/whatsapp/v24.0/' . $phone_number_id . '/messages';
    }

    /**
     * Número institucional completo, con la clave y el WABA compartidos.
     *
     * @param array<string, mixed> $campos Campos a pisar.
     *
     * @return WhatsappConfig
     */
    protected function crear_principal(array $campos = []): WhatsappConfig
    {
        $config = new WhatsappConfig();
        $config->forceFill(array_merge([
            'proposito'       => 'principal',
            'kapso_api_key'   => self::CLAVE_KAPSO,
            'phone_number_id' => self::PNI_PRINCIPAL,
            'waba_id'         => self::WABA_ID,
            'webhook_secret'  => self::SECRETO_PRINCIPAL,
            'is_active'       => true,
            'test_mode'       => false,
        ], $campos));
        $config->save();

        return $config;
    }

    /**
     * Número personal completo y listo (clave, número y secreto del webhook).
     *
     * @param array<string, mixed> $campos Campos a pisar: `['webhook_secret' => '']` es «sin registrar el
     *                                     webhook», `['is_active' => false]` es «apagado».
     *
     * @return WhatsappConfig
     */
    protected function crear_personal(array $campos = []): WhatsappConfig
    {
        $config = new WhatsappConfig();
        $config->forceFill(array_merge([
            'proposito'       => 'personal',
            'kapso_api_key'   => self::CLAVE_KAPSO,
            'phone_number_id' => self::PNI_PERSONAL,
            'waba_id'         => self::WABA_ID,
            'webhook_secret'  => self::SECRETO_PERSONAL,
            'display_phone'   => '+54 9 3444 62-2139',
            'is_active'       => true,
            'test_mode'       => false,
        ], $campos));
        $config->save();

        return $config;
    }

    /**
     * Crea el agente y su token, y los deja en `$this->agente` / `$this->token_agente`.
     *
     * @return string Token en texto plano.
     */
    protected function iniciar_agente(): string
    {
        $this->agente       = $this->crear_agente();
        $this->token_agente = $this->token_de($this->agente);

        return $this->token_agente;
    }

    /**
     * Pedido al panel como el agente (lo crea la primera vez).
     *
     * @param string               $metodo GET, POST…
     * @param string               $uri    Ruta completa.
     * @param array<string, mixed> $datos  Cuerpo o query.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function como_agente(string $metodo, string $uri, array $datos = [])
    {
        if ($this->token_agente === null) {
            $this->iniciar_agente();
        }

        return $this->pedir_como($this->token_agente, $metodo, $uri, $datos);
    }

    /**
     * `POST whatsapp-personal/enviar` como el agente, resolviendo el freno `terceros` del guard: el
     * primer pedido vuelve 428 con el código de confirmación y se repite el MISMO pedido con
     * `X-Agente-Confirmar`. Es lo que hace el MCP; el controlador es lo que se está midiendo.
     *
     * @param array<string, mixed> $cuerpo Cuerpo del pedido.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function enviar(array $cuerpo)
    {
        if ($this->token_agente === null) {
            $this->iniciar_agente();
        }

        $uri       = '/api/admin/whatsapp-personal/enviar';
        $respuesta = $this->pedir_como($this->token_agente, 'POST', $uri, $cuerpo);

        if ($respuesta->getStatusCode() === 428 && $respuesta->json('codigo_confirmacion')) {
            return $this->pedir_como(
                $this->token_agente,
                'POST',
                $uri,
                $cuerpo,
                ['X-Agente-Confirmar' => $respuesta->json('codigo_confirmacion')]
            );
        }

        return $respuesta;
    }

    /**
     * Cuerpo válido de un envío real de texto.
     *
     * @param array<string, mixed> $campos Campos a pisar.
     *
     * @return array<string, mixed>
     */
    protected function cuerpo_de_envio(array $campos = []): array
    {
        return array_merge([
            'telefono'         => '3412345678',
            'texto'            => 'Hola, te escribo desde mi número',
            'dry_run'          => false,
            'confirm_telefono' => '5678',
            'op'               => 'op-prueba-000001',
        ], $campos);
    }

    /**
     * Inserta un mensaje en `whatsapp_personal_messages` y mantiene la fila del chat al día.
     *
     * @param array<string, mixed> $campos Columnas a pisar. `sent_at` acepta Carbon o string.
     *
     * @return int Id del mensaje.
     */
    protected function sembrar_mensaje(array $campos = []): int
    {
        static $secuencia = 0;
        $secuencia++;

        $fila = array_merge([
            'phone_number_id'     => self::PNI_PERSONAL,
            'chat_telefono'       => self::TEL_CLIENTE,
            'direccion'           => 'in',
            'origen'              => 'cloud_api',
            'whatsapp_message_id' => 'wamid.sembrado.' . $secuencia . '.' . uniqid(),
            'tipo'                => 'text',
            'cuerpo'              => 'Hola, ¿tenés precio de la amoladora?',
            'estado'              => 'recibido',
            'sent_at'             => now()->subMinutes(30),
        ], $campos);

        $sent_at = $fila['sent_at'] instanceof Carbon ? $fila['sent_at'] : Carbon::parse($fila['sent_at']);

        $fila['sent_at']    = $sent_at->toDateTimeString();
        $fila['created_at'] = isset($campos['created_at']) ? $campos['created_at'] : $sent_at->toDateTimeString();
        $fila['updated_at'] = isset($campos['updated_at']) ? $campos['updated_at'] : $sent_at->toDateTimeString();

        $id = (int) DB::table('whatsapp_personal_messages')->insertGetId($fila);

        $chat = DB::table('whatsapp_personal_chats')->where('chat_telefono', $fila['chat_telefono'])->first();
        if ($chat === null) {
            DB::table('whatsapp_personal_chats')->insert([
                'chat_telefono'   => $fila['chat_telefono'],
                'contact_name'    => isset($campos['contact_name']) ? $campos['contact_name'] : null,
                'last_message_at' => $sent_at->toDateTimeString(),
                'last_inbound_at' => $fila['direccion'] === 'in' ? $sent_at->toDateTimeString() : null,
                'created_at'      => $sent_at->toDateTimeString(),
                'updated_at'      => $sent_at->toDateTimeString(),
            ]);

            return $id;
        }

        $cambios = [];
        if ($chat->last_message_at === null || Carbon::parse($chat->last_message_at)->lt($sent_at)) {
            $cambios['last_message_at'] = $sent_at->toDateTimeString();
        }
        if ($fila['direccion'] === 'in'
            && ($chat->last_inbound_at === null || Carbon::parse($chat->last_inbound_at)->lt($sent_at))) {
            $cambios['last_inbound_at'] = $sent_at->toDateTimeString();
        }
        if ($cambios !== []) {
            DB::table('whatsapp_personal_chats')->where('id', $chat->id)->update($cambios);
        }

        return $id;
    }

    /**
     * Foto de cuántas filas tiene cada tabla de la base, salvo las del número personal y las que no son
     * de negocio. Comparar dos fotos es la prueba más fuerte de «no tocó nada más»: no depende de que
     * alguien se acuerde de listar la tabla que se ensució.
     *
     * @return array<string, int> Tabla => cantidad de filas.
     */
    protected function foto_de_la_base(): array
    {
        $excluidas = [
            'whatsapp_personal_messages',
            'whatsapp_personal_chats',
            'migrations',
            'cache',
            'cache_locks',
            'sessions',
        ];

        $foto = [];
        foreach (DB::select('SHOW TABLES') as $fila) {
            $tabla = (string) array_values((array) $fila)[0];
            if (in_array($tabla, $excluidas, true)) {
                continue;
            }
            $foto[$tabla] = (int) DB::table($tabla)->count();
        }

        return $foto;
    }

    /**
     * Cuántos pedidos HTTP se hicieron desde que se armó el fake.
     *
     * @return int
     */
    protected function pedidos_http(): int
    {
        return count(Http::recorded());
    }

    // ---------------------------------------------------------------------------------------------
    // Webhook
    // ---------------------------------------------------------------------------------------------

    /**
     * Pega al webhook real con el body crudo firmado igual que lo firma Kapso (HMAC-SHA256 del body).
     *
     * @param array<string, mixed> $payload Cuerpo del webhook.
     * @param string               $secreto Secreto con el que se firma.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function postear_al_webhook(array $payload, string $secreto = self::SECRETO_PERSONAL)
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/webhook/whatsapp', [], [], [], [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $body, $secreto),
        ], $body);
    }

    /**
     * Payload de Kapso con la FORMA documentada (event-types): `phone_number_id` en la raíz,
     * `conversation.{phone_number, phone_number_id, contact_name}` y `message.kapso.{direction, origin}`.
     *
     * @param string               $evento  Nombre del evento (`whatsapp.message.received`…).
     * @param array<string, mixed> $mensaje Nodo `message`.
     * @param array<string, mixed> $conversacion Campos de `conversation` a sumar.
     * @param string               $pni     `phone_number_id` del número que recibió el evento.
     *
     * @return array<string, mixed>
     */
    protected function payload_de(string $evento, array $mensaje, array $conversacion = [], string $pni = self::PNI_PERSONAL): array
    {
        return [
            'event'           => $evento,
            'phone_number_id' => $pni,
            'conversation'    => array_merge(['phone_number_id' => $pni], $conversacion),
            'message'         => $mensaje,
        ];
    }

    /**
     * Mensaje de texto que un cliente le escribe al número personal.
     *
     * @param string   $desde     Teléfono del cliente (formato WhatsApp, sin `+`).
     * @param string   $texto     Cuerpo.
     * @param string   $wamid     Id del mensaje.
     * @param int|null $timestamp Epoch del mensaje (null = ahora).
     * @param string   $nombre    Nombre del contacto que manda Kapso.
     *
     * @return array<string, mixed>
     */
    protected function entrante(string $desde, string $texto, string $wamid, ?int $timestamp = null, string $nombre = 'Cliente de prueba'): array
    {
        return $this->payload_de('whatsapp.message.received', [
            'id'        => $wamid,
            'from'      => $desde,
            'type'      => 'text',
            'text'      => ['body' => $texto],
            'timestamp' => (string) ($timestamp !== null ? $timestamp : time()),
            'kapso'     => ['direction' => 'inbound', 'status' => 'received', 'origin' => 'cloud_api', 'has_media' => false],
        ], ['phone_number' => $desde, 'contact_name' => $nombre]);
    }

    /**
     * Eco de un mensaje que Lucas escribió desde la app de WhatsApp Business: `from` es el negocio y el
     * cliente va en `to`.
     *
     * @param string   $para      Teléfono del cliente (formato WhatsApp, sin `+`).
     * @param string   $texto     Cuerpo.
     * @param string   $wamid     Id del mensaje.
     * @param string   $origen    `business_app`, `cloud_api`, `history_sync`…
     * @param int|null $timestamp Epoch del mensaje (null = ahora).
     * @param string   $evento    Evento que lo transporta (la doc de Kapso no lo precisa).
     *
     * @return array<string, mixed>
     */
    protected function eco(string $para, string $texto, string $wamid, string $origen = 'business_app', ?int $timestamp = null, string $evento = 'whatsapp.message.sent'): array
    {
        return $this->payload_de($evento, [
            'id'        => $wamid,
            'from'      => self::TEL_NEGOCIO_WA,
            'to'        => $para,
            'type'      => 'text',
            'text'      => ['body' => $texto],
            'timestamp' => (string) ($timestamp !== null ? $timestamp : time()),
            'kapso'     => ['direction' => 'outbound', 'status' => 'sent', 'origin' => $origen, 'has_media' => false],
        ], ['phone_number' => $para]);
    }

    /**
     * Evento de estado de entrega de un saliente (`delivered`, `read`, `failed`).
     *
     * @param string               $evento Nombre completo del evento.
     * @param string               $wamid  Id del mensaje.
     * @param array<string, mixed> $extra  Campos a sumar a `message` (p. ej. `errors`).
     *
     * @return array<string, mixed>
     */
    protected function estado_de_entrega(string $evento, string $wamid, array $extra = []): array
    {
        return $this->payload_de($evento, array_merge([
            'id'        => $wamid,
            'to'        => self::TEL_CLIENTE_WA,
            'timestamp' => (string) time(),
            'kapso'     => ['direction' => 'outbound'],
        ], $extra), ['phone_number' => self::TEL_CLIENTE_WA]);
    }
}
