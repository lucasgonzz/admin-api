<?php

namespace App\Services;

/**
 * Deja absoluta (con esquema) una URL de instancia demo cargada a mano en el módulo de Demos.
 *
 * 🔴 POR QUÉ EXISTE ESTA CLASE (17/8/2026)
 *
 * `demos.erp_spa_url` y `demos.ecommerce_spa_url` son `string(255)` de texto libre, y
 * `DemoController::normalize_url_attributes()` solo les hace `trim` + `rtrim('/')`: **nunca exige
 * `http(s)://`**. O sea que un operador puede guardar `demo3.comerciocity.com` y el sistema lo
 * acepta, igual que `DemoSeeder` guarda `empresa.local:8080` para el entorno local.
 *
 * Un valor así, concatenado directo en un link, **no es una URL relativa: es una URL con esquema
 * `demo3.comerciocity.com:`**. El navegador la rechaza con "protocolo desconocido" y no navega —
 * y como no es un error de red ni de HTTP, el `.catch` del SPA no se entera y el botón se queda
 * cargando para siempre. Ese fue el bug reportado por Lucas el 17/8/2026 sobre el botón "Entrar a
 * mi demo" de la página de experiencia.
 *
 * Antes de esta clase la regla estaba escrita **dos veces** —`LeadDemoMailHelper::normalize_mail_url()`
 * y `LeadAiService::normalize_demo_url()`, que documenta la duplicación como deuda conocida del
 * grupo 212— y **faltaba en todos los demás consumidores**. Los dos que la tenían no fallaban; los
 * que no la tenían, sí. Por eso ahora hay un solo lugar: si el criterio cambia, cambia acá.
 */
class DemoUrlNormalizer
{
    /**
     * Hosts que son siempre desarrollo local, sin puerto.
     *
     * @var array<int, string>
     */
    const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1'];

    /**
     * Sufijos de host reservados para desarrollo local (`empresa.local`, `tienda.local`, etc.).
     *
     * @var array<int, string>
     */
    const LOCAL_HOST_SUFFIXES = ['.local', '.test', '.localhost'];

    /**
     * Devuelve la URL con esquema garantizado y sin barra final.
     *
     * Idempotente: una URL que ya viene absoluta vuelve igual (solo se le saca la barra final),
     * así que se puede llamar sobre valores ya normalizados sin efecto.
     *
     * @param string|null $raw_url Valor crudo tal como está guardado en la columna.
     *
     * @return string URL absoluta, o cadena vacía si $raw_url viene vacía.
     */
    public static function absolute($raw_url)
    {
        // Los espacios de más son frecuentes al pegar la URL en el formulario de Demos.
        $url = rtrim(trim((string) $raw_url), '/');
        if ($url === '') {
            return '';
        }

        // Ya es absoluta: no se toca nada más que la barra final.
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        // Una URL protocol-relative (`//host/...`) queda igual de rota que una sin esquema:
        // las barras iniciales se sacan para no terminar armando `https:///host`.
        $url = ltrim($url, '/');

        return self::scheme_for_host(self::host_of($url)) . $url;
    }

    /**
     * Extrae el host de una URL SIN esquema (`empresa.local:8080/algo` → `empresa.local`).
     *
     * No se usa `parse_url()` a propósito: sobre una cadena sin esquema, `parse_url()` interpreta
     * `empresa.local:8080` como esquema `empresa.local` + path `8080`, devuelve `null` para
     * PHP_URL_HOST y deja la decisión sin dato. Es el mismo motivo por el que
     * `DemoUpdateService::slug_from_url()` devolvía slug vacío para estas URLs.
     *
     * @param string $url URL sin esquema, ya recortada.
     *
     * @return string Host en minúsculas, o cadena vacía si no se pudo aislar.
     */
    private static function host_of($url)
    {
        // Hasta la primera barra queda la autoridad (host + puerto eventual).
        $partes    = explode('/', $url);
        $autoridad = $partes[0];

        // Credenciales embebidas (`user:pass@host`), por si alguien las pegó desde el navegador.
        $arroba = strrpos($autoridad, '@');
        if ($arroba !== false) {
            $autoridad = substr($autoridad, $arroba + 1);
        }

        // El puerto se descarta: la decisión de esquema es por host.
        $sin_puerto = explode(':', $autoridad);

        return strtolower($sin_puerto[0]);
    }

    /**
     * Elige el esquema que corresponde a un host.
     *
     * 🔴 POR QUÉ NO ES SIEMPRE `https://` — y por qué esta rama no se puede "simplificar":
     * las dos copias viejas de esta regla prefijaban `https://` a secas. Para producción está
     * bien (todas las demos viven en `comerciocity.com`, que es HTTPS), pero convierte
     * `empresa.local:8080` en `https://empresa.local:8080`, que **tampoco entra**: en local la SPA
     * la sirve `npm run serve` por HTTP plano. Sin esta rama, el arreglo del link de ingreso no
     * funcionaría justo en el entorno donde se prueba la demo antes de publicarla.
     *
     * Los sufijos de abajo son de uso reservado para desarrollo (RFC 6762 para `.local`, RFC 6761
     * para `.test`/`.localhost`): ningún host real de un cliente puede caer acá por accidente.
     *
     * @param string $host Host ya normalizado a minúsculas.
     *
     * @return string `http://` o `https://`.
     */
    private static function scheme_for_host($host)
    {
        if (in_array($host, self::LOCAL_HOSTS, true)) {
            return 'http://';
        }

        foreach (self::LOCAL_HOST_SUFFIXES as $sufijo) {
            if (substr($host, -strlen($sufijo)) === $sufijo) {
                return 'http://';
            }
        }

        // Cualquier host real: HTTPS. Ante la duda, el esquema seguro.
        return 'https://';
    }
}
