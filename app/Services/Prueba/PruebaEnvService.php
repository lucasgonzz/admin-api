<?php

namespace App\Services\Prueba;

use App\Helpers\AdminApiPublicUrl;
use App\Models\Client;
use App\Models\EnvTemplate;
use App\Models\TrialInstance;
use App\Services\EcommerceDistCustomizer;
use App\Services\EcommerceInstallationService;
use App\Services\EnvSshService;
use App\Services\SpaRuntimeConfig;

/**
 * Arma el contenido de los archivos de configuración de una instancia del pool (decisión D3 del plan
 * de `prueba-alta-instancia`, 3/11/2026): los dos `.env` (ERP y tienda) a partir de `env_templates`
 * —la misma plantilla que usa la instalación de un cliente— y los dos `config.js` de las SPA, con las
 * mismas variables que `InstallationService::build_spa_env_vars()` y
 * `EcommerceInstallationService::build_tienda_spa_env_vars()`.
 *
 * Es cálculo puro sobre la base del admin: no toca el VPS (eso es `PruebaVpsService`).
 *
 * Los archivos nacen con los HOSTS DEL POOL (`api-prueba-NN`...). `prueba-asignar.sh` los reescribe con
 * el slug del negocio (sed de `prueba-NN.comerciocity.com` → `<slug>.comerciocity.com`, que alcanza a
 * los cuatro hosts porque todos terminan igual) y el admin escribe después las claves del cliente
 * (`variables_del_cliente()`).
 */
class PruebaEnvService
{
    /** Nombre que lleva la tienda del pool mientras no tiene negocio. */
    const NOMBRE_TIENDA_DEL_POOL = 'Tienda de prueba';

