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
     *
     * @return LeadMessage
     */
    public function send_suggestion(LeadMessage $message, ?string $edited_content = null): LeadMessage
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
            return $this->send_followup_suggestion_via_template($message, $lead);
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
            $message = app(\App\Services\LeadAiService::class)->apply_pending_actions($message);
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
                'status'  => 'rechazado',
                'sent_at' => null,
            ]);

            $lead->sync_suggestion_flags();

            LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

            return $message->fresh();
        }

        $whatsapp_message_id = null;
        $send_failed = false;

        if ($phone !== '') {
            $whatsapp_message_id = $this->send_body($phone, $body, $lead, $message);

            if ($whatsapp_message_id === null) {
                $send_failed = true;
                Log::channel('daily')->warning('LeadSuggestionSendService: send_text() retornó null, el envío no se confirmó.', [
                    'lead_id'    => $lead->id,
                    'message_id' => $message->id,
                ]);
            }
        } else {
            $send_failed = true;
            Log::channel('daily')->warning('LeadSuggestionSendService: lead sin teléfono.', [
                'lead_id'    => $lead->id,
                'message_id' => $message->id,
            ]);
        }

        /*
         * FIX: antes de este cambio, un envío fallido (sin teléfono o send_text() devolviendo
         * null) igual quedaba marcado status='enviado', mintiendo sobre la entrega. Ahora se
         * trata igual que el caso "ventana de 24hs cerrada": status='rechazado', sin tocar el
         * pipeline del lead. La notificación a admins ante el fallo de envío en sí ya la maneja
         * WhatsappSendService de forma centralizada (no se duplica acá).
         */
        if ($send_failed) {
            (new LeadAiSuggestionAutoSendScheduler())->cancel_for_message((int) $message->id);

            $message->update([
                'status'  => 'rechazado',
                'sent_at' => null,
            ]);

            $lead->sync_suggestion_flags();

            LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

            return $message->fresh();
        }

        $original_content = (string) $message->content;
        $update_payload = [
            'status'              => 'enviado',
            'sent_at'             => now(),
            'whatsapp_message_id' => $whatsapp_message_id,
        ];

        if ($edited_content !== null && trim($edited_content) !== '' && trim($edited_content) !== $original_content) {
            $update_payload['edited_content'] = trim($edited_content);
            $this->record_setter_correction($lead, $original_content, trim($edited_content));
        }

        (new LeadAiSuggestionAutoSendScheduler())->cancel_for_message((int) $message->id);

        $message->update($update_payload);

        $this->apply_suggested_pipeline_status($lead, $message);

        $lead->sync_suggestion_flags();

        LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

        return $message->fresh();
    }

    /**
     * Envía el cuerpo al número dado, partiéndolo en mensajes separados si contiene "---".
     *
     * El separador reconocido es "\n---\n" (línea con solo tres guiones).
     * Devuelve el whatsapp_message_id del último mensaje enviado.
     *
     * @param string      $phone
     * @param string      $body
     * @param Lead        $lead    Para armar el contexto de la notificación de fallo a admins.
     * @param LeadMessage $message Para armar el contexto de la notificación de fallo a admins.
     *
     * @return string|null
     */
    private function send_body(string $phone, string $body, Lead $lead, LeadMessage $message): ?string
    {
        $parts = array_values(array_filter(
            array_map('trim', preg_split('/\n---\n/', $body)),
            fn($p) => $p !== ''
        ));

        $context = 'Sugerencia de Claude - Lead #' . $lead->id
            . (! empty($lead->contact_name) ? " ({$lead->contact_name})" : '')
            . " (mensaje #{$message->id})";

        $last_id = null;
        foreach ($parts as $part) {
            $last_id = $this->whatsapp_send_service->send_text($phone, $part, $context);
        }

        return $last_id;
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
     *
     * @return LeadMessage
     */
    private function send_followup_suggestion_via_template(LeadMessage $message, Lead $lead): LeadMessage
    {
        $template = FollowupTemplate::query()->find($message->followup_template_id);
        $phone    = trim((string) $lead->phone);

        if ($template === null || $phone === '') {
            Log::channel('daily')->warning('LeadSuggestionSendService: seguimiento sin plantilla o sin teléfono, no enviado.', [
                'lead_id'    => $lead->id,
                'message_id' => $message->id,
            ]);

            (new LeadAiSuggestionAutoSendScheduler())->cancel_for_message((int) $message->id);
            $message->update(['status' => 'rechazado', 'sent_at' => null]);
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

            $message->update(['status' => 'rechazado', 'sent_at' => null]);
            $lead->sync_suggestion_flags();
            LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

            return $message->fresh();
        }

        $message->update([
            'status'              => 'enviado',
            'sent_at'             => now(),
            'whatsapp_message_id' => $whatsapp_message_id,
        ]);

        /* Un seguimiento normalmente no cambia el pipeline, pero respetamos suggested_lead_status si existe. */
        $this->apply_suggested_pipeline_status($lead, $message);
        $lead->sync_suggestion_flags();
        LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

        return $message->fresh();
    }
}
