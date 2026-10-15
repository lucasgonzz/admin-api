<?php

namespace App\Observers;

use App\Models\LeadMessage;
use App\Services\LeadOrigenService;
use Illuminate\Support\Facades\Log;

/**
 * Calcula el origen del lead cuando nace su PRIMER mensaje entrante (misión
 * origen-del-lead-y-conversiones-meta, 13/10/2026).
 *
 * Es un observer y no un renglón adentro de `WhatsappWebhookController::handle_lead_message()` a
 * propósito: ese controlador lo está reescribiendo otra misión (multi-número), y un gancho adentro
 * garantiza conflicto. Acá se entera igual, porque el webhook crea el mensaje con `LeadMessage::create()`.
 *
 * 🔴 No puede tirar abajo el webhook: si esto falla, el mensaje del lead tiene que quedar guardado
 * igual y Kapso tiene que recibir su 200. Todo va adentro de un try/catch que solo loguea.
 */
class OrigenDesdeElPrimerMensajeObserver
{
    /**
     * @param LeadMessage $mensaje Recién creado.
     *
     * @return void
     */
    public function created(LeadMessage $mensaje)
    {
        try {
            /* El filtro barato va acá, antes de resolver el servicio: casi todos los mensajes que se
               crean son salientes o eventos internos. */
            if ((string) $mensaje->sender !== 'lead' || (bool) $mensaje->is_status_event || ! $mensaje->lead_id) {
                return;
            }

            app(LeadOrigenService::class)->al_crear_mensaje($mensaje);
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('Origen del lead: no se pudo calcular desde el primer mensaje.', [
                'lead_message_id' => $mensaje->id,
                'lead_id'         => $mensaje->lead_id,
                'error'           => $exception->getMessage(),
            ]);
        }
    }
}
