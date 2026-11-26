<?php

namespace Tests\Feature\LeadsReferidosCompromisos;

use App\Jobs\DetectarCompromisoEnMensajeJob;
use App\Models\FollowupTemplate;
use App\Models\Lead;

/**
 * Ronda 3 de revisión de E2 (misión leads-referidos-compromisos): el hueco {{2}} de `cc_coord_dia_acordado` es «el momento que
 * propuso el lead, en sus palabras» («el viernes a la mañana»), NO la frase textual («el martes te escribo»).
 *
 *  - El compromiso guarda ese momento en un campo propio (`momento`); la cita sigue siendo la cita.
 *  - Sin momento (los que nacen de una llamada o a mano sin él) la plantilla NO se usa: queda el borrador de la IA como sugerencia y,
 *    con la ventana cerrada, la tarea a Juan.
 *  - Con la acción `esperar` esta plantilla no se usa nunca (dice «Te escribo como quedamos»).
 *  - Lo que se ve sigue siendo lo que sale.
 *
 * Calendario real de 2026. Hoy están EN ROJO.
 */
class RondaTresDeRevisionDeE2Test extends BaseDeReferidosYCompromisos
{
    /** El viernes a las 10:00. */
    private const VIERNES = '2026-11-27 10:00';

    /** Un segundo después de las 10:00 del viernes. */
    private const VIERNES_YA = '2026-11-27 10:00:30';

    /**
     * Martes 24/11 a las 12:00, con el motor prendido y la plantilla real aprobada.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->ir_a('2026-11-24 12:00:00');
        $this->prender_el_motor();
        FollowupTemplate::create([
            'template_name' => 'cc_coord_dia_acordado', 'estado' => 'manual_compromiso', 'dia_numero' => 1, 'language_code' => 'es_AR',
            'body_template' => 'Hola {{1}}! Te escribo como quedamos: me habías dicho que podías hacer la demo de ComercioCity {{2}}. ¿Sigue en pie? Decime el horario que te queda cómodo y te dejo el acceso listo.',
            'activa' => true, 'meta_status' => 'APPROVED',
        ]);
    }

    /**
     * Un compromiso para el viernes 10:00 con la ventana cerrada (el lead habló el martes).
     *
     * @param array<string, mixed> $cuerpo Pisa el cuerpo del compromiso.
     *
     * @return Lead
     */
    private function compromiso_con_la_ventana_cerrada(array $cuerpo): Lead
    {
        $this->ir_a('2026-11-24 12:00:00');
        $lead = $this->lead_de_ro(['phone' => '+54935116' . random_int(10000, 99999)]);
        $this->entrante_a_las($lead, 'Dale, lo vemos.', '2026-11-24 11:00:00');
        $this->crear_compromiso_por_el_panel($lead, array_merge(['vence_at' => self::VIERNES, 'accion' => 'escribir', 'resumen' => 'Retomar la demo.'], $cuerpo));

        return $lead->fresh();
    }

    /**
     * Mueve el reloj a la hora y corre el comando.
     *
     * @return void
     */
    private function llegar_la_hora(): void
    {
        $this->ir_a(self::VIERNES_YA);
        $this->assertSame(0, $this->correr_el_comando_de_compromisos());
    }

    /**
     * Sin `momento` la plantilla NO se usa aunque haya una cita real: nada por Meta, ningún mensaje por plantilla y una tarea para
     * Juan (con la IA caída, no hay ni borrador).
     *
     * @return void
     */
    public function test_sin_momento_no_se_usa_la_plantilla(): void
    {
        foreach (['el martes te escribo', 'mañana te escribo yo', 'el viernes te aviso'] as $cita) {
            $lead = $this->compromiso_con_la_ventana_cerrada(['cita_del_lead' => $cita]);
            $this->fakear_la_ia_caida();

            $this->llegar_la_hora();

            $this->assertSame([], $this->envios->plantillas, '«' . $cita . '»: no sale la plantilla.');
            $this->assertSame(0, \App\Models\LeadMessage::query()->where('lead_id', (int) $lead->id)->whereNotNull('followup_template_id')->count(), '«' . $cita . '»: ningún mensaje por plantilla.');
            $tareas = $this->tareas_del_motor($lead);
            $this->assertCount(1, $tareas, '«' . $cita . '»: queda la tarea para Juan.');
            $this->assertStringStartsWith('Escribile a Ro', (string) $tareas->first()->title);
        }
    }

