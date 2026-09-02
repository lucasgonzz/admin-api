<?php

namespace Tests\Feature;

use App\Events\AdminTaskNotificationCreated;
use App\Events\LeadSuggestionCreated;
use App\Models\Admin;
use App\Models\AdminTask;
use App\Models\AdminTaskNotification;
use App\Models\Lead;
use App\Support\BroadcastPayloadBudget;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El payload de los eventos que van por Pusher no puede depender del tamaño del modelo.
 *
 * Origen, medido en producción el 2/9/2026 sobre el lead Juan: la pantalla mostró
 * «No se pudo generar la sugerencia de Claude: Pusher error: The data content of this event
 * exceeds the allowed maximum (10240 bytes)» **con la sugerencia visible y completa en la misma
 * pantalla**. La sugerencia se había generado y persistido bien; lo que explotó fue el aviso que
 * viene después, y la excepción arrastró consigo el reporte de la operación.
 *
 * Este test fija las dos mitades del arreglo:
 *
 * 1. **El payload.** `LeadSuggestionCreated` mandaba el `Lead` entero —144 columnas, con
 *    `demo_plan`, `demo_summary_structured`, `call_summary` y `notes` adentro— más cinco
 *    relaciones. Un lead con la demo resuelta no entra en 10240 bytes. Acá se mide el payload
 *    viejo y el nuevo sobre el mismo lead.
 * 2. **El id siempre viaja.** Recortar el modelo solo sirve si el consumidor puede ir a buscarlo:
 *    `lead_id` / `notification_id` no se recortan nunca.
 *
 * 🔴 Lo que este test NO puede medir es el sobre que Pusher arma alrededor del payload (nombre
 * del evento, canales, y el payload serializado *adentro* de un string JSON, con cada comilla
 * escapada). Por eso el presupuesto es 9000 y no 10240: ver
 * {@see \App\Support\BroadcastPayloadBudget::PRESUPUESTO_BYTES}.
 */
