<?php

namespace Tests\Feature\LandingAgenda;

use App\Mail\LeadDemoAccesoMail;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\DemoScheduledWhatsappService;
use App\Services\LeadAtribucionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Segunda vuelta, 🟠4: una vez escrita la reserva, ningún paso posterior puede terminar en un 500 sin
 * carta ni aviso. El trabajo de después ya está despachado; lo que falla queda como error en el hilo.
 */
class ReservaEscritaNuncaTerminaEn500Test extends BaseDeLaAgendaDeLaLanding
{
    public function test_si_falla_el_save_final_responde_201_y_salen_la_carta_y_el_aviso(): void
    {
        Lead::saving(function (Lead $lead) {
            if ($lead->demo_reservada_desde_landing_at !== null && $lead->isDirty('status')) {
                throw new \RuntimeException('falla simulada del guardado final');
            }
        });

        $this->reservar($this->cuerpo())->assertStatus(201)->assertJsonPath('turno.desde', '15:00');

        $lead = Lead::query()->where('phone', '+5493416665544')->firstOrFail();
        $this->assertSame('demo_agendada', $lead->status, 'La reserva quedó escrita por el UPDATE.');
        Mail::assertSent(LeadDemoAccesoMail::class);
        $this->assertCount(1, $this->enviados_con(DemoScheduledWhatsappService::TEMPLATE_NAME));
        $this->assertTrue(
            LeadMessage::query()->where('lead_id', $lead->id)->where('is_error', true)->where('content', 'like', '%falla simulada del guardado final%')->exists(),
            'El fallo queda en el hilo como error.'
        );
    }

    public function test_si_falla_la_atribucion_la_reserva_sigue_y_queda_el_rastro(): void
    {
        $this->app->instance(LeadAtribucionService::class, new class extends LeadAtribucionService {
            public function registrar_contacto_de_reserva(\App\Models\LeadMessage $evento, array $datos): void
            {
                throw new \RuntimeException('atribución caída');
            }
        });

        $this->reservar($this->cuerpo())->assertStatus(201);

        $lead = Lead::query()->where('phone', '+5493416665544')->firstOrFail();
        Mail::assertSent(LeadDemoAccesoMail::class);
        $this->assertTrue(LeadMessage::query()->where('lead_id', $lead->id)->where('is_error', true)->where('content', 'like', '%atribución caída%')->exists());
        $this->assertTrue(DB::table('lead_atribucion_reservas')->where('lead_id', $lead->id)->exists(), 'La reserva se acredita igual.');
    }
}
