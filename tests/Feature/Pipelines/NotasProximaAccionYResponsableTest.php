<?php

namespace Tests\Feature\Pipelines;

use App\Models\PipelineActivity;
use App\Models\PipelineOpportunity;

/**
 * Notas, próxima acción y responsable de una oportunidad (endpoints #16, #18, #19 y #20).
 *
 * Lo que se protege:
 *  1. Que la nota acepte una fecha anterior (se carga después de la llamada) pero no una futura.
 *  2. Que cambiar la próxima acción o el responsable deje su actividad con `data` legible (fechas
 *     como hora local, nombres de admins como foto), y que no cambiar nada no deje nada.
 *  3. 🔴 Que una oportunidad cerrada no lleve próxima acción.
 *  4. 🔴 Que del historial solo se borren las notas.
 */
class NotasProximaAccionYResponsableTest extends BaseDePipelines
{
    /**
     * 1. Nota con canal y fecha anterior: se guarda con esa fecha. Como la última actividad es la
     *    de mayor `occurred_at`, una nota con fecha de ayer NO pasa a ser la última; una nota de
     *    ahora sí (y ante el empate en el segundo, gana la de mayor id).
     *
     * @return void
     */
    public function test_nota_con_canal_y_fecha_anterior(): void
    {
        $admin    = $this->admin_logueado('Lucas');
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $respuesta = $this->postJson('/api/admin/pipeline-opportunities/' . $op['id'] . '/notes', [
            'body'        => 'Le mandé el video del asistente.',
            'channel'     => 'whatsapp',
            'occurred_at' => '2026-09-26 18:30',
        ]);

        $respuesta->assertStatus(201);
        $this->assertSame('note', $respuesta->json('activity.type'));
        $this->assertSame('whatsapp', $respuesta->json('activity.channel'));
        $this->assertSame('Le mandé el video del asistente.', $respuesta->json('activity.body'));
        $this->assertSame('2026-09-26 18:30:00', $respuesta->json('activity.occurred_at'));
        $this->assertSame('2026-09-27 10:00:00', $respuesta->json('activity.created_at'));
        $this->assertSame(['id' => $admin->id, 'name' => 'Lucas'], $respuesta->json('activity.admin'));
        $this->assertNull($respuesta->json('activity.data'));
        $this->assertSame('created', $respuesta->json('opportunity.last_activity.type'), 'Una nota con fecha de ayer no es la última actividad.');

        $ahora = $this->postJson('/api/admin/pipeline-opportunities/' . $op['id'] . '/notes', [
            'body' => 'Dice que lo ve el lunes.',
        ]);
        $ahora->assertStatus(201);
        $this->assertSame('2026-09-27 10:00:00', $ahora->json('activity.occurred_at'));
        $this->assertNull($ahora->json('activity.channel'));
        $this->assertSame('note', $ahora->json('opportunity.last_activity.type'));
        $this->assertSame('Dice que lo ve el lunes.', $ahora->json('opportunity.last_activity.body'));

        /* La ficha las lista de la más nueva a la más vieja (por cuándo pasó). */
        $historial = $this->getJson('/api/admin/pipeline-opportunities/' . $op['id'])->assertStatus(200)->json('activities');
        $this->assertSame(['note', 'created', 'note'], array_column($historial, 'type'));
        $this->assertSame('Le mandé el video del asistente.', $historial[2]['body']);
    }

    /**
     * 2. `occurred_at` futuro: 422. El minuto corriente sí se acepta. Una fecha ISO se rechaza.
     *
     * @return void
     */
    public function test_nota_con_fecha_futura_o_iso_da_422(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());
        $url      = '/api/admin/pipeline-opportunities/' . $op['id'] . '/notes';

        $futura = $this->postJson($url, ['body' => 'Todavía no pasó', 'occurred_at' => '2026-09-27 10:05']);
        $futura->assertStatus(422);
        $this->assertSame(['La fecha de la nota no puede ser futura.'], $futura->json('errors.occurred_at'));

        $iso = $this->postJson($url, ['body' => 'Con Z', 'occurred_at' => '2026-09-26T18:30:00.000Z']);
        $iso->assertStatus(422);
        $this->assertArrayHasKey('occurred_at', $iso->json('errors'));

        $this->postJson($url, ['body' => 'Recién', 'occurred_at' => '2026-09-27 10:00'])->assertStatus(201);

