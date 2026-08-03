<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\AdminCalendarConnection;
use App\Models\Lead;
use App\Helpers\AppTime;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Disponibilidad real del closer: horario laboral + Google Calendar, unificados en un solo lugar.
 *
 * El grupo 306 sacó al closer del cálculo de disponibilidad de la DEMO (se ofrece siempre, lo antes
 * posible). La contrapartida es que su agenda hay que consultarla en el otro extremo del flujo,
 * cuando el lead termina la demo y quiere avanzar (grupo 307). Este servicio junta las piezas que ya
 * existían sueltas: el horario por día de `LeadDemoSettings`, los rangos ocupados de
 * `CloserGoogleCalendarBusyService` (con su caché de 5 minutos), y la duración/gracia de la llamada.
 *
 * Solo lectura: no crea eventos ni toca leads (salvo limpiar `closer_hold_event_id` cuando
 * `is_hold_still_valid()` detecta que el evento ya no existe en Google, igual que ya hace
 * `CloserGoogleCalendarEventService::mark_hold_as_demo_en_curso()` ante el mismo 404). Escribir
 * eventos nuevos es del prompt 03.
 */
class CloserAgendaService
{
    /**
     * Tope de días hacia adelante que recorre next_slots(). Misma ventana que ya usa la
     * disponibilidad de demos: no hace falta una más ancha para ofrecer horario de closer.
     */
    private const DIAS_VENTANA = 14;

    /** Nombres de los días de la semana en castellano, indexados como Carbon::dayOfWeek (0=domingo). */
    private const NOMBRES_DIA = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    /** @var CloserGoogleCalendarBusyService */
    protected $busy_service;

    /** @var GoogleCalendarOAuthService */
    protected $oauth_service;

    /**
     * @param CloserGoogleCalendarBusyService $busy_service  Rangos ocupados + caché de 5 minutos.
     * @param GoogleCalendarOAuthService      $oauth_service Autenticación OAuth Google (para is_hold_still_valid).
     */
    public function __construct(
        CloserGoogleCalendarBusyService $busy_service,
        GoogleCalendarOAuthService $oauth_service
    ) {
        $this->busy_service  = $busy_service;
        $this->oauth_service = $oauth_service;
    }

    /**
     * ¿El closer puede tomar una llamada AHORA MISMO?
     *
     * Dos condiciones: el rango [ahora, ahora + duración] entra en su horario laboral de hoy
     * (respetando `llamada_debe_terminar_en_horario`, igual que `create_hold_for_lead()`), y no hay
     * ningún evento de Google solapando ese rango.
     *
     * $lead es OPCIONAL y no está en el enunciado original del prompt como parámetro, pero el
     * criterio de éxito 5 lo exige: si a este lead se le reservó preventivamente este mismo hueco
     * (grupo 306, prompt 05), esa reserva NO puede contar como "el closer está ocupado" — si no, la
     * llamada encadenada (el caso más valioso) nunca se ofrecería. Se recalcula el rango del hold
     * con la misma fórmula que `CloserGoogleCalendarEventService::calculate_event_times()` (no hay
     * forma de pedirle a Google "excluí este evento" desde acá sin otra llamada a la API) y se
     * descarta de los rangos ocupados cualquier bloque que lo solape.
     *
     * @param Lead|null $lead Lead cuyo hold propio no debe contar como ocupado, si tiene uno.
     *
     * @return bool
     */
    public function is_free_now(?Lead $lead = null): bool
    {
        $now      = AppTime::now();
        $duracion = LeadDemoSettings::get_duracion_llamada_closer_minutos();
        $fin      = $now->copy()->addMinutes($duracion);

        if (! $this->dentro_de_horario_closer($now, $fin)) {
            return false;
        }

        $fecha_str = $now->format('Y-m-d');

        // Invalidar la caché ANTES de consultar: un dato de hace 5 minutos alcanza para OFRECER
        // horarios, no para decidir si el closer está libre AHORA -- de esa decisión depende que se
        // le meta una llamada encima de otra cosa.
        $this->busy_service->flush_cache_for_date($fecha_str);

        try {
            $busy_result = $this->busy_service->get_busy_ranges_for_dates([$fecha_str]);
        } catch (\Throwable $e) {
            // Fail-soft AL REVÉS que next_slots(): con Google caído, la respuesta segura es "no
            // libre" -- un falso positivo acá mete una llamada encima de otra cosa del closer.
            Log::channel('disponibilidad')->error(
                '[CLOSER_AGENDA] Excepción al consultar Google Calendar en is_free_now().'
                . ' error=' . $e->getMessage()
            );

            return false;
        }

        // CloserGoogleCalendarBusyService NO propaga los fallos de Google como excepción: un token
        // revocado o un error de la API de freeBusy queda registrado en el snapshot (estado
        // "token_revocado" / "error_api") y el closer se excluye en silencio de "ranges", que queda
        // vacío -- indistinguible de "de verdad no tiene nada ocupado". Como flush_cache_for_date()
        // se llamó recién arriba, esta consulta es SIEMPRE fresca (nunca cache hit), así que el
        // snapshot de este resultado puntual sí refleja el fetch real. Si algún closer falló, no hay
        // forma confiable de saber si está libre: responder false por seguridad, igual que ante una
        // excepción.
        foreach ($busy_result['snapshot']['closers'] ?? [] as $closer_entry) {
            $estado_closer = $closer_entry['estado'] ?? '';
            if (in_array($estado_closer, ['error_api', 'token_revocado'], true)) {
                Log::channel('disponibilidad')->error(
                    '[CLOSER_AGENDA] No se pudo confirmar disponibilidad de Google Calendar en is_free_now();'
                    . ' se responde false por seguridad.'
                    . ' admin_id=' . ($closer_entry['admin_id'] ?? '?')
                    . ' estado=' . $estado_closer
                );

                return false;
            }
        }

        $busy_ranges = $busy_result['ranges'][$fecha_str] ?? [];
        $busy_ranges = $this->excluir_hold_propio($busy_ranges, $lead, $fecha_str);

        $inicio_minutos = $now->hour * 60 + $now->minute;
        $fin_minutos    = $fin->hour * 60 + $fin->minute;

        foreach ($busy_ranges as $rango) {
            [$bstart, $bend] = $rango;
            if ($inicio_minutos < $bend && $fin_minutos > $bstart) {
                return false;
            }
        }

        return true;
    }

