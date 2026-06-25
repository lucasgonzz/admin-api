<?php

namespace App\Services;

use App\Helpers\WhatsappNormalizer;
use App\Jobs\SendLeadPresentationMessageJob;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\MessageVariant;
use App\Services\LeadBroadcastService;
use Illuminate\Support\Facades\Log;

/**
 * Mensajes automáticos de bienvenida y presentación para leads nuevos por WhatsApp.
 *
 * Textos y demora configurables desde admin-spa (Cuenta). La idempotencia usa `system_message_kind`
 * en lead_messages, con fallback a fragmentos históricos en el contenido.
 */
class LeadWhatsappOnboardingService
{
    /** Valor en `system_message_kind` para la respuesta automática inmediata. */
    public const KIND_AUTO = 'whatsapp_auto';

    /** Valor en `system_message_kind` para el mensaje de bienvenida diferido. */
    public const KIND_WELCOME = 'whatsapp_welcome';

    /**
     * @var WhatsappSendService Envío saliente vía Kapso.
     */
    private $whatsapp_send_service;

    /**
     * @param WhatsappSendService|null $whatsapp_send_service Inyección opcional (tests).
     */
    public function __construct(?WhatsappSendService $whatsapp_send_service = null)
    {
        $this->whatsapp_send_service = $whatsapp_send_service ?? new WhatsappSendService();
    }

