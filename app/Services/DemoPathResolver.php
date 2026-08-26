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
     * Caracteres válidos para un identificador de sitio: los que puede tener un subdominio.
     *
     * 🔴 No es cosmética. El identificador termina interpolado en un `cd` remoto y en el
     * directorio que después se vacía con `find -delete`. Sin esta restricción, un `vps_path`
     * cargado a mano como `x; rm -rf algo` o `../..` produce una ruta perfectamente bien formada
     * que no es la que nadie quiso.
     */
    const SITE_ID_PATTERN = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/';

    /**
     * Prefijo reservado del sitio de la API en el VPS.
     *
     * 🔴 En CloudPanel, `/home/api-{slug}/htdocs/api-{slug}.comerciocity.com` es un **symlink a
     * `empresa-api/public`** (verificado por SSH el 26/8/2026, §1 del informe de migración). Si el
     * SPA de una demo resolviera a un directorio `api-*`, el `find . -mindepth 1 -delete` del
     * deploy seguiría el symlink y vaciaría el `public/` de la API. Y llegar ahí es un typo de un
     * solo campo: pegar la URL de la API en «ERP SPA URL», que en el modal es el campo de al lado.
     */
    const API_SITE_PREFIX = 'api-';

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
        /* strtolower porque la columna es texto libre y nadie la valida: un "VPS" cargado a mano
         * se guarda con 200 OK, y sin esto la demo se quedaba en hosting compartido en silencio
         * mientras la grilla mostraba "VPS". Es el mismo criterio que DemoUrlNormalizer usa para
         * el host. Un valor que igual no reconozcamos ('vpss') sigue cayendo a shared_hosting. */
        $hosting_type = strtolower(trim((string) ($demo->erp_hosting_type ?? '')));

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

        /* A minúsculas: `parse_url()` NO normaliza el host, y en Linux `/home/DEMO3` no es
         * `/home/demo3`. DemoUrlNormalizer::host_of() ya baja el host para decidir el esquema —
         * el criterio estaba escrito en la casa de al lado. */
        $partes = explode('.', strtolower($host));

        // El slug es el primer segmento del hostname (antes del primer punto).
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

        // A minúsculas, por el mismo motivo que slug_from_url(): el host es un directorio.
        return strtolower((string) $host);
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
        $vps_path = strtolower(trim((string) ($demo->erp_vps_path ?? '')));
        if ($vps_path !== '') {
            return $this->assert_site_id($vps_path, '«VPS Path ERP»');
        }

        $slug = $this->slug($demo);
        if ($slug === '') {
            throw new \RuntimeException(
                'La demo está marcada como VPS pero no se pudo determinar su ubicación: no tiene '
                . 'cargado el campo «VPS Path ERP» ni una «ERP SPA URL» de la que deducirlo. '
                . 'Completá alguno de los dos desde el módulo de Demos.'
            );
        }

        return $this->assert_site_id($slug, 'el subdominio de la «ERP SPA URL»');
    }

    /**
     * Valida que un identificador de sitio pueda usarse como segmento de una ruta remota.
     *
     * 🔴 Lo que ataja no es un ataque: es un campo de texto libre que termina adentro de un `cd`
     * por SSH y de un `find -delete`. Un `vps_path` con una barra, un `..` o un `;` produce una
     * ruta bien formada que apunta a otro lado, y ninguna etapa posterior lo nota. `DemoController`
     * no valida nada (el CRUD es declarativo) y la columna es un `string` a secas, así que este es
     * el único lugar donde se puede frenar.
     *
     * @param  string  $site_id
     * @param  string  $origen  Cómo nombrar el dato en el mensaje de error
     * @return string
     * @throws \RuntimeException
     */
    private function assert_site_id(string $site_id, string $origen): string
    {
        if (preg_match(self::SITE_ID_PATTERN, $site_id) !== 1) {
            throw new \RuntimeException(
                'El identificador de la demo en el VPS ("' . $site_id . '", tomado de ' . $origen
                . ') tiene caracteres que no puede tener el nombre de un sitio. Se esperan solo '
                . 'letras, números y guiones (ej: demo3). Corregilo desde el módulo de Demos.'
            );
        }

        return $site_id;
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

            $vps_slug = $this->vps_slug($demo);
            $this->assert_no_es_el_sitio_de_la_api($vps_slug, $spa_domain);

            return '/home/' . $vps_slug . '/htdocs/' . $spa_domain;
        }

        return self::SHARED_HOSTING_PREFIX . $this->assert_slug($demo) . '/spa';
    }

    /**
     * Frena el despliegue del SPA si la ruta resuelta es la del sitio de la API.
     *
     * 🔴 EL CASO QUE ESTO ATAJA, Y POR QUÉ ES NUEVO (26/8/2026)
     *
     * En el modal de Demos, «ERP SPA URL» y «ERP API URL» son campos contiguos y casi homónimos, y
     * `DemoController` no valida ninguno. Pegar la URL de la API en el campo del SPA se guarda con
     * 200 OK. En hosting compartido ese typo era inofensivo: la ruta resultante
     * (`public_html/api-demo3/spa`) no existe y el `cd` del deploy falla.
     *
     * En el VPS **sí existe**: `/home/api-demo3/htdocs/api-demo3.comerciocity.com` es el symlink a
     * `empresa-api/public`. El `cd` lo sigue, y el `find . -mindepth 1 -delete` del deploy del SPA
     * se lleva puesto el `index.php`, el `.htaccess` y el symlink de storage de la API — con el
     * `2>/dev/null || true` comiéndose cualquier error. La etapa siguiente falla por otro motivo y
     * nadie relaciona una cosa con la otra.
     *
     * @param  string  $vps_slug
     * @param  string  $spa_domain
     * @return void
     * @throws \RuntimeException
     */
    private function assert_no_es_el_sitio_de_la_api(string $vps_slug, string $spa_domain): void
    {
        $prefijo = self::API_SITE_PREFIX;
        $largo   = strlen($prefijo);

        if (substr($vps_slug, 0, $largo) !== $prefijo && substr($spa_domain, 0, $largo) !== $prefijo) {
            return;
        }

        throw new \RuntimeException(
            'El SPA de esta demo resolvería a "/home/' . $vps_slug . '/htdocs/' . $spa_domain
            . '", que en el VPS es el sitio de la API (y un symlink a su carpeta public). '
            . 'Desplegar el SPA ahí borraría la API. Casi seguro la «ERP SPA URL» tiene cargada la '
            . 'URL de la API: revisala en el módulo de Demos.'
        );
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
