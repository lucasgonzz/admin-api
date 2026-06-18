<?php

namespace App\Services;

use App\Events\LeadSuggestionCreated;
use App\Services\CloserGoogleCalendarBusyService;
use App\Services\LeadBroadcastService;
use App\Services\LeadDemoSettings;
use App\Models\AiSystemPrompt;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadPipelineStatus;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Integración con Anthropic (Claude) para sugerir respuestas de WhatsApp al setter.
 *
 * El flujo principal es:
 *   1. Primera llamada a Claude → puede devolver solicita_disponibilidad: true
 *   2. Si es así, consultar slots libres y hacer una segunda llamada con esa info
 *   3. Crear el LeadMessage con la respuesta final (primera o segunda llamada)
 */
class LeadAiService
{
    /**
     * Convierte minutos del día (0-1439) al formato legible HH:MM.
     * Pensado para los logs de diagnóstico de disponibilidad, donde mostrar
     * minutos crudos (por ejemplo 720) es ilegible frente a "12:00".
     *
     * Es público y estático porque también lo reutiliza CloserGoogleCalendarBusyService
     * para formatear los eventos de Google Calendar con el mismo criterio.
     *
     * @param int $minutes Minutos transcurridos desde la medianoche (0 = 00:00).
     * @return string Hora en formato HH:MM en 24hs (ejemplo: 720 → "12:00").
     */
    public static function format_minutes_to_hhmm(int $minutes): string
    {
        /* Parte entera de horas y resto de minutos; sprintf rellena con ceros a la izquierda. */
        $hours = intdiv($minutes, 60);
        $mins  = $minutes % 60;
        return sprintf('%02d:%02d', $hours, $mins);
    }

    /**
     * Normaliza un string de hora ("12:00", "12:00:00", "9:00") al formato HH:MM.
     * Se usa para los logs de demos agendadas, donde demo_start_time/demo_end_time
     * vienen como texto desde la base de datos.
     *
     * @param string|null $time Hora en texto, o null si no está cargada.
     * @return string Hora en formato HH:MM, o "s/h" si no se pudo interpretar.
     */
    private static function time_string_to_hhmm(?string $time): string
    {
        if ($time === null || $time === '') {
            return 's/h';
        }
        /* Extraer HH:MM del texto y reusar el formateador para uniformar el padding. */
        if (preg_match('/(\d{1,2}):(\d{2})/', $time, $m)) {
            return self::format_minutes_to_hhmm((int) $m[1] * 60 + (int) $m[2]);
        }
        return $time;
    }

    /**
     * Arma el texto legible de los rangos ocupados de una fecha para el log.
     * Si no hay rangos, devuelve "libre"; si hay, los lista en formato HH:MM.
     *
     * @param array<int, array{0: int, 1: int}> $ranges Rangos [inicio_min, fin_min].
     * @return string Ejemplo: "ocupado 13:10 a 13:40, ocupado 14:00 a 15:00" o "libre".
     */
    private static function format_busy_ranges_for_date(array $ranges): string
    {
        if (empty($ranges)) {
            return 'libre';
        }
        /* Un fragmento "ocupado HH:MM a HH:MM" por cada rango, separados por coma. */
        $partes = [];
        foreach ($ranges as $rango) {
            $partes[] = 'ocupado ' . self::format_minutes_to_hhmm($rango[0]) . ' a ' . self::format_minutes_to_hhmm($rango[1]);
        }
        return implode(', ', $partes);
    }

