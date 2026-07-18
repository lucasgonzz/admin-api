<?php

namespace App\Services;

use App\Helpers\WhatsappNormalizer;
use App\Models\SupportMessage;
use App\Models\WhatsappConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Envío de mensajes salientes vía Kapso (Meta Cloud API): texto, imagen y audio.
 */
class WhatsappSendService
{
    /**
     * Motivo del último fallo de envío de esta instancia (excepción, status HTTP, validación, etc.).
     * Lo lee el llamador tras recibir null de send_text()/send_template() para persistirlo en el LeadMessage
     * (prompt 336). Se resetea a null al inicio de cada send_text()/send_template() y se setea en
     * notify_admins_of_failure(), único punto por el que pasan todos los caminos de fallo. Null si el
     * último envío de esta instancia fue exitoso.
     *
     * @var string|null
     */
    public $last_send_error = null;

    /**
     * Envía un mensaje de soporte según kind y adjuntos (audio, imagen o texto).
     *
     * @param string         $to      Número destino E.164.
     * @param SupportMessage $message Mensaje persistido con relación attachments cargada si es posible.
     *
     * @return string|null whatsapp_message_id de Meta.
     */
    public function send_support_message(string $to, SupportMessage $message): ?string
    {
        $message->loadMissing('attachments');

        $kind = (string) ($message->kind ?? 'text');
        $audio_attachment = null;
        $image_attachment = null;

        foreach ($message->attachments as $attachment) {
            $mime = strtolower((string) ($attachment->mime ?? ''));
            if ($kind === 'audio' || strpos($mime, 'audio/') === 0) {
                $audio_attachment = $attachment;
                break;
            }
            if ($kind === 'image' || strpos($mime, 'image/') === 0) {
                $image_attachment = $attachment;
            }
        }

        if ($audio_attachment !== null) {
            return $this->send_audio_attachment($to, $audio_attachment);
        }

        if ($image_attachment !== null) {
            $caption = trim((string) ($message->body ?? ''));

            return $this->send_image_attachment($to, $image_attachment, $caption !== '' ? $caption : null);
        }

        return $this->send_text($to, (string) ($message->body ?? ''));
    }

