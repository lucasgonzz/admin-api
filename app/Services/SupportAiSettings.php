<?php

namespace App\Services;

use App\Models\AdminSetting;

/**
 * Configuración global de sugerencias IA automáticas en soporte WhatsApp.
 *
 * Persistencia en `admin_settings`: activación, demora antes de consultar a Claude (debounce)
 * y demora antes del envío automático de la sugerencia generada.
 */
class SupportAiSettings
{
    /** Clave: sugerencias automáticas activas en soporte WhatsApp. */
    public const KEY_SUGGESTIONS_ENABLED = 'support_ai_suggestions_enabled';

    /** Clave: segundos de inactividad del cliente antes de pedir sugerencia a Claude (debounce). */
    public const KEY_SUGGESTION_DELAY_SECONDS = 'support_ai_suggestion_delay';

    /** Clave: segundos hasta enviar automáticamente una sugerencia generada (0 = envío inmediato). */
    public const KEY_AUTO_SEND_DELAY_SECONDS = 'support_ai_auto_send_delay';

    /** Demora por defecto antes de consultar a Claude (0 = inmediato, comportamiento histórico). */
    private const DEFAULT_SUGGESTION_DELAY_SECONDS = 0;

    /** Demora por defecto antes del envío automático de la sugerencia (0 = sin espera humana). */
    private const DEFAULT_AUTO_SEND_DELAY_SECONDS = 0;

    /** Mínimo y máximo para demora antes de pedir sugerencia IA (segundos). */
    public const SUGGESTION_DELAY_MIN_SECONDS = 0;

    public const SUGGESTION_DELAY_MAX_SECONDS = 3600;

    /** Mínimo y máximo para auto-envío de sugerencias (0 desactiva la espera previa al envío). */
    public const AUTO_SEND_DELAY_MIN_SECONDS = 0;

    public const AUTO_SEND_DELAY_MAX_SECONDS = 3600;

    /**
     * Indica si las sugerencias automáticas están activas.
     *
     * @return bool
     */
    public static function is_suggestions_enabled(): bool
    {
        return filter_var(
            AdminSetting::get(self::KEY_SUGGESTIONS_ENABLED, false),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Segundos de espera tras el último mensaje del cliente antes de consultar a Claude.
     *
     * @return int
     */
    public static function get_suggestion_delay_seconds(): int
    {
        $raw = AdminSetting::get(self::KEY_SUGGESTION_DELAY_SECONDS, null);
        if ($raw === null || $raw === '') {
            return self::DEFAULT_SUGGESTION_DELAY_SECONDS;
        }

        $value = (int) $raw;

        return self::clamp_suggestion_delay($value);
    }

    /**
     * Segundos tras generar la sugerencia antes de enviarla por WhatsApp sin intervención del operador.
     *
     * @return int
     */
    public static function get_auto_send_delay_seconds(): int
    {
        $raw = AdminSetting::get(self::KEY_AUTO_SEND_DELAY_SECONDS, null);
        if ($raw === null || $raw === '') {
            return self::DEFAULT_AUTO_SEND_DELAY_SECONDS;
        }

        $value = (int) $raw;

        return self::clamp_auto_send_delay($value);
    }

    /**
     * Payload completo para el panel de configuración (GET settings).
     *
     * @return array<string, mixed>
     */
    public static function to_array(): array
    {
        return [
            'suggestions_enabled'    => self::is_suggestions_enabled(),
            'suggestion_delay'       => self::get_suggestion_delay_seconds(),
            'auto_send_delay'        => self::get_auto_send_delay_seconds(),
        ];
    }

    /**
     * Acota la demora antes de pedir sugerencia IA al rango permitido.
     *
     * @param int $value
     *
     * @return int
     */
    public static function clamp_suggestion_delay(int $value): int
    {
        if ($value < self::SUGGESTION_DELAY_MIN_SECONDS) {
            return self::SUGGESTION_DELAY_MIN_SECONDS;
        }

        if ($value > self::SUGGESTION_DELAY_MAX_SECONDS) {
            return self::SUGGESTION_DELAY_MAX_SECONDS;
        }

        return $value;
    }

    /**
     * Acota la demora de auto-envío al rango permitido.
     *
     * @param int $value
     *
     * @return int
     */
    public static function clamp_auto_send_delay(int $value): int
    {
        if ($value < self::AUTO_SEND_DELAY_MIN_SECONDS) {
            return self::AUTO_SEND_DELAY_MIN_SECONDS;
        }

        if ($value > self::AUTO_SEND_DELAY_MAX_SECONDS) {
            return self::AUTO_SEND_DELAY_MAX_SECONDS;
        }

        return $value;
    }
}
