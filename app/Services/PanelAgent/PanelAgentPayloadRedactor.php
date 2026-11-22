<?php

namespace App\Services\PanelAgent;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\UploadedFile;

/**
 * Redacta datos antes de guardarlos en la auditoría del agente: ningún secreto llega a la base.
 *
 * Una clave que coincide con `panel_agent.redact_key_pattern` pierde su valor; un archivo subido se reemplaza
 * por su nombre y tamaño. Se aplica al payload del pedido y al extracto de la respuesta.
 */
class PanelAgentPayloadRedactor
{
    /** Texto que reemplaza a un valor sensible. */
    const MASK = '[REDACTADO]';

    /**
     * Devuelve una copia del valor con las claves sensibles enmascaradas, recorriendo arrays y objetos.
     *
     * @param  mixed $value Array, objeto o escalar.
     * @return mixed Misma forma, con lo sensible redactado (los objetos salen como arrays).
     */
    public function redact($value)
    {
        if ($value instanceof UploadedFile) {
            // Del archivo sólo queda el nombre y el tamaño: nunca su contenido.
            return ['archivo' => $value->getClientOriginalName(), 'bytes' => $value->getSize()];
        }

        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        } elseif (is_object($value)) {
            $value = json_decode((string) json_encode($value), true);
        }

        if (! is_array($value)) {
            return $value;
        }

        // Patrón de claves sensibles.
        $pattern = (string) config('panel_agent.redact_key_pattern');

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && $pattern !== '' && preg_match($pattern, $key) === 1) {
                $result[$key] = self::MASK;
                continue;
            }
            $result[$key] = $this->redact($item);
        }

        return $result;
    }

    /**
     * Redacta el valor, lo codifica como JSON compacto y lo recorta.
     *
     * @param  mixed $value Datos a guardar.
     * @param  int   $max   Máximo de caracteres del resultado (recortado con «…» al final).
     * @return string
     */
    public function to_json_excerpt($value, int $max): string
    {
        // JSON_INVALID_UTF8_SUBSTITUTE (PHP 7.2+): un byte inválido (p. ej. un «\xF3» latin-1 en la query) pasa a
        // «U+FFFD» en vez de perder el valor entero; así el texto que llega a la columna utf8mb4 siempre es válido.
        $json = (string) json_encode(
            $this->redact($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($max > 0 && mb_strlen($json) > $max) {
            return mb_substr($json, 0, max(0, $max - 1)) . '…';
        }

        return $json;
    }

    /**
     * Deja un texto que viene del cliente (header, ruta) listo para una columna utf8mb4 estricta.
     *
     * Pasos: opcionalmente decodifica el percent-encoding (contrato del header `X-Agente-Motivo`: UTF-8 con
     * `urllib.parse.quote(texto, safe='')`); si lo que queda no es UTF-8 válido lo toma como ISO-8859-1 (un
     * cliente viejo que mandó el byte latin-1 crudo no rompe la auditoría); reemplaza caracteres de control y
     * saltos por un espacio, junta espacios y recorta por CARACTERES (no por bytes) a `$max`.
     *
     * @param  mixed $value           Texto crudo recibido (null o vacío dan cadena vacía).
     * @param  int   $max             Máximo de caracteres del resultado.
     * @param  bool  $percent_decoded true si el texto viaja percent-encoded y hay que decodificarlo.
     * @return string UTF-8 válido, sin controles, de hasta `$max` caracteres.
     */
    public function clean_text($value, int $max, bool $percent_decoded = false): string
    {
        // Texto de trabajo, ya decodificado si el contrato lo pide.
        $text = (string) $value;
        if ($percent_decoded) {
            $text = rawurldecode($text);
        }

        // Cualquier cosa que no sea UTF-8 válido se interpreta como latin-1 (todo byte tiene equivalente).
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = (string) mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        }

        // Controles (incluye \r, \n, NUL) y separadores de línea Unicode pasan a espacio; luego se juntan los espacios.
        $text = preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $text);
        $text = trim((string) preg_replace('/ {2,}/', ' ', (string) $text));

        return mb_substr($text, 0, max(0, $max));
    }
}
