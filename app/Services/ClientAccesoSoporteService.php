<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientApi;
use App\Services\Concerns\TapaLaClaveDelCliente;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * El ACCESO DE SOPORTE firmado al sistema de un cliente (misión cruzada `acceso-soporte-firmado`, 30/10/2026:
 * `POST claude/clients/{id}/acceso-soporte`).
 *
 * 🔴 QUÉ RESUELVE. Hasta ahora, para que un agente (Claude Code, Codex) entrara al sistema de un cliente, Lucas tenía que iniciar
 * sesión a mano en cada uno y guardar la sesión (`guardar-sesion.ps1`). Con esto el agente le pide al ADMIN un acceso, el admin se lo
 * pide al `empresa-api` del cliente FIRMANDO con la clave que ya comparten (`clients.api_key` = `ADMIN_API_INBOUND_KEY`, header
 * `X-Admin-Api-Key`) y el `empresa-api` devuelve un token de UN SOLO USO que vence a los pocos minutos. Con ese token el admin arma la
 * URL de entrada del SPA del cliente. Nadie escribe una contraseña y ninguna clave pasa por la máquina de Lucas.
 *
 * EL CONTRATO con el `empresa-api` (C1 del plan de la misión, `POST {api_base}/api/admin-sync/acceso-soporte`):
 *
 *   - Pedido: headers `X-Admin-Api-Key` y `Accept: application/json`; cuerpo JSON `{solicitante, motivo?}`.
 *   - 200 `{ok, token (64 alfanuméricos), vence_en_segundos (entero), usuario_id}`  → `ok`.
 *   - 401 (clave mala, ausente, o instancia sin clave cargada)                       → `clave_rechazada`.
 *   - 403 con `code = acceso_soporte_desactivado`                                    → `acceso_desactivado`.
 *   - 409 con `code = dueno_no_resoluble`                                            → `dueno_no_resoluble`.
 *   - 404 o 405 SIN `code` (los de Laravel: la ruta no existe)                       → `version_vieja`.
 *   - timeout o falla de conexión                                                    → `no_responde`.
 *   - cualquier otra cosa (incluido un 200 sin token válido y un 3xx)                → `respuesta_invalida`.
 *
 * 🔴 EL FRENTE. Un cliente puede tener más de un frente (`ClientApi`): el admin le habla al que resuelve
 * `ClientEmpresaApiUrlResolver::resolve_client_api()` (el mismo al que le pega el puente de admin-sync y todo lo saliente). El token
 * que emite ESE `empresa-api` solo vale en ESE frente (su base de datos, su tabla `acceso_soporte_tokens`), así que la URL de entrada
 * se arma con el `spa_url` de ESE mismo frente y NUNCA con el de otro: el SPA de un frente le llevaría el token a una API que no lo
 * emitió, y el acceso fallaría con un "token inválido" sin explicación. Por eso, si el frente ganador no trae un `spa_url` válido, el
 * resultado es `sin_spa_url` y no se cae al SPA de otro frente.
 *
 * La única excepción es cuando la URL de la API la ganó el valor legacy `clients.api_url` (no hay `ClientApi` detrás): ahí no existe un
 * "SPA del mismo frente" que mirar y se usa `resolve_spa_url()` como respaldo, que es el criterio histórico del admin para ese caso.
 *
 * 🔴 EL TOKEN VA EN EL FRAGMENTO (`#t=`), NO EN LA QUERY. La URL que devuelve este servicio es `<spa>/acceso-soporte#t=<token>`: el
 * fragmento no viaja al servidor, así que no queda en los access logs del hosting ni en el `Referer`. El SPA lo lee y lo saca de la
 * barra al instante.
 *
 * 🔴 NADA DE LO QUE SALE DE ACÁ LLEVA `clients.api_key` NI EL TOKEN, salvo el campo `url` de la respuesta de éxito (que es el
 * entregable). El log lleva SOLO client_id, client_api_id, solicitante, motivo, host del frente, estado y milisegundos: nunca el cuerpo
 * que contestó el cliente (donde viaja el token) ni la clave. Los textos que vuelven (el mensaje de una falla de red) pasan por
 * `tapar_clave()` antes de salir.
 *
 * 🔴 SIN REDIRECCIONES. Guzzle, al redirigir a otro host, saca `Authorization` pero NO un header propio como `X-Admin-Api-Key`: un 3xx
 * del cliente vuelve como una respuesta más (`respuesta_invalida`) y no se sigue, para que la clave del cliente no viaje a donde apunte
 * la redirección.
 *
 * 🔴 SIN REINTENTOS. Emitir un acceso NO es idempotente (cada pedido deja una fila de auditoría y un token vivo en el cliente): un
 * timeout no se reintenta, lo decide quien llama. Timeout corto (15 s): la ruta del cliente solo resuelve al dueño y guarda un hash.
 *
 * `dry_run` NO le pega al cliente: solo resuelve y devuelve el frente (dónde se pediría el acceso) y si está `listo`.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, promoción en constructor, `readonly`, `enum`,
 * atributos, `mixed` ni `never`.
 */
