<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Busca y baja los artefactos que GitHub Actions publica en cada release de `empresa-spa` y
 * `empresa-api` (misión `actualizar-sin-el-vps`, 9/9/2026).
 *
 * Hasta esa misión cada `ClientVersionUpgrade` compilaba la SPA y empaquetaba la API EN EL VPS DE
 * PRODUCCIÓN (`/home/builds`): `npm ci` + webpack a un núcleo al 100 % durante 5-10 minutos, más
 * `composer install`, más un zip de 759 MB con el `.git` adentro, y todo eso UNA VEZ POR FRENTE. El
 * 9/9/2026 eso hizo fallar un upgrade por timeout con el VPS a load 50. Desde ahora el build es uno
 * por versión, lo hace GitHub Actions al crear el tag, y `DeploymentService` lo baja de acá:
 *
 *  - `empresa-spa-v{version}-dist.zip`: el contenido de `dist/` en la raíz del zip.
 *  - `empresa-api-v{version}.zip`: el repo en la raíz, con `vendor/`, sin `.git`, `.env`,
 *    `storage/`, `public/` ni `tests/`.
 *
 * Los dos se buscan en el release del tag `v{version}` del repo correspondiente, bajo el owner de
 * `services.github.releases_owner`. Si el release o el asset no existen, `find_asset()` devuelve
 * `null` y el pipeline sigue por la vía vieja (el VPS de builds): así una versión publicada antes
 * de esta misión se despliega igual. Si GitHub falla de otra manera (401, 403, 500, sin red) se
 * lanza: un problema de credenciales o de red no se disfraza de "no hay artefacto", porque eso
 * mandaría el build al VPS en silencio, que es justo lo que se vino a sacar.
 *
 * 🔴 La descarga va A ARCHIVO (`sink` de Guzzle), nunca a memoria: el zip de la API pesa 50-100 MB
 * y el admin corre en el shared hosting de Hostinger con `memory_limit` chico.
 *
 * 🔴 El token viaja en el header `Authorization`, jamás en la URL (Guzzle copia la URI completa
 * adentro del mensaje de sus excepciones), y NO viaja al servidor de archivos: GitHub responde la
 * descarga con un 302 a `objects.githubusercontent.com` (una URL firmada), y Guzzle ≥ 7.4.2 —el
 * lock trae 7.10.0— saca `Authorization` y `Cookie` en toda redirección que cambie de origen
 * (`RedirectMiddleware::modifyRequest()`, `UriComparator::isCrossOrigin()`). Si viajara, S3 lo
 * rechaza con 400 por traer dos mecanismos de autenticación. Hay un test que fija ese comportamiento.
 *
 * Copia el patrón del cliente HTTP de `ManualRepositoryService` (mismo token, mismo `User-Agent`
 * —GitHub rechaza sin él—, misma configuración TLS que Anthropic para WAMP/Windows).
 */
class ReleaseArtifactService
{
    /** Base de la API de GitHub. */
    const GITHUB_API_BASE = 'https://api.github.com';

    /** Owner por defecto de los repos, si `services.github.releases_owner` viene vacío. */
    const OWNER_POR_DEFECTO = 'lucasgonzz';

    /** GitHub exige un `User-Agent`; sin él responde 403. Mismo valor que ManualRepositoryService. */
    const USER_AGENT = 'ComercioCity-Admin/1.0';

    /** `Accept` de la API JSON. */
    const ACCEPT_API = 'application/vnd.github+json';

    /** `Accept` con el que la URL del asset devuelve el binario (vía 302) en vez de su JSON. */
    const ACCEPT_BINARIO = 'application/octet-stream';

    /** Segundos de espera de la consulta del release. */
    const TIMEOUT_API_SEGUNDOS = 30;

    /**
     * Segundos de espera de la descarga entera. Generoso a propósito: 100 MB desde el shared
     * hosting pueden tardar varios minutos, y cortar a la mitad es peor que esperar.
     */
    const TIMEOUT_DESCARGA_SEGUNDOS = 600;

    /** Máximo de redirecciones que se siguen (GitHub usa una: API → objects.githubusercontent.com). */
    const MAX_REDIRECCIONES = 5;

    /** Caracteres del cuerpo de una respuesta de error que se copian al mensaje de la excepción. */
    const CUERPO_MAXIMO_EN_ERROR = 300;

