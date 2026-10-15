<?php

namespace Tests\Feature\Pipelines;

use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\LeadMessage;
use App\Models\Pipeline;
use App\Models\PipelineActivity;
use App\Models\PipelineOpportunity;
use App\Models\PipelineStage;
use Database\Seeders\PipelineAgentesSeeder;
use Database\Seeders\PipelineLeadSeeder;

/**
 * La automatización del embudo "Leads" (misión embudo-lead-pipelines, 13/10/2026): que el tablero
 * de Tomás se llene y se mueva solo.
 *
 * Lo que se protege, y por qué cada cosa:
 *
 *  1. El alta: `closer_activo` crea la oportunidad en "Interesados", una sola vez.
 *  2. Los movimientos: `scheduled_at` → "Esperando la llamada"; `completada` → "En seguimiento".
 *  3. 🔴 EL CAMINO REAL. `CallSummaryService` pone la llamada en `completada` ANTES de pasar el lead
 *     a `closer_activo`. Si el alta no reconciliara el estado de las llamadas, toda oportunidad de
 *     ese camino —el principal— quedaría clavada en "Interesados" para siempre, sin ningún error
 *     que lo denunciara. Es el test más importante del archivo.
 *  4. 🔴 Que no mueva HACIA ATRÁS: una llamada nueva agendada no devuelve a un lead que ya está en
 *     seguimiento, y no le deshace a Tomás un movimiento manual más avanzado.
 *  5. 🔴 Que sobreviva a un RENOMBRE de etapa: Tomás edita las etapas desde Configuración, y buscar
 *     la etapa por nombre dejaría el embudo mudo al primer renombre.
 *  6. D3: la etapa perdida cierra el lead; la ganada NO le toca el estado.
 *  7. Que el pipeline "Agentes" quede intacto.
 *  8. Que la ficha traiga la videollamada y el tablero NO (`lead_calls.transcript` es un longText).
 *  9. Que sin el pipeline sembrado nada se rompa.
 */
class EmbudoDeLeadTest extends BaseDePipelines
{
    /**
     * Deja la base sin pipelines `lead` previos y siembra uno limpio. Todo adentro de la
     * transacción de la prueba.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->borrar_pipeline(PipelineLeadSeeder::SLUG);
    }

    /* ------------------------------------------------------------------------------------------
     | Ayudantes
     |----------------------------------------------------------------------------------------- */

    /**
     * Borra un pipeline por slug con todo lo que le cuelga.
     *
     * @param string $slug
     *
     * @return void
     */
    private function borrar_pipeline($slug)
    {
        $previos = Pipeline::query()->where('slug', $slug)->pluck('id')->all();
        if ($previos === []) {
            return;
        }

        $oportunidades = PipelineOpportunity::query()->whereIn('pipeline_id', $previos)->pluck('id')->all();
        PipelineActivity::query()->whereIn('opportunity_id', $oportunidades)->delete();
        PipelineOpportunity::query()->whereIn('id', $oportunidades)->delete();
        PipelineStage::query()->whereIn('pipeline_id', $previos)->delete();
        Pipeline::query()->whereIn('id', $previos)->delete();
    }

    /**
     * Siembra el embudo y lo devuelve.
     *
     * @return Pipeline
     */
    private function sembrar_embudo()
    {
        $this->seed(PipelineLeadSeeder::class);

        return Pipeline::query()->where('slug', PipelineLeadSeeder::SLUG)->firstOrFail();
    }

    /**
     * Un admin marcado como closer (el responsable que el embudo le pone a cada tarjeta).
     *
     * @return \App\Models\Admin
     */
    private function crear_closer()
    {
        $closer            = $this->crear_admin('Tomás');
        $closer->is_closer = true;
        $closer->save();

        return $closer;
    }