class ClientAccesoSoporteService
{
    use TapaLaClaveDelCliente;

    /**
     * Ruta del `empresa-api` que emite el acceso, relativa a la base de la API del frente (el resolvedor le agrega `/public` en
     * shared hosting).
     *
     * @var string
     */
    const RUTA_DE_EMISION = 'api/admin-sync/acceso-soporte';

    /**
     * Ruta del SPA del cliente que canjea el token (ruta pública `/acceso-soporte`, `AccesoSoporte.vue`).
     *
     * @var string
     */
    const RUTA_DEL_SPA = '/acceso-soporte';

    /**
     * Segundos de espera de la llamada al cliente. Corto a propósito: emitir es resolver al dueño y guardar un hash.
     *
     * @var int
     */
    const TIMEOUT_SEGUNDOS = 15;

    /**
     * Quién pide el acceso cuando quien llama no lo dice.
     *
     * @var string
     */
    const SOLICITANTE_POR_DEFECTO = 'claude';

    /**
     * Largo máximo de `solicitante` (el límite del contrato C1/C3).
     *
     * @var int
     */
    const MAX_SOLICITANTE = 60;

    /**
     * Largo máximo de `motivo` (el límite del contrato C1/C3).
     *
     * @var int
     */
    const MAX_MOTIVO = 120;

    /** Dry run: se sabe a qué frente se le pediría el acceso y no falta nada. No se le pegó al cliente. */
    const ESTADO_LISTO = 'listo';

    /** El cliente contestó con un token válido: el acceso está emitido. */
    const ESTADO_OK = 'ok';

    /** El cliente no tiene `api_key` en el admin: no hay con qué firmar el pedido. */
    const ESTADO_SIN_API_KEY = 'sin_api_key';

    /** No hay una URL válida de `empresa-api` para el cliente. */
    const ESTADO_SIN_URL = 'sin_url';

    /** El frente no tiene un `spa_url` válido: no hay a dónde mandar a la persona con el token. */
    const ESTADO_SIN_SPA_URL = 'sin_spa_url';

    /** El `empresa-api` del cliente contestó 404/405 sin `code`: su versión todavía no tiene la ruta. */
    const ESTADO_VERSION_VIEJA = 'version_vieja';

    /** El `empresa-api` del cliente contestó 401: su `ADMIN_API_INBOUND_KEY` no coincide con `clients.api_key` (o no está cargada). */
    const ESTADO_CLAVE_RECHAZADA = 'clave_rechazada';

    /** El acceso de soporte está apagado en el `.env` del cliente (`ACCESO_SOPORTE_HABILITADO=false`). */
    const ESTADO_ACCESO_DESACTIVADO = 'acceso_desactivado';

