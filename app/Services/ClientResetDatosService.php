<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientDataReset;
use App\Services\Concerns\TapaLaClaveDelCliente;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * El RESET DE DATOS DE NEGOCIO de un cliente, visto desde el admin (misión cruzada `reset-datos-cliente`, 21/11/2026).
 *
 * 🔴 QUÉ ES. El `empresa-api` del cliente sabe vaciar sus tablas de negocio (artículos, ventas, saldos...) conservando la configuración
 * y reiniciando los IDs (`POST api/admin-sync/reset-datos-negocio`, contrato C1 del plan). Este servicio es el PROXY del admin: autentica
 * con la clave que ya comparten (`clients.api_key` = `ADMIN_API_INBOUND_KEY`, header `X-Admin-Api-Key`), reenvía el pedido y registra
 * cada llamada en `client_data_resets`. NO decide nada sobre los datos: los frenos de verdad (nombre de la base, token de una
 * simulación previa, respaldo obligatorio, candado, bloqueos) viven en el `empresa-api`. Lo único que frena el admin es el nombre del
 * cliente (`confirm_client_name`, lo hacen los controladores) y que haya con qué llamar (frente, clave y URL).
 *
 * Molde: `ClientAccesoSoporteService` (mismo header, mismo resolvedor de URL, mismo tapado de la clave). Diferencias deliberadas:
 *
 *  - UN SOLO INTENTO, sin reintentos: el reset no es idempotente (un token de simulación sirve una sola vez y un timeout puede
 *    significar que el cliente SÍ borró). Ante un timeout se contesta `cliente_no_responde` y se manda a consultar los registros.
 *  - Timeout largo para ejecutar (el respaldo y el vaciado pueden tardar minutos): 60 s simulando, 900 s ejecutando.
 *  - Sin redirecciones (`allow_redirects = false`): Guzzle no saca un header propio como `X-Admin-Api-Key` al redirigir a otro host.
 *
 * TRADUCCIÓN de lo que contesta el cliente (siempre `{error, mensaje, message, client_id, frente}` + `detalle` si el cliente lo manda):
 *
 *   - 200 con `ok = true`                                   → pasa el cuerpo de empresa + `client_id` y `frente`.
 *   - 401                                                   → 409 `clave_rechazada`.
 *   - 404/405 sin `error`                                   → 409 `version_vieja` (su sistema es anterior a esta función).
 *   - 409/422/500 con un `error` de código estable          → el MISMO código y el MISMO status (`en_curso`, `varios_duenos`...).
 *   - timeout o conexión                                    → 502 `cliente_no_responde` (el registro queda `sin_respuesta`).
 *   - cualquier otra cosa (200 que no es JSON, 3xx, 5xx...) → 502 `respuesta_invalida`.
 *   - antes de llamar: sin frentes → 409 `sin_frentes` (no se registra), sin `api_key` → 409 `sin_api_key`, sin URL → 409 `sin_url`.
 *
 * 🔴 NI `clients.api_key` NI EL TOKEN DE SIMULACIÓN SALEN POR NINGÚN LADO excepto donde es el entregable: el token nuevo viaja sólo en la
 * respuesta de la SIMULACIÓN (es lo que la persona necesita para ejecutar). Todo lo que viene del cliente (mensajes, `detalle`, el cuerpo
 * entero de un 200) pasa por `limpiar_valor()`, que tapa la clave y el token que se mandó; el log y `client_data_resets` llevan sólo
 * conteos, códigos y el `registro_id` de empresa.
 *
 * PHP 7.4 estricto, sin ninguna sintaxis de PHP 8 (nullsafe, match, argumentos nombrados, propiedades de sólo lectura, enums, atributos,
 * union types, promoción en el constructor ni los tipos mixed y never).
 */
class ClientResetDatosService
{
    use TapaLaClaveDelCliente;

    /**
     * Ruta del `empresa-api` que simula y ejecuta el reset, relativa a la base de la API del frente (el resolvedor le agrega `/public`
     * en shared hosting).
     *
     * @var string
     */
    const RUTA_DEL_RESET = 'api/admin-sync/reset-datos-negocio';

    /**
     * Ruta del `empresa-api` que lista los últimos registros del reset (GET).
     *
     * @var string
     */
    const RUTA_DE_REGISTROS = 'api/admin-sync/reset-datos-negocio/registros';

    /**
     * Segundos de espera al SIMULAR (cuenta filas de cientos de tablas).
     *
     * @var int
     */
    const TIMEOUT_SIMULAR_SEGUNDOS = 60;

    /**
     * Segundos de espera al EJECUTAR (respaldo completo + vaciado; mismo orden que el timeout del user setup).
     *
     * @var int
     */
    const TIMEOUT_EJECUTAR_SEGUNDOS = 900;

    /**
     * Segundos de espera al pedir los registros del cliente.
     *
     * @var int
     */
    const TIMEOUT_REGISTROS_SEGUNDOS = 15;

    /**
     * Cuántos registros se listan (del admin y del cliente).
     *
     * @var int
     */
    const LIMITE_DE_REGISTROS = 10;

    /**
     * Quién pide cuando quien llama no lo dice (la puerta de `claude/`).
     *
     * @var string
     */
    const SOLICITANTE_POR_DEFECTO = 'claude';

    /**
     * Largo máximo de `solicitante` (límite del contrato C1).
     *
     * @var int
     */
    const MAX_SOLICITANTE = 60;

