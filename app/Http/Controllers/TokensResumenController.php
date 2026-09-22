<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientAiTokenUsage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
     * 🔴 Techo del rango que se puede LEER de una sola vez, en días.
     *
     * Un año largo: cubre cualquier consulta razonable (la pantalla ofrece 7, 30 y 90 días) y corta
     * el `desde=1900-01-01` que agruparía la tabla entera. Es distinto del techo de lo que se le
     * puede PEDIR al cliente, que lo fija el endpoint del otro lado.
     */
    const MAX_DIAS_DE_LECTURA = 366;

    /**
     * Total del período, serie por día, ranking de clientes, desglose por acción, por modelo y por
     * proveedor, y cuántos clientes eligieron cada proveedor.
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

        $por_dia       = ClientAiTokenUsage::resumir(['fecha'], $desde, $hasta);
        $por_proceso   = ClientAiTokenUsage::resumir(['proceso'], $desde, $hasta);
        $por_cliente   = ClientAiTokenUsage::resumir(['client_id'], $desde, $hasta);
        $por_modelo    = ClientAiTokenUsage::resumir(['modelo', 'proveedor'], $desde, $hasta);
        $por_proveedor = ClientAiTokenUsage::resumir(['proveedor'], $desde, $hasta);

        /* El total sale de la serie por día y no de otra consulta: todos los cortes suman lo
         * mismo, así que pedirlo de nuevo sería trabajo repetido con una chance de dar distinto. */
        $totales = ClientAiTokenUsage::totalizar($por_dia);

        $por_proceso   = $this->ordenar_por_costo($por_proceso);
        $por_cliente   = $this->ordenar_por_costo($this->ponerle_nombre_a_los_clientes($por_cliente));
        $por_modelo    = $this->ordenar_por_costo($por_modelo);
        $por_proveedor = $this->ordenar_por_costo($por_proveedor);

        // Cada modelo se marca con si tiene precio, igual que en la pestaña del cliente, para que
        // el front no tenga que deducirlo de un null que también podría ser "costó cero".
        foreach ($por_modelo as $indice => $grupo) {
            $por_modelo[$indice]['tiene_precio'] = ClientAiTokenUsage::tiene_precio($grupo['modelo']);
        }

        /* `por_proveedor` NO lleva `tiene_precio`: un proveedor agrupa varios modelos y puede
         * tener unos con precio y otros sin. Lo que viaja es lo que ya trae `resumir()` para
         * cualquier grupo con varios modelos —`costo_usd` (null solo si NINGUNO tenía precio) y
         * `modelos_sin_precio` (los que quedaron afuera, si hay)—, que es lo mismo que el front ya
         * lee para el total y para el corte por acción: si la lista no está vacía, el costo es un
         * piso, no el total, y se dice. */

        return response()->json([
            'desde'                  => $desde,
            'hasta'                  => $hasta,
            'totales'                => $totales,
            'por_dia'                => $por_dia,
            'por_cliente'            => $por_cliente,
            'por_proceso'            => $por_proceso,
            'por_modelo'             => $por_modelo,
            'por_proveedor'          => $por_proveedor,
            'clientes_por_proveedor' => $this->clientes_por_proveedor(),
        ]);
    }

    /**
     * Cuántos clientes eligieron cada proveedor de IA, según lo que informó cada uno en su última
     * recolección: `[{proveedor: 'anthropic'|'deepseek'|null, clientes: int}]`, con null como
     * "sin informar".
     *
     * 🔴 Cuenta SOLO clientes activos, que son los que barre `tokens:recolectar`. Un cliente
     * inactivo nunca pasa por la recolección, así que nunca pudo informar nada: contarlo como "sin
     * informar" inflaría ese número con clientes dados de baja y taparía el dato real, que es
     * cuántos del parque vivo todavía corren una versión que no informa qué modelo usa.
     *
     * No depende del rango de fechas: es la foto de hoy, no un histórico. Y se agrupa en la base
     * y no en PHP por el mismo motivo que `resumir()`: cuarenta y cinco filas no son nada, pero
     * traérselas enteras para contarlas acá es trabajo por nada.
     *
     * @return array<int, array{proveedor: string|null, clientes: int}>
     */
    private function clientes_por_proveedor()
    {
        $grupos = Client::query()
            ->where('is_active', true)
            ->groupBy('ai_proveedor')
            ->orderBy('ai_proveedor')
            ->get(['ai_proveedor', DB::raw('COUNT(*) as clientes')]);

        $resultado = [];

        foreach ($grupos as $grupo) {
            $resultado[] = [
                'proveedor' => $grupo->ai_proveedor === null ? null : (string) $grupo->ai_proveedor,
                'clientes'  => (int) $grupo->clientes,
            ];
        }

        return $resultado;
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
     * Se valida antes de tocar la base: un `desde` posterior al `hasta` es un 422, no un resultado
     * vacío que parece "este cliente no gastó nada".
     *
     * 🔴 Y hay un TECHO de 366 días, que no es una comodidad: sin él, un
     * `desde=1900-01-01&hasta=2100-01-01` —escrito a mano o pegado de un link viejo— agrupa la tabla
     * entera de todos los clientes en una sola consulta. Nadie mira doscientos años de consumo de
     * IA; la pantalla ofrece 7, 30 y 90 días.
     *
     * El middleware `ConvertEmptyStringsToNull` hace que `?hasta=` llegue como null y caiga en el
     * default de hoy. Por eso los dos cortes de abajo corren DESPUÉS de resolver los defaults y no
     * como reglas de `validate()`: un `hasta` vacío con un `desde` en el futuro pasaría las reglas
     * y se comería el `after_or_equal`.
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

        if ($desde > $hasta) {
            throw ValidationException::withMessages([
                'desde' => 'La fecha "desde" no puede ser posterior a la fecha "hasta".',
            ]);
        }

        $dias = Carbon::parse($desde)->startOfDay()->diffInDays(Carbon::parse($hasta)->startOfDay()) + 1;

        if ($dias > self::MAX_DIAS_DE_LECTURA) {
            throw ValidationException::withMessages([
                'desde' => 'El rango pedido es de ' . $dias . ' días y el máximo es '
                    . self::MAX_DIAS_DE_LECTURA . '. Achicá el período.',
            ]);
        }

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
