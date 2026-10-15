<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\RecursosLink;
use App\Models\RecursosSesion;
use App\Models\RecursosUsuario;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cómo se entra al centro de recursos (misión centro-de-recursos-clientes, 13/10/2026): canjeando un
 * PASE que emitió el sistema del cliente, o con el LINK personal del dueño. Las dos terminan en lo
 * mismo: un usuario del centro y una sesión nueva con su token.
 *
 * EL PASE, y por qué es así (plan de la misión, §1). El usuario, logueado en su sistema, toca "Centro
 * de recursos"; su `empresa-api` crea un pase de un solo uso EN SU BASE y lo manda a la página junto
 * con `api` (la URL base de esa `empresa-api`). La página le pasa `{pase, api}` al admin, y el admin:
 *
 *   1. busca por el HOST de `api` el FRENTE del cliente que YA tiene cargado (`client_apis.url`, o
 *      `clients.api_url` legacy), desempatando por el path;
 *   2. 🔴 le pide el canje a la URL de ESE frente tal como está en la base del admin (normalizada con
 *      su `hosting_type`) — NUNCA a la `api` que vino del navegador. `api` solo sirve para elegir: si
 *      se le pegara a lo que manda el navegador, cualquiera podría apuntar el admin a un servidor
 *      propio que contesta "200, soy el dueño de tal negocio" (y de paso llevarse la `api_key` del
 *      cliente en el header). Hay un test que lo cuida.
 *      Y al frente que matcheó, no al ACTIVO (`admin_sync_url()`): el pase vive en la instancia que lo
 *      emitió, y si el frente activo corre una versión vieja sin la ruta, preguntarle a él daría un
 *      `pase_invalido` engañoso. Solo el match por `clients.api_url` (que no tiene frente propio) va
 *      por `admin_sync_url()`;
 *   3. recién con el 200 del cliente crea la sesión.
 *
 * No depende de ninguna clave nueva en la flota: es el mismo canal admin → cliente
 * (`X-Admin-Api-Key = clients.api_key`) que ya usan el puente de catálogo y el contacto del dueño.
 *
 * DOS CLIENTES CON EL MISMO FRENTE (pasa: un cliente cargado apuntando a la API de otro). Si después
 * del desempate por path siguen quedando varios, es la MISMA instancia: se canjea una vez contra ella
 * y se elige el cliente cuyo `clients.user_id` es el dueño del usuario que volvió. Si no hay
 * exactamente uno, no se adivina: `cliente_no_encontrado`.
 *
 * BASES COMPARTIDAS (`shared_database_group_id`): ahí adentro viven varios comercios con usuarios de
 * todos; el usuario que vuelve tiene que ser de ESTE dueño (`clients.user_id`), o es `pase_invalido` y
 * no se crea ni se completa nada. El `empresa-api` ya lo filtra por su `USER_ID`; esto es la segunda
 * reja, del lado del admin.
 *
 * LOGIN MAESTRO = SOLO LECTURA (misión centro-recursos-soporte-y-tema, 14/10/2026). Si el cliente
 * contesta el canje con `soporte: true` (el pase lo emitió un login maestro: soporte entrando como el
 * dueño), la sesión que se abre es de SOLO LECTURA y vence a las `sesion_soporte_horas`: se ve el avance
 * del dueño, pero no se guarda nada a su nombre (`recursos.escritura` corta las rutas que escriben) y
 * tampoco se toca la ficha del dueño (nombre, mail, `es_dueno`, `ultimo_ingreso_at`). Un canje sin
 * `soporte` (un `empresa-api` viejo no lo manda) es una sesión normal, exactamente como antes.
 *
 * Errores (contrato C2): `cliente_no_encontrado`, `pase_invalido` (el cliente dijo que no: 404 —incluido
 * el 404 de ruta de un cliente con versión vieja—, 422, el pase no tiene forma de pase, o el usuario no
 * es de este dueño) y `cliente_no_responde` (no se le pudo preguntar o contestó otra cosa).
 */
class RecursosSesionService
{
    /**
     * Ruta del canje en el `empresa-api` del cliente (contrato C3).
     */
    const CANJEAR_PASE_PATH = 'api/admin-sync/recursos/canjear-pase';

    /**
     * @var ClientEmpresaApiUrlResolver
     */
    protected $resolver;

