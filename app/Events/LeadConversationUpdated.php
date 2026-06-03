<?php

namespace App\Events;

use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadBroadcastService;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento Pusher cuando cambia la conversación WhatsApp de un lead (mensaje nuevo o lectura).
 *
 * admin-spa escucha en `leads.admins` para actualizar tabla, conversación abierta y badge del menú.
 */
class LeadConversationUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @var int Lead afectado.
     */
    public $lead_id;

    /**
     * @var int|null Mensaje recién creado (opcional, para append en UI sin refetch).
     */
    public $lead_message_id;

    /**
     * @param int      $lead_id
     * @param int|null $lead_message_id
     */
    public function __construct(int $lead_id, ?int $lead_message_id = null)
    {
        $this->lead_id = $lead_id;
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
     * Lead con contador de no leídos, mensaje opcional y total global para el nav.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $lead = Lead::query()
            ->where('id', $this->lead_id)
            ->with([
                'target_client',
                'promoted_client',
                'created_by_admin',
                'demo',
                'personalized_demo_videos',
            ])
            ->withUnreadLeadMessagesCount()
            ->first();

        $message = null;
        if ($this->lead_message_id !== null) {
            $message = LeadMessage::query()
                ->with('attachments')
                ->where('id', $this->lead_message_id)
                ->first();
        }

        return [
            'lead'         => $lead,
            'message'      => $message,
            'unread_total' => LeadBroadcastService::count_unread_lead_messages_global(),
        ];
    }
}
