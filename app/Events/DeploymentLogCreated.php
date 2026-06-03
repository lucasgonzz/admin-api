<?php

namespace App\Events;

use App\Models\DeploymentLog;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emite una nueva línea de log de deployment al canal privado del upgrade.
 */
class DeploymentLogCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Línea de log creada.
     *
     * @var DeploymentLog
     */
    public $log;

    /**
     * @param  DeploymentLog  $log
     */
    public function __construct(DeploymentLog $log)
    {
        $this->log = $log;
    }

    /**
     * Canal privado por ID de upgrade.
     *
     * @return PrivateChannel
     */
    public function broadcastOn()
    {
        return new PrivateChannel('deployment.' . $this->log->client_version_upgrade_id);
    }

    /**
     * Nombre del evento para Echo.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'log.created';
    }

    /**
     * Payload enviado al frontend.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith()
    {
        return [
            'log' => $this->log,
        ];
    }
}
