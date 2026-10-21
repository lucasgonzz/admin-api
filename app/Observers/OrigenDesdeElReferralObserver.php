<?php

namespace App\Observers;

use App\Models\WhatsappAdReferral;
use App\Services\LeadOrigenService;
use Illuminate\Support\Facades\Log;

/**
 * Atribuye al anuncio el lead que YA existía cuando llegó el referral (misión
 * origen-del-lead-y-conversiones-meta, 13/10/2026).
 *
 * El webhook crudo de Meta (el que trae el bloque `referral`) y el de Kapso (el que crea el lead y su
 * primer mensaje) llegan en cualquier orden. Si el referral llega primero, el origen lo resuelve
 * `OrigenDesdeElPrimerMensajeObserver` cuando nace el mensaje; si llega después, lo resuelve este.
 *
 * Es un observer y no un renglón en `MetaRawWebhookController` para no tocar el endpoint, que tiene
 * prohibido crear leads y está escrito para contestar 200 pase lo que pase.
 *
 * 🔴 No puede tirar abajo el webhook: todo adentro de un try/catch que solo loguea. El referral ya
 * quedó guardado; atribuirlo es un extra.
 */
class OrigenDesdeElReferralObserver
{
    /**
     * @param WhatsappAdReferral $referral Recién guardado.
     *
     * @return void
     */
    public function created(WhatsappAdReferral $referral)
    {
        try {
            app(LeadOrigenService::class)->al_crear_referral($referral);
            $mensaje = \App\Models\LeadMessage::query()->where('whatsapp_message_id', $referral->wamid)
                ->where('sender', 'lead')->where('is_status_event', false)->first();
            if ($mensaje !== null) {
                app(\App\Services\LeadAtribucionService::class)->registrar_ctwa($mensaje, $referral);
            }
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('Origen del lead: no se pudo atribuir el referral a un lead.', [
                'whatsapp_ad_referral_id' => $referral->id,
                'error'                   => $exception->getMessage(),
            ]);
        }
    }
}
