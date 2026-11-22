<?php

namespace App\Http\Controllers\Api;

use App\Models\ClientDataReset;
use App\Services\ClientResetDatosService;
use Illuminate\Http\Request;

/**
 * La ruta con la que un agente (Claude Code, Codex) simula o ejecuta el RESET DE DATOS DE NEGOCIO de un cliente
 * (misión cruzada `reset-datos-cliente`, 21/11/2026): `POST claude/clients/{id}/reset-datos` y
 * `GET claude/clients/{id}/reset-datos/registros`.
 *
 * 🔴 Extiende `ClaudeClientCatalogoController` a propósito, igual que `ClaudeClientAccesoSoporteController`: es la misma familia (el admin
 * le habla a un cliente con SU clave, sin que esa clave pase por la máquina de quien llama) y hereda SIN copiar los helpers de esa clase
 * (cliente por id o uuid, errores estables, freno de `confirm_client_name`, `responder_*`). Las rutas heredadas NO están registradas con
 * este controlador. Toda la lógica vive en `ClientResetDatosService`; este controlador sólo lee el pedido, frena por el nombre y
 * traduce el resultado a HTTP.
 *
 * 🔴 EL CONTRATO (C2 del plan de la misión). Pedido:
 *
 *     POST {ADMIN_API_URL}/api/claude/clients/{id|uuid}/reset-datos
 *     Headers: X-Claude-Task-Key: <COMERCIOCITY_ADMIN_INGEST_KEY>   Accept: application/json
 *     Body:    { dry_run?: bool (DEFAULT TRUE), confirm_client_name?: string (obligatorio si dry_run=false),
 *                confirmar_base_de_datos?: string, token_de_simulacion?: string (los dos obligatorios si dry_run=false),
 *                incluir_comprobantes_con_cae?: bool, incluir_integraciones_conectadas?: bool,
 *                solicitante?: string ≤ 60 (default "claude"), motivo?: string ≤ 120 }     lista cerrada: una clave de más = 422
 *
 * Respuestas:
 *
 *   - 200: el cuerpo que contestó el `empresa-api` (simulación: conteos por tabla, bloqueos, `ejecutable` y `token_de_simulacion`;
 *     ejecución: tablas vaciadas, respaldo, verificación) más `client_id` y `frente: {client_api_id, hosting_type, api_host}`.
 *     La respuesta lleva `Cache-Control: no-store` (la simulación trae el token que habilita a ejecutar).
 *   - 404 `cliente_inexistente` · 422 `validacion` (con `detalle` por campo; el nombre que no confirma es `detalle.confirm_client_name`).
 *   - 409 `sin_frentes` · `sin_api_key` · `sin_url` · `clave_rechazada` · `version_vieja`.
 *   - Los 409/422/500 de empresa (`en_curso`, `varios_duenos`, `confirmacion_invalida`, `tablas_sin_clasificar`, `comprobantes_con_cae`,
 *     `integraciones_conectadas`, `sin_espacio_para_respaldo`, `respaldo_fallido`, `reset_incompleto`...) pasan con su `error` y su status.
 *   - 502 `cliente_no_responde` · `respuesta_invalida`.
 *
 * `GET …/registros` contesta `{client_id, admin: [últimos 10 de client_data_resets], cliente: <lo que respondió empresa, o null>}`.
 *
 * 🔴 ORDEN de las comprobaciones: cliente → validación del pedido → freno del nombre (sólo con `dry_run = false`) → servicio. Un pedido
 * inválido o un nombre equivocado NO le pegan al cliente ni dejan registro.
 *
 * 🔴 NINGUNA RESPUESTA, LOG NI REGISTRO LLEVA `clients.api_key`. El token de simulación sólo viaja en la respuesta de la simulación.
 *
 * Protegida por el middleware `claude.task.key`, como todo el bloque.
 */
class ClaudeClientResetDatosController extends ClaudeClientCatalogoController
{
    /**
     * Parámetros del pedido (lista cerrada).
     *
     * @var array<int, string>
     */
    const PARAMETROS_DEL_RESET = [
        'dry_run', 'confirm_client_name', 'confirmar_base_de_datos', 'token_de_simulacion',
        'incluir_comprobantes_con_cae', 'incluir_integraciones_conectadas', 'solicitante', 'motivo',
    ];

    /**
     * Simula o ejecuta el reset de datos de negocio de UN cliente.
     *
     * Ver el docblock de la clase: es el contrato completo (C2). `dry_run` es `true` por defecto y estricto; con `dry_run = false` el
     * `confirm_client_name` es obligatorio y se compara contra `clients.name` (recorte y minúsculas); el error NO revela el nombre.
     *
     * @param Request                 $request  Request entrante.
     * @param int|string              $id       Id numérico o uuid del cliente.
     * @param ClientResetDatosService $servicio Inyectado por el contenedor.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function reset_datos_json(Request $request, $id, ClientResetDatosService $servicio)
    {
        $client = $this->cargar_cliente($id);

        if ($client === null) {
            return $this->responder_cliente_inexistente($id);
        }

        $lectura = $servicio->leer_pedido($request->all(), self::PARAMETROS_DEL_RESET);

        if (count($lectura['errores']) > 0) {
            return $this->responder_validacion($lectura['errores']);
        }

        $pedido = $lectura['pedido'];

        /* Freno: el nombre. Sólo cuando de verdad se va a ejecutar (una simulación no borra nada). Se reusa el freno del trait (misma
           comparación, mismo caso del cliente sin nombre) pero se contesta con la forma de ESTE controlador: `validacion` + `detalle`. */
        if (! $pedido['dry_run']) {
            $rechazo = $this->rechazar_si_el_nombre_del_cliente_no_confirma($request, $client, 'No se borró nada.');

            if ($rechazo !== null) {
                $datos = $rechazo->getData(true);

                return $this->responder_validacion(
                    ['confirm_client_name' => trim((string) $datos['error'])],
                    ['client_id' => (int) $client->id, 'ayuda' => isset($datos['ayuda']) ? (string) $datos['ayuda'] : null]
                );
            }
        }

        $pedido['origen']      = ClientDataReset::ORIGEN_CLAUDE;
        $pedido['admin_id']    = null;
        $pedido['solicitante'] = $pedido['solicitante'] === null ? ClientResetDatosService::SOLICITANTE_POR_DEFECTO : $pedido['solicitante'];

        $resultado = $servicio->pedir($client, $pedido);

        // 🔴 Puede llevar el token de la simulación: que nada lo guarde en una caché intermedia.
        return response()->json($resultado['cuerpo'], $resultado['http_status'])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Lista los últimos registros del reset: los del admin y los que cuenta el cliente.
     *
     * @param Request                 $request  Request entrante.
     * @param int|string              $id       Id numérico o uuid del cliente.
     * @param ClientResetDatosService $servicio Inyectado por el contenedor.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function registros_json(Request $request, $id, ClientResetDatosService $servicio)
    {
        $client = $this->cargar_cliente($id);

        if ($client === null) {
            return $this->responder_cliente_inexistente($id);
        }

        return response()->json($servicio->registros($client), 200)->header('Cache-Control', 'no-store, private');
    }
}
