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
     * Indica si hay mensajes del lead sin responder después del último saliente que le llegó.
     *
     * Qué cuenta como respuesta lo decide {@see LeadMessage::is_reply_to_lead()}, que es la única
     * definición del sistema: un saliente despachado y con `whatsapp_message_id`, o sea que
     * efectivamente salió por WhatsApp. Una sugerencia de la IA esperando verificación NO contesta
     * nada, y un envío que falló tampoco.
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
            /* Respuesta que efectivamente le llegó al lead, sea del setter o del agente. */
            if (LeadMessage::is_reply_to_lead($message)) {
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
