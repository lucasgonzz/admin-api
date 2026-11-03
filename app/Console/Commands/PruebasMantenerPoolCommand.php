<?php

namespace App\Console\Commands;

use App\Services\Prueba\PruebaPoolService;
use App\Services\Prueba\PruebaSettings;
use Illuminate\Console\Command;

/**
 * `pruebas:mantener-pool [--minimo=]`: encola el armado de las instancias que falten para sostener
 * `admin_settings.prueba_pool_minimo` instancias libres (decisión D1 del plan de
 * `prueba-alta-instancia`, 3/11/2026). Corre por el scheduler cada diez minutos; cada instancia la
 * arma `CrearInstanciaDePoolJob` en la cola.
 */
class PruebasMantenerPoolCommand extends Command
{
    protected $signature = 'pruebas:mantener-pool {--minimo= : Cuántas libres sostener (default: admin_settings.prueba_pool_minimo)}';

    protected $description = 'Sostiene el pool de instancias libres de la prueba gratuita encolando las que falten.';

    public function handle(PruebaPoolService $pool): int
    {
        $minimo = $this->option('minimo');
        $minimo = $minimo === null || trim((string) $minimo) === '' ? null : (int) $minimo;

        try {
            $nombres = $pool->mantener($minimo);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $this->info('Libres o en armado: ' . PruebaPoolService::libres_o_creando() . ' (mínimo ' . ($minimo === null ? PruebaSettings::pool_minimo() : $minimo) . '). Encoladas: ' . ($nombres === [] ? 'ninguna' : implode(', ', $nombres)) . '.');

        return 0;
    }
}
