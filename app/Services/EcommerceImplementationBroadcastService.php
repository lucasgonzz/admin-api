<?php

namespace App\Services;

use App\Events\EcommerceImplementationMessageReceived;

/**
 * Emisión centralizada de eventos Pusher para conversaciones de implementación de ecommerce.
 */
class EcommerceImplementationBroadcastService
{
    /**
     * Notifica a admin-spa que se agregó un mensaje a una implementación de ecommerce.
     *
     * @param int $ecommerce_implementation_id         Implementación afectada.
     * @param int $ecommerce_implementation_message_id Mensaje recién persistido.
     *
     * @return void
     */
    public static function emit_message_received(int $ecommerce_implementation_id, int $ecommerce_implementation_message_id): void
    {
        EcommerceImplementationMessageReceived::dispatch($ecommerce_implementation_id, $ecommerce_implementation_message_id);
    }
}
