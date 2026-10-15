<?php

namespace App\Services;

use App\Models\SupportMessage;
use App\Models\SupportTicket;

/**
 * Define, en PHP, cuándo un ticket de soporte está "sin responder".
 *
 * Hermana de {@see LeadPendingReviewService} y escrita por el mismo motivo: el criterio existe dos
 * veces —una en SQL y una en PHP— porque hidratar el hilo de todos los tickets para contar la
 * tarjeta del panel de inicio no es viable (la relación `messages` del ticket arrastra adjuntos y
 * admin emisor), pero una consulta con dos `EXISTS` anidados tampoco se puede leer de un vistazo
 * para saber si dice lo que tiene que decir.
 *
 * Esta clase es el lado legible: el ÚNICO motivo por el que existe es ser el oráculo contra el que
 * `TicketsSinResponderEnSqlYEnPhpCoincidenTest` compara {@see SupportTicket::scopeSinResponder()},
 * ticket por ticket. Es una equivalencia que no se sostiene sola: cualquiera de los dos lados se
 * puede tocar sin que el otro se entere, y la divergencia no da error — da un número apenas
 * distinto que nadie audita.
 *
 * No marca ni escribe nada: solo decide.
 */
class SupportPendingReplyService
{
    /**
     * ¿Este ticket está esperando que el operador conteste?
     *
     * Gemelo en PHP de {@see SupportTicket::scopeSinResponder()}: el ticket tiene que estar `open` y
     * su último mensaje REAL tiene que ser del cliente (`sender_type = 'user'`). Lo que es un
     * mensaje real lo decide {@see SupportMessage::es_mensaje_real()} —el mismo método que usa el
     * gemelo SQL por su versión `apply_mensaje_real_conditions()`—, así que un borrador de la IA sin
     * aprobar se saltea y no tapa al mensaje del cliente que sigue esperando.
     *
     * ⚠️ Acá tampoco se mira la entrega del saliente, igual que en el gemelo SQL y a diferencia de
     * lo que hace leads con `whatsapp_message_id`: los tickets del ERP no lo tienen nunca. El por
     * qué completo está en el docblock de {@see SupportTicket::scopeSinResponder()}.
     *
     * Público (no privado) a propósito: es el oráculo del test de paridad. No volverlo privado sin
     * borrar ese test — es la misma advertencia que lleva
     * {@see LeadPendingReviewService::lead_requiere_revision()}.
     *
     * @param SupportTicket $ticket Ticket con la relación `messages` cargada (ya viene ordenada por id).
     *
     * @return bool
     */
    public function ticket_sin_responder(SupportTicket $ticket): bool
    {
        /* Gemelo de la primera condición del scope: un ticket cerrado no pide nada. */
        if ((string) $ticket->status !== SupportTicket::STATUS_ABIERTO) {
            return false;
        }

        $mensajes = $ticket->messages;
        if ($mensajes === null || $mensajes->isEmpty()) {
            return false;
        }

        /* Se busca por id mayor y no se confía en el orden de la colección: la relación viene
           ordenada por id, pero este método también se llama sobre colecciones armadas a mano. */
        $ultimo_real = null;
        foreach ($mensajes as $mensaje) {
            if (! SupportMessage::es_mensaje_real($mensaje)) {
                continue;
            }

            if ($ultimo_real === null || (int) $mensaje->id > (int) $ultimo_real->id) {
                $ultimo_real = $mensaje;
            }
        }

        /* Hilo vacío de mensajes reales (solo borradores, o nada): no hay nadie esperando. */
        if ($ultimo_real === null) {
            return false;
        }

        return (string) $ultimo_real->sender_type === 'user';
    }
}