class PayloadDeBroadcastBajoElLimiteDePusherTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Límite duro que impone Pusher Channels al body del evento HTTP.
     *
     * Es el número que aparece literal en el mensaje de error de producción.
     */
    const LIMITE_DURO_PUSHER = 10240;

    /**
     * Lead mínimo: solo lo que hace falta para que exista.
     *
     * @return Lead
     */
    private function crear_lead_pelado(): Lead
    {
        return Lead::create([
            'contact_name' => 'Juan de prueba',
            'phone'        => '54911' . random_int(1000000, 9999999),
            'status'       => 'calificado',
        ]);
    }

    /**
     * Carga el lead exactamente como lo carga `LeadSuggestionCreated::broadcastWith()`.
     *
     * Se repite la consulta a propósito en vez de llamar al evento: es la única forma de medir
     * la forma VIEJA del payload (`['lead' => $lead]`) sobre los mismos datos que mide la nueva.
     *
     * @param int $lead_id
     *
     * @return Lead|null
     */
    private function cargar_lead_como_el_evento(int $lead_id)
    {
        return Lead::query()
            ->where('id', $lead_id)
            ->with([
                'target_client',
                'promoted_client',
                'created_by_admin',
                'demo',
                'personalized_demo_videos',
            ])
            ->first();
    }

    /**
     * Llena el lead con el contenido que en producción lo vuelve pesado.
     *
     * No son campos inventados para hinchar el número: son las columnas grandes que tiene la
     * tabla `leads` (las únicas `text`/`json` que se llenan en el ciclo normal de una demo).
     * El `demo_plan` respeta la forma que congela
     * {@see \App\Services\DemoPlanResolver::resolver()} — secciones con sus clips —, que es lo
     * que hace que un lead con la demo resuelta pese varios KB por sí solo.
     *
     * @param Lead $lead
     *
     * @return void
     */
    private function cargar_el_lead_como_uno_con_la_demo_resuelta(Lead $lead): void
    {
        /* Plan de demo con la forma real: secciones, cada una con sus clips. */
        $secciones = [];
        for ($s = 0; $s < 8; $s++) {
            $clips = [];
            for ($c = 0; $c < 6; $c++) {
                $clips[] = [
                    'id'              => 'S' . $s . '.' . $c,
                    'titulo'          => 'Clip de demostración número ' . $c . ' de la sección ' . $s,
                    'archivo'         => 'demo/seccion-' . $s . '/clip-' . $c . '.mp4',
                    'orden'           => $c,
                    'origen'          => $c < 3 ? 'nucleo' : 'biblioteca',
                    'condicion'       => 'registra_compras=true',
                    'evento_esperado' => 'clip.seccion-' . $s . '.clip-' . $c . '.visto',
                ];
            }
            $secciones[] = [
                'id'    => 'S' . $s . ' - Sección de la demo',
                'orden' => $s,
                'clips' => $clips,
            ];
        }

        $lead->demo_plan = [
            'version_catalogo'      => 4,
            'resuelto_at'           => '2026-09-02 10:00:00',
            'respuestas'            => [
                'registra_compras'  => true,
                'usa_depositos'     => true,
                'tipo_precios'      => 'listas',
                'usa_produccion'    => false,
                'usa_cajas'         => true,
                'usa_codigos'       => true,
                'usa_imagenes'      => true,
                'iva_incluido'      => true,
                'cuentas_corrientes' => true,
            ],
            'secciones'             => $secciones,
            'condiciones_invalidas' => [],
            'totales'               => [
                'secciones'        => count($secciones),
                'clips_nucleo'     => 24,
                'clips_biblioteca' => 24,
            ],
        ];

        /* Resumen de la llamada del closer y notas del setter: texto libre, sin techo. */
        $lead->call_summary            = str_repeat('Resumen de la llamada con el closer. ', 40);
        $lead->demo_summary            = str_repeat('Resumen de lo que el lead recorrió en la demo. ', 30);
        $lead->demo_summary_structured = str_repeat('{"seccion":"ventas","visto":true},', 60);
        $lead->notes                   = str_repeat('Nota del setter sobre el lead. ', 40);

        $lead->save();
    }

    /**
     * El payload viejo de `LeadSuggestionCreated` no entraba en Pusher; el nuevo sí, siempre.
     *
     * @return void
     */
    public function test_el_payload_de_lead_suggestion_created_queda_bajo_el_presupuesto()
    {
        $lead = $this->crear_lead_pelado();

        /* ---- Lead pelado: el caso normal, donde el modelo tiene que seguir viajando ---- */
        $antes_pelado   = BroadcastPayloadBudget::medir(['lead' => $this->cargar_lead_como_el_evento((int) $lead->id)]);
        $payload_pelado = (new LeadSuggestionCreated((int) $lead->id))->broadcastWith();
        $despues_pelado = BroadcastPayloadBudget::medir($payload_pelado);

        /* ---- Lead con la demo resuelta: el caso que reventó en producción ---- */
        $this->cargar_el_lead_como_uno_con_la_demo_resuelta($lead);

        $antes_cargado   = BroadcastPayloadBudget::medir(['lead' => $this->cargar_lead_como_el_evento((int) $lead->id)]);
        $payload_cargado = (new LeadSuggestionCreated((int) $lead->id))->broadcastWith();
        $despues_cargado = BroadcastPayloadBudget::medir($payload_cargado);

        fwrite(STDERR, PHP_EOL . '  [medición] LeadSuggestionCreated, lead pelado:  antes=' . $antes_pelado
            . ' bytes / después=' . $despues_pelado . ' bytes' . PHP_EOL);
        fwrite(STDERR, '  [medición] LeadSuggestionCreated, lead cargado: antes=' . $antes_cargado
            . ' bytes / después=' . $despues_cargado . ' bytes' . PHP_EOL);

        /* El caso normal no cambia de conducta: el modelo sigue viajando (compatibilidad
         * hacia atrás con una admin-spa vieja, que solo sabe leer `lead`). */
        $this->assertArrayHasKey('lead', $payload_pelado, 'Un lead chico tiene que seguir viajando entero.');
        $this->assertSame((int) $lead->id, $payload_pelado['lead_id']);

        /* El caso que rompía: el payload viejo se pasaba del límite duro de Pusher. Si esta
         * aserción alguna vez falla, el defecto original dejó de ser reproducible con esta
         * carga y hay que revisar el fixture — NO bajarle la exigencia. */
        $this->assertGreaterThan(
            self::LIMITE_DURO_PUSHER,
            $antes_cargado,
            'El payload viejo tenía que superar los 10240 bytes de Pusher con un lead con la demo resuelta.'
        );

        /* Y el nuevo entra siempre, con el id adentro para que el consumidor pueda refrescar. */
        $this->assertLessThanOrEqual(BroadcastPayloadBudget::PRESUPUESTO_BYTES, $despues_cargado);
        $this->assertArrayNotHasKey('lead', $payload_cargado, 'Al no entrar, el modelo se recorta.');
        $this->assertSame((int) $lead->id, $payload_cargado['lead_id'], 'El id no se recorta nunca.');
    }

    /**
     * El presupuesto devuelve el payload intacto cuando entra, y sin la clave pesada cuando no.
     *
     * @return void
     */
    public function test_el_presupuesto_solo_recorta_la_clave_pesada()
    {
        $chico = BroadcastPayloadBudget::ajustar(
            ['lead_id' => 7, 'lead' => ['id' => 7, 'nombre' => 'Juan']],
            'lead',
            'TestChico'
        );

        $this->assertSame(['lead_id' => 7, 'lead' => ['id' => 7, 'nombre' => 'Juan']], $chico);

        $grande = BroadcastPayloadBudget::ajustar(
            ['lead_id' => 7, 'lead' => ['id' => 7, 'notas' => str_repeat('x', 12000)]],
            'lead',
            'TestGrande'
        );

        $this->assertSame(['lead_id' => 7], $grande);
    }

    /**
     * `AdminTaskNotificationCreated` también lleva su id siempre.
     *
     * @return void
     */
    public function test_admin_task_notification_created_lleva_siempre_el_id()
    {
        $admin = Admin::create([
            'name'     => 'Admin de prueba',
            'email'    => 'payload-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);

        $task = AdminTask::create([
            'title'               => 'Tarea de prueba',
            'content'             => 'Contenido de la tarea',
            'created_by_admin_id' => $admin->id,
        ]);

        $notification = AdminTaskNotification::create([
            'admin_task_id' => $task->id,
            'admin_id'      => $admin->id,
            'seen_at'       => null,
        ]);

        $payload = (new AdminTaskNotificationCreated((int) $notification->id))->broadcastWith();

        $this->assertSame((int) $notification->id, $payload['notification_id']);
        $this->assertArrayHasKey('notification', $payload, 'Una notificación chica viaja entera.');
    }
}
