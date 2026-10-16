<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Models\ClientTemplate;
use App\Services\AsistenteRecordatoriosService;
use App\Services\AsistenteWhatsappSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Los recordatorios de la Agenda por WhatsApp: «recordame el viernes a las 10 que llame a Juan».
 *
 * Misión cruzada recordatorios-whatsapp (16/10/2026). El admin le consulta cada cinco minutos
 * `recordatorios-pendientes` al `empresa-api` de los clientes con el canal prendido (anunciándole si
 * hay plantilla con `plantilla=1|0`), le manda cada recordatorio al teléfono de la ficha por el número
 * del asistente —texto libre con la ventana abierta, plantilla con la ventana cerrada, nada sin
 * plantilla—, deja la fila saliente y lo marca con `recordatorios/avisado`.
 *
 * Lo que estas pruebas cuidan, en orden de cuánto duele si se rompe:
 *
 *   1. **No mandarle dos veces el mismo recordatorio al dueño**, ni siquiera si la marca falla.
 *   2. **No mandar texto libre con la ventana cerrada**, y no mandar nada sin plantilla.
 *   3. **El anuncio `plantilla=1` solo con la plantilla de verdad configurada**: es lo que hace que el
 *      asistente le prometa (o no) el aviso al dueño.
 *   4. **El 404 de un sistema viejo es silencio** y una hora sin volver a preguntar.
 *
 * Todo lo que sale a la red está fakeado (`Http::fake()` del `BaseDelCanal` y el espía de envío).
 */
class RecordatoriosDelAsistenteTest extends BaseDelCanal
{
    /**
     * Id del recordatorio tal como lo arma el `empresa-api`: `recordatorio:{pending_id}-{created_at unix}:{Y-m-d}`.
     */
    const ID = 'recordatorio:57-1792150000:2026-10-16';

    /**
     * Detalle de la tarea, sin el prefijo de la hora.
     */
    const DETALLE = 'Llamar a Juan por el presupuesto';

    /**
     * Texto del recordatorio, en la voz del asistente.
     */
    const TEXTO = "⏰ Te recuerdo: *Llamar a Juan por el presupuesto* (hoy 10:00).\nCuando lo hagas, avisame y lo marco como hecho.";

    /**
     * Nombre de la plantilla de Meta de los recordatorios.
     */
    const PLANTILLA = 'cc_recordatorio_asistente';

    /**
     * Respuesta de `recordatorios-pendientes` con un recordatorio de hoy a las 10.
     *
     * @param string   $detalle      Detalle de la tarea.
     * @param int|null $conversacion Conversación de WhatsApp vigente del dueño.
     * @param string   $id           Id del recordatorio.
     *
     * @return array<string, mixed>
     */
    private function pendientes(string $detalle = self::DETALLE, ?int $conversacion = 77, string $id = self::ID): array
    {
        return [
            'recordatorios' => [
                [
                    'id'                 => $id,
                    'tarea_id'           => 57,
                    'fecha'              => now()->toDateString(),
                    'hora'               => '10:00',
                    'detalle'            => $detalle,
                    'texto'              => self::TEXTO,
                    'ai_conversation_id' => $conversacion,
                    'ruta'               => '/agenda',
                ],
            ],
        ];
    }

    /**
     * Fila entrante del dueño al asistente: abre la ventana del número por el que entró.
     *
     * @param Client      $client          Cliente.
     * @param string|null $phone_number_id Número por el que entró.
     * @param string      $estado          Estado de la fila (respondido = turno terminado).
     * @param string|null $telefono        Quién escribió (default: el de la ficha).
     *
     * @return ClientAssistantMessage
     */
    private function fila_entrante(
        Client $client,
        ?string $phone_number_id = self::PHONE_NUMBER_ID_PRINCIPAL,
        string $estado = ClientAssistantMessage::ESTADO_RESPONDIDO,
        ?string $telefono = null
    ): ClientAssistantMessage {
        static $secuencia = 0;
        $secuencia++;

        $fila                      = new ClientAssistantMessage();
        $fila->client_id           = $client->id;
        $fila->telefono            = $telefono !== null ? $telefono : (string) $client->phone;
        $fila->phone_number_id     = $phone_number_id;
        $fila->direccion           = ClientAssistantMessage::DIRECCION_ENTRANTE;
        $fila->whatsapp_message_id = 'wamid.ENTRANTE-RECORDATORIO-' . $secuencia;
        $fila->tipo                = 'text';
        $fila->texto               = '¿Cómo vengo con las ventas?';
        $fila->estado              = $estado;
        $fila->ai_conversation_id  = 77;
        $fila->save();

        return $fila;
    }

