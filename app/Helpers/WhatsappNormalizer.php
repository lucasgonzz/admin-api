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
