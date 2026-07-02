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

    /**
     * Suma de mensajes del lead sin leer agrupados por estado (`leads.status`) del lead.
     *
     * Cada clave del array devuelto es el slug de estado; el valor es la cantidad total de
     * mensajes entrantes (sender = lead) sin leer en todos los leads con ese estado.
     *
     * @param int $admin_id
     * @return array<string, int>
     */
    public static function count_unread_by_status_for_admin(int $admin_id): array
    {
        if ($admin_id < 1) {
            return [];
        }

        // Mensajes entrantes sin lectura del admin, agrupados por el estado actual del lead.
        $rows = LeadMessage::query()
            ->join('leads', 'leads.id', '=', 'lead_messages.lead_id')
            ->where('lead_messages.sender', 'lead')
            ->whereNotExists(function ($query) use ($admin_id) {
                $query->selectRaw('1')
                    ->from('lead_message_reads')
                    ->whereColumn('lead_message_reads.lead_message_id', 'lead_messages.id')
                    ->where('lead_message_reads.admin_id', $admin_id);
            })
            ->groupBy('leads.status')
            ->selectRaw('leads.status as status, COUNT(*) as unread_count')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $status = (string) ($row->status ?? '');
            if ($status === '') {
                continue;
            }
            $result[$status] = (int) ($row->unread_count ?? 0);
        }

        return $result;
    }
}
