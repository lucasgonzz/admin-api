<?php

namespace App\Services\Prueba;

use App\Models\AdminSetting;

/**
 * Las pocas perillas de la prueba gratuita, todas en `admin_settings` con default escrito acá
 * (plan de `prueba-alta-instancia`, D1, D4 y D5).
 */
class PruebaSettings
{
    /** Cuántas instancias `libre` sostiene `pruebas:mantener-pool`. */
    const KEY_POOL_MINIMO = 'prueba_pool_minimo';

    /** Tope de instancias ocupadas y despiertas; superado, el alta da 409 `sin_capacidad`. */
    const KEY_MAX_ACTIVAS = 'prueba_max_activas';

    /** Días de prueba por defecto. */
    const KEY_DIAS_DEFAULT = 'prueba_dias_default';

    /** Días después del vencimiento sin contratar tras los que `pruebas:borrar` borra la instancia. */
    const KEY_DIAS_GRACIA_BORRADO = 'prueba_dias_gracia_borrado';

    /** URL del video de bienvenida (viaja en C1 y en C6). */
    const KEY_VIDEO_URL = 'prueba_video_url';

    /** WhatsApp de soporte que ve el negocio en la pantalla de vencimiento (viaja en C1 y en C6). */
    const KEY_WHATSAPP_SOPORTE = 'prueba_whatsapp_soporte';

    const DEFAULT_POOL_MINIMO         = 3;
    const DEFAULT_MAX_ACTIVAS         = 25;
    const DEFAULT_DIAS                = 7;
    const DEFAULT_DIAS_GRACIA_BORRADO = 30;

    /** Tope duro de días que acepta el admin al editar una prueba. */
    const MAX_DIAS = 365;

    public static function pool_minimo(): int
    {
        return max(0, (int) AdminSetting::get(self::KEY_POOL_MINIMO, self::DEFAULT_POOL_MINIMO));
    }

    public static function max_activas(): int
    {
        return max(1, (int) AdminSetting::get(self::KEY_MAX_ACTIVAS, self::DEFAULT_MAX_ACTIVAS));
    }

    public static function dias_default(): int
    {
        return max(1, (int) AdminSetting::get(self::KEY_DIAS_DEFAULT, self::DEFAULT_DIAS));
    }

    public static function dias_gracia_borrado(): int
    {
        return max(0, (int) AdminSetting::get(self::KEY_DIAS_GRACIA_BORRADO, self::DEFAULT_DIAS_GRACIA_BORRADO));
    }

    public static function video_url(): string
    {
        return trim((string) AdminSetting::get(self::KEY_VIDEO_URL, ''));
    }

    public static function whatsapp_soporte(): string
    {
        $valor = trim((string) AdminSetting::get(self::KEY_WHATSAPP_SOPORTE, ''));

        if ($valor !== '') {
            return $valor;
        }

        return trim((string) config('services.prueba.whatsapp_soporte', ''));
    }
}
