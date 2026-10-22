<?php

namespace Tests\Feature\LandingAgenda;

use App\Models\AdminSetting;
use App\Services\LeadAiService;
use App\Services\LeadDemoSettings;

/**
 * `GET /api/landing/agenda/turnos` (D1): la grilla del panel, unión de las instancias, solo los slots
 * con ventana de dos horas, y encima el filtro de presentación (09:00–21:00, en horas enteras, no antes
 * de una hora desde ahora, siete días). Días sin horarios no viajan.
 */
class TurnosDeLaLandingTest extends BaseDeLaAgendaDeLaLanding
{
    public function test_la_forma_de_la_respuesta_y_el_filtro_de_presentacion(): void
    {
        $respuesta = $this->getJson('/api/landing/agenda/turnos')->assertStatus(200);

        $this->assertSame('America/Argentina/Buenos_Aires', $respuesta->json('zona'));
        $this->assertSame(60, $respuesta->json('duracion_minutos'));

        $dias = $respuesta->json('dias');
        $this->assertCount(7, $dias, 'Siete días corridos desde hoy, todos con horarios.');
        $this->assertSame(['fecha' => '2026-10-22', 'etiqueta' => 'jue 22/10', 'nombre' => 'jueves'], array_diff_key($dias[0], ['horarios' => true]));
        $this->assertSame(['hora' => '11:00', 'hasta' => '13:00'], $dias[0]['horarios'][0], 'Hoy, nada antes de una hora desde ahora (10:00 + 60).');
        $this->assertSame('2026-10-28', $dias[6]['fecha']);

        $fechas = array_column($dias, 'fecha');
        $ordenadas = $fechas;
        sort($ordenadas);
        $this->assertSame($ordenadas, $fechas, 'Orden cronológico.');

        foreach ($dias as $dia) {
            foreach ($dia['horarios'] as $horario) {
                $this->assertMatchesRegularExpression('/^(09|1\d|20|21):00$/', $horario['hora'], 'Solo de 09:00 a 21:00, en horas enteras (paso de 60).');
            }
        }
        $manana = $dias[1];
        $this->assertSame('vie 23/10', $manana['etiqueta']);
        $this->assertSame('viernes', $manana['nombre']);
        $this->assertSame(['hora' => '09:00', 'hasta' => '11:00'], $manana['horarios'][0]);
        $this->assertSame(['hora' => '21:00', 'hasta' => '23:00'], $manana['horarios'][count($manana['horarios']) - 1]);
        $this->assertCount(13, $manana['horarios']);
    }

    public function test_los_slots_que_no_caen_en_el_paso_estan_en_la_grilla_pero_no_se_muestran(): void
    {
        $snapshot = null;
        $grilla   = app(LeadAiService::class)->build_availability_json(7, $snapshot, null, null, true);
        $this->assertContains('09:05', $grilla['demos'][$this->demo_1->id]['viernes 2026-10-23'], 'La grilla del panel va cada 5 minutos.');
        $this->assertContains('09:30', $grilla['demos'][$this->demo_1->id]['viernes 2026-10-23']);

        $horas = array_column($this->getJson('/api/landing/agenda/turnos')->json('dias.1.horarios'), 'hora');
        $this->assertNotContains('09:05', $horas);
        $this->assertNotContains('09:30', $horas);
    }

