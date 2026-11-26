<?php

namespace App\Services\Mcp;

use App\Models\Admin;
use App\Models\McpOauthClient;
use App\Models\McpOauthCode;
use App\Models\McpOauthToken;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servidor OAuth 2.1 mínimo del conector MCP de sólo lectura (misión dots-openclaw, 25/11/2026).
 *
 * Reúne toda la lógica que los controladores sólo cablean: metadatos de descubrimiento, registro dinámico de
 * clientes (DCR), validación del pedido de autorización, emisión y canje del código (PKCE S256), emisión y rotación
 * de tokens, verificación del bearer y revocación.
 *
 * 🔴 Reglas de seguridad que este archivo hace cumplir:
 *  - Códigos y tokens se guardan SÓLO como sha256; en claro existen únicamente en la respuesta que los entrega.
 *  - PKCE S256 es obligatorio; la comparación del desafío usa `hash_equals`.
 *  - El `redirect_uri` tiene que ser idéntico a uno registrado, y los registrables son https, del host de ChatGPT,
 *    de las rutas que documenta OpenAI, sin fragmento ni query.
 *  - El código es de un solo uso (el canje lo marca con un UPDATE atómico) y vence a los 300 s; reusarlo revoca
 *    los tokens que emitió.
 *  - El refresh token rota; reusar uno viejo revoca toda la familia.
 *  - Los tokens van atados a una audiencia (`resource`) y se vuelve a chequear la política del admin en cada uso.
 *  - Los logs nunca llevan códigos, tokens ni contraseñas.
 */
class McpOauthService
{
    /** Prefijo de los tokens de acceso. */
    const PREFIJO_ACCESS = 'ccm_at_';

    /** Prefijo de los refresh tokens. */
    const PREFIJO_REFRESH = 'ccm_rt_';

    /** Prefijo de los identificadores de cliente. */
    const PREFIJO_CLIENTE = 'ccm_c_';

    /** Scope de lectura (siempre presente en lo que se otorga). */
    const SCOPE_LECTURA = 'comerciocity.read';

    /** Largo máximo del `state` que se acepta (y se devuelve completo) en la autorización. */
    const MAX_STATE = 2048;

    /** Scope que pide ChatGPT para recibir refresh tokens. */
    const SCOPE_OFFLINE = 'offline_access';

    /** Tope de `redirect_uris` por registro. */
    const MAX_REDIRECTS = 5;

    /** Segundos mínimos entre dos actualizaciones de `last_used_at` de un token. */
    const SEGUNDOS_ENTRE_USOS = 60;

    /* ------------------------------------------------------------------------------------------
     | Configuración y metadatos
     |----------------------------------------------------------------------------------------- */

    /**
     * Si el conector está prendido (`config('mcp.enabled')`).
     *
     * @return bool
     */
    public function habilitado()
    {
        return (bool) config('mcp.enabled', true);
    }

    /**
     * El issuer (origen público del admin-api, sin path ni barra final).
     *
     * @return string
     */
    public function issuer()
    {
        return rtrim((string) config('mcp.issuer'), '/');
    }

    /**
     * La URL canónica del recurso protegido (el endpoint MCP), sin barra final. Es la audiencia de los tokens.
     *
     * @return string
     */
    public function recurso()
    {
        return rtrim((string) config('mcp.resource'), '/');
    }

    /**
     * La URL del documento de metadatos del recurso protegido, la que se anuncia en `WWW-Authenticate`.
     *
     * Se arma con el origen y el path del recurso: `<origen>/.well-known/oauth-protected-resource<path>`.
     *
     * @return string
     */
    public function url_metadatos_del_recurso()
    {
        $partes = parse_url($this->recurso());
        $origen = (isset($partes['scheme']) ? $partes['scheme'] : 'https') . '://' . (isset($partes['host']) ? $partes['host'] : '')
            . (isset($partes['port']) ? ':' . $partes['port'] : '');
        $path = isset($partes['path']) ? $partes['path'] : '';

        return $origen . '/.well-known/oauth-protected-resource' . $path;
    }

    /**
     * Metadatos del recurso protegido (RFC 9728).
     *
     * @return array<string, mixed>
     */
    public function metadatos_del_recurso()
    {
        return [
            'resource'                 => $this->recurso(),
            'authorization_servers'    => [$this->issuer()],
            'scopes_supported'         => [self::SCOPE_LECTURA],
            'bearer_methods_supported' => ['header'],
            'resource_name'            => 'ComercioCity (sólo lectura)',
        ];
    }

