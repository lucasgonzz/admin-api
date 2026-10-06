<?php

namespace App\Services\Concerns;

/**
 * Tapa la clave de un cliente (`clients.api_key`) en cualquier texto que vaya a salir del admin:
 * una respuesta de `claude/*`, un mensaje de error, una línea de log.
 *
 * 🔴 POR QUÉ EXISTE. La `api_key` del cliente es el secreto con el que el admin le habla a su
 * `empresa-api` (header `X-Admin-Api-Key`). Los endpoints `catalogo/clave` y `catalogo/puente` la manejan de
 * punta a punta —la escriben en el `.env` del cliente y la mandan en cada pedido— y alrededor de
 * ella hay textos que NO controlamos: el cuerpo de lo que contesta el sistema del cliente (una
 * página de error con los headers volcados, un 401 que repite lo que recibió) y el mensaje de las
 * excepciones de SSH y de HTTP. Una clave en una respuesta no se puede des-mostrar: queda en la
 * consola, en el historial de la conversación y en cualquier captura.
 *
 * Es el mismo criterio de `ClientModelosIaSyncService::tapar_clave()` y de
 * `ClientImageSearchLogService::tapar_clave()`, con dos diferencias:
 *
 *  - Recibe la CLAVE y no el `Client`: el servicio que genera una clave nueva (C1) la tiene en una
 *    variable antes de que el modelo la refleje, y no tiene por qué pasar por el modelo para taparla.
 *  - Cubre también las formas con las que la clave aparece en un comando o en un mensaje de shell
 *    (entre comillas simples POSIX y escapada para `sed`), porque C1 la escribe en un `.env` remoto
 *    con `sed`/`printf` y el mensaje de un comando fallido podría citarla.
 *
 * Las copias viejas no se tocan en esta misión (son de otros servicios, con sus propios tests): el
 * que las unifique se encuentra con esta como destino.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, promoción en
 * constructor, `readonly`, `enum`, atributos, `mixed` ni `never`.
 */
trait TapaLaClaveDelCliente
{
    /**
     * Por qué se reemplaza la clave en un texto.
     *
     * @var string
     */
    protected $marca_de_clave_oculta = '[clave oculta]';

    /**
     * Largo mínimo de una clave para que se la tape. Una clave de prueba de dos letras haría
     * destrozos en cualquier texto, y una real nunca es tan corta (`Str::random(40)`).
     *
     * @var int
     */
    protected $largo_minimo_de_clave_a_tapar = 6;

    /**
     * Tapa la clave en un texto, en todas las formas en las que puede aparecer.
     *
     * Se tapa sobre el texto ENTERO y recién después se recorta (quien llama): al revés, una clave
     * partida en el borde del recorte no coincidiría con nada y saldría a la vista a medias.
     *
     * @param string|null $texto Texto a limpiar.
     * @param string|null $clave La clave del cliente (`clients.api_key`).
     *
     * @return string
     */
    protected function tapar_clave($texto, $clave)
    {
        $texto = (string) $texto;
        $clave = trim((string) $clave);

        if (strlen($clave) < $this->largo_minimo_de_clave_a_tapar) {
            return $texto;
        }

        $formas = [$clave];

        /* Escapada como JSON (sin las comillas de los extremos): así sale si viaja dentro de un cuerpo. */
        $en_json = json_encode($clave);

        if (is_string($en_json) && strlen($en_json) >= 2) {
            $formas[] = substr($en_json, 1, -1);
        }

        /* Codificada como URL, por si el otro lado la repite en una dirección. */
        $formas[] = rawurlencode($clave);
        $formas[] = urlencode($clave);

        /* Entre comillas simples POSIX (como se arma un argumento de shell) y con la comilla simple
           cerrada y reabierta, que es cómo aparece una clave con `'` adentro en un comando. */
        $formas[] = "'" . str_replace("'", "'\\''", $clave) . "'";
        $formas[] = str_replace("'", "'\\''", $clave);

        /* Escapada para el reemplazo de `sed` (barra, delimitador `|` y `&`), que es como la lleva el
           comando que la escribe en el `.env`. */
        $formas[] = str_replace(['\\', '|', '&'], ['\\\\', '\\|', '\\&'], $clave);

        $formas = array_values(array_unique($formas));

        /* La más larga primero, para no dejar pedazos de una forma que contiene a otra. */
        usort($formas, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        return str_replace($formas, $this->marca_de_clave_oculta, $texto);
    }

    /**
     * Un texto listo para salir en una respuesta: sin la clave, en UTF-8 válido y recortado.
     *
     * El UTF-8 importa: un byte suelto de un cuerpo en latin1 (o de la salida de un comando) hace
     * fallar el `json_encode` de la respuesta de Laravel, y el que llama recibe un 500 en vez del
     * motivo que queríamos darle.
     *
     * @param string|null $texto  Texto armado con cosas que devolvió un servidor.
     * @param string|null $clave  La clave del cliente.
     * @param int         $limite Caracteres máximos que salen.
     *
     * @return string
     */
    protected function texto_seguro($texto, $clave, $limite = 300)
    {
        $tapado = $this->tapar_clave($texto, $clave);

        return mb_substr(mb_convert_encoding($tapado, 'UTF-8', 'UTF-8'), 0, $limite);
    }
}
