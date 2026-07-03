<?php

namespace App\Http\Controllers;

use App\Events\SupportMessageReceived;
use App\Helpers\WhatsappNormalizer;
use App\Services\SupportAiSettings;
use App\Services\SupportAiSuggestionScheduler;
use App\Models\Client;
use App\Models\ClientEmployee;
use App\Models\EcommerceImplementation;
use App\Models\EcommerceImplementationMessage;
use App\Models\Implementation;
use App\Models\ImplementationMessage;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\WhatsappConfig;
use App\Services\EcommerceImplementationBroadcastService;
use App\Services\EcommerceImplementationConversationService;
use App\Services\ImplementationBroadcastService;
use App\Services\ImplementationConversationService;
use App\Services\CloserNotificationService;
use App\Services\LeadAiSuggestionScheduler;
use App\Services\LeadBroadcastService;
use App\Services\LeadDocNumberGenerator;
use App\Services\LeadEscalationWhatsappService;
use App\Services\LeadWhatsappInboundAudioService;
use App\Services\LeadWhatsappOnboardingService;
use App\Services\LeadWhatsappReactionService;
use App\Services\SistemaQueryService;
use App\Services\SupportTicketAssignmentService;
use App\Services\WhatsappInboundMediaService;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Webhook público de Kapso para mensajes WhatsApp entrantes.
 * Enruta a soporte (clientes activos) o al pipeline de leads (desconocidos).
 */
class WhatsappWebhookController extends Controller
{
    /**
     * Evento Kapso que indica un mensaje entrante de cliente.
     */
    private const INBOUND_EVENT = 'whatsapp.message.received';

    /**
     * Eventos Kapso de estado de entrega de mensajes salientes.
     * Clave: nombre del evento Kapso. Valor: estado interno normalizado.
     * El estado 'enviado' no se persiste (se infiere por whatsapp_message_id).
     */
    private const STATUS_EVENTS = [
        'whatsapp.message.sent'      => 'enviado',
        'whatsapp.message.delivered' => 'entregado',
        'whatsapp.message.read'      => 'leido',
        'whatsapp.message.failed'    => 'fallido',
    ];

    /**
     * Recibe y procesa un evento de webhook de Kapso.
     *
     * @param Request                        $request
     * @param SupportTicketAssignmentService $assignment_service
     * @return JsonResponse
     */
    public function receive(
        Request $request,
        SupportTicketAssignmentService $assignment_service
    ): JsonResponse {
        $config = WhatsappConfig::getActive();
        if (! $config || ! $config->is_active) {
            return response()->json(['message' => 'WhatsApp integration unavailable.'], 503);
        }

        if (! $this->verify_signature($request, $config)) {
            Log::channel('daily')->warning('WhatsApp webhook: firma inválida.', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $raw_body = $request->getContent();
        $payload = json_decode($raw_body, true);
        if (! is_array($payload)) {
            return response()->json(['ok' => true], 200);
        }

        $event_type = $this->resolve_event_type($request, $payload);

        // Procesar eventos de estado de entrega de mensajes salientes (entregado / leído / fallido).
        // Solo afecta LeadMessages; si el wamid no corresponde a ninguno, se ignora sin error.
        if (array_key_exists((string) $event_type, self::STATUS_EVENTS)) {
            $this->handle_outbound_status_event((string) $event_type, $payload);
            return response()->json(['ok' => true], 200);
        }

        if ($event_type !== self::INBOUND_EVENT) {
            return response()->json(['ok' => true], 200);
        }

        $parsed = $this->parse_inbound_message($payload);
        if ($parsed === null) {
            return response()->json(['ok' => true], 200);
        }

        // Reacciones: actualizar el mensaje original; no crear fila nueva ni consultar a Claude.
        $reaction_service = new LeadWhatsappReactionService();
        $reaction_data = $reaction_service->extract_reaction($payload, $parsed);
        if ($reaction_data !== null) {
            try {
                $support_contact = $this->find_support_contact_by_phone((string) $reaction_data['from']);
                if ($support_contact === null) {
                    $reaction_service->handle_lead_inbound_reaction($reaction_data, $payload);
                }
            } catch (\Throwable $exception) {
                Log::channel('daily')->error('WhatsApp webhook: error al procesar reacción.', [
                    'from'                => $reaction_data['from'] ?? null,
                    'reaction_message_id' => $reaction_data['reaction_message_id'] ?? null,
                    'error'               => $exception->getMessage(),
                ]);
            }

            return response()->json(['ok' => true], 200);
        }

        if ($this->is_duplicate_message($parsed['message_id'])) {
            return response()->json(['ok' => true], 200);
        }

        try {
            if ($this->is_inbound_audio_kind((string) ($parsed['type'] ?? ''))) {
                Log::channel('daily')->info('WhatsApp webhook: mensaje entrante es AUDIO.', [
                    'from'                 => $parsed['from'],
                    'whatsapp_message_id'  => $parsed['message_id'],
                    'normalized_kind'      => $parsed['type'],
                    'has_inbound_media'    => ! empty($parsed['inbound_media']),
                    'body_preview'         => mb_substr((string) ($parsed['body'] ?? ''), 0, 120),
                ]);
            }

            $support_contact = $this->find_support_contact_by_phone($parsed['from']);

            if ($support_contact !== null) {
                $client = $support_contact['client'];
                $client_employee = $support_contact['client_employee'];

                // Verificar si el cliente tiene una implementación activa antes de enrutar a soporte.
                // Si tiene activas la del sistema y la del ecommerce a la vez, se prioriza la del sistema.
                $implementation           = $client->implementation;
                $ecommerce_implementation = $client->ecommerce_implementation;

                if ($implementation !== null && $implementation->status === 'in_progress') {
                    $this->handle_implementation_message($parsed, $client, $implementation);
                    Log::channel('daily')->info('WhatsApp webhook: mensaje enrutado a implementación.', [
                        'from'              => $parsed['from'],
                        'type'              => $parsed['type'],
                        'route'             => 'implementacion',
                        'client_id'         => $client->id,
                        'implementation_id' => $implementation->id,
                    ]);
                } elseif ($ecommerce_implementation !== null && $ecommerce_implementation->status === 'in_progress') {
                    $this->handle_ecommerce_implementation_message($parsed, $client, $ecommerce_implementation);
                    Log::channel('daily')->info('WhatsApp webhook: mensaje enrutado a implementación de ecommerce.', [
                        'from'                        => $parsed['from'],
                        'type'                        => $parsed['type'],
                        'route'                       => 'ecommerce-implementacion',
                        'client_id'                   => $client->id,
                        'ecommerce_implementation_id' => $ecommerce_implementation->id,
                    ]);
                } else {
                    $this->handle_support_message($parsed, $client, $client_employee, $assignment_service);
                    Log::channel('daily')->info('WhatsApp webhook: mensaje enrutado a soporte.', [
                        'from'               => $parsed['from'],
                        'type'               => $parsed['type'],
                        'route'              => 'cliente',
                        'client_id'          => $client->id,
                        'client_employee_id' => $client_employee ? $client_employee->id : null,
                        'is_audio'           => $this->is_inbound_audio_kind((string) ($parsed['type'] ?? '')),
                    ]);
                }
            } else {
                $this->handle_lead_message($parsed, $payload);
                Log::channel('daily')->info('WhatsApp webhook: mensaje enrutado a lead.', [
                    'from'       => $parsed['from'],
                    'type'       => $parsed['type'],
                    'route'      => 'lead',
                    'is_audio'   => $this->is_inbound_audio_kind((string) ($parsed['type'] ?? '')),
                    'has_media'  => ! empty($parsed['inbound_media']),
                    'message_id' => $parsed['message_id'],
                ]);
            }
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsApp webhook: error al procesar mensaje.', [
                'from'      => $parsed['from'],
                'type'      => $parsed['type'],
                'message_id' => $parsed['message_id'],
                'error'     => $exception->getMessage(),
            ]);
        }

        return response()->json(['ok' => true], 200);
    }

