<?php

namespace App\Http\Controllers;

use App\Events\SupportMessageReceived;
use App\Helpers\WhatsappNormalizer;
use App\Services\SupportAiSettings;
use App\Services\SupportAiSuggestionScheduler;
use App\Models\Client;
use App\Models\ClientEmployee;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\WhatsappConfig;
use App\Services\LeadAiSuggestionScheduler;
use App\Services\LeadBroadcastService;
use App\Services\LeadWhatsappInboundAudioService;
use App\Services\LeadWhatsappOnboardingService;
use App\Services\SupportTicketAssignmentService;
use App\Services\WhatsappInboundMediaService;
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
        if ($event_type !== self::INBOUND_EVENT) {
            return response()->json(['ok' => true], 200);
        }

        $parsed = $this->parse_inbound_message($payload);
        if ($parsed === null) {
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
                $this->handle_support_message($parsed, $client, $client_employee, $assignment_service);
                Log::channel('daily')->info('WhatsApp webhook: mensaje enrutado a soporte.', [
                    'from'               => $parsed['from'],
                    'type'               => $parsed['type'],
                    'route'              => 'cliente',
                    'client_id'          => $client->id,
                    'client_employee_id' => $client_employee ? $client_employee->id : null,
                    'is_audio'           => $this->is_inbound_audio_kind((string) ($parsed['type'] ?? '')),
                ]);
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

        // Imagen: solo caption; el archivo se guarda como adjunto (no kapso.content legado).
        if ($type === 'image') {
            return $this->extract_image_body($message);
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
     * Texto visible para mensajes de imagen: únicamente el caption de WhatsApp si existe.
     *
     * @param array<string, mixed> $message
     *
     * @return string|null
     */
    private function extract_image_body(array $message): ?string
    {
        if (isset($message['image']['caption'])) {
            $caption = trim((string) $message['image']['caption']);
            if ($caption !== '') {
                return $caption;
            }
        }

        return null;
    }

    /**
     * Comprueba idempotencia en support_messages y lead_messages.
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

        return false;
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

        // 2) Bienvenida inmediata + job de presentación (segundo en el hilo; cada envío emite su propio broadcast).
        if ($run_onboarding) {
            $onboarding_service->send_welcome_and_schedule_presentation($lead, $display_name);
        }

        // Sugerencia IA con debounce: espera configurable tras el último inbound (2º mensaje o más).
        (new LeadAiSuggestionScheduler())->schedule_after_lead_inbound((int) $lead->id);
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