    /**
     * @param ClientEmpresaApiUrlResolver|null $resolver Inyectable para los tests.
     */
    public function __construct(?ClientEmpresaApiUrlResolver $resolver = null)
    {
        $this->resolver = $resolver !== null ? $resolver : new ClientEmpresaApiUrlResolver();
    }

    /**
     * Canjea un pase y devuelve la sesión nueva.
     *
     * @param mixed $pase Lo que mandó la página.
     * @param mixed $api  La URL base del `empresa-api` que emitió el pase, tal como vino.
     *
     * @return array{ok: bool, error?: string, token?: string, usuario?: RecursosUsuario, solo_lectura?: bool}
     */
    public function por_pase($pase, $api): array
    {
        $pase = is_string($pase) ? trim($pase) : '';

        $candidatos = $this->candidatos_por_api($api);

        if ($candidatos === []) {
            return ['ok' => false, 'error' => 'cliente_no_encontrado'];
        }

        /* El pase es un Str::random(64) del lado del cliente. Se acepta un margen (32 a 128, con - y _)
           para no atar el admin al detalle de cómo lo genera; lo que no tiene forma de pase no se le
           manda a nadie. */
        if (! preg_match('/^[A-Za-z0-9_\-]{32,128}$/', $pase)) {
            return ['ok' => false, 'error' => 'pase_invalido'];
        }

        $canje = $this->canjear($candidatos, $pase);

        if (! $canje['ok']) {
            return $canje;
        }

        $client = $this->cliente_del_canje($candidatos, $canje['user']);

        if (! $client instanceof Client) {
            return ['ok' => false, 'error' => 'cliente_no_encontrado'];
        }

        if (! self::usuario_es_del_dueno($client, $canje['user'])) {
            Log::channel('daily')->warning('RecursosSesion: el usuario del pase no es del dueño de este cliente (base compartida).', [
                'client_id' => $client->id,
            ]);

            return ['ok' => false, 'error' => 'pase_invalido'];
        }

        // Contrato C3: `soporte` ausente = false. Solo un `true` estricto abre una sesión de solo lectura.
        $soporte = isset($canje['soporte']) && $canje['soporte'] === true;

        $usuario = $this->usuario_del_pase($client, $canje['user'], $soporte);

        return [
            'ok'           => true,
            'token'        => $this->crear_sesion($usuario, $soporte),
            'usuario'      => $usuario,
            'solo_lectura' => $soporte,
        ];
    }

    /**
     * Abre una sesión con el link personal del dueño.
     *
     * @param mixed $t El token del link, tal como vino.
     *
     * @return array{ok: bool, error?: string, token?: string, usuario?: RecursosUsuario}
     */
    public function por_link($t): array
    {
        $t = is_string($t) ? trim($t) : '';

        if ($t === '' || strlen($t) > 200) {
            return ['ok' => false, 'error' => 'link_invalido'];
        }

        $link = RecursosLink::where('token_hash', hash('sha256', $t))->first();

        if (! $link instanceof RecursosLink || $link->expires_at === null || $link->expires_at->isPast()) {
            return ['ok' => false, 'error' => 'link_invalido'];
        }

        $usuario = RecursosUsuario::with('client')->find($link->recursos_usuario_id);

        if (! $usuario instanceof RecursosUsuario) {
            return ['ok' => false, 'error' => 'link_invalido'];
        }

        RecursosLink::where('id', $link->id)->increment('usos');

        $usuario->ultimo_ingreso_at = now();
        $usuario->save();

        return [
            'ok'      => true,
            'token'   => $this->crear_sesion($usuario),
            'usuario' => $usuario,
        ];
    }

    /**
     * Crea una sesión para el usuario y devuelve el token en claro (lo único que se devuelve en
     * claro; en la base queda el sha256).
     *
     * Una sesión de SOLO LECTURA (pase de login maestro) vence a las `sesion_soporte_horas` en vez de
     * a los `sesion_dias`, y es la única que escribe la columna `solo_lectura`: una sesión normal se
     * crea exactamente igual que antes (la columna queda en su default `false`).
     *
     * @param RecursosUsuario $usuario
     * @param bool            $solo_lectura
     *
     * @return string
     */
    public function crear_sesion(RecursosUsuario $usuario, bool $solo_lectura = false): string
    {
        $token = Str::random(64);

        $datos = [
            'recursos_usuario_id' => $usuario->id,
            'token_hash'          => hash('sha256', $token),
            'expires_at'          => now()->addDays((int) config('recursos.sesion_dias', 365)),
            'last_used_at'        => now(),
        ];

        if ($solo_lectura) {
            $datos['expires_at']   = now()->addHours((int) config('recursos.sesion_soporte_horas', 12));
            $datos['solo_lectura'] = true;
        }

        RecursosSesion::create($datos);

        return $token;
    }

