<?php

namespace App\Services;

use App\Models\LeadMessage;
use App\Models\LeadMessageAttachment;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\WhatsappConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Descarga media entrante de Kapso y la persiste como adjunto de soporte.
 */
class WhatsappInboundMediaService
{
    /**
     * Extrae URL y metadata de un mensaje multimedia del webhook Kapso.
     *
     * @param array<string, mixed> $message Nodo message del payload.
     * @param string               $type    Tipo WhatsApp (image, document, …).
     *
     * @return array{url: string|null, mime: string|null, filename: string|null, whatsapp_media_id: string|null}|null
     */
    public function extract_inbound_media(array $message, string $type): ?array
    {
        $media_types = ['image', 'video', 'document', 'audio', 'sticker', 'ptt', 'voice'];
        if (! in_array($type, $media_types, true)) {
            return null;
        }

        // Notas de voz: Kapso/Meta pueden usar type ptt con nodo audio o ptt.
        $payload_key = $this->resolve_media_payload_key($message, $type);

        $kapso = isset($message['kapso']) && is_array($message['kapso']) ? $message['kapso'] : [];
        $url = $this->resolve_media_url($message, $payload_key, $kapso);

        $whatsapp_media_id = null;
        if (isset($message[$payload_key]['id'])) {
            $whatsapp_media_id = trim((string) $message[$payload_key]['id']);
            if ($whatsapp_media_id === '') {
                $whatsapp_media_id = null;
            }
        }

        if (($url === null || $url === '') && $whatsapp_media_id === null) {
            return null;
        }

        $mime = null;
        $filename = null;

        if (isset($message[$payload_key]['mime_type'])) {
            $mime = trim((string) $message[$payload_key]['mime_type']);
        }

        if (isset($kapso['content'])) {
            $parsed_meta = $this->parse_kapso_content_metadata((string) $kapso['content']);
            if ($mime === null || $mime === '') {
                $mime = $parsed_meta['mime'];
            }
            if ($filename === null || $filename === '') {
                $filename = $parsed_meta['filename'];
            }
        }

        if ($filename === null || $filename === '') {
            if ($url !== null && $url !== '') {
                $filename = $this->guess_filename_from_url($url, $type);
            }
        }

        return [
            'url'               => $url !== null && $url !== '' ? $url : null,
            'mime'              => $mime,
            'filename'          => $filename,
            'whatsapp_media_id' => $whatsapp_media_id,
        ];
    }

    /**
     * Resuelve metadata de descarga para audio de lead (varios fallbacks Kapso/Meta).
     *
     * @param array<string, mixed> $message Nodo message del webhook.
     * @param array<string, mixed> $parsed  Salida de parse_inbound_message.
     *
     * @return array{url: string|null, mime: string|null, filename: string|null, whatsapp_media_id: string|null}|null
     */
    public function resolve_lead_inbound_media(array $message, array $parsed): ?array
    {
        $media = null;

        if (! empty($parsed['inbound_media']) && is_array($parsed['inbound_media'])) {
            $media = $parsed['inbound_media'];
        }

        if ($media === null) {
            $try_types = ['audio', 'ptt', 'voice'];
            $i = 0;
            for ($i = 0; $i < count($try_types); $i = $i + 1) {
                $media = $this->extract_inbound_media($message, $try_types[$i]);
                if ($media !== null) {
                    break;
                }
            }
        }

        if ($media === null && ! empty($parsed['kapso_content'])) {
            $media = $this->extract_inbound_media_from_kapso_content((string) $parsed['kapso_content']);
        }

        if ($media !== null) {
            return $media;
        }

        $kapso = isset($message['kapso']) && is_array($message['kapso']) ? $message['kapso'] : [];
        $url = null;
        if (isset($kapso['media_url']) && is_string($kapso['media_url'])) {
            $url = trim($kapso['media_url']);
        } elseif (isset($kapso['mediaUrl']) && is_string($kapso['mediaUrl'])) {
            $url = trim($kapso['mediaUrl']);
        }

        if ($url === null || $url === '') {
            return null;
        }

        $mime = null;
        $whatsapp_media_id = null;
        $payload_key = $this->resolve_media_payload_key($message, 'audio');
        if (isset($message[$payload_key]['mime_type'])) {
            $mime = trim((string) $message[$payload_key]['mime_type']);
        }
        if (isset($message[$payload_key]['id'])) {
            $whatsapp_media_id = trim((string) $message[$payload_key]['id']);
            if ($whatsapp_media_id === '') {
                $whatsapp_media_id = null;
            }
        }

        return [
            'url'               => $url,
            'mime'              => $mime !== '' ? $mime : null,
            'filename'          => null,
            'whatsapp_media_id' => $whatsapp_media_id,
        ];
    }

