<?php

namespace App\Events;

use App\Models\SupportMessage;
use App\Support\BroadcastGuard;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Avisa en tiempo real que cambió el estado de entrega de WhatsApp de un mensaje de soporte
 * (enviado -> entregado -> leído) o que el operador puso/quitó una reacción sobre él.
 *
 * Lo escucha admin-spa como `.support.message.status` (con el punto adelante: el nombre se
 * emite SIN el namespace de Laravel) en el mismo lugar donde ya escucha `SupportMessageRead`, y
 * mezcla estos campos en el mensaje del store y en `ticket.last_message`.
 *
 * Canales: los mismos que `SupportMessageRead` (el del cliente, el del operador asignado y
 * `support.admins`). Como los tickets ya no tienen operador asignado, el canal personal cae en
 * `support.admin.0`, igual que el resto de los eventos de soporte con un ticket sin dueño;
 * `support.admins` es el que escuchan todos los operadores.
 *
 * 🔴 El payload lleva SOLO ids, estados, fechas y el emoji de la reacción: nunca el texto del
 * mensaje ni datos del cliente. Los canales de soporte son públicos y la clave de Pusher está
 * horneada en el bundle del SPA (ver `LosEventosDeBroadcastMandanSoloIdsTest`).
 */
class SupportMessageStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * Id del mensaje cuyo estado cambió.
     *
     * @var int
     */
    public $support_message_id;

    /**
     * Mensaje ya recargado desde la base (null si no existe más).
     *
     * @var SupportMessage|null
     */
    public $message;

    /**
     * Canal público del cliente dueño del ticket.
     *
     * @var string
     */
    public $client_channel;

    /**
     * Canal personal del operador asignado (support.admin.0 si el ticket no tiene dueño).
     *
     * @var string
     */
    public $admin_channel;

    /**
     * @param int $support_message_id Id del SupportMessage recién persistido.
     */
    public function __construct(int $support_message_id)
    {
        $this->support_message_id = $support_message_id;

        // Se relee de la base: el estado que se emite es el que quedó guardado, no el que tenía
        // en memoria quien dispara el evento.
        $this->message = SupportMessage::query()
            ->with('ticket.client')
            ->where('id', $support_message_id)
            ->first();

        if ($this->message === null) {
            $this->client_channel = 'support.client.unknown';
            $this->admin_channel = 'support.admin.0';
            return;
        }

        $ticket = $this->message->ticket;
        $client = $ticket ? $ticket->client : null;
        // uuid del cliente para el canal por cliente ("unknown" si no se pudo resolver).
        $client_uuid = optional($client)->uuid;
        if ($client_uuid == null || $client_uuid === '') {
            $client_uuid = 'unknown';
        }
        // Operador asignado (legacy): 0 cuando el ticket no tiene dueño.
        $assigned = $ticket && $ticket->assigned_admin_id != null
            ? (int) $ticket->assigned_admin_id
            : 0;

        $this->client_channel = 'support.client.' . $client_uuid;
        $this->admin_channel = 'support.admin.' . $assigned;
    }

    /**
     * Emite el evento sin poder voltear a quien lo emitió.
     *
     * Pasa por {@see BroadcastGuard}: el estado ya quedó guardado antes de llegar acá, así que
     * una falla de Pusher se loguea y se sigue (mismo criterio que `SupportTicketUpdated`).
     *
     * @return void
     */
    public static function dispatch()
    {
        BroadcastGuard::emitir(new static(...func_get_args()));
    }

    /**
     * Solo emite si el mensaje existe.
     *
     * @return bool
     */
    public function broadcastWhen()
    {
        return $this->message !== null;
    }

    /**
     * Mismos canales que SupportMessageRead, para reutilizar las suscripciones del SPA.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
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
     * Nombre del evento para Echo: el SPA lo escucha como `.support.message.status`.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'support.message.status';
    }

    /**
     * Payload: ids, estado de entrega, fechas y reacción del operador. Sin texto ni datos personales.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith()
    {
        $message = $this->message;

        return [
            'id'                       => (int) $message->id,
            'support_ticket_id'        => (int) $message->support_ticket_id,
            'whatsapp_delivery_status' => $message->whatsapp_delivery_status,
            'whatsapp_delivered_at'    => $message->whatsapp_delivered_at ? $message->whatsapp_delivered_at->toJSON() : null,
            'whatsapp_seen_at'         => $message->whatsapp_seen_at ? $message->whatsapp_seen_at->toJSON() : null,
            'whatsapp_message_id'      => $message->whatsapp_message_id,
            // Reacción del operador (aditivo: el SPA que no la conoce la ignora).
            'admin_reaction_emoji'     => $message->admin_reaction_emoji,
            'admin_reaction_at'        => $message->admin_reaction_at ? $message->admin_reaction_at->toJSON() : null,
            'admin_reaction_admin_id'  => $message->admin_reaction_admin_id !== null ? (int) $message->admin_reaction_admin_id : null,
        ];
    }
}