    /**
     * Metadatos del servidor de autorización (RFC 8414 / OpenID discovery).
     *
     * @return array<string, mixed>
     */
    public function metadatos_del_servidor()
    {
        $issuer = $this->issuer();

        return [
            'issuer'                                          => $issuer,
            'authorization_endpoint'                          => $issuer . '/mcp/oauth/authorize',
            'token_endpoint'                                  => $issuer . '/api/mcp/oauth/token',
            'registration_endpoint'                           => $issuer . '/api/mcp/oauth/register',
            'response_types_supported'                        => ['code'],
            'grant_types_supported'                           => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported'                => ['S256'],
            'token_endpoint_auth_methods_supported'           => ['none'],
            'scopes_supported'                                => [self::SCOPE_LECTURA, self::SCOPE_OFFLINE],
            'authorization_response_iss_parameter_supported'  => true,
        ];
    }

    /* ------------------------------------------------------------------------------------------
     | Registro dinámico de clientes (DCR)
     |----------------------------------------------------------------------------------------- */

    /**
     * Registra un cliente. Valida los redirects contra `config/mcp.php` y guarda una fila.
     *
     * @param array<string, mixed> $entrada Cuerpo JSON del registro (`redirect_uris`, `client_name`, …).
     *
     * @return array{ok: bool, cliente?: array<string, mixed>, error?: string, descripcion?: string}
     */
    public function registrar_cliente(array $entrada)
    {
        $uris = isset($entrada['redirect_uris']) ? $entrada['redirect_uris'] : null;
        if (! is_array($uris) || count($uris) < 1 || count($uris) > self::MAX_REDIRECTS) {
            return $this->falla_de_registro('invalid_redirect_uri', 'redirect_uris tiene que traer entre 1 y ' . self::MAX_REDIRECTS . ' direcciones.');
        }

        $limpias = [];
        foreach ($uris as $uri) {
            if (! is_string($uri) || ! $this->redirect_permitido($uri)) {
                return $this->falla_de_registro('invalid_redirect_uri', 'Redirect no permitido: tiene que ser https, de ChatGPT y sin fragmento.');
            }
            $limpias[] = $uri;
        }
        $limpias = array_values(array_unique($limpias));

        // Método de autenticación: sólo cliente público (PKCE reemplaza al secreto).
        if (isset($entrada['token_endpoint_auth_method']) && $entrada['token_endpoint_auth_method'] !== 'none') {
            return $this->falla_de_registro('invalid_client_metadata', 'Sólo se admite token_endpoint_auth_method "none" (cliente público con PKCE).');
        }

        // Grants y tipos de respuesta: si vienen, tienen que ser un subconjunto de lo que el servidor soporta.
        $grants = $this->subconjunto($entrada, 'grant_types', ['authorization_code', 'refresh_token'], ['authorization_code', 'refresh_token']);
        $tipos  = $this->subconjunto($entrada, 'response_types', ['code'], ['code']);
        if ($grants === null || $tipos === null) {
            return $this->falla_de_registro('invalid_client_metadata', 'grant_types o response_types fuera de lo que soporta el servidor.');
        }

        $nombre = $this->nombre_de_cliente(isset($entrada['client_name']) ? $entrada['client_name'] : null);

        $cliente = McpOauthClient::create([
            'client_id'     => self::PREFIJO_CLIENTE . bin2hex(random_bytes(16)),
            'client_name'   => $nombre,
            'redirect_uris' => $limpias,
            'grant_types'   => $grants,
        ]);

        return [
            'ok'      => true,
            'cliente' => [
                'client_id'                  => $cliente->client_id,
                'client_id_issued_at'        => $cliente->created_at->getTimestamp(),
                'client_name'                => $cliente->client_name,
                'redirect_uris'              => $limpias,
                'grant_types'                => $grants,
                'response_types'             => $tipos,
                'token_endpoint_auth_method' => 'none',
            ],
        ];
    }

