<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\ClientAiPlanSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Empuja a cada cliente el paquete de IA que tiene asignado y deja el desenlace en `ai_plan_sync_*`
 * (misión foto-sucursal-y-asistente-configurable, 17/9/2026).
 *
 * Corre solo todas las noches (ver `Kernel::schedule()`), y a mano cuando hace falta reintentar uno.
 *
 * 🔴 **Dos decisiones que no son cosméticas, calcadas de `tokens:recolectar`:**
 *
 *  1. **En serie, con un segundo de pausa entre clientes.** El shared hosting de Hostinger bloquea
 *     la IP de la CUENTA ENTERA por ráfaga de conexiones (~18/s medidas), y cuarenta y cinco
 *     requests seguidos es exactamente el patrón que la dispara. El costo de la pausa es menos de un
 *     minuto en total, de madrugada; el costo del bloqueo es que se cae todo lo que sale de esta
 *     máquina.
 *
 *  2. **`try/catch` por cliente.** Un cliente caído no puede cortar el barrido de los otros. El
 *     service ya se compromete a no lanzar; este `catch` es el cinturón sobre los tirantes.
 *
 * No hay ventana de reintento como en `tokens:recolectar` porque el push es idempotente y corre
 * todas las noches: un cliente que estuvo caído recibe su plan en la corrida siguiente sin más.
 */
class SincronizarAiPlanesCommand extends Command
{
    /**
     * Nombre y opciones del comando Artisan.
     *
     * @var string
     */
    protected $signature = 'ai-planes:sincronizar
        {--client= : ID de un solo cliente, para reintentar sin barrer a todos}';

    /**
     * Descripción visible en php artisan list.
     *
     * @var string
     */
    protected $description = 'Empuja a cada cliente el paquete de IA asignado y registra el desenlace en ai_plan_sync_*';

    /**
     * Segundos de espera entre un cliente y el siguiente.
     *
     * @var int
     */
    const PAUSA_ENTRE_CLIENTES = 1;

    /**
     * Ejecuta el barrido.
     *
     * @param ClientAiPlanSyncService $sync Servicio que hace el trabajo.
     *
     * @return int
     */
    public function handle(ClientAiPlanSyncService $sync): int
    {
        $clientes = $this->clientes_a_barrer();

        if ($clientes === null) {
            return 1;
        }

        if ($clientes->isEmpty()) {
            $this->info('No hay clientes activos para sincronizar.');

            return 0;
        }

        $this->info('Sincronizando el paquete de IA de ' . $clientes->count() . ' cliente(s).');

        $resultados = [];
        $ultimo     = $clientes->count() - 1;

        foreach ($clientes->values() as $indice => $client) {
            try {
                $resultado = $sync->pushear_al_cliente($client);

                $resultados[] = [
                    (int) $client->id,
                    $client->resolve_display_name(),
                    $resultado['estado'],
                    $this->recortar($resultado['mensaje']),
                ];
            } catch (\Throwable $exception) {
                /* El service se compromete a no lanzar, así que llegar acá significa que algo se
                 * rompió fuera de su contrato. Se anota y se sigue. */
                Log::channel('daily')->error('ai-planes:sincronizar: excepción empujando el plan de un cliente.', [
                    'client_id' => $client->id,
                    'error'     => $exception->getMessage(),
                ]);

                $resultados[] = [
                    (int) $client->id,
                    $client->resolve_display_name(),
                    'excepcion',
                    $this->recortar($exception->getMessage()),
                ];
            }

            // 🔴 La pausa va ENTRE clientes, no después del último. Y no corre en las pruebas.
            if ($indice < $ultimo && ! $this->getLaravel()->runningUnitTests()) {
                sleep(self::PAUSA_ENTRE_CLIENTES);
            }
        }

        $this->table(['Cliente', 'Nombre', 'Estado', 'Detalle'], $resultados);

        $this->resumir_estados($resultados);

        return 0;
    }

    /**
     * Los clientes a barrer: uno solo si vino `--client`, o todos los activos.
     *
     * @return \Illuminate\Support\Collection|null Null si el `--client` no existe (ya se avisó).
     */
    private function clientes_a_barrer()
    {
        $client_id = $this->option('client');

        if ($client_id !== null && trim((string) $client_id) !== '') {
            $client = Client::find((int) $client_id);

            if ($client === null) {
                $this->error('No existe el cliente #' . (int) $client_id . '.');

                return null;
            }

            /* Con `--client` se sincroniza igual aunque esté inactivo: quien escribe el ID lo pide
             * explícitamente. */
            if (! (bool) $client->is_active) {
                $this->warn('Ojo: el cliente #' . $client->id . ' está INACTIVO. Se sincroniza igual porque lo pediste por ID.');
            }

            return collect([$client]);
        }

        return Client::where('is_active', true)->orderBy('id')->get();
    }

    /**
     * Cuenta los desenlaces y grita solo lo que hay que mirar.
     *
     * `no_soportado` se cuenta aparte de `failed`: es la versión vieja del cliente, lo ESPERADO
     * durante semanas. Mezclarlo con los fallos reales haría que la salida arranque en rojo todas
     * las noches y que nadie la mire.
     *
     * @param array<int, array<int, mixed>> $resultados Filas de la tabla impresa.
     *
     * @return void
     */
    private function resumir_estados(array $resultados)
    {
        $conteo = [];

        foreach ($resultados as $fila) {
            $estado          = (string) $fila[2];
            $conteo[$estado] = (isset($conteo[$estado]) ? $conteo[$estado] : 0) + 1;
        }

        $no_soportado = isset($conteo['no_soportado']) ? $conteo['no_soportado'] : 0;
        $fallados     = (isset($conteo['failed']) ? $conteo['failed'] : 0)
            + (isset($conteo['excepcion']) ? $conteo['excepcion'] : 0);

        if ($no_soportado > 0) {
            $this->line($no_soportado . ' cliente(s) todavía no tienen la versión con la ruta plan-ia. No es un error.');
        }

        if ($fallados > 0) {
            $this->error($fallados . ' cliente(s) fallaron. El motivo de cada uno quedó en clients.ai_plan_sync_message.');
        }
    }

    /**
     * Recorta un mensaje para que la tabla de la consola siga siendo legible.
     *
     * @param string|null $mensaje Mensaje del desenlace.
     *
     * @return string
     */
    private function recortar($mensaje)
    {
        $texto = trim((string) $mensaje);

        if (mb_strlen($texto) <= 80) {
            return $texto;
        }

        return mb_substr($texto, 0, 77) . '...';
    }
}
