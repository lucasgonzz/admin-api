<?php

namespace App\Services;

use App\Models\AdminSetting;

/**
 * Configuración global de sugerencias IA automáticas en soporte WhatsApp.
 *
 * Persistencia en `admin_settings`: activación, demora antes de consultar a Claude (debounce),
 * demora antes del envío automático de la sugerencia generada y régimen de nacimiento de los
 * tickets nuevos.
 *
 * Ninguna de estas claves tiene migración ni seeder: la fila se materializa en el primer PUT del
 * panel y hasta entonces manda el default de acá. Sembrarlas sería un segundo lugar donde vive
 * el default, y los dos se desincronizan sin que nada avise.
 */
class SupportAiSettings
{
    /** Clave: sugerencias automáticas activas en soporte WhatsApp. */
    public const KEY_SUGGESTIONS_ENABLED = 'support_ai_suggestions_enabled';

    /** Clave: segundos de inactividad del cliente antes de pedir sugerencia a Claude (debounce). */
    public const KEY_SUGGESTION_DELAY_SECONDS = 'support_ai_suggestion_delay';

    /** Clave: segundos hasta enviar automáticamente una sugerencia generada (0 = envío inmediato). */
    public const KEY_AUTO_SEND_DELAY_SECONDS = 'support_ai_auto_send_delay';

    /** Clave: régimen con el que NACE un ticket nuevo (true = su respuesta espera aprobación humana). */
    public const KEY_REQUIRE_VERIFICATION = 'support_ai_require_verification';

    /** Demora por defecto antes de consultar a Claude (0 = inmediato, comportamiento histórico). */
    private const DEFAULT_SUGGESTION_DELAY_SECONDS = 0;

    /** Demora por defecto antes del envío automático de la sugerencia (0 = sin espera humana). */
    private const DEFAULT_AUTO_SEND_DELAY_SECONDS = 0;

    /** Régimen por defecto de los tickets nuevos: verificación humana prendida. */
    private const DEFAULT_REQUIRE_VERIFICATION = true;

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
     * Régimen con el que nace un ticket de soporte nuevo.
     *
     * El nombre dice `new_ticket` a propósito: este es el ÚNICO punto donde se lee esta clave, y
     * se lee una sola vez, al crear el ticket. Si aparece una llamada desde un job o desde el
     * camino de envío, el nombre tiene que frenar a quien la escriba — leerla en runtime dejaría
     * contestando solos a tickets que un operador ya venía verificando a mano.
     *
     * @return bool
     */
    public static function new_ticket_requires_verification(): bool
    {
        $raw = AdminSetting::get(self::KEY_REQUIRE_VERIFICATION, null);

        // Ausente o vacío cae en true a propósito, y no en filter_var(''), que da false: el único
        // error tolerable de este método es hacer esperar de más un mensaje; el otro es mandarle
        // al cliente algo que nadie leyó.
        if ($raw === null || $raw === '') {
            return self::DEFAULT_REQUIRE_VERIFICATION;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
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
            'require_verification'   => self::new_ticket_requires_verification(),
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