    /**
     * Largo máximo de `motivo` (límite del contrato C1).
     *
     * @var int
     */
    const MAX_MOTIVO = 120;

    /**
     * Largo máximo de `confirm_client_name`.
     *
     * @var int
     */
    const MAX_NOMBRE = 190;

    /**
     * Largo máximo de `confirmar_base_de_datos` y de `token_de_simulacion`.
     *
     * @var int
     */
    const MAX_CONFIRMACION = 100;

    /**
     * Los HTTP de empresa que se reenvían con su código de error cuando traen un `error` estable.
     *
     * @var array<int, int>
     */
    const STATUS_QUE_PASAN_CON_SU_CODIGO = [409, 422, 500];

    /**
     * Con qué se reemplaza el token de simulación en un texto que se limpia.
     *
     * @var string
     */
    protected $marca_de_token_oculto = '[token oculto]';

    /**
     * @var ClientEmpresaApiUrlResolver Resuelve el frente del cliente y sus URLs.
     */
    protected $api_url_resolver;

    /**
     * @param ClientEmpresaApiUrlResolver|null $api_url_resolver Inyectable para las pruebas.
     */
    public function __construct(?ClientEmpresaApiUrlResolver $api_url_resolver = null)
    {
        $this->api_url_resolver = $api_url_resolver === null
            ? new ClientEmpresaApiUrlResolver()
            : $api_url_resolver;
    }

    /* ------------------------------------------------------------------------------------------
     | Lectura del pedido (compartida por las dos puertas: `claude/` y el panel)
     |----------------------------------------------------------------------------------------- */

    /**
     * Lee y valida a mano el cuerpo de un pedido. No toca nada ni le pega a nadie.
     *
     * `dry_run` es ESTRICTO: sólo `true`, `false`, `1`, `0` y sus textos. Un valor que no se entiende es un error de validación y NO un
     * `false`: con `$request->boolean()` un `"maybe"` valdría `false`, o sea "ejecutar", que es el lado peligroso del interruptor. Sin él
     * vale `true` (simulación). Si la puerta lo fija (`$dry_run_fijo` distinto de null, como el panel con sus dos rutas), el cuerpo no
     * puede traerlo.
     *
     * Con `dry_run = false` son obligatorios `confirmar_base_de_datos` y `token_de_simulacion` (los que emitió una simulación previa): sin
     * ellos el cliente contestaría `confirmacion_invalida`; se corta acá, antes de llamar y de registrar nada.
     *
     * @param array<string, mixed>  $entrada      Cuerpo del pedido (`$request->all()`).
     * @param array<int, string>    $permitidos   Lista cerrada de claves que esta puerta acepta.
     * @param bool|null             $dry_run_fijo true/false si la puerta decide el modo; null si lo decide el cuerpo.
     *
     * @return array{errores: array<string, string>, pedido: array<string, mixed>}
     */
    public function leer_pedido(array $entrada, array $permitidos, $dry_run_fijo = null)
    {
        $errores = [];

        // Lista cerrada: una clave de más es un error (así nadie le cuela al cliente un parámetro que el contrato no tiene).
        foreach (array_keys($entrada) as $clave_recibida) {
            if (! in_array((string) $clave_recibida, $permitidos, true)) {
                $errores[$this->texto_de_afuera($clave_recibida, 60)] = 'No es un parámetro de este endpoint. Los que acepta: ' . implode(', ', $permitidos) . '.';
            }
        }

        // Modo del pedido: simulación (true) por defecto.
        $dry_run = true;

        if ($dry_run_fijo !== null) {
            $dry_run = (bool) $dry_run_fijo;
        } else {
            $bruto = isset($entrada['dry_run']) ? $entrada['dry_run'] : null;

            if ($bruto !== null && $bruto !== '') {
                $leido = $this->leer_booleano($bruto);

                if ($leido === null) {
                    $errores['dry_run'] = 'Tiene que ser booleano (true, false, 1 o 0). Sin él es true: simula y no borra nada.';
                } else {
                    $dry_run = $leido;
                }
            }
        }

        // Los dos levantadores de frenos: booleanos estrictos, apagados si no vienen.
        $incluir_cae           = $this->leer_flag($entrada, 'incluir_comprobantes_con_cae', $errores);
        $incluir_integraciones = $this->leer_flag($entrada, 'incluir_integraciones_conectadas', $errores);

        // El nombre del cliente es del admin, no viaja a empresa: sólo se valida que sea un texto razonable.
        $nombre = isset($entrada['confirm_client_name']) ? $entrada['confirm_client_name'] : null;

        if ($nombre !== null && (! is_string($nombre) || mb_strlen($nombre) > self::MAX_NOMBRE)) {
            $errores['confirm_client_name'] = 'Tiene que ser un texto de hasta ' . self::MAX_NOMBRE . ' caracteres.';
        }

        $base  = $this->leer_confirmacion($entrada, 'confirmar_base_de_datos', $errores);
        $token = $this->leer_confirmacion($entrada, 'token_de_simulacion', $errores);

        if (! $dry_run) {
            if ($base === null && ! isset($errores['confirmar_base_de_datos'])) {
                $errores['confirmar_base_de_datos'] = 'Es obligatorio con dry_run=false: es el nombre de la base que informó la simulación.';
            }

            if ($token === null && ! isset($errores['token_de_simulacion'])) {
                $errores['token_de_simulacion'] = 'Es obligatorio con dry_run=false: es el token que entregó la simulación (vale 15 minutos y un solo uso).';
            }
        }

        $solicitante = $this->sanear_texto_de_auditoria(isset($entrada['solicitante']) ? $entrada['solicitante'] : null, self::MAX_SOLICITANTE, 'solicitante', $errores);
        $motivo      = $this->sanear_texto_de_auditoria(isset($entrada['motivo']) ? $entrada['motivo'] : null, self::MAX_MOTIVO, 'motivo', $errores);

        return [
            'errores' => $errores,
            'pedido'  => [
                'dry_run'                          => $dry_run,
                'confirmar_base_de_datos'          => $base,
                'token_de_simulacion'              => $token,
                'incluir_comprobantes_con_cae'     => $incluir_cae,
                'incluir_integraciones_conectadas' => $incluir_integraciones,
                'solicitante'                      => $solicitante,
                'motivo'                           => $motivo,
            ],
        ];
    }

