<?php

namespace App\Console\Commands;

use App\Services\Local\LogosService;
use Illuminate\Console\Command;

/**
 * Trae el logo de cada negocio desde su propio sistema para «El local» (misión el-local, 3/11/2026).
 *
 * Corre solo todos los días a las 04:10 (ver `Kernel::schedule()`), en segundo plano: es un barrido en serie
 * contra el `empresa-api` de cada cliente activo, con un timeout de 6 s por cliente. Con `--client=` se corre
 * para uno solo (id, slug o uuid; ese puede estar inactivo).
 *
 * La lógica entera está en {@see LogosService}; acá solo se recorre y se resume. Un cliente que falla no corta
 * el barrido: queda con su `logo_sync_status` y se sigue con el próximo.
 */
class LocalSincronizarLogosCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'local:sincronizar-logos
        {--client= : Id, slug o uuid de un solo cliente (por defecto, todos los activos)}';

    /**
     * @var string
     */
    protected $description = 'Trae el logo de cada negocio (admin-sync/branding) para dibujarlo en El local';

    /**
     * @param LogosService $logos
     *
     * @return int
     */
    public function handle(LogosService $logos): int
    {
        $filtro   = $this->option('client');
        $clientes = $logos->clientes($filtro);

        if ($filtro !== null && $filtro !== '' && $clientes->count() === 0) {
            $this->error('No encontré ningún cliente con id, slug o uuid "' . $filtro . '".');

            return 1;
        }

        $por_estado = [];
        foreach ($clientes as $client) {
            try {
                $resultado = $logos->sincronizar($client);
                $estado    = $resultado['estado'];
            } catch (\Throwable $e) {
                // Un cliente roto no puede dejar sin logo al resto del barrido.
                $estado = 'excepcion';
                $this->warn('Cliente #' . (int) $client->id . ': ' . get_class($e));
            }

            $por_estado[$estado] = isset($por_estado[$estado]) ? $por_estado[$estado] + 1 : 1;
        }

        ksort($por_estado);
        $partes = [];
        foreach ($por_estado as $estado => $cantidad) {
            $partes[] = $estado . ': ' . $cantidad;
        }

        $this->info('Logos sincronizados de ' . $clientes->count() . ' clientes' . (count($partes) > 0 ? ' (' . implode(', ', $partes) . ')' : '') . '.');

        return 0;
    }
}
