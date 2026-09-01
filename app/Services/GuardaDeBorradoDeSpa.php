<?php

namespace App\Services;

/**
 * 🔴 ÚLTIMA LÍNEA ANTES DE UN BORRADO RECURSIVO.
 *
 * Los cuatro pipelines que despliegan un SPA —instalación de cliente, actualización de cliente,
 * instalación de demo y actualización de demo— arman el mismo comando:
 *
 *     cd "$SPA_DIR" || exit 1; find . -mindepth 1 -delete
 *
 * y ninguno de los cuatro tiene forma de saber si `$SPA_DIR` es el directorio correcto. Esta clase
 * es lo único que se interpone entre una ruta mal resuelta y el borrado.
 *
 * EL INCIDENTE QUE LE DIO ORIGEN (31/8/2026). En los clientes, el directorio se armaba concatenando
 * 'domains/comerciocity.com/public_html/' con str_replace('/api','/spa', $path). Con una ClientApi
 * de VPS y `path` vacío —que es exactamente cómo quedaron los clientes 43 y 13 en la migración,
 * §2.5 del informe del 26/8— la cuenta daba la RAÍZ de la cuenta compartida, y ese find vaciaba el
 * public_html entero: las carpetas de los ~40 clientes activos, de una sola pasada.
 *
 * 🔴 POR QUÉ VIVE ACÁ Y NO ADENTRO DE UN RESOLVER. Nació en ClientApiPathResolver, acoplada a
 * ClientApi. Cuando hubo que cubrir el camino de las demos había dos salidas malas: fabricar una
 * ClientApi falsa desde el pipeline de demos, o copiar la guarda al DemoPathResolver. La segunda es
 * la que importa evitar: dos copias de la regla que decide si se borra o no es exactamente la clase
 * de error que estas guardas vienen a atajar (ver APRENDER_NO_PARCHEAR.md, "arreglar las instancias
 * y no la familia"). Por eso acá no se recibe ningún modelo: solo strings.
 *
 * 🔴 Y NO ALCANZA CON QUE EL RESOLVER ESTÉ BIEN. DemoPathResolver ya valida el insumo por su cuenta
 * (assert_slug, assert_no_es_el_sitio_de_la_api) y hace bien en hacerlo, pero eso es otra cosa: esta
 * guarda existe justamente para el día en que el resolver devuelva mal. Es barata, es mecánica, y
 * corre sobre el string que efectivamente se va a vaciar. No la saques porque "el path ya viene
 * resuelto".
 */
class GuardaDeBorradoDeSpa
{
    /**
     * Directorios que jamás pueden vaciarse: adentro de cada uno vive más de un sitio.
     *
     * Están sin barra final porque el chequeo compara contra la ruta ya normalizada con rtrim.
     *
     * @var array<int, string>
     */
    const RAICES_COMPARTIDAS = [
        'domains',
        'domains/comerciocity.com',
        '/home',
    ];

    /**
     * Segmentos que no pueden aparecer en una ruta que se va a vaciar.
     *
     * 🔴 `..` NO ES UNA HIPÓTESIS, se llega con datos y sin ningún bug de código. Medido el
     * 31/8/2026: `parse_url('https://..', PHP_URL_HOST)` devuelve el string `'..'`, no vacío ni
     * false. Así que una demo en VPS con la «ERP SPA URL» cargada como `https://..` —campo de texto
     * libre que DemoController no valida— resuelve a `/home/<slug>/htdocs/..`, que tiene al slug
     * como segmento, no es ninguna raíz de la lista, y no es literalmente `/home/<slug>` ni
     * `/home/<slug>/htdocs`. Pasaba entera. Y del otro lado, `cd '/home/demo3/htdocs/..'` deja al
     * shell parado en `/home/demo3` y el `find` vacía el home del usuario con todos sus sitios.
     *
     * Con `https://.` el dir queda `/home/<slug>/htdocs/.` y se lleva el htdocs — que es exactamente
     * el caso que la regla del home vino a tapar, derrotado por un carácter.
     *
     * El segmento vacío entra en la misma lista porque `/home/demo3//htdocs` es, para el shell
     * remoto, el mismo directorio que `/home/demo3/htdocs`, que esta clase dice rechazar.
     *
     * @var array<int, string>
     */
    const SEGMENTOS_PROHIBIDOS = ['', '.', '..'];

