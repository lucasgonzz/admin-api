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
        $json = (string) json_encode($this->redact($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($max > 0 && mb_strlen($json) > $max) {
            return mb_substr($json, 0, max(0, $max - 1)) . '…';
        }

        return $json;
    }
}