    /**
     * Texto visible en el chat cuando no se pudo guardar la miniatura local.
     *
     * @param string|null $kapso_content Contenido legado de Kapso (incluye URL).
     *
     * @return string
     */
    public function build_image_fallback_body(?string $kapso_content): string
    {
        if ($kapso_content !== null && trim($kapso_content) !== '') {
            return trim($kapso_content);
        }

        return '[Imagen recibida por WhatsApp]';
    }

    /**
     * Reconstruye metadata de media solo desde kapso.content (fallback del webhook).
     *
     * @param string $kapso_content
     *
     * @return array{url: string|null, mime: string|null, filename: string|null, whatsapp_media_id: string|null}|null
     */
    public function extract_inbound_media_from_kapso_content(string $kapso_content): ?array
    {
        $parsed_meta = $this->parse_kapso_content_metadata($kapso_content);
        if ($parsed_meta['url'] === null || $parsed_meta['url'] === '') {
            return null;
        }

        return [
            'url'               => $parsed_meta['url'],
            'mime'              => $parsed_meta['mime'],
            'filename'          => $parsed_meta['filename'],
            'whatsapp_media_id' => null,
        ];
    }

    /**
     * Descarga el archivo remoto y crea SupportMessageAttachment en disco public.
     *
     * @param SupportMessage                                      $message
     * @param int                                                 $ticket_id
     * @param array{url: string, mime: string|null, filename: string|null} $media
     *
     * @return bool true si se guardó el adjunto.
     */
    public function persist_support_attachment(SupportMessage $message, int $ticket_id, array $media): bool
    {
        $config = WhatsappConfig::getActive();
        if (! $config || ! $config->is_active) {
            return false;
        }

        $api_key = trim((string) $config->kapso_api_key);
        $phone_number_id = trim((string) $config->phone_number_id);
        $binary = null;

        if (! empty($media['url'])) {
            $binary = $this->download_media_binary((string) $media['url'], $api_key);
        }

        if (($binary === null || $binary === '') && ! empty($media['whatsapp_media_id']) && $phone_number_id !== '') {
            $binary = $this->download_media_binary_by_whatsapp_id(
                (string) $media['whatsapp_media_id'],
                $phone_number_id,
                $api_key
            );
        }

        if ($binary === null || $binary === '') {
            Log::channel('daily')->warning('WhatsappInboundMediaService: no se pudo descargar media.', [
                'message_id'        => $message->id,
                'url'               => $media['url'] ?? null,
                'whatsapp_media_id' => $media['whatsapp_media_id'] ?? null,
            ]);

            return false;
        }

        $mime = isset($media['mime']) ? trim((string) $media['mime']) : '';
        $extension = $this->resolve_extension($mime, $media['filename'] ?? null, 'bin');
        $stored_name = 'wa_' . substr(md5((string) $message->whatsapp_message_id), 0, 12) . '.' . $extension;
        $directory = 'support_messages/' . $ticket_id;
        $stored_path = $directory . '/' . $stored_name;

        Storage::disk('public')->put($stored_path, $binary);

        SupportMessageAttachment::create([
            'support_message_id' => $message->id,
            'disk'               => 'public',
            'path'               => $stored_path,
            'mime'               => $mime !== '' ? $mime : null,
            'size'               => strlen($binary),
        ]);

        return true;
    }

