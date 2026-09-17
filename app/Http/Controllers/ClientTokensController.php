<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientAiTokenUsage;
use App\Models\ClientAiTokenUsagePerson;
use App\Services\ClientAiTokensSyncService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * API JSON (Sanctum) del consumo de IA de un cliente, para la pestaña "Tokens" del modal del
 * cliente en admin-spa.
 *
 * Molde: `ClientScheduleController` (payload plano armado a medida, `find_client_by_route_id`,
 * bloque `sincronizacion` en la respuesta).
 *
 * Dos cosas que este controlador NO hace, a propósito:
 *
 *  1. **No le pega al `empresa-api` del cliente para leer.** Lee de `client_ai_token_usages`, que
 *     llena la recolección nocturna. Abrir una pestaña no puede depender de que la instancia de un
 *     cliente esté arriba, ni tardar quince segundos por un timeout.
 *  2. **No guarda el costo.** Lo calcula al leer, con `config('ia_precios')`. Ver el docblock de
 *     ese archivo para el porqué.
 *
 * El único camino que sí sale a la red es `sync_json()`, que es el botón "Traer ahora" y corre
 * SINCRÓNICO —como `traer_del_cliente` de mensualidad— porque quien lo aprieta está mirando la
 * pantalla y espera ver el resultado, no un "encolado".
 */
class ClientTokensController extends Controller
{
    /**
     * Días del rango por defecto cuando el pedido no trae fechas.
     */
    const DIAS_POR_DEFECTO = 30;

    /**
     * Techo del rango que se le puede pedir al `empresa-api` en una sola llamada.
     *
     * 🔴 Se lee del service y NO se redeclara acá: es el mismo número que tiene que respetar el
     * comando de recolección, y dos copias del mismo techo son dos números que se desincronizan.
     * Cuando el rango que el operador está mirando es más largo, el refresco cubre los últimos 62
     * días y la respuesta lo DICE en `nota` — recortar en silencio sería mentirle a quien apretó el
     * botón.
     */
    const MAX_DIAS_POR_PEDIDO = ClientAiTokensSyncService::MAX_DIAS_POR_PEDIDO;

    /**
     * 🔴 Techo del rango que se puede LEER de una sola vez, en días.
     *
     * Un año largo: cubre cualquier consulta razonable (la pantalla ofrece 7, 30 y 90 días) y corta
     * el `desde=1900-01-01` que agruparía la tabla entera. Es distinto del techo de lo que se le
     * puede PEDIR al cliente, que lo fija el endpoint del otro lado.
     */
    const MAX_DIAS_DE_LECTURA = 366;

