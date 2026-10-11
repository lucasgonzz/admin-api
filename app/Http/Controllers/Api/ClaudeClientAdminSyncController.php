<?php

namespace App\Http\Controllers\Api;

use App\Models\ClientApi;
use App\Services\ClientAdminSyncClaveService;
use App\Services\ClientAdminSyncPuenteService;
use App\Services\ClientCatalogoPuenteService;
use Illuminate\Http\Request;

/**
 * Las dos rutas con las que el admin cierra y atiende `admin-sync` en el `empresa-api` de UN cliente (misión cruzada
 * `cerrar-admin-sync`, 10/10/2026):
 *
 *   - A3 `POST claude/clients/{id}/admin-sync/puente`        — reenvía UN pedido de una lista blanca de siete entradas
 *     (`mostrador/*` y `update-default-version`) con la clave del cliente (`ClientAdminSyncPuenteService`).
 *   - A4 `POST claude/clients/{id}/admin-sync/exigir-clave`  — mira, prende o apaga `ADMIN_SYNC_REQUIRE_API_KEY` en el `.env` de
 *     cada frente del cliente, con prueba y vuelta atrás (`ClientAdminSyncClaveService`).
 *
 * 🔴 Extiende `ClaudeClientCatalogoController` a propósito: es la misma familia (el acceso del admin a un cliente con SU clave, sin
 * que esa clave pase por la máquina de Lucas) y hereda SIN copiar los helpers de esa clase —el cargado del cliente por id o uuid,
 * los códigos de error estables, el freno de `confirm_client_name`, los booleanos estrictos y los límites de la ruta—, que son los
 * que ya tienen sus tests. Las dos rutas heredadas (`clave_json`, `puente_json`) NO están registradas con este controlador.
 *
 * 🔴 EL CONTRATO (el mismo del catálogo): los errores de ESTE controlador llevan en `error` un CÓDIGO ESTABLE
 * (`cliente_inexistente`, `sin_frentes`, `sin_api_key`, `sin_url`, `validacion`, `cliente_no_responde`) y en `mensaje` el texto; un
 * 404 SIN `error` es el de Laravel (este admin no tiene la ruta). 422 siempre es `validacion`, con `detalle` por campo. El puente
 * contesta SIEMPRE 200 con `puente: true` cuando LLEGÓ al cliente, y el HTTP del cliente viaja adentro, en `status`.
 *
 * 🔴 NINGUNA RESPUESTA, LOG NI MENSAJE DE ERROR LLEVA EL VALOR DE `clients.api_key`.
 *
 * Protegidos por el middleware `claude.task.key`, como todo el bloque. Lista cerrada de parámetros: una clave de más es 422 y no se
 * hace nada.
 */
class ClaudeClientAdminSyncController extends ClaudeClientCatalogoController
{
    /**
     * Parámetros de A3 (lista cerrada).
     *
     * @var array<int, string>
     */
    const PARAMETROS_DEL_PUENTE_DE_ADMIN_SYNC = ['metodo', 'ruta', 'cuerpo'];

    /**
     * Parámetros de A4 (lista cerrada).
     *
     * @var array<int, string>
     */
    const PARAMETROS_DE_EXIGIR_CLAVE = ['accion', 'confirm_client_name'];

    /* ==============================================================================================
     | A3 — POST claude/clients/{id}/admin-sync/puente
     |============================================================================================= */