    /**
     * Descarga media entrante y crea LeadMessageAttachment en disco public (mismo flujo que soporte).
     *
     * @param LeadMessage                                         $message
     * @param int                                                 $lead_id
     * @param array{url?: string|null, mime?: string|null, filename?: string|null, whatsapp_media_id?: string|null} $media
     *
     * @return bool true si se guardó el adjunto.
     */
    public function persist_lead_attachment(LeadMessage $message, int $lead_id, array $media): bool
    {
        $config = WhatsappConfig::getActive();
        if (! $config || ! $config->is_active) {
            return false;
        }

        $api_key = trim((string) $config->kapso_api_key);
        $phone_number_id = trim((string) $config->phone_number_id);
        $binary = null;

        if (! empty($media['url'])) {
            $binary = $this->download_media_binary((string) $media['url'], $api_key);
        }

        if (($binary === null || $binary === '') && ! empty($media['whatsapp_media_id']) && $phone_number_id !== '') {
            $binary = $this->download_media_binary_by_whatsapp_id(
                (string) $media['whatsapp_media_id'],
                $phone_number_id,
                $api_key
            );
        }

        if ($binary === null || $binary === '') {
            Log::channel('daily')->warning('WhatsappInboundMediaService: no se pudo descargar media de lead.', [
                'message_id'        => $message->id,
                'url'               => $media['url'] ?? null,
                'whatsapp_media_id' => $media['whatsapp_media_id'] ?? null,
            ]);

            return false;
        }

        $mime = isset($media['mime']) ? trim((string) $media['mime']) : '';
        $fallback_ext = 'bin';
        if ($mime !== '' && strpos(strtolower($mime), 'audio/') === 0) {
            $fallback_ext = 'ogg';
        }
        $extension = $this->resolve_extension($mime, $media['filename'] ?? null, $fallback_ext);
        $stored_name = 'wa_' . substr(md5((string) $message->whatsapp_message_id), 0, 12) . '.' . $extension;
        $directory = 'lead_messages/' . $lead_id;
        $stored_path = $directory . '/' . $stored_name;

        Storage::disk('public')->put($stored_path, $binary);

        LeadMessageAttachment::create([
            'lead_message_id' => $message->id,
            'disk'            => 'public',
            'path'            => $stored_path,
            'mime'            => $mime !== '' ? $mime : null,
            'size'            => strlen($binary),
        ]);

        Log::channel('daily')->info('WhatsappInboundMediaService: adjunto de lead persistido.', [
            'lead_message_id' => $message->id,
            'path'            => $stored_path,
            'mime'            => $mime !== '' ? $mime : null,
            'size_bytes'      => strlen($binary),
        ]);

        return true;
    }

    /**
     * Clave del nodo media en el payload (ptt/voice suelen usar audio o ptt).
     *
     * @param array<string, mixed> $message
     * @param string               $type
     *
     * @return string
     */
    private function resolve_media_payload_key(array $message, string $type): string
    {
        if (isset($message[$type]) && is_array($message[$type])) {
            return $type;
        }

        if (in_array($type, ['ptt', 'voice', 'audio'], true)) {
            if (isset($message['audio']) && is_array($message['audio'])) {
                return 'audio';
            }
            if (isset($message['ptt']) && is_array($message['ptt'])) {
                return 'ptt';
            }
        }

        return $type;
    }

    /**
     * Resuelve la URL pública o firmada del archivo en Kapso / Meta.
     *
     * @param array<string, mixed> $message
     * @param string               $type
     * @param array<string, mixed> $kapso
     *
     * @return string|null
     */
    private function resolve_media_url(array $message, string $type, array $kapso): ?string
    {
        if (isset($kapso['media_url']) && is_string($kapso['media_url'])) {
            $url = trim($kapso['media_url']);
            if ($url !== '') {
                return $url;
            }
        }

        if (isset($kapso['mediaUrl']) && is_string($kapso['mediaUrl'])) {
            $url = trim($kapso['mediaUrl']);
            if ($url !== '') {
                return $url;
            }
        }

        if (isset($message[$type]['link']) && is_string($message[$type]['link'])) {
            $url = trim($message[$type]['link']);
            if ($url !== '') {
                return $url;
            }
        }

        if (isset($kapso['content'])) {
            $parsed_meta = $this->parse_kapso_content_metadata((string) $kapso['content']);

            return $parsed_meta['url'];
        }

        return null;
    }

    /**
     * Detecta un adjunto de WhatsApp/Kapso persistido como texto en implementation_messages.body.
     *
     * El webhook guarda kapso.content con formato:
     *   [Document attached (archivo.xlsx)]
     *   [Size: … | Type: …]
     *   URL: https://…
     *
     * @param string $body Texto del mensaje almacenado en implementation_messages.
     *
     * @return array{url: string, filename: string, mime: string|null}|null
     */
    public function parse_attachment_from_message_body(string $body): ?array
    {
        $body = trim($body);

        if ($body === '') {
            return null;
        }

        // Solo mensajes con patrón de adjunto multimedia de Kapso.
        if (! preg_match('/\b(document|image|video|audio)\s+attached\b/i', $body)) {
            return null;
        }

        $meta = $this->parse_kapso_content_metadata($body);
        $url  = trim((string) ($meta['url'] ?? ''));

        if ($url === '') {
            return null;
        }

        $filename = trim((string) ($meta['filename'] ?? ''));

        if ($filename === '') {
            $filename = 'archivo';
        }

        return [
            'url'      => $url,
            'filename' => $filename,
            'mime'     => $meta['mime'],
        ];
    }

