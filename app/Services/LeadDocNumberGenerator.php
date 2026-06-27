<?php

namespace App\Services;

use App\Models\Lead;

/**
 * Genera números de documento aleatorios de 5 dígitos para leads creados automáticamente.
 *
 * Se usa como credencial de demo (usuario y contraseña). El valor es aleatorio
 * y se verifica unicidad en la tabla leads antes de persistirlo.
 *
 * Ejemplo: 48291
 */
class LeadDocNumberGenerator
{
    /**
     * Longitud total esperada del documento generado.
     */
    public const TOTAL_LENGTH = 5;

    /**
     * Valor mínimo del rango aleatorio (inclusive).
     */
    public const MIN_VALUE = 0;

    /**
     * Valor máximo del rango aleatorio (inclusive).
     */
    public const MAX_VALUE = 99999;

    /**
     * Intentos máximos para obtener un doc_number no usado por otro lead.
     */
    public const MAX_UNIQUENESS_ATTEMPTS = 100;

    /**
     * Genera un doc_number aleatorio de exactamente 5 dígitos numéricos.
     *
     * @return string Cadena de exactamente 5 dígitos (p. ej. 0042 → "00042").
     */
    public static function generate_random(): string
    {
        // Número aleatorio criptográficamente seguro en el rango 00000–99999.
        $raw_value = random_int(self::MIN_VALUE, self::MAX_VALUE);

        // Padding a la izquierda para garantizar longitud fija de 5.
        return str_pad((string) $raw_value, self::TOTAL_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Genera un doc_number aleatorio de 5 dígitos que no exista en otro lead.
     *
     * @return string Cadena de exactamente 5 dígitos numéricos, única en leads.doc_number.
     *
     * @throws \RuntimeException Si no se encuentra un valor libre tras los reintentos.
     */
    public static function generate_unique_random(): string
    {
        // Reintentos ante colisión (poco probable con 100k valores posibles).
        for ($attempt = 0; $attempt < self::MAX_UNIQUENESS_ATTEMPTS; $attempt++) {
            $doc_number = self::generate_random();

            // Solo aceptar si ningún otro lead ya usa ese documento.
            $already_used = Lead::query()
                ->where('doc_number', $doc_number)
                ->exists();

            if (! $already_used) {
                return $doc_number;
            }
        }

        throw new \RuntimeException(
            'No se pudo generar un doc_number único de '.self::TOTAL_LENGTH.' dígitos.'
        );
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

        $lead->doc_number = self::generate_unique_random();
        $lead->save();

        return true;
    }
}
