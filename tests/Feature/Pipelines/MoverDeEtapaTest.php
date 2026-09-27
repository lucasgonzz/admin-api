<?php

namespace Tests\Feature\Pipelines;

use App\Models\PipelineActivity;
use App\Models\PipelineStage;

/**
 * Mover una oportunidad de etapa (endpoint #17), con los payloads que manda el `MoveModal`.
 *
 * Lo que se protege, en orden de importancia:
 *  1. 🔴 Que los campos se validen contra la definición de la etapa DESTINO, en el back, con el
 *     error en `fields.<key>` (es donde el modal lo pinta) y sin mover nada si falla.
 *  2. 🔴 El estado sale de la etapa: ganada / perdida cierran y limpian la próxima acción; volver
 *     a una abierta reabre, salvo que el sujeto ya tenga OTRA abierta en el pipeline.
 *  3. El campo agenda completa la próxima acción (fecha y hora; fecha sola = 00:00).
 *  4. La foto del historial: nombres de/a y los campos con valor, en el orden de la definición.
 *  5. Fechas SIN ISO: un "2026-09-30T15:00:00.000Z" se rechaza (es la trampa de las tres horas).
 */
class MoverDeEtapaTest extends BaseDePipelines
{
    /**
     * 1. Obligatorio faltante: 422 con el error en `fields.canal`, mensaje en español, y la
     *    oportunidad no se movió ni dejó historial.
     *
     * @return void
     */
    public function test_obligatorio_faltante_da_422_en_fields_clave_y_no_mueve(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $respuesta = $this->mover($op['id'], [
            'stage_id' => $this->etapa($pipeline, 'Contactado')->id,
            'fields'   => ['canal' => ''],
            'note'     => 'Le escribí',
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame(['fields.canal'], array_keys($respuesta->json('errors')));
        $this->assertSame('Completá «Canal».', $respuesta->json('message'));

        $this->assertSame($this->etapa($pipeline, 'Por contactar')->id, $this->oportunidad($op['id'])->stage_id);
        $this->assertSame(0, PipelineActivity::query()->where('opportunity_id', $op['id'])->where('type', 'stage_change')->count());
    }

    /**
     * 2. Formatos inválidos por tipo, todos en un solo 422 (número, sí/no, fecha, texto largo), y
     *    la fecha y hora en ISO o sin hora rechazadas.
     *
     * @return void
     */
    public function test_formatos_invalidos_por_tipo(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $respuesta = $this->mover($op['id'], [
            'stage_id' => $this->etapa($pipeline, 'Calificado')->id,
            'fields'   => [
                'empleados'   => 'muchos',
                'usa_sistema' => 'quizás',
                'fecha_alta'  => '30/09/2026',
                'comentario'  => str_repeat('x', 256),
            ],
        ]);

        $respuesta->assertStatus(422);
        $errores = $respuesta->json('errors');
        foreach (['fields.empleados', 'fields.usa_sistema', 'fields.fecha_alta', 'fields.comentario'] as $clave) {
            $this->assertArrayHasKey($clave, $errores);
        }
        $this->assertStringContainsString('(y 3 errores más)', $respuesta->json('message'));

        $reunion = $this->etapa($pipeline, 'Reunión agendada')->id;

        $iso = $this->mover($op['id'], ['stage_id' => $reunion, 'fields' => ['fecha_reunion' => '2026-09-30T15:00:00.000Z']]);
        $iso->assertStatus(422);
        $this->assertArrayHasKey('fields.fecha_reunion', $iso->json('errors'));

        $sin_hora = $this->mover($op['id'], ['stage_id' => $reunion, 'fields' => ['fecha_reunion' => '2026-09-30']]);
        $sin_hora->assertStatus(422);
        $this->assertArrayHasKey('fields.fecha_reunion', $sin_hora->json('errors'));

        $fecha_imposible = $this->mover($op['id'], ['stage_id' => $reunion, 'fields' => ['fecha_reunion' => '2026-02-30 10:00']]);
        $fecha_imposible->assertStatus(422);

        $this->assertSame('Por contactar', $this->oportunidad($op['id'])->stage->name);
    }

    /**
     * 3. Una lista acepta solo sus opciones.
     *
     * @return void
     */
    public function test_select_fuera_de_opciones_da_422(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $respuesta = $this->mover($op['id'], [
            'stage_id' => $this->etapa($pipeline, 'Contactado')->id,
            'fields'   => ['canal' => 'Mail'],
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame(['«Canal» tiene que ser una de las opciones: WhatsApp, Llamada.'], $respuesta->json('errors')['fields.canal']);
    }

    /**
     * 4. Valores válidos de cada tipo: número como string, `false` en un sí/no obligatorio (cuenta
     *    como respondido), fecha `Y-m-d`; las claves que no están en la definición se ignoran.
     *
     * @return void
     */
    public function test_valores_validos_de_cada_tipo_y_false_cuenta_como_respondido(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $respuesta = $this->mover($op['id'], [
            'stage_id' => $this->etapa($pipeline, 'Calificado')->id,
            'fields'   => [
                'empleados'   => '12',
                'usa_sistema' => false,
                'fecha_alta'  => '2026-10-01',
                'comentario'  => 'Tiene dos sucursales',
                'inventado'   => 'no se guarda',
            ],
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame([
            ['key' => 'empleados', 'label' => 'Empleados', 'type' => 'number', 'value' => 12],
            ['key' => 'usa_sistema', 'label' => 'Usa otro sistema', 'type' => 'boolean', 'value' => false],
            ['key' => 'fecha_alta', 'label' => 'Fecha de alta', 'type' => 'date', 'value' => '2026-10-01'],
            ['key' => 'comentario', 'label' => 'Comentario', 'type' => 'text', 'value' => 'Tiene dos sucursales'],
        ], $respuesta->json('activity.data'));
    }

    /**
     * 5. Movimiento libre: se puede saltear etapas. A la misma etapa: 422. A una etapa de otro
     *    pipeline: 422.
     *
     * @return void
     */
    public function test_movimiento_libre_misma_etapa_y_etapa_ajena(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $otro     = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $mas_adelante = $this->etapa($pipeline, 'Más adelante')->id;

        $this->mover($op['id'], ['stage_id' => $mas_adelante, 'fields' => ['fecha_retomar' => '2026-11-02']])
            ->assertStatus(200)
            ->assertJsonPath('opportunity.stage.name', 'Más adelante');

        $misma = $this->mover($op['id'], ['stage_id' => $mas_adelante, 'fields' => ['fecha_retomar' => '2026-11-03']]);
        $misma->assertStatus(422);
        $this->assertSame('Ya está en esa etapa.', $misma->json('message'));

        $ajena = $this->mover($op['id'], ['stage_id' => $this->etapa($otro, 'Contactado')->id, 'fields' => ['canal' => 'WhatsApp']]);
        $ajena->assertStatus(422);
        $this->assertSame('La etapa elegida no es de este pipeline.', $ajena->json('message'));
    }

    /**
     * 6. La foto del historial: de / a con ids y nombres, la nota, y en `data` solo los campos con
     *    valor en el orden de la definición. Renombrar después la etapa no cambia la foto.
     *    Y la agenda (fecha y hora) completa la próxima acción con el nombre de la etapa.
     *
     * @return void
     */
    public function test_foto_en_data_nombres_de_y_a_y_agenda_con_fecha_y_hora(): void
    {
        $admin    = $this->admin_logueado('Lucas');
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());
        $reunion  = $this->etapa($pipeline, 'Reunión agendada');

        $this->clavar_reloj('2026-09-27 11:30:00');

        $respuesta = $this->mover($op['id'], [
            'stage_id' => $reunion->id,
            'fields'   => [
                'participantes'  => '',
                'que_quiere_ver' => 'Stock y compras',
                'fecha_reunion'  => '2026-09-30 15:00',
                'extra'          => 'x',
            ],
            'note'     => 'Atiende el dueño.',
        ]);

        $respuesta->assertStatus(200);

        $actividad = $respuesta->json('activity');
        $this->assertSame('stage_change', $actividad['type']);
        $this->assertSame($this->etapa($pipeline, 'Por contactar')->id, $actividad['from_stage_id']);
        $this->assertSame('Por contactar', $actividad['from_stage_name']);
        $this->assertSame($reunion->id, $actividad['to_stage_id']);
        $this->assertSame('Reunión agendada', $actividad['to_stage_name']);
        $this->assertSame('Atiende el dueño.', $actividad['body']);
        $this->assertSame(['id' => $admin->id, 'name' => 'Lucas'], $actividad['admin']);
        $this->assertSame('2026-09-27 11:30:00', $actividad['occurred_at']);
        $this->assertSame([
            ['key' => 'fecha_reunion', 'label' => 'Fecha y hora', 'type' => 'datetime', 'value' => '2026-09-30 15:00'],
            ['key' => 'que_quiere_ver', 'label' => 'Qué quiere ver', 'type' => 'textarea', 'value' => 'Stock y compras'],
        ], $actividad['data']);

        /* La oportunidad: agenda → próxima acción; nota = nombre de la etapa; entró ahora. */
        $oportunidad = $respuesta->json('opportunity');
        $this->assertSame('2026-09-30 15:00:00', $oportunidad['next_action_at']);
        $this->assertSame('Reunión agendada', $oportunidad['next_action_note']);
        $this->assertSame('week', $oportunidad['agenda_bucket']);
        $this->assertSame('2026-09-27 11:30:00', $oportunidad['stage_entered_at']);
        $this->assertSame('stage_change', $oportunidad['last_activity']['type']);

        /* Renombrar la etapa no toca la foto. */
        $this->putJson('/api/admin/pipeline-stages/' . $reunion->id, ['name' => 'Reunión (renombrada)'])->assertStatus(200);

        $historial = $this->getJson('/api/admin/pipeline-opportunities/' . $op['id'])->assertStatus(200)->json('activities');
        $this->assertSame('Reunión agendada', $historial[0]['to_stage_name']);
        $this->assertSame('Fecha y hora', $historial[0]['data'][0]['label']);
    }

    /**
     * 7. Agenda con la nota del payload: si viene `next_action_note`, gana sobre el nombre de la
     *    etapa. Y una agenda de FECHA sola queda a las 00:00:00.
     *
     * @return void
     */
    public function test_agenda_con_nota_del_payload_y_fecha_sola_a_las_cero(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $this->mover($op['id'], [
            'stage_id'         => $this->etapa($pipeline, 'Reunión agendada')->id,
            'fields'           => ['fecha_reunion' => '2026-09-29 09:00'],
            'next_action_note' => 'Mostrar el asistente',
        ])->assertStatus(200)
            ->assertJsonPath('opportunity.next_action_at', '2026-09-29 09:00:00')
            ->assertJsonPath('opportunity.next_action_note', 'Mostrar el asistente');

        $this->mover($op['id'], [
            'stage_id' => $this->etapa($pipeline, 'Más adelante')->id,
            'fields'   => ['fecha_retomar' => '2026-10-15'],
        ])->assertStatus(200)
            ->assertJsonPath('opportunity.next_action_at', '2026-10-15 00:00:00')
            ->assertJsonPath('opportunity.next_action_note', 'Más adelante')
            ->assertJsonPath('opportunity.agenda_bucket', 'later');
    }

    /**
     * 8. Destino sin campo agenda: si el payload no trae `next_action_at`, se conserva la que
     *    tenía; si la trae, se aplica con su nota; si la trae en null, se limpia.
     *
     * @return void
     */
    public function test_sin_agenda_conserva_o_aplica_la_del_payload(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $this->putJson('/api/admin/pipeline-opportunities/' . $op['id'], [
            'next_action_at'   => '2026-09-29 11:00',
            'next_action_note' => 'Llamar',
        ])->assertStatus(200);

        $this->mover($op['id'], [
            'stage_id' => $this->etapa($pipeline, 'Contactado')->id,
            'fields'   => ['canal' => 'Llamada'],
        ])->assertStatus(200)
            ->assertJsonPath('opportunity.next_action_at', '2026-09-29 11:00:00')
            ->assertJsonPath('opportunity.next_action_note', 'Llamar');

        $this->mover($op['id'], [
            'stage_id'         => $this->etapa($pipeline, 'Calificado')->id,
            'fields'           => ['usa_sistema' => true],
            'next_action_at'   => '2026-10-01 09:30',
            'next_action_note' => 'Mandar propuesta',
        ])->assertStatus(200)
            ->assertJsonPath('opportunity.next_action_at', '2026-10-01 09:30:00')
            ->assertJsonPath('opportunity.next_action_note', 'Mandar propuesta');

        $this->mover($op['id'], [
            'stage_id'       => $this->etapa($pipeline, 'Por contactar')->id,
            'next_action_at' => null,
        ])->assertStatus(200)
            ->assertJsonPath('opportunity.next_action_at', null)
            ->assertJsonPath('opportunity.next_action_note', null)
            ->assertJsonPath('opportunity.agenda_bucket', 'none');

        /* Una fecha de próxima acción en ISO se rechaza. */
        $iso = $this->mover($op['id'], [
            'stage_id'       => $this->etapa($pipeline, 'Contactado')->id,
            'fields'         => ['canal' => 'WhatsApp'],
            'next_action_at' => '2026-10-01T12:30:00.000Z',
        ]);
        $iso->assertStatus(422);
        $this->assertArrayHasKey('next_action_at', $iso->json('errors'));
    }

    /**
     * 9. 🔴 Perdida exige motivo; al entrar cierra (`closed_at`), guarda el motivo y limpia la
     *    próxima acción aunque el payload traiga una.
     *
     * @return void
     */
    public function test_perdida_exige_motivo_cierra_y_limpia_la_proxima_accion(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());
        $perdido  = $this->etapa($pipeline, 'Perdido')->id;

        $this->putJson('/api/admin/pipeline-opportunities/' . $op['id'], ['next_action_at' => '2026-09-28'])->assertStatus(200);

        $sin_motivo = $this->mover($op['id'], ['stage_id' => $perdido, 'note' => 'No quiere saber nada']);
        $sin_motivo->assertStatus(422);
        $this->assertArrayHasKey('lost_reason', $sin_motivo->json('errors'));
        $this->assertNull($this->oportunidad($op['id'])->closed_at);

        $this->clavar_reloj('2026-09-27 16:45:00');

        $respuesta = $this->mover($op['id'], [
            'stage_id'         => $perdido,
            'lost_reason'      => 'Precio',
            'next_action_at'   => '2026-10-10 10:00',
            'next_action_note' => 'Volver a intentar',
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame('2026-09-27 16:45:00', $respuesta->json('opportunity.closed_at'));
        $this->assertSame('Precio', $respuesta->json('opportunity.lost_reason'));
        $this->assertNull($respuesta->json('opportunity.next_action_at'));
        $this->assertNull($respuesta->json('opportunity.next_action_note'));
        $this->assertSame('closed', $respuesta->json('opportunity.agenda_bucket'));
        $this->assertSame('lost', $respuesta->json('opportunity.stage.type'));
    }

    /**
     * 10. Ganada cierra y limpia la próxima acción; el motivo de pérdida no se guarda en una ganada.
     *
     * @return void
     */
    public function test_ganada_cierra_limpia_y_no_guarda_motivo(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $this->putJson('/api/admin/pipeline-opportunities/' . $op['id'], ['next_action_at' => '2026-09-28 10:00'])->assertStatus(200);

        $respuesta = $this->mover($op['id'], [
            'stage_id'       => $this->etapa($pipeline, 'Ganado')->id,
            'fields'         => ['paquete' => 'Asistente Pro'],
            'lost_reason'    => 'esto no va',
            'next_action_at' => '2026-10-01 10:00',
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame('2026-09-27 10:00:00', $respuesta->json('opportunity.closed_at'));
        $this->assertNull($respuesta->json('opportunity.lost_reason'));
        $this->assertNull($respuesta->json('opportunity.next_action_at'));
        $this->assertSame([['key' => 'paquete', 'label' => 'Paquete', 'type' => 'text', 'value' => 'Asistente Pro']], $respuesta->json('activity.data'));
    }

    /**
     * 11. Reabrir (de una cerrada a una abierta) limpia `closed_at` y `lost_reason`.
     *
     * @return void
     */
    public function test_reabrir_limpia_closed_at_y_lost_reason(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $this->mover($op['id'], ['stage_id' => $this->etapa($pipeline, 'Perdido')->id, 'lost_reason' => 'Precio'])->assertStatus(200);

        $respuesta = $this->mover($op['id'], [
            'stage_id'       => $this->etapa($pipeline, 'Por contactar')->id,
            'note'           => 'Volvió a escribir',
            'next_action_at' => '2026-09-28 10:00',
        ]);

        $respuesta->assertStatus(200);
        $this->assertNull($respuesta->json('opportunity.closed_at'));
        $this->assertNull($respuesta->json('opportunity.lost_reason'));
        $this->assertSame('open', $respuesta->json('opportunity.stage.type'));
        $this->assertSame('2026-09-28 10:00:00', $respuesta->json('opportunity.next_action_at'));
        $this->assertSame('Perdido', $respuesta->json('activity.from_stage_name'));
    }

    /**
     * 12. 🔴 Reabrir cuando el sujeto ya tiene OTRA abierta en el pipeline: 422 y la cerrada queda
     *     como estaba.
     *
     * @return void
     */
    public function test_reabrir_con_otra_abierta_da_422(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $cliente  = $this->crear_cliente();

        $vieja = $this->alta_de_uno($pipeline, $cliente);
        $this->mover($vieja['id'], ['stage_id' => $this->etapa($pipeline, 'Perdido')->id, 'lost_reason' => 'Precio'])->assertStatus(200);

        $nueva = $this->alta_de_uno($pipeline, $cliente);

        $respuesta = $this->mover($vieja['id'], ['stage_id' => $this->etapa($pipeline, 'Por contactar')->id]);

        $respuesta->assertStatus(422);
        $this->assertSame(
            'No se puede reabrir: el cliente ya tiene otra oportunidad abierta en este pipeline (#' . $nueva['id'] . ').',
            $respuesta->json('message')
        );

        $recargada = $this->oportunidad($vieja['id']);
        $this->assertSame('Perdido', $recargada->stage->name);
        $this->assertSame('Precio', $recargada->lost_reason);
        $this->assertNotNull($recargada->closed_at);

        /* Mover entre cerradas (perdida → ganada) no es reabrir: se puede. */
        $this->mover($vieja['id'], ['stage_id' => $this->etapa($pipeline, 'Ganado')->id])
            ->assertStatus(200)
            ->assertJsonPath('opportunity.lost_reason', null);
    }

    /**
     * 13. La respuesta trae la oportunidad RECARGADA con la forma de la ficha (contacto y pipeline
     *     completo), no la instancia en memoria: los conteos del pipeline ya reflejan el movimiento.
     *
     * @return void
     */
    public function test_la_respuesta_trae_la_oportunidad_recargada_con_la_forma_de_la_ficha(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $cliente  = $this->crear_cliente();
        $op       = $this->alta_de_uno($pipeline, $cliente);

        $respuesta = $this->mover($op['id'], [
            'stage_id' => $this->etapa($pipeline, 'Contactado')->id,
            'fields'   => ['canal' => 'WhatsApp'],
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame($cliente->phone, $respuesta->json('opportunity.subject.phone'));
        $this->assertSame($cliente->email, $respuesta->json('opportunity.subject.email'));
        $this->assertSame($pipeline->id, $respuesta->json('opportunity.pipeline.id'));

        $etapas = [];
        foreach ($respuesta->json('opportunity.pipeline.stages') as $etapa) {
            $etapas[$etapa['name']] = $etapa['opportunities_count'];
        }
        $this->assertSame(0, $etapas['Por contactar']);
        $this->assertSame(1, $etapas['Contactado']);

        $this->assertSame(PipelineStage::query()->where('name', 'Contactado')->where('pipeline_id', $pipeline->id)->value('id'), $this->oportunidad($op['id'])->stage_id);
    }
}
