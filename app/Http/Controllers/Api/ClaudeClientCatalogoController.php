<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientApi;
use App\Services\ClientCatalogoPuenteService;
use App\Services\ClientInboundKeySyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Las dos rutas que le dan a `/categorizar` (el motor del Catálogo de un cliente) acceso al
 * `empresa-api` de ese cliente SIN que la clave del cliente pase por la máquina de Lucas (misión
 * cruzada `implementacion-dos-sistemas`, 6/10/2026):
 *
 *   - C1 `POST claude/clients/{id}/catalogo/clave`  — el admin ESCRIBE `ADMIN_API_INBOUND_KEY` =
 *     `clients.api_key` en el `.env` de cada frente del cliente (`ClientInboundKeySyncService`).
 *   - C2 `POST claude/clients/{id}/catalogo/puente` — el admin hace de PUENTE para las nueve rutas
 *     `admin-sync/catalogo/*` del cliente (`ClientCatalogoPuenteService`).
 *
 * 🔴 POR QUÉ LAS DOS VIVEN EN UN CONTROLADOR APARTE y no en `ClaudeClientOpsController`: es un solo
 * tema (el acceso del motor al catálogo de un cliente), el archivo de operaciones ya pasa las 2800
 * líneas y hay otras misiones de admin abiertas que lo tocan. Menos archivos compartidos, menos
 * conflictos.
 *
 * 🔴 EL CONTRATO CON EL MOTOR (lo que no se puede mover sin avisar):
 *
 *   - Los errores de ESTE controlador (los que el admin produce, antes o en vez de llegar al
 *     cliente) llevan en `error` un CÓDIGO ESTABLE —`cliente_inexistente`, `sin_frentes`,
 *     `sin_api_key`, `sin_url`, `validacion`, `cliente_no_responde`— y en `mensaje` el texto para
 *     leer. El motor distingue "el admin conoce la ruta" de "el admin es viejo y no la tiene" por
 *     eso: un 404 SIN `error` en el cuerpo es el 404 de Laravel (ruta inexistente); uno CON `error`
 *     es `cliente_inexistente`. Por eso, a diferencia del resto de `claude/*` (donde `error` es el
 *     texto), acá `error` es el código.
 *   - 422 siempre es `validacion`, con `detalle` por campo (`{campo: motivo}`).
 *   - El puente contesta SIEMPRE 200 con `puente: true` cuando el admin LLEGÓ al cliente, cualquiera
 *     sea el HTTP que éste devolvió: el status del cliente viaja adentro, en `status`. Si el puente
 *     devolviera el código del cliente, un 404 del cliente (ruta que no existe en su versión) se
 *     confundiría con un 404 del propio admin.
 *
 * 🔴 NINGUNA RESPUESTA, LOG NI MENSAJE DE ERROR LLEVA EL VALOR DE `clients.api_key`. Todo texto que
 * viene de afuera (el cuerpo del cliente, la excepción de SSH o de HTTP) pasa por
 * `TapaLaClaveDelCliente` antes de salir.
 *
 * Protegidos por el middleware `claude.task.key`, como todo el bloque. Lista cerrada de parámetros:
 * una clave de más es 422 y no se hace nada (ignorarla en silencio dejaría creer que se aplicó).
 */
class ClaudeClientCatalogoController extends Controller
{
    /**
     * Los helpers del bloque `claude/*`: el freno del nombre (`confirm_client_name`), `error_422`,
     * `normalizar_nombre`. Se usa el del nombre tal cual para que C1 se comporte exactamente como
     * `PUT claude/clients/{id}/schedule`.
     */
    use RespuestasParaClaude;

    /**
     * Parámetros de C1 (lista cerrada).
     *
     * @var array<int, string>
     */
    const PARAMETROS_DE_LA_CLAVE = ['dry_run', 'confirm_client_name', 'pisar_distintas'];

    /**
     * Parámetros de C2 (lista cerrada).
     *
     * @var array<int, string>
     */
    const PARAMETROS_DEL_PUENTE = ['metodo', 'ruta', 'cuerpo'];

    /**
     * Máximo de caracteres de `confirm_client_name` (lo que entra en `clients.name`).
     *
     * @var int
     */
    const MAX_NOMBRE = 190;

