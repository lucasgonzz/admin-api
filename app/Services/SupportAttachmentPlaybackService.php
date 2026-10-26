<?php

namespace App\Services;

use App\Models\SupportMessageAttachment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Genera, una sola vez, una copia reproducible en Safari/iOS de un audio de soporte.
 *
 * Las notas de voz de WhatsApp llegan como Ogg/Opus, y Safari (sobre todo en iPhone) no reproduce Ogg ni
 * WebM ni AMR en un `<audio>`. Si hay ffmpeg se transcodifica a AAC `.m4a` y se cachea al lado del
 * original (`support_messages/<ticket>/playback/<id>.m4a`); si no hay ffmpeg o falla, no se hace nada y
 * quien llama sirve el original: nunca es motivo de error 500.
 */
class SupportAttachmentPlaybackService
{
    /**
     * Mimes de audio que Safari no reproduce.
     *
     * @var array<int, string>
     */
    const NOT_PLAYABLE_MIMES = ['audio/ogg', 'audio/opus', 'audio/webm', 'audio/amr', 'audio/x-opus+ogg'];

    /**
     * Extensiones de audio que Safari no reproduce.
     *
     * @var array<int, string>
     */
    const NOT_PLAYABLE_EXTENSIONS = ['ogg', 'oga', 'opus', 'webm', 'amr'];

    /**
     * Indica si un adjunto es un audio cuyo contenedor no reproduce Safari.
     *
     * @param string $mime Mime ya normalizado (sin parámetros).
     * @param string $path Ruta relativa del archivo guardado (para mirar la extensión).
     *
     * @return bool
     */
    public function needs_transcoding(string $mime, string $path): bool
    {
        // Extensión del archivo guardado, en minúsculas.
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // Un mime de audio que Safari no reproduce.
        if (in_array($mime, self::NOT_PLAYABLE_MIMES, true)) {
            return true;
        }

        // Mime de audio genérico o ausente (filas viejas) pero con extensión de contenedor no reproducible.
        $is_audio_or_unknown = $mime === '' || strpos($mime, 'audio/') === 0;

        return $is_audio_or_unknown && in_array($extension, self::NOT_PLAYABLE_EXTENSIONS, true);
    }

    /**
     * Ruta absoluta de la copia `.m4a` reproducible, generándola si todavía no existe.
     *
     * @param SupportMessageAttachment $attachment    Adjunto de audio.
     * @param string                   $original_path Ruta absoluta del original (disco local).
     *
     * @return string|null Ruta absoluta de la copia, o null si no se pudo (sin ffmpeg, falló, sin ticket).
     */
    public function playable_absolute_path(SupportMessageAttachment $attachment, string $original_path): ?string
    {
        // Disco del adjunto (el mismo para el original y la copia).
        $disk_name = trim((string) ($attachment->disk ?? 'public'));
        if ($disk_name === '') {
            $disk_name = 'public';
        }

        // Ticket dueño del adjunto: define la carpeta de la copia.
        $message = $attachment->message;
        if ($message === null) {
            return null;
        }

        // Ruta relativa de la copia cacheada.
        $playback_relative = 'support_messages/' . (int) $message->support_ticket_id . '/playback/' . (int) $attachment->id . '.m4a';
        $disk = Storage::disk($disk_name);

        // Si ya se generó antes, se reutiliza sin volver a correr ffmpeg.
        if ($disk->exists($playback_relative) && $disk->size($playback_relative) > 0) {
            return $disk->path($playback_relative);
        }

        // Sin ffmpeg no hay copia: el llamador sirve el original.
        $locator = app(FfmpegLocator::class);
        $ffmpeg_path = $locator->path();
        if ($ffmpeg_path === null) {
            return null;
        }

        // Archivo temporal donde ffmpeg escribe; se borra siempre al salir.
        $temp_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sup_play_' . uniqid('', true) . '.m4a';

        try {
            $error = $locator->convert_audio(
                $ffmpeg_path,
                $original_path,
                $temp_path,
                '-c:a aac -b:a 64k -movflags +faststart'
            );

            if ($error !== null) {
                return null;
            }

            $disk->put($playback_relative, (string) file_get_contents($temp_path));

            return $disk->path($playback_relative);
        } catch (\Throwable $exception) {
            Log::channel('daily')->warning('SupportAttachmentPlaybackService: no se pudo generar la copia reproducible.', [
                'attachment_id' => $attachment->id,
                'error'         => $exception->getMessage(),
            ]);

            return null;
        } finally {
            if (is_file($temp_path)) {
                @unlink($temp_path);
            }
        }
    }
}
