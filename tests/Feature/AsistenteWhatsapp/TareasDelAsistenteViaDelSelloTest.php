<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Models\ClientTemplate;
use App\Services\AsistenteRecordatoriosService;
use App\Services\AsistenteWhatsappSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * El campo `via` del sello (`POST …/asistente/recordatorios/avisado`).
 *
 * Misión agente-tareas-programadas, enmienda E1. El admin le dice a la `empresa-api` por dónde salió
 * el ítem: `texto` (ventana de 24 hs abierta, salió el texto completo) o `plantilla` (salió sólo una
 * plantilla, la de tareas o la de recordatorio). Es opcional y compatible hacia atrás: la
 * `empresa-api` vieja lo ignora. Vale para todo ítem del carril, sea o no una tarea.
 *
 * Todo lo que sale a la red está fakeado (`Http::fake()` del `BaseDelCanal` y el espía de envío).
 */
class TareasDelAsistenteViaDelSelloTest extends BaseDelCanal
{
    /** Id opaco de un ítem de tarea del asistente. */
    const ID_TAREA = 'recordatorio:tarea_asistente:31-1795300000';

    /** Id de un recordatorio común de la Agenda. */
    const ID_COMUN = 'recordatorio:57-1792150000:2026-11-21:1700';

    /** Plantilla de recordatorios (respaldo de siempre). */
    const PLANTILLA_RECORDATORIO = 'cc_recordatorio_asistente';

    /** Plantilla propia de las tareas. */
    const PLANTILLA_TAREA = 'cc_tarea_del_asistente';

    /**
     * Ítem de tarea del asistente.
     *
     * @return array<string, mixed>
     */
    private function item_de_tarea(): array
    {
        return [
            'id'                 => self::ID_TAREA,
            'tarea_id'           => null,
            'fecha'              => now()->toDateString(),
            'hora'               => '17:00',
            'detalle'            => 'que hoy te escriba por «Logística». Ya te dejé el mensaje',
            'texto'              => "*Logística*\nSalieron 14 pedidos.",
            'ai_conversation_id' => 88,
            'ruta'               => '',
            'plantilla'          => 'tarea_del_asistente',
            'resumen'            => '«Logística»',
        ];
    }

    /**
     * Recordatorio común de la Agenda (no es tarea).
     *
     * @return array<string, mixed>
     */
    private function item_comun(): array
    {
        return [
            'id'                 => self::ID_COMUN,
            'tarea_id'           => 57,
            'fecha'              => now()->toDateString(),
            'hora'               => '17:00',
            'detalle'            => 'Llamar a Juan por el presupuesto',
            'texto'              => '⏰ Te recuerdo: *Llamar a Juan por el presupuesto* (hoy 17:00).',
            'ai_conversation_id' => 77,
            'ruta'               => '/agenda',
        ];
    }

    /**
     * Fakea la consulta de pendientes y la marca (que puede ser una secuencia).
     *
     * @param array<int, array<string, mixed>> $items Ítems pendientes.
     * @param mixed                            $marca Respuesta de la marca, o null para un 200.
     *
     * @return void
     */
    private function fakear_empresa(array $items, $marca = null): void
    {
        $this->fakear_http([
            '*/asistente/recordatorios-pendientes*' => Http::response(['recordatorios' => $items], 200),
            '*/asistente/recordatorios/avisado'     => $marca !== null ? $marca : Http::response(['ok' => true], 200),
        ]);
    }

    /**
     * Fila entrante del dueño: abre la ventana de 24 hs.
     *
     * @param Client $client Cliente.
     *
     * @return void
     */
    private function abrir_ventana(Client $client): void
    {
        $fila                      = new ClientAssistantMessage();
        $fila->client_id           = $client->id;
        $fila->telefono            = (string) $client->phone;
        $fila->phone_number_id     = self::PHONE_NUMBER_ID_PRINCIPAL;
        $fila->direccion           = ClientAssistantMessage::DIRECCION_ENTRANTE;
        $fila->whatsapp_message_id = 'wamid.ENTRANTE-VIA-1';
        $fila->tipo                = 'text';
        $fila->texto               = 'Gracias, quedo atento.';
        $fila->estado              = ClientAssistantMessage::ESTADO_RESPONDIDO;
        $fila->ai_conversation_id  = 88;
        $fila->save();
    }