    /**
     * La oportunidad del lead en el embudo (la última), o null.
     *
     * @param Lead $lead
     *
     * @return PipelineOpportunity|null
     */
    private function oportunidad_del_lead(Lead $lead)
    {
        $pipeline = Pipeline::query()->where('slug', PipelineLeadSeeder::SLUG)->first();

        if ($pipeline === null) {
            return null;
        }

        return PipelineOpportunity::query()
            ->where('pipeline_id', $pipeline->id)
            ->where('lead_id', $lead->id)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * La clave de la etapa donde está parada la oportunidad del lead.
     *
     * @param Lead $lead
     *
     * @return string|null
     */
    private function clave_de_etapa(Lead $lead)
    {
        $oportunidad = $this->oportunidad_del_lead($lead);

        $this->assertNotNull($oportunidad, 'El lead no tiene oportunidad en el embudo.');

        return $oportunidad->fresh('stage')->stage->stage_key;
    }

    /**
     * Pasa el lead a `closer_activo`, que es el disparador del alta.
     *
     * @param Lead $lead
     *
     * @return void
     */
    private function confirmar_interes(Lead $lead)
    {
        $lead->status = 'closer_activo';
        $lead->save();
    }

    /* ------------------------------------------------------------------------------------------
     | 1. El alta
     |----------------------------------------------------------------------------------------- */

    /**
     * `closer_activo` da de alta la oportunidad en "Interesados", con el closer de responsable y la
     * actividad del historial.
     *
     * @return void
     */
    public function test_el_lead_que_confirma_interes_entra_en_interesados()
    {
        $closer = $this->crear_closer();
        $this->sembrar_embudo();

        $lead = $this->crear_lead();
        $this->confirmar_interes($lead);

        $oportunidad = $this->oportunidad_del_lead($lead);

        $this->assertNotNull($oportunidad, 'No nació la oportunidad al pasar a closer_activo.');
        $this->assertSame(PipelineLeadSeeder::ETAPA_INTERESADOS, $this->clave_de_etapa($lead));
        $this->assertSame((int) $closer->id, (int) $oportunidad->owner_admin_id, 'El responsable tiene que ser el closer.');
        $this->assertNull($oportunidad->created_by_admin_id, 'El alta no la hizo una persona: created_by_admin_id va null.');

        $actividad = PipelineActivity::query()
            ->where('opportunity_id', $oportunidad->id)
            ->where('type', PipelineActivity::TYPE_CREATED)
            ->first();

        $this->assertNotNull($actividad, 'El alta tiene que dejar su actividad en el historial.');
        $this->assertSame('Confirmó interés después de la demo', $actividad->body);
    }

    /**
     * Idempotente: pasar dos veces por `closer_activo` no crea dos tarjetas.
     *
     * @return void
     */
    public function test_pasar_dos_veces_por_closer_activo_no_duplica_la_oportunidad()
    {
        $this->crear_closer();
        $pipeline = $this->sembrar_embudo();

        $lead = $this->crear_lead();
        $this->confirmar_interes($lead);

        $lead->status = 'demo_realizada';
        $lead->save();
        $this->confirmar_interes($lead->fresh());

        $this->assertSame(
            1,
            PipelineOpportunity::query()->where('pipeline_id', $pipeline->id)->where('lead_id', $lead->id)->count(),
            'El embudo creó una segunda oportunidad para el mismo lead.'
        );
    }

    /**
     * Sin closer cargado la tarjeta nace sin responsable, y no se rompe nada.
     *
     * @return void
     */
    public function test_sin_closer_la_oportunidad_nace_sin_responsable()
    {
        $this->sembrar_embudo();

        $lead = $this->crear_lead();
        $this->confirmar_interes($lead);

        $oportunidad = $this->oportunidad_del_lead($lead);

        $this->assertNotNull($oportunidad);
        $this->assertNull($oportunidad->owner_admin_id);
    }

    /* ------------------------------------------------------------------------------------------
     | 2. Los movimientos
     |----------------------------------------------------------------------------------------- */

    /**
     * La llamada agendada mueve a "Esperando la llamada", carga la fecha en el campo de la etapa y
     * 🔴 NO fija próxima acción (eso le crearía a Tomás un segundo evento por la misma llamada).
     *
     * @return void
     */
    public function test_la_llamada_agendada_mueve_a_esperando_la_llamada()
    {
        $this->crear_closer();
        $this->sembrar_embudo();

        $lead = $this->crear_lead();
        $this->confirmar_interes($lead);

        LeadCall::create([
            'lead_id'      => $lead->id,
            'estado'       => 'pendiente',
            'scheduled_at' => '2026-09-28 15:00:00',
            'meet_url'     => 'https://meet.google.com/abc-defg-hij',
        ]);

        $this->assertSame(PipelineLeadSeeder::ETAPA_ESPERANDO_LLAMADA, $this->clave_de_etapa($lead));

        $oportunidad = $this->oportunidad_del_lead($lead);

        $this->assertNull(
            $oportunidad->next_action_at,
            'La fecha de la videollamada no fija próxima acción: duplicaría el evento del calendario del closer.'
        );

        $actividad = PipelineActivity::query()
            ->where('opportunity_id', $oportunidad->id)
            ->where('type', PipelineActivity::TYPE_STAGE_CHANGE)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($actividad);
        $this->assertStringContainsString('agendó la videollamada', (string) $actividad->body);
        $this->assertStringContainsString('meet.google.com', (string) $actividad->body, 'El link del Meet va en la actividad.');
    }

    /**
     * La llamada terminada mueve a "En seguimiento".
     *
     * @return void
     */
    public function test_la_llamada_terminada_mueve_a_en_seguimiento()
    {
        $this->crear_closer();
        $this->sembrar_embudo();

        $lead = $this->crear_lead();
        $this->confirmar_interes($lead);

        $llamada = LeadCall::create([
            'lead_id'      => $lead->id,
            'estado'       => 'pendiente',
            'scheduled_at' => '2026-09-28 15:00:00',
        ]);

        $this->assertSame(PipelineLeadSeeder::ETAPA_ESPERANDO_LLAMADA, $this->clave_de_etapa($lead));

        $llamada->update(['estado' => 'completada', 'started_at' => '2026-09-28 15:02:00']);

        $this->assertSame(PipelineLeadSeeder::ETAPA_EN_SEGUIMIENTO, $this->clave_de_etapa($lead));
    }

    /**
     * 🔴 EL CAMINO REAL de `CallSummaryService`: la llamada queda `completada` ANTES de que el lead
     * pase a `closer_activo`. La oportunidad nace y tiene que quedar en "En seguimiento", no en
     * "Interesados".
     *
     * @return void
     */
    public function test_la_llamada_ya_completada_antes_del_closer_activo_deja_la_oportunidad_en_seguimiento()
    {
        $this->crear_closer();
        $this->sembrar_embudo();

        $lead = $this->crear_lead();

        /* Exactamente el orden de CallSummaryService::process_transcript_for_call(): primero la
           llamada, después el estado del lead. */
        LeadCall::create([
            'lead_id'      => $lead->id,
            'estado'       => 'completada',
            'scheduled_at' => '2026-09-27 09:00:00',
            'started_at'   => '2026-09-27 09:01:00',
            'call_summary' => ['resumen_general' => 'Le cerró el precio.'],
            'transcript'   => "Tomás: hola\nLead: hola",
        ]);

        $this->assertNull($this->oportunidad_del_lead($lead), 'Sin closer_activo todavía no hay tarjeta.');

        $this->confirmar_interes($lead);

        $this->assertSame(PipelineLeadSeeder::ETAPA_EN_SEGUIMIENTO, $this->clave_de_etapa($lead));
    }

    /**
     * 🔴 No mueve hacia atrás, y esto hay que probarlo con un movimiento MANUAL de Tomás, no con una
     * segunda llamada: con una llamada completada adentro la regla 1 ya devuelve "En seguimiento" y
     * la guarda de `sort_order` no se ejercita.
     *
     * Acá Tomás adelanta la tarjeta a mano a "En seguimiento" con la llamada todavía sin hacer, y
     * recién entonces aparece la fecha de la videollamada. Sin la guarda, el embudo se la devolvería
     * a "Esperando la llamada" y le desharía el movimiento.
     *
     * @return void
     */
    public function test_no_le_deshace_a_tomas_un_movimiento_manual_mas_avanzado()
    {
        $this->admin_logueado();
        $this->crear_closer();
        $pipeline = $this->sembrar_embudo();

        $lead = $this->crear_lead();
        $this->confirmar_interes($lead);

        $oportunidad = $this->oportunidad_del_lead($lead);
        $adelante    = $this->etapa($pipeline, 'En seguimiento');

        $this->mover($oportunidad->id, ['stage_id' => $adelante->id])->assertStatus(200);
        $this->assertSame(PipelineLeadSeeder::ETAPA_EN_SEGUIMIENTO, $this->clave_de_etapa($lead));

        LeadCall::create(['lead_id' => $lead->id, 'estado' => 'pendiente', 'scheduled_at' => '2026-09-30 11:00:00']);

        $this->assertSame(
            PipelineLeadSeeder::ETAPA_EN_SEGUIMIENTO,
            $this->clave_de_etapa($lead),
            'La fecha de la videollamada no puede devolver a una etapa anterior lo que Tomás ya adelantó.'
        );
    }

    /**
     * Con una llamada ya completada, una segunda llamada agendada tampoco retrocede la tarjeta: acá
     * lo que manda es la regla 1 (alguna completada → "En seguimiento"), no la guarda de orden.
     *
     * @return void
     */
    public function test_una_segunda_llamada_agendada_no_retrocede_la_tarjeta()
    {
        $this->crear_closer();
        $this->sembrar_embudo();

        $lead = $this->crear_lead();
        $this->confirmar_interes($lead);

        LeadCall::create(['lead_id' => $lead->id, 'estado' => 'completada', 'started_at' => '2026-09-27 09:00:00']);

        $this->assertSame(PipelineLeadSeeder::ETAPA_EN_SEGUIMIENTO, $this->clave_de_etapa($lead));

        LeadCall::create(['lead_id' => $lead->id, 'estado' => 'pendiente', 'scheduled_at' => '2026-09-30 11:00:00']);

        $this->assertSame(
            PipelineLeadSeeder::ETAPA_EN_SEGUIMIENTO,
            $this->clave_de_etapa($lead),
            'Una segunda llamada agendada no tiene que devolver la tarjeta a una etapa anterior.'
        );
    }

    /**
     * 🔴 Sobrevive a un renombre: la etapa se busca por `stage_key`, no por nombre.
     *
     * @return void
     */
    public function test_sigue_moviendo_con_la_etapa_renombrada()
    {
        $this->crear_closer();
        $pipeline = $this->sembrar_embudo();

        $etapa       = $this->etapa($pipeline, 'Esperando la llamada');
        $etapa->name = 'Agendada con Tomás';
        $etapa->save();

        $lead = $this->crear_lead();
        $this->confirmar_interes($lead);

        LeadCall::create(['lead_id' => $lead->id, 'estado' => 'pendiente', 'scheduled_at' => '2026-09-28 15:00:00']);

        $this->assertSame(
            PipelineLeadSeeder::ETAPA_ESPERANDO_LLAMADA,
            $this->clave_de_etapa($lead),
            'Renombrar la etapa no puede dejar muda la automatización.'
        );
    }

    /**
     * Si la etapa destino no existe (Tomás la borró), no mueve y no rompe nada.
     *
     * @return void
     */
    public function test_sin_la_etapa_destino_no_mueve_y_no_rompe()
    {
        $this->crear_closer();
        $pipeline = $this->sembrar_embudo();

        $lead = $this->crear_lead();
        $this->confirmar_interes($lead);

        PipelineStage::query()
            ->where('pipeline_id', $pipeline->id)
            ->where('stage_key', PipelineLeadSeeder::ETAPA_ESPERANDO_LLAMADA)
            ->delete();

        LeadCall::create(['lead_id' => $lead->id, 'estado' => 'pendiente', 'scheduled_at' => '2026-09-28 15:00:00']);

        $this->assertSame(PipelineLeadSeeder::ETAPA_INTERESADOS, $this->clave_de_etapa($lead));
    }

    /* ------------------------------------------------------------------------------------------
     | 3. Decisión D3 — la etapa cerrada y `leads.status`
     |----------------------------------------------------------------------------------------- */

    /**
     * "No avanza" cierra el lead: `cerrado_perdido`, `claude_auto_reply` apagado, los flags de
     * seguimiento apagados y el evento en la conversación.
     *
     * @return void
     */
    public function test_la_etapa_perdida_cierra_el_lead()
    {
        $this->admin_logueado();
        $this->crear_closer();
        $pipeline = $this->sembrar_embudo();

        $lead = $this->crear_lead([
            'claude_auto_reply'          => true,
            'requiere_seguimiento'       => true,
            'tiene_sugerencia_pendiente' => true,
            'tiene_seguimiento_sin_ver'  => true,
        ]);
        $this->confirmar_interes($lead);

        $oportunidad = $this->oportunidad_del_lead($lead);
        $perdida     = $this->etapa($pipeline, 'No avanza');

        $this->mover($oportunidad->id, [
            'stage_id'    => $perdida->id,
            'lost_reason' => 'Precio',
        ])->assertStatus(200);

        $lead = $lead->fresh();

        $this->assertSame('cerrado_perdido', $lead->status);
        $this->assertFalse((bool) $lead->claude_auto_reply, 'El embudo lo dio por perdido: el agente no sigue contestando solo.');
        $this->assertFalse((bool) $lead->requiere_seguimiento);
        $this->assertFalse((bool) $lead->tiene_sugerencia_pendiente);
        $this->assertFalse((bool) $lead->tiene_seguimiento_sin_ver);
        $this->assertNull($lead->pendiente_revision_at);

        $evento = LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('is_status_event', true)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($evento, 'El cierre tiene que quedar en la conversación como evento de estado.');
        $this->assertStringContainsString('No avanza', (string) $evento->content);
        $this->assertStringContainsString('Precio', (string) $evento->content, 'El motivo de pérdida va en el evento.');
    }

    /**
     * "Cerrado" (ganada) NO toca `leads.status`: el lead lo cierra la promoción a cliente.
     *
     * @return void
     */
    public function test_la_etapa_ganada_no_toca_el_estado_del_lead()
    {
        $this->admin_logueado();
        $this->crear_closer();
        $pipeline = $this->sembrar_embudo();

        $lead = $this->crear_lead();
        $this->confirmar_interes($lead);

        $oportunidad = $this->oportunidad_del_lead($lead);
        $ganada      = $this->etapa($pipeline, 'Cerrado');

        $this->mover($oportunidad->id, ['stage_id' => $ganada->id])->assertStatus(200);

        $this->assertSame('closer_activo', $lead->fresh()->status, 'La etapa ganada no cierra el lead: eso lo hace la promoción a cliente.');
    }

    /**
     * 🔴 El pipeline "Agentes" no se toca: perder una oportunidad de lead AHÍ no cierra el lead.
     *
     * @return void
     */
    public function test_la_etapa_perdida_de_otro_pipeline_no_cierra_el_lead()
    {
        $this->admin_logueado();
        $this->borrar_pipeline(PipelineAgentesSeeder::SLUG);
        $this->seed(PipelineAgentesSeeder::class);

        $agentes = Pipeline::query()->where('slug', PipelineAgentesSeeder::SLUG)->firstOrFail();
        $lead    = $this->crear_lead(['status' => 'demo_realizada', 'claude_auto_reply' => true]);

        $creada  = $this->alta_de_uno($agentes, $lead);
        $perdida = $this->etapa($agentes, 'Perdido');

        $this->mover($creada['id'], ['stage_id' => $perdida->id, 'lost_reason' => 'Precio'])->assertStatus(200);

        $lead = $lead->fresh();

        $this->assertSame('demo_realizada', $lead->status, 'Mover una tarjeta de "Agentes" no puede cerrar un lead.');
        $this->assertTrue((bool) $lead->claude_auto_reply);
    }

    /* ------------------------------------------------------------------------------------------
     | 4. La ficha
     |----------------------------------------------------------------------------------------- */

    /**
     * La ficha trae la videollamada (resumen y transcripción) y el tablero NO.
     *
     * 🔴 Que el tablero no la traiga no es cosmético: `lead_calls.transcript` es un longText, y una
     * columna así multiplicada por las tarjetas de un tablero es exactamente cómo un listado se
     * vuelve lento sin que nadie lo vea venir.
     *
     * @return void
     */
    public function test_la_ficha_trae_la_videollamada_y_el_tablero_no()
    {
        $this->admin_logueado();
        $this->crear_closer();
        $pipeline = $this->sembrar_embudo();

        $lead = $this->crear_lead();
        $this->confirmar_interes($lead);

        LeadCall::create([
            'lead_id'      => $lead->id,
            'estado'       => 'completada',
            'scheduled_at' => '2026-09-27 09:00:00',
            'started_at'   => '2026-09-27 09:01:00',
            'meet_url'     => 'https://meet.google.com/abc-defg-hij',
            'call_summary' => ['resumen_general' => 'Le cerró el precio.', 'escenario_cierre' => 'A'],
            'transcript'   => "Tomás: hola\nLead: hola",
        ]);

        $oportunidad = $this->oportunidad_del_lead($lead);

        $ficha = $this->getJson('/api/admin/pipeline-opportunities/' . $oportunidad->id);
        $ficha->assertStatus(200);

        $this->assertSame('completada', $ficha->json('opportunity.call.estado'));
        $this->assertSame('2026-09-27 09:00:00', $ficha->json('opportunity.call.scheduled_at'));
        $this->assertSame('https://meet.google.com/abc-defg-hij', $ficha->json('opportunity.call.meet_url'));
        $this->assertSame('Le cerró el precio.', $ficha->json('opportunity.call.call_summary.resumen_general'));
        $this->assertStringContainsString('Lead: hola', (string) $ficha->json('opportunity.call.transcript'));

        $tablero = $this->getJson('/api/admin/pipelines/' . $pipeline->id . '/opportunities');
        $tablero->assertStatus(200);

        $tarjetas = $tablero->json('opportunities');
        $this->assertNotEmpty($tarjetas, 'El tablero tendría que devolver la tarjeta.');

        foreach ($tarjetas as $tarjeta) {
            $this->assertArrayNotHasKey('call', $tarjeta, 'El tablero no puede traer la transcripción de la llamada.');
        }
    }

    /**
     * Para un sujeto CLIENTE la ficha trae `call` en null (un cliente no tiene `lead_calls`).
     *
     * @return void
     */
    public function test_la_ficha_de_un_cliente_trae_la_videollamada_en_null()
    {
        $this->admin_logueado();
        $pipeline = $this->sembrar_embudo();

        $cliente = $this->crear_cliente();
        $creada  = $this->alta_de_uno($pipeline, $cliente);

        $ficha = $this->getJson('/api/admin/pipeline-opportunities/' . $creada['id']);

        $ficha->assertStatus(200);
        $this->assertNull($ficha->json('opportunity.call'));
    }

    /* ------------------------------------------------------------------------------------------
     | 5. Sin el pipeline sembrado
     |----------------------------------------------------------------------------------------- */

    /**
     * Sin el pipeline `lead` (base recién creada, o producción antes de que corra el seeder), ni el
     * cambio de estado ni la llamada rompen nada.
     *
     * @return void
     */
    public function test_sin_el_pipeline_sembrado_no_pasa_nada()
    {
        $this->crear_closer();

        $lead = $this->crear_lead();
        $this->confirmar_interes($lead);

        LeadCall::create(['lead_id' => $lead->id, 'estado' => 'completada', 'started_at' => '2026-09-27 09:00:00']);

        $this->assertSame('closer_activo', $lead->fresh()->status);
        $this->assertNull($this->oportunidad_del_lead($lead));
    }

    /**
     * El `slug` del pipeline viaja en la API: es con eso que la SPA puede linkear `?pipeline=lead`
     * sin conocer el id que le tocó en cada base.
     *
     * @return void
     */
    public function test_la_api_publica_el_slug_del_pipeline()
    {
        $this->admin_logueado();
        $this->sembrar_embudo();

        $respuesta = $this->getJson('/api/admin/pipelines');
        $respuesta->assertStatus(200);

        $slugs = array_column($respuesta->json('pipelines'), 'slug');

        $this->assertContains(PipelineLeadSeeder::SLUG, $slugs);
    }
}
