<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Services\AsistenteAvisosService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Los avisos del asistente por WhatsApp: "terminé de leer tu factura, revisala acá".
 *
 * Misión asistente-aviso-escaneo-factura (16/10/2026). El admin le consulta cada minuto
 * `avisos-pendientes` al `empresa-api` de los clientes cuyo dueño le escribió al asistente en las
 * últimas 24 hs, le manda cada aviso por el número por el que el dueño escribió (si la ventana de
 * ese número está abierta), deja la fila saliente con la conversación del escaneo y lo marca con
 * `avisos/avisado`.
 *
 * Lo que estas pruebas cuidan, en orden de cuánto duele si se rompe:
 *
 *   1. **No mandarle dos veces el mismo aviso al dueño**, ni siquiera si la marca falla.
 *   2. **No mandar texto libre con la ventana cerrada** (Meta lo rechaza y el dueño no ve nada).
 *   3. **El 404 de un sistema viejo es silencio**: ni al dueño ni a los admins, y no se lo vuelve a
 *      consultar en seis horas.
 *   4. El link: el del `empresa-api` si viene, o el SPA del admin más la ruta.
 *
 * Todo lo que sale a la red está fakeado (`Http::fake()` del `BaseDelCanal` y el espía de envío).
 */
class AvisosDelAsistenteTest extends BaseDelCanal
{
    /**
     * Ruta relativa del SPA que manda el `empresa-api` en cada aviso.
     */
    const RUTA = '/proveedores/compras?compra=40&escaneo=9b2c-uuid';

    /**
     * Link absoluto que manda el `empresa-api` cuando conoce la base.
     */
    const URL = 'https://ferreteria.comerciocity.com/proveedores/compras?compra=40&escaneo=9b2c-uuid';

    /**
     * Texto del aviso, sin el link (el link lo agrega quien manda).
     */
    const TEXTO = 'Terminé de leer la factura de Distribuidora Sur: Factura A 0003-00012345 por $152.300, 4 renglones, 3 reconocidos y 1 artículo nuevo para dar de alta o asociar.';

    /**
     * Fila entrante del hilo, como la deja el webhook y la completa el job.
     *
     * @param Client      $client          Cliente dueño del hilo.
     * @param int|null    $conversacion    Conversación del `empresa-api` (la del escaneo).
     * @param string|null $phone_number_id Número por el que entró.
     * @param int         $horas_atras     Hace cuántas horas entró.
     * @param string|null $telefono        Quién escribió (default: el teléfono de la ficha).
     *
     * @return ClientAssistantMessage
     */
    private function fila_entrante(
        Client $client,
        ?int $conversacion = 91,
        ?string $phone_number_id = self::PHONE_NUMBER_ID_PRINCIPAL,
        int $horas_atras = 0,
        ?string $telefono = null
    ): ClientAssistantMessage {
        static $secuencia = 0;
        $secuencia++;

        $fila                      = new ClientAssistantMessage();
        $fila->client_id           = $client->id;
        $fila->telefono            = $telefono !== null ? $telefono : (string) $client->phone;
        $fila->phone_number_id     = $phone_number_id;
        $fila->direccion           = ClientAssistantMessage::DIRECCION_ENTRANTE;
        $fila->whatsapp_message_id = 'wamid.ENTRANTE-AVISO-' . $secuencia;
        $fila->tipo                = 'image';
        $fila->texto               = 'Cargame esta factura';
        $fila->estado              = ClientAssistantMessage::ESTADO_RESPONDIDO;
        $fila->ai_conversation_id  = $conversacion;
        $fila->created_at          = now()->subHours($horas_atras);
        $fila->save();

        return $fila;
    }

    /**
     * Respuesta de `avisos-pendientes` con un aviso de escaneo.
     *
     * @param string|null $url          Link absoluto, o null si el `empresa-api` no conoce la base.
     * @param int|null    $conversacion Conversación del escaneo.
     *
     * @return array<string, mixed>
     */
    private function pendientes(?string $url = self::URL, ?int $conversacion = 91): array
    {
        return [
            'avisos' => [
                [
                    'id'                 => 'escaneo_factura:15',
                    'tipo'               => 'escaneo_factura',
                    'ai_conversation_id' => $conversacion,
                    'texto'              => self::TEXTO,
                    'ruta'               => self::RUTA,
                    'url'                => $url,
                ],
            ],
        ];
    }