    /**
     * Sanea un texto libre que va a quedar en un registro y en un log (`solicitante`, `motivo`).
     *
     * Orden: se descarta lo ausente o vacío (null), se exige un string en UTF-8 válido, se reemplazan por un espacio los caracteres de
     * control (incluidos saltos de línea y los separadores de línea y de párrafo de Unicode: una línea de log no se puede partir ni
     * falsificar con un `\n`), se sacan los invisibles de formato, se colapsan los espacios y se recorta. Recién ahí se mide el largo: un
     * texto más largo que el máximo es un error (no se recorta en silencio, un motivo cortado a la mitad puede decir otra cosa).
     *
     * @param mixed                 $crudo   Lo que vino en el pedido.
     * @param int                   $maximo  Caracteres máximos.
     * @param string                $campo   Nombre del campo (para el error).
     * @param array<string, string> $errores Errores del pedido, por referencia.
     *
     * @return string|null El texto saneado, o null si no vino nada (o si hubo un error).
     */
    public function sanear_texto_de_auditoria($crudo, $maximo, $campo, array &$errores)
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
     * Un booleano estricto: true/false, 1/0 y sus textos. Cualquier otra cosa es null (inválido).
     *
     * @param mixed $valor Valor crudo.
     *
     * @return bool|null
     */
    public function leer_booleano($valor)
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

    /* ------------------------------------------------------------------------------------------
     | El pedido al cliente
     |----------------------------------------------------------------------------------------- */