    /**
     * Consumo de IA del cliente en el rango pedido, ya agregado y costeado.
     *
     * @param Request    $request  Pedido con `desde` y `hasta` opcionales.
     * @param int|string $clientId Id numérico o uuid del cliente.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function show_json(Request $request, $clientId)
    {
        $client = $this->find_client_by_route_id($clientId);
        $rango  = $this->resolver_rango($request);

        return response()->json($this->armar_payload($client, $rango['desde'], $rango['hasta']));
    }

    /**
     * Botón "Traer ahora": le pide el consumo al `empresa-api` del cliente y devuelve el payload ya
     * releído de la base.
     *
     * Es idempotente por construcción (el upsert de `ClientAiTokensSyncService`): apretarlo dos
     * veces seguidas deja exactamente el mismo resultado.
     *
     * @param Request                   $request  Pedido con `desde` y `hasta` opcionales.
     * @param int|string                $clientId Id numérico o uuid del cliente.
     * @param ClientAiTokensSyncService $sync     Inyectado por el IoC de Laravel.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function sync_json(Request $request, $clientId, ClientAiTokensSyncService $sync)
    {
        $client = $this->find_client_by_route_id($clientId);
        $rango  = $this->resolver_rango($request);

        $desde_pedido = $rango['desde'];
        $desde_real   = $this->recortar_al_techo($desde_pedido, $rango['hasta']);

        $resultado = $sync->traer_del_cliente($client, $desde_real, $rango['hasta']);

        // El cliente quedó con las columnas `ai_tokens_sync_*` nuevas: hay que releerlo para que el
        // payload muestre el estado de ESTE intento y no el que estaba en memoria.
        $client->refresh();

        $payload = $this->armar_payload($client, $desde_pedido, $rango['hasta']);

        $payload['refresco'] = [
            'estado'  => $resultado['estado'],
            'mensaje' => $resultado['mensaje'],
            'filas'   => $resultado['filas'],
            'desde'   => $desde_real,
            'hasta'   => $rango['hasta'],
        ];

        if ($desde_real !== $desde_pedido) {
            $payload['refresco']['nota'] = 'El sistema del cliente acepta hasta '
                . self::MAX_DIAS_POR_PEDIDO . ' días por consulta, así que el refresco cubrió desde '
                . $desde_real . '. Lo anterior a esa fecha sigue siendo lo que trajo la recolección '
                . 'de cada noche.';
        }

        return response()->json($payload);
    }

    /**
     * Payload común del GET y del POST.
     *
     * @param Client $client Cliente dueño del consumo.
     * @param string $desde  Primer día del rango, AAAA-MM-DD.
     * @param string $hasta  Último día del rango, AAAA-MM-DD.
     *
     * @return array<string, mixed>
     */
    private function armar_payload(Client $client, $desde, $hasta)
    {
        $por_dia     = ClientAiTokenUsage::resumir(['fecha'], $desde, $hasta, (int) $client->id);
        $por_proceso = ClientAiTokenUsage::resumir(['proceso'], $desde, $hasta, (int) $client->id);
        $por_modelo  = ClientAiTokenUsage::resumir(['modelo', 'proveedor'], $desde, $hasta, (int) $client->id);

        /* El total sale de `por_dia` y no de una cuarta consulta: los tres cortes suman lo mismo,
         * así que pedirlo de nuevo sería trabajo repetido con una chance de dar distinto. */
        $totales = ClientAiTokenUsage::totalizar($por_dia);

        // El costo más grande primero: es el orden en el que se mira una tabla de gastos.
        $por_proceso = $this->ordenar_por_costo($por_proceso);
        $por_modelo  = $this->ordenar_por_costo($por_modelo);

        // Cada modelo se marca con si tiene precio, para que el front no tenga que deducirlo.
        foreach ($por_modelo as $indice => $grupo) {
            $por_modelo[$indice]['tiene_precio'] = ClientAiTokenUsage::tiene_precio($grupo['modelo']);
        }

        return [
            'client_id'      => (int) $client->id,
            'desde'          => $desde,
            'hasta'          => $hasta,
            'totales'        => $totales,
            'por_dia'        => $por_dia,
            'por_proceso'    => $por_proceso,
            'por_modelo'     => $por_modelo,
            /* 🔴 Sin costo, y no es un olvido: el precio depende del modelo y este corte no lo
             * trae. Va en tokens y llamadas, y la interfaz lo aclara para que nadie los lea como
             * plata. Repartir el costo total proporcionalmente sería inventar un número. */
            'por_persona'    => ClientAiTokenUsagePerson::resumir((int) $client->id, $desde, $hasta),
            'sincronizacion' => $this->estado_de_sincronizacion($client),
        ];
    }

    /**
     * Estado de la última recolección del consumo de este cliente.
     *
     * Las tres columnas en null significan "nunca se intentó", que NO es lo mismo que un fallo ni
     * que "no gastó nada".
     *
     * @param Client $client Cliente.
     *
     * @return array<string, string|null>
     */
    private function estado_de_sincronizacion(Client $client)
    {
        return [
            'estado'          => $client->ai_tokens_sync_status === null ? null : (string) $client->ai_tokens_sync_status,
            'mensaje'         => $client->ai_tokens_sync_message === null ? null : (string) $client->ai_tokens_sync_message,
            'sincronizado_at' => $client->ai_tokens_synced_at === null ? null : (string) $client->ai_tokens_synced_at,
        ];
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
     * Corre el `desde` hacia adelante si el rango supera lo que el cliente acepta por consulta.
     *
     * @param string $desde Primer día pedido.
     * @param string $hasta Último día pedido.
     *
     * @return string El `desde` efectivo.
     */
    private function recortar_al_techo($desde, $hasta)
    {
        $primero = Carbon::parse($desde)->startOfDay();
        $ultimo  = Carbon::parse($hasta)->startOfDay();

        if ($primero->diffInDays($ultimo) + 1 <= self::MAX_DIAS_POR_PEDIDO) {
            return $desde;
        }

        return $ultimo->copy()->subDays(self::MAX_DIAS_POR_PEDIDO - 1)->format('Y-m-d');
    }

    /**
     * Ordena grupos por costo descendente, y a igual costo por tokens: un modelo sin precio no
     * tiene por qué caer al fondo de la tabla solo por no tener plata calculada.
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

    /**
     * Busca el Client por id numérico o uuid (mismo criterio que `ClientScheduleController`).
     *
     * @param int|string $route_id Id numérico o uuid.
     *
     * @return Client
     */
    private function find_client_by_route_id($route_id)
    {
        if (is_numeric($route_id)) {
            return Client::findOrFail((int) $route_id);
        }

        return Client::where('uuid', (string) $route_id)->firstOrFail();
    }
}
