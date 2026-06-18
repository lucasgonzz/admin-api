<?php

namespace App\Services;

use App\Exceptions\GoogleCalendarTokenRevokedException;
use App\Models\Admin;
use App\Models\AdminCalendarConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Consulta los eventos de calendarios Google de los admins marcados como closer,
 * y devuelve los rangos ocupados por fecha en minutos del día (zona Argentina).
 *
 * Aplica una caché corta de 5 minutos para no pegarle a la API de Google en cada
 * mensaje de WhatsApp entrante. Esta es la tercera capa de bloqueo de disponibilidad,
 * sumada a las dos capas existentes en LeadAiService (bloqueo por demo_id y bloqueo
 * por agenda calculada del closer post-demo).
 *
 * Si un closer no tiene calendario conectado, se asume disponible (no bloquea nada).
 * Si la API de Google falla o el token está revocado, se loguea y se excluye al closer.
 */
class CloserGoogleCalendarBusyService
{
    /** @var GoogleCalendarOAuthService */
    protected $oauth_service;

    /**
     * @param GoogleCalendarOAuthService $oauth_service Servicio de autenticación OAuth Google.
     */
    public function __construct(GoogleCalendarOAuthService $oauth_service)
    {
        $this->oauth_service = $oauth_service;
    }

    /**
     * Devuelve los rangos ocupados por fecha, provenientes de los calendarios
     * Google de todos los closers activos con conexión válida.
     *
     * Si hay más de un closer conectado, se aplica intersección conservadora:
     * un slot solo es libre si TODOS los closers activos estarían libres.
     *
     * @param string[] $date_strings Fechas Y-m-d a consultar.
     * @return array<string, array<int, array{0: int, 1: int}>> Mapa fecha → rangos [inicio_min, fin_min].
     */
    public function get_busy_ranges_for_dates(array $date_strings): array
    {
        if (empty($date_strings)) {
            return [];
        }

        // Clave de caché única por combinación de fechas consultadas.
        $cache_key = 'closer_google_calendar_busy_' . md5(implode(',', $date_strings));

        return Cache::remember($cache_key, now()->addMinutes(5), function () use ($date_strings) {
            // Solo admins marcados como closer.
            $closers = Admin::where('is_closer', true)->get();

            if ($closers->isEmpty()) {
                return [];
            }

            // Rangos ocupados por closer_id → fecha → array de rangos.
            $busy_by_closer = [];

            foreach ($closers as $closer) {
                // Buscar conexión activa de Google Calendar para este closer.
                $connection = AdminCalendarConnection::where('admin_id', $closer->id)
                    ->where('is_active', true)
                    ->first();

                if (! $connection) {
                    // Closer sin calendario conectado: no aporta restricción.
                    // Si no configuró calendario, el sistema no bloquea disponibilidad.
                    continue;
                }

                // Consultar rangos ocupados desde Google Calendar (con manejo de errores).
                $ranges = $this->fetch_busy_ranges_from_google($connection, $date_strings);
                if ($ranges !== null) {
                    $busy_by_closer[$closer->id] = $ranges;
                }
            }

            if (empty($busy_by_closer)) {
                return [];
            }

            // Un solo closer con calendario conectado: devolver directo sin intersección.
            if (count($busy_by_closer) === 1) {
                return reset($busy_by_closer);
            }

            // TODO: decisión pendiente de asignación de closer cuando haya más de uno -
            // hoy se usa intersección conservadora (un slot solo es libre si TODOS los
            // closers activos estarían libres). Revisar cuando se defina el criterio de
            // asignación real (round robin / carga / manual).
            return $this->intersect_busy_ranges($busy_by_closer, $date_strings);
        });
    }

