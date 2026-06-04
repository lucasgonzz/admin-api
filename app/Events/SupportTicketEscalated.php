<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Alerta en tiempo real cuando Claude no puede resolver el caso
 * y marca el ticket para revisión humana del operador.
 */
class SupportTicketEscalated implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * Id del ticket escalado.
     *
     * @var int
     */
    public $ticket_id;

    /**
     * Nombre visible del ticket (o fallback "Ticket #id").
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
     * Motivo corto del escalado generado por Claude.
     *
     * @var string
     */
    public $escalation_reason;

    /**
     * @param int    $ticket_id
     * @param string $ticket_name
     * @param string $client_name
     * @param string $escalation_reason
     */
    public function __construct(int $ticket_id, string $ticket_name, string $client_name, string $escalation_reason)
    {
        $this->ticket_id         = $ticket_id;
        $this->ticket_name       = $ticket_name;
        $this->client_name       = $client_name;
        $this->escalation_reason = $escalation_reason;
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
     * Nombre del evento para Echo (.SupportTicketEscalated).
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'SupportTicketEscalated';
    }

    /**
     * Payload transmitido al frontend.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ticket_id'         => $this->ticket_id,
            'ticket_name'       => $this->ticket_name,
            'client_name'       => $this->client_name,
            'escalation_reason' => $this->escalation_reason,
        ];
    }
}
