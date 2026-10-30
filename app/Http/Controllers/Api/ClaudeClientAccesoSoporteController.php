<?php

namespace App\Http\Controllers\Api;

use App\Models\ClientApi;
use App\Services\ClientAccesoSoporteService;
use Illuminate\Http\Request;

/**
 * La ruta con la que un agente (Claude Code, Codex) pide ENTRAR al sistema de un cliente sin que nadie inicie sesión ni escriba una
 * contraseña (misión cruzada `acceso-soporte-firmado`, 30/10/2026): `POST claude/clients/{id}/acceso-soporte`.
 *
 * 🔴 Extiende `ClaudeClientCatalogoController` a propósito, igual que `ClaudeClientAdminSyncController`: es la misma familia (el acceso del
 * admin a un cliente con SU clave, sin que esa clave pase por la máquina de Lucas) y hereda SIN copiar los helpers de esa clase —el
 * cargado del cliente por id o uuid, los códigos de error estables, el freno de `confirm_client_name`, los booleanos estrictos y el
 * `eco()` para repetir texto de afuera sin romper el JSON—, que son los que ya tienen sus tests. Las rutas heredadas (`clave_json`,
 * `puente_json`) NO están registradas con este controlador.
 *
 * 🔴 EL CONTRATO (C3 del plan de la misión). Pedido:
 *
 *     POST {ADMIN_API_URL}/api/claude/clients/{id|uuid}/acceso-soporte
 *     Headers: X-Claude-Task-Key: <COMERCIOCITY_ADMIN_INGEST_KEY>   Accept: application/json
 *     Body:    { dry_run?: bool (DEFAULT TRUE), confirm_client_name?: string (obligatorio si dry_run=false),
 *                solicitante?: string ≤ 60 (default "claude"), motivo?: string ≤ 120 }       lista cerrada: una clave de más = 422
 *
 * Respuestas:
 *
 *   - 200 con `dry_run = true`: `{client_id, dry_run: true, listo, motivo, frente: {client_api_id, hosting_type, api_host, spa_url}}`.
 *     NO emite ningún token y NO le pega al cliente: solo dice a qué frente se le pediría el acceso y si está todo para pedirlo. Si falta
 *     algo de configuración (sin `api_key`, sin URL de API, sin `spa_url`), `listo` es false y `motivo` lo dice con un texto (con
 *     `dry_run = false` esos mismos casos son 409 con su código).
 *   - 200 con `dry_run = false`: `{client_id, dry_run: false, url, vence_en_segundos, expira_en, usuario_id, frente}`. `url` es
 *     `https://<spa del MISMO frente>/acceso-soporte#t=<token>` (el token va en el FRAGMENTO, que no viaja al servidor). `expira_en` es
 *     ISO-8601 en UTC. La respuesta lleva `Cache-Control: no-store`.
 *   - 404 `cliente_inexistente` · 422 `validacion` (con `detalle` por campo).
 *   - 409 `sin_frentes` (el cliente no tiene ninguna `ClientApi`) · `sin_api_key` · `sin_url` · `sin_spa_url`.
 *   - 409 `version_vieja`       el cliente contestó 404/405 sin `code`: su versión no tiene la ruta; actualizarlo o entrar con el login maestro.
 *   - 409 `clave_rechazada`     el cliente contestó 401: su `ADMIN_API_INBOUND_KEY` no coincide o falta (se arregla con `catalogo/clave`).
 *   - 409 `dueno_no_resoluble`  el cliente no pudo decidir cuál es su dueño. · 409 `acceso_desactivado`  apagado en su `.env`.
 *   - 502 `cliente_no_responde` (timeout o conexión) · 502 `respuesta_invalida` (un 200 sin token válido, un 3xx, un 5xx...).
 *
 * Los errores de ESTE controlador llevan en `error` un CÓDIGO ESTABLE y en `mensaje` el texto, con la misma forma que
 * `ClaudeClientAdminSyncController` (`responder_error` / `responder_validacion`). Los de servicio (409/502) traen además `client_id` y
 * `frente`, para saber a qué frente se le habló.
 *
 * 🔴 ORDEN de las comprobaciones: cliente → validación del pedido → freno del nombre (solo con `dry_run = false`) → `sin_frentes` →
 * servicio. Un pedido inválido no llega a tocar al cliente, y un nombre equivocado no llega a pedir ningún token.
 *
 * 🔴 NINGUNA RESPUESTA, LOG NI MENSAJE DE ERROR LLEVA `clients.api_key` NI EL TOKEN. El token solo existe en el campo `url` de la respuesta
 * de éxito, que es el entregable. Quien llama tampoco debe imprimirlo ni guardarlo: el acceso vence en pocos minutos y es de un solo uso.
 *
 * Protegida por el middleware `claude.task.key`, como todo el bloque.
 */
