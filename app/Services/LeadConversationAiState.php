<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadMessage;

/**
 * Estado de la conversación WhatsApp del lead relevante para sugerencias IA de Claude.
 *
 * Centraliza reglas compartidas entre el scheduler automático y el pedido manual del setter.
 */
class LeadConversationAiState
{
    /**
     * Cuenta mensajes entrantes del lead (excluye el primero, que dispara onboarding sin IA).
     *
     * @param int $lead_id
     *
     * @return int
     */
    public static function count_lead_inbound_messages(int $lead_id): int
    {
        return (int) LeadMessage::query()
            ->where('lead_id', $lead_id)
            ->where('sender', 'lead')
            ->where(function ($query) {
                $query->where(function ($sub) {
                    $sub->whereNull('kind')->orWhere('kind', '!=', 'reaction');
                });
            })
            ->get()
            ->filter(function (LeadMessage $message) {
                return ! LeadWhatsappReactionService::is_legacy_reaction_content((string) $message->content);
            })
            ->count();
    }

    /**
     * Indica si hay mensajes del lead sin respuesta del setter tras el último envío saliente.
     *
     * Considera respuesta: mensaje del setter en estado enviado/aprobado o sugerencia de sistema ya aprobada.
     *
     * @param Lead $lead Lead con relación `messages` cargada (orden por id).
     *
     * @return bool
     */
    public static function has_unanswered_lead_messages(Lead $lead): bool
    {
        $messages = $lead->messages;
        if ($messages === null || $messages->isEmpty()) {
            return false;
        }

        $last_outbound_index = -1;
        $index = 0;

        foreach ($messages as $message) {
            $sender = (string) $message->sender;
            $status = (string) $message->status;

            /* Respuesta del setter pegada o enviada por WhatsApp. */
            if ($sender === 'setter' && in_array($status, ['enviado', 'aprobado'], true)) {
                $last_outbound_index = $index;
            }

            /* Sugerencia de Claude ya enviada al lead. */
            if ($sender === 'sistema' && $status === 'aprobado') {
                $last_outbound_index = $index;
            }

            $index++;
        }

        $cursor = $last_outbound_index + 1;
        $total = $messages->count();

        while ($cursor < $total) {
            $candidate = $messages[$cursor];
            if ((string) $candidate->sender === 'lead'
                && (string) $candidate->status === 'enviado'
                && (string) ($candidate->kind ?? '') !== 'reaction'
                && ! LeadWhatsappReactionService::is_legacy_reaction_content((string) $candidate->content)) {
                return true;
            }
            $cursor++;
        }

        return false;
    }

    /**
     * Indica si existe una sugerencia de Claude pendiente de revisión (no seguimiento automático).
     *
     * @param Lead $lead
     *
     * @return bool
     */
    public static function has_pending_non_followup_suggestion(Lead $lead): bool
    {
        return LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('sender', 'sistema')
            ->where('status', 'sugerido')
            ->where('is_followup', false)
            ->exists();
    }
}