    /**
     * Los clientes (con el frente que matcheó) dueños de una URL de `empresa-api`, por su HOST
     * (plan §1).
     *
     * Se compara el host en minúsculas y sin `www.` contra el de cada `client_apis.url` y cada
     * `clients.api_url`. Si el host es de un solo cliente, es ése (un cliente con dos frentes —las dos
     * carpetas del shared hosting— tiene dos URLs y sigue siendo uno). Si es de más de uno, se
     * desempata por el path normalizado (sin `/public` final ni barra); los que quedan después de eso
     * son la MISMA instancia, y los resuelve el canje (ver `cliente_del_canje()`).
     *
     * De cada cliente se elige UN frente: primero una `ClientApi` cuyo path coincida con el que mandó
     * el navegador, después cualquier `ClientApi` que matcheó, y por último `clients.api_url` (null en
     * `client_api`).
     *
     * @param mixed $api
     *
     * @return array<int, array{client: Client, client_api: ClientApi|null}>
     */
    public function candidatos_por_api($api): array
    {
        $buscada = self::partes_de_url(is_string($api) ? $api : '');

        if ($buscada === null) {
            return [];
        }

        /** @var array<int, array<int, array{path: string, client_api: ClientApi|null}>> $matches client_id => frentes */
        $matches = [];

        /* El LIKE solo achica la lista; la comparación que vale es la exacta de abajo. El host ya viene
           validado por parse_url (letras, números, puntos y guiones), así que no hay comodines que
           escapar más que el `_`, que en un host no aparece en la práctica pero se escapa igual. */
        $patron = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $buscada['host']) . '%';

        foreach (ClientApi::where('url', 'like', $patron)->orderBy('id')->get() as $client_api) {
            $partes = self::partes_de_url((string) $client_api->url);

            if ($partes !== null && $partes['host'] === $buscada['host']) {
                $matches[(int) $client_api->client_id][] = ['path' => $partes['path'], 'client_api' => $client_api];
            }
        }

        foreach (Client::where('api_url', 'like', $patron)->get(['id', 'api_url']) as $legacy) {
            $partes = self::partes_de_url((string) $legacy->api_url);

            if ($partes !== null && $partes['host'] === $buscada['host']) {
                $matches[(int) $legacy->id][] = ['path' => $partes['path'], 'client_api' => null];
            }
        }

        $ids = array_keys($matches);

        if (count($ids) > 1) {
            $ids = array_values(array_filter($ids, function ($id) use ($matches, $buscada) {
                foreach ($matches[$id] as $frente) {
                    if ($frente['path'] === $buscada['path']) {
                        return true;
                    }
                }

                return false;
            }));
        }

        $candidatos = [];

        foreach ($ids as $id) {
            $client = Client::find($id);

            if ($client instanceof Client) {
                $candidatos[] = ['client' => $client, 'client_api' => self::mejor_frente($matches[$id], $buscada['path'])];
            }
        }

