<?php

namespace App\Events;

use App\Models\SupportMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportMessageReceived implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * Mensaje completo a renderizar en admin-spa.
     *
     * @var SupportMessage|null
     */
    public $message;

    /**
     * Canal por cliente para vista global de bandeja.
     *
     * @var string
     */
    public $client_channel;

    /**
     * Canal por admin para vista personal de asignados.
     *
     * @var string
     */
    public $admin_channel;

    /**
     * Construye evento y resuelve canales de emisión.
     *
     * @param int $support_message_id
     */
    public function __construct(int $support_message_id)
    {
        // Carga relaciones usadas en canales Pusher y en el JSON del payload hacia admin-spa.
        $this->message = self::load_message_for_support_broadcast($support_message_id);
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

    /**
     * Incluye ticket+cliente para nombres de canales (support.client.* / support.admin.*) y payload a Echo.
     */
    public static function load_message_for_support_broadcast(int $support_message_id)
    {
        $message = SupportMessage::query()
            ->where('id', $support_message_id)
            ->withAll()
            ->first();
        if (!is_null($message)) {
            $message->loadMissing('ticket.client', 'ticket.client_employee');
            if ($message->ticket) {
                $message->ticket->loadCount([
                    'messages as unread_messages_count' => function ($sub) {
                        $sub->where('sender_type', 'user')->whereNull('read_at');
                    },
                ]);
            }
        }
        return $message;
    }

    /**
     * Evita enviar a Pusher si el mensaje no pudo resolverse.
     */
    public function broadcastWhen()
    {
        return $this->message !== null;
    }

    /**
     * Define canales de broadcast para inbox global y personal.
     *
     * Incluye support.admins para que cualquier operador conectado refresque badges / UserTicketsNav
     * en tiempo real (mensajes desde empresa-spa no sólo llegaban al canal del asignado).
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
     * Nombre explícito de evento para Echo.
     */
    public function broadcastAs()
    {
        return 'SupportMessageReceived';
    }

    /**
     * Payload transmitido al frontend.
     */
    public function broadcastWith()
    {
        return [
            'message' => $this->message,
        ];
    }
}