    /**
     * Frena si el directorio que se está por vaciar no es, sin lugar a dudas, el del SPA que se
     * quiere desplegar.
     *
     * @param  string  $dir            El directorio que el comando remoto va a vaciar.
     * @param  string  $identificador  Lo que tiene que aparecer adentro de la ruta para que sea
     *                                 reconocible: el primer segmento del path o el vps_path en un
     *                                 cliente, el slug en una demo. Vacío = no hay con qué
     *                                 identificarlo, y eso ya es motivo de freno.
     * @param  string  $sujeto         A quién pertenece, para el mensaje ("la ClientApi 12").
     * @param  string  $que_revisar    Qué campos mirar antes de reintentar.
     * @return void
     * @throws \RuntimeException Si el directorio no es identificable como el de este SPA.
     */
    public static function assert(string $dir, string $identificador, string $sujeto, string $que_revisar): void
    {
        $dir_limpio    = rtrim(trim($dir), '/');
        $identificador = trim($identificador);

        /* Vacío o la raíz del filesystem. */
        if ($dir_limpio === '') {
            throw new \RuntimeException(self::motivo($sujeto, $dir, 'está vacío', $que_revisar));
        }

        /*
         * Los segmentos de la ruta, tal como los va a interpretar el shell remoto. Se calculan una
         * vez y todos los chequeos de abajo trabajan sobre esto: comparar strings enteros dejaba
         * pasar `/home/demo3//htdocs`, que remotamente es el mismo directorio que uno prohibido.
         */
        $segmentos = explode('/', $dir_limpio);

        /*
         * 🔴 Ni `.`, ni `..`, ni segmentos vacíos. Va PRIMERO porque una ruta con `..` no se puede
         * razonar: el directorio que el shell termina vaciando no es el que dice el string, así que
         * ningún chequeo posterior significa lo que parece. El detalle de cómo se llega está en el
         * docblock de SEGMENTOS_PROHIBIDOS.
         */
        foreach ($segmentos as $indice => $segmento) {
            /* El primer segmento de una ruta absoluta es vacío por el '/' inicial, y es legítimo. */
            if ($indice === 0 && $segmento === '') {
                continue;
            }

            if (in_array($segmento, self::SEGMENTOS_PROHIBIDOS, true)) {
                throw new \RuntimeException(
                    self::motivo(
                        $sujeto,
                        $dir,
                        'tiene un segmento "' . $segmento . '", y con eso el directorio que el '
                            . 'servidor termina vaciando no es el que dice la ruta',
                        $que_revisar
                    )
                );
            }
        }

        /* Las raíces donde conviven todos los sitios de un servidor. */
        if (in_array($dir_limpio, self::raices_compartidas(), true)) {
            throw new \RuntimeException(
                self::motivo($sujeto, $dir, 'es un directorio raíz compartido', $que_revisar)
            );
        }

        /*
         * Sin identificador no hay forma de decir que este directorio es el correcto, y ese es
         * justamente el caso del incidente: el dato faltaba y la ruta salió igual.
         */
        if ($identificador === '') {
            throw new \RuntimeException(
                self::motivo($sujeto, $dir, 'no hay ningún identificador cargado con el cual reconocerlo', $que_revisar)
            );
        }

        /*
         * 🔴 SEGMENTO COMPLETO, NO SUBSTRING. Este chequeo era un strpos() hasta el 31/8/2026 y
         * dejaba pasar el directorio del vecino: con identificador "demo", la ruta
         * `.../public_html/demo2/spa` CONTIENE "demo" y pasaba. O sea que la guarda que existe para
         * que el pipeline de una demo no vacíe el SPA de otra, no atajaba justamente ese caso.
         *
         * Y no es teórico: los pares <slug> / <slug>2 son la convención del sistema —cada cliente
         * tiene dos instancias, y las demos van demo, demo2, demo3—. En producción están galvan y
         * galvan2, ferretotal y ferretotal2, arfren y arfren2, trama y trama2.
         *
         * Las cuatro formas de ruta que llegan acá tienen al identificador como un segmento entero:
         *
         *   cliente shared  domains/comerciocity.com/public_html/{colman}/spa
         *   cliente VPS     /home/{lacava}/htdocs/lacava.comerciocity.com
         *   demo shared     domains/comerciocity.com/public_html/{demo3}/spa
         *   demo VPS        /home/{demo3}/htdocs/demo3.comerciocity.com
         */
        $posicion = array_search($identificador, $segmentos, true);
        if ($posicion === false) {
            throw new \RuntimeException(
                self::motivo(
                    $sujeto,
                    $dir,
                    'no tiene al identificador "' . $identificador . '" como uno de sus directorios',
                    $que_revisar
                )
            );
        }

        /*
         * 🔴 Y el SPA vive ADENTRO del directorio del identificador, nunca es el directorio mismo.
         *
         * Sin esto, con identificador "demo3" pasaban `.../public_html/demo3` —la carpeta madre, que
         * contiene `api/` Y `spa/`— y `.../public_html/demo3/api`, que es la API. Vaciar cualquiera
         * de los dos se lleva puesto el sistema del cliente o de la demo. Se llega el día en que
         * spa_path() devuelva api_path() o pierda el sufijo `/spa`, que es exactamente la clase de
         * bug para la que esta guarda existe.
         *
         * Las cuatro formas reales tienen al menos un segmento después del identificador:
         * `{colman}/spa`, `{demo3}/spa`, `/home/{lacava}/htdocs/lacava.comerciocity.com`.
         */
        if ($posicion === count($segmentos) - 1) {
            throw new \RuntimeException(
                self::motivo(
                    $sujeto,
                    $dir,
                    'es el directorio raíz de "' . $identificador . '", donde vive también la API, '
                        . 'y no el del SPA',
                    $que_revisar
                )
            );
        }

        if (end($segmentos) === 'api') {
            throw new \RuntimeException(
                self::motivo($sujeto, $dir, 'es el directorio de la API, no el del SPA', $que_revisar)
            );
        }

        /*
         * 🔴 El home del usuario y su htdocs, que CONTIENEN el identificador y por eso pasarían el
         * chequeo de arriba.
         *
         * En el VPS el SPA vive en /home/<slug>/htdocs/<dominio>. Si el dominio saliera vacío, la
         * ruta queda en /home/demo3/htdocs —que contiene "demo3"— y vaciarlo se lleva TODOS los
         * sitios de ese usuario. Hoy el resolver tira antes si el dominio está vacío, pero esa es la
         * mitad del argumento: esta guarda existe para cuando el resolver falle.
         */
        $homes = ['/home/' . $identificador, '/home/' . $identificador . '/htdocs'];
        if (in_array($dir_limpio, $homes, true)) {
            throw new \RuntimeException(
                self::motivo(
                    $sujeto,
                    $dir,
                    'es el home del usuario en el VPS (o su htdocs), donde conviven todos sus sitios',
                    $que_revisar
                )
            );
        }
    }