    /**
     * Le pide la simulación o la ejecución del reset al `empresa-api` del cliente, registra el intento y devuelve lo que hay que
     * contestar.
     *
     * `$pedido` es el que arma `leer_pedido()` más lo que decide la puerta: `origen` (`panel` o `claude`), `solicitante` (obligatorio
     * acá: la puerta le pone el default) y `admin_id` (null por `claude/`).
     *
     * Devuelve siempre `['http_status' => int, 'cuerpo' => array]`: el cuerpo es el de empresa más `client_id` y `frente` si salió bien, o
     * `{ok:false, error, mensaje, message, client_id, frente[, detalle]}` si no.
     *
     * 🔴 Un solo pedido, sin reintentos. Un cliente sin frentes no se registra (no hay a quién se le hubiera hablado).
     *
     * @param Client               $client Cliente.
     * @param array<string, mixed> $pedido Pedido ya leído y validado.
     *
     * @return array{http_status: int, cuerpo: array<string, mixed>}
     */
    public function pedir(Client $client, array $pedido)
    {
        $inicio  = microtime(true);
        $clave   = trim((string) $client->api_key);
        $dry_run = (bool) $pedido['dry_run'];
        $token   = isset($pedido['token_de_simulacion']) ? (string) $pedido['token_de_simulacion'] : '';

        // Sin ningún frente no hay a quién hablarle ni qué registrar.
        if (ClientApi::query()->where('client_id', $client->id)->count() === 0) {
            return $this->respuesta_de_error(
                $client,
                null,
                'sin_frentes',
                409,
                'El cliente no tiene ninguna API (frente) cargada en el admin: no hay a dónde pedir el reset. No se hizo nada.'
            );
        }

        $frente = $this->resolver_el_frente($client, self::RUTA_DEL_RESET);

        // Corte 1: sin api_key no hay con qué firmar el pedido (y el empresa-api no atiende sin ella).
        if ($clave === '') {
            return $this->terminar(
                $client,
                $pedido,
                $inicio,
                $this->respuesta_de_error(
                    $client,
                    $frente['publico'],
                    'sin_api_key',
                    409,
                    'El cliente no tiene api_key en el admin: es la clave con la que el admin le habla a su empresa-api. Corré '
                    . 'POST claude/clients/{id}/catalogo/clave con dry_run=false para generarla y escribirla en sus .env. No se hizo nada.'
                ),
                ClientDataReset::ESTADO_RECHAZADO
            );
        }

        // Corte 2: sin URL de API resoluble (configuración faltante del admin).
        if ($frente['url'] === '') {
            return $this->terminar(
                $client,
                $pedido,
                $inicio,
                $this->respuesta_de_error(
                    $client,
                    $frente['publico'],
                    'sin_url',
                    409,
                    'Este cliente no tiene una URL válida de empresa-api configurada (ClientApi activa o api_url legacy). No se hizo nada.'
                ),
                ClientDataReset::ESTADO_RECHAZADO
            );
        }

        // El cuerpo que sale: la lista cerrada del contrato C1 (el nombre del cliente NO viaja).
        $cuerpo = [
            'dry_run'     => $dry_run,
            'solicitante' => (string) $pedido['solicitante'],
            'origen'      => (string) $pedido['origen'],
        ];

        if ($pedido['motivo'] !== null && $pedido['motivo'] !== '') {
            $cuerpo['motivo'] = (string) $pedido['motivo'];
        }

        // Los levantadores de frenos salen sólo si están prendidos: levantar un freno es siempre un acto explícito.
        if (! empty($pedido['incluir_comprobantes_con_cae'])) {
            $cuerpo['incluir_comprobantes_con_cae'] = true;
        }

        if (! empty($pedido['incluir_integraciones_conectadas'])) {
            $cuerpo['incluir_integraciones_conectadas'] = true;
        }

        // La confirmación sólo viaja al ejecutar: una simulación no usa ni base ni token.
        if (! $dry_run) {
            $cuerpo['confirmar_base_de_datos'] = (string) $pedido['confirmar_base_de_datos'];
            $cuerpo['token_de_simulacion']     = $token;
        }

        try {
            $response = Http::withHeaders([
                    'X-Admin-Api-Key' => $clave,
                    'Accept'          => 'application/json',
                ])
                /* 🔴 Sin seguir redirecciones: Guzzle, al redirigir a otro host, saca `Authorization` pero NO un header propio como
                 * `X-Admin-Api-Key`, y la clave del cliente viajaría a donde apunte la redirección. Un 3xx vuelve como una respuesta
                 * más. */
                ->withOptions(['allow_redirects' => false])
                // Negocio en prueba → IP del VPS sin DNS; cualquier otro cliente, el pedido intacto.
                ->withOptions(app(\App\Services\Prueba\PruebaResolucionLocal::class)->opciones($client, (string) $frente['url']))
                ->timeout($dry_run ? self::TIMEOUT_SIMULAR_SEGUNDOS : self::TIMEOUT_EJECUTAR_SEGUNDOS)
                ->withBody(
                    (string) json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'application/json'
                )
                ->post($frente['url']);
        } catch (\Throwable $e) {
            // ConnectionException, timeout, DNS: no hay respuesta HTTP. Un timeout al ejecutar NO prueba que no se borró nada.
            return $this->terminar(
                $client,
                $pedido,
                $inicio,
                $this->respuesta_de_error(
                    $client,
                    $frente['publico'],
                    'cliente_no_responde',
                    502,
                    $this->limpiar_texto(
                        'No se pudo contactar al empresa-api del cliente (timeout de '
                        . ($dry_run ? self::TIMEOUT_SIMULAR_SEGUNDOS : self::TIMEOUT_EJECUTAR_SEGUNDOS) . ' s o falla de conexión): '
                        . ClientCatalogoPuenteService::sin_la_query_de_las_urls($e->getMessage())
                        . '. No se reintenta: el reset no es idempotente y no se sabe si llegó a correr. Consultá los registros '
                        . '(GET claude/clients/{id}/reset-datos/registros) antes de volver a intentar.',
                        $clave,
                        $token,
                        600
                    )
                ),
                ClientDataReset::ESTADO_SIN_RESPUESTA
            );
        }

        return $this->terminar_con_la_respuesta($client, $pedido, $inicio, $response, $frente['publico'], $clave, $token);
    }

    /**
     * Lo que se contesta en `GET …/reset-datos/registros`: los últimos registros del admin y lo que cuenta el cliente.
     *
     * `cliente` es lo que respondió el `empresa-api` tal cual (ya limpio de secretos), o null si no se pudo preguntar (sin clave, sin
     * URL, timeout, error o respuesta que no es JSON): consultar los registros nunca tira error por culpa del cliente.
     *
     * @param Client $client Cliente.
     *
     * @return array{client_id: int, admin: array<int, array<string, mixed>>, cliente: array<string, mixed>|null}
     */
    public function registros(Client $client)
    {
        $admin = ClientDataReset::query()
            ->where('client_id', $client->id)
            ->orderByDesc('id')
            ->limit(self::LIMITE_DE_REGISTROS)
            ->get()
            ->toArray();

        return [
            'client_id' => (int) $client->id,
            'admin'     => $admin,
            'cliente'   => $this->registros_del_cliente($client),
        ];
    }

    /**
     * Le pregunta al `empresa-api` del cliente sus últimos registros del reset (C1 `GET …/registros?limit=10`).
     *
     * @param Client $client Cliente.
     *
     * @return array<string, mixed>|null El cuerpo limpio, o null si no se pudo.
     */
    protected function registros_del_cliente(Client $client)
    {
        $clave = trim((string) $client->api_key);

        if ($clave === '' || ClientApi::query()->where('client_id', $client->id)->count() === 0) {
            return null;
        }

        $frente = $this->resolver_el_frente($client, self::RUTA_DE_REGISTROS);

        if ($frente['url'] === '') {
            return null;
        }

        try {
            $response = Http::withHeaders([
                    'X-Admin-Api-Key' => $clave,
                    'Accept'          => 'application/json',
                ])
                ->withOptions(['allow_redirects' => false])
                ->withOptions(app(\App\Services\Prueba\PruebaResolucionLocal::class)->opciones($client, (string) $frente['url']))
                ->timeout(self::TIMEOUT_REGISTROS_SEGUNDOS)
                ->get($frente['url'], ['limit' => self::LIMITE_DE_REGISTROS]);
        } catch (\Throwable $e) {
            // Sin respuesta: se contesta sin lo del cliente (no se loguea el mensaje: trae la URL y puede traer lo que sea).
            return null;
        }

        $decodificado = json_decode((string) $response->body(), true);

        if ((int) $response->status() !== 200 || ! is_array($decodificado)) {
            return null;
        }

        return $this->limpiar_valor($decodificado, $clave, '');
    }

