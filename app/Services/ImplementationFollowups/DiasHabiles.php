<?php

namespace App\Services\ImplementationFollowups;

use Carbon\Carbon;

/**
 * Días hábiles y horario del seguimiento de implementaciones (misión cruzada `seguimiento-de-implementaciones`,
 * 11/11/2026).
 *
 * Hábil = lunes a viernes, sin feriados (decisión de la misión: los feriados quedan afuera). Todo se cuenta en hora
 * de Argentina sobre el instante que se pasa, sea cual sea su zona. El repo no tenía un helper de días hábiles
 * (`FranjaHorariaDeSeguimientos` es la franja de los leads, de 09 a 21 y todos los días).
 */
class DiasHabiles
{
    /** La zona del negocio y del admin. */
    const ZONA_HORARIA = 'America/Argentina/Buenos_Aires';

    /** Hora a la que vence un seguimiento (y abre el horario de `pending`). */
    const HORA_DE_VENCIMIENTO = 10;

    /** Hora a la que cierra el horario de `pending` (exclusiva: a las 18:00 ya no). */
    const HORA_DE_CIERRE = 18;

    /**
     * `$dias` días hábiles después del DÍA de `$desde`, a las 10:00 de Argentina.
     *
     * Se cuenta por días: un contacto del lunes a las 15:00 con 2 días da el miércoles a las 10:00; uno del sábado
     * da el martes a las 10:00 (lunes 1, martes 2).
     *
     * @param Carbon $desde El instante de partida.
     * @param int    $dias  Cuántos días hábiles (mínimo 1).
     *
     * @return Carbon En zona de Argentina.
     */
    public static function sumar(Carbon $desde, $dias)
    {
        $dia   = $desde->copy()->setTimezone(self::ZONA_HORARIA)->startOfDay();
        $falta = max(1, (int) $dias);

        while ($falta > 0) {
            $dia->addDay();
            if (! $dia->isWeekend()) {
                $falta--;
            }
        }

        return $dia->setTime(self::HORA_DE_VENCIMIENTO, 0, 0);
    }

    /**
     * El primer día hábil DESPUÉS de `$fecha`, a las 10:00 de Argentina («pausado hasta el viernes» vence el lunes).
     *
     * @param Carbon $fecha La fecha (se toma el día).
     *
     * @return Carbon
     */
    public static function primero_despues_de(Carbon $fecha)
    {
        return self::sumar($fecha, 1);
    }

    /**
     * Si el instante cae en el horario en que Martín escribe seguimientos: lunes a viernes de 10:00 a 18:00 de
     * Argentina.
     *
     * @param Carbon $instante El instante.
     *
     * @return bool
     */
    public static function en_horario(Carbon $instante)
    {
        $local = $instante->copy()->setTimezone(self::ZONA_HORARIA);

        return ! $local->isWeekend()
            && (int) $local->hour >= self::HORA_DE_VENCIMIENTO
            && (int) $local->hour < self::HORA_DE_CIERRE;
    }
}