class ClaudeClientAccesoSoporteController extends ClaudeClientCatalogoController
{
    /**
     * Parámetros del pedido (lista cerrada).
     *
     * @var array<int, string>
     */
    const PARAMETROS_DEL_ACCESO_DE_SOPORTE = ['dry_run', 'confirm_client_name', 'solicitante', 'motivo'];

    /**
     * Cómo se contesta cada estado de error del servicio: `estado => [código estable de `error`, HTTP]`.
     *
     * Los 409 son "el acceso no se puede dar por cómo está configurado el admin o el cliente" (se arregla del lado de quien llama o de
     * Lucas); los 502 son "el cliente contestó mal o no contestó" (se reintenta o se mira el cliente).
     *
     * @var array<string, array{0: string, 1: int}>
     */
    const ERRORES_DEL_SERVICIO = [
        ClientAccesoSoporteService::ESTADO_SIN_API_KEY         => ['sin_api_key', 409],
        ClientAccesoSoporteService::ESTADO_SIN_URL             => ['sin_url', 409],
        ClientAccesoSoporteService::ESTADO_SIN_SPA_URL         => ['sin_spa_url', 409],
        ClientAccesoSoporteService::ESTADO_VERSION_VIEJA       => ['version_vieja', 409],
        ClientAccesoSoporteService::ESTADO_CLAVE_RECHAZADA     => ['clave_rechazada', 409],
        ClientAccesoSoporteService::ESTADO_ACCESO_DESACTIVADO  => ['acceso_desactivado', 409],
        ClientAccesoSoporteService::ESTADO_DUENO_NO_RESOLUBLE  => ['dueno_no_resoluble', 409],
        ClientAccesoSoporteService::ESTADO_NO_RESPONDE         => ['cliente_no_responde', 502],
        ClientAccesoSoporteService::ESTADO_RESPUESTA_INVALIDA  => ['respuesta_invalida', 502],
    ];

