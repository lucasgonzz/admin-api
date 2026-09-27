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

    /* ------------------------------------------------------------------------------------------
     | Ronda de arreglos R1: la próxima acción no queda pegada de la etapa anterior
     |----------------------------------------------------------------------------------------- */

    /**
     * Las actividades `next_action` de una oportunidad, de la más vieja a la más nueva.
     *
     * @param int $oportunidad_id
     *
     * @return array<int, array<string, mixed>> `data` de cada una.
     */
    private function cambios_de_proxima_accion($oportunidad_id)
    {
        return PipelineActivity::query()
            ->where('opportunity_id', $oportunidad_id)
            ->where('type', PipelineActivity::TYPE_NEXT_ACTION)
            ->orderBy('id')
            ->get()
            ->map(function (PipelineActivity $actividad) {
                return ['from' => $actividad->data['from'], 'to' => $actividad->data['to'], 'note' => $actividad->data['note']];
            })
            ->all();
    }

    /**
     * 14. R1.3 (se conserva): una próxima acción MANUAL y FUTURA sobrevive a un movimiento que no la
     *     toca, y la oportunidad lo avisa con `next_action_carries_over`. No hay actividad
     *     `next_action` porque no cambió nada.
     *
     * @return void
     */
    public function test_la_proxima_accion_manual_futura_se_conserva_al_mover(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $antes = $this->putJson('/api/admin/pipeline-opportunities/' . $op['id'], [
            'next_action_at'   => '2026-09-29 11:00',
            'next_action_note' => 'Llamar al dueño',
        ])->assertStatus(200);
        $this->assertSame('manual', $antes->json('opportunity.next_action_source'));
        $this->assertTrue($antes->json('opportunity.next_action_carries_over'));

        $respuesta = $this->mover($op['id'], [
            'stage_id' => $this->etapa($pipeline, 'Contactado')->id,
            'fields'   => ['canal' => 'Llamada'],
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame('2026-09-29 11:00:00', $respuesta->json('opportunity.next_action_at'));
        $this->assertSame('Llamar al dueño', $respuesta->json('opportunity.next_action_note'));
        $this->assertSame('manual', $respuesta->json('opportunity.next_action_source'));
        $this->assertTrue($respuesta->json('opportunity.next_action_carries_over'));

        /* Solo la del PUT: el movimiento no cambió la próxima acción. */
        $this->assertCount(1, $this->cambios_de_proxima_accion($op['id']));
    }

    /**
     * 15. R1.3 (se borra): una próxima acción manual que YA VENCIÓ no sobrevive al movimiento: se
     *     borra entera y queda una actividad `next_action` con el mismo `occurred_at` que el cambio
     *     de etapa. La última actividad de la tarjeta sigue siendo el movimiento.
     *
     * @return void
     */
    public function test_una_proxima_accion_manual_vencida_se_borra_al_mover(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $vencida = $this->putJson('/api/admin/pipeline-opportunities/' . $op['id'], [
            'next_action_at'   => '2026-09-26 18:00',
            'next_action_note' => 'Mandar el video',
        ])->assertStatus(200);
        $this->assertSame('overdue', $vencida->json('opportunity.agenda_bucket'));
        $this->assertFalse($vencida->json('opportunity.next_action_carries_over'), 'Vencida: no se arrastra.');

        $this->clavar_reloj('2026-09-27 10:15:00');

        $respuesta = $this->mover($op['id'], [
            'stage_id' => $this->etapa($pipeline, 'Contactado')->id,
            'fields'   => ['canal' => 'WhatsApp'],
            'note'     => 'Le escribí',
        ]);

        $respuesta->assertStatus(200);
        $this->assertNull($respuesta->json('opportunity.next_action_at'));
        $this->assertNull($respuesta->json('opportunity.next_action_note'));
        $this->assertNull($respuesta->json('opportunity.next_action_source'));
        $this->assertFalse($respuesta->json('opportunity.next_action_carries_over'));
        $this->assertSame('none', $respuesta->json('opportunity.agenda_bucket'));

        $cambios = $this->cambios_de_proxima_accion($op['id']);
        $this->assertSame(['from' => '2026-09-26 18:00:00', 'to' => null, 'note' => null], end($cambios));

        $ultima_de_proxima = PipelineActivity::query()->where('opportunity_id', $op['id'])->where('type', 'next_action')->orderByDesc('id')->first();
        $this->assertSame('2026-09-27 10:15:00', $ultima_de_proxima->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame('stage_change', $respuesta->json('opportunity.last_activity.type'));
        $this->assertSame('Le escribí', $respuesta->json('opportunity.last_activity.body'));
    }

    /**
     * 16. R1.3 (se borra): la próxima acción que puso la AGENDA de la etapa que se deja (la fecha
     *     de la reunión) no sobrevive al pasar a una etapa sin agenda, aunque sea futura. Es el
     *     defecto que motivó R1.
     *
     * @return void
     */
    public function test_la_agenda_de_la_etapa_que_se_deja_no_sobrevive(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $agendada = $this->mover($op['id'], [
            'stage_id' => $this->etapa($pipeline, 'Reunión agendada')->id,
            'fields'   => ['fecha_reunion' => '2026-09-30 15:00'],
        ])->assertStatus(200);
        $this->assertSame('agenda', $agendada->json('opportunity.next_action_source'));
        $this->assertFalse($agendada->json('opportunity.next_action_carries_over'), 'La de la agenda no se arrastra.');

        $respuesta = $this->mover($op['id'], ['stage_id' => $this->etapa($pipeline, 'Calificado')->id, 'fields' => ['usa_sistema' => true]]);

        $respuesta->assertStatus(200);
        $this->assertNull($respuesta->json('opportunity.next_action_at'));
        $this->assertNull($respuesta->json('opportunity.next_action_note'));
        $this->assertNull($respuesta->json('opportunity.next_action_source'));
        $this->assertSame(
            [['from' => '2026-09-30 15:00:00', 'to' => null, 'note' => null]],
            $this->cambios_de_proxima_accion($op['id']),
            'Una sola actividad next_action: la del borrado (la de la agenda no se registra aparte).'
        );
    }

    /**
     * 17. R1.2: una etapa con campo agenda OPCIONAL que queda vacío no es la regla 1: si el payload
     *     trae `next_action_at`, se aplica (manual) con su nota y deja su actividad.
     *
     * @return void
     */
    public function test_agenda_opcional_vacia_con_next_action_at_en_el_payload_se_aplica(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $seguimiento = $this->postJson('/api/admin/pipelines/' . $pipeline->id . '/stages', [
            'name'   => 'Seguimiento',
            'fields' => [['key' => 'volver_a_llamar', 'label' => 'Volver a llamar', 'type' => 'datetime', 'agenda' => true]],
        ])->assertStatus(201)->json('stage.id');

        $respuesta = $this->mover($op['id'], [
            'stage_id'         => $seguimiento,
            'fields'           => ['volver_a_llamar' => ''],
            'next_action_at'   => '2026-10-03 10:00',
            'next_action_note' => 'Preguntar por la demo',
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame('2026-10-03 10:00:00', $respuesta->json('opportunity.next_action_at'));
        $this->assertSame('Preguntar por la demo', $respuesta->json('opportunity.next_action_note'));
        $this->assertSame('manual', $respuesta->json('opportunity.next_action_source'));
        $this->assertTrue($respuesta->json('opportunity.next_action_carries_over'));
        $this->assertSame([], $respuesta->json('activity.data'), 'El campo agenda vacío no va a la foto.');
        $this->assertSame(
            [['from' => null, 'to' => '2026-10-03 10:00:00', 'note' => 'Preguntar por la demo']],
            $this->cambios_de_proxima_accion($op['id'])
        );
    }

    /**
     * 18. Nota de próxima acción sin fecha: 422 en `errors.next_action_at` con el texto del
     *     contrato, tanto con la clave en null (R1.2) como sin la clave (R1.3). No se mueve nada.
     *
     * @return void
     */
    public function test_nota_de_proxima_accion_sin_fecha_da_422_al_mover(): void
    {
        $this->admin_logueado();
        $pipeline   = $this->crear_pipeline();
        $op         = $this->alta_de_uno($pipeline, $this->crear_cliente());
        $contactado = $this->etapa($pipeline, 'Contactado')->id;

        $sin_clave = $this->mover($op['id'], [
            'stage_id'         => $contactado,
            'fields'           => ['canal' => 'WhatsApp'],
            'next_action_note' => 'Llamar',
        ]);
        $sin_clave->assertStatus(422);
        $this->assertSame(['next_action_at' => ['Poné la fecha de la próxima acción.']], $sin_clave->json('errors'));
        $this->assertSame('Poné la fecha de la próxima acción.', $sin_clave->json('message'));

        $en_null = $this->mover($op['id'], [
            'stage_id'         => $contactado,
            'fields'           => ['canal' => 'WhatsApp'],
            'next_action_at'   => null,
            'next_action_note' => 'Llamar',
        ]);
        $en_null->assertStatus(422);
        $this->assertSame(['next_action_at' => ['Poné la fecha de la próxima acción.']], $en_null->json('errors'));

        $this->assertSame('Por contactar', $this->oportunidad($op['id'])->stage->name);
    }

    /**
     * 19. La actividad `next_action` del mover: SÍ por R1.2 (payload), por R1.3 (borrado) y por
     *     cerrar; NO por R1.1 (agenda), que ya queda en la foto del cambio de etapa.
     *
     * @return void
     */
    public function test_actividad_next_action_al_mover_por_regla_2_regla_3_y_cierre_y_no_por_regla_1(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());

        /* R1.1: la agenda la fija, sin actividad aparte. */
        $this->mover($op['id'], ['stage_id' => $this->etapa($pipeline, 'Reunión agendada')->id, 'fields' => ['fecha_reunion' => '2026-09-30 15:00']])
            ->assertStatus(200);
        $this->assertSame([], $this->cambios_de_proxima_accion($op['id']), 'Por R1.1 no hay actividad next_action.');

        /* R1.2: la del payload, con actividad. */
        $this->mover($op['id'], [
            'stage_id'         => $this->etapa($pipeline, 'Contactado')->id,
            'fields'           => ['canal' => 'Llamada'],
            'next_action_at'   => '2026-10-05 10:00',
            'next_action_note' => 'Mandar la propuesta',
        ])->assertStatus(200);

        /* R1.1 otra vez (sin actividad) y después R1.3: la de la agenda se borra, con actividad. */
        $this->mover($op['id'], ['stage_id' => $this->etapa($pipeline, 'Reunión agendada')->id, 'fields' => ['fecha_reunion' => '2026-10-01 09:00']])
            ->assertStatus(200);
        $this->mover($op['id'], ['stage_id' => $this->etapa($pipeline, 'Calificado')->id, 'fields' => ['usa_sistema' => false]])
            ->assertStatus(200);

        /* Una manual futura y después cerrar: se borra, con actividad. */
        $this->putJson('/api/admin/pipeline-opportunities/' . $op['id'], ['next_action_at' => '2026-10-10 10:00', 'next_action_note' => 'Insistir'])
            ->assertStatus(200);
        $this->mover($op['id'], ['stage_id' => $this->etapa($pipeline, 'Ganado')->id, 'fields' => ['paquete' => 'Pro']])
            ->assertStatus(200);

        $this->assertSame([
            ['from' => '2026-09-30 15:00:00', 'to' => '2026-10-05 10:00:00', 'note' => 'Mandar la propuesta'],
            ['from' => '2026-10-01 09:00:00', 'to' => null, 'note' => null],
            ['from' => null, 'to' => '2026-10-10 10:00:00', 'note' => 'Insistir'],
            ['from' => '2026-10-10 10:00:00', 'to' => null, 'note' => null],
        ], $this->cambios_de_proxima_accion($op['id']));
    }

    /**
     * 20. `next_action_at` se ignora SIN validarlo cuando no se aplica: moviendo a una perdida o a
     *     una ganada, y cuando la agenda de la etapa trae valor (R1.1).
     *
     * @return void
     */
    public function test_next_action_at_mal_formado_se_ignora_si_no_se_aplica(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $this->mover($op['id'], [
            'stage_id'       => $this->etapa($pipeline, 'Perdido')->id,
            'lost_reason'    => 'Precio',
            'next_action_at' => 'cuando pueda',
        ])->assertStatus(200)->assertJsonPath('opportunity.next_action_at', null);

        $this->mover($op['id'], [
            'stage_id'       => $this->etapa($pipeline, 'Ganado')->id,
            'next_action_at' => ['no', 'es', 'una', 'fecha'],
        ])->assertStatus(200)->assertJsonPath('opportunity.next_action_at', null);

        /* Reabrir hacia una etapa con agenda: manda la agenda y lo del payload no se mira. */
        $this->mover($op['id'], [
            'stage_id'       => $this->etapa($pipeline, 'Reunión agendada')->id,
            'fields'         => ['fecha_reunion' => '2026-10-02 16:30'],
            'next_action_at' => '2026-99-99T25:00:00.000Z',
        ])->assertStatus(200)
            ->assertJsonPath('opportunity.next_action_at', '2026-10-02 16:30:00')
            ->assertJsonPath('opportunity.next_action_source', 'agenda');
    }

    /**
     * 21. `closed_at` al mover ENTRE cerradas (ganada ↔ perdida): se vuelve a escribir con la hora
     *     del movimiento (regla 5), y el motivo sigue a la etapa.
     *
     * @return void
     */
    public function test_closed_at_al_mover_entre_cerradas(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $this->mover($op['id'], ['stage_id' => $this->etapa($pipeline, 'Ganado')->id])
            ->assertStatus(200)
            ->assertJsonPath('opportunity.closed_at', '2026-09-27 10:00:00');

        $this->clavar_reloj('2026-09-27 12:00:00');
        $this->mover($op['id'], ['stage_id' => $this->etapa($pipeline, 'Perdido')->id, 'lost_reason' => 'Se arrepintió'])
            ->assertStatus(200)
            ->assertJsonPath('opportunity.closed_at', '2026-09-27 12:00:00')
            ->assertJsonPath('opportunity.lost_reason', 'Se arrepintió');

        $this->clavar_reloj('2026-09-27 13:00:00');
        $this->mover($op['id'], ['stage_id' => $this->etapa($pipeline, 'Ganado')->id])
            ->assertStatus(200)
            ->assertJsonPath('opportunity.closed_at', '2026-09-27 13:00:00')
            ->assertJsonPath('opportunity.lost_reason', null);
    }

    /**
     * 22. Reapertura hacia una etapa con agenda obligatoria: la exige (422 en su campo) y, con la
     *     fecha, reabre y fija la próxima acción desde la agenda.
     *
     * @return void
     */
    public function test_reapertura_hacia_una_etapa_con_agenda(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());
        $reunion  = $this->etapa($pipeline, 'Reunión agendada')->id;

        $this->mover($op['id'], ['stage_id' => $this->etapa($pipeline, 'Perdido')->id, 'lost_reason' => 'No es el momento'])->assertStatus(200);

        $sin_fecha = $this->mover($op['id'], ['stage_id' => $reunion]);
        $sin_fecha->assertStatus(422);
        $this->assertSame(['fields.fecha_reunion'], array_keys($sin_fecha->json('errors')));
        $this->assertNotNull($this->oportunidad($op['id'])->closed_at, 'Un 422 no reabre.');

        $respuesta = $this->mover($op['id'], ['stage_id' => $reunion, 'fields' => ['fecha_reunion' => '2026-10-01 11:00']]);
        $respuesta->assertStatus(200);
        $this->assertNull($respuesta->json('opportunity.closed_at'));
        $this->assertNull($respuesta->json('opportunity.lost_reason'));
        $this->assertSame('2026-10-01 11:00:00', $respuesta->json('opportunity.next_action_at'));
        $this->assertSame('Reunión agendada', $respuesta->json('opportunity.next_action_note'));
        $this->assertSame('agenda', $respuesta->json('opportunity.next_action_source'));
    }

    /**
     * 23. R3: un número infinito (`1e999`) es 422 en su campo, no un 500 al serializar la
     *     respuesta. Se manda el JSON crudo porque PHP no puede codificar INF para `postJson`.
     *
     * @return void
     */
    public function test_un_numero_infinito_da_422_y_no_500(): void
    {
        $this->admin_logueado();
        $pipeline   = $this->crear_pipeline();
        $op         = $this->alta_de_uno($pipeline, $this->crear_cliente());
        $calificado = $this->etapa($pipeline, 'Calificado')->id;

        $crudo = $this->call(
            'POST',
            '/api/admin/pipeline-opportunities/' . $op['id'] . '/move',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            '{"stage_id": ' . $calificado . ', "fields": {"empleados": 1e999, "usa_sistema": true}}'
        );
        $crudo->assertStatus(422);
        $this->assertArrayHasKey('fields.empleados', $crudo->json('errors'));

        $como_texto = $this->mover($op['id'], ['stage_id' => $calificado, 'fields' => ['empleados' => '1e999', 'usa_sistema' => true]]);
        $como_texto->assertStatus(422);
        $this->assertArrayHasKey('fields.empleados', $como_texto->json('errors'));

        $this->assertSame('Por contactar', $this->oportunidad($op['id'])->stage->name);
    }
}