    /**
     * Pedidos que salieron a una ruta.
     *
     * @param string $fragmento Pedazo de la URL.
     * @param string $metodo    GET | POST.
     *
     * @return array<int, \Illuminate\Http\Client\Request>
     */
    private function pedidos_a(string $fragmento, string $metodo): array
    {
        $pedidos = [];
        foreach (Http::recorded() as $par) {
            $request = $par[0];
            if ($request->method() === $metodo && strpos($request->url(), $fragmento) !== false) {
                $pedidos[] = $request;
            }
        }

        return $pedidos;
    }

    /**
     * El camino feliz: el aviso sale con su link, deja la fila saliente con la conversación del
     * escaneo y queda marcado con el link y el wamid con los que salió.
     *
     * @return void
     */
    public function test_el_aviso_sale_con_el_link_deja_la_fila_y_queda_marcado(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->fila_entrante($client);

        $this->fakear_http([
            '*/asistente/avisos-pendientes*' => Http::response($this->pendientes(), 200),
            '*/asistente/avisos/avisado'     => Http::response(['ok' => true], 200),
        ]);

        $resultado = app(AsistenteAvisosService::class)->enviar_a($client);

        $this->assertSame('enviado', $resultado['estado']);
        $this->assertSame(1, $resultado['enviados']);

        $this->assertCount(1, $espia->textos);
        $this->assertSame((string) $client->phone, $espia->textos[0]['to']);
        $this->assertSame(self::TEXTO . "\n\n" . self::URL, $espia->textos[0]['body'], 'El mensaje es el texto, una línea en blanco y el link.');

        /* La consulta va con la clave del cliente. */
        $consultas = $this->pedidos_a('/asistente/avisos-pendientes', 'GET');
        $this->assertCount(1, $consultas);
        $this->assertSame('clave-del-cliente', $consultas[0]->header('X-Admin-Api-Key')[0]);

        /* La marca lleva el id del contrato, el link y el wamid. */
        $marcas = $this->pedidos_a('/asistente/avisos/avisado', 'POST');
        $this->assertCount(1, $marcas);
        $this->assertSame('escaneo_factura:15', $marcas[0]['id']);
        $this->assertSame(self::URL, $marcas[0]['url']);
        $this->assertSame('wamid.saliente.1', $marcas[0]['whatsapp_message_id']);

        /* La fila saliente es la que hace que citar el aviso reabra la conversación del escaneo. */
        $saliente = ClientAssistantMessage::query()
            ->where('client_id', $client->id)
            ->where('direccion', ClientAssistantMessage::DIRECCION_SALIENTE)
            ->first();

        $this->assertNotNull($saliente);
        $this->assertSame(91, (int) $saliente->ai_conversation_id);
        $this->assertSame('wamid.saliente.1', $saliente->whatsapp_message_id);
        $this->assertSame(self::PHONE_NUMBER_ID_PRINCIPAL, $saliente->phone_number_id);
        $this->assertSame(self::TEXTO . "\n\n" . self::URL, $saliente->texto);
    }

    /**
     * Sin `url` del `empresa-api`, el link se arma con el SPA del admin más la `ruta`, y la consulta
     * le pasa ese SPA en `spa_url`.
     *
     * @return void
     */
    public function test_sin_url_el_link_sale_del_spa_del_admin_mas_la_ruta(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $api    = $this->crear_client_api($client);
        $api->spa_url = 'https://ferreteria.comerciocity.com/';
        $api->save();
        $this->fila_entrante($client);

        $this->fakear_http([
            '*/asistente/avisos-pendientes*' => Http::response($this->pendientes(null), 200),
            '*/asistente/avisos/avisado'     => Http::response(['ok' => true], 200),
        ]);

        app(AsistenteAvisosService::class)->enviar_a($client);

        $this->assertCount(1, $espia->textos);
        $this->assertSame(
            self::TEXTO . "\n\n" . 'https://ferreteria.comerciocity.com' . self::RUTA,
            $espia->textos[0]['body']
        );

        $consulta = $this->pedidos_a('/asistente/avisos-pendientes', 'GET')[0];
        $this->assertStringContainsString('spa_url=' . rawurlencode('https://ferreteria.comerciocity.com'), $consulta->url());
    }

