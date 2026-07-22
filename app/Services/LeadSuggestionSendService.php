<?php

namespace App\Services;

use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadPipelineStatus;
use App\Models\ProtocolEntry;
use Illuminate\Support\Facades\Log;

/**
 * Envía por WhatsApp un mensaje sugerido por Claude tras aprobación del setter en admin-spa.
 *
 * FIX (prompt 366, lead #440, 22/7/2026): una sugerencia partida en varios mensajes tiene tres
 * desenlaces posibles, no dos. Antes de este cambio solo existían "nada enviado" (rechazado) y
 * "todo enviado" (enviado); una sugerencia de 4 partes que llegó hasta la 3ra y falló la 4ta con
 * un 409 de Kapso ("otro mensaje en vuelo para esta conversación") se registraba igual como
 * "rechazado" — el hilo mostraba el bloque rojo "No se pudo enviar" pese a que el lead sí había
 * recibido y respondido al contenido. Ahora existe el caso intermedio de **envío parcial**
 * (0 < partes enviadas < partes totales): se registra `status = 'enviado'` con
 * `sent_parts_count`/`total_parts_count`/`partial_send_pending`, se aplica igual el pipeline
 * sugerido y se deja constancia clara en el hilo de cuántos mensajes llegaron. Además,
 * `send_body()` ahora espacia el envío de cada parte y reintenta con backoff ante fallos
 * transitorios (409/429/5xx), para que el 409 de Kapso deje de ser la causa habitual del caso C.
 */
class LeadSuggestionSendService
{
    /**
     * @var WhatsappSendService
     */
    private $whatsapp_send_service;

    /**
     * @param WhatsappSendService|null $whatsapp_send_service
     */
    public function __construct(?WhatsappSendService $whatsapp_send_service = null)
    {
        $this->whatsapp_send_service = $whatsapp_send_service ?? new WhatsappSendService();
    }