    /* ------------------------------------------------------------------------------------------
     | Traducción de lo que contestó el cliente
     |----------------------------------------------------------------------------------------- */

    /**
     * Arma el resultado a partir de lo que contestó el cliente y lo registra.
     *
     * @param Client                    $client  Cliente.
     * @param array<string, mixed>      $pedido  Pedido que salió.
     * @param float                     $inicio  Instante de arranque (`microtime(true)`).
     * @param \Illuminate\Http\Client\Response $response Respuesta del cliente.
     * @param array<string, mixed>      $frente  El frente público (lo que sale en la respuesta).
     * @param string                    $clave   `clients.api_key` (a tapar en cualquier texto).
     * @param string                    $token   El token de simulación que se mandó (a tapar; '' en una simulación).
     *
     * @return array{http_status: int, cuerpo: array<string, mixed>}
     */
    protected function terminar_con_la_respuesta(Client $client, array $pedido, $inicio, $response, array $frente, $clave, $token)
    {
        $status = (int) $response->status();

        // El cuerpo se decodifica y NO se vuelve a usar como texto: pasa limpio por `limpiar_valor()`.
        $decodificado = json_decode((string) $response->body(), true);
        $datos        = is_array($decodificado) ? $decodificado : [];
        $codigo       = isset($datos['error']) && is_string($datos['error']) ? $datos['error'] : null;

        if ($status === 200) {
            if (($datos['ok'] ?? null) !== true) {
                return $this->terminar(
                    $client,
                    $pedido,
                    $inicio,
                    $this->respuesta_de_error(
                        $client,
                        $frente,
                        'respuesta_invalida',
                        502,
                        'El empresa-api del cliente contestó 200 pero no con el cuerpo del contrato del reset (JSON con ok = true). '
                        . 'No se sabe qué pasó: consultá los registros antes de volver a intentar.'
                    ),
                    ClientDataReset::ESTADO_ERROR
                );
            }

            $limpio = $this->limpiar_valor($datos, $clave, $token);
            $estado = (! $pedido['dry_run'] && ($limpio['estado'] ?? null) === ClientDataReset::ESTADO_OK_CON_ADVERTENCIAS)
                ? ClientDataReset::ESTADO_OK_CON_ADVERTENCIAS
                : ClientDataReset::ESTADO_OK;

            return $this->terminar(
                $client,
                $pedido,
                $inicio,
                [
                    'http_status' => 200,
                    'cuerpo'      => array_merge($limpio, ['client_id' => (int) $client->id, 'frente' => $frente]),
                ],
                $estado,
                null,
                $this->resumen_de_un_200($limpio)
            );
        }

        if ($status === 401) {
            return $this->terminar(
                $client,
                $pedido,
                $inicio,
                $this->respuesta_de_error(
                    $client,
                    $frente,
                    'clave_rechazada',
                    409,
                    'El empresa-api del cliente rechazó la clave del admin (401): su ADMIN_API_INBOUND_KEY no coincide con la clients.api_key '
                    . 'del admin, o no la tiene cargada. Corré POST claude/clients/{id}/catalogo/clave con dry_run=false para dejarla igual en '
                    . 'sus .env. No se borró nada.'
                ),
                ClientDataReset::ESTADO_RECHAZADO
            );
        }

        // 404 o 405 SIN `error` = los de Laravel: la ruta no existe en la versión del cliente. Con `error` es una respuesta de la app.
        if (($status === 404 || $status === 405) && $codigo === null) {
            return $this->terminar(
                $client,
                $pedido,
                $inicio,
                $this->respuesta_de_error(
                    $client,
                    $frente,
                    'version_vieja',
                    409,
                    'El sistema de este cliente todavía no tiene el reset de datos (su versión es anterior a esta función; contestó ' . $status
                    . ' sin código): actualizá el sistema del cliente a una versión que lo traiga. No se borró nada.'
                ),
                ClientDataReset::ESTADO_RECHAZADO
            );
        }

        // Un freno o una falla del cliente con su código estable: pasa con el MISMO código y el MISMO status.
        if (in_array($status, self::STATUS_QUE_PASAN_CON_SU_CODIGO, true) && $codigo !== null && preg_match('/^[a-z0-9_]{1,60}$/D', $codigo) === 1) {
            $mensaje = isset($datos['mensaje']) && is_string($datos['mensaje']) && $datos['mensaje'] !== ''
                ? $datos['mensaje']
                : 'El empresa-api del cliente contestó ' . $codigo . '.';

            $extra = [];

            if (isset($datos['detalle']) && is_array($datos['detalle'])) {
                $extra['detalle'] = $this->limpiar_valor($datos['detalle'], $clave, $token);
            }

            return $this->terminar(
                $client,
                $pedido,
                $inicio,
                $this->respuesta_de_error($client, $frente, $codigo, $status, $this->limpiar_texto($mensaje, $clave, $token, 600), $extra),
                $status === 500 ? ClientDataReset::ESTADO_ERROR : ClientDataReset::ESTADO_RECHAZADO
            );
        }

        // Cualquier otra cosa: se informa el status y NADA del cuerpo.
        $detalle = ($status >= 300 && $status < 400)
            ? ' Es una redirección y el admin no las sigue (para no mandar la clave del cliente a otro host): revisá la URL de la API del frente.'
            : '';

        return $this->terminar(
            $client,
            $pedido,
            $inicio,
            $this->respuesta_de_error(
                $client,
                $frente,
                'respuesta_invalida',
                502,
                'El empresa-api del cliente contestó HTTP ' . $status . ', que no es parte del contrato del reset.' . $detalle
                . ' Si era una ejecución, consultá los registros antes de volver a intentar.'
            ),
            ClientDataReset::ESTADO_ERROR
        );
    }

