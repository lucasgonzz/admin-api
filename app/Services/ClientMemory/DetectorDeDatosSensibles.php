<?php

namespace App\Services\ClientMemory;

/**
 * Lo que NO puede entrar en la ficha del cliente ni en el título o el resumen de un ticket (misión cruzada
 * `ficha-del-cliente`, 11/11/2026, arreglo de la revisión): solo lo que PARECE un secreto.
 *
 * 🔴 Es un detector propio, no `SupportImplementationContextService::ocultar_claves()`: aquella regla tapa lo que
 * sigue a «clave» en el historial (ahí un falso positivo solo oculta una palabra), pero usada para RECHAZAR dejaba
 * afuera notas legítimas que la ficha necesita: «Clave Fiscal nivel 3», «la clave de ARCA», «se le blanqueó la
 * contraseña», «recuperar clave con código». No se toca la del historial.
 *
 * Se rechaza:
 *  - `clave`: «clave / contraseña / password / pass / pin / token / usuario y clave» (con calificadores como
 *    «fiscal», «de ARCA», «nueva»), seguidos de `:` o `=` y un valor; de «es / era / sería» y un valor que no sea
 *    una palabra de relleno («la misma», «igual»); o directo de una palabra con dígitos o símbolos de 4 o más
 *    caracteres («clave Perro123»).
 *  - `cbu`: 22 dígitos seguidos (CBU o CVU).
 *  - `tarjeta`: 15, 16 o 19 dígitos (con o sin espacios o guiones) que pasan el control de Luhn.
 */
class DetectorDeDatosSensibles
{
    /** Las palabras que anuncian un secreto. */
    const PALABRAS = 'usuario\s+y\s+(?:clave|contrase[ñn]a)|contrase[ñn]as?|claves?|passwords?|pass|pin|token';

    /** Calificadores que pueden ir entre la palabra y el valor («clave fiscal nueva: …», «clave de ARCA es …»). */
    const CALIFICADORES = '(?:\s+(?:fiscal|nueva|nuevo|actual|provisoria|provisorio|temporal|vieja|viejo|web|(?:de|del)\s+[\p{L}\p{N}]+))*';

    /** Después de «es / era / sería», lo que NO es un valor. */
    const RELLENO = ['la', 'el', 'lo', 'los', 'las', 'un', 'una', 'de', 'del', 'que', 'para', 'su', 'tu', 'mi',
        'misma', 'mismo', 'igual', 'nueva', 'nuevo', 'vieja', 'viejo', 'otra', 'otro', 'correcta', 'correcto',
        'incorrecta', 'incorrecto', 'distinta', 'distinto', 'segura', 'seguro', 'larga', 'corta'];

    /** Etiquetas del tipo detectado, para el mensaje. */
    const ETIQUETAS = [
        'clave'   => 'una clave o contraseña con su valor',
        'cbu'     => 'un CBU o CVU (22 dígitos)',
        'tarjeta' => 'un número de tarjeta',
    ];

    /**
     * El tipo de dato sensible que tiene el texto (`clave`, `cbu`, `tarjeta`), o null si no tiene ninguno.
     *
     * @param string|null $texto El texto.
     *
     * @return string|null
     */
    public function detectar($texto)
    {
        $texto = (string) $texto;
        if (trim($texto) === '') {
            return null;
        }

        if ($this->tiene_clave($texto)) {
            return 'clave';
        }
        if (preg_match('/(?<!\d)\d{22}(?!\d)/', $texto)) {
            return 'cbu';
        }
        if ($this->tiene_tarjeta($texto)) {
            return 'tarjeta';
        }

        return null;
    }

    /**
     * La etiqueta en castellano de un tipo.
     *
     * @param string $tipo clave | cbu | tarjeta.
     *
     * @return string
     */
    public function etiqueta($tipo)
    {
        return isset(self::ETIQUETAS[$tipo]) ? self::ETIQUETAS[$tipo] : 'un dato sensible';
    }

    /**
     * @param string $texto El texto.
     *
     * @return bool
     */
    protected function tiene_clave($texto)
    {
        $patron = '/\b(?:' . self::PALABRAS . ')\b' . self::CALIFICADORES . '(\s*[:=])?[ \t]*(\S+)?(?:[ \t]+(\S+))?/iu';
        if (! preg_match_all($patron, $texto, $coincidencias, PREG_SET_ORDER)) {
            return false;
        }

        foreach ($coincidencias as $una) {
            $separador = isset($una[1]) ? trim($una[1]) : '';
            $siguiente = isset($una[2]) ? $this->limpiar($una[2]) : '';
            $despues   = isset($una[3]) ? $this->limpiar($una[3]) : '';

            if ($separador !== '') {
                if (mb_strlen($siguiente) >= 2) {
                    return true;
                }
                continue;
            }

            if (in_array(mb_strtolower($siguiente), ['es', 'era', 'sería', 'seria'], true)) {
                if (mb_strlen($despues) >= 4 && ! in_array(mb_strtolower($despues), self::RELLENO, true)) {
                    return true;
                }
                continue;
            }

            if ($this->parece_un_secreto($siguiente)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Una palabra de 4 o más caracteres con algún dígito o símbolo.
     *
     * @param string $palabra La palabra, sin la puntuación del final.
     *
     * @return bool
     */
    protected function parece_un_secreto($palabra)
    {
        return mb_strlen($palabra) >= 4 && preg_match('/[\d@#$%&*+=_\/\\\\!?¡¿.\-]/u', $palabra) === 1;
    }

    /**
     * La palabra sin comillas ni la puntuación del final de la oración.
     *
     * @param string $palabra La palabra.
     *
     * @return string
     */
    protected function limpiar($palabra)
    {
        $palabra = preg_replace('/^["\'«“(]+/u', '', (string) $palabra);

        return (string) preg_replace('/[.,;:!?)»”"\']+$/u', '', (string) $palabra);
    }

    /**
     * Una tira de 15, 16 o 19 dígitos (con espacios o guiones sueltos entre medio) que pasa Luhn.
     *
     * @param string $texto El texto.
     *
     * @return bool
     */
    protected function tiene_tarjeta($texto)
    {
        if (! preg_match_all('/(?<![\d])\d(?:[ \-]?\d){14,18}(?![\d])/', $texto, $tiras)) {
            return false;
        }

        foreach ($tiras[0] as $tira) {
            $digitos = preg_replace('/\D/', '', $tira);
            if (in_array(strlen($digitos), [15, 16, 19], true) && $this->luhn($digitos)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $digitos Solo dígitos.
     *
     * @return bool
     */
    protected function luhn($digitos)
    {
        $suma  = 0;
        $doble = false;
        for ($i = strlen($digitos) - 1; $i >= 0; $i--) {
            $d = (int) $digitos[$i];
            if ($doble) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $suma += $d;
            $doble = ! $doble;
        }

        return $suma % 10 === 0;
    }
}
