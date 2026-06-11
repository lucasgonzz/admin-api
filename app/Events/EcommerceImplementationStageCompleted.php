<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento emitido cuando un cliente completa una etapa del flujo de implementación
 * de la tienda online vía WhatsApp.
 *
 * Se difunde al canal `admin-implementations` con el evento
 * `ecommerce.implementation.stage.completed` para notificar al panel de admin-spa.
 */
class EcommerceImplementationStageCompleted implements ShouldBroadcast
{
    use Dispatchable;

    /**
     * ID de la implementación de ecommerce que avanzó de etapa.
     *
     * @var int
     */
    public int $ecommerce_implementation_id;

    /**
     * Número de etapa que acaba de completarse (1–5).
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
     * @param int    $ecommerce_implementation_id ID de la implementación afectada.
     * @param int    $stage_number                Número de etapa completada.
     * @param string $client_name                 Nombre del cliente para el toast.
     */
    public function __construct(int $ecommerce_implementation_id, int $stage_number, string $client_name)
    {
        $this->ecommerce_implementation_id = $ecommerce_implementation_id;
        $this->stage_number                = $stage_number;
        $this->client_name                 = $client_name;
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
        return 'ecommerce.implementation.stage.completed';
    }

    /**
     * Payload enviado al frontend con los datos del evento.
     *
     * @return array{ecommerce_implementation_id: int, stage_number: int, client_name: string}
     */
    public function broadcastWith(): array
    {
        return [
            'ecommerce_implementation_id' => $this->ecommerce_implementation_id,
            'stage_number'                => $this->stage_number,
            'client_name'                 => $this->client_name,
        ];
    }
}