    /**
     * Sin `momento` y con una IA que escribe un borrador válido: queda el borrador como sugerencia (nunca por plantilla) y la tarea.
     *
     * @return void
     */
    public function test_sin_momento_queda_el_borrador_de_la_ia(): void
    {
        $lead = $this->compromiso_con_la_ventana_cerrada(['cita_del_lead' => 'el martes te escribo']);
        $this->fakear_la_ia_de_compromisos([$this->texto_valido_de_martin()]);

        $this->llegar_la_hora();

        $mensajes = $this->mensajes_del_motor($lead);
        $this->assertCount(1, $mensajes);
        $this->assertFalse((bool) $mensajes->first()->is_followup, 'Es el borrador de la IA, no la plantilla.');
        $this->assertSame($this->texto_valido_de_martin(), (string) $mensajes->first()->content);
        $this->assertCount(1, $this->tareas_del_motor($lead));
        $this->assertSame([], $this->envios->plantillas);
    }

    /**
     * Con `momento` («el viernes a la mañana») sale la plantilla con ESE valor en {{2}} (y no con la cita), y lo que se ve es lo que
     * se manda al aprobar.
     *
     * @return void
     */
    public function test_con_momento_la_plantilla_lleva_el_momento_y_no_la_cita(): void
    {
        $lead = $this->compromiso_con_la_ventana_cerrada(['cita_del_lead' => 'el viernes te aviso', 'momento' => 'el viernes a la mañana']);
        $this->fakear_la_ia_de_compromisos([]);

        $this->llegar_la_hora();

        $mensaje = $this->mensajes_del_motor($lead)->first();
        $this->assertNotNull($mensaje);
        $this->assertTrue((bool) $mensaje->is_followup);
        $this->assertStringContainsString('hacer la demo de ComercioCity el viernes a la mañana. ¿Sigue en pie?', (string) $mensaje->content);
        $this->assertStringNotContainsString('te aviso', (string) $mensaje->content);

        $this->loguear_a($this->admin);
        $this->putJson('/api/admin/lead-message/' . $mensaje->id . '/approve')->assertStatus(200);

        $this->assertCount(1, $this->envios->plantillas);
        $this->assertSame(['Ro', 'el viernes a la mañana'], $this->envios->plantillas[0]['variables']);
        $this->assertCount(0, $this->tareas_del_motor($lead), 'Con la plantilla completa no hace falta tarea.');
    }

    /**
     * Con la acción `esperar` esta plantilla NO se usa nunca, aunque tenga momento: tarea para Juan.
     *
     * @return void
     */
    public function test_esperar_nunca_usa_la_plantilla(): void
    {
        $lead = $this->compromiso_con_la_ventana_cerrada([
            'accion' => 'esperar', 'vence_at' => self::VIERNES, 'cita_del_lead' => 'el viernes te aviso', 'momento' => 'el viernes',
        ]);
        $this->fakear_la_ia_caida();

        $this->llegar_la_hora();

        $this->assertSame([], $this->envios->plantillas);
        $this->assertSame(0, \App\Models\LeadMessage::query()->where('lead_id', (int) $lead->id)->whereNotNull('followup_template_id')->count());
        $this->assertCount(1, $this->tareas_del_motor($lead));
    }

    /**
     * La detección con IA guarda el momento aparte de la cita; si la IA no lo trae, queda sin él.
     *
     * @return void
     */
    public function test_la_deteccion_guarda_el_momento_aparte_de_la_cita(): void
    {
        $this->prender_el_motor(['deteccion' => true]);
        $con    = $this->lead_de_ro(['phone' => '+54935117' . random_int(10000, 99999)]);
        $sin    = $this->lead_de_ro(['phone' => '+54935118' . random_int(10000, 99999)]);
        $m_con  = $this->mensaje_del_lead($con, 'el martes te escribo');
        $m_sin  = $this->mensaje_del_lead($sin, 'el martes te escribo');

        $this->fakear_la_ia_de_compromisos([['hay_compromiso' => true, 'fecha' => '2026-12-01', 'accion' => 'esperar', 'cita' => 'el martes te escribo', 'momento' => 'el martes']]);
        $this->correr_job(new DetectarCompromisoEnMensajeJob((int) $con->id, (int) $m_con->id));
        $this->fakear_la_ia_de_compromisos([['hay_compromiso' => true, 'fecha' => '2026-12-01', 'accion' => 'esperar', 'cita' => 'el martes te escribo']]);
        $this->correr_job(new DetectarCompromisoEnMensajeJob((int) $sin->id, (int) $m_sin->id));

        $this->assertSame('el martes', $this->compromiso_vigente_de($con)->momento);
        $this->assertSame('el martes te escribo', $this->compromiso_vigente_de($con)->cita_del_lead);
        $this->assertNull($this->compromiso_vigente_de($sin)->momento);
    }

    // ------------------------------------------------------------------------------------------
    // El momento no sobrevive a un cambio de día
    // ------------------------------------------------------------------------------------------

