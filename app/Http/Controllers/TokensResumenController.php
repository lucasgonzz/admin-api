<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientAiTokenUsage;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * API JSON (Sanctum) del consumo de IA de TODOS los clientes juntos, para la pantalla "Tokens" de
 * admin-spa.
 *
 * Es la otra mitad de lo que pidió Lucas: `ClientTokensController` contesta "cuánto gastó este
 * cliente" y esto contesta "cuánto estamos gastando en total, y quién se lo lleva". El ranking es
 * el dato operativo: con cuarenta y cinco clientes, el gasto no se reparte parejo y saber cuál
 * empujó el número es lo que permite ir a mirar SU pestaña.
 *
 * Lee siempre de `client_ai_token_usages` (nunca sale a la red) y calcula el costo al leer, con
 * `config('ia_precios')`. Mismo criterio que la pestaña del cliente.
 */
class TokensResumenController extends Controller
{
    /**
     * Días del rango por defecto cuando el pedido no trae fechas.
     */
    const DIAS_POR_DEFECTO = 30;

    /**
     * Total del período, serie por día, ranking de clientes y desglose por acción.
     *
     * @param Request $request Pedido con `desde` y `hasta` opcionales.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json(Request $request)
    {
        $rango = $this->resolver_rango($request);
        $desde = $rango['desde'];
        $hasta = $rango['hasta'];

        $por_dia     = ClientAiTokenUsage::resumir(['fecha'], $desde, $hasta);
        $por_proceso = ClientAiTokenUsage::resumir(['proceso'], $desde, $hasta);
        $por_cliente = ClientAiTokenUsage::resumir(['client_id'], $desde, $hasta);

        /* El total sale de la serie por día y no de una cuarta consulta: los tres cortes suman lo
         * mismo, así que pedirlo de nuevo sería trabajo repetido con una chance de dar distinto. */
        $totales = ClientAiTokenUsage::totalizar($por_dia);

        $por_proceso = $this->ordenar_por_costo($por_proceso);
        $por_cliente = $this->ordenar_por_costo($this->ponerle_nombre_a_los_clientes($por_cliente));

        return response()->json([
            'desde'       => $desde,
            'hasta'       => $hasta,
            'totales'     => $totales,
            'por_dia'     => $por_dia,
            'por_cliente' => $por_cliente,
            'por_proceso' => $por_proceso,
        ]);
    }

    /**
     * Le agrega a cada fila del ranking el nombre del cliente y su uuid.
     *
     * 🔴 Con UNA sola consulta para todos los ids, no una por fila. El ranking tiene tantas filas
     * como clientes con consumo, y eso son cuarenta y cinco consultas evitables por cada vez que
     * alguien abre la pantalla.
     *
     * Un `client_id` que ya no exista en `clients` (cliente borrado) queda igual en el ranking, con
     * el nombre en null: el gasto ocurrió y esconderlo cambiaría el total. La tabla no tiene FK
     * física justamente para que ese caso sea posible.
     *
     * @param array<int, array<string, mixed>> $grupos Grupos agrupados por `client_id`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ponerle_nombre_a_los_clientes(array $grupos)
    {
        $ids = [];

        foreach ($grupos as $grupo) {
            $ids[] = (int) $grupo['client_id'];
        }

        if ($ids === []) {
            return $grupos;
        }

        /** @var array<int, Client> Clientes del ranking, indexados por id. */
        $clientes = Client::whereIn('id', $ids)->get(['id', 'uuid', 'name', 'company_name'])->keyBy('id');

        foreach ($grupos as $indice => $grupo) {
            $client = $clientes->get((int) $grupo['client_id']);

            $grupos[$indice]['client_id']    = (int) $grupo['client_id'];
            $grupos[$indice]['client_uuid']  = $client === null ? null : (string) $client->uuid;
            $grupos[$indice]['cliente']      = $client === null ? null : $client->resolve_display_name();
        }

        return $grupos;
    }

    /**
     * Resuelve el rango del pedido, con los últimos 30 días como valor por defecto.
     *
     * @param Request $request Pedido.
     *
     * @return array{desde: string, hasta: string}
     */
    private function resolver_rango(Request $request)
    {
        $datos = $request->validate([
            'desde' => 'nullable|date_format:Y-m-d',
            'hasta' => 'nullable|date_format:Y-m-d|after_or_equal:desde',
        ]);

        $hasta = isset($datos['hasta']) && $datos['hasta'] !== null
            ? (string) $datos['hasta']
            : Carbon::now(config('app.timezone'))->format('Y-m-d');

        $desde = isset($datos['desde']) && $datos['desde'] !== null
            ? (string) $datos['desde']
            : Carbon::parse($hasta)->subDays(self::DIAS_POR_DEFECTO - 1)->format('Y-m-d');

        return ['desde' => $desde, 'hasta' => $hasta];
    }

    /**
     * Ordena grupos por costo descendente, y a igual costo por tokens.
     *
     * @param array<int, array<string, mixed>> $grupos Grupos de `resumir()`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ordenar_por_costo(array $grupos)
    {
        usort($grupos, function ($a, $b) {
            if ((float) $a['costo_usd'] === (float) $b['costo_usd']) {
                return (int) $b['tokens'] - (int) $a['tokens'];
            }

            return (float) $b['costo_usd'] > (float) $a['costo_usd'] ? 1 : -1;
        });

        return $grupos;
    }
}
