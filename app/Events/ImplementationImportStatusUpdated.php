<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento emitido cuando cambia el estado de importación de una categoría en la Etapa 4.
 *
 * Se difunde al canal `admin-implementations` para que admin-spa actualice el panel en tiempo real.
 */
class ImplementationImportStatusUpdated implements ShouldBroadcast
{
    use Dispatchable;

    /**
     * ID de la implementación afectada.
     *
     * @var int
     */
    public int $implementation_id;

    /**
     * Categoría importada: articles | clients | suppliers.
     *
     * @var string
     */
    public string $category;

    /**
     * Estado actual: pending | importing | success | failed.
     *
     * @var string
     */
    public string $status;

    /**
     * Mensaje de error si status === failed; null en caso contrario.
     *
     * @var string|null
     */
    public ?string $error;

    /**
     * @param int         $implementation_id ID de la implementación.
     * @param string      $category          articles | clients | suppliers.
     * @param string      $status            pending | importing | success | failed.
     * @param string|null $error             Detalle del error o null.
     */
    public function __construct(int $implementation_id, string $category, string $status, ?string $error = null)
    {
        $this->implementation_id = $implementation_id;
        $this->category          = $category;
        $this->status            = $status;
        $this->error             = $error;
    }

    /**
     * Canal de difusión compartido del panel admin.
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
        return 'implementation.import.status_updated';
    }

    /**
     * Payload enviado al frontend.
     *
     * @return array{implementation_id: int, category: string, status: string, error: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'implementation_id' => $this->implementation_id,
            'category'          => $this->category,
            'status'            => $this->status,
            'error'             => $this->error,
        ];
    }
}
