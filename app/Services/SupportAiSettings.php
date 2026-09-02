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
     * El nombre dice `new_ticket` a propósito. Esta clave se puede leer todas las veces que haga
     * falta para PINTAR el panel de Cuenta (`to_array()`, el fallback del PUT), pero en el camino
     * de un ticket se lee UNA sola vez: al crearlo, para estampar el régimen. Lo que el nombre
     * tiene que frenar es una llamada desde el camino de envío —un job, o cualquier lugar que
     * decida si un mensaje sale—: leerla ahí dejaría contestando solos a tickets que un operador
     * ya venía verificando a mano, que es justo lo que esta decisión de producto prohíbe. Si
     * aparece una llamada desde `app/Jobs/` o desde el envío, está mal.
     *
     * @return bool
     */
    public static function new_ticket_requires_verification(): bool
    {
        $raw = AdminSetting::get(self::KEY_REQUIRE_VERIFICATION, null);

        $normalizado = $raw === null ? '' : strtolower(trim((string) $raw));

        // Fila ausente, o presente pero vacía / con puros espacios: nadie dijo nada, y "nadie dijo
        // nada" no es "apagala". Una fila vacía es una escritura a medias, no una decisión.
        if ($normalizado === '') {
            return self::DEFAULT_REQUIRE_VERIFICATION;
        }

        // Se listan los valores que APAGAN, y todo lo demás deja la verificación prendida. Es al
        // revés de lo obvio —un `filter_var($raw, FILTER_VALIDATE_BOOLEAN)` pelado— y es a
        // propósito: filter_var() manda a false todo lo que no reconoce (' ', 'si', '2', '-1',
        // 'null'), o sea que cualquier valor raro caería para el lado peligroso, que es justo lo
        // que este método existe para evitar.
        //
        // Los dos errores posibles acá no valen lo mismo: hacer esperar de más un mensaje lo
        // arregla un operador aprobándolo desde la conversación; mandarle al cliente algo que
        // nadie leyó no se deshace. Por eso apagar la verificación tiene que decirse sin
        // ambigüedad. El único escritor normal es el controller, que ya normaliza a '1'/'0'; esta
        // lista está para lo que escribe una persona en un incidente: un UPDATE a mano, una
        // remediación por SSH, un import.
        return ! in_array($normalizado, ['0', 'false', 'off', 'no'], true);
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