    /** El `empresa-api` no pudo decidir cuál es el dueño al que dejar entrar. */
    const ESTADO_DUENO_NO_RESOLUBLE = 'dueno_no_resoluble';

    /** Timeout o conexión: el admin no llegó al cliente. */
    const ESTADO_NO_RESPONDE = 'no_responde';

    /** El cliente contestó algo que no es el contrato (un 200 sin token válido, un 3xx, un 5xx...). */
    const ESTADO_RESPUESTA_INVALIDA = 'respuesta_invalida';

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

    /**
     * Pide (o simula pedir) un acceso de soporte al sistema del cliente.
     *
     * Resultado, siempre con las mismas claves:
     *   - `estado`: uno de los `ESTADO_*`.
     *   - `mensaje`: el motivo, cuando el estado no es `listo` ni `ok` (texto para leer; sin clave ni token).
     *   - `frente`: `{client_api_id, hosting_type, api_host, spa_url}` con lo que se pudo resolver (null en lo que no). Viene siempre,
     *     también en un corte por configuración.
     *   - `url`, `vence_en_segundos`, `expira_en`, `usuario_id`: solo con `ok` (null en los demás estados).
     *
     * 🔴 `dry_run = true` NO hace ningún pedido HTTP y devuelve `listo` o uno de los tres cortes de configuración (`sin_api_key`,
     * `sin_url`, `sin_spa_url`). Con `dry_run = false` hace UN pedido (sin reintentos) y devuelve cualquiera de los estados.
     *
     * @param Client      $client      Cliente al que se le pide el acceso.
     * @param bool        $dry_run     true: solo resolver y decir si está listo.
     * @param string      $solicitante Quién pide el acceso (ya saneado; ≤ 60).
     * @param string|null $motivo      Para qué (ya saneado; ≤ 120), o null.
     *
     * @return array{estado: string, mensaje: string|null, frente: array|null, url: string|null, vence_en_segundos: int|null, expira_en: string|null, usuario_id: int|null}
     */
    public function ejecutar(Client $client, $dry_run, $solicitante, $motivo = null)
    {
        $inicio = microtime(true);
        $clave  = trim((string) $client->api_key);

        /* El frente se resuelve ANTES de los cortes, aunque falte la clave: resolverlo no toca la red, y así hasta un corte por
           configuración informa a qué frente se le hubiera pedido el acceso (lo que un dry_run viene a mostrar). El ORDEN de los cortes
           no cambia: primero la clave, después la URL de la API, después el SPA. */
        $frente = $this->resolver_el_frente($client);

        // Corte 1: sin api_key. Sin la clave compartida no hay con qué firmar el pedido (y el empresa-api no emite sin ella).
        if ($clave === '') {
            return $this->cortar(
                $client,
                self::ESTADO_SIN_API_KEY,
                'El cliente no tiene api_key en el admin: es la clave con la que el admin le habla a su empresa-api y con la que se firma '
                . 'el pedido de acceso. Corré POST claude/clients/{id}/catalogo/clave con dry_run=false para generarla y escribirla en sus .env.',
                $frente['publico'],
                $dry_run,
                $solicitante,
                $motivo,
                $inicio
            );
        }

        // Corte 2: sin URL de API resoluble. Es configuración faltante del admin, no del cliente.
        if ($frente['url_de_emision'] === '') {
            return $this->cortar(
                $client,
                self::ESTADO_SIN_URL,
                'Este cliente no tiene una URL válida de empresa-api configurada (ClientApi activa o api_url legacy).',
                $frente['publico'],
                $dry_run,
                $solicitante,
                $motivo,
                $inicio
            );
        }

        // Corte 3: sin SPA del MISMO frente. El token solo vale en el frente que lo emitió: no se cae al SPA de otro.
        if ($frente['publico']['spa_url'] === null) {
            return $this->cortar(
                $client,
                self::ESTADO_SIN_SPA_URL,
                'El frente al que le habla el admin no tiene un spa_url válido: sin él no hay a dónde mandar a la persona con el acceso. '
                . 'Cargalo en la ClientApi del cliente. No se usa el SPA de otro frente: el token solo vale en el frente que lo emitió.',
                $frente['publico'],
                $dry_run,
                $solicitante,
                $motivo,
                $inicio
            );
        }

        // Dry run: llegó hasta acá, o sea que hay clave, frente y SPA. No se le pega al cliente.
        if ($dry_run) {
            return $this->resultado(self::ESTADO_LISTO, null, $frente['publico']);
        }

        $respuesta = $this->pedir_el_acceso($client, $clave, $frente, $solicitante, $motivo);

        $this->registrar($client, $frente['publico'], $solicitante, $motivo, $respuesta['estado'], $inicio);

        return $respuesta;
    }

