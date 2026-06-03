<?php

namespace App\Events;

use App\Models\SupportTicket;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Notifica que un mensaje fue eliminado del hilo (p. ej. borrador IA obsoleto).
 */
class SupportMessageRemoved implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @var int Id del mensaje eliminado.
     */
    public $support_message_id;

    /**
     * @var int Id del ticket contenedor.
     */
    public $support_ticket_id;

    /**
     * @param int $support_message_id
     * @param int $support_ticket_id
     */
    public function __construct(int $support_message_id, int $support_ticket_id)
    {
        $this->support_message_id = $support_message_id;
        $this->support_ticket_id = $support_ticket_id;
    }

    /**
     * @return bool
     */
    public function broadcastWhen(): bool
    {
        return true;
    }

    /**
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
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'SupportMessageRemoved';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->support_message_id,
            'ticket_id'  => $this->support_ticket_id,
        ];
    }
}
