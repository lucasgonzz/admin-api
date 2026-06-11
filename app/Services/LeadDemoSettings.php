<?php

namespace App\Services;

use App\Models\AdminSetting;

/**
 * Parámetros configurables para el ciclo de vida de las demos.
 *
 * Persistencia en `admin_settings`; controla duración, márgenes de setup/gracia
 * y tiempos de automatizaciones (recordatorio, check de ingreso, resumen del lead).
 */
class LeadDemoSettings
{
    /** Clave: duración estimada de la demo en minutos. */
    public const KEY_DURACION_MINUTOS = 'demo_duracion_minutos';

    /** Clave: minutos antes del inicio para correr demo setup automático. */
    public const KEY_SETUP_MINUTOS_ANTES = 'demo_setup_minutos_antes';

    /** Clave: minutos de gracia post-demo para liberar el slot de disponibilidad. */
    public const KEY_GRACIA_MINUTOS_POST = 'demo_gracia_minutos_post';

    /** Clave: minutos antes del inicio para enviar recordatorio por WhatsApp. */
    public const KEY_RECORDATORIO_MINUTOS_ANTES = 'demo_recordatorio_minutos_antes';

    /** Clave: minutos post-inicio para preguntar al lead si pudo ingresar. */
    public const KEY_CHECK_INGRESO_MINUTOS_POST = 'demo_check_ingreso_minutos_post';

    /** Clave: minutos antes del fin de la demo para generar resumen del lead. */
    public const KEY_RESUMEN_MINUTOS_ANTES_FIN = 'demo_resumen_minutos_antes_fin';

    /** Valor por defecto: duración de la demo (minutos). */
    private const DEFAULT_DURACION_MINUTOS = 60;

    /** Valor por defecto: setup antes del inicio (minutos). */
    private const DEFAULT_SETUP_MINUTOS_ANTES = 15;

    /** Valor por defecto: gracia post-demo (minutos). */
    private const DEFAULT_GRACIA_MINUTOS_POST = 10;

    /** Valor por defecto: recordatorio antes del inicio (minutos). */
    private const DEFAULT_RECORDATORIO_MINUTOS_ANTES = 15;

    /** Valor por defecto: check de ingreso post-inicio (minutos). */
    private const DEFAULT_CHECK_INGRESO_MINUTOS_POST = 5;

    /** Valor por defecto: resumen antes del fin de la demo (minutos). */
    private const DEFAULT_RESUMEN_MINUTOS_ANTES_FIN = 10;

    /** Mínimo permitido para todos los parámetros (minutos). */
    public const MIN_MINUTOS = 0;

    /** Máximo permitido para todos los parámetros (minutos). */
    public const MAX_MINUTOS = 240;