    /**
     * Los próximos $cantidad huecos del closer, en orden cronológico, desde $desde (default: ahora).
     *
     * Recorre día por día hasta DIAS_VENTANA. Por cada día, genera candidatos dentro del horario
     * laboral con la frecuencia configurada -- el rango completo [inicio, inicio+duración] tiene que
     * caer dentro del horario, no solo el inicio -- y descarta los que solapen con Google Calendar.
     * Para hoy, además descarta lo que empiece antes de ahora + gracia.
     *
     * Fail-soft: si Google Calendar falla para una fecha, esa fecha se ofrece solo por horario
     * laboral (sin filtrar ocupados) y se loguea -- es preferible ofrecer un horario que después haya
     * que mover, a no poder ofrecer ninguno. Contrario a is_free_now(), que ante el mismo fallo
     * responde `false`: ahí el error es "prometer un horario que no está" y acá es "no decidir nada".
     *
     * @param int        $cantidad Cantidad de huecos a devolver.
     * @param Carbon|null $desde   Momento desde el cual buscar (default: ahora).
     *
     * @return array<int, array{inicio: Carbon, fin: Carbon, label: string}>
     */
    public function next_slots(int $cantidad = 3, ?Carbon $desde = null): array
    {
        $now   = AppTime::now();
        $desde = $desde ?? $now;

        $duracion   = LeadDemoSettings::get_duracion_llamada_closer_minutos();
        $frecuencia = LeadDemoSettings::get_frecuencia_slots_minutos();
        $gracia     = LeadDemoSettings::get_gracia_minutos_post();

        $today_key = $now->format('Y-m-d');
        $desde_key = $desde->format('Y-m-d');

        $slots = [];

        for ($i = 0; $i < self::DIAS_VENTANA && count($slots) < $cantidad; $i++) {
            $day      = $desde->copy()->startOfDay()->addDays($i);
            $date_str = $day->format('Y-m-d');

            $horario_raw = $this->horario_closer_para_dia($day->dayOfWeek);
            if ($horario_raw === '') {
                // El closer no trabaja este día (horario vacío, ver LeadDemoSettings).
                continue;
            }

            [$horario_inicio, $horario_fin] = $this->parse_horario_minutos($horario_raw);
            if ($horario_inicio === null) {
                continue;
            }

            try {
                $busy_result = $this->busy_service->get_busy_ranges_for_dates([$date_str]);
                $busy_ranges = $busy_result['ranges'][$date_str] ?? [];
            } catch (\Throwable $e) {
                Log::channel('disponibilidad')->error(
                    '[CLOSER_AGENDA] Excepción al consultar Google Calendar en next_slots();'
                    . ' se ofrece esta fecha solo por horario laboral, sin filtrar ocupados.'
                    . ' fecha=' . $date_str
                    . ' error=' . $e->getMessage()
                );
                $busy_ranges = [];
            }

            /*
             * Cota inferior real del día: nada antes de $desde (si este es su día), y si además es
             * el día calendario de HOY, tampoco antes de ahora + gracia -- no sirve ofrecerle al
             * lead una llamada que arranca en 40 segundos. Las dos cotas son independientes: con
             * $desde=ahora (el caso default) coinciden y no cambia nada; con un $desde futuro (ej.
             * "buscar desde mañana a las 15:00"), la cota de $desde por sí sola ya evita ofrecer
             * huecos de la mañana de ese día, aunque no sea "hoy".
             */
            $limite_inferior_min = null;
            if ($date_str === $desde_key) {
                $limite_inferior_min = $desde->hour * 60 + $desde->minute;
            }
            if ($date_str === $today_key) {
                $margen_hoy           = $now->hour * 60 + $now->minute + $gracia;
                $limite_inferior_min  = $limite_inferior_min === null ? $margen_hoy : max($limite_inferior_min, $margen_hoy);
            }

            for ($slot_min = $horario_inicio; $slot_min + $duracion <= $horario_fin; $slot_min += $frecuencia) {
                if ($limite_inferior_min !== null && $slot_min < $limite_inferior_min) {
                    continue;
                }

                $slot_end_min = $slot_min + $duracion;
                $libre        = true;

                foreach ($busy_ranges as $rango) {
                    [$bstart, $bend] = $rango;
                    if ($slot_min < $bend && $slot_end_min > $bstart) {
                        $libre = false;
                        break;
                    }
                }

                if (! $libre) {
                    continue;
                }

                $inicio = $day->copy()->addMinutes($slot_min);
                $fin    = $inicio->copy()->addMinutes($duracion);

                $slots[] = [
                    'inicio' => $inicio,
                    'fin'    => $fin,
                    'label'  => $this->build_label($inicio, $now),
                ];

                if (count($slots) >= $cantidad) {
                    break;
                }
            }
        }

        return $slots;
    }