    /**
     * Resuelve EL frente del cliente: la `ClientApi` a la que le habla el admin, la URL de emisión de ESE frente, y el `spa_url` de ESE
     * frente.
     *
     * La API y la URL salen de los mismos candidatos y en el mismo orden (`base_url_candidates()` del resolvedor las comparte), así que
     * `resolve_client_api()` y `admin_sync_url()` hablan del mismo frente.
     *
     * @param Client $client Cliente.
     *
     * @return array{url_de_emision: string, publico: array{client_api_id: int|null, hosting_type: string|null, api_host: string|null, spa_url: string|null}}
     */
    protected function resolver_el_frente(Client $client)
    {
        $api            = $this->api_url_resolver->resolve_client_api($client);
        $url_de_emision = $this->api_url_resolver->admin_sync_url($client, self::RUTA_DE_EMISION);

        if ($api instanceof ClientApi) {
            // El SPA del MISMO frente. Si éste no lo trae, queda null: no se busca en otro.
            $spa_url       = $this->normalizar_spa_url($api->spa_url);
            $client_api_id = (int) $api->id;
            $hosting_type  = $api->hosting_type ? (string) $api->hosting_type : 'shared_hosting';
        } else {
            // Ganó el `clients.api_url` legacy (o no hay URL): no hay un frente del cual tomar el SPA. Respaldo histórico del admin.
            $spa_url       = $this->normalizar_spa_url($this->api_url_resolver->resolve_spa_url($client));
            $client_api_id = null;
            $hosting_type  = null;
        }

        return [
            'url_de_emision' => $url_de_emision,
            'publico'        => [
                'client_api_id' => $client_api_id,
                'hosting_type'  => $hosting_type,
                'api_host'      => $this->host_de($url_de_emision),
                'spa_url'       => $spa_url === '' ? null : $spa_url,
            ],
        ];
    }

