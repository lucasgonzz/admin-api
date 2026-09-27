<?php

namespace Tests\Feature\Pipelines;

use App\Models\PipelineActivity;
use App\Models\PipelineOpportunity;

/**
 * El alta masiva de clientes y leads a un pipeline (endpoint #12), tal cual la manda el
 * `AddModal` de la SPA.
 *
 * Lo que se protege:
 *  1. 🔴 Una sola oportunidad abierta por sujeto y pipeline: el que ya está se saltea con
 *     `already_open` (también el segundo de un sujeto repetido en el mismo pedido).
 *  2. Que después de cerrar se pueda volver a agregar (las cerradas son historia).
 *  3. La etapa inicial (primera abierta o la elegida, que tiene que ser abierta y de ese pipeline)
 *     y el responsable (el logueado si la clave no viene; nadie si viene en null).
 *  4. Que cada alta deje su actividad `created` con la nota.
 */
class AltaMasivaTest extends BaseDePipelines
{
    /**
     * 1. Clientes y leads en el mismo pedido: primera etapa abierta, el admin logueado de
     *    responsable, la forma Opportunity del contrato y la actividad `created` con la nota.
     *
     * @return void
     */
    public function test_clientes_y_leads_juntos_en_la_primera_abierta_con_el_logueado_de_responsable(): void
    {
        $admin    = $this->admin_logueado('Lucas');
        $pipeline = $this->crear_pipeline();
        $cliente  = $this->crear_cliente();
        $lead     = $this->crear_lead(['status' => 'contactado']);

        $respuesta = $this->alta($pipeline, [
            ['type' => 'client', 'id' => $cliente->id],
            ['type' => 'lead', 'id' => $lead->id],
        ], ['note' => 'Campaña de septiembre']);

        $respuesta->assertStatus(201);
        $this->assertSame([], $respuesta->json('skipped'));
        $this->assertCount(2, $respuesta->json('created'));

        $del_cliente = $respuesta->json('created.0');
        $inicial     = $this->etapa($pipeline, 'Por contactar');

        $this->assertSame($pipeline->id, $del_cliente['pipeline_id']);
        $this->assertSame($inicial->id, $del_cliente['stage_id']);
        $this->assertSame(
            ['id' => $inicial->id, 'name' => 'Por contactar', 'color' => '#adb5bd', 'type' => 'open', 'sort_order' => 0],
            $del_cliente['stage']
        );
        $this->assertSame('client', $del_cliente['subject_type']);
        $this->assertSame($cliente->id, $del_cliente['client_id']);
        $this->assertNull($del_cliente['lead_id']);
        $this->assertSame(['id' => $admin->id, 'name' => 'Lucas'], $del_cliente['owner']);
        $this->assertSame($admin->id, $del_cliente['owner_admin_id']);
        $this->assertNull($del_cliente['next_action_at']);
        $this->assertSame('none', $del_cliente['agenda_bucket']);
        $this->assertSame('2026-09-27 10:00:00', $del_cliente['stage_entered_at']);
        $this->assertSame(0, $del_cliente['days_in_stage']);
        $this->assertNull($del_cliente['closed_at']);
        $this->assertSame('2026-09-27 10:00:00', $del_cliente['created_at']);

        /* Sujeto cliente: nombre, razón social, activo; sin contacto fuera de la ficha. */
        $this->assertSame($cliente->name, $del_cliente['subject']['name']);
        $this->assertSame($cliente->company_name, $del_cliente['subject']['secondary']);
        $this->assertTrue($del_cliente['subject']['is_active']);
        $this->assertNull($del_cliente['subject']['phone']);
        $this->assertNull($del_cliente['subject']['email']);
        $this->assertFalse($del_cliente['subject']['missing']);

        /* Sujeto lead: empresa como nombre, contacto como secundario, estado con su etiqueta. */
        $del_lead = $respuesta->json('created.1');
        $this->assertSame('lead', $del_lead['subject_type']);
        $this->assertSame($lead->company_name, $del_lead['subject']['name']);
        $this->assertSame($lead->contact_name, $del_lead['subject']['secondary']);
        $this->assertSame('contactado', $del_lead['subject']['status']);
        $this->assertSame('Contactado', $del_lead['subject']['status_label']);

        /* La última actividad es el alta, con la nota. */
        $this->assertSame('created', $del_cliente['last_activity']['type']);
        $this->assertSame('Campaña de septiembre', $del_cliente['last_activity']['body']);
        $this->assertSame('Lucas', $del_cliente['last_activity']['admin_name']);
        $this->assertSame('2026-09-27 10:00:00', $del_cliente['last_activity']['occurred_at']);

        $actividad = PipelineActivity::query()->where('opportunity_id', $del_cliente['id'])->first();
        $this->assertSame(PipelineActivity::TYPE_CREATED, $actividad->type);
        $this->assertSame($inicial->id, $actividad->to_stage_id);
        $this->assertSame('Por contactar', $actividad->to_stage_name);
        $this->assertNull($actividad->from_stage_id);
        $this->assertSame([], $actividad->data);
        $this->assertSame($admin->id, $actividad->admin_id);
    }

