<?php

namespace App\Services;

use App\Models\Lead;

/**
 * Define, en PHP, cuándo un lead amerita atención humana.
 *
 * 🔴 El nombre quedó del botón de revisión que existió hasta el 2/9/2026 — ese botón marcaba
 * `pendiente_revision_at` para pintar filas de rojo, y se sacó cuando el rojo y el amarillo
 * pasaron a calcularse en vivo: la marca ya no cambiaba ningún color. Lo que SÍ sigue vivo, y es
 * el motivo por el que esta clase no se borró con el botón, es
 * {@see self::lead_requiere_revision()}: es el **gemelo en PHP** de `Lead::scopeRequiereRevision()`
 * y el oráculo contra el que `RevisionDeLeadsEnSqlYEnPhpCoincidenTest` compara la versión SQL,
 * lead por lead. Sin él no hay con qué verificar que la tarjeta y la grilla digan lo mismo.
 *
 * No marca ni escribe nada: solo decide.
 */
class LeadPendingReviewService
{
    /**
     * Determina si un lead amerita revisión por razón A (mensajes sin responder) o razón B
     * (error sin resolver al final del hilo).
     *
     * Público (no privado) porque `Lead::scopeRequiereRevision()` es su gemelo en SQL y
     * `RevisionDeLeadsEnSqlYEnPhpCoincidenTest` compara las dos implementaciones lead por lead.
     * No volver a hacerlo privado sin borrar ese test.
     *
     * @param Lead $lead Lead con relación messages cargada (ordenada por id).
     *
     * @return bool
     */
    public function lead_requiere_revision(Lead $lead): bool
    {
        /* Gemelo de la guarda de Lead::scopeRequiereRevision(): un lead marcado como "ya no recibe
           mensajes" no pide atención — no hay nada que reintentar ni a quién contestarle— y no
           puede contar en la tarjeta sin que la grilla lo pinte, que es el defecto que Lucas
           reportó el 2/9/2026. */
        if ($lead->no_recibe_mensajes_at !== null) {
            return false;
        }

        /* Razón A: mensajes del lead sin responder. Cuenta como respuesta solo un saliente que
           efectivamente salió por WhatsApp (ver LeadMessage::is_reply_to_lead), así que también
           cubre los dos casos en que el sistema generó algo que el lead nunca vio: la sugerencia
           que espera verificación, y el envío que falló. */
        if (LeadConversationAiState::has_unanswered_lead_messages($lead)) {
            return true;
        }

        /* Razón B: el hilo termina en un error sin resolver. */
        return $this->tiene_error_sin_resolver($lead);
    }

    /**
     * True si el hilo termina en un error (is_error) sin actividad real posterior: ningún mensaje del
     * lead ni del setter/sistema después del último error registrado. Captura fallos de envío/generación
     * (incluidos los de seguimientos automáticos) que no dejaron un mensaje entrante esperando.
     *
     * @param Lead $lead
     *
     * @return bool
     */
    private function tiene_error_sin_resolver(Lead $lead): bool
    {
        $messages = $lead->messages;
        if ($messages === null || $messages->isEmpty()) {
            return false;
        }

        // Id del último mensaje marcado como error y del último mensaje de "actividad real".
        $last_error_id = 0;
        $last_real_id = 0;

        foreach ($messages as $message) {
            $id = (int) $message->id;

            if ($message->is_error) {
                if ($id > $last_error_id) {
                    $last_error_id = $id;
                }
                continue;
            }

            /* Actividad "real" = cualquier mensaje que no sea evento de estado (mensaje del lead,
               del setter, o sugerencia del sistema). Los registros de error son is_status_event=true,
               así que no cuentan como actividad real acá. */
            if (! $message->is_status_event) {
                if ($id > $last_real_id) {
                    $last_real_id = $id;
                }
            }
        }

        return $last_error_id > 0 && $last_error_id > $last_real_id;
    }
}
