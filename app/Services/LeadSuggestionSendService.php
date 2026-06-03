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
        $whatsapp_message_id = null;
        if ($phone !== '') {
            $whatsapp_message_id = $this->whatsapp_send_service->send_text($phone, $body);
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

        LeadPipelineStatus::ensure_exists($slug);
        $lead->status = $slug;
        $lead->save();
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
}
