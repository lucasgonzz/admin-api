<?php

namespace App\Services;

use App\Events\LeadConversationUpdated;
use App\Models\LeadMessage;

/**
 * Emisión centralizada de eventos Pusher para conversaciones de leads.
 */
class LeadBroadcastService
{
    /**
     * Notifica a admin-spa que hubo cambios en la conversación de un lead.
     *
     * @param int      $lead_id
     * @param int|null $lead_message_id Mensaje recién persistido (opcional).
     *
     * @return void
     */
    public static function emit_conversation_updated(int $lead_id, ?int $lead_message_id = null): void
    {
        LeadConversationUpdated::dispatch($lead_id, $lead_message_id);
    }

    /**
     * Total de mensajes del lead sin leer en todo el sistema (sender = lead, read_at nulo).
     *
     * @return int
     */
    public static function count_unread_lead_messages_global(): int
    {
        return (int) LeadMessage::query()
            ->where('sender', 'lead')
            ->whereNull('read_at')
            ->count();
    }
}
