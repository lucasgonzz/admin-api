<?php

namespace App\Services;

use App\Models\AdminSetting;

/**
 * Textos y demora de los mensajes automáticos de onboarding WhatsApp para leads nuevos.
 *
 * Persistencia en `admin_settings`; valores por defecto equivalentes al comportamiento histórico.
 */
class LeadWhatsappOnboardingSettings
{
    /** Clave: mensaje automático inmediato cuando hay nombre de contacto. */
    public const KEY_AUTO_WITH_NAME = 'lead_whatsapp_auto_message_with_name';

    /** Clave: mensaje automático inmediato sin nombre de contacto. */
    public const KEY_AUTO_WITHOUT_NAME = 'lead_whatsapp_auto_message_without_name';

    /** Clave: mensaje de bienvenida/presentación diferido con nombre. */
    public const KEY_WELCOME_WITH_NAME = 'lead_whatsapp_welcome_message_with_name';

    /** Clave: mensaje de bienvenida/presentación diferido sin nombre. */
    public const KEY_WELCOME_WITHOUT_NAME = 'lead_whatsapp_welcome_message_without_name';

    /** Clave: segundos de espera antes del mensaje de bienvenida. */
    public const KEY_WELCOME_DELAY_SECONDS = 'lead_whatsapp_welcome_delay_seconds';

    /** Clave: segundos de inactividad del lead antes de pedir sugerencia a Claude (debounce). */
    public const KEY_AI_SUGGESTION_DELAY_SECONDS = 'lead_whatsapp_ai_suggestion_delay_seconds';

    /** Clave: segundos hasta enviar automáticamente una sugerencia no confirmada (0 = envío inmediato). */
    public const KEY_AI_SUGGESTION_AUTO_SEND_DELAY_SECONDS = 'lead_whatsapp_ai_suggestion_auto_send_delay_seconds';

    /** Placeholder documentado en plantillas editables desde admin-spa. */
    public const PLACEHOLDER_NOMBRE = '{nombre}';

    /**
     * Mensaje automático por defecto (con nombre): respuesta inmediata al primer inbound.
     */
    private const DEFAULT_AUTO_WITH_NAME = '¡Hola {nombre}! 👋 Ya te atendemos, dame un momento.';

    /**
     * Mensaje automático por defecto (sin nombre).
     */
    private const DEFAULT_AUTO_WITHOUT_NAME = '¡Hola {nombre}! 👋 Un asesor comercial se pondrá en contacto a la brevedad.';

    /**
     * Mensaje de bienvenida por defecto (con nombre): presentación de ComercioCity.
     */
    private const DEFAULT_WELCOME_WITH_NAME = "¡Hola {nombre}! 👋 Soy Martín, del equipo de ComercioCity.\n"
        . "Gracias por escribirnos. ComercioCity es una plataforma de gestión para distribuidoras y comercios — incluye ERP, ecommerce integrado, facturación ARCA, implementación completa y soporte humano real.\n"
        . 'Para ver si te podemos ayudar, necesito entender un poco tu negocio. ¿A qué se dedica tu empresa y cuántas personas trabajan con vos?';

    /**
     * Mensaje de bienvenida por defecto (sin nombre).
     */
    private const DEFAULT_WELCOME_WITHOUT_NAME = "¡Hola! 👋 Soy Martín, del equipo de ComercioCity.\n"
        . "Gracias por escribirnos. ComercioCity es una plataforma de gestión para distribuidoras y comercios — incluye ERP, ecommerce integrado, facturación ARCA, implementación completa y soporte humano real.\n"
        . 'Para ver si te podemos ayudar, necesito entender un poco tu negocio. ¿A qué se dedica tu empresa y cuántas personas trabajan con vos?';

    /** Demora por defecto antes del mensaje de bienvenida (segundos). */
    private const DEFAULT_WELCOME_DELAY_SECONDS = 60;

    /** Demora por defecto antes de pedir sugerencia IA tras mensajes del lead (segundos). */
    private const DEFAULT_AI_SUGGESTION_DELAY_SECONDS = 60;

    /** Demora por defecto antes de enviar automáticamente una sugerencia pendiente (0 = envío inmediato). */
    private const DEFAULT_AI_SUGGESTION_AUTO_SEND_DELAY_SECONDS = 120;

    /** Mínimo y máximo permitidos para demoras de bienvenida y similares (segundos). */
    public const DELAY_MIN_SECONDS = 5;

    public const DELAY_MAX_SECONDS = 3600;

    /** Mínimo y máximo para debounce antes de pedir sugerencia IA (0 = consulta inmediata). */
    public const AI_SUGGESTION_DELAY_MIN_SECONDS = 0;

    public const AI_SUGGESTION_DELAY_MAX_SECONDS = 3600;

    /** Mínimo y máximo para auto-envío de sugerencias (0 = envío inmediato sin espera humana). */
    public const AUTO_SEND_DELAY_MIN_SECONDS = 0;

    public const AUTO_SEND_DELAY_MAX_SECONDS = 3600;