    /**
     * ¿La reserva preventiva de este lead (grupo 306, prompt 05) sigue siendo usable?
     *
     * Tres condiciones: `closer_hold_event_id` no vacío, el evento sigue existiendo en Google (un
     * 404 -- el closer lo borró a mano -- no es un error: se limpia la columna y se loguea como
     * info, mismo criterio que `mark_hold_as_demo_en_curso()`), y su rango no quedó en el pasado.
     *
     * @param Lead $lead
     *
     * @return bool
     */
    public function is_hold_still_valid(Lead $lead): bool
    {
        if (empty($lead->closer_hold_event_id)) {
            return false;
        }

        $connection = $this->get_closer_connection();
        if (! $connection) {
            return false;
        }

        try {
            $access_token = $this->oauth_service->get_fresh_access_token($connection);

            $response = Http::withToken($access_token)
                ->get(
                    'https://www.googleapis.com/calendar/v3/calendars/'
                        . urlencode($connection->google_calendar_id)
                        . '/events/' . urlencode($lead->closer_hold_event_id)
                );

            if ($response->status() === 404) {
                // El closer borró el evento a mano: no es un error del sistema (mismo criterio que
                // CloserGoogleCalendarEventService::mark_hold_as_demo_en_curso()).
                Log::channel('disponibilidad')->info(
                    '[CLOSER_AGENDA] La reserva preventiva ya no existe en Google (404).'
                    . ' lead_id=' . $lead->id
                    . ' closer_hold_event_id=' . $lead->closer_hold_event_id
                );
                $lead->closer_hold_event_id = null;
                $lead->save();

                return false;
            }

            if ($response->failed()) {
                Log::channel('disponibilidad')->warning(
                    '[CLOSER_AGENDA] Error al consultar la reserva preventiva en Google.'
                    . ' lead_id=' . $lead->id
                    . ' HTTP=' . $response->status()
                );

                return false;
            }

            $end_str = $response->json('end.dateTime');
            if (! $end_str) {
                return false;
            }

            $event_end = Carbon::parse($end_str)->setTimezone('America/Argentina/Buenos_Aires');

            return $event_end->gt(AppTime::now());
        } catch (\Throwable $e) {
            Log::channel('disponibilidad')->error(
                '[CLOSER_AGENDA] Excepción al verificar la reserva preventiva.'
                . ' lead_id=' . $lead->id
                . ' error=' . $e->getMessage()
            );

            return false;
        }
    }

