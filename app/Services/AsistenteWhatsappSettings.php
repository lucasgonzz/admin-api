<?php

namespace App\Services;

use App\Models\AdminSetting;

/**
 * Los interruptores globales del canal del asistente por WhatsApp.
 *
 * Son tres y cada uno tapa un agujero distinto:
 *
 *   1. **Los tickets de soporte, desconectados.** Lucas lo dictó el 16/9/2026: *"actualmente los
 *      clientes se comunican a otro número por soporte, así que simplemente dejá desconectada la
 *      parte de tickets de soporte; en el futuro, cuando consiga otro número, se pondrá en
 *      marcha"*. 🔴 Es un INTERRUPTOR y no un borrado: `SupportTicket`, `SupportMessage`,
 *      `SupportAiSuggestionService`, la bandeja del admin-spa y el espejo hacia el ERP del cliente
 *      quedan enteros y funcionando. El día que aparezca el otro número, esto se vuelve a prender
 *      escribiendo una fila.
 *   2. **Qué se le contesta a quien escribe igual**, con los tickets apagados. Vacío por defecto,
 *      o sea silencio: contestar algo automático a un cliente que escribió por soporte es peor que
 *      no contestar, porque lo deja pensando que alguien lo leyó.
 *   3. **Con qué plantilla salen los informes de la mañana.** A las 8:30 la ventana de 24 hs de
 *      Meta está cerrada para casi todos los dueños, así que sin plantilla aprobada esa parte no
 *      sale. No hay default posible: una plantilla inventada la rechaza Meta.
 *
 * Ninguna de las tres tiene migración ni seeder, por el mismo motivo que `SupportAiSettings`: la
 * fila se materializa cuando alguien la escribe, y hasta entonces manda el default de acá.
 * Sembrarlas sería un segundo lugar donde vive el default, y los dos se desincronizan sin aviso.
 */
class AsistenteWhatsappSettings
{
    /** Clave: los mensajes de clientes por WhatsApp abren ticket de soporte. */
    public const KEY_TICKETS_ENABLED = 'support_whatsapp_tickets_enabled';

    /** Clave: texto que se le manda a quien escribe con los tickets desconectados. */
    public const KEY_DESCONECTADO_TEXTO = 'support_whatsapp_desconectado_texto';

    /** Clave: nombre de la plantilla de Meta con la que salen los informes de la mañana. */
    public const KEY_INFORME_TEMPLATE_NAME = 'asistente_informe_template_name';

    /** Clave: idioma con el que esa plantilla quedó aprobada en Meta. */
    public const KEY_INFORME_TEMPLATE_LANGUAGE = 'asistente_informe_template_language';

    /** Idioma por defecto de la plantilla, el mismo que usan las de cliente. */
    private const DEFAULT_INFORME_TEMPLATE_LANGUAGE = 'es_AR';

    /**
     * Indica si un mensaje de cliente por WhatsApp todavía abre un ticket de soporte.
     *
     * 🔴 El default es APAGADO, al revés del reflejo habitual de "la fila que falta no cambia
     * nada". Es la decisión de esta misión: el canal de soporte por este número se desconecta, y
     * si dependiera de que alguien se acuerde de escribir la fila, no se desconectaría nunca.
     * Prenderlo es explícito: la fila tiene que decir que sí.
     *
     * Se listan los valores que PRENDEN y todo lo demás deja el canal apagado, que es el lado
     * seguro: con esto prendido por error, un dueño que le habla a su asistente abre además un
     * ticket que nadie va a contestar.
     *
     * @return bool
     */
    public static function tickets_habilitados(): bool
    {
        $raw = AdminSetting::get(self::KEY_TICKETS_ENABLED, null);

        $normalizado = $raw === null ? '' : strtolower(trim((string) $raw));

        return in_array($normalizado, ['1', 'true', 'on', 'si', 'sí', 'yes'], true);
    }

    /**
     * Texto que se le manda a quien escribe por soporte con el canal desconectado.
     *
     * Vacío (el default) significa NO CONTESTAR NADA. Es a propósito: el mensaje queda registrado
     * en el log del webhook, pero del otro lado no aparece ningún robot diciendo algo genérico.
     *
     * @return string Cadena vacía si nadie cargó un texto.
     */
    public static function texto_de_soporte_desconectado(): string
    {
        return trim((string) AdminSetting::get(self::KEY_DESCONECTADO_TEXTO, ''));
    }

    /**
     * Nombre de la plantilla de Meta con la que salen los informes de la mañana.
     *
     * @return string Cadena vacía si no hay ninguna configurada.
     */
    public static function plantilla_de_informes(): string
    {
        return trim((string) AdminSetting::get(self::KEY_INFORME_TEMPLATE_NAME, ''));
    }

    /**
     * Idioma con el que esa plantilla quedó aprobada en Meta.
     *
     * @return string
     */
    public static function idioma_de_la_plantilla_de_informes(): string
    {
        $idioma = trim((string) AdminSetting::get(self::KEY_INFORME_TEMPLATE_LANGUAGE, ''));

        return $idioma !== '' ? $idioma : self::DEFAULT_INFORME_TEMPLATE_LANGUAGE;
    }
}