    /**
     * Devuelve la configuración completa para el panel (GET settings).
     *
     * @return array<string, mixed>
     */
    public static function to_array(): array
    {
        return [
            'auto_message_with_name'    => self::get_auto_message_with_name(),
            'auto_message_without_name' => self::get_auto_message_without_name(),
            'welcome_message_with_name' => self::get_welcome_message_with_name(),
            'welcome_message_without_name' => self::get_welcome_message_without_name(),
            'welcome_delay_seconds'        => self::get_welcome_delay_seconds(),
            'ai_suggestion_delay_seconds'         => self::get_ai_suggestion_delay_seconds(),
            'ai_suggestion_auto_send_delay_seconds' => self::get_ai_suggestion_auto_send_delay_seconds(),
            'placeholder_nombre'                  => self::PLACEHOLDER_NOMBRE,
        ];
    }

    /**
     * Persiste la configuración validada desde admin-spa.
     *
     * @param array<string, mixed> $data Campos del formulario.
     *
     * @return void
     */
    public static function persist_from_request(array $data): void
    {
        AdminSetting::set(self::KEY_AUTO_WITH_NAME, trim((string) $data['auto_message_with_name']));
        AdminSetting::set(self::KEY_AUTO_WITHOUT_NAME, trim((string) $data['auto_message_without_name']));
        AdminSetting::set(self::KEY_WELCOME_WITH_NAME, trim((string) $data['welcome_message_with_name']));
        AdminSetting::set(self::KEY_WELCOME_WITHOUT_NAME, trim((string) $data['welcome_message_without_name']));
        AdminSetting::set(self::KEY_WELCOME_DELAY_SECONDS, (string) (int) $data['welcome_delay_seconds']);
        AdminSetting::set(self::KEY_AI_SUGGESTION_DELAY_SECONDS, (string) (int) $data['ai_suggestion_delay_seconds']);
        AdminSetting::set(
            self::KEY_AI_SUGGESTION_AUTO_SEND_DELAY_SECONDS,
            (string) (int) $data['ai_suggestion_auto_send_delay_seconds']
        );
    }

    /**
     * Siembra valores por defecto si aún no existen en BD.
     *
     * @return void
     */
    public static function seed_defaults_if_missing(): void
    {
        if (AdminSetting::get(self::KEY_AUTO_WITH_NAME) === null) {
            AdminSetting::set(self::KEY_AUTO_WITH_NAME, self::DEFAULT_AUTO_WITH_NAME);
        }
        if (AdminSetting::get(self::KEY_AUTO_WITHOUT_NAME) === null) {
            AdminSetting::set(self::KEY_AUTO_WITHOUT_NAME, self::DEFAULT_AUTO_WITHOUT_NAME);
        }
        if (AdminSetting::get(self::KEY_WELCOME_WITH_NAME) === null) {
            AdminSetting::set(self::KEY_WELCOME_WITH_NAME, self::DEFAULT_WELCOME_WITH_NAME);
        }
        if (AdminSetting::get(self::KEY_WELCOME_WITHOUT_NAME) === null) {
            AdminSetting::set(self::KEY_WELCOME_WITHOUT_NAME, self::DEFAULT_WELCOME_WITHOUT_NAME);
        }
        if (AdminSetting::get(self::KEY_WELCOME_DELAY_SECONDS) === null) {
            AdminSetting::set(self::KEY_WELCOME_DELAY_SECONDS, (string) self::DEFAULT_WELCOME_DELAY_SECONDS);
        }
        if (AdminSetting::get(self::KEY_AI_SUGGESTION_DELAY_SECONDS) === null) {
            AdminSetting::set(self::KEY_AI_SUGGESTION_DELAY_SECONDS, (string) self::DEFAULT_AI_SUGGESTION_DELAY_SECONDS);
        }
        if (AdminSetting::get(self::KEY_AI_SUGGESTION_AUTO_SEND_DELAY_SECONDS) === null) {
            AdminSetting::set(
                self::KEY_AI_SUGGESTION_AUTO_SEND_DELAY_SECONDS,
                (string) self::DEFAULT_AI_SUGGESTION_AUTO_SEND_DELAY_SECONDS
            );
        }
    }

    /**
     * @return string
     */
    public static function get_auto_message_with_name(): string
    {
        return (string) AdminSetting::get(self::KEY_AUTO_WITH_NAME, self::DEFAULT_AUTO_WITH_NAME);
    }

    /**
     * @return string
     */
    public static function get_auto_message_without_name(): string
    {
        return (string) AdminSetting::get(self::KEY_AUTO_WITHOUT_NAME, self::DEFAULT_AUTO_WITHOUT_NAME);
    }

    /**
     * @return string
     */
    public static function get_welcome_message_with_name(): string
    {
        return (string) AdminSetting::get(self::KEY_WELCOME_WITH_NAME, self::DEFAULT_WELCOME_WITH_NAME);
    }