    /**
     * Le pide el acceso al `empresa-api` del cliente y arma el resultado según lo que contestó. UN pedido, sin reintentos.
     *
     * @param Client               $client      Cliente.
     * @param string               $clave       `clients.api_key` (ya recortada y no vacía).
     * @param array<string, mixed> $frente      Lo que devolvió `resolver_el_frente()` (con `spa_url` válido).
     * @param string               $solicitante Quién pide.
     * @param string|null          $motivo      Para qué.
     *
     * @return array{estado: string, mensaje: string|null, frente: array|null, url: string|null, vence_en_segundos: int|null, expira_en: string|null, usuario_id: int|null}
     */
    protected function pedir_el_acceso(Client $client, $clave, array $frente, $solicitante, $motivo)
    {
        $cuerpo = ['solicitante' => (string) $solicitante];

        // El motivo es opcional: si no hay, la clave no viaja (el empresa-api lo trata como ausente).
        if ($motivo !== null && $motivo !== '') {
            $cuerpo['motivo'] = (string) $motivo;
        }

        try {
            $response = Http::withHeaders([
                    'X-Admin-Api-Key' => $clave,
                    'Accept'          => 'application/json',
                ])
                /* 🔴 Sin seguir redirecciones: Guzzle, al redirigir a otro host, saca `Authorization` pero NO un header propio como
                 * `X-Admin-Api-Key`, y la clave del cliente viajaría a donde apunte la redirección. Un 3xx vuelve como una
                 * respuesta más. */
                ->withOptions(['allow_redirects' => false])
                ->timeout(self::TIMEOUT_SEGUNDOS)
                ->withBody(
                    (string) json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'application/json'
                )
                ->post($frente['url_de_emision']);
        } catch (\Throwable $e) {
            // ConnectionException, timeout, DNS: no hay respuesta HTTP. El mensaje de cURL repite la URL entera (sin query: acá no hay).
            return $this->resultado(
                self::ESTADO_NO_RESPONDE,
                $this->texto_seguro(
                    'No se pudo contactar al empresa-api del cliente (timeout de ' . self::TIMEOUT_SEGUNDOS . ' s o falla de conexión): '
                    . ClientCatalogoPuenteService::sin_la_query_de_las_urls($e->getMessage()),
                    $clave
                ),
                $frente['publico']
            );
        }

        $status = (int) $response->status();

        // El cuerpo se decodifica y NO se vuelve a usar como texto: ahí viaja el token en un 200.
        $decodificado = json_decode((string) $response->body(), true);
        $datos        = is_array($decodificado) ? $decodificado : [];
        $code         = isset($datos['code']) && is_string($datos['code']) ? $datos['code'] : null;

        if ($status === 200) {
            return $this->resultado_de_un_200($datos, $frente['publico'], $clave);
        }

        if ($status === 401) {
            return $this->resultado(
                self::ESTADO_CLAVE_RECHAZADA,
                'El empresa-api del cliente rechazó la clave del admin (401): su ADMIN_API_INBOUND_KEY no coincide con la clients.api_key '
                . 'del admin, o no la tiene cargada. Corré POST claude/clients/{id}/catalogo/clave con dry_run=false para dejarla igual en sus .env.',
                $frente['publico']
            );
        }

        if ($status === 403 && $code === 'acceso_soporte_desactivado') {
            return $this->resultado(
                self::ESTADO_ACCESO_DESACTIVADO,
                'El acceso de soporte está desactivado en el sistema de este cliente (ACCESO_SOPORTE_HABILITADO=false en su .env). '
                . 'No se emitió ningún acceso.',
                $frente['publico']
            );
        }

        if ($status === 409 && $code === 'dueno_no_resoluble') {
            return $this->resultado(
                self::ESTADO_DUENO_NO_RESOLUBLE,
                'El empresa-api del cliente no pudo decidir con certeza cuál es el usuario dueño al que dejar entrar (por ejemplo, hay más '
                . 'de un dueño en su base y no tiene USER_ID definido en el .env). No se emitió ningún acceso: nunca se elige "el primero".',
                $frente['publico']
            );
        }

        // 404 o 405 SIN `code` = los de Laravel: la ruta no existe en la versión del cliente. Con `code` es una respuesta de la app.
        if (($status === 404 || $status === 405) && $code === null) {
            return $this->resultado(
                self::ESTADO_VERSION_VIEJA,
                'El sistema de este cliente todavía no tiene el acceso de soporte (su versión es anterior a esta función; contestó '
                . $status . ' sin código). Hay que actualizarlo a una versión que lo traiga, o entrar con el login maestro o con '
                . 'guardar-sesion.ps1.',
                $frente['publico']
            );
        }

        // Cualquier otra cosa: se informa el status y NADA del cuerpo.
        $detalle = ($status >= 300 && $status < 400)
            ? ' Es una redirección y el admin no las sigue (para no mandar la clave del cliente a otro host): revisá la URL de la API del frente.'
            : '';

        return $this->resultado(
            self::ESTADO_RESPUESTA_INVALIDA,
            'El empresa-api del cliente contestó HTTP ' . $status . ', que no es parte del contrato del acceso de soporte.' . $detalle,
            $frente['publico']
        );
    }