    /**
     * Reenvía UN pedido de la lista blanca a `admin-sync/*` del `empresa-api` del cliente, con la clave del cliente, y devuelve lo
     * que contestó.
     *
     * Pedido: `{metodo: GET|POST|PUT, ruta: "/<ruta>[?query]", cuerpo?: objeto|lista (solo POST y PUT)}`. La `ruta` es relativa a
     * `/api/admin-sync` y su query se reenvía tal cual; el PATH tiene que estar en `ClientAdminSyncPuenteService::LISTA_BLANCA`
     * (7 entradas), sino 422 `validacion` y NO se le pega al cliente.
     *
     * 🔴 Cuando el admin LLEGÓ al cliente, SIEMPRE contesta 200 con `{puente: true, status, cuerpo, cuerpo_crudo}`: el HTTP del
     * cliente (un 401, un 404, un 422, un 500, un 3xx) viaja adentro, en `status`. Cuando NO pudo llegar: 409 `sin_api_key`, 409
     * `sin_url` o 502 `cliente_no_responde` (timeout o conexión). Nunca lleva la clave del cliente.
     *
     * @param Request                     $request Request entrante.
     * @param int|string                  $id      Id numérico o uuid del cliente.
     * @param ClientAdminSyncPuenteService $puente  Inyectado por el contenedor.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function puente_de_admin_sync_json(Request $request, $id, ClientAdminSyncPuenteService $puente)
    {
        $client = $this->cargar_cliente($id);

        if ($client === null) {
            return $this->responder_cliente_inexistente($id);
        }

        $pedido = $this->leer_el_pedido_del_puente_de_admin_sync($request);

        if (count($pedido['errores']) > 0) {
            return $this->responder_validacion($pedido['errores'], $pedido['extra']);
        }

        $resultado = $puente->reenviar($client, $pedido['metodo'], $pedido['ruta'], $pedido['cuerpo']);

        switch ($resultado['estado']) {
            case ClientAdminSyncPuenteService::ESTADO_SIN_API_KEY:
                return $this->responder_error('sin_api_key', 409, (string) $resultado['mensaje']);

            case ClientAdminSyncPuenteService::ESTADO_SIN_URL:
                return $this->responder_error('sin_url', 409, (string) $resultado['mensaje']);

            case ClientAdminSyncPuenteService::ESTADO_NO_RESPONDE:
                return $this->responder_error('cliente_no_responde', 502, (string) $resultado['mensaje']);
        }

        return response()->json([
            'puente'       => true,
            'status'       => (int) $resultado['status'],
            'cuerpo'       => $resultado['cuerpo'],
            'cuerpo_crudo' => $resultado['cuerpo_crudo'],
        ], 200);
    }

    /**
     * Lee y valida a mano el pedido de A3. No le pega a nadie.
     *
     * Todo lo que se puede decidir sin llamar al cliente se decide acá: método, forma de la ruta, lista blanca y forma del cuerpo.
     * Un pedido fuera de la lista blanca NUNCA llega al servicio.
     *
     * @param Request $request Request entrante.
     *
     * @return array{errores: array<string, string>, extra: array<string, mixed>, metodo: string|null, ruta: string|null, cuerpo: mixed}
     */
    protected function leer_el_pedido_del_puente_de_admin_sync(Request $request)
    {
        $errores = $this->parametros_de_mas($request, self::PARAMETROS_DEL_PUENTE_DE_ADMIN_SYNC);
        $extra   = [];

        /* El método: GET, POST o PUT, sin importar mayúsculas. Cualquier otro no está en la lista blanca. */
        $metodo     = null;
        $metodo_raw = $request->input('metodo');

        if ($metodo_raw === null || $metodo_raw === '') {
            $errores['metodo'] = 'Es obligatorio. Tiene que ser GET, POST o PUT.';
        } elseif (! is_string($metodo_raw)) {
            $errores['metodo'] = 'Tiene que ser un texto: GET, POST o PUT.';
        } elseif (! mb_check_encoding($metodo_raw, 'UTF-8')) {
            $errores['metodo'] = 'Tiene bytes que no son UTF-8 válido: tiene que ser el texto GET, POST o PUT.';
        } elseif (! in_array(strtoupper(trim($metodo_raw)), ['GET', 'POST', 'PUT'], true)) {
            $errores['metodo'] = 'Tiene que ser GET, POST o PUT: el puente no reenvía ningún otro método (llegó "' . $this->eco($metodo_raw, 20) . '").';
        } else {
            $metodo = strtoupper(trim($metodo_raw));
        }

        /* La ruta: relativa, con `/` inicial, sin caracteres raros y dentro de la lista blanca. */
        $ruta     = null;
        $ruta_raw = $request->input('ruta');

        if ($ruta_raw === null || $ruta_raw === '') {
            $errores['ruta'] = 'Es obligatoria: la ruta relativa a /api/admin-sync, con `/` inicial (por ejemplo /mostrador/duenos).';
        } elseif (! is_string($ruta_raw)) {
            $errores['ruta'] = 'Tiene que ser un texto: la ruta relativa a /api/admin-sync.';
        } elseif (! mb_check_encoding($ruta_raw, 'UTF-8')) {
            $errores['ruta'] = 'Tiene bytes que no son UTF-8 válido: una ruta del puente es un texto (lo que no sea ASCII va codificado como %XX).';
        } elseif (mb_strlen($ruta_raw) > self::MAX_RUTA) {
            $errores['ruta'] = 'Es demasiado larga (máximo ' . self::MAX_RUTA . ' caracteres).';
        } elseif (preg_match('/[\x00-\x1f\x7f#]/', $ruta_raw) === 1) {
            $errores['ruta'] = 'Lleva caracteres de control o un `#`: una ruta del puente es un path con su query y nada más.';
        } elseif (substr($ruta_raw, 0, 1) !== '/') {
            $errores['ruta'] = 'Tiene que empezar con `/` (es relativa a /api/admin-sync, por ejemplo /mostrador/duenos).';
        } elseif ($metodo !== null) {
            list($path, $query) = ClientCatalogoPuenteService::separar_la_ruta($ruta_raw);

            if (! ClientAdminSyncPuenteService::ruta_permitida($metodo, $path)) {
                $errores['ruta'] = $metodo . ' ' . $this->eco($path, 120) . ' no está en la lista blanca del puente: solo reenvía las rutas de mostrador y update-default-version.';
                $extra['rutas_permitidas'] = ClientAdminSyncPuenteService::rutas_permitidas();
            } elseif (ClientCatalogoPuenteService::query_trae_method_override($query)) {
                /* 🔴 `_method` en la query: el cliente (Laravel) lo toma como el método REAL de un POST y esquivaría la lista
                   blanca de métodos. */
                $errores['ruta'] = 'La query lleva `_method`: Laravel lo toma como el método real de un POST, y el puente '
                    . 'solo reenvía GET, POST y PUT tal cual. Sacalo de la query.';
            } else {
                $ruta = $ruta_raw;
            }
        }

        /* El cuerpo: objeto o lista, solo en un POST o un PUT. */
        $cuerpo     = null;
        $cuerpo_raw = $request->input('cuerpo');

        if ($cuerpo_raw !== null) {
            if (! is_array($cuerpo_raw)) {
                $errores['cuerpo'] = 'Tiene que ser un objeto o una lista JSON.';
            } elseif (! mb_check_encoding($cuerpo_raw, 'UTF-8')) {
                $errores['cuerpo'] = 'Tiene texto con bytes que no son UTF-8 válido: el cuerpo tiene que ser JSON.';
            } elseif ($metodo === 'GET' && count($cuerpo_raw) > 0) {
                $errores['cuerpo'] = 'Un GET no lleva cuerpo: los filtros van en la query de la ruta (/mostrador/duenos?x=1).';
            } elseif (ClientCatalogoPuenteService::cuerpo_trae_method_override($cuerpo_raw)) {
                /* 🔴 Lo mismo por el cuerpo: Laravel lee `_method` de las claves de arriba del JSON. */
                $errores['cuerpo'] = 'El primer nivel del cuerpo lleva `_method`: Laravel lo toma como el método real de un POST, '
                    . 'y el puente solo reenvía GET, POST y PUT tal cual. Sacalo del cuerpo.';
            } elseif (in_array($metodo, ClientAdminSyncPuenteService::METODOS_CON_CUERPO, true)) {
                $cuerpo = $this->cuerpo_original($request);
            }
        }

        return ['errores' => $errores, 'extra' => $extra, 'metodo' => $metodo, 'ruta' => $ruta, 'cuerpo' => $cuerpo];
    }

