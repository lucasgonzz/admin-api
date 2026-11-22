<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Models\ClientTemplate;
use App\Services\AsistenteRecordatoriosService;
use App\Services\AsistenteWhatsappSettings;
use Illuminate\Support\Facades\Http;

/**
 * Las tareas programadas del asistente, vistas desde el admin: la entrega por WhatsApp.
 *
 * Misión cruzada agente-tareas-programadas (21/11/2026). El `empresa-api` expone el resultado de una
 * tarea programada como un ítem más de `recordatorios-pendientes` (el carril de los recordatorios de
 * la Agenda y de los avisos C2). El admin de hoy ya lo manda sin cambios: texto libre con la ventana
 * abierta, `cc_recordatorio_asistente` con el `detalle` con la ventana cerrada. Lo nuevo es OPCIONAL:
 * el ítem de tarea trae dos campos más, `plantilla = 'tarea_del_asistente'` y `resumen` («titulo»), y
 * si el admin tiene cargada una plantilla propia para tareas (`AdminSetting
 * asistente_tarea_template_name` + `ClientTemplate` activa) la usa con el resumen y la hora.
 *
 * Lo que estas pruebas cuidan, en orden de cuánto duele si se rompe:
 *
 *   1. **Compatibilidad hacia atrás**: una fila sin `plantilla`/`resumen` (empresa vieja) sale
 *      exactamente igual que hoy, aunque el admin tenga la plantilla de tareas cargada.
 *   2. **Respaldo**: sin la plantilla de tareas (o con una de las dos mitades) el ítem de tarea sale
 *      por la plantilla de recordatorios, que es la que siempre sirve.
 *   3. **Ventana abierta = texto libre**, nunca plantilla, haya o no plantilla de tareas.
 *   4. **La plantilla propia** (CA16): con la ventana cerrada y todo configurado, sale con el resumen.
 *
 * Todo lo que sale a la red está fakeado (`Http::fake()` del `BaseDelCanal` y el espía de envío).
 */
class TareasDelAsistenteTest extends BaseDelCanal
{
    /**
     * Id opaco del ítem de tarea: `recordatorio:tarea_asistente:{ejecucion_id}-{created_at unix}`.
     */
    const ID_TAREA = 'recordatorio:tarea_asistente:31-1795300000';

    /**
     * Id de un recordatorio de la Agenda de hoy (forma vieja).
     */
    const ID_VIEJO = 'recordatorio:57-1792150000:2026-11-21:1700';

    /**
     * Valor del campo `plantilla` que marca un ítem de tarea del asistente.
     */
    const MARCA_DE_TAREA = 'tarea_del_asistente';

    /**
     * Resumen de la tarea, tal como lo manda el `empresa-api`: «titulo» entre comillas angulares.
     */
    const RESUMEN = '«Preguntas de logística»';

    /**
     * Detalle redactado para la plantilla de recordatorios.
     */
    const DETALLE = 'que hoy te escriba por «Preguntas de logística». Ya te dejé el mensaje: respondeme por acá y seguimos';

    /**
     * Resultado del turno de la tarea (formato WhatsApp), lo que sale con la ventana abierta.
     */
    const TEXTO = "*Preguntas de logística*\nEsta semana salieron 14 pedidos y 2 quedaron demorados.";

    /**
     * Plantilla de Meta de los recordatorios (el respaldo de siempre).
     */
    const PLANTILLA_RECORDATORIO = 'cc_recordatorio_asistente';

    /**
     * Plantilla de Meta propia de las tareas del asistente.
     */
    const PLANTILLA_TAREA = 'cc_tarea_del_asistente';

    /**
     * Clave de `AdminSetting` con el nombre de la plantilla de tareas.
     *
     * Literal a propósito: la constante `AsistenteWhatsappSettings::KEY_TAREA_TEMPLATE_NAME` todavía no
     * existe y este archivo tiene que fallar por la conducta que falta, no por una constante ausente.
     */
    const CLAVE_PLANTILLA_TAREA = 'asistente_tarea_template_name';

