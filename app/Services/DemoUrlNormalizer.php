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
     * Devuelve la URL con esquema garantizado.
     *
     * 🔴 **No toca nada más que el esquema.** En particular NO recorta la barra final: este mismo
     * método normaliza también los `video_url` de los tutoriales personalizados
     * (`LeadDemoMailHelper::build_view_data()`), que son URLs arbitrarias cargadas por un operador
     * y pueden traer query string o path significativo. Recortarles el último carácter es una
     * forma silenciosa de corromperlas. El que va a concatenarle una ruta usa `base()`.
     *
     * Idempotente: una URL que ya viene absoluta vuelve igual, así que se puede llamar sobre
     * valores ya normalizados sin efecto.
     *
     * @param string|null $raw_url Valor crudo tal como está guardado en la columna.
     *
     * @return string URL absoluta, o cadena vacía si $raw_url viene vacía.
     */
    public static function absolute($raw_url)
    {
        // Los espacios de más son frecuentes al pegar la URL en el formulario de Demos.
        $url = trim((string) $raw_url);
        if ($url === '') {
            return '';
        }

        // Ya es absoluta: se devuelve tal cual, sin tocarle nada.
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        // Una URL protocol-relative (`//host/...`) queda igual de rota que una sin esquema:
        // las barras iniciales se sacan para no terminar armando `https:///host`.
        $url = ltrim($url, '/');

        // Un valor que era solo barras no es una URL: sin esto devolvía `https://` a secas, y
        // `base()` lo dejaba en `https:`, que es peor que vacío porque parece una URL.
        if ($url === '') {
            return '';
        }

        return self::scheme_for_host(self::host_of($url)) . $url;
    }

    /**
     * Devuelve la URL absoluta y sin barra final, lista para concatenarle una ruta.
     *
     * Existe aparte de `absolute()` porque recortar la barra final solo es seguro cuando quien
     * llama va a pegarle un path a continuación. Ver el comentario de `absolute()`.
     *
     * @param string|null $raw_url Valor crudo tal como está guardado en la columna.
     *
     * @return string URL absoluta sin barra final, o cadena vacía si $raw_url viene vacía.
     */
    public static function base($raw_url)
    {
        return rtrim(self::absolute($raw_url), '/');
    }

    /**
     * Extrae el host de una URL SIN esquema (`empresa.local:8080/algo` → `empresa.local`).
     *
     * 🔴 No se usa `parse_url()` acá, y el motivo es más angosto de lo que parece — medido con
     * PHP 7.4.33, que es el de producción:
     *
     *     parse_url('empresa.local:8080',   PHP_URL_HOST) === 'empresa.local'
     *     parse_url('demo3.comerciocity.com', PHP_URL_HOST) === NULL
     *
     * O sea que `parse_url()` acierta cuando hay puerto y **devuelve null cuando no lo hay**, que
     * es justamente la forma en que se carga a mano una demo de producción. Una decisión de
     * esquema que dependa de eso queda sin dato en el caso más común. Por eso el parseo de acá es
     * propio y explícito.
     *
     * @param string $url URL sin esquema, ya recortada y sin barras iniciales.
     *
     * @return string Host en minúsculas, o cadena vacía si no se pudo aislar.
     */
    private static function host_of($url)
    {
        // La autoridad termina en el primer separador que aparezca: path, query o fragmento. Los
        // tres se cortan, no solo la barra: `host?a=b@c.local` metía la query adentro del host y
        // hacía que el `@` de más abajo se comiera el host real.
        //
        // 🔴 La barra INVERTIDA también corta, y no es un detalle cosmético: el estándar de URL
        // manda normalizar `\` a `/` en los esquemas especiales, así que el navegador lee
        // `demo3.comerciocity.com\algo.local` como host `demo3.comerciocity.com` + path
        // `/algo.local`. Sin ese carácter acá, este parseo veía un host terminado en `.local` y le
        // daba HTTP plano a un dominio real de cliente.
        $corte = strcspn($url, "/?#\\");
        $autoridad = substr($url, 0, $corte);

        // Credenciales embebidas (`user:pass@host`), por si alguien las pegó desde el navegador.
        $arroba = strrpos($autoridad, '@');
        if ($arroba !== false) {
            $autoridad = substr($autoridad, $arroba + 1);
        }

        // IPv6 va entre corchetes (`[::1]:8080`) y adentro tiene los mismos dos puntos que el
        // separador de puerto, así que se resuelve antes y por separado.
        if (isset($autoridad[0]) && $autoridad[0] === '[') {
            $cierre = strpos($autoridad, ']');
            if ($cierre !== false) {
                return strtolower(substr($autoridad, 1, $cierre - 1));
            }
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
     * Los sufijos son de uso reservado para desarrollo (RFC 6762 para `.local`, RFC 6761 para
     * `.test` y `.localhost`) y los rangos de IP son los privados de la RFC 1918: ningún host real
     * de un cliente puede caer acá por accidente.
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

        // IP privada de LAN: es la forma en que se prueba la demo desde un teléfono contra la
        // máquina de desarrollo, que es un requisito fijo del proyecto (verificación en tres
        // anchos). Ahí tampoco hay TLS.
        if (self::is_private_ipv4($host)) {
            return 'http://';
        }

        // Cualquier host real: HTTPS. Ante la duda, el esquema seguro.
        return 'https://';
    }

    /**
     * ¿El host es una IP privada de LAN (RFC 1918)?
     *
     * @param string $host Host a evaluar.
     *
     * @return bool
     */
    private static function is_private_ipv4($host)
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        // FILTER_FLAG_NO_PRIV_RANGE devuelve false para las privadas: si la IP es válida pero no
        // pasa este filtro, es de rango privado. Cubre 10/8, 172.16/12 y 192.168/16 sin escribir
        // los rangos a mano.
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE) === false;
    }
}
