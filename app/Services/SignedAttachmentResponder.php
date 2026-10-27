<?php

namespace App\Services;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Arma la respuesta HTTP con la que se sirve el archivo de un adjunto por URL firmada (soporte y leads).
 *
 * Es la lógica que vivía dentro de `SupportMessageController::serve_attachment_file` movida tal cual a un
 * servicio, para que los adjuntos de soporte y los de leads se sirvan con la MISMA política y no haya dos
 * copias que se separen solas:
 *  - Responde con BinaryFileResponse (`response()->file()`), que soporta `Range` y contesta 206: iOS Safari
 *    exige rangos para reproducir audio y video. NO se usa `Storage::response()` porque en Laravel 8
 *    devuelve un StreamedResponse sin rangos.
 *  - El Content-Type es el mime guardado SIN parámetros (`audio/ogg; codecs=opus` → `audio/ogg`) y validado:
 *    si no tiene forma de mime se sirve como `application/octet-stream` y se fuerza la descarga.
 *  - Inline solo para image/* (menos svg), audio/*, video/* y application/pdf. Todo lo demás (html, svg,
 *    js…) se fuerza como descarga: el archivo lo manda un tercero y, servido inline, correría desde el
 *    origen del admin.
 *  - `?disposition=attachment` (viaja dentro de la firma) fuerza la descarga.
 *  - Siempre `nosniff` y una CSP que anula cualquier script/recurso si el archivo llegara a renderizarse.
 *
 * La ruta absoluta la resuelve antes {@see SupportAttachmentFileResolver} (disco `public` + contención).
 */
class SignedAttachmentResponder
{
    /**
     * Resuelve el mime definitivo con el que se va a servir un archivo y si ese mime obliga a descargarlo.
     *
     * Normaliza el mime guardado (sin parámetros, en minúsculas); si no hay, lo deduce de los bytes; y si
     * tampoco, octet-stream. Un mime que no tiene forma de mime nunca se refleja en el Content-Type.
     *
     * @param string $absolute_path Ruta absoluta del archivo ya resuelta (para deducir el mime de los bytes).
     * @param string $stored_mime   Mime guardado en la fila del adjunto (puede venir vacío o con parámetros).
     *
     * @return array{0: string, 1: bool} [mime definitivo, true si el mime obliga a forzar la descarga].
     */
    public function resolve_mime(string $absolute_path, string $stored_mime): array
    {
        // Servicio que normaliza el mime (quita parámetros y pasa a minúsculas).
        $media_service = app(WhatsappInboundMediaService::class);

        // Mime guardado, sin parámetros y en minúsculas.
        $mime = $media_service->normalize_mime($stored_mime);

        // Sin mime guardado: se lo deduce de los bytes; y si tampoco, octet-stream.
        if ($mime === '') {
            $detected_mime = @mime_content_type($absolute_path);
            $mime = $detected_mime !== false ? $media_service->normalize_mime((string) $detected_mime) : '';
        }

        // El mime viene de la base (y a veces del cliente): si no tiene forma de mime se lo trata como binario.
        // Un mime inválido nunca se refleja en el Content-Type y fuerza la descarga.
        $force_download = false;
        if ($mime === '') {
            $mime = 'application/octet-stream';
        } elseif (! preg_match('#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#', $mime)) {
            $mime = 'application/octet-stream';
            $force_download = true;
        }

        return [$mime, $force_download];
    }

    /**
     * Arma la BinaryFileResponse del archivo con la política de disposición y cabeceras de seguridad.
     *
     * @param Request $request        Petición (se lee `?disposition=attachment` de la query firmada).
     * @param string  $absolute_path  Ruta absoluta del archivo que se envía.
     * @param string  $mime           Mime definitivo (el de {@see resolve_mime}, o el que cambió el llamador).
     * @param bool    $force_download true si el mime obliga a descargar (mime inválido).
     * @param string  $download_name  Nombre con el que se baja o se muestra el archivo.
     *
     * @return BinaryFileResponse
     */
    public function build_response(Request $request, string $absolute_path, string $mime, bool $force_download, string $download_name): BinaryFileResponse
    {
        // Inline solo para tipos que el navegador muestra sin ejecutar nada propio del archivo.
        $is_inline_allowed = $this->is_inline_safe_mime($mime);
        if (strtolower(trim((string) $request->query('disposition', ''))) === 'attachment') {
            $force_download = true;
        }
        $disposition = ($is_inline_allowed && ! $force_download) ? 'inline' : 'attachment';

        // Cabeceras: nosniff y una CSP que anula cualquier script/recurso si el archivo llegara a renderizarse.
        $headers = [
            'Content-Type'            => $mime,
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
        ];

        // Nombre alternativo solo ASCII para la cabecera Content-Disposition.
        $fallback_name = preg_replace('/[^\x20-\x7e]|[%\/\\\\]/', '_', $download_name);

        // BinaryFileResponse: Accept-Ranges y 206 los resuelve Symfony al preparar la respuesta.
        $response = response()->file($absolute_path, $headers);
        $response->setContentDisposition($disposition, $download_name, $fallback_name);

        // BinaryFileResponse arranca en `public`: se lo pasa a `private` (el archivo es de un cliente) con la
        // misma ventana que la firma de la URL, para que el navegador no vuelva a pedirlo.
        $response->setPrivate();
        $response->setMaxAge(172800);

        return $response;
    }

    /**
     * Camino corto para quien no necesita tocar el mime en el medio (leads): resuelve el mime y arma la respuesta.
     *
     * @param Request     $request       Petición (se lee `?disposition=attachment`).
     * @param string      $absolute_path Ruta absoluta del archivo que se envía.
     * @param string|null $stored_mime   Mime guardado en la fila del adjunto (null o vacío si no hay).
     * @param string      $download_name Nombre con el que se baja o se muestra el archivo.
     *
     * @return BinaryFileResponse
     */
    public function respond(Request $request, string $absolute_path, ?string $stored_mime, string $download_name): BinaryFileResponse
    {
        list($mime, $force_download) = $this->resolve_mime($absolute_path, (string) ($stored_mime ?? ''));

        return $this->build_response($request, $absolute_path, $mime, $force_download, $download_name);
    }

    /**
     * Indica si un mime se puede mostrar inline sin riesgo: imágenes (sin svg), audio, video y PDF.
     *
     * @param string $mime Mime normalizado (sin parámetros).
     *
     * @return bool
     */
    private function is_inline_safe_mime(string $mime): bool
    {
        // El SVG es un documento con scripts: nunca inline.
        if (strpos($mime, 'svg') !== false) {
            return false;
        }

        if (strpos($mime, 'image/') === 0 || strpos($mime, 'audio/') === 0 || strpos($mime, 'video/') === 0) {
            return true;
        }

        return $mime === 'application/pdf';
    }
}
