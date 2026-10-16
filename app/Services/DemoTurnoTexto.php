<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Models\Lead;
use Carbon\Carbon;

/**
 * El turno de la demo de un lead, dicho en palabras para el mail (misión mail-demo-turno-y-asistente,
 * 16/10/2026).
 *
 * La demo directa no se agenda: cuando el lead acepta, el sistema le asigna una instancia libre y
 * escribe en el lead el día, la hora de inicio (diez minutos después) y la hora de fin del turno
 * (`demo_date`, `demo_start_time`, `demo_end_time`). Lucas pidió que el mail diga "el día y hora de
 * su demo", y eso es exactamente este dato: el turno real, el mismo que el agente le dice por el
 * chat y el que muestra la página de experiencia. Esta clase lo traduce a texto, y es el único
 * lugar que lo hace, para que el asunto y el cuerpo del mail no puedan decir dos cosas distintas.
 *
 * 🔴 No inventa nada. Si el lead no tiene fecha, hora de inicio y hora de fin legibles, devuelve
 * null y el mail sale SIN bloque de turno (y con el asunto de siempre): una hora supuesta en un
 * mail que el lead va a guardar es peor que ninguna.
 *
 * Los números de horas van en LETRAS ("dos horas"), no en dígitos: es la misma restricción que
 * `LeadAiService::frase_de_la_reserva()` (lo que se le dice al lead no puede nombrar un número de
 * horas con sufijo `hs`). Las horas de reloj ("15:10") sí van en dígitos, porque son la hora real.
 */
class DemoTurnoTexto
{
    /**
     * Arma las frases del turno de un lead.
     *
     * @param Lead        $lead  Lead con la demo ya asignada.
     * @param Carbon|null $ahora Instante de referencia (reloj de la app si no viene); sirve para decir
     *                           "hoy" o "mañana" en vez de la fecha.
     *
     * @return array{
     *     fecha_larga: string, es_hoy: bool, hora_inicio: string, hora_fin: string,
     *     reserva: string|null, duracion: string, cuando_para_el_asunto: string
     * }|null Null si el lead no tiene un turno completo y legible, o si el turno ya terminó.
     */
    public static function de_lead(Lead $lead, ?Carbon $ahora = null): ?array
    {
        if ($lead->demo_date === null) {
            return null;
        }

        $inicio = self::hhmm((string) $lead->demo_start_time);
        $fin    = self::hhmm((string) $lead->demo_end_time);
        if ($inicio === null || $fin === null) {
            return null;
        }

        $ahora = $ahora !== null
            ? $ahora->copy()->setTimezone(DemoDirectaService::TZ)
            : AppTime::now(DemoDirectaService::TZ);

        $dia  = Carbon::parse($lead->demo_date->format('Y-m-d'), DemoDirectaService::TZ)->startOfDay();

        /* Un turno que ya terminó no se anuncia: el reenvío del mail al día siguiente no puede decir
         * "tu demo es hoy" con la fecha de ayer ni "es el miércoles" de una demo que pasó. */
        if ($dia->copy()->addMinutes($fin['minutos'])->lt($ahora)) {
            return null;
        }

        $hoy  = $ahora->copy()->startOfDay();
        $dias = (int) $hoy->diffInDays($dia, false);

        $fecha_larga = $dia->copy()->locale('es')->isoFormat('dddd D [de] MMMM');

        if ($dias === 0) {
            $cuando = 'hoy';
        } elseif ($dias === 1) {
            $cuando = 'mañana';
        } else {
            $cuando = 'el ' . $fecha_larga;
        }

        return [
            'fecha_larga'           => $fecha_larga,
            'es_hoy'                => $dias === 0,
            'hora_inicio'           => $inicio['texto'],
            'hora_fin'              => $fin['texto'],
            'reserva'               => self::reserva_en_letras($inicio['minutos'], $fin['minutos']),
            'duracion'              => self::duracion_en_palabras(),
            'cuando_para_el_asunto' => $cuando . ' a las ' . $inicio['texto'],
        ];
    }

    /**
     * Cuánto dura la demo, en palabras ("menos de una hora"), según la configuración.
     *
     * Es la misma cuenta de `LeadAiService::frase_de_la_reserva()`: el siguiente número entero de
     * horas ESTRICTAMENTE mayor que la duración, para que el "menos de" nunca mienta.
     *
     * @return string
     */
    public static function duracion_en_palabras(): string
    {
        $duracion_minutos = LeadDemoSettings::get_duracion_minutos();

        if ($duracion_minutos <= 60) {
            return 'menos de una hora';
        }

        return 'menos de ' . LeadAiService::horas_en_letras((int) floor($duracion_minutos / 60) + 1);
    }

    /**
     * El largo del turno en letras ("dos horas"), o null si no es un número entero de horas.
     *
     * Cerca de la medianoche el turno viene recortado a las 23:59 y ahí "reservada dos horas" sería
     * falso: sin un número redondo de horas se omite y el mail solo dice hasta qué hora.
     *
     * @param int $inicio Minutos desde la medianoche.
     * @param int $fin    Minutos desde la medianoche.
     *
     * @return string|null
     */
    private static function reserva_en_letras(int $inicio, int $fin): ?string
    {
        $minutos = $fin - $inicio;
        if ($minutos <= 0 || $minutos % 60 !== 0) {
            return null;
        }

        $horas = intdiv($minutos, 60);

        return ($horas >= 1 && $horas <= 12) ? LeadAiService::horas_en_letras($horas) : null;
    }

    /**
     * Una hora "H:MM" / "HH:MM" / "HH:MM:SS" como texto "HH:MM" y como minutos desde la medianoche.
     *
     * @param string $hora
     *
     * @return array{texto: string, minutos: int}|null Null si no se lee.
     */
    private static function hhmm(string $hora): ?array
    {
        if (! preg_match('/^\s*(\d{1,2}):(\d{2})/', $hora, $m)) {
            return null;
        }

        $horas   = (int) $m[1];
        $minutos = (int) $m[2];
        if ($horas > 23 || $minutos > 59) {
            return null;
        }

        return [
            'texto'   => sprintf('%02d:%02d', $horas, $minutos),
            'minutos' => $horas * 60 + $minutos,
        ];
    }
}
