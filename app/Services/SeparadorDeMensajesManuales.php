<?php

namespace App\Services;

/**
 * Parte en varios mensajes lo que escribió UNA PERSONA, y solo cuando lo pidió explícitamente.
 *
 * 🔴 Es a propósito más estricta que el criterio del agente, y las dos conviven sin unificarse.
 * El agente separa con una línea de tres guiones a secas porque es lo único que le pide el
 * prompt (bloque "VARIOS MENSAJES" de SupportAiSuggestionService), así que del lado del agente
 * alcanza con `\n---\n`. Una persona, en cambio, escribe markdown, precios con guiones y
 * separadores visuales que no quieren decir nada: partirle el texto por un "---" que puso para
 * otra cosa sería cambiarle el mensaje sin que lo haya pedido. Por eso acá el separador es el
 * completo —renglón en blanco, línea con tres guiones, renglón en blanco— y nada más.
 *
 * El criterio estricto es un subconjunto del laxo: todo lo que parte acá también partiría del
 * lado del agente. Unificar hacia el estricto rompería al agente, que manda `\n---\n` pelado;
 * unificar hacia el laxo partiría un subrayado de markdown escrito sin intención de partir nada.
 *
 * Vive sola, sin estado ni dependencias, para que soporte y leads usen literalmente el mismo
 * criterio y no dos copias que se van separando con el tiempo.
 */
class SeparadorDeMensajesManuales
{
    /**
     * Tope de partes en las que se puede romper un mensaje manual.
     *
     * No es desconfianza del operador: cada parte cuesta una pausa de 1200ms y un POST a Kapso
     * adentro del request, así que el que apretó "enviar" se come la espera. Es más alto que el
     * del agente (3) porque acá la intención es explícita: si alguien escribió cinco
     * separadores, los escribió queriendo. Lo que pasa del tope se pega a la última parte, así
     * que nunca se pierde texto.
     */
    const MAX_PARTES = 5;

    /**
     * Separador canónico, para volver a UNIR partes que no salieron.
     *
     * El texto pendiente de un envío parcial se rearma con esto para que el operador lo pueda
     * copiar y remandar tal cual, con los separadores en su lugar.
     */
    const SEPARADOR = "\n\n---\n\n";

    /**
     * Renglón en blanco, línea con tres guiones, renglón en blanco.
     *
     * Se usa `[ \t]` y no `\s` justamente porque `\s` incluye el salto de línea: con `\s*` el
     * patrón terminaría matcheando un "---" sin renglones en blanco alrededor, que es
     * exactamente el caso que esta clase no tiene que partir.
     */
    const PATRON = '/\n[ \t]*\n[ \t]*---[ \t]*\n[ \t]*\n/';

    /**
     * Parte el texto en los mensajes que la persona quiso mandar por separado.
     *
     * @param string $texto Lo que escribió el operador, crudo.
     *
     * @return array<int, string> Una sola parte si no pidió partir nada.
     */
    public function partir(string $texto): array
    {
        // Un textarea manda \r\n o \r según el navegador y el sistema operativo. Sin
        // normalizar, el mismo separador parte en una máquina y no parte en otra.
        $normalizado = str_replace(["\r\n", "\r"], "\n", $texto);

        $partes = preg_split(self::PATRON, $normalizado);
        if ($partes === false) {
            return [trim($normalizado)];
        }

        $limpias = [];
        foreach ($partes as $parte) {
            $parte = trim($parte);
            if ($parte !== '') {
                $limpias[] = $parte;
            }
        }

        // Un texto que era todo separadores no puede terminar en cero mensajes.
        if (empty($limpias)) {
            return [trim($normalizado)];
        }

        if (count($limpias) > self::MAX_PARTES) {
            $sobrantes = array_splice($limpias, self::MAX_PARTES - 1);
            $limpias[] = implode("\n\n", $sobrantes);
        }

        return $limpias;
    }
}
