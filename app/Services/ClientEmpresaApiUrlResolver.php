<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientVersionUpgrade;

/**
 * Resuelve la URL base del empresa-api de un cliente para llamadas salientes (admin-sync, etc.).
 *
 * Prioridad: API destino del upgrade → API activa del cliente → primera ClientApi → clients.api_url (legacy).
 */
class ClientEmpresaApiUrlResolver
{
    /**
     * Ruta relativa del endpoint publish-version en empresa-api.
     */
    const PUBLISH_VERSION_PATH = 'api/admin-sync/publish-version';

    /**
     * Ruta relativa para actualizar default_version / api_url tras deployment.
     */
    const UPDATE_DEFAULT_VERSION_PATH = 'api/admin-sync/update-default-version';

    /**
     * Ruta relativa del endpoint de branding (logo/color/nombre) en empresa-api.
     */
    const BRANDING_PATH = 'api/admin-sync/branding';

    /**
     * Ruta relativa del endpoint que recibe los horarios comerciales del cliente en empresa-api.
     */
    const BUSINESS_HOURS_PATH = 'api/admin-sync/business-hours';

    /**
     * Devuelve la URL base del empresa-api (sin slash final) o cadena vacía si no hay URL válida.
     * Cada candidato se normaliza con su hosting_type asociado, agregando /public en shared_hosting si corresponde.
     *
     * @param  Client  $client
     * @param  ClientVersionUpgrade|null  $upgrade
     * @return string
     */
    public function resolve_base_url(Client $client, ?ClientVersionUpgrade $upgrade = null): string
    {
        $client->loadMissing('active_client_api', 'client_apis');

        if ($upgrade !== null) {
            $upgrade->loadMissing('target_client_api');
        }

        // Lista de candidatos: cada uno es un array [url, hosting_type]
        $candidates = [];

        // Prioridad 1: API destino del upgrade
        if ($upgrade !== null && $upgrade->target_client_api instanceof ClientApi) {
            $hosting_type = $upgrade->target_client_api->hosting_type ?? 'shared_hosting';
            $candidates[] = [$upgrade->target_client_api->url, $hosting_type];
        }

        // Prioridad 2: API activa del cliente
        if ($client->active_client_api instanceof ClientApi) {
            $hosting_type = $client->active_client_api->hosting_type ?? 'shared_hosting';
            $candidates[] = [$client->active_client_api->url, $hosting_type];
        }

        // Prioridad 3: Cada ClientApi del cliente
        foreach ($client->client_apis as $client_api) {
            $hosting_type = $client_api->hosting_type ?? 'shared_hosting';
            $candidates[] = [$client_api->url, $hosting_type];
        }

        // Prioridad 4: clients.api_url (legacy, sin ClientApi detras)
        // Se trata como VPS porque es un valor histórico cargado a mano y no sabemos
        // con qué convención se guardó; mejor no agregar /public a un legacy.
        $candidates[] = [$client->api_url, 'vps'];

        foreach ($candidates as [$candidate_url, $hosting_type]) {
            $normalized = $this->normalize_api_base_url($candidate_url, $hosting_type);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    /**
     * URL completa hacia un path de admin-sync (p. ej. publish-version).
     *
     * @param  Client  $client
     * @param  string  $path  Ruta sin slash inicial (ej. api/admin-sync/publish-version)
     * @param  ClientVersionUpgrade|null  $upgrade
     * @return string  Vacío si no se pudo resolver la base
     */
    public function admin_sync_url(Client $client, string $path, ?ClientVersionUpgrade $upgrade = null): string
    {
        $base_url = $this->resolve_base_url($client, $upgrade);
        if ($base_url === '') {
            return '';
        }

        return $base_url . '/' . ltrim($path, '/');
    }

    /**
     * Normaliza y valida la URL, agregando /public en hosting compartido si corresponde.
     *
     * Realiza trim, rtrim de slash, validación de esquema http/https y host.
     * Antes de decidir si agrega /public, saca TODAS las repeticiones finales que ya
     * tuviera (con strip_public_suffix()), para que el resultado sea idempotente aunque
     * la URL de origen venga sucia de una carga vieja (ej. "/public/public"). Grupo 237.
     * En shared_hosting o null: agrega un único /public.
     * En vps: devuelve sin agregarlo (no se asume /public sobre valores legacy).
     *
     * @param  string|null  $url
     * @param  string|null  $hosting_type  'shared_hosting', 'vps' o null (default shared_hosting)
     * @return string  URL normalizada o cadena vacía si no es válida
     */
    public function normalize_api_base_url(?string $url, ?string $hosting_type = null): string
    {
        // Normalizar y validar usando la lógica base (trim, rtrim, esquema http/https, host)
        $url = $this->normalize_base_url($url);
        if ($url === '') {
            return '';
        }

        // Sacar todas las repeticiones finales de "/public" antes de decidir si agregar una,
        // para que sea idempotente sin importar cuántas veces venga repetido el sufijo.
        $url = self::strip_public_suffix($url);

        // Default a shared_hosting si no se especifica
        if ($hosting_type === null) {
            $hosting_type = 'shared_hosting';
        }

        // En shared_hosting: agregar un único /public
        if ($hosting_type === 'shared_hosting') {
            if (substr($url, -7) !== '/public') {
                $url .= '/public';
            }
        }

        // En vps: devolver sin agregarlo

        return $url;
    }

    /**
     * Saca todas las repeticiones finales de "/public" de una URL (no solo una).
     *
     * Mismo criterio que ClientApiController::normalize_api_url(): usa "while" en vez de "if"
     * porque columnas cargadas a mano en el pasado pueden tener "/public/public" o más.
     * Método estático para poder reutilizarlo tanto acá (normalize_api_base_url) como desde
     * comandos artisan de barrido de datos existentes.
     *
     * @param  string  $url  URL ya recortada de barra final.
     * @return string  URL sin sufijos "/public" finales ni barra final.
     */
    public static function strip_public_suffix(string $url): string
    {
        // Asegurar que no quede barra final antes de comparar el sufijo.
        $url = rtrim($url, '/');

        // Sacar repeticiones de "/public" mientras el final de la URL matchee.
        while (substr($url, -7) === '/public') {
            $url = rtrim(substr($url, 0, -7), '/');
        }

        return $url;
    }

    /**
     * Calcula la URL de API a usar en VUE_APP_API_URL / APP_URL del .env de una instancia,
     * según el hosting_type del ClientApi destino.
     *
     * Unifica la lógica que antes estaba duplicada como método privado "get_api_url_for_env()"
     * en DeploymentService e InstallationService (grupo 237): ambos llaman a este método para
     * quedar sincronizados y aprovechar la limpieza idempotente de "/public" repetidos.
     *
     * @param  ClientApi  $target_api  ClientApi destino del deploy/instalación.
     * @return string  URL normalizada, con /public en shared_hosting o sin él en vps.
     */
    public function build_api_url_for_env(ClientApi $target_api): string
    {
        return $this->normalize_api_base_url($target_api->url, $target_api->hosting_type);
    }

    /**
     * Normaliza la URL de API de una demo aplicando la regla de /public según su hosting.
     *
     * En hosting compartido la API se sirve desde domains/comerciocity.com/public_html/{slug}/api,
     * y el subdominio apunta a esa carpeta: hay que entrar por /public. En el VPS el docroot YA es
     * public/, así que agregarlo daría public/public y 404 en todo — la misma trampa que documenta
     * §2.1 del informe 20260826-plan-migracion-shared-a-vps.md para los clientes.
     *
     * 🔴 El segundo parámetro es opcional y su default es shared_hosting: un llamador que no lo
     * pase se comporta byte por byte como antes de que existiera. Esa es la compatibilidad hacia
     * atrás, y no depende de que nadie se acuerde de nada. Quien sí lo pasa debe sacarlo de
     * DemoPathResolver::hosting_type(), no de la columna cruda, para que el default y el rechazo
     * de valores basura vivan en un solo lugar.
     *
     * Si la URL no es absoluta (ej: empresa.local:8000 del seeder local), devuelve el valor crudo
     * con trim/rtrim pero SIN agregar /public en ningún hosting, porque agregarle /public a una URL
     * local rompería artisan serve. Esa rama es además la que DemoUpdateService::step_verify_demo()
     * usa para reconocer una demo local y saltearse los chequeos HTTP.
     *
     * @param  string|null  $url  URL de API de la demo (puede incluir o no /public)
     * @param  string|null  $hosting_type  'shared_hosting', 'vps' o null (default shared_hosting)
     * @return string  URL normalizada, o cadena vacía si está vacía
     */
    public function normalize_demo_api_base_url(?string $url, ?string $hosting_type = null): string
    {
        $url_trimmed = rtrim(trim((string) $url), '/');
        if ($url_trimmed === '') {
            return '';
        }

        if ($hosting_type === null) {
            $hosting_type = 'shared_hosting';
        }

        // Intentar normalizar como URL absoluta con el hosting de la demo
        $normalized = $this->normalize_api_base_url($url_trimmed, $hosting_type);
        if ($normalized !== '') {
            return $normalized;
        }

        // Si normalize_api_base_url() devolvió vacío (URL no es http/https),
        // es una URL local (ej: empresa.local:8000). Devolverla cruda sin /public.
        return $url_trimmed;
    }

    /**
     * Normaliza y valida que la URL sea absoluta (http/https) con host resoluble.
     *
     * @param  string|null  $url
     * @return string
     */
    protected function normalize_base_url(?string $url): string
    {
        $url = rtrim(trim((string) $url), '/');
        if ($url === '') {
            return '';
        }

        if (! preg_match('#^https?://#i', $url)) {
            return '';
        }

        $parsed = parse_url($url);
        if (empty($parsed['host'])) {
            return '';
        }

        return $url;
    }
}