    /**
     * Extrae el nombre del contacto desde `contacts[].profile.name` del payload Meta/Kapso.
     *
     * Prioriza el contacto cuyo `wa_id` coincide con el teléfono normalizado del remitente.
     *
     * @param array<string, mixed> $payload  Body JSON del webhook.
     * @param string               $normalized_phone Teléfono E.164 del remitente.
     *
     * @return string|null Nombre limpio o null si no viene en el payload.
     */
    public function extract_profile_name_from_payload(array $payload, string $normalized_phone): ?string
    {
        $contacts = $payload['contacts'] ?? null;
        if (! is_array($contacts)) {
            return null;
        }

        foreach ($contacts as $contact) {
            if (! is_array($contact)) {
                continue;
            }

            $wa_id = $contact['wa_id'] ?? null;
            if ($wa_id === null || $wa_id === '') {
                continue;
            }

            if (! WhatsappNormalizer::phones_match((string) $wa_id, $normalized_phone)) {
                continue;
            }

            $name = $this->normalize_contact_name($contact['profile']['name'] ?? null);
            if ($name !== null) {
                return $name;
            }
        }

        foreach ($contacts as $contact) {
            if (! is_array($contact)) {
                continue;
            }

            $name = $this->normalize_contact_name($contact['profile']['name'] ?? null);
            if ($name !== null) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Resuelve el nombre a usar en saludos: profile Meta, Kapso conversation o lead existente.
     *
     * @param array<string, mixed> $parsed   Resultado de parse_inbound_message.
     * @param array<string, mixed> $payload  Body JSON del webhook.
     * @param Lead|null              $lead     Lead ya persistido, si existe.
     *
     * @return string|null
     */
    public function resolve_display_name(array $parsed, array $payload, ?Lead $lead = null): ?string
    {
        $from_phone = (string) ($parsed['from'] ?? '');
        $profile_name = $from_phone !== ''
            ? $this->extract_profile_name_from_payload($payload, $from_phone)
            : null;

        if ($profile_name !== null) {
            return $profile_name;
        }

        $kapso_name = $this->normalize_contact_name($parsed['contact_name'] ?? null);
        if ($kapso_name !== null) {
            return $kapso_name;
        }

        if ($lead !== null) {
            return $this->normalize_contact_name($lead->contact_name);
        }

        return null;
    }

    /**
     * Indica si corresponde enviar bienvenida y programar presentación en este inbound.
     *
     * Solo leads en estado `nuevo`, sin bienvenida previa y sin historial en lead_messages.
     *
     * @param Lead $lead
     *
     * @return bool
     */
    public function should_run_onboarding(Lead $lead): bool
    {
        if ((string) $lead->status !== 'nuevo') {
            return false;
        }

        if ($this->has_auto_message_been_sent($lead)) {
            return false;
        }

        if ($this->lead_has_conversation_history($lead)) {
            return false;
        }

        return true;
    }

    /**
     * Envía mensaje automático inmediato, persiste en lead_messages y encola bienvenida diferida.
     *
     * @param Lead        $lead
     * @param string|null $display_name Nombre para personalizar saludos.
     *
     * @return void
     */
    public function send_welcome_and_schedule_presentation(Lead $lead, ?string $display_name): void
    {
        if ($this->has_auto_message_been_sent($lead)) {
            return;
        }

        $auto_body = $this->build_auto_message_body($display_name);
        $this->persist_and_send_system_message($lead, $auto_body, self::KIND_AUTO);

        if (! $this->has_welcome_been_sent($lead)) {
            /* Demora: variante asignada (pre-pick con nombre) → global → sync si es 0. */
            $delay_seconds = $this->resolve_welcome_delay_seconds($lead, $display_name);
            $pending_dispatch = SendLeadPresentationMessageJob::dispatch((int) $lead->id, $display_name);

            // Con demora 0 no depender de queue:work (mismo criterio que sugerencia IA).
            if ($delay_seconds > 0) {
                $pending_dispatch->delay(now()->addSeconds($delay_seconds));
            } else {
                $pending_dispatch->onConnection('sync')->afterResponse();
            }
        }
    }

    /**
     * Envía el mensaje de bienvenida (invocado por el job diferido).
     *
     * @param Lead        $lead
     * @param string|null $display_name Nombre capturado al momento del primer contacto.
     *
     * @return void
     */
    public function send_presentation_message(Lead $lead, ?string $display_name): void
    {
        if ((string) $lead->status !== 'nuevo') {
            Log::channel('daily')->info('LeadWhatsappOnboarding: presentación omitida (lead ya no está en nuevo).', [
                'lead_id' => $lead->id,
                'status'  => $lead->status,
            ]);

            return;
        }

        if ($this->has_welcome_been_sent($lead)) {
            return;
        }

        /* Primera iteración A/B: solo asignar variante cuando hay nombre de contacto. */
        $body = $this->resolve_welcome_message_body($lead, $display_name);
        $this->persist_and_send_system_message($lead, $body, self::KIND_WELCOME);
    }

    /**
     * Texto del mensaje automático inmediato tras el primer mensaje del lead.
     *
     * @param string|null $display_name
     *
     * @return string
     */
    public function build_auto_message_body(?string $display_name): string
    {
        return LeadWhatsappOnboardingSettings::build_auto_message_body($display_name);
    }

    /**
     * Resuelve el cuerpo del welcome: variante A/B activa o fallback histórico.
     *
     * Si no hay nombre de contacto, mantiene el texto actual sin variante (primera iteración).
     *
     * @param Lead        $lead
     * @param string|null $display_name
     *
     * @return string
     */
    private function resolve_welcome_message_body(Lead $lead, ?string $display_name): string
    {
        $normalized_name = $this->normalize_contact_name($display_name);

        if ($normalized_name === null) {
            return $this->build_welcome_message_body($display_name);
        }

        /* Reutilizar variante ya asignada al programar el job (misma que definió el delay). */
        $variant = $this->resolve_or_assign_welcome_variant($lead, $display_name);
        if ($variant === null) {
            return $this->build_welcome_message_body($display_name);
        }

        $body = LeadWhatsappOnboardingSettings::apply_nombre_placeholder($variant->body, $normalized_name);
        $variant->increment_sent();

        return $body;
    }

    /**
     * Segundos de espera antes del welcome: delay de la variante o fallback global.
     *
     * @param Lead        $lead
     * @param string|null $display_name Nombre al momento del primer contacto.
     *
     * @return int
     */
    private function resolve_welcome_delay_seconds(Lead $lead, ?string $display_name): int
    {
        $variant = $this->resolve_or_assign_welcome_variant($lead, $display_name);

        if ($variant !== null && $variant->delay_seconds !== null) {
            return (int) $variant->delay_seconds;
        }

        return LeadWhatsappOnboardingSettings::get_welcome_delay_seconds();
    }

    /**
     * Devuelve la variante A/B del welcome; la asigna al lead si aún no tiene y hay nombre.
     *
     * @param Lead        $lead
     * @param string|null $display_name
     *
     * @return MessageVariant|null
     */
    private function resolve_or_assign_welcome_variant(Lead $lead, ?string $display_name): ?MessageVariant
    {
        if ($lead->welcome_variant_id) {
            return MessageVariant::find($lead->welcome_variant_id);
        }

        $normalized_name = $this->normalize_contact_name($display_name);
        if ($normalized_name === null) {
            return null;
        }

        /* A/B testing: elegir variante activa del tipo welcome_with_name. */
        $variant = MessageVariant::pick_active_variant('welcome_with_name');
        if ($variant === null) {
            return null;
        }

        Lead::where('id', $lead->id)->update(['welcome_variant_id' => $variant->id]);
        $lead->welcome_variant_id = $variant->id;

        return $variant;
    }

    /**
     * Texto del mensaje de bienvenida / presentación de ComercioCity.
     *
     * @param string|null $display_name
     *
     * @return string
     */
    public function build_welcome_message_body(?string $display_name): string
    {
        return LeadWhatsappOnboardingSettings::build_welcome_message_body($display_name);
    }

    /**
     * Alias del nombre histórico del método de presentación.
     *
     * @param string|null $display_name
     *
     * @return string
     */
    public function build_presentation_message_body(?string $display_name): string
    {
        return $this->build_welcome_message_body($display_name);
    }

    /**
     * Indica si ya se envió el mensaje automático inmediato.
     *
     * @param Lead $lead
     *
     * @return bool
     */
    public function has_auto_message_been_sent(Lead $lead): bool
    {
        return $this->has_system_message_of_kind($lead, self::KIND_AUTO, LeadWhatsappOnboardingSettings::legacy_auto_sent_marker());
    }

    /**
     * Indica si ya se envió el mensaje de bienvenida diferido.
     *
     * @param Lead $lead
     *
     * @return bool
     */
    public function has_welcome_been_sent(Lead $lead): bool
    {
        return $this->has_system_message_of_kind($lead, self::KIND_WELCOME, LeadWhatsappOnboardingSettings::legacy_welcome_sent_marker());
    }

    /**
     * Alias histórico para presentación / bienvenida diferida.
     *
     * @param Lead $lead
     *
     * @return bool
     */
    public function has_presentation_been_sent(Lead $lead): bool
    {
        return $this->has_welcome_been_sent($lead);
    }

    /**
     * @param Lead   $lead
     * @param string $kind
     * @param string $legacy_marker
     *
     * @return bool
     */
    private function has_system_message_of_kind(Lead $lead, string $kind, string $legacy_marker): bool
    {
        if (LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('sender', 'sistema')
            ->where('system_message_kind', $kind)
            ->exists()) {
            return true;
        }

        return LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('sender', 'sistema')
            ->where('content', 'like', '%' . $legacy_marker . '%')
            ->exists();
    }

    /**
     * @param Lead $lead
     *
     * @return bool
     */
    private function lead_has_conversation_history(Lead $lead): bool
    {
        return LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->exists();
    }

    /**
     * Registra un mensaje automático en la conversación del lead y lo envía por WhatsApp.
     *
     * Siempre persiste en `lead_messages` para que admin-spa muestre el hilo completo,
     * aunque falle el envío saliente a Kapso.
     *
     * @param Lead        $lead
     * @param string      $body Texto del mensaje.
     * @param string|null $system_message_kind Tipo para idempotencia (whatsapp_auto | whatsapp_welcome).
     *
     * @return LeadMessage|null Registro creado en conversación.
     */
    public function persist_and_send_system_message(Lead $lead, string $body, ?string $system_message_kind = null): ?LeadMessage
    {
        $phone = trim((string) $lead->phone);
        $whatsapp_message_id = null;

        if ($phone !== '') {
            $whatsapp_message_id = $this->whatsapp_send_service->send_text($phone, $body);
        } else {
            Log::channel('daily')->warning('LeadWhatsappOnboarding: lead sin teléfono; se guarda en conversación sin enviar.', [
                'lead_id' => $lead->id,
            ]);
        }

        $lead_message = LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'sistema',
            'content'               => $body,
            'status'                => 'enviado',
            'is_followup'           => false,
            'requiere_verificacion' => false,
            'whatsapp_message_id'   => $whatsapp_message_id,
            'system_message_kind'   => $system_message_kind,
            'sent_at'               => now(),
        ]);

        LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $lead_message->id);

        return $lead_message;
    }

    /**
     * @param mixed $raw
     *
     * @return string|null
     */
    private function normalize_contact_name($raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim((string) $raw);

        return $trimmed === '' ? null : $trimmed;
    }
}
