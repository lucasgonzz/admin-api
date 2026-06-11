<?php

namespace App\Events;

use App\Models\EcommerceImplementation;
use App\Models\EcommerceImplementationMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento Pusher cuando llega o se envía un mensaje WhatsApp de la implementación
 * de la tienda online.
 *
 * admin-spa escucha en `admin-implementations` el evento
 * `ecommerce.implementation.message.received` para agregar el mensaje al hilo abierto.
 */
class EcommerceImplementationMessageReceived implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * Implementación dueña del mensaje.
     *
     * @var int
     */
    public $ecommerce_implementation_id;

    /**
     * Mensaje recién creado en ecommerce_implementation_messages.
     *
     * @var int
     */
    public $ecommerce_implementation_message_id;

    /**
     * @param int $ecommerce_implementation_id         ID de la implementación.
     * @param int $ecommerce_implementation_message_id ID del mensaje persistido.
     */
    public function __construct(int $ecommerce_implementation_id, int $ecommerce_implementation_message_id)
    {
        $this->ecommerce_implementation_id         = $ecommerce_implementation_id;
        $this->ecommerce_implementation_message_id = $ecommerce_implementation_message_id;
    }

    /**
     * Solo emite si la implementación y el mensaje siguen existiendo.
     *
     * @return bool
     */
    public function broadcastWhen(): bool
    {
        if (! EcommerceImplementation::query()->where('id', $this->ecommerce_implementation_id)->exists()) {
            return false;
        }

        return EcommerceImplementationMessage::query()
            ->where('id', $this->ecommerce_implementation_message_id)
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
     * Nombre del evento escuchado vía Echo (.ecommerce.implementation.message.received).
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'ecommerce.implementation.message.received';
    }

    /**
     * Payload mínimo: implementación afectada y mensaje para append en UI.
     *
     * @return array{ecommerce_implementation_id: int, message: EcommerceImplementationMessage|null}
     */
    public function broadcastWith(): array
    {
        $message = EcommerceImplementationMessage::query()
            ->where('id', $this->ecommerce_implementation_message_id)
            ->first();

        return [
            'ecommerce_implementation_id' => $this->ecommerce_implementation_id,
            'message'                     => $message,
        ];
    }
}
