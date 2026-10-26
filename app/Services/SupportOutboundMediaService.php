<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

/**
 * Prepara el archivo que el operador adjunta a un mensaje de soporte ANTES de crear el mensaje.
 *
 * Mira los bytes (no la extensión ni el mime que declara el navegador) para decidir tres cosas:
 *  - el `kind` real del mensaje (audio / image / el que mandó el SPA);
 *  - el mime normalizado y la extensión coherente con lo que el archivo ES;
 *  - si en un ticket de WhatsApp el archivo se puede mandar (422 temprano, en español, antes de crear
 *    nada) o no.
 *
 * En un ticket del ERP (`source = erp`) NO se rechaza nada nuevo: solo se guarda con extensión y mime
 * coherentes. No convierte audio: la conversión (ffmpeg) va en el envío, ver
 * {@see WhatsappSendService::send_audio_attachment()}.
 */
class SupportOutboundMediaService
{
    /**
     * Tope de audio que acepta la Cloud API de WhatsApp (16 MB).
     *
     * @var int
     */
    const MAX_AUDIO_BYTES = 16 * 1024 * 1024;

    /**
     * Tope de imagen que acepta la Cloud API de WhatsApp (5 MB).
     *
     * @var int
     */
    const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    /**
     * Analiza el archivo subido y devuelve cómo guardarlo (o por qué se rechaza).
     *
     * @param UploadedFile $file           Archivo del campo `attachment`.
     * @param string       $requested_kind `kind` que mandó el SPA (no se confía en él para audio/imagen).
     * @param bool         $is_whatsapp    true si el ticket sale por WhatsApp (ahí sí se valida).
     *
     * @return array{error: string|null, kind: string, mime: string, extension: string, container: string|null}
     *         `error` trae el mensaje para el operador cuando hay que rechazar (422); null si se puede guardar.
     */
    public function prepare(UploadedFile $file, string $requested_kind, bool $is_whatsapp): array
    {
        // Servicio que normaliza mimes y conoce la tabla mime → extensión.
        $media_service = new WhatsappInboundMediaService();

        // Tamaño del archivo en bytes.
        $size = (int) $file->getSize();

        // Contenedor de audio por los bytes mágicos de la cabecera.
        $container = AudioContainerSniffer::sniff_file((string) $file->getRealPath());

        // Mime que detecta PHP por contenido y mime que declara el cliente (iOS manda octet-stream a veces).
        $detected_mime = $media_service->normalize_mime((string) $file->getMimeType());
        $client_mime = $media_service->normalize_mime((string) $file->getClientMimeType());
        $real_mime = $detected_mime;
        if ($real_mime === '' || $real_mime === 'application/octet-stream') {
            $real_mime = $client_mime;
        }

        // ¿El operador o el navegador dicen que es audio? Sirve para desambiguar MP4/WebM, que también pueden ser video.
        $audio_hint = $requested_kind === 'audio'
            || strpos($detected_mime, 'audio/') === 0
            || strpos($client_mime, 'audio/') === 0;

        // 1. Audio: contenedor inequívoco de audio, o MP4/WebM con la pista de que es audio.
        $is_audio = AudioContainerSniffer::is_unambiguous_audio($container)
            || ($container !== null && $audio_hint);
        if ($is_audio) {
            return $this->prepare_audio($container, $size, $requested_kind, $is_whatsapp);
        }

        // 2. Imagen (el contenido es de una imagen, no un SVG).
        if (strpos($real_mime, 'image/') === 0 && strpos($real_mime, 'svg') === false) {
            return $this->prepare_image($real_mime, $size, $requested_kind, $is_whatsapp, $media_service);
        }

        // 3. Dijeron que era audio pero los bytes no son un audio que se reconozca.
        if ($requested_kind === 'audio' && $is_whatsapp) {
            return $this->result(
                'No se pudo reconocer el formato del audio. Grabalo de nuevo o adjuntá un MP3, M4A u OGG.',
                $requested_kind,
                $real_mime,
                $this->extension_for_generic($real_mime, $file, $media_service),
                null
            );
        }

        // 4. Cualquier otro archivo (documento, video…): se guarda con mime y extensión coherentes, sin rechazar.
        return $this->result(
            null,
            $requested_kind,
            $real_mime !== '' ? $real_mime : 'application/octet-stream',
            $this->extension_for_generic($real_mime, $file, $media_service),
            $container
        );
    }