    /**
     * Máximo de caracteres de la `ruta` del puente (path + query). Una query de filtros entra de
     * sobra; más que esto es un error de quien arma el pedido.
     *
     * @var int
     */
    const MAX_RUTA = 2000;

    /* ==============================================================================================
     | C1 — POST claude/clients/{id}/catalogo/clave
     |============================================================================================= */

    /**
     * Deja la clave de la API del cliente escrita en el `.env` de TODOS sus frentes.
     *
     * Frenos, en este orden y con el criterio de `PUT claude/clients/{id}/schedule`:
     *   1. `dry_run`, por defecto `true`: lee el `.env` de cada frente y dice qué escribiría; no
     *      respalda ni escribe nada, y no genera ninguna clave.
     *   2. `confirm_client_name`, obligatorio con `dry_run=false`: tiene que coincidir con
     *      `clients.name` (recorte y minúsculas). El error NO revela el nombre correcto: es un freno,
     *      no un formulario a completar.
     *
     * `pisar_distintas` (opcional, default false, igual de estricto que `dry_run`): aplicando, un frente cuyo
     * `.env` ya tiene OTRA clave NO se escribe salvo que venga en true; queda `estado: distinta`,
     * `accion: ninguna`, `error: "tiene otra clave; para reemplazarla, pisar_distintas: true"` y `listo` en
     * false. El dry_run PREDICE lo mismo que haría aplicar con los mismos parámetros: sin el campo (o en
     * false) el frente `distinta` sale con `accion: ninguna` y ese mismo motivo; con el campo en true, con
     * `accion: escribir`.
     *
     * Aplicando, antes de cada escritura se respalda el `.env` del frente (`.env.bak-<fecha>`) y
     * `EnvSshService` relee el archivo y verifica que la variable haya quedado: si no quedó, ese
     * frente es `estado: error` y los demás siguen.
     *
     * Respuesta 200: `{client_id, dry_run, api_key_en_el_admin, frentes[], listo}`. NUNCA lleva el
     * valor de ninguna clave. `listo` es true con AL MENOS UN frente `igual` o `escrita` y NINGUNO en
     * `falta`, `distinta` o `error`; un frente `sin_env` no cuenta (ver
     * `ClientInboundKeySyncService::calcular_listo()`). Errores: 404 `cliente_inexistente`, 409
     * `sin_frentes`, 422 `validacion` y 500 `clave_no_guardada` (falló la base al guardar la clave
     * recién generada: texto fijo, sin nada de lo que dijo la base; no se escribió nada en ningún servidor).
     *
     * @param Request                      $request      Request entrante (`dry_run`, `confirm_client_name`).
     * @param int|string                   $id           Id numérico o uuid del cliente.
     * @param ClientInboundKeySyncService  $sincronizador Inyectado por el contenedor.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function clave_json(Request $request, $id, ClientInboundKeySyncService $sincronizador)
    {
        $client = $this->cargar_cliente($id);

        if ($client === null) {
            return $this->responder_cliente_inexistente($id);
        }

        $pedido = $this->leer_el_pedido_de_la_clave($request);

        if (count($pedido['errores']) > 0) {
            return $this->responder_validacion($pedido['errores']);
        }

        /* Freno 2: el nombre. Solo cuando de verdad se va a escribir. */
        if (! $pedido['dry_run']) {
            /* El cierre va vacío: `responder_validacion()` ya termina diciendo que no se hizo nada. */
            $rechazo = $this->rechazar_si_el_nombre_del_cliente_no_confirma($request, $client, '');

            if ($rechazo !== null) {
                /* Se reusa el freno del trait (misma comparación, mismo caso del cliente sin nombre)
                   pero se contesta con la forma de ESTE controlador: `validacion` + `detalle`. */
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
                'El cliente no tiene ninguna API (frente) cargada en el admin: no hay a dónde escribir la clave. '
                . 'No se hizo nada.'
            );
        }

        try {
            $resultado = $sincronizador->sincronizar($client, $pedido['dry_run'], $pedido['pisar_distintas']);
        } catch (\RuntimeException $e) {
            /* Solo el fallo de guardar la clave generada tiene respuesta propia; cualquier otra
               excepción es un error de verdad y sigue su camino. */
            if ($e->getCode() !== ClientInboundKeySyncService::CODIGO_CLAVE_NO_GUARDADA) {
                throw $e;
            }

            /* El mensaje es el texto FIJO del servicio: nada de lo que dijo la base, que trae la clave. */
            return $this->responder_error('clave_no_guardada', 500, $e->getMessage());
        }