    /**
     * Sin `url` ni SPA conocido, el aviso sale igual, sin link. Y la consulta no manda `spa_url`.
     *
     * @return void
     */
    public function test_sin_url_ni_spa_el_aviso_sale_sin_link(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->fila_entrante($client);

        $this->fakear_http([
            '*/asistente/avisos-pendientes*' => Http::response($this->pendientes(null), 200),
            '*/asistente/avisos/avisado'     => Http::response(['ok' => true], 200),
        ]);

        app(AsistenteAvisosService::class)->enviar_a($client);

        $this->assertCount(1, $espia->textos);
        $this->assertSame(self::TEXTO, $espia->textos[0]['body']);

        $consulta = $this->pedidos_a('/asistente/avisos-pendientes', 'GET')[0];
        $this->assertStringNotContainsString('spa_url', $consulta->url());

        $marca = $this->pedidos_a('/asistente/avisos/avisado', 'POST')[0];
        $this->assertArrayNotHasKey('url', $marca->data(), 'Sin link no se manda una url vacía.');
    }

    /**
     * 🔴 Solo se consulta a quien le escribió al asistente en las últimas 24 hs, con el canal prendido.
     *
     * @return void
     */
    public function test_el_barrido_solo_consulta_a_quien_escribio_en_las_ultimas_24_hs(): void
    {
        $this->espiar_sender();
        $this->crear_config_whatsapp();

        $viejo = $this->crear_cliente('+5493411111111');
        $this->fila_entrante($viejo, 91, self::PHONE_NUMBER_ID_PRINCIPAL, 25);

        $apagado = $this->crear_cliente('+5493412222222', false);
        $this->fila_entrante($apagado);

        $sin_clave = $this->crear_cliente('+5493413333333', true, '');
        $this->fila_entrante($sin_clave);

        $activo = $this->crear_cliente('+5493414444444');
        $this->fila_entrante($activo);

        $this->fakear_http([
            '*/asistente/avisos-pendientes*' => Http::response(['avisos' => []], 200),
        ]);

        $resultados = app(AsistenteAvisosService::class)->enviar_a_todos();

        $ids = array_map(function (array $fila) {
            return $fila['client_id'];
        }, $resultados);

        $this->assertContains((int) $activo->id, $ids);
        $this->assertNotContains((int) $viejo->id, $ids, 'Sin entrante en 24 hs no se consulta.');
        $this->assertNotContains((int) $apagado->id, $ids, 'Con el canal apagado no se consulta.');
        $this->assertNotContains((int) $sin_clave->id, $ids, 'Sin clave no se consulta.');

        /* Una sola consulta: la del activo. */
        $this->assertCount(1, $this->pedidos_a('/asistente/avisos-pendientes', 'GET'));
    }

