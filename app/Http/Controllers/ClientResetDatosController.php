<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Models\Client;
use App\Models\ClientDataReset;
use App\Services\ClientResetDatosService;
use Illuminate\Http\Request;

/**
 * API JSON (Sanctum) del reset de datos de negocio de un cliente, para la solapa "Reiniciar datos" de la ficha del cliente en admin-spa
 * (misión cruzada `reset-datos-cliente`, 21/11/2026, contrato C3 del plan):
 *
 *   - `POST admin/client/{clientId}/reset-datos/simular`   → `{incluir_comprobantes_con_cae?, incluir_integraciones_conectadas?}`
 *   - `POST admin/client/{clientId}/reset-datos/ejecutar`  → `{confirm_client_name, confirmar_base_de_datos, token_de_simulacion,
 *                                                              incluir_comprobantes_con_cae?, incluir_integraciones_conectadas?, motivo?}`
 *   - `GET  admin/client/{clientId}/reset-datos/registros` → `{client_id, admin: [...], cliente: ...}`
 *
 * Es la misma operación que la puerta de Claude (`ClaudeClientResetDatosController`), con el mismo servicio y las mismas respuestas
 * (C2), pero con `origen = panel`, el nombre del admin autenticado como `solicitante` y su id en `client_data_resets.admin_id`. El modo
 * lo decide la RUTA (simular = `dry_run` true, ejecutar = false): el cuerpo no puede traer `dry_run` ni `solicitante`.
 *
 * Ejecutar exige el nombre del cliente (`confirm_client_name`, mismo freno del trait `RespuestasParaClaude`, que no revela el nombre).
 * Los errores llevan `error` (código estable), `mensaje` y `message` (el interceptor del SPA lee ése); el nombre que no confirma es 422
 * `validacion` con `detalle.confirm_client_name`.
 *
 * Molde: `ClientSessionLockController`. Lógica de negocio: toda en `ClientResetDatosService`.
 */
class ClientResetDatosController extends Controller
{
    use RespuestasParaClaude;

    /**
     * Claves que acepta `simular` (lista cerrada).
     *
     * @var array<int, string>
     */
    const PARAMETROS_DE_SIMULAR = ['incluir_comprobantes_con_cae', 'incluir_integraciones_conectadas'];

    /**
     * Claves que acepta `ejecutar` (lista cerrada).
     *
     * @var array<int, string>
     */
    const PARAMETROS_DE_EJECUTAR = [
        'confirm_client_name', 'confirmar_base_de_datos', 'token_de_simulacion',
        'incluir_comprobantes_con_cae', 'incluir_integraciones_conectadas', 'motivo',
    ];

    /**
     * Simula el reset (no borra nada) y devuelve conteos, bloqueos y el token para ejecutar.
     *
     * @param Request                 $request  Request entrante.
     * @param int|string              $clientId Id numérico o uuid del cliente.
     * @param ClientResetDatosService $servicio Inyectado por el contenedor.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function simular_json(Request $request, $clientId, ClientResetDatosService $servicio)
    {
        $client  = $this->find_client_by_route_id($clientId);
        $lectura = $servicio->leer_pedido($request->all(), self::PARAMETROS_DE_SIMULAR, true);

        if (count($lectura['errores']) > 0) {
            return $this->responder_validacion($client, $lectura['errores']);
        }

        return $this->pedir($request, $client, $lectura['pedido'], $servicio);
    }

    /**
     * Ejecuta el reset con el nombre del cliente confirmado, la base y el token de la simulación.
     *
     * @param Request                 $request  Request entrante.
     * @param int|string              $clientId Id numérico o uuid del cliente.
     * @param ClientResetDatosService $servicio Inyectado por el contenedor.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ejecutar_json(Request $request, $clientId, ClientResetDatosService $servicio)
    {
        $client  = $this->find_client_by_route_id($clientId);
        $lectura = $servicio->leer_pedido($request->all(), self::PARAMETROS_DE_EJECUTAR, false);

        if (count($lectura['errores']) > 0) {
            return $this->responder_validacion($client, $lectura['errores']);
        }

        // Freno: el nombre. Mismo trait que la puerta de Claude; se contesta con la forma `validacion` + `detalle`.
        $rechazo = $this->rechazar_si_el_nombre_del_cliente_no_confirma($request, $client, 'No se borró nada.');

        if ($rechazo !== null) {
            $datos = $rechazo->getData(true);

            return $this->responder_validacion($client, ['confirm_client_name' => trim((string) $datos['error'])]);
        }

        return $this->pedir($request, $client, $lectura['pedido'], $servicio);
    }

    /**
     * Lista los últimos registros del reset (los del admin y lo que cuenta el cliente).
     *
     * @param int|string              $clientId Id numérico o uuid del cliente.
     * @param ClientResetDatosService $servicio Inyectado por el contenedor.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function registros_json($clientId, ClientResetDatosService $servicio)
    {
        $client = $this->find_client_by_route_id($clientId);

        return response()->json($servicio->registros($client), 200)->header('Cache-Control', 'no-store, private');
    }

    /**
     * Completa el pedido con lo del panel (origen, admin y solicitante) y se lo pasa al servicio.
     *
     * @param Request                 $request  Request entrante (de ahí sale el admin autenticado).
     * @param Client                  $client   Cliente.
     * @param array<string, mixed>    $pedido   Pedido ya leído y validado.
     * @param ClientResetDatosService $servicio Servicio del reset.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function pedir(Request $request, Client $client, array $pedido, ClientResetDatosService $servicio)
    {
        $admin = $request->user();

        // El solicitante es el nombre del admin autenticado, saneado como cualquier texto que va a un registro y a un log.
        $errores_del_nombre = [];
        $nombre             = $admin !== null
            ? $servicio->sanear_texto_de_auditoria((string) $admin->name, ClientResetDatosService::MAX_SOLICITANTE, 'solicitante', $errores_del_nombre)
            : null;

        $pedido['origen']      = ClientDataReset::ORIGEN_PANEL;
        $pedido['admin_id']    = $admin !== null ? (int) $admin->id : null;
        $pedido['solicitante'] = $nombre === null ? ClientDataReset::ORIGEN_PANEL : $nombre;

        $resultado = $servicio->pedir($client, $pedido);

        // 🔴 Puede llevar el token de la simulación: que nada lo guarde en una caché intermedia.
        return response()->json($resultado['cuerpo'], $resultado['http_status'])->header('Cache-Control', 'no-store, private');
    }

    /**
     * 422 `validacion` con la forma del contrato (`error`, `mensaje`, `message`, `detalle`, `client_id`).
     *
     * @param Client                $client  Cliente.
     * @param array<string, string> $detalle `campo => motivo`.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function responder_validacion(Client $client, array $detalle)
    {
        $motivos = [];

        foreach ($detalle as $campo => $motivo) {
            $motivos[] = $campo . ': ' . $motivo;
        }

        $mensaje = 'El pedido no es válido. ' . implode(' ', $motivos) . ' No se hizo nada.';

        return response()->json([
            'ok'        => false,
            'error'     => 'validacion',
            'mensaje'   => $mensaje,
            'message'   => $mensaje,
            'detalle'   => $detalle,
            'client_id' => (int) $client->id,
        ], 422);
    }

    /**
     * Busca el cliente por id numérico o uuid (404 si no existe).
     *
     * @param int|string $route_id Segmento de la ruta.
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