    /**
     * Excluye de $busy_ranges el bloque que corresponde al hold propio de $lead, si tiene uno.
     *
     * No hay forma barata de pedirle a Google "los ocupados salvo este evento" (habría que resolver
     * el evento por id, otra llamada a la API): se recalcula el rango con la misma fórmula que
     * `CloserGoogleCalendarEventService::calculate_event_times()` (inicio = fin de la demo, fin =
     * gracia + duración de la llamada) y se le RESTA esa porción a cualquier rango ocupado que lo
     * solape -- nunca se descarta el rango entero. Google fusiona eventos contiguos en un solo
     * bloque de freeBusy: si una reunión real quedó pegada al hold (ej. hold 15:00-15:40 y una
     * reunión de 15:40 a 17:00), el bloque ocupado que devuelve Google es uno solo, [15:00,17:00].
     * Descartarlo entero haría desaparecer la reunión real junto con el hold. Asume que el hold
     * sigue en el horario para el que se creó -- si el closer lo movió a mano en Google, esta
     * exclusión puede quedar desalineada; es la misma asunción que ya hace el resto del ciclo de
     * hold (nadie vuelve a leer el horario real del evento salvo `is_hold_still_valid()`).
     *
     * @param array<int, array{0: int, 1: int}> $busy_ranges
     * @param Lead|null                          $lead
     * @param string                             $fecha_str Fecha Y-m-d que se está evaluando.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private function excluir_hold_propio(array $busy_ranges, ?Lead $lead, string $fecha_str): array
    {
        if ($lead === null || empty($lead->closer_hold_event_id) || ! $lead->demo_date || ! $lead->demo_start_time) {
            return $busy_ranges;
        }

        if ($lead->demo_date->format('Y-m-d') !== $fecha_str) {
            return $busy_ranges;
        }

        [$hold_start, $hold_end] = $this->calcular_rango_hold($lead);
        if (! $hold_start->isSameDay($hold_end) || $hold_start->format('Y-m-d') !== $fecha_str) {
            return $busy_ranges;
        }

        $hold_start_min = $hold_start->hour * 60 + $hold_start->minute;
        $hold_end_min   = $hold_end->hour * 60 + $hold_end->minute;

        $resultado = [];
        foreach ($busy_ranges as $rango) {
            [$bstart, $bend] = $rango;

            // Sin solapamiento con el hold: el rango ocupado queda intacto.
            if ($hold_end_min <= $bstart || $hold_start_min >= $bend) {
                $resultado[] = [$bstart, $bend];
                continue;
            }

            // Con solapamiento: restar solo la porción del hold. Puede sobrevivir un tramo antes,
            // uno después, los dos, o ninguno (si el hold cubre el rango entero).
            if ($bstart < $hold_start_min) {
                $resultado[] = [$bstart, $hold_start_min];
            }
            if ($hold_end_min < $bend) {
                $resultado[] = [$hold_end_min, $bend];
            }
        }

        return $resultado;
    }

    /**
     * Recalcula [inicio, fin] del hold del closer para $lead, con la misma fórmula que
     * `CloserGoogleCalendarEventService::calculate_event_times()` (protected, en otro servicio).
     *
     * @param Lead $lead
     *
     * @return Carbon[] [event_start, event_end]
     */
    private function calcular_rango_hold(Lead $lead): array
    {
        $tz = 'America/Argentina/Buenos_Aires';

        $duracion_demo    = LeadDemoSettings::get_duracion_minutos();
        $gracia_post      = LeadDemoSettings::get_gracia_minutos_post();
        $duracion_llamada = LeadDemoSettings::get_duracion_llamada_closer_minutos();

        $demo_start_carbon = Carbon::parse($lead->demo_date->format('Y-m-d') . ' ' . $lead->demo_start_time, $tz);

        $event_start = $demo_start_carbon->copy()->addMinutes($duracion_demo);
        $event_end   = $event_start->copy()->addMinutes($gracia_post + $duracion_llamada);

        return [$event_start, $event_end];
    }