    /**
     * 🔴 404 = sistema sin la función: silencio total y seis horas sin volver a preguntar.
     *
     * @return void
     */
    public function test_el_404_es_silencioso_y_pausa_seis_horas(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->fila_entrante($client);

        $this->fakear_http([
            '*/asistente/avisos-pendientes*' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $servicio = app(AsistenteAvisosService::class);

        $this->assertSame('degradado', $servicio->enviar_a($client)['estado']);
        $this->assertCount(0, $espia->textos, 'Ni al dueño ni a los admins: el 404 es la versión vieja, no un error.');
        $this->assertCount(0, $espia->plantillas);

        /* Una hora después sigue en pausa: no hay segunda consulta. */
        $this->travel(60)->minutes();
        $this->assertSame('en_pausa', $servicio->enviar_a($client)['estado']);
        $this->assertCount(1, $this->pedidos_a('/asistente/avisos-pendientes', 'GET'));

        /* Pasadas las seis horas se vuelve a consultar (con una entrante nueva para seguir dentro de las 24 hs). */
        $this->travel(301)->minutes();
        $this->fila_entrante($client);
        $this->assertSame('degradado', $servicio->enviar_a($client)['estado']);
        $this->assertCount(2, $this->pedidos_a('/asistente/avisos-pendientes', 'GET'));
        $this->assertCount(0, $espia->textos);
    }

    /**
     * Un error del sistema del cliente pausa diez minutos, sin escribirle al dueño.
     *
     * @return void
     */
    public function test_un_error_del_cliente_pausa_diez_minutos_sin_escribirle_al_dueno(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->fila_entrante($client);

        $this->fakear_http([
            '*/asistente/avisos-pendientes*' => Http::response(['message' => 'Server Error'], 500),
        ]);

        $servicio = app(AsistenteAvisosService::class);

        $this->assertSame('error', $servicio->enviar_a($client)['estado']);
        $this->assertSame('en_pausa', $servicio->enviar_a($client)['estado']);
        $this->assertCount(1, $this->pedidos_a('/asistente/avisos-pendientes', 'GET'));

        $this->travel(11)->minutes();
        $this->assertSame('error', $servicio->enviar_a($client)['estado']);
        $this->assertCount(2, $this->pedidos_a('/asistente/avisos-pendientes', 'GET'));

        $this->assertCount(0, $espia->textos);
    }

    /**
     * El dueño que mandó la factura por el número del ASISTENTE recibe el aviso por ese número.
     *
     * @return void
     */
    public function test_entro_por_el_asistente_sale_por_el_asistente(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->crear_config_asistente();
        $client = $this->crear_cliente();
        $this->fila_entrante($client, 91, self::PHONE_NUMBER_ID_ASISTENTE);

        $this->fakear_http([
            '*/asistente/avisos-pendientes*' => Http::response($this->pendientes(), 200),
            '*/asistente/avisos/avisado'     => Http::response(['ok' => true], 200),
        ]);

        $resultado = app(AsistenteAvisosService::class)->enviar_a($client);

        $this->assertSame('enviado', $resultado['estado']);
        $this->assertSame([self::PHONE_NUMBER_ID_ASISTENTE], $espia->destinos);

        $saliente = ClientAssistantMessage::query()
            ->where('client_id', $client->id)
            ->where('direccion', ClientAssistantMessage::DIRECCION_SALIENTE)
            ->first();
        $this->assertSame(self::PHONE_NUMBER_ID_ASISTENTE, $saliente->phone_number_id);
    }

    /**
     * 🔴 El que escribió por el PRINCIPAL recibe el aviso por el principal, aunque el número del
     * asistente exista. Y la ventana del principal la abre su propio mensaje al asistente: sin eso,
     * hoy (con el número del asistente frenado) el aviso no saldría nunca.
     *
     * @return void
     */
    public function test_entro_por_el_principal_sale_por_el_principal_con_su_ventana(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->crear_config_asistente();
        $client = $this->crear_cliente();
        $this->fila_entrante($client, 91, self::PHONE_NUMBER_ID_PRINCIPAL);

        $this->fakear_http([
            '*/asistente/avisos-pendientes*' => Http::response($this->pendientes(), 200),
            '*/asistente/avisos/avisado'     => Http::response(['ok' => true], 200),
        ]);

        $resultado = app(AsistenteAvisosService::class)->enviar_a($client);

        $this->assertSame('enviado', $resultado['estado']);
        $this->assertSame([self::PHONE_NUMBER_ID_PRINCIPAL], $espia->destinos);
    }

    /**
     * La conversación manda sobre "el último que escribió": el aviso va a quien mandó la factura (un
     * empleado), aunque después haya escrito el dueño en otra conversación.
     *
     * @return void
     */
    public function test_el_aviso_va_a_quien_mando_la_factura(): void
    {
        $espia    = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client   = $this->crear_cliente();
        $empleado = '+5493419999999';

        $this->fila_entrante($client, 91, self::PHONE_NUMBER_ID_PRINCIPAL, 1, $empleado);
        $this->fila_entrante($client, 92);

        $this->fakear_http([
            '*/asistente/avisos-pendientes*' => Http::response($this->pendientes(), 200),
            '*/asistente/avisos/avisado'     => Http::response(['ok' => true], 200),
        ]);

        app(AsistenteAvisosService::class)->enviar_a($client);

        $this->assertCount(1, $espia->textos);
        $this->assertSame($empleado, $espia->textos[0]['to']);
    }

    /**
     * Sin conversación en el aviso, va a la última entrante del cliente en las 24 hs.
     *
     * @return void
     */
    public function test_sin_conversacion_va_a_la_ultima_entrante_del_cliente(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->fila_entrante($client, 92);

        $this->fakear_http([
            '*/asistente/avisos-pendientes*' => Http::response($this->pendientes(self::URL, null), 200),
            '*/asistente/avisos/avisado'     => Http::response(['ok' => true], 200),
        ]);

        app(AsistenteAvisosService::class)->enviar_a($client);

        $this->assertCount(1, $espia->textos);
        $this->assertSame((string) $client->phone, $espia->textos[0]['to']);

        $saliente = ClientAssistantMessage::query()
            ->where('client_id', $client->id)
            ->where('direccion', ClientAssistantMessage::DIRECCION_SALIENTE)
            ->first();
        $this->assertNull($saliente->ai_conversation_id, 'Sin conversación en el aviso, la fila no inventa una.');
    }

    /**
     * 🔴 Ventana cerrada en el número por el que hay que salir: no se manda nada, no se marca nada y
     * no queda reclamado (para que salga cuando se abra).
     *
     * El dueño mandó la factura por el número del asistente hace 30 hs, y en las últimas 24 hs solo
     * le escribió por el principal: la ventana del asistente está cerrada para Meta.
     *
     * @return void
     */
    public function test_con_la_ventana_cerrada_no_se_manda_ni_se_marca(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->crear_config_asistente();
        $client = $this->crear_cliente();

        $this->fila_entrante($client, 91, self::PHONE_NUMBER_ID_ASISTENTE, 30);
        $this->fila_entrante($client, 92, self::PHONE_NUMBER_ID_PRINCIPAL);

        $this->fakear_http([
            '*/asistente/avisos-pendientes*' => Http::response($this->pendientes(), 200),
            '*/asistente/avisos/avisado'     => Http::response(['ok' => true], 200),
        ]);

        $servicio  = app(AsistenteAvisosService::class);
        $resultado = $servicio->enviar_a($client);

        $this->assertSame('pendiente', $resultado['estado']);
        $this->assertStringContainsString('ventana_cerrada', $resultado['detalle']);
        $this->assertCount(0, $espia->textos);
        $this->assertCount(0, $espia->plantillas, 'El aviso nunca sale por plantilla.');
        $this->assertCount(0, $this->pedidos_a('/asistente/avisos/avisado', 'POST'));
        $this->assertFalse(Cache::has($servicio->clave_de_reclamo($client, 'escaneo_factura:15')));
    }

    /**
     * 🔴 Si la marca falla, la vuelta siguiente NO le vuelve a escribir al dueño: reintenta solo la
     * marca, con el link y el wamid del primer envío. Y una vez marcado, no se toca más.
     *
     * @return void
     */
    public function test_si_la_marca_falla_no_se_duplica_el_aviso(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->fila_entrante($client);

        $this->fakear_http([
            '*/asistente/avisos-pendientes*' => Http::response($this->pendientes(), 200),
            '*/asistente/avisos/avisado'     => Http::sequence()
                ->push(['message' => 'Server Error'], 500)
                ->push(['ok' => true], 200),
        ]);

        $servicio = app(AsistenteAvisosService::class);

        $this->assertSame('enviado', $servicio->enviar_a($client)['estado']);
        $this->assertCount(1, $espia->textos);

        /* El `empresa-api` lo sigue ofreciendo porque la marca falló: no sale otro mensaje. */
        $segunda = $servicio->enviar_a($client);
        $this->assertStringContainsString('remarcado', $segunda['detalle']);
        $this->assertCount(1, $espia->textos, 'El aviso no se le manda dos veces al dueño.');

        $marcas = $this->pedidos_a('/asistente/avisos/avisado', 'POST');
        $this->assertCount(2, $marcas);
        $this->assertSame('wamid.saliente.1', $marcas[1]['whatsapp_message_id'], 'La marca reintentada lleva el wamid del primer envío.');
        $this->assertSame(self::URL, $marcas[1]['url']);

        /* Ya marcado: aunque volviera a aparecer, ni mensaje ni marca. */
        $tercera = $servicio->enviar_a($client);
        $this->assertStringContainsString('ya_avisado', $tercera['detalle']);
        $this->assertCount(1, $espia->textos);
        $this->assertCount(2, $this->pedidos_a('/asistente/avisos/avisado', 'POST'));

        /* Una sola fila saliente. */
        $this->assertSame(1, ClientAssistantMessage::query()
            ->where('client_id', $client->id)
            ->where('direccion', ClientAssistantMessage::DIRECCION_SALIENTE)
            ->count());
    }

    /**
     * Si Meta rechaza el aviso, el reclamo se suelta, no se marca, y la vuelta siguiente lo manda.
     *
     * @return void
     */
    public function test_si_meta_rechaza_el_aviso_queda_pendiente_y_sale_despues(): void
    {
        $espia  = $this->espiar_sender(false);
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->fila_entrante($client);

        $this->fakear_http([
            '*/asistente/avisos-pendientes*' => Http::response($this->pendientes(), 200),
            '*/asistente/avisos/avisado'     => Http::response(['ok' => true], 200),
        ]);

        $servicio = app(AsistenteAvisosService::class);

        $primera = $servicio->enviar_a($client);
        $this->assertSame('pendiente', $primera['estado']);
        $this->assertCount(0, $this->pedidos_a('/asistente/avisos/avisado', 'POST'));
        $this->assertFalse(Cache::has($servicio->clave_de_reclamo($client, 'escaneo_factura:15')));

        $espia->confirma = true;

        $this->assertSame('enviado', $servicio->enviar_a($client)['estado']);
        $this->assertCount(2, $espia->textos);
        $this->assertCount(1, $this->pedidos_a('/asistente/avisos/avisado', 'POST'));
    }

    /**
     * Un aviso que alguien ya reclamó (otra corrida en vuelo) no se manda.
     *
     * @return void
     */
    public function test_un_aviso_reclamado_por_otra_corrida_no_se_manda(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->fila_entrante($client);

        $this->fakear_http([
            '*/asistente/avisos-pendientes*' => Http::response($this->pendientes(), 200),
        ]);

        $servicio = app(AsistenteAvisosService::class);
        Cache::put($servicio->clave_de_reclamo($client, 'escaneo_factura:15'), ['estado' => AsistenteAvisosService::RECLAMO_RECLAMADO], now()->addDay());

        $resultado = $servicio->enviar_a($client);

        $this->assertStringContainsString('en_curso', $resultado['detalle']);
        $this->assertCount(0, $espia->textos);
    }

    /**
     * El comando con `--client` corre el servicio sobre ese cliente y termina bien.
     *
     * @return void
     */
    public function test_el_comando_manda_los_avisos_de_un_cliente(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->fila_entrante($client);

        $this->fakear_http([
            '*/asistente/avisos-pendientes*' => Http::response($this->pendientes(), 200),
            '*/asistente/avisos/avisado'     => Http::response(['ok' => true], 200),
        ]);

        $codigo = Artisan::call('asistente:enviar-avisos', ['--client' => $client->id]);

        $this->assertSame(0, $codigo);
        $this->assertCount(1, $espia->textos);
        $this->assertStringContainsString('enviado', Artisan::output());
    }

    /**
     * El comando está agendado cada minuto, sin superponerse, en segundo plano y sin heredar el
     * candado del cron (el molde de los otros comandos que corren en segundo plano).
     *
     * @return void
     */
    public function test_el_comando_esta_agendado_cada_minuto_sin_superponerse(): void
    {
        $schedule = app(Schedule::class);

        $eventos = array_values(array_filter($schedule->events(), function ($evento) {
            return strpos((string) $evento->command, 'asistente:enviar-avisos') !== false;
        }));

        $this->assertCount(1, $eventos, 'Tiene que haber exactamente un barrido de avisos agendado.');

        $evento = $eventos[0];
        $this->assertSame('* * * * *', $evento->expression);
        $this->assertTrue($evento->withoutOverlapping, 'Dos barridos a la vez reclamarían los mismos avisos.');
        $this->assertTrue($evento->runInBackground, 'En primer plano clava el scheduler mientras espera a los clientes.');
        $this->assertStringStartsWith('exec 3>&- 4>&- 5>&- 6>&- 7>&- 8>&- 9>&-; ', $evento->command);
    }
}
