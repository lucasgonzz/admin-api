<?php

namespace App\Services;

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
        if ($phone !== '') {
            $whatsapp_message_id = $this->send_body($phone, $body);
        } else {
            Log::channel('daily')->warning('LeadSuggestionSendService: lead sin teléfono.', [
                'lead_id'    => $lead->id,
                'message_id' => $message->id,
            ]);
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
     * @param string $phone
     * @param string $body
     *
     * @return string|null
     */
    private function send_body(string $phone, string $body): ?string
    {
        $parts = array_values(array_filter(
            array_map('trim', preg_split('/\n---\n/', $body)),
            fn($p) => $p !== ''
        ));

        $last_id = null;
        foreach ($parts as $part) {
            $last_id = $this->whatsapp_send_service->send_text($phone, $part);
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
}
