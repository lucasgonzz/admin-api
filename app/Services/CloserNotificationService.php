<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;

/**
 * Notifica por WhatsApp al admin marcado como closer cuando un lead
 * confirma demo real y queda listo para la llamada de cierre.
 */
class CloserNotificationService
{
    /**
     * @var WhatsappSendService
     */
    private $whatsapp_send_service;

    /**
     * @param WhatsappSendService|null $whatsapp_send_service
     */
    public function __construct(?WhatsappSendService $whatsapp_send_service = null)
    {
        $this->whatsapp_send_service = $whatsapp_send_service ?? new WhatsappSendService();
    }

    /**
     * Envía el aviso al closer si corresponde (lead no notificado antes y closer con teléfono cargado).
     *
     * @param Lead $lead
     *
     * @return void
     */
    public function notify_for_lead(Lead $lead): void
    {
        // Anti-duplicado: si ya se notificó por este lead, no repetir el envío.
        if ($lead->closer_notified_at !== null) {
            return;
        }

        // Closer destinatario: admin con is_closer = true y teléfono cargado.
        $closer = Admin::query()->where('is_closer', true)->whereNotNull('phone_number')->first();
        if ($closer === null) {
            Log::channel('daily')->warning('CloserNotificationService: no hay closer con phone_number configurado.', [
                'lead_id' => $lead->id,
            ]);

            return;
        }

        $phone = trim((string) $closer->phone_number);
        if ($phone === '') {
            return;
        }

        // Referencia legible del lead para el mensaje (contacto, empresa o id).
        $contact_name = trim((string) ($lead->contact_name ?? ''));
        $company_name = trim((string) ($lead->company_name ?? ''));
        $referencia = $contact_name !== '' ? $contact_name : ($company_name !== '' ? $company_name : 'Lead #'.$lead->id);

        // Mensaje fijo en código por ahora (no editable desde admin en este prompt).
        $body = "{$referencia} confirmó la demo y está listo para la llamada. Subite cuando puedas.";

        $message_id = $this->whatsapp_send_service->send_text($phone, $body);

        if ($message_id === null) {
            Log::channel('daily')->warning('CloserNotificationService: fallo al enviar WhatsApp al closer.', [
                'lead_id'   => $lead->id,
                'closer_id' => $closer->id,
            ]);

            return;
        }

        // Solo marcamos como notificado si el envío fue exitoso.
        $lead->closer_notified_at = now();
        $lead->save();

        Log::channel('daily')->info('CloserNotificationService: closer notificado.', [
            'lead_id'   => $lead->id,
            'closer_id' => $closer->id,
        ]);
    }
}
