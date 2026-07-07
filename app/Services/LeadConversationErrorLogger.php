<?php

namespace App\Services;

use App\Models\LeadMessage;
use Illuminate\Support\Facades\Log;

/**
 * Registra en la conversación del lead un mensaje de ERROR (fallo de envío o de generación de IA),
 * para que el operador vea en el hilo qué pasó y pueda reportarlo con detalle.
 *
 * El mensaje se crea como sistema + is_status_event=true (no mueve el orden de la bandeja, no cuenta
 * como "sin leer" del lead ni entra en las notificaciones de listado) + is_error=true (MessageBubble
 * lo renderiza como bloque rojo, prompt 300).
 */
class LeadConversationErrorLogger
{
    /** Máximo de caracteres del detalle del error guardado en el hilo. */
    const MAX_DETALLE = 800;

    /**
     * Inserta un mensaje de error en la conversación del lead. Nunca lanza: si falla el registro,
     * solo loguea (no debe tapar ni romper el error original que lo disparó).
     *
     * @param int    $lead_id
     * @param string $contexto Etiqueta legible de qué falló (ej. "No se pudo enviar la sugerencia por WhatsApp").
     * @param string $detalle  Detalle técnico (mensaje de la excepción).
     *
     * @return void
     */
    public function log(int $lead_id, string $contexto, string $detalle): void
    {
        if ($lead_id <= 0) {
            return;
        }

        try {
            $contexto = trim($contexto);
            $detalle = trim($detalle);

            if (strlen($detalle) > self::MAX_DETALLE) {
                $detalle = substr($detalle, 0, self::MAX_DETALLE) . '…';
            }

            $content = $contexto;
            if ($detalle !== '') {
                $content = $contexto !== '' ? ($contexto . ': ' . $detalle) : $detalle;
            }
            if ($content === '') {
                $content = 'Error de sistema';
            }

            $message = LeadMessage::create([
                'lead_id'             => $lead_id,
                'sender'              => 'sistema',
                'content'             => $content,
                'status'              => 'enviado',
                'is_status_event'     => true,
                'is_error'            => true,
                'is_followup'         => false,
                'whatsapp_message_id' => null,
                'sent_at'             => null,
            ]);

            LeadBroadcastService::emit_conversation_updated($lead_id, (int) $message->id);
        } catch (\Throwable $e) {
            Log::channel('daily')->error('LeadConversationErrorLogger: no se pudo registrar el error en el hilo.', [
                'lead_id' => $lead_id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
