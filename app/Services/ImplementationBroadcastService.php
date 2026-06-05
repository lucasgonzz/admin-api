<?php

namespace App\Services;

use App\Events\ImplementationMessageReceived;

/**
 * Emisión centralizada de eventos Pusher para conversaciones de implementación.
 */
class ImplementationBroadcastService
{
    /**
     * Notifica a admin-spa que se agregó un mensaje a una implementación.
     *
     * @param int $implementation_id         Implementación afectada.
     * @param int $implementation_message_id Mensaje recién persistido.
     *
     * @return void
     */
    public static function emit_message_received(int $implementation_id, int $implementation_message_id): void
    {
        ImplementationMessageReceived::dispatch($implementation_id, $implementation_message_id);
    }
}