        return response()->json($resultado, 200);
    }

    /* ==============================================================================================
     | C2 — POST claude/clients/{id}/catalogo/puente
     |============================================================================================= */

    /**
     * Reenvía UN pedido de la lista blanca al `admin-sync/catalogo/*` del `empresa-api` del cliente,
     * con la clave del cliente, y devuelve lo que contestó.
     *
     * Pedido: `{metodo: GET|POST, ruta: "/<ruta>[?query]", cuerpo?: objeto|lista (solo POST)}`. La
     * `ruta` es relativa a `/api/admin-sync/catalogo` y su query se reenvía tal cual; el PATH tiene
     * que estar en `ClientCatalogoPuenteService::LISTA_BLANCA` (9 rutas), sino 422 `validacion` y NO
     * se le pega al cliente.
     *
     * 🔴 Cuando el admin LLEGÓ al cliente, SIEMPRE contesta 200 con
     * `{puente: true, status, cuerpo, cuerpo_crudo}`: el HTTP del cliente (un 401, un 404, un 422, un
     * 500) viaja adentro, en `status`. Cuando NO pudo llegar: 409 `sin_api_key`, 409 `sin_url` o 502
     * `cliente_no_responde` (timeout o conexión). Nunca lleva la clave del cliente.
     *
     * @param Request                    $request Request entrante.
     * @param int|string                 $id      Id numérico o uuid del cliente.
     * @param ClientCatalogoPuenteService $puente Inyectado por el contenedor.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function puente_json(Request $request, $id, ClientCatalogoPuenteService $puente)
    {
        $client = $this->cargar_cliente($id);

        if ($client === null) {
            return $this->responder_cliente_inexistente($id);
        }

        $pedido = $this->leer_el_pedido_del_puente($request);

        if (count($pedido['errores']) > 0) {
            return $this->responder_validacion($pedido['errores'], $pedido['extra']);
        }

        $resultado = $puente->reenviar($client, $pedido['metodo'], $pedido['ruta'], $pedido['cuerpo']);

        switch ($resultado['estado']) {
            case ClientCatalogoPuenteService::ESTADO_SIN_API_KEY:
                return $this->responder_error('sin_api_key', 409, (string) $resultado['mensaje']);

            case ClientCatalogoPuenteService::ESTADO_SIN_URL:
                return $this->responder_error('sin_url', 409, (string) $resultado['mensaje']);

            case ClientCatalogoPuenteService::ESTADO_NO_RESPONDE:
                return $this->responder_error('cliente_no_responde', 502, (string) $resultado['mensaje']);
        }

        return response()->json([
            'puente'       => true,
            'status'       => (int) $resultado['status'],
            'cuerpo'       => $resultado['cuerpo'],
            'cuerpo_crudo' => $resultado['cuerpo_crudo'],
        ], 200);
    }

    /* ==============================================================================================
     | Helpers
     |============================================================================================= */

    /**
     * Lee y valida a mano el pedido de C2. No le pega a nadie.
     *
     * Todo lo que se puede decidir sin llamar al cliente se decide acá: método, forma de la ruta,
     * lista blanca y forma del cuerpo. Un pedido fuera de la lista blanca NUNCA llega al servicio.
     *
     * @param Request $request Request entrante.
     *
     * @return array{errores: array<string, string>, extra: array<string, mixed>, metodo: string|null, ruta: string|null, cuerpo: mixed}
     */
    protected function leer_el_pedido_del_puente(Request $request)
    {
        $errores = $this->parametros_de_mas($request, self::PARAMETROS_DEL_PUENTE);
        $extra   = [];

        /* El método: GET o POST, sin importar mayúsculas. Cualquier otro no está en la lista blanca. */
        $metodo     = null;
        $metodo_raw = $request->input('metodo');

        if ($metodo_raw === null || $metodo_raw === '') {
            $errores['metodo'] = 'Es obligatorio. Tiene que ser GET o POST.';
        } elseif (! is_string($metodo_raw)) {
            $errores['metodo'] = 'Tiene que ser un texto: GET o POST.';
        } elseif (! mb_check_encoding($metodo_raw, 'UTF-8')) {
            $errores['metodo'] = 'Tiene bytes que no son UTF-8 válido: tiene que ser el texto GET o POST.';
        } elseif (! in_array(strtoupper(trim($metodo_raw)), ['GET', 'POST'], true)) {
            $errores['metodo'] = 'Tiene que ser GET o POST: el puente no reenvía ningún otro método (llegó "' . $this->eco($metodo_raw, 20) . '").';
        } else {
            $metodo = strtoupper(trim($metodo_raw));
        }

        /* La ruta: relativa, con `/` inicial, sin caracteres raros y dentro de la lista blanca. */
        $ruta     = null;
        $ruta_raw = $request->input('ruta');

        if ($ruta_raw === null || $ruta_raw === '') {
            $errores['ruta'] = 'Es obligatoria: la ruta relativa a /api/admin-sync/catalogo, con `/` inicial (por ejemplo /resumen).';
        } elseif (! is_string($ruta_raw)) {
            $errores['ruta'] = 'Tiene que ser un texto: la ruta relativa a /api/admin-sync/catalogo.';
        } elseif (! mb_check_encoding($ruta_raw, 'UTF-8')) {
            $errores['ruta'] = 'Tiene bytes que no son UTF-8 válido: una ruta del puente es un texto (lo que no sea ASCII va codificado como %XX).';
        } elseif (mb_strlen($ruta_raw) > self::MAX_RUTA) {
            $errores['ruta'] = 'Es demasiado larga (máximo ' . self::MAX_RUTA . ' caracteres).';
        } elseif (preg_match('/[\x00-\x1f\x7f#]/', $ruta_raw) === 1) {
            $errores['ruta'] = 'Lleva caracteres de control o un `#`: una ruta del puente es un path con su query y nada más.';
        } elseif (substr($ruta_raw, 0, 1) !== '/') {
            $errores['ruta'] = 'Tiene que empezar con `/` (es relativa a /api/admin-sync/catalogo, por ejemplo /resumen).';
        } elseif ($metodo !== null) {
            list($path, $query) = ClientCatalogoPuenteService::separar_la_ruta($ruta_raw);

            if (! ClientCatalogoPuenteService::ruta_permitida($metodo, $path)) {
                $errores['ruta'] = $metodo . ' ' . $this->eco($path, 120) . ' no está en la lista blanca del puente: solo reenvía las rutas del catálogo.';
                $extra['rutas_permitidas'] = ClientCatalogoPuenteService::rutas_permitidas();
            } elseif (ClientCatalogoPuenteService::query_trae_method_override($query)) {
                /* 🔴 `_method` en la query: el cliente (Laravel) lo toma como el método REAL de un POST y
                   esquivaría la lista blanca de métodos. */
                $errores['ruta'] = 'La query lleva `_method`: Laravel lo toma como el método real de un POST, y el puente '
                    . 'solo reenvía GET y POST tal cual. Sacalo de la query.';
            } else {
                $ruta = $ruta_raw;
            }
        }

        /* El cuerpo: objeto o lista, solo en un POST. */
        $cuerpo     = null;
        $cuerpo_raw = $request->input('cuerpo');

        if ($cuerpo_raw !== null) {
            if (! is_array($cuerpo_raw)) {
                $errores['cuerpo'] = 'Tiene que ser un objeto o una lista JSON.';
            } elseif (! mb_check_encoding($cuerpo_raw, 'UTF-8')) {
                $errores['cuerpo'] = 'Tiene texto con bytes que no son UTF-8 válido: el cuerpo tiene que ser JSON.';
            } elseif ($metodo === 'GET' && count($cuerpo_raw) > 0) {
                $errores['cuerpo'] = 'Un GET no lleva cuerpo: los filtros van en la query de la ruta (/articulos?desde_id=100).';
            } elseif (ClientCatalogoPuenteService::cuerpo_trae_method_override($cuerpo_raw)) {
                /* 🔴 Lo mismo por el cuerpo: Laravel lee `_method` de las claves de arriba del JSON. */
                $errores['cuerpo'] = 'El primer nivel del cuerpo lleva `_method`: Laravel lo toma como el método real de un POST, '
                    . 'y el puente solo reenvía GET y POST tal cual. Sacalo del cuerpo.';
            } elseif ($metodo === 'POST') {
                $cuerpo = $this->cuerpo_original($request);
            }
        }

        return ['errores' => $errores, 'extra' => $extra, 'metodo' => $metodo, 'ruta' => $ruta, 'cuerpo' => $cuerpo];
    }

    /**
     * El `cuerpo` del pedido tal como lo mandó el motor, SIN las transformaciones de Laravel.
     *
     * Los objetos quedan como `stdClass` (decodificar sin `assoc`): un objeto con claves numéricas
     * (`{"12": "Fijaciones"}`, artículo → categoría) sigue siendo un objeto y no una lista, y los
     * middlewares de recorte y de "texto vacío es null" no le tocan nada. Los valida igual el cliente.
     *
     * @param Request $request Request entrante.
     *
     * @return array|\stdClass|null
     */
    protected function cuerpo_original(Request $request)
    {
        if ($request->isJson()) {
            $decodificado = json_decode((string) $request->getContent());

            if ($decodificado instanceof \stdClass && property_exists($decodificado, 'cuerpo')) {
                $cuerpo = $decodificado->cuerpo;

                return ($cuerpo instanceof \stdClass || is_array($cuerpo)) ? $cuerpo : null;
            }
        }

        /* No es JSON (formulario): lo único que hay es lo que Laravel ya leyó. */
        $leido = $request->input('cuerpo');

        return is_array($leido) ? $leido : null;
    }

    /**
     * Lee y valida a mano el pedido de C1. No toca nada.
     *
     * `dry_run` es estricto: solo `true`, `false`, `1`, `0` (y sus textos). Un valor que no se
     * entiende es un 422 y NO un `false`: con `$request->boolean()` un `"maybe"` valdría `false`, o
     * sea "aplicar", que es el lado peligroso del interruptor.
     *
     * @param Request $request Request entrante.
     *
     * @return array{errores: array<string, string>, dry_run: bool, pisar_distintas: bool}
     */
    protected function leer_el_pedido_de_la_clave(Request $request)
    {
        $errores = $this->parametros_de_mas($request, self::PARAMETROS_DE_LA_CLAVE);
        $dry_run = true;

        $bruto = $request->input('dry_run');

        if ($bruto !== null && $bruto !== '') {
            $leido = $this->leer_booleano($bruto);

            if ($leido === null) {
                $errores['dry_run'] = 'Tiene que ser booleano (true, false, 1 o 0). Sin él es true: no escribe nada.';
            } else {
                $dry_run = $leido;
            }
        }

        /* `pisar_distintas` se lee igual de estricto que `dry_run`: un valor que no se entiende es 422 y NO un
           `true` (el lado peligroso: reemplazar una clave que alguien puede estar usando). */
        $pisar_distintas = false;
        $bruto_de_pisar  = $request->input('pisar_distintas');

        if ($bruto_de_pisar !== null && $bruto_de_pisar !== '') {
            $leido_de_pisar = $this->leer_booleano($bruto_de_pisar);

            if ($leido_de_pisar === null) {
                $errores['pisar_distintas'] = 'Tiene que ser booleano (true, false, 1 o 0). Sin él es false: un frente que ya tiene otra clave no se pisa.';
            } else {
                $pisar_distintas = $leido_de_pisar;
            }
        }

        $nombre = $request->input('confirm_client_name');

        if ($nombre !== null && (! is_string($nombre) || mb_strlen($nombre) > self::MAX_NOMBRE)) {
            $errores['confirm_client_name'] = 'Tiene que ser un texto de hasta ' . self::MAX_NOMBRE . ' caracteres.';
        }

        return ['errores' => $errores, 'dry_run' => $dry_run, 'pisar_distintas' => $pisar_distintas];
    }

    /**
     * Carga el cliente por id numérico o uuid de la ruta.
     *
     * Devuelve null en vez de tirar `ModelNotFoundException`: el 404 se arma a mano, con el cuerpo
     * `cliente_inexistente` que el motor sabe leer.
     *
     * @param int|string $route_id Segmento de la ruta.
     *
     * @return Client|null
     */
    protected function cargar_cliente($route_id)
    {
        $route_id = (string) $route_id;

        if (is_numeric($route_id)) {
            $id = DB::table('clients')->where('id', (int) $route_id)->value('id');
        } elseif (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $route_id) === 1) {
            $id = DB::table('clients')->where('uuid', $route_id)->value('id');
        } else {
            /* Ni un número ni un uuid: no puede ser un cliente, y no se le pregunta a la base con cualquier
               texto de la URL. (Con bytes que no son UTF-8 el ruteador ni siquiera llega acá: sus patrones
               son UTF-8 y contesta su 404 de siempre.) */
            return null;
        }

        if ($id === null) {
            return null;
        }

        return Client::query()->where('id', (int) $id)->first();
    }

    /**
     * Los parámetros que el endpoint no acepta, por campo.
     *
     * @param Request            $request   Request entrante.
     * @param array<int, string> $aceptados Los parámetros que el endpoint sí acepta.
     *
     * @return array<string, string> `campo => motivo`, vacío si no hay nada de más.
     */
    protected function parametros_de_mas(Request $request, array $aceptados)
    {
        $errores = [];

        foreach (array_keys($request->all()) as $clave) {
            if (in_array((string) $clave, $aceptados, true)) {
                continue;
            }

            $errores[$this->eco($clave, 60)] = 'No es un parámetro de este endpoint. Los que acepta: ' . implode(', ', $aceptados) . '.';
        }

        return $errores;
    }

    /**
     * Un texto que vino de AFUERA (un parámetro, el id de la URL, la ruta), listo para repetirlo en una
     * respuesta: en UTF-8 válido y recortado.
     *
     * 🔴 `json_encode` falla con un solo byte que no sea UTF-8, y entonces Laravel no contesta el 422 que
     * se estaba armando sino un 500. Lo que llega por la query o por la URL puede traer cualquier byte
     * (`%FF`), así que todo lo que se repite en un mensaje o se usa de clave de `detalle` pasa por acá:
     * los bytes inválidos salen como `?`.
     *
     * @param mixed $texto  Lo que vino de afuera.
     * @param int   $limite Caracteres máximos que se repiten.
     *
     * @return string
     */
    protected function eco($texto, $limite)
    {
        return mb_substr(mb_convert_encoding((string) $texto, 'UTF-8', 'UTF-8'), 0, $limite);
    }

    /**
     * Un booleano estricto: true/false, 1/0 y sus textos. Cualquier otra cosa es null (inválido).
     *
     * @param mixed $valor Valor crudo.
     *
     * @return bool|null
     */
    protected function leer_booleano($valor)
    {
        if (is_bool($valor)) {
            return $valor;
        }

        if (is_int($valor) && ($valor === 0 || $valor === 1)) {
            return $valor === 1;
        }

        if (is_string($valor)) {
            $texto = strtolower(trim($valor));

            if ($texto === '1' || $texto === 'true') {
                return true;
            }

            if ($texto === '0' || $texto === 'false') {
                return false;
            }
        }

        return null;
    }

    /**
     * La respuesta de error de este controlador: `error` es el CÓDIGO estable y `mensaje` el texto.
     *
     * @param string               $codigo  Código estable (`cliente_inexistente`, `sin_frentes`, ...).
     * @param int                  $status  HTTP.
     * @param string               $mensaje Texto para leer.
     * @param array<string, mixed> $extra   Campos extra del cuerpo.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function responder_error($codigo, $status, $mensaje, array $extra = [])
    {
        return response()->json(array_merge(['error' => $codigo, 'mensaje' => $mensaje], $extra), $status);
    }

    /**
     * 404 `cliente_inexistente`.
     *
     * @param int|string $route_id Lo que vino en la ruta.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function responder_cliente_inexistente($route_id)
    {
        return $this->responder_error(
            'cliente_inexistente',
            404,
            'No existe el cliente ' . $this->eco($route_id, 60) . ' en el admin. No se hizo nada.'
        );
    }

    /**
     * 422 `validacion`, con el motivo por campo en `detalle`.
     *
     * @param array<string, string> $detalle `campo => motivo`.
     * @param array<string, mixed>  $extra   Campos extra del cuerpo.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function responder_validacion(array $detalle, array $extra = [])
    {
        /* El motivo de cada campo va también en el texto: quien imprime solo `mensaje` no pierde nada. */
        $motivos = [];

        foreach ($detalle as $campo => $motivo) {
            $motivos[] = $campo . ': ' . $motivo;
        }

        return $this->responder_error(
            'validacion',
            422,
            'El pedido no es válido. ' . implode(' ', $motivos) . ' No se hizo nada.',
            array_merge(['detalle' => $detalle], $extra)
        );
    }
}
