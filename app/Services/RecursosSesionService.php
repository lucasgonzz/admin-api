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
 *   1. identifica al cliente por el HOST de `api` contra las URLs que YA tiene cargadas
 *      (`client_apis.url` y `clients.api_url`);
 *   2. 🔴 le pide el canje a la URL que resuelve `ClientEmpresaApiUrlResolver::admin_sync_url()` para
 *      ESE cliente — NUNCA a la `api` que vino del navegador. `api` solo sirve para elegir cliente: si
 *      se le pegara a lo que manda el navegador, cualquiera podría apuntar el admin a un servidor
 *      propio que contesta "200, soy el dueño de tal negocio" (y de paso llevarse la `api_key` del
 *      cliente en el header). Hay un test que lo cuida;
 *   3. recién con el 200 del cliente crea la sesión.
 *
 * No depende de ninguna clave nueva en la flota: es el mismo canal admin → cliente
 * (`X-Admin-Api-Key = clients.api_key`) que ya usan el puente de catálogo y el contacto del dueño.
 *
 * Errores (contrato C2): `cliente_no_encontrado` (el host no es de ningún cliente, o es de más de uno
 * y el path no desempata), `pase_invalido` (el cliente dijo que no: 404 —incluido el 404 de ruta de
 * un cliente con versión vieja—, 422, o el pase no tiene forma de pase) y `cliente_no_responde` (no se
 * le pudo preguntar o contestó otra cosa).
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
     * @return array{ok: bool, error?: string, token?: string, usuario?: RecursosUsuario}
     */
    public function por_pase($pase, $api): array
    {
        $pase = is_string($pase) ? trim($pase) : '';

        $client = $this->cliente_por_api($api);

        if (! $client instanceof Client) {
            return ['ok' => false, 'error' => 'cliente_no_encontrado'];
        }

        /* El pase es un Str::random(64) del lado del cliente. Se acepta un margen (32 a 128, con - y _)
           para no atar el admin al detalle de cómo lo genera; lo que no tiene forma de pase no se le
           manda a nadie. */
        if (! preg_match('/^[A-Za-z0-9_\-]{32,128}$/', $pase)) {
            return ['ok' => false, 'error' => 'pase_invalido'];
        }

        $canje = $this->canjear_en_el_cliente($client, $pase);

        if (! $canje['ok']) {
            return $canje;
        }

        $usuario = $this->usuario_del_pase($client, $canje['user']);

        return [
            'ok'      => true,
            'token'   => $this->crear_sesion($usuario),
            'usuario' => $usuario,
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
     * @param RecursosUsuario $usuario
     *
     * @return string
     */
    public function crear_sesion(RecursosUsuario $usuario): string
    {
        $token = Str::random(64);

        RecursosSesion::create([
            'recursos_usuario_id' => $usuario->id,
            'token_hash'          => hash('sha256', $token),
            'expires_at'          => now()->addDays((int) config('recursos.sesion_dias', 365)),
            'last_used_at'        => now(),
        ]);

        return $token;
    }

    /**
     * El cliente dueño de una URL de `empresa-api`, por su HOST (plan §1).
     *
     * Se compara el host en minúsculas y sin `www.` contra el de cada `client_apis.url` y cada
     * `clients.api_url`. Si el host es de un solo cliente, es ése (un cliente con dos frentes —las dos
     * carpetas del shared hosting— tiene dos URLs y sigue siendo uno). Si es de más de un cliente, se
     * desempata por el path normalizado (sin `/public` final ni barra); si sigue sin quedar uno solo,
     * no se adivina: null.
     *
     * @param mixed $api
     *
     * @return Client|null
     */
    public function cliente_por_api($api): ?Client
    {
        $buscada = self::partes_de_url(is_string($api) ? $api : '');

        if ($buscada === null) {
            return null;
        }

        /** @var array<int, array<int, string>> $paths_por_cliente client_id => [paths] */
        $paths_por_cliente = [];

        /* El LIKE solo achica la lista; la comparación que vale es la exacta de abajo. El host ya viene
           validado por parse_url (letras, números, puntos y guiones), así que no hay comodines que
           escapar más que el `_`, que en un host no aparece en la práctica pero se escapa igual. */
        $patron = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $buscada['host']) . '%';

        foreach (ClientApi::where('url', 'like', $patron)->get(['client_id', 'url']) as $client_api) {
            $partes = self::partes_de_url((string) $client_api->url);

            if ($partes !== null && $partes['host'] === $buscada['host']) {
                $paths_por_cliente[(int) $client_api->client_id][] = $partes['path'];
            }
        }

        foreach (Client::where('api_url', 'like', $patron)->get(['id', 'api_url']) as $legacy) {
            $partes = self::partes_de_url((string) $legacy->api_url);

            if ($partes !== null && $partes['host'] === $buscada['host']) {
                $paths_por_cliente[(int) $legacy->id][] = $partes['path'];
            }
        }

        $ids = array_keys($paths_por_cliente);

        if (count($ids) > 1) {
            $ids = array_values(array_filter($ids, function ($id) use ($paths_por_cliente, $buscada) {
                return in_array($buscada['path'], $paths_por_cliente[$id], true);
            }));
        }

        if (count($ids) !== 1) {
            if (count($paths_por_cliente) > 1) {
                Log::channel('daily')->warning('RecursosSesion: el host del pase es de más de un cliente y el path no desempata.', [
                    'host'     => $buscada['host'],
                    'clientes' => array_keys($paths_por_cliente),
                ]);
            }

            return null;
        }

        return Client::find($ids[0]);
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
     * Le pide el canje del pase al `empresa-api` del cliente (contrato C3).
     *
     * Sin reintentos a propósito: el pase es de un solo uso, y un reintento después de un timeout en
     * el que el cliente SÍ lo marcó usado daría `pase_invalido` y escondería el problema real.
     *
     * @param Client $client
     * @param string $pase
     *
     * @return array{ok: bool, error?: string, user?: array<string, mixed>}
     */
    protected function canjear_en_el_cliente(Client $client, string $pase): array
    {
        // 🔴 La URL sale de la base del admin para ESTE cliente, nunca de lo que mandó el navegador.
        $url = $this->resolver->admin_sync_url($client, self::CANJEAR_PASE_PATH);

        if ($url === '' || trim((string) $client->api_key) === '') {
            $this->anotar($client, $url === '' ? 'el cliente no tiene una URL de empresa-api válida' : 'el cliente no tiene api_key');

            return ['ok' => false, 'error' => 'cliente_no_responde'];
        }

        try {
            $response = Http::withHeaders([
                    'X-Admin-Api-Key' => (string) $client->api_key,
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
            $this->anotar($client, 'no se pudo conectar (' . $e->getMessage() . ')');

            return ['ok' => false, 'error' => 'cliente_no_responde'];
        }

        $status = (int) $response->status();

        // 404: el pase no existe, ya se usó, venció o es de otro dueño — o el cliente corre una versión
        // sin la ruta. Para la página es lo mismo: no hay sesión y sigue como invitado (plan §2, C3).
        if ($status === 404 || $status === 422) {
            return ['ok' => false, 'error' => 'pase_invalido'];
        }

        if ($status !== 200) {
            $this->anotar($client, 'el cliente respondió HTTP ' . $status);

            return ['ok' => false, 'error' => 'cliente_no_responde'];
        }

        $user = null;

        try {
            $user = $response->json('user');
        } catch (\Throwable $e) {
            $user = null;
        }

        if (! is_array($user) || ! isset($user['id']) || ! is_numeric($user['id']) || (int) $user['id'] <= 0) {
            $this->anotar($client, 'la respuesta del canje no trae un usuario válido');

            return ['ok' => false, 'error' => 'cliente_no_responde'];
        }

        return ['ok' => true, 'user' => $user];
    }

    /**
     * El usuario del centro que corresponde al usuario que devolvió el cliente: el mismo de siempre
     * si ya entró, o uno nuevo.
     *
     * Si el que entra es el DUEÑO (`owner_id` null) y todavía no tiene fila con su id, pero el panel ya
     * le había generado el link personal sin saber su id (`es_dueno` y `empresa_user_id` null), se le
     * COMPLETA el id a esa fila en vez de crear otra: es la misma persona, y su avance tiene que estar
     * en un solo lugar.
     *
     * @param Client               $client
     * @param array<string, mixed> $user   `{id, name, email, owner_id}` del contrato C3.
     *
     * @return RecursosUsuario
     */
    protected function usuario_del_pase(Client $client, array $user): RecursosUsuario
    {
        $empresa_user_id = (int) $user['id'];
        $es_dueno        = ! isset($user['owner_id']) || $user['owner_id'] === null || (int) $user['owner_id'] === 0;
        $nombre          = trim(isset($user['name']) && is_string($user['name']) ? $user['name'] : '');
        $email           = ClientContactEmailResolver::mail_valido(isset($user['email']) ? $user['email'] : null);

        if ($nombre === '') {
            $nombre = $es_dueno ? trim((string) $client->name) : '';
        }

        if ($nombre === '') {
            $nombre = 'Usuario';
        }

        $nombre = mb_substr($nombre, 0, 255, 'UTF-8');

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

        if (! $usuario instanceof RecursosUsuario) {
            $usuario = new RecursosUsuario();
            $usuario->client_id = $client->id;
        }

        $usuario->empresa_user_id   = $empresa_user_id;
        $usuario->nombre            = $nombre;
        $usuario->email             = $email;
        $usuario->es_dueno          = $es_dueno;
        $usuario->ultimo_ingreso_at = now();

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