    /**
     * Pide (o simula pedir) un acceso de soporte al sistema de UN cliente.
     *
     * Ver el docblock de la clase: es el contrato completo (C3). `dry_run` es `true` por defecto y estricto: un valor que no se entiende
     * es 422, nunca "emitir". Con `dry_run = false` el `confirm_client_name` es obligatorio y se compara contra `clients.name` (recorte y
     * minúsculas); el error NO revela el nombre correcto.
     *
     * @param Request                     $request  Request entrante.
     * @param int|string                  $id       Id numérico o uuid del cliente.
     * @param ClientAccesoSoporteService  $servicio Inyectado por el contenedor.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function acceso_soporte_json(Request $request, $id, ClientAccesoSoporteService $servicio)
    {
        $client = $this->cargar_cliente($id);

        if ($client === null) {
            return $this->responder_cliente_inexistente($id);
        }

        $pedido = $this->leer_el_pedido_del_acceso_de_soporte($request);

        if (count($pedido['errores']) > 0) {
            return $this->responder_validacion($pedido['errores']);
        }

        /* Freno: el nombre. Solo cuando de verdad se va a pedir un token (un dry_run no emite nada). Se reusa el freno del trait (misma
           comparación, mismo caso del cliente sin nombre) pero se contesta con la forma de ESTE controlador: `validacion` + `detalle`. */
        if (! $pedido['dry_run']) {
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
                'El cliente no tiene ninguna API (frente) cargada en el admin: no hay a dónde pedir el acceso. No se hizo nada.'
            );
        }

        $resultado = $servicio->ejecutar($client, $pedido['dry_run'], $pedido['solicitante'], $pedido['motivo']);

        // Dry run: 200 siempre. Si falta configuración, `listo` es false y `motivo` dice qué.
        if ($pedido['dry_run']) {
            $listo = $resultado['estado'] === ClientAccesoSoporteService::ESTADO_LISTO;

            return response()->json([
                'client_id' => (int) $client->id,
                'dry_run'   => true,
                'listo'     => $listo,
                'motivo'    => $listo ? null : (string) $resultado['mensaje'],
                'frente'    => $this->frente_para_responder($resultado['frente']),
            ], 200);
        }

        if ($resultado['estado'] !== ClientAccesoSoporteService::ESTADO_OK) {
            // Un estado que el mapa no conoce (no debería pasar) se trata como respuesta inválida del cliente, no como un éxito.
            $error = isset(self::ERRORES_DEL_SERVICIO[$resultado['estado']])
                ? self::ERRORES_DEL_SERVICIO[$resultado['estado']]
                : ['respuesta_invalida', 502];

            return $this->responder_error(
                $error[0],
                $error[1],
                (string) $resultado['mensaje'],
                ['client_id' => (int) $client->id, 'frente' => $this->frente_para_responder($resultado['frente'])]
            );
        }

        // 🔴 Lleva el token (en `url`): que nada lo guarde en una caché intermedia.
        return response()->json([
            'client_id'         => (int) $client->id,
            'dry_run'           => false,
            'url'               => $resultado['url'],
            'vence_en_segundos' => (int) $resultado['vence_en_segundos'],
            'expira_en'         => $resultado['expira_en'],
            'usuario_id'        => $resultado['usuario_id'],
            'frente'            => $this->frente_para_responder($resultado['frente']),
        ], 200)->header('Cache-Control', 'no-store, private');
    }

    /**
     * Lee y valida a mano el pedido. No toca nada ni le pega a nadie.
     *
     * `dry_run` es estricto: solo `true`, `false`, `1`, `0` (y sus textos). Un valor que no se entiende es un 422 y NO un `false`: con
     * `$request->boolean()` un `"maybe"` valdría `false`, o sea "emitir", que es el lado peligroso del interruptor. Sin él vale `true`.
     *
     * `solicitante` y `motivo` quedan para la auditoría del cliente y para el log del admin, así que se SANEAN: tienen que ser texto en
     * UTF-8 válido, y los caracteres de control y los saltos de línea se reemplazan por un espacio (una línea de log o una fila de
     * auditoría no se puede partir ni falsificar con un `\n`). Un texto más largo que el máximo es 422 (no se recorta en silencio: un
     * motivo cortado a la mitad puede decir otra cosa). `solicitante` vacío vale `claude`; `motivo` vacío es null.
     *
     * @param Request $request Request entrante.
     *
     * @return array{errores: array<string, string>, dry_run: bool, solicitante: string, motivo: string|null}
     */
    protected function leer_el_pedido_del_acceso_de_soporte(Request $request)
    {
        $errores = $this->parametros_de_mas($request, self::PARAMETROS_DEL_ACCESO_DE_SOPORTE);
        $dry_run = true;
        $bruto   = $request->input('dry_run');

        if ($bruto !== null && $bruto !== '') {
            $leido = $this->leer_booleano($bruto);

            if ($leido === null) {
                $errores['dry_run'] = 'Tiene que ser booleano (true, false, 1 o 0). Sin él es true: no pide ningún acceso.';
            } else {
                $dry_run = $leido;
            }
        }

        $nombre = $request->input('confirm_client_name');

        if ($nombre !== null && (! is_string($nombre) || mb_strlen($nombre) > self::MAX_NOMBRE)) {
            $errores['confirm_client_name'] = 'Tiene que ser un texto de hasta ' . self::MAX_NOMBRE . ' caracteres.';
        }

        $solicitante = $this->leer_texto_de_auditoria($request->input('solicitante'), ClientAccesoSoporteService::MAX_SOLICITANTE, 'solicitante', $errores);
        $motivo      = $this->leer_texto_de_auditoria($request->input('motivo'), ClientAccesoSoporteService::MAX_MOTIVO, 'motivo', $errores);

        return [
            'errores'     => $errores,
            'dry_run'     => $dry_run,
            'solicitante' => $solicitante === null ? ClientAccesoSoporteService::SOLICITANTE_POR_DEFECTO : $solicitante,
            'motivo'      => $motivo,
        ];
    }

    /**
     * Sanea un texto libre que va a quedar en una auditoría y en un log (`solicitante`, `motivo`).
     *
     * Orden: se descarta lo ausente o vacío (null), se exige un string en UTF-8 válido, se reemplazan por un espacio los caracteres de
     * control (incluidos saltos de línea, tabuladores y los separadores de línea y de párrafo de Unicode), se sacan los invisibles de
     * formato (el de "derecha a izquierda" sirve para falsificar lo que se ve en una línea), se colapsan los espacios y se recorta. Recién
     * ahí se mide el largo.
     *
     * @param mixed                 $crudo   Lo que vino en el pedido.
     * @param int                   $maximo  Caracteres máximos.
     * @param string                $campo   Nombre del campo (para el error).
     * @param array<string, string> $errores Errores del pedido, por referencia.
     *
     * @return string|null El texto saneado, o null si no vino nada (o si hubo un error).
     */
    protected function leer_texto_de_auditoria($crudo, $maximo, $campo, array &$errores)
    {
        if ($crudo === null || $crudo === '') {
            return null;
        }

        if (! is_string($crudo)) {
            $errores[$campo] = 'Tiene que ser un texto de hasta ' . $maximo . ' caracteres.';

            return null;
        }

        if (! mb_check_encoding($crudo, 'UTF-8')) {
            $errores[$campo] = 'Tiene bytes que no son UTF-8 válido: tiene que ser un texto de hasta ' . $maximo . ' caracteres.';

            return null;
        }

        // Control, separador de línea y de párrafo → espacio; formato invisible → fuera; espacios repetidos → uno; sin bordes.
        $texto = preg_replace('/[\p{Cc}\x{2028}\x{2029}]+/u', ' ', $crudo);
        $texto = $texto === null ? null : preg_replace('/\p{Cf}+/u', '', $texto);
        $texto = $texto === null ? null : preg_replace('/\s{2,}/u', ' ', $texto);

        if ($texto === null) {
            $errores[$campo] = 'No se pudo leer como texto: tiene que ser un texto de hasta ' . $maximo . ' caracteres.';

            return null;
        }

        $texto = trim($texto);

        if ($texto === '') {
            return null;
        }

        if (mb_strlen($texto) > $maximo) {
            $errores[$campo] = 'Es demasiado largo (máximo ' . $maximo . ' caracteres).';

            return null;
        }

        return $texto;
    }

    /**
     * El frente en la forma del contrato: siempre las mismas cuatro claves, con null en lo que no se pudo resolver.
     *
     * @param array<string, mixed>|null $frente Lo que devolvió el servicio.
     *
     * @return array{client_api_id: int|null, hosting_type: string|null, api_host: string|null, spa_url: string|null}
     */
    protected function frente_para_responder($frente)
    {
        $frente = is_array($frente) ? $frente : [];

        return [
            'client_api_id' => isset($frente['client_api_id']) ? (int) $frente['client_api_id'] : null,
            'hosting_type'  => isset($frente['hosting_type']) ? (string) $frente['hosting_type'] : null,
            'api_host'      => isset($frente['api_host']) ? (string) $frente['api_host'] : null,
            'spa_url'       => isset($frente['spa_url']) ? (string) $frente['spa_url'] : null,
        ];
    }
}
