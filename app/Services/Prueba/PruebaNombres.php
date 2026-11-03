<?php

namespace App\Services\Prueba;

use App\Services\HostingProvisioningStructure;

/**
 * Los nombres de la prueba gratuita, en UN solo lugar (contrato C7 del programa y decisión D2 del
 * plan de `prueba-alta-instancia`, 3/11/2026).
 *
 * - Instancia del pool: `prueba-NN` → sitios `api-prueba-NN`, `prueba-NN`, `tienda-prueba-NN`,
 *   `api-tienda-prueba-NN` (todos `.comerciocity.com`).
 * - Alias del negocio: `<slug>` → `api-<slug>`, `<slug>`, `tienda-<slug>`, `api-tienda-<slug>`.
 *
 * Las dos expresiones regulares son las que valida todo lo que viaja a un comando del VPS: un slug o
 * un nombre que no las cumple NO llega a ningún `bash`.
 */
class PruebaNombres
{
    /** Slug del negocio: minúsculas, dígitos y guiones, 1 a 20, sin guion inicial. */
    const REGEX_SLUG = '/^[a-z0-9][a-z0-9-]{0,19}$/';

    /** Nombre de una instancia del pool. */
    const REGEX_NOMBRE = '/^prueba-[0-9]{2}$/';

    /**
     * Labels que un slug NO puede ser aunque cumpla la expresión: la raíz, los sitios institucionales y
     * las demos. Ninguno de estos llega a un comando ni a la zona DNS como slug de una prueba.
     */
    const RESERVADOS = ['www', 'api', 'admin', 'api-admin', 'demo', 'demo2', 'demo3', 'tienda', 'ia', 'mail', 'ftp', 'cpanel', 'webmail', 'ns1', 'ns2'];

    /**
     * Los prefijos de host, del más largo al más corto: un slug NO puede empezar con ninguno, porque
     * `tienda-lola` sería la tienda de la prueba `lola` y `api-ferretotal` la API de un cliente real
     * (chequeo independiente B1, 3/11/2026). `sin_prefijos_de_host()` los recorta del nombre de la empresa.
     */
    const PREFIJOS_DE_HOST = ['api-tienda-', 'tienda-', 'api-'];

    /** Prefijos de los cuatro sitios, en el orden del JSON de los scripts. */
    const PREFIJOS = [
        'erp_api'    => 'api-',
        'erp_spa'    => '',
        'tienda_spa' => 'tienda-',
        'tienda_api' => 'api-tienda-',
    ];

    public static function dominio(): string
    {
        return HostingProvisioningStructure::dominio();
    }

    public static function es_slug_valido($slug): bool
    {
        return is_string($slug)
            && preg_match(self::REGEX_SLUG, $slug) === 1
            && ! in_array($slug, self::RESERVADOS, true)
            && ! self::es_nombre_valido($slug)
            && ! self::empieza_con_prefijo_de_host($slug);
    }

    /** ¿Es la palabra pelada de un prefijo (`api`, `tienda`, `api-tienda`)? Con cualquier sufijo vuelve a ser un prefijo. */
    public static function es_palabra_de_prefijo(string $slug): bool
    {
        foreach (self::PREFIJOS_DE_HOST as $prefijo) {
            if ($slug === rtrim($prefijo, '-')) {
                return true;
            }
        }

        return false;
    }

    /** ¿El slug empieza con `api-`, `tienda-` o `api-tienda-`? Entonces pisa el host de otro. */
    public static function empieza_con_prefijo_de_host(string $slug): bool
    {
        foreach (self::PREFIJOS_DE_HOST as $prefijo) {
            if (strpos($slug, $prefijo) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recorta del principio todos los prefijos de host, las veces que haga falta: «Tienda Lola» → `lola`,
     * «Tienda API Pepe» → `pepe`, «Tienda API» → `` (vacío: el llamador rechaza).
     */
    public static function sin_prefijos_de_host(string $slug): string
    {
        $slug = ltrim($slug, '-');
        while ($slug !== '' && self::empieza_con_prefijo_de_host($slug)) {
            foreach (self::PREFIJOS_DE_HOST as $prefijo) {
                if (strpos($slug, $prefijo) === 0) {
                    $slug = ltrim(substr($slug, strlen($prefijo)), '-');
                    break;
                }
            }
        }

        return $slug;
    }

    public static function es_nombre_valido($nombre): bool
    {
        return is_string($nombre) && preg_match(self::REGEX_NOMBRE, $nombre) === 1;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function assert_slug(string $slug): string
    {
        if (! self::es_slug_valido($slug)) {
            throw new \InvalidArgumentException('Slug de prueba inválido: "' . $slug . '" (se espera ' . self::REGEX_SLUG . ').');
        }

        return $slug;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function assert_nombre(string $nombre): string
    {
        if (! self::es_nombre_valido($nombre)) {
            throw new \InvalidArgumentException('Nombre de instancia inválido: "' . $nombre . '" (se espera prueba-NN).');
        }

        return $nombre;
    }

    /** Los cuatro labels (sin dominio) de un slug o de un nombre de pool. */
    public static function labels(string $base): array
    {
        $labels = [];
        foreach (self::PREFIJOS as $clave => $prefijo) {
            $labels[$clave] = $prefijo . $base;
        }

        return $labels;
    }

    /** Los cuatro hosts (con dominio) de un slug o de un nombre de pool. */
    public static function hosts(string $base): array
    {
        $hosts = [];
        foreach (self::labels($base) as $clave => $label) {
            $hosts[$clave] = $label . '.' . self::dominio();
        }

        return $hosts;
    }

    public static function labels_del_slug(string $slug): array
    {
        return self::labels(self::assert_slug($slug));
    }

    public static function hosts_del_slug(string $slug): array
    {
        return self::hosts(self::assert_slug($slug));
    }

    public static function labels_del_pool(string $nombre): array
    {
        return self::labels(self::assert_nombre($nombre));
    }

    public static function hosts_del_pool(string $nombre): array
    {
        return self::hosts(self::assert_nombre($nombre));
    }

    /** `prueba-01` → `prueba-01_` (prefijo de caché y de Redis de la instancia). */
    public static function prefijo_redis(string $base): string
    {
        return $base . '_';
    }

    /** El nombre del pool número N: `prueba-07`. */
    public static function nombre_numero(int $n): string
    {
        return 'prueba-' . str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    }

    /** URL de ingreso del negocio a su sistema con el token de sesión ya iniciada. */
    public static function url_ingreso(string $slug, string $token): string
    {
        return 'https://' . self::hosts_del_slug($slug)['erp_spa'] . '/demo/ingreso?t=' . $token;
    }
}
