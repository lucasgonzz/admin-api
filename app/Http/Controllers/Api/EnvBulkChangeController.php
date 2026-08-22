<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
     * @param  EnvBulkChangeService  $service
     * @return JsonResponse  { clientes: [...] }
     */
    public function clients(EnvBulkChangeService $service): JsonResponse
    {
        return response()->json(['clientes' => $service->listar_clientes()]);
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
         */
        $request->validate([
            'alcance'   => 'required|in:todos,seleccion',
            'clients'   => 'required_if:alcance,seleccion|array|min:1',
            'clients.*' => 'integer',
            'vars'      => 'required|array|min:1',
            'vars.*'    => 'nullable|string',
        ]);

        $vars = $request->input('vars');

        /* Valida los nombres de variable antes de que lleguen a un comando remoto. */
        foreach (array_keys($vars) as $key) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $key) !== 1) {
                return response()->json([
                    'message' => "El nombre de variable '{$key}' no es válido. Se esperan nombres tipo ANTHROPIC_API_KEY.",
                ], 422);
            }
        }

        $client_ids = $request->input('alcance') === 'todos' ? null : $request->input('clients');

        $preview = $service->previsualizar($vars, $client_ids, optional($request->user())->id);

        return response()->json($preview);
    }

    /**
     * Aplica un lote previamente previsualizado.
     *
     * @param  Request  $request  { token: string, confirmar: true }
     * @param  EnvBulkChangeService  $service
     * @return JsonResponse  { aplicados, fallidos, clientes: [...] }
     */
    public function apply(Request $request, EnvBulkChangeService $service): JsonResponse
    {
        $request->validate([
            'token'     => 'required|string',
            'confirmar' => 'required|accepted',
        ]);

        try {
            $resultado = $service->aplicar($request->input('token'));
        } catch (\RuntimeException $e) {
            /* Token inexistente, vencido o lote ya aplicado: es un error del pedido, no del servidor. */
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($resultado);
    }
}
