<?php

namespace App\Services;

use App\Models\Demo;

/**
 * Resuelve, para una demo, en qué servidor vive y qué rutas remotas le corresponden.
 *
 * Es el gemelo de ClientApiPathResolver, que hace lo mismo para un cliente. Son dos clases y no
 * una porque las reglas difieren en dos puntos reales, y meter las dos políticas adentro de un
 * mismo método es exactamente la divergencia silenciosa que ClientApiPathResolver vino a evitar:
 *
 *   1. El path de hosting compartido de un CLIENTE sale de una columna (`client_apis.path`). El de
 *      una DEMO no existe como columna: se deriva del slug de `erp_spa_url`.
 *   2. Un cliente en `vps` SIN `vps_path` es un error y tira excepción. Una demo sin `erp_vps_path`
 *      cae al slug (decisión de Lucas, 26/8/2026): el caso normal es que el sitio del VPS se llame
 *      igual que el subdominio, y obligar a cargarlo a mano sería pura ceremonia.
 *
 * 🔴 Lee SOLO las columnas `erp_*`. `ecommerce_hosting_type` / `ecommerce_vps_path` existen en la
 * tabla porque Lucas quiso el dato por sistema, pero al 26/8/2026 no hay pipeline de actualización
 * de ecommerce para demos que los consuma. Si algún día lo hay, va a necesitar sus propios métodos
 * acá — no reutilizar estos asumiendo que "es lo mismo".
 */
class DemoPathResolver
{
    /**
     * Prefijo de las rutas del hosting compartido de Hostinger, relativo al home del usuario SSH.
     */
    const SHARED_HOSTING_PREFIX = 'domains/comerciocity.com/public_html/';

    /**
     * Tipo de hosting del ERP de la demo, normalizado.
     *
     * Cualquier valor que no sea exactamente 'vps' cae a 'shared_hosting': un dato basura en la
     * columna nunca puede hacer que el pipeline elija el camino nuevo por accidente.
     *
     * @param  Demo  $demo
     * @return string  'shared_hosting' | 'vps'
     */
    public function hosting_type(Demo $demo): string
    {
        $hosting_type = trim((string) ($demo->erp_hosting_type ?? ''));

        return $hosting_type === 'vps' ? 'vps' : 'shared_hosting';
    }

    /**
     * ¿El ERP de esta demo vive en el VPS?
     *
     * @param  Demo  $demo
     * @return bool
     */
    public function is_vps(Demo $demo): bool
    {
        return $this->hosting_type($demo) === 'vps';
    }

    /**
     * Tipo de credencial SSH/SFTP con la que se llega al servidor de esta demo.
     *
     * Es el valor de la columna `type` de `client_ssh_credentials`, igual que en
     * ClientApiPathResolver::credential_type().
     *
     * @param  Demo  $demo
     * @return string  'shared_hosting' | 'vps'
     */
    public function credential_type(Demo $demo): string
    {
        return $this->hosting_type($demo);
    }

    /**
     * Slug de la demo, deducido de su URL de SPA.
     *
     * @param  Demo  $demo
     * @return string  Cadena vacía si la URL no permite deducirlo.
     */
    public function slug(Demo $demo): string
    {
        return $this->slug_from_url((string) $demo->erp_spa_url);
    }

    /**
     * Infiere el slug a partir de una URL de SPA: demo.comerciocity.com -> "demo".
     *
     * 🔴 Se normaliza ANTES de parsear (regla del 17/8/2026), y el caso que lo justifica es
     * angosto: medido con PHP 7.4.33, `parse_url('demo3.comerciocity.com', PHP_URL_HOST)` devuelve
     * **null** —sin puerto no reconoce host—, mientras que con puerto (`empresa.local:8080`) sí lo
     * reconoce. O sea que el slug quedaba vacío justo para una demo de producción cargada a mano
     * sin esquema, que es la forma más común, y las rutas del hosting se armaban como
     * `public_html//spa`: el ZIP subido a un directorio equivocado, sin ningún error.
     *
     * Recibe un string y no una Demo porque DemoUpdateService::slug_from_url() delega acá, y ese
     * método se prueba por reflexión con URLs sueltas (tests/Unit/DemoUpdateServiceSlugTest.php).
     *
     * @param  string  $url
     * @return string
     */
    public function slug_from_url(string $url): string
    {
        $host = parse_url(DemoUrlNormalizer::absolute($url), PHP_URL_HOST);
        if ($host === null || $host === false) {
            $host = '';
        }

        // El slug es el primer segmento del hostname (antes del primer punto).
        $partes = explode('.', $host);

        return $partes[0];
    }

