<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tienda online (ecommerce) asociada a un cliente.
 *
 * @property int         $client_id            Cliente dueño de la tienda.
 * @property string|null $domain               Dominio final de la tienda.
 * @property string|null $api_url              URL de la API de la tienda.
 * @property string|null $spa_url              URL del SPA de la tienda.
 * @property string|null $api_path             Path efectivo de instalación de la API (cargado a mano o derivado).
 * @property string|null $spa_path             Path efectivo de instalación del SPA (cargado a mano o derivado).
 * @property string      $status               pending | installing | active.
 * @property array|null  $ecommerce_setup_data Configuración recolectada por WhatsApp.
 */
class ClientEcommerce extends Model
{
    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'client_id',
        'domain',
        'api_url',
        'spa_url',
        'api_path',
        'spa_path',
        'status',
        'ecommerce_setup_data',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'ecommerce_setup_data' => 'array',
    ];

    /**
     * Cliente dueño de esta tienda.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Corridas del pipeline de instalación/actualización de esta tienda.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function installations()
    {
        return $this->hasMany(ClientEcommerceInstallation::class);
    }

    /**
     * Normaliza una URL: castea a string, recorta espacios y saca la barra final.
     *
     * Se usa para comparar/persistir spa_url y api_url de forma consistente,
     * evitando duplicados por diferencias de barra final o espacios sueltos.
     *
     * @param  mixed  $url  Valor crudo recibido (puede venir null, número, etc.)
     * @return string       URL normalizada, o cadena vacía si no queda nada útil.
     */
    public static function normalize_url($url)
    {
        $value = trim((string) $url);
        if ($value === '') {
            return '';
        }

        return rtrim($value, '/');
    }

    /**
     * Normaliza un path de instalación del hosting cargado a mano: lo deja relativo a `domains/`,
     * sin barras al inicio/fin, sin barras dobles y sin tramos "." ni "..".
     *
     * POR QUÉ ES ASÍ Y NO MÁS SIMPLE (no lo "achiques" a un trim de barras): este valor lo escribe
     * Lucas a mano en el modal y termina siendo el destino de un `rm -rf` en el swap atómico del
     * deploy (`EcommerceInstallationService::build_spa_atomic_deploy_shell()`). Lo que se pega en
     * ese campo, en la práctica, es una de tres cosas: la ruta relativa correcta, la ruta con el
     * prefijo `domains/` de más, o la ruta absoluta entera copiada de una sesión SSH
     * (`/home/uXXXXXXXX/domains/...`). Las tres tienen que terminar en el mismo string.
     *
     * @param  mixed  $path  Valor crudo del formulario.
     * @return string        Path relativo a `domains/`, o cadena vacía si no queda nada usable.
     */
    public static function normalize_hosting_path($path)
    {
        $value = trim((string) $path);
        if ($value === '') {
            return '';
        }

        // Barras invertidas a barras normales (una ruta copiada de WinSCP/Windows).
        $value = str_replace('\\', '/', $value);

        $segments = explode('/', $value);

        // Última aparición de un tramo llamado exactamente "domains": se descarta todo lo anterior
        // y ese tramo también. De una sola pasada resuelve "domains/x/public_html/..." y
        // "/home/u123/domains/x/public_html/...", porque el prefijo `domains/` lo agrega después
        // EcommerceInstallationService::HOSTING_PREFIX y no debe quedar duplicado en la columna.
        // (Contrapartida asumida: una carpeta real llamada "domains" adentro de un public_html
        //  cortaría mal. No existe ni tiene sentido que exista en este hosting.)
        $last_domains_index = -1;
        foreach ($segments as $index => $segment) {
            if ($segment === 'domains') {
                $last_domains_index = $index;
            }
        }
        if ($last_domains_index >= 0) {
            $segments = array_slice($segments, $last_domains_index + 1);
        }

        $clean_segments = [];
        foreach ($segments as $segment) {
            $segment = trim($segment);

            // Barras dobles y "./" no cambian el significado del path: se descartan.
            if ($segment === '' || $segment === '.') {
                continue;
            }

            // "Subir un nivel" apuntaría fuera de domains/ y el pipeline hace `rm -rf` sobre este
            // path: se rechaza la entrada ENTERA (se devuelve vacío) y el sistema vuelve a la
            // derivación automática, que siempre es una ruta segura. Es a propósito que no se
            // "limpie" el ".." resolviéndolo: resolver silenciosamente una ruta que alguien
            // escribió mal es peor que ignorarla.
            if ($segment === '..') {
                return '';
            }

            $clean_segments[] = $segment;
        }

        return implode('/', $clean_segments);
    }

    /**
     * Resuelve el host (dominio) de una URL, sin el prefijo "www.".
     *
     * Si el valor no trae esquema (http/https) se le antepone "https://" antes
     * de parsear, porque parse_url() no puede resolver el host de una URL sin
     * esquema (la interpreta toda como path).
     *
     * @param  mixed  $url  URL o dominio suelto (con o sin esquema).
     * @return string       Dominio en minúsculas sin "www.", o cadena vacía si no se pudo resolver.
     */
    public static function domain_from_url($url)
    {
        $value = trim((string) $url);
        if ($value === '') {
            return '';
        }

        // Si no trae esquema, se lo agregamos para que parse_url() pueda resolver el host.
        if (strpos($value, '://') === false) {
            $value = 'https://'.$value;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (empty($host)) {
            return '';
        }

        $host = strtolower($host);

        // Prohibido str_starts_with (PHP 7.4): se usa strpos === 0.
        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }

        return $host;
    }

    /**
     * Dominio efectivo de la tienda.
     *
     * La columna `domain` siempre gana si tiene valor (permite pisar un caso
     * especial a mano en la base sin tocar código). Si está vacía, se deriva
     * del host de `spa_url`.
     *
     * @return string
     */
    public function resolve_domain()
    {
        $domain = trim((string) $this->domain);
        if ($domain !== '') {
            return $domain;
        }

        return self::domain_from_url($this->spa_url);
    }

    /**
     * Path del SPA DERIVADO del dominio, ignorando la columna `spa_path`.
     *
     * Existe separado de resolve_spa_path() porque hay dos lugares que necesitan saber cuál sería
     * el path si nadie hubiera cargado uno a mano: manual_spa_path() (para decidir si lo guardado
     * es un path manual o el derivado que materializó el propio guardado) y el hint del modal.
     *
     * @return string
     */
    public function derived_spa_path(): string
    {
        $domain = $this->resolve_domain();
        if ($domain === '') {
            return '';
        }

        return $domain.'/public_html';
    }

    /**
     * Path de la API DERIVADO del dominio, ignorando la columna `api_path`.
     *
     * Misma razón de ser que derived_spa_path(), para la API.
     *
     * @return string
     */
    public function derived_api_path(): string
    {
        $domain = $this->resolve_domain();
        if ($domain === '') {
            return '';
        }

        return $domain.'/public_html/api';
    }

    /**
     * Path de instalación del SPA, relativo a `domains/` en el hosting.
     *
     * Convención (definición de Lucas, 22/7/2026): la tienda de cada cliente
     * vive en su propio dominio de Hostinger, no como subcarpeta de
     * comerciocity.com. El SPA se sirve desde `domains/{dominio}/public_html`;
     * acá se guarda solo la parte relativa a `domains/`, o sea
     * `{dominio}/public_html` — el prefijo `domains/` lo agrega el servicio
     * de instalación. La columna `spa_path` siempre gana si tiene valor.
     *
     * Desde la misión ecommerce-paths-subcarpeta esa columna también puede traer un path cargado
     * a mano en el modal, que apunta a una carpeta física arbitraria del hosting (por ejemplo
     * `comerciocity.store/public_html/tienda/spa`) sin relación con el host de la URL pública.
     * La derivación por dominio vive ahora en derived_spa_path().
     *
     * @return string
     */
    public function resolve_spa_path()
    {
        $spa_path = trim((string) $this->spa_path, '/');
        if ($spa_path !== '') {
            return $spa_path;
        }

        return $this->derived_spa_path();
    }

    /**
     * Path de instalación de la API, relativo a `domains/` en el hosting.
     *
     * Misma convención que resolve_spa_path(): tienda-api se sirve desde
     * `domains/{dominio}/public_html/api`. La columna `api_path` siempre
     * gana si tiene valor, y desde la misión ecommerce-paths-subcarpeta ese valor puede ser un
     * path cargado a mano (una carpeta arbitraria del hosting, incluso hermana de la del SPA).
     *
     * @return string
     */
    public function resolve_api_path()
    {
        $api_path = trim((string) $this->api_path, '/');
        if ($api_path !== '') {
            return $api_path;
        }

        return $this->derived_api_path();
    }

    /**
     * Path del SPA cargado A MANO, o cadena vacía si lo que hay guardado es el derivado.
     *
     * POR QUÉ EXISTE (no lo reemplaces por `$this->spa_path` a secas): la columna `spa_path`
     * guarda SIEMPRE el path efectivo, incluido el derivado, porque
     * ClientController::sync_ecommerce_urls_from_request() lo materializa en cada guardado (y así
     * se puede mirar la base y ver dónde está instalada cada tienda). O sea que "columna con
     * valor" NO significa "path cargado a mano". La única forma de distinguirlos sin agregar una
     * columna es comparar contra la derivación: si coinciden, no es manual.
     *
     * De esto dependen dos cosas: que el campo del modal se vea VACÍO en los 40 clientes que hoy
     * tienen el path derivado guardado, y que el recálculo por cambio de dominio siga funcionando
     * para ellos.
     *
     * Limitación conocida y aceptada: si alguien carga a mano un path que es exactamente el
     * derivado, el sistema lo trata como derivado (el campo se ve vacío la próxima vez). El
     * efecto es idéntico salvo que después se cambie el dominio.
     *
     * @return string
     */
    public function manual_spa_path(): string
    {
        $stored = self::normalize_hosting_path($this->spa_path);
        if ($stored === '' || $stored === $this->derived_spa_path()) {
            return '';
        }

        return $stored;
    }

    /**
     * Path de la API cargado A MANO, o cadena vacía si lo guardado es el derivado.
     *
     * Misma lógica y mismas advertencias que manual_spa_path().
     *
     * @return string
     */
    public function manual_api_path(): string
    {
        $stored = self::normalize_hosting_path($this->api_path);
        if ($stored === '' || $stored === $this->derived_api_path()) {
            return '';
        }

        return $stored;
    }

    /**
     * Tramo de resolve_api_path() que queda anidado ADENTRO de resolve_spa_path() (prompt 191/01).
     *
     * Por la convención de subdominios de Hostinger confirmada el 22/7/2026 (ver
     * `resolve_api_path()`), lo normal es que tienda-api viva dentro del docroot del SPA
     * (`{dominio}/public_html/api`). Ese subpath hay que preservarlo explícitamente cuando el
     * deploy del SPA reemplaza el docroot entero (`build_spa_atomic_deploy_shell()` en
     * `EcommerceInstallationService`), porque de lo contrario el `rm -rf` del docroot viejo se
     * lleva puesta la API instalada del cliente.
     *
     * Devuelve cadena vacía cuando no hay nada que preservar: si algún cliente tiene la API
     * cargada a mano en un dominio o carpeta separada del SPA (caso legítimo, columnas `spa_path`/
     * `api_path` con valores independientes), no hay anidamiento y el deploy debe comportarse
     * exactamente como antes de este fix, sin preservar nada de más.
     *
     * @return string  Subpath relativo (sin barra inicial ni final), o '' si la API no está anidada.
     */
    public function api_subpath_inside_spa_docroot(): string
    {
        // Normaliza ambos paths sin barras al inicio/fin para poder compararlos como texto plano.
        $spa_path = trim((string) $this->resolve_spa_path(), '/');
        $api_path = trim((string) $this->resolve_api_path(), '/');

        // Sin dominio/paths cargados no hay nada que resolver.
        if ($spa_path === '' || $api_path === '') {
            return '';
        }

        // La API está anidada solo si su path arranca exactamente con "{spa_path}/". Si no
        // matchea (API en otro dominio, o mismo string que el SPA sin subcarpeta), no hay
        // anidamiento: se devuelve vacío para no preservar nada que no corresponda.
        $spa_prefix = $spa_path . '/';
        if (substr($api_path, 0, strlen($spa_prefix)) !== $spa_prefix) {
            return '';
        }

        // Resto del path después del prefijo del SPA (con la convención actual, "api").
        return substr($api_path, strlen($spa_prefix));
    }
}