    /**
     * Carga la plantilla de los recordatorios: el nombre en `AdminSetting` y su fila en `client_templates`.
     *
     * @param bool $con_fila Si se crea la fila en `client_templates`.
     * @param bool $activa   Si esa fila está activa.
     *
     * @return void
     */
    private function cargar_plantilla(bool $con_fila = true, bool $activa = true): void
    {
        AdminSetting::set(AsistenteWhatsappSettings::KEY_RECORDATORIO_TEMPLATE_NAME, self::PLANTILLA);

        if (! $con_fila) {
            return;
        }

        $plantilla                = new ClientTemplate();
        $plantilla->template_name = self::PLANTILLA;
        $plantilla->language_code = 'es_AR';
        $plantilla->categoria     = 'recordatorios';
        $plantilla->titulo        = 'Recordatorio del asistente';
        $plantilla->body_template = 'Te recuerdo: {{1}}';
        $plantilla->activa        = $activa;
        $plantilla->save();
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
     * Fakea la consulta y la marca con respuestas comunes.
     *
     * @param mixed $marca Respuesta de la marca (default: 200).
     *
     * @return void
     */
    private function fakear_empresa($marca = null): void
    {
        $this->fakear_http([
            '*/asistente/recordatorios-pendientes*' => Http::response($this->pendientes(), 200),
            '*/asistente/recordatorios/avisado'     => $marca !== null ? $marca : Http::response(['ok' => true], 200),
        ]);
    }

    /**
     * Ventana abierta: sale como texto libre (el texto del `empresa-api` tal cual), deja la fila
     * saliente con la conversación y queda marcado con el id en el cuerpo y el wamid.
     *
     * @return void
     */
    public function test_con_la_ventana_abierta_sale_como_texto_y_queda_marcado(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->fila_entrante($client);
        $this->fakear_empresa();

        $resultado = app(AsistenteRecordatoriosService::class)->enviar_a($client);

        $this->assertSame('enviado', $resultado['estado']);
        $this->assertSame(1, $resultado['enviados']);
        $this->assertStringContainsString(self::ID . '=enviado_texto', $resultado['detalle']);

        $this->assertCount(1, $espia->textos);
        $this->assertCount(0, $espia->plantillas);
        $this->assertSame((string) $client->phone, $espia->textos[0]['to']);
        $this->assertSame(self::TEXTO, $espia->textos[0]['body']);

        /* La consulta va con la clave del cliente y el anuncio de que NO hay plantilla. */
        $consultas = $this->pedidos_a('/api/admin-sync/asistente/recordatorios-pendientes', 'GET');
        $this->assertCount(1, $consultas);
        $this->assertSame('clave-del-cliente', $consultas[0]->header('X-Admin-Api-Key')[0]);
        $this->assertStringContainsString('plantilla=0', $consultas[0]->url());

        /* La marca lleva el id del contrato EN EL CUERPO (tiene `:`) y el wamid. */
        $marcas = $this->pedidos_a('/api/admin-sync/asistente/recordatorios/avisado', 'POST');
        $this->assertCount(1, $marcas);
        $this->assertSame(self::ID, $marcas[0]['id']);
        $this->assertSame('wamid.saliente.1', $marcas[0]['whatsapp_message_id']);

        $saliente = ClientAssistantMessage::query()
            ->where('client_id', $client->id)
            ->where('direccion', ClientAssistantMessage::DIRECCION_SALIENTE)
            ->first();

        $this->assertNotNull($saliente);
        $this->assertSame(77, (int) $saliente->ai_conversation_id);
        $this->assertSame('wamid.saliente.1', $saliente->whatsapp_message_id);
        $this->assertSame(self::TEXTO, $saliente->texto);
        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $saliente->estado);
        $this->assertSame(self::PHONE_NUMBER_ID_PRINCIPAL, $saliente->phone_number_id);
    }

