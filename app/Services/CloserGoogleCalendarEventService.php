<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\AdminCalendarConnection;
use App\Models\Lead;
use App\Services\GoogleCalendarOAuthService;
use App\Services\CloserGoogleCalendarBusyService;
use App\Services\LeadDemoSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gestiona la creación, actualización y eliminación de eventos en el Google Calendar
 * del closer cuando se agenda, reagenda o cancela una demo de un lead.
 *
 * La creación de eventos es best-effort: si falla por cualquier razón (sin scope de
 * escritura, token expirado, error de red), se loguea el error y el flujo de
 * agendamiento continúa normalmente sin lanzar excepciones.
 *
 * El evento creado representa el bloque de tiempo que el closer dedicará a la llamada
 * post-demo (entrada a la sala + gracia + duración de la llamada), no la demo en sí.
 */
class CloserGoogleCalendarEventService
{
    /** @var GoogleCalendarOAuthService */
    protected $oauth_service;

    /** @var CloserGoogleCalendarBusyService */
    protected $busy_service;

    /**
     * @param GoogleCalendarOAuthService      $oauth_service Servicio de autenticación OAuth Google.
     * @param CloserGoogleCalendarBusyService $busy_service  Servicio de disponibilidad (para invalidar caché).
     */
    public function __construct(
        GoogleCalendarOAuthService $oauth_service,
        CloserGoogleCalendarBusyService $busy_service
    ) {
        $this->oauth_service = $oauth_service;
        $this->busy_service  = $busy_service;
    }

    /**
     * Crea un evento en el Google Calendar del closer para la llamada post-demo del lead.
     *
     * Calcula el horario del evento a partir de los campos de demo del lead y las
     * variables de configuración de LeadDemoSettings. Si el closer no tiene calendario
     * conectado con scope de escritura, no hace nada (sin excepción).
     *
     * Persiste el google_event_id y meet_url devueltos por Google usando update() sobre el lead
     * (solo actualiza esos campos, sin tocar el resto del modelo).
     *
     * Debe llamarse DESPUÉS del save() principal del lead, con demo_date y demo_start_time
     * ya persistidos en la base de datos.
     *
     * @param Lead $lead Lead con demo ya guardada (demo_date, demo_start_time, demo_end_time asignados).
     * @return void
     */
    public function create_event_for_lead(Lead $lead): void
    {
        // Verificar que el lead tiene los datos mínimos para crear el evento.
        if (! $lead->demo_date || ! $lead->demo_start_time) {
            Log::channel('disponibilidad')->warning(
                '[CALENDAR_EVENT] No se puede crear evento: lead #' . $lead->id
                . ' no tiene demo_date o demo_start_time asignados.'
            );
            return;
        }

        // Obtener la conexión activa del closer.
        $connection = $this->get_closer_connection();
        if (! $connection) {
            return;
        }

        try {
            // Calcular horario del evento: inicio = fin de la demo, fin = gracia + llamada closer.
            [$event_start, $event_end] = $this->calculate_event_times($lead);

            // Construir el cuerpo del evento para la API de Google Calendar (incluye Google Meet).
            $event_body = $this->build_event_body($lead, $event_start, $event_end);

            // Obtener access_token fresco para la llamada.
            $access_token = $this->oauth_service->get_fresh_access_token($connection);

            // Query params: conferenceDataVersion=1 habilita Google Meet; sendUpdates=all solo si hay email del lead.
            $lead_tiene_email = ! empty($lead->email);
            $query_params     = 'conferenceDataVersion=1';
            if ($lead_tiene_email) {
                $query_params .= '&sendUpdates=all';
            }

            // Llamar a la API de Google Calendar para crear el evento con Meet.
            $response = Http::withToken($access_token)
                ->post(
                    'https://www.googleapis.com/calendar/v3/calendars/'
                        . urlencode($connection->google_calendar_id)
                        . '/events?' . $query_params,
                    $event_body
                );

            // HTTP 403: el token no tiene scope de escritura (calendar.events).
            // Se loguea como warning y se continúa sin lanzar excepción.
            if ($response->status() === 403) {
                Log::channel('disponibilidad')->warning(
                    '[CALENDAR_EVENT] El token del closer no tiene scope calendar.events.'
                    . ' El closer debe desconectarse y reconectarse para obtener el nuevo permiso.'
                    . ' admin_id=' . $connection->admin_id
                );
                return;
            }

            if ($response->failed()) {
                Log::channel('disponibilidad')->warning(
                    '[CALENDAR_EVENT] Error al crear evento en Google Calendar.'
                    . ' admin_id=' . $connection->admin_id
                    . ' HTTP=' . $response->status()
                    . ' body=' . substr($response->body(), 0, 500)
                );
                return;
            }

            // Persistir google_event_id y meet_url en el lead usando update().
            $google_event_id = $response->json('id');

            // Extraer meet_url de la respuesta de Google (entryPointType === video).
            $meet_url     = null;
            $entry_points = $response->json('conferenceData.entryPoints') ?? [];
            foreach ($entry_points as $entry) {
                if (($entry['entryPointType'] ?? '') === 'video') {
                    $meet_url = $entry['uri'] ?? null;
                    break;
                }
            }

            $update_data = ['google_event_id' => $google_event_id];
            if ($meet_url) {
                $update_data['meet_url'] = $meet_url;
            }
            $lead->update($update_data);

            Log::channel('disponibilidad')->info(
                '[CALENDAR_EVENT] Evento creado en Google Calendar del closer.'
                . ' lead_id=' . $lead->id
                . ' google_event_id=' . $google_event_id
                . ' meet_url=' . ($meet_url ?? 'no generada')
                . ' event_start=' . $event_start->toDateTimeString()
                . ' event_end=' . $event_end->toDateTimeString()
            );

            // Invalidar la caché de disponibilidad para que el próximo cálculo vea el nuevo evento.
            $this->busy_service->flush_cache_for_date(
                $lead->demo_date->format('Y-m-d')
            );
        } catch (\Throwable $e) {
            Log::channel('disponibilidad')->error(
                '[CALENDAR_EVENT] Excepción al crear evento en Google Calendar.'
                . ' lead_id=' . $lead->id
                . ' error=' . $e->getMessage()
            );
        }
    }