    /**
     * `APP_KEY` nueva para una instancia: la misma en el ERP y en la tienda (decisión de producto de las
     * tiendas: comparten base y clave).
     */
    public static function generar_app_key(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    /**
     * Las variables del `.env` del ERP de una instancia del pool.
     *
     * @param array<string, string> $db      `['name' => ..., 'user' => ..., 'pass' => ...]` de `pool-crear.sh`.
     * @param string                $app_key La `APP_KEY` generada con `generar_app_key()`.
     *
     * @return array<string, string>
     */
    public function variables_erp(TrialInstance $instancia, array $db, string $app_key): array
    {
        $vars  = $this->plantilla('empresa');
        $hosts = $instancia->hosts_del_pool();

        $clave_deepseek = trim((string) config('services.prueba.deepseek_api_key'));
        if ($clave_deepseek !== '') {
            $vars['DEEPSEEK_API_KEY'] = $clave_deepseek;
        }

        $vars['APP_KEY']                 = $app_key;
        $vars['APP_URL']                 = 'https://' . $hosts['erp_api'];
        $vars['SANCTUM_STATEFUL_DOMAINS'] = $hosts['erp_spa'];
        $vars['SANCTUM_STATEFUL_CORS']   = 'https://' . $hosts['erp_spa'];
        $vars['USER_ID']                 = (string) $instancia->user_id;

        /* La base propia (F2/F4 de /migrar-cliente): sin prefijo de cuenta, en el MySQL del VPS. */
        $vars['DB_CONNECTION'] = 'mysql';
        $vars['DB_HOST']       = '127.0.0.1';
        $vars['DB_PORT']       = '3306';
        $vars['DB_DATABASE']   = (string) $db['name'];
        $vars['DB_USERNAME']   = (string) $db['user'];
        $vars['DB_PASSWORD']   = (string) $db['pass'];

        /* Lo propio de un .env de VPS (aplicar_variables_del_vps + F4 de la migración): la bandera, Redis
           con prefijo propio —el VPS tiene UN Redis para todos— y la cola por Redis, que es la que consume
           el worker de supervisor que crea pool-crear.sh. */
        $vars['VPS']              = 'true';
        $vars['CACHE_DRIVER']     = 'redis';
        $vars['QUEUE_CONNECTION'] = 'redis';
        $vars['REDIS_HOST']       = '127.0.0.1';
        $vars['REDIS_PORT']       = '6379';
        $vars['REDIS_CLIENT']     = 'phpredis';
        $vars['CACHE_PREFIX']     = PruebaNombres::prefijo_redis($instancia->nombre);
        $vars['REDIS_PREFIX']     = PruebaNombres::prefijo_redis($instancia->nombre);

        /* Es una prueba (M3 lo acepta en empresa: modo prueba del demo setup, modal de primer ingreso,
           pantalla de vencimiento). */
        $vars['FOR_USER'] = 'prueba';

        /* El .env nace cerrado a admin-sync, como el de una instalación. La clave real del cliente la
           escribe ArmarPruebaJob al asignar; hasta entonces hay una al azar que nadie conoce. */
        $vars['ADMIN_API_INBOUND_KEY']            = bin2hex(random_bytes(20));
        $vars['ADMIN_API_OUTBOUND_KEY']           = '';
        $vars['ADMIN_SYNC_REQUIRE_API_KEY']       = 'true';
        $vars['ADMIN_SYNC_SETUP_REQUIRE_API_KEY'] = 'true';
        $vars['ADMIN_API_URL']                    = AdminApiPublicUrl::base();

        return $vars;
    }

    /**
     * Las variables del `.env` de la tienda de una instancia del pool (plantilla `scope = tienda` más
     * lo que `EcommerceInstallationService::step_write_env()` deriva del dominio y copia del ERP).
     *
     * @param array<string, string> $db
     * @param array<string, string> $vars_erp Las del ERP, para copiar lo compartido (base, APP_KEY, MAIL_PASSWORD).
     *
     * @return array<string, string>
     */
    public function variables_tienda(TrialInstance $instancia, array $db, array $vars_erp): array
    {
        $vars  = $this->plantilla('tienda');
        $hosts = $instancia->hosts_del_pool();

        $vars['APP_NAME'] = self::NOMBRE_TIENDA_DEL_POOL;
        $vars['APP_KEY']  = (string) $vars_erp['APP_KEY'];
        $vars['APP_URL']  = 'https://' . $hosts['tienda_api'];

        $vars['SANCTUM_STATEFUL_DOMAINS']  = $hosts['tienda_spa'];
        $vars['SANCTUM_STATEFUL_CORS']     = 'https://' . $hosts['tienda_spa'];
        $vars['SANCTUM_STATEFUL_CORS_WWW'] = 'https://www.' . $hosts['tienda_spa'];

        /* Mismo cálculo que una tienda instalada por el pipeline: el padre común de los dos hosts. */
        $session_domain          = EcommerceInstallationService::derive_session_domain('https://' . $hosts['tienda_spa'], 'https://' . $hosts['tienda_api']);
        $vars['SESSION_DOMAIN']  = $session_domain !== '' ? $session_domain : 'null';

        $vars['DB_CONNECTION'] = 'mysql';
        $vars['DB_HOST']       = '127.0.0.1';
        $vars['DB_PORT']       = '3306';
        $vars['DB_DATABASE']   = (string) $db['name'];
        $vars['DB_USERNAME']   = (string) $db['user'];
        $vars['DB_PASSWORD']   = (string) $db['pass'];

        if (isset($vars_erp['MAIL_PASSWORD']) && (string) $vars_erp['MAIL_PASSWORD'] !== '') {
            $vars['MAIL_PASSWORD'] = (string) $vars_erp['MAIL_PASSWORD'];
        }

        /* La tienda no usa cola ni caché compartida (QUEUE_CONNECTION=sync en la plantilla), pero si la
           plantilla trajera Redis, que no se mezcle con nadie. */
        $vars['CACHE_PREFIX'] = PruebaNombres::prefijo_redis($instancia->ecommerce_vps_path);
        $vars['REDIS_PREFIX'] = PruebaNombres::prefijo_redis($instancia->ecommerce_vps_path);

        return $vars;
    }

    /**
     * Las variables de admin-sync que se escriben en el `.env` del ERP al ASIGNAR la instancia a un
     * cliente (las mismas que `aplicar_cierre_de_admin_sync()` de la instalación).
     *
     * @return array<string, string>
     */
    public function variables_del_cliente(Client $client): array
    {
        return [
            'ADMIN_API_INBOUND_KEY'            => trim((string) $client->api_key),
            'ADMIN_API_OUTBOUND_KEY'           => trim((string) $client->inbound_api_key),
            'ADMIN_SYNC_REQUIRE_API_KEY'       => 'true',
            'ADMIN_SYNC_SETUP_REQUIRE_API_KEY' => 'true',
            'ADMIN_API_URL'                    => AdminApiPublicUrl::base(),
            'ADMIN_API_CLIENT_UUID'            => (string) $client->uuid,
            'FOR_USER'                         => 'prueba',
        ];
    }

    /** `config.js` del SPA del ERP (las mismas claves que `InstallationService::build_spa_env_vars()`). */
    public function config_js_erp(TrialInstance $instancia): string
    {
        $hosts = $instancia->hosts_del_pool();

        $vars = [
            'VUE_APP_API_URL'        => 'https://' . $hosts['erp_api'],
            'VUE_APP_APP_URL'        => 'https://' . $hosts['erp_spa'],
            'VUE_APP_PUSHER_KEY'     => trim((string) config('services.deploy.spa_pusher_key', '')),
            'VUE_APP_PUSHER_CLUSTER' => trim((string) config('services.deploy.spa_pusher_cluster', 'sa1')),
        ];

        $spa_build_env = config('services.deploy.spa_build_env', []);
        if (is_array($spa_build_env)) {
            foreach ($spa_build_env as $clave => $valor) {
                $vars[(string) $clave] = trim((string) $valor);
            }
        }

        return SpaRuntimeConfig::render($vars);
    }

    /** `config.js` del SPA de la tienda (las claves de `build_tienda_spa_env_vars()` del pipeline de tiendas). */
    public function config_js_tienda(TrialInstance $instancia): string
    {
        $hosts   = $instancia->hosts_del_pool();
        $spa_url = 'https://' . $hosts['tienda_spa'];

        $vars = [
            'VUE_APP_API_URL'          => 'https://' . $hosts['tienda_api'],
            'VUE_APP_COMMERCE_ID'      => (string) $instancia->user_id,
            'VUE_APP_APP_URL'          => $spa_url,
            'VUE_APP_PUSHER_KEY'       => trim((string) config('services.deploy.spa_pusher_key', '')),
            'VUE_APP_PUSHER_CLUSTER'   => trim((string) config('services.deploy.spa_pusher_cluster', 'sa1')),
            'VUE_APP_SITE_NAME'        => self::NOMBRE_TIENDA_DEL_POOL,
            'VUE_APP_SITE_DESCRIPTION' => EcommerceDistCustomizer::normalize_description(self::NOMBRE_TIENDA_DEL_POOL),
            'VUE_APP_SITE_IMAGE'       => $spa_url . '/img/og-image.png',
            'VUE_APP_SITE_URL'         => $spa_url,
        ];

        return SpaRuntimeConfig::render($vars);
    }

    /**
     * El texto del `.env` a partir de las variables, con el formato que phpdotenv acepta
     * (`EnvSshService::format_env_value()`), en el orden recibido.
     *
     * @param array<string, string> $vars
     */
    public function contenido_env(array $vars): string
    {
        $formato = app(EnvSshService::class);
        $lineas  = [];

        foreach ($vars as $clave => $valor) {
            $lineas[] = $clave . '=' . $formato->format_env_value((string) $valor);
        }

        return implode("\n", $lineas) . "\n";
    }

    /**
     * La plantilla de un scope como KEY => valor (vacío cuando la fila no tiene valor), ordenada como
     * la escribe el pipeline de instalación.
     *
     * @return array<string, string>
     */
    private function plantilla(string $scope): array
    {
        $vars = [];

        $filas = EnvTemplate::where('scope', $scope)->orderBy('group')->orderBy('sort_order')->get();
        foreach ($filas as $fila) {
            $vars[(string) $fila->key] = (string) ($fila->value ?? '');
        }

        return $vars;
    }
}
