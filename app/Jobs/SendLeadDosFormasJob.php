<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Services\LeadEmbudoSeguimientosService;
use App\Services\LeadFlujoEntradaService;
use App\Services\LeadWhatsappOnboardingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Manda el paso 2 del flujo de entrada nuevo (misión embudo-whatsapp-precios, E2, 21/11/2026): cuando el lead
 * contesta lo que se le preguntó SIN preguntar nada, salen las dos formas de arrancar, la página y, si es un lead de la
 * web con lugar en el pool, el link de la prueba.
 *
 * Lo encola el webhook con 3 s de demora (ese inbound no agenda IA: lo que había que decir sale literal). El job NO
 * lleva qué mandar: recalcula los puntos debidos al enviar (`LeadFlujoEntradaService::puntos_debidos`), así que si se
 * reintenta manda sólo lo que falta, y si la IA ya cubrió un punto en un mensaje que salió, no lo repite.
 */
class SendLeadDosFormasJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int ID del lead destinatario.
     */
    private $lead_id;

    /**
     * @param int $lead_id
     */
    public function __construct(int $lead_id)
    {
        $this->lead_id = $lead_id;
    }

    /**
     * Manda los puntos del paso 2 que todavía se le deben al lead.
     *
     * @param LeadWhatsappOnboardingService $onboarding_service
     *
     * @return void
     */
    public function handle(LeadWhatsappOnboardingService $onboarding_service): void
    {
        $lead = Lead::query()->find($this->lead_id);
        if ($lead === null) {
            Log::channel('daily')->warning('SendLeadDosFormasJob: lead no encontrado.', [
                'lead_id' => $this->lead_id,
            ]);

            return;
        }

        try {
            $onboarding_service->enviar_puntos_del_flujo($lead);

            /* E3: el lead que contestó está en `contactado` (si el literal no le dio el link): se programan sus pasos de
               seguimiento. Idempotente: el webhook ya los anclé en su mensaje y acá se vuelven a anclar en el mismo. */
            $lead = $lead->fresh();
            if ($lead !== null && (string) $lead->status === 'contactado') {
                LeadEmbudoSeguimientosService::programar_para_estado($lead, null);
            }
        } finally {
            /* Termine como termine, el literal ya no está «en camino»: si falló, el próximo inbound lo sigue la IA, que
               recibe los puntos que falten. */
            LeadFlujoEntradaService::limpiar_literal_encolado($lead);
        }
    }
}