    /**
     * Un resumen del 200 del cliente para el registro: sólo conteos y datos del respaldo, nunca nada secreto.
     *
     * @param array<string, mixed> $cuerpo Cuerpo del 200 (ya limpio).
     *
     * @return array<string, mixed>
     */
    protected function resumen_de_un_200(array $cuerpo)
    {
        $resumen = [
            'total_filas' => isset($cuerpo['total_filas']) && is_numeric($cuerpo['total_filas']) ? (int) $cuerpo['total_filas'] : null,
            'registro_id' => isset($cuerpo['registro_id']) && is_numeric($cuerpo['registro_id']) ? (int) $cuerpo['registro_id'] : null,
        ];

        if (isset($cuerpo['a_vaciar']) && is_array($cuerpo['a_vaciar'])) {
            $resumen['tablas_con_filas'] = $this->contar_tablas_con_filas($cuerpo['a_vaciar']);
            $resumen['ejecutable']       = (bool) ($cuerpo['ejecutable'] ?? false);
            $resumen['bloqueos']         = [];

            if (isset($cuerpo['bloqueos']) && is_array($cuerpo['bloqueos'])) {
                foreach ($cuerpo['bloqueos'] as $bloqueo) {
                    if (is_array($bloqueo) && isset($bloqueo['codigo']) && is_string($bloqueo['codigo'])) {
                        $resumen['bloqueos'][] = mb_substr($bloqueo['codigo'], 0, 60);
                    }
                }
            }
        }

        if (isset($cuerpo['vaciadas']) && is_array($cuerpo['vaciadas'])) {
            $resumen['tablas_con_filas'] = $this->contar_tablas_con_filas($cuerpo['vaciadas']);
        }

        if (isset($cuerpo['respaldo']) && is_array($cuerpo['respaldo'])) {
            $resumen['respaldo'] = [
                'ruta_relativa' => isset($cuerpo['respaldo']['ruta_relativa']) ? mb_substr((string) $cuerpo['respaldo']['ruta_relativa'], 0, 200) : null,
                'bytes'         => isset($cuerpo['respaldo']['bytes']) && is_numeric($cuerpo['respaldo']['bytes']) ? (int) $cuerpo['respaldo']['bytes'] : null,
            ];
        }

        if (isset($cuerpo['archivos']['errores']) && is_array($cuerpo['archivos']['errores'])) {
            $resumen['archivos_con_error'] = count($cuerpo['archivos']['errores']);
        }

        return $resumen;
    }

    /**
     * Cuántas tablas tienen al menos una fila en un mapa `tabla => filas`.
     *
     * @param array<string, mixed> $conteos Mapa de conteos.
     *
     * @return int
     */
    protected function contar_tablas_con_filas(array $conteos)
    {
        $con_filas = 0;

        foreach ($conteos as $filas) {
            if (is_numeric($filas) && (int) $filas > 0) {
                $con_filas++;
            }
        }

        return $con_filas;
    }

    /* ------------------------------------------------------------------------------------------
     | Registro y log
     |----------------------------------------------------------------------------------------- */

