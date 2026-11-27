<?php

namespace App\Console\Commands;

use App\Services\Prueba\PruebaCicloService;
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

    /**
     * Cuántas libres hay AL DÍA (con la última versión publicada de empresa y de tienda); si no hay versiones
     * publicadas, las libres o en armado de siempre.
     *
     * @param PruebaPoolService $pool Servicio del pool.
     *
     * @return int
     */
    private function libres_al_dia(PruebaPoolService $pool): int
    {
        $version = $pool->version_publicada_de_empresa();
        $tienda  = $pool->version_publicada_de_tienda();

        return $version !== '' && $tienda !== '' ? PruebaPoolService::libres_o_creando_al_dia($version, $tienda) : PruebaPoolService::libres_o_creando();
    }

    public function handle(PruebaPoolService $pool): int
    {
        $minimo = $this->option('minimo');
        $minimo = $minimo === null || trim((string) $minimo) === '' ? null : (int) $minimo;

        $objetivo = $minimo === null ? PruebaSettings::pool_minimo() : $minimo;
        $motivo   = $objetivo > $this->libres_al_dia($pool) ? $pool->motivo_para_no_encolar() : null;
        if ($motivo !== null) {
            $this->warn('No se encola: ' . $motivo);

            return 0;
        }

        try {
            $nombres = $pool->mantener($minimo);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $armado = $nombres === [] ? PruebaPoolService::armado_en_curso() : null;
        if ($armado !== null && $objetivo > $this->libres_al_dia($pool)) {
            /* G1 (15/11/2026): no es un error; la próxima corrida del scheduler lo vuelve a intentar. */
            $this->info('Nada encolado: hay un armado en curso (' . $armado->nombre . ', etapa ' . $armado->etapa . '). Se reintenta en la próxima corrida.');
        }

        /* D4: con el pool al día y sin nada en curso, se retira UNA libre desactualizada por corrida. */
        try {
            $retirada = $pool->retirar_una_desactualizada(app(PruebaCicloService::class));
        } catch (\Throwable $e) {
            $this->error('No se pudo retirar la libre desactualizada: ' . $e->getMessage());

            return 1;
        }
        if ($retirada !== null) {
            $this->info('Retirada: ' . $retirada . ' (versión vieja).');
        }

        $this->info('Libres o en armado: ' . PruebaPoolService::libres_o_creando() . ' (mínimo ' . ($minimo === null ? PruebaSettings::pool_minimo() : $minimo) . '). Encoladas: ' . ($nombres === [] ? 'ninguna' : implode(', ', $nombres)) . '.');

        return 0;
    }
}