    /**
     * Carga una plantilla: el nombre en `AdminSetting` y su fila activa en `client_templates`.
     *
     * @param string $clave_setting Clave de `AdminSetting`.
     * @param string $nombre        Nombre de la plantilla de Meta.
     *
     * @return void
     */
    private function cargar_plantilla(string $clave_setting, string $nombre): void
    {
        AdminSetting::set($clave_setting, $nombre);

        $plantilla                = new ClientTemplate();
        $plantilla->template_name = $nombre;
        $plantilla->language_code = 'es_AR';
        $plantilla->categoria     = 'recordatorios';
        $plantilla->titulo        = 'Plantilla ' . $nombre;
        $plantilla->body_template = 'Te escribo por: {{1}}';
        $plantilla->activa        = true;
        $plantilla->save();
    }

    /**
     * Plantilla de recordatorios cargada.
     *
     * @return void
     */
    private function cargar_plantilla_de_recordatorios(): void
    {
        $this->cargar_plantilla(AsistenteWhatsappSettings::KEY_RECORDATORIO_TEMPLATE_NAME, self::PLANTILLA_RECORDATORIO);
    }

    /**
     * Plantilla de tareas cargada.
     *
     * @return void
     */
    private function cargar_plantilla_de_tareas(): void
    {
        $this->cargar_plantilla('asistente_tarea_template_name', self::PLANTILLA_TAREA);
    }

    /**
     * Sellos que salieron hacia la `empresa-api`.
     *
     * @return array<int, \Illuminate\Http\Client\Request>
     */
    private function sellos(): array
    {
        $pedidos = [];
        foreach (Http::recorded() as $par) {
            $request = $par[0];
            if ($request->method() === 'POST' && strpos($request->url(), '/asistente/recordatorios/avisado') !== false) {
                $pedidos[] = $request;
            }
        }

        return $pedidos;
    }

    /**
     * El `via` que llevó un sello, o null si no lo trajo.
     *
     * @param \Illuminate\Http\Client\Request $sello Pedido de marca.
     *
     * @return string|null
     */
    private function via_de($sello): ?string
    {
        $datos = $sello->data();

        return isset($datos['via']) ? (string) $datos['via'] : null;
    }

    /**
     * (a) Ventana abierta: el sello de una tarea lleva `via = texto`.
     *
     * @return void
     */
    public function test_ventana_abierta_el_sello_lleva_via_texto(): void
    {
        $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->cargar_plantilla_de_recordatorios();
        $this->cargar_plantilla_de_tareas();
        $this->abrir_ventana($client);
        $this->fakear_empresa([$this->item_de_tarea()]);

        app(AsistenteRecordatoriosService::class)->enviar_a($client);

        $sellos = $this->sellos();
        $this->assertCount(1, $sellos);
        $this->assertSame('texto', $this->via_de($sellos[0]));
        $this->assertSame('wamid.saliente.1', $sellos[0]['whatsapp_message_id']);
    }

    /**
     * (b) Ventana cerrada con plantilla de tareas: `via = plantilla`.
     *
     * @return void
     */
    public function test_ventana_cerrada_con_plantilla_de_tareas_lleva_via_plantilla(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->cargar_plantilla_de_recordatorios();
        $this->cargar_plantilla_de_tareas();
        $this->fakear_empresa([$this->item_de_tarea()]);

        app(AsistenteRecordatoriosService::class)->enviar_a($client);

        $this->assertSame(self::PLANTILLA_TAREA, $espia->plantillas[0]['template_name']);
        $sellos = $this->sellos();
        $this->assertCount(1, $sellos);
        $this->assertSame('plantilla', $this->via_de($sellos[0]));
    }

    /**
     * (c) Ventana cerrada, sin plantilla de tareas pero con la de recordatorio: `via = plantilla`.
     *
     * @return void
     */
    public function test_ventana_cerrada_con_plantilla_de_recordatorio_lleva_via_plantilla(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->cargar_plantilla_de_recordatorios();
        $this->fakear_empresa([$this->item_de_tarea()]);

        app(AsistenteRecordatoriosService::class)->enviar_a($client);

        $this->assertSame(self::PLANTILLA_RECORDATORIO, $espia->plantillas[0]['template_name']);
        $sellos = $this->sellos();
        $this->assertCount(1, $sellos);
        $this->assertSame('plantilla', $this->via_de($sellos[0]));
    }