        $this->assertSame(1, PipelineActivity::query()->where('opportunity_id', $op['id'])->where('type', 'note')->count());
    }

    /**
     * 3. Sin cuerpo o con un canal que no existe: 422.
     *
     * @return void
     */
    public function test_nota_sin_cuerpo_o_con_canal_invalido_da_422(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());
        $url      = '/api/admin/pipeline-opportunities/' . $op['id'] . '/notes';

        $vacia = $this->postJson($url, ['body' => '   ']);
        $vacia->assertStatus(422);
        $this->assertSame('Falta la nota.', $vacia->json('message'));

        $canal = $this->postJson($url, ['body' => 'Hola', 'channel' => 'telegram']);
        $canal->assertStatus(422);
        $this->assertArrayHasKey('channel', $canal->json('errors'));
    }

    /**
     * 4. Próxima acción: deja una actividad `next_action` con `{from, to, note}` (hora local). Si
     *    solo viene la fecha, la nota se conserva. Si no cambia nada, no deja actividad.
     *
     * @return void
     */
    public function test_proxima_accion_registra_actividad_con_data(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());
        $url      = '/api/admin/pipeline-opportunities/' . $op['id'];

        $primera = $this->putJson($url, ['next_action_at' => '2026-09-30 15:00', 'next_action_note' => 'Llamar']);
        $primera->assertStatus(200);
        $this->assertSame('2026-09-30 15:00:00', $primera->json('opportunity.next_action_at'));
        $this->assertSame('Llamar', $primera->json('opportunity.next_action_note'));
        $this->assertSame('week', $primera->json('opportunity.agenda_bucket'));
        $this->assertCount(1, $primera->json('activities'));
        $this->assertSame('next_action', $primera->json('activities.0.type'));
        $this->assertSame(['from' => null, 'to' => '2026-09-30 15:00:00', 'note' => 'Llamar'], $primera->json('activities.0.data'));

        $segunda = $this->putJson($url, ['next_action_at' => '2026-10-02']);
        $segunda->assertStatus(200);
        $this->assertSame(
            ['from' => '2026-09-30 15:00:00', 'to' => '2026-10-02 00:00:00', 'note' => 'Llamar'],
            $segunda->json('activities.0.data')
        );

        $igual = $this->putJson($url, ['next_action_at' => '2026-10-02', 'next_action_note' => 'Llamar']);
        $igual->assertStatus(200);
        $this->assertSame([], $igual->json('activities'), 'Sin cambios no hay actividad.');

        $this->assertSame(2, PipelineActivity::query()->where('opportunity_id', $op['id'])->where('type', 'next_action')->count());
    }

    /**
     * 5. Responsable: actividad `owner` con los NOMBRES (foto). Sacarlo (null) también se registra.
     *    Si cambian responsable y próxima acción en el mismo PUT, vienen las dos, la más nueva
     *    primero.
     *
     * @return void
     */
    public function test_responsable_registra_actividad_con_nombres(): void
    {
        $this->admin_logueado('Lucas');
        $thomas   = $this->crear_admin('Thomas');
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());
        $url      = '/api/admin/pipeline-opportunities/' . $op['id'];

        $a_thomas = $this->putJson($url, ['owner_admin_id' => $thomas->id]);
        $a_thomas->assertStatus(200);
        $this->assertSame(['id' => $thomas->id, 'name' => 'Thomas'], $a_thomas->json('opportunity.owner'));
        $this->assertSame('owner', $a_thomas->json('activities.0.type'));
        $this->assertSame(['from' => 'Lucas', 'to' => 'Thomas'], $a_thomas->json('activities.0.data'));

        $sin_nadie = $this->putJson($url, ['owner_admin_id' => null]);
        $sin_nadie->assertStatus(200);
        $this->assertNull($sin_nadie->json('opportunity.owner'));
        $this->assertSame(['from' => 'Thomas', 'to' => null], $sin_nadie->json('activities.0.data'));

        $las_dos = $this->putJson($url, ['owner_admin_id' => $thomas->id, 'next_action_at' => '2026-09-28 09:00']);
        $las_dos->assertStatus(200);
        $this->assertSame(['next_action', 'owner'], array_column($las_dos->json('activities'), 'type'));

        $mismo = $this->putJson($url, ['owner_admin_id' => $thomas->id]);
        $this->assertSame([], $mismo->json('activities'));

        $inexistente = $this->putJson($url, ['owner_admin_id' => 999999999]);
        $inexistente->assertStatus(422);
        $this->assertArrayHasKey('owner_admin_id', $inexistente->json('errors'));
    }

    /**
     * 6. 🔴 Una cerrada no lleva próxima acción (422); limpiarla (null) no molesta; el responsable
     *    sí se puede cambiar.
     *
     * @return void
     */
    public function test_proxima_accion_a_una_cerrada_da_422(): void
    {
        $this->admin_logueado();
        $thomas   = $this->crear_admin('Thomas');
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());
        $url      = '/api/admin/pipeline-opportunities/' . $op['id'];

        $this->mover($op['id'], ['stage_id' => $this->etapa($pipeline, 'Perdido')->id, 'lost_reason' => 'Precio'])->assertStatus(200);

        $con_fecha = $this->putJson($url, ['next_action_at' => '2026-10-01 10:00']);
        $con_fecha->assertStatus(422);
        $this->assertStringContainsString('cerrada', $con_fecha->json('message'));

        $con_nota = $this->putJson($url, ['next_action_note' => 'Insistir']);
        $con_nota->assertStatus(422);

        $this->putJson($url, ['next_action_at' => null])
            ->assertStatus(200)
            ->assertJsonPath('activities', []);

        $this->putJson($url, ['owner_admin_id' => $thomas->id])
            ->assertStatus(200)
            ->assertJsonPath('opportunity.owner.name', 'Thomas');

        $this->assertNull($this->oportunidad($op['id'])->next_action_at);
    }

    /**
     * 7. 🔴 Del historial se borran las notas; el alta y los cambios de etapa, no (422).
     *
     * @return void
     */
    public function test_borrar_nota_si_y_cambio_de_etapa_no(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $nota = $this->postJson('/api/admin/pipeline-opportunities/' . $op['id'] . '/notes', ['body' => 'Borrame'])
            ->assertStatus(201)
            ->json('activity.id');

        $cambio = $this->mover($op['id'], ['stage_id' => $this->etapa($pipeline, 'Contactado')->id, 'fields' => ['canal' => 'WhatsApp']])
            ->assertStatus(200)
            ->json('activity.id');

        $alta = PipelineActivity::query()->where('opportunity_id', $op['id'])->where('type', 'created')->value('id');

        $this->deleteJson('/api/admin/pipeline-activities/' . $nota)->assertStatus(200)->assertJson(['ok' => true]);
        $this->assertNull(PipelineActivity::query()->find($nota));

        $no = $this->deleteJson('/api/admin/pipeline-activities/' . $cambio);
        $no->assertStatus(422);
        $this->assertSame('Solo se pueden borrar las notas: el historial de etapas no se borra.', $no->json('message'));

        $this->deleteJson('/api/admin/pipeline-activities/' . $alta)->assertStatus(422);

        $this->assertNotNull(PipelineActivity::query()->find($cambio));
        $this->assertNotNull(PipelineActivity::query()->find($alta));
    }

    /**
     * 10. R1 en el PUT: una nota de próxima acción que queda sin fecha es 422 en
     *     `errors.next_action_at` con el texto del contrato (sin fecha previa, o borrando la fecha
     *     y mandando nota a la vez). Con una fecha ya cargada, cambiar solo la nota se puede.
     *
     * @return void
     */
    public function test_nota_de_proxima_accion_sin_fecha_da_422_en_el_put(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());
        $url      = '/api/admin/pipeline-opportunities/' . $op['id'];

        $sola = $this->putJson($url, ['next_action_note' => 'Llamar']);
        $sola->assertStatus(422);
        $this->assertSame(['next_action_at' => ['Poné la fecha de la próxima acción.']], $sola->json('errors'));
        $this->assertSame('Poné la fecha de la próxima acción.', $sola->json('message'));

        $borrando_la_fecha = $this->putJson($url, ['next_action_at' => null, 'next_action_note' => 'Llamar']);
        $borrando_la_fecha->assertStatus(422);
        $this->assertSame(['next_action_at' => ['Poné la fecha de la próxima acción.']], $borrando_la_fecha->json('errors'));

        $this->assertNull($this->oportunidad($op['id'])->next_action_note);
        $this->assertSame(0, PipelineActivity::query()->where('opportunity_id', $op['id'])->where('type', 'next_action')->count());

        $this->putJson($url, ['next_action_at' => '2026-09-29 11:00'])->assertStatus(200);
        $this->putJson($url, ['next_action_note' => 'Llamar al dueño'])
            ->assertStatus(200)
            ->assertJsonPath('opportunity.next_action_at', '2026-09-29 11:00:00')
            ->assertJsonPath('opportunity.next_action_note', 'Llamar al dueño');
    }

    /**
     * 11. R1 en el PUT: con fecha, el origen pasa a `manual` (aunque la hubiera puesto la agenda de
     *     la etapa); borrar la fecha borra la próxima acción ENTERA (fecha, nota y origen) y lo
     *     registra.
     *
     * @return void
     */
    public function test_el_put_marca_el_origen_manual_y_borrar_la_fecha_borra_todo(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());
        $url      = '/api/admin/pipeline-opportunities/' . $op['id'];

        $this->mover($op['id'], ['stage_id' => $this->etapa($pipeline, 'Reunión agendada')->id, 'fields' => ['fecha_reunion' => '2026-09-30 15:00']])
            ->assertStatus(200)
            ->assertJsonPath('opportunity.next_action_source', 'agenda');

        $misma_fecha = $this->putJson($url, ['next_action_at' => '2026-09-30 15:00', 'next_action_note' => 'Confirmar la reunión']);
        $misma_fecha->assertStatus(200);
        $this->assertSame('manual', $misma_fecha->json('opportunity.next_action_source'));
        $this->assertTrue($misma_fecha->json('opportunity.next_action_carries_over'));
        $this->assertSame(['from' => '2026-09-30 15:00:00', 'to' => '2026-09-30 15:00:00', 'note' => 'Confirmar la reunión'], $misma_fecha->json('activities.0.data'));

        $borrada = $this->putJson($url, ['next_action_at' => null]);
        $borrada->assertStatus(200);
        $this->assertNull($borrada->json('opportunity.next_action_at'));
        $this->assertNull($borrada->json('opportunity.next_action_note'));
        $this->assertNull($borrada->json('opportunity.next_action_source'));
        $this->assertFalse($borrada->json('opportunity.next_action_carries_over'));
        $this->assertSame(['from' => '2026-09-30 15:00:00', 'to' => null, 'note' => null], $borrada->json('activities.0.data'));

        $recargada = $this->oportunidad($op['id']);
        $this->assertNull($recargada->next_action_note);
        $this->assertNull($recargada->next_action_source);
    }

    /**
     * 9. La ficha (#15): la oportunidad con el contacto del sujeto y el pipeline completo (etapas y
     *    conteos), y el historial de la más nueva a la más vieja con la forma Activity.
     *
     * @return void
     */
    public function test_la_ficha_trae_contacto_pipeline_completo_e_historial(): void
    {
        $this->admin_logueado('Lucas');
        $pipeline = $this->crear_pipeline();
        $cliente  = $this->crear_cliente();
        $op       = $this->alta_de_uno($pipeline, $cliente, ['note' => 'Alta']);

        $this->clavar_reloj('2026-09-27 10:30:00');
        $this->postJson('/api/admin/pipeline-opportunities/' . $op['id'] . '/notes', ['body' => 'Seguimiento', 'channel' => 'call'])->assertStatus(201);

        $ficha = $this->getJson('/api/admin/pipeline-opportunities/' . $op['id']);
        $ficha->assertStatus(200);

        $oportunidad = $ficha->json('opportunity');
        $this->assertSame($cliente->phone, $oportunidad['subject']['phone']);
        $this->assertSame($cliente->email, $oportunidad['subject']['email']);
        $this->assertSame($pipeline->id, $oportunidad['pipeline']['id']);
        $this->assertSame(['Precio', 'No lo necesita', 'Otro'], $oportunidad['pipeline']['lost_reasons']);
        $this->assertSame(['open' => 1, 'won' => 0, 'lost' => 0, 'total' => 1], $oportunidad['pipeline']['counts']);
        $this->assertCount(count($this->etapas_de_prueba()), $oportunidad['pipeline']['stages']);
        $this->assertSame('2026-09-27 10:30:00', $oportunidad['updated_at'], 'Una nota nueva mueve el updated_at de la oportunidad.');

        $actividades = $ficha->json('activities');
        $this->assertSame(['note', 'created'], array_column($actividades, 'type'));
        $this->assertSame([
            'id', 'opportunity_id', 'type', 'admin', 'from_stage_id', 'from_stage_name', 'to_stage_id',
            'to_stage_name', 'body', 'channel', 'data', 'occurred_at', 'created_at',
        ], array_keys($actividades[0]));
        $this->assertSame('Alta', $actividades[1]['body']);
        $this->assertSame('Por contactar', $actividades[1]['to_stage_name']);
        $this->assertSame([], $actividades[1]['data']);
    }

    /**
     * 8. Borrar una oportunidad borra su historial, y después es 404 legible.
     *
     * @return void
     */
    public function test_borrar_oportunidad_borra_su_historial(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $this->postJson('/api/admin/pipeline-opportunities/' . $op['id'] . '/notes', ['body' => 'Una nota'])->assertStatus(201);

        $this->deleteJson('/api/admin/pipeline-opportunities/' . $op['id'])->assertStatus(200)->assertJson(['ok' => true]);

        $this->assertNull(PipelineOpportunity::query()->find($op['id']));
        $this->assertSame(0, PipelineActivity::query()->where('opportunity_id', $op['id'])->count());

        $this->getJson('/api/admin/pipeline-opportunities/' . $op['id'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'La oportunidad no existe (puede que otro admin la haya borrado).');
    }
}