    /**
     * Cierra un pedido: lo registra (fila + línea de log) y devuelve el resultado tal cual.
     *
     * 🔴 El registro NUNCA tira el pedido abajo: si la ejecución ya borró datos, perder la respuesta por una falla al escribir el
     * rastro sería peor que no tener el rastro. Una falla del registro va al log sin ningún dato del pedido.
     *
     * @param Client                    $client    Cliente.
     * @param array<string, mixed>      $pedido    Pedido que salió.
     * @param float                     $inicio    Instante de arranque (`microtime(true)`).
     * @param array<string, mixed>      $resultado `['http_status', 'cuerpo']` a devolver.
     * @param string                    $estado    Uno de los `ClientDataReset::ESTADO_*`.
     * @param string|null               $codigo    Código del error; si es null se saca del cuerpo (`error`).
     * @param array<string, mixed>|null $resumen   Resumen del 200; si es null se arma uno con el mensaje del error.
     *
     * @return array{http_status: int, cuerpo: array<string, mixed>}
     */
    protected function terminar(Client $client, array $pedido, $inicio, array $resultado, $estado, $codigo = null, $resumen = null)
    {
        $cuerpo = $resultado['cuerpo'];
        $codigo = $codigo !== null ? $codigo : (isset($cuerpo['error']) ? (string) $cuerpo['error'] : null);
        $frente = isset($cuerpo['frente']) && is_array($cuerpo['frente']) ? $cuerpo['frente'] : null;

        // El resumen de un error es su propio mensaje (ya limpio): dice qué pasó sin repetir nada del cuerpo del cliente.
        if ($resumen === null && isset($cuerpo['mensaje'])) {
            $resumen = ['mensaje' => mb_substr((string) $cuerpo['mensaje'], 0, 300)];
        }

        $clave = trim((string) $client->api_key);
        $token = isset($pedido['token_de_simulacion']) ? (string) $pedido['token_de_simulacion'] : '';
        $ms    = (int) round((microtime(true) - $inicio) * 1000);

        try {
            ClientDataReset::query()->create([
                'client_id'     => (int) $client->id,
                'client_api_id' => $frente !== null ? $frente['client_api_id'] : null,
                'admin_id'      => isset($pedido['admin_id']) ? $pedido['admin_id'] : null,
                'origen'        => (string) $pedido['origen'],
                'solicitante'   => $this->limpiar_texto((string) $pedido['solicitante'], $clave, $token, self::MAX_SOLICITANTE),
                'motivo'        => $pedido['motivo'] === null ? null : $this->limpiar_texto((string) $pedido['motivo'], $clave, $token, self::MAX_MOTIVO),
                'dry_run'       => (bool) $pedido['dry_run'],
                'estado'        => $estado,
                'codigo'        => $codigo,
                'http_status'   => (int) $resultado['http_status'],
                'resumen'       => $resumen,
            ]);
        } catch (\Throwable $e) {
            Log::error('ClientResetDatosService: no se pudo guardar el registro del reset.', [
                'client_id' => (int) $client->id,
                'excepcion' => get_class($e),
            ]);
        }

        /* 🔴 La línea de log lleva SOLO ids, origen, modo, estado, código, status y milisegundos: nunca el cuerpo que contestó el cliente
           (ahí viaja el token de una simulación), nunca la clave. `solicitante` y `motivo` pasan por el tapador por si alguien pega una. */
        $contexto = [
            'client_id'     => (int) $client->id,
            'client_api_id' => $frente !== null ? $frente['client_api_id'] : null,
            'origen'        => (string) $pedido['origen'],
            'solicitante'   => $this->limpiar_texto((string) $pedido['solicitante'], $clave, $token, self::MAX_SOLICITANTE),
            'dry_run'       => (bool) $pedido['dry_run'],
            'estado'        => $estado,
            'codigo'        => $codigo,
            'http_status'   => (int) $resultado['http_status'],
            'ms'            => $ms,
        ];

        if ($estado === ClientDataReset::ESTADO_OK || $estado === ClientDataReset::ESTADO_OK_CON_ADVERTENCIAS) {
            Log::info('ClientResetDatosService: pedido de reset de datos.', $contexto);
        } else {
            Log::warning('ClientResetDatosService: pedido de reset de datos.', $contexto);
        }

        return $resultado;
    }

    /* ------------------------------------------------------------------------------------------
     | Ayudas
     |----------------------------------------------------------------------------------------- */

    /**
     * La respuesta de error en la forma del contrato C2: `{ok:false, error, mensaje, message, client_id, frente[, detalle]}`.
     * `message` duplica `mensaje` para el interceptor del SPA.
     *
     * @param Client                    $client  Cliente.
     * @param array<string, mixed>|null $frente  El frente público (null si no se llegó a resolver).
     * @param string                    $codigo  Código estable.
     * @param int                       $status  HTTP que se contesta.
     * @param string                    $mensaje Texto para leer (sin clave ni token).
     * @param array<string, mixed>      $extra   Claves extra del cuerpo (`detalle`).
     *
     * @return array{http_status: int, cuerpo: array<string, mixed>}
     */
    protected function respuesta_de_error(Client $client, $frente, $codigo, $status, $mensaje, array $extra = [])
    {
        return [
            'http_status' => (int) $status,
            'cuerpo'      => array_merge([
                'ok'        => false,
                'error'     => $codigo,
                'mensaje'   => $mensaje,
                'message'   => $mensaje,
                'client_id' => (int) $client->id,
                'frente'    => $this->frente_para_responder($frente),
            ], $extra),
        ];
    }

    /**
     * Resuelve EL frente del cliente: la URL del endpoint pedido y el frente público (id, tipo y host de la API).
     *
     * @param Client $client Cliente.
     * @param string $ruta   Ruta del endpoint de empresa, relativa a la base de la API.
     *
     * @return array{url: string, publico: array{client_api_id: int|null, hosting_type: string|null, api_host: string|null}}
     */
    protected function resolver_el_frente(Client $client, $ruta)
    {
        $api = $this->api_url_resolver->resolve_client_api($client);
        $url = $this->api_url_resolver->admin_sync_url($client, $ruta);

        if ($api instanceof ClientApi) {
            $client_api_id = (int) $api->id;
            $hosting_type  = $api->hosting_type ? (string) $api->hosting_type : 'shared_hosting';
        } else {
            // Ganó el `clients.api_url` legacy (o no hay URL): no hay un frente del cual tomar los datos.
            $client_api_id = null;
            $hosting_type  = null;
        }

        return [
            'url'     => $url,
            'publico' => [
                'client_api_id' => $client_api_id,
                'hosting_type'  => $hosting_type,
                'api_host'      => $this->host_de($url),
            ],
        ];
    }