    /**
     * Resuelve un adjunto de audio.
     *
     * @param string|null $container Contenedor detectado por los bytes.
     * @param int         $size      Tamaño en bytes.
     * @param string      $requested_kind `kind` pedido por el SPA (se respeta tal cual en tickets del ERP).
     * @param bool        $is_whatsapp true para aplicar las reglas del canal WhatsApp.
     *
     * @return array{error: string|null, kind: string, mime: string, extension: string, container: string|null}
     */
    private function prepare_audio(?string $container, int $size, string $requested_kind, bool $is_whatsapp): array
    {
        // Mime y extensión salen del contenedor real, no de lo que declaró el navegador.
        $mime = AudioContainerSniffer::mime_for((string) $container);
        $extension = AudioContainerSniffer::extension_for((string) $container);
        $error = null;

        if ($is_whatsapp) {
            if ($size > self::MAX_AUDIO_BYTES) {
                $error = 'El audio supera el tamaño máximo que acepta WhatsApp (16 MB).';
            } elseif (AudioContainerSniffer::requires_conversion($container) && app(FfmpegLocator::class)->path() === null) {
                // WhatsApp no acepta este contenedor y no hay con qué convertirlo: es mejor decirlo ahora
                // que dejar un mensaje "enviado" que Meta descarta al procesarlo.
                $error = 'El audio está en formato ' . AudioContainerSniffer::label_for((string) $container)
                    . ', que WhatsApp no acepta, y este servidor no tiene ffmpeg para convertirlo. '
                    . 'Grabalo de nuevo o adjuntá un audio OGG/Opus, MP3 o M4A.';
            }
        }

        // En WhatsApp el kind real es audio aunque el SPA haya mandado otra cosa; en el ERP se respeta el pedido.
        $kind = $is_whatsapp ? 'audio' : $requested_kind;

        return $this->result($error, $kind, $mime, $extension, $container);
    }

    /**
     * Resuelve un adjunto de imagen.
     *
     * @param string                       $real_mime      Mime real de la imagen.
     * @param int                          $size           Tamaño en bytes.
     * @param string                       $requested_kind `kind` pedido por el SPA.
     * @param bool                         $is_whatsapp    true para aplicar las reglas del canal WhatsApp.
     * @param WhatsappInboundMediaService  $media_service  Tabla mime → extensión.
     *
     * @return array{error: string|null, kind: string, mime: string, extension: string, container: string|null}
     */
    private function prepare_image(string $real_mime, int $size, string $requested_kind, bool $is_whatsapp, WhatsappInboundMediaService $media_service): array
    {
        // `image/jpg` no existe: el mime correcto es image/jpeg.
        $mime = $real_mime === 'image/jpg' ? 'image/jpeg' : $real_mime;
        $extension = $media_service->extension_from_mime($mime);
        $error = null;

        if ($is_whatsapp) {
            if (! in_array($mime, ['image/jpeg', 'image/png'], true)) {
                $error = 'WhatsApp solo acepta imágenes JPG o PNG (este archivo es ' . $mime . ').';
            } elseif ($size > self::MAX_IMAGE_BYTES) {
                $error = 'La imagen supera el tamaño máximo que acepta WhatsApp (5 MB).';
            }
        }

        // En WhatsApp el kind real es image aunque el SPA haya mandado otra cosa; en el ERP se respeta el pedido.
        $kind = $is_whatsapp ? 'image' : $requested_kind;

        return $this->result($error, $kind, $mime, $extension !== null ? $extension : 'jpg', null);
    }

    /**
     * Extensión para un archivo que no es audio ni imagen reconocidos.
     *
     * Sale del mime (tabla conocida) o de la que adivina Laravel por contenido; NUNCA de la extensión que
     * puso el cliente en el nombre, porque el archivo se guarda en el disco público.
     *
     * @param string                       $mime          Mime real.
     * @param UploadedFile                 $file          Archivo subido.
     * @param WhatsappInboundMediaService  $media_service Tabla mime → extensión.
     *
     * @return string
     */
    private function extension_for_generic(string $mime, UploadedFile $file, WhatsappInboundMediaService $media_service): string
    {
        $from_table = $media_service->extension_from_mime($mime);
        if ($from_table !== null) {
            return $from_table;
        }

        // Extensión que adivina Laravel por contenido, solo si está en la lista permitida.
        $guessed = strtolower((string) $file->guessExtension());
        $is_safe = in_array($guessed, WhatsappInboundMediaService::SAFE_EXTENSIONS, true);

        return $is_safe ? $guessed : 'bin';
    }

    /**
     * Arma el arreglo de resultado.
     *
     * @param string|null $error     Mensaje de rechazo o null.
     * @param string      $kind      `kind` final del mensaje.
     * @param string      $mime      Mime normalizado a guardar.
     * @param string      $extension Extensión a guardar.
     * @param string|null $container Contenedor de audio detectado (si hay).
     *
     * @return array{error: string|null, kind: string, mime: string, extension: string, container: string|null}
     */
    private function result(?string $error, string $kind, string $mime, string $extension, ?string $container): array
    {
        return [
            'error'     => $error,
            'kind'      => $kind,
            'mime'      => $mime,
            'extension' => $extension,
            'container' => $container,
        ];
    }
}
