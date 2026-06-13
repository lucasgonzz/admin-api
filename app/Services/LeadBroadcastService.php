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
     * Total de mensajes del lead sin leer para un admin específico.
     *
     * Un mensaje se considera no leído para un admin si no existe un registro
     * en lead_message_reads para ese (lead_message_id, admin_id).
     *
     * @param int $admin_id
     * @return int
     */
    public static function count_unread_for_admin(int $admin_id): int
    {
        return (int) LeadMessage::query()
            ->where('sender', 'lead')
            ->whereNotExists(function ($query) use ($admin_id) {
                $query->selectRaw('1')
                    ->from('lead_message_reads')
                    ->whereColumn('lead_message_reads.lead_message_id', 'lead_messages.id')
                    ->where('lead_message_reads.admin_id', $admin_id);
            })
            ->count();
    }
}
