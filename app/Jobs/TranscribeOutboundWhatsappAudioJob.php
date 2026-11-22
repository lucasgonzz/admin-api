<?php

namespace App\Jobs;

use App\Events\SupportMessageReceived;
use App\Models\LeadMessage;
use App\Models\SupportMessage;
use App\Services\LeadBroadcastService;
use App\Services\SupportAttachmentFileResolver;
use App\Services\WhatsappOutboundAudioTranscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Transcribe un audio de WhatsApp que mandó el equipo y escribe el texto en el mensaje.
 *
 * Corre fuera del request del envío (el botón de enviar no espera a OpenAI). Es idempotente y no pisa nada:
 * si el mensaje ya tiene texto, no existe más o no corresponde, no hace nada. Nunca lanza, para que una falla
 * de transcripción no termine como failed_job ni reintente en loop.
 *
 * - Canal `lead`: `lead_messages.content` pasa de `[Audio enviado]` al texto.
 * - Canal `support`: `support_messages.body` pasa de vacío al texto (sólo tickets de WhatsApp).
 */
class TranscribeOutboundWhatsappAudioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Marca que deja el envío de un audio a un lead mientras no hay transcripción. */
    const LEAD_AUDIO_PLACEHOLDER = '[Audio enviado]';

    /** Un solo intento: la transcripción es de cortesía y no debe reintentar en loop. */
    public $tries = 1;

    /** Segundos máximos de ejecución (OpenAI tiene timeout propio de 30 s). */
    public $timeout = 90;

    /** @var string Canal del mensaje: `lead` o `support`. */
    private $channel;

    /** @var int Id del LeadMessage o del SupportMessage según el canal. */
    private $message_id;

    /**
     * @param string $channel    `lead` o `support`.
     * @param int    $message_id Id del mensaje cuyo audio hay que transcribir.
     */
    public function __construct(string $channel, int $message_id)
    {
        $this->channel    = $channel;
        $this->message_id = $message_id;
    }

    /**
     * Ejecuta la transcripción. No lanza nunca: cualquier error se loguea y el mensaje queda como estaba.
     *
     * @param WhatsappOutboundAudioTranscriptionService $service  Cliente de OpenAI.
     * @param SupportAttachmentFileResolver             $resolver Resuelve la ruta segura dentro del disco `public`.
     *
     * @return void
     */
    public function handle(WhatsappOutboundAudioTranscriptionService $service, SupportAttachmentFileResolver $resolver): void
    {
        try {
            if ($this->channel === 'lead') {
                $this->transcribe_lead_message($service, $resolver);
            } elseif ($this->channel === 'support') {
                $this->transcribe_support_message($service, $resolver);
            }
        } catch (\Throwable $e) {
            Log::warning('TranscribeOutboundWhatsappAudioJob: falló sin afectar el mensaje.', [
                'channel'    => $this->channel,
                'message_id' => $this->message_id,
                'error'      => get_class($e),
            ]);
        }
    }

    /**
     * Transcribe el audio de un mensaje de lead y reemplaza el `[Audio enviado]` por el texto.
     *
     * @param WhatsappOutboundAudioTranscriptionService $service  Cliente de OpenAI.
     * @param SupportAttachmentFileResolver             $resolver Resolvedor de rutas.
     *
     * @return void
     */
    private function transcribe_lead_message(WhatsappOutboundAudioTranscriptionService $service, SupportAttachmentFileResolver $resolver): void
    {
        // Sólo el audio del setter que todavía no tiene texto (el borrado por un envío fallido da null).
        $message = LeadMessage::query()
            ->where('id', $this->message_id)
            ->where('sender', 'setter')
            ->where('kind', 'audio')
            ->where('content', self::LEAD_AUDIO_PLACEHOLDER)
            ->first();
        if ($message === null) {
            return;
        }

        $attachment = $message->attachments()->orderBy('id')->first();
        if ($attachment === null) {
            return;
        }

        $absolute_path = $resolver->resolve((string) $attachment->path);
        if ($absolute_path === null) {
            return;
        }

        $text = $service->transcribe($absolute_path, basename((string) $attachment->path));
        if ($text === null) {
            return;
        }

        // Update condicional: si otro proceso ya escribió el texto no se pisa.
        $updated = LeadMessage::query()
            ->where('id', $message->id)
            ->where('content', self::LEAD_AUDIO_PLACEHOLDER)
            ->update(['content' => $text]);
        if ($updated < 1) {
            return;
        }

        // Aviso en tiempo real: que falle no invalida la transcripción ya guardada.
        try {
            LeadBroadcastService::emit_conversation_updated((int) $message->lead_id, (int) $message->id);
        } catch (\Throwable $e) {
            Log::warning('TranscribeOutboundWhatsappAudioJob: no se pudo emitir el aviso del lead.', ['message_id' => $message->id]);
        }
    }

    /**
     * Transcribe el audio de un mensaje de soporte (ticket de WhatsApp) y lo escribe en `body`.
     *
     * @param WhatsappOutboundAudioTranscriptionService $service  Cliente de OpenAI.
     * @param SupportAttachmentFileResolver             $resolver Resolvedor de rutas.
     *
     * @return void
     */
    private function transcribe_support_message(WhatsappOutboundAudioTranscriptionService $service, SupportAttachmentFileResolver $resolver): void
    {
        // Sólo el audio del operador, sin texto, en un ticket que vive en WhatsApp.
        $message = SupportMessage::query()
            ->where('id', $this->message_id)
            ->where('sender_type', 'admin')
            ->where('kind', 'audio')
            ->where(function ($query) {
                $query->whereNull('body')->orWhere('body', '');
            })
            ->whereHas('ticket', function ($query) {
                $query->where('source', 'whatsapp');
            })
            ->first();
        if ($message === null) {
            return;
        }

        $attachment = $message->attachments()->orderBy('id')->first();
        if ($attachment === null) {
            return;
        }

        $absolute_path = $resolver->resolve((string) $attachment->path);
        if ($absolute_path === null) {
            return;
        }

        $text = $service->transcribe($absolute_path, basename((string) $attachment->path));
        if ($text === null) {
            return;
        }

        // Update condicional: sólo si el cuerpo sigue vacío.
        $updated = SupportMessage::query()
            ->where('id', $message->id)
            ->where(function ($query) {
                $query->whereNull('body')->orWhere('body', '');
            })
            ->update(['body' => $text]);
        if ($updated < 1) {
            return;
        }

        // Aviso en tiempo real a los operadores: que falle no invalida la transcripción ya guardada.
        try {
            event(new SupportMessageReceived((int) $message->id));
        } catch (\Throwable $e) {
            Log::warning('TranscribeOutboundWhatsappAudioJob: no se pudo emitir el aviso de soporte.', ['message_id' => $message->id]);
        }
    }
}
