<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Alerta en tiempo real cuando un ticket supera el umbral sin respuesta del operador.
 */
class SupportTicketAlert implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * Id del ticket demorado.
     *
     * @var int
     */
    public $ticket_id;

    /**
     * Nombre visible del ticket.
     *
     * @var string
     */
    public $ticket_name;

    /**
     * Nombre del cliente o contacto remoto.
     *
     * @var string
     */
    public $client_name;

    /**
     * Minutos transcurridos desde el último mensaje del cliente.
     *
     * @var int
     */
    public $minutos_sin_respuesta;

    /**
     * @param int    $ticket_id
     * @param string $ticket_name
     * @param string $client_name
     * @param int    $minutos_sin_respuesta
     */
    public function __construct(int $ticket_id, string $ticket_name, string $client_name, int $minutos_sin_respuesta)
    {
        $this->ticket_id             = $ticket_id;
        $this->ticket_name           = $ticket_name;
        $this->client_name           = $client_name;
        $this->minutos_sin_respuesta = $minutos_sin_respuesta;
    }

    /**
     * Canal global escuchado por operadores conectados en admin-spa.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('support.admins'),
        ];
    }

    /**
     * Nombre del evento para Echo (.SupportTicketAlert).
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'SupportTicketAlert';
    }

    /**
     * Payload transmitido al frontend.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ticket_id'             => $this->ticket_id,
            'ticket_name'           => $this->ticket_name,
            'client_name'           => $this->client_name,
            'minutos_sin_respuesta' => $this->minutos_sin_respuesta,
        ];
    }
}