    /**
     * Ventana cerrada con la plantilla cargada: sale por plantilla, con el detalle y la hora como
     * única variable, y queda marcado. La fila saliente guarda la variable, no la plantilla cruda.
     *
     * @return void
     */
    public function test_con_la_ventana_cerrada_y_plantilla_sale_por_plantilla(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->cargar_plantilla();
        $this->fakear_empresa();

        $resultado = app(AsistenteRecordatoriosService::class)->enviar_a($client);

        $this->assertSame('enviado', $resultado['estado']);
        $this->assertStringContainsString(self::ID . '=enviado_plantilla', $resultado['detalle']);

        $this->assertCount(0, $espia->textos, 'Fuera de la ventana no se manda texto libre: Meta lo rechaza.');
        $this->assertCount(1, $espia->plantillas);
        $this->assertSame(self::PLANTILLA, $espia->plantillas[0]['template_name']);
        $this->assertSame('es_AR', $espia->plantillas[0]['language_code']);
        $this->assertSame([self::DETALLE . ' (hoy 10:00)'], $espia->plantillas[0]['variables']);

        $this->assertStringContainsString('plantilla=1', $this->pedidos_a('/asistente/recordatorios-pendientes', 'GET')[0]->url());

        $marcas = $this->pedidos_a('/asistente/recordatorios/avisado', 'POST');
        $this->assertCount(1, $marcas);
        $this->assertSame('wamid.plantilla.1', $marcas[0]['whatsapp_message_id']);

        $saliente = ClientAssistantMessage::query()
            ->where('client_id', $client->id)
            ->where('direccion', ClientAssistantMessage::DIRECCION_SALIENTE)
            ->first();
        $this->assertSame(self::DETALLE . ' (hoy 10:00)', $saliente->texto);
    }

    /**
     * 🔴 Ventana cerrada y sin plantilla: no sale nada, no se marca nada y no queda reclamado, para
     * que salga como texto si el dueño escribe antes de que el `empresa-api` lo venza.
     *
     * @return void
     */
    public function test_con_la_ventana_cerrada_y_sin_plantilla_no_sale_nada(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->fakear_empresa();

        $servicio  = app(AsistenteRecordatoriosService::class);
        $resultado = $servicio->enviar_a($client);

        $this->assertSame('pendiente', $resultado['estado']);
        $this->assertStringContainsString(self::ID . '=sin_plantilla', $resultado['detalle']);
        $this->assertCount(0, $espia->textos);
        $this->assertCount(0, $espia->plantillas);
        $this->assertCount(0, $this->pedidos_a('/asistente/recordatorios/avisado', 'POST'));
        $this->assertFalse(Cache::has($servicio->clave_de_reclamo($client, self::ID)));

        /* El dueño escribió: la vuelta siguiente lo manda como texto. */
        $this->fila_entrante($client);

        $this->assertSame('enviado', $servicio->enviar_a($client)['estado']);
        $this->assertCount(1, $espia->textos);
    }

    /**
     * 🔴 `plantilla=1` viaja solo con la plantilla DE VERDAD configurada: el nombre en `AdminSetting`
     * Y la fila activa en `client_templates`. Con una de las dos sola, se anuncia `plantilla=0` (y no
     * se manda por plantilla): es lo que evita que el asistente prometa un aviso que no sale.
     *
     * @return void
     */
    public function test_plantilla_1_viaja_solo_con_la_plantilla_configurada(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->fakear_empresa();

        $servicio = app(AsistenteRecordatoriosService::class);

        /* Sin nada. */
        $servicio->enviar_a($client);

        /* Solo el nombre, sin la fila. */
        $this->cargar_plantilla(false);
        $servicio->enviar_a($client);

        /* El nombre con la fila inactiva. */
        $this->cargar_plantilla(true, false);
        $servicio->enviar_a($client);

        $this->assertCount(0, $espia->plantillas, 'Con la plantilla a medias no se manda por plantilla.');

        /* Ahora sí: la fila activa. */
        ClientTemplate::query()->where('template_name', self::PLANTILLA)->update(['activa' => true]);
        $servicio->enviar_a($client);

        $consultas = $this->pedidos_a('/asistente/recordatorios-pendientes', 'GET');
        $this->assertCount(4, $consultas);
        $this->assertStringContainsString('plantilla=0', $consultas[0]->url());
        $this->assertStringContainsString('plantilla=0', $consultas[1]->url());
        $this->assertStringContainsString('plantilla=0', $consultas[2]->url());
        $this->assertStringContainsString('plantilla=1', $consultas[3]->url());

        $this->assertCount(1, $espia->plantillas);
    }