    /* ==============================================================================================
     | A4 — POST claude/clients/{id}/admin-sync/exigir-clave
     |============================================================================================= */

    /**
     * Mira, prende o apaga la exigencia de la clave de admin-sync en el `.env` de TODOS los frentes del cliente.
     *
     * Pedido: `{accion?: ver|prender|apagar, confirm_client_name?}`. `ver` es el default y NO escribe nada. `prender` y `apagar`
     * exigen `confirm_client_name` (recorte y minúsculas contra `clients.name`); el error NO revela el nombre correcto.
     *
     * Respuesta 200: `{client_id, accion, estado, listo, motivo, frentes[], prueba}`:
     *   - `frentes[]`: `{client_api_id, hosting_type, path, es_la_activa, clave: igual|falta|distinta|sin_env|error,
     *     exigencia: sin_definir|true|false|invalido|null, exigencia_setup: ídem, accion: ninguna|ya_estaba|escrito|revertido|
     *     fallo|reversion_fallo, error}`. `clave` compara `ADMIN_API_INBOUND_KEY` del `.env` con `clients.api_key`; NUNCA sale
     *     ningún valor de clave.
     *   - `estado`: `visto` | `prendido` | `apagado` | `abortado` (una precondición falló: no se escribió nada) | `revertido` (la
     *     prueba o una escritura falló y se restauró todo) | `revertido_con_fallas` (y alguna restauración también falló: mirar a
     *     mano) | `sin_efecto` (quedó prendido pero un pedido SIN clave no dio 401) | `sin_verificar` (quedó prendido, la clave
     *     entra, pero no se pudo confirmar el 401 sin clave).
     *   - `listo`: en `prender`, solo `estado = prendido`; en `apagar`, solo `apagado`; en `ver`, que TODOS los frentes estén en
     *     `clave: igual` y el cliente tenga clave (las condiciones para prender).
     *   - `motivo`: texto cuando algo no salió (el motivo por frente va además en cada `frentes[].error`).
     *   - `prueba`: `{con_clave: {ruta, status, resultado}, sin_clave: {ruta, status, resultado, veredicto}}` si llegó a correr.
     * Errores: 404 `cliente_inexistente`, 409 `sin_frentes`, 422 `validacion`.
     *
     * @param Request                    $request   Request entrante.
     * @param int|string                 $id        Id numérico o uuid del cliente.
     * @param ClientAdminSyncClaveService $servicio  Inyectado por el contenedor.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function exigir_clave_json(Request $request, $id, ClientAdminSyncClaveService $servicio)
    {
        $client = $this->cargar_cliente($id);

        if ($client === null) {
            return $this->responder_cliente_inexistente($id);
        }

        $pedido = $this->leer_el_pedido_de_exigir_clave($request);

        if (count($pedido['errores']) > 0) {
            return $this->responder_validacion($pedido['errores']);
        }

        /* Freno: el nombre. Solo cuando de verdad se va a escribir. */
        if (in_array($pedido['accion'], ClientAdminSyncClaveService::ACCIONES_QUE_ESCRIBEN, true)) {
            $rechazo = $this->rechazar_si_el_nombre_del_cliente_no_confirma($request, $client, '');

            if ($rechazo !== null) {
                $datos = $rechazo->getData(true);

                return $this->responder_validacion(
                    ['confirm_client_name' => trim((string) $datos['error'])],
                    ['client_id' => (int) $client->id, 'ayuda' => isset($datos['ayuda']) ? (string) $datos['ayuda'] : null]
                );
            }
        }

