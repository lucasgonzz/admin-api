<?php

namespace App\Helpers;

/**
 * Normaliza a UTF-8 el texto que devuelven los web services SOAP de ARCA/AFIP.
 *
 * Port de `App\Http\Controllers\Helpers\Utf8Helper` (empresa-api). Existe porque
 * el cliente SOAP del padrón se abre con `'encoding' => 'ISO-8859-1'` (lo exige
 * el servicio: con UTF-8 declarado, ARCA devuelve bytes que PHP interpreta mal),
 * así que las razones sociales y domicilios con acentos o "ñ" llegan en
 * Windows-1252 / ISO-8859-1 y hay que pasarlos a UTF-8 antes de guardarlos.
 *
 * Sin este paso el dato entra roto a `clients.afip_razon_social` y sale roto en
 * el PDF de la factura, que es donde se ve.
 */
class Utf8Normalizer
{
    /**
     * Normaliza recursivamente un valor (array, objeto o string) a UTF-8 limpio.
     * Los valores que no son texto (números, booleanos, null) pasan intactos.
     *
     * @param  mixed $value
     * @return mixed
     */
    public static function convertir($value)
    {
        if (is_object($value)) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            foreach ($value as $key => $val) {
                $value[$key] = self::convertir($val);
            }

            return $value;
        }

        if (is_string($value)) {
            $value = self::a_utf8($value);
            $value = self::limpiar($value);

            /* ARCA a veces devuelve la comilla simple escapada. Se la restaura, no se
               la borra: el original de empresa-api hace `str_replace("\\'", '')`, que
               convierte "D'ANGELO S.A." en "DANGELO S.A." — y eso entra así a
               `clients.afip_razon_social` y sale impreso en la factura. Los apellidos
               con apóstrofo (D'Angelo, D'Agostino, O'Higgins) no son raros en el
               padrón, así que acá el port se aparta del original a propósito. */
            return str_replace("\\'", "'", $value);
        }

        return $value;
    }

    /**
     * Convierte un string a UTF-8 de forma tolerante: si ya es UTF-8 válido no
     * lo toca, y si no, prueba en orden Windows-1252, ISO-8859-1 y finalmente
     * `iconv` ignorando los bytes que no pueda mapear.
     *
     * Se prioriza Windows-1252 sobre ISO-8859-1 porque es lo que devuelve ARCA
     * en la práctica (mismo criterio que el original de empresa-api).
     *
     * @param  string $value
     * @return string
     */
    private static function a_utf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $encoding = mb_detect_encoding($value, ['Windows-1252', 'ISO-8859-1', 'UTF-8'], true);

        if ($encoding) {
            $convertido = @mb_convert_encoding($value, 'UTF-8', $encoding);

            if (is_string($convertido) && $convertido !== '') {
                return $convertido;
            }
        }

        $convertido = @iconv('Windows-1252', 'UTF-8//IGNORE', $value);

        if (is_string($convertido) && $convertido !== '') {
            return $convertido;
        }

        $convertido = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $value);

        if (is_string($convertido) && $convertido !== '') {
            return $convertido;
        }

        return $value;
    }

    /**
     * Saca caracteres de control y colapsa espacios raros, preservando las
     * letras acentuadas. Corre sobre texto que ya está en UTF-8.
     *
     * @param  string $value
     * @return string
     */
    private static function limpiar(string $value): string
    {
        $sin_controles = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

        if (is_string($sin_controles)) {
            $value = $sin_controles;
        }

        $espacios_normalizados = preg_replace('/\s+/u', ' ', $value);

        if (is_string($espacios_normalizados)) {
            $value = $espacios_normalizados;
        }

        return trim($value);
    }
}