    /**
     * Arma el resultado a partir de un 200 del cliente: o trae un token válido y un vencimiento, o es una respuesta inválida.
     *
     * Un token es exactamente 64 alfanuméricos (el contrato C1): cualquier otra forma no se entrega, porque la URL que sale de acá es
     * lo que un agente va a abrir en un navegador. `vence_en_segundos` tiene que ser un entero positivo.
     *
     * @param array<string, mixed> $datos  Cuerpo decodificado del cliente.
     * @param array<string, mixed> $frente El frente (lo que sale en la respuesta).
     * @param string               $clave  `clients.api_key` (a tapar en cualquier texto).
     *
     * @return array{estado: string, mensaje: string|null, frente: array|null, url: string|null, vence_en_segundos: int|null, expira_en: string|null, usuario_id: int|null}
     */
    protected function resultado_de_un_200(array $datos, array $frente, $clave)
    {
        $token = isset($datos['token']) && is_string($datos['token']) ? $datos['token'] : '';
        $vence = isset($datos['vence_en_segundos']) ? $datos['vence_en_segundos'] : null;

        if (preg_match('/^[A-Za-z0-9]{64}$/D', $token) !== 1 || ! is_int($vence) || $vence < 1) {
            return $this->resultado(
                self::ESTADO_RESPUESTA_INVALIDA,
                'El empresa-api del cliente contestó 200 pero sin un token válido (64 caracteres alfanuméricos) y un vencimiento en '
                . 'segundos. No se emitió ningún acceso utilizable.',
                $frente
            );
        }

        $usuario_id = isset($datos['usuario_id']) && is_numeric($datos['usuario_id']) ? (int) $datos['usuario_id'] : null;

        // 🔴 El token va en el FRAGMENTO (`#t=`), no en la query: no viaja al servidor ni queda en los access logs del hosting.
        $url = rtrim((string) $frente['spa_url'], '/') . self::RUTA_DEL_SPA . '#t=' . $token;

        return $this->resultado(
            self::ESTADO_OK,
            null,
            $frente,
            [
                'url'               => $url,
                'vence_en_segundos' => $vence,
                'expira_en'         => Carbon::now('UTC')->addSeconds($vence)->format('Y-m-d\TH:i:s\Z'),
                'usuario_id'        => $usuario_id,
            ]
        );
    }

    /**
     * Un corte de configuración (sin api_key, sin URL, sin SPA): en dry_run no se registra nada; en modo real se deja la línea de log
     * y el resultado vuelve igual.
     *
     * @param Client                    $client      Cliente.
     * @param string                    $estado      Uno de los `ESTADO_SIN_*`.
     * @param string                    $mensaje     Motivo (sin clave ni token).
     * @param array<string, mixed>|null $frente      Lo que se alcanzó a resolver del frente.
     * @param bool                      $dry_run     Si es una simulación.
     * @param string                    $solicitante Quién pide.
     * @param string|null               $motivo      Para qué.
     * @param float                     $inicio      Instante de arranque (`microtime(true)`).
     *
     * @return array{estado: string, mensaje: string|null, frente: array|null, url: string|null, vence_en_segundos: int|null, expira_en: string|null, usuario_id: int|null}
     */
    protected function cortar(Client $client, $estado, $mensaje, $frente, $dry_run, $solicitante, $motivo, $inicio)
    {
        if (! $dry_run) {
            $this->registrar($client, $frente, $solicitante, $motivo, $estado, $inicio);
        }

        return $this->resultado($estado, $mensaje, $frente);
    }