        if (ClientApi::query()->where('client_id', $client->id)->count() === 0) {
            return $this->responder_error(
                'sin_frentes',
                409,
                'El cliente no tiene ninguna API (frente) cargada en el admin: no hay a dónde mirar ni escribir. No se hizo nada.'
            );
        }

        return response()->json($servicio->ejecutar($client, $pedido['accion']), 200);
    }

    /**
     * Lee y valida a mano el pedido de A4. No toca nada.
     *
     * `accion` es estricta: solo `ver`, `prender` o `apagar`. Un valor que no se entiende es un 422 y NO un `ver` ni un `prender`.
     *
     * @param Request $request Request entrante.
     *
     * @return array{errores: array<string, string>, accion: string}
     */
    protected function leer_el_pedido_de_exigir_clave(Request $request)
    {
        $errores = $this->parametros_de_mas($request, self::PARAMETROS_DE_EXIGIR_CLAVE);
        $accion  = ClientAdminSyncClaveService::ACCION_VER;
        $bruta   = $request->input('accion');

        if ($bruta !== null && $bruta !== '') {
            $permitidas = [
                ClientAdminSyncClaveService::ACCION_VER,
                ClientAdminSyncClaveService::ACCION_PRENDER,
                ClientAdminSyncClaveService::ACCION_APAGAR,
            ];

            if (! is_string($bruta) || ! in_array(strtolower(trim($bruta)), $permitidas, true)) {
                $errores['accion'] = 'Tiene que ser una de: ' . implode(', ', $permitidas) . '. Sin ella es `ver`: no escribe nada.';
            } else {
                $accion = strtolower(trim($bruta));
            }
        }

        $nombre = $request->input('confirm_client_name');

        if ($nombre !== null && (! is_string($nombre) || mb_strlen($nombre) > self::MAX_NOMBRE)) {
            $errores['confirm_client_name'] = 'Tiene que ser un texto de hasta ' . self::MAX_NOMBRE . ' caracteres.';
        }

        return ['errores' => $errores, 'accion' => $accion];
    }
}
