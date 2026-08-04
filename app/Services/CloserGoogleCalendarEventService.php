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

        // Calcular horario del evento: inicio = fin de la demo, fin = gracia + llamada closer.
        [$event_start, $event_end] = $this->calculate_event_times($lead);

        // Construir el cuerpo del evento para la API de Google Calendar (incluye Google Meet).
        $event_body = $this->build_event_body($lead, $event_start, $event_end);

        // sendUpdates=all solo si hay email del lead (Google envía la invitación al lead).
        $lead_tiene_email = ! empty($lead->email);

        // Crear el evento en Google Calendar usando el método compartido (maneja sus propias excepciones).
        $result = $this->execute_calendar_event_creation($connection, $event_body, $lead_tiene_email);

        // Si falló (403, error HTTP o excepción), execute_calendar_event_creation ya logueó el motivo.
        if ($result === null) {
            return;
        }

        // Persistir google_event_id y meet_url en el lead usando update() (solo esos campos).
        $update_data = ['google_event_id' => $result['google_event_id']];
        if ($result['meet_url']) {
            $update_data['meet_url'] = $result['meet_url'];
        }
        $lead->update($update_data);

        Log::channel('disponibilidad')->info(
            '[CALENDAR_EVENT] Evento creado en Google Calendar del closer.'
            . ' lead_id=' . $lead->id
            . ' google_event_id=' . $result['google_event_id']
            . ' meet_url=' . ($result['meet_url'] ?? 'no generada')
            . ' event_start=' . $event_start->toDateTimeString()
            . ' event_end=' . $event_end->toDateTimeString()
        );

        // Invalidar la caché de disponibilidad para que el próximo cálculo vea el nuevo evento.
        $this->busy_service->flush_cache_for_date(
            $lead->demo_date->format('Y-m-d')
        );
    }

    /**
     * Reserva el hueco del closer como POSIBLE llamada (grupo 306, prompt 05) — sin invitar al
     * lead ni generar Meet. La llamada recién se coordina con el lead cuando termina la demo y
     * dice que quiere avanzar (grupo 307); esto solo evita que otro lead se lleve el mismo hueco
     * mientras tanto.
     *
     * Se reserva SOLO si se cumplen las dos condiciones sobre el momento en que la demo
     * terminaría: (1) cae dentro del horario laboral del closer, y (2) el closer no tiene ningún
     * evento en el calendario en ese momento. Si falla cualquiera, no se reserva nada — la
     * coordinación queda para el final (grupo 307).
     *
     * Persiste el id en `closer_hold_event_id` (columna separada de `google_event_id`: esa es la
     * llamada CONFIRMADA que consumen LeadCallService, delete_event_for_lead() y Recall.ai —
     * mezclarlas engancharía el bot de transcripción a una reserva vacía).
     *
     * @param Lead $lead Lead con demo ya guardada (demo_date, demo_start_time asignados).
     *
     * @return bool true si se creó la reserva; false si no se cumplieron las condiciones, no hay
     *              conexión del closer, o falló la llamada a Google.
     */
    public function create_hold_for_lead(Lead $lead): bool
    {
        if (! $lead->demo_date || ! $lead->demo_start_time) {
            Log::channel('disponibilidad')->warning(
                '[CALENDAR_EVENT] No se puede reservar hueco: lead #' . $lead->id
                . ' no tiene demo_date o demo_start_time asignados.'
            );
            return false;
        }

        $connection = $this->get_closer_connection();
        if (! $connection) {
            return false;
        }

        // Mismo cálculo que la llamada confirmada: inicio = fin de la demo, fin = gracia + llamada.
        [$event_start, $event_end] = $this->calculate_event_times($lead);

        // Condición 1: el rango entra en el horario laboral del closer ese día de semana.
        if (! $this->rango_dentro_de_horario_closer($event_start, $event_end)) {
            Log::channel('disponibilidad')->info(
                '[CALENDAR_EVENT] No se reserva hueco: falló la condición de horario del closer.'
                . ' lead_id=' . $lead->id
                . ' event_start=' . $event_start->toDateTimeString()
                . ' event_end=' . $event_end->toDateTimeString()
            );
            return false;
        }

        /* Condición 2: el calendario del closer está libre en ese rango. Invalidar la caché de
         * esta fecha ANTES de consultar — un dato de hace 5 minutos alcanza para OFRECER
         * horarios, pero no para decidir si se pisa el calendario de una persona. */
        $fecha_str = $event_start->format('Y-m-d');
        $this->busy_service->flush_cache_for_date($fecha_str);

        $busy_result = $this->busy_service->get_busy_ranges_for_dates([$fecha_str]);
        $busy_ranges = $busy_result['ranges'][$fecha_str] ?? [];

        $inicio_minutos = $event_start->hour * 60 + $event_start->minute;
        $fin_minutos    = $event_end->hour * 60 + $event_end->minute;

        foreach ($busy_ranges as $rango_ocupado) {
            [$bstart, $bend] = $rango_ocupado;
            if ($inicio_minutos < $bend && $fin_minutos > $bstart) {
                Log::channel('disponibilidad')->info(
                    '[CALENDAR_EVENT] No se reserva hueco: falló la condición de calendario libre.'
                    . ' lead_id=' . $lead->id
                    . ' event_start=' . $event_start->toDateTimeString()
                    . ' event_end=' . $event_end->toDateTimeString()
                );
                return false;
            }
        }

        // Las dos condiciones se cumplen: reservar. sendUpdates queda en false a propósito: es lo
        // que evita mandarle a un desconocido una invitación a una reunión que no pidió.
        $event_body = $this->build_hold_event_body($lead, $event_start, $event_end);
        $result     = $this->execute_calendar_event_creation($connection, $event_body, false);

        if ($result === null) {
            return false;
        }

        $lead->update(['closer_hold_event_id' => $result['google_event_id']]);

        Log::channel('disponibilidad')->info(
            '[CALENDAR_EVENT] Reserva preventiva creada en el calendario del closer.'
            . ' lead_id=' . $lead->id
            . ' closer_hold_event_id=' . $result['google_event_id']
            . ' event_start=' . $event_start->toDateTimeString()
            . ' event_end=' . $event_end->toDateTimeString()
        );

        // La reserva recién creada también cambia la disponibilidad: invalidar de nuevo.
        $this->busy_service->flush_cache_for_date($fecha_str);

        return true;
    }

    /**
     * Libera la reserva preventiva del closer (grupo 306, prompt 05). Idempotente: sin
     * `closer_hold_event_id` cargado, no hace nada y no tira error.
     *
     * Una reserva que queda colgada le come al closer un hueco real que el grupo 307 va a
     * necesitar para el próximo lead — por eso esto se engancha en cada punto donde la demo se
     * cancela, se reagenda, o el lead no llega a entrar.
     *
     * @param Lead        $lead
     * @param string|null $demo_date_cache Fecha Y-m-d a invalidar en caché. Mismo motivo que en
     *                                     delete_event_for_lead(): si el llamador ya limpió
     *                                     demo_date en memoria/BD antes de llamar acá (caso
     *                                     cancelación/reagendado), pasarla explícita — si no, se
     *                                     usa $lead->demo_date.
     *
     * @return void
     */
    public function release_hold_for_lead(Lead $lead, ?string $demo_date_cache = null): void
    {
        if (empty($lead->closer_hold_event_id)) {
            return;
        }

        $fecha_str = $demo_date_cache
            ?? ($lead->demo_date ? $lead->demo_date->format('Y-m-d') : null);

        // delete_event_by_id() ya invalida la caché de esta fecha si se le pasa (mismo mecanismo
        // que usa la llamada confirmada al cancelar).
        $this->delete_event_by_id($lead->closer_hold_event_id, $fecha_str);

        $lead->closer_hold_event_id = null;
        $lead->save();

        Log::channel('disponibilidad')->info(
            '[CALENDAR_EVENT] Reserva preventiva del closer liberada.'
            . ' lead_id=' . $lead->id
        );
    }

    /**
     * Actualiza la reserva preventiva del closer para reflejar que el lead ya está haciendo la
     * demo (grupo 306, prompt 07): pasa de "posible llamada" a "demo en curso" sin tocar el
     * horario ni el id del evento.
     *
     * Un PATCH, no un DELETE + POST: recrear el evento le cambiaría el id (closer_hold_event_id
     * es lo que después usa release_hold_for_lead() para liberarlo) y le volvería a sonar la
     * notificación al closer sin motivo. Sin closer_hold_event_id, no hace nada y no loguea
     * error: es el caso normal de una demo que no cumplía las dos condiciones del prompt 05 y por
     * lo tanto no reservó nada.
     *
     * @param Lead $lead Lead con demo_ingreso_confirmado recién marcado.
     * @return void
     */
    public function mark_hold_as_demo_en_curso(Lead $lead): void
    {
        if (empty($lead->closer_hold_event_id)) {
            return;
        }

        $connection = $this->get_closer_connection();
        if (! $connection) {
            return;
        }

        $tz = 'America/Argentina/Buenos_Aires';

        // Mismo cálculo que create_hold_for_lead(): el evento del closer arranca cuando termina
        // la demo. Ese horario no cambia acá -- sin tocar start/end del evento existente.
        $event_times      = $this->calculate_event_times($lead);
        $event_start      = $event_times[0];
        $fin_estimado_str = $event_start->copy()->setTimezone($tz)->format('H:i');

        $lead_nombre = trim(($lead->name ?? '') . ' ' . ($lead->last_name ?? ''));
        if ($lead_nombre === '') {
            $lead_nombre = 'Lead #' . $lead->id;
        }

        $ingreso_confirmado_str = $lead->demo_ingreso_confirmado_at
            ? $lead->demo_ingreso_confirmado_at->copy()->setTimezone($tz)->format('H:i')
            : Carbon::now($tz)->format('H:i');

        $patch_body = [
            'summary'     => '[CC] Demo en curso — ' . $lead_nombre,
            'description' => 'El lead está haciendo la demo ahora mismo. '
                . 'Ingreso confirmado a las ' . $ingreso_confirmado_str . '. '
                . 'Estimado que termina a las ' . $fin_estimado_str . '.' . "\n"
                . 'Lead: ' . $lead_nombre,
            // colorId "5" = banana/amarillo: distinto del "8" (reserva preventiva) y del "2"
            // (llamada confirmada), para que el closer note el cambio sin abrir el evento.
            'colorId' => '5',
        ];

        try {
            $access_token = $this->oauth_service->get_fresh_access_token($connection);

            $response = Http::withToken($access_token)
                ->patch(
                    'https://www.googleapis.com/calendar/v3/calendars/'
                        . urlencode($connection->google_calendar_id)
                        . '/events/' . urlencode($lead->closer_hold_event_id),
                    $patch_body
                );

            if ($response->status() === 404) {
                // El closer borró el evento a mano: no es un error del sistema. Se limpia la
                // columna para que release_hold_for_lead() no intente liberar algo que no existe.
                Log::channel('disponibilidad')->info(
                    '[CALENDAR_EVENT] La reserva preventiva ya no existe en Google (404) al marcar demo en curso.'
                    . ' lead_id=' . $lead->id
                    . ' closer_hold_event_id=' . $lead->closer_hold_event_id
                );
                $lead->closer_hold_event_id = null;
                $lead->save();
                return;
            }

            if ($response->failed()) {
                Log::channel('disponibilidad')->warning(
                    '[CALENDAR_EVENT] Error al marcar la reserva del closer como demo en curso.'
                    . ' lead_id=' . $lead->id
                    . ' HTTP=' . $response->status()
                    . ' body=' . substr($response->body(), 0, 500)
                );
                return;
            }

            Log::channel('disponibilidad')->info(
                '[CALENDAR_EVENT] Reserva del closer actualizada a demo en curso.'
                . ' lead_id=' . $lead->id
                . ' closer_hold_event_id=' . $lead->closer_hold_event_id
            );
        } catch (\Throwable $e) {
            Log::channel('disponibilidad')->error(
                '[CALENDAR_EVENT] Excepción al marcar la reserva del closer como demo en curso.'
                . ' lead_id=' . $lead->id
                . ' error=' . $e->getMessage()
            );
        }
    }

    /**
     * Promueve la reserva preventiva del closer a llamada real (grupo 307, prompt 03): el lead ya
     * confirmó que quiere la llamada, así que el mismo evento -- sin invitados ni Meet, creado por
     * `create_hold_for_lead()` -- pasa a ser la reunión de verdad, por PATCH. No se toca el
     * horario del evento (sigue siendo el que calculó `calculate_event_times()` al crear el hold);
     * solo cambian título, color, invitado y Meet.
     *
     * Mueve el id de `closer_hold_event_id` a `google_event_id` y limpia la columna del hold: las
     * dos NUNCA pueden quedar con el mismo valor -- `google_event_id` es la que consume el flujo
     * de Recall.ai, y si el hold sigue apuntando ahí, cualquier liberación posterior de "el hold"
     * borraría la llamada real.
     *
     * @param Lead $lead Lead con `closer_hold_event_id` cargado.
     *
     * @return array{google_event_id: ?string, meet_url: ?string, event_start: Carbon, event_end: Carbon}|null
     *              null si no hay hold, no hay conexión del closer, o falló la llamada a Google.
     */
    public function promote_hold_to_call(Lead $lead): ?array
    {
        if (empty($lead->closer_hold_event_id)) {
            return null;
        }

        $connection = $this->get_closer_connection();
        if (! $connection) {
            return null;
        }

        [$event_start, $event_end] = $this->calculate_event_times($lead);

        $lead_nombre = trim(($lead->name ?? '') . ' ' . ($lead->last_name ?? ''));
        if ($lead_nombre === '') {
            $lead_nombre = 'Lead #' . $lead->id;
        }

        // Invitado condicional: en la dinámica nueva el lead puede no tener email todavía (grupo
        // 308, prompt 03) -- sin email el evento queda sin invitado y el Meet se pasa por chat.
        // Mismo patrón que build_event_body() y create_ad_hoc_meet_now().
        $attendees = [];
        if (! empty($lead->email)) {
            $attendees[] = ['email' => $lead->email];
        }

        $patch_body = [
            'summary'     => '[CC] Llamada — ' . $lead_nombre,
            'description' => 'Lead: ' . $lead_nombre . "\n" . 'Llamada post-demo confirmada por el lead.',
            // colorId "2" = sage/verde oscuro, el mismo que usan las llamadas confirmadas creadas
            // desde cero (build_event_body()) -- distinto del "8" (reserva preventiva).
            'colorId'        => '2',
            'conferenceData' => [
                'createRequest' => [
                    'requestId'             => 'cc-lead-' . $lead->id . '-hold-promoted-' . time(),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ],
        ];
        if (! empty($attendees)) {
            // Recién acá tiene sentido invitar y pedir sendUpdates: es la primera vez que el lead
            // efectivamente aceptó la reunión (el hold se creó sin su conocimiento).
            $patch_body['attendees'] = $attendees;
        }

        $query_params = 'conferenceDataVersion=1';
        if (! empty($attendees)) {
            $query_params .= '&sendUpdates=all';
        }

        try {
            $access_token = $this->oauth_service->get_fresh_access_token($connection);

            $response = Http::withToken($access_token)
                ->patch(
                    'https://www.googleapis.com/calendar/v3/calendars/'
                        . urlencode($connection->google_calendar_id)
                        . '/events/' . urlencode($lead->closer_hold_event_id) . '?' . $query_params,
                    $patch_body
                );

            if ($response->status() === 404) {
                // El closer borró el evento a mano: no es un error del sistema (mismo criterio que
                // mark_hold_as_demo_en_curso()).
                Log::channel('disponibilidad')->info(
                    '[CALENDAR_EVENT] No se pudo promover el hold a llamada real: ya no existe en Google (404).'
                    . ' lead_id=' . $lead->id
                    . ' closer_hold_event_id=' . $lead->closer_hold_event_id
                );
                $lead->closer_hold_event_id = null;
                $lead->save();

                return null;
            }

            if ($response->failed()) {
                Log::channel('disponibilidad')->warning(
                    '[CALENDAR_EVENT] Error al promover el hold a llamada real.'
                    . ' lead_id=' . $lead->id
                    . ' HTTP=' . $response->status()
                    . ' body=' . substr($response->body(), 0, 500)
                );

                return null;
            }

            $meet_url     = null;
            $entry_points = $response->json('conferenceData.entryPoints') ?? [];
            foreach ($entry_points as $entry) {
                if (($entry['entryPointType'] ?? '') === 'video') {
                    $meet_url = $entry['uri'] ?? null;
                    break;
                }
            }

            $google_event_id = $lead->closer_hold_event_id;

            $lead->google_event_id      = $google_event_id;
            $lead->meet_url             = $meet_url;
            $lead->closer_hold_event_id = null;
            $lead->save();

            Log::channel('disponibilidad')->info(
                '[CALENDAR_EVENT] Reserva preventiva promovida a llamada real.'
                . ' lead_id=' . $lead->id
                . ' google_event_id=' . $google_event_id
                . ' meet_url=' . ($meet_url ?? 'no generada')
            );

            return [
                'google_event_id' => $google_event_id,
                'meet_url'        => $meet_url,
                'event_start'     => $event_start,
                'event_end'       => $event_end,
            ];
        } catch (\Throwable $e) {
            Log::channel('disponibilidad')->error(
                '[CALENDAR_EVENT] Excepción al promover el hold a llamada real.'
                . ' lead_id=' . $lead->id
                . ' error=' . $e->getMessage()
            );

            return null;
        }
    }

    /**
     * Crea el evento de la llamada del closer en un horario EXPLÍCITO, no derivado de
     * `demo_date` + duración (a diferencia de `create_event_for_lead()`). La usa
     * `agendar_llamada_closer` (grupo 307, prompt 03) cuando no hay una reserva preventiva
     * vigente para promover y el lead eligió otro hueco de `CloserAgendaService::next_slots()` --
     * que puede caer en cualquier momento futuro, no necesariamente pegado al fin de SU demo (ej.
     * la rama diferida: demo que termina a las 23:00 y el hueco real es a la mañana siguiente).
     *
     * Reusa el mismo armado de cuerpo de evento que `create_event_for_lead()`
     * (`build_event_body()`: invitado condicional por email, Meet, `sendUpdates` si hay invitado),
     * solo que con el rango que le pasa el llamador en vez de `calculate_event_times($lead)`.
     *
     * @param Lead   $lead        Lead para el cual se crea la llamada.
     * @param Carbon $event_start Inicio del evento, ya validado contra los huecos ofrecidos.
     * @param Carbon $event_end   Fin del evento (inicio + duración de la llamada del closer).
     *
     * @return array{google_event_id: ?string, meet_url: ?string}|null null si no hay conexión del
     *              closer o falló la llamada a Google.
     */
    public function create_call_event_at(Lead $lead, Carbon $event_start, Carbon $event_end): ?array
    {
        $connection = $this->get_closer_connection();
        if (! $connection) {
            return null;
        }

        $event_body       = $this->build_event_body($lead, $event_start, $event_end);
        $lead_tiene_email = ! empty($lead->email);

        $result = $this->execute_calendar_event_creation($connection, $event_body, $lead_tiene_email);

        if ($result === null) {
            return null;
        }

        Log::channel('disponibilidad')->info(
            '[CALENDAR_EVENT] Evento de llamada del closer creado en horario elegido por el lead (sin hold vigente).'
            . ' lead_id=' . $lead->id
            . ' google_event_id=' . $result['google_event_id']
            . ' event_start=' . $event_start->toDateTimeString()
            . ' event_end=' . $event_end->toDateTimeString()
        );

        // Invalidar la caché de disponibilidad: este evento nuevo cambia lo que el closer tiene
        // ocupado ese día.
        $this->busy_service->flush_cache_for_date($event_start->format('Y-m-d'));

        return $result;
    }

    /**
     * Expone `calculate_event_times()` (protected) para que otros servicios puedan conocer el
     * rango [inicio, fin] de la reserva/llamada del closer de un lead sin duplicar la fórmula --
     * la usa `LeadAiService::apply_parsed_response()` para comparar el horario que el lead
     * confirmó contra el rango real del hold vigente.
     *
     * @param Lead $lead
     *
     * @return Carbon[] [event_start, event_end]
     */
    public function get_call_event_times(Lead $lead): array
    {
        return $this->calculate_event_times($lead);
    }

    /**
     * Verifica que el rango [$event_start, $event_end] entre en el horario laboral del closer
     * para el día de semana de $event_start. Respeta el checkbox
     * `llamada_debe_terminar_en_horario`: si está activo, el fin también tiene que entrar.
     *
     * Un rango que cruza medianoche (fin en un día de calendario distinto al de inicio) se
     * considera fuera de horario: el horario configurado es por-día y no tiene sentido partirlo.
     *
     * @param Carbon $event_start
     * @param Carbon $event_end
     *
     * @return bool
     */
    private function rango_dentro_de_horario_closer(Carbon $event_start, Carbon $event_end): bool
    {
        if (! $event_start->isSameDay($event_end)) {
            return false;
        }

        $dow = $event_start->dayOfWeek;
        if ($dow === 0) {
            $horario_raw = LeadDemoSettings::get_closer_horario_domingo();
        } elseif ($dow === 6) {
            $horario_raw = LeadDemoSettings::get_closer_horario_sabado();
        } else {
            $horario_raw = LeadDemoSettings::get_closer_horario_lunes_viernes();
        }

        // Horario vacío significa que el closer no trabaja ese día.
        if ($horario_raw === '') {
            return false;
        }

        $partes = explode('-', $horario_raw);
        if (count($partes) !== 2) {
            return false;
        }

        if (! preg_match('/(\d{1,2}):(\d{2})/', $partes[0], $mi) || ! preg_match('/(\d{1,2}):(\d{2})/', $partes[1], $mf)) {
            return false;
        }

        $horario_inicio = (int) $mi[1] * 60 + (int) $mi[2];
        $horario_fin    = (int) $mf[1] * 60 + (int) $mf[2];

        $inicio_minutos = $event_start->hour * 60 + $event_start->minute;
        $fin_minutos    = $event_end->hour * 60 + $event_end->minute;

        if ($inicio_minutos < $horario_inicio || $inicio_minutos > $horario_fin) {
            return false;
        }

        if (LeadDemoSettings::get_llamada_debe_terminar_en_horario() && $fin_minutos > $horario_fin) {
            return false;
        }

        return true;
    }

    /**
     * Cuerpo del evento de RESERVA PREVENTIVA: sin attendees, sin conferenceData, con un
     * `colorId` distinto del `'2'` de las llamadas confirmadas para que el closer distinga de un
     * vistazo una reserva de una reunión real.
     *
     * @param Lead   $lead
     * @param Carbon $event_start
     * @param Carbon $event_end
     *
     * @return array
     */
    private function build_hold_event_body(Lead $lead, Carbon $event_start, Carbon $event_end): array
    {
        $tz = 'America/Argentina/Buenos_Aires';

        $lead_nombre = trim(($lead->name ?? '') . ' ' . ($lead->last_name ?? ''));
        if ($lead_nombre === '') {
            $lead_nombre = 'Lead #' . $lead->id;
        }

        $start_iso = $event_start->copy()->setTimezone($tz)->toIso8601String();
        $end_iso   = $event_end->copy()->setTimezone($tz)->toIso8601String();

        return [
            // Prefijo [CC] igual que el resto de los eventos generados por ComercioCity.
            'summary'     => '[CC] Posible llamada — ' . $lead_nombre,
            'description' => 'RESERVA PREVENTIVA: el lead todavía no confirmó esta llamada. '
                . 'Se libera sola si la demo no ocurre o si el lead no avanza.' . "\n"
                . 'Lead: ' . $lead_nombre . "\n"
                . 'Demo: ' . $lead->demo_date->format('Y-m-d') . ' ' . $lead->demo_start_time,
            'start'   => [
                'dateTime' => $start_iso,
                'timeZone' => $tz,
            ],
            'end'     => [
                'dateTime' => $end_iso,
                'timeZone' => $tz,
            ],
            // colorId "8" = grafito/gris: distinto del "2" (sage) que usan las llamadas confirmadas.
            'colorId' => '8',
            // Sin attendees ni conferenceData a propósito: es un bloqueo interno, no una invitación.
        ];
    }

    /**
     * Crea un evento en el Google Calendar de una conexión dada y devuelve el id del evento
     * y el link de Meet extraídos de la respuesta de Google. No persiste nada — el llamador
     * decide dónde guardar el resultado (Lead o LeadCall según el caso).
     *
     * @param AdminCalendarConnection $connection  Conexión activa del closer.
     * @param array                   $event_body  Body ya armado para la API de Google Calendar.
     * @param bool                    $send_updates Si true, agrega sendUpdates=all (solo si hay attendees).
     *
     * @return array{google_event_id: ?string, meet_url: ?string}|null null si falló (403, error HTTP, excepción).
     */
    protected function execute_calendar_event_creation(AdminCalendarConnection $connection, array $event_body, bool $send_updates): ?array
    {
        try {
            // Obtener access_token fresco para la llamada.
            $access_token = $this->oauth_service->get_fresh_access_token($connection);

            // Query params: conferenceDataVersion=1 habilita Google Meet; sendUpdates=all solo si hay attendees.
            $query_params = 'conferenceDataVersion=1';
            if ($send_updates) {
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
                return null;
            }

            if ($response->failed()) {
                Log::channel('disponibilidad')->warning(
                    '[CALENDAR_EVENT] Error al crear evento en Google Calendar.'
                    . ' admin_id=' . $connection->admin_id
                    . ' HTTP=' . $response->status()
                    . ' body=' . substr($response->body(), 0, 500)
                );
                return null;
            }

            // Extraer google_event_id de la respuesta.
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

            return ['google_event_id' => $google_event_id, 'meet_url' => $meet_url];
        } catch (\Throwable $e) {
            Log::channel('disponibilidad')->error(
                '[CALENDAR_EVENT] Excepción al crear evento en Google Calendar.'
                . ' admin_id=' . $connection->admin_id
                . ' error=' . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * Crea un evento "ad-hoc" en el Google Calendar del closer para una llamada que arranca
     * AHORA (no ligada a una demo agendada): duración = LeadDemoSettings::get_duracion_llamada_closer_minutos().
     * No persiste nada en el Lead — el llamador decide dónde guardar el resultado (normalmente
     * en una fila de LeadCall). Best-effort: si el closer no tiene calendario conectado o falla
     * la llamada a Google, devuelve null sin lanzar excepción.
     *
     * @param Lead $lead Lead para el cual se crea la llamada.
     *
     * @return array{google_event_id: ?string, meet_url: ?string}|null
     */
    public function create_ad_hoc_meet_now(Lead $lead): ?array
    {
        // Obtener la conexión activa del closer (misma lógica que el flujo de agendamiento normal).
        $connection = $this->get_closer_connection();
        if (! $connection) {
            return null;
        }

        // Zona horaria de Argentina (sin DST, siempre -03:00).
        $tz = 'America/Argentina/Buenos_Aires';

        // El evento ad-hoc arranca ahora mismo y dura lo que dura una llamada de closer configurada.
        $event_start      = Carbon::now($tz);
        $duracion_llamada = LeadDemoSettings::get_duracion_llamada_closer_minutos();
        $event_end        = $event_start->copy()->addMinutes($duracion_llamada);

        // Nombre del lead para el summary y la descripción del evento.
        $lead_nombre = trim(($lead->name ?? '') . ' ' . ($lead->last_name ?? ''));
        if ($lead_nombre === '') {
            $lead_nombre = 'Lead #' . $lead->id;
        }

        // Invitados: si el lead tiene email, Google envía la invitación con el link de Meet.
        $attendees = [];
        if (! empty($lead->email)) {
            $attendees[] = ['email' => $lead->email];
        }

        // Cuerpo simplificado del evento ad-hoc (no depende de demo_date/demo_start_time).
        $event_body = [
            'summary'     => '[CC] Llamada con ' . $lead_nombre,
            'description' => 'Lead: ' . $lead_nombre . "\n"
                . 'Llamada ad-hoc creada manualmente desde el panel del closer.',
            'start'       => [
                'dateTime' => $event_start->toIso8601String(),
                'timeZone' => $tz,
            ],
            'end'         => [
                'dateTime' => $event_end->toIso8601String(),
                'timeZone' => $tz,
            ],
            'colorId'        => '2',
            'conferenceData' => [
                'createRequest' => [
                    'requestId'             => 'cc-lead-' . $lead->id . '-adhoc-' . time(),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ],
        ];
        if (! empty($attendees)) {
            $event_body['attendees'] = $attendees;
        }

        // Crear el evento vía el método compartido; no se persiste nada en el Lead acá.
        $result = $this->execute_calendar_event_creation($connection, $event_body, ! empty($attendees));

        if ($result !== null) {
            Log::channel('disponibilidad')->info(
                '[CALENDAR_EVENT] Evento ad-hoc creado en Google Calendar del closer.'
                . ' lead_id=' . $lead->id
                . ' google_event_id=' . ($result['google_event_id'] ?? 'null')
                . ' meet_url=' . ($result['meet_url'] ?? 'no generada')
            );
        }

        return $result;
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

        // Limpiar google_event_id, meet_url y recall_bot_id del lead en memoria (sin save; el llamador persiste).
        // recall_bot_id se limpia porque si el lead reagenda, se enviará un nuevo bot a la nueva reunión.
        $lead->google_event_id = null;
        $lead->meet_url        = null;
        $lead->recall_bot_id   = null;
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
