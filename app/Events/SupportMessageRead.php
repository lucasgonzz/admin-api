<?php

namespace App\Events;

use App\Models\SupportMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Notifica actualización de read_at en un mensaje (doble check / "visto").
 */
class SupportMessageRead implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * Mensaje con read_at y relaciones mínimas.
     *
     * @var SupportMessage|null
     */
    public $message;

    /**
     * @var string
     */
    public $client_channel;

    /**
     * @var string
     */
    public $admin_channel;

    /**
     * @param int $support_message_id
     */
    public function __construct(int $support_message_id)
    {
        $this->message = SupportMessageReceived::load_message_for_support_broadcast($support_message_id);
        if (is_null($this->message)) {
            $this->client_channel = 'support.client.unknown';
            $this->admin_channel = 'support.admin.0';
            return;
        }
        $ticket = $this->message->ticket;
        $client = $ticket ? $ticket->client : null;
        $client_uuid = optional($client)->uuid;
        if ($client_uuid == null || $client_uuid === '') {
            $client_uuid = 'unknown';
        }
        $assigned = $ticket && $ticket->assigned_admin_id != null
            ? (int) $ticket->assigned_admin_id
            : 0;
        $this->client_channel = 'support.client.' . $client_uuid;
        $this->admin_channel = 'support.admin.' . $assigned;
    }

    public function broadcastWhen()
    {
        return $this->message !== null;
    }

    /**
     * Mismos canales que un mensaje nuevo, para reutilizar suscripciones Pusher.
     *
     * support.admins notifica a todos los operadores para alinear contadores del inbox y lecturas.
     */
    public function broadcastOn()
    {
        return [
            new Channel($this->client_channel),
            new Channel($this->admin_channel),
            new Channel('support.admins'),
        ];
    }

    /**
     * Nombre de evento para Echo en admin-spa.
     */
    public function broadcastAs()
    {
        return 'SupportMessageRead';
    }

    /**
     * Payload con el modelo actualizado (incluye read_at).
     */
    public function broadcastWith()
    {
        return [
            'message' => $this->message,
        ];
    }
}
