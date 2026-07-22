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
 * @property string|null $api_path             Path de instalación del API.
 * @property string|null $spa_path             Path de instalación del SPA.
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
     * Path de instalación del SPA, relativo a `domains/` en el hosting.
     *
     * Convención (definición de Lucas, 22/7/2026): la tienda de cada cliente
     * vive en su propio dominio de Hostinger, no como subcarpeta de
     * comerciocity.com. El SPA se sirve desde `domains/{dominio}/public_html`;
     * acá se guarda solo la parte relativa a `domains/`, o sea
     * `{dominio}/public_html` — el prefijo `domains/` lo agrega el servicio
     * de instalación. La columna `spa_path` siempre gana si tiene valor.
     *
     * @return string
     */
    public function resolve_spa_path()
    {
        $spa_path = trim((string) $this->spa_path, '/');
        if ($spa_path !== '') {
            return $spa_path;
        }

        $domain = $this->resolve_domain();
        if ($domain === '') {
            return '';
        }

        return $domain.'/public_html';
    }

    /**
     * Path de instalación de la API, relativo a `domains/` en el hosting.
     *
     * Misma convención que resolve_spa_path(): tienda-api se sirve desde
     * `domains/{dominio}/public_html/api`. La columna `api_path` siempre
     * gana si tiene valor.
     *
     * @return string
     */
    public function resolve_api_path()
    {
        $api_path = trim((string) $this->api_path, '/');
        if ($api_path !== '') {
            return $api_path;
        }

        $domain = $this->resolve_domain();
        if ($domain === '') {
            return '';
        }

        return $domain.'/public_html/api';
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