    /**
     * Envía un mensaje de texto a un número WhatsApp y retorna el ID de Meta.
     *
     * @param string      $to                        Número destino en formato E.164 (+549…).
     * @param string      $body                       Texto del mensaje.
     * @param string|null $context                    Descripción legible para la notificación de fallo
     *                                                 a admins (ej: "Sugerencia de Claude - Lead #42 (Juan)").
     *                                                 Si es null se arma una descripción genérica.
     * @param bool        $skip_failure_notification  Interno: true SOLO cuando este envío es la propia
     *                                                 notificación de fallo a un admin (evita recursión
     *                                                 infinita si Kapso está caído y ese envío también falla).
     *
     * @return string|null whatsapp_message_id asignado por Meta, o null si falló.
     */
    public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
    {
        // Resetea el motivo del fallo anterior: solo debe quedar seteado si ESTE envío falla (prompt 336).
        $this->last_send_error = null;

        $notify_context = $context !== null ? $context : "Envío de texto a {$to}";

        /*
         * FIX (test_mode simulado, 3/7/2026): antes, con test_mode activo,
         * resolve_send_context() devolvía null y este método lo trataba igual que
         * un fallo real de envío. LeadSuggestionSendService no podía distinguir
         * "no se envió porque estamos probando" de "no se envió porque falló de
         * verdad", y marcaba el mensaje como rechazado sin nunca aplicar el
         * pipeline sugerido por Claude (apply_suggested_pipeline_status()) — el
         * lead nunca avanzaba de estado en el admin durante pruebas locales.
         * Ahora, si test_mode está activo, se devuelve un whatsapp_message_id
         * simulado (prefijo "test-") sin llamar a la API real, para que el resto
         * del pipeline trate el mensaje como enviado con éxito. Se chequea acá,
         * antes de resolve_send_context(), porque ese método ya corta a null en
         * test_mode y no expone el motivo hacia arriba.
         */
        $active_config = WhatsappConfig::getActive();
        if ($active_config && $active_config->is_active && $active_config->test_mode) {
            $normalized_to = WhatsappNormalizer::normalize($to);
            $to_digits = preg_replace('/\D+/', '', $normalized_to) ?? '';
            if ($to_digits === '') {
                Log::channel('daily')->warning('WhatsappSendService: número destino inválido.', [
                    'to' => $to,
                ]);
                $this->notify_admins_of_failure($notify_context, "Número destino inválido: {$to}", $skip_failure_notification);

                return null;
            }

            $text_body = trim($body);
            if ($text_body === '') {
                Log::channel('daily')->warning('WhatsappSendService: cuerpo de mensaje vacío.');
                $this->notify_admins_of_failure($notify_context, 'Cuerpo de mensaje vacío.', $skip_failure_notification);

                return null;
            }

            $fake_message_id = 'test-' . (string) \Illuminate\Support\Str::uuid();

            Log::channel('daily')->info('WhatsappSendService: test_mode activo, envío simulado (no se llamó a la API real).', [
                'to'                       => $normalized_to,
                'fake_whatsapp_message_id' => $fake_message_id,
            ]);

            return $fake_message_id;
        }

        $send_context = $this->resolve_send_context($skip_failure_notification);
        if ($send_context === null) {
            return null;
        }

        $normalized_to = WhatsappNormalizer::normalize($to);
        $to_digits = preg_replace('/\D+/', '', $normalized_to) ?? '';
        if ($to_digits === '') {
            Log::channel('daily')->warning('WhatsappSendService: número destino inválido.', [
                'to' => $to,
            ]);
            $this->notify_admins_of_failure($notify_context, "Número destino inválido: {$to}", $skip_failure_notification);

            return null;
        }

        $text_body = trim($body);
        if ($text_body === '') {
            Log::channel('daily')->warning('WhatsappSendService: cuerpo de mensaje vacío.');
            $this->notify_admins_of_failure($notify_context, 'Cuerpo de mensaje vacío.', $skip_failure_notification);

            return null;
        }

        $endpoint = $this->messages_endpoint($send_context['phone_number_id']);

        try {
            $http = KapsoHttpClient::make($send_context['api_key'], (int) config('services.client_api.timeout', 15));

            $response = $http
                ->retry((int) config('services.client_api.retries', 2), 500)
                ->post($endpoint, [
                    'messaging_product' => 'whatsapp',
                    'to'                => $to_digits,
                    'type'              => 'text',
                    'text'              => [
                        'body' => $text_body,
                    ],
                ]);

            $message_id = $this->extract_message_id_from_response($response, $normalized_to);
            if ($message_id === null) {
                $this->notify_admins_of_failure($notify_context, 'Kapso/Meta no devolvió message_id (ver logs para detalle).', $skip_failure_notification);
            }

            return $message_id;
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappSendService: excepción al enviar texto.', [
                'to'    => $normalized_to,
                'error' => $exception->getMessage(),
            ]);
            $this->notify_admins_of_failure($notify_context, $exception->getMessage(), $skip_failure_notification);
        }

        return null;
    }

    /**
     * Envía una plantilla Meta aprobada (Template Message) y retorna el ID de Meta.
     *
     * Necesario para contactar leads pasadas las 24 hs de su última respuesta,
     * cuando Meta ya no permite mensajes free-form.
     *
     * @param string      $to            Número destino en formato E.164 (+549…).
     * @param string      $template_name Nombre exacto de la plantilla aprobada en Meta.
     * @param array       $variables     Valores de las variables del body, en orden ({{1}}, {{2}}…).
     * @param string      $language_code Código de idioma de la plantilla en Meta.
     * @param string|null $context       Descripción legible para la notificación de fallo a admins
     *                                   (ej: "Seguimiento automático - Lead #42 (Juan)"). Si es null
     *                                   se arma una descripción genérica con el nombre de la plantilla.
     *
     * @return string|null whatsapp_message_id asignado por Meta, o null si falló.
     */
    public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
    {
        // Resetea el motivo del fallo anterior: solo debe quedar seteado si ESTE envío falla (prompt 336).
        $this->last_send_error = null;

        $notify_context = $context !== null ? $context : "Envío de plantilla '{$template_name}' a {$to}";

        $send_context = $this->resolve_send_context();
        if ($send_context === null) {
            return null;
        }

        $normalized_to = WhatsappNormalizer::normalize($to);
        $to_digits = preg_replace('/\D+/', '', $normalized_to) ?? '';
        if ($to_digits === '') {
            Log::channel('daily')->warning('WhatsappSendService: número destino inválido (template).', [
                'to' => $to,
            ]);
            $this->notify_admins_of_failure($notify_context, "Número destino inválido: {$to}", false);

            return null;
        }

        // Payload base del template; sin components si la plantilla no tiene variables.
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to_digits,
            'type'              => 'template',
            'template'          => [
                'name'     => $template_name,
                'language' => ['code' => $language_code],
            ],
        ];

        // Solo agregamos el componente body si hay variables para inyectar.
        if (! empty($variables)) {
            $payload['template']['components'] = [[
                'type'       => 'body',
                'parameters' => array_map(function ($value) {
                    return ['type' => 'text', 'text' => (string) $value];
                }, $variables),
            ]];
        }

        $endpoint = $this->messages_endpoint($send_context['phone_number_id']);

        try {
            $http = KapsoHttpClient::make($send_context['api_key'], (int) config('services.client_api.timeout', 15));

            $response = $http
                ->retry((int) config('services.client_api.retries', 2), 500)
                ->post($endpoint, $payload);

            $message_id = $this->extract_message_id_from_response($response, $normalized_to);
            if ($message_id === null) {
                $this->notify_admins_of_failure($notify_context, "Kapso/Meta no devolvió message_id para la plantilla {$template_name}.", false);
            }

            return $message_id;
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappSendService: excepción al enviar template.', [
                'to'       => $normalized_to,
                'template' => $template_name,
                'error'    => $exception->getMessage(),
            ]);
            $this->notify_admins_of_failure($notify_context, $exception->getMessage(), false);
        }

        return null;
    }

    /**
     * Sube un adjunto local y envía mensaje de imagen por WhatsApp.
     *
     * @param string $to
     * @param object $attachment SupportMessageAttachment con disk/path/mime.
     * @param string|null $caption
     *
     * @return string|null
     */
    public function send_image_attachment(string $to, $attachment, ?string $caption = null): ?string
    {
        $context = $this->resolve_send_context();
        if ($context === null) {
            return null;
        }

        $disk = (string) ($attachment->disk ?? 'public');
        $relative_path = (string) ($attachment->path ?? '');
        if ($relative_path === '' || ! Storage::disk($disk)->exists($relative_path)) {
            Log::channel('daily')->warning('WhatsappSendService: adjunto de imagen no encontrado.', [
                'path' => $relative_path,
            ]);

            return null;
        }

        $absolute_path = Storage::disk($disk)->path($relative_path);
        $mime = (string) ($attachment->mime ?? 'image/jpeg');
        $upload_filename = basename($absolute_path);
        $media_id = $this->upload_media(
            $context['phone_number_id'],
            $context['api_key'],
            $absolute_path,
            $mime,
            $upload_filename
        );

        if ($media_id === null) {
            return null;
        }

        return $this->send_image_by_media_id($to, $context['phone_number_id'], $context['api_key'], $media_id, $caption);
    }

    /**
     * Sube un adjunto de audio y lo envía por WhatsApp (nota de voz o audio según formato).
     *
     * @param string $to
     * @param object $attachment SupportMessageAttachment con disk/path/mime.
     *
     * @return string|null
     */
    public function send_audio_attachment(string $to, $attachment): ?string
    {
        $context = $this->resolve_send_context();
        if ($context === null) {
            return null;
        }

        $disk = (string) ($attachment->disk ?? 'public');
        $relative_path = (string) ($attachment->path ?? '');
        if ($relative_path === '' || ! Storage::disk($disk)->exists($relative_path)) {
            Log::channel('daily')->warning('WhatsappSendService: adjunto de audio no encontrado.', [
                'path' => $relative_path,
            ]);

            return null;
        }

        $absolute_path = Storage::disk($disk)->path($relative_path);
        $stored_mime = strtolower((string) ($attachment->mime ?? ''));
        $extension = strtolower(pathinfo($absolute_path, PATHINFO_EXTENSION));

        // Si el audio es MP4/M4A, intentar convertirlo a OGG/Opus via ffmpeg.
        // Esto resuelve el error 131053 de Meta ("audio/mp4 processed as application/octet-stream"):
        // Chrome graba en fragmented MP4 (fMP4) que Meta acepta como upload pero descarta al procesar.
        // ffmpeg convierte el fMP4 a OGG/Opus que Meta acepta y entrega correctamente.
        // Si ffmpeg no está disponible (ej: shared hosting), se continúa con el archivo original.
        if (strpos($stored_mime, 'mp4') !== false || $extension === 'm4a' || $extension === 'mp4') {
            $converted = $this->maybe_convert_mp4_to_ogg($absolute_path);
            if ($converted !== null) {
                $absolute_path = $converted;
                $stored_mime = 'audio/ogg';
                $extension = 'ogg';
            }
        }

        $whatsapp_mime = $this->resolve_whatsapp_audio_mime($stored_mime, $extension);
        $upload_filename = $this->resolve_whatsapp_audio_upload_filename($extension, $whatsapp_mime);
        $voice_note = $this->should_send_as_whatsapp_voice_note($whatsapp_mime, $extension);

        $media_id = $this->upload_media(
            $context['phone_number_id'],
            $context['api_key'],
            $absolute_path,
            $whatsapp_mime,
            $upload_filename
        );

        if ($media_id === null) {
            return null;
        }

        return $this->send_audio_by_media_id(
            $to,
            $context['phone_number_id'],
            $context['api_key'],
            $media_id,
            $voice_note
        );
    }

    /**
     * Sube un archivo al endpoint media de Kapso/Meta.
     *
     * @param string      $phone_number_id
     * @param string      $api_key
     * @param string      $absolute_path
     * @param string      $mime
     * @param string|null $upload_filename Nombre de archivo en el multipart (debe coincidir con el mime).
     *
     * @return string|null Media ID.
     */
    public function upload_media(
        string $phone_number_id,
        string $api_key,
        string $absolute_path,
        string $mime,
        ?string $upload_filename = null
    ): ?string {
        $endpoint = 'https://api.kapso.ai/meta/whatsapp/v24.0/'
            . rawurlencode($phone_number_id)
            . '/media';

        try {
            $file_contents = file_get_contents($absolute_path);
            if ($file_contents === false || $file_contents === '') {
                Log::channel('daily')->warning('WhatsappSendService: archivo de imagen vacío o ilegible.', [
                    'path' => $absolute_path,
                ]);

                return null;
            }

            $multipart_name = $upload_filename !== null && $upload_filename !== ''
                ? $upload_filename
                : basename($absolute_path);

            $http = KapsoHttpClient::make($api_key, (int) config('services.client_api.timeout', 30), false);
            $response = $http
                ->attach('file', $file_contents, $multipart_name, ['Content-Type' => $mime])
                ->post($endpoint, [
                    'messaging_product' => 'whatsapp',
                ]);

            if ($response->successful()) {
                $payload = $response->json();
                if (is_array($payload) && isset($payload['id']) && $payload['id'] !== '') {
                    return (string) $payload['id'];
                }
            }

            Log::channel('daily')->error('WhatsappSendService: error al subir media.', [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 500),
            ]);
            $this->notify_admins_of_failure(
                "Subida de adjunto ({$mime}) a WhatsApp",
                'Kapso respondió con error al subir el archivo. Status: ' . $response->status(),
                false
            );
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappSendService: excepción al subir media.', [
                'path'  => $absolute_path,
                'error' => $exception->getMessage(),
            ]);
            $this->notify_admins_of_failure("Subida de adjunto ({$mime}) a WhatsApp", $exception->getMessage(), false);
        }

        return null;
    }

    /**
     * Envía imagen referenciando un media_id previamente subido.
     *
     * @param string      $to
     * @param string      $phone_number_id
     * @param string      $api_key
     * @param string      $media_id
     * @param string|null $caption
     *
     * @return string|null
     */
    private function send_image_by_media_id(
        string $to,
        string $phone_number_id,
        string $api_key,
        string $media_id,
        ?string $caption
    ): ?string {
        $normalized_to = WhatsappNormalizer::normalize($to);
        $to_digits = preg_replace('/\D+/', '', $normalized_to) ?? '';
        if ($to_digits === '') {
            return null;
        }

        $image_payload = ['id' => $media_id];
        if ($caption !== null && trim($caption) !== '') {
            $image_payload['caption'] = trim($caption);
        }

        $endpoint = $this->messages_endpoint($phone_number_id);

        try {
            $http = KapsoHttpClient::make($api_key, (int) config('services.client_api.timeout', 15));
            $response = $http
                ->retry((int) config('services.client_api.retries', 2), 500)
                ->post($endpoint, [
                    'messaging_product' => 'whatsapp',
                    'to'                => $to_digits,
                    'type'              => 'image',
                    'image'             => $image_payload,
                ]);

            $message_id = $this->extract_message_id_from_response($response, $normalized_to);
            if ($message_id === null) {
                $this->notify_admins_of_failure("Envío de imagen a {$to}", 'Kapso/Meta no devolvió message_id.', false);
            }

            return $message_id;
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappSendService: excepción al enviar imagen.', [
                'to'    => $normalized_to,
                'error' => $exception->getMessage(),
            ]);
            $this->notify_admins_of_failure("Envío de imagen a {$to}", $exception->getMessage(), false);
        }

        return null;
    }

    /**
     * Envía audio referenciando un media_id (nota de voz si voice = true).
     *
     * @param string $to
     * @param string $phone_number_id
     * @param string $api_key
     * @param string $media_id
     * @param bool   $voice_note      true para notas de voz (.ogg opus).
     *
     * @return string|null
     */
    private function send_audio_by_media_id(
        string $to,
        string $phone_number_id,
        string $api_key,
        string $media_id,
        bool $voice_note
    ): ?string {
        $normalized_to = WhatsappNormalizer::normalize($to);
        $to_digits = preg_replace('/\D+/', '', $normalized_to) ?? '';
        if ($to_digits === '') {
            return null;
        }

        $audio_payload = ['id' => $media_id];
        if ($voice_note) {
            $audio_payload['voice'] = true;
        }

        $endpoint = $this->messages_endpoint($phone_number_id);

        try {
            $http = KapsoHttpClient::make($api_key, (int) config('services.client_api.timeout', 15));
            $response = $http
                ->retry((int) config('services.client_api.retries', 2), 500)
                ->post($endpoint, [
                    'messaging_product' => 'whatsapp',
                    'to'                => $to_digits,
                    'type'              => 'audio',
                    'audio'             => $audio_payload,
                ]);

            $message_id = $this->extract_message_id_from_response($response, $normalized_to);
            if ($message_id === null) {
                $this->notify_admins_of_failure("Envío de audio a {$to}", 'Kapso/Meta no devolvió message_id.', false);
            }

            return $message_id;
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappSendService: excepción al enviar audio.', [
                'to'    => $normalized_to,
                'error' => $exception->getMessage(),
            ]);
            $this->notify_admins_of_failure("Envío de audio a {$to}", $exception->getMessage(), false);
        }

        return null;
    }

    /**
     * Envía un archivo como documento (fallback para formatos no soportados como audio nativo,
     * y también usado directamente por LeadController@send_direct_document_json — prompt 466 —
     * para el envío de documentos, por eso el método es público).
     *
     * @param string      $to
     * @param object      $attachment
     * @param string|null $filename
     * @param string|null $mime
     *
     * @return string|null
     */
    public function send_document_attachment(string $to, $attachment, ?string $filename = null, ?string $mime = null): ?string
    {
        $context = $this->resolve_send_context();
        if ($context === null) {
            return null;
        }

        $disk = (string) ($attachment->disk ?? 'public');
        $relative_path = (string) ($attachment->path ?? '');
        if ($relative_path === '' || ! Storage::disk($disk)->exists($relative_path)) {
            return null;
        }

        $absolute_path = Storage::disk($disk)->path($relative_path);
        $upload_mime = $mime !== null && $mime !== '' ? $mime : (string) ($attachment->mime ?? 'application/octet-stream');
        $upload_filename = $filename !== null && $filename !== '' ? $filename : basename($absolute_path);

        $media_id = $this->upload_media(
            $context['phone_number_id'],
            $context['api_key'],
            $absolute_path,
            $upload_mime,
            $upload_filename
        );

        if ($media_id === null) {
            return null;
        }

        $normalized_to = WhatsappNormalizer::normalize($to);
        $to_digits = preg_replace('/\D+/', '', $normalized_to) ?? '';
        if ($to_digits === '') {
            return null;
        }

        $document_payload = ['id' => $media_id];
        if ($upload_filename !== '') {
            $document_payload['filename'] = $upload_filename;
        }

        $endpoint = $this->messages_endpoint($context['phone_number_id']);

        try {
            $http = KapsoHttpClient::make($context['api_key'], (int) config('services.client_api.timeout', 15));
            $response = $http
                ->retry((int) config('services.client_api.retries', 2), 500)
                ->post($endpoint, [
                    'messaging_product' => 'whatsapp',
                    'to'                => $to_digits,
                    'type'              => 'document',
                    'document'          => $document_payload,
                ]);

            return $this->extract_message_id_from_response($response, $normalized_to);
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappSendService: excepción al enviar documento.', [
                'to'    => $normalized_to,
                'error' => $exception->getMessage(),
            ]);
        }

        return null;
    }


    /**
     * Intenta convertir un archivo de audio MP4/fMP4 a OGG/Opus usando ffmpeg.
     *
     * Chrome y Safari generan fragmented MP4 (fMP4) que Meta acepta como upload pero
     * descarta silenciosamente al procesar (error 131053: "audio/mp4 processed as
     * application/octet-stream"). Convertir a OGG/Opus soluciona el problema.
     *
     * Si ffmpeg no está disponible (ej: shared hosting sin acceso a exec) devuelve null
     * y el pipeline continúa con el archivo original sin romper el flujo.
     *
     * @param string $absolute_path Ruta absoluta del archivo MP4/M4A de origen.
     *
     * @return string|null Ruta del archivo OGG temporal creado, o null si no fue posible convertir.
     */
    private function maybe_convert_mp4_to_ogg(string $absolute_path): ?string
    {
        // Verificar que exec() esté habilitado y que ffmpeg esté disponible.
        if (! function_exists('exec')) {
            return null;
        }

        $ffmpeg_path = trim((string) shell_exec('which ffmpeg 2>/dev/null'));
        if ($ffmpeg_path === '') {
            Log::channel('daily')->info('WhatsappSendService: ffmpeg no disponible, se omite conversión MP4→OGG.', [
                'path' => $absolute_path,
            ]);

            return null;
        }

        $output_path = sys_get_temp_dir() . '/wa_audio_' . uniqid('', true) . '.ogg';

        // Convertir a OGG/Opus (-acodec libopus) con calidad estándar para WhatsApp.
        // -y sobreescribe si ya existe, -loglevel error suprime output no crítico.
        $command = escapeshellcmd($ffmpeg_path)
            . ' -y -i ' . escapeshellarg($absolute_path)
            . ' -acodec libopus -b:a 32k -ar 16000 -ac 1'
            . ' -loglevel error '
            . escapeshellarg($output_path)
            . ' 2>&1';

        exec($command, $output_lines, $exit_code);

        if ($exit_code !== 0 || ! file_exists($output_path) || filesize($output_path) === 0) {
            Log::channel('daily')->warning('WhatsappSendService: ffmpeg no pudo convertir MP4→OGG.', [
                'path'      => $absolute_path,
                'exit_code' => $exit_code,
                'output'    => implode(' ', $output_lines),
            ]);

            if (file_exists($output_path)) {
                @unlink($output_path);
            }

            return null;
        }

        Log::channel('daily')->info('WhatsappSendService: MP4 convertido a OGG via ffmpeg.', [
            'original' => $absolute_path,
            'output'   => $output_path,
            'size'     => filesize($output_path),
        ]);

        return $output_path;
    }

    /**
     * Mime de subida aceptado por WhatsApp para mensajes de audio.
     *
     * @param string $stored_mime
     * @param string $extension
     *
     * @return string
     */
    private function resolve_whatsapp_audio_mime(string $stored_mime, string $extension): string
    {
        // WebM de Chrome (codec Opus) → declarar como audio/ogg para que Meta lo acepte.
        if (strpos($stored_mime, 'webm') !== false || $extension === 'webm') {
            return 'audio/ogg';
        }
        if (strpos($stored_mime, 'ogg') !== false || $extension === 'ogg') {
            return 'audio/ogg';
        }
        if (strpos($stored_mime, 'mpeg') !== false || $extension === 'mp3') {
            return 'audio/mpeg';
        }
        if (strpos($stored_mime, 'aac') !== false || $extension === 'aac') {
            return 'audio/aac';
        }
        if (strpos($stored_mime, 'amr') !== false || $extension === 'amr') {
            return 'audio/amr';
        }
        if (strpos($stored_mime, 'mp4') !== false || $extension === 'm4a' || $extension === 'mp4') {
            return 'audio/mp4';
        }

        return 'audio/ogg';
    }

    /**
     * Nombre de archivo en el upload (extensión coherente con el mime de WhatsApp).
     *
     * @param string $extension
     * @param string $whatsapp_mime
     *
     * @return string
     */
    private function resolve_whatsapp_audio_upload_filename(string $extension, string $whatsapp_mime): string
    {
        // WebM → subir con extensión .ogg coherente con el mime declarado.
        if ($extension === 'webm') {
            return 'audio_' . time() . '.ogg';
        }
        if ($extension !== '') {
            return 'audio_' . time() . '.' . $extension;
        }

        if ($whatsapp_mime === 'audio/mpeg') {
            return 'audio_' . time() . '.mp3';
        }
        if ($whatsapp_mime === 'audio/mp4') {
            return 'audio_' . time() . '.m4a';
        }

        return 'audio_' . time() . '.ogg';
    }

    /**
     * Notas de voz en WhatsApp requieren OGG con codec Opus y flag voice.
     *
     * @param string $whatsapp_mime
     * @param string $extension
     *
     * @return bool
     */
    private function should_send_as_whatsapp_voice_note(string $whatsapp_mime, string $extension): bool
    {
        return $whatsapp_mime === 'audio/ogg' || $extension === 'ogg';
    }

    /**
     * Configuración activa de Kapso para envíos salientes.
     *
     * @param bool $skip_failure_notification Interno: ver {@see send_text()}.
     *
     * @return array{api_key: string, phone_number_id: string}|null
     */
    private function resolve_send_context(bool $skip_failure_notification = false): ?array
    {
        $config = WhatsappConfig::getActive();
        if (! $config || ! $config->is_active) {
            Log::channel('daily')->warning('WhatsappSendService: configuración inactiva o inexistente.');
            $this->notify_admins_of_failure(
                'Configuración de WhatsApp',
                'No hay configuración activa de WhatsApp (WhatsappConfig::getActive() es null o is_active=false). Ningún mensaje se está enviando.',
                $skip_failure_notification
            );

            return null;
        }

        // Modo de prueba: cortamos el envío real (devolvemos null) pero sin warning,
        // ya que es un estado esperado. El resto del pipeline (sugerencias, guardado) sigue normal.
        // No se notifica a admins: no es una falla, es un comportamiento intencional.
        if ($config->test_mode) {
            Log::channel('daily')->info('WhatsappSendService: test_mode activo, mensaje no enviado.');

            return null;
        }

        $api_key = trim((string) $config->kapso_api_key);
        $phone_number_id = trim((string) $config->phone_number_id);
        if ($api_key === '' || $phone_number_id === '') {
            Log::channel('daily')->warning('WhatsappSendService: kapso_api_key o phone_number_id vacíos.');
            $this->notify_admins_of_failure(
                'Configuración de WhatsApp',
                'kapso_api_key o phone_number_id están vacíos en la configuración activa.',
                $skip_failure_notification
            );

            return null;
        }

        return [
            'api_key'         => $api_key,
            'phone_number_id' => $phone_number_id,
        ];
    }

    /**
     * Notifica a los admins suscritos que un envío de WhatsApp falló.
     *
     * Punto único de disparo hacia {@see SystemErrorWhatsappService}, que a su vez agrupa
     * ráfagas de fallos (máximo 1 WhatsApp cada 10 minutos, ver esa clase para el detalle).
     *
     * $skip_failure_notification debe ser true únicamente cuando el envío que falló ES la
     * propia notificación de fallo hacia un admin (SystemErrorWhatsappService::notify_send_error
     * llama a send_text() con este flag en true) — evita que un Kapso caído dispare una
     * recursión de notificaciones fallidas notificando notificaciones fallidas.
     *
     * @param string $context                    Descripción legible de qué se intentaba enviar.
     * @param string $detail                      Detalle del error (excepción, status HTTP, etc.).
     * @param bool   $skip_failure_notification
     *
     * @return void
     */
    private function notify_admins_of_failure(string $context, string $detail, bool $skip_failure_notification): void
    {
        // Captura el motivo del fallo para que el llamador pueda adjuntarlo al LeadMessage (prompt 336).
        // Punto único: todos los caminos de fallo de send_text()/send_template() pasan por acá.
        $this->last_send_error = $detail;

        if ($skip_failure_notification) {
            return;
        }

        try {
            app(SystemErrorWhatsappService::class)->notify_send_error($context, $detail);
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappSendService: excepción al notificar admins de fallo de envío.', [
                'context' => $context,
                'error'   => $exception->getMessage(),
            ]);
        }
    }

    /**
     * URL del endpoint de envío de mensajes.
     *
     * @param string $phone_number_id
     *
     * @return string
     */
    private function messages_endpoint(string $phone_number_id): string
    {
        return 'https://api.kapso.ai/meta/whatsapp/v24.0/'
            . rawurlencode($phone_number_id)
            . '/messages';
    }

    /**
     * Extrae el ID de mensaje de la respuesta de Kapso.
     *
     * @param \Illuminate\Http\Client\Response $response
     * @param string                           $normalized_to
     *
     * @return string|null
     */
    private function extract_message_id_from_response($response, string $normalized_to): ?string
    {
        if ($response->successful()) {
            $payload = $response->json();
            $message_id = null;
            if (is_array($payload) && isset($payload['messages'][0]['id'])) {
                $message_id = (string) $payload['messages'][0]['id'];
            }

            if ($message_id !== null && $message_id !== '') {
                Log::channel('daily')->info('WhatsappSendService: envío exitoso.', [
                    'to'                  => $normalized_to,
                    'whatsapp_message_id' => $message_id,
                ]);

                return $message_id;
            }

            Log::channel('daily')->error('WhatsappSendService: respuesta sin message_id.', [
                'to'     => $normalized_to,
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 500),
            ]);

            return null;
        }

        Log::channel('daily')->error('WhatsappSendService: error HTTP de Kapso.', [
            'to'     => $normalized_to,
            'status' => $response->status(),
            'error'  => substr($response->body(), 0, 500),
        ]);

        return null;
    }
}
