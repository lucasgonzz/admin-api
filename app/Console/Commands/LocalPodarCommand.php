<?php

namespace App\Console\Commands;

use App\Services\Local\ActividadClientesService;
use Illuminate\Console\Command;

/**
 * Borra la actividad de clientes de «El local» que pasó la retención (misión el-local, 3/11/2026).
 *
 * Corre solo todos los días a las 04:20 (ver `Kernel::schedule()`). Por defecto conserva 7 días
 * ({@see ActividadClientesService::RETENCION_DIAS}); con `--dias=N` se cambia a mano. Es un DELETE en tandas
 * contra la base del admin: no le pega a ningún cliente.
 */
class LocalPodarCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'local:podar
        {--dias= : Cuántos días se conservan (por defecto, 7)}';

    /**
     * @var string
     */
    protected $description = 'Borra la actividad de clientes de El local más vieja que la retención';

    /**
     * @param ActividadClientesService $actividad
     *
     * @return int
     */
    public function handle(ActividadClientesService $actividad): int
    {
        $opcion = $this->option('dias');

        if ($opcion === null || $opcion === '') {
            $dias = ActividadClientesService::RETENCION_DIAS;
        } elseif (is_numeric($opcion) && (int) $opcion == $opcion && (int) $opcion >= 1) {
            $dias = (int) $opcion;
        } else {
            $this->error('--dias tiene que ser un entero de 1 o más.');

            return 1;
        }

        $borradas = $actividad->podar($dias);

        $this->info('Actividad de más de ' . $dias . ' días borrada: ' . $borradas . ' filas.');

        return 0;
    }
}
