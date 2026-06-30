<?php

namespace App\Events;

use App\Models\Lead;
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
     * True cuando el evento es solo una actualización de estado de entrega WhatsApp (entregado/leído/fallido).
     * El frontend usa este flag para omitir refresco de badges y fila de la grilla de leads.
     *
     * @var bool
     */
    public $is_status_update;

    /**
     * @param int      $lead_id
     * @param int|null $lead_message_id
     * @param bool     $is_status_update True solo para broadcasts de cambio de estado de entrega WhatsApp.
     */
    public function __construct(int $lead_id, ?int $lead_message_id = null, bool $is_status_update = false)
    {
        $this->lead_id          = $lead_id;
        $this->lead_message_id  = $lead_message_id;
        $this->is_status_update = $is_status_update;
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
     * Solo se envían IDs. El total de no leídos es per-usuario, por lo que NO viaja
     * en el evento (el canal `leads.admins` es compartido): cada cliente hace
     * GET /lead/unread-badges para obtener su propio total al recibir el evento.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'lead_id'          => $this->lead_id,
            'lead_message_id'  => $this->lead_message_id,
            // El frontend omite refresco de badges/grilla cuando este flag es true.
            'is_status_update' => $this->is_status_update,
        ];
    }
}
