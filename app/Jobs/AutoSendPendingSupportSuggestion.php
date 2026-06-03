<?php

namespace App\Jobs;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\SupportAiSuggestionDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Envía por WhatsApp la sugerencia IA pendiente si el operador no respondió antes del timer.
 */
class AutoSendPendingSupportSuggestion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int ID del ticket de soporte.
     */
    private $ticket_id;

    /**
     * @param int $ticket_id
     */
    public function __construct(int $ticket_id)
    {
        $this->ticket_id = $ticket_id;
    }

    /**
     * Envía ai_pending_suggestion si sigue vigente e idempotente respecto al estado del ticket.
     *
     * @param SupportAiSuggestionDeliveryService $delivery_service
     *
     * @return void
     */
    public function handle(SupportAiSuggestionDeliveryService $delivery_service): void
    {
        $ticket = SupportTicket::query()->find($this->ticket_id);
        if ($ticket === null) {
            return;
        }

        if ($ticket->status !== 'open') {
            return;
        }

        if ($this->last_message_is_from_admin($ticket->id)) {
            $this->clear_pending_fields($ticket);

            return;
        }

        $draft_message = SupportMessage::query()
            ->where('support_ticket_id', $ticket->id)
            ->where('is_ai_suggestion_draft', true)
            ->orderBy('id', 'desc')
            ->first();

        if ($draft_message !== null) {
            if ($draft_message->ai_auto_send_at !== null && now()->lt($draft_message->ai_auto_send_at)) {
                return;
            }

            $delivery_service->deliver_draft_message($draft_message, $ticket);
            $this->clear_pending_fields($ticket);

            Log::channel('daily')->info('AutoSendPendingSupportSuggestion: sugerencia enviada automáticamente.', [
                'ticket_id'  => $ticket->id,
                'message_id' => $draft_message->id,
            ]);

            return;
        }

        $pending_text = trim((string) ($ticket->ai_pending_suggestion ?? ''));
        if ($pending_text === '') {
            return;
        }

        if ($ticket->ai_suggestion_send_at !== null && now()->lt($ticket->ai_suggestion_send_at)) {
            return;
        }

        $delivery_service->deliver_text_reply($ticket, $pending_text);
        $this->clear_pending_fields($ticket);

        Log::channel('daily')->info('AutoSendPendingSupportSuggestion: sugerencia enviada automáticamente.', [
            'ticket_id' => $ticket->id,
        ]);
    }

    /**
     * Limpia campos de sugerencia pendiente tras enviar o cancelar.
     *
     * @param SupportTicket $ticket
     *
     * @return void
     */
    private function clear_pending_fields(SupportTicket $ticket): void
    {
        $ticket->ai_pending_suggestion = null;
        $ticket->ai_suggestion_send_at = null;
        $ticket->save();
    }

    /**
     * Indica si el último mensaje del hilo ya es del operador.
     *
     * @param int $ticket_id
     *
     * @return bool
     */
    private function last_message_is_from_admin(int $ticket_id): bool
    {
        $last_message = SupportMessage::query()
            ->where('support_ticket_id', $ticket_id)
            ->where('is_ai_suggestion_draft', false)
            ->orderBy('id', 'desc')
            ->first();

        return $last_message !== null && $last_message->sender_type === 'admin';
    }
}