    /**
     * Procesa un evento de estado de entrega de un mensaje saliente (entregado / leído / fallido).
     *
     * Correlaciona el wamid del payload con lead_messages.whatsapp_message_id.
     * Si no existe ningún LeadMessage con ese wamid (p.ej. es de soporte o implementación),
     * retorna sin hacer nada — es un caso esperado, no un error.
     * Tras actualizar el mensaje dispara LeadConversationUpdated con is_status_update = true
     * para que el frontend refresque solo el estado visual del bubble, sin tocar badges.
     *
     * @param string               $event_type Nombre del evento Kapso (clave de STATUS_EVENTS).
     * @param array<string, mixed> $payload    Body JSON decodificado del webhook.
     *
     * @return void
     */
    private function handle_outbound_status_event(string $event_type, array $payload): void
    {
        try {
            // Extraer el wamid de Meta que identifica el mensaje saliente.
            // NOTA (2/7/2026): el payload real de Kapso para eventos de estado NO trae
            // 'whatsapp_message_id' (ese campo solo aparece en la documentación de ejemplo).
            // El wamid real viaja en 'message.id', igual que en los mensajes entrantes
            // (ver parse_inbound_message(), que ya lee $message['id']).
            $wamid = $payload['message']['id'] ?? null;
            if ($wamid === null || $wamid === '') {
                Log::channel('daily')->warning('WhatsApp webhook: evento de estado sin whatsapp_message_id.', [
                    'event_type' => $event_type,
                ]);
                return;
            }

            // Buscar el mensaje saliente de lead que corresponde a ese wamid.
            // Si no existe (p.ej. es de soporte o implementación), ignorar silenciosamente.
            $message = LeadMessage::query()->where('whatsapp_message_id', $wamid)->first();
            if ($message === null) {
                return;
            }

            // Resolver el estado interno mapeado al evento recibido.
            $status = self::STATUS_EVENTS[$event_type];

            // El estado 'enviado' se infiere por la presencia de whatsapp_message_id; no persistir.
            if ($status === 'enviado') {
                return;
            }

            // Campos a actualizar según el tipo de evento de entrega.
            if ($status === 'entregado') {
                $message->update([
                    'whatsapp_delivery_status' => 'entregado',
                    'whatsapp_delivered_at'    => now(),
                ]);
            } elseif ($status === 'leido') {
                // Un mensaje leído siempre fue entregado. Si el evento delivered no llegó antes,
                // completar whatsapp_delivered_at en el mismo update para mantener consistencia.
                $updates = [
                    'whatsapp_delivery_status' => 'leido',
                    'whatsapp_seen_at'         => now(),
                ];
                if ($message->whatsapp_delivered_at === null) {
                    $updates['whatsapp_delivered_at'] = now();
                }
                $message->update($updates);
            } elseif ($status === 'fallido') {
                $message->update([
                    'whatsapp_delivery_status' => 'fallido',
                ]);
            }

            // Notificar al frontend del cambio de estado (is_status_update = true para evitar
            // refresco de badges y fila de grilla — solo actualizar el bubble del mensaje).
            event(new \App\Events\LeadConversationUpdated((int) $message->lead_id, (int) $message->id, true));
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsApp webhook: error al procesar evento de estado de entrega.', [
                'event_type' => $event_type,
                'error'      => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Verifica la firma HMAC-SHA256 del body crudo contra el secreto configurado.
     *
     * @param Request        $request
     * @param WhatsappConfig $config
     *
     * @return bool
     */
    private function verify_signature(Request $request, WhatsappConfig $config): bool
    {
        // Kapso documenta X-Webhook-Signature; se acepta también X-Kapso-Signature.
        $signature = (string) ($request->header('X-Kapso-Signature') ?: $request->header('X-Webhook-Signature'));
        if ($signature === '') {
            return false;
        }

        $signature = str_replace('sha256=', '', $signature);
        $raw_body = $request->getContent();
        $expected = hash_hmac('sha256', $raw_body, $config->webhook_secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Resuelve el tipo de evento desde header o payload.
     *
     * @param Request              $request
     * @param array<string, mixed> $payload
     *
     * @return string|null
     */
    private function resolve_event_type(Request $request, array $payload): ?string
    {
        $header_event = $request->header('X-Webhook-Event');
        if ($header_event !== null && $header_event !== '') {
            return (string) $header_event;
        }

        if (isset($payload['event']) && is_string($payload['event'])) {
            return $payload['event'];
        }

        if (isset($payload['message']) && is_array($payload['message'])) {
            return self::INBOUND_EVENT;
        }

        return null;
    }

    /**
     * Extrae campos relevantes del payload Kapso v2 para mensajes entrantes.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|null
     */
    private function parse_inbound_message(array $payload): ?array
    {
        $message = $payload['message'] ?? null;
        if (! is_array($message)) {
            return null;
        }

        // Remitente: prioriza message.from; fallback a conversation.phone_number.
        $from_raw = $message['from'] ?? null;
        if (($from_raw === null || $from_raw === '') && isset($payload['conversation']['phone_number'])) {
            $from_raw = $payload['conversation']['phone_number'];
        }

        $message_id = $message['id'] ?? null;
        if ($from_raw === null || $from_raw === '' || $message_id === null || $message_id === '') {
            return null;
        }

        $raw_type = isset($message['type']) ? (string) $message['type'] : 'text';
        $raw_type = $this->resolve_inbound_message_type($message, $raw_type);
        $body = $this->extract_message_body($message, $raw_type);

        $inbound_media_service = new WhatsappInboundMediaService();
        $inbound_media = $inbound_media_service->extract_inbound_media($message, $raw_type);
        if ($inbound_media === null && in_array($raw_type, ['ptt', 'voice'], true)) {
            $inbound_media = $inbound_media_service->extract_inbound_media($message, 'audio');
        }
        if ($inbound_media === null && $this->message_has_kapso_audio_transcript($message)) {
            $inbound_media = $inbound_media_service->extract_inbound_media($message, 'audio');
        }

        $type = $this->normalize_whatsapp_message_kind($raw_type);

        $timestamp = $message['timestamp'] ?? null;

        $contact_name = null;
        if (isset($payload['conversation']['kapso']['contact_name'])) {
            $contact_name = (string) $payload['conversation']['kapso']['contact_name'];
        }

        $kapso_content = null;
        if (isset($message['kapso']['content'])) {
            $kapso_content = trim((string) $message['kapso']['content']);
            if ($kapso_content === '') {
                $kapso_content = null;
            }
        }

        if ($inbound_media === null && $type === 'audio' && $kapso_content !== null) {
            $inbound_media = $inbound_media_service->extract_inbound_media_from_kapso_content($kapso_content);
        }

        return [
            'from'          => WhatsappNormalizer::normalize((string) $from_raw),
            'message_id'    => (string) $message_id,
            'type'          => $type,
            'body'          => $body,
            'inbound_media' => $inbound_media,
            'kapso_content' => $kapso_content,
            'timestamp'     => $timestamp,
            'contact_name'  => $contact_name,
        ];
    }

    /**
     * Obtiene texto del mensaje según tipo (texto, audio con transcripción Kapso, media con caption, kapso.content).
     *
     * @param array<string, mixed> $message
     * @param string               $type
     *
     * @return string|null
     */
    private function extract_message_body(array $message, string $type): ?string
    {
        if ($type === 'text' && isset($message['text']['body'])) {
            $text_body = trim((string) $message['text']['body']);

            return $text_body === '' ? null : $text_body;
        }

        // Audio / nota de voz: transcripción en message.kapso.transcript.text.
        if ($type === 'audio' || $type === 'ptt' || $type === 'voice') {
            return $this->extract_audio_body($message);
        }

        // Imagen / documento / video: solo caption; el archivo se guarda como adjunto (no kapso.content legado).
        if (in_array($type, ['image', 'document', 'video'], true)) {
            return $this->extract_media_caption_body($message, $type);
        }

        if (isset($message['kapso']['content'])) {
            $kapso_content = trim((string) $message['kapso']['content']);

            return $kapso_content === '' ? null : $kapso_content;
        }

        $media_keys = ['image', 'video', 'document', 'audio'];
        foreach ($media_keys as $media_key) {
            if (isset($message[$media_key]['caption'])) {
                $caption = trim((string) $message[$media_key]['caption']);
                if ($caption !== '') {
                    return $caption;
                }
            }
        }

        return null;
    }

    /**
     * Resuelve el cuerpo de un mensaje de voz usando la transcripción de Kapso.
     *
     * @param array<string, mixed> $message Nodo message del payload Kapso.
     *
     * @return string Texto transcripto o placeholder si Kapso no envió transcripción.
     */
    private function extract_audio_body(array $message): string
    {
        $transcript_text = '';
        if (isset($message['kapso']['transcript']['text'])) {
            $transcript_text = trim((string) $message['kapso']['transcript']['text']);
        }

        if ($transcript_text !== '') {
            return $transcript_text;
        }

        return '[Audio sin transcripción]';
    }

    /**
     * Texto visible para mensajes multimedia con adjunto: únicamente el caption de WhatsApp si existe.
     *
     * @param array<string, mixed> $message
     * @param string               $type    image | document | video
     *
     * @return string|null
     */
    private function extract_media_caption_body(array $message, string $type): ?string
    {
        if (isset($message[$type]['caption'])) {
            $caption = trim((string) $message[$type]['caption']);
            if ($caption !== '') {
                return $caption;
            }
        }

        return null;
    }

    /**
     * Comprueba idempotencia en support_messages, lead_messages e implementation_messages.
     *
     * @param string $message_id ID de Meta.
     *
     * @return bool
     */
    private function is_duplicate_message(string $message_id): bool
    {
        if (SupportMessage::where('whatsapp_message_id', $message_id)->exists()) {
            return true;
        }

        if (LeadMessage::where('whatsapp_message_id', $message_id)->exists()) {
            return true;
        }

        if (ImplementationMessage::where('whatsapp_message_id', $message_id)->exists()) {
            return true;
        }

        if (EcommerceImplementationMessage::where('whatsapp_message_id', $message_id)->exists()) {
            return true;
        }

        return false;
    }

    /**
     * Guarda el mensaje entrante en implementation_messages y delega al servicio de conversación.
     *
     * @param array<string, mixed> $parsed         Resultado de parse_inbound_message.
     * @param Client               $client         Cliente dueño de la implementación.
     * @param Implementation       $implementation Implementación activa del cliente.
     *
     * @return void
     */
    private function handle_implementation_message(
        array $parsed,
        Client $client,
        Implementation $implementation
    ): void {
        // Persistir el mensaje entrante para trazabilidad (idempotencia garantizada por is_duplicate_message).
        $body = $parsed['body'];
        $message_type = (string) ($parsed['type'] ?? 'text');

        // Fallback de cuerpo para mensajes sin texto (imagen, audio, etc.).
        if ($body === null || trim($body) === '') {
            $body = '[' . strtoupper($message_type) . ' recibido]';
        }

        $inbound_message = ImplementationMessage::create([
            'implementation_id'   => $implementation->id,
            'stage_number'        => (int) $implementation->current_stage,
            'direction'           => 'inbound',
            'body'                => $body,
            'whatsapp_message_id' => $parsed['message_id'],
            'sent_at'             => $this->resolve_message_datetime($parsed['timestamp']),
        ]);

        ImplementationBroadcastService::emit_message_received(
            (int) $implementation->id,
            (int) $inbound_message->id
        );

        // Delegar el procesamiento de la conversación al servicio correspondiente.
        $service = new ImplementationConversationService();
        $service->handle($implementation, $parsed);
    }

    /**
     * Guarda el mensaje entrante en ecommerce_implementation_messages y delega al servicio
     * de conversación de la implementación de ecommerce.
     *
     * @param array<string, mixed>    $parsed         Resultado de parse_inbound_message.
     * @param Client                  $client         Cliente dueño de la implementación.
     * @param EcommerceImplementation $implementation Implementación de ecommerce activa del cliente.
     *
     * @return void
     */
    private function handle_ecommerce_implementation_message(
        array $parsed,
        Client $client,
        EcommerceImplementation $implementation
    ): void {
        // Persistir el mensaje entrante para trazabilidad (idempotencia garantizada por is_duplicate_message).
        $body         = $parsed['body'];
        $message_type = (string) ($parsed['type'] ?? 'text');

        // Fallback de cuerpo para mensajes sin texto (imagen, audio, etc.).
        if ($body === null || trim($body) === '') {
            $body = '[' . strtoupper($message_type) . ' recibido]';
        }

        $inbound_message = EcommerceImplementationMessage::create([
            'ecommerce_implementation_id' => $implementation->id,
            'stage_number'                => (int) $implementation->current_stage,
            'direction'                   => 'inbound',
            'body'                        => $body,
            'whatsapp_message_id'         => $parsed['message_id'],
            'sent_at'                     => $this->resolve_message_datetime($parsed['timestamp']),
        ]);

        EcommerceImplementationBroadcastService::emit_message_received(
            (int) $implementation->id,
            (int) $inbound_message->id
        );

        // Delegar el procesamiento de la conversación al servicio de ecommerce.
        $service = new EcommerceImplementationConversationService();
        $service->handle($implementation, $parsed);
    }

    /**
     * Resuelve cliente y empleado (si aplica) a partir del teléfono del remitente.
     *
     * Prioriza empleados registrados; luego dueño del cliente o lead promovido.
     *
     * @param string $phone Teléfono E.164 normalizado.
     *
     * @return array{client: Client, client_employee: ClientEmployee|null}|null
     */
    private function find_support_contact_by_phone(string $phone): ?array
    {
        $client_employees = ClientEmployee::query()
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->with('client')
            ->get();

        foreach ($client_employees as $client_employee) {
            if (! WhatsappNormalizer::phones_match((string) $client_employee->phone, $phone)) {
                continue;
            }

            $client = $client_employee->client;
            if ($client && $client->is_active) {
                return [
                    'client'           => $client,
                    'client_employee'  => $client_employee,
                ];
            }
        }

        $client = $this->find_client_by_phone($phone);
        if ($client === null) {
            return null;
        }

        return [
            'client'          => $client,
            'client_employee' => null,
        ];
    }

    /**
     * Busca un cliente activo cuyo teléfono coincida con el remitente.
     *
     * @param string $phone Teléfono E.164 normalizado.
     *
     * @return Client|null
     */
    private function find_client_by_phone(string $phone): ?Client
    {
        $active_clients = Client::where('is_active', true)->get();
        foreach ($active_clients as $client) {
            if (! empty($client->phone) && WhatsappNormalizer::phones_match((string) $client->phone, $phone)) {
                return $client;
            }
        }

        // Fallback: lead promovido cuyo teléfono coincide (client.phone puede no estar cargado aún).
        $promoted_leads = Lead::whereNotNull('promoted_client_id')
            ->whereNotNull('phone')
            ->get();

        foreach ($promoted_leads as $lead) {
            if (! WhatsappNormalizer::phones_match((string) $lead->phone, $phone)) {
                continue;
            }

            $client = Client::where('is_active', true)->find($lead->promoted_client_id);
            if ($client) {
                return $client;
            }
        }

        return null;
    }

    /**
     * Busca un lead existente por teléfono (el más reciente si hay varios).
     *
     * @param string $phone Teléfono E.164 normalizado.
     *
     * @return Lead|null
     */
    private function find_lead_by_phone(string $phone): ?Lead
    {
        $leads = Lead::whereNotNull('phone')->orderBy('id', 'desc')->get();
        foreach ($leads as $lead) {
            if (WhatsappNormalizer::phones_match((string) $lead->phone, $phone)) {
                return $lead;
            }
        }

        return null;
    }

    /**
     * Crea o actualiza ticket de soporte WhatsApp y persiste el mensaje entrante.
     *
     * @param array<string, mixed>           $parsed
     * @param Client                         $client
     * @param ClientEmployee|null              $client_employee
     * @param SupportTicketAssignmentService $assignment_service
     *
     * @return void
     */
    private function handle_support_message(
        array $parsed,
        Client $client,
        ?ClientEmployee $client_employee,
        SupportTicketAssignmentService $assignment_service
    ): void {
        // NUEVO — interceptar el canal "sistema:" antes de crear ticket de soporte.
        // Solo aplica a clientes activos (este método ya está dentro de esa rama); los leads
        // se enrutan por handle_lead_message y nunca llegan acá.
        $body = (string) ($parsed['body'] ?? '');
        if (SistemaQueryService::is_sistema_query($body)) {
            $config = WhatsappConfig::getActive();

            if (SistemaQueryService::client_employee_can_query($client, $client_employee)) {
                // Remitente autorizado: resolver la consulta y responder por WhatsApp.
                if ($config) {
                    (new SistemaQueryService())->handle(
                        $body,
                        $client,
                        $client_employee,
                        $config,
                        (string) $parsed['from']
                    );
                }
            } else {
                // Empleado sin permiso: responder con mensaje claro de denegación.
                if ($config) {
                    (new WhatsappSendService())->send_text(
                        (string) $parsed['from'],
                        'No tenés permiso para consultar el sistema por este medio. Pedile al dueño que lo active.'
                    );
                }
            }

            // No crear ticket de soporte para mensajes del canal sistema.
            return;
        }
        // FIN interceptación canal "sistema:".

        $ticket_for_ai_dispatch = null;
        $inbound_message_id = null;

        DB::transaction(function () use (
            $parsed,
            $client,
            $client_employee,
            $assignment_service,
            &$ticket_for_ai_dispatch,
            &$inbound_message_id
        ) {
            $normalized_phone = $parsed['from'];
            $delivered_at = $this->resolve_message_datetime($parsed['timestamp']);

            $ticket_query = SupportTicket::where('client_id', $client->id)
                ->where('source', 'whatsapp')
                ->where('whatsapp_phone', $normalized_phone)
                ->where('status', 'open');

            if ($client_employee !== null) {
                $ticket_query->where('client_employee_id', $client_employee->id);
            } else {
                $ticket_query->whereNull('client_employee_id');
            }

            $ticket = $ticket_query->first();

            if ($ticket === null) {
                $assigned_admin_id = $assignment_service->resolve_assigned_admin_id($client);
                $contact_name = $this->resolve_support_contact_name($client, $client_employee, $parsed);

                $ticket = SupportTicket::create([
                    'client_id'           => $client->id,
                    'client_employee_id'  => $client_employee ? $client_employee->id : null,
                    'client_user_id'      => 0,
                    'client_user_name'    => $contact_name !== '' ? $contact_name : null,
                    'assigned_admin_id'   => $assigned_admin_id,
                    // Sin título inicial: Claude lo sugiere en la primera Sugerencia IA.
                    'name'                => null,
                    'status'              => 'open',
                    'source'              => 'whatsapp',
                    'whatsapp_phone'      => $normalized_phone,
                    'opened_at'           => now(),
                ]);
            } else {
                $contact_name = $this->resolve_support_contact_name($client, $client_employee, $parsed);
                if ($contact_name !== '' && empty($ticket->client_user_name)) {
                    $ticket->client_user_name = $contact_name;
                    $ticket->save();
                }
            }

            $kind = substr((string) $parsed['type'], 0, 20);

            $message = SupportMessage::create([
                'support_ticket_id'   => $ticket->id,
                'sender_type'         => 'user',
                'kind'                => $kind,
                'body'                => $parsed['body'],
                'whatsapp_message_id' => $parsed['message_id'],
                'delivered_at'        => $delivered_at,
            ]);

            if ($kind === 'image') {
                $this->persist_inbound_whatsapp_image($message, (int) $ticket->id, $parsed);
            } elseif (! empty($parsed['inbound_media']) && is_array($parsed['inbound_media'])) {
                $inbound_media_service = new WhatsappInboundMediaService();
                try {
                    $inbound_media_service->persist_support_attachment(
                        $message,
                        (int) $ticket->id,
                        $parsed['inbound_media']
                    );
                } catch (\Throwable $exception) {
                    Log::channel('daily')->error('WhatsApp webhook: error al guardar adjunto.', [
                        'message_id' => $message->id,
                        'error'      => $exception->getMessage(),
                    ]);
                }
            }

            $ticket->last_client_message_at = $delivered_at;
            $ticket->save();

            $ticket_for_ai_dispatch = $ticket;
            $inbound_message_id = (int) $message->id;
        });

        if ($inbound_message_id !== null) {
            event(new SupportMessageReceived($inbound_message_id));
        }

        if ($ticket_for_ai_dispatch !== null) {
            $this->dispatch_ai_suggestion_if_enabled($ticket_for_ai_dispatch);
        }
    }

    /**
     * Encola generación de sugerencia IA si la configuración global está activa.
     *
     * @param SupportTicket $ticket Ticket recién actualizado con mensaje del cliente.
     *
     * @return void
     */
    private function dispatch_ai_suggestion_if_enabled(SupportTicket $ticket): void
    {
        if (! SupportAiSettings::is_suggestions_enabled()) {
            return;
        }

        (new SupportAiSuggestionScheduler())->schedule_after_client_inbound((int) $ticket->id);
    }

    /**
     * Descarga imagen entrante de WhatsApp y define body visible si no hay miniatura local.
     *
     * @param SupportMessage       $message
     * @param int                    $ticket_id
     * @param array<string, mixed> $parsed
     *
     * @return void
     */
    private function persist_inbound_whatsapp_image(SupportMessage $message, int $ticket_id, array $parsed): void
    {
        $inbound_media_service = new WhatsappInboundMediaService();
        $stored = false;

        $inbound_media = $parsed['inbound_media'] ?? null;
        if ((! is_array($inbound_media) || empty($inbound_media)) && ! empty($parsed['kapso_content'])) {
            $inbound_media = $inbound_media_service->extract_inbound_media_from_kapso_content(
                (string) $parsed['kapso_content']
            );
        }

        if (is_array($inbound_media) && ! empty($inbound_media)) {
            try {
                $stored = $inbound_media_service->persist_support_attachment(
                    $message,
                    $ticket_id,
                    $inbound_media
                );
            } catch (\Throwable $exception) {
                Log::channel('daily')->error('WhatsApp webhook: error al guardar imagen.', [
                    'message_id' => $message->id,
                    'error'      => $exception->getMessage(),
                ]);
            }
        }

        if ($stored) {
            return;
        }

        $caption = trim((string) ($parsed['body'] ?? ''));
        if ($caption !== '') {
            return;
        }

        $fallback_body = $inbound_media_service->build_image_fallback_body($parsed['kapso_content'] ?? null);
        $message->body = $fallback_body;
        $message->save();
    }

    /**
     * Integra mensaje entrante con el flujo de leads existente (historial + sugerencia IA).
     *
     * Crea el lead si no existe, envía bienvenida y programa presentación en primer contacto WhatsApp.
     *
     * @param array<string, mixed> $parsed
     * @param array<string, mixed> $payload Body JSON del webhook (contacts.profile.name).
     * @return void
     */
    private function handle_lead_message(array $parsed, array $payload): void
    {
        $onboarding_service = new LeadWhatsappOnboardingService();
        $lead = $this->find_lead_by_phone($parsed['from']);
        $display_name = $onboarding_service->resolve_display_name($parsed, $payload, $lead);

        if ($lead === null) {
            $lead = Lead::create([
                'phone'        => $parsed['from'],
                'contact_name' => $display_name,
                'status'       => 'nuevo',
            ]);

            // Documento aleatorio de 5 dígitos (contraseña demo y setup remoto).
            LeadDocNumberGenerator::assign_to_lead_if_empty($lead);
        } elseif ($display_name !== null && empty($lead->contact_name)) {
            $lead->contact_name = $display_name;
            $lead->save();
        } elseif (! empty($parsed['contact_name']) && empty($lead->contact_name)) {
            $contact_name = trim((string) $parsed['contact_name']);
            if ($contact_name !== '') {
                $lead->contact_name = $contact_name;
                $lead->save();
            }
        }

        // Evaluar onboarding antes de persistir el inbound (historial vacío = primer contacto).
        $run_onboarding = $onboarding_service->should_run_onboarding($lead);

        // Tipo normalizado (ptt/voice → audio) alineado a tickets de soporte.
        $kind = $this->normalize_whatsapp_message_kind((string) $parsed['type']);

        $content = $parsed['body'];
        if ($content === null || trim($content) === '') {
            if ($kind === 'audio') {
                $content = '[Audio sin transcripción]';
            } else {
                $content = '[' . strtoupper((string) $parsed['type']) . ' recibido por WhatsApp]';
            }
        }

        // 1) Mensaje del lead en la conversación (cronológicamente primero).
        $inbound_lead_message = LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'lead',
            'kind'                  => $kind,
            'content'               => $content,
            'status'                => 'enviado',
            'is_followup'           => false,
            'requiere_verificacion' => false,
            'whatsapp_message_id'   => $parsed['message_id'],
            'sent_at'               => $this->resolve_message_datetime($parsed['timestamp']),
        ]);

        if ($kind === 'audio') {
            $lead_audio_service = new LeadWhatsappInboundAudioService();
            $lead_audio_service->process_inbound($lead, $inbound_lead_message, $parsed, $payload);
        } elseif (! empty($parsed['inbound_media']) && is_array($parsed['inbound_media'])) {
            $inbound_media_service = new WhatsappInboundMediaService();
            try {
                $inbound_media_service->persist_lead_attachment(
                    $inbound_lead_message,
                    (int) $lead->id,
                    $parsed['inbound_media']
                );
            } catch (\Throwable $exception) {
                Log::channel('daily')->error('WhatsApp webhook: error al guardar adjunto de lead.', [
                    'lead_id'    => $lead->id,
                    'message_id' => $inbound_lead_message->id,
                    'error'      => $exception->getMessage(),
                ]);
            }
        }

        $inbound_lead_message = LeadMessage::query()
            ->with('attachments')
            ->find($inbound_lead_message->id);

        LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $inbound_lead_message->id);

        /*
         * FIX (1/7/2026): la condición anterior (`prev_lead_msgs === 0`, es decir "es el
         * primer mensaje de toda la conversación") nunca se cumplía en la práctica.
         * `welcome_variant_id` recién se asigna al lead cuando se envía el mensaje de welcome
         * (con delay, después del primer mensaje del lead) — así que en el primer mensaje del
         * lead el `if` de afuera no entra (welcome_variant_id todavía null), y en el segundo
         * mensaje del lead (la respuesta real al welcome) `prev_lead_msgs` ya vale 1, no 0.
         * Resultado: responded_count quedaba en 0 siempre. La medición correcta es "primera
         * respuesta del lead que llega DESPUÉS del mensaje de welcome", no "primer mensaje de
         * toda la conversación".
         */
        if ($lead->welcome_variant_id) {
            $welcome_message = LeadMessage::where('lead_id', $lead->id)
                ->where('system_message_kind', 'whatsapp_welcome')
                ->orderBy('id')
                ->first();

            if ($welcome_message) {
                $lead_msgs_after_welcome = LeadMessage::where('lead_id', $lead->id)
                    ->where('sender', 'lead')
                    ->where('id', '>', (int) $welcome_message->id)
                    ->where('id', '<', (int) $inbound_lead_message->id)
                    ->count();

                if ($lead_msgs_after_welcome === 0) {
                    $ab_variant = \App\Models\MessageVariant::find($lead->welcome_variant_id);
                    if ($ab_variant) {
                        $ab_variant->increment_responded();
                    }
                }
            }
        }

        // Notificación WhatsApp a todos los admins suscritos a este lead.
        (new \App\Services\LeadMessageNotificationWhatsappService(
            new \App\Services\WhatsappSendService()
        ))->notify($lead, $content);

        // 2) Bienvenida inmediata + job de presentación (segundo en el hilo; cada envío emite su propio broadcast).
        if ($run_onboarding) {
            $onboarding_service->send_welcome_and_schedule_presentation($lead, $display_name);
        }

        // Detectar confirmaciones de la demo (ingreso / fin) ANTES de pedir sugerencia IA.
        // Si se detecta una confirmación de fin, la IA no debe generar sugerencias sobre ese mensaje.
        $this->handle_demo_confirmation_if_needed($lead, (string) $content);

        // Sugerencia IA con debounce: espera configurable tras el último inbound (2º mensaje o más).
        (new LeadAiSuggestionScheduler())->schedule_after_lead_inbound((int) $lead->id);
    }

    /**
     * Detecta confirmaciones del lead durante la demo y avanza el flujo automático.
     *
     * Dos casos según el estado de los flags de demo:
     *   - Caso A: ya se envió el check de ingreso pero el ingreso aún no fue confirmado.
     *     Si el mensaje confirma el ingreso, setea demo_ingreso_confirmado. Si no, envía
     *     ayuda con link, usuario y contraseña para que pueda entrar.
     *   - Caso B: ya se envió el check de fin y el ingreso estaba confirmado. Si el mensaje
     *     confirma que terminó, transiciona el lead a demo_realizada y notifica admins/closer.
     *
     * @param Lead   $lead    Lead remitente del mensaje entrante.
     * @param string $content Contenido textual del mensaje entrante.
     *
     * @return void
     */
    private function handle_demo_confirmation_if_needed(Lead $lead, string $content): void
    {
        // Estados en los que aplica el procesamiento automático del ciclo de demo.
        $estados_ciclo = ['demo_agendada', 'ingresando_demo', 'demo_en_curso', 'demo_pendiente_de_terminar'];
        if (! in_array((string) $lead->status, $estados_ciclo, true)) {
            return;
        }

        // Normalizar el contenido para la búsqueda de palabras clave.
        $content_lower = mb_strtolower(trim($content));

        // Caso A: check de ingreso enviado, ingreso aún no confirmado.
        // El lead puede estar en demo_agendada o ingresando_demo en este punto.
        if ($lead->demo_check_ingreso_enviado && ! $lead->demo_ingreso_confirmado) {
            if ($this->content_confirms_ingress($content_lower)) {
                $lead->demo_ingreso_confirmado    = true;
                $lead->demo_ingreso_confirmado_at = now('America/Argentina/Buenos_Aires');
                $lead->status                     = 'demo_en_curso';
                $lead->save();

                // Incrementar attended_count en la variante A/B al confirmar ingreso.
                if ($lead->welcome_variant_id) {
                    $ab_variant_att = \App\Models\MessageVariant::find($lead->welcome_variant_id);
                    if ($ab_variant_att) {
                        $ab_variant_att->increment_attended();
                    }
                }

                // Notificar a admins suscritos que el lead confirmó el ingreso.
                try {
                    $ciclo_service = new \App\Services\DemoCicloAdminNotificationService(
                        new \App\Services\WhatsappSendService()
                    );
                    $ciclo_service->notify_ingreso_confirmado($lead->fresh());
                } catch (\Throwable $e) {
                    Log::error('WhatsappWebhookController: error al notificar ingreso_confirmado.', [
                        'lead_id' => $lead->id,
                        'error'   => $e->getMessage(),
                    ]);
                }

                LeadBroadcastService::emit_conversation_updated((int) $lead->id);
                return;
            }
            // No pudo entrar: enviar ayuda con link, doc y contraseña.
            $this->send_demo_access_help($lead);
            return;
        }

        // Caso B: check de fin enviado, esperando confirmación de fin.
        // Solo si el ingreso ya estaba confirmado.
        if ($lead->demo_fin_check_enviado && $lead->demo_ingreso_confirmado) {
            if ($this->content_confirms_demo_done($content_lower)) {
                $this->mark_demo_realizada($lead);
            }
            return;
        }
    }

    /**
     * Indica si el contenido del mensaje confirma que el lead pudo ingresar a la demo.
     *
     * @param string $lower Contenido del mensaje en minúsculas y sin espacios extremos.
     *
     * @return bool true si alguna palabra clave de confirmación de ingreso está presente.
     */
    private function content_confirms_ingress(string $lower): bool
    {
        // Palabras clave de confirmación positiva de ingreso.
        $keywords = ['sí', 'si', 'pude', 'entré', 'entre', 'ingresé', 'ingrese', 'ya estoy', 'adentro', 'dentro', 'ok', 'perfecto', 'genial', 'listo'];
        foreach ($keywords as $kw) {
            if (strpos($lower, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Indica si el contenido del mensaje confirma que el lead terminó de recorrer la demo.
     *
     * @param string $lower Contenido del mensaje en minúsculas y sin espacios extremos.
     *
     * @return bool true si alguna palabra clave de confirmación de fin está presente.
     */
    private function content_confirms_demo_done(string $lower): bool
    {
        // Palabras clave de confirmación de fin de la demo.
        $keywords = ['sí', 'si', 'terminé', 'termine', 'pude', 'listo', 'ya', 'recorrí', 'recorri', 'vi todo', 'la vi', 'completa', 'ok', 'perfecto'];
        foreach ($keywords as $kw) {
            if (strpos($lower, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Envía al lead los datos de acceso a la demo cuando no pudo ingresar.
     *
     * Incluye link, usuario y contraseña (ambos = número de documento del lead) y
     * registra el mensaje en la conversación para trazabilidad.
     *
     * @param Lead $lead Lead que no pudo ingresar a la demo.
     *
     * @return void
     */
    private function send_demo_access_help(Lead $lead): void
    {
        // Documento del lead: usuario y contraseña de la demo.
        $doc = (string) ($lead->doc_number ?? '');
        // URL de la demo: config si existe, fallback hardcodeado en caso contrario.
        $demo_url = config('services.demo_url', 'https://demo.comerciocity.com');
        $msg = "No te preocupes, te paso los datos para ingresar:\n\n"
            . "🔗 Link: {$demo_url}\n"
            . "👤 Usuario: {$doc}\n"
            . "🔑 Contraseña: {$doc}\n\n"
            . "Probá de nuevo y avisame cuando puedas entrar 👋";

        app(WhatsappSendService::class)->send_text((string) $lead->phone, $msg);

        LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'sistema',
            'content'               => $msg,
            'status'                => 'enviado',
            'is_followup'           => false,
            'requiere_verificacion' => false,
        ]);

        LeadBroadcastService::emit_conversation_updated((int) $lead->id);
    }

    /**
     * Transiciona el lead a `demo_realizada` y dispara las notificaciones de cierre.
     *
     * Registra un mensaje de sistema en la conversación, notifica a todos los admins
     * con notify_lead_escalation_whatsapp = true (incluido el closer si tiene el flag)
     * y dispara la notificación específica del closer.
     *
     * @param Lead $lead Lead que confirmó que terminó la demo.
     *
     * @return void
     */
    private function mark_demo_realizada(Lead $lead): void
    {
        $lead->status = 'demo_realizada';
        $lead->save();

        // Registrar mensaje de sistema en la conversación.
        LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'sistema',
            'content'               => '✅ Demo completada. Estado actualizado a Demo realizada.',
            'status'                => 'enviado',
            'is_followup'           => false,
            'requiere_verificacion' => false,
        ]);

        LeadBroadcastService::emit_conversation_updated((int) $lead->id);

        // Notificar a todos los admins con notify_lead_escalation_whatsapp = true
        // (esto incluye al closer si tiene ese flag activo).
        $motivo = 'El lead confirmó que terminó la demo. Listo para la llamada de cierre.';
        app(LeadEscalationWhatsappService::class)->notify($lead, $motivo);

        // Notificación específica al closer (usa closer_notified_at como anti-duplicado).
        app(CloserNotificationService::class)->notify_for_lead($lead);
    }

    /**
     * Ajusta el type del payload cuando Kapso manda transcript pero type distinto de audio.
     *
     * @param array<string, mixed> $message
     * @param string               $raw_type
     *
     * @return string
     */
    private function resolve_inbound_message_type(array $message, string $raw_type): string
    {
        if (isset($message['audio']) && is_array($message['audio'])) {
            return 'audio';
        }

        if (isset($message['ptt']) && is_array($message['ptt'])) {
            return 'ptt';
        }

        if ($this->message_has_kapso_audio_transcript($message)) {
            return 'audio';
        }

        $kapso_content = '';
        if (isset($message['kapso']['content'])) {
            $kapso_content = (string) $message['kapso']['content'];
        }
        if ($kapso_content !== '' && preg_match('/audio\s+attached/i', $kapso_content)) {
            return 'audio';
        }

        return $raw_type;
    }

    /**
     * Indica si el kind normalizado corresponde a audio / nota de voz.
     *
     * @param string $kind
     *
     * @return bool
     */
    private function is_inbound_audio_kind(string $kind): bool
    {
        $normalized = strtolower(trim($kind));

        return in_array($normalized, ['audio', 'ptt', 'voice'], true);
    }

    /**
     * true si Kapso adjuntó transcripción de nota de voz.
     *
     * @param array<string, mixed> $message
     *
     * @return bool
     */
    private function message_has_kapso_audio_transcript(array $message): bool
    {
        if (! isset($message['kapso']) || ! is_array($message['kapso'])) {
            return false;
        }

        $kapso = $message['kapso'];
        if (! isset($kapso['transcript']) || ! is_array($kapso['transcript'])) {
            return false;
        }

        $text = isset($kapso['transcript']['text']) ? trim((string) $kapso['transcript']['text']) : '';

        return $text !== '';
    }

    /**
     * Unifica tipos de voz de WhatsApp/Kapso al kind persistido `audio`.
     *
     * @param string $type Tipo crudo del payload (audio, ptt, voice, text, …).
     *
     * @return string
     */
    private function normalize_whatsapp_message_kind(string $type): string
    {
        $normalized = strtolower(trim($type));
        if (in_array($normalized, ['ptt', 'voice'], true)) {
            return 'audio';
        }

        return substr($normalized !== '' ? $normalized : 'text', 0, 20);
    }

    /**
     * Convierte timestamp unix de Meta a Carbon; fallback a now().
     *
     * @param mixed $timestamp Valor crudo del payload.
     *
     * @return \Carbon\Carbon
     */
    private function resolve_message_datetime($timestamp): Carbon
    {
        if ($timestamp !== null && $timestamp !== '' && is_numeric($timestamp)) {
            return Carbon::createFromTimestamp((int) $timestamp);
        }

        return now();
    }

    /**
     * Nombre visible del contacto remoto en soporte.
     * Dueño: nombre del cliente; empleado: nombre del empleado.
     *
     * @param Client              $client
     * @param ClientEmployee|null $client_employee
     * @param array<string,mixed> $parsed
     *
     * @return string
     */
    private function resolve_support_contact_name(Client $client, ?ClientEmployee $client_employee, array $parsed): string
    {
        if ($client_employee !== null) {
            return trim((string) ($client_employee->name ?? ''));
        }

        $client_name = $client->resolve_display_name();
        if ($client_name !== '') {
            return $client_name;
        }

        return trim((string) ($parsed['contact_name'] ?? ''));
    }
}