    /**
     * 🔴 La línea de log de un pedido de acceso real. Lleva SOLO client_id, client_api_id, solicitante, motivo, host del frente, estado
     * y milisegundos: nunca el cuerpo que contestó el cliente (ahí viaja el token), nunca la clave del cliente, nunca la URL de entrada.
     *
     * Un éxito va a `info` y todo lo demás a `warning`. `solicitante` y `motivo` pasan por el tapador de claves por si a alguien se le
     * ocurre pegar una ahí.
     *
     * @param Client                    $client      Cliente.
     * @param array<string, mixed>|null $frente      El frente resuelto (o null si no se llegó a resolver).
     * @param string                    $solicitante Quién pidió.
     * @param string|null               $motivo      Para qué.
     * @param string                    $estado      Estado final.
     * @param float                     $inicio      Instante de arranque (`microtime(true)`).
     *
     * @return void
     */
    protected function registrar(Client $client, $frente, $solicitante, $motivo, $estado, $inicio)
    {
        $clave   = trim((string) $client->api_key);
        $mensaje = 'ClientAccesoSoporteService: pedido de acceso de soporte.';

        $contexto = [
            'client_id'     => (int) $client->id,
            'client_api_id' => is_array($frente) ? $frente['client_api_id'] : null,
            'solicitante'   => $this->texto_seguro($solicitante, $clave, self::MAX_SOLICITANTE),
            'motivo'        => $motivo === null ? null : $this->texto_seguro($motivo, $clave, self::MAX_MOTIVO),
            'host'          => is_array($frente) ? $frente['api_host'] : null,
            'estado'        => $estado,
            'ms'            => (int) round((microtime(true) - $inicio) * 1000),
        ];

        if ($estado === self::ESTADO_OK) {
            Log::info($mensaje, $contexto);
        } else {
            Log::warning($mensaje, $contexto);
        }
    }

    /**
     * El resultado común.
     *
     * @param string                    $estado  Uno de los `ESTADO_*`.
     * @param string|null               $mensaje Motivo, cuando no es `listo` ni `ok`.
     * @param array<string, mixed>|null $frente  El frente (`client_api_id`, `hosting_type`, `api_host`, `spa_url`).
     * @param array<string, mixed>      $extra   Las claves de un éxito (`url`, `vence_en_segundos`, `expira_en`, `usuario_id`).
     *
     * @return array{estado: string, mensaje: string|null, frente: array|null, url: string|null, vence_en_segundos: int|null, expira_en: string|null, usuario_id: int|null}
     */
    protected function resultado($estado, $mensaje, $frente, array $extra = [])
    {
        return array_merge([
            'estado'            => $estado,
            'mensaje'           => $mensaje,
            'frente'            => $frente,
            'url'               => null,
            'vence_en_segundos' => null,
            'expira_en'         => null,
            'usuario_id'        => null,
        ], $extra);
    }

    /**
     * El host de una URL (con el puerto si no es el de default), sin ruta ni query. Null si no se puede sacar.
     *
     * Es lo único de la URL de la API que sale en la respuesta y en el log: la ruta lleva `/public` y la forma de instalar del cliente,
     * que no le sirve a nadie.
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
     * Normaliza la URL de un SPA: sin espacios ni barra final, http/https con host. Devuelve '' si no sirve.
     *
     * Es la regla de `ClientEmpresaApiUrlResolver::normalize_base_url()` (que es `protected`, y ese archivo no se toca desde esta
     * misión) más una exigencia propia: sin query, fragmento ni credenciales. A la URL de entrada se le pega `/acceso-soporte#t=...`, y
     * un `spa_url` con `?x=1` o `#algo` produciría una URL que el SPA no sabe leer.
     *
     * @param string|null $url URL cargada en `client_apis.spa_url`.
     *
     * @return string
     */
    protected function normalizar_spa_url($url)
    {
        $url = rtrim(trim((string) $url), '/');

        if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
            return '';
        }

        $partes = parse_url($url);

        if (! is_array($partes) || empty($partes['host'])) {
            return '';
        }

        if (isset($partes['query']) || isset($partes['fragment']) || isset($partes['user']) || isset($partes['pass'])) {
            return '';
        }

        return $url;
    }
}