    /**
     * Ítem de tarea del asistente, con los campos nuevos opcionales.
     *
     * @param string $id Id del ítem.
     *
     * @return array<string, mixed>
     */
    private function item_de_tarea(string $id = self::ID_TAREA): array
    {
        return [
            'id'                 => $id,
            'tarea_id'           => null,
            'fecha'              => now()->toDateString(),
            'hora'               => '17:00',
            'detalle'            => self::DETALLE,
            'texto'              => self::TEXTO,
            'ai_conversation_id' => 88,
            'ruta'               => '',
            'plantilla'          => self::MARCA_DE_TAREA,
            'resumen'            => self::RESUMEN,
        ];
    }

    /**
     * Recordatorio de la Agenda con la forma de hoy: sin `plantilla` ni `resumen`.
     *
     * @param string $id Id del recordatorio.
     *
     * @return array<string, mixed>
     */
    private function item_viejo(string $id = self::ID_VIEJO): array
    {
        return [
            'id'                 => $id,
            'tarea_id'           => 57,
            'fecha'              => now()->toDateString(),
            'hora'               => '17:00',
            'detalle'            => 'Llamar a Juan por el presupuesto',
            'texto'              => "⏰ Te recuerdo: *Llamar a Juan por el presupuesto* (hoy 17:00).",
            'ai_conversation_id' => 77,
            'ruta'               => '/agenda',
        ];
    }

    /**
     * Fakea la consulta de pendientes (con los ítems dados) y la marca.
     *
     * @param array<int, array<string, mixed>> $items Ítems que devuelve `recordatorios-pendientes`.
     *
     * @return void
     */
    private function fakear_empresa(array $items): void
    {
        $this->fakear_http([
            '*/asistente/recordatorios-pendientes*' => Http::response(['recordatorios' => $items], 200),
            '*/asistente/recordatorios/avisado'     => Http::response(['ok' => true], 200),
        ]);
    }

    /**
     * Fila entrante del dueño: abre la ventana de 24 hs del número por el que entró.
     *
     * @param Client $client Cliente.
     *
     * @return ClientAssistantMessage
     */
    private function abrir_ventana(Client $client): ClientAssistantMessage
    {
        $fila                      = new ClientAssistantMessage();
        $fila->client_id           = $client->id;
        $fila->telefono            = (string) $client->phone;
        $fila->phone_number_id     = self::PHONE_NUMBER_ID_PRINCIPAL;
        $fila->direccion           = ClientAssistantMessage::DIRECCION_ENTRANTE;
        $fila->whatsapp_message_id = 'wamid.ENTRANTE-TAREAS-1';
        $fila->tipo                = 'text';
        $fila->texto               = 'Gracias, quedo atento.';
        $fila->estado              = ClientAssistantMessage::ESTADO_RESPONDIDO;
        $fila->ai_conversation_id  = 88;
        $fila->save();

        return $fila;
    }

