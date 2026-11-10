<?php

namespace App\Services;

use App\Events\SupportMessageReceived;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Persiste y envía por WhatsApp una respuesta de texto del operador/IA en tickets de soporte.
 */
class SupportAiSuggestionDeliveryService
{
    /**
     * Resultado del ultimo envio, para que el llamador pueda contarle la verdad al operador.
     *
     * Sin esto, quien aprueba un borrador solo veia el `remote_delivery_status` de la primera
     * parte: en un envio partido a medias esa parte SI salio, asi que la pantalla decia
     * "entregado" mientras las otras dos nunca llegaron al cliente.
     *
     * @var array<string, mixed>|null
     */
    private $ultimo_resultado = null;

    /**
     * Resultado del ultimo envio, o null si todavia no hubo ninguno.
     *
     * @return array<string, mixed>|null
     */
    public function ultimo_resultado()
    {
        return $this->ultimo_resultado;
    }

    /**
     * Crea SupportMessage admin, envía por WhatsApp y emite realtime si el envío fue exitoso.
     *
     * @param SupportTicket $ticket Ticket abierto con source whatsapp y número destino.
     * @param string        $body   Texto a enviar al cliente.
     *
     * @return SupportMessage|null Mensaje persistido o null si no se pudo enviar.
     */
    public function deliver_text_reply(SupportTicket $ticket, string $body): ?SupportMessage
    {
        if (SupportCodexSettings::manages($ticket)) {
            return null;
        }
        $text_body = trim($body);
        if ($text_body === '' || $ticket->status !== 'open') {
            return null;
        }

        if ($ticket->source !== 'whatsapp' || empty($ticket->whatsapp_phone)) {
            Log::channel('daily')->warning('SupportAiSuggestionDeliveryService: ticket sin canal WhatsApp.', [
                'ticket_id' => $ticket->id,
            ]);

            return null;
        }

        $message = SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'admin',
            'sender_admin_id'   => null,
            'kind'              => 'text',
            'body'              => $text_body,
            'delivered_at'      => now(),
            /* Lo escribió el agente, aunque salga sin pasar por una persona. */
            'ai_generated_at'   => now(),
        ]);

        $this->entregar($ticket, $message, 'excepción al enviar');

        $message = SupportMessage::where('id', $message->id)->withAll()->first();
        if ($message !== null) {
            event(new SupportMessageReceived($message->id));
        }

        return $message;
    }

    public function deliver_erp_auto_reply(SupportTicket $ticket, string $body, int $expected_message_id, ?array $tour, bool $solicitud_tour = false): ?SupportMessage
    {
        return $this->deliver_erp_auto($ticket, $body, $expected_message_id, $tour, null, $solicitud_tour);
    }

    public function deliver_erp_auto_draft(SupportMessage $borrador, SupportTicket $ticket): ?SupportMessage
    {
        $datos = $borrador->erp_auto_reply;
        if (! isset($datos['message_id'])) {
            return null;
        }

        return $this->deliver_erp_auto($ticket, (string) $borrador->body, (int) $datos['message_id'],
            SupportTourService::guion($datos['tour'] ?? null), (int) $borrador->id, ($datos['tour_requested'] ?? null) === true);
    }

    public function approve_erp_draft(SupportTicket $ticket, int $draft_id, ?string $edited_body, int $admin_id): SupportMessage
    {
        $mensaje = DB::transaction(function () use ($ticket, $draft_id, $edited_body, $admin_id) {
            $ticket = SupportTicket::lockForUpdate()->findOrFail($ticket->id);
            abort_unless($ticket->status === 'open', 422, 'El ticket está cerrado.');
            $mensaje = SupportMessage::where('support_ticket_id', $ticket->id)->where('is_ai_suggestion_draft', true)
                ->lockForUpdate()->find($draft_id);
            abort_unless($mensaje !== null, 422, 'Ese borrador ya se envió o se descartó.');
            abort_if(SupportCodexSettings::manages($ticket) && isset($mensaje->erp_auto_reply['message_id']),
                409, 'Martín ya está a cargo del ticket. Este borrador de Robotito quedó reemplazado.');

            $body_original = trim((string) $mensaje->body);
            $body = $edited_body !== null ? trim($edited_body) : $body_original;
            $body = SupportTourService::quitar_invitacion($body);
            abort_if($body === '', 422, 'El mensaje no puede quedar vacío.');

            $esperado = $mensaje->erp_auto_reply['message_id'] ?? null;
            $ultimo = SupportMessage::where('support_ticket_id', $ticket->id)->where('is_ai_suggestion_draft', false)->max('id');
            abort_if($esperado !== null ? (int) $ultimo !== (int) $esperado : (int) $ultimo > $mensaje->id,
                409, 'La conversación cambió desde que se preparó el borrador.');

            if ($body !== $body_original) {
                $mensaje->ai_original_body = $body_original;
                $mensaje->body = $body;
            }
            $mensaje->sender_admin_id = $admin_id;
            $this->preparar_erp_message($mensaje);

            $ticket->ai_pending_suggestion = null;
            $ticket->ai_suggestion_send_at = null;
            $ticket->save();
            return $mensaje;
        });

        return $this->sincronizar_erp_message($mensaje);
    }

    private function deliver_erp_auto(SupportTicket $ticket, string $body, int $expected_message_id, ?array $tour, ?int $draft_id, bool $solicitud_tour): ?SupportMessage
    {
        $mensaje = DB::transaction(function () use ($ticket, $body, $expected_message_id, $tour, $draft_id, $solicitud_tour) {
            $ticket = SupportTicket::lockForUpdate()->find($ticket->id);
            if ($ticket === null || $ticket->source !== 'erp' || $ticket->status !== 'open'
                || $ticket->agentes_erp || SupportCodexSettings::manages($ticket)
                || ! $ticket->claude_auto_reply || $ticket->requiere_verificacion_mensajes
                || ! SupportAiSettings::is_suggestions_enabled() || trim($body) === '') {
                return null;
            }

            $entrante = SupportMessage::where('support_ticket_id', $ticket->id)
                ->where('is_ai_suggestion_draft', false)->latest('id')->first();
            if ($entrante === null || $entrante->sender_type !== 'user' || (int) $entrante->id !== $expected_message_id) {
                return null;
            }

            if ($draft_id !== null) {
                $mensaje = SupportMessage::where('support_ticket_id', $ticket->id)->where('is_ai_suggestion_draft', true)
                    ->where('ai_auto_send_at', '<=', now())->lockForUpdate()->find($draft_id);
                if ($mensaje === null) {
                    return null;
                }
                $mensaje->erp_auto_reply = null;
            } else {
                $mensaje = new SupportMessage([
                    'support_ticket_id' => $ticket->id, 'sender_type' => 'admin', 'kind' => 'text', 'ai_generated_at' => now(),
                ]);
            }

            $invitacion = null;
            if (SupportTourService::tiene_invitacion($body)) {
                if ($solicitud_tour) {
                    $invitacion = ['tour_requested' => true];
                } elseif ($tour !== null) {
                    $invitacion = ['tour_invitation' => $tour];
                } else {
                    $body = SupportTourService::quitar_invitacion($body);
                }
            } elseif ($tour !== null) {
                $resultado = app(SupportTourService::class)->ofrecer($ticket, $entrante, $tour);
                if ($resultado['ok']) {
                    $body .= "\n\n" . SupportTourService::AVISO;
                } elseif (! in_array($resultado['code'], ['tour_consent_required', 'tour_invitation_mismatch'], true)) {
                    $body .= "\n\nNo pude confirmar que Robotito quedara disponible. Podés seguir los pasos que te pasé antes.";
                }
            }
            if (trim($body) === '') {
                return null;
            }
            $mensaje->body = $body;
            $this->preparar_erp_message($mensaje, $invitacion);
            return $mensaje;
        });

        // El cron y los reintentos ven el mismo cuerpo definitivo y el mismo UUID.
        return $mensaje === null ? null : $this->sincronizar_erp_message($mensaje);
    }

    private function preparar_erp_message(SupportMessage $message, ?array $invitacion = null): void
    {
        $message->is_ai_suggestion_draft = false;
        $message->ai_auto_send_at = null;
        $message->erp_auto_reply = $invitacion;
        $message->delivered_at = now();
        $message->remote_delivery_status = 'not_received';
        $message->save();
    }

    private function sincronizar_erp_message(SupportMessage $message): SupportMessage
    {
        app(SupportClientSyncService::class)->sync_message_to_client($message);
        SupportMessageReceived::dispatch_after_commit($message->id);

        return $message;
    }

    /**
     * Envía por WhatsApp un borrador IA existente y lo convierte en mensaje enviado.
     *
     * @param SupportMessage $message Borrador con is_ai_suggestion_draft=true.
     * @param SupportTicket  $ticket  Ticket contenedor.
     *
     * @return SupportMessage|null
     */
    public function deliver_draft_message(SupportMessage $message, SupportTicket $ticket): ?SupportMessage
    {
        if (SupportCodexSettings::manages($ticket)) {
            return null;
        }
        $text_body = trim((string) ($message->body ?? ''));
        if ($text_body === '' || $ticket->status !== 'open') {
            return null;
        }

        if ($ticket->source !== 'whatsapp' || empty($ticket->whatsapp_phone)) {
            Log::channel('daily')->warning('SupportAiSuggestionDeliveryService: ticket sin canal WhatsApp.', [
                'ticket_id' => $ticket->id,
            ]);

            return null;
        }

        $message->is_ai_suggestion_draft = false;
        $message->ai_auto_send_at = null;
        $message->delivered_at = now();
        $message->save();

        $this->entregar($ticket, $message, 'excepción al enviar borrador');

        $loaded = SupportMessage::query()->where('id', $message->id)->withAll()->first();
        if ($loaded !== null) {
            event(new SupportMessageReceived($loaded->id));
        }

        return $message;
    }

    /**
     * Manda el mensaje por WhatsApp respetando la ventana de 24hs de Meta.
     *
     * Antes esto llamaba derecho a `WhatsappSendService::send_support_message()`, que solo sabe
     * mandar texto libre. Un auto-envío que cae pasadas las 24hs del último mensaje del cliente
     * —una sugerencia demorada, un ticket viejo que se reabre— lo rechaza Meta, y quedaba
     * marcado "no recibido" sin ningún motivo a la vista. `deliver_follow_up()` resuelve la
     * ventana y, si está cerrada, manda la plantilla aprobada con el texto adentro.
     *
     * @param SupportTicket  $ticket           Ticket de WhatsApp con número destino.
     * @param SupportMessage $message          Mensaje ya persistido.
     * @param string         $contexto_de_log  Frase para el log si salta una excepción.
     *
     * @return void
     */
    private function entregar(SupportTicket $ticket, SupportMessage $message, string $contexto_de_log): void
    {
        try {
            $resultado = app(SupportWhatsappOpenerService::class)
                ->deliver_follow_up($ticket, $message, $this->resolver_nombre_del_operador($ticket));

            $this->ultimo_resultado = $resultado;

            if ($resultado['delivery'] === 'partial') {
                // Ni "salio" ni "no salio": salio a medias. Decirle "no salio" seria repetir el
                // error del incidente del lead #440 en el log en vez de en la base.
                Log::channel('daily')->warning('SupportAiSuggestionDeliveryService: el mensaje del agente salio a medias.', [
                    'ticket_id'   => $ticket->id,
                    'message_id'  => $message->id,
                    'sent_parts'  => $resultado['sent_parts'],
                    'total_parts' => $resultado['total_parts'],
                    'error'       => $resultado['error'],
                ]);
            } elseif ($resultado['delivery'] !== 'sent') {
                Log::channel('daily')->warning('SupportAiSuggestionDeliveryService: el mensaje del agente no salió.', [
                    'ticket_id'     => $ticket->id,
                    'message_id'    => $message->id,
                    'used_template' => $resultado['used_template'],
                    'error'         => $resultado['error'],
                ]);
            }
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('SupportAiSuggestionDeliveryService: '.$contexto_de_log.'.', [
                'ticket_id'  => $ticket->id,
                'message_id' => $message->id,
                'error'      => $exception->getMessage(),
            ]);
            $message->remote_delivery_status = 'not_received';
            $message->save();
        }
    }

    /**
     * Nombre que firma la plantilla cuando la ventana está cerrada.
     *
     * El agente no es una persona, así que se usa el operador asignado al ticket. Si no hay
     * ninguno, queda "Soporte" y no el nombre de nadie en particular.
     *
     * @param SupportTicket $ticket Ticket con el operador asignado.
     *
     * @return string
     */
    private function resolver_nombre_del_operador(SupportTicket $ticket): string
    {
        $ticket->loadMissing('assigned_admin');
        $nombre = $ticket->assigned_admin !== null ? trim((string) $ticket->assigned_admin->name) : '';

        return $nombre !== '' ? $nombre : 'Soporte';
    }
}
