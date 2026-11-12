<?php

namespace App\Services\Prueba;

use App\Models\AdminSetting;
use App\Models\Client;

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

    /** URL anterior, conservada como respaldo para las instalaciones ya configuradas. */
    const KEY_VIDEO_URL = 'prueba_video_url';

    /** Historia durante el armado, en la landing (C6). */
    const KEY_VIDEO_ESPERA_URL = 'prueba_video_espera_url';

    /** Bienvenida corta al primer ingreso del negocio (C1). */
    const KEY_VIDEO_BIENVENIDA_URL = 'prueba_video_bienvenida_url';

    /** WhatsApp de soporte que ve el negocio en la pantalla de vencimiento (viaja en C1 y en C6). */
    const KEY_WHATSAPP_SOPORTE = 'prueba_whatsapp_soporte';

    /** Minutos sin respuesta antes del respaldo automático de soporte (ver respaldo_soporte_minutos()). */
    const KEY_RESPALDO_SOPORTE_MINUTOS = 'prueba_respaldo_soporte_minutos';
    const DEFAULT_RESPALDO_SOPORTE_MINUTOS = 3;

    /** Encuesta de fin de prueba (misión prueba-e2e-campana-imperio, 11/11/2026): 1 = se manda al vencer y al contratar. */
    const KEY_ENCUESTA_ACTIVA = 'prueba_encuesta_activa';

    /** Qué bienvenida de WhatsApp se manda: `v2` (default; con respaldo a la v1 mientras Meta no la apruebe) o `v1`. */
    const KEY_BIENVENIDA_VERSION = 'prueba_bienvenida_version';

    /**
     * A quién le llegan las NOVEDADES de la misión prueba-e2e-campana-imperio (encuesta, aviso de que la prueba
     * termina, correo por paso, bienvenida v2, pasos de los hitos nuevos y la copia de la bienvenida en el sistema):
     * `ensayo` (default) = solo a los clientes marcados `clients.es_ensayo`; `todos` = a todos los negocios en prueba.
     */
    const KEY_NOVEDADES_ALCANCE = 'prueba_novedades_alcance';
    const ALCANCE_ENSAYO        = 'ensayo';
    const ALCANCE_TODOS         = 'todos';

    /** 0 hasta que Lucas prenda el pool desde admin_settings: sin DNS ni plantilla, un mínimo > 0 solo fabrica fallidas. */
    const DEFAULT_POOL_MINIMO         = 0;
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

    public static function video_espera_url(): string
    {
        return self::video_del_momento(self::KEY_VIDEO_ESPERA_URL);
    }

    public static function video_bienvenida_url(): string
    {
        return self::video_del_momento(self::KEY_VIDEO_BIENVENIDA_URL);
    }

    private static function video_del_momento(string $key): string
    {
        $url = trim((string) AdminSetting::get($key, ''));

        // Separar un destino no debe dejar sin video al otro ni exigir migrar la configuración anterior.
        return $url !== '' ? $url : self::video_url();
    }

    /**
     * Minutos sin respuesta tras los que el respaldo automático contesta un ticket de prueba cuando Martín está
     * caído (misión prueba-ayuda-unificada-en-soporte, 10/11/2026). `prueba_respaldo_soporte_minutos`, default 3,
     * entre 1 y 120.
     *
     * @return int
     */
    public static function respaldo_soporte_minutos(): int
    {
        $minutos = (int) AdminSetting::get(self::KEY_RESPALDO_SOPORTE_MINUTOS, self::DEFAULT_RESPALDO_SOPORTE_MINUTOS);

        return $minutos < 1 ? self::DEFAULT_RESPALDO_SOPORTE_MINUTOS : min($minutos, 120);
    }

    /**
     * Si la encuesta de fin de prueba se manda (`prueba_encuesta_activa`, default 1). Con 0 no se crea ni se
     * manda ninguna; las que ya salieron se pueden seguir contestando.
     *
     * @return bool
     */
    public static function encuesta_activa(): bool
    {
        return (string) AdminSetting::get(self::KEY_ENCUESTA_ACTIVA, '1') !== '0';
    }

    /**
     * La versión de la bienvenida por WhatsApp (`prueba_bienvenida_version`): `v2` salvo que el setting diga `v1`.
     *
     * @return string `v1` o `v2`.
     */
    public static function bienvenida_version(): string
    {
        return strtolower(trim((string) AdminSetting::get(self::KEY_BIENVENIDA_VERSION, 'v2'))) === 'v1' ? 'v1' : 'v2';
    }

    /**
     * El alcance de las novedades (`prueba_novedades_alcance`): `todos` solo si el setting lo dice; cualquier otra
     * cosa (o sin setting) es `ensayo`.
     *
     * @return string
     */
    public static function novedades_alcance(): string
    {
        return strtolower(trim((string) AdminSetting::get(self::KEY_NOVEDADES_ALCANCE, self::ALCANCE_ENSAYO))) === self::ALCANCE_TODOS
            ? self::ALCANCE_TODOS
            : self::ALCANCE_ENSAYO;
    }

    /**
     * Si a este cliente le corresponden las novedades: con el alcance en `todos`, a cualquiera; con `ensayo`, solo
     * al marcado como ensayo. Para el resto, la prueba se comporta exactamente como antes de la misión.
     *
     * @param Client|null $client
     *
     * @return bool
     */
    public static function novedades_para(?Client $client): bool
    {
        if ($client === null) {
            return false;
        }

        return (bool) $client->es_ensayo || self::novedades_alcance() === self::ALCANCE_TODOS;
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
