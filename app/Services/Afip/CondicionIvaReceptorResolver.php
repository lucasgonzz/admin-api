<?php

namespace App\Services\Afip;

/**
 * Mapeo de la condición IVA (texto libre guardado en `clients.afip_condicion_iva`)
 * al `CondicionIVAReceptorId` que espera AFIP en el comprobante WSFE.
 *
 * Port simplificado de `App\Http\Controllers\Helpers\Afip\CondicionIvaReceptorHelper`
 * (empresa-api): allá el mapeo se resuelve a partir de `sale->client->iva_condition`
 * (una relación Eloquent); acá el dato ya viene como string plano cacheado en el
 * propio `Client` de admin (prompt 328/329), así que alcanza con un mapeo directo
 * nombre → id, sin necesidad de cargar ninguna relación.
 */
class CondicionIvaReceptorResolver
{
    /**
     * Id de AFIP a usar cuando el cliente no tiene condición IVA cargada o el
     * texto no matchea ninguno de los valores conocidos. Consumidor Final es el
     * fallback más conservador (no supone que el cliente esté inscripto).
     *
     * @var int
     */
    const FALLBACK_ID = 5;

    /**
     * Resuelve el `CondicionIVAReceptorId` de AFIP a partir del nombre de
     * condición IVA guardado en el receptor.
     *
     * @param  string|null $condicion_iva_nombre Texto libre (ej. "Monotributista").
     * @return int
     */
    public static function resolve($condicion_iva_nombre)
    {
        // Sin dato cargado: se usa el fallback (Consumidor Final) sin romper la emisión.
        if (empty($condicion_iva_nombre)) {
            return self::FALLBACK_ID;
        }

        // Normalizamos a minúsculas y sin espacios extra para comparar de forma tolerante.
        $nombre = mb_strtolower(trim($condicion_iva_nombre));

        // Mapeo estándar AFIP (mismos valores que empresa-api, ver CondicionIvaReceptorHelper).
        $mapa = [
            'responsable inscripto' => 1,
            'exento' => 4,
            'consumidor final' => 5,
            'monotributista' => 6,
        ];

        return isset($mapa[$nombre]) ? $mapa[$nombre] : self::FALLBACK_ID;
    }
}