    /**
     * Busca un asset por nombre en el release de un tag.
     *
     * @param string $repo       Nombre del repo, sin owner (ej: `empresa-spa`).
     * @param string $tag        Tag del release (ej: `v4.0.23`).
     * @param string $asset_name Nombre exacto del asset (ej: `empresa-spa-v4.0.23-dist.zip`).
     *
     * @return array<string, mixed>|null `['name', 'url', 'size', 'browser_download_url']`, o null si
     *                                   no hay release para ese tag o el release no trae ese asset.
     *
     * @throws \InvalidArgumentException Si algún argumento viene vacío.
     * @throws \RuntimeException         Si GitHub responde un error distinto de 404, o no responde.
     */
    public function find_asset(string $repo, string $tag, string $asset_name): ?array
    {
        $repo       = trim($repo);
        $tag        = trim($tag);
        $asset_name = trim($asset_name);

        if ($repo === '' || $tag === '' || $asset_name === '') {
            throw new \InvalidArgumentException(
                'Para buscar un artefacto hacen falta el repo, el tag y el nombre del asset.'
            );
        }

        $url = self::GITHUB_API_BASE . '/repos/' . rawurlencode($this->owner()) . '/' . rawurlencode($repo)
            . '/releases/tags/' . rawurlencode($tag);

        try {
            $response = $this->api_client()->get($url);
        } catch (ConnectionException $e) {
            throw new \RuntimeException(
                "No se pudo consultar a GitHub el release {$tag} de {$repo}: " . $e->getMessage(),
                0,
                $e
            );
        }

        /* 404 es la respuesta normal para "ese tag no tiene release" (y también la que da un repo
           privado sin token, que para este servicio es lo mismo: no hay artefacto que bajar). */
        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw new \RuntimeException(
                "GitHub respondió {$response->status()} al buscar el release {$tag} de {$repo}: "
                . $this->truncar($response->body())
            );
        }

        $assets = $response->json('assets');
        if (! is_array($assets)) {
            return null;
        }

        foreach ($assets as $asset) {
            if (! is_array($asset) || (string) ($asset['name'] ?? '') !== $asset_name) {
                continue;
            }

            return [
                'name'                 => (string) $asset['name'],
                'url'                  => (string) ($asset['url'] ?? ''),
                'size'                 => (int) ($asset['size'] ?? 0),
                'browser_download_url' => (string) ($asset['browser_download_url'] ?? ''),
            ];
        }