    /**
     * 🔴 404 = sistema sin la función: silencio total y una hora sin volver a preguntar.
     *
     * @return void
     */
    public function test_el_404_es_silencioso_y_pausa_una_hora(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->fila_entrante($client);

        $this->fakear_http([
            '*/asistente/recordatorios-pendientes*' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $servicio = app(AsistenteRecordatoriosService::class);

        $this->assertSame('degradado', $servicio->enviar_a($client)['estado']);
        $this->assertCount(0, $espia->textos, 'Ni al dueño ni a los admins: el 404 es la versión vieja, no un error.');
        $this->assertCount(0, $espia->plantillas);

        $this->travel(59)->minutes();
        $this->assertSame('en_pausa', $servicio->enviar_a($client)['estado']);
        $this->assertCount(1, $this->pedidos_a('/asistente/recordatorios-pendientes', 'GET'));

        $this->travel(2)->minutes();
        $this->assertSame('degradado', $servicio->enviar_a($client)['estado']);
        $this->assertCount(2, $this->pedidos_a('/asistente/recordatorios-pendientes', 'GET'));
        $this->assertCount(0, $espia->textos);
    }

    /**
     * Un error del sistema del cliente (401, 500…) pausa diez minutos, sin escribirle al dueño.
     *
     * @return void
     */
    public function test_un_error_del_cliente_pausa_diez_minutos(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();

        $this->fakear_http([
            '*/asistente/recordatorios-pendientes*' => Http::response(['message' => 'Unauthenticated.'], 401),
        ]);

        $servicio = app(AsistenteRecordatoriosService::class);

        $this->assertSame('error', $servicio->enviar_a($client)['estado']);
        $this->travel(9)->minutes();
        $this->assertSame('en_pausa', $servicio->enviar_a($client)['estado']);
        $this->assertCount(1, $this->pedidos_a('/asistente/recordatorios-pendientes', 'GET'));

        $this->travel(2)->minutes();
        $this->assertSame('error', $servicio->enviar_a($client)['estado']);
        $this->assertCount(2, $this->pedidos_a('/asistente/recordatorios-pendientes', 'GET'));

        $this->assertCount(0, $espia->textos);
    }

    /**
     * 🔴 Dos corridas seguidas mandan un solo recordatorio, aunque el `empresa-api` lo siguiera
     * ofreciendo (un reloj corrido, una marca que se perdió del otro lado).
     *
     * @return void
     */
    public function test_dos_corridas_mandan_un_solo_recordatorio(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->fila_entrante($client);
        $this->fakear_empresa();

        $servicio = app(AsistenteRecordatoriosService::class);

        $this->assertSame('enviado', $servicio->enviar_a($client)['estado']);
        $segunda = $servicio->enviar_a($client);

        $this->assertStringContainsString(self::ID . '=ya_avisado', $segunda['detalle']);
        $this->assertCount(1, $espia->textos, 'El recordatorio no se le manda dos veces al dueño.');
        $this->assertCount(1, $this->pedidos_a('/asistente/recordatorios/avisado', 'POST'));
    }

    /**
     * Un recordatorio que otra corrida tiene reclamado (en vuelo) no se manda.
     *
     * @return void
     */
    public function test_un_recordatorio_reclamado_por_otra_corrida_no_se_manda(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->fila_entrante($client);
        $this->fakear_empresa();

        $servicio = app(AsistenteRecordatoriosService::class);
        Cache::put($servicio->clave_de_reclamo($client, self::ID), ['estado' => AsistenteRecordatoriosService::RECLAMO_RECLAMADO], now()->addMinutes(10));

        $this->assertStringContainsString(self::ID . '=en_curso', $servicio->enviar_a($client)['detalle']);
        $this->assertCount(0, $espia->textos);
    }

    /**
     * 🔴 Si la marca falla, la vuelta siguiente NO le vuelve a escribir al dueño: reintenta solo la
     * marca, con el wamid del primer envío. Y una vez marcado, no se toca más.
     *
     * @return void
     */
    public function test_si_la_marca_falla_la_segunda_corrida_no_reenvia_y_reintenta_la_marca(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->fila_entrante($client);

        $this->fakear_empresa(Http::sequence()
            ->push(['message' => 'Server Error'], 500)
            ->push(['ok' => true], 200));

        $servicio = app(AsistenteRecordatoriosService::class);

        $this->assertSame('enviado', $servicio->enviar_a($client)['estado']);
        $this->assertCount(1, $espia->textos);

        $segunda = $servicio->enviar_a($client);
        $this->assertStringContainsString(self::ID . '=remarcado', $segunda['detalle']);
        $this->assertCount(1, $espia->textos, 'El recordatorio no se le manda dos veces al dueño.');

        $marcas = $this->pedidos_a('/asistente/recordatorios/avisado', 'POST');
        $this->assertCount(2, $marcas);
        $this->assertSame(self::ID, $marcas[1]['id']);
        $this->assertSame('wamid.saliente.1', $marcas[1]['whatsapp_message_id'], 'La marca reintentada lleva el wamid del primer envío.');

        $tercera = $servicio->enviar_a($client);
        $this->assertStringContainsString(self::ID . '=ya_avisado', $tercera['detalle']);
        $this->assertCount(1, $espia->textos);
        $this->assertCount(2, $this->pedidos_a('/asistente/recordatorios/avisado', 'POST'));

        $this->assertSame(1, ClientAssistantMessage::query()
            ->where('client_id', $client->id)
            ->where('direccion', ClientAssistantMessage::DIRECCION_SALIENTE)
            ->count());
    }

    /**
     * Un 404 en la marca la da por cerrada: el `empresa-api` ya no reconoce el recordatorio (la tarea
     * se borró o se completó) y reintentar no lleva a nada.
     *
     * @return void
     */
    public function test_un_404_en_la_marca_la_da_por_cerrada(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->fila_entrante($client);
        $this->fakear_empresa(Http::response(['message' => 'Not Found'], 404));

        $servicio = app(AsistenteRecordatoriosService::class);
        $servicio->enviar_a($client);

        $reclamo = Cache::get($servicio->clave_de_reclamo($client, self::ID));
        $this->assertSame(AsistenteRecordatoriosService::RECLAMO_MARCADO, $reclamo['estado']);

        $this->assertStringContainsString(self::ID . '=ya_avisado', $servicio->enviar_a($client)['detalle']);
        $this->assertCount(1, $espia->textos);
        $this->assertCount(1, $this->pedidos_a('/asistente/recordatorios/avisado', 'POST'));
    }

    /**
     * 🔴 El recordatorio no se mete en medio de un turno: mientras el dueño tenga un mensaje sin
     * contestar, espera sin reclamo. Y el teléfono de la ficha se compara normalizado con el del hilo.
     *
     * @return void
     */
    public function test_con_un_turno_en_curso_no_se_manda(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        /* La ficha con otro formato que el que guarda el webhook. */
        $client = $this->crear_cliente('+54 9 341 123-4567');
        $turno  = $this->fila_entrante($client, self::PHONE_NUMBER_ID_PRINCIPAL, ClientAssistantMessage::ESTADO_ENVIADO, '+5493411234567');
        $this->fakear_empresa();

        $servicio = app(AsistenteRecordatoriosService::class);

        $primera = $servicio->enviar_a($client);
        $this->assertStringContainsString(self::ID . '=turno_en_curso', $primera['detalle']);
        $this->assertCount(0, $espia->textos);
        $this->assertFalse(Cache::has($servicio->clave_de_reclamo($client, self::ID)));

        $turno->estado = ClientAssistantMessage::ESTADO_RESPONDIDO;
        $turno->save();

        $this->assertSame('enviado', $servicio->enviar_a($client)['estado']);
        $this->assertCount(1, $espia->textos);
    }

    /**
     * 🔴 Solo se consulta a los clientes activos, con el canal prendido, con clave y con teléfono.
     * Y SIN el filtro de actividad de los avisos: el que no le escribió nunca al asistente se consulta
     * igual (el recordatorio sale justamente cuando el dueño no está hablando).
     *
     * @return void
     */
    public function test_el_barrido_solo_consulta_a_los_clientes_que_pueden_recibirlo(): void
    {
        $this->espiar_sender();
        $this->crear_config_whatsapp();

        $sin_telefono = $this->crear_cliente('');
        $apagado      = $this->crear_cliente('+5493412222222', false);
        $sin_clave    = $this->crear_cliente('+5493413333333', true, '');
        $inactivo     = $this->crear_cliente('+5493415555555');
        $inactivo->is_active = false;
        $inactivo->save();

        /* Sin ningún mensaje al asistente, nunca. */
        $activo = $this->crear_cliente('+5493414444444');

        $this->fakear_http([
            '*/asistente/recordatorios-pendientes*' => Http::response(['recordatorios' => []], 200),
        ]);

        $resultados = app(AsistenteRecordatoriosService::class)->enviar_a_todos();

        $ids = array_map(function (array $fila) {
            return $fila['client_id'];
        }, $resultados);

        $this->assertContains((int) $activo->id, $ids, 'Sin actividad reciente se consulta igual.');
        $this->assertNotContains((int) $sin_telefono->id, $ids, 'Sin teléfono no hay a quién recordarle.');
        $this->assertNotContains((int) $apagado->id, $ids, 'Con el canal apagado no se consulta.');
        $this->assertNotContains((int) $sin_clave->id, $ids, 'Sin clave no se consulta.');
        $this->assertNotContains((int) $inactivo->id, $ids, 'Un cliente inactivo no se consulta.');

        $this->assertCount(1, $this->pedidos_a('/asistente/recordatorios-pendientes', 'GET'));
    }

    /**
     * Con el número del asistente listo, el recordatorio sale por ese número, y su ventana es la del
     * hilo del asistente: lo que el dueño le escribió al principal no la abre.
     *
     * @return void
     */
    public function test_con_el_numero_del_asistente_listo_sale_por_ahi_y_mide_su_ventana(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->crear_config_asistente();
        $client = $this->crear_cliente();
        $this->cargar_plantilla();

        /* Le escribió al asistente por el PRINCIPAL: la ventana del número del asistente sigue cerrada. */
        $this->fila_entrante($client, self::PHONE_NUMBER_ID_PRINCIPAL);
        $this->fakear_empresa();

        $servicio = app(AsistenteRecordatoriosService::class);
        $this->assertStringContainsString(self::ID . '=enviado_plantilla', $servicio->enviar_a($client)['detalle']);
        $this->assertSame([self::PHONE_NUMBER_ID_ASISTENTE], $espia->destinos);

        /* Otro recordatorio, ahora con un mensaje por el número del asistente: sale como texto. */
        $this->fila_entrante($client, self::PHONE_NUMBER_ID_ASISTENTE);
        $this->fakear_http([
            '*/asistente/recordatorios-pendientes*' => Http::response($this->pendientes(self::DETALLE, 77, 'recordatorio:58-1792150000:2026-10-16'), 200),
            '*/asistente/recordatorios/avisado'     => Http::response(['ok' => true], 200),
        ]);

        $this->assertStringContainsString('=enviado_texto', $servicio->enviar_a($client)['detalle']);
        $this->assertSame([self::PHONE_NUMBER_ID_ASISTENTE, self::PHONE_NUMBER_ID_ASISTENTE], $espia->destinos);
    }

    /**
     * 🔴 Tres rechazos de Meta y el recordatorio queda `fallido`: no se reintenta cada cinco minutos.
     *
     * @return void
     */
    public function test_tres_rechazos_de_meta_dejan_el_recordatorio_fallido(): void
    {
        $espia  = $this->espiar_sender(false);
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->fila_entrante($client);
        $this->fakear_empresa();

        $servicio = app(AsistenteRecordatoriosService::class);

        $this->assertStringContainsString(self::ID . '=error', $servicio->enviar_a($client)['detalle']);
        $this->assertStringContainsString(self::ID . '=error', $servicio->enviar_a($client)['detalle']);
        $this->assertStringContainsString(self::ID . '=fallido', $servicio->enviar_a($client)['detalle']);
        $this->assertStringContainsString(self::ID . '=fallido', $servicio->enviar_a($client)['detalle']);

        $this->assertCount(3, $espia->textos, 'Tres intentos y no más.');
        $this->assertCount(0, $this->pedidos_a('/asistente/recordatorios/avisado', 'POST'), 'Lo que no salió no se marca.');
    }

    /**
     * La variable de la plantilla: si el detalle no entra, se recorta el detalle y NO la hora; y un
     * recordatorio que no es de hoy no dice «hoy».
     *
     * @return void
     */
    public function test_la_variable_recorta_el_detalle_y_conserva_la_hora(): void
    {
        $servicio = app(AsistenteRecordatoriosService::class);

        $largo    = str_repeat('Revisar el pedido de la distribuidora ', 30);
        $variable = $servicio->armar_variable_de_plantilla([
            'detalle' => $largo,
            'texto'   => self::TEXTO,
            'fecha'   => now()->toDateString(),
            'hora'    => '10:00',
        ]);

        $this->assertLessThan(AsistenteRecordatoriosService::LARGO_MAXIMO_DE_VARIABLE, mb_strlen($variable));
        $this->assertStringEndsWith('… (hoy 10:00)', $variable);

        $ayer = now()->subDay();
        $this->assertSame(
            self::DETALLE . ' (' . $ayer->day . '/' . $ayer->month . ' 10:00)',
            $servicio->armar_variable_de_plantilla([
                'detalle' => self::DETALLE,
                'texto'   => self::TEXTO,
                'fecha'   => $ayer->toDateString(),
                'hora'    => '10:00',
            ])
        );

        /* Sin detalle, el texto aplanado: nunca una variable que diga solo la hora. */
        $sin_detalle = $servicio->armar_variable_de_plantilla([
            'detalle' => '',
            'texto'   => "Te recuerdo:\nllamar a Juan",
            'fecha'   => now()->toDateString(),
            'hora'    => '10:00',
        ]);
        $this->assertSame('Te recuerdo: llamar a Juan (hoy 10:00)', $sin_detalle);
    }

    /**
     * Las claves nuevas de la configuración: sin fila, sin plantilla y con el idioma por defecto.
     *
     * @return void
     */
    public function test_la_plantilla_de_recordatorios_arranca_sin_cargar(): void
    {
        $this->assertSame('', AsistenteWhatsappSettings::plantilla_de_recordatorios());
        $this->assertSame('es_AR', AsistenteWhatsappSettings::idioma_de_la_plantilla_de_recordatorios());
        $this->assertNull(AsistenteRecordatoriosService::plantilla_configurada());
    }

    /**
     * El comando con `--client` corre el servicio sobre ese cliente y termina bien.
     *
     * @return void
     */
    public function test_el_comando_manda_los_recordatorios_de_un_cliente(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->fila_entrante($client);
        $this->fakear_empresa();

        $codigo = Artisan::call('asistente:enviar-recordatorios', ['--client' => $client->id]);

        $this->assertSame(0, $codigo);
        $this->assertCount(1, $espia->textos);
        $this->assertStringContainsString('enviado', Artisan::output());
    }

    /**
     * El comando está agendado cada cinco minutos, sin superponerse, en segundo plano y sin heredar
     * el candado del cron.
     *
     * @return void
     */
    public function test_el_comando_esta_agendado_cada_cinco_minutos_sin_superponerse(): void
    {
        $schedule = app(Schedule::class);

        $eventos = array_values(array_filter($schedule->events(), function ($evento) {
            return strpos((string) $evento->command, 'asistente:enviar-recordatorios') !== false;
        }));

        $this->assertCount(1, $eventos, 'Tiene que haber exactamente un barrido de recordatorios agendado.');

        $evento = $eventos[0];
        $this->assertSame('*/5 * * * *', $evento->expression);
        $this->assertTrue($evento->withoutOverlapping);
        $this->assertSame(10, $evento->expiresAt, 'El candado vence a los 10 minutos, no a las 24 hs del default.');
        $this->assertTrue($evento->runInBackground);
        $this->assertStringStartsWith('exec 3>&- 4>&- 5>&- 6>&- 7>&- 8>&- 9>&-; ', $evento->command);
    }
}