    /**
     * Dominio completo del SPA de la demo (demo3.comerciocity.com), sin esquema ni puerto.
     *
     * Sale de `erp_spa_url` y no de "{vps_slug}.comerciocity.com" a propósito: es el dato real que
     * cargó el operador, es el mismo que verifica DemoUpdateService::step_verify_demo(), y es
     * exactamente el criterio que ya usa DeploymentService::get_spa_path() con `spa_url`.
     *
     * @param  Demo  $demo
     * @return string  Cadena vacía si no se pudo deducir.
     */
    public function spa_domain(Demo $demo): string
    {
        $host = parse_url(DemoUrlNormalizer::absolute((string) $demo->erp_spa_url), PHP_URL_HOST);
        if ($host === null || $host === false) {
            return '';
        }

        return (string) $host;
    }

    /**
     * Identificador de la demo dentro del VPS: `erp_vps_path` si está cargado, si no el slug.
     *
     * @param  Demo  $demo
     * @return string
     * @throws \RuntimeException Si no hay ni vps_path ni slug del que deducirlo.
     */
    public function vps_slug(Demo $demo): string
    {
        $vps_path = trim((string) ($demo->erp_vps_path ?? ''));
        if ($vps_path !== '') {
            return $vps_path;
        }

        $slug = $this->slug($demo);
        if ($slug === '') {
            throw new \RuntimeException(
                'La demo está marcada como VPS pero no se pudo determinar su ubicación: no tiene '
                . 'cargado el campo «VPS Path ERP» ni una «ERP SPA URL» de la que deducirlo. '
                . 'Completá alguno de los dos desde el módulo de Demos.'
            );
        }

        return $slug;
    }

    /**
     * Directorio raíz de la API de la demo en su servidor.
     *
     * - shared_hosting: ruta RELATIVA al home del usuario SSH
     *   (domains/comerciocity.com/public_html/{slug}/api).
     * - vps: ruta ABSOLUTA (/home/api-{vps_slug}/empresa-api), misma convención que un cliente.
     *
     * @param  Demo  $demo
     * @return string
     * @throws \RuntimeException Si no se puede armar una ruta completa.
     */
    public function api_path(Demo $demo): string
    {
        if ($this->is_vps($demo)) {
            return '/home/api-' . $this->vps_slug($demo) . '/empresa-api';
        }

        return self::SHARED_HOSTING_PREFIX . $this->assert_slug($demo) . '/api';
    }

    /**
     * Directorio del SPA de la demo en su servidor.
     *
     * - shared_hosting: ruta RELATIVA (domains/comerciocity.com/public_html/{slug}/spa).
     * - vps: ruta ABSOLUTA (/home/{vps_slug}/htdocs/{dominio_spa}), misma convención que un cliente.
     *
     * @param  Demo  $demo
     * @return string
     * @throws \RuntimeException Si no se puede armar una ruta completa.
     */
    public function spa_path(Demo $demo): string
    {
        if ($this->is_vps($demo)) {
            $spa_domain = $this->spa_domain($demo);
            if ($spa_domain === '') {
                throw new \RuntimeException(
                    'La demo está marcada como VPS pero no se pudo determinar el dominio de su SPA '
                    . 'a partir de la «ERP SPA URL». Cargala desde el módulo de Demos antes de '
                    . 'actualizar.'
                );
            }

            return '/home/' . $this->vps_slug($demo) . '/htdocs/' . $spa_domain;
        }

        return self::SHARED_HOSTING_PREFIX . $this->assert_slug($demo) . '/spa';
    }

    /**
     * Devuelve el slug, o tira si está vacío.
     *
     * 🔴 Existe por el `find . -mindepth 1 -delete` de
     * DemoUpdateService::build_spa_hosting_deploy_shell(): una ruta con un segmento vacío no es un
     * error visible, es un directorio equivocado vaciado. Antes de esta guarda, una demo sin
     * `erp_spa_url` armaba `public_html//spa` y el pipeline seguía como si nada.
     *
     * @param  Demo  $demo
     * @return string
     * @throws \RuntimeException
     */
    private function assert_slug(Demo $demo): string
    {
        $slug = $this->slug($demo);
        if ($slug === '') {
            throw new \RuntimeException(
                'No se pudo determinar el subdominio de la demo a partir de su «ERP SPA URL». '
                . 'Sin ese dato las rutas del hosting quedan incompletas y el despliegue iría a un '
                . 'directorio equivocado. Cargala desde el módulo de Demos antes de actualizar.'
            );
        }

        return $slug;
    }
}