        return null;
    }

    /**
     * Baja un asset a un archivo local, siguiendo la redirección de GitHub al servidor de archivos,
     * y verifica que el tamaño en disco sea exactamente el que el release declara.
     *
     * Si algo falla —error HTTP, corte de red, tamaño distinto— el archivo local se borra antes de
     * lanzar: un zip a medias que quede en `storage/app/deployments` es justo lo que
     * `DeploymentService::step_upload_spa()` toma como "artefacto ya bajado".
     *
     * @param array<string, mixed> $asset      Lo que devolvió `find_asset()` (`url` y `size`).
     * @param string               $local_path Ruta local destino; el directorio se crea si no existe.
     *
     * @return int Bytes escritos (igual a `size`).
     *
     * @throws \InvalidArgumentException Si el asset no trae `url` o declara 0 bytes.
     * @throws \RuntimeException         Si la descarga falla o el tamaño no coincide.
     */
    public function download_asset(array $asset, string $local_path): int
    {
        $url            = trim((string) ($asset['url'] ?? ''));
        $bytes_esperados = (int) ($asset['size'] ?? 0);
        $nombre         = trim((string) ($asset['name'] ?? ''));
        if ($nombre === '') {
            $nombre = $url;
        }

        if ($url === '') {
            throw new \InvalidArgumentException('El asset no tiene `url`: no hay de dónde bajarlo.');
        }

        if ($bytes_esperados <= 0) {
            throw new \InvalidArgumentException(
                "El asset {$nombre} declara {$bytes_esperados} bytes: un artefacto vacío no se despliega."
            );
        }

        $directorio = dirname($local_path);
        if (! is_dir($directorio) && ! mkdir($directorio, 0755, true) && ! is_dir($directorio)) {
            throw new \RuntimeException("No se pudo crear el directorio local {$directorio}.");
        }

        $this->borrar_si_existe($local_path);

        try {
            $response = $this->download_client()
                ->withOptions(['sink' => $local_path])
                ->get($url);
        } catch (\Throwable $e) {
            $this->borrar_si_existe($local_path);

            throw new \RuntimeException(
                "Falló la descarga del artefacto {$nombre}: " . $e->getMessage(),
                0,
                $e
            );
        }

        if ($response->failed()) {
            /* Con `sink`, el cuerpo del error quedó en el archivo: se lee de ahí y se borra. */
            $cuerpo = is_file($local_path) ? (string) file_get_contents($local_path, false, null, 0, 4096) : '';
            $this->borrar_si_existe($local_path);

            throw new \RuntimeException(
                "GitHub respondió {$response->status()} al bajar el artefacto {$nombre}: " . $this->truncar($cuerpo)
            );
        }

        clearstatcache(true, $local_path);
        $bytes = is_file($local_path) ? (int) filesize($local_path) : 0;

        if ($bytes !== $bytes_esperados) {
            $this->borrar_si_existe($local_path);

            throw new \RuntimeException(
                "El artefacto {$nombre} bajó incompleto: el release declara {$bytes_esperados} bytes y llegaron "
                . "{$bytes}. Se borró el archivo local."
            );
        }

        return $bytes;
    }

    /**
     * Baja a un archivo local el ZIP del código fuente de un tag (el "zipball" que GitHub arma solo).
     *
     * Nace en la misión `instalar-sin-el-vps` (10/9/2026) y es ADITIVO: no toca nada de lo de arriba,
     * que `DeploymentService` usa en producción desde el 9/9.
     *
     * 🔴 Existe por una sola cosa: `public/`. El asset `empresa-api-v{v}.zip` lo excluye a propósito
     * —en un upgrade esos archivos son del cliente y no se pisan—, pero una instalación de cero sin
     * `public/index.php` responde 404 en todo. El zipball es la única fuente que sirve para
     * CUALQUIER tag, incluidos los anteriores a la 4.0.23 y los viejos que puede pedir una demo: un
     * asset `-public.zip` habría que agregarlo al workflow y no existiría para ninguna versión ya
     * publicada. Medido el 10/9/2026 en `empresa-api` v4.0.23: 9,25 MB, 2 segundos.
     *
     * Adentro, todo cuelga de un directorio raíz con el sha (`lucasgonzz-empresa-api-bc47e4c/`); ese
     * prefijo lo saca quien re-empaqueta (`ArtefactosDeRelease::artefacto_bajar_public_del_tag()`).
     *
     * Mismo cliente HTTP que `download_asset()`: mismo token en el header, mismo `User-Agent`, mismo
     * `sink` a archivo (nunca a memoria: el admin corre en el shared hosting de Hostinger) y la
     * misma redirección al servidor de archivos, a la que el token no cruza.
     *
     * ⚠️ A diferencia de un asset, el zipball no declara su tamaño en ningún lado: no hay contra qué
     * comparar los bytes que llegaron. Lo único que se verifica acá es que no haya bajado vacío; que
     * sea un zip de verdad y traiga lo que tiene que traer lo verifica quien lo abre.
     *
     * @param string $repo       Nombre del repo, sin owner (ej: `empresa-api`).
     * @param string $tag        Tag (ej: `v4.0.23`).
     * @param string $local_path Ruta local destino; el directorio se crea si no existe.
     *
     * @return int|null Bytes escritos, o `null` si GitHub no tiene ese tag (404, que es también lo
     *                  que responde un repo privado sin token: para este servicio es lo mismo).
     *
     * @throws \InvalidArgumentException Si el repo o el tag vienen vacíos.
     * @throws \RuntimeException         Si GitHub falla de otra manera, o si el archivo bajó vacío.
     */
    public function download_source_zip(string $repo, string $tag, string $local_path): ?int
    {
        $repo = trim($repo);
        $tag  = trim($tag);

        if ($repo === '' || $tag === '') {
            throw new \InvalidArgumentException('Para bajar el zipball hacen falta el repo y el tag.');
        }

        $url = self::GITHUB_API_BASE . '/repos/' . rawurlencode($this->owner()) . '/' . rawurlencode($repo)
            . '/zipball/' . rawurlencode($tag);

        $directorio = dirname($local_path);
        if (! is_dir($directorio) && ! mkdir($directorio, 0755, true) && ! is_dir($directorio)) {
            throw new \RuntimeException("No se pudo crear el directorio local {$directorio}.");
        }

        $this->borrar_si_existe($local_path);

        try {
            $response = $this->download_client()
                ->withOptions(['sink' => $local_path])
                ->get($url);
        } catch (\Throwable $e) {
            $this->borrar_si_existe($local_path);

            throw new \RuntimeException(
                "Falló la descarga del zipball {$tag} de {$repo}: " . $e->getMessage(),
                0,
                $e
            );
        }

        if ($response->status() === 404) {
            $this->borrar_si_existe($local_path);

            return null;
        }

        if ($response->failed()) {
            /* Con `sink`, el cuerpo del error quedó en el archivo: se lee de ahí y se borra. */
            $cuerpo = is_file($local_path) ? (string) file_get_contents($local_path, false, null, 0, 4096) : '';
            $this->borrar_si_existe($local_path);

            throw new \RuntimeException(
                "GitHub respondió {$response->status()} al bajar el zipball {$tag} de {$repo}: "
                . $this->truncar($cuerpo)
            );
        }

        clearstatcache(true, $local_path);
        $bytes = is_file($local_path) ? (int) filesize($local_path) : 0;

        if ($bytes <= 0) {
            $this->borrar_si_existe($local_path);

            throw new \RuntimeException(
                "El zipball {$tag} de {$repo} bajó vacío: no hay de dónde sacar public/."
            );
        }

        return $bytes;
    }

    /**
     * Owner de los repos de los releases.
     *
     * @return string
     */
    private function owner(): string
    {
        $owner = trim((string) config('services.github.releases_owner', self::OWNER_POR_DEFECTO));

        return $owner === '' ? self::OWNER_POR_DEFECTO : $owner;
    }

    /**
     * Cliente para la API JSON.
     *
     * @return PendingRequest
     */
    private function api_client(): PendingRequest
    {
        return $this->cliente_base(self::ACCEPT_API, self::TIMEOUT_API_SEGUNDOS);
    }

    /**
     * Cliente para bajar el binario de un asset.
     *
     * `allow_redirects` explícito: GitHub contesta 302 hacia el servidor de archivos, y sólo por
     * https. El token no cruza a ese otro origen (ver el docblock de la clase).
     *
     * @return PendingRequest
     */
    private function download_client(): PendingRequest
    {
        return $this->cliente_base(self::ACCEPT_BINARIO, self::TIMEOUT_DESCARGA_SEGUNDOS)
            ->withOptions([
                'allow_redirects' => [
                    'max'       => self::MAX_REDIRECCIONES,
                    'protocols' => ['https'],
                ],
            ]);
    }

    /**
     * Cliente HTTP contra GitHub: token en el header si está configurado, `User-Agent` obligatorio y
     * la misma configuración TLS que usa `ManualRepositoryService` (WAMP/Windows suele necesitar el
     * `ca_bundle`).
     *
     * @param string $accept  Header `Accept`.
     * @param int    $timeout Segundos de espera.
     *
     * @return PendingRequest
     */
    private function cliente_base(string $accept, int $timeout): PendingRequest
    {
        $headers = [
            'Accept'     => $accept,
            'User-Agent' => self::USER_AGENT,
        ];

        $token = trim((string) config('services.github.token', ''));
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $http = Http::withHeaders($headers)->timeout($timeout);

        $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
        $ca_bundle  = config('services.anthropic.ca_bundle');

        if (! $verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        return $http;
    }

    /**
     * Borra un archivo local si existe.
     *
     * @param string $path Ruta local.
     *
     * @return void
     */
    private function borrar_si_existe(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Recorta el cuerpo de una respuesta de error para el mensaje de la excepción.
     *
     * @param string $texto Cuerpo crudo.
     *
     * @return string
     */
    private function truncar(string $texto): string
    {
        $texto = trim($texto);

        if (mb_strlen($texto) <= self::CUERPO_MAXIMO_EN_ERROR) {
            return $texto;
        }

        return mb_substr($texto, 0, self::CUERPO_MAXIMO_EN_ERROR) . '…';
    }
}
