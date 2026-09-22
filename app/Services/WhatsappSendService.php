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
     * Lo lee el llamador tras recibir null de send_text()/send_template()/send_reaction() para
     * persistirlo en el LeadMessage (prompt 336) o para armar el mensaje que ve el operador. Se
     * resetea a null al inicio de cada uno de esos tres métodos y se setea en
     * notify_admins_of_failure(), único punto por el que pasan todos los caminos de fallo. Null si el
     * último envío de esta instancia fue exitoso.
     *
     * @var string|null
     */
    public $last_send_error = null;

    /**
     * Código de status HTTP del último fallo de send_text()/send_template()/send_reaction() (409,
     * 429, 500, etc.), cuando se pudo determinar. Tiene dos lectores:
     *
     * 1. LeadSuggestionSendService::send_body() (prompt 366 / fix lead #440) tras recibir null de
     *    send_text(), para decidir vía last_send_was_transient() si el fallo es transitorio
     *    (típicamente el 409 de Kapso "otro mensaje en vuelo para esta conversación") y conviene
     *    reintentar con backoff, o si es un rechazo definitivo.
     * 2. LeadController::mensaje_de_reaccion_fallida(), para no diagnosticar la ventana de 24hs
     *    sobre un fallo que es otra cosa (Meta la rechaza con 400; un 401 o un 500 no son ese caso).
     *
     * Se resetea a null en el mismo punto que $last_send_error (arranque de los tres métodos de
     * envío). Null si el último envío de esta instancia fue exitoso o si no se pudo determinar el
     * status HTTP.
     *
     * @var int|null
     */
    public $last_send_status_code = null;

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
        // Resetea el status HTTP del fallo anterior por el mismo motivo (prompt 366, fix lead #440).
        $this->last_send_status_code = null;

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

            /*
             * Captura del status HTTP real del fallo (prompt 366, fix lead #440), ANTES de
             * notify_admins_of_failure(): el llamador (LeadSuggestionSendService::send_body())
             * lo necesita para decidir si conviene reintentar (409/429/5xx transitorios) o cortar
             * de una. Vía principal: la RequestException de Laravel trae la Response real adjunta.
             */
            if ($exception instanceof \Illuminate\Http\Client\RequestException && $exception->response !== null) {
                $this->last_send_status_code = (int) $exception->response->status();
            } else {
                // Respaldo: algunas excepciones de Guzzle no llegan como RequestException con
                // response adjunta, pero el mensaje trae el texto "status code XXX" igual (así lo
                // formatea Guzzle/Laravel, y es literalmente lo que se vio en el log de producción).
                if (preg_match('/status code (\d{3})/', $exception->getMessage(), $matches)) {
                    $this->last_send_status_code = (int) $matches[1];
                }
            }

            $this->notify_admins_of_failure($notify_context, $exception->getMessage(), $skip_failure_notification);
        }

        return null;
    }

    /**
     * Indica si el último fallo de send_text() es transitorio y tiene sentido reintentarlo
     * después de esperar (no un error de configuración ni un rechazo definitivo de Meta).
     *
     * 409 es el caso central: Kapso responde "Another message is already in-flight for this
     * conversation" cuando llega un envío mientras otro de la misma conversación sigue en vuelo.
     * Es seguro reintentarlo: el 409 significa que el mensaje NO se encoló, así que no puede
     * duplicarse en el WhatsApp del destinatario.
     *
     * @return bool
     */
    public function last_send_was_transient(): bool
    {
        return in_array($this->last_send_status_code, [409, 429, 500, 502, 503, 504], true);
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
        // Resetea el status HTTP del fallo anterior por el mismo motivo (prompt 366, fix lead #440).
        $this->last_send_status_code = null;

        $notify_context = $context !== null ? $context : "Envío de plantilla '{$template_name}' a {$to}";

        /*
         * 🔴 GUARD DE VARIABLE VACÍA. No lo saques "porque más abajo ya está el `! empty($variables)`".
         *
         * Ese `! empty()` NO cubre este caso y por eso está esta guarda separada: `['']` no está
         * vacío —tiene un elemento— así que pasa el chequeo, se arma el componente `body` y sale a
         * la red un parámetro de texto vacío. Y para Meta un parámetro de texto vacío ES un
         * parámetro que falta: responde `(#131008) Required parameter is missing` y descarta el
         * mensaje. El aguas arriba es igual de engañoso: `$lead->contact_name ?? ''` parece un
         * fallback y no lo es, porque `??` solo atrapa null y el campo puede venir `''`.
         *
         * Dos guardas que parecían cubrir el caso y ninguna lo cubría: 2.933 seguimientos perdidos
         * sobre 159 leads entre julio y agosto de 2026, todos con `whatsapp_message_id` null. Se
         * corta acá, antes de salir a la red, porque un envío rechazado por Meta consume ventana de
         * conversación y no deja rastro legible de por qué.
         *
         * Va antes de resolve_send_context() a propósito: un payload inválido es inválido tenga o
         * no configuración activa, y así el motivo del fallo queda escrito igual.
         */
        $empty_variable_error = $this->find_empty_template_variable($template_name, $variables);
        if ($empty_variable_error !== null) {
            Log::channel('daily')->warning('WhatsappSendService: plantilla con variable vacía, envío cortado antes de salir.', [
                'to'       => $to,
                'template' => $template_name,
                'error'    => $empty_variable_error,
            ]);
            $this->notify_admins_of_failure($notify_context, $empty_variable_error, false);

            return null;
        }

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
     * Envía una reacción con emoji sobre un mensaje ya existente de la conversación.
     *
     * 🔴 A diferencia de {@see send_text()}, acá el cuerpo vacío es LEGÍTIMO y no se corta:
     * `$emoji = ''` es exactamente como la Cloud API de Meta pide QUITAR una reacción. Por eso este
     * método no tiene la guarda de "cuerpo vacío" que sí tiene send_text() — no es un olvido y no
     * hay que "arreglarlo". Tampoco se le hace `trim()` al emoji al armar el payload: el string
     * vacío tiene que viajar tal cual.
     *
     * No toca la base ni conoce LeadMessage: solo habla con Kapso, igual que sus hermanos. Quien
     * llama decide qué persistir según lo que devuelva.
     *
     * @param string      $to                        Número destino en formato E.164 (+549…).
     * @param string      $target_whatsapp_message_id wamid del mensaje al que se reacciona.
     * @param string      $emoji                      Emoji a aplicar; '' quita la reacción.
     * @param string|null $context                    Descripción legible para la notificación de
     *                                                 fallo a admins. Si es null se arma una genérica.
     * @param bool        $skip_failure_notification  true para NO gastar el aviso por WhatsApp a los
     *                                                 admins (throttleado globalmente a 1 cada 10
     *                                                 minutos). El panel lo manda en true: un operador
     *                                                 toqueteando la paleta en un hilo frío quemaría
     *                                                 ese cupo y dejaría un fallo de envío REAL de esos
     *                                                 10 minutos reducido a una línea de log. El motivo
     *                                                 igual queda en last_send_error, que es lo que el
     *                                                 llamador le muestra a quien apretó.
     *
     * @return string|null wamid de la reacción asignado por Meta, o null si falló.
     */
    public function send_reaction(string $to, string $target_whatsapp_message_id, string $emoji, ?string $context = null, bool $skip_failure_notification = false): ?string
    {
        // Resetea el motivo del fallo anterior: solo debe quedar seteado si ESTE envío falla.
        $this->last_send_error = null;
        // Resetea el status HTTP del fallo anterior por el mismo motivo.
        $this->last_send_status_code = null;

        $notify_context = $context !== null ? $context : "Reacción a {$target_whatsapp_message_id} para {$to}";

        $target_wamid = trim($target_whatsapp_message_id);
        if ($target_wamid === '') {
            Log::channel('daily')->warning('WhatsappSendService: reacción sin wamid del mensaje objetivo.', [
                'to' => $to,
            ]);
            $this->notify_admins_of_failure($notify_context, 'La reacción no se envió: falta el id de WhatsApp del mensaje objetivo.', $skip_failure_notification);

            return null;
        }

        /*
         * test_mode: mismo criterio que send_text(). Se chequea ANTES de resolve_send_context(),
         * porque ese método ya corta a null en test_mode y no expone el motivo hacia arriba, así
         * que el llamador no podría distinguir "no salió porque estamos probando" de un fallo real.
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

            $fake_message_id = 'test-' . (string) \Illuminate\Support\Str::uuid();

            Log::channel('daily')->info('WhatsappSendService: test_mode activo, reacción simulada (no se llamó a la API real).', [
                'to'                       => $normalized_to,
                'target_message_id'        => $target_wamid,
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

        $endpoint = $this->messages_endpoint($send_context['phone_number_id']);

        try {
            $http = KapsoHttpClient::make($send_context['api_key'], (int) config('services.client_api.timeout', 15));

            $response = $http
                ->retry((int) config('services.client_api.retries', 2), 500)
                ->post($endpoint, [
                    'messaging_product' => 'whatsapp',
                    'to'                => $to_digits,
                    'type'              => 'reaction',
                    'reaction'          => [
                        'message_id' => $target_wamid,
                        // Sin trim(): '' quita la reacción y tiene que viajar tal cual.
                        'emoji'      => $emoji,
                    ],
                ]);

            $message_id = $this->extract_message_id_from_response($response, $normalized_to);
            if ($message_id === null) {
                $this->notify_admins_of_failure($notify_context, 'Kapso/Meta no devolvió message_id para la reacción.', $skip_failure_notification);
            }

            return $message_id;
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappSendService: excepción al enviar reacción.', [
                'to'                => $normalized_to,
                'target_message_id' => $target_wamid,
                'error'             => $exception->getMessage(),
            ]);

            /*
             * Captura del status HTTP real ANTES de notify_admins_of_failure(): es lo que deja
             * legible el 400 de "pasaron más de 24 horas" (ventana cerrada) en last_send_status_code,
             * con el motivo en last_send_error para que el operador lo vea en el panel.
             */
            if ($exception instanceof \Illuminate\Http\Client\RequestException && $exception->response !== null) {
                $this->last_send_status_code = (int) $exception->response->status();
            } else {
                // Respaldo: algunas excepciones de Guzzle no llegan como RequestException con
                // response adjunta, pero el mensaje trae el texto "status code XXX" igual.
                if (preg_match('/status code (\d{3})/', $exception->getMessage(), $matches)) {
                    $this->last_send_status_code = (int) $matches[1];
                }
            }

            $this->notify_admins_of_failure($notify_context, $exception->getMessage(), $skip_failure_notification);
        }

        return null;
    }

    /**
     * Busca la primera variable de plantilla que llegó vacía y devuelve el motivo legible.
     *
     * Se compara con `trim()` y no con `empty()`: `'   '` no está vacío para PHP y sí lo está para
     * Meta. `'0'` sí es un valor válido, y por eso tampoco sirve `empty()` acá.
     *
     * @param string            $template_name Nombre de la plantilla, para que el motivo diga cuál.
     * @param array<int, mixed> $variables     Valores posicionales del body ({{1}}, {{2}}…).
     *
     * @return string|null Motivo en castellano, o null si todas las variables tienen contenido.
     */
    private function find_empty_template_variable(string $template_name, array $variables): ?string
    {
        /* Posición 1-based, que es como Meta numera los placeholders. */
        $posicion = 0;

        foreach ($variables as $value) {
            $posicion++;
            $placeholder = '{{' . $posicion . '}}';

            if (is_array($value) || is_object($value)) {
                return "La plantilla '{$template_name}' no se envió: la variable {$placeholder} llegó con un valor no textual.";
            }

            if (trim((string) $value) === '') {
                return "La plantilla '{$template_name}' no se envió porque la variable {$placeholder} llegó vacía. "
                    . 'Para Meta un parámetro de texto vacío es un parámetro que falta '
                    . '(error 131008: Required parameter is missing), así que el mensaje se habría rechazado igual.';
            }
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
     * Envía una imagen por WhatsApp a partir de una URL pública, sin subirle nada a Meta.
     *
     * La Cloud API acepta un mensaje de imagen de dos formas: `image: {id}` con un media_id que
     * antes hubo que subir al endpoint `/media` —es lo que hace {@see send_image_attachment()} con
     * los adjuntos de soporte, que viven en el disco del admin—, o `image: {link}` con una URL que
     * Meta baja por su cuenta. Este método usa la segunda, y es a propósito: las fotos que el
     * asistente del sistema de un cliente adjunta a una respuesta (misión `asistente-omnisciente`,
     * 21/9/2026) son las imágenes de su catálogo de artículos, que ya son URLs públicas del hosting
     * o de R2. Mandarlas por media_id sería bajar los bytes al admin para volver a subírselos a
     * Meta —dos viajes por foto por un archivo que Meta puede ir a buscar solo— más un media_id que
     * caduca a los 30 días y que acá no se reutiliza nunca.
     *
     * Lo que Meta exige del link: público (sin login ni firma que venza), `http(s)` y de un tipo
     * que soporte (jpeg o png, hasta 5 MB). Si no cumple, Meta rechaza el mensaje y esto devuelve
     * null como cualquier otro fallo de envío, con el motivo en `$last_send_error` y el status en
     * `$last_send_status_code`.
     *
     * 🔴 `$skip_failure_notification` va en **true por defecto**, al revés que en {@see send_text()}.
     * Una foto que no sale no es un incidente para los admins: el texto de la respuesta ya salió,
     * y el aviso a admins está throttleado a uno cada 10 minutos de forma global — gastarlo en una
     * foto del catálogo deja mudo un fallo de envío real de esos diez minutos. El motivo igual queda
     * en `$last_send_error` para que el llamador lo loguee.
     *
     * @param string      $to                        Número destino en formato E.164 (+549…).
     * @param string      $url                       URL pública `http(s)` de la imagen.
     * @param string|null $caption                   Epígrafe debajo de la foto; null o vacío = sin epígrafe.
     * @param string|null $context                   Descripción legible para el motivo del fallo
     *                                                 (ej: "Asistente por WhatsApp - foto - cliente #42").
     *                                                 Si es null se arma una descripción genérica.
     * @param bool        $skip_failure_notification  true (por defecto) para NO avisar a los admins
     *                                                 del fallo. Ver arriba por qué.
     *
     * @return string|null whatsapp_message_id asignado por Meta, o null si falló.
     */
    public function send_image_by_link(string $to, string $url, ?string $caption = null, ?string $context = null, bool $skip_failure_notification = true): ?string
    {
        // Mismo reseteo que en send_text(): el motivo solo debe quedar seteado si ESTE envío falla.
        $this->last_send_error = null;
        $this->last_send_status_code = null;

        $notify_context = $context !== null ? $context : "Envío de imagen por link a {$to}";

        /*
         * Un link que no es http(s) se corta ACÁ, antes de mirar la configuración: Meta lo
         * rechazaría igual, y así el motivo queda escrito tenga o no configuración activa. Es la
         * misma razón por la que send_template() valida sus variables antes de resolve_send_context().
         */
        $link = trim($url);
        if (! preg_match('#^https?://#i', $link)) {
            Log::channel('daily')->warning('WhatsappSendService: link de imagen inválido, envío cortado antes de salir.', [
                'to'  => $to,
                'url' => mb_strimwidth($link, 0, 200, '…'),
            ]);
            $this->notify_admins_of_failure(
                $notify_context,
                'El link de la imagen no es una URL http(s): ' . mb_strimwidth($link, 0, 200, '…'),
                $skip_failure_notification
            );

            return null;
        }

        /*
         * test_mode: mismo criterio que send_text(). Se chequea ANTES de resolve_send_context(),
         * porque ese método ya corta a null en test_mode sin dejar motivo, y el llamador loguearía
         * "la foto no salió" sin nada adentro por algo que no es un fallo.
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

            $fake_message_id = 'test-' . (string) \Illuminate\Support\Str::uuid();

            Log::channel('daily')->info('WhatsappSendService: test_mode activo, imagen por link simulada (no se llamó a la API real).', [
                'to'                       => $normalized_to,
                'url'                      => $link,
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
            Log::channel('daily')->warning('WhatsappSendService: número destino inválido (imagen por link).', [
                'to' => $to,
            ]);
            $this->notify_admins_of_failure($notify_context, "Número destino inválido: {$to}", $skip_failure_notification);

            return null;
        }

        $image_payload = ['link' => $link];
        $caption_text = $caption !== null ? trim($caption) : '';
        if ($caption_text !== '') {
            // 1024 es el tope de Meta para el caption de una imagen: más largo, rechaza el mensaje entero.
            $image_payload['caption'] = mb_strimwidth($caption_text, 0, 1024, '…');
        }

        $endpoint = $this->messages_endpoint($send_context['phone_number_id']);

        try {
            $http = KapsoHttpClient::make($send_context['api_key'], (int) config('services.client_api.timeout', 15));

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
                $this->notify_admins_of_failure($notify_context, 'Kapso/Meta no devolvió message_id para la imagen (ver logs para detalle).', $skip_failure_notification);
            }

            return $message_id;
        } catch (\Throwable $exception) {
            /*
             * El origen y el archivo, no el link entero: es una URL del catálogo de un cliente real
             * y este log lo leen personas (mismo criterio que el warning del job que manda las
             * fotos del asistente). Para entender un fallo alcanza con de dónde salía y qué archivo
             * era; el link completo sigue estando del lado del `empresa-api` que lo mandó.
             */
            Log::channel('daily')->error('WhatsappSendService: excepción al enviar imagen por link.', [
                'to'      => $normalized_to,
                'origen'  => parse_url($link, PHP_URL_HOST),
                'archivo' => basename((string) parse_url($link, PHP_URL_PATH)),
                'error'   => $exception->getMessage(),
            ]);

            /*
             * Status HTTP real del fallo, igual que en send_text(): es lo que distingue en el log un
             * 400 de Meta por un link que no pudo bajar de un 5xx pasajero de Kapso.
             */
            if ($exception instanceof \Illuminate\Http\Client\RequestException && $exception->response !== null) {
                $this->last_send_status_code = (int) $exception->response->status();
            } else {
                if (preg_match('/status code (\d{3})/', $exception->getMessage(), $matches)) {
                    $this->last_send_status_code = (int) $matches[1];
                }
            }

            $this->notify_admins_of_failure($notify_context, $exception->getMessage(), $skip_failure_notification);
        }

        return null;
    }

    /**
     * Envía una imagen que ya está en memoria: la sube a `/media` y la manda por su media_id.
     *
     * Es el otro camino de {@see send_image_by_link()}, y la diferencia que importa no es el costo
     * sino **cuándo se entera uno de que la foto no sirve**:
     *
     * - Por **link**, Meta contesta 200 con un `wamid` y recién después va a buscar el archivo. Si
     *   el tipo no le sirve —webp, por ejemplo, que es lo que guarda el ERP— lo descarta en
     *   silencio: el admin registra la foto como enviada, no hay ningún error en ningún log y el
     *   dueño no recibe nada.
     * - Por **media_id**, la subida es sincrónica: un archivo que Meta no acepta se rechaza en el
     *   acto, con status, y el llamador puede contarlo como fallido de verdad.
     *
     * Quién elige cuál es {@see AsistenteFotoSalienteService::va_por_link()}, y ahí está escrito
     * por qué no se usa siempre el mismo.
     *
     * 🔴 `$skip_failure_notification` va en **true por defecto**, igual que en `send_image_by_link()`
     * y por el mismo motivo: una foto que no sale no es un incidente para los admins, y el aviso
     * está throttleado a uno cada 10 minutos de forma global.
     *
     * @param string      $to                        Número destino E.164.
     * @param string      $contents                  Bytes de la imagen.
     * @param string      $mime                      `image/jpeg` o `image/png`.
     * @param string      $filename                  Nombre en el multipart; su extensión debe coincidir con el mime.
     * @param string|null $caption                   Epígrafe, o null para mandarla sola.
     * @param string|null $context                   Descripción legible para el motivo del fallo.
     * @param bool        $skip_failure_notification  true (por defecto) para NO avisar a los admins.
     * @param int|null    $segundos_por_llamada       Techo de cada una de las dos llamadas (la subida
     *                                                y el mensaje). Con un valor acá los reintentos
     *                                                HTTP del mensaje también bajan a uno: esto
     *                                                corre adentro del turno, con presupuesto, y el
     *                                                que reintenta es el job. Null usa la config de
     *                                                siempre.
     *
     * @return string|null whatsapp_message_id asignado por Meta, o null si falló.
     */
    public function send_image_by_bytes(
        string $to,
        string $contents,
        string $mime,
        string $filename,
        ?string $caption = null,
        ?string $context = null,
        bool $skip_failure_notification = true,
        ?int $segundos_por_llamada = null
    ): ?string {
        // Mismo reseteo que en send_image_by_link(): el motivo solo queda si ESTE envío falla.
        $this->last_send_error = null;
        $this->last_send_status_code = null;

        $notify_context = $context !== null ? $context : "Envío de imagen a {$to}";

        if ($contents === '') {
            $this->notify_admins_of_failure($notify_context, 'La imagen a enviar llegó vacía.', $skip_failure_notification);

            return null;
        }

        /*
         * test_mode antes de resolve_send_context(), igual que en send_image_by_link(): ese método
         * corta a null sin dejar motivo, y el llamador loguearía "la foto no salió" por algo que no
         * es un fallo. Acá además evita subirle un archivo real a Meta desde un entorno de prueba.
         */
        $active_config = WhatsappConfig::getActive();
        if ($active_config && $active_config->is_active && $active_config->test_mode) {
            $fake_message_id = 'test-' . (string) \Illuminate\Support\Str::uuid();

            Log::channel('daily')->info('WhatsappSendService: test_mode activo, imagen por media_id simulada (no se subió nada).', [
                'to'                       => WhatsappNormalizer::normalize($to),
                'archivo'                  => $filename,
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
            Log::channel('daily')->warning('WhatsappSendService: número destino inválido (imagen por media_id).', [
                'to' => $to,
            ]);
            $this->notify_admins_of_failure($notify_context, "Número destino inválido: {$to}", $skip_failure_notification);

            return null;
        }

        $media_id = $this->upload_media_bytes(
            $send_context['phone_number_id'],
            $send_context['api_key'],
            $contents,
            $mime,
            $filename,
            $skip_failure_notification,
            $segundos_por_llamada
        );

        if ($media_id === null) {
            /* upload_media_bytes() ya dejó el motivo y el status por notify_admins_of_failure(); si
             * por algún camino no lo hizo, se pone uno legible para que el log del job no salga
             * con el motivo en blanco. */
            if ($this->last_send_error === null) {
                $this->notify_admins_of_failure($notify_context, 'No se pudo subir la imagen a WhatsApp.', $skip_failure_notification);
            }

            return null;
        }

        return $this->send_image_by_media_id(
            $to,
            $send_context['phone_number_id'],
            $send_context['api_key'],
            $media_id,
            $caption,
            $skip_failure_notification,
            $notify_context,
            $segundos_por_llamada
        );
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
        try {
            $file_contents = file_get_contents($absolute_path);
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappSendService: excepción al leer el archivo a subir.', [
                'path'  => $absolute_path,
                'error' => $exception->getMessage(),
            ]);
            $this->notify_admins_of_failure("Subida de adjunto ({$mime}) a WhatsApp", $exception->getMessage(), false);

            return null;
        }

        if ($file_contents === false || $file_contents === '') {
            Log::channel('daily')->warning('WhatsappSendService: archivo de imagen vacío o ilegible.', [
                'path' => $absolute_path,
            ]);

            return null;
        }

        $multipart_name = $upload_filename !== null && $upload_filename !== ''
            ? $upload_filename
            : basename($absolute_path);

        return $this->upload_media_bytes($phone_number_id, $api_key, $file_contents, $mime, $multipart_name);
    }

    /**
     * Sube al endpoint media de Kapso/Meta un archivo que ya está en memoria.
     *
     * Es el cuerpo de {@see upload_media()} sin el paso por el disco. Existe porque las fotos que
     * el asistente le manda al dueño **nunca tocan el storage del admin**: se bajan del hosting del
     * cliente, se convierten a JPEG en memoria y se suben desde ahí (ver
     * {@see AsistenteFotoSalienteService}). Escribir un archivo temporal para volver a leerlo dos
     * líneas después sería dejar la foto del catálogo de un cliente en el disco de la plataforma
     * por el único motivo de que esta firma pedía una ruta.
     *
     * @param string $phone_number_id
     * @param string $api_key
     * @param string $contents                  Bytes del archivo.
     * @param string $mime                      Content-Type con el que viaja.
     * @param string $upload_filename           Nombre en el multipart (su extensión debe coincidir con el mime).
     * @param bool   $skip_failure_notification true para NO avisarle a los admins si la subida falla.
     *                                          Va en true desde las fotos del asistente por el mismo
     *                                          motivo que en {@see send_image_by_link()}: el aviso
     *                                          está throttleado a uno cada 10 minutos de forma
     *                                          global y gastarlo en una foto del catálogo deja mudo
     *                                          un fallo de envío real.
     * @param int|null $segundos                Techo de esta llamada; null usa la config de siempre.
     *
     * @return string|null Media ID.
     */
    public function upload_media_bytes(
        string $phone_number_id,
        string $api_key,
        string $contents,
        string $mime,
        string $upload_filename,
        bool $skip_failure_notification = false,
        ?int $segundos = null
    ): ?string {
        if ($contents === '') {
            return null;
        }

        $endpoint = 'https://api.kapso.ai/meta/whatsapp/v24.0/'
            . rawurlencode($phone_number_id)
            . '/media';

        try {
            $timeout = $segundos !== null ? $segundos : (int) config('services.client_api.timeout', 30);

            $http = KapsoHttpClient::make($api_key, $timeout, false);
            $response = $http
                ->attach('file', $contents, $upload_filename, ['Content-Type' => $mime])
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

            /* El status del rechazo queda a mano del llamador: es lo que distingue un 409 de Kapso
             * —que se reintenta— de un 400 de Meta por un archivo que no acepta, que no. */
            $this->last_send_status_code = (int) $response->status();
            $this->notify_admins_of_failure(
                "Subida de adjunto ({$mime}) a WhatsApp",
                'Kapso respondió con error al subir el archivo. Status: ' . $response->status(),
                $skip_failure_notification
            );
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappSendService: excepción al subir media.', [
                'archivo' => $upload_filename,
                'error'   => $exception->getMessage(),
            ]);
            $this->notify_admins_of_failure(
                "Subida de adjunto ({$mime}) a WhatsApp",
                $exception->getMessage(),
                $skip_failure_notification
            );
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
     * @param bool        $skip_failure_notification true para NO avisarle a los admins. Lo usan las
     *                                               fotos del asistente, que no son un incidente.
     * @param string|null $notify_context            Descripción legible del envío para el motivo del
     *                                               fallo; null arma la genérica de siempre.
     * @param int|null    $segundos                  Techo de esta llamada. Con un valor acá los
     *                                               reintentos HTTP bajan a UNO: el llamador corre
     *                                               con presupuesto de tiempo y reintenta por su
     *                                               cuenta, así que tres intentos internos le
     *                                               triplicarían el peor caso a sus espaldas.
     *
     * @return string|null
     */
    private function send_image_by_media_id(
        string $to,
        string $phone_number_id,
        string $api_key,
        string $media_id,
        ?string $caption,
        bool $skip_failure_notification = false,
        ?string $notify_context = null,
        ?int $segundos = null
    ): ?string {
        $contexto = $notify_context !== null ? $notify_context : "Envío de imagen a {$to}";

        $normalized_to = WhatsappNormalizer::normalize($to);
        $to_digits = preg_replace('/\D+/', '', $normalized_to) ?? '';
        if ($to_digits === '') {
            $this->notify_admins_of_failure($contexto, "Número destino inválido: {$to}", $skip_failure_notification);

            return null;
        }

        $image_payload = ['id' => $media_id];
        $caption_text = $caption !== null ? trim($caption) : '';
        if ($caption_text !== '') {
            // 1024 es el tope de Meta para el caption de una imagen, igual que en send_image_by_link():
            // más largo, rechaza el mensaje entero — y acá el epígrafe lo escribe el asistente de un
            // cliente, así que no hay nadie garantizando el largo del otro lado.
            $image_payload['caption'] = mb_strimwidth($caption_text, 0, 1024, '…');
        }

        $endpoint = $this->messages_endpoint($phone_number_id);

        try {
            $timeout   = $segundos !== null ? $segundos : (int) config('services.client_api.timeout', 15);
            $reintentos = $segundos !== null ? 1 : (int) config('services.client_api.retries', 2);

            $http = KapsoHttpClient::make($api_key, $timeout);
            $response = $http
                ->retry($reintentos, 500)
                ->post($endpoint, [
                    'messaging_product' => 'whatsapp',
                    'to'                => $to_digits,
                    'type'              => 'image',
                    'image'             => $image_payload,
                ]);

            $message_id = $this->extract_message_id_from_response($response, $normalized_to);
            if ($message_id === null) {
                $this->last_send_status_code = (int) $response->status();
                $this->notify_admins_of_failure($contexto, 'Kapso/Meta no devolvió message_id.', $skip_failure_notification);
            }

            return $message_id;
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappSendService: excepción al enviar imagen.', [
                'to'    => $normalized_to,
                'error' => $exception->getMessage(),
            ]);

            /* Mismo criterio que en send_image_by_link(): el status real es lo que le deja decidir
             * al llamador si el fallo fue transitorio (el 409 de Kapso) y conviene reintentar. */
            if ($exception instanceof \Illuminate\Http\Client\RequestException && $exception->response !== null) {
                $this->last_send_status_code = (int) $exception->response->status();
            } else {
                if (preg_match('/status code (\d{3})/', $exception->getMessage(), $matches)) {
                    $this->last_send_status_code = (int) $matches[1];
                }
            }

            $this->notify_admins_of_failure($contexto, $exception->getMessage(), $skip_failure_notification);
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
     * $skip_failure_notification se pasa en true en dos casos, y sólo en esos dos:
     *
     * 1. Cuando el envío que falló ES la propia notificación de fallo hacia un admin
     *    (SystemErrorWhatsappService::notify_send_error llama a send_text() con este flag en true)
     *    — evita que un Kapso caído dispare una recursión de notificaciones fallidas notificando
     *    notificaciones fallidas.
     * 2. Desde send_reaction(), porque el fallo de una reacción ya se le muestra en el acto al
     *    operador que la disparó. El aviso a admins está throttleado a 1 cada 10 minutos y es
     *    global: gastar ese cupo en un pulgar que no salió deja mudo un fallo de envío real.
     *
     * En los dos casos $last_send_error se sigue seteando (pasa antes del early return de abajo),
     * así que el llamador conserva el motivo aunque no se notifique a nadie.
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