    /**
     * (d) Si la marca falla, el reintento del sello lleva el MISMO `via` que el envío original.
     * Se prueba con las dos vías: texto (ventana abierta) y plantilla (cerrada).
     *
     * @return void
     */
    public function test_el_reintento_del_sello_repite_el_mismo_via(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->cargar_plantilla_de_recordatorios();
        $this->cargar_plantilla_de_tareas();

        $servicio = app(AsistenteRecordatoriosService::class);

        /* Ventana cerrada: sale por plantilla, la marca falla, el reintento marca sola. */
        $this->fakear_empresa([$this->item_de_tarea()], Http::sequence()
            ->push(['message' => 'Server Error'], 500)
            ->push(['ok' => true], 200));
        $servicio->enviar_a($client);
        $segunda = $servicio->enviar_a($client);
        $this->assertStringContainsString(self::ID_TAREA . '=remarcado', $segunda['detalle']);
        $this->assertCount(1, $espia->plantillas, 'No se le manda dos veces al dueño.');

        $sellos = $this->sellos();
        $this->assertCount(2, $sellos);
        $this->assertSame('plantilla', $this->via_de($sellos[0]));
        $this->assertSame('plantilla', $this->via_de($sellos[1]), 'El reintento lleva el mismo via que el envío original.');

        /* Ventana abierta: sale como texto, la marca falla, el reintento marca sola. */
        $this->abrir_ventana($client);
        $this->fakear_empresa([$this->item_comun()], Http::sequence()
            ->push(['message' => 'Server Error'], 500)
            ->push(['ok' => true], 200));
        $servicio->enviar_a($client);
        $servicio->enviar_a($client);

        $sellos = $this->sellos();
        $this->assertCount(2, $sellos);
        $this->assertSame('texto', $this->via_de($sellos[0]));
        $this->assertSame('texto', $this->via_de($sellos[1]));
    }

    /**
     * Un reclamo viejo en la caché (de antes del deploy) no trae `via`: el sello se reintenta sin el
     * campo, sin inventarlo.
     *
     * @return void
     */
    public function test_reclamo_viejo_sin_via_reintenta_el_sello_sin_el_campo(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->cargar_plantilla_de_recordatorios();
        $this->fakear_empresa([$this->item_comun()]);

        $servicio = app(AsistenteRecordatoriosService::class);
        Cache::put(
            $servicio->clave_de_reclamo($client, self::ID_COMUN),
            ['estado' => AsistenteRecordatoriosService::RECLAMO_ENVIADO, 'whatsapp_message_id' => 'wamid.VIEJO'],
            now()->addDay()
        );

        $resultado = $servicio->enviar_a($client);

        $this->assertStringContainsString(self::ID_COMUN . '=remarcado', $resultado['detalle']);
        $this->assertCount(0, $espia->textos);
        $this->assertCount(0, $espia->plantillas);
        $sellos = $this->sellos();
        $this->assertCount(1, $sellos);
        $this->assertSame('wamid.VIEJO', $sellos[0]['whatsapp_message_id']);
        $this->assertArrayNotHasKey('via', $sellos[0]->data());
    }

    /**
     * (e) Un recordatorio común (no tarea) también lleva `via`: texto con la ventana abierta y
     * plantilla con la cerrada.
     *
     * @return void
     */
    public function test_un_recordatorio_comun_tambien_lleva_via(): void
    {
        $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->cargar_plantilla_de_recordatorios();

        $servicio = app(AsistenteRecordatoriosService::class);

        /* Cerrada. */
        $this->fakear_empresa([$this->item_comun()]);
        $servicio->enviar_a($client);
        $sellos = $this->sellos();
        $this->assertCount(1, $sellos);
        $this->assertSame('plantilla', $this->via_de($sellos[0]));

        /* Abierta, con otro recordatorio. */
        $this->abrir_ventana($client);
        $otro       = $this->item_comun();
        $otro['id'] = 'recordatorio:58-1792150000:2026-11-21:1800';
        $this->fakear_empresa([$otro]);
        $servicio->enviar_a($client);
        $sellos = $this->sellos();
        $this->assertCount(1, $sellos);
        $this->assertSame('texto', $this->via_de($sellos[0]));
    }
}