    /**
     * 2. Etapa inicial explícita: tiene que ser una abierta de ESTE pipeline. Si no, 422 y no se
     *    crea nada. Desde la ronda de arreglos R2 el alta manda también los campos de esa etapa
     *    (en el pipeline de prueba "Contactado" pide `canal` obligatorio), y la foto queda en la
     *    actividad `created`.
     *
     * @return void
     */
    public function test_etapa_inicial_explicita_y_422_si_no_es_abierta_o_es_de_otro_pipeline(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $otro     = $this->crear_pipeline();
        $cliente  = $this->crear_cliente();

        $contactado = $this->etapa($pipeline, 'Contactado');
        $creada     = $this->alta_de_uno($pipeline, $cliente, [
            'stage_id' => $contactado->id,
            'fields'   => ['canal' => 'WhatsApp'],
        ]);
        $this->assertSame($contactado->id, $creada['stage_id']);

        $alta = PipelineActivity::query()->where('opportunity_id', $creada['id'])->first();
        $this->assertSame('Contactado', $alta->to_stage_name);
        $this->assertEquals([['key' => 'canal', 'label' => 'Canal', 'type' => 'select', 'value' => 'WhatsApp']], $alta->data);

        $otro_cliente = $this->crear_cliente();

        $ganada = $this->alta($pipeline, [['type' => 'client', 'id' => $otro_cliente->id]], ['stage_id' => $this->etapa($pipeline, 'Ganado')->id]);
        $ganada->assertStatus(422);
        $this->assertSame('La etapa inicial tiene que ser una etapa abierta de este pipeline.', $ganada->json('message'));

        $ajena = $this->alta($pipeline, [['type' => 'client', 'id' => $otro_cliente->id]], ['stage_id' => $this->etapa($otro, 'Por contactar')->id]);
        $ajena->assertStatus(422);

        $this->assertSame(0, PipelineOpportunity::query()->where('client_id', $otro_cliente->id)->count(), 'Un 422 no deja oportunidades creadas.');
    }

    /**
     * 3. Responsable: la clave en null deja sin responsable; un id explícito lo asigna; un id que
     *    no existe es 422.
     *
     * @return void
     */
    public function test_responsable_null_explicito_o_inexistente(): void
    {
        $this->admin_logueado('Lucas');
        $thomas   = $this->crear_admin('Thomas');
        $pipeline = $this->crear_pipeline();

        $sin = $this->alta_de_uno($pipeline, $this->crear_cliente(), ['owner_admin_id' => null]);
        $this->assertNull($sin['owner_admin_id']);
        $this->assertNull($sin['owner']);

        $con = $this->alta_de_uno($pipeline, $this->crear_cliente(), ['owner_admin_id' => $thomas->id]);
        $this->assertSame(['id' => $thomas->id, 'name' => 'Thomas'], $con['owner']);

        $inexistente = $this->alta($pipeline, [['type' => 'client', 'id' => $this->crear_cliente()->id]], ['owner_admin_id' => 999999999]);
        $inexistente->assertStatus(422);
        $this->assertArrayHasKey('owner_admin_id', $inexistente->json('errors'));
    }

