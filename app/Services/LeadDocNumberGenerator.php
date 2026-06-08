<?php

namespace App\Services;

use App\Models\Lead;

/**
 * Genera números de documento de 12 dígitos para leads creados automáticamente.
 *
 * El formato es determinístico y fácil de recordar a partir del id del lead:
 * - prefijo fijo "20" (2 dígitos),
 * - id del lead con ceros a la izquierda (8 dígitos),
 * - sufijo de verificación derivado del id (2 dígitos).
 *
 * Ejemplo: lead id=42 → 200000004207
 */
class LeadDocNumberGenerator
{
    /**
     * Prefijo constante del documento (parece año 20xx y ancla el patrón).
     */
    public const PREFIX = '20';

    /**
     * Cantidad de dígitos reservados para el id del lead (con padding).
     */
    public const ID_PADDING_LENGTH = 8;

    /**
     * Longitud total esperada del documento generado.
     */
    public const TOTAL_LENGTH = 12;

    /**
     * Construye el doc_number de 12 dígitos a partir del id del lead.
     *
     * @param int $lead_id Id autoincremental del lead.
     *
     * @return string Cadena de exactamente 12 dígitos numéricos.
     */
    public static function from_lead_id(int $lead_id): string
    {
        // Id acotado a 8 dígitos para mantener el largo total en 12.
        $id_bounded = $lead_id % (10 ** self::ID_PADDING_LENGTH);

        // Parte central: id con ceros a la izquierda (p. ej. 42 → 00000042).
        $id_part = str_pad((string) $id_bounded, self::ID_PADDING_LENGTH, '0', STR_PAD_LEFT);

        // Sufijo de 2 dígitos: determinístico pero no obvio a simple vista.
        $checksum = ($lead_id * 7 + 13) % 100;
        $checksum_part = str_pad((string) $checksum, 2, '0', STR_PAD_LEFT);

        return self::PREFIX . $id_part . $checksum_part;
    }

    /**
     * Asigna doc_number al lead si aún no tiene uno (p. ej. alta automática por WhatsApp).
     *
     * @param Lead $lead Lead ya persistido (debe tener id).
     *
     * @return bool true si se guardó un doc_number nuevo.
     */
    public static function assign_to_lead_if_empty(Lead $lead): bool
    {
        // No sobrescribir documentos cargados manualmente desde el panel.
        if (! empty($lead->doc_number)) {
            return false;
        }

        $lead_id = (int) $lead->id;
        if ($lead_id <= 0) {
            return false;
        }

        $lead->doc_number = self::from_lead_id($lead_id);
        $lead->save();

        return true;
    }
}
