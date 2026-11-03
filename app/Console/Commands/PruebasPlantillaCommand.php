<?php

namespace App\Console\Commands;

use App\Services\Prueba\PruebaPoolService;
use App\Services\Prueba\PruebaVpsService;
use Illuminate\Console\Command;

/**
 * `pruebas:plantilla [--version=] [--tienda=]`: arma en el VPS la plantilla pre-extraída de una versión
 * publicada de empresa y de tienda (`/root/plantillas/<producto>-v<v>/`, con `vendor/` instalado), de
 * la que `pool-crear.sh` copia cada instancia en segundos (decisión D3 del plan de
 * `prueba-alta-instancia`, 3/11/2026). Sin opciones, la última publicada de cada una.
 *
 * Se corre a mano después de cada release de empresa o de tienda, antes de que
 * `pruebas:mantener-pool` arme instancias nuevas.
 */
class PruebasPlantillaCommand extends Command
{
    protected $signature = 'pruebas:plantilla {--version= : Versión de empresa (default: la última publicada)} {--tienda= : Versión de tienda (default: la última publicada)}';

    protected $description = 'Arma en el VPS la plantilla pre-extraída de una versión publicada de empresa y de tienda para el pool de pruebas.';

    public function handle(PruebaPoolService $pool, PruebaVpsService $vps): int
    {
        $version = trim((string) $this->option('version'));
        $tienda  = trim((string) $this->option('tienda'));

        if ($version === '') {
            $version = $pool->version_publicada_de_empresa();
        }
        if ($tienda === '') {
            $tienda = $pool->version_publicada_de_tienda();
        }

        if ($version === '' || $tienda === '') {
            $this->error('No hay una versión publicada de empresa (' . $version . ') o de tienda (' . $tienda . ').');

            return 1;
        }

        $this->info('Armando la plantilla de empresa ' . $version . ' y tienda ' . $tienda . ' en el VPS (composer puede tardar minutos)...');

        try {
            $resultado = $vps->pool_plantilla($version, $tienda);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $this->info('Plantilla lista.');
        $this->line(json_encode($resultado, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return 0;
    }
}