    /**
     * Elimina el evento del lead en Google Calendar (si existe google_event_id en el lead).
     *
     * Solo borra en Google y limpia google_event_id y meet_url en el objeto $lead.
     * NO llama a $lead->save(): el guardado lo hace el llamador, que consolida todos
     * los cambios del lead en un único save() para evitar escrituras parciales.
     *
     * @param Lead        $lead            Lead cuyo evento en Google Calendar se desea eliminar.
     * @param string|null $demo_date_cache Fecha Y-m-d para invalidar caché (si el lead ya no tiene demo_date).
     * @return void
     */
    public function delete_event_for_lead(Lead $lead, ?string $demo_date_cache = null): void
    {
        // Si no hay google_event_id, no hay nada que eliminar.
        if (empty($lead->google_event_id)) {
            return;
        }

        // Obtener la fecha de demo para invalidar caché: usa el parámetro si se pasó,
        // o bien la fecha actual del lead (antes de que sea limpiada por el llamador).
        $demo_date_str = $demo_date_cache
            ?? ($lead->demo_date ? $lead->demo_date->format('Y-m-d') : null);

        // Llamar al método centralizado usando el ID del lead.
        $this->delete_event_by_id($lead->google_event_id, $demo_date_str);

        // Limpiar google_event_id y meet_url del lead en memoria (sin save; el llamador persiste).
        $lead->google_event_id = null;
        $lead->meet_url        = null;
    }

