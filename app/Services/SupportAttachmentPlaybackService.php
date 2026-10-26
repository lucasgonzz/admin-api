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
 *
 * Concurrencia (un navegador pide la misma URL muchas veces, una por cada Range):
 *  - ffmpeg escribe en un temporal DENTRO de la carpeta de destino y se publica con `rename()` (atómico):
 *    nadie puede leer una copia a medio escribir.
 *  - Un `flock` no bloqueante sobre un archivo `.lock` hace que transcodifique UN solo proceso; los demás
 *    sirven el original mientras tanto, sin esperar.
 *  - Si ffmpeg falla o vence el tope de tiempo se deja una marca `.fail` (con vencimiento por mtime) para no
 *    reintentar en cada Range.
 *  - Una copia cacheada de 0 bytes se considera rota y se regenera.
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
     * Segundos que dura la marca negativa `.fail` antes de volver a intentar la conversión.
     *
     * @var int
     */
    const FAILURE_MARK_SECONDS = 300;

    /**
     * Tope de tiempo de ffmpeg para esta conversión, en segundos.
     *
     * @var int
     */
    const FFMPEG_TIMEOUT_SECONDS = 30;

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
     * @param string                   $original_path Ruta absoluta del original (ya resuelta dentro del disco `public`).
     *
     * @return string|null Ruta absoluta de la copia, o null si no está lista (sin ffmpeg, falló, otro proceso
     *                     la está generando, sin ticket): quien llama sirve el original.
     */
    public function playable_absolute_path(SupportMessageAttachment $attachment, string $original_path): ?string
    {
        // Ticket dueño del adjunto: define la carpeta de la copia.
        $message = $attachment->message;
        if ($message === null) {
            return null;
        }

        // Disco: SIEMPRE `public`. La columna `attachments.disk` la puede escribir el cliente (metadata
        // remota) y no se le hace caso (ver SupportAttachmentFileResolver).
        $disk = Storage::disk('public');
        $adapter = $disk->getDriver()->getAdapter();
        if (! $adapter instanceof \League\Flysystem\Adapter\Local) {
            return null;
        }

        // Rutas de trabajo: carpeta de la copia y los cuatro archivos (final, lock, marca de fallo, temporal).
        $directory = rtrim($adapter->getPathPrefix(), '/\\') . DIRECTORY_SEPARATOR
            . 'support_messages' . DIRECTORY_SEPARATOR . (int) $message->support_ticket_id
            . DIRECTORY_SEPARATOR . 'playback';
        $final_path = $directory . DIRECTORY_SEPARATOR . (int) $attachment->id . '.m4a';
        $lock_path = $final_path . '.lock';
        $failure_path = $final_path . '.fail';

        // Los is_file()/filemtime() no pueden mirar una caché de stat vieja (un proceso largo, o la copia que otro
        // proceso acaba de publicar o borrar).
        clearstatcache();

        // 1. Copia ya generada: se reutiliza sin volver a correr ffmpeg. Una de 0 bytes está rota: se descarta.
        if (is_file($final_path)) {
            if (filesize($final_path) > 0) {
                return $final_path;
            }
            @unlink($final_path);
        }

        // 2. Marca negativa vigente: ffmpeg falló hace poco, no se reintenta en cada Range.
        if (is_file($failure_path)) {
            if ((time() - (int) filemtime($failure_path)) < self::FAILURE_MARK_SECONDS) {
                return null;
            }
            @unlink($failure_path);
        }

        // 3. Sin ffmpeg no hay copia: el llamador sirve el original.
        $locator = app(FfmpegLocator::class);
        $ffmpeg_path = $locator->path();
        if ($ffmpeg_path === null) {
            return null;
        }

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return null;
        }

        // 4. Lock no bloqueante: si otro proceso ya está transcodificando, este sirve el original sin esperar.
        $lock_handle = @fopen($lock_path, 'c');
        if ($lock_handle === false) {
            return null;
        }
        if (! flock($lock_handle, LOCK_EX | LOCK_NB)) {
            fclose($lock_handle);

            return null;
        }

        // Temporal en la MISMA carpeta (mismo filesystem, para que rename() sea atómico); la extensión .m4a la
        // necesita ffmpeg para elegir el contenedor.
        $temp_path = $directory . DIRECTORY_SEPARATOR . (int) $attachment->id . '.' . uniqid('', true) . '.part.m4a';

        try {
            // Otro proceso pudo terminar mientras esperábamos el lock.
            if (is_file($final_path) && filesize($final_path) > 0) {
                return $final_path;
            }

            $error = $locator->convert_audio(
                $ffmpeg_path,
                $original_path,
                $temp_path,
                '-c:a aac -b:a 64k -movflags +faststart',
                self::FFMPEG_TIMEOUT_SECONDS
            );

            if ($error !== null) {
                // Marca negativa: no se reintenta hasta que venza.
                @touch($failure_path);

                return null;
            }

            // Publicación atómica: o está la copia entera o no está.
            if (! @rename($temp_path, $final_path)) {
                @touch($failure_path);

                return null;
            }

            return $final_path;
        } catch (\Throwable $exception) {
            Log::channel('daily')->warning('SupportAttachmentPlaybackService: no se pudo generar la copia reproducible.', [
                'attachment_id' => $attachment->id,
                'error'         => $exception->getMessage(),
            ]);
            @touch($failure_path);

            return null;
        } finally {
            if (is_file($temp_path)) {
                @unlink($temp_path);
            }
            flock($lock_handle, LOCK_UN);
            fclose($lock_handle);
            @unlink($lock_path);
        }
    }
}
