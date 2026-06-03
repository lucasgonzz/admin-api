<?php

namespace App\Events;

use App\Models\SupportTicket;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Notifica en tiempo real que hay una sugerencia IA pendiente de revisión o envío automático.
 */
class SupportAiSuggestionPending implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @var int Id del ticket con sugerencia pendiente.
     */
    public $support_ticket_id;

    /**
     * @param int $support_ticket_id
     */
    public function __construct(int $support_ticket_id)
    {
        $this->support_ticket_id = $support_ticket_id;
    }

    /**
     * Solo emite si el ticket sigue existiendo y tiene texto pendiente.
     *
     * @return bool
     */
    public function broadcastWhen(): bool
    {
        return SupportTicket::query()
            ->where('id', $this->support_ticket_id)
            ->whereNotNull('ai_pending_suggestion')
            ->where('ai_pending_suggestion', '!=', '')
            ->exists();
    }

    /**
     * Canales tenant y admin del ticket, más bandeja global.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $ticket = SupportTicket::query()
            ->with('client:id,uuid')
            ->find($this->support_ticket_id);

        $client_uuid = 'unknown';
        if ($ticket && $ticket->client && ! empty($ticket->client->uuid)) {
            $client_uuid = (string) $ticket->client->uuid;
        }

        $assigned = $ticket && $ticket->assigned_admin_id !== null
            ? (int) $ticket->assigned_admin_id
            : 0;

        return [
            new Channel('support.client.'.$client_uuid),
            new Channel('support.admin.'.$assigned),
            new Channel('support.admins'),
        ];
    }

    /**
     * Nombre del evento para Echo (.SupportAiSuggestionPending).
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'SupportAiSuggestionPending';
    }

    /**
     * Payload con datos de la sugerencia pendiente para el panel de conversación.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $ticket = SupportTicket::query()
            ->where('id', $this->support_ticket_id)
            ->first();

        if ($ticket === null) {
            return [
                'ticket_id'             => $this->support_ticket_id,
                'ai_pending_suggestion' => null,
                'ai_suggestion_send_at'   => null,
            ];
        }

        return [
            'ticket_id'             => $ticket->id,
            'ai_pending_suggestion' => $ticket->ai_pending_suggestion,
            'ai_suggestion_send_at'   => $ticket->ai_suggestion_send_at
                ? $ticket->ai_suggestion_send_at->toIso8601String()
                : null,
        ];
    }
}
