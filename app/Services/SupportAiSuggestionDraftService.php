<?php

namespace App\Services;

use App\Events\SupportMessageReceived;
use App\Events\SupportMessageRemoved;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Log;

/**
 * Borradores de sugerencia IA persistidos como mensajes admin en el hilo de soporte.
 */
class SupportAiSuggestionDraftService
{
    /**
     * Elimina borradores IA del ticket y notifica al frontend.
     *
     * @param int $ticket_id
     *
     * @return void
     */
    public function delete_drafts_for_ticket(int $ticket_id): void
    {
        // Cancelar aprobación no confirma envío y libera el próximo entrante para el monitor.
        \App\Models\SupportCodexOperation::where('support_ticket_id', $ticket_id)
            ->where('status', 'awaiting_approval')->update(['status' => 'cancelled', 'detail' => 'Borrador descartado o reemplazado.', 'approval_payload' => null]);
        $drafts = SupportMessage::query()
            ->where('support_ticket_id', $ticket_id)
            ->where('is_ai_suggestion_draft', true)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('support_codex_operations')->whereColumn('support_codex_operations.message_id', 'support_messages.id')->where('status', 'sending');
            })
            ->get();

        if ($drafts->isEmpty()) {
            return;
        }

        foreach ($drafts as $draft) {
            $message_id = (int) $draft->id;
            $draft->delete();
            event(new SupportMessageRemoved($message_id, $ticket_id));
        }

        Log::channel('daily')->info('SupportAiSuggestionDraftService: borradores IA eliminados.', [
            'ticket_id' => $ticket_id,
            'count'     => $drafts->count(),
        ]);
    }

    /**
     * Limpia campos de sugerencia pendiente en el ticket y elimina borradores del hilo.
     *
     * @param SupportTicket $ticket
     *
     * @return void
     */
    public function clear_ticket_pending_state(SupportTicket $ticket): void
    {
        $this->delete_drafts_for_ticket((int) $ticket->id);

        $ticket->ai_pending_suggestion = null;
        $ticket->ai_suggestion_send_at = null;
        $ticket->save();
    }

    /**
     * Crea un mensaje borrador con la sugerencia de Claude y sincroniza flags del ticket.
     *
     * @param SupportTicket $ticket
     * @param string        $body                    Texto sugerido por Claude.
     * @param int           $auto_send_delay_seconds Segundos hasta el envío automático (0 = sin timer).
     *
     * @return SupportMessage
     */
    public function create_draft(SupportTicket $ticket, string $body, int $auto_send_delay_seconds): SupportMessage
    {
        $text_body = trim($body);
        $this->delete_drafts_for_ticket((int) $ticket->id);

        $auto_send_at = null;
        if ($auto_send_delay_seconds > 0) {
            $auto_send_at = now()->addSeconds($auto_send_delay_seconds);
        }

        $message = SupportMessage::create([
            'support_ticket_id'       => $ticket->id,
            'sender_type'             => 'admin',
            'sender_admin_id'         => null,
            'kind'                    => 'text',
            'body'                    => $text_body,
            'is_ai_suggestion_draft'  => true,
            'ai_auto_send_at'         => $auto_send_at,
            /* Sello de autoría: sobrevive a la aprobación, cuando el mensaje deja de ser
             * borrador y pasa a figurar a nombre del operador que lo mandó. */
            'ai_generated_at'         => now(),
        ]);

        $ticket->ai_pending_suggestion = $text_body;
        $ticket->ai_suggestion_send_at = $auto_send_at;
        $ticket->save();

        $loaded = SupportMessage::query()->where('id', $message->id)->withAll()->first();
        if ($loaded !== null) {
            try {
                event(new SupportMessageReceived($loaded->id));
            } catch (\Throwable $exception) {
                // La conversación se puede recargar; no se pierde un borrador ya persistido por Pusher.
                Log::channel('daily')->warning('Soporte: borrador guardado sin aviso realtime.', ['ticket_id' => $ticket->id]);
            }
        }

        return $message;
    }
}
