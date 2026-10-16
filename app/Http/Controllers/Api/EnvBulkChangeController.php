<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EnvChangeItem;
use App\Models\EnvTemplate;
use App\Services\EnvBulkChangeService;
use App\Services\ProbadorDeClaves;
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
     * Los valores se pueden dar de dos maneras, combinables mientras no repitan variable:
     *  - `vars`: KEY => valor en el body, como siempre.
     *  - `plantilla.keys`: nombres de variables cuyo valor se toma de la plantilla .env del admin
     *    (scope 'empresa', el único que este endpoint toca). Es el camino para las claves guardadas
     *    cifradas: quien opera el endpoint nunca tiene que conocer ni dictar el secreto.
     *
     * Para las variables que vienen de la plantilla y tienen prueba disponible (hoy ANTHROPIC_API_KEY)
     * se prueba la clave contra su proveedor ANTES de crear el lote: si el proveedor la rechaza, 422 y
     * no se crea nada; si la prueba no es concluyente (red, timeout, 5xx) sigue y se informa. Se saltea
     * con `omitir_verificacion: true`.
     *
     * @param  Request  $request  { alcance: 'todos'|'seleccion', clients?: int[], vars?: {KEY: valor}, plantilla?: {keys: string[]}, omitir_verificacion?: bool }
     * @param  EnvBulkChangeService  $service
     * @param  ProbadorDeClaves  $probador
     * @return JsonResponse  { token, expira_en, clientes: [...], verificaciones: {KEY: {estado, mensaje}} }
     */
    public function preview(Request $request, EnvBulkChangeService $service, ProbadorDeClaves $probador): JsonResponse
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
         *
         * `vars` es obligatorio salvo que se pida alguna variable de la plantilla.
         */
        $request->validate([
            'alcance'             => 'required|in:todos,seleccion',
            'clients'             => 'required_if:alcance,seleccion|array|min:1',
            'clients.*'           => 'integer',
            'vars'                => 'required_without:plantilla|array|min:1',
            'vars.*'              => 'required|string',
            'plantilla'           => 'nullable|array',
            'plantilla.keys'      => 'required_with:plantilla|array|min:1',
            'plantilla.keys.*'    => 'required|string',
            'omitir_verificacion' => 'nullable|boolean',
        ]);

        $vars = $request->input('vars');

        if (! is_array($vars)) {
            $vars = [];
        }

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

        /* Resultado de las pruebas de clave; se informa en la respuesta. */
        $verificaciones = [];

        /* Variables pedidas a la plantilla: se resuelven en el servidor, el valor nunca viaja en el body. */
        $desde_plantilla = [];

        if ($request->has('plantilla')) {
            $resuelto = $this->resolver_desde_plantilla((array) $request->input('plantilla.keys'), $vars);

            if (isset($resuelto['error'])) {
                return response()->json(['message' => $resuelto['error']], 422);
            }

            $desde_plantilla = $resuelto['valores'];

            /* Prueba las claves contra su proveedor antes de crear nada. */
            if (! (bool) $request->input('omitir_verificacion', false)) {
                foreach ($desde_plantilla as $key => $valor) {
                    if (! $probador->es_soportada($key)) {
                        continue;
                    }

                    $prueba = $probador->probar($key, $valor);

                    /* El proveedor la rechazó: aplicarla rompería la IA de los clientes. No se crea ningún lote. */
                    if ($prueba['estado'] === ProbadorDeClaves::RECHAZADA) {
                        return response()->json([
                            'message'        => "La clave {$key} guardada en la plantilla fue rechazada por su proveedor. "
                                . 'No se creó ningún lote: cargá una clave válida en la plantilla. Detalle: ' . $prueba['mensaje'],
                            'verificaciones' => [$key => ['estado' => $prueba['estado'], 'mensaje' => $prueba['mensaje']]],
                        ], 422);
                    }

                    $verificaciones[$key] = ['estado' => $prueba['estado'], 'mensaje' => $prueba['mensaje']];
                }
            }
        }

        /* Los valores del body y los de la plantilla se juntan en el mapa que consume el servicio. */
        $todas = $vars;
        foreach ($desde_plantilla as $key => $valor) {
            $todas[$key] = $valor;
        }

        $client_ids = $request->input('alcance') === 'todos' ? null : $request->input('clients');

        try {
            $preview = $service->previsualizar($todas, $client_ids, optional($request->user())->id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        /* Sin pruebas se responde un objeto vacío ({}), no una lista ([]). */
        $preview['verificaciones'] = count($verificaciones) > 0 ? $verificaciones : new \stdClass();

        return response()->json($preview);
    }

    /**
     * Resuelve los valores de las variables pedidas a la plantilla .env (scope 'empresa').
     *
     * Los mensajes de error nombran la variable, nunca su valor.
     *
     * @param  array  $keys  Nombres pedidos.
     * @param  array  $vars  Variables que ya vienen en el body (no pueden repetirse).
     * @return array  { valores: {KEY: valor} } o { error: string }
     */
    private function resolver_desde_plantilla(array $keys, array $vars): array
    {
        $valores = [];

        foreach ($keys as $key) {
            $key = (string) $key;

            /* Mismo criterio de nombres que `vars`: llegan a un comando remoto. */
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                return ['error' => "El nombre de variable '{$key}' no es válido. Se esperan nombres tipo ANTHROPIC_API_KEY."];
            }

            $plantilla = EnvTemplate::where('scope', 'empresa')->where('key', $key)->first();

            if (! $plantilla) {
                return ['error' => "La variable '{$key}' no existe en la plantilla .env."];
            }

            /*
             * Ilegible (APP_KEY cambiada, payload dañado) no es lo mismo que vacío: el accessor devuelve null
             * en los dos casos, y aplicarla escribiría la variable vacía en el .env de los clientes.
             */
            if ($plantilla->esta_ilegible()) {
                return ['error' => "El valor guardado de '{$key}' no se puede leer; volvé a cargarlo en la plantilla. No se creó ningún lote."];
            }

            /* El accessor del modelo devuelve el valor descifrado. */
            $valor = $plantilla->value;

            if ($valor === null || trim((string) $valor) === '') {
                return ['error' => "La variable '{$key}' no tiene valor cargado en la plantilla .env."];
            }

            if (strpos((string) $valor, "\n") !== false || strpos((string) $valor, "\r") !== false) {
                return ['error' => "El valor de '{$key}' en la plantilla tiene un salto de línea y una variable de .env es de una sola línea."];
            }

            /* Se usa el nombre canónico de la plantilla. */
            $nombre = (string) $plantilla->key;

            if (array_key_exists($nombre, $vars) || array_key_exists($nombre, $valores)) {
                return ['error' => "La variable '{$nombre}' viene repetida (en vars y en plantilla, o dos veces en plantilla)."];
            }

            $valores[$nombre] = (string) $valor;
        }

        return ['valores' => $valores];
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
