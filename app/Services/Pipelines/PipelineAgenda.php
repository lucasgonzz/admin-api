<?php

namespace App\Services\Pipelines;

use App\Helpers\AppTime;
use App\Models\PipelineStage;
use Carbon\Carbon;

/**
 * El cálculo de en qué "balde" de la agenda cae una oportunidad (misión pipelines-crm, 27/9/2026).
 *
 * 🔴 ES EL ÚNICO. Lo usan la tarjeta del tablero (`agenda_bucket`), el filtro `agenda=` del
 * tablero, los contadores del resumen (chips "Vencidas" / "Hoy") y la vista de Agenda. Si cada
 * endpoint decidiera por su cuenta qué es "vencida", el chip diría 3 y la agenda mostraría 4.
 *
 * Reglas, con `AppTime::now()` en hora de Argentina:
 *  - etapa won / lost → `closed` (una cerrada no tiene próxima acción que vencer);
 *  - sin `next_action_at` → `none`;
 *  - antes de hoy a las 00:00 → `overdue`;
 *  - hoy (a cualquier hora, aunque ya haya pasado) → `today`. Una fecha SIN hora se guarda como
 *    00:00:00 y por eso cae en `today`, no en `overdue`: "retomar el 27" no está vencido el 27;
 *  - de mañana a hoy + 7 días (exclusivo del día 8) → `week`;
 *  - después → `later`.
 */
class PipelineAgenda
{
    const OVERDUE = 'overdue';
    const TODAY   = 'today';
    const WEEK    = 'week';
    const LATER   = 'later';
    const NONE    = 'none';
    const CLOSED  = 'closed';

    /**
     * Los baldes de una oportunidad abierta, en el orden de la vista de Agenda.
     *
     * @var array<int, string>
     */
    const BALDES_ABIERTOS = [self::OVERDUE, self::TODAY, self::WEEK, self::LATER, self::NONE];

    /**
     * Balde de una oportunidad.
     *
     * @param string|null              $tipo_de_etapa  `type` de su etapa actual.
     * @param \DateTimeInterface|null  $next_action_at Próxima acción (hora local) o null.
     * @param Carbon|null              $ahora          Reloj (default `AppTime::now()`); se pasa
     *                                                 para calcular una lista entera con el mismo
     *                                                 instante.
     *
     * @return string overdue | today | week | later | none | closed
     */
    public static function balde($tipo_de_etapa, $next_action_at, $ahora = null)
    {
        if ($tipo_de_etapa !== PipelineStage::TYPE_OPEN) {
            return self::CLOSED;
        }

        if ($next_action_at === null) {
            return self::NONE;
        }

        $ahora = $ahora !== null ? $ahora : AppTime::now();

        $hoy           = $ahora->copy()->startOfDay();
        $manana        = $hoy->copy()->addDay();
        $fin_de_semana = $hoy->copy()->addDays(8);

        $momento = Carbon::instance($next_action_at);

        if ($momento->lt($hoy)) {
            return self::OVERDUE;
        }

        if ($momento->lt($manana)) {
            return self::TODAY;
        }

        if ($momento->lt($fin_de_semana)) {
            return self::WEEK;
        }

        return self::LATER;
    }
}
