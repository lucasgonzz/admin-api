<?php

namespace App\Http\Controllers;

use App\Jobs\SyncClientSessionLockJob;
use App\Models\Client;
use Illuminate\Http\Request;

/**
 * API JSON (Sanctum) del candado de sesión por pestaña de un cliente, para la pestaña
 * "Candado de sesión" del modal del cliente en admin-spa.
 *
 * Molde: `ClientScheduleController` (GET/PUT client/{clientId}/horarios), simplificado porque acá
 * no hay un conjunto que reemplazar: es un solo booleano, `bloquear_pestanas_duplicadas`.
 *
 * 🔴 El guardado y el push van SEPARADOS a propósito, igual que horarios: `update_json()` guarda el
 * interruptor y recién DESPUÉS encola el push (`->onConnection('database')`), nunca síncrono
 * adentro del request — un empresa-api caído no puede sumarle hasta ~45 segundos de espera al modal
 * del admin por un efecto secundario que a quien está guardando no le importa en ese momento.
 */
class ClientSessionLockController extends Controller
{
    /**
     * Estado actual del interruptor + el estado del último push, para pintar la pestaña.
     *
     * @param  int|string $clientId Id numérico o uuid del cliente.
     * @return \Illuminate\Http\JsonResponse
     */
    public function show_json($clientId)
    {
        $client = $this->find_client_by_route_id($clientId);

        return response()->json($this->armar_payload($client));
    }

    /**
     * Guarda el interruptor y encola el push al empresa-api del cliente.
     *
     * Body: `{ "bloquear_pestanas_duplicadas": true|false }` (booleano, requerido — mismo guard que
     * `ClientScheduleReplacementService`: un body vacío o sin la clave usable no pisa el valor
     * guardado).
     *
     * @param  Request    $request
     * @param  int|string $clientId Id numérico o uuid del cliente.
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_json(Request $request, $clientId)
    {
        // 🔴 Igual que BusinessHoursController del lado de empresa-api: un body vacío o sin la
        // clave usable NO pisa el valor guardado. 422 antes de tocar una sola fila.
        if (! $request->has('bloquear_pestanas_duplicadas') || $request->input('bloquear_pestanas_duplicadas') === null) {
            return response()->json([
                'message' => 'Falta bloquear_pestanas_duplicadas (booleano) en el body.',
                'error'   => 'payload_vacio',
            ], 422);
        }

        $client = $this->find_client_by_route_id($clientId);

        $client->bloquear_pestanas_duplicadas = $request->boolean('bloquear_pestanas_duplicadas');
        $client->save();

        /* El interruptor ya quedó guardado: recién ahora se avisa al empresa-api del cliente, y se
         * hace ENCOLANDO. `->onConnection('database')` explícito, no decorativo: ver el docblock de
         * SyncClientSessionLockJob. Va DESPUÉS del save a propósito: el job lee el cliente de la
         * base cuando corre. */
        SyncClientSessionLockJob::dispatch($client->id)->onConnection('database');

        return response()->json($this->armar_payload($client));
    }

    /**
     * Reintento a mano de la sincronización del candado al empresa-api del cliente, para el botón
     * "Reintentar sincronización" de la pestaña.
     *
     * Es idempotente: reenvía el estado actual del interruptor, no acumula nada del lado del
     * cliente.
     *
     * @param  int|string $clientId Id numérico o uuid del cliente.
     * @return \Illuminate\Http\JsonResponse
     */
    public function sync_json($clientId)
    {
        $client = $this->find_client_by_route_id($clientId);

        // 🔴 Misma conexión explícita que en update_json(): nunca HTTP adentro del request.
        SyncClientSessionLockJob::dispatch($client->id)->onConnection('database');

        return response()->json([
            'encolado'       => true,
            'conexion'       => 'database',
            'client_id'      => (int) $client->id,
            'sincronizacion' => $this->estado_de_sincronizacion($client),
            'nota'           => 'El push corre en el worker `queue:work database` que el scheduler '
                . 'dispara cada minuto. El estado que viaja acá todavía es el del intento anterior: '
                . 'volvé a pedir GET admin/client/{clientId}/candado-sesion para ver el resultado.',
        ], 202);
    }

    /**
     * Payload común de show_json() y de la respuesta del PUT.
     *
     * @param  Client $client Cliente dueño del interruptor.
     * @return array
     */
    private function armar_payload(Client $client)
    {
        return [
            'bloquear_pestanas_duplicadas' => (bool) $client->bloquear_pestanas_duplicadas,
            'sincronizacion'               => $this->estado_de_sincronizacion($client),
        ];
    }

    /**
     * Estado del último push del candado al empresa-api del cliente, para que la pestaña pueda
     * mostrar "Sincronizado el …" o el motivo del fallo sin volver a pegarle a la API del cliente.
     *
     * Las tres columnas en null significan "nunca se intentó", que NO es lo mismo que un fallo.
     *
     * @param  Client $client Cliente dueño del interruptor.
     * @return array
     */
    private function estado_de_sincronizacion(Client $client)
    {
        return [
            'estado'           => $client->pestanas_sync_status === null ? null : (string) $client->pestanas_sync_status,
            'mensaje'          => $client->pestanas_sync_message === null ? null : (string) $client->pestanas_sync_message,
            'sincronizado_at'  => $client->pestanas_synced_at === null ? null : (string) $client->pestanas_synced_at,
        ];
    }

    /**
     * Busca el Client por id numérico o uuid (mismo criterio que ClientScheduleController).
     *
     * @param  int|string $route_id Id numérico o uuid.
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