    /**
     * Elimina un evento de Google Calendar por su ID, sin necesitar el objeto Lead.
     *
     * Útil cuando el google_event_id ya fue borrado del lead antes del save() principal
     * pero se necesita eliminarlo en Google Calendar con el valor guardado previamente.
     *
     * @param string      $event_id        ID del evento a eliminar en Google Calendar.
     * @param string|null $demo_date_cache Fecha Y-m-d para invalidar caché de disponibilidad.
     * @return void
     */
    public function delete_event_by_id(string $event_id, ?string $demo_date_cache = null): void
    {
        if (empty($event_id)) {
            return;
        }

        $connection = $this->get_closer_connection();
        if (! $connection) {
            return;
        }

        try {
            $access_token = $this->oauth_service->get_fresh_access_token($connection);

            // Llamar a la API de Google Calendar para eliminar el evento.
            $response = Http::withToken($access_token)
                ->delete(
                    'https://www.googleapis.com/calendar/v3/calendars/'
                        . urlencode($connection->google_calendar_id)
                        . '/events/' . urlencode($event_id)
                );

            // HTTP 410 = ya fue eliminado antes (Gone): se trata como éxito.
            if ($response->failed() && $response->status() !== 410) {
                Log::channel('disponibilidad')->warning(
                    '[CALENDAR_EVENT] Error al eliminar evento en Google Calendar.'
                    . ' google_event_id=' . $event_id
                    . ' HTTP=' . $response->status()
                );
                return;
            }

            Log::channel('disponibilidad')->info(
                '[CALENDAR_EVENT] Evento eliminado de Google Calendar del closer.'
                . ' google_event_id=' . $event_id
            );

            // Invalidar caché de disponibilidad si se conoce la fecha de la demo.
            if ($demo_date_cache) {
                $this->busy_service->flush_cache_for_date($demo_date_cache);
            }
        } catch (\Throwable $e) {
            Log::channel('disponibilidad')->error(
                '[CALENDAR_EVENT] Excepción al eliminar evento en Google Calendar.'
                . ' google_event_id=' . $event_id
                . ' error=' . $e->getMessage()
            );
        }
    }

    /**
     * Elimina el evento anterior del lead y crea uno nuevo (para reagendamiento).
     *
     * Llama internamente a delete_event_by_id() y luego a create_event_for_lead().
     * El lead debe tener ya los nuevos valores de demo_date, demo_start_time y demo_end_time
     * ya persistidos en la base de datos.
     *
     * @param Lead        $lead                  Lead con los nuevos datos de demo ya persistidos.
     * @param string|null $old_google_event_id   ID del evento anterior a eliminar.
     * @param string|null $old_demo_date         Fecha Y-m-d de la demo anterior (para invalidar caché).
     * @return void
     */
    public function recreate_event_for_lead(Lead $lead, ?string $old_google_event_id = null, ?string $old_demo_date = null): void
    {
        // Eliminar el evento anterior usando el ID guardado antes de la limpieza del lead.
        if ($old_google_event_id) {
            $this->delete_event_by_id($old_google_event_id, $old_demo_date);
        }

        // Crear el nuevo evento con los datos actualizados de la demo.
        $this->create_event_for_lead($lead);
    }

    /**
     * Calcula los timestamps de inicio y fin del evento en el calendario del closer.
     *
     * El evento comienza al finalizar la demo (el closer entra a la sala aunque el lead
     * esté terminando), y termina tras la gracia post-demo más la duración de la llamada.
     *
     * Fórmula:
     *   event_start = demo_date + demo_start_time + duracion_minutos
     *   event_end   = event_start + gracia_minutos_post + duracion_llamada_closer_minutos
     *
     * @param Lead $lead Lead con demo_date y demo_start_time asignados.
     * @return Carbon[] Array de dos Carbon: [event_start, event_end].
     */
    protected function calculate_event_times(Lead $lead): array
    {
        // Zona horaria de Argentina (sin DST, siempre -03:00).
        $tz = 'America/Argentina/Buenos_Aires';

        // Obtener configuración de duración de la demo y la llamada.
        $duracion_demo          = LeadDemoSettings::get_duracion_minutos();
        $gracia_post            = LeadDemoSettings::get_gracia_minutos_post();
        $duracion_llamada       = LeadDemoSettings::get_duracion_llamada_closer_minutos();

        // Construir el datetime de inicio de la demo combinando fecha y hora en zona Argentina.
        $demo_date_str  = $lead->demo_date->format('Y-m-d');
        $demo_start_str = $lead->demo_start_time;

        $demo_start_carbon = Carbon::parse($demo_date_str . ' ' . $demo_start_str, $tz);

        // El evento del closer empieza cuando termina la demo.
        $event_start = $demo_start_carbon->copy()->addMinutes($duracion_demo);

        // El evento del closer termina tras la gracia + la llamada.
        $event_end = $event_start->copy()->addMinutes($gracia_post + $duracion_llamada);

        return [$event_start, $event_end];
    }