    /**
     * 4. 🔴 `skipped`: el que ya tiene una abierta (`already_open`), el que no existe
     *    (`not_found`) y el repetido en el mismo pedido (el segundo, `already_open`).
     *
     * @return void
     */
    public function test_salteados_already_open_y_not_found(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $ya_esta  = $this->crear_cliente();
        $nuevo    = $this->crear_cliente();
        $lead     = $this->crear_lead();

        $this->alta_de_uno($pipeline, $ya_esta);

        $respuesta = $this->alta($pipeline, [
            ['type' => 'client', 'id' => $ya_esta->id],
            ['type' => 'client', 'id' => 999999999],
            ['type' => 'lead', 'id' => 999999998],
            ['type' => 'client', 'id' => $nuevo->id],
            ['type' => 'client', 'id' => $nuevo->id],
            ['type' => 'lead', 'id' => $lead->id],
        ]);

        $respuesta->assertStatus(201);
        $this->assertSame([$nuevo->id, null], [$respuesta->json('created.0.client_id'), $respuesta->json('created.0.lead_id')]);
        $this->assertSame($lead->id, $respuesta->json('created.1.lead_id'));
        $this->assertCount(2, $respuesta->json('created'));

        $this->assertSame([
            ['type' => 'client', 'id' => $ya_esta->id, 'reason' => 'already_open'],
            ['type' => 'client', 'id' => 999999999, 'reason' => 'not_found'],
            ['type' => 'lead', 'id' => 999999998, 'reason' => 'not_found'],
            ['type' => 'client', 'id' => $nuevo->id, 'reason' => 'already_open'],
        ], $respuesta->json('skipped'));

        $this->assertSame(1, PipelineOpportunity::query()->where('pipeline_id', $pipeline->id)->where('client_id', $ya_esta->id)->count());
        $this->assertSame(1, PipelineOpportunity::query()->where('pipeline_id', $pipeline->id)->where('client_id', $nuevo->id)->count());
    }

    /**
     * 5. Estar abierto en OTRO pipeline no impide el alta: la regla es por sujeto Y pipeline.
     *
     * @return void
     */
    public function test_un_sujeto_puede_estar_abierto_en_varios_pipelines(): void
    {
        $this->admin_logueado();
        $cliente = $this->crear_cliente();

        $this->alta_de_uno($this->crear_pipeline(), $cliente);
        $this->alta_de_uno($this->crear_pipeline(), $cliente);

        $this->assertSame(2, PipelineOpportunity::query()->where('client_id', $cliente->id)->count());
    }

    /**
     * 6. Después de cerrar (perdida), el mismo sujeto se puede volver a agregar: nace otra
     *    oportunidad y la cerrada queda como historia.
     *
     * @return void
     */
    public function test_se_puede_volver_a_agregar_despues_de_cerrar(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $cliente  = $this->crear_cliente();

        $primera = $this->alta_de_uno($pipeline, $cliente);

        $this->mover($primera['id'], [
            'stage_id'    => $this->etapa($pipeline, 'Perdido')->id,
            'lost_reason' => 'No es el momento',
        ])->assertStatus(200);

        $segunda = $this->alta_de_uno($pipeline, $cliente, ['note' => 'Segunda vuelta']);
        $this->assertNotSame($primera['id'], $segunda['id']);
        $this->assertSame('open', $segunda['stage']['type']);

        $del_cliente = $this->getJson('/api/admin/pipeline-opportunities?client_id=' . $cliente->id)->assertStatus(200)->json('opportunities');
        $this->assertSame([$segunda['id'], $primera['id']], array_column($del_cliente, 'id'), 'La abierta primero, la cerrada después.');
    }

