<?php

namespace App\Helpers;

/**
 * URL pública de la API del admin HACIA SÍ MISMO: la que el admin le entrega a otro sistema para
 * que le pegue (misión demo-seguimiento-y-setup-rapido, 9/9/2026).
 *
 * 🔴 CLASE DE ERROR QUE CIERRA: "el admin arma URLs hacia sí mismo con APP_URL, y en el shared
 * hosting APP_URL no incluye /public". En producción `APP_URL=https://api-admin.comerciocity.com`,
 * pero el subdominio apunta a la raíz del proyecto (no a `public/`), así que la API responde bajo
 * `https://api-admin.comerciocity.com/public/api/...`. Medido el 9/9/2026: `demo_eventos_url`
 * viajaba como `https://api-admin.comerciocity.com/api/demo-eventos`, Hostinger contestaba 404 con
 * su HTML, y las instancias demo1 y demo2 acumularon 23 eventos varados con 10 intentos cada uno.
 * El seguimiento de la demo v2 (misiones 48-52) no había llegado NUNCA al admin en producción:
 * `demo_eventos_recibidos` tenía cero filas. Es la misma trampa del `/public` que ya documenta la
 * memoria de la API de Claude, vista desde adentro.
 *
 * Dos fuentes, en este orden:
 *
 *  1. `ADMIN_API_PUBLIC_URL` (config `services.admin_api.public_url`), explícita. Es la que hay
 *     que cargar en producción: `https://api-admin.comerciocity.com/public`.
 *  2. Sin la variable, `APP_URL` más `/public` cuando el admin corre bajo `public_html/` — la
 *     convención del shared hosting de Hostinger, la MISMA que `ClientEmpresaApiUrlResolver`
 *     aplica a toda `ClientApi` con `hosting_type='shared_hosting'`. En local, en el VPS o con un
 *     docroot que ya sea `public/`, queda `APP_URL` tal cual.
 *
 * Detección de la próxima instancia de esta clase de error:
 * `grep -rn "config('app.url')" app/` — toda URL que el admin le entrega a OTRO sistema para que
 * le pegue tiene que salir de acá, no de `app.url` a secas.
 *
 * PHP 7.4: sin `str_contains`, `str_ends_with` ni `?->`.
 */
class AdminApiPublicUrl
{
    /**
     * Base pública de la API del admin, sin barra final. Ej: `https://api-admin.comerciocity.com/public`.
     *
     * @return string Vacío si no hay ni variable ni APP_URL de dónde deducirla.
     */
    public static function base(): string
    {
        $explicita = trim((string) config('services.admin_api.public_url'));

        if ($explicita !== '') {
            return rtrim($explicita, '/');
        }

        return self::base_deducida((string) config('app.url'), base_path());
    }

    /**
     * URL pública de una ruta de `routes/api.php` del admin. El prefijo `/api` lo pone acá,
     * igual que lo pone RouteServiceProvider del lado del servidor.
     *
     * @param string $ruta Ruta relativa, con o sin barra inicial. Ej: `demo-eventos`.
     *
     * @return string Ej: `https://api-admin.comerciocity.com/public/api/demo-eventos`.
     */
    public static function api(string $ruta): string
    {
        return self::base() . '/api/' . ltrim($ruta, '/');
    }

    /**
     * Repliegue sin variable explícita. Es un método aparte, con los dos insumos como parámetros,
     * para poder probarlo sin tocar `base_path()` ni la config.
     *
     * @param string $app_url   Valor de `APP_URL`.
     * @param string $base_path Carpeta raíz del proyecto en el disco (`base_path()`).
     *
     * @return string Base pública sin barra final.
     */
    public static function base_deducida(string $app_url, string $base_path): string
    {
        $base = rtrim(trim($app_url), '/');

        if ($base === '') {
            return '';
        }

        // Ya viene con /public: no se duplica.
        if (substr($base, -7) === '/public') {
            return $base;
        }

        /* Shared hosting de Hostinger: el proyecto vive bajo `.../public_html/<sitio>/api` y el
         * subdominio apunta a esa carpeta, no a `public/`. Se mira la ruta del disco y no el host,
         * porque el host no dice nada sobre cómo está montado el docroot. */
        $ruta = str_replace('\\', '/', $base_path);

        if (strpos($ruta, '/public_html/') !== false) {
            return $base . '/public';
        }

        return $base;
    }
}
