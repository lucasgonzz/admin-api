<?php

namespace App\Console\Commands;

use App\Services\RecursosBusquedasService;
use Illuminate\Console\Command;

/**
 * Borra las búsquedas del centro de recursos que ya pasaron la retención (misión
 * busquedas-centro-recursos, 16/10/2026).
 *
 * Corre solo todos los días a las 03:30 (ver `Kernel::schedule()`). Por defecto conserva
 * `config('recursos.busquedas.retencion_dias')` días (90); con `--dias=N` se cambia a mano. Lo que se
 * guarda es lo que escribió un cliente en un cuadro de texto libre, y aunque se descarta lo que parece un
 * dato personal, un nombre propio no se puede detectar: por eso la retención es corta.
 */
class PurgarBusquedasDeRecursosCommand extends Command
{
    /**
     * Nombre y opciones del comando Artisan.
     *
     * @var string
     */
    protected $signature = 'recursos:purgar-busquedas
        {--dias= : Cuántos días se conservan (por defecto, los de config recursos.busquedas.retencion_dias)}';

    /**
     * Descripción visible en php artisan list.
     *
     * @var string
     */
    protected $description = 'Borra las búsquedas del centro de recursos más viejas que la retención';

    /**
     * Ejecuta la purga.
     *
     * @param RecursosBusquedasService $busquedas
     *
     * @return int
     */
    public function handle(RecursosBusquedasService $busquedas): int
    {
        $opcion = $this->option('dias');

        if ($opcion === null || $opcion === '') {
            $dias = (int) config('recursos.busquedas.retencion_dias', 90);
        } elseif (is_numeric($opcion) && (int) $opcion == $opcion && (int) $opcion >= 1) {
            $dias = (int) $opcion;
        } else {
            $this->error('--dias tiene que ser un entero de 1 o más.');

            return 1;
        }

        $borradas = $busquedas->purgar($dias);

        $this->info('Búsquedas de más de ' . $dias . ' días borradas: ' . $borradas . '.');

        return 0;
    }
}
