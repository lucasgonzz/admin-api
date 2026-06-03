<?php

namespace App\Services;

use App\Events\SupportMessageReceived;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Log;

/**
 * Persiste y envía por WhatsApp una respuesta de texto del operador/IA en tickets de soporte.
 */
class SupportAiSuggestionDeliveryService
{
    /**
     * @var WhatsappSendService Envío saliente vía Kapso.
     */
    private $whatsapp_send_service;

    /**
     * @param WhatsappSendService|null $whatsapp_send_service Inyección opcional (tests).
     */
    public function __construct(?WhatsappSendService $whatsapp_send_service = null)
    {
        $this->whatsapp_send_service = $whatsapp_send_service ?? new WhatsappSendService();
    }

    /**
     * Crea SupportMessage admin, envía por WhatsApp y emite realtime si el envío fue exitoso.
     *
     * @param SupportTicket $ticket Ticket abierto con source whatsapp y número destino.
     * @param string        $body   Texto a enviar al cliente.
     *
     * @return SupportMessage|null Mensaje persistido o null si no se pudo enviar.
     */
    public function deliver_text_reply(SupportTicket $ticket, string $body): ?SupportMessage
    {
        $text_body = trim($body);
        if ($text_body === '' || $ticket->status !== 'open') {
            return null;
        }

        if ($ticket->source !== 'whatsapp' || empty($ticket->whatsapp_phone)) {
            Log::channel('daily')->warning('SupportAiSuggestionDeliveryService: ticket sin canal WhatsApp.', [
                'ticket_id' => $ticket->id,
            ]);

            return null;
        }

        $message = SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'admin',
            'sender_admin_id'   => null,
            'kind'              => 'text',
            'body'              => $text_body,
            'delivered_at'      => now(),
        ]);

        try {
            $whatsapp_message_id = $this->whatsapp_send_service->send_support_message(
                (string) $ticket->whatsapp_phone,
                $message
            );

            if ($whatsapp_message_id) {
                $message->update([
                    'whatsapp_message_id'    => $whatsapp_message_id,
                    'remote_delivery_status' => null,
                ]);
            } else {
                $message->remote_delivery_status = 'not_received';
                $message->save();
            }
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('SupportAiSuggestionDeliveryService: excepción al enviar.', [
                'ticket_id' => $ticket->id,
                'error'     => $exception->getMessage(),
            ]);
            $message->remote_delivery_status = 'not_received';
            $message->save();
        }

        $message = SupportMessage::where('id', $message->id)->withAll()->first();
        if ($message !== null) {
            event(new SupportMessageReceived($message->id));
        }

        return $message;
    }
}
