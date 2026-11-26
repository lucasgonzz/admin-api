<?php

namespace App\Jobs;

use App\Services\ClientUpgradeMarkService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Vuelve a evaluar las marcas «requiere actualización» de un cliente (misión martin-descripcion-y-actualizaciones,
 * 25/11/2026). Lo despacha el cierre de un upgrade en `terminada` (`ClientVersionUpgrade::boot()`), después del
 * commit: la consulta a GitHub no corre adentro del request que cierra el upgrade ni lo puede hacer fallar.
 */
class RefreshClientUpgradeMarksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Misma conexión que el aviso de actualización: la atiende `queue:work database` del scheduler. */
    const CONEXION_DE_COLA = 'database';

    /** Reintentos: si GitHub no responde, el refresh horario lo vuelve a mirar igual. */
    public $tries = 1;

    /** @var int Cliente cuyas marcas se evalúan. */
    public $client_id;

    /**
     * @param int $client_id
     */
    public function __construct(int $client_id)
    {
        $this->client_id = $client_id;
    }

    /**
     * Evalúa las marcas abiertas del cliente.
     *
     * @param ClientUpgradeMarkService $service
     *
     * @return void
     */
    public function handle(ClientUpgradeMarkService $service)
    {
        $service->refresh_client($this->client_id);
    }
}