    /**
     * «Cambiar fecha» manda sólo `vence_at`: el momento viejo («el viernes a la mañana») ya no vale para el día nuevo. Queda en null y,
     * a la hora, la plantilla no se usa (tarea para Juan con la ventana cerrada).
     *
     * @return void
     */
    public function test_cambiar_la_fecha_borra_el_momento(): void
    {
        $lead = $this->compromiso_con_la_ventana_cerrada(['cita_del_lead' => 'el viernes te aviso', 'momento' => 'el viernes a la mañana']);
        $fila = $this->compromiso_vigente_de($lead);
        $this->loguear_a($this->admin);

        $this->putJson('/api/admin/lead-compromisos/' . $fila->id, ['vence_at' => '2026-12-02 10:00'])->assertStatus(200);

        $this->assertNull($this->compromiso((int) $fila->id)->momento, 'El día cambió: el momento viejo no sirve.');

        $this->fakear_la_ia_caida();
        $this->ir_a('2026-12-02 10:00:30');
        $this->assertSame(0, $this->correr_el_comando_de_compromisos());
        $this->assertSame([], $this->envios->plantillas);
        $this->assertCount(1, $this->tareas_del_motor($lead), 'Sin momento no hay plantilla: tarea para Juan.');
    }

    /**
     * Si el pedido trae un `momento` nuevo junto con la fecha, se respeta; y un PUT que no cambia la fecha no lo toca.
     *
     * @return void
     */
    public function test_cambiar_la_fecha_con_un_momento_nuevo_lo_conserva(): void
    {
        $lead = $this->compromiso_con_la_ventana_cerrada(['momento' => 'el viernes a la mañana']);
        $fila = $this->compromiso_vigente_de($lead);
        $this->loguear_a($this->admin);

        $this->putJson('/api/admin/lead-compromisos/' . $fila->id, ['resumen' => 'Otro resumen.'])->assertStatus(200);
        $this->assertSame('el viernes a la mañana', $this->compromiso((int) $fila->id)->momento, 'Sin cambiar la fecha no se toca.');

        $this->putJson('/api/admin/lead-compromisos/' . $fila->id, ['vence_at' => '2026-12-02 10:00', 'momento' => 'el miércoles a la mañana'])->assertStatus(200);
        $this->assertSame('el miércoles a la mañana', $this->compromiso((int) $fila->id)->momento);
    }

    /**
     * Si la franja horaria corre la ejecución a OTRO día (vence a las 22:30 y sale a las 9 del día siguiente), el momento no se usa:
     * la plantilla no sale y queda la tarea.
     *
     * @return void
     */
    public function test_corrido_al_dia_siguiente_no_usa_el_momento(): void
    {
        $lead = $this->compromiso_con_la_ventana_cerrada(['vence_at' => '2026-11-26 22:30', 'momento' => 'el jueves a la noche']);
        $fila = $this->compromiso_vigente_de($lead);
        $this->fakear_la_ia_caida();

        $this->ir_a('2026-11-26 22:30:30');
        $this->assertSame(0, $this->correr_el_comando_de_compromisos());
        $this->assertSame('vigente', $this->compromiso((int) $fila->id)->status);
        $this->assertNull($this->compromiso((int) $fila->id)->momento, 'Salió otro día: el momento ya no es ese.');

        $this->ir_a('2026-11-27 09:06:00');
        $this->assertSame(0, $this->correr_el_comando_de_compromisos());
        $this->assertSame([], $this->envios->plantillas);
        $this->assertCount(1, $this->tareas_del_motor($lead));
    }

    /**
     * Si la franja sólo corre la HORA dentro del mismo día (vence a las 7 y sale a las 9), el momento sigue valiendo y sale la plantilla.
     *
     * @return void
     */
    public function test_corrido_dentro_del_mismo_dia_conserva_el_momento(): void
    {
        $lead = $this->compromiso_con_la_ventana_cerrada(['vence_at' => '2026-11-27 07:00', 'momento' => 'el viernes a la mañana']);
        $fila = $this->compromiso_vigente_de($lead);
        $this->fakear_la_ia_caida();

        $this->ir_a('2026-11-27 07:00:30');
        $this->assertSame(0, $this->correr_el_comando_de_compromisos());
        $this->assertSame('el viernes a la mañana', $this->compromiso((int) $fila->id)->momento);

        $this->ir_a('2026-11-27 09:06:00');
        $this->assertSame(0, $this->correr_el_comando_de_compromisos());
        $this->assertCount(1, $this->mensajes_del_motor($lead));
        $this->assertTrue((bool) $this->mensajes_del_motor($lead)->first()->is_followup, 'Sale por plantilla, con el mismo momento.');
    }
}
