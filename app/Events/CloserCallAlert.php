<?php

namespace App\Events;

use App\Models\Lead;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento broadcast emitido cuando un lead termina la demo y el closer debe tomar la llamada.
 *
 * Se transmite por el canal privado `closer-alerts`, escuchado únicamente por admins
 * con rol closer. El payload incluye los datos necesarios para mostrar el modal de alerta
 * en CloserPanel.vue con el nombre del lead, el link de Meet y el resumen de la demo.
 */
class CloserCallAlert implements ShouldBroadcast
{
    use Dispatchable;

    /**
     * Lead que terminó la demo y dispara la alerta al closer.
     *
     * @var Lead
     */
    public Lead $lead;

    /**
     * @param Lead $lead Lead que terminó la demo.
     */
    public function __construct(Lead $lead)
    {
        $this->lead = $lead;
    }

    /**
     * Canal privado de alertas del closer; solo admins con is_closer pueden escuchar.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('closer-alerts')];
    }

    /**
     * Nombre del evento para Echo (prefijo punto en el cliente: `.call.alert`).
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'call.alert';
    }

    /**
     * Payload enviado al frontend.
     *
     * Incluye los datos mínimos necesarios para el modal de alerta:
     * - lead_id: para el endpoint de aceptación.
     * - lead_name: nombre del contacto para mostrar en el modal.
     * - meet_url: link de Google Meet para abrir al aceptar.
     * - demo_summary: resumen estructurado generado por Claude (puede ser null).
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'lead_id'      => $this->lead->id,
            'lead_name'    => $this->lead->contact_name,
            'meet_url'     => $this->lead->meet_url,
            'demo_summary' => $this->lead->demo_summary_structured,
        ];
    }
}
