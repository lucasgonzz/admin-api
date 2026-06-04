<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento emitido cuando un cliente completa una etapa del flujo de implementación vía WhatsApp.
 *
 * Se difunde al canal `admin-implementations` para notificar en tiempo real al panel de admin-spa.
 * El operador recibe un toast con el nombre del cliente y la etapa completada.
 */
class ImplementationStageCompleted implements ShouldBroadcast
{
    use Dispatchable;

    /**
     * ID de la implementación que avanzó de etapa.
     *
     * @var int
     */
    public int $implementation_id;

    /**
     * Número de etapa que acaba de completarse (1–7).
     *
     * @var int
     */
    public int $stage_number;

    /**
     * Nombre del cliente para mostrar en el toast del panel admin.
     *
     * @var string
     */
    public string $client_name;

    /**
     * @param int    $implementation_id ID de la implementación afectada.
     * @param int    $stage_number      Número de etapa completada.
     * @param string $client_name       Nombre del cliente para el toast.
     */
    public function __construct(int $implementation_id, int $stage_number, string $client_name)
    {
        $this->implementation_id = $implementation_id;
        $this->stage_number      = $stage_number;
        $this->client_name       = $client_name;
    }

    /**
     * Canal de difusión: canal compartido del panel admin.
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
     * Nombre del evento escuchado desde admin-spa vía Echo.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'implementation.stage.completed';
    }

    /**
     * Payload enviado al frontend con los datos del evento.
     *
     * @return array{implementation_id: int, stage_number: int, client_name: string}
     */
    public function broadcastWith(): array
    {
        return [
            'implementation_id' => $this->implementation_id,
            'stage_number'      => $this->stage_number,
            'client_name'       => $this->client_name,
        ];
    }
}