    /**
     * Envía el texto al lead por WhatsApp y marca el mensaje como enviado.
     *
     * Si el cuerpo contiene el separador "\n---\n", se parte en múltiples mensajes
     * y se envían secuencialmente. El whatsapp_message_id que se persiste corresponde
     * al último envío.
     *
     * @param LeadMessage $message       Mensaje en estado `sugerido`.
     * @param string|null $edited_content Texto final; si es null se usa content del mensaje.
     * @param array|null  $final_actions  Paquete de acciones editado por el admin (prompt 320, ver
     *                                    contrato `final_actions` en LeadAiService::apply_pending_actions()).
     *                                    Si es null se aplican las acciones originales de Claude
     *                                    (comportamiento sin cambios).
     * @param bool        $is_auto_send   FIX (prompt 337): true cuando llama AutoSendLeadAiSuggestionJob
     *                                    (respaldo automático, sin revisión humana), false (default) cuando
     *                                    llama un endpoint de aprobación humana. Con pending_actions y
     *                                    true, nunca se ejecutan acciones con efecto externo
     *                                    (agendar_demo/cancelar_demo/Mail 1) a ciegas — ver Caso A/B en
     *                                    el cuerpo del método.
     * @param int|null    $sent_by_admin_id (prompt 403) Admin que aprobó la sugerencia desde el panel
     *                                    (Auth::id() del endpoint approve_*). Null cuando el auto-envío
     *                                    de respaldo la manda sin revisión humana.
     *
     * @return LeadMessage
     */
    public function send_suggestion(LeadMessage $message, ?string $edited_content = null, ?array $final_actions = null, bool $is_auto_send = false, ?int $sent_by_admin_id = null): LeadMessage
    {
        if ((string) $message->sender !== 'sistema') {
            throw new \InvalidArgumentException('Solo se pueden enviar sugerencias del sistema.');
        }

        if ((string) $message->status !== 'sugerido') {
            throw new \InvalidArgumentException('Solo se pueden enviar mensajes en estado sugerido.');
        }

        $lead = $message->lead;
        if ($lead === null) {
            $lead = Lead::query()->find($message->lead_id);
        }

        if ($lead === null) {
            throw new \RuntimeException('Lead no encontrado para el mensaje.');
        }

        /*
         * Seguimiento por plantilla pendiente de aprobación (tramo de agenda, prompt 283). Se reenvía
         * SIEMPRE por su plantilla Meta guardada (send_template), no por send_text: los seguimientos
         * disparan justamente cuando el lead quedó en silencio, así que la ventana de 24hs suele estar
         * cerrada y send_text daría 422. La plantilla no admite texto editado, así que $edited_content
         * se ignora en este camino (el setter aprueba o rechaza; para escribir algo propio, rechaza y
         * responde manualmente).
         */
        if ($message->is_followup && ! empty($message->followup_template_id)) {
            return $this->send_followup_suggestion_via_template($message, $lead, $sent_by_admin_id);
        }

        /*
         * FIX (prompt 337): resguardo del respaldo automático. Antes de aplicar cualquier acción
         * pendiente, si esto es un auto-envío (job, sin revisión humana) y el paquete trae
         * agendar_demo o cancelar_demo, se corta acá (Caso A): el texto de Claude confirma un
         * horario al lead y no hay forma segura de mandarlo sin persistir la reserva. El mensaje
         * queda `sugerido` sin tocar, a la espera de que un humano lo apruebe desde el panel.
         */
        if ($is_auto_send && ! empty($message->pending_actions)) {
            $pending_actions = $message->pending_actions;
            if (! empty($pending_actions['agendar_demo']) || ! empty($pending_actions['cancelar_demo'])) {
                return $this->handle_auto_send_agendamiento_gate($message, $lead);
            }
        }

        /*
         * Mensajes que quedaron pendientes por el motivo "agendamiento" (ver
         * LeadAiService::requires_agendamiento_verification_gate) no aplicaron todavía ninguna
         * acción (agendar_demo, guardar_nombre, mail, etc.) — se aplican recién acá, al aprobar,
         * revalidando disponibilidad en este momento y no la de cuando Claude respondió. Si la
         * validación falla (ej. el horario ya se ocupó mientras esperaba aprobación), no se envía
         * nada al lead: el error se propaga para que LeadController devuelva 422 y el admin pida
         * una sugerencia nueva.
         */
        if (! empty($message->pending_actions)) {
            if ($is_auto_send) {
                /*
                 * FIX (prompt 337): Caso B del respaldo automático. Llegar hasta acá ya significa
                 * que el paquete NO trae agendar_demo ni cancelar_demo (se filtró arriba), así que
                 * es seguro auto-enviar el texto — pero solo aplicando acciones sin efecto externo.
                 * Se arma un `final_actions` mínimo que desactiva explícitamente agendar_demo y
                 * cancelar_demo (por si vinieran igual en el paquete crudo) y fuerza
                 * enviar_mail_demo=false (el Mail 1 de acceso a la demo nunca sale sin aprobación
                 * humana, aunque guardar_email haya guardado un email nuevo). El resto de las
                 * acciones (guardar_nombre, guardar_email, estado_sugerido,
                 * requiere_intervencion_humana/motivo_intervencion) no se tocan acá: al no venir en
                 * este array, apply_pending_actions() conserva el valor original de Claude.
                 */
                $final_actions = [
                    'agendar_demo'     => null,
                    'cancelar_demo'    => false,
                    'enviar_mail_demo' => false,
                ];
            }

            $message = app(\App\Services\LeadAiService::class)->apply_pending_actions($message, $final_actions);

            if ($is_auto_send) {
                // El mensaje salió por WhatsApp sin que nadie lo revisara: Lucas quiere verlo en
                // la fila roja de la grilla (mismo mecanismo que el Caso A, columna del prompt 301).
                $this->mark_lead_pending_review($lead);
            }
        }

        $body = $edited_content !== null ? trim($edited_content) : trim((string) $message->content);
        if ($body === '') {
            throw new \InvalidArgumentException('El mensaje a enviar no puede estar vacío.');
        }

        $phone = trim((string) $lead->phone);

        // Si la ventana de conversación de WhatsApp está cerrada (sin mensaje entrante en 24hs),
        // no intentar send_text (Meta devuelve 422). Marcar como rechazado y salir.
        if ($phone !== '' && ! $this->is_within_whatsapp_window($lead)) {
            Log::channel('daily')->warning('LeadSuggestionSendService: ventana de 24hs cerrada, sugerencia no enviada.', [
                'lead_id'    => $lead->id,
                'message_id' => $message->id,
            ]);

            (new LeadAiSuggestionAutoSendScheduler())->cancel_for_message((int) $message->id);

            $message->update([
                'status'              => 'rechazado',
                'sent_at'             => null,
                // Motivo conocido en este call site, no viene de WhatsappSendService (prompt 336).
                'whatsapp_send_error' => 'Ventana de 24hs de WhatsApp cerrada (el lead no escribió en las últimas 24hs).',
            ]);

            $lead->sync_suggestion_flags();

            LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

            // Deja asentado en el hilo que la sugerencia no se pudo enviar (prompt 299), para
            // que quede visible tanto en aprobación manual como en auto-envío de respaldo.
            (new LeadConversationErrorLogger())->log(
                (int) $lead->id,
                'No se pudo enviar la sugerencia por WhatsApp',
                'La ventana de 24hs de WhatsApp está cerrada (el lead no escribió en las últimas 24hs).'
            );

            return $message->fresh();
        }

        // Resultado real del envío por partes (prompt 366): a diferencia del string|null anterior,
        // ahora send_body() informa cuántas partes salieron y cuántas había en total, lo que
        // habilita el caso C (envío parcial) más abajo.
        $send_result = null;
        $send_failed = false;
        // Motivo real del fallo (prompt 336): se completa recién si send_failed queda en true.
        $error_detail = null;

        if ($phone !== '') {
            $send_result = $this->send_body($phone, $body, $lead, $message);

            if ($send_result['sent_parts'] === 0) {
                $send_failed = true;
                // El motivo real quedó capturado en la instancia de WhatsappSendService al fallar send_text().
                $error_detail = $send_result['error'];
                Log::channel('daily')->warning('LeadSuggestionSendService: send_body() no envió ninguna parte.', [
                    'lead_id'    => $lead->id,
                    'message_id' => $message->id,
                ]);
            }
        } else {
            $send_failed = true;
            $error_detail = 'El lead no tiene teléfono cargado.';
            Log::channel('daily')->warning('LeadSuggestionSendService: lead sin teléfono.', [
                'lead_id'    => $lead->id,
                'message_id' => $message->id,
            ]);
        }

        /*
         * Caso A (prompt 366): no salió ninguna parte (o el lead no tiene teléfono). Comportamiento
         * idéntico al de antes de este fix: status='rechazado', sin tocar el pipeline del lead. La
         * notificación a admins ante el fallo de envío en sí ya la maneja WhatsappSendService de
         * forma centralizada (no se duplica acá).
         */
        if ($send_failed) {
            (new LeadAiSuggestionAutoSendScheduler())->cancel_for_message((int) $message->id);

            $message->update([
                'status'              => 'rechazado',
                'sent_at'             => null,
                'whatsapp_send_error' => $error_detail,
            ]);

            $lead->sync_suggestion_flags();

            LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

            // Deja asentado en el hilo que el envío no se confirmó (prompt 299), con el motivo real
            // capturado (prompt 336) o el texto genérico como fallback.
            (new LeadConversationErrorLogger())->log(
                (int) $lead->id,
                'No se pudo enviar la sugerencia por WhatsApp',
                $error_detail ?: 'El envío no se confirmó (lead sin teléfono o error de conexión con WhatsApp/Kapso).'
            );

            return $message->fresh();
        }

        // Caso C (prompt 366, lead #440): salieron algunas partes pero no todas. Ni "rechazado"
        // (el lead sí recibió contenido real) ni "enviado" a secas (faltan partes por mandar).
        $is_partial_send = $send_result['sent_parts'] < $send_result['total_parts'];

        $original_content = (string) $message->content;
        $update_payload = [
            'status'              => 'enviado',
            'sent_at'             => now(),
            'whatsapp_message_id' => $send_result['last_message_id'],
            // Admin que aprobó esta sugerencia desde el panel (null si fue auto-envío de la IA, prompt 403).
            'sent_by_admin_id'    => $sent_by_admin_id,
            // Contabilidad del envío por partes (prompt 366): null/null/null en el caso completo (B).
            'sent_parts_count'    => $send_result['sent_parts'],
            'total_parts_count'   => $send_result['total_parts'],
            'partial_send_pending' => $is_partial_send ? $send_result['pending_text'] : null,
        ];

        if ($is_partial_send) {
            // Motivo legible del corte, mismo formato que usa LeadConversationErrorLogger más abajo.
            $update_payload['whatsapp_send_error'] = sprintf(
                'Envío parcial: salieron los primeros %d de %d mensajes. El resto no se envió: %s.',
                $send_result['sent_parts'],
                $send_result['total_parts'],
                $send_result['error'] ?: 'motivo no determinado'
            );
        }

        if ($edited_content !== null && trim($edited_content) !== '' && trim($edited_content) !== $original_content) {
            $update_payload['edited_content'] = trim($edited_content);
            $this->record_setter_correction($lead, $original_content, trim($edited_content));
        }

        (new LeadAiSuggestionAutoSendScheduler())->cancel_for_message((int) $message->id);

        $message->update($update_payload);

        // El estado sugerido se aplica tanto en el envío completo (B) como en el parcial (C): en
        // ambos casos el lead recibió contenido real y avanzó la conversación. Solo el caso A (nada
        // enviado) deja el pipeline intacto.
        $this->apply_suggested_pipeline_status($lead, $message);

        if ($is_partial_send) {
            // Un envío parcial siempre necesita ojo humano: marca el lead en la fila destacada de
            // la grilla (mismo mecanismo que ya usa el respaldo automático sin revisión, prompt 337).
            $this->mark_lead_pending_review($lead);

            // Deja constancia en el hilo de qué llegó y qué no, en tono claro y no alarmista (no es
            // un bloque rojo de "falló todo": el lead sí recibió contenido).
            (new LeadConversationErrorLogger())->log(
                (int) $lead->id,
                'Envío parcial de la sugerencia',
                sprintf(
                    'El lead recibió los primeros %d de %d mensajes. Los %d en adelante no se enviaron y quedaron pendientes para mandar a mano. Motivo: %s.',
                    $send_result['sent_parts'],
                    $send_result['total_parts'],
                    $send_result['sent_parts'] + 1,
                    $send_result['error'] ?: 'motivo no determinado'
                )
            );
        }

        $lead->sync_suggestion_flags();

        LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

        return $message->fresh();
    }

