<?php

namespace App\Services;

use App\Events\LeadSuggestionCreated;
use App\Services\CloserGoogleCalendarBusyService;
use App\Services\CloserGoogleCalendarEventService;
use App\Services\GoogleCalendarOAuthService;
use App\Services\LeadBroadcastService;
use App\Services\LeadDemoSettings;
use App\Models\AiSystemPrompt;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadPartner;
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
     * Restricción explícita para la primera llamada: el agente no puede inventar rangos horarios
     * sin haber recibido el JSON de disponibilidad en una segunda llamada previa.
     * Complementa el protocolo de WhatsApp y evita alucinaciones tipo "tengo de 18 a 20 hs".
     */
    private const PROHIBICION_RANGO_HORARIO_SIN_JSON = <<<'TXT'
⚠️ PROHIBIDO — Nunca anunciar un rango de horario propio sin JSON de disponibilidad:
Cuando el lead pregunta por disponibilidad en términos generales ("la semana que viene por la tarde", "¿podés mañana?", "¿tenés algo el finde?") sin mencionar un día puntual, la única acción válida es devolver solicita_disponibilidad: true con fecha_solicitada en el primer día hábil del rango pedido. NO responder con frases como "tengo disponibilidad de X a Y hs" ni ninguna variante que afirme conocer el horario disponible. Esa información solo puede venir del JSON que el sistema devuelve en la segunda llamada. Si el agente no tiene ese JSON en el contexto actual, no tiene información de disponibilidad.
TXT;

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

        /* true cuando Claude pide reagendar: la segunda llamada debe traer los nuevos slots disponibles. */
        $cancelar_demo_flag = ! empty($parsed['cancelar_demo']);

        /* true cuando cualquiera de las tres condiciones aplica */
        $needs_availability_check = $solicita_disponibilidad || $estado_sugerido === 'demo_agendada' || $cancelar_demo_flag;

        /*
         * Si Claude pidió disponibilidad, puede haber devuelto también una fecha específica
         * (fecha_solicitada en formato Y-m-d) para que el sistema consulte ese día puntual
         * cuando está fuera del rango de los 3 días hábiles por defecto.
         */
        $fecha_solicitada = isset($parsed['fecha_solicitada']) ? trim((string) $parsed['fecha_solicitada']) : '';

        if ($needs_availability_check) {
            try {
                /* Pasar la fecha solicitada (o null si no viene) para ampliar el rango del JSON. */
                return $this->generate_suggestion_with_availability(
                    $lead,
                    $is_followup,
                    $fecha_solicitada !== '' ? $fecha_solicitada : null
                );
            } catch (\Throwable $e) {
                Log::error('Error en segunda llamada a Claude (disponibilidad)', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);

                /* Fallback: conservar el mensaje de primera llamada si existe (o vacío si no hay).
                 * La nota interna va a razonamiento, NO a mensaje_sugerido, para que el auto-send
                 * no la despache al lead. Se fuerza requiere_verificacion: true para que el setter
                 * deba aprobar manualmente antes de enviar. */
                $fallback_base = trim((string) ($parsed['mensaje_sugerido'] ?? ''));
                $parsed['mensaje_sugerido'] = $fallback_base;
                $parsed['razonamiento'] = 'No se pudo obtener disponibilidad del calendario. El setter debe confirmar el horario manualmente antes de enviar este mensaje.';
                $parsed['requiere_verificacion'] = true;
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
     * Cuando $specific_date tiene valor (Claude devolvió fecha_solicitada en la primera
     * llamada), se amplía el JSON de disponibilidad para cubrir el rango hasta esa fecha.
     *
     * @param Lead        $lead          Lead con relación `messages` cargada.
     * @param bool        $is_followup   true si lo disparó el scheduler de inactividad.
     * @param string|null $specific_date Fecha objetivo en formato Y-m-d, o null para los 3 días por defecto.
     *
     * @throws \RuntimeException Si falla la llamada HTTP o el JSON es inválido.
     *
     * @return LeadMessage Mensaje creado con los horarios sugeridos por Claude.
     */
    protected function generate_suggestion_with_availability(Lead $lead, bool $is_followup, ?string $specific_date = null): LeadMessage
    {
        /* JSON estructurado por demo para que Claude interprete disponibilidad sin regex.
         * Se pasa $specific_date para ampliar el rango cuando el lead pidió una fecha lejana.
         * El snapshot de Google Calendar se captura en la misma consulta de disponibilidad. */
        $calendar_snapshot    = null;
        $availability_data    = $this->build_availability_json(3, $calendar_snapshot, $specific_date);

        /*
         * Ampliar snapshot con demos agendadas, slots enviados a Claude y config del closer
         * para debug completo de disponibilidad (prompt 123).
         */
        $demos_agendadas = Lead::query()
            ->whereNotNull('demo_date')
            ->whereNotNull('demo_start_time')
            ->whereNotNull('demo_id')
            ->where('demo_date', '>=', now('America/Argentina/Buenos_Aires')->toDateString())
            ->get(['id', 'contact_name', 'demo_id', 'demo_date', 'demo_start_time', 'demo_end_time'])
            ->map(fn ($lead_row) => [
                'lead_id'         => $lead_row->id,
                'contact_name'    => $lead_row->contact_name ?? '(sin nombre)',
                'demo_id'         => $lead_row->demo_id,
                'demo_date'       => ($lead_row->demo_date ? $lead_row->demo_date->format('Y-m-d') : null),
                'demo_start_time' => $lead_row->demo_start_time,
                'demo_end_time'   => $lead_row->demo_end_time,
            ])
            ->values()
            ->all();

        /* Config del closer activa al momento de la consulta de disponibilidad. */
        $closer_config = [
            'horario_lv'                       => LeadDemoSettings::get_closer_horario_lunes_viernes(),
            'horario_sab'                      => LeadDemoSettings::get_closer_horario_sabado(),
            'horario_dom'                      => LeadDemoSettings::get_closer_horario_domingo(),
            'duracion_demo_min'                => LeadDemoSettings::get_duracion_minutos(),
            'setup_minutos_antes'              => LeadDemoSettings::get_setup_minutos_antes(),
            'gracia_post_min'                  => LeadDemoSettings::get_gracia_minutos_post(),
            'duracion_llamada_closer_min'      => LeadDemoSettings::get_duracion_llamada_closer_minutos(),
            'frecuencia_slots_min'             => LeadDemoSettings::get_frecuencia_slots_minutos(),
            'llamada_debe_terminar_en_horario' => LeadDemoSettings::get_llamada_debe_terminar_en_horario(),
        ];

        /* Inyectar datos adicionales en el snapshot (Google Calendar ya viene de build_availability_json). */
        if ($calendar_snapshot === null) {
            $calendar_snapshot = [];
        }
        $calendar_snapshot['demos_agendadas']  = $demos_agendadas;
        $calendar_snapshot['slots_disponibles'] = $availability_data['demos'] ?? [];
        $calendar_snapshot['closer_config']    = $closer_config;

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

            /*
             * Detectar horario propuesto por el lead. Se usa solo como pista para Claude;
             * el modelo razona sobre el texto completo. La regex es intencionalmente estricta
             * para no capturar falsos positivos como "5 de julio" o "dentro de 5 días":
             * solo captura patrones con indicador horario explícito (HH:MM, 14hs, 9h, 5pm, 8am).
             */
            if (preg_match('/\b(\d{1,2})(?::(\d{2}))\s*(?:hs?|h)?\b/i', $last_content, $m)
                || preg_match('/\b(\d{1,2})\s*(?:hs|h|am|pm|a\.?m\.?|p\.?m\.?)\b/i', $last_content, $m)) {
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

        /*
         * Instrucción crítica para la segunda llamada: Claude ya tiene los slots en el JSON.
         * Se reemplaza la prohibición absoluta de solicita_disponibilidad por una regla
         * matizada: solo puede devolverla si el lead pidió una fecha que NO está en el JSON
         * (demasiado lejana), junto con fecha_solicitada para que el sistema la consulte.
         * Para cualquier fecha que SÍ aparece en el JSON (con o sin slots), debe responder
         * directamente sin volver a pedir disponibilidad.
         */
        $availability_context .= "\n\n⚠️ ATENCIÓN - SEGUNDA LLAMADA: El sistema YA te trajo los horarios disponibles en el JSON de arriba.";
        $availability_context .= "\n- Si la fecha que pidió el lead SÍ aparece en el JSON (con o sin slots): usá esa info. Si tiene slots, ofrecelos. Si aparece SIN slots, significa que no hay disponibilidad ese día: informá al lead y ofrecé alternativas cercanas del JSON. NO vuelvas a pedir disponibilidad para una fecha que ya está en el JSON.";
        $availability_context .= "\n- Si la fecha que pidió el lead NO aparece en el JSON (pidió un día más lejano que los que trae el JSON): devolvé solicita_disponibilidad: true junto con fecha_solicitada en formato Y-m-d, para que el sistema consulte ese día puntual. Usá la FECHA Y HORA ACTUAL del contexto para calcular la fecha exacta que pide el lead (ej: 'dentro de 5 días', 'el viernes que viene').";
        $availability_context .= "\n- Si el lead pidió un día sin especificar hora (ej: 'el sábado') y ese día está en el JSON con slots: ofrecele directamente los horarios disponibles de ese día.";
        $availability_context .= "\n- Si el lead pidió un horario concreto disponible: confirmalo aclarando si es mañana o tarde, y pedile el email (Paso 3 del protocolo).";

        /*
         * Regla de inferencia AM/PM: las demos son en horario diurno/laboral. Claude debe
         * usar sentido común para interpretar horas ambiguas y siempre aclarar el turno
         * al confirmar para que el lead pueda corregir si eligió el otro.
         */
        $availability_context .= "\n\nINTERPRETACIÓN DE HORARIOS (AM/PM):";
        $availability_context .= "\n- Las demos son siempre en horario diurno/laboral. Si el lead dice una hora ambigua ('a las 5', 'a las 9'), inferí con sentido común: nadie agenda una demo de madrugada.";
        $availability_context .= "\n- 'A las 5', 'a las 6', 'a las 7' sin aclaración → casi siempre es PM (17, 18, 19hs). 'A las 9', 'a las 10', 'a las 11' → casi siempre AM (mañana).";
        $availability_context .= "\n- Si el lead aclara explícitamente ('a las 5 de la tarde', 'a las 9 de la mañana'), respetá eso.";
        $availability_context .= "\n- SIEMPRE que confirmes un horario, aclarás si es de la mañana o de la tarde (ej: 'el sábado a las 10 de la mañana'), para que el lead pueda corregirte si quería el otro turno.";
        $availability_context .= "\n- Si una hora ambigua podría caer fuera del rango en una interpretación pero dentro en la otra (ej: 'a las 8' → 8am está en rango, 20hs no), elegí la interpretación que caiga dentro del horario disponible y confirmala aclarando el turno, para que el lead corrija si hace falta.";

        /* Pasar el estado para inyectar la sección FAQ solo cuando corresponde */
        $system       = $this->build_system_prompt();
        $user_content = $this->build_user_content($lead, $is_followup, $availability_context);
        $model        = (string) config('services.anthropic.model', 'claude-sonnet-4-20250514');
        $http         = $this->build_http_client();

        /* Segunda llamada a Claude con disponibilidad como contexto adicional. */
        $response = $http->post('https://api.anthropic.com/v1/messages', [
            'model'      => $model,
            'max_tokens' => 3000,
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

        return $this->create_message_and_update_lead($lead, $parsed, $is_followup, $calendar_snapshot);
    }

    /**
     * Construye el JSON de disponibilidad por demo para que Claude interprete slots sin regex.
     *
     * Incluye la fecha/hora actual en Argentina, la duración configurada de cada demo
     * y un mapa demo_id → fecha (Y-m-d) → horarios libres (HH:MM).
     *
     * Cuando $specific_date tiene valor, delega a prepare_slot_availability_context() el
     * cálculo del rango desde mañana hasta esa fecha, para que Claude tenga contexto completo
     * de días intermedios al buscar una fecha lejana.
     *
     * @param int         $days_ahead        Cantidad mínima de días hábiles a incluir (default: 3; ignorado si $specific_date es válida).
     * @param array|null  $calendar_snapshot Referencia opcional para recibir el snapshot de Google Calendar.
     * @param string|null $specific_date     Fecha objetivo en formato Y-m-d, o null para comportamiento por defecto.
     *
     * @return array<string, mixed> Estructura: hoy, duration_demo_minutos, demos.
     */
    public function build_availability_json(int $days_ahead = 3, &$calendar_snapshot = null, ?string $specific_date = null): array
    {
        /* Contexto compartido: días hábiles, rangos bloqueados y parámetros de demo.
         * Se pasa $specific_date para que, si el lead pidió una fecha lejana, se amplíe el rango. */
        $context = $this->prepare_slot_availability_context($days_ahead, $specific_date);

        /* Exponer snapshot de calendario al llamador (segunda llamada con disponibilidad). */
        $calendar_snapshot = $context['google_calendar_snapshot'] ?? null;

        /*
         * Garantizar snapshot mínimo de diagnóstico cuando se consultó disponibilidad
         * pero no hubo datos de Google Calendar (p. ej. sin closers conectados).
         */
        if (empty($calendar_snapshot)) {
            $calendar_snapshot = [
                'consultado_en' => now()->toIso8601String(),
                'closers'       => [],
                'nota'          => 'sin_datos',
            ];
        }

        /* Etiqueta legible de hoy en timezone Argentina. */
        $day_names_full = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $hoy_label      = ucfirst($day_names_full[$context['now']->dayOfWeek])
            .' '.$context['now']->format('d/m/Y').', '.$context['now']->format('H:i').'hs (hora Argentina)';

        /* Slots disponibles por demo y por fecha.
         * Cada llamada aplica las dos capas de bloqueo: por demo y por closer.
         * La clave incluye el nombre del día de semana para que Claude pueda asociar
         * "el domingo", "el sábado", etc. con la fecha correcta sin ambigüedad. */
        $day_names_key = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $demos_json = [];
        foreach ($context['demos'] as $demo) {
            $demo_id = (int) $demo->id;
            $demos_json[$demo_id] = [];

            foreach ($context['dates_map'] as $date_key => $day) {
                /* Clave legible: "domingo 2026-06-28", "lunes 2026-06-29", etc. */
                $dia_nombre  = $day_names_key[$day->dayOfWeek];
                $date_label  = $dia_nombre . ' ' . $date_key;

                /* Rangos bloqueados por este entorno técnico específico. */
                $blocked_ranges = $context['blocked_by_demo'][$demo_id][$date_key] ?? [];
                /* Rangos de closer ocupado para esta fecha (transversal a todas las demos). */
                $closer_busy_for_date = $context['closer_busy'][$date_key] ?? [];
                $demos_json[$demo_id][$date_label] = $this->compute_day_slots_for_demo(
                    $day,
                    $blocked_ranges,
                    $context['now'],
                    $context['today_key'],
                    $context['now_minutes'],
                    $context['duracion'],
                    $closer_busy_for_date,
                    $context['gracia_post'],
                    $context['slot_config'] ?? []
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
     * Cuando se provee $specific_date, en lugar de calcular los próximos $days_ahead días
     * hábiles, se calcula el rango desde mañana hasta esa fecha inclusive (solo días con
     * horario configurado). Esto permite que Claude tenga contexto de todo el rango intermedio
     * para una fecha lejana solicitada por el lead.
     *
     * @param int         $days_ahead    Cantidad mínima de días hábiles a incluir (ignorado si $specific_date es válida).
     * @param string|null $specific_date Fecha objetivo en formato Y-m-d, o null para comportamiento por defecto.
     *
     * @return array<string, mixed>
     */
    protected function prepare_slot_availability_context(int $days_ahead = 3, ?string $specific_date = null): array
    {
        /* Parámetros de configuración de demos. */
        $duracion    = LeadDemoSettings::get_duracion_minutos();
        $setup_antes = LeadDemoSettings::get_setup_minutos_antes();
        $gracia_post = LeadDemoSettings::get_gracia_minutos_post();

        /* Parámetros para generación dinámica de slots (incorporados en prompt 075/076). */
        /* Horarios laborales del closer por día de semana, en formato H:i-H:i. */
        $horario_lv        = LeadDemoSettings::get_closer_horario_lunes_viernes();
        $horario_sab       = LeadDemoSettings::get_closer_horario_sabado();
        $horario_dom       = LeadDemoSettings::get_closer_horario_domingo();
        /* Frecuencia en minutos entre slots candidatos (ej. 30 = :00 y :30). */
        $frecuencia_slots  = LeadDemoSettings::get_frecuencia_slots_minutos();
        /* Checkbox: si true la llamada del closer también debe terminar dentro del horario. */
        $llamada_termina   = LeadDemoSettings::get_llamada_debe_terminar_en_horario();
        /* Duración de la llamada del closer post-demo en minutos. */
        $duracion_closer   = LeadDemoSettings::get_duracion_llamada_closer_minutos();

        /*
         * Config agrupada para pasarla a compute_day_slots_for_demo() y get_all_slots_for_day()
         * sin tener que extender la firma con 6+ parámetros individuales.
         */
        $slot_config = [
            'horario_lv'                       => $horario_lv,
            'horario_sab'                      => $horario_sab,
            'horario_dom'                      => $horario_dom,
            'frecuencia_slots'                 => $frecuencia_slots,
            'duracion'                         => $duracion,
            'gracia_post'                      => $gracia_post,
            'duracion_llamada_closer'          => $duracion_closer,
            'llamada_debe_terminar_en_horario' => $llamada_termina,
        ];

        /* Log de diagnóstico: config activa para esta ejecución, facilita comparar con slots resultantes. */
        Log::channel('disponibilidad')->info(
            '[DISPONIBILIDAD] Config activa: '
            . "duracion={$duracion}min, setup_antes={$setup_antes}min, gracia_post={$gracia_post}min, "
            . "duracion_closer={$duracion_closer}min, frecuencia_slots={$frecuencia_slots}min, "
            . 'llamada_termina=' . ($llamada_termina ? 'si' : 'no') . ', '
            . "horario_lv={$horario_lv}, horario_sab={$horario_sab}, "
            . 'horario_dom=' . ($horario_dom !== '' ? $horario_dom : 'sin trabajo')
        );

        /* Demos activas; sin ellas se delega al algoritmo legacy en get_available_slots(). */
        $demos = \App\Models\Demo::orderBy('id')->get();

        /* Instante actual en Argentina. */
        $now         = now('America/Argentina/Buenos_Aires');
        $now_minutes = $now->hour * 60 + $now->minute;
        $today_key   = $now->copy()->startOfDay()->format('Y-m-d');
        /* El cursor arranca en mañana: nunca se ofrece el día actual como opción de demo.
         * El closer necesita al menos un día de anticipación para prepararse. */
        $cursor      = $now->copy()->startOfDay()->addDay();

        /* Lista inicial de días hábiles: solo fechas con horario configurado para ese día de semana. */
        $working_days = [];

        /*
         * Cuando se pide una fecha específica lejana, calcular el rango desde mañana hasta
         * esa fecha inclusive (solo días con horario configurado). Esto le da a Claude el
         * contexto de todo el rango intermedio para poder ofrecer alternativas si el día
         * exacto no tiene slots. Si la fecha es inválida o pasada, se cae al comportamiento
         * por defecto de $days_ahead.
         */
        $use_specific_date = false;
        if ($specific_date !== null) {
            /* Validar formato Y-m-d y que la fecha sea futura (>= mañana). */
            $target_date = null;
            try {
                $target_date = \Carbon\Carbon::createFromFormat('Y-m-d', $specific_date, 'America/Argentina/Buenos_Aires')
                    ->startOfDay();
            } catch (\Throwable $e) {
                $target_date = null;
            }

            /* Fecha mínima aceptable: mañana. */
            $tomorrow = $now->copy()->startOfDay()->addDay();

            if ($target_date !== null && $target_date->gte($tomorrow)) {
                /* Recorrer desde mañana hasta la fecha objetivo inclusive, incluyendo solo
                 * días con horario configurado. */
                $cursor_specific = $tomorrow->copy();
                while ($cursor_specific->lte($target_date)) {
                    $dow_s       = $cursor_specific->dayOfWeek;
                    $horario_dia = ($dow_s === 0) ? $horario_dom : (($dow_s === 6) ? $horario_sab : $horario_lv);
                    if ($horario_dia !== '') {
                        $working_days[] = $cursor_specific->copy();
                    }
                    $cursor_specific->addDay();
                }

                /* Si el rango produjo al menos un día hábil, usarlo; de lo contrario caer al default. */
                if (! empty($working_days)) {
                    $use_specific_date = true;
                    /* Adelantar el cursor principal al día siguiente de la fecha objetivo
                     * para que la lógica de "día extra" arranque desde ahí si es necesaria. */
                    $cursor = $target_date->copy()->addDay();
                }
            }
        }

        /* Comportamiento por defecto: próximos $days_ahead días hábiles desde mañana. */
        if (! $use_specific_date) {
            while (count($working_days) < $days_ahead) {
                /* 0=domingo, 6=sábado, 1-5=lunes a viernes (convención Carbon). */
                $dow = $cursor->dayOfWeek;
                /* Horario laboral del closer según el día de la semana evaluado. */
                $horario_dia = '';
                if ($dow === 0) {
                    $horario_dia = $horario_dom;
                } elseif ($dow === 6) {
                    $horario_dia = $horario_sab;
                } else {
                    $horario_dia = $horario_lv;
                }

                /* Incluir el día solo si tiene rango horario configurado (no vacío). */
                if ($horario_dia !== '') {
                    $working_days[] = $cursor->copy();
                }
                $cursor->addDay();
            }
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

        /* Snapshot legible de eventos Google del closer (solo para debug en admin-spa). */
        $google_calendar_snapshot = null;

        /* Tercera capa de bloqueo: eventos del calendario Google del closer.
         * Si la API de Google falla, se degrada de forma segura (continúa sin esta capa)
         * para no romper el flujo de WhatsApp por un error externo. */
        try {
            $google_busy_service = new CloserGoogleCalendarBusyService(
                new \App\Services\GoogleCalendarOAuthService()
            );
            $google_busy_result = $google_busy_service->get_busy_ranges_for_dates($date_strings);
            $google_busy        = $google_busy_result['ranges'] ?? [];
            $google_calendar_snapshot = $google_busy_result['snapshot'] ?? null;

            /* Log explícito cuando ningún closer tiene calendario conectado o aplicable. */
            $this->log_google_calendar_connection_diagnosis($google_calendar_snapshot);

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
                    $gracia_post,
                    $slot_config
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
            /* Avanzar el cursor hasta el próximo día con horario configurado (p. ej. domingo si aplica). */
            $horario_extra = '';
            while ($horario_extra === '') {
                $dow_extra = $cursor->dayOfWeek;
                if ($dow_extra === 0) {
                    $horario_extra = $horario_dom;
                } elseif ($dow_extra === 6) {
                    $horario_extra = $horario_sab;
                } else {
                    $horario_extra = $horario_lv;
                }
                if ($horario_extra === '') {
                    $cursor->addDay();
                }
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
                $google_busy_extra_result = $google_busy_service_extra->get_busy_ranges_for_dates([$extra_key]);
                $google_busy_extra        = $google_busy_extra_result['ranges'] ?? [];

                if (! empty($google_busy_extra[$extra_key])) {
                    $closer_busy[$extra_key] = array_merge(
                        $closer_busy[$extra_key],
                        $google_busy_extra[$extra_key]
                    );
                }

                /* Acumular eventos del día extra en el snapshot principal. */
                if (! empty($google_busy_extra_result['snapshot'])) {
                    $google_calendar_snapshot = $this->merge_google_calendar_snapshots(
                        $google_calendar_snapshot,
                        $google_busy_extra_result['snapshot']
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
            'duracion'                 => $duracion,
            'gracia_post'              => $gracia_post,
            'now'                      => $now,
            'now_minutes'              => $now_minutes,
            'today_key'                => $today_key,
            'demos'                    => $demos,
            'dates_map'                => $dates_map,
            'blocked_by_demo'          => $blocked_by_demo,
            'closer_busy'              => $closer_busy,
            /* Config de generación de slots para pasar a compute_day_slots_for_demo(). */
            'slot_config'              => $slot_config,
            /* Snapshot de eventos Google consultados al calcular disponibilidad. */
            'google_calendar_snapshot' => $google_calendar_snapshot,
        ];
    }

    /**
     * Registra en el canal disponibilidad si la capa de Google Calendar no aporta bloqueos.
     *
     * Facilita el diagnóstico en producción cuando no hay closers marcados,
     * ninguno tiene calendario conectado o todos fallaron al consultar la API.
     *
     * @param array<string, mixed>|null $snapshot Snapshot devuelto por CloserGoogleCalendarBusyService.
     * @return void
     */
    protected function log_google_calendar_connection_diagnosis(?array $snapshot): void
    {
        if (empty($snapshot)) {
            Log::channel('disponibilidad')->warning(
                '[DISPONIBILIDAD] Google Calendar: snapshot nulo tras consultar disponibilidad.'
                . ' La tercera capa de bloqueo no aportó datos de diagnóstico.'
            );

            return;
        }

        $closers = $snapshot['closers'] ?? [];

        if (empty($closers)) {
            Log::channel('disponibilidad')->warning(
                '[DISPONIBILIDAD] Google Calendar: ningún admin marcado como closer (is_closer=true).'
                . ' La tercera capa no bloquea slots por eventos del calendario.'
            );

            return;
        }

        $closers_con_calendario = 0;

        foreach ($closers as $closer_entry) {
            $estado = $closer_entry['estado'] ?? '';
            $nombre = $closer_entry['nombre'] ?? ('admin #' . ($closer_entry['admin_id'] ?? '?'));

            if ($estado === 'consultado' || $estado === 'cacheado') {
                $closers_con_calendario++;
                continue;
            }

            if ($estado === 'sin_calendario') {
                Log::channel('disponibilidad')->warning(
                    '[DISPONIBILIDAD] Google Calendar: closer "' . $nombre . '" (admin #'
                    . ($closer_entry['admin_id'] ?? '?') . ') sin calendario conectado o conexión inactiva.'
                    . ' Esta capa no aplica bloqueos para ese closer.'
                );
                continue;
            }

            Log::channel('disponibilidad')->warning(
                '[DISPONIBILIDAD] Google Calendar: closer "' . $nombre . '" (admin #'
                . ($closer_entry['admin_id'] ?? '?') . ') excluido de la capa por estado "' . $estado . '".'
            );
        }

        if ($closers_con_calendario === 0) {
            Log::channel('disponibilidad')->warning(
                '[DISPONIBILIDAD] Google Calendar: ningún closer con calendario consultable.'
                . ' Los slots no se filtran por eventos externos del calendario.'
            );
        }
    }

    /**
     * Fusiona dos snapshots de calendario Google acumulando eventos por closer.
     *
     * Se usa cuando la consulta principal y la de día extra consultan fechas distintas.
     *
     * @param array<string, mixed>|null $base_snapshot   Snapshot de la consulta principal.
     * @param array<string, mixed>|null $extra_snapshot  Snapshot de la consulta del día extra.
     * @return array<string, mixed>|null Snapshot combinado o el único disponible.
     */
    protected function merge_google_calendar_snapshots(?array $base_snapshot, ?array $extra_snapshot): ?array
    {
        if (empty($base_snapshot)) {
            return $extra_snapshot;
        }
        if (empty($extra_snapshot)) {
            return $base_snapshot;
        }

        $merged_closers = [];
        foreach ($base_snapshot['closers'] ?? [] as $closer_entry) {
            $merged_closers[(int) $closer_entry['admin_id']] = $closer_entry;
        }

        foreach ($extra_snapshot['closers'] ?? [] as $closer_entry) {
            $admin_id = (int) $closer_entry['admin_id'];

            if (! isset($merged_closers[$admin_id])) {
                $merged_closers[$admin_id] = $closer_entry;
                continue;
            }

            $existing_eventos = $merged_closers[$admin_id]['eventos'] ?? [];
            $extra_eventos    = $closer_entry['eventos'] ?? [];

            if (! empty($extra_eventos)) {
                $merged_closers[$admin_id]['eventos'] = array_merge($existing_eventos, $extra_eventos);
            }

            /* Si el segundo snapshot trae un estado más informativo, conservarlo. */
            if (($merged_closers[$admin_id]['estado'] ?? '') === 'cacheado'
                && ($closer_entry['estado'] ?? '') !== 'cacheado') {
                $merged_closers[$admin_id]['estado'] = $closer_entry['estado'];
            }
        }

        return [
            'consultado_en' => $base_snapshot['consultado_en'] ?? ($extra_snapshot['consultado_en'] ?? now()->toIso8601String()),
            'closers'       => array_values($merged_closers),
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
     * Nota sobre el linde exacto en la capa 2 (closer): si closer_release == cstart
     * el slot queda BLOQUEADO (comparación >=). Esto evita el bug del caso Patricia/Lead #105,
     * donde dos demos adyacentes compartían exactamente el mismo instante de liberación
     * y el segundo slot se ofrecía erróneamente como disponible.
     *
     * @param Carbon                            $day                         Día a evaluar.
     * @param array<int, array{0: int, 1: int}> $blocked_ranges              Rangos bloqueados por demo en minutos del día.
     * @param Carbon                            $now                         Instante actual en Argentina.
     * @param string                            $today_key                   Fecha de hoy (Y-m-d).
     * @param int                               $now_minutes                 Minutos transcurridos hoy.
     * @param int                               $duracion                    Duración de la demo en minutos.
     * @param array<int, array{0: int, 1: int}> $closer_busy_ranges_for_date Rangos de closer ocupado para este día.
     * @param int                               $gracia_post                 Minutos de gracia post-demo.
     * @param array<string, mixed>              $slot_config                 Config de generación de slots (horarios, frecuencia, flags).
     *
     * @return string[] Horarios disponibles en formato HH:MM.
     */
    protected function compute_day_slots_for_demo(Carbon $day, array $blocked_ranges, Carbon $now, string $today_key, int $now_minutes, int $duracion, array $closer_busy_ranges_for_date = [], int $gracia_post = 0, array $slot_config = []): array
    {
        $date_key  = $day->format('Y-m-d');
        $is_today  = $date_key === $today_key;

        /* Slots candidatos del día: generados dinámicamente según horario del closer y frecuencia. */
        $all_slots = $this->get_all_slots_for_day($day, $slot_config);

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

            /* Capa 1: chequeo por demo_id; impide solapar entornos técnicos.
             * El rango bloqueado es [inicio_demo - setup_antes, fin_demo + gracia_post].
             * Se bloquea si el slot se solapa con ese rango. */
            foreach ($blocked_ranges as [$bstart, $bend]) {
                if ($slot_start < $bend && $slot_end > $bstart) {
                    $slot_free = false;
                    break;
                }
            }

            /*
             * Capa 2: chequeo por closer; impide que el closer atienda dos leads en simultáneo.
             * Se verifica si el instante en que el lead candidato liberaría al closer
             * (slot_end + gracia_post = inicio_llamada proyectada) cae dentro de una ventana
             * ya comprometida por otra demo.
             *
             * Bug fix (prompt 076): comparación cambiada de estricta (>) a >= para el linde exacto.
             * Si closer_release == cstart, el closer arrancaría justo al liberar la demo anterior,
             * lo que hace imposible intercalar la llamada. Se bloquea correctamente con >=.
             */
            if ($slot_free && ! empty($closer_busy_ranges_for_date)) {
                /* Instante en que este lead candidato quedaría listo para el closer. */
                $closer_release = $slot_end + $gracia_post;
                foreach ($closer_busy_ranges_for_date as [$cstart, $cend]) {
                    /* Bloqueado si closer_release cae DENTRO de la ventana comprometida (inclusive el inicio). */
                    if ($closer_release >= $cstart && $closer_release < $cend) {
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
     *   - Lunes a viernes: cada hora de 09:00 a 17:00 (9 bloques, el último termina a las 18:00)
     *   - Sábado: 09:00, 10:00, 11:00, 12:00 (4 bloques, el último termina a las 13:00)
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
                    $context['gracia_post'],
                    $context['slot_config'] ?? []
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
        /* El cursor arranca en mañana: nunca se ofrece el día actual como opción de demo.
         * El closer necesita al menos un día de anticipación para prepararse. */
        $cursor    = $now->copy()->startOfDay()->addDay();

        /* Horarios laborales del closer para decidir qué días son hábiles en el algoritmo legacy. */
        $horario_lv  = LeadDemoSettings::get_closer_horario_lunes_viernes();
        $horario_sab = LeadDemoSettings::get_closer_horario_sabado();
        $horario_dom = LeadDemoSettings::get_closer_horario_domingo();

        while (count($working_days) < $days_ahead) {
            /* 0=domingo, 6=sábado, 1-5=lunes a viernes (convención Carbon). */
            $dow = $cursor->dayOfWeek;
            /* Horario laboral del closer según el día de la semana evaluado. */
            $horario_dia = '';
            if ($dow === 0) {
                $horario_dia = $horario_dom;
            } elseif ($dow === 6) {
                $horario_dia = $horario_sab;
            } else {
                $horario_dia = $horario_lv;
            }

            /* Incluir el día solo si tiene rango horario configurado (no vacío). */
            if ($horario_dia !== '') {
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
            /* Avanzar el cursor hasta el próximo día con horario configurado (p. ej. domingo si aplica). */
            $horario_extra = '';
            while ($horario_extra === '') {
                $dow_extra = $cursor->dayOfWeek;
                if ($dow_extra === 0) {
                    $horario_extra = $horario_dom;
                } elseif ($dow_extra === 6) {
                    $horario_extra = $horario_sab;
                } else {
                    $horario_extra = $horario_lv;
                }
                if ($horario_extra === '') {
                    $cursor->addDay();
                }
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
     * Devuelve los slots candidatos para un día concreto, generados dinámicamente
     * a partir del horario laboral del closer y la frecuencia de slots configurada.
     *
     * Un slot HH:MM es ofrecible si la llamada del closer proyectada (que arranca en
     * slot_inicio + duracion_demo + gracia_post) cae dentro del horario laboral del closer:
     *   - inicio_llamada >= inicio_horario_closer (el closer ya entró a trabajar)
     *   - inicio_llamada <= fin_horario_closer    (la llamada empieza antes de que el closer salga)
     *   - Si checkbox llamada_debe_terminar_en_horario ON:
     *     también fin_llamada <= fin_horario_closer
     *
     * Si el horario del closer para ese día de semana está vacío, se devuelve array vacío
     * y el día queda sin slots (el algoritmo de días hábiles agrega un día extra si hace falta).
     *
     * Nota: el método legacy get_available_slots_legacy() sigue usando este mismo método
     * sin $slot_config (array vacío), por lo que usa los defaults hardcodeados del fallback.
     *
     * @param Carbon               $day         Día a evaluar.
     * @param array<string, mixed> $slot_config Config de generación (horario_lv, horario_sab,
     *                                          horario_dom, frecuencia_slots, duracion,
     *                                          gracia_post, duracion_llamada_closer,
     *                                          llamada_debe_terminar_en_horario).
     *
     * @return string[] Horarios en formato HH:MM, ordenados de menor a mayor.
     */
    private function get_all_slots_for_day(Carbon $day, array $slot_config = []): array
    {
        /*
         * Extraer config con fallbacks a valores hardcodeados históricos,
         * para que el algoritmo legacy siga funcionando cuando no hay $slot_config.
         */
        /* Horario laboral del closer por día de semana (H:i-H:i). */
        $horario_lv  = isset($slot_config['horario_lv'])  ? (string) $slot_config['horario_lv']  : '09:00-17:00';
        $horario_sab = isset($slot_config['horario_sab']) ? (string) $slot_config['horario_sab'] : '09:00-13:00';
        $horario_dom = isset($slot_config['horario_dom']) ? (string) $slot_config['horario_dom'] : '';
        /* Frecuencia entre slots candidatos en minutos (ej. 60 = en punto, 30 = :00 y :30). */
        $frecuencia  = isset($slot_config['frecuencia_slots']) ? (int) $slot_config['frecuencia_slots'] : 60;
        /* Parámetros de la demo necesarios para proyectar cuándo arranca la llamada del closer. */
        $duracion    = isset($slot_config['duracion'])    ? (int) $slot_config['duracion']    : 60;
        $gracia      = isset($slot_config['gracia_post']) ? (int) $slot_config['gracia_post'] : 0;
        /* Duración de la llamada del closer (para la restricción del checkbox). */
        $dur_closer  = isset($slot_config['duracion_llamada_closer'])          ? (int)  $slot_config['duracion_llamada_closer']          : 30;
        /* Checkbox: true = la llamada también debe terminar dentro del horario. */
        $llamada_termina = isset($slot_config['llamada_debe_terminar_en_horario']) ? (bool) $slot_config['llamada_debe_terminar_en_horario'] : false;

        /*
         * Frecuencia mínima de 5 minutos para evitar loops infinitos o listas exageradamente largas.
         * En producción siempre vendrá un valor del conjunto {5, 10, 15, 30, 60}.
         */
        if ($frecuencia < 5) {
            $frecuencia = 5;
        }

        /* Seleccionar el horario según día de semana (0=domingo, 6=sábado). */
        $dow = $day->dayOfWeek;
        if ($dow === 0) {
            /* Domingo */
            $horario_raw = $horario_dom;
        } elseif ($dow === 6) {
            /* Sábado */
            $horario_raw = $horario_sab;
        } else {
            /* Lunes a viernes */
            $horario_raw = $horario_lv;
        }

        /* Horario vacío significa que el closer no trabaja ese día: sin slots. */
        if ($horario_raw === '') {
            return [];
        }

        /* Parsear "HH:MM-HH:MM" → inicio y fin en minutos del día. */
        $partes = explode('-', $horario_raw);
        if (count($partes) !== 2) {
            return [];
        }

        /* Extraer inicio del horario. */
        if (! preg_match('/(\d{1,2}):(\d{2})/', $partes[0], $mi)) {
            return [];
        }
        /* Extraer fin del horario. */
        if (! preg_match('/(\d{1,2}):(\d{2})/', $partes[1], $mf)) {
            return [];
        }

        /* Minutos del día para el inicio y fin del horario laboral del closer. */
        $horario_inicio = (int) $mi[1] * 60 + (int) $mi[2];
        $horario_fin    = (int) $mf[1] * 60 + (int) $mf[2];

        /*
         * Generar todos los slots desde medianoche hasta el final del día en pasos de $frecuencia,
         * y retener solo los que cumplan las condiciones de ofrecibilidad.
         * El ancla es 0 (medianoche): con frecuencia=30 los slots son :00 y :30; con 60 solo :00.
         */
        $slots = [];
        for ($slot_min = 0; $slot_min < 1440; $slot_min += $frecuencia) {
            /*
             * Proyectar el instante en que el closer tomaría la llamada para este slot:
             *   inicio_llamada = inicio_demo + duracion_demo + gracia_post
             */
            $inicio_llamada = $slot_min + $duracion + $gracia;
            /* Fin de la llamada del closer (relevante solo si el checkbox está activo). */
            $fin_llamada    = $inicio_llamada + $dur_closer;

            /* La llamada debe COMENZAR dentro del horario laboral del closer. */
            if ($inicio_llamada < $horario_inicio || $inicio_llamada > $horario_fin) {
                continue;
            }

            /* Si el checkbox está activado: la llamada también debe TERMINAR dentro del horario. */
            if ($llamada_termina && $fin_llamada > $horario_fin) {
                continue;
            }

            $slots[] = self::format_minutes_to_hhmm($slot_min);
        }

        return $slots;
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
    protected function create_message_and_update_lead(
        Lead $lead,
        array $parsed,
        bool $is_followup,
        ?array $calendar_snapshot = null
    ): LeadMessage {
        /* Extraer y validar los campos obligatorios de la respuesta. */
        $mensaje    = isset($parsed['mensaje_sugerido']) ? trim((string) $parsed['mensaje_sugerido']) : '';
        $estado_raw = isset($parsed['estado_sugerido']) ? trim((string) $parsed['estado_sugerido']) : '';

        /*
         * Permitir mensaje vacío únicamente cuando Claude solicita disponibilidad (flujo normal de agenda).
         * En ese caso el mensaje vacío es intencional: el sistema hará una segunda llamada con los slots.
         * Fuera de ese caso, mensaje o estado vacío sigue siendo un error real.
         */
        $solicita_disponibilidad_flag = ! empty($parsed['solicita_disponibilidad']);
        if ($estado_raw === '' || ($mensaje === '' && ! $solicita_disponibilidad_flag)) {
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

        /*
         * Flag para detectar si el agendar_demo que sigue es un reagendado (el lead ya tenía demo
         * y pidió cambiar el horario). Se usa para elegir el template correcto en DemoScheduledWhatsappService.
         * Se marca true dentro del bloque cancelar_demo cuando efectivamente había una demo previa.
         */
        $es_reagendado = false;

        /*
         * Variables para coordinar las operaciones de Google Calendar event DESPUÉS del save() principal.
         * Se usan flags para evitar llamadas parciales al servicio antes de que el lead esté persistido.
         */
        // ID del evento anterior de Google Calendar: guardado antes de limpiar el lead.
        $google_event_id_anterior = null;
        // Fecha de la demo anterior: se necesita para invalidar la caché al eliminar el evento.
        $google_event_demo_date_anterior = null;
        // Flag: se debe eliminar el evento existente en Google Calendar del closer.
        $google_event_delete_needed = false;
        // Flag: se debe crear un nuevo evento en Google Calendar del closer.
        $google_event_create_needed = false;

        /* Acción: cancelar demo agendada cuando el lead pide reagendar.
         * Solo tiene efecto si el lead tiene demo_date cargada; si no, el flag se ignora.
         * Limpia los 4 campos de demo para liberar el slot en la disponibilidad de inmediato. */
        $cancelar_demo = ! empty($parsed['cancelar_demo']);
        if ($cancelar_demo && $lead->demo_date !== null) {
            /* Marcar que el próximo agendar_demo es un reagendado. */
            $es_reagendado = true;
            /* Guardar valores anteriores para el log antes de limpiarlos. */
            $demo_date_anterior  = $lead->demo_date ? $lead->demo_date->format('Y-m-d') : 'sin fecha';
            $demo_start_anterior = $lead->demo_start_time ?? 'sin hora';

            /* Marcar que se debe eliminar el evento anterior de Google Calendar del closer.
             * Se guarda el ID y la fecha ANTES de limpiar el lead para usarlos en el POST-save. */
            if (! empty($lead->google_event_id)) {
                $google_event_delete_needed      = true;
                $google_event_id_anterior        = $lead->google_event_id;
                $google_event_demo_date_anterior = $lead->demo_date->format('Y-m-d');
                // Limpiar google_event_id y meet_url en memoria para que el save() principal los persista como null.
                $lead->google_event_id = null;
                $lead->meet_url        = null;
            }

            /* Limpiar los campos de demo: libera el slot y deja al lead listo para reagendar. */
            $lead->demo_id         = null;
            $lead->demo_date       = null;
            $lead->demo_start_time = null;
            $lead->demo_end_time   = null;

            Log::info('LeadAiService: demo cancelada por solicitud de reagendado.', [
                'lead_id'            => $lead->id,
                'demo_date_anterior' => $demo_date_anterior,
                'demo_hora_anterior' => $demo_start_anterior,
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
                /* Validar que el slot exista en la disponibilidad real para esa demo.
                 * Las claves del JSON incluyen el nombre del día ("domingo 2026-06-28"),
                 * pero Claude devuelve demo_date en formato Y-m-d. Buscar la clave que
                 * contenga la fecha solicitada. */
                $availability = $this->build_availability_json();
                $slots_demo   = [];
                $demo_slots_by_date = $availability['demos'][$demo_id] ?? [];
                foreach ($demo_slots_by_date as $date_label => $slots) {
                    /* La clave puede ser "Y-m-d" (legacy) o "nombre Y-m-d" (nuevo formato). */
                    if ($date_label === $demo_date || (strlen($demo_date) <= strlen($date_label) && substr($date_label, -strlen($demo_date)) === $demo_date)) {
                        $slots_demo = $slots;
                        break;
                    }
                }

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

                    /*
                     * FIX (prompt 118): actualizar el status junto con los campos de demo.
                     * La demo ya quedó persistida en BD; no esperar al envío del mensaje por WhatsApp.
                     * Así, si el lead responde antes del auto-send, generate_suggestion() ve demo_agendada.
                     */
                    $lead->status = 'demo_agendada';
                    $pipeline_status       = LeadPipelineStatus::ensure_exists('demo_agendada');
                    $estado                = $pipeline_status->slug;
                    /* El badge de cambio de estado se mantiene (suggested_lead_status != null).
                     * apply_suggested_pipeline_status() tiene guardia para no pisar el status ya aplicado. */
                    $suggested_lead_status = $estado !== $previous_status ? $estado : null;

                    Log::info('LeadAiService: demo agendada vía acción estructurada y validada.', [
                        'lead_id'    => $lead->id,
                        'demo_id'    => $demo_id,
                        'demo_date'  => $demo_date,
                        'demo_start' => $demo_start,
                        'demo_end'   => $demo_end,
                    ]);

                    /* Marcar que se debe crear el evento en Google Calendar del closer
                     * después del save() principal del lead. */
                    $google_event_create_needed = true;

                    /* Notificar por WhatsApp a los admins suscritos a demos agendadas.
                     * Si $es_reagendado = true se usa el template de cambio de horario. */
                    try {
                        $demo_notify_service = new \App\Services\DemoScheduledWhatsappService(
                            new \App\Services\WhatsappSendService()
                        );
                        $demo_notify_service->notify($lead, $demo_date, $demo_start, $es_reagendado);
                    } catch (\Throwable $e) {
                        Log::error('LeadAiService: error al notificar demo agendada por WhatsApp.', [
                            'lead_id'       => $lead->id,
                            'is_reagendado' => $es_reagendado,
                            'error'         => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        /*
         * Flags de notificación WhatsApp a admins para las acciones de inferencia del ciclo de demo.
         * Se marcan true únicamente cuando la acción se procesa de verdad (primera vez, anti-duplicado).
         * Las notificaciones se disparan después del $lead->save() para que los timestamps estén persistidos.
         */
        $notificar_ingreso_confirmado = false;
        $notificar_fin_confirmado     = false;
        $notificar_no_ingreso         = false;

        /* Acción: confirmar que el lead ingresó a la demo (inferencia conversacional).
         * Solo válida si el lead está en ingresando_demo o en demo_agendada (tolerante,
         * para el caso en que el check se envió pero el estado todavía no actualizó).
         * Si ya estaba confirmado, no se repite el timestamp ni se re-dispara nada. */
        $confirmar_ingreso = ! empty($parsed['confirmar_ingreso']);
        if ($confirmar_ingreso) {
            /* Estados desde los cuales tiene sentido confirmar el ingreso. */
            $estados_validos_ingreso = ['ingresando_demo', 'demo_agendada'];
            if (in_array((string) $lead->status, $estados_validos_ingreso, true)) {
                /* Anti-duplicado: solo setear la fecha la primera vez que se confirma. */
                if (! $lead->demo_ingreso_confirmado) {
                    /* Marcar el flag y registrar el momento exacto de confirmación. */
                    $lead->demo_ingreso_confirmado    = true;
                    $lead->demo_ingreso_confirmado_at = now('America/Argentina/Buenos_Aires');
                    /* Habilitar la notificación a admins (se dispara después del save). */
                    $notificar_ingreso_confirmado = true;
                    Log::info('LeadAiService: ingreso a demo confirmado por inferencia.', [
                        'lead_id' => $lead->id,
                    ]);
                }

                /* Forzar el estado a demo_en_curso independientemente de lo que Claude sugirió. */
                $estado_raw      = 'demo_en_curso';
                $pipeline_status = LeadPipelineStatus::ensure_exists($estado_raw);
                $estado          = $pipeline_status->slug;
                /* Recalcular el diff de estado para que el badge del mensaje sea correcto. */
                $suggested_lead_status = $estado !== $previous_status ? $estado : null;
            }
        }

        /* Acción: confirmar que el lead terminó la demo (inferencia conversacional).
         * Válida en demo_en_curso o demo_pendiente_de_terminar.
         * Anti-duplicado igual que confirmar_ingreso.
         * Cubre también la reanudación (evento 8): lead en demo_pendiente_de_terminar
         * que vuelve y confirma el fin. El mismo enganche sirve para ambos estados. */
        $confirmar_fin_demo = ! empty($parsed['confirmar_fin_demo']);
        if ($confirmar_fin_demo) {
            /* Estados desde los cuales tiene sentido confirmar el fin. */
            $estados_validos_fin = ['demo_en_curso', 'demo_pendiente_de_terminar'];
            if (in_array((string) $lead->status, $estados_validos_fin, true)) {
                /* Anti-duplicado: solo setear la fecha la primera vez que se confirma el fin. */
                if (! $lead->demo_terminada_confirmada) {
                    /* Marcar el flag y registrar el momento exacto de confirmación de fin. */
                    $lead->demo_terminada_confirmada    = true;
                    $lead->demo_terminada_confirmada_at = now('America/Argentina/Buenos_Aires');
                    /* Habilitar la notificación a admins (se dispara después del save). */
                    $notificar_fin_confirmado = true;
                    Log::info('LeadAiService: fin de demo confirmado por inferencia.', [
                        'lead_id' => $lead->id,
                    ]);
                }

                /* Forzar el estado a demo_realizada independientemente de lo que Claude sugirió. */
                $estado_raw      = 'demo_realizada';
                $pipeline_status = LeadPipelineStatus::ensure_exists($estado_raw);
                $estado          = $pipeline_status->slug;
                /* Recalcular el diff de estado para que el badge del mensaje sea correcto. */
                $suggested_lead_status = $estado !== $previous_status ? $estado : null;
            }
        }

        /* Acción: marcar que el lead no va a poder ingresar a la demo.
         * Claude la usa cuando el lead dice explícitamente que no puede o no quiere entrar.
         * Solo válida si el lead está en ingresando_demo. */
        $marcar_no_ingreso = ! empty($parsed['marcar_no_ingreso']);
        if ($marcar_no_ingreso && (string) $lead->status === 'ingresando_demo') {
            /* Retroceder a demo_pendiente_de_ingreso para que el sistema pueda reintentar el flujo. */
            $estado_raw      = 'demo_pendiente_de_ingreso';
            $pipeline_status = LeadPipelineStatus::ensure_exists($estado_raw);
            $estado          = $pipeline_status->slug;
            /* Recalcular el diff de estado para el badge. */
            $suggested_lead_status = $estado !== $previous_status ? $estado : null;
            /* Habilitar la notificación a admins (se dispara después del save). */
            $notificar_no_ingreso = true;
            Log::info('LeadAiService: no ingreso a demo marcado por inferencia.', [
                'lead_id' => $lead->id,
            ]);
        }

        /*
         * Acción: sugerir socio adicional cuando el lead lo menciona en post-llamada (closer_activo).
         * Solo aplica si el lead está en closer_activo; fuera de ese estado se ignora la acción.
         */
        $sugerir_socio = isset($parsed['sugerir_socio']) && is_array($parsed['sugerir_socio'])
            ? $parsed['sugerir_socio']
            : null;
        if ($sugerir_socio !== null && (string) $lead->status === 'closer_activo') {
            $nombre   = trim((string) ($sugerir_socio['nombre']   ?? ''));
            $telefono = trim((string) ($sugerir_socio['telefono'] ?? ''));
            $rol      = trim((string) ($sugerir_socio['rol']      ?? ''));

            if ($nombre !== '' || $telefono !== '') {
                LeadPartner::create([
                    'lead_id'              => $lead->id,
                    'name'                 => $nombre !== '' ? $nombre : null,
                    'phone'                => $telefono !== '' ? $telefono : null,
                    'notes'                => $rol !== '' ? "Rol: {$rol}" : null,
                    'source'               => 'whatsapp_suggestion',
                    'pending_confirmation' => true,
                ]);

                Log::info('LeadAiService: socio sugerido desde WhatsApp post-llamada.', [
                    'lead_id' => $lead->id,
                    'nombre'  => $nombre,
                    'telefono'=> $telefono,
                ]);
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

            /* Notificar por WhatsApp a los admins suscritos a escalaciones de lead.
             * Se ejecuta en bloque separado para que un fallo en WhatsApp no afecte
             * el AdminTask ya creado ni el flujo principal del mensaje. */
            try {
                $escalation_service = new \App\Services\LeadEscalationWhatsappService(
                    new \App\Services\WhatsappSendService()
                );
                $escalation_service->notify($lead, $motivo_intervencion);
            } catch (\Throwable $e) {
                Log::error('LeadAiService: error al notificar escalación por WhatsApp.', [
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
            /* Snapshot de eventos Google del closer al ofrecer disponibilidad (debug admin-spa). */
            'calendar_snapshot'     => $calendar_snapshot
                ? json_encode($calendar_snapshot, JSON_UNESCAPED_UNICODE)
                : null,
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

        /*
         * Operaciones de Google Calendar del closer: se ejecutan después del save() para que
         * los campos de demo (demo_date, demo_start_time, etc.) estén ya persistidos en BD.
         * Son best-effort: si fallan, no rompen el flujo de agendamiento.
         *
         * Tres escenarios posibles:
         *   1. Solo cancelar_demo sin agendar_demo: eliminar el evento existente.
         *   2. cancelar_demo + agendar_demo (reagendado): eliminar el viejo y crear el nuevo.
         *   3. Solo agendar_demo (primer agendado): crear el evento nuevo.
         */
        if ($google_event_delete_needed || $google_event_create_needed) {
            try {
                $google_event_service = new CloserGoogleCalendarEventService(
                    new GoogleCalendarOAuthService(),
                    new CloserGoogleCalendarBusyService(new GoogleCalendarOAuthService())
                );

                if ($google_event_delete_needed) {
                    // Eliminar el evento anterior usando el ID guardado antes de limpiar el lead.
                    // (google_event_id ya está null en el lead por lo que pasamos el ID guardado).
                    $google_event_service->delete_event_by_id(
                        $google_event_id_anterior,
                        $google_event_demo_date_anterior
                    );
                }

                if ($google_event_create_needed) {
                    // Crear el nuevo evento usando el lead fresco con los datos de demo persistidos.
                    $google_event_service->create_event_for_lead($lead->fresh());
                }
            } catch (\Throwable $e) {
                Log::error('LeadAiService: error en operaciones de Google Calendar del closer.', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        /*
         * Notificaciones WhatsApp a admins del ciclo de demo.
         * Se disparan después del save() para que los timestamps (_at) ya estén persistidos.
         * Cada bloque es independiente: un fallo en uno no afecta a los demás.
         */
        if ($notificar_ingreso_confirmado) {
            try {
                $ciclo_service = new \App\Services\DemoCicloAdminNotificationService(
                    new \App\Services\WhatsappSendService()
                );
                $ciclo_service->notify_ingreso_confirmado($lead->fresh());
            } catch (\Throwable $e) {
                Log::error('LeadAiService: error al notificar ingreso_confirmado a admins.', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        if ($notificar_fin_confirmado) {
            try {
                $ciclo_service = new \App\Services\DemoCicloAdminNotificationService(
                    new \App\Services\WhatsappSendService()
                );
                $ciclo_service->notify_fin_confirmado($lead->fresh());
            } catch (\Throwable $e) {
                Log::error('LeadAiService: error al notificar fin_confirmado a admins.', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);
            }

            /* Disparar alerta "Tomar llamada" al closer: modal broadcast + WhatsApp + fallbacks automáticos.
             * Se ejecuta solo la primera vez que se confirma el fin de la demo (bandera $notificar_fin_confirmado). */
            try {
                $closer_alert_service = new \App\Services\CloserAlertService();
                $closer_alert_service->fire_alert($lead->fresh());
            } catch (\Throwable $e) {
                Log::error('LeadAiService: error al disparar alerta del closer.', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        if ($notificar_no_ingreso) {
            try {
                $ciclo_service = new \App\Services\DemoCicloAdminNotificationService(
                    new \App\Services\WhatsappSendService()
                );
                $ciclo_service->notify_no_ingreso($lead->fresh(), 'el lead indicó que no podía ingresar');
            } catch (\Throwable $e) {
                Log::error('LeadAiService: error al notificar no_ingreso a admins.', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

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

        /* Notificar a admins suscritos cuando la sugerencia requiere verificación manual. */
        if ($req_verif) {
            try {
                $verificacion_service = new \App\Services\LeadVerificacionWhatsappService(
                    new \App\Services\WhatsappSendService()
                );
                $verificacion_service->notify($lead->fresh(), $msg);
            } catch (\Throwable $e) {
                Log::error('LeadAiService: error al notificar verificacion pendiente por WhatsApp.', [
                    'lead_id'    => $lead->id,
                    'message_id' => $msg->id,
                    'error'      => $e->getMessage(),
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
            if ($texto === '' || strncmp($texto, '```', 3) === 0 || strncmp($texto, '{', 1) === 0) {
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

        /*
         * Regla de código adicional (prompt 151): refuerza que sin JSON de disponibilidad
         * en el contexto actual el agente no puede afirmar rangos horarios propios.
         */
        $contenido .= "\n\n" . self::PROHIBICION_RANGO_HORARIO_SIN_JSON;

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

        /*
         * Fecha y hora actual en Argentina para que Claude pueda calcular referencias
         * temporales relativas ("dentro de 5 días", "el viernes que viene", etc.)
         * tanto en la primera como en la segunda llamada.
         */
        $now_ar    = now('America/Argentina/Buenos_Aires');
        $day_names = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $fecha_hoy = ucfirst($day_names[$now_ar->dayOfWeek])
            . ' ' . $now_ar->format('d/m/Y')
            . ', ' . $now_ar->format('H:i') . 'hs (hora Argentina)';

        $txt = <<<TXT
FECHA Y HORA ACTUAL: {$fecha_hoy}

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

        /* Inyectar el objetivo activo según el estado de la demo.
         * Este bloque le indica a Claude qué debe perseguir en cada momento del ciclo de la demo,
         * de forma análoga a cómo persigue el agendamiento cuando solicita disponibilidad.
         * El detalle fino de comportamiento está en el protocolo (sección CICLO DE LA DEMO). */
        $lead_status_for_context = (string) $lead->status;

        if ($lead_status_for_context === 'ingresando_demo') {
            /* El lead está en el momento de intentar entrar al sistema demo. */
            $txt .= "\n\nCONTEXTO DE DEMO - INGRESO:\n"
                . "El lead tiene la demo en curso de inicio y se le preguntó si pudo ingresar al sistema.\n"
                . "Tu objetivo es asegurarte de que entre. Si dice que tuvo un problema para entrar, resolveselo:\n"
                . "pasale el link de la demo y aclarale que el usuario y la contraseña son su número de documento.\n"
                . "(Ver datos del lead arriba y regla 13.2 del protocolo.)\n"
                . "Cuando el lead confirme que ya entró (infieras de su mensaje, no por una palabra exacta),\n"
                . "devolvé la acción confirmar_ingreso: true en el JSON.\n"
                . "Si el lead dice claramente que no va a poder o no quiere entrar, devolvé marcar_no_ingreso: true.\n"
                . "Si intentaste resolver el acceso y aun así no puede, devolvé requiere_intervencion_humana: true\n"
                . "con motivo_intervencion claro.";
        } elseif ($lead_status_for_context === 'demo_en_curso') {
            /* El lead ya está dentro de la demo, haciendo el recorrido. */
            $txt .= "\n\nCONTEXTO DE DEMO - EN CURSO:\n"
                . "El lead ya está dentro de la demo. Respondé cualquier duda técnica que tenga sobre el sistema\n"
                . "con naturalidad. Pero tu objetivo permanente es saber cuándo terminó la demo: si ya se le\n"
                . "preguntó si terminó y responde otra cosa, respondele lo que pregunte y volvé a preguntar al\n"
                . "final si ya terminó. No te quedes esperando pasivamente.\n"
                . "Cuando infieras que el lead terminó la demo (aunque te lo diga indirectamente, o te diga que sí\n"
                . "y encima te haga una pregunta), devolvé confirmar_fin_demo: true, respondé lo que haya que\n"
                . "responder, y dejá que el sistema lo avance.";
        } elseif ($lead_status_for_context === 'demo_pendiente_de_terminar') {
            /* El lead volvió a escribir después de que el sistema no pudo confirmar el fin de la demo. */
            $txt .= "\n\nCONTEXTO DE DEMO - PENDIENTE DE TERMINAR:\n"
                . "Se había dado por no confirmada la finalización de la demo de este lead, pero volvió a escribir.\n"
                . "Si de su mensaje se infiere que efectivamente terminó la demo, devolvé confirmar_fin_demo: true.\n"
                . "Si todavía está en la demo, seguí ayudándolo y volvé a perseguir saber cuándo termina.";
        } elseif ($lead_status_for_context === 'closer_activo') {
            /* Post-llamada: el lead ya tuvo la demo con el closer y puede mencionar socios u otros contactos. */
            $txt .= "\n\nCONTEXTO POST-LLAMADA - CLOSER ACTIVO:\n"
                . "El lead ya tuvo la llamada de cierre con el closer. Si en su mensaje menciona explícitamente\n"
                . "a otra persona que participa en la decisión (socio, cónyuge, contador, etc.) con nombre\n"
                . "y/o número de teléfono, devolvé la acción sugerir_socio con los datos detectados.\n"
                . "Solo usar cuando el lead lo mencione con datos de contacto concretos. Si no hay socio nuevo,\n"
                . "omití sugerir_socio o ponelo en null.";
        }

        $txt .= "\n¿Qué respuesta sugerís y en qué estado debería quedar el lead?";

        return $txt;
    }

    /**
     * Extrae y decodifica el JSON de la respuesta de Claude.
     *
     * Claude a veces autocorrige dentro de la misma respuesta (primer JSON incorrecto,
     * texto intermedio y segundo JSON correcto). En ese caso se retorna el último
     * bloque JSON válido encontrado, no el span completo entre el primer { y el último }.
     *
     * @param string $raw Texto crudo devuelto por la API (puede tener texto extra fuera del JSON).
     *
     * @throws \RuntimeException Si no se encuentra un JSON válido en la respuesta.
     *
     * @return array<string, mixed>
     */
    protected function parse_json_response(string $raw): array
    {
        // Candidatos JSON válidos encontrados al recorrer cada apertura `{`.
        $candidates = [];
        // Posición desde la cual buscar la próxima apertura `{`.
        $pos = 0;

        while (($start = strpos($raw, '{', $pos)) !== false) {
            // Probar desde el `}` más a la derecha hacia atrás hasta emparejar con este `{`.
            $end = strrpos($raw, '}');

            while ($end !== false && $end >= $start) {
                // Fragmento candidato entre el `{` actual y el `}` en evaluación.
                $candidate = substr($raw, $start, $end - $start + 1);
                $decoded   = json_decode($candidate, true);

                if (is_array($decoded)) {
                    $candidates[] = $decoded;
                    break;
                }

                // Si no decodifica, probar con el `}` anterior más cercano a este `{`.
                $prev_end_relative = strrpos(substr($raw, $start, $end - $start), '}');

                if ($prev_end_relative === false) {
                    break;
                }

                $end = $start + $prev_end_relative;
            }

            $pos = $start + 1;
        }

        if (empty($candidates)) {
            throw new \RuntimeException('Claude no devolvió JSON válido: '.$raw);
        }

        /*
         * Priorizar el último candidato que contenga 'mensaje_sugerido': eso garantiza
         * que nunca se devuelva un sub-objeto anidado (como agendar_demo) en lugar del
         * objeto raíz. Si ningún candidato tiene 'mensaje_sugerido', usar el último
         * válido como fallback (comportamiento original para respuestas sin esa clave).
         */
        $candidates_with_mensaje = array_filter($candidates, function ($c) {
            return array_key_exists('mensaje_sugerido', $c);
        });

        if (! empty($candidates_with_mensaje)) {
            return end($candidates_with_mensaje);
        }

        return end($candidates);
    }
}