    /**
     * Parsea el texto legado de kapso.content (Image attached … URL: https://…).
     *
     * @param string $content
     *
     * @return array{url: string|null, mime: string|null, filename: string|null}
     */
    private function parse_kapso_content_metadata(string $content): array
    {
        $url = null;
        $mime = null;
        $filename = null;

        if (preg_match('/URL:\s*(https?:\/\/\S+)/i', $content, $matches)) {
            $url = rtrim($matches[1], '.,;)"\'');
        } elseif (preg_match('#(https?://[^\s\]\)\"\'<>]+)#i', $content, $matches)) {
            $url = rtrim($matches[1], '.,;)"\'');
        }

        if (preg_match('/Type:\s*([^\|\]]+)/i', $content, $matches)) {
            $mime = trim($matches[1]);
        }

        // Nombre entre paréntesis tras "Document/Image/Video/Audio attached".
        if (preg_match('/(?:document|image|video|audio)\s+attached\s*\(([^)]+)\)/i', $content, $matches)) {
            $filename = trim($matches[1]);
        } elseif (preg_match('/\(([A-Za-z0-9._ -]+\.[A-Za-z0-9]+)\)/i', $content, $matches)) {
            $filename = trim($matches[1]);
        }

        return [
            'url'      => $url,
            'mime'     => $mime,
            'filename' => $filename,
        ];
    }

    /**
     * Descarga bytes del archivo remoto (Kapso suele requerir X-API-Key).
     *
     * @param string $url
     * @param string $api_key
     *
     * @return string|null
     */
    private function download_media_binary(string $url, string $api_key): ?string
    {
        try {
            $http = KapsoHttpClient::make($api_key, (int) config('services.client_api.timeout', 30));
            $response = $http->withHeaders([
                'Accept' => '*/*',
            ])->get($url);

            if ($response->successful()) {
                return $response->body();
            }

            // Reintento sin API key por si la URL ya es firmada y pública.
            $fallback = KapsoHttpClient::make(null, (int) config('services.client_api.timeout', 30));
            $response = $fallback->get($url);
            if ($response->successful()) {
                return $response->body();
            }
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappInboundMediaService: excepción al descargar.', [
                'url'   => $url,
                'error' => $exception->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Descarga media usando el ID de Meta vía proxy Kapso (cuando no hay URL directa).
     *
     * @param string $whatsapp_media_id ID en message.image.id.
     * @param string $phone_number_id   phone_number_id de la config activa.
     * @param string $api_key
     *
     * @return string|null
     */
    private function download_media_binary_by_whatsapp_id(
        string $whatsapp_media_id,
        string $phone_number_id,
        string $api_key
    ): ?string {
        $metadata_endpoint = 'https://api.kapso.ai/meta/whatsapp/v24.0/'
            . rawurlencode($whatsapp_media_id)
            . '?phone_number_id=' . rawurlencode($phone_number_id);

        try {
            $http = KapsoHttpClient::make($api_key, (int) config('services.client_api.timeout', 30));
            $response = $http->get($metadata_endpoint);

            if (! $response->successful()) {
                return null;
            }

            $payload = $response->json();
            if (is_array($payload) && isset($payload['url']) && is_string($payload['url'])) {
                return $this->download_media_binary($payload['url'], $api_key);
            }

            $body = $response->body();
            if ($body !== null && $body !== '' && strlen($body) > 100) {
                return $body;
            }
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappInboundMediaService: excepción al resolver media por ID.', [
                'whatsapp_media_id' => $whatsapp_media_id,
                'error'             => $exception->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Deduce extensión de archivo a partir de mime o nombre.
     *
     * @param string      $mime
     * @param string|null $filename
     * @param string      $fallback
     *
     * @return string
     */
    private function resolve_extension(string $mime, ?string $filename, string $fallback): string
    {
        if ($filename !== null && $filename !== '') {
            $parts = explode('.', $filename);
            if (count($parts) > 1) {
                $ext = strtolower((string) end($parts));
                if ($ext !== '') {
                    return $ext;
                }
            }
        }

        $mime_map = [
            'image/jpeg'  => 'jpg',
            'image/jpg'   => 'jpg',
            'image/png'   => 'png',
            'image/webp'  => 'webp',
            'image/gif'   => 'gif',
            'audio/ogg'   => 'ogg',
            'audio/opus'  => 'ogg',
            'audio/mpeg'  => 'mp3',
            'audio/mp4'   => 'm4a',
            'audio/aac'   => 'aac',
            'audio/amr'   => 'amr',
            'audio/webm'  => 'webm',
        ];

        if (isset($mime_map[strtolower($mime)])) {
            return $mime_map[strtolower($mime)];
        }

        return $fallback;
    }

    /**
     * Obtiene nombre de archivo desde la URL si no vino en el payload.
     *
     * @param string $url
     * @param string $type
     *
     * @return string|null
     */
    private function guess_filename_from_url(string $url, string $type): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return $type . '_' . time();
        }

        $basename = basename($path);
        if ($basename !== '' && strpos($basename, '.') !== false) {
            return $basename;
        }

        return null;
    }
}
