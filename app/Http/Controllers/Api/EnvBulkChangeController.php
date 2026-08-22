<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EnvChangeItem;
use App\Services\EnvBulkChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cambio masivo de variables .env sobre los clientes, en dos tiempos.
 *
 * Estos endpoints son los que va a consumir el conector MCP para operar por voz. Por eso el alcance
 * es explícito y la escritura exige un token de previsualización — ver EnvBulkChangeService.
 */
class EnvBulkChangeController extends Controller
{
    /**
     * Lista los clientes operables, con subdominios, versión y tipo de hosting.
     *
     * @param  Request  $request  { incluir_inactivos?: bool }
     * @param  EnvBulkChangeService  $service
     * @return JsonResponse  { clientes: [...] }
     */
    public function clients(Request $request, EnvBulkChangeService $service): JsonResponse
    {
        $incluir_inactivos = (bool) $request->input('incluir_inactivos', false);

        return response()->json(['clientes' => $service->listar_clientes($incluir_inactivos)]);
    }

    /**
     * Previsualiza el cambio: devuelve el diff por cliente y un token. NO escribe nada.
     *
     * @param  Request  $request  { alcance: 'todos'|'seleccion', clients?: int[], vars: {KEY: valor} }
     * @param  EnvBulkChangeService  $service
     * @return JsonResponse  { token, expira_en, clientes: [...] }
     */
    public function preview(Request $request, EnvBulkChangeService $service): JsonResponse
    {
        /*
         * El alcance es un campo obligatorio y explícito, no se deduce de que `clients` venga
         * vacío. Es la diferencia entre tocarle el .env a un cliente y tocárselo a los 40, y el
         * que va a llenar este payload es un modelo transcribiendo lo que Lucas dijo en voz alta:
         * una lista que llega vacía por un bug no puede significar "todos".
         *
         * Por la misma razón `vars.*` es `string` y no `nullable`: un campo que se pierde en el
         * camino llega como null, y un null acá blanquearía esa variable en todos los clientes.
         * Vaciar una variable a propósito es una operación distinta y todavía no existe.
         */
        $request->validate([
            'alcance'   => 'required|in:todos,seleccion',
            'clients'   => 'required_if:alcance,seleccion|array|min:1',
            'clients.*' => 'integer',
            'vars'      => 'required|array|min:1',
            'vars.*'    => 'required|string',
        ]);

        $vars = $request->input('vars');

        foreach ($vars as $key => $valor) {
            /* Valida los nombres de variable antes de que lleguen a un comando remoto. */
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $key) !== 1) {
                return response()->json([
                    'message' => "El nombre de variable '{$key}' no es válido. Se esperan nombres tipo ANTHROPIC_API_KEY.",
                ], 422);
            }

            /*
             * Una variable de .env es de una sola línea. Un salto de línea parte el comando de sed
             * en dos y lo hace abortar — y es un input plausible viniendo de una transcripción de
             * voz o de una API key copiada con el newline del final.
             */
            if (strpos((string) $valor, "\n") !== false || strpos((string) $valor, "\r") !== false) {
                return response()->json([
                    'message' => "El valor de '{$key}' tiene un salto de línea y una variable de .env es de una sola línea.",
                ], 422);
            }
        }

        $client_ids = $request->input('alcance') === 'todos' ? null : $request->input('clients');

        try {
            $preview = $service->previsualizar($vars, $client_ids, optional($request->user())->id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($preview);
    }

    /**
     * Aplica un lote previamente previsualizado.
     *
     * @param  Request  $request  { token: string, confirmar: true, reanudar?: bool }
     * @param  EnvBulkChangeService  $service
     * @return JsonResponse  { aplicados, omitidos, fallidos, sin_cambios, pendientes, clientes }
     */
    public function apply(Request $request, EnvBulkChangeService $service): JsonResponse
    {
        $request->validate([
            'token'     => 'required|string',
            'confirmar' => 'required|accepted',
            'reanudar'  => 'nullable|boolean',
        ]);

        try {
            $resultado = $service->aplicar(
                $request->input('token'),
                (bool) $request->input('reanudar', false)
            );
        } catch (\RuntimeException $e) {
            /* Token inexistente, vencido, lote ya aplicado o corrida en curso: error del pedido. */
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($resultado);
    }

    /**
     * Historial de cambios de .env, para poder contestar "qué le cambiaste a este cliente".
     *
     * Sin esto la auditoría existía sólo como filas en una tabla que nadie podía leer sin entrar a
     * MySQL a mano, que es lo mismo que no tenerla.
     *
     * @param  Request  $request  { client_id?: int, env_key?: string, limit?: int }
     * @return JsonResponse  { cambios: [...] }
     */
    public function history(Request $request): JsonResponse
    {
        $request->validate([
            'client_id' => 'nullable|integer',
            'env_key'   => 'nullable|string',
            'limit'     => 'nullable|integer|min:1|max:500',
        ]);

        $query = EnvChangeItem::with('client', 'client_api')->orderByDesc('id');

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->input('client_id'));
        }

        if ($request->filled('env_key')) {
            $query->where('env_key', $request->input('env_key'));
        }

        $items = $query->limit((int) $request->input('limit', 100))->get();

        $cambios = [];

        foreach ($items as $item) {
            $cambios[] = [
                'client_id'    => $item->client_id,
                'nombre'       => $item->client ? $item->client->resolve_display_name() : null,
                'api_url'      => $item->client_api ? $item->client_api->url : null,
                'key'          => $item->env_key,
                'valor_previo' => $item->old_value_masked,
                'valor_nuevo'  => $item->new_value_masked,
                'status'       => $item->status,
                'error'        => $item->error,
                'backup_path'  => $item->backup_path,
                'fecha'        => $item->created_at ? $item->created_at->toIso8601String() : null,
            ];
        }

        return response()->json(['cambios' => $cambios]);
    }
}
