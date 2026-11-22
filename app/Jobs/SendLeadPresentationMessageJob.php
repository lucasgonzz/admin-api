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
 * Envía el mensaje de presentación de ComercioCity después del primer contacto WhatsApp: a los
 * ~60 s para el lead que escribió lo suyo, a los ~10 s para el que llegó con la frase de un botón
 * (misión flujo-lead-landing-closer, 17/10/2026). La demora y la variante se deciden al encolar
 * (LeadWhatsappOnboardingService); este job solo lee la variante ya asignada al lead, así que no
 * necesita saber de qué tipo era.
 *
 * Excepción (misión martin-link-prueba-landing, 13/11/2026): al lead de la web la presentación es la
 * oferta de la prueba gratis, sin variante, y eso lo decide el encolado y viaja en
 * `$oferta_de_prueba`. Default false: un job encolado antes del deploy se deserializa sin la
 * propiedad y sigue como siempre.
 *
 * Segunda excepción (misión embudo-whatsapp-precios, E2, 21/11/2026): con el flujo de entrada nuevo prendido, la
 * presentación son los puntos del paso 1 que todavía no salieron (`LeadFlujoEntradaService`), y eso viaja en
 * `$modo = 'flujo_entrada'`. Default null: un job encolado antes del deploy se deserializa sin la propiedad y
 * sigue como siempre.
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
     * @var bool true = mandar la oferta de la prueba gratis en vez de la variante A/B.
     */
    private $oferta_de_prueba = false;

    /**
     * @var string|null `flujo_entrada` = mandar los puntos del paso 1 del flujo de entrada nuevo; null = como siempre.
     */
    private $modo = null;

    /**
     * @param int         $lead_id
     * @param string|null $display_name
     * @param bool        $oferta_de_prueba
     * @param string|null $modo Modo especial de la presentación (`flujo_entrada`) o null.
     */
    public function __construct(int $lead_id, ?string $display_name = null, bool $oferta_de_prueba = false, ?string $modo = null)
    {
        $this->lead_id = $lead_id;
        $this->oferta_de_prueba = $oferta_de_prueba;
        $this->modo = $modo;
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

        $onboarding_service->send_presentation_message($lead, $this->display_name, (bool) $this->oferta_de_prueba, $this->modo);
    }

    /**
     * @return bool Para los tests: si este job manda el paso 1 del flujo de entrada nuevo.
     */
    public function es_flujo_de_entrada(): bool
    {
        return $this->modo === LeadWhatsappOnboardingService::MODO_FLUJO_ENTRADA;
    }

    /**
     * @return bool Para los tests: si este job manda la oferta de la prueba.
     */
    public function es_oferta_de_prueba(): bool
    {
        return (bool) $this->oferta_de_prueba;
    }
}
