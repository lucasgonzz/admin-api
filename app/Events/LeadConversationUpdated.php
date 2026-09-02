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
     * Estado de entrega de WhatsApp que originó el broadcast ('entregado', 'leido' o 'fallido').
     * `null` cuando el evento no es una actualización de estado (mensaje nuevo, etc.).
     *
     * @var string|null
     */
    public $delivery_status;

    /**
     * @param int      $lead_id
     * @param int|null $lead_message_id
     * @param bool     $is_status_update True solo para broadcasts de cambio de estado de entrega WhatsApp.
     * @param string|null $delivery_status Estado de entrega WhatsApp puntual ('entregado'/'leido'/'fallido').
     */
    public function __construct(int $lead_id, ?int $lead_message_id = null, bool $is_status_update = false, ?string $delivery_status = null)
    {
        $this->lead_id          = $lead_id;
        $this->lead_message_id  = $lead_message_id;
        $this->is_status_update = $is_status_update;
        $this->delivery_status  = $delivery_status;
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
     * Payload mínimo, y acá «mínimo» es literal: nada de esto crece con el modelo.
     *
     * 🔴 Este evento es el único de los de leads que nunca chocó con el límite de 10240 bytes
     * de Pusher Channels, y la razón vale escribirla: manda **solo ids, un booleano y un slug
     * corto**, ninguno de los cuales crece con el lead ni con lo que escriba un cliente. Un
     * payload es seguro por su forma, no porque a alguien le haya parecido chico: el docblock
     * de `LeadSuggestionCreated` afirmaba que excluir `messages` alcanzaba, y la producción lo
     * desmintió el 2/9/2026. Si alguna vez se le agrega un modelo acá, tiene que pasar por
     * {@see \App\Support\BroadcastPayloadBudget} como los otros tres eventos.
     *
     * El total de no leídos es per-usuario, por lo que NO viaja en el evento (el canal
     * `leads.admins` es compartido): cada cliente hace GET /lead/unread-badges para obtener su
     * propio total al recibir el evento.
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
            // Estado de entrega puntual (string corto, no compromete el límite de 10KB).
            'delivery_status'  => $this->delivery_status,
        ];
    }
}
