<?php

namespace App\Helpers;

use App\Models\AdminSetting;
use Carbon\Carbon;

/**
 * Reloj centralizado del sistema con soporte de tiempo virtual en entorno local.
 * En producción siempre devuelve la hora real; en local puede leerse desde AdminSetting.
 */
class AppTime
{
    /** Clave en admin_settings para persistir el datetime virtual de debug. */
    const SETTING_KEY = 'debug_virtual_time';

    /**
     * Devuelve el momento actual.
     * En local: si hay tiempo virtual seteado, lo devuelve en el timezone indicado.
     * En producción: siempre devuelve Carbon::now() real.
     *
     * @param string $tz Zona horaria IANA para el instante devuelto.
     *
     * @return Carbon
     */
    public static function now(string $tz = 'America/Argentina/Buenos_Aires'): Carbon
    {
        // Solo en local se consulta el override guardado en BD.
        if (config('app.env') === 'local') {
            $virtual = AdminSetting::get(self::SETTING_KEY, null);
            if ($virtual !== null && $virtual !== '') {
                try {
                    return Carbon::parse($virtual)->setTimezone($tz);
                } catch (\Exception $e) {
                    // Formato inválido: caer al tiempo real.
                }
            }
        }

        return Carbon::now($tz);
    }

    /**
     * Guarda el tiempo virtual en la BD (solo tiene efecto en local).
     *
     * @param string $datetime Valor en formato Y-m-d H:i o Y-m-d H:i:s.
     *
     * @return void
     */
    public static function set_virtual(string $datetime): void
    {
        AdminSetting::set(self::SETTING_KEY, $datetime);
    }

    /**
     * Elimina el tiempo virtual (vuelve al reloj real).
     *
     * @return void
     */
    public static function clear_virtual(): void
    {
        AdminSetting::where('key', self::SETTING_KEY)->delete();
    }

    /**
     * Devuelve el valor guardado o null si no hay tiempo virtual activo.
     *
     * @return string|null
     */
    public static function get_virtual()
    {
        $v = AdminSetting::get(self::SETTING_KEY, null);
        if ($v === null || $v === '') {
            return null;
        }

        return $v;
    }

    /**
     * true si hay tiempo virtual activo (solo posible en local).
     *
     * @return bool
     */
    public static function is_active(): bool
    {
        if (config('app.env') !== 'local') {
            return false;
        }

        return self::get_virtual() !== null;
    }
}
