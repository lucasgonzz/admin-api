<?php

namespace App\Events;

use App\Models\SupportTicket;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Notifica que se reinició el debounce antes de consultar a Claude en soporte WhatsApp.
 */
class SupportAiSuggestionScheduled implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @var int Id del ticket de soporte.
     */
    public $support_ticket_id;

    /**
     * @var int Segundos de espera configurados antes de consultar a Claude.
     */
    public $delay_seconds;

    /**
     * @var string ISO8601 del instante en que se consultará a Claude si no hay mensajes nuevos.
     */
    public $consult_at;

    /**
     * @var int Token de debounce para reiniciar animaciones en el frontend.
     */
    public $schedule_token;

    /**
     * @param int    $support_ticket_id
     * @param int    $delay_seconds
     * @param string $consult_at
     * @param int    $schedule_token
     */
    public function __construct(int $support_ticket_id, int $delay_seconds, string $consult_at, int $schedule_token)
    {
        $this->support_ticket_id = $support_ticket_id;
        $this->delay_seconds = $delay_seconds;
        $this->consult_at = $consult_at;
        $this->schedule_token = $schedule_token;
    }

    /**
     * Solo emite si el ticket sigue existiendo.
     *
     * @return bool
     */
    public function broadcastWhen(): bool
    {
        return SupportTicket::query()->where('id', $this->support_ticket_id)->exists();
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
     * Nombre del evento para Echo (.SupportAiSuggestionScheduled).
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'SupportAiSuggestionScheduled';
    }

    /**
     * Payload con tiempos del debounce para animación en admin-spa.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ticket_id'       => $this->support_ticket_id,
            'delay_seconds'   => $this->delay_seconds,
            'consult_at'      => $this->consult_at,
            'schedule_token'  => $this->schedule_token,
        ];
    }
}