    /**
     * Construye el cuerpo del evento a enviar a la API de Google Calendar.
     *
     * @param Lead   $lead        Lead asociado al evento.
     * @param Carbon $event_start Inicio del evento en zona Argentina.
     * @param Carbon $event_end   Fin del evento en zona Argentina.
     * @return array<string, mixed> Body del evento para la API de Google.
     */
    protected function build_event_body(Lead $lead, Carbon $event_start, Carbon $event_end): array
    {
        // Zona horaria de Argentina para los campos dateTime del evento.
        $tz = 'America/Argentina/Buenos_Aires';

        // Nombre del lead para el summary y la descripción.
        $lead_nombre = trim(($lead->name ?? '') . ' ' . ($lead->last_name ?? ''));
        if ($lead_nombre === '') {
            $lead_nombre = 'Lead #' . $lead->id;
        }

        // Hora de fin de la demo para la descripción (calculada a partir de start + duración).
        $demo_end_str = $lead->demo_end_time ?? '?';

        // Formatear los datetime como ISO 8601 con offset de Argentina (-03:00).
        $start_iso = $event_start->setTimezone($tz)->toIso8601String();
        $end_iso   = $event_end->setTimezone($tz)->toIso8601String();

        // Invitados: si el lead tiene email, Google envía la invitación con el link de Meet.
        $attendees = [];
        if (! empty($lead->email)) {
            $attendees[] = ['email' => $lead->email];
        }

        // Cuerpo base del evento con Google Meet (conferenceData).
        $event_body = [
            // Prefijo [CC] para distinguir eventos generados por ComercioCity de eventos personales.
            'summary'     => '[CC] Llamada con ' . $lead_nombre,
            'description' => 'Lead: ' . $lead_nombre . "\n"
                . 'Demo: ' . $lead->demo_date->format('Y-m-d')
                . ' ' . $lead->demo_start_time . ' - ' . $demo_end_str . "\n"
                . 'Llamada post-demo agendada por ComercioCity.',
            'start'       => [
                'dateTime' => $start_iso,
                'timeZone' => $tz,
            ],
            'end'         => [
                'dateTime' => $end_iso,
                'timeZone' => $tz,
            ],
            // colorId "2" = sage/verde oscuro: distingue visualmente los eventos de ComercioCity.
            'colorId'        => '2',
            'conferenceData' => [
                'createRequest' => [
                    'requestId'             => 'cc-lead-' . $lead->id . '-' . time(),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ],
        ];

        // Solo incluir attendees si hay email; Google no lo requiere cuando no hay invitado externo.
        if (! empty($attendees)) {
            $event_body['attendees'] = $attendees;
        }

        return $event_body;
    }

    /**
     * Obtiene la conexión activa de Google Calendar del primer closer con is_closer=true.
     *
     * Si no hay closers activos con conexión, loguea y devuelve null.
     * Criterio: primer admin con is_closer=true y AdminCalendarConnection activa.
     * TODO: revisar cuando se defina asignación multi-closer (round robin / carga / manual).
     *
     * @return AdminCalendarConnection|null Conexión activa del closer, o null si no hay.
     */
    protected function get_closer_connection(): ?AdminCalendarConnection
    {
        // Buscar el primer closer activo con conexión de calendario.
        $closer = Admin::where('is_closer', true)->first();

        if (! $closer) {
            Log::channel('disponibilidad')->info(
                '[CALENDAR_EVENT] No hay ningún admin marcado como closer (is_closer=true).'
                . ' No se crea evento en Google Calendar.'
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
                '[CALENDAR_EVENT] El closer #' . $closer->id
                . ' no tiene Google Calendar conectado o la conexión está inactiva.'
                . ' No se crea evento.'
            );
            return null;
        }

        return $connection;
    }
}
