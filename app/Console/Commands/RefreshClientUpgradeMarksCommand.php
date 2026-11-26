<?php

namespace App\Console\Commands;

use App\Services\ClientUpgradeMarkService;
use Illuminate\Console\Command;

/**
 * Vuelve a evaluar las marcas «requiere actualización» abiertas (misión martin-descripcion-y-actualizaciones,
 * 25/11/2026): calcula desde qué versión publicada se puede actualizar a cada cliente y resuelve las que la
 * versión instalada ya contiene. Corre cada hora desde el scheduler; `--client=N` evalúa un solo cliente.
 */
class RefreshClientUpgradeMarksCommand extends Command
{
    protected $signature = 'client-upgrade-marks:refresh {--client= : Id de un cliente puntual}';

    protected $description = 'Evalúa las marcas de actualización abiertas (versión objetivo y resolución).';

    /**
     * Ejecuta el refresh.
     *
     * @param ClientUpgradeMarkService $service
     *
     * @return int
     */
    public function handle(ClientUpgradeMarkService $service)
    {
        $client = $this->option('client');
        if ($client !== null && $client !== '') {
            $service->refresh_client((int) $client);
            $this->info('Cliente evaluado: ' . (int) $client);

            return 0;
        }
        $this->info('Clientes evaluados: ' . $service->refresh_all());

        return 0;
    }
}
