<?php

namespace Tests\Feature\LandingAgenda;

use App\Models\Lead;

/**
 * Segunda vuelta, 🟡7: `demo_reservada_desde_landing_at` vale para el turno que reservó desde la web. Si
 * la agenda cambia por otro camino (reagenda, cancelación, panel, PATCH), se borra.
 */
class MarcaDeLaReservaWebTest extends BaseDeLaAgendaDeLaLanding
{
    private function lead_reservado(): Lead
    {
        $this->reservar($this->cuerpo())->assertStatus(201);
        $lead = Lead::query()->where('phone', '+5493416665544')->firstOrFail();
        $this->assertNotNull($lead->demo_reservada_desde_landing_at);

        return $lead;
    }

    public function test_reagendar_por_otro_camino_borra_la_marca(): void
    {
        $lead = $this->lead_reservado();

        $lead->demo_start_time = '18:00';
        $lead->demo_end_time   = '20:00';
        $lead->save();

        $this->assertNull($lead->fresh()->demo_reservada_desde_landing_at);
    }

    public function test_cancelar_la_demo_borra_la_marca(): void
    {
        $lead = $this->lead_reservado();

        $lead->demo_id         = null;
        $lead->demo_date       = null;
        $lead->demo_start_time = null;
        $lead->demo_end_time   = null;
        $lead->status          = 'solicita_disponibilidad';
        $lead->save();

        $this->assertNull($lead->fresh()->demo_reservada_desde_landing_at);
    }

    public function test_extender_el_fin_desde_el_panel_la_conserva(): void
    {
        $lead = $this->lead_reservado();

        // «Cambiar hora de fin» del panel: mismo turno elegido por el lead, más tiempo.
        $lead->demo_end_time = '23:30';
        $lead->save();

        $this->assertNotNull($lead->fresh()->demo_reservada_desde_landing_at);
    }

    public function test_un_cambio_que_no_toca_el_turno_la_conserva(): void
    {
        $lead = $this->lead_reservado();

        $lead->update(['contact_name' => 'Marina L.', 'demo_mail_sent_at' => now()]);
        $lead->status = 'demo_en_curso';
        $lead->save();

        $this->assertNotNull($lead->fresh()->demo_reservada_desde_landing_at);
    }

    public function test_volver_a_reservar_desde_la_web_con_la_demo_vencida_renueva_la_marca(): void
    {
        $lead = $this->lead_reservado();
        $primera = $lead->demo_reservada_desde_landing_at;

        /* La demo del viernes pasó sin que entrara. */
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-26 10:00:00', 'America/Argentina/Buenos_Aires'));
        $lead->update(['status' => 'demo_pendiente_de_ingreso']);

        $this->reservar($this->cuerpo(['fecha' => '2026-10-27', 'hora' => '15:00']))->assertStatus(201);

        $lead = $lead->fresh();
        $this->assertSame('2026-10-27', $lead->demo_date->format('Y-m-d'));
        $this->assertNotNull($lead->demo_reservada_desde_landing_at);
        $this->assertTrue($lead->demo_reservada_desde_landing_at->gt($primera));
    }
}
