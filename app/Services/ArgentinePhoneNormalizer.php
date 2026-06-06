<?php

namespace App\Services;

/**
 * Normalizador de números de teléfono argentinos al formato E.164.
 *
 * Convierte números en formatos variables (con o sin código de país, con 15, etc.)
 * al formato estándar internacional +549XXXXXXXXXX para uso en WhatsApp y APIs externas.
 */
class ArgentinePhoneNormalizer
{
    /**
     * Normaliza un número de teléfono argentino al formato E.164 con prefijo +.
     *
     * Reglas aplicadas en orden de prioridad:
     *
     * 1. Quitar todo carácter no dígito del input.
     * 2. 13 dígitos empezando en 549  → ya en E.164 sin +, agregar +.
     * 3. 12 dígitos empezando en 54   → falta el 9 de móvil, insertar 9 después del 54.
     * 4. 10 dígitos empezando en 15   → número local con 15 (fallback Buenos Aires),
     *                                    reemplazar 15 por +5491.
     * 5. 11 dígitos empezando en 9    → falta el prefijo 54, agregar +54 adelante.
     * 6. 10 dígitos sin 15 ni 9 inicial → número local sin código de país ni 15,
     *                                      agregar +549 adelante.
     * 7. 8 dígitos → número local sin característica, no normalizable con certeza;
     *                devolver el raw original para revisión manual.
     * 8. Cualquier otro caso → devolver el raw original sin modificar.
     *
     * @param string $raw Número de teléfono en formato libre (puede incluir guiones, espacios, etc.).
     *
     * @return string Número en E.164 comenzando con + si fue normalizado, o raw original si no.
     */
    public static function normalize(string $raw): string
    {
        /* Quitar todo carácter que no sea dígito para trabajar sobre la cadena limpia. */
        $digits = preg_replace('/\D/', '', $raw);

        /* Fallback seguro ante resultado null de preg_replace (no debería ocurrir). */
        if ($digits === null) {
            return $raw;
        }

        /* Longitud de la cadena de dígitos para decidir la regla aplicable. */
        $length = strlen($digits);

        /* Regla 2: 13 dígitos empezando en 549 → ya está en E.164 sin +. */
        if ($length === 13 && strpos($digits, '549') === 0) {
            return '+' . $digits;
        }

        /* Regla 3: 12 dígitos empezando en 54 → insertar el 9 de móvil argentino. */
        if ($length === 12 && strpos($digits, '54') === 0) {
            return '+549' . substr($digits, 2);
        }

        /* Regla 4: 10 dígitos empezando en 15 → número local de Buenos Aires con 15.
         * Se reemplaza el 15 por +5491 (código de país + 9 móvil + 1 área Buenos Aires). */
        if ($length === 10 && strpos($digits, '15') === 0) {
            return '+5491' . substr($digits, 2);
        }

        /* Regla 5: 11 dígitos empezando en 9 → número móvil sin prefijo 54. */
        if ($length === 11 && strpos($digits, '9') === 0) {
            return '+54' . $digits;
        }

        /* Regla 6: 10 dígitos sin 15 ni 9 inicial → número local sin código de país ni 15.
         * Se asume que es un número celular completo (característica + número). */
        if ($length === 10 && strpos($digits, '15') !== 0 && strpos($digits, '9') !== 0) {
            return '+549' . $digits;
        }

        /* Regla 7: 8 dígitos → número local sin característica, imposible normalizar
         * con certeza. Devolver raw original para que un operador lo revise. */
        if ($length === 8) {
            return $raw;
        }

        /* Regla 8: cualquier otro formato no contemplado → devolver raw original. */
        return $raw;
    }
}
