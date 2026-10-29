<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\IaApp\IaDirectorioService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Le pregunta a cada cliente activo quién es su dueño y actualiza el directorio del login de la app
 * `ia.comerciocity.com` (misión cruzada `app-ia-y-memoria`, 29/10/2026, plan §4 A.1).
 *
 * Corre todas las noches (ver `Kernel::schedule()`) y a mano con `--client=<id o slug>`.
 *
 * Calcado de `ai-planes:sincronizar`: en serie con una pausa entre clientes (el shared de Hostinger bloquea
 * la cuenta entera por ráfaga de conexiones) y `try/catch` por cliente (uno caído no corta a los demás).
 * Un cliente con una versión vieja contesta 404 y queda como `version_vieja`: no es un error.
 */
class SincronizarDirectorioIaCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ia:directorio-sincronizar
        {--client= : ID o slug de un solo cliente, para no barrer a todos}';

    /**
     * @var string
     */
    protected $description = 'Actualiza el directorio de dueños del login de la app ia.comerciocity.com (admin-sync/ia-app/dueno de cada cliente)';

    /**
     * @param IaDirectorioService $directorio
     *
     * @return int
     */
    public function handle(IaDirectorioService $directorio): int
    {
        $clientes = $this->clientes_a_barrer();

        if ($clientes === null) {
            return 1;
        }

        if ($clientes->isEmpty()) {
            $this->info('No hay clientes activos para sincronizar.');

            return 0;
        }

        $this->info('Sincronizando el directorio de ' . $clientes->count() . ' cliente(s).');

        $filas   = [];
        $cuentas = [];
        $ultimo  = $clientes->count() - 1;
        $pausa   = (int) config('ia_app.directorio.pausa_ms', 1000);

        foreach ($clientes->values() as $indice => $client) {
            try {
                $resultado = $directorio->sincronizar($client);
                $estado    = $resultado['estado'];
                $detalle   = $resultado['mensaje'];
            } catch (\Throwable $e) {
                Log::channel('daily')->error('ia:directorio-sincronizar: excepción con un cliente.', [
                    'client_id' => (int) $client->id,
                    'error'     => mb_substr($e->getMessage(), 0, 300),
                ]);
                $estado  = 'excepcion';
                $detalle = mb_substr($e->getMessage(), 0, 120);
            }

            $filas[]          = [(int) $client->id, $client->resolve_display_name(), $estado, mb_substr((string) $detalle, 0, 120)];
            $cuentas[$estado] = isset($cuentas[$estado]) ? $cuentas[$estado] + 1 : 1;

            // La pausa va ENTRE clientes y no corre en las pruebas.
            if ($indice < $ultimo && $pausa > 0 && ! $this->getLaravel()->runningUnitTests()) {
                usleep($pausa * 1000);
            }
        }

        $this->table(['Cliente', 'Nombre', 'Estado', 'Detalle'], $filas);

        $resumen = [];
        foreach ($cuentas as $estado => $cantidad) {
            $resumen[] = $estado . ': ' . $cantidad;
        }
        $this->info('Resumen — ' . implode(' · ', $resumen));

        return 0;
    }

    /**
     * Uno solo si vino `--client` (por id o por slug, aunque esté inactivo), o todos los activos.
     *
     * @return \Illuminate\Support\Collection|null Null si el `--client` no existe (ya se avisó).
     */
    private function clientes_a_barrer()
    {
        $pedido = trim((string) $this->option('client'));

        if ($pedido !== '') {
            $client = ctype_digit($pedido) ? Client::find((int) $pedido) : Client::where('slug', $pedido)->first();

            if ($client === null) {
                $this->error('No existe el cliente «' . $pedido . '».');

                return null;
            }

            return collect([$client]);
        }

        return Client::where('is_active', true)->orderBy('id')->get();
    }
}