    /**
     * Envía el cuerpo al número dado, partiéndolo en mensajes separados si contiene "---".
     *
     * El separador reconocido es "\n---\n" (línea con solo tres guiones).
     *
     * FIX (prompt 366, lead #440, 22/7/2026): antes este método enviaba las partes en un foreach
     * sin ninguna pausa entre una y otra, y devolvía solo el id de la última parte — si la última
     * fallaba (típicamente 409 de Kapso por "otro mensaje en vuelo"), el llamador interpretaba el
     * null como "no se envió nada" aunque las anteriores hubieran salido bien. Ahora:
     *   - se espera 1200ms entre parte y parte exitosa (le da tiempo a Kapso a liberar el
     *     bloqueo de "in-flight" de la conversación, que es la causa de raíz del 409);
     *   - cada parte se reintenta hasta 3 veces con backoff (1500ms / 3500ms) cuando el fallo es
     *     transitorio (409/429/5xx, ver WhatsappSendService::last_send_was_transient());
     *   - si una parte no sale tras los 3 intentos, se corta el envío ahí (no tiene sentido mandar
     *     la parte 5 si la 4 nunca llegó) y se devuelve el detalle exacto de qué salió y qué no,
     *     para que send_suggestion() pueda registrar un envío parcial en vez de mentir con
     *     "rechazado" o "enviado" a secas.
     *
     * @param string      $phone
     * @param string      $body
     * @param Lead        $lead    Para armar el contexto de la notificación de fallo a admins.
     * @param LeadMessage $message Para armar el contexto de la notificación de fallo a admins.
     *
     * @return array{sent_parts:int, total_parts:int, last_message_id:string|null, pending_text:string|null, error:string|null}
     */
    private function send_body(string $phone, string $body, Lead $lead, LeadMessage $message): array
    {
        // Split idéntico al comportamiento anterior: separador "\n---\n", trim y descarte de partes vacías.
        $parts = array_values(array_filter(
            array_map('trim', preg_split('/\n---\n/', $body)),
            fn($p) => $p !== ''
        ));

        $total_parts = count($parts);

        $context = 'Sugerencia de Claude - Lead #' . $lead->id
            . (! empty($lead->contact_name) ? " ({$lead->contact_name})" : '')
            . " (mensaje #{$message->id})";

        // Id de la última parte enviada con éxito hasta el momento.
        $last_message_id = null;
        // Cantidad de partes que efectivamente salieron.
        $sent_parts = 0;
        // Motivo del corte, solo se completa si alguna parte falla tras agotar los reintentos.
        $error = null;

        foreach ($parts as $index => $part) {
            $part_sent = false;

            // Hasta 3 intentos por parte. Los intermedios pasan skip_failure_notification=true
            // para que un reintento que después sale bien no dispare la notificación de fallo a
            // los admins (solo el último intento, si también falla, notifica de verdad).
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $is_last_attempt = $attempt === 3;

                $message_id = $this->whatsapp_send_service->send_text($phone, $part, $context, ! $is_last_attempt);

                if ($message_id !== null) {
                    $last_message_id = $message_id;
                    $sent_parts++;
                    $part_sent = true;
                    break;
                }

                // Fallo transitorio (409 "in-flight" de Kapso es el caso central) y todavía quedan
                // intentos: esperar con backoff creciente antes de reintentar la misma parte.
                if (! $is_last_attempt && $this->whatsapp_send_service->last_send_was_transient()) {
                    usleep($attempt === 1 ? 1500000 : 3500000);
                    continue;
                }

                // Fallo no transitorio, o ya era el último intento: no tiene sentido seguir
                // reintentando esta parte.
                break;
            }

            if (! $part_sent) {
                // Se agotaron los intentos (o el fallo no era transitorio): cortar acá. El texto
                // pendiente incluye esta parte (la que falló) más todas las que quedaron sin
                // intentar, para que el setter las pueda mandar a mano.
                $error = $this->whatsapp_send_service->last_send_error;
                $pending_text = implode("\n---\n", array_slice($parts, $index));

                return [
                    'sent_parts'       => $sent_parts,
                    'total_parts'      => $total_parts,
                    'last_message_id'  => $last_message_id,
                    'pending_text'     => $pending_text,
                    'error'            => $error,
                ];
            }

            // Pausa entre partes exitosas (NO después de la última): es la prevención de raíz del
            // 409 de Kapso ("Another message is already in-flight for this conversation"), le da
            // tiempo a liberar el bloqueo de la conversación antes de mandar la siguiente parte.
            // Sin este comentario un futuro lector podría borrar el usleep por "innecesario".
            if ($index < $total_parts - 1) {
                usleep(1200000);
            }
        }