    public function test_los_dias_sin_horarios_no_viajan(): void
    {
        /* Sábado y domingo solo de madrugada: la grilla tiene turnos, pero el filtro los esconde todos. */
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_SABADO, '00:00-08:00');
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_DOMINGO, '00:00-08:00');

        $fechas = array_column($this->getJson('/api/landing/agenda/turnos')->assertStatus(200)->json('dias'), 'fecha');

        $this->assertNotContains('2026-10-24', $fechas);
        $this->assertNotContains('2026-10-25', $fechas);
        $this->assertContains('2026-10-26', $fechas);
    }

    public function test_solo_los_slots_con_ventana_de_dos_horas(): void
    {
        /* Las dos instancias ocupadas mañana de 13:00 a 15:00: ocupan de 12:45 (setup) a 15:10. */
        $this->ocupar($this->demo_1, '2026-10-23', '13:00', '15:00');
        $this->ocupar($this->demo_2, '2026-10-23', '13:00', '15:00');

        $snapshot = null;
        $grilla   = app(LeadAiService::class)->build_availability_json(7, $snapshot, null, null, true);
        $this->assertContains('11:00', $grilla['demos'][$this->demo_1->id]['viernes 2026-10-23'], 'Una demo de una hora a las 11 entra en la grilla...');

        $horas = array_column($this->getJson('/api/landing/agenda/turnos')->json('dias.1.horarios'), 'hora');
        $this->assertContains('10:00', $horas);
        $this->assertNotContains('11:00', $horas, '...pero no entra la reserva de dos horas: no se ofrece.');
        $this->assertNotContains('13:00', $horas);
        $this->assertNotContains('15:00', $horas);
        $this->assertContains('16:00', $horas);
    }

    public function test_el_piso_del_get_nunca_es_menor_que_el_que_exige_la_reserva(): void
    {
        /* Setup de 90 minutos: la reserva no acepta nada antes de las 11:30; el GET tampoco lo ofrece. */
        AdminSetting::set(LeadDemoSettings::KEY_SETUP_MINUTOS_ANTES, '90');

        $hoy = $this->getJson('/api/landing/agenda/turnos')->assertStatus(200)->json('dias.0');
        $this->assertSame('2026-10-22', $hoy['fecha']);
        $this->assertSame('12:00', $hoy['horarios'][0]['hora'], 'Antes, con el piso de la config (60), ofrecía 11:00.');

        $this->reservar($this->cuerpo(['fecha' => '2026-10-22', 'hora' => '11:00']))->assertStatus(409)->assertJsonPath('codigo', 'turno_tomado');
    }

    public function test_los_turnos_van_con_cache_corto_que_se_invalida_al_reservar(): void
    {
        $this->ocupar($this->demo_2, '2026-10-23', '15:00', '17:00');
        $this->assertContains('15:00', array_column($this->getJson('/api/landing/agenda/turnos')->json('dias.1.horarios'), 'hora'));

        /* La otra instancia se ocupa por fuera de la landing: durante el caché, el GET no se entera. */
        $this->ocupar($this->demo_1, '2026-10-23', '15:00', '17:00');
        $this->assertContains('15:00', array_column($this->getJson('/api/landing/agenda/turnos')->json('dias.1.horarios'), 'hora'));

        /* Una reserva desde la web lo invalida. */
        $this->reservar($this->cuerpo(['hora' => '19:00']))->assertStatus(201);
        $this->assertNotContains('15:00', array_column($this->getJson('/api/landing/agenda/turnos')->json('dias.1.horarios'), 'hora'));
    }

    public function test_el_turno_tomado_trae_los_turnos_frescos_aunque_el_get_este_en_cache(): void
    {
        $this->getJson('/api/landing/agenda/turnos')->assertStatus(200);
        $this->ocupar($this->demo_1, '2026-10-23', '14:30', '16:30');
        $this->ocupar($this->demo_2, '2026-10-23', '15:00', '17:00');

        $turnos = $this->reservar($this->cuerpo())->assertStatus(409)->json('turnos');
        $manana = collect($turnos)->firstWhere('fecha', '2026-10-23');
        $this->assertNotContains('15:00', array_column($manana['horarios'], 'hora'));
        $this->assertNotContains('15:00', array_column($this->getJson('/api/landing/agenda/turnos')->json('dias.1.horarios'), 'hora'), 'Y el 409 invalida el caché.');
    }

    public function test_una_sola_instancia_libre_alcanza_para_ofrecer_el_turno(): void
    {
        $this->ocupar($this->demo_1, '2026-10-23', '13:00', '15:00');

        $horas = array_column($this->getJson('/api/landing/agenda/turnos')->json('dias.1.horarios'), 'hora');
        $this->assertContains('13:00', $horas, 'La segunda instancia está libre: el turno se ofrece.');
    }
}
