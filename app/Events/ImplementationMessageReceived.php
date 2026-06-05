<?php

namespace App\Events;

use App\Models\Implementation;
use App\Models\ImplementationMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento Pusher cuando llega o se envía un mensaje WhatsApp de implementación.
 *
 * admin-spa escucha en `admin-implementations` para agregar el mensaje al hilo
 * abierto sin refetch completo del detalle.
 */
class ImplementationMessageReceived implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * Implementación dueña del mensaje.
     *
     * @var int
     */
    public $implementation_id;

    /**
     * Mensaje recién creado en implementation_messages.
     *
     * @var int
     */
    public $implementation_message_id;

    /**
     * @param int $implementation_id         ID de la implementación.
     * @param int $implementation_message_id ID del mensaje persistido.
     */
    public function __construct(int $implementation_id, int $implementation_message_id)
    {
        $this->implementation_id = $implementation_id;
        $this->implementation_message_id = $implementation_message_id;
    }

    /**
     * Solo emite si la implementación y el mensaje siguen existiendo.
     *
     * @return bool
     */
    public function broadcastWhen(): bool
    {
        if (! Implementation::query()->where('id', $this->implementation_id)->exists()) {
            return false;
        }

        return ImplementationMessage::query()
            ->where('id', $this->implementation_message_id)
            ->exists();
    }

    /**
     * Canal compartido del panel de implementaciones en admin-spa.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('admin-implementations'),
        ];
    }

    /**
     * Nombre del evento escuchado vía Echo (.implementation.message.received).
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'implementation.message.received';
    }

    /**
     * Payload mínimo: implementación afectada y mensaje para append en UI.
     *
     * @return array{implementation_id: int, message: ImplementationMessage|null}
     */
    public function broadcastWith(): array
    {
        $message = ImplementationMessage::query()
            ->where('id', $this->implementation_message_id)
            ->first();

        return [
            'implementation_id' => $this->implementation_id,
            'message'           => $message,
        ];
    }
}
