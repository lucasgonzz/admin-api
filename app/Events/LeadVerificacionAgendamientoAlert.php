<?php

namespace App\Events;

use App\Models\Lead;
use App\Models\LeadMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento broadcast emitido cuando un mensaje queda pendiente de verificación humana por el
 * motivo "agendamiento" (el lead está en el tramo solicita_disponibilidad..demo_pendiente_de_terminar,
 * ver LeadAiService::create_message_and_update_lead).
 *
 * Se transmite por el canal privado `verificacion-agendamiento-alerts`. A diferencia de
 * `closer-alerts` (que es exclusivo para el rol closer), este canal lo escuchan todos los admins
 * logueados — cualquiera puede estar supervisando el tramo de agendamiento, no es un rol fijo.
 */
class LeadVerificacionAgendamientoAlert implements ShouldBroadcast
{
    use Dispatchable;

    /**
     * Lead que requiere verificación por agendamiento.
     *
     * @var Lead
     */
    public Lead $lead;

    /**
     * Mensaje sugerido por la IA que quedó pendiente de aprobación humana.
     *
     * @var LeadMessage
     */
    public LeadMessage $message;

    /**
     * @param Lead        $lead    Lead en tramo de agendamiento.
     * @param LeadMessage $message Mensaje pendiente de verificación.
     */
    public function __construct(Lead $lead, LeadMessage $message)
    {
        $this->lead    = $lead;
        $this->message = $message;
    }

    /**
     * Canal privado escuchado por cualquier admin autenticado (sin restricción de rol closer).
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('verificacion-agendamiento-alerts')];
    }

    /**
     * Nombre del evento para Echo (prefijo punto en el cliente: `.verificacion.agendamiento.alert`).
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'verificacion.agendamiento.alert';
    }

    /**
     * Payload mínimo — el frontend ya tiene el patrón de refetch al recibir eventos similares.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'lead_id'    => $this->lead->id,
            'lead_name'  => $this->lead->contact_name,
            'message_id' => $this->message->id,
        ];
    }
}
