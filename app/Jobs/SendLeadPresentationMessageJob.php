<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Services\LeadWhatsappOnboardingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Envía el mensaje de presentación de ComercioCity ~60 s después del primer contacto WhatsApp.
 */
class SendLeadPresentationMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int ID del lead destinatario.
     */
    private $lead_id;

    /**
     * @var string|null Nombre usado en el saludo (capturado al primer inbound).
     */
    private $display_name;

    /**
     * @param int         $lead_id
     * @param string|null $display_name
     */
    public function __construct(int $lead_id, ?string $display_name = null)
    {
        $this->lead_id = $lead_id;
        $this->display_name = $display_name !== null && trim($display_name) !== ''
            ? trim($display_name)
            : null;
    }

    /**
     * Envía presentación solo si el lead sigue en estado `nuevo`.
     *
     * @param LeadWhatsappOnboardingService $onboarding_service
     *
     * @return void
     */
    public function handle(LeadWhatsappOnboardingService $onboarding_service): void
    {
        $lead = Lead::query()->find($this->lead_id);
        if ($lead === null) {
            Log::channel('daily')->warning('SendLeadPresentationMessageJob: lead no encontrado.', [
                'lead_id' => $this->lead_id,
            ]);

            return;
        }

        $onboarding_service->send_presentation_message($lead, $this->display_name);
    }
}
