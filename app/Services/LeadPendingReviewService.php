<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\Log;

/**
 * Marca leads como "pendientes de revisión" (pendiente_revision_at), sin enviar ni generar nada.
 *
 * Es la lógica del botón de revisión de la barra de leads (reemplaza el recovery masivo viejo).
 */
class LeadPendingReviewService
{
    /** Estados en los que no tiene sentido marcar para revisión (lead ya cerrado o pausado). */
    const ESTADOS_EXCLUIDOS = ['cerrado_ganado', 'cerrado_perdido', 'en_pausa'];

    /**
     * Setea pendiente_revision_at = now() en cada lead que amerita revisión.
     *
     * Razón A: mensajes del lead sin responder (reusa LeadConversationAiState::has_unanswered_lead_messages).
     * Razón B: el hilo termina en un error sin resolver (is_error posterior a toda actividad real).
     *
     * El marcado es idempotente (no pisa una marca previa) y global (recorre todos los leads activos).
     *
     * @return array{marcados:int, ya_marcados:int, sin_pendientes:int}
     */
    public function mark_pending_leads(): array
    {
        // Leads activos (excluye cerrados/en pausa) con sus mensajes cargados para clasificar razón A/B.
        $leads = Lead::query()
            ->with('messages')
            ->whereNotIn('status', self::ESTADOS_EXCLUIDOS)
            ->get();

        // Contadores devueltos para el toast del frontend (prompt 296).
        $marcados = 0;
        $ya_marcados = 0;
        $sin_pendientes = 0;

        foreach ($leads as $lead) {
            if (! $this->lead_requiere_revision($lead)) {
                $sin_pendientes++;
                continue;
            }

            /* Idempotente: no pisar una marca previa. */
            if ($lead->pendiente_revision_at !== null) {
                $ya_marcados++;
                continue;
            }

            $lead->pendiente_revision_at = now();
            $lead->save();
            $marcados++;
        }

        Log::channel('daily')->info('LeadPendingReviewService: marcado de pendientes completado.', [
            'marcados' => $marcados,
            'ya_marcados' => $ya_marcados,
            'sin_pendientes' => $sin_pendientes,
        ]);

        return [
            'marcados' => $marcados,
            'ya_marcados' => $ya_marcados,
            'sin_pendientes' => $sin_pendientes,
        ];
    }

    /**
     * Determina si un lead amerita revisión por razón A (mensajes sin responder) o razón B
     * (error sin resolver al final del hilo).
     *
     * @param Lead $lead Lead con relación messages cargada (ordenada por id).
     *
     * @return bool
     */
    private function lead_requiere_revision(Lead $lead): bool
    {
        /* Razón A: mensajes del lead sin responder (definición existente del sistema). También cubre
           las sugerencias de Claude cuyo envío falló: al fallar quedan 'rechazado' y el mensaje del
           lead sigue contando como sin responder. */
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
