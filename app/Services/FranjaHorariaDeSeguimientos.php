<?php

namespace App\Services;

use App\Helpers\AppTime;
use Carbon\Carbon;

/**
 * Franja horaria en la que pueden salir los seguimientos AUTOMÁTICOS de lead: de 09:00 a 21:00, hora
 * de Argentina (misión asistente-arreglos-segunda-prueba, N11, 19/10/2026).
 *
 * 🔴 Por qué existe: `leads:check-followups` corre cada dos horas (`Kernel.php`, `everyTwoHours()`,
 * o sea a las 00, 02, 04…) y no miraba la hora. El 18/10/2026 a las 00:00 le mandó «Hace un par de
 * días te escribimos…» al dueño de demo3. Un mensaje comercial a medianoche no lo manda una persona,
 * y tampoco lo tiene que mandar el cron.
 *
 * La usan los tres caminos automáticos que le escriben a un lead sin que nadie apriete nada:
 *   - `LeadFollowupService::process_all_active_leads()`: fuera de la franja no procesa a nadie (la
 *     primera corrida dentro de la franja, las 10:00, los toma igual, porque el cron decide por horas
 *     desde el último mensaje y de noche no se escribe nada);
 *   - el respaldo de auto-envío de un seguimiento retenido (`LeadAiSuggestionAutoSendScheduler` /
 *     `AutoSendLeadAiSuggestionJob`): si vence de noche se reprograma al próximo inicio de la franja;
 *   - `leads:check-pagina-sin-demo`: de noche no manda ni marca, y la primera corrida de la mañana lo
 *     vuelve a evaluar.
 * NO la usan el botón manual de forzar seguimiento, los mensajes que programa un operador ni los
 * recordatorios atados a una demo agendada: ahí hay una persona o un turno detrás.
 *
 * Todo se mide en hora de Argentina sobre el instante que se pasa, sea cual sea su zona; el «ahora» de
 * los que la usan sale de {@see AppTime::now()}, para que los tests (y el reloj virtual de local)
 * puedan mover la hora.
 */
class FranjaHorariaDeSeguimientos
{
    /** Zona horaria de la franja: la del negocio y la del admin (`config/app.php`). */
    public const ZONA_HORARIA = 'America/Argentina/Buenos_Aires';

    /**
     * Hora (0-23) a la que ABRE la franja: desde las 09:00:00 en punto ya se puede mandar. Es la hora
     * de arranque de un comercio, y la misma a la que corre el aviso diario de clientes sin teléfono
     * (`support:check-clients-without-phone`, `dailyAt('09:00')`).
     */
    public const HORA_DE_INICIO = 9;

    /**
     * Hora (0-23) a la que CIERRA la franja, exclusiva: a las 20:59:59 todavía se manda, a las
     * 21:00:00 ya no. Con el cron cada dos horas en horas pares, la última corrida que procesa es la
     * de las 20:00 y la primera de la mañana la de las 10:00.
     */
    public const HORA_DE_FIN = 21;

    /**
     * ¿Este instante está dentro de la franja? [HORA_DE_INICIO:00, HORA_DE_FIN:00), hora de Argentina.
     *
     * @param Carbon $momento Instante a evaluar, en cualquier zona horaria.
     *
     * @return bool
     */
    public static function esta_dentro(Carbon $momento): bool
    {
        $hora = (int) $momento->copy()->setTimezone(self::ZONA_HORARIA)->hour;

        return $hora >= self::HORA_DE_INICIO && $hora < self::HORA_DE_FIN;
    }

    /**
     * ¿AHORA está dentro de la franja? Usa {@see AppTime::now()} (el reloj que mueven los tests).
     *
     * @return bool
     */
    public static function ahora_esta_dentro(): bool
    {
        return self::esta_dentro(AppTime::now(self::ZONA_HORARIA));
    }

    /**
     * El primer instante en que se puede mandar a partir de `$momento`: él mismo si ya está dentro de
     * la franja; si no, las HORA_DE_INICIO:00 de ese mismo día (si es de madrugada) o del día siguiente
     * (si es después del cierre). Se devuelve en hora de Argentina.
     *
     * @param Carbon $momento Instante a partir del cual se busca, en cualquier zona horaria.
     *
     * @return Carbon Copia nueva (no modifica `$momento`).
     */
    public static function proximo_momento_permitido(Carbon $momento): Carbon
    {
        $local = $momento->copy()->setTimezone(self::ZONA_HORARIA);

        if (self::esta_dentro($local)) {
            return $local;
        }

        $inicio_de_hoy = $local->copy()->setTime(self::HORA_DE_INICIO, 0, 0);
        if ($local->lt($inicio_de_hoy)) {
            return $inicio_de_hoy;
        }

        return $inicio_de_hoy->addDay();
    }
}