    /**
     * Devuelve la configuración completa para el panel (GET settings).
     *
     * @return array<string, int>
     */
    public static function to_array(): array
    {
        return [
            'duracion_minutos'             => self::get_duracion_minutos(),
            'setup_minutos_antes'          => self::get_setup_minutos_antes(),
            'gracia_minutos_post'          => self::get_gracia_minutos_post(),
            'recordatorio_minutos_antes'   => self::get_recordatorio_minutos_antes(),
            'check_ingreso_minutos_post'   => self::get_check_ingreso_minutos_post(),
            'resumen_minutos_antes_fin'    => self::get_resumen_minutos_antes_fin(),
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
        AdminSetting::set(self::KEY_DURACION_MINUTOS,           (string) self::clamp((int) $data['duracion_minutos']));
        AdminSetting::set(self::KEY_SETUP_MINUTOS_ANTES,        (string) self::clamp((int) $data['setup_minutos_antes']));
        AdminSetting::set(self::KEY_GRACIA_MINUTOS_POST,        (string) self::clamp((int) $data['gracia_minutos_post']));
        AdminSetting::set(self::KEY_RECORDATORIO_MINUTOS_ANTES, (string) self::clamp((int) $data['recordatorio_minutos_antes']));
        AdminSetting::set(self::KEY_CHECK_INGRESO_MINUTOS_POST, (string) self::clamp((int) $data['check_ingreso_minutos_post']));
        AdminSetting::set(self::KEY_RESUMEN_MINUTOS_ANTES_FIN,  (string) self::clamp((int) $data['resumen_minutos_antes_fin']));
    }

    /**
     * Siembra valores por defecto si aún no existen en BD.
     *
     * @return void
     */
    public static function seed_defaults_if_missing(): void
    {
        if (AdminSetting::get(self::KEY_DURACION_MINUTOS) === null) {
            AdminSetting::set(self::KEY_DURACION_MINUTOS, (string) self::DEFAULT_DURACION_MINUTOS);
        }
        if (AdminSetting::get(self::KEY_SETUP_MINUTOS_ANTES) === null) {
            AdminSetting::set(self::KEY_SETUP_MINUTOS_ANTES, (string) self::DEFAULT_SETUP_MINUTOS_ANTES);
        }
        if (AdminSetting::get(self::KEY_GRACIA_MINUTOS_POST) === null) {
            AdminSetting::set(self::KEY_GRACIA_MINUTOS_POST, (string) self::DEFAULT_GRACIA_MINUTOS_POST);
        }
        if (AdminSetting::get(self::KEY_RECORDATORIO_MINUTOS_ANTES) === null) {
            AdminSetting::set(self::KEY_RECORDATORIO_MINUTOS_ANTES, (string) self::DEFAULT_RECORDATORIO_MINUTOS_ANTES);
        }
        if (AdminSetting::get(self::KEY_CHECK_INGRESO_MINUTOS_POST) === null) {
            AdminSetting::set(self::KEY_CHECK_INGRESO_MINUTOS_POST, (string) self::DEFAULT_CHECK_INGRESO_MINUTOS_POST);
        }
        if (AdminSetting::get(self::KEY_RESUMEN_MINUTOS_ANTES_FIN) === null) {
            AdminSetting::set(self::KEY_RESUMEN_MINUTOS_ANTES_FIN, (string) self::DEFAULT_RESUMEN_MINUTOS_ANTES_FIN);
        }
    }

    /**
     * Duración estimada de la demo en minutos.
     *
     * @return int
     */
    public static function get_duracion_minutos(): int
    {
        return self::clamp((int) AdminSetting::get(self::KEY_DURACION_MINUTOS, (string) self::DEFAULT_DURACION_MINUTOS));
    }

    /**
     * Minutos antes del inicio para correr demo setup automático.
     *
     * @return int
     */
    public static function get_setup_minutos_antes(): int
    {
        return self::clamp((int) AdminSetting::get(self::KEY_SETUP_MINUTOS_ANTES, (string) self::DEFAULT_SETUP_MINUTOS_ANTES));
    }

    /**
     * Minutos de gracia post-demo para liberar el slot de disponibilidad.
     *
     * @return int
     */
    public static function get_gracia_minutos_post(): int
    {
        return self::clamp((int) AdminSetting::get(self::KEY_GRACIA_MINUTOS_POST, (string) self::DEFAULT_GRACIA_MINUTOS_POST));
    }

    /**
     * Minutos antes del inicio para enviar recordatorio por WhatsApp al lead.
     *
     * @return int
     */
    public static function get_recordatorio_minutos_antes(): int
    {
        return self::clamp((int) AdminSetting::get(self::KEY_RECORDATORIO_MINUTOS_ANTES, (string) self::DEFAULT_RECORDATORIO_MINUTOS_ANTES));
    }

    /**
     * Minutos después del inicio para preguntar al lead si pudo ingresar a la demo.
     *
     * @return int
     */
    public static function get_check_ingreso_minutos_post(): int
    {
        return self::clamp((int) AdminSetting::get(self::KEY_CHECK_INGRESO_MINUTOS_POST, (string) self::DEFAULT_CHECK_INGRESO_MINUTOS_POST));
    }

    /**
     * Minutos antes del fin de la demo para generar resumen del lead para el closer.
     *
     * @return int
     */
    public static function get_resumen_minutos_antes_fin(): int
    {
        return self::clamp((int) AdminSetting::get(self::KEY_RESUMEN_MINUTOS_ANTES_FIN, (string) self::DEFAULT_RESUMEN_MINUTOS_ANTES_FIN));
    }

    /**
     * Acota un valor entero al rango permitido [MIN_MINUTOS, MAX_MINUTOS].
     *
     * @param int $value
     *
     * @return int
     */
    private static function clamp(int $value): int
    {
        if ($value < self::MIN_MINUTOS) {
            return self::MIN_MINUTOS;
        }
        if ($value > self::MAX_MINUTOS) {
            return self::MAX_MINUTOS;
        }

        return $value;
    }
}
