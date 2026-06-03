<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadMessageAttachment;
use Illuminate\Support\Facades\Log;

/**
 * Procesa mensajes de voz/audio entrantes de leads por WhatsApp (transcripción + archivo local).
 */
class LeadWhatsappInboundAudioService
{
    /**
     * @var WhatsappInboundMediaService
     */
    private $media_service;

    /**
     * @param WhatsappInboundMediaService|null $media_service
     */
    public function __construct(?WhatsappInboundMediaService $media_service = null)
    {
        $this->media_service = $media_service ?? new WhatsappInboundMediaService();
    }

    /**
     * Registra en log, descarga el audio y crea lead_message_attachments.
     *
     * @param Lead        $lead
     * @param LeadMessage $message         Mensaje ya persistido (content = transcripción).
     * @param array<string, mixed> $parsed Resultado de parse_inbound_message.
     * @param array<string, mixed> $payload Body completo del webhook Kapso.
     *
     * @return bool true si el archivo quedó guardado en storage.
     */
    public function process_inbound(Lead $lead, LeadMessage $message, array $parsed, array $payload): bool
    {
        $transcript = trim((string) $message->content);
        $has_transcript = $transcript !== '' && $transcript !== '[Audio sin transcripción]';

        Log::channel('daily')->info('Lead WhatsApp: AUDIO entrante del lead (inicio de procesamiento).', [
            'event'               => 'lead_inbound_audio',
            'lead_id'             => $lead->id,
            'lead_message_id'     => $message->id,
            'whatsapp_message_id' => $message->whatsapp_message_id,
            'phone'               => $lead->phone,
            'has_transcript'      => $has_transcript,
            'transcript_preview'  => mb_substr($transcript, 0, 200),
        ]);

        $message_node = $payload['message'] ?? null;
        if (! is_array($message_node)) {
            $message_node = [];
        }

        $media = $this->media_service->resolve_lead_inbound_media($message_node, $parsed);
        if ($media === null) {
            Log::channel('daily')->warning('Lead WhatsApp: AUDIO del lead sin metadata descargable (solo transcripción en BD).', [
                'event'           => 'lead_inbound_audio_no_media',
                'lead_id'         => $lead->id,
                'lead_message_id' => $message->id,
                'parsed_type'     => $parsed['type'] ?? null,
                'kapso_content'   => isset($parsed['kapso_content']) ? mb_substr((string) $parsed['kapso_content'], 0, 120) : null,
            ]);

            return false;
        }

        Log::channel('daily')->info('Lead WhatsApp: AUDIO del lead — metadata de descarga resuelta.', [
            'event'             => 'lead_inbound_audio_media_resolved',
            'lead_message_id'   => $message->id,
            'has_url'           => ! empty($media['url']),
            'whatsapp_media_id' => $media['whatsapp_media_id'] ?? null,
            'mime'              => $media['mime'] ?? null,
        ]);

        try {
            $stored = $this->media_service->persist_lead_attachment($message, (int) $lead->id, $media);
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('Lead WhatsApp: AUDIO del lead — excepción al guardar archivo.', [
                'lead_id'         => $lead->id,
                'lead_message_id' => $message->id,
                'error'           => $exception->getMessage(),
            ]);

            return false;
        }

        if (! $stored) {
            Log::channel('daily')->error('Lead WhatsApp: AUDIO del lead — descarga fallida (revisar Kapso API key / storage).', [
                'event'             => 'lead_inbound_audio_download_failed',
                'lead_message_id'   => $message->id,
                'url'               => $media['url'] ?? null,
                'whatsapp_media_id' => $media['whatsapp_media_id'] ?? null,
            ]);

            return false;
        }

        $attachment = LeadMessageAttachment::query()
            ->where('lead_message_id', $message->id)
            ->orderBy('id', 'desc')
            ->first();

        Log::channel('daily')->info('Lead WhatsApp: AUDIO del lead guardado correctamente (transcripción + archivo).', [
            'event'           => 'lead_inbound_audio_stored',
            'lead_id'         => $lead->id,
            'lead_message_id' => $message->id,
            'storage_path'    => $attachment ? $attachment->path : null,
            'mime'            => $attachment ? $attachment->mime : null,
            'size_bytes'      => $attachment ? $attachment->size : null,
            'has_transcript'  => $has_transcript,
        ]);

        return true;
    }
}