        return $candidatos;
    }

    /**
     * Host (minúsculas, sin `www.`) y path normalizado (minúsculas, sin `/public` final ni barra) de
     * una URL http/https, o null si no es una.
     *
     * @param string $url
     *
     * @return array{host: string, path: string}|null
     */
    public static function partes_de_url(string $url): ?array
    {
        $url = trim($url);

        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return null;
        }

        $partes = parse_url($url);

        if (! is_array($partes) || empty($partes['host'])) {
            return null;
        }

        $host = strtolower((string) $partes['host']);

        if (substr($host, 0, 4) === 'www.') {
            $host = substr($host, 4);
        }

        $path = ClientEmpresaApiUrlResolver::strip_public_suffix(strtolower(isset($partes['path']) ? (string) $partes['path'] : ''));

        return ['host' => $host, 'path' => $path];
    }

    /**
     * El dueño del usuario que devolvió el canje: su `owner_id`, o su propio `id` si es el dueño.
     *
     * @param array<string, mixed> $user
     *
     * @return int
     */
    public static function dueno_del_usuario(array $user): int
    {
        if (isset($user['owner_id']) && is_numeric($user['owner_id']) && (int) $user['owner_id'] > 0) {
            return (int) $user['owner_id'];
        }

        return (int) $user['id'];
    }

    /**
     * Si el usuario devuelto puede entrar como usuario de este cliente.
     *
     * Solo hay algo que mirar en una BASE COMPARTIDA (`shared_database_group_id`) con `user_id`
     * cargado: ahí el usuario tiene que ser de este dueño. Fuera de eso, la instancia es del cliente y
     * cualquier usuario suyo vale.
     *
     * @param Client               $client
     * @param array<string, mixed> $user
     *
     * @return bool
     */
    public static function usuario_es_del_dueno(Client $client, array $user): bool
    {
        if ($client->shared_database_group_id === null || (int) $client->user_id <= 0) {
            return true;
        }

        return self::dueno_del_usuario($user) === (int) $client->user_id;
    }

    /**
     * El frente que se usa de un cliente (ver `candidatos_por_api()`).
     *
     * @param array<int, array{path: string, client_api: ClientApi|null}> $frentes
     * @param string                                                     $path
     *
     * @return ClientApi|null
     */
    protected static function mejor_frente(array $frentes, string $path): ?ClientApi
    {
        foreach ($frentes as $frente) {
            if ($frente['client_api'] instanceof ClientApi && $frente['path'] === $path) {
                return $frente['client_api'];
            }
        }

        foreach ($frentes as $frente) {
            if ($frente['client_api'] instanceof ClientApi) {
                return $frente['client_api'];
            }
        }

        return null;
    }

    /**
     * La URL de canje de un candidato, SIEMPRE de la base del admin: la de su `ClientApi` normalizada
     * con su `hosting_type` (el mismo criterio que `ClientEmpresaApiUrlResolver`) o, si el match fue
     * por `clients.api_url`, la de `admin_sync_url()`.
     *
     * @param array{client: Client, client_api: ClientApi|null} $candidato
     *
     * @return string Vacía si no hay una URL válida.
     */
    protected function url_de_canje(array $candidato): string
    {
        if ($candidato['client_api'] instanceof ClientApi) {
            $hosting = $candidato['client_api']->hosting_type !== null ? $candidato['client_api']->hosting_type : 'shared_hosting';
            $base    = $this->resolver->normalize_api_base_url((string) $candidato['client_api']->url, $hosting);

            return $base === '' ? '' : $base . '/' . self::CANJEAR_PASE_PATH;
        }

        return $this->resolver->admin_sync_url($candidato['client'], self::CANJEAR_PASE_PATH);
    }

    /**
     * Le pide el canje del pase a la instancia de los candidatos (contrato C3).
     *
     * Con un solo candidato es una llamada, con su clave. Con varios (el mismo frente cargado en dos
     * clientes) la URL es la del primero —es la misma instancia— y se prueba la clave de cada uno hasta
     * que la instancia no la rechace: un 401/403 no consume el pase, así que no se gasta nada probando.
     * Lo primero que no sea un rechazo de clave es la respuesta.
     *
     * Sin reintentos a propósito: el pase es de un solo uso, y un reintento después de un timeout en
     * el que el cliente SÍ lo marcó usado daría `pase_invalido` y escondería el problema real.
     *
     * @param array<int, array{client: Client, client_api: ClientApi|null}> $candidatos
     * @param string                                                       $pase
     *
     * @return array{ok: bool, error?: string, user?: array<string, mixed>, soporte?: bool}
     */
    protected function canjear(array $candidatos, string $pase): array
    {
        $primero = $candidatos[0];
        $url     = $this->url_de_canje($primero);

        if ($url === '') {
            $this->anotar($primero['client'], 'el cliente no tiene una URL de empresa-api válida');

            return ['ok' => false, 'error' => 'cliente_no_responde'];
        }

        $claves = [];
        foreach ($candidatos as $candidato) {
            $clave = trim((string) $candidato['client']->api_key);

            if ($clave !== '' && ! in_array($clave, $claves, true)) {
                $claves[] = $clave;
            }
        }

        if ($claves === []) {
            $this->anotar($primero['client'], 'el cliente no tiene api_key');

            return ['ok' => false, 'error' => 'cliente_no_responde'];
        }

        $resultado = ['ok' => false, 'error' => 'cliente_no_responde'];

        foreach ($claves as $clave) {
            $resultado = $this->pedir_canje($url, $clave, $pase, $primero['client']);

            if (! isset($resultado['status']) || ($resultado['status'] !== 401 && $resultado['status'] !== 403)) {
                break;
            }
        }

        unset($resultado['status']);

        return $resultado;
    }

    /**
     * Un pedido de canje. Devuelve además el `status` HTTP (0 si no hubo respuesta).
     *
     * @param string $url
     * @param string $clave
     * @param string $pase
     * @param Client $para_el_log
     *
     * @return array{ok: bool, status: int, error?: string, user?: array<string, mixed>, soporte?: bool}
     */
    protected function pedir_canje(string $url, string $clave, string $pase, Client $para_el_log): array
    {
        try {
            $response = Http::withHeaders([
                    'X-Admin-Api-Key' => $clave,
                    'Accept'          => 'application/json',
                ])
                /* 🔴 Sin seguir redirecciones (mismo motivo que `ClientCatalogoPuenteService`): Guzzle, al
                   redirigir a otro host, saca `Authorization` pero NO un header propio como
                   `X-Admin-Api-Key`, y la clave del cliente viajaría a donde apunte la redirección. Un 3xx
                   vuelve como una respuesta más y cae en `cliente_no_responde`. */
                ->withOptions(['allow_redirects' => false])
                ->timeout((int) config('services.client_api.timeout', 15))
                ->post($url, ['pase' => $pase]);
        } catch (\Throwable $e) {
            $this->anotar($para_el_log, 'no se pudo conectar (' . str_replace($clave, '***', $e->getMessage()) . ')');

            return ['ok' => false, 'status' => 0, 'error' => 'cliente_no_responde'];
        }

        $status = (int) $response->status();

        // 404: el pase no existe, ya se usó, venció o es de otro dueño — o el cliente corre una versión
        // sin la ruta. Para la página es lo mismo: no hay sesión y sigue como invitado (plan §2, C3).
        if ($status === 404 || $status === 422) {
            return ['ok' => false, 'status' => $status, 'error' => 'pase_invalido'];
        }

        if ($status !== 200) {
            $this->anotar($para_el_log, 'el cliente respondió HTTP ' . $status);

            return ['ok' => false, 'status' => $status, 'error' => 'cliente_no_responde'];
        }

        $user = null;

        try {
            $user = $response->json('user');
        } catch (\Throwable $e) {
            $user = null;
        }

        if (! is_array($user) || ! isset($user['id']) || ! is_numeric($user['id']) || (int) $user['id'] <= 0) {
            $this->anotar($para_el_log, 'la respuesta del canje no trae un usuario válido');

            return ['ok' => false, 'status' => $status, 'error' => 'cliente_no_responde'];
        }

        // `soporte` es opcional (contrato C3): un `empresa-api` viejo no lo manda y eso es `false`. Solo un
        // `true` booleano cuenta; "true", 1 o cualquier otra cosa se lee como sesión normal.
        $soporte = false;

        try {
            $soporte = $response->json('soporte') === true;
        } catch (\Throwable $e) {
            $soporte = false;
        }

        return ['ok' => true, 'status' => $status, 'user' => $user, 'soporte' => $soporte];
    }

    /**
     * De qué cliente es el usuario que devolvió el canje.
     *
     * Con un solo candidato, ése. Con varios (la misma instancia cargada en más de un cliente), el
     * candidato cuyo `clients.user_id` es el dueño del usuario; si no hay exactamente uno, null.
     *
     * @param array<int, array{client: Client, client_api: ClientApi|null}> $candidatos
     * @param array<string, mixed>                                         $user
     *
     * @return Client|null
     */
    protected function cliente_del_canje(array $candidatos, array $user): ?Client
    {
        if (count($candidatos) === 1) {
            return $candidatos[0]['client'];
        }

        $dueno  = self::dueno_del_usuario($user);
        $elegidos = array_values(array_filter($candidatos, function ($candidato) use ($dueno) {
            return (int) $candidato['client']->user_id > 0 && (int) $candidato['client']->user_id === $dueno;
        }));

        if (count($elegidos) !== 1) {
            Log::channel('daily')->warning('RecursosSesion: el frente es de varios clientes y el dueño del usuario no desempata.', [
                'clientes' => array_map(function ($candidato) {
                    return (int) $candidato['client']->id;
                }, $candidatos),
                'dueno'    => $dueno,
            ]);

            return null;
        }

        return $elegidos[0]['client'];
    }

    /**
     * El usuario del centro que corresponde al usuario que devolvió el cliente: el mismo de siempre
     * si ya entró, o uno nuevo. Nombre y mail se ACTUALIZAN con lo que devuelve el sistema (es la
     * fuente de verdad): así el dueño que entró primero por el link, con el nombre de la ficha, queda
     * con su nombre real.
     *
     * Si el que entra es el DUEÑO (`owner_id` null) y todavía no tiene fila con su id, pero el panel ya
     * le había generado el link personal sin saber su id (`es_dueno` y `empresa_user_id` null), se le
     * COMPLETA el id a esa fila en vez de crear otra: es la misma persona, y su avance tiene que estar
     * en un solo lugar.
     *
     * 🔴 En modo SOPORTE (pase de login maestro) NO se escribe nada sobre un usuario que ya existe: ni
     * nombre, ni mail, ni `es_dueno`, ni `ultimo_ingreso_at`, ni se le completa el `empresa_user_id` a la
     * fila que generó el panel. Es soporte mirando: la ficha del dueño queda como estaba (el panel del
     * admin lee `ultimo_ingreso_at` como "cuándo entró el cliente"). Si no existe ninguna fila se crea con
     * los datos del sistema y `ultimo_ingreso_at` en null —el panel ya admite null: así nace la fila del
     * link personal—.
     *
     * @param Client               $client
     * @param array<string, mixed> $user    `{id, name, email, owner_id}` del contrato C3.
     * @param bool                 $soporte El pase vino de un login maestro.
     *
     * @return RecursosUsuario
     */
    protected function usuario_del_pase(Client $client, array $user, bool $soporte = false): RecursosUsuario
    {
        $empresa_user_id = (int) $user['id'];
        $es_dueno        = ! isset($user['owner_id']) || $user['owner_id'] === null || (int) $user['owner_id'] === 0;
        $nombre          = trim(isset($user['name']) && is_string($user['name']) ? $user['name'] : '');
        $email           = ClientContactEmailResolver::mail_valido(isset($user['email']) ? $user['email'] : null);

        $usuario = RecursosUsuario::where('client_id', $client->id)
            ->where('empresa_user_id', $empresa_user_id)
            ->first();

        if (! $usuario instanceof RecursosUsuario && $es_dueno) {
            $usuario = RecursosUsuario::where('client_id', $client->id)
                ->where('es_dueno', true)
                ->whereNull('empresa_user_id')
                ->orderBy('id')
                ->first();
        }

        if ($soporte && $usuario instanceof RecursosUsuario) {
            $usuario->setRelation('client', $client);

            return $usuario;
        }

        if (! $usuario instanceof RecursosUsuario) {
            $usuario = new RecursosUsuario();
            $usuario->client_id = $client->id;
        }

        // Sin nombre en el sistema: se queda el que tenía; si es nuevo, uno genérico (nunca el negocio,
        // que los demás verían como "nombre de pila").
        if ($nombre === '') {
            $nombre = trim((string) $usuario->nombre) !== '' ? (string) $usuario->nombre : ($es_dueno ? 'Dueño' : 'Usuario');
        }

        $usuario->empresa_user_id   = $empresa_user_id;
        $usuario->nombre            = mb_substr($nombre, 0, 255, 'UTF-8');
        $usuario->es_dueno          = $es_dueno;

        // En soporte llegamos acá solo con una fila NUEVA: nace sin "último ingreso" (nadie entró de verdad).
        if (! $soporte) {
            $usuario->ultimo_ingreso_at = now();
        }

        if ($email !== null) {
            $usuario->email = $email;
        }

        try {
            $usuario->save();
        } catch (QueryException $e) {
            /* Dos canjes del mismo usuario a la vez (dos pestañas): el único (client_id,
               empresa_user_id) frena al segundo INSERT. Es el mismo usuario: se usa la fila que ganó. */
            $existente = RecursosUsuario::where('client_id', $client->id)
                ->where('empresa_user_id', $empresa_user_id)
                ->first();

            if (! $existente instanceof RecursosUsuario) {
                throw $e;
            }

            $usuario = $existente;
        }

        $usuario->setRelation('client', $client);

        return $usuario;
    }

    /**
     * Una línea de log por un canje que no se pudo hacer. Sin la clave ni el pase.
     *
     * @param Client $client
     * @param string $motivo
     *
     * @return void
     */
    protected function anotar(Client $client, string $motivo): void
    {
        $clave = (string) $client->api_key;

        if ($clave !== '') {
            $motivo = str_replace($clave, '***', $motivo);
        }

        Log::channel('daily')->info('RecursosSesion: no se pudo canjear el pase. ' . $motivo, [
            'client_id' => $client->id,
        ]);
    }
}