    /**
     * Consulta la API freeBusy de Google para obtener los rangos ocupados del closer.
     *
     * Convierte los rangos ISO 8601 de Google a minutos del día en zona
     * America/Argentina/Buenos_Aires, agrupados por fecha Y-m-d.
     *
     * @param AdminCalendarConnection $connection   Conexión activa del closer.
     * @param string[]                $date_strings Fechas Y-m-d a consultar.
     * @return array<string, array<int, array{0: int, 1: int}>>|null Mapa fecha → rangos, o null si falló.
     */
    protected function fetch_busy_ranges_from_google(AdminCalendarConnection $connection, array $date_strings): ?array
    {
        try {
            // Obtener access_token fresco para esta consulta.
            $access_token = $this->oauth_service->get_fresh_access_token($connection);
        } catch (GoogleCalendarTokenRevokedException $e) {
            // Token revocado: loguear y excluir a este closer del cálculo.
            Log::warning('CloserGoogleCalendarBusyService: token revocado, se excluye el closer', [
                'admin_id' => $e->admin_id,
            ]);
            return null;
        }

        // Inicializar resultado con arrays vacíos para cada fecha solicitada.
        $result = [];
        foreach ($date_strings as $date) {
            $result[$date] = [];
        }

        try {
            // Construir rango de tiempo que cubre todas las fechas solicitadas.
            $tz        = 'America/Argentina/Buenos_Aires';
            $min_date  = min($date_strings);
            $max_date  = max($date_strings);

            // timeMin: inicio del primer día; timeMax: fin del último día.
            $time_min = \Carbon\Carbon::parse($min_date, $tz)->startOfDay()->toIso8601String();
            $time_max = \Carbon\Carbon::parse($max_date, $tz)->endOfDay()->toIso8601String();

            // Llamada a la API freeBusy de Google.
            $response = \Illuminate\Support\Facades\Http::withToken($access_token)
                ->post('https://www.googleapis.com/calendar/v3/freeBusy', [
                    'timeMin'  => $time_min,
                    'timeMax'  => $time_max,
                    'timeZone' => $tz,
                    'items'    => [
                        ['id' => $connection->google_calendar_id],
                    ],
                ]);

            if ($response->failed()) {
                Log::warning('CloserGoogleCalendarBusyService: fallo en freeBusy', [
                    'admin_id' => $connection->admin_id,
                    'status'   => $response->status(),
                    'body'     => $response->body(),
                ]);
                return null;
            }

            // Actualizar timestamp de última sincronización exitosa.
            $connection->update(['last_synced_at' => now()]);

            // Parsear los rangos busy devueltos por Google.
            $calendars = $response->json('calendars', []);
            $busy_list = $calendars[$connection->google_calendar_id]['busy'] ?? [];

            /* Diagnóstico: detalle de cada evento busy devuelto por Google, con los valores
             * originales ISO 8601 y los minutos calculados tras convertir a zona Argentina. */
            $busy_log_detail = [];

            foreach ($busy_list as $busy) {
                if (empty($busy['start']) || empty($busy['end'])) {
                    continue;
                }

                // Convertir ISO 8601 a Carbon en zona Argentina.
                $start_carbon = \Carbon\Carbon::parse($busy['start'])->setTimezone($tz);
                $end_carbon   = \Carbon\Carbon::parse($busy['end'])->setTimezone($tz);

                // Agrupar por la fecha local del inicio del evento.
                $date_key = $start_carbon->format('Y-m-d');

                // Convertir a minutos del día (0 = medianoche, 60 = 1am, etc.).
                $start_minutes = $start_carbon->hour * 60 + $start_carbon->minute;
                $end_minutes   = $end_carbon->hour * 60 + $end_carbon->minute;

                // Si el evento termina en otro día, usar 23:59 como tope del día.
                if ($end_carbon->format('Y-m-d') !== $date_key) {
                    $end_minutes = 23 * 60 + 59;
                }

                // Acumular detalle para el log de diagnóstico (start/end originales + conversión).
                $busy_log_detail[] = [
                    'start'         => $busy['start'],
                    'end'           => $busy['end'],
                    'date_key'      => $date_key,
                    'start_minutes' => $start_minutes,
                    'end_minutes'   => $end_minutes,
                ];

                // Solo incluir si la fecha está en las solicitadas.
                if (isset($result[$date_key])) {
                    $result[$date_key][] = [$start_minutes, $end_minutes];
                }
            }

            /* Diagnóstico: confirmar qué cuenta/calendario se consultó y qué eventos devolvió,
             * como texto plano legible (una línea por evento, horarios en HH:MM) en el canal
             * propio 'disponibilidad'. Se loguea aunque la lista venga vacía (0 eventos) para
             * distinguir "no hay eventos" de "no se está consultando la cuenta correcta".
             * Nunca se loguean tokens. El formateo de minutos se reutiliza de LeadAiService. */
            $lineas_eventos = [];
            foreach ($busy_log_detail as $evento) {
                $lineas_eventos[] = '  - ' . $evento['date_key'] . ': '
                    . LeadAiService::format_minutes_to_hhmm($evento['start_minutes'])
                    . ' a ' . LeadAiService::format_minutes_to_hhmm($evento['end_minutes']);
            }

            /* Cantidad de eventos para la línea de resumen (con pluralización correcta). */
            $cantidad_eventos = count($busy_log_detail);
            $resumen_eventos  = '(' . $cantidad_eventos . ' evento' . ($cantidad_eventos === 1 ? '' : 's')
                . ' encontrado' . ($cantidad_eventos === 1 ? '' : 's') . ')';

            $mensaje_google = '[DISPONIBILIDAD] Google Calendar consultado: admin #' . $connection->admin_id
                . ' (cuenta ' . $connection->google_account_email
                . ', calendar_id ' . $connection->google_calendar_id . ')' . "\n"
                . 'Eventos encontrados para ' . implode(', ', $date_strings) . ':' . "\n";
            if ($cantidad_eventos > 0) {
                $mensaje_google .= implode("\n", $lineas_eventos) . "\n";
            }
            $mensaje_google .= $resumen_eventos;

            Log::channel('disponibilidad')->info($mensaje_google);
        } catch (\Exception $e) {
            Log::error('CloserGoogleCalendarBusyService: excepción al consultar freeBusy', [
                'admin_id' => $connection->admin_id,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }

        return $result;
    }

    /**
     * Intersecta los rangos ocupados de múltiples closers.
     *
     * Un minuto está "ocupado" en el resultado final solo si TODOS los closers
     * están ocupados en ese minuto. Esto es conservador: un slot es libre solo
     * si todos los closers activos estarían disponibles.
     *
     * @param array<int, array<string, array<int, array{0: int, 1: int}>>> $busy_by_closer
     *   Mapa closer_id → fecha → rangos.
     * @param string[] $date_strings Fechas a procesar.
     * @return array<string, array<int, array{0: int, 1: int}>> Resultado intersectado.
     */
    protected function intersect_busy_ranges(array $busy_by_closer, array $date_strings): array
    {
        $result = [];
        foreach ($date_strings as $date) {
            $result[$date] = [];
        }

        // Resolución en minutos para la intersección (granularidad: 1 minuto).
        $minutes_in_day = 24 * 60;

        foreach ($date_strings as $date) {
            // Para cada minuto del día, verificar si TODOS los closers están ocupados.
            $all_closer_ids = array_keys($busy_by_closer);
            $num_closers    = count($all_closer_ids);

            // Construir mapa de minutos ocupados por cada closer.
            $occupied_by_closer = [];
            foreach ($all_closer_ids as $closer_id) {
                $occupied_by_closer[$closer_id] = array_fill(0, $minutes_in_day, false);
                foreach ($busy_by_closer[$closer_id][$date] ?? [] as [$start, $end]) {
                    for ($m = $start; $m < $end && $m < $minutes_in_day; $m++) {
                        $occupied_by_closer[$closer_id][$m] = true;
                    }
                }
            }

            // Un minuto está en la intersección si todos los closers lo tienen ocupado.
            $intersected = array_fill(0, $minutes_in_day, false);
            for ($m = 0; $m < $minutes_in_day; $m++) {
                $all_occupied = true;
                foreach ($all_closer_ids as $closer_id) {
                    if (! $occupied_by_closer[$closer_id][$m]) {
                        $all_occupied = false;
                        break;
                    }
                }
                $intersected[$m] = $all_occupied;
            }

            // Comprimir los minutos intersectados en rangos [inicio, fin].
            $ranges    = [];
            $in_range  = false;
            $range_start = 0;
            for ($m = 0; $m < $minutes_in_day; $m++) {
                if ($intersected[$m] && ! $in_range) {
                    $in_range    = true;
                    $range_start = $m;
                } elseif (! $intersected[$m] && $in_range) {
                    $ranges[]   = [$range_start, $m];
                    $in_range   = false;
                }
            }
            if ($in_range) {
                $ranges[] = [$range_start, $minutes_in_day];
            }

            $result[$date] = $ranges;
        }

        return $result;
    }
}