    /**
     * Genera un mensaje sugerido por IA y actualiza el estado sugerido del lead.
     *
     * Si Claude devuelve `solicita_disponibilidad: true`, se realiza una segunda
     * llamada con los horarios disponibles antes de crear el LeadMessage.
     *
     * @param Lead $lead         Lead con relación `messages` cargada.
     * @param bool $is_followup  true si lo disparó el scheduler de inactividad.
     *
     * @throws \RuntimeException Si falta API key, falla HTTP o el JSON es inválido.
     *
     * @return LeadMessage Mensaje creado con status `sugerido` (pendiente de envío por el setter).
     */
    public function generate_suggestion(Lead $lead, bool $is_followup): LeadMessage
    {
        /* Validar que la API key esté configurada antes de cualquier llamada. */
        $api_key = (string) config('services.anthropic.api_key');
        if ($api_key === '') {
            throw new \RuntimeException('ANTHROPIC_API_KEY no está configurada.');
        }

        /* Pasar el estado para inyectar la sección FAQ solo cuando corresponde */
        $system       = $this->build_system_prompt();
        $user_content = $this->build_user_content($lead, $is_followup);
        $model        = (string) config('services.anthropic.model', 'claude-sonnet-4-20250514');
        $http         = $this->build_http_client();

        /* Primera llamada a Claude para obtener sugerencia base. */
        $response = $http->post('https://api.anthropic.com/v1/messages', [
            'model'      => $model,
            'max_tokens' => 1000,
            'system'     => $system,
            'messages'   => [
                ['role' => 'user', 'content' => $user_content],
            ],
        ]);

        if ($response->failed()) {
            Log::error('Anthropic API error', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException('Error Anthropic HTTP '.$response->status().': '.$response->body());
        }

        $text   = $this->extract_response_text($response->json());

        /* Log de diagnóstico: respuesta cruda de Claude en la primera llamada. */
        Log::debug('LeadAiService [PRIMERA LLAMADA] - respuesta Claude', [
            'lead_id'  => $lead->id,
            'response' => $text,
        ]);

        $parsed = $this->parse_json_response($text);

        /*
         * Determinar si hay que hacer la segunda llamada con slots disponibles.
         *
         * Se fuerza en dos casos:
         *   1. Claude lo pidió explícitamente (solicita_disponibilidad: true).
         *   2. Claude sugiere demo_agendada: siempre hay que verificar que el slot
         *      propuesto esté libre antes de confirmar, sin importar si Claude
         *      devolvió solicita_disponibilidad: false o no lo incluyó.
         */
        $solicita_disponibilidad = ! empty($parsed['solicita_disponibilidad']);
        $estado_sugerido         = trim((string) ($parsed['estado_sugerido'] ?? ''));

        /* true cuando cualquiera de las dos condiciones aplica */
        $needs_availability_check = $solicita_disponibilidad || $estado_sugerido === 'demo_agendada';

        if ($needs_availability_check) {
            try {
                return $this->generate_suggestion_with_availability($lead, $is_followup);
            } catch (\Throwable $e) {
                Log::error('Error en segunda llamada a Claude (disponibilidad)', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);

                /* Fallback: usar mensaje de primera llamada con nota para el setter. */
                $fallback_base = trim((string) ($parsed['mensaje_sugerido'] ?? ''));
                $parsed['mensaje_sugerido'] = $fallback_base !== ''
                    ? $fallback_base."\n\nNota: No se pudo obtener disponibilidad. El setter debe confirmar horarios manualmente."
                    : 'No se pudo obtener disponibilidad. El setter debe confirmar horarios manualmente.';
            }
        }

        return $this->create_message_and_update_lead($lead, $parsed, $is_followup);
    }

    /**
     * Realiza una segunda llamada a Claude incluyendo los slots de demo disponibles.
     *
     * Se invoca cuando:
     *   - La primera llamada devuelve `solicita_disponibilidad: true`, o bien
     *   - La primera llamada devuelve `estado_sugerido: demo_agendada` (se fuerza
     *     la verificación para evitar confirmar horarios ocupados).
     *
     * Obtiene los horarios libres, detecta si el lead propuso un horario concreto,
     * construye el contexto y repite la llamada a la API para que Claude confirme
     * o rechace ese horario y sugiera alternativas si es necesario.
     *
     * @param Lead $lead        Lead con relación `messages` cargada.
     * @param bool $is_followup true si lo disparó el scheduler de inactividad.
     *
     * @throws \RuntimeException Si falla la llamada HTTP o el JSON es inválido.
     *
     * @return LeadMessage Mensaje creado con los horarios sugeridos por Claude.
     */
    protected function generate_suggestion_with_availability(Lead $lead, bool $is_followup): LeadMessage
    {
        /* JSON estructurado por demo para que Claude interprete disponibilidad sin regex. */
        $availability_data    = $this->build_availability_json();
        $availability_context = "DISPONIBILIDAD DE DEMOS (JSON):\n"
            .json_encode($availability_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        /*
         * Detectar si el último mensaje del lead contiene un horario concreto propuesto.
         * Se usa solo como pista adicional; Claude debe cruzar con el JSON de arriba.
         */
        $lead_proposed_time = '';

        /* Último mensaje enviado por el lead (sender = 'lead'). */
        $last_lead_message = $lead->messages
            ->filter(fn($m) => (string) $m->sender === 'lead')
            ->last();

        if ($last_lead_message) {
            $last_content = trim((string) $last_lead_message->content);

            if (preg_match('/\b(\d{1,2})(?::(\d{2}))?\s*(?:hs?|h|:00)?\b/i', $last_content, $m)) {
                $lead_proposed_time = $m[0];
            }
        }

        /* Instrucciones para agendar demo usando el JSON de disponibilidad. */
        $availability_context .= "\n\nINSTRUCCIONES PARA AGENDAR:";
        $availability_context .= "\n- Analizá el historial de la conversación para determinar qué fecha y hora quiere el lead (puede decir \"hoy\", \"mañana\", \"el jueves\", \"a las 16\", etc.).";
        $availability_context .= "\n- Verificá que ese slot esté disponible en el JSON de arriba para la demo correspondiente.";
        $availability_context .= "\n- Si el slot está disponible: confirmalo al lead y devolvé agendar_demo con demo_id, demo_date (formato Y-m-d), demo_start_time (formato HH:MM). NO incluyas demo_end_time; el servidor lo calcula.";
        $availability_context .= "\n- Si el slot NO está disponible: informale al lead con naturalidad y ofrecé las alternativas más cercanas disponibles.";
        $availability_context .= "\n- El demo_id debe corresponder a una demo que tenga ese slot disponible en el JSON.";
        $availability_context .= "\n- Nunca confirmes un horario que no aparezca en el JSON de disponibilidad.";

        if ($lead_proposed_time !== '') {
            $availability_context .= "\n- El lead propuso el horario: \"{$lead_proposed_time}\". Verificá si ese horario aparece en el JSON de disponibilidad.";
        }

        /* Pasar el estado para inyectar la sección FAQ solo cuando corresponde */
        $system       = $this->build_system_prompt();
        $user_content = $this->build_user_content($lead, $is_followup, $availability_context);
        $model        = (string) config('services.anthropic.model', 'claude-sonnet-4-20250514');
        $http         = $this->build_http_client();

        /* Segunda llamada a Claude con disponibilidad como contexto adicional. */
        $response = $http->post('https://api.anthropic.com/v1/messages', [
            'model'      => $model,
            'max_tokens' => 1000,
            'system'     => $system,
            'messages'   => [
                ['role' => 'user', 'content' => $user_content],
            ],
        ]);

        if ($response->failed()) {
            Log::error('Anthropic API error (segunda llamada con disponibilidad)', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('Error Anthropic HTTP '.$response->status().': '.$response->body());
        }

        $text   = $this->extract_response_text($response->json());

        /* Log de diagnóstico: respuesta cruda de Claude en la segunda llamada. */
        Log::debug('LeadAiService [SEGUNDA LLAMADA - con disponibilidad] - respuesta Claude', [
            'lead_id'  => $lead->id,
            'response' => $text,
        ]);

        $parsed = $this->parse_json_response($text);

        return $this->create_message_and_update_lead($lead, $parsed, $is_followup);
    }

    /**
     * Construye el JSON de disponibilidad por demo para que Claude interprete slots sin regex.
     *
     * Incluye la fecha/hora actual en Argentina, la duración configurada de cada demo
     * y un mapa demo_id → fecha (Y-m-d) → horarios libres (HH:MM).
     *
     * @param int $days_ahead Cantidad mínima de días hábiles a incluir (default: 3).
     *
     * @return array<string, mixed> Estructura: hoy, duration_demo_minutos, demos.
     */
    public function build_availability_json(int $days_ahead = 3): array
    {
        /* Contexto compartido: días hábiles, rangos bloqueados y parámetros de demo. */
        $context = $this->prepare_slot_availability_context($days_ahead);

        /* Etiqueta legible de hoy en timezone Argentina. */
        $day_names_full = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $hoy_label      = ucfirst($day_names_full[$context['now']->dayOfWeek])
            .' '.$context['now']->format('d/m/Y').', '.$context['now']->format('H:i').'hs (hora Argentina)';

        /* Slots disponibles por demo y por fecha.
         * Cada llamada aplica las dos capas de bloqueo: por demo y por closer. */
        $demos_json = [];
        foreach ($context['demos'] as $demo) {
            $demo_id = (int) $demo->id;
            $demos_json[$demo_id] = [];

            foreach ($context['dates_map'] as $date_key => $day) {
                /* Rangos bloqueados por este entorno técnico específico. */
                $blocked_ranges = $context['blocked_by_demo'][$demo_id][$date_key] ?? [];
                /* Rangos de closer ocupado para esta fecha (transversal a todas las demos). */
                $closer_busy_for_date = $context['closer_busy'][$date_key] ?? [];
                $demos_json[$demo_id][$date_key] = $this->compute_day_slots_for_demo(
                    $day,
                    $blocked_ranges,
                    $context['now'],
                    $context['today_key'],
                    $context['now_minutes'],
                    $context['duracion'],
                    $closer_busy_for_date,
                    $context['gracia_post']
                );
            }
        }

        return [
            'hoy'                   => $hoy_label,
            'duration_demo_minutos' => $context['duracion'],
            'demos'                 => $demos_json,
        ];
    }

    /**
     * Prepara días hábiles, consulta de bloqueos y mapa de fechas para disponibilidad.
     *
     * Centraliza la lógica compartida entre get_available_slots() y build_availability_json().
     * Si algún día queda sin slots libres en la unión de demos, agrega un día hábil extra.
     *
     * @param int $days_ahead Cantidad mínima de días hábiles a incluir.
     *
     * @return array<string, mixed>
     */
    protected function prepare_slot_availability_context(int $days_ahead = 3): array
    {
        /* Parámetros de configuración de demos. */
        $duracion    = LeadDemoSettings::get_duracion_minutos();
        $setup_antes = LeadDemoSettings::get_setup_minutos_antes();
        $gracia_post = LeadDemoSettings::get_gracia_minutos_post();

        /* Demos activas; sin ellas se delega al algoritmo legacy en get_available_slots(). */
        $demos = \App\Models\Demo::orderBy('id')->get();

        /* Instante actual en Argentina. */
        $now         = now('America/Argentina/Buenos_Aires');
        $now_minutes = $now->hour * 60 + $now->minute;
        $today_key   = $now->copy()->startOfDay()->format('Y-m-d');
        $cursor      = $now->copy()->startOfDay();

        /* Lista inicial de días hábiles (lunes a sábado, sin domingos). */
        $working_days = [];
        while (count($working_days) < $days_ahead) {
            if ($cursor->dayOfWeek !== 0) {
                $working_days[] = $cursor->copy();
            }
            $cursor->addDay();
        }

        $date_strings = [];
        foreach ($working_days as $day) {
            $date_strings[] = $day->format('Y-m-d');
        }

        /* Rangos bloqueados por demo y rangos de closer ocupado para los días iniciales.
         * Ambas estructuras se construyen en un solo recorrido sobre la misma query de leads. */
        $load_result     = $this->load_blocked_ranges_by_demo($demos, $date_strings, $duracion, $setup_antes, $gracia_post);
        $blocked_by_demo = $load_result['blocked_by_demo'];
        $closer_busy     = $load_result['closer_busy'];

        /* Tercera capa de bloqueo: eventos del calendario Google del closer.
         * Si la API de Google falla, se degrada de forma segura (continúa sin esta capa)
         * para no romper el flujo de WhatsApp por un error externo. */
        try {
            $google_busy_service = new CloserGoogleCalendarBusyService(
                new \App\Services\GoogleCalendarOAuthService()
            );
            $google_busy = $google_busy_service->get_busy_ranges_for_dates($date_strings);

            // Fusionar rangos de Google Calendar con los rangos de agenda interna.
            foreach ($date_strings as $date) {
                if (! empty($google_busy[$date])) {
                    $closer_busy[$date] = array_merge(
                        $closer_busy[$date] ?? [],
                        $google_busy[$date]
                    );
                }
            }
        } catch (\Exception $e) {
            // Degradación segura: loguear y continuar sin la capa de Google Calendar.
            Log::warning('LeadAiService: fallo en CloserGoogleCalendarBusyService, se continúa sin la tercera capa', [
                'error' => $e->getMessage(),
            ]);
        }

        /* Diagnóstico: rangos de closer ocupado ya combinados (capa 2 interna + capa 3 Google),
         * por fecha y en formato HH:MM legible, antes de calcular los slots libres.
         * Permite comparar de un vistazo contra el log "closer_busy interno" y ver qué
         * aportó la capa de Google Calendar. Va al canal propio 'disponibilidad'. */
        $lineas_combinado = [];
        foreach ($date_strings as $fecha) {
            $lineas_combinado[] = '  ' . $fecha . ': ' . self::format_busy_ranges_for_date($closer_busy[$fecha] ?? []);
        }
        Log::channel('disponibilidad')->info(
            "[DISPONIBILIDAD] closer_busy combinado (interno + Google) por fecha:\n"
            . implode("\n", $lineas_combinado)
        );

        /* Mapa fecha → Carbon y unión de slots para detectar días sin disponibilidad. */
        $dates_map = [];
        $any_full  = false;

        foreach ($working_days as $day) {
            $date_key              = $day->format('Y-m-d');
            $dates_map[$date_key]  = $day;
            $union_available       = [];

            foreach ($demos as $demo) {
                $demo_slots = $this->compute_day_slots_for_demo(
                    $day,
                    $blocked_by_demo[$demo->id][$date_key] ?? [],
                    $now,
                    $today_key,
                    $now_minutes,
                    $duracion,
                    $closer_busy[$date_key] ?? [],
                    $gracia_post
                );
                foreach ($demo_slots as $slot) {
                    if (! in_array($slot, $union_available, true)) {
                        $union_available[] = $slot;
                    }
                }
            }

            if (empty($union_available)) {
                $any_full = true;
            }
        }

        /*
         * Si algún día quedó sin slots en la unión, agregar el siguiente día hábil
         * para que Claude siempre tenga alternativas concretas.
         */
        if ($any_full) {
            while ($cursor->dayOfWeek === 0) {
                $cursor->addDay();
            }
            $extra_key  = $cursor->format('Y-m-d');
            $dates_map[$extra_key] = $cursor->copy();

            /* Cargar bloqueos del día extra y fusionarlos con los ya existentes. */
            $extra_result = $this->load_blocked_ranges_by_demo($demos, [$extra_key], $duracion, $setup_antes, $gracia_post);
            foreach ($demos as $demo) {
                $blocked_by_demo[$demo->id][$extra_key] = $extra_result['blocked_by_demo'][$demo->id][$extra_key] ?? [];
            }
            /* Fusionar rangos de closer del día extra (agenda interna). */
            $closer_busy[$extra_key] = $extra_result['closer_busy'][$extra_key] ?? [];

            /* Agregar también la tercera capa (Google Calendar) para el día extra. */
            try {
                $google_busy_service_extra = new CloserGoogleCalendarBusyService(
                    new \App\Services\GoogleCalendarOAuthService()
                );
                $google_busy_extra = $google_busy_service_extra->get_busy_ranges_for_dates([$extra_key]);
                if (! empty($google_busy_extra[$extra_key])) {
                    $closer_busy[$extra_key] = array_merge(
                        $closer_busy[$extra_key],
                        $google_busy_extra[$extra_key]
                    );
                }
            } catch (\Exception $e) {
                Log::warning('LeadAiService: fallo en CloserGoogleCalendarBusyService para día extra', [
                    'extra_key' => $extra_key,
                    'error'     => $e->getMessage(),
                ]);
            }

            /* Diagnóstico: closer_busy combinado para el día extra agregado.
             * Prefijo distinto ([DISPONIBILIDAD - día extra]) para no confundirlo con el
             * bloque del ciclo principal; la función produce dos bloques cuando hay día extra. */
            Log::channel('disponibilidad')->info(
                "[DISPONIBILIDAD - día extra] closer_busy combinado (interno + Google) del día extra agregado:\n"
                . '  ' . $extra_key . ': ' . self::format_busy_ranges_for_date($closer_busy[$extra_key] ?? [])
            );
        }

        return [
            'duracion'        => $duracion,
            'gracia_post'     => $gracia_post,
            'now'             => $now,
            'now_minutes'     => $now_minutes,
            'today_key'       => $today_key,
            'demos'           => $demos,
            'dates_map'       => $dates_map,
            'blocked_by_demo' => $blocked_by_demo,
            'closer_busy'     => $closer_busy,
        ];
    }

    /**
     * Consulta leads con demo agendada y arma rangos bloqueados por demo y fecha,
     * junto con los rangos de ocupación del closer (transversales a todas las demos).
     *
     * El closer queda ocupado desde [fin_demo + gracia_post] hasta
     * [fin_demo + gracia_post + duracion_llamada_closer_minutos].
     * Ese bloqueo es independiente del entorno técnico (demo_id) y evita que
     * dos leads liberen su demo en ventanas solapadas que requieran al closer.
     *
     * @param \Illuminate\Support\Collection $demos         Colección de Demo.
     * @param string[]                       $date_strings  Fechas Y-m-d a consultar.
     * @param int                            $duracion      Duración de la demo en minutos.
     * @param int                            $setup_antes   Margen de setup antes del inicio.
     * @param int                            $gracia_post   Margen de gracia después del fin.
     *
     * @return array{
     *   blocked_by_demo: array<int, array<string, array<int, array{0: int, 1: int}>>>,
     *   closer_busy: array<string, array<int, array{0: int, 1: int}>>
     * }
     */
    protected function load_blocked_ranges_by_demo($demos, array $date_strings, int $duracion, int $setup_antes, int $gracia_post): array
    {
        /* Inicializar estructura vacía por demo y fecha. */
        $blocked_by_demo = [];
        foreach ($demos as $demo) {
            $blocked_by_demo[$demo->id] = [];
            foreach ($date_strings as $date) {
                $blocked_by_demo[$demo->id][$date] = [];
            }
        }

        /* Inicializar estructura de closer ocupado por fecha (transversal a demos). */
        $closer_busy = [];
        foreach ($date_strings as $date) {
            $closer_busy[$date] = [];
        }

        if (empty($date_strings)) {
            return ['blocked_by_demo' => $blocked_by_demo, 'closer_busy' => $closer_busy];
        }

        /* Duración de la llamada del closer; define el ancho de la ventana ocupada post-gracia. */
        $duracion_closer = LeadDemoSettings::get_duracion_llamada_closer_minutos();

        /* Leads con demo en las fechas solicitadas.
         * demo_date es una columna DATE pura (sin hora ni timezone), por lo que se compara
         * directamente con whereIn sin ninguna conversión de zona horaria. */
        $booked_leads = Lead::whereIn('demo_date', $date_strings)
            ->whereNotNull('demo_start_time')
            ->whereNotNull('demo_id')
            ->get(['id', 'demo_id', 'demo_date', 'demo_start_time', 'demo_end_time']);

        /* Diagnóstico: detalle de cada demo agendada encontrada para las fechas consultadas,
         * como texto plano legible (una línea por demo) en el canal propio 'disponibilidad'.
         * Permite confirmar qué leads (capa 1 y 2) está considerando el cálculo de disponibilidad. */
        $lineas_demos = [];
        foreach ($booked_leads as $bl_log) {
            $fecha_demo = $bl_log->demo_date ? $bl_log->demo_date->format('Y-m-d') : 's/fecha';
            $hora_inicio = self::time_string_to_hhmm($bl_log->demo_start_time);
            $hora_fin    = self::time_string_to_hhmm($bl_log->demo_end_time);
            $lineas_demos[] = '  - Lead #' . $bl_log->id . ' | Demo #' . $bl_log->demo_id
                . ' | ' . $fecha_demo . ' | ' . $hora_inicio . ' a ' . $hora_fin;
        }

        /* Cantidad de demos para la línea de resumen (con pluralización correcta).
         * Se loguea aunque sea 0 para distinguir "no hay demos" de "no se ejecutó el log". */
        $cantidad_demos = $booked_leads->count();
        $resumen_demos  = '(' . $cantidad_demos . ' demo' . ($cantidad_demos === 1 ? '' : 's')
            . ' encontrada' . ($cantidad_demos === 1 ? '' : 's') . ')';

        $mensaje_demos = '[DISPONIBILIDAD] Demos agendadas encontradas para ' . implode(', ', $date_strings) . ':' . "\n";
        if ($cantidad_demos > 0) {
            $mensaje_demos .= implode("\n", $lineas_demos) . "\n";
        }
        $mensaje_demos .= $resumen_demos;

        Log::channel('disponibilidad')->info($mensaje_demos, ['cantidad' => $cantidad_demos]);

        foreach ($booked_leads as $bl) {
            $demo_id  = (int) $bl->demo_id;
            /* demo_date es una fecha de calendario pura; no tiene timezone, se formatea directamente. */
            $date_key = $bl->demo_date->format('Y-m-d');

            if (! preg_match('/(\d{1,2}):(\d{2})/', (string) $bl->demo_start_time, $m)) {
                continue;
            }
            $start_minutes = (int) $m[1] * 60 + (int) $m[2];

            if ($bl->demo_end_time && preg_match('/(\d{1,2}):(\d{2})/', (string) $bl->demo_end_time, $m2)) {
                $end_minutes = (int) $m2[1] * 60 + (int) $m2[2];
            } else {
                $end_minutes = $start_minutes + $duracion;
            }

            /* Bloqueo por demo: impide que dos leads usen el mismo entorno técnico en simultáneo. */
            if (isset($blocked_by_demo[$demo_id][$date_key])) {
                $blocked_by_demo[$demo_id][$date_key][] = [$start_minutes - $setup_antes, $end_minutes + $gracia_post];
            }

            /* Bloqueo del closer: ventana post-gracia en la que el closer atiende a este lead.
             * Es transversal a todas las demos; aplica a cualquier fecha que esté en el mapa. */
            if (isset($closer_busy[$date_key])) {
                /* Inicio de la ventana del closer: cuando el lead queda listo post-gracia. */
                $closer_start = $end_minutes + $gracia_post;
                /* Fin de la ventana: inicio + duración estimada de la llamada. */
                $closer_end   = $closer_start + $duracion_closer;
                $closer_busy[$date_key][] = [$closer_start, $closer_end];
            }
        }

        /* Diagnóstico: closer_busy interno (agenda calculada a partir de las demos, antes de
         * mezclar con Google Calendar), por fecha y en formato HH:MM legible. Se compara luego
         * contra el log "closer_busy combinado" para ver exactamente qué aportó la capa de Google. */
        $lineas_busy_interno = [];
        foreach ($closer_busy as $fecha => $rangos) {
            $lineas_busy_interno[] = '  ' . $fecha . ': ' . self::format_busy_ranges_for_date($rangos);
        }

        Log::channel('disponibilidad')->info(
            "[DISPONIBILIDAD] closer_busy interno (agenda calculada) por fecha:\n"
            . implode("\n", $lineas_busy_interno)
        );

        return ['blocked_by_demo' => $blocked_by_demo, 'closer_busy' => $closer_busy];
    }

    /**
     * Calcula los slots libres de una demo en un día concreto.
     *
     * Aplica dos capas de bloqueo independientes:
     *   1. Bloqueo por demo_id: evita que dos leads usen el mismo entorno técnico en simultáneo.
     *   2. Bloqueo por closer: evita que el closer deba atender dos leads en ventanas solapadas.
     *
     * Un slot candidato es válido solo si pasa ambas validaciones.
     *
     * Nota sobre el linde exacto en el chequeo de closer: se usan comparaciones estrictas
     * (> y <) para que el linde exacto (un lead libera al closer justo cuando otro lo necesita)
     * se considere válido, conforme a la regla de negocio acordada.
     *
     * @param Carbon                              $day                         Día a evaluar.
     * @param array<int, array{0: int, 1: int}>   $blocked_ranges              Rangos bloqueados por demo en minutos del día.
     * @param Carbon                              $now                         Instante actual en Argentina.
     * @param string                              $today_key                   Fecha de hoy (Y-m-d).
     * @param int                                 $now_minutes                 Minutos transcurridos hoy.
     * @param int                                 $duracion                    Duración de la demo en minutos.
     * @param array<int, array{0: int, 1: int}>   $closer_busy_ranges_for_date Rangos de closer ocupado para este día (transversal a demos).
     * @param int                                 $gracia_post                 Minutos de gracia post-demo; necesario para calcular cuándo el lead candidato liberaría al closer.
     *
     * @return string[] Horarios disponibles en formato HH:MM.
     */
    protected function compute_day_slots_for_demo(Carbon $day, array $blocked_ranges, Carbon $now, string $today_key, int $now_minutes, int $duracion, array $closer_busy_ranges_for_date = [], int $gracia_post = 0): array
    {
        $date_key  = $day->format('Y-m-d');
        $is_today  = $date_key === $today_key;

        /* Slots candidatos del día según protocolo: sábados 9-11hs, lunes-viernes 9-20hs. */
        $all_slots = $this->get_all_slots_for_day($day);

        $available = [];
        foreach ($all_slots as $slot) {
            [$sh, $sm]  = explode(':', $slot);
            $slot_start = (int) $sh * 60 + (int) $sm;
            $slot_end   = $slot_start + $duracion;

            /* Hoy: descartar slots pasados o con menos de 30 min de margen. */
            if ($is_today && $slot_start < $now_minutes + 30) {
                continue;
            }

            $slot_free = true;

            /* Capa 1: chequeo por demo_id; impide solapar entornos técnicos. */
            foreach ($blocked_ranges as [$bstart, $bend]) {
                if ($slot_start < $bend && $slot_end > $bstart) {
                    $slot_free = false;
                    break;
                }
            }

            /* Capa 2: chequeo por closer; impide que el closer deba atender dos leads en simultáneo.
             * Se verifica si el momento en que el lead candidato liberaría al closer
             * (slot_end + gracia_post) cae dentro de una ventana ya comprometida por otro lead.
             * Comparaciones estrictas: el linde exacto (un lead termina justo cuando otro empieza) es válido. */
            if ($slot_free && ! empty($closer_busy_ranges_for_date)) {
                /* Instante en que este lead candidato quedaría disponible para el closer. */
                $closer_release = $slot_end + $gracia_post;
                foreach ($closer_busy_ranges_for_date as [$cstart, $cend]) {
                    if ($closer_release > $cstart && $closer_release < $cend) {
                        $slot_free = false;
                        break;
                    }
                }
            }

            if ($slot_free) {
                $available[] = $slot;
            }
        }

        return $available;
    }

    /**
     * Consulta los horarios de demo ocupados y devuelve los slots disponibles por día.
     *
     * Incluye los próximos $days_ahead días hábiles (lunes a sábado) a partir de mañana.
     * Si alguno de esos días queda sin disponibilidad, agrega el siguiente día hábil.
     *
     * Horarios posibles:
     *   - Lunes a viernes: cada hora de 09:00 a 20:00 (12 bloques, el último termina a las 21:00)
     *   - Sábado: 09:00, 10:00, 11:00 (3 bloques, el último termina a las 12:00)
     *
     * Un slot está ocupado si existe un lead con `demo_date` en esa fecha
     * y `demo_start_time` que coincide con el inicio del bloque.
     *
     * @param int $days_ahead Cantidad mínima de días hábiles a incluir (default: 3).
     *
     * @return array<string, string[]> Mapa fecha (Y-m-d) → array de slots disponibles ('HH:MM').
     */
    public function get_available_slots(int $days_ahead = 3): array
    {
        /* Obtener todas las demos registradas para el cálculo multi-demo. */
        $demos = \App\Models\Demo::orderBy('id')->get();

        /*
         * Fallback: si no hay demos registradas, usar el algoritmo legacy
         * (bloquea exactamente el slot de inicio sin márgenes).
         */
        if ($demos->isEmpty()) {
            return $this->get_available_slots_legacy($days_ahead);
        }

        /* Contexto compartido con build_availability_json(). */
        $context = $this->prepare_slot_availability_context($days_ahead);
        $result  = [];

        foreach ($context['dates_map'] as $date_key => $day) {
            $union_available = [];

            /* Rangos de closer ocupado para esta fecha (transversal a todas las demos). */
            $closer_busy_for_date = $context['closer_busy'][$date_key] ?? [];

            foreach ($context['demos'] as $demo) {
                $demo_slots = $this->compute_day_slots_for_demo(
                    $day,
                    $context['blocked_by_demo'][$demo->id][$date_key] ?? [],
                    $context['now'],
                    $context['today_key'],
                    $context['now_minutes'],
                    $context['duracion'],
                    $closer_busy_for_date,
                    $context['gracia_post']
                );

                foreach ($demo_slots as $slot) {
                    if (! in_array($slot, $union_available, true)) {
                        $union_available[] = $slot;
                    }
                }
            }

            $result[$date_key] = $union_available;
        }

        return $result;
    }

    /**
     * Algoritmo legacy de disponibilidad: bloquea exactamente el slot de inicio_time
     * sin márgenes ni soporte multi-demo. Se usa como fallback cuando no hay demos.
     *
     * Un slot está ocupado si existe un lead con `demo_date` en esa fecha
     * y `demo_start_time` que coincide con el inicio del bloque.
     *
     * @param int $days_ahead Cantidad mínima de días hábiles a incluir (default: 3).
     *
     * @return array<string, string[]> Mapa fecha (Y-m-d) → array de slots disponibles ('HH:MM').
     */
    public function get_available_slots_legacy(int $days_ahead = 3): array
    {
        /* Construir lista de días hábiles a partir de HOY. */
        $working_days = [];
        /* Instante actual en Argentina; se usa para filtrar slots de hoy ya pasados. */
        $now = now('America/Argentina/Buenos_Aires');
        /* Minutos transcurridos del día actual (para comparar contra horas de slot). */
        $now_minutes = $now->hour * 60 + $now->minute;
        /* Fecha de hoy (Y-m-d) para detectar el día actual dentro del loop de slots. */
        $today_key = $now->copy()->startOfDay()->format('Y-m-d');
        $cursor    = $now->copy()->startOfDay();

        while (count($working_days) < $days_ahead) {
            if ($cursor->dayOfWeek !== 0) {
                $working_days[] = $cursor->copy();
            }
            $cursor->addDay();
        }

        $date_strings = array_map(function ($day) {
            return $day->format('Y-m-d');
        }, $working_days);

        /* demo_date es DATE puro; se compara directamente sin conversión de timezone. */
        $booked_leads = Lead::whereIn('demo_date', $date_strings)
            ->whereNotNull('demo_start_time')
            ->get(['demo_date', 'demo_start_time']);

        /* Agrupar horarios ocupados por fecha. */
        $occupied_by_date = [];
        foreach ($booked_leads as $booked_lead) {
            /* demo_date no tiene timezone: formatear directamente sin setTimezone(). */
            $date_key = $booked_lead->demo_date->format('Y-m-d');
            $time_raw = trim((string) $booked_lead->demo_start_time);
            if (preg_match('/(\d{1,2}):(\d{2})/', $time_raw, $m)) {
                $occupied_by_date[$date_key][] = str_pad($m[1], 2, '0', STR_PAD_LEFT).':'.$m[2];
            }
        }

        $result   = [];
        $any_full = false;

        foreach ($working_days as $day) {
            $date_key  = $day->format('Y-m-d');
            /* Slots candidatos del día según protocolo (método centralizado). */
            $all_slots = $this->get_all_slots_for_day($day);

            /* Indica si el día que estamos evaluando es hoy. */
            $is_today = $date_key === $today_key;

            $booked    = isset($occupied_by_date[$date_key]) ? $occupied_by_date[$date_key] : [];
            $available = array_values(array_filter($all_slots, function ($slot) use ($booked, $is_today, $now_minutes) {
                /*
                 * Para el día de hoy, descartar los slots cuyo horario de inicio
                 * ya pasó o está demasiado cerca (margen mínimo de 30 minutos).
                 */
                if ($is_today) {
                    [$sh, $sm]  = explode(':', $slot);
                    $slot_start = (int) $sh * 60 + (int) $sm;
                    if ($slot_start < $now_minutes + 30) {
                        return false;
                    }
                }

                return ! in_array($slot, $booked, true);
            }));

            if (empty($available)) {
                $any_full = true;
            }
            $result[$date_key] = $available;
        }

        if ($any_full) {
            while ($cursor->dayOfWeek === 0) {
                $cursor->addDay();
            }
            $extra_key   = $cursor->format('Y-m-d');
            /* demo_date es DATE puro; comparar directamente sin conversión de timezone. */
            $extra_leads = Lead::where('demo_date', $extra_key)
                ->whereNotNull('demo_start_time')
                ->get(['demo_date', 'demo_start_time']);

            $extra_booked = [];
            foreach ($extra_leads as $el) {
                $time_raw = trim((string) $el->demo_start_time);
                if (preg_match('/(\d{1,2}):(\d{2})/', $time_raw, $m)) {
                    $extra_booked[] = str_pad($m[1], 2, '0', STR_PAD_LEFT).':'.$m[2];
                }
            }

            /* Slots del día extra: usar el mismo método centralizado. */
            $extra_all_slots = $this->get_all_slots_for_day($cursor);

            $result[$extra_key] = array_values(array_filter($extra_all_slots, function ($slot) use ($extra_booked) {
                return ! in_array($slot, $extra_booked, true);
            }));
        }

        return $result;
    }

    /**
     * Devuelve los horarios candidatos para un día concreto según el protocolo de demos.
     *
     * Centraliza la lista de slots para evitar que los distintos puntos del archivo
     * (compute_day_slots_for_demo, get_available_slots_legacy y el bloque del día extra)
     * puedan desincronizarse entre sí.
     *
     * Reglas según protocolo WhatsApp (sección "AGENDA DE DEMOS"):
     *   - Lunes a viernes: 09:00 a 20:00, una hora por bloque (12 slots).
     *   - Sábado: 09:00, 10:00, 11:00 (último bloque termina a las 12:00).
     *   - Domingo: no aplica (el caller no debe pasar domingos).
     *
     * @param Carbon $day Día a evaluar.
     *
     * @return string[] Horarios en formato HH:MM.
     */
    private function get_all_slots_for_day(Carbon $day): array
    {
        /* Sábado: rango reducido de mañana. */
        if ($day->dayOfWeek === 6) {
            return ['09:00', '10:00', '11:00'];
        }

        /* Lunes a viernes: rango completo hasta las 20:00 (bloque final termina a las 21:00). */
        return [
            '09:00', '10:00', '11:00', '12:00',
            '13:00', '14:00', '15:00', '16:00',
            '17:00', '18:00', '19:00', '20:00',
        ];
    }

    /**
     * Construye el cliente HTTP configurado para Anthropic.
     *
     * Centraliza el setup de headers, timeout y opciones TLS (necesario en WAMP/Windows)
     * para reutilizarlo en la primera y segunda llamada a Claude.
     *
     * @return PendingRequest
     */
    protected function build_http_client(): PendingRequest
    {
        $api_key = (string) config('services.anthropic.api_key');

        $http = Http::withHeaders([
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(90);

        /* Configuración TLS según entorno (cacert para WAMP en Windows). */
        $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
        $ca_bundle  = config('services.anthropic.ca_bundle');

        if (! $verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        return $http;
    }

    /**
     * Extrae el texto concatenado de todos los bloques de contenido de la respuesta de Claude.
     *
     * @param array<string, mixed> $body Respuesta JSON decodificada de la API.
     *
     * @return string Texto completo extraído.
     */
    protected function extract_response_text(array $body): string
    {
        $text = '';

        if (isset($body['content']) && is_array($body['content'])) {
            foreach ($body['content'] as $block) {
                if (is_array($block) && isset($block['text'])) {
                    $text .= (string) $block['text'];
                }
            }
        }

        return $text;
    }

    /**
     * Crea el LeadMessage y actualiza el estado del lead a partir del JSON de Claude.
     *
     * Operación compartida entre la primera y segunda llamada a Claude.
     * Aplica el estado sugerido, marca flags de seguimiento y persiste todo.
     *
     * @param Lead                 $lead        Lead a actualizar.
     * @param array<string, mixed> $parsed      JSON decodificado de la respuesta de Claude.
     * @param bool                 $is_followup true si el trigger fue el scheduler de inactividad.
     *
     * @throws \RuntimeException Si el mensaje o el estado sugerido vienen vacíos.
     *
     * @return LeadMessage Mensaje creado con status `sugerido` (sin envío a WhatsApp).
     */
    protected function create_message_and_update_lead(Lead $lead, array $parsed, bool $is_followup): LeadMessage
    {
        /* Extraer y validar los campos obligatorios de la respuesta. */
        $mensaje    = isset($parsed['mensaje_sugerido']) ? trim((string) $parsed['mensaje_sugerido']) : '';
        $estado_raw = isset($parsed['estado_sugerido']) ? trim((string) $parsed['estado_sugerido']) : '';

        if ($mensaje === '' || $estado_raw === '') {
            throw new \RuntimeException('Respuesta de Claude incompleta (mensaje o estado vacío).');
        }

        /* Estado del lead antes de aplicar la sugerencia (para badge en el mensaje). */
        $previous_status = (string) $lead->status;

        /* Crea el estado en catálogo si Claude devolvió uno nuevo; normaliza slug. */
        $pipeline_status = LeadPipelineStatus::ensure_exists($estado_raw);
        $estado          = $pipeline_status->slug;

        $razonamiento = isset($parsed['razonamiento']) ? (string) $parsed['razonamiento'] : null;
        $req_verif    = ! empty($parsed['requiere_verificacion']);

        /* Solo marcamos el mensaje si la sugerencia implica un cambio de estado del lead. */
        $suggested_lead_status = $estado !== $previous_status ? $estado : null;

        /* --- Procesar acciones estructuradas devueltas por Claude --- */

        /* Acción: guardar nombre del lead si no tiene uno y Claude lo identificó con certeza. */
        $guardar_nombre = isset($parsed['guardar_nombre']) ? trim((string) $parsed['guardar_nombre']) : '';
        if ($guardar_nombre !== '' && empty($lead->contact_name)) {
            $lead->contact_name = $guardar_nombre;
            Log::info('LeadAiService: nombre del lead guardado vía acción estructurada.', [
                'lead_id' => $lead->id,
                'nombre'  => $guardar_nombre,
            ]);
        }

        /* Acción: guardar email del lead si no tiene uno y el valor parece válido. */
        $guardar_email = isset($parsed['guardar_email']) ? trim((string) $parsed['guardar_email']) : '';
        /* Bandera para disparar Mail 1 después del save. */
        $email_nuevo = false;
        if ($guardar_email !== '' && filter_var($guardar_email, FILTER_VALIDATE_EMAIL) && empty($lead->email)) {
            $lead->email = $guardar_email;
            $email_nuevo = true;
            Log::info('LeadAiService: email del lead guardado vía acción estructurada.', [
                'lead_id' => $lead->id,
                'email'   => $guardar_email,
            ]);
        }

        /* Acción: agendar demo si Claude devolvió el objeto con los campos requeridos. */
        $agendar_demo = isset($parsed['agendar_demo']) && is_array($parsed['agendar_demo'])
            ? $parsed['agendar_demo']
            : null;
        if ($agendar_demo !== null) {
            /* Extraer campos del objeto agendar_demo (demo_end_time lo calcula el servidor). */
            $demo_id    = isset($agendar_demo['demo_id'])        ? (int) $agendar_demo['demo_id']                 : null;
            $demo_date  = isset($agendar_demo['demo_date'])       ? trim((string) $agendar_demo['demo_date'])      : '';
            $demo_start = isset($agendar_demo['demo_start_time']) ? trim((string) $agendar_demo['demo_start_time']) : '';

            /* Normalizar hora de inicio a HH:MM para comparar con el JSON de disponibilidad. */
            if ($demo_start !== '' && preg_match('/(\d{1,2}):(\d{2})/', $demo_start, $start_match)) {
                $demo_start = str_pad($start_match[1], 2, '0', STR_PAD_LEFT).':'.$start_match[2];
            }

            if ($demo_id && $demo_date !== '' && $demo_start !== '') {
                /* Validar que el slot exista en la disponibilidad real para esa demo. */
                $availability = $this->build_availability_json();
                $slots_demo   = $availability['demos'][$demo_id][$demo_date] ?? [];

                if (! in_array($demo_start, $slots_demo, true)) {
                    Log::error('LeadAiService: Claude devolvió un agendar_demo con slot no disponible. Se ignora.', [
                        'lead_id'            => $lead->id,
                        'demo_id'            => $demo_id,
                        'demo_date'          => $demo_date,
                        'demo_start'         => $demo_start,
                        'slots_disponibles'  => $slots_demo,
                    ]);

                    /*
                     * Camino "slot inválido detectado por servidor":
                     * Claude alucinó un horario que no figura en el JSON de disponibilidad.
                     * El agendado en BD ya quedó descartado arriba, pero el mensaje sugerido
                     * todavía confirma ese horario falso al lead. Para no enviar una confirmación
                     * mentirosa, se hace una tercera llamada correctiva a Claude (aislada del
                     * historial) para que redacte una disculpa natural con las alternativas reales.
                     */
                    $mensaje_correctivo = $this->call_corrective_availability_response(
                        $lead,
                        $demo_start,
                        $demo_date,
                        $slots_demo
                    );

                    if ($mensaje_correctivo !== '') {
                        /* Sobrescribir el mensaje mentiroso y forzar estado neutro. */
                        $mensaje    = $mensaje_correctivo;
                        $estado_raw = 'solicita_disponibilidad';
                    } else {
                        /* Fallback fijo si la tercera llamada falló: garantiza que nunca se envíe confirmación falsa. */
                        $alternativas = implode(', ', array_slice($slots_demo, 0, 3));
                        $mensaje = "Ese horario ya no está disponible. "
                            . ($alternativas ? "Te puedo ofrecer: {$alternativas}." : "Escribime para coordinar un horario.");
                        $estado_raw = 'solicita_disponibilidad';
                    }

                    /*
                     * Recalcular el estado derivado. Más arriba (antes de este bloque) ya se
                     * computaron $pipeline_status, $estado y $suggested_lead_status a partir del
                     * $estado_raw original, que en este escenario suele ser 'demo_agendada' (Claude
                     * confirmó el slot alucinado). Como acá forzamos el estado neutro, hay que
                     * rehacer ese cálculo para que el lead NO quede en demo_agendada, sino en
                     * solicita_disponibilidad, conforme al camino de "slot inválido detectado por servidor".
                     */
                    $pipeline_status       = LeadPipelineStatus::ensure_exists($estado_raw);
                    $estado                = $pipeline_status->slug;
                    $suggested_lead_status = $estado !== $previous_status ? $estado : null;
                } else {
                    /* Fin de demo: inicio + duración configurada (Claude no debe enviar demo_end_time). */
                    $duracion  = LeadDemoSettings::get_duracion_minutos();
                    $demo_end  = Carbon::createFromFormat('H:i', $demo_start)
                        ->addMinutes($duracion)
                        ->format('H:i');

                    $lead->demo_id         = $demo_id;
                    $lead->demo_date       = $demo_date;
                    $lead->demo_start_time = $demo_start;
                    $lead->demo_end_time   = $demo_end;
                    Log::info('LeadAiService: demo agendada vía acción estructurada y validada.', [
                        'lead_id'    => $lead->id,
                        'demo_id'    => $demo_id,
                        'demo_date'  => $demo_date,
                        'demo_start' => $demo_start,
                        'demo_end'   => $demo_end,
                    ]);
                }
            }
        }

        /* --- Fin de acciones estructuradas --- */

        /* Acción: crear tarea de alerta si Claude detectó que se requiere intervención humana. */
        $requiere_intervencion = ! empty($parsed['requiere_intervencion_humana']);
        $motivo_intervencion   = isset($parsed['motivo_intervencion']) ? trim((string) $parsed['motivo_intervencion']) : '';

        if ($requiere_intervencion) {
            try {
                /* Obtener el admin con is_default_task_assignee = true para notificarlo (si existe). */
                $default_assignee = \App\Models\Admin::where('is_default_task_assignee', true)->first();

                /* Armar título legible: priorizar nombre del lead, luego empresa, luego teléfono. */
                $identificador = '';
                if (! empty($lead->contact_name)) {
                    $identificador = $lead->contact_name;
                } elseif (! empty($lead->company_name)) {
                    $identificador = $lead->company_name;
                } else {
                    $identificador = $lead->phone ?? "Lead #{$lead->id}";
                }

                $task_title   = "Revisar conversación de {$identificador}";
                $task_content = $motivo_intervencion !== ''
                    ? $motivo_intervencion
                    : 'Claude detectó que esta conversación requiere revisión humana.';

                /* Obtener el sort_order más bajo disponible para que aparezca primero. */
                \App\Models\AdminTask::increment('sort_order');

                /* Admin creador: default assignee, primer admin o ID 1 (compatible PHP 7, sin ?->). */
                $created_by_admin_id = 1;
                if ($default_assignee) {
                    $created_by_admin_id = $default_assignee->id;
                } else {
                    $fallback_admin = \App\Models\Admin::first();
                    if ($fallback_admin) {
                        $created_by_admin_id = $fallback_admin->id;
                    }
                }

                \App\Models\AdminTask::create([
                    'created_by_admin_id' => $created_by_admin_id,
                    'assigned_admin_id'   => null,   /* Sin asignar: visible para todos en el badge */
                    'lead_id'             => $lead->id,
                    'title'               => $task_title,
                    'content'             => $task_content,
                    'todos'               => null,
                    'is_done'             => false,
                    'sort_order'          => 0,
                ]);

                Log::info('LeadAiService: tarea de alerta creada por intervención humana requerida.', [
                    'lead_id' => $lead->id,
                    'motivo'  => $task_content,
                ]);
            } catch (\Throwable $e) {
                Log::error('LeadAiService: error al crear tarea de alerta de intervención humana.', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        $msg = LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'sistema',
            'content'               => $mensaje,
            'ai_reasoning'          => $razonamiento,
            'suggested_lead_status' => $suggested_lead_status,
            'status'                => 'sugerido',
            'is_followup'           => $is_followup,
            'requiere_verificacion' => $req_verif,
            'sent_at'               => null,
        ]);

        $lead->tiene_sugerencia_pendiente = true;

        if ($is_followup) {
            $lead->requiere_seguimiento     = true;
            /* Alerta en tabla de leads hasta que el setter abra la pestaña de conversación. */
            $lead->tiene_seguimiento_sin_ver = true;
        }

        /* Único save del lead: consolida nombre, email, demo y flags de sugerencia. */
        $lead->save();

        /* Disparar Mail 1 si se guardó un email nuevo en esta pasada. */
        if ($email_nuevo) {
            try {
                $lead->loadMissing('demo');
                $mailable = \App\Mail\Helpers\LeadDemoMailHelper::build($lead);
                \Illuminate\Support\Facades\Mail::to($lead->email)->send($mailable);
                $lead->update(['demo_mail_sent_at' => now()]);
                Log::info('LeadAiService: Mail 1 enviado automáticamente al guardar email del lead.', [
                    'lead_id' => $lead->id,
                    'email'   => $lead->email,
                ]);
            } catch (\Throwable $e) {
                Log::error('LeadAiService: error al enviar Mail 1 automático.', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        /* Programar auto-envío antes del broadcast: el payload Pusher debe incluir ai_auto_send_at. */
        (new LeadAiSuggestionAutoSendScheduler())->schedule_for_suggested_message($msg);
        $msg = $msg->fresh();

        // Notificar a admin-spa vía socket para actualizar la fila del lead en tiempo real.
        LeadSuggestionCreated::dispatch($lead->id);
        LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $msg->id);

        return $msg;
    }

    /**
     * Tercera llamada correctiva a Claude cuando el servidor descartó un agendar_demo
     * por slot inválido (camino "slot inválido detectado por servidor").
     *
     * Claude alucinó un horario que no figura en el JSON de disponibilidad y, aun así,
     * el mensaje sugerido ya confirma ese horario al lead. Para no enviar esa confirmación
     * falsa, se hace una llamada AISLADA del historial de la conversación (el historial es
     * justamente lo que confunde al modelo) con un prompt mínimo y restringido: solo puede
     * redactar una disculpa con las alternativas reales, nunca devolver agendar_demo ni JSON.
     *
     * Si la respuesta parece estructurada (empieza con ``` o {) se trata como fallo y se
     * devuelve string vacío para que el caller active su fallback fijo de PHP.
     *
     * @param Lead     $lead                Lead al que se le responde.
     * @param string   $slot_invalido       Horario alucinado que el servidor descartó (HH:MM).
     * @param string   $demo_date           Fecha de la demo propuesta (Y-m-d).
     * @param string[] $slots_disponibles   Slots realmente disponibles para esa demo y fecha.
     *
     * @return string Mensaje natural al lead, o string vacío si falló (activa fallback).
     */
    private function call_corrective_availability_response(Lead $lead, string $slot_invalido, string $demo_date, array $slots_disponibles): string
    {
        try {
            /* Lista legible de alternativas para inyectar en el prompt (ej: "18:00, 19:00, 20:00"). */
            $alternativas_legibles = implode(', ', $slots_disponibles);

            /*
             * Prompt de usuario mínimo y restringido. NO se usa build_user_content() a propósito:
             * ese arma el prompt completo con historial, que es lo que hace alucinar al modelo.
             * Esta llamada debe estar completamente aislada de la conversación.
             */
            $user_content = "El lead propuso agendar a las {$slot_invalido} para el {$demo_date}, pero ese horario ya no está disponible.\n";
            $user_content .= $alternativas_legibles !== ''
                ? "Los próximos horarios disponibles son: {$alternativas_legibles}.\n"
                : "Por ahora no hay horarios disponibles para esa fecha.\n";
            $user_content .= "Redactá un mensaje natural y breve para el lead disculpándote y ofreciéndole esas alternativas.\n";
            $user_content .= "No uses `agendar_demo`. Solo devolvé el texto del mensaje, sin JSON, sin estructura, solo el mensaje al lead.";

            /* Mismo system prompt que el flujo normal; max_tokens acotado a un mensaje corto. */
            $system = $this->build_system_prompt();
            $model  = (string) config('services.anthropic.model', 'claude-sonnet-4-20250514');
            $http   = $this->build_http_client();

            $response = $http->post('https://api.anthropic.com/v1/messages', [
                'model'      => $model,
                'max_tokens' => 400,
                'system'     => $system,
                'messages'   => [
                    ['role' => 'user', 'content' => $user_content],
                ],
            ]);

            if ($response->failed()) {
                Log::error('LeadAiService: fallo HTTP en tercera llamada correctiva (slot inválido).', [
                    'lead_id' => $lead->id,
                    'status'  => $response->status(),
                    'body'    => $response->body(),
                ]);
                return '';
            }

            /* Texto limpio de la respuesta. */
            $texto = trim($this->extract_response_text($response->json()));

            /*
             * Si la respuesta empieza con bloque de código o JSON, el modelo ignoró la restricción
             * (intentó devolver estructura). Se trata como fallo para caer al fallback fijo.
             */
            if ($texto === '' || str_starts_with($texto, '```') || str_starts_with($texto, '{')) {
                Log::error('LeadAiService: tercera llamada correctiva devolvió contenido estructurado o vacío. Se ignora.', [
                    'lead_id'  => $lead->id,
                    'response' => $texto,
                ]);
                return '';
            }

            return $texto;
        } catch (\Throwable $e) {
            Log::error('LeadAiService: excepción en tercera llamada correctiva (slot inválido).', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Arma el system prompt: esqueleto en BD + protocolo completo desde GitHub.
     *
     * Las protocol_entries y placeholders legacy ya no participan del armado.
     *
     * @return string
     */
    protected function build_system_prompt(): string
    {
        $prompt_activo = AiSystemPrompt::obtener_activo();

        if (! $prompt_activo) {
            throw new \RuntimeException(
                'No hay system prompt activo en la BD. '.
                'Correr AiSystemPromptSeeder o UpdateAiSystemPromptSeeder.'
            );
        }

        /** Texto base editable desde admin (contexto + formato JSON de respuesta). */
        $contenido = trim((string) $prompt_activo->contenido);

        /* Inyectar identidad del agente si existe registro activo. */
        $agent_identity = \App\Models\AgentIdentity::obtener_activo();
        if ($agent_identity) {
            $contenido = "IDENTIDAD DEL AGENTE:\n" . trim($agent_identity->description) . "\n\n" . $contenido;
        }

        /** Documento maestro en GitHub; si falla la lectura, solo se usa el esqueleto de BD. */
        $whatsapp_protocol = app(WhatsappProtocolService::class)->getProtocol();
        if ($whatsapp_protocol !== '') {
            $contenido .= "\n\nPROTOCOLO DE WHATSAPP\n";
            $contenido .= $whatsapp_protocol;
        }

        return $contenido;
    }

    /**
     * Construye el contenido user con historial y datos del lead.
     *
     * Si se proporciona $availability_context, se agrega al final del contenido
     * para que Claude pueda sugerir horarios concretos de demo al setter.
     *
     * @param Lead   $lead                 Lead con mensajes cargados.
     * @param bool   $is_followup          true si el trigger fue inactividad del lead.
     * @param string $availability_context Contexto de slots disponibles; vacío si no aplica.
     *
     * @return string Contenido listo para enviar como mensaje user a la API.
     */
    protected function build_user_content(Lead $lead, bool $is_followup, string $availability_context = ''): string
    {
        $historial = '';
        foreach ($lead->messages as $msg) {
            /* Saltar mensajes que el operador marcó como eliminados del contexto de IA. */
            if ($msg->deleted_from_context) {
                continue;
            }

            /* $sender se asigna primero para poder usarlo en los filtros siguientes. */
            $sender = (string) $msg->sender;

            /* Reacciones de WhatsApp no son mensajes de texto del lead (legacy o mal parseadas). */
            if ((string) ($msg->kind ?? '') === 'reaction') {
                continue;
            }
            if ($sender === 'lead' && LeadWhatsappReactionService::is_legacy_reaction_content((string) $msg->content)) {
                continue;
            }
            $status = (string) $msg->status;
            $label = strtoupper($sender);
            // Audio: content es la transcripción Kapso; el prefijo orienta a Claude como en soporte.
            if ($sender === 'lead' && (string) ($msg->kind ?? 'text') === 'audio') {
                $label = 'LEAD (audio transcripto)';
            }
            $fecha = $msg->created_at ? $msg->created_at->format('d/m/Y H:i') : '';

            /* Sugerencia de Claude que el setter no envió (canceló envío automático o rechazó). */
            if ($sender === 'sistema' && $status === 'rechazado') {
                $body = LeadWhatsAppPasteCleaner::clean_export_paste((string) $msg->content);
                $historial .= "[{$fecha}] SISTEMA (sugerencia no enviada al lead): {$body}\n";

                continue;
            }

            /* Si el setter aprobó con ajustes, usar el texto enviado y marcar el historial para Claude. */
            $edited = trim((string) ($msg->edited_content ?? ''));
            if ($edited !== '') {
                $label .= ' (enviado con ajuste)';
                $body = LeadWhatsAppPasteCleaner::clean_export_paste($edited);
            } else {
                $body = LeadWhatsAppPasteCleaner::clean_export_paste((string) $msg->content);
            }
            $historial .= "[{$fecha}] {$label}: {$body}\n";
        }

        $extra = $is_followup
            ? "\nATENCIÓN: seguimiento automático por inactividad del lead. Generá un mensaje de seguimiento apropiado.\n"
            : '';

        $demo = $lead->demo_date ? $lead->demo_date->format('Y-m-d') : '';

        $txt = <<<TXT
Conversación del lead:
{$historial}

Estado actual: {$lead->status}
Última actualización lead: {$lead->updated_at}
Contacto: {$lead->contact_name} | Empresa: {$lead->company_name}
Teléfono: {$lead->phone} | Email: {$lead->email}
Rubro/tipo negocio: {$lead->business_type}
Notas internas: {$lead->notes}
Demo fecha: {$demo}
{$extra}
TXT;

        /* Inyectar disponibilidad de demos si se provee (segunda llamada con slots). */
        if ($availability_context !== '') {
            $txt .= "\n\nDISPONIBILIDAD DE DEMOS:\n{$availability_context}";
        }

        $txt .= "\n¿Qué respuesta sugerís y en qué estado debería quedar el lead?";

        return $txt;
    }

    /**
     * Extrae y decodifica el JSON de la respuesta de Claude.
     *
     * @param string $raw Texto crudo devuelto por la API (puede tener texto extra fuera del JSON).
     *
     * @throws \RuntimeException Si no se encuentra un JSON válido en la respuesta.
     *
     * @return array<string, mixed>
     */
    protected function parse_json_response(string $raw): array
    {
        $start = strpos($raw, '{');
        $end   = strrpos($raw, '}');

        if ($start === false || $end === false || $end <= $start) {
            throw new \RuntimeException('Claude no devolvió JSON válido: '.$raw);
        }

        $json = substr($raw, $start, $end - $start + 1);
        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw new \RuntimeException('JSON inválido: '.json_last_error_msg());
        }

        return $data;
    }
}