    /**
     * @return string
     */
    public static function get_welcome_message_without_name(): string
    {
        return (string) AdminSetting::get(self::KEY_WELCOME_WITHOUT_NAME, self::DEFAULT_WELCOME_WITHOUT_NAME);
    }

    /**
     * Segundos de espera antes de enviar el mensaje de bienvenida.
     *
     * @return int
     */
    public static function get_welcome_delay_seconds(): int
    {
        $raw = AdminSetting::get(self::KEY_WELCOME_DELAY_SECONDS, (string) self::DEFAULT_WELCOME_DELAY_SECONDS);
        $seconds = (int) $raw;
        if ($seconds < self::DELAY_MIN_SECONDS) {
            return self::DEFAULT_WELCOME_DELAY_SECONDS;
        }
        if ($seconds > self::DELAY_MAX_SECONDS) {
            return self::DELAY_MAX_SECONDS;
        }

        return $seconds;
    }

    /**
     * Segundos de espera tras el último mensaje del lead antes de pedir sugerencia a Claude.
     *
     * Cada nuevo inbound reinicia el contador (debounce).
     *
     * @return int
     */
    public static function get_ai_suggestion_delay_seconds(): int
    {
        $raw = AdminSetting::get(self::KEY_AI_SUGGESTION_DELAY_SECONDS, (string) self::DEFAULT_AI_SUGGESTION_DELAY_SECONDS);
        $seconds = (int) $raw;

        return self::clamp_ai_suggestion_delay($seconds);
    }

    /**
     * Acota la demora antes de pedir sugerencia IA al rango permitido.
     *
     * @param int $value
     *
     * @return int
     */
    public static function clamp_ai_suggestion_delay(int $value): int
    {
        if ($value < self::AI_SUGGESTION_DELAY_MIN_SECONDS) {
            return self::AI_SUGGESTION_DELAY_MIN_SECONDS;
        }

        if ($value > self::AI_SUGGESTION_DELAY_MAX_SECONDS) {
            return self::AI_SUGGESTION_DELAY_MAX_SECONDS;
        }

        return $value;
    }

    /**
     * Segundos tras crear una sugerencia de Claude antes de enviarla por WhatsApp sin confirmación del setter.
     *
     * 0 envía la sugerencia de inmediato sin espera del setter.
     *
     * @return int
     */
    public static function get_ai_suggestion_auto_send_delay_seconds(): int
    {
        $raw = AdminSetting::get(
            self::KEY_AI_SUGGESTION_AUTO_SEND_DELAY_SECONDS,
            (string) self::DEFAULT_AI_SUGGESTION_AUTO_SEND_DELAY_SECONDS
        );
        $seconds = (int) $raw;
        if ($seconds < self::AUTO_SEND_DELAY_MIN_SECONDS) {
            return self::AUTO_SEND_DELAY_MIN_SECONDS;
        }
        if ($seconds > self::AUTO_SEND_DELAY_MAX_SECONDS) {
            return self::AUTO_SEND_DELAY_MAX_SECONDS;
        }

        return $seconds;
    }

    /**
     * Arma el cuerpo del mensaje automático inmediato según plantilla y nombre del contacto.
     *
     * @param string|null $display_name Nombre del lead o perfil WhatsApp.
     *
     * @return string
     */
    public static function build_auto_message_body(?string $display_name): string
    {
        $name = self::normalize_contact_name($display_name);
        if ($name !== null) {
            return self::apply_nombre_placeholder(self::get_auto_message_with_name(), $name);
        }

        return self::get_auto_message_without_name();
    }

    /**
     * Arma el cuerpo del mensaje de bienvenida diferido según plantilla y nombre.
     *
     * @param string|null $display_name
     *
     * @return string
     */
    public static function build_welcome_message_body(?string $display_name): string
    {
        $name = self::normalize_contact_name($display_name);
        if ($name !== null) {
            return self::apply_nombre_placeholder(self::get_welcome_message_with_name(), $name);
        }

        return self::get_welcome_message_without_name();
    }

    /**
     * Reemplaza `{nombre}` en la plantilla por el nombre normalizado.
     *
     * @param string $template
     * @param string $name
     *
     * @return string
     */
    public static function apply_nombre_placeholder(string $template, string $name): string
    {
        return str_replace(self::PLACEHOLDER_NOMBRE, $name, $template);
    }

    /**
     * Fragmento histórico del mensaje automático (compatibilidad con registros antiguos).
     *
     * @return string
     */
    public static function legacy_auto_sent_marker(): string
    {
        return 'Ya te atendemos, dame un momento.';
    }

    /**
     * Fragmento histórico del mensaje de bienvenida (compatibilidad con registros antiguos).
     *
     * @return string
     */
    public static function legacy_welcome_sent_marker(): string
    {
        return 'Soy Martín, del equipo de ComercioCity.';
    }

    /**
     * @param mixed $raw
     *
     * @return string|null
     */
    private static function normalize_contact_name($raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim((string) $raw);

        return $trimmed === '' ? null : $trimmed;
    }
}