    /**
     * 8. R2: el alta en una etapa con campos obligatorios los exige (422 en `fields.<key>`, como al
     *    mover) y no crea nada; un valor fuera de las opciones también es 422.
     *
     * @return void
     */
    public function test_alta_con_campos_obligatorios_faltantes_da_422_y_no_crea_nada(): void
    {
        $this->admin_logueado();
        $pipeline   = $this->crear_pipeline();
        $cliente    = $this->crear_cliente();
        $contactado = $this->etapa($pipeline, 'Contactado')->id;

        $sin_campos = $this->alta($pipeline, [['type' => 'client', 'id' => $cliente->id]], ['stage_id' => $contactado]);
        $sin_campos->assertStatus(422);
        $this->assertSame(['fields.canal'], array_keys($sin_campos->json('errors')));
        $this->assertSame('Completá «Canal».', $sin_campos->json('message'));

        $fuera_de_opciones = $this->alta($pipeline, [['type' => 'client', 'id' => $cliente->id]], [
            'stage_id' => $contactado,
            'fields'   => ['canal' => 'Paloma mensajera'],
        ]);
        $fuera_de_opciones->assertStatus(422);
        $this->assertArrayHasKey('fields.canal', $fuera_de_opciones->json('errors'));

        $reunion = $this->alta($pipeline, [['type' => 'client', 'id' => $cliente->id]], [
            'stage_id' => $this->etapa($pipeline, 'Reunión agendada')->id,
            'fields'   => ['que_quiere_ver' => 'Stock'],
        ]);
        $reunion->assertStatus(422);
        $this->assertSame(['fields.fecha_reunion'], array_keys($reunion->json('errors')));

        $this->assertSame(0, PipelineOpportunity::query()->where('pipeline_id', $pipeline->id)->count());
    }

    /**
     * 9. R2: el alta en una etapa con campo agenda fija la próxima acción de cada oportunidad
     *    (`source = agenda`, nota = nombre de la etapa), con el mismo valor para todos los sujetos
     *    y la foto en la actividad `created`. Como en la regla R1.1 del mover, no deja una actividad
     *    `next_action` aparte: la fecha ya está en la foto.
     *
     * @return void
     */
    public function test_alta_en_etapa_con_agenda_fija_la_proxima_accion(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();

        $respuesta = $this->alta($pipeline, [
            ['type' => 'client', 'id' => $this->crear_cliente()->id],
            ['type' => 'lead', 'id' => $this->crear_lead()->id],
        ], [
            'stage_id' => $this->etapa($pipeline, 'Reunión agendada')->id,
            'fields'   => ['fecha_reunion' => '2026-09-30 15:00', 'que_quiere_ver' => 'El asistente respondiendo stock'],
            'note'     => 'Agendado en la llamada',
        ]);

        $respuesta->assertStatus(201);
        $this->assertCount(2, $respuesta->json('created'));

        foreach ($respuesta->json('created') as $op) {
            $this->assertSame('2026-09-30 15:00:00', $op['next_action_at']);
            $this->assertSame('Reunión agendada', $op['next_action_note']);
            $this->assertSame('agenda', $op['next_action_source']);
            $this->assertFalse($op['next_action_carries_over']);
            $this->assertSame('week', $op['agenda_bucket']);

            $actividades = PipelineActivity::query()->where('opportunity_id', $op['id'])->get();
            $this->assertSame(['created'], $actividades->pluck('type')->all());
            $this->assertSame('Agendado en la llamada', $actividades[0]->body);
        }

        $foto = $this->getJson('/api/admin/pipeline-opportunities/' . $respuesta->json('created.0.id'))->assertStatus(200)->json('activities.0.data');
        $this->assertSame([
            ['key' => 'fecha_reunion', 'label' => 'Fecha y hora', 'type' => 'datetime', 'value' => '2026-09-30 15:00'],
            ['key' => 'que_quiere_ver', 'label' => 'Qué quiere ver', 'type' => 'textarea', 'value' => 'El asistente respondiendo stock'],
        ], $foto);
    }

    /**
     * 7. Validación de forma: lista vacía, más de 500, tipo de sujeto inválido.
     *
     * @return void
     */
    public function test_validacion_de_forma_del_pedido(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();

        $vacia = $this->alta($pipeline, []);
        $vacia->assertStatus(422);
        $this->assertArrayHasKey('subjects', $vacia->json('errors'));

        $muchos = [];
        for ($i = 1; $i <= 501; $i++) {
            $muchos[] = ['type' => 'client', 'id' => $i];
        }
        $this->alta($pipeline, $muchos)->assertStatus(422);

        $tipo_raro = $this->alta($pipeline, [['type' => 'proveedor', 'id' => 1]]);
        $tipo_raro->assertStatus(422);
        $this->assertArrayHasKey('subjects.0.type', $tipo_raro->json('errors'));

        $this->assertSame(0, PipelineOpportunity::query()->where('pipeline_id', $pipeline->id)->count());
    }
}