    /**
     * Las raíces prohibidas, con la de la cuenta compartida DERIVADA de la convención real.
     *
     * 🔴 No está como literal a propósito. `domains/comerciocity.com/public_html/` vive en dos
     * constantes —ClientApiPathResolver::PREFIJO_SHARED y DemoPathResolver::SHARED_HOSTING_PREFIX—
     * y tenerla escrita una tercera vez acá significaba que, el día que Hostinger cambie el dominio
     * de la cuenta y alguien toque esas dos, esta lista queda vieja **en silencio** y la raíz de la
     * cuenta compartida pasa a ser borrable. Es la misma "dos copias de la regla" contra la que
     * predica el docblock de esta clase tres párrafos más arriba.
     *
     * Se derivan las dos constantes y no una sola, porque el día que diverjan entre sí, las dos
     * raíces tienen que estar prohibidas.
     *
     * @return array<int, string>
     */
    private static function raices_compartidas(): array
    {
        $raices = self::RAICES_COMPARTIDAS;

        foreach ([ClientApiPathResolver::PREFIJO_SHARED, DemoPathResolver::SHARED_HOSTING_PREFIX] as $prefijo) {
            $raices[] = rtrim($prefijo, '/');
        }

        return array_values(array_unique($raices));
    }

    /**
     * Mensaje único de la guarda: qué se frenó, por qué, y qué mirar antes de reintentar.
     *
     * @param  string  $sujeto
     * @param  string  $dir
     * @param  string  $motivo
     * @param  string  $que_revisar
     * @return string
     */
    private static function motivo(string $sujeto, string $dir, string $motivo, string $que_revisar): string
    {
        return 'FRENADO ANTES DE BORRAR: el directorio del SPA calculado para ' . $sujeto . ' ('
            . $motivo . ') no se puede vaciar. Ruta calculada: "' . $dir . '". El despliegue del SPA '
            . 'le corre un `find . -mindepth 1 -delete` adentro, así que con una ruta mal resuelta '
            . 'borraría los archivos de todos los sitios de ese servidor. ' . $que_revisar;
    }
}
