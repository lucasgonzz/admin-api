<?php

namespace App\Events;

use App\Models\Lead;
use App\Services\LeadBroadcastService;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento Pusher cuando cambia la conversación WhatsApp de un lead (mensaje nuevo o lectura).
 *
 * admin-spa escucha en `leads.admins` para actualizar tabla, conversación abierta y badge del menú.
 *
 * El payload es mínimo (solo IDs + unread_total) para no superar el límite de 10KB de Pusher.
 * El frontend hace un GET al recibir el evento para cargar los datos actualizados del lead y mensaje.
 */
class LeadConversationUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @var int Lead afectado.
     */
    public $lead_id;

    /**
     * @var int|null Mensaje recién creado (opcional).
     */
    public $lead_message_id;

    /**
     * @param int      $lead_id
     * @param int|null $lead_message_id
     */
    public function __construct(int $lead_id, ?int $lead_message_id = null)
    {
        $this->lead_id        = $lead_id;
        $this->lead_message_id = $lead_message_id;
    }

    /**
     * @return bool
     */
    public function broadcastWhen(): bool
    {
        return Lead::query()->where('id', $this->lead_id)->exists();
    }

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('leads.admins'),
        ];
    }

    /**
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'LeadConversationUpdated';
    }

    /**
     * Payload mínimo para no superar el límite de 10KB de Pusher.
     *
     * Solo se envían IDs y el total global de no leídos.
     * El frontend hace GET /leads/{id} y GET /lead-messages/{id} al recibirlo.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'lead_id'        => $this->lead_id,
            'lead_message_id' => $this->lead_message_id,
            'unread_total'   => LeadBroadcastService::count_unread_lead_messages_global(),
        ];
    }
}