    /**
     * Carga una plantilla: el nombre en `AdminSetting` (si se pide) y su fila en `client_templates`.
     *
     * @param string $clave_setting Clave de `AdminSetting` donde va el nombre.
     * @param string $nombre        Nombre de la plantilla de Meta.
     * @param bool   $con_fila      Si se crea la fila en `client_templates`.
     *
     * @return void
     */
    private function cargar_plantilla(string $clave_setting, string $nombre, bool $con_fila = true): void
    {
        AdminSetting::set($clave_setting, $nombre);

        if (! $con_fila) {
            return;
        }

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
     * Carga la plantilla de recordatorios (el respaldo de siempre).
     *
     * @return void
     */
    private function cargar_plantilla_de_recordatorios(): void
    {
        $this->cargar_plantilla(
            AsistenteWhatsappSettings::KEY_RECORDATORIO_TEMPLATE_NAME,
            self::PLANTILLA_RECORDATORIO
        );
    }

    /**
     * Carga la plantilla propia de las tareas del asistente.
     *
     * @param bool $con_fila Si se crea la fila en `client_templates`.
     *
     * @return void
     */
    private function cargar_plantilla_de_tareas(bool $con_fila = true): void
    {
        $this->cargar_plantilla(self::CLAVE_PLANTILLA_TAREA, self::PLANTILLA_TAREA, $con_fila);
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
     * CA16: ventana cerrada, ítem de tarea y plantilla de tareas cargada (nombre + fila activa): sale
     * por `cc_tarea_del_asistente` con UNA variable, el resumen y la hora, y se marca con el wamid.
     *
     * 🔴 ROJO hoy: el servicio no conoce la plantilla de tareas y manda `cc_recordatorio_asistente`.
     *
     * @return void
     */
    public function test_usa_la_plantilla_de_tareas(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->cargar_plantilla_de_recordatorios();
        $this->cargar_plantilla_de_tareas();
        $this->fakear_empresa([$this->item_de_tarea()]);

        $resultado = app(AsistenteRecordatoriosService::class)->enviar_a($client);

        $this->assertSame('enviado', $resultado['estado']);
        $this->assertStringContainsString(self::ID_TAREA . '=enviado_plantilla', $resultado['detalle']);

        $this->assertCount(0, $espia->textos, 'Fuera de la ventana no se manda texto libre: Meta lo rechaza.');
        $this->assertCount(1, $espia->plantillas);
        $this->assertSame(self::PLANTILLA_TAREA, $espia->plantillas[0]['template_name']);
        $this->assertSame('es_AR', $espia->plantillas[0]['language_code']);
        $this->assertSame([self::RESUMEN . ' (hoy 17:00)'], $espia->plantillas[0]['variables']);

        /* La marca lleva el id del ítem y el wamid de la plantilla. */
        $marcas = $this->pedidos_a('/asistente/recordatorios/avisado', 'POST');
        $this->assertCount(1, $marcas);
        $this->assertSame(self::ID_TAREA, $marcas[0]['id']);
        $this->assertSame('wamid.plantilla.1', $marcas[0]['whatsapp_message_id']);
    }

    /**
     * CA16 (respaldo): ventana cerrada y ítem de tarea, pero SIN plantilla de tareas utilizable: sale
     * por `cc_recordatorio_asistente` con el `detalle` y la hora, igual que un recordatorio de hoy.
     * Se cubren los tres casos de «no hay plantilla de tareas»: nada cargado, sólo el nombre sin la fila
     * y el nombre con la fila inactiva.
     *
     * Compatibilidad: VERDE hoy, porque el servicio de hoy ignora los campos nuevos y usa siempre la
     * plantilla de recordatorios. Tiene que seguir verde con la implementación.
     *
     * @return void
     */
    public function test_sin_plantilla_de_tareas_usa_la_de_recordatorio(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->cargar_plantilla_de_recordatorios();

        $servicio = app(AsistenteRecordatoriosService::class);

        /* 1. Nada de tareas cargado. */
        $this->fakear_empresa([$this->item_de_tarea('recordatorio:tarea_asistente:31-1')]);
        $servicio->enviar_a($client);

        /* 2. Sólo el nombre, sin la fila en `client_templates`. */
        $this->cargar_plantilla_de_tareas(false);
        $this->fakear_empresa([$this->item_de_tarea('recordatorio:tarea_asistente:32-2')]);
        $servicio->enviar_a($client);

        /* 3. El nombre con la fila inactiva. */
        $this->cargar_plantilla_de_tareas(true);
        ClientTemplate::query()->where('template_name', self::PLANTILLA_TAREA)->update(['activa' => false]);
        $this->fakear_empresa([$this->item_de_tarea('recordatorio:tarea_asistente:33-3')]);
        $servicio->enviar_a($client);

        $this->assertCount(0, $espia->textos);
        $this->assertCount(3, $espia->plantillas, 'Cada ítem tiene que salir una vez, por el respaldo.');
        foreach ($espia->plantillas as $plantilla) {
            $this->assertSame(self::PLANTILLA_RECORDATORIO, $plantilla['template_name']);
            $this->assertSame([self::DETALLE . ' (hoy 17:00)'], $plantilla['variables']);
        }
    }

    /**
     * Ventana abierta: el ítem de tarea sale como TEXTO libre (el resultado del turno tal cual), nunca
     * por plantilla, aunque la plantilla de tareas esté cargada. Deja la fila saliente en la
     * conversación del turno y se marca con el wamid.
     *
     * Compatibilidad: VERDE hoy (con la ventana abierta el servicio manda `texto` sin mirar la
     * plantilla). Tiene que seguir verde con la implementación.
     *
     * @return void
     */
    public function test_ventana_abierta_manda_el_texto(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->cargar_plantilla_de_recordatorios();
        $this->cargar_plantilla_de_tareas();
        $this->abrir_ventana($client);
        $this->fakear_empresa([$this->item_de_tarea()]);

        $resultado = app(AsistenteRecordatoriosService::class)->enviar_a($client);

        $this->assertSame('enviado', $resultado['estado']);
        $this->assertStringContainsString(self::ID_TAREA . '=enviado_texto', $resultado['detalle']);

        $this->assertCount(0, $espia->plantillas, 'Con la ventana abierta no hace falta plantilla.');
        $this->assertCount(1, $espia->textos);
        $this->assertSame((string) $client->phone, $espia->textos[0]['to']);
        $this->assertSame(self::TEXTO, $espia->textos[0]['body']);

        $marcas = $this->pedidos_a('/asistente/recordatorios/avisado', 'POST');
        $this->assertCount(1, $marcas);
        $this->assertSame(self::ID_TAREA, $marcas[0]['id']);
        $this->assertSame('wamid.saliente.1', $marcas[0]['whatsapp_message_id']);

        $saliente = ClientAssistantMessage::query()
            ->where('client_id', $client->id)
            ->where('direccion', ClientAssistantMessage::DIRECCION_SALIENTE)
            ->first();

        $this->assertNotNull($saliente);
        $this->assertSame(88, (int) $saliente->ai_conversation_id);
        $this->assertSame(self::TEXTO, $saliente->texto);
    }

    /**
     * CA17: filas con la forma de hoy (sin `plantilla` ni `resumen`, una empresa vieja) salen
     * exactamente igual que antes, aunque el admin YA tenga cargada la plantilla de tareas: por
     * `cc_recordatorio_asistente`, con el `detalle` y la hora como única variable. Un ítem de tarea con
     * `plantilla` pero SIN `resumen` tampoco puede usar la plantilla de tareas (no tendría qué poner).
     *
     * Compatibilidad: VERDE hoy. Es el candado contra que la implementación use la plantilla de
     * tareas para todo lo que salga en ventana cerrada.
     *
     * @return void
     */
    public function test_filas_viejas_igual_que_hoy(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->cargar_plantilla_de_recordatorios();
        $this->cargar_plantilla_de_tareas();

        $servicio = app(AsistenteRecordatoriosService::class);

        /* Recordatorio de la Agenda, sin los campos nuevos. */
        $this->fakear_empresa([$this->item_viejo()]);
        $resultado = $servicio->enviar_a($client);

        $this->assertSame('enviado', $resultado['estado']);
        $this->assertStringContainsString(self::ID_VIEJO . '=enviado_plantilla', $resultado['detalle']);

        /* `fakear_http()` reinicia el registro de pedidos: la marca se lee antes de volver a fakear. */
        $marcas_del_viejo = $this->pedidos_a('/asistente/recordatorios/avisado', 'POST');

        /* Ítem con la marca de tarea pero sin resumen: tampoco usa la plantilla de tareas. */
        $sin_resumen = $this->item_de_tarea('recordatorio:tarea_asistente:40-9');
        unset($sin_resumen['resumen']);
        $this->fakear_empresa([$sin_resumen]);
        $servicio->enviar_a($client);
        $marcas_del_ultimo = $this->pedidos_a('/asistente/recordatorios/avisado', 'POST');

        $this->assertCount(0, $espia->textos);
        $this->assertCount(2, $espia->plantillas);

        $this->assertSame(self::PLANTILLA_RECORDATORIO, $espia->plantillas[0]['template_name']);
        $this->assertSame(['Llamar a Juan por el presupuesto (hoy 17:00)'], $espia->plantillas[0]['variables']);

        $this->assertSame(self::PLANTILLA_RECORDATORIO, $espia->plantillas[1]['template_name']);
        $this->assertSame([self::DETALLE . ' (hoy 17:00)'], $espia->plantillas[1]['variables']);

        /* Las dos marcas salieron con sus ids. */
        $this->assertCount(1, $marcas_del_viejo);
        $this->assertSame(self::ID_VIEJO, $marcas_del_viejo[0]['id']);
        $this->assertCount(1, $marcas_del_ultimo);
        $this->assertSame('recordatorio:tarea_asistente:40-9', $marcas_del_ultimo[0]['id']);
    }
}
