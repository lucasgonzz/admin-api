<?php

namespace App\Events;

use App\Models\Lead;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento emitido cuando se crea automáticamente una sugerencia pendiente para un lead.
 *
 * Cubre dos orígenes:
 *  - {@see \App\Services\LeadAiService}: sugerencia de seguimiento generada por Claude.
 *  - {@see \App\Console\Commands\SendDemoReminders}: recordatorio pre-demo hardcodeado.
 *
 * El canal `leads.admins` es escuchado por cualquier operador conectado en admin-spa
 * para actualizar la fila del lead en la tabla sin recargar la página.
 */
class LeadSuggestionCreated implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * Identificador del lead cuya sugerencia fue creada.
     *
     * Se prefiere el id sobre el modelo completo para no serializar el objeto
     * en el constructor; se carga la versión más fresca en {@see broadcastWith}.
     *
     * @var int
     */
    public $lead_id;

    /**
     * @param int $lead_id Identificador del lead actualizado.
     */
    public function __construct(int $lead_id)
    {
        $this->lead_id = $lead_id;
    }

    /**
     * Solo emite si el lead sigue existiendo en la base de datos.
     *
     * @return bool
     */
    public function broadcastWhen(): bool
    {
        return Lead::query()->where('id', $this->lead_id)->exists();
    }

    /**
     * Canal compartido escuchado por todos los operadores de admin-spa.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('leads.admins'),
        ];
    }

    /**
     * Nombre del evento para Echo (.LeadSuggestionCreated).
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'LeadSuggestionCreated';
    }

    /**
     * Payload del evento: lead con relaciones habituales, sin `messages`.
     *
     * Se excluye `messages` para mantenerse bajo el límite de ~10 KB de Pusher Channels.
     * admin-spa actualiza los flags del lead (tiene_sugerencia_pendiente, etc.) en la tabla;
     * los mensajes de conversación se cargan bajo demanda cuando el setter abre el panel.
     *
     * @return array{lead: \App\Models\Lead|null}
     */
    public function broadcastWith(): array
    {
        // Cargar el lead con relaciones necesarias para la tabla, excluyendo messages.
        $lead = Lead::query()
            ->where('id', $this->lead_id)
            ->with([
                'target_client',
                'promoted_client',
                'created_by_admin',
                'demo',
                'personalized_demo_videos',
            ])
            ->first();

        return [
            'lead' => $lead,
        ];
    }
}