    /**
     * Verifica que [$start, $end] entre en el horario laboral del closer para el día de semana de
     * $start, respetando `llamada_debe_terminar_en_horario`. Mismo criterio que
     * `CloserGoogleCalendarEventService::rango_dentro_de_horario_closer()` (private ahí, duplicado
     * acá -- este prompt solo puede tocar este archivo nuevo).
     *
     * @param Carbon $start
     * @param Carbon $end
     *
     * @return bool
     */
    private function dentro_de_horario_closer(Carbon $start, Carbon $end): bool
    {
        if (! $start->isSameDay($end)) {
            return false;
        }

        $horario_raw = $this->horario_closer_para_dia($start->dayOfWeek);
        if ($horario_raw === '') {
            return false;
        }

        [$horario_inicio, $horario_fin] = $this->parse_horario_minutos($horario_raw);
        if ($horario_inicio === null) {
            return false;
        }

        $inicio_minutos = $start->hour * 60 + $start->minute;
        $fin_minutos    = $end->hour * 60 + $end->minute;

        if ($inicio_minutos < $horario_inicio || $inicio_minutos > $horario_fin) {
            return false;
        }

        if (LeadDemoSettings::get_llamada_debe_terminar_en_horario() && $fin_minutos > $horario_fin) {
            return false;
        }

        return true;
    }

    /**
     * Horario laboral del closer (string "H:i-H:i" o vacío) para un día de semana dado.
     *
     * @param int $dow Carbon::dayOfWeek (0 = domingo, 6 = sábado).
     *
     * @return string
     */
    private function horario_closer_para_dia(int $dow): string
    {
        if ($dow === 0) {
            return LeadDemoSettings::get_closer_horario_domingo();
        }
        if ($dow === 6) {
            return LeadDemoSettings::get_closer_horario_sabado();
        }

        return LeadDemoSettings::get_closer_horario_lunes_viernes();
    }

    /**
     * Parsea un horario "H:i-H:i" a minutos del día [inicio, fin]. Devuelve [null, null] si el
     * formato es inválido.
     *
     * @param string $horario_raw
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function parse_horario_minutos(string $horario_raw): array
    {
        $partes = explode('-', $horario_raw);
        if (count($partes) !== 2) {
            return [null, null];
        }

        if (! preg_match('/(\d{1,2}):(\d{2})/', $partes[0], $mi) || ! preg_match('/(\d{1,2}):(\d{2})/', $partes[1], $mf)) {
            return [null, null];
        }

        return [(int) $mi[1] * 60 + (int) $mi[2], (int) $mf[1] * 60 + (int) $mf[2]];
    }

    /**
     * Label legible en castellano rioplatense para un horario: "hoy a las 18:30",
     * "mañana a las 9:00", "el jueves a las 15:00". Lo consume el bloque de contexto del agente
     * (prompt 04), así que termina tal cual en un mensaje al lead.
     *
     * @param Carbon $inicio
     * @param Carbon $now
     *
     * @return string
     */
    private function build_label(Carbon $inicio, Carbon $now): string
    {
        $hora = $inicio->format('G:i');

        if ($inicio->isSameDay($now)) {
            return 'hoy a las ' . $hora;
        }

        if ($inicio->isSameDay($now->copy()->addDay())) {
            return 'mañana a las ' . $hora;
        }

        return 'el ' . self::NOMBRES_DIA[$inicio->dayOfWeek] . ' a las ' . $hora;
    }

    /**
     * Conexión activa de Google Calendar del primer closer con is_closer=true.
     *
     * Duplicado de `CloserGoogleCalendarEventService::get_closer_connection()` (protected ahí):
     * este prompt solo puede tocar este archivo nuevo. Mismo criterio: primer admin con
     * is_closer=true y AdminCalendarConnection activa.
     *
     * @return AdminCalendarConnection|null
     */
    private function get_closer_connection(): ?AdminCalendarConnection
    {
        $closer = Admin::where('is_closer', true)->first();

        if (! $closer) {
            Log::channel('disponibilidad')->info(
                '[CLOSER_AGENDA] No hay ningún admin marcado como closer (is_closer=true).'
            );

            return null;
        }

        $connection = AdminCalendarConnection::where('admin_id', $closer->id)
            ->where('is_active', true)
            ->whereNotNull('google_calendar_id')
            ->where('google_calendar_id', '!=', '')
            ->first();

        if (! $connection) {
            Log::channel('disponibilidad')->info(
                '[CLOSER_AGENDA] El closer #' . $closer->id
                . ' no tiene Google Calendar conectado o la conexión está inactiva.'
            );

            return null;
        }

        return $connection;
    }
}
