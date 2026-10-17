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

    /** `message_type` de las variantes A/B de la presentación genérica (lead que escribe lo suyo). */
    public const VARIANT_TYPE_WELCOME = 'welcome';

    /**
     * `message_type` de las variantes A/B de la presentación para el lead que llega con la FRASE DE
     * UN BOTÓN (misión flujo-lead-landing-closer, 17/10/2026). Las tres de arranque las siembra
     * `MessageVariantLandingSeeder`; si ninguna está activa se cae a las de `welcome`.
     */
    public const VARIANT_TYPE_WELCOME_LANDING = 'welcome_landing';

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
        /*
         * 🔴 ESTE MÉTODO PARECE MUERTO Y NO LO ESTÁ. No lo borres.
         *
         * La forma `contacts[].profile.name` que busca acá es la del payload CRUDO de Meta Cloud
         * API, no la del payload procesado de Kapso. Con los payloads que entran hoy por
         * `webhook/whatsapp` (formato Kapso) la clave `contacts` no existe en la raíz, así que
         * este método devuelve null SIEMPRE — y eso llevó a creer, durante meses, que Meta no
         * mandaba el nombre. No era eso: el nombre de Kapso viaja en `conversation.contact_name`
         * y se lee en WhatsappWebhookController::parse_inbound_message() (arreglado el 27/8/2026).
         *
         * Se conserva porque es EXACTAMENTE la forma que entra por `webhook/meta-raw`
         * (MetaRawWebhookController, webhook `kind: meta` de Kapso), donde el payload sí es el
         * crudo de Meta con `entry[].changes[].value.contacts[].profile.name`. Borrarlo hoy sería
         * tener que reescribirlo idéntico mañana.
         */
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
     * ¿El texto ES la frase de uno de nuestros botones (la web, la landing vieja o Instagram)?
     *
     * Mismo criterio que `LeadOrigenService::es_frase_exacta()`: normalizado, como mucho UN saludo
     * adelante («Buenas! Hola, quiero hacer la demo...») y NADA atrás. «Es», no «contiene»: el lead
     * que escribe «Hola, quiero hacer la demo de ComercioCity, pero recién la semana que viene» ya
     * dijo algo propio y recibe el onboarding genérico, con sus 60 segundos.
     *
     * Se compara contra las TRES constantes de `LeadOrigenService::FRASES_POR_ORIGEN` y no contra
     * `frases_de_cta()` (las editables de la configuración de demos) a propósito: el botón de la
     * página de experiencia manda la misma frase que la landing vieja, pero ese botón solo lo ve un
     * lead que ya existe, así que nunca es un primer mensaje; y una frase cargada a mano en la
     * configuración no es un botón nuestro. Tampoco se mira `origen`: lo escribe un observer
     * DESPUÉS de este punto, y acá hace falta decidir con el inbound en la mano (D8 del plan).
     *
     * @param string|null $texto El contenido del primer mensaje del lead.
     *
     * @return bool
     */
    public static function primer_mensaje_es_frase_de_boton(?string $texto): bool
    {
        if ($texto === null || trim($texto) === '') {
            return false;
        }

        foreach (LeadOrigenService::FRASES_POR_ORIGEN as $par) {
            if (LeadOrigenService::es_frase_exacta($texto, $par[0])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Envía mensaje automático inmediato, persiste en lead_messages y encola bienvenida diferida.
     *
     * Dos caminos (misión flujo-lead-landing-closer, 17/10/2026, D8 del plan):
     *
     *  - El PRIMER mensaje es la frase de un botón ({@see primer_mensaje_es_frase_de_boton()}): sale
     *    el auto landing («Enseguida Martín se suma...»), la presentación se elige entre las
     *    variantes `welcome_landing` (con fallback a `welcome` si no hay ninguna activa) y la demora
     *    es la de la variante o, si no tiene, la landing (10 s por default).
     *  - Cualquier otro primer mensaje: exactamente como hasta hoy (auto genérico con o sin nombre,
     *    variantes `welcome`, demora de la variante o la global de 60 s).
     *
     * @param Lead        $lead
     * @param string|null $display_name   Nombre para personalizar saludos.
     * @param string|null $primer_mensaje Texto del inbound que disparó el onboarding. Null (llamadores
     *                                    viejos) = camino genérico.
     *
     * @return void
     */
    public function send_welcome_and_schedule_presentation(Lead $lead, ?string $display_name, ?string $primer_mensaje = null): void
    {
        if ($this->has_auto_message_been_sent($lead)) {
            return;
        }

        $es_lead_de_un_boton = self::primer_mensaje_es_frase_de_boton($primer_mensaje);

        $auto_body = $es_lead_de_un_boton
            ? LeadWhatsappOnboardingSettings::get_auto_message_landing()
            : $this->build_auto_message_body($display_name);
        $this->persist_and_send_system_message($lead, $auto_body, self::KIND_AUTO);

        if ($es_lead_de_un_boton) {
            Log::channel('daily')->info('LeadWhatsappOnboarding: primer mensaje con la frase de un botón; onboarding landing.', [
                'lead_id' => $lead->id,
            ]);
        }

        if (! $this->has_welcome_been_sent($lead)) {
            /* Demora: variante asignada (pre-pick universal) → global (landing o genérica) → sync si es 0. */
            $delay_seconds = $this->resolve_welcome_delay_seconds($lead, $es_lead_de_un_boton);
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

        /* Variantes A/B universales: funcionan con o sin nombre de contacto. */
        $body = $this->resolve_welcome_message_body($lead, $display_name);
        $this->persist_and_send_system_message($lead, $body, self::KIND_WELCOME);
    }

    /**
     * Texto del mensaje automático inmediato tras el primer mensaje del lead.
     *
     * Recibe el nombre COMPLETO del perfil de WhatsApp (el mismo que se persiste en
     * `contact_name`) y el saludo lleva sólo el primer nombre: la reducción la hace
     * `LeadWhatsappOnboardingSettings::apply_nombre_placeholder()`, único punto por el que pasan
     * las tres inyecciones de `{nombre}` del onboarding (misión experiencia-landing, 11/9/2026).
     *
     * @param string|null $display_name Nombre completo tal como lo devolvió resolve_display_name().
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
     * Funciona siempre, independientemente de si el lead tiene nombre de contacto.
     * Si hay variante asignada:
     *   - Con nombre: reemplaza el placeholder {nombre} con el PRIMER nombre del contacto (la
     *     reducción vive en LeadWhatsappOnboardingSettings::apply_nombre_placeholder(), misión
     *     experiencia-landing, 11/9/2026: "Juan Pérez" saluda como "Juan").
     *   - Sin nombre: elimina el placeholder {nombre} y recorta espacios sobrantes.
     * Si no hay variantes activas del tipo 'welcome', hace fallback al mensaje
     * configurado en admin_settings.
     *
     * @param Lead        $lead
     * @param string|null $display_name Nombre completo del contacto (puede ser null).
     *
     * @return string Cuerpo del mensaje listo para enviar.
     */
    private function resolve_welcome_message_body(Lead $lead, ?string $display_name): string
    {
        /* Reutilizar variante ya asignada al programar el job (misma que definió el delay). */
        $variant = $this->resolve_or_assign_welcome_variant($lead);
        if ($variant === null) {
            return $this->build_welcome_message_body($display_name);
        }

        /* Nombre normalizado: puede ser null si el lead no compartió perfil. */
        $normalized_name = $this->normalize_contact_name($display_name);

        if ($normalized_name !== null) {
            /* Con nombre: inyectar el nombre en el placeholder. */
            $body = LeadWhatsappOnboardingSettings::apply_nombre_placeholder($variant->body, $normalized_name);
        } else {
            /* Sin nombre: eliminar el placeholder y limpiar espacios residuales. */
            $body = trim(str_replace('{nombre}', '', $variant->body));
        }

        $variant->increment_sent();

        return $body;
    }

    /**
     * Segundos de espera antes del welcome: delay de la variante o fallback global.
     *
     * Siempre consulta la variante asignada al lead, sin requerir nombre de contacto.
     * Si la variante tiene delay_seconds configurado, lo usa; de lo contrario usa el
     * valor global de admin_settings: el landing (10 s) para el lead de un botón, el genérico
     * (60 s) para el resto.
     *
     * @param Lead $lead
     * @param bool $lead_de_un_boton true si el primer mensaje fue la frase de un botón (17/10/2026).
     *
     * @return int Segundos de demora para encolar el job.
     */
    private function resolve_welcome_delay_seconds(Lead $lead, bool $lead_de_un_boton = false): int
    {
        $variant = $this->resolve_or_assign_welcome_variant($lead, $lead_de_un_boton);

        if ($variant !== null && $variant->delay_seconds !== null) {
            return (int) $variant->delay_seconds;
        }

        return $lead_de_un_boton
            ? LeadWhatsappOnboardingSettings::get_welcome_delay_seconds_landing()
            : LeadWhatsappOnboardingSettings::get_welcome_delay_seconds();
    }

    /**
     * Devuelve la variante A/B del welcome; la asigna al lead si aún no tiene una.
     *
     * Funciona siempre, independientemente de si el lead tiene nombre de contacto.
     * Si el lead ya tiene welcome_variant_id asignado, devuelve esa misma variante
     * (garantiza consistencia entre el delay calculado al encolar y el body enviado): por eso el
     * job diferido no necesita saber de qué tipo era el lead, lee la variante ya persistida.
     * Si no tiene variante: para el lead de un botón elige una activa de `welcome_landing` y, si
     * no hay ninguna, cae a `welcome` (D2 del plan: nunca se queda sin presentación por tener las
     * tres landing apagadas); para el resto, una de `welcome`, como siempre. Y la persiste.
     *
     * @param Lead $lead
     * @param bool $lead_de_un_boton true si el primer mensaje fue la frase de un botón (17/10/2026).
     *
     * @return MessageVariant|null null si no hay variantes activas de ningún tipo aplicable.
     */
    private function resolve_or_assign_welcome_variant(Lead $lead, bool $lead_de_un_boton = false): ?MessageVariant
    {
        /* Reutilizar variante ya asignada para mantener coherencia entre delay y body. */
        if ($lead->welcome_variant_id) {
            return MessageVariant::find($lead->welcome_variant_id);
        }

        $variant = null;
        if ($lead_de_un_boton) {
            $variant = MessageVariant::pick_active_variant(self::VARIANT_TYPE_WELCOME_LANDING);
            if ($variant === null) {
                Log::channel('daily')->info('LeadWhatsappOnboarding: sin variantes welcome_landing activas; se cae a welcome.', [
                    'lead_id' => $lead->id,
                ]);
            }
        }

        /* A/B testing: elegir variante activa del tipo universal 'welcome'. */
        if ($variant === null) {
            $variant = MessageVariant::pick_active_variant(self::VARIANT_TYPE_WELCOME);
        }
        if ($variant === null) {
            return null;
        }

        /* Persistir la asignación para que el job use la misma variante que definió el delay. */
        Lead::where('id', $lead->id)->update(['welcome_variant_id' => $variant->id]);
        $lead->welcome_variant_id = $variant->id;

        return $variant;
    }

    /**
     * Texto del mensaje de bienvenida / presentación de ComercioCity.
     *
     * Mismo criterio que build_auto_message_body(): entra el nombre completo, el saludo lleva el
     * primer nombre.
     *
     * @param string|null $display_name Nombre completo tal como lo devolvió resolve_display_name().
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