    /**
     * Si un `redirect_uri` se puede registrar: https, host exacto de la lista, puerto estándar, sin credenciales,
     * sin query ni fragmento, y con un path de los permitidos.
     *
     * @param string $uri Dirección a evaluar.
     *
     * @return bool
     */
    public function redirect_permitido($uri)
    {
        // Largo razonable y nada de espacios ni caracteres de control.
        if ($uri === '' || strlen($uri) > 500 || preg_match('/[\x00-\x20\x7f]/', $uri) === 1) {
            return false;
        }
        // Fragmento o query (aunque estén vacíos) fuera.
        if (strpos($uri, '#') !== false || strpos($uri, '?') !== false) {
            return false;
        }

        $partes = parse_url($uri);
        if ($partes === false || ! isset($partes['scheme'], $partes['host'], $partes['path'])) {
            return false;
        }
        if (strtolower($partes['scheme']) !== 'https' || isset($partes['user']) || isset($partes['pass'])) {
            return false;
        }
        if (isset($partes['port']) && (int) $partes['port'] !== 443) {
            return false;
        }
        if (! in_array(strtolower($partes['host']), array_map('strtolower', (array) config('mcp.redirect_hosts', [])), true)) {
            return false;
        }

        foreach ((array) config('mcp.redirect_paths', []) as $patron) {
            if (preg_match($patron, $partes['path']) === 1) {
                return true;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------------------------------------
     | Autorización
     |----------------------------------------------------------------------------------------- */

    /**
     * Valida un pedido de autorización (el GET que muestra la pantalla y el POST que la envía; el POST se
     * revalida entero, nada de lo que viene del navegador se da por bueno).
     *
     * El resultado separa dos clases de error:
     *  - `fatal` (texto): el pedido no permite redirigir con seguridad (`response_type`, cliente o
     *    `redirect_uri` malos). Se muestra en la página con 400 y NUNCA se redirige.
     *  - `error` (`error` + `descripcion`): con cliente y redirect válidos, se vuelve al redirect con el error.
     *
     * @param array<string, mixed> $entrada Parámetros del pedido (query o formulario).
     *
     * @return array{fatal: string|null, error: array<string, string>|null, cliente: McpOauthClient|null, parametros: array<string, string>}
     */
    public function validar_pedido_autorizacion(array $entrada)
    {
        $parametros = [
            'response_type'         => $this->texto($entrada, 'response_type'),
            'client_id'             => $this->texto($entrada, 'client_id'),
            'redirect_uri'          => $this->texto($entrada, 'redirect_uri'),
            'state'                 => $this->state_del_pedido($entrada),
            'code_challenge'        => $this->texto($entrada, 'code_challenge'),
            'code_challenge_method' => $this->texto($entrada, 'code_challenge_method'),
            'scope'                 => $this->texto($entrada, 'scope'),
            'resource'              => $this->texto($entrada, 'resource', 500),
        ];
        $resultado = ['fatal' => null, 'error' => null, 'cliente' => null, 'parametros' => $parametros];

        if ($parametros['response_type'] !== 'code') {
            $resultado['fatal'] = 'El pedido no es válido: response_type tiene que ser "code".';

            return $resultado;
        }

        $cliente = $parametros['client_id'] === '' ? null : McpOauthClient::where('client_id', $parametros['client_id'])->first();
        if ($cliente === null || ! hash_equals((string) $cliente->client_id, $parametros['client_id'])) {
            $resultado['fatal'] = 'El pedido no es válido: no conocemos a esa aplicación.';

            return $resultado;
        }
        $resultado['cliente'] = $cliente;

        // `redirect_uri` idéntico a uno registrado; si falta y hay uno solo, se usa ese.
        $registrados = (array) $cliente->redirect_uris;
        if ($parametros['redirect_uri'] === '' && count($registrados) === 1) {
            $parametros['redirect_uri'] = (string) $registrados[0];
        }
        if ($parametros['redirect_uri'] === '' || ! in_array($parametros['redirect_uri'], $registrados, true)) {
            $resultado['fatal'] = 'El pedido no es válido: la dirección de retorno no coincide con la registrada.';

            return $resultado;
        }
        $resultado['parametros'] = $parametros;

        // A partir de acá los errores vuelven al redirect (con state e iss).
        // `state` demasiado largo: se rechaza (nunca se recorta, el cliente lo compara con el suyo) y no se devuelve.
        if ($this->state_excede($entrada)) {
            $resultado['error'] = ['error' => 'invalid_request', 'descripcion' => 'El parámetro state supera los ' . self::MAX_STATE . ' caracteres.'];

            return $resultado;
        }
        $desafio = $parametros['code_challenge'];
        if ($desafio === '' || $parametros['code_challenge_method'] !== 'S256' || preg_match('/^[A-Za-z0-9_-]{43,128}$/', $desafio) !== 1) {
            $resultado['error'] = ['error' => 'invalid_request', 'descripcion' => 'Se requiere PKCE con code_challenge_method=S256.'];

            return $resultado;
        }

        if (! $this->resource_valido($entrada)) {
            $resultado['error'] = ['error' => 'invalid_target', 'descripcion' => 'El parámetro resource no es el de este servidor.'];

            return $resultado;
        }

        if ($this->normalizar_scope($parametros['scope']) === null) {
            $resultado['error'] = ['error' => 'invalid_scope', 'descripcion' => 'Scope no soportado.'];

            return $resultado;
        }

        return $resultado;
    }

    /**
     * La URL de redirección de vuelta al cliente con los parámetros dados, más el `iss` (RFC 9207).
     *
     * @param string               $redirect_uri Redirect ya validado (sin query).
     * @param array<string, mixed> $parametros   Parámetros a agregar (`code` o `error`, y `state` si hay).
     *
     * @return string
     */
    public function url_de_retorno($redirect_uri, array $parametros)
    {
        $parametros['iss'] = $this->issuer();

        return $redirect_uri . '?' . http_build_query($parametros, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Si un admin puede conectar el conector: no `solo_leads`, no `es_agente`, y dentro de `MCP_ADMIN_EMAILS`
     * cuando esa lista tiene valor.
     *
     * @param Admin $admin Admin a evaluar.
     *
     * @return bool
     */
    public function admin_autorizado(Admin $admin)
    {
        if ((bool) $admin->solo_leads || (bool) $admin->es_agente) {
            return false;
        }

        $permitidos = [];
        foreach (explode(',', (string) config('mcp.admin_emails', '')) as $email) {
            $email = strtolower(trim($email));
            if ($email !== '') {
                $permitidos[] = $email;
            }
        }

        return count($permitidos) === 0 || in_array(strtolower((string) $admin->email), $permitidos, true);
    }

    /**
     * Emite un código de autorización para un pedido ya validado y devuelve el código EN CLARO (64 hex). En la
     * base queda sólo su sha256.
     *
     * @param McpOauthClient       $cliente    Cliente que pidió la autorización.
     * @param Admin                $admin      Admin que dijo "Permitir".
     * @param array<string, string> $parametros Parámetros del pedido ya validado.
     *
     * @return string
     */
    public function emitir_codigo(McpOauthClient $cliente, Admin $admin, array $parametros)
    {
        $codigo = bin2hex(random_bytes(32));

        McpOauthCode::create([
            'code_hash'      => hash('sha256', $codigo),
            'client_id'      => $cliente->client_id,
            'admin_id'       => $admin->id,
            'redirect_uri'   => $parametros['redirect_uri'],
            'code_challenge' => $parametros['code_challenge'],
            'scope'          => $this->normalizar_scope($parametros['scope']),
            'resource'       => $this->recurso(),
            'expires_at'     => Carbon::now()->addSeconds((int) config('mcp.ttl_code_seg', 300)),
        ]);

        $cliente->forceFill(['last_used_at' => Carbon::now()])->save();

        return $codigo;
    }

    /* ------------------------------------------------------------------------------------------
     | Token
     |----------------------------------------------------------------------------------------- */

    /**
     * Atiende `POST /api/mcp/oauth/token` para los dos grants.
     *
     * @param array<string, mixed> $entrada Cuerpo del pedido (form o JSON).
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function atender_token(array $entrada)
    {
        $grant = $this->texto($entrada, 'grant_type');

        // Un cliente sólo puede usar los grants con los que se registró (D7).
        if ($grant === 'authorization_code' || $grant === 'refresh_token') {
            $cliente = McpOauthClient::where('client_id', $this->texto($entrada, 'client_id'))->first();
            if ($cliente !== null && ! in_array($grant, $cliente->grants_permitidos(), true)) {
                return $this->error_de_token('unauthorized_client', 'El cliente no está registrado para el grant ' . $grant . '.');
            }
        }

        if ($grant === 'authorization_code') {
            return $this->canjear_codigo($entrada);
        }
        if ($grant === 'refresh_token') {
            return $this->renovar($entrada);
        }

        return $this->error_de_token('unsupported_grant_type', 'Sólo se admite authorization_code y refresh_token.');
    }

    /**
     * Canjea un código de autorización por el par de tokens.
     *
     * Orden de chequeos: forma de los parámetros, código existente, reuso (revoca lo emitido), cliente, vencimiento;
     * recién ahí se consume el código (UPDATE atómico) y se verifican `redirect_uri`, PKCE y `resource`.
     *
     * @param array<string, mixed> $entrada Cuerpo del pedido.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function canjear_codigo(array $entrada)
    {
        $code          = $this->texto($entrada, 'code', 200);
        $client_id     = $this->texto($entrada, 'client_id');
        $redirect_uri  = $this->texto($entrada, 'redirect_uri');
        $verifier      = $this->texto($entrada, 'code_verifier');

        if ($code === '' || $client_id === '' || $redirect_uri === '' || $verifier === '') {
            return $this->error_de_token('invalid_request', 'Faltan code, client_id, redirect_uri o code_verifier.');
        }
        if (preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) !== 1) {
            return $this->error_de_token('invalid_request', 'code_verifier con formato inválido.');
        }

        $fila = McpOauthCode::where('code_hash', hash('sha256', $code))->first();
        if ($fila === null || ! hash_equals((string) $fila->code_hash, hash('sha256', $code))) {
            return $this->error_de_token('invalid_grant', 'Código inválido.');
        }

        // Reuso de un código ya canjeado: se deniega y se revoca todo lo que emitió.
        if ($fila->used_at !== null) {
            return $this->reuso_de_codigo($fila);
        }

        if (! hash_equals((string) $fila->client_id, $client_id)) {
            return $this->error_de_token('invalid_grant', 'El código no es de ese cliente.');
        }
        if (Carbon::now()->greaterThan($fila->expires_at)) {
            return $this->error_de_token('invalid_grant', 'El código venció.');
        }

        // Consumo atómico: si dos canjes llegan a la vez, sólo uno gana el UPDATE.
        $consumidos = McpOauthCode::where('id', $fila->id)->whereNull('used_at')->update(['used_at' => Carbon::now()]);
        if ($consumidos !== 1) {
            return $this->reuso_de_codigo($fila);
        }

        if (! hash_equals((string) $fila->redirect_uri, $redirect_uri)) {
            return $this->error_de_token('invalid_grant', 'redirect_uri distinto del del pedido de autorización.');
        }

        $desafio_calculado = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (! hash_equals((string) $fila->code_challenge, $desafio_calculado)) {
            return $this->error_de_token('invalid_grant', 'code_verifier no coincide con el desafío PKCE.');
        }

        if (! $this->resource_valido($entrada)) {
            return $this->error_de_token('invalid_target', 'El parámetro resource no es el de este servidor.');
        }

        $admin = Admin::find($fila->admin_id);
        if ($admin === null || ! $this->admin_autorizado($admin)) {
            return $this->error_de_token('invalid_grant', 'El usuario ya no puede usar el conector.');
        }

        $cliente = McpOauthClient::where('client_id', $fila->client_id)->first();
        if ($cliente !== null) {
            $cliente->forceFill(['last_used_at' => Carbon::now()])->save();
        }

        $par = $this->emitir_par($fila->client_id, (int) $fila->admin_id, (string) $fila->scope, (string) $fila->resource, bin2hex(random_bytes(16)), (int) $fila->id, $cliente === null || in_array('refresh_token', $cliente->grants_permitidos(), true));

        return ['status' => 200, 'body' => $par];
    }

    /**
     * Respuesta a un código reusado: revoca los tokens que emitió ese código y devuelve `invalid_grant`.
     *
     * @param McpOauthCode $fila Código ya usado.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function reuso_de_codigo(McpOauthCode $fila)
    {
        $familias = McpOauthToken::where('code_id', $fila->id)->pluck('family')->unique()->all();
        if (count($familias) > 0) {
            McpOauthToken::whereIn('family', $familias)->whereNull('revoked_at')->update(['revoked_at' => Carbon::now()]);
        }
        Log::warning('mcp codigo reusado client=' . $fila->client_id . ' admin=' . $fila->admin_id);

        return $this->error_de_token('invalid_grant', 'El código ya se usó.');
    }

    /**
     * Cambia un refresh token por un par nuevo (rotación). Reusar uno ya rotado revoca toda la familia.
     *
     * @param array<string, mixed> $entrada Cuerpo del pedido.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function renovar(array $entrada)
    {
        $refresh   = $this->texto($entrada, 'refresh_token', 200);
        $client_id = $this->texto($entrada, 'client_id');
        if ($refresh === '' || $client_id === '') {
            return $this->error_de_token('invalid_request', 'Faltan refresh_token o client_id.');
        }

        $hash = hash('sha256', $refresh);
        $fila = McpOauthToken::where('refresh_hash', $hash)->first();
        if ($fila === null || ! hash_equals((string) $fila->refresh_hash, $hash) || ! hash_equals((string) $fila->client_id, $client_id)) {
            return $this->error_de_token('invalid_grant', 'Refresh token inválido.');
        }

        // Ya rotado o revocado: si alguien lo vuelve a presentar se trata como robo y cae toda la familia.
        if ($fila->revoked_at !== null) {
            McpOauthToken::where('family', $fila->family)->whereNull('revoked_at')->update(['revoked_at' => Carbon::now()]);
            Log::warning('mcp refresh reusado client=' . $fila->client_id . ' admin=' . $fila->admin_id);

            return $this->error_de_token('invalid_grant', 'El refresh token ya no sirve.');
        }
        if ($fila->refresh_expires_at === null || Carbon::now()->greaterThan($fila->refresh_expires_at)) {
            return $this->error_de_token('invalid_grant', 'El refresh token venció.');
        }
        if (! $this->resource_valido($entrada)) {
            return $this->error_de_token('invalid_target', 'El parámetro resource no es el de este servidor.');
        }

        $admin = Admin::find($fila->admin_id);
        if ($admin === null || ! $this->admin_autorizado($admin)) {
            McpOauthToken::where('family', $fila->family)->whereNull('revoked_at')->update(['revoked_at' => Carbon::now()]);

            return $this->error_de_token('invalid_grant', 'El usuario ya no puede usar el conector.');
        }

        // Rotación atómica: el viejo par queda revocado en el mismo UPDATE que lo reclama.
        $rotados = McpOauthToken::where('id', $fila->id)->whereNull('revoked_at')->update(['revoked_at' => Carbon::now()]);
        if ($rotados !== 1) {
            McpOauthToken::where('family', $fila->family)->whereNull('revoked_at')->update(['revoked_at' => Carbon::now()]);

            return $this->error_de_token('invalid_grant', 'El refresh token ya no sirve.');
        }

        $par = $this->emitir_par($fila->client_id, (int) $fila->admin_id, (string) $fila->scope, (string) $fila->resource, (string) $fila->family, $fila->code_id === null ? null : (int) $fila->code_id);

        return ['status' => 200, 'body' => $par];
    }

    /**
     * Crea y guarda un par access + refresh y arma la respuesta del endpoint de token (con los tokens en claro,
     * que es la única vez que existen fuera de quien los recibe).
     *
     * @param string   $client_id Cliente dueño del par.
     * @param int      $admin_id  Admin que autorizó.
     * @param string   $scope     Scope otorgado.
     * @param string   $resource  Audiencia.
     * @param string   $familia   Familia de refresh.
     * @param int|null $code_id   Código con el que nació la familia.
     * @param bool     $con_refresh Si el cliente se registró con el grant `refresh_token` (si no, no se emite refresh).
     *
     * @return array<string, mixed>
     */
    private function emitir_par($client_id, $admin_id, $scope, $resource, $familia, $code_id, $con_refresh = true)
    {
        $access  = self::PREFIJO_ACCESS . $this->aleatorio_url();
        $refresh = $con_refresh ? self::PREFIJO_REFRESH . $this->aleatorio_url() : null;
        $ttl     = (int) config('mcp.ttl_access_seg', 3600);
        $ahora   = Carbon::now();

        McpOauthToken::create([
            'access_hash'        => hash('sha256', $access),
            'refresh_hash'       => $refresh === null ? null : hash('sha256', $refresh),
            'family'             => $familia,
            'code_id'            => $code_id,
            'client_id'          => $client_id,
            'admin_id'           => $admin_id,
            'scope'              => $scope,
            'resource'           => $resource,
            'access_expires_at'  => $ahora->copy()->addSeconds($ttl),
            'refresh_expires_at' => $refresh === null ? null : $ahora->copy()->addDays((int) config('mcp.ttl_refresh_dias', 30)),
        ]);

        $respuesta = [
            'access_token' => $access,
            'token_type'   => 'Bearer',
            'expires_in'   => $ttl,
            'scope'        => $scope,
        ];
        if ($refresh !== null) {
            $respuesta['refresh_token'] = $refresh;
        }

        return $respuesta;
    }

    /* ------------------------------------------------------------------------------------------
     | Bearer y revocación
     |----------------------------------------------------------------------------------------- */

    /**
     * Resuelve un bearer a su token y su admin, o null si no sirve.
     *
     * Sirve si: tiene el prefijo, el hash existe, no está revocado ni vencido, su audiencia es la canónica y el
     * admin sigue existiendo y pasando la política (un admin pasado a `solo_leads` después de emitir el token lo
     * pierde). Actualiza `last_used_at` a lo sumo una vez por minuto.
     *
     * @param string $bearer El token en claro que llegó en `Authorization: Bearer`.
     *
     * @return array{token: McpOauthToken, admin: Admin}|null
     */
    public function autenticar($bearer)
    {
        if (strpos($bearer, self::PREFIJO_ACCESS) !== 0 || strlen($bearer) > 200) {
            return null;
        }

        $hash  = hash('sha256', $bearer);
        $token = McpOauthToken::where('access_hash', $hash)->first();
        if ($token === null || ! hash_equals((string) $token->access_hash, $hash)) {
            return null;
        }
        if ($token->revoked_at !== null || Carbon::now()->greaterThan($token->access_expires_at)) {
            return null;
        }
        if (! hash_equals($this->recurso(), rtrim((string) $token->resource, '/'))) {
            return null;
        }

        $admin = Admin::find($token->admin_id);
        if ($admin === null || ! $this->admin_autorizado($admin)) {
            return null;
        }

        // `last_used_at` a lo sumo una vez por minuto (no escribir en cada llamada).
        if ($token->last_used_at === null || $token->last_used_at->diffInSeconds(Carbon::now(), false) >= self::SEGUNDOS_ENTRE_USOS) {
            McpOauthToken::where('id', $token->id)->update(['last_used_at' => Carbon::now()]);
        }

        return ['token' => $token, 'admin' => $admin];
    }

    /**
     * Revoca los tokens vigentes (todos, o los de un admin) y devuelve cuántos eran.
     *
     * "Vigente" = sin revocar y con acceso o refresh que todavía no venció.
     *
     * @param int|null $admin_id Si viene, sólo los de ese admin.
     *
     * @return int
     */
    public function revocar_todos($admin_id = null)
    {
        $ahora = Carbon::now();

        $consulta = McpOauthToken::whereNull('revoked_at')->where(function ($q) use ($ahora) {
            $q->where('access_expires_at', '>', $ahora)->orWhere('refresh_expires_at', '>', $ahora);
        });
        if ($admin_id !== null) {
            $consulta->where('admin_id', $admin_id);
        }

        return (int) $consulta->update(['revoked_at' => $ahora]);
    }

    /* ------------------------------------------------------------------------------------------
     | Auxiliares
     |----------------------------------------------------------------------------------------- */

    /**
     * Un texto escalar del arreglo de entrada (string vacío si falta o no es escalar), recortado a un largo.
     *
     * @param array<string, mixed> $entrada Parámetros.
     * @param string               $clave   Nombre del parámetro.
     * @param int                  $largo   Tope de caracteres.
     *
     * @return string
     */
    private function texto(array $entrada, $clave, $largo = 300)
    {
        if (! isset($entrada[$clave]) || ! is_scalar($entrada[$clave]) || is_bool($entrada[$clave])) {
            return '';
        }

        return substr((string) $entrada[$clave], 0, $largo);
    }

    /**
     * El `state` del pedido, completo: vacío si falta, no es texto o supera {@see MAX_STATE} (en ese caso
     * `validar_pedido_autorizacion()` rechaza el pedido; jamás se devuelve recortado).
     *
     * @param array<string, mixed> $entrada Parámetros del pedido.
     *
     * @return string
     */
    private function state_del_pedido(array $entrada)
    {
        if (! isset($entrada['state']) || ! is_string($entrada['state']) || strlen($entrada['state']) > self::MAX_STATE) {
            return '';
        }

        return $entrada['state'];
    }

    /**
     * Si el pedido trae un `state` de texto más largo que {@see MAX_STATE}.
     *
     * @param array<string, mixed> $entrada Parámetros del pedido.
     *
     * @return bool
     */
    private function state_excede(array $entrada)
    {
        return isset($entrada['state']) && is_string($entrada['state']) && strlen($entrada['state']) > self::MAX_STATE;
    }

    /**
     * Si el `resource` del pedido (cuando viene) es el canónico. Ausente o vacío vale (se usa el canónico).
     *
     * @param array<string, mixed> $entrada Parámetros del pedido.
     *
     * @return bool
     */
    private function resource_valido(array $entrada)
    {
        if (! array_key_exists('resource', $entrada) || $entrada['resource'] === null || $entrada['resource'] === '') {
            return true;
        }

        return is_string($entrada['resource']) && hash_equals($this->recurso(), rtrim($entrada['resource'], '/'));
    }

    /**
     * Normaliza el scope pedido: siempre incluye el de lectura; devuelve null si pide algo que no existe.
     *
     * @param string $pedido Scope del pedido (separado por espacios; vacío = sólo lectura).
     *
     * @return string|null `comerciocity.read` o `comerciocity.read offline_access`.
     */
    private function normalizar_scope($pedido)
    {
        $pedidos = preg_split('/\s+/', trim($pedido), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($pedidos as $scope) {
            if (! in_array($scope, [self::SCOPE_LECTURA, self::SCOPE_OFFLINE], true)) {
                return null;
            }
        }

        return in_array(self::SCOPE_OFFLINE, $pedidos, true) ? self::SCOPE_LECTURA . ' ' . self::SCOPE_OFFLINE : self::SCOPE_LECTURA;
    }

    /**
     * 32 bytes aleatorios en base64url sin relleno (43 caracteres).
     *
     * @return string
     */
    private function aleatorio_url()
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Un error del endpoint de token.
     *
     * @param string $error       Código OAuth.
     * @param string $descripcion Descripción en castellano.
     *
     * @return array{status: int, body: array<string, string>}
     */
    private function error_de_token($error, $descripcion)
    {
        return ['status' => 400, 'body' => ['error' => $error, 'error_description' => $descripcion]];
    }

    /**
     * Un error de registro (DCR).
     *
     * @param string $error       `invalid_redirect_uri` o `invalid_client_metadata`.
     * @param string $descripcion Descripción en castellano.
     *
     * @return array{ok: bool, error: string, descripcion: string}
     */
    private function falla_de_registro($error, $descripcion)
    {
        return ['ok' => false, 'error' => $error, 'descripcion' => $descripcion];
    }

    /**
     * Lista de valores de un campo del registro, si es un subconjunto de los permitidos.
     *
     * @param array<string, mixed> $entrada    Cuerpo del registro.
     * @param string               $clave      Campo (`grant_types`, `response_types`).
     * @param array<int, string>   $permitidos Valores aceptables.
     * @param array<int, string>   $defecto    Valor si el campo no viene.
     *
     * @return array<int, string>|null null si trae algo fuera de lo permitido.
     */
    private function subconjunto(array $entrada, $clave, array $permitidos, array $defecto)
    {
        if (! isset($entrada[$clave])) {
            return $defecto;
        }
        if (! is_array($entrada[$clave]) || count($entrada[$clave]) === 0) {
            return null;
        }
        foreach ($entrada[$clave] as $valor) {
            if (! is_string($valor) || ! in_array($valor, $permitidos, true)) {
                return null;
            }
        }

        return array_values(array_unique($entrada[$clave]));
    }

    /**
     * Nombre de cliente apto para mostrar: sin caracteres de control, recortado a 120, o null.
     *
     * @param mixed $valor Lo que mandó el cliente.
     *
     * @return string|null
     */
    private function nombre_de_cliente($valor)
    {
        if (! is_string($valor)) {
            return null;
        }
        $limpio = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $valor));

        return $limpio === '' ? null : mb_substr($limpio, 0, 120);
    }
}
