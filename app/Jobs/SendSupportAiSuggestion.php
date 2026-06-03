<?php

namespace App\Jobs;

use App\Events\SupportAiSuggestionPending;
use App\Models\AdminSetting;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\SupportAiSuggestionDeliveryService;
use App\Services\SupportAiSuggestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Genera sugerencia de Claude tras un mensaje entrante de WhatsApp (si la configuración está activa).
 */
class SendSupportAiSuggestion implements ShouldQueue
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
     * Genera la sugerencia y envía o programa el envío según support_ai_auto_send_delay.
     *
     * @param SupportAiSuggestionService         $suggestion_service
     * @param SupportAiSuggestionDeliveryService $delivery_service
     *
     * @return void
     */
    public function handle(
        SupportAiSuggestionService $suggestion_service,
        SupportAiSuggestionDeliveryService $delivery_service
    ): void {
        $ticket = SupportTicket::query()->with('client')->find($this->ticket_id);
        if ($ticket === null) {
            return;
        }

        if ($ticket->status !== 'open') {
            return;
        }

        if ($this->last_message_is_from_admin($ticket->id)) {
            return;
        }

        $result = $suggestion_service->generate($ticket);
        $suggested_message = trim((string) ($result['suggested_message'] ?? ''));
        if ($suggested_message === '') {
            Log::channel('daily')->info('SendSupportAiSuggestion: sugerencia vacía, no se envía.', [
                'ticket_id' => $ticket->id,
                'reasoning' => $result['reasoning'] ?? '',
            ]);

            return;
        }

        $suggested_title = trim((string) ($result['suggested_title'] ?? ''));
        if ($suggested_title !== '' && trim((string) ($ticket->name ?? '')) === '') {
            $ticket->name = $suggested_title;
            $ticket->save();
        }

        $delay = (int) AdminSetting::get('support_ai_auto_send_delay', 0);

        if ($delay <= 0) {
            $delivery_service->deliver_text_reply($ticket, $suggested_message);

            return;
        }

        $ticket->ai_pending_suggestion = $suggested_message;
        $ticket->ai_suggestion_send_at = now()->addSeconds($delay);
        $ticket->save();

        event(new SupportAiSuggestionPending($ticket->id));

        AutoSendPendingSupportSuggestion::dispatch($ticket->id)
            ->delay($ticket->ai_suggestion_send_at);
    }

    /**
     * Indica si el último mensaje del hilo ya es del operador (cancela auto-respuesta).
     *
     * @param int $ticket_id
     *
     * @return bool
     */
    private function last_message_is_from_admin(int $ticket_id): bool
    {
        $last_message = SupportMessage::query()
            ->where('support_ticket_id', $ticket_id)
            ->orderBy('id', 'desc')
            ->first();

        return $last_message !== null && $last_message->sender_type === 'admin';
    }
}
