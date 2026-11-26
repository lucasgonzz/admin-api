<?php

namespace App\Console\Commands;

use App\Services\ClientUpgradeMarkService;
use Illuminate\Console\Command;

/**
 * Crea las marcas «requiere actualización» que faltan para las misiones de soporte ya corregidas y todavía no
 * instaladas (misión martin-descripcion-y-actualizaciones, 25/11/2026). Se corre una vez al desplegar; es
 * idempotente (la clave `mission:<id>` evita duplicados).
 */
class BackfillClientUpgradeMarksCommand extends Command
{
    protected $signature = 'client-upgrade-marks:backfill';

    protected $description = 'Crea las marcas de actualización de las misiones de soporte corregidas y no instaladas.';

    /**
     * Ejecuta el backfill.
     *
     * @param ClientUpgradeMarkService $service
     *
     * @return int
     */
    public function handle(ClientUpgradeMarkService $service)
    {
        $count = $service->backfill();
        $this->info('Misiones revisadas: ' . $count);

        return 0;
    }
}