    /**
     * El frente en la forma del contrato: siempre las mismas tres claves, con null en lo que no se pudo resolver.
     *
     * @param array<string, mixed>|null $frente Lo que resolvió `resolver_el_frente()`.
     *
     * @return array{client_api_id: int|null, hosting_type: string|null, api_host: string|null}
     */
    protected function frente_para_responder($frente)
    {
        $frente = is_array($frente) ? $frente : [];

        return [
            'client_api_id' => isset($frente['client_api_id']) ? (int) $frente['client_api_id'] : null,
            'hosting_type'  => isset($frente['hosting_type']) ? (string) $frente['hosting_type'] : null,
            'api_host'      => isset($frente['api_host']) ? (string) $frente['api_host'] : null,
        ];
    }

    /**
     * El host de una URL (con el puerto si no es el de default), sin ruta ni query. Null si no se puede sacar.
     *
     * Es lo único de la URL de la API que sale en la respuesta y en el log.
     *
     * @param string $url URL absoluta.
     *
     * @return string|null
     */
    protected function host_de($url)
    {
        if ($url === '') {
            return null;
        }

        $partes = parse_url($url);

        if (! is_array($partes) || empty($partes['host'])) {
            return null;
        }

        return (string) $partes['host'] . (isset($partes['port']) ? ':' . $partes['port'] : '');
    }

    /**
     * Limpia un texto que viene (o va a salir hacia) afuera: tapa la clave del cliente y el token de simulación, lo deja en UTF-8
     * válido y lo recorta. Se tapa sobre el texto ENTERO y recién después se recorta (una clave partida en el borde del recorte no
     * coincidiría con nada y saldría a la vista a medias).
     *
     * @param string $texto  Texto a limpiar.
     * @param string $clave  `clients.api_key`.
     * @param string $token  Token de simulación que se mandó ('' si no hay).
     * @param int    $limite Caracteres máximos que salen.
     *
     * @return string
     */
    protected function limpiar_texto($texto, $clave, $token, $limite)
    {
        $tapado = $this->tapar_clave($texto, $clave);

        if (strlen($token) >= $this->largo_minimo_de_clave_a_tapar) {
            $tapado = str_replace($token, $this->marca_de_token_oculto, $tapado);
        }

        return mb_substr(mb_convert_encoding($tapado, 'UTF-8', 'UTF-8'), 0, $limite);
    }

    /**
     * Limpia recursivamente todo lo que vino del cliente (cuerpo de un 200, `detalle` de un error, registros): cada texto pasa por
     * `limpiar_texto()`; los números, booleanos y null quedan como están y las claves de los arrays no se tocan.
     *
     * @param mixed  $valor Valor decodificado del JSON del cliente.
     * @param string $clave `clients.api_key`.
     * @param string $token Token de simulación que se mandó ('' si no hay).
     *
     * @return mixed
     */
    protected function limpiar_valor($valor, $clave, $token)
    {
        if (is_string($valor)) {
            return $this->limpiar_texto($valor, $clave, $token, 5000);
        }

        if (is_array($valor)) {
            $limpio = [];

            foreach ($valor as $llave => $contenido) {
                $limpio[$llave] = $this->limpiar_valor($contenido, $clave, $token);
            }

            return $limpio;
        }

        return $valor;
    }

    /**
     * Un texto que vino de AFUERA (el nombre de un parámetro), listo para repetirlo en una respuesta: UTF-8 válido y recortado.
     *
     * @param mixed $texto  Lo que vino de afuera.
     * @param int   $limite Caracteres máximos.
     *
     * @return string
     */
    protected function texto_de_afuera($texto, $limite)
    {
        return mb_substr(mb_convert_encoding((string) $texto, 'UTF-8', 'UTF-8'), 0, $limite);
    }

    /**
     * Lee un flag booleano opcional del cuerpo: apagado si no viene; si viene mal, error de validación.
     *
     * @param array<string, mixed>  $entrada Cuerpo del pedido.
     * @param string                $campo   Nombre del flag.
     * @param array<string, string> $errores Errores del pedido, por referencia.
     *
     * @return bool
     */
    protected function leer_flag(array $entrada, $campo, array &$errores)
    {
        $bruto = isset($entrada[$campo]) ? $entrada[$campo] : null;

        if ($bruto === null || $bruto === '') {
            return false;
        }

        $leido = $this->leer_booleano($bruto);

        if ($leido === null) {
            $errores[$campo] = 'Tiene que ser booleano (true, false, 1 o 0). Sin él es false: el freno queda puesto.';

            return false;
        }

        return $leido;
    }

    /**
     * Lee un texto de confirmación opcional (`confirmar_base_de_datos`, `token_de_simulacion`): texto sin espacios ni caracteres de
     * control, de hasta `MAX_CONFIRMACION`. Vacío o ausente es null.
     *
     * @param array<string, mixed>  $entrada Cuerpo del pedido.
     * @param string                $campo   Nombre del campo.
     * @param array<string, string> $errores Errores del pedido, por referencia.
     *
     * @return string|null
     */
    protected function leer_confirmacion(array $entrada, $campo, array &$errores)
    {
        $bruto = isset($entrada[$campo]) ? $entrada[$campo] : null;

        if ($bruto === null || $bruto === '') {
            return null;
        }

        if (! is_string($bruto) || mb_strlen($bruto) > self::MAX_CONFIRMACION || preg_match('/^[^\s\p{Cc}]+$/u', $bruto) !== 1) {
            $errores[$campo] = 'Tiene que ser un texto sin espacios de hasta ' . self::MAX_CONFIRMACION . ' caracteres.';

            return null;
        }

        return $bruto;
    }
}
