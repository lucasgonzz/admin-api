<?php

namespace App\Helpers;

/**
 * Normaliza y compara teléfonos argentinos para integración WhatsApp (Kapso / Meta).
 */
class WhatsappNormalizer
{
    /**
     * Convierte variantes locales o internacionales a E.164 móvil argentino (+549XXXXXXXXXX).
     *
     * Ejemplos soportados:
     * - +5491112345678
     * - 5491112345678
     * - 01112345678
     * - 1112345678
     *
     * @param string $phone Teléfono en cualquier formato habitual.
     *
     * @return string Número en E.164 o cadena vacía si no hay dígitos.
     */
    public static function normalize(string $phone): string
    {
        // Solo dígitos para comparar y reconstruir el número.
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }

        // Quita ceros iniciales de marcado local (011… → 11…).
        $digits = ltrim($digits, '0');

        // Ya incluye código de país 54.
        if (strpos($digits, '54') === 0) {
            return self::ensure_argentina_mobile_prefix('+' . $digits);
        }

        // Número local argentino (área + móvil/fijo, típicamente 10 dígitos).
        if (strlen($digits) === 10 || strlen($digits) === 11) {
            return '+549' . $digits;
        }

        // Otros formatos internacionales: conservar con prefijo +.
        if (strlen($digits) > 11) {
            return '+' . $digits;
        }

        // Fallback conservador para números cortos locales.
        return '+549' . $digits;
    }

    /**
     * Compara dos teléfonos tolerando prefijos de país y formato distinto.
     *
     * @param string $phone_a Primer teléfono.
     * @param string $phone_b Segundo teléfono.
     *
     * @return bool true si representan el mismo número.
     */
    public static function phones_match(string $phone_a, string $phone_b): bool
    {
        $normalized_a = self::normalize($phone_a);
        $normalized_b = self::normalize($phone_b);

        if ($normalized_a !== '' && $normalized_a === $normalized_b) {
            return true;
        }

        // Comparación por sufijo para tolerar variaciones de prefijo.
        $digits_a = preg_replace('/\D+/', '', $normalized_a) ?? '';
        $digits_b = preg_replace('/\D+/', '', $normalized_b) ?? '';

        $suffix_lengths = [10, 9, 8];
        foreach ($suffix_lengths as $suffix_length) {
            if (strlen($digits_a) >= $suffix_length && strlen($digits_b) >= $suffix_length) {
                if (substr($digits_a, -$suffix_length) === substr($digits_b, -$suffix_length)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Compara dos teléfonos por IGUALDAD EXACTA de sus dígitos.
     *
     * 🔴 **Es el único comparador de esta clase que NO tolera nada**, y existe para el número
     * del asistente (misión whatsapp-dos-numeros, 13/10/2026), donde un falso positivo le abre a
     * una persona el asistente de IA del negocio de otra: ventas, costos, deudas, clientes.
     *
     * Lo que NO hace, y por qué:
     *
     *   - **No llama a `normalize()`.** `normalize('+15551234567')` devuelve `+54915551234567`: le
     *     pega el prefijo argentino a un número que no lo es. El número del asistente es un +1.
     *   - **No cae al sufijo de ocho dígitos** como `phones_match()`, que da `true` entre
     *     `+5493411234567` y `+5493511234567` — Rosario y Córdoba, dos personas distintas.
     *
     * Se comparan solo los dígitos porque el mismo número llega escrito de varias formas
     * (`+54 9 341 123-4567`, `5493411234567`): eso es formato, no identidad. Lo que no se tolera es
     * que falte o sobre un dígito.
     *
     * Con cualquiera de los dos sin dígitos devuelve false: dos ausencias no son una coincidencia.
     *
     * @param string $phone_a Primer teléfono.
     * @param string $phone_b Segundo teléfono.
     *
     * @return bool true solo si son el MISMO número, dígito a dígito.
     */
    public static function equals_exacto(string $phone_a, string $phone_b): bool
    {
        $digits_a = preg_replace('/\D+/', '', $phone_a) ?? '';
        $digits_b = preg_replace('/\D+/', '', $phone_b) ?? '';

        if ($digits_a === '' || $digits_b === '') {
            return false;
        }

        return $digits_a === $digits_b;
    }

    /**
     * Compara un teléfono GUARDADO en una ficha contra el REMITENTE de un mensaje, sin tolerar
     * ningún dígito de más ni de menos.
     *
     * 🔴 **Es el comparador de «dueño» del número del asistente** (misión asistente-segundo-numero,
     * 15/10/2026): un falso positivo acá le abre a una persona el asistente de IA del negocio de otra
     * (ventas, costos, deudas, clientes). Por eso no usa `phones_match()`, que cae a los últimos ocho
     * dígitos y da `true` entre Rosario y Córdoba.
     *
     * Pero tampoco es una igualdad de dígitos pelada (`equals_exacto()`), porque las fichas guardan el
     * teléfono de un dueño argentino en formatos distintos —`+54 9 341 123-4567`, `3411234567`,
     * `03411234567`— y WhatsApp lo manda siempre como `5493411234567`: una igualdad pelada dejaría
     * sin asistente a todo el que tenga la ficha sin el prefijo del país. Lo que hace, en dos casos:
     *
     *   - **Remitente argentino** (empieza con `54`): se compara el número CANÓNICO de los dos lados
     *     (`normalize()`), completo, sin sufijos. Acá `normalize()` es seguro porque el remitente ya
     *     es argentino: solo ordena el formato.
     *   - **Remitente de cualquier otro país**: igualdad de dígitos, sin `normalize()` en ningún lado.
     *     `normalize('+15551234567')` devuelve `+54915551234567`, y con esa conversión un número de
     *     Estados Unidos podría "coincidir" con la ficha de un argentino de diez dígitos.
     *
     * Con cualquiera de los dos sin dígitos devuelve false: dos ausencias no son una coincidencia.
     *
     * @param string $guardado  Teléfono tal como está en la ficha (cliente, empleado, lead).
     * @param string $remitente Teléfono del remitente tal como llegó en el payload.
     *
     * @return bool
     */
    public static function equals_estricto(string $guardado, string $remitente): bool
    {
        $digitos_guardado  = preg_replace('/\D+/', '', $guardado) ?? '';
        $digitos_remitente = preg_replace('/\D+/', '', $remitente) ?? '';

        if ($digitos_guardado === '' || $digitos_remitente === '') {
            return false;
        }

        if (strpos(ltrim($digitos_remitente, '0'), '54') === 0) {
            $canonico = self::normalize($remitente);

            return $canonico !== '' && $canonico === self::normalize($guardado);
        }

        return $digitos_guardado === $digitos_remitente;
    }

    /**
     * Asegura el 9 móvil argentino tras el código 54 cuando falta (+5411… → +54911…).
     *
     * @param string $e164 Número que ya incluye + y empieza con 54.
     *
     * @return string E.164 ajustado.
     */
    private static function ensure_argentina_mobile_prefix(string $e164): string
    {
        $digits = preg_replace('/\D+/', '', $e164) ?? '';
        if (strpos($digits, '54') !== 0) {
            return $e164;
        }

        // Formato internacional móvil AR: 54 + 9 + área + número (≥ 12 dígitos totales).
        if (strlen($digits) >= 12 && substr($digits, 2, 1) !== '9') {
            $digits = '549' . substr($digits, 2);
        }

        return '+' . $digits;
    }
}
