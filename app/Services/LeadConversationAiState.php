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
     * El escudo y la ventana supervisada son aprobación humana, no una demora larga.
     * Se usa también al ejecutar jobs viejos: el lead puede entrar al tramo después
     * de haber encolado una respuesta que todavía no tenía requiere_verificacion.
     */
    public static function requiere_aprobacion_humana(Lead $lead): bool
    {
        return (bool) $lead->requiere_verificacion_mensajes
            || (LeadWhatsappOnboardingSettings::get_auto_activar_verificacion_al_solicitar_disponibilidad()
                && in_array((string) $lead->status, Lead::ESTADOS_VENTANA_VERIFICACION_MENSAJES, true));
    }

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
     * definición del sistema para un SALIENTE: un mensaje despachado y con `whatsapp_message_id`, o
     * sea que efectivamente salió por WhatsApp. Una sugerencia de la IA esperando verificación NO
     * contesta nada, y un envío que falló tampoco.
     *
     * Además, y por fuera de esa función (una reacción no es un saliente, ver el porqué en
     * {@see Lead::apply_condicion_mensaje_sin_responder()}), un mensaje del lead con
     * {@see LeadMessage::tiene_reaccion_admin()} tampoco cuenta como sin responder: decisión de
     * Lucas del 8/9/2026, reaccionarle desde el panel es una forma de respuesta.
     *
     * 🔴 Y tampoco cuenta un mensaje del lead con `id <= leads.atendido_hasta_message_id`, la
     * marca «no necesita respuesta» de la bandeja (misión flujo-lead-landing-closer, decisión D6
     * de Lucas, 17/10/2026). Esta función es el gate del scheduler de la IA
     * ({@see LeadAiSuggestionScheduler}, {@see \App\Jobs\GenerateLeadAiSuggestionJob}) y del
     * botón de revisión ({@see LeadPendingReviewService}): si el operador dijo que ese mensaje no
     * necesita respuesta, la IA no tiene por qué redactarle una. Es el GEMELO PHP de la cláusula
     * que {@see Lead::apply_condicion_mensaje_sin_responder()} aplica en SQL, y los dos tienen que
     * decir lo mismo (lo verifica `RevisionDeLeadsEnSqlYEnPhpCoincidenTest`, usando esta función
     * como oráculo a través de `LeadPendingReviewService`). Si tocás uno, tocá el otro.
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

        /* Hasta qué id del hilo el operador dio por atendido sin contestar. 0 = nada marcado, y
           como ningún mensaje tiene id 0 la comparación de abajo deja pasar a todos. */
        $atendido_hasta = (int) ($lead->atendido_hasta_message_id ?? 0);

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
                /* Gemelo de la cláusula «no necesita respuesta» del SQL (D6, 17/10/2026). */
                && (int) $candidate->id > $atendido_hasta
                && (string) ($candidate->kind ?? '') !== 'reaction'
                && ! LeadWhatsappReactionService::is_legacy_reaction_content((string) $candidate->content)
                && ! LeadMessage::tiene_reaccion_admin($candidate)) {
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
