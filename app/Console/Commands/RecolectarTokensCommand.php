<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\ClientAiTokensSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Trae de cada cliente cuántos tokens de IA gastó y los deja espejados en el admin.
 *
 * Corre solo todas las noches a las 03:15 (ver `Kernel::schedule()`), y a mano cuando hace falta
 * reintentar uno o traer un rango viejo.
 *
 * 🔴 **Tres decisiones que no son cosméticas:**
 *
 *  1. **En serie, con un segundo de pausa entre clientes.** El shared hosting de Hostinger bloquea
 *     la IP de la CUENTA ENTERA por ráfaga de conexiones (~18/s medidas), y cuarenta y cinco
 *     requests seguidos sin respirar es exactamente el patrón que la dispara. El costo de la pausa
 *     es menos de un minuto en total, a las tres de la mañana; el costo de que Hostinger bloquee la
 *     IP es que se caiga todo lo demás que sale de esta máquina.
 *
 *  2. **`try/catch` por cliente.** Un cliente caído no puede cortar el barrido de los otros
 *     cuarenta y cuatro. El molde es `AsistenteInformesService::enviar_a_todos()`. El service ya se
 *     compromete a no lanzar; este `catch` es el cinturón sobre los tirantes.
 *
 *  3. **Ventana de tres días hacia atrás, no del día de ayer solamente.** Un cliente que estuvo
 *     caído anoche se recupera SOLO en la corrida siguiente, sin que nadie se entere ni tenga que
 *     intervenir. Se puede hacer justamente porque el upsert es idempotente: repetir días no
 *     duplica nada. Sin esto haría falta una cola de reintentos, que es mucho más código para
 *     resolver lo mismo peor.
 */
class RecolectarTokensCommand extends Command
{
    /**
     * Nombre y opciones del comando Artisan.
     *
     * @var string
     */
    protected $signature = 'tokens:recolectar
        {--client= : ID de un solo cliente, para reintentar sin barrer a todos}
        {--dias=3 : Cuántos días hacia atrás traer, contando hoy (techo: 62, el del sistema del cliente)}';

    /**
     * Descripción visible en php artisan list.
     *
     * @var string
     */
    protected $description = 'Trae de cada cliente su consumo de tokens de IA y lo espeja en client_ai_token_usages';

    /**
     * Segundos de espera entre un cliente y el siguiente.
     *
     * @var int
     */
    const PAUSA_ENTRE_CLIENTES = 1;

    /**
     * Ejecuta el barrido.
     *
     * @param ClientAiTokensSyncService $sync Servicio que hace el trabajo.
     *
     * @return int
     */
    public function handle(ClientAiTokensSyncService $sync): int
    {
        $dias = (int) $this->option('dias');

        if ($dias < 1) {
            $this->error('--dias tiene que ser 1 o más.');

            return 1;
        }

        /* 🔴 Se recorta al techo en vez de dejarlo pasar. El `empresa-api` corta en 62 días con un
         * 422, así que un `--dias=90` escrito para un backfill no traería NADA: dejaría a los
         * cuarenta y cinco clientes en `failed` de una sola pasada. Es recuperable —el motivo queda
         * escrito en cada uno— pero es una corrida entera tirada y un susto al mirar la tabla.
         * Para cubrir más que eso se corre el comando varias veces, moviendo la ventana. */
        if ($dias > ClientAiTokensSyncService::MAX_DIAS_POR_PEDIDO) {
            $this->warn(
                'Pediste ' . $dias . ' días y el sistema de los clientes acepta hasta '
                . ClientAiTokensSyncService::MAX_DIAS_POR_PEDIDO . ' por consulta. Se traen los '
                . ClientAiTokensSyncService::MAX_DIAS_POR_PEDIDO . ' más recientes. Para cubrir más, '
                . 'corré el comando de nuevo con otra ventana.'
            );

            $dias = ClientAiTokensSyncService::MAX_DIAS_POR_PEDIDO;
        }

        $hasta = Carbon::now(config('app.timezone'))->format('Y-m-d');
        $desde = Carbon::now(config('app.timezone'))->subDays($dias - 1)->format('Y-m-d');

        $clientes = $this->clientes_a_barrer();

        if ($clientes === null) {
            return 1;
        }

        if ($clientes->isEmpty()) {
            $this->info('No hay clientes activos para recolectar.');

            return 0;
        }

        $this->info('Recolectando tokens del ' . $desde . ' al ' . $hasta . ' para ' . $clientes->count() . ' cliente(s).');

        $resultados = [];
        $ultimo     = $clientes->count() - 1;

        foreach ($clientes->values() as $indice => $client) {
            try {
                $resultado = $sync->traer_del_cliente($client, $desde, $hasta);

                $resultados[] = [
                    (int) $client->id,
                    $client->resolve_display_name(),
                    $resultado['estado'],
                    $resultado['filas'],
                    $this->recortar($resultado['mensaje']),
                ];
            } catch (\Throwable $exception) {
                /* El service se compromete a no lanzar, así que llegar acá significa que algo se
                 * rompió fuera de su contrato. Se anota y se sigue: el barrido de los demás no se
                 * detiene por uno. */
                Log::channel('daily')->error('tokens:recolectar: excepción trayendo el consumo de un cliente.', [
                    'client_id' => $client->id,
                    'error'     => $exception->getMessage(),
                ]);

                $resultados[] = [
                    (int) $client->id,
                    $client->resolve_display_name(),
                    'excepcion',
                    0,
                    $this->recortar($exception->getMessage()),
                ];
            }

            // 🔴 La pausa va ENTRE clientes, no después del último: dormir al final es un segundo
            // regalado. Y no corre en las pruebas, donde solo agregaría tiempo muerto.
            if ($indice < $ultimo && ! $this->getLaravel()->runningUnitTests()) {
                sleep(self::PAUSA_ENTRE_CLIENTES);
            }
        }

        $this->table(['Cliente', 'Nombre', 'Estado', 'Filas', 'Detalle'], $resultados);

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

            /* Con `--client` se trae igual aunque esté inactivo: quien escribe el ID está pidiendo
             * explícitamente ese cliente, y silenciarlo sin decir por qué sería peor. */
            if (! (bool) $client->is_active) {
                $this->warn('Ojo: el cliente #' . $client->id . ' está INACTIVO. Se consulta igual porque lo pediste por ID.');
            }

            return collect([$client]);
        }

        return Client::where('is_active', true)->orderBy('id')->get();
    }

    /**
     * Cuenta los desenlaces y grita solo lo que hay que mirar.
     *
     * `no_soportado` se cuenta aparte de `failed` a propósito: es la versión vieja del cliente, que
     * es lo ESPERADO durante semanas. Mezclarlo con los fallos reales haría que la salida del
     * comando arranque en rojo todas las noches y que nadie la mire más.
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
            $this->line($no_soportado . ' cliente(s) todavía no tienen la versión con el endpoint de consumo. No es un error.');
        }

        if ($fallados > 0) {
            $this->error($fallados . ' cliente(s) fallaron. El motivo de cada uno quedó en clients.ai_tokens_sync_message.');
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
