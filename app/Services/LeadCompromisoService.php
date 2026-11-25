<?php

namespace App\Services;

use App\Models\AdminSetting;

/**
 * El motor de compromisos de contacto con los leads (misión leads-referidos-compromisos, etapa E2).
 *
 * Un compromiso es «el próximo contacto acordado con este lead»: Juan lo llama el viernes, Martín le escribe el martes o
 * se espera que el lead avise. Este servicio reúne toda la lógica: crearlo, reemplazarlo, cancelarlo, decidir si algo
 * cambió desde que se creó y ejecutarlo a su hora.
 *
 * 🔴 TODO EL MOTOR NACE APAGADO (`lead_compromisos_activo` = '0'). Con el interruptor apagado ninguna de las puertas hace
 * nada y el sistema se comporta exactamente como antes de esta misión: no se crean compromisos, no se suspende ningún
 * seguimiento, el comando no ejecuta nada y Martín no ve ningún bloque. Los otros dos interruptores (detección con IA y
 * envío automático) sólo tienen efecto con el motor prendido.
 */
class LeadCompromisoService
{
    /** Clave de `admin_settings`: el motor entero. Default apagado. */
    const SETTING_ACTIVO = 'lead_compromisos_activo';

    /** Clave de `admin_settings`: detectar con IA el compromiso que el lead dice por WhatsApp. Default apagado. */
    const SETTING_DETECCION_IA = 'lead_compromisos_deteccion_ia';

    /** Clave de `admin_settings`: mandar solos los mensajes del motor (apagado = quedan como sugerencia). */
    const SETTING_ENVIO_AUTOMATICO = 'lead_compromisos_envio_automatico';

    /** Clave de `admin_settings`: plantilla de Meta con la que se retoma a un lead con la ventana de 24 h cerrada. */
    const SETTING_PLANTILLA = 'lead_compromiso_plantilla';

    /** Plantilla por defecto del compromiso («Te escribo como quedamos…»). */
    const PLANTILLA_POR_DEFECTO = 'cc_coord_dia_acordado';

    /**
     * ¿Está prendido el motor de compromisos?
     *
     * @return bool
     */
    public static function activo(): bool
    {
        return (string) AdminSetting::get(self::SETTING_ACTIVO, '0') === '1';
    }

    /**
     * ¿Está prendida la detección con IA de lo que el lead escribe? Sólo cuenta con el motor prendido.
     *
     * @return bool
     */
    public static function deteccion_ia_activa(): bool
    {
        return self::activo() && (string) AdminSetting::get(self::SETTING_DETECCION_IA, '0') === '1';
    }

    /**
     * ¿Los mensajes del motor salen solos? Apagado (default) quedan como sugerencia para aprobar.
     *
     * @return bool
     */
    public static function envio_automatico_activo(): bool
    {
        return (string) AdminSetting::get(self::SETTING_ENVIO_AUTOMATICO, '0') === '1';
    }

    /**
     * Nombre de la plantilla de Meta con la que se retoma a un lead que tiene la ventana de 24 h cerrada.
     *
     * @return string El configurado o, si está vacío, {@see self::PLANTILLA_POR_DEFECTO}.
     */
    public static function nombre_de_la_plantilla(): string
    {
        $nombre = trim((string) AdminSetting::get(self::SETTING_PLANTILLA, self::PLANTILLA_POR_DEFECTO));

        return $nombre !== '' ? $nombre : self::PLANTILLA_POR_DEFECTO;
    }
}