        return [
            'sent_parts'      => $sent_parts,
            'total_parts'     => $total_parts,
            'last_message_id' => $last_message_id,
            'pending_text'    => null,
            'error'           => null,
        ];
    }

    /**
     * Aplica el cambio de estado sugerido por Claude al enviar el mensaje.
     *
     * @param Lead        $lead
     * @param LeadMessage $message
     *
     * @return void
     */
    private function apply_suggested_pipeline_status(Lead $lead, LeadMessage $message): void
    {
        $slug = trim((string) ($message->suggested_lead_status ?? ''));
        if ($slug === '') {
            return;
        }

        /*
         * FIX (prompt 118): si create_message_and_update_lead() ya aplicó el status,
         * no repetir el save() redundante al enviar el mensaje sugerido.
         */
        if ((string) $lead->status === $slug) {
            return;
        }

        LeadPipelineStatus::ensure_exists($slug);
        $lead->status = $slug;
        $lead->save();

        // Si el lead pasa a closer_activo (demo confirmada), avisar al closer por WhatsApp.
        // CloserNotificationService vive en el mismo namespace App\Services, no requiere `use`.
        if ($slug === 'closer_activo') {
            (new CloserNotificationService())->notify_for_lead($lead);
        }
    }

    /**
     * Registra corrección del setter como protocol_entry pendiente de revisión.
     *
     * @param Lead   $lead
     * @param string $original_content
     * @param string $edited_content
     *
     * @return void
     */
    private function record_setter_correction(Lead $lead, string $original_content, string $edited_content): void
    {
        ProtocolEntry::create([
            'titulo'           => 'Corrección del setter — '.now()->format('d/m/Y H:i'),
            'descripcion'      => 'Corrección automática detectada. El setter modificó '.
                'el mensaje sugerido por Claude. Revisar si aplica '.
                'como entrada general del protocolo.',
            'mensaje_template' => $edited_content,
            'categoria'        => 'situacion_frecuente',
            'estado_aplicable' => $lead->status,
            'notas_setter'     => 'Mensaje original de Claude: '.$original_content,
            'activa'           => false,
        ]);
    }

    /**
     * Indica si el lead escribió en las últimas 24 horas (ventana activa de WhatsApp).
     *
     * Fuera de este período Meta rechaza los mensajes de texto libre con 422.
     *
     * @param Lead $lead
     *
     * @return bool
     */
    private function is_within_whatsapp_window(Lead $lead): bool
    {
        return LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('sender', 'lead')
            ->where('created_at', '>=', now()->subHours(24))
            ->exists();
    }

    /**
     * Aprueba y envía un seguimiento pendiente por su plantilla Meta guardada.
     *
     * Camino separado de send_text porque los seguimientos se disparan con el lead en silencio (ventana
     * de 24hs cerrada). Al aprobar (o al vencer el timer de respaldo), se envía la plantilla con el
     * nombre del contacto como {{1}}. Marca 'enviado' con whatsapp_message_id si Kapso confirma; si
     * falla, 'rechazado' (WhatsappSendService ya notificó a los admins de forma centralizada).
     *
     * @param LeadMessage $message
     * @param Lead        $lead
     * @param int|null    $sent_by_admin_id (prompt 403) Admin que aprobó el seguimiento desde el panel;
     *                                       null cuando lo aprobó el respaldo automático.
     *
     * @return LeadMessage
     */
    private function send_followup_suggestion_via_template(LeadMessage $message, Lead $lead, ?int $sent_by_admin_id = null): LeadMessage
    {
        $template = FollowupTemplate::query()->find($message->followup_template_id);
        $phone    = trim((string) $lead->phone);

        if ($template === null || $phone === '') {
            Log::channel('daily')->warning('LeadSuggestionSendService: seguimiento sin plantilla o sin teléfono, no enviado.', [
                'lead_id'    => $lead->id,
                'message_id' => $message->id,
            ]);

            (new LeadAiSuggestionAutoSendScheduler())->cancel_for_message((int) $message->id);
            $message->update([
                'status'              => 'rechazado',
                'sent_at'             => null,
                // Motivo conocido en este call site (prompt 336): no hubo intento de envío real.
                'whatsapp_send_error' => 'Seguimiento sin plantilla o lead sin teléfono.',
            ]);
            $lead->sync_suggestion_flags();
            LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

            return $message->fresh();
        }

        $contact_name = trim((string) ($lead->contact_name ?? ''));

        $context = 'Seguimiento aprobado - Lead #' . $lead->id
            . (! empty($lead->contact_name) ? " ({$lead->contact_name})" : '');

        $whatsapp_message_id = $this->whatsapp_send_service->send_template(
            $phone,
            $template->template_name,
            [$contact_name],
            $template->language_code,
            $context
        );

        (new LeadAiSuggestionAutoSendScheduler())->cancel_for_message((int) $message->id);

        if ($whatsapp_message_id === null) {
            Log::channel('daily')->warning('LeadSuggestionSendService: seguimiento aprobado falló al enviarse por plantilla.', [
                'lead_id'    => $lead->id,
                'message_id' => $message->id,
                'template'   => $template->template_name,
            ]);

            $message->update([
                'status'              => 'rechazado',
                'sent_at'             => null,
                // Motivo real capturado por WhatsappSendService (prompt 336).
                'whatsapp_send_error' => $this->whatsapp_send_service->last_send_error,
            ]);
            $lead->sync_suggestion_flags();
            LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

            // Deja asentado en el hilo que el seguimiento (aprobado por el setter o auto-enviado)
            // falló al enviarse por su plantilla (prompt 299), con el motivo real o el texto
            // genérico como fallback.
            (new LeadConversationErrorLogger())->log(
                (int) $lead->id,
                'No se pudo enviar el seguimiento por WhatsApp',
                $this->whatsapp_send_service->last_send_error ?: 'El envío por plantilla no se confirmó (revisar conexión con WhatsApp/Kapso).'
            );

            return $message->fresh();
        }

        $message->update([
            'status'              => 'enviado',
            'sent_at'             => now(),
            'whatsapp_message_id' => $whatsapp_message_id,
            // Admin que aprobó este seguimiento desde el panel (null si fue el respaldo automático, prompt 403).
            'sent_by_admin_id'    => $sent_by_admin_id,
        ]);

        /* Un seguimiento normalmente no cambia el pipeline, pero respetamos suggested_lead_status si existe. */
        $this->apply_suggested_pipeline_status($lead, $message);
        $lead->sync_suggestion_flags();
        LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

        return $message->fresh();
    }

    /**
     * Caso A del respaldo automático (prompt 337): el paquete de acciones pendientes trae
     * agendar_demo o cancelar_demo. El texto de Claude confirma (o cancela) un horario al lead, así
     * que no hay forma segura de enviarlo sin persistir la reserva — se corta el auto-envío entero
     * y queda en manos de un humano.
     *
     * No se envía nada por WhatsApp: el mensaje queda `sugerido` con pending_actions intacto (sigue
     * aprobable desde el panel). Se limpia el countdown (ai_auto_send_at) y se cancela el token de
     * respaldo, se marca el lead para revisión, se deja constancia en el hilo, y se notifica a los
     * admins reutilizando el mismo canal que ya avisa la verificación pendiente de agendamiento.
     *
     * @param LeadMessage $message Mensaje `sugerido` con pending_actions de agendamiento.
     * @param Lead        $lead    Lead dueño del mensaje.
     *
     * @return LeadMessage Mismo mensaje, sin cambios de estado (sigue `sugerido`).
     */
    private function handle_auto_send_agendamiento_gate(LeadMessage $message, Lead $lead): LeadMessage
    {
        // Cancela el token del job (invalida cualquier reintento ya encolado) y limpia
        // ai_auto_send_at: la burbuja no debe seguir mostrando un countdown que nunca va a disparar.
        (new LeadAiSuggestionAutoSendScheduler())->cancel_for_message((int) $message->id);

        $this->mark_lead_pending_review($lead);

        // Deja constancia en el hilo del motivo por el que el respaldo no auto-envió este mensaje.
        (new LeadConversationErrorLogger())->log(
            (int) $lead->id,
            'Mensaje de agendamiento sin aprobar',
            'El respaldo automático no envía este mensaje porque agenda o cancela una demo: requiere aprobación humana desde el panel.'
        );

        // Mismo canal que ya usa la verificación pendiente de agendamiento (push + WhatsApp a admins
        // suscritos); no se inventa un canal nuevo para este aviso.
        try {
            (new LeadVerificacionAgendamientoNotificationService())->notify($lead, $message);
        } catch (\Throwable $e) {
            Log::channel('daily')->error('LeadSuggestionSendService: error al notificar respaldo retenido (Caso A, prompt 337).', [
                'lead_id'    => $lead->id,
                'message_id' => $message->id,
                'error'      => $e->getMessage(),
            ]);
        }

        LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

        Log::channel('daily')->info('LeadSuggestionSendService: auto-envío de respaldo retenido (Caso A, agendamiento sin aprobar).', [
            'lead_id'    => $lead->id,
            'message_id' => $message->id,
        ]);

        return $message->fresh();
    }

    /**
     * Marca el lead como pendiente de revisión (columna del prompt 301), sin pisar una marca
     * previa. Se usa cuando el respaldo automático actuó sin que nadie lo mirara: Caso A (no se
     * envió nada, quedó esperando aprobación) y Caso B (se envió, pero sin revisión humana).
     *
     * @param Lead $lead
     *
     * @return void
     */
    private function mark_lead_pending_review(Lead $lead): void
    {
        if ($lead->pendiente_revision_at !== null) {
            return;
        }

        $lead->pendiente_revision_at = now();
        $lead->save();
    }
}
