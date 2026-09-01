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
        'domains/comerciocity.com/public_html',
        '/home',
    ];

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

        /* Las raíces donde conviven todos los sitios de un servidor. */
        if (in_array($dir_limpio, self::RAICES_COMPARTIDAS, true)) {
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

        if (strpos($dir_limpio, $identificador) === false) {
            throw new \RuntimeException(
                self::motivo(
                    $sujeto,
                    $dir,
                    'no contiene el identificador "' . $identificador . '"',
                    $que_revisar
                )
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
