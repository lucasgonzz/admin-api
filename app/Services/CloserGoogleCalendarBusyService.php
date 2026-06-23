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
     * Devuelve los rangos ocupados por fecha y un snapshot legible de los eventos consultados.
     *
     * Si hay más de un closer conectado, se aplica intersección conservadora en `ranges`:
     * un slot solo es libre si TODOS los closers activos estarían libres.
     *
     * @param string[] $date_strings Fechas Y-m-d a consultar.
     * @return array{
     *   ranges: array<string, array<int, array{0: int, 1: int}>>,
     *   snapshot: array<string, mixed>|null
     * }
     */
    public function get_busy_ranges_for_dates(array $date_strings): array
    {
        if (empty($date_strings)) {
            return ['ranges' => [], 'snapshot' => null];
        }

        // Clave de caché única por combinación de fechas consultadas.
        $cache_key = 'closer_google_calendar_busy_' . md5(implode(',', $date_strings));

        // Fragmento corto de la clave para los logs (la clave completa es larga).
        $cache_key_short = substr($cache_key, -8);

        /* Diagnóstico: detectar si la respuesta va a salir de un valor cacheado de una
         * corrida anterior o si se va a consultar la API de Google ahora. */
        $from_cache = Cache::has($cache_key);

        if ($from_cache) {
            Log::channel('disponibilidad')->info(
                '[DISPONIBILIDAD] Consulta a Google Calendar: usando valor cacheado para '
                . implode(', ', $date_strings) . ' (TTL 5 min, clave ...' . $cache_key_short . ').'
                . ' El resultado puede no reflejar eventos creados en los últimos minutos.'
            );
        } else {
            Log::channel('disponibilidad')->info(
                '[DISPONIBILIDAD] Consulta a Google Calendar: cache miss, se va a consultar la API para '
                . implode(', ', $date_strings) . ' (clave ...' . $cache_key_short . ').'
            );
        }

        /* El closure devuelve ranges + snapshot fresco; la caché guarda ambos para reutilizar ranges. */
        $cached_payload = Cache::remember($cache_key, now()->addMinutes(5), function () use ($date_strings) {
            return $this->compute_busy_ranges_and_snapshot($date_strings);
        });

        /* Compatibilidad con entradas cacheadas antes del prompt 105 (solo mapa de rangos). */
        if (isset($cached_payload['ranges'])) {
            $ranges = $cached_payload['ranges'];
        } else {
            $ranges = is_array($cached_payload) ? $cached_payload : [];
        }

        // Respuesta servida desde caché: marcar closers como cacheado sin eventos detallados.
        if ($from_cache) {
            $snapshot = $this->build_cache_hit_snapshot($date_strings);
        } else {
            $snapshot = $cached_payload['snapshot'] ?? null;
        }

        return [
            'ranges'   => $ranges,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * Consulta Google Calendar y arma rangos en minutos junto al snapshot estructurado.
     *
     * @param string[] $date_strings Fechas Y-m-d a consultar.
     * @return array{ranges: array<string, array<int, array{0: int, 1: int}>>, snapshot: array<string, mixed>}
     */
    protected function compute_busy_ranges_and_snapshot(array $date_strings): array
    {
        // Momento de la consulta fresca a Google (o del intento).
        $consultado_en = now()->toIso8601String();

        // Solo admins marcados como closer.
        $closers = Admin::where('is_closer', true)->get();

        if ($closers->isEmpty()) {
            Log::channel('disponibilidad')->info(
                '[DISPONIBILIDAD] No hay ningún admin marcado como closer (is_closer=true).'
                . ' La capa de Google Calendar no aporta restricción.'
            );

            return [
                'ranges'   => [],
                'snapshot' => [
                    'consultado_en' => $consultado_en,
                    'closers'       => [],
                ],
            ];
        }

        // Rangos ocupados por closer_id → fecha → array de rangos.
        $busy_by_closer   = [];
        $snapshot_closers = [];

        foreach ($closers as $closer) {
            $closer_label = $closer->name ?? $closer->email ?? ('admin #' . $closer->id);

            // Entrada base del snapshot para este closer.
            $snapshot_entry = [
                'admin_id' => $closer->id,
                'nombre'   => $closer_label,
            ];

            // Buscar conexión activa de Google Calendar para este closer.
            $connection = AdminCalendarConnection::where('admin_id', $closer->id)
                ->where('is_active', true)
                ->first();

            if (! $connection) {
                Log::channel('disponibilidad')->info(
                    '[DISPONIBILIDAD] Closer #' . $closer->id . ' (' . $closer_label . ')'
                    . ' no tiene Google Calendar conectado o la conexión está inactiva.'
                    . ' Se omite de esta capa.'
                );

                $snapshot_entry['estado'] = 'sin_calendario';
                $snapshot_closers[]       = $snapshot_entry;
                continue;
            }

            $snapshot_entry['google_account_email'] = $connection->google_account_email;
            $snapshot_entry['google_calendar_id']   = $connection->google_calendar_id;

            // Consultar rangos ocupados desde Google Calendar (con manejo de errores).
            $fetch_result = $this->fetch_busy_ranges_from_google($connection, $date_strings);

            if (empty($fetch_result['ok'])) {
                $snapshot_entry['estado'] = $fetch_result['estado'] ?? 'error_api';
                $snapshot_closers[]       = $snapshot_entry;
                continue;
            }

            $snapshot_entry['estado']  = 'consultado';
            $snapshot_entry['eventos'] = $fetch_result['eventos'] ?? [];
            $snapshot_closers[]        = $snapshot_entry;

            $busy_by_closer[$closer->id] = $fetch_result['ranges'];
        }

        // Sin closers consultables: no hay restricción pero sí snapshot de diagnóstico.
        if (empty($busy_by_closer)) {
            return [
                'ranges'   => [],
                'snapshot' => [
                    'consultado_en' => $consultado_en,
                    'closers'       => $snapshot_closers,
                ],
            ];
        }

        // Un solo closer con calendario conectado: devolver directo sin intersección.
        if (count($busy_by_closer) === 1) {
            $ranges = reset($busy_by_closer);
        } else {
            // Intersección conservadora entre closers activos con calendario.
            $ranges = $this->intersect_busy_ranges($busy_by_closer, $date_strings);
        }

        return [
            'ranges'   => $ranges,
            'snapshot' => [
                'consultado_en' => $consultado_en,
                'closers'       => $snapshot_closers,
            ],
        ];
    }

    /**
     * Arma snapshot cuando la respuesta de rangos proviene de caché (sin eventos detallados).
     *
     * @param string[] $date_strings Fechas consultadas (contexto del log).
     * @return array<string, mixed>
     */
    protected function build_cache_hit_snapshot(array $date_strings): array
    {
        $closers          = Admin::where('is_closer', true)->get();
        $snapshot_closers = [];

        foreach ($closers as $closer) {
            $closer_label = $closer->name ?? $closer->email ?? ('admin #' . $closer->id);

            $snapshot_entry = [
                'admin_id' => $closer->id,
                'nombre'   => $closer_label,
                'estado'   => 'cacheado',
                'eventos'  => [],
            ];

            $connection = AdminCalendarConnection::where('admin_id', $closer->id)
                ->where('is_active', true)
                ->first();

            if ($connection) {
                $snapshot_entry['google_account_email'] = $connection->google_account_email;
                $snapshot_entry['google_calendar_id']   = $connection->google_calendar_id;
            }

            $snapshot_closers[] = $snapshot_entry;
        }

        Log::channel('disponibilidad')->info(
            '[DISPONIBILIDAD] Snapshot de calendario marcado como cacheado para '
            . implode(', ', $date_strings) . ' (sin detalle de eventos de Google).'
        );

        return [
            'consultado_en' => now()->toIso8601String(),
            'closers'       => $snapshot_closers,
        ];
    }

    /**
     * Consulta la API freeBusy de Google para obtener los rangos ocupados del closer.
     *
     * Convierte los rangos ISO 8601 de Google a minutos del día en zona
     * America/Argentina/Buenos_Aires, agrupados por fecha Y-m-d.
     *
     * @param AdminCalendarConnection $connection   Conexión activa del closer.
     * @param string[]                $date_strings Fechas Y-m-d a consultar.
     * @return array{
     *   ok: bool,
     *   estado?: string,
     *   ranges?: array<string, array<int, array{0: int, 1: int}>>,
     *   eventos?: array<int, array{fecha: string, inicio: string, fin: string}>
     * }
     */
    protected function fetch_busy_ranges_from_google(AdminCalendarConnection $connection, array $date_strings): array
    {
        try {
            // Obtener access_token fresco para esta consulta.
            $access_token = $this->oauth_service->get_fresh_access_token($connection);
        } catch (GoogleCalendarTokenRevokedException $e) {
            Log::warning('CloserGoogleCalendarBusyService: token revocado, se excluye el closer', [
                'admin_id' => $e->admin_id,
            ]);
            Log::channel('disponibilidad')->warning(
                '[DISPONIBILIDAD] Google Calendar: token revocado para admin #' . $e->admin_id
                . ', se excluye el closer de esta capa.'
            );

            return ['ok' => false, 'estado' => 'token_revocado'];
        }

        // Inicializar resultado con arrays vacíos para cada fecha solicitada.
        $result  = [];
        $eventos = [];
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
                Log::channel('disponibilidad')->warning(
                    '[DISPONIBILIDAD] Google Calendar: fallo en freeBusy para admin #' . $connection->admin_id
                    . ' (HTTP ' . $response->status() . ').'
                    . ' Se excluye el closer de esta capa. Respuesta: ' . $response->body()
                );

                return ['ok' => false, 'estado' => 'error_api'];
            }

            // Actualizar timestamp de última sincronización exitosa.
            $connection->update(['last_synced_at' => now()]);

            // Parsear los rangos busy devueltos por Google.
            $calendars = $response->json('calendars', []);
            $busy_list = $calendars[$connection->google_calendar_id]['busy'] ?? [];

            /* Diagnóstico: detalle de cada evento busy devuelto por Google. */
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

                $inicio_hhmm = LeadAiService::format_minutes_to_hhmm($start_minutes);
                $fin_hhmm    = LeadAiService::format_minutes_to_hhmm($end_minutes);

                // Acumular detalle para el log de diagnóstico.
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

                    $eventos[] = [
                        'fecha'  => $date_key,
                        'inicio' => $inicio_hhmm,
                        'fin'    => $fin_hhmm,
                    ];
                }
            }

            $lineas_eventos = [];
            foreach ($busy_log_detail as $evento) {
                $lineas_eventos[] = '  - ' . $evento['date_key'] . ': '
                    . LeadAiService::format_minutes_to_hhmm($evento['start_minutes'])
                    . ' a ' . LeadAiService::format_minutes_to_hhmm($evento['end_minutes']);
            }

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
            Log::channel('disponibilidad')->error(
                '[DISPONIBILIDAD] Google Calendar: excepción al consultar freeBusy para admin #'
                . $connection->admin_id . '. Se excluye el closer de esta capa. Error: ' . $e->getMessage()
            );

            return ['ok' => false, 'estado' => 'error_api'];
        }

        return [
            'ok'      => true,
            'ranges'  => $result,
            'eventos' => $eventos,
        ];
    }

    /**
     * Lista eventos con nombre del calendario Google del closer para el panel de administración.
     *
     * Usa calendar.events.list (no freeBusy) para obtener summary y horarios legibles.
     * Si la API falla o el token está revocado, devuelve array vacío sin lanzar excepción.
     * Actualiza last_synced_at en la conexión cuando la consulta es exitosa.
     *
     * @param AdminCalendarConnection $connection   Conexión activa con google_calendar_id configurado.
     * @param int                     $days_ahead   Cantidad de días hacia adelante desde hoy a consultar.
     * @return array<int, array{fecha: string, inicio: string, fin: string, nombre: string}>
     */
    public function get_events_for_admin(AdminCalendarConnection $connection, int $days_ahead = 30): array
    {
        // Zona horaria de referencia para rangos y formateo de horas.
        $tz = 'America/Argentina/Buenos_Aires';

        // Sin calendario elegido no hay eventos que listar.
        if (empty($connection->google_calendar_id)) {
            return [];
        }

        try {
            // Access token fresco para la llamada a events.list.
            $access_token = $this->oauth_service->get_fresh_access_token($connection);
        } catch (GoogleCalendarTokenRevokedException $e) {
            Log::warning('CloserGoogleCalendarBusyService: token revocado al listar eventos para admin', [
                'admin_id' => $e->admin_id,
            ]);

            return [];
        } catch (\Exception $e) {
            Log::error('CloserGoogleCalendarBusyService: error al obtener token para listar eventos', [
                'admin_id' => $connection->admin_id,
                'error'    => $e->getMessage(),
            ]);

            return [];
        }

        try {
            // Rango de consulta: desde ahora hasta $days_ahead días adelante.
            $time_min = \Carbon\Carbon::now($tz)->toIso8601String();
            $time_max = \Carbon\Carbon::now($tz)->addDays($days_ahead)->toIso8601String();

            // Llamada a events.list con instancias expandidas y orden cronológico.
            $response = \Illuminate\Support\Facades\Http::withToken($access_token)
                ->get(
                    'https://www.googleapis.com/calendar/v3/calendars/'
                    . rawurlencode($connection->google_calendar_id)
                    . '/events',
                    [
                        'timeMin'      => $time_min,
                        'timeMax'      => $time_max,
                        'singleEvents' => 'true',
                        'orderBy'      => 'startTime',
                        'maxResults'   => 100,
                        'timeZone'     => $tz,
                    ]
                );

            if ($response->failed()) {
                Log::warning('CloserGoogleCalendarBusyService: fallo al listar eventos', [
                    'admin_id' => $connection->admin_id,
                    'status'   => $response->status(),
                    'body'     => $response->body(),
                ]);

                return [];
            }

            // Marcar sincronización exitosa en la conexión del closer.
            $connection->update(['last_synced_at' => now()]);

            // Parsear cada item devuelto por Google en el formato del panel admin.
            $items  = $response->json('items', []);
            $events = [];

            foreach ($items as $item) {
                // Nombre del evento; Google puede omitir summary en borradores o eventos sin título.
                $nombre = ! empty($item['summary']) ? $item['summary'] : 'Sin título';

                $start = $item['start'] ?? [];
                $end   = $item['end'] ?? [];

                // Eventos de día completo usan start.date en lugar de start.dateTime.
                $is_all_day = isset($start['date']) && ! isset($start['dateTime']);

                if ($is_all_day) {
                    $fecha  = $start['date'];
                    $inicio = 'Todo el día';
                    $fin    = '';
                } else {
                    $start_carbon = \Carbon\Carbon::parse($start['dateTime'])->setTimezone($tz);
                    $end_carbon   = \Carbon\Carbon::parse($end['dateTime'] ?? $start['dateTime'])->setTimezone($tz);

                    $fecha  = $start_carbon->format('Y-m-d');
                    $inicio = $start_carbon->format('H:i');
                    $fin    = $end_carbon->format('H:i');
                }

                $events[] = [
                    'fecha'  => $fecha,
                    'inicio' => $inicio,
                    'fin'    => $fin,
                    'nombre' => $nombre,
                ];
            }

            return $events;
        } catch (\Exception $e) {
            Log::error('CloserGoogleCalendarBusyService: excepción al listar eventos', [
                'admin_id' => $connection->admin_id,
                'error'    => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Invalida la entrada de caché de disponibilidad de Google Calendar para una fecha concreta.
     *
     * Se llama desde CloserGoogleCalendarEventService después de crear o eliminar un evento,
     * para que el próximo cálculo de slots consulte la API de Google en lugar de usar el valor
     * cacheado de 5 minutos (que podría no reflejar el evento recién creado/eliminado).
     *
     * @param string $date Fecha Y-m-d cuya caché se invalida.
     * @return void
     */
    public function flush_cache_for_date(string $date): void
    {
        $cache_key = 'closer_google_calendar_busy_' . md5($date);

        Cache::forget($cache_key);

        Log::channel('disponibilidad')->info(
            '[CALENDAR_EVENT] Caché de disponibilidad invalidada para ' . $date
            . ' (clave ...' . substr($cache_key, -8) . ').'
        );
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
            $ranges      = [];
            $in_range    = false;
            $range_start = 0;
            for ($m = 0; $m < $minutes_in_day; $m++) {
                if ($intersected[$m] && ! $in_range) {
                    $in_range    = true;
                    $range_start = $m;
                } elseif (! $intersected[$m] && $in_range) {
                    $ranges[] = [$range_start, $m];
                    $in_range = false;
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
