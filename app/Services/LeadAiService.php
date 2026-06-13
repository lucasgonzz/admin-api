<?php

namespace App\Services;

use App\Events\LeadSuggestionCreated;
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

        /* Log de diagnóstico: contenido enviado a Claude en la primera llamada. */
        Log::debug('LeadAiService [PRIMERA LLAMADA] - system prompt', [
            'lead_id' => $lead->id,
            'system'  => $system,
        ]);

        Log::debug('LeadAiService [PRIMERA LLAMADA] - user content', [
            'lead_id' => $lead->id,
            'content' => $user_content,
        ]);

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
        /* Obtener slots disponibles y construir el string de disponibilidad para Claude. */
        $slots = $this->get_available_slots();

        /* Nombres de días en español para armar el texto de disponibilidad. */
        $day_names = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

        $availability_lines = '';
        foreach ($slots as $date_key => $times) {
            /* Construir etiqueta legible: "Lunes 14/05" */
            $carbon    = Carbon::parse($date_key);
            $day_label = ucfirst($day_names[$carbon->dayOfWeek]).' '.$carbon->format('d/m');

            if (empty($times)) {
                $availability_lines .= "- {$day_label}: sin disponibilidad\n";
            } else {
                $availability_lines .= "- {$day_label}: ".implode(', ', $times)."\n";
            }
        }

        /*
         * Construir etiqueta de fecha/hora actual de Argentina para que Claude
         * sepa exactamente qué día y hora es hoy (evita errores tipo "mañana sábado"
         * cuando hoy ya es sábado).
         */
        $now_arg        = now('America/Argentina/Buenos_Aires');
        $day_names_full = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $hoy_label      = ucfirst($day_names_full[$now_arg->dayOfWeek]).' '.$now_arg->format('d/m/Y').', '.$now_arg->format('H:i').'hs (hora Argentina)';

        /* String base que se inyecta como contexto en el user content. */
        $availability_context = "HOY ES: {$hoy_label}\n\nSlots disponibles (demos de 1 hora, lunes a viernes 9-18hs, sábado 9-12hs):\n{$availability_lines}";

        /* Agregar IDs de demos activas para que Claude pueda incluir demo_id en agendar_demo. */
        $demos    = \App\Models\Demo::orderBy('id')->get(['id']);
        $demo_ids = $demos->pluck('id')->implode(', ');
        if ($demo_ids !== '') {
            $availability_context .= "\nDEMOS DISPONIBLES: {$demo_ids}";
            $availability_context .= "\nAl elegir demo_id para agendar_demo, preferir la demo con menos agendas en ese día. Si hay empate, elegir la de menor ID.";
        }

        /*
         * Detectar si el último mensaje del lead contiene un horario concreto propuesto.
         * Si lo tiene, agregarlo al contexto para que Claude lo verifique explícitamente
         * contra los slots disponibles antes de confirmar o rechazar ese horario.
         */
        $lead_proposed_time = '';

        /* Último mensaje enviado por el lead (sender = 'lead'). */
        $last_lead_message = $lead->messages
            ->filter(fn($m) => (string) $m->sender === 'lead')
            ->last();

        if ($last_lead_message) {
            $last_content = trim((string) $last_lead_message->content);

            /*
             * Detectar patrones de hora en el mensaje del lead.
             * Ejemplos que deben matchear: "12", "12hs", "12:00", "las 12", "a las 10:30".
             */
            if (preg_match('/\b(\d{1,2})(?::(\d{2}))?\s*(?:hs?|h|:00)?\b/i', $last_content, $m)) {
                $lead_proposed_time = $m[0];
            }
        }

        /* Si se detectó un horario propuesto, agregar instrucción explícita para Claude. */
        if ($lead_proposed_time !== '') {
            $availability_context .= "\nEl lead propuso el horario: \"{$lead_proposed_time}\". Verificá si ese horario aparece en los slots disponibles de arriba. Si aparece: confirmalo. Si NO aparece: es porque está ocupado - informale al lead que ese horario no está disponible y ofrecele las alternativas libres más cercanas.";
        }

        /* Pasar el estado para inyectar la sección FAQ solo cuando corresponde */
        $system       = $this->build_system_prompt();
        $user_content = $this->build_user_content($lead, $is_followup, $availability_context);
        $model        = (string) config('services.anthropic.model', 'claude-sonnet-4-20250514');
        $http         = $this->build_http_client();

        /* Log de diagnóstico: contenido enviado a Claude en la segunda llamada. */
        Log::debug('LeadAiService [SEGUNDA LLAMADA - con disponibilidad] - system prompt', [
            'lead_id' => $lead->id,
            'system'  => $system,
        ]);

        Log::debug('LeadAiService [SEGUNDA LLAMADA - con disponibilidad] - user content', [
            'lead_id' => $lead->id,
            'content' => $user_content,
        ]);

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
     * Consulta los horarios de demo ocupados y devuelve los slots disponibles por día.
     *
     * Incluye los próximos $days_ahead días hábiles (lunes a sábado) a partir de mañana.
     * Si alguno de esos días queda sin disponibilidad, agrega el siguiente día hábil.
     *
     * Horarios posibles:
     *   - Lunes a viernes: cada hora de 09:00 a 17:00 (9 bloques, el último termina a las 18:00)
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
        /* Leer parámetros de configuración de demos. */
        $duracion    = LeadDemoSettings::get_duracion_minutos();
        $setup_antes = LeadDemoSettings::get_setup_minutos_antes();
        $gracia_post = LeadDemoSettings::get_gracia_minutos_post();

        /* Obtener todas las demos registradas para el cálculo multi-demo. */
        $demos = \App\Models\Demo::orderBy('id')->get();

        /*
         * Fallback: si no hay demos registradas, usar el algoritmo legacy
         * (bloquea exactamente el slot de inicio sin márgenes).
         */
        if ($demos->isEmpty()) {
            return $this->get_available_slots_legacy($days_ahead);
        }

        /* Construir lista de días hábiles a partir de HOY (excluye domingos). */
        $working_days = [];
        /* Instante actual en Argentina; se usa para filtrar slots de hoy ya pasados. */
        $now = now('America/Argentina/Buenos_Aires');
        /* Minutos transcurridos del día actual (para comparar contra horas de slot). */
        $now_minutes = $now->hour * 60 + $now->minute;
        /* Fecha de hoy (Y-m-d) para detectar el día actual dentro del loop de slots. */
        $today_key = $now->copy()->startOfDay()->format('Y-m-d');
        /* Cursor empieza HOY al inicio del día en timezone Argentina. */
        $cursor = $now->copy()->startOfDay();

        while (count($working_days) < $days_ahead) {
            /* 0 = domingo, se omite. */
            if ($cursor->dayOfWeek !== 0) {
                $working_days[] = $cursor->copy();
            }
            $cursor->addDay();
        }
        /* Al salir del while, $cursor apunta al día siguiente al último día incluido. */

        /* Array de strings de fecha para la consulta SQL. */
        $date_strings = array_map(function ($day) {
            return $day->format('Y-m-d');
        }, $working_days);

        /*
         * Obtener todos los leads con demo agendada en esos días.
         * Se incluye demo_id para asociar cada bloqueo a su demo específica.
         */
        $placeholders = implode(',', array_fill(0, count($date_strings), '?'));
        $booked_leads = Lead::whereRaw(
            "DATE(CONVERT_TZ(demo_date, '+00:00', '-03:00')) IN ({$placeholders})",
            $date_strings
        )
            ->whereNotNull('demo_start_time')
            ->whereNotNull('demo_id')
            ->get(['demo_id', 'demo_date', 'demo_start_time', 'demo_end_time']);

        /*
         * Construir mapa de rangos bloqueados por demo y fecha.
         * blocked_by_demo[$demo_id][$date] = [[block_start_min, block_end_min], ...]
         */
        $blocked_by_demo = [];
        foreach ($demos as $demo) {
            $blocked_by_demo[$demo->id] = [];
            foreach ($date_strings as $date) {
                $blocked_by_demo[$demo->id][$date] = [];
            }
        }

        foreach ($booked_leads as $bl) {
            $demo_id  = (int) $bl->demo_id;
            $date_key = $bl->demo_date->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d');

            if (! isset($blocked_by_demo[$demo_id][$date_key])) {
                continue;
            }

            /* Parsear hora de inicio del lead. */
            if (! preg_match('/(\d{1,2}):(\d{2})/', (string) $bl->demo_start_time, $m)) {
                continue;
            }
            $start_minutes = (int) $m[1] * 60 + (int) $m[2];

            /* Hora de fin: usar demo_end_time si existe, sino calcular con duración configurada. */
            if ($bl->demo_end_time && preg_match('/(\d{1,2}):(\d{2})/', (string) $bl->demo_end_time, $m2)) {
                $end_minutes = (int) $m2[1] * 60 + (int) $m2[2];
            } else {
                $end_minutes = $start_minutes + $duracion;
            }

            /* El rango bloqueado incluye margen de setup antes y gracia después. */
            $block_start = $start_minutes - $setup_antes;
            $block_end   = $end_minutes + $gracia_post;

            $blocked_by_demo[$demo_id][$date_key][] = [$block_start, $block_end];
        }

        /* Construir resultado: para cada día, slots disponibles en al menos una demo. */
        $result   = [];
        $any_full = false;

        foreach ($working_days as $day) {
            $date_key  = $day->format('Y-m-d');
            /* Sábados tienen slots reducidos (9–11hs); resto de días 9–17hs. */
            $all_slots = $day->dayOfWeek === 6
                ? ['09:00', '10:00', '11:00']
                : ['09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00'];

            /* Indica si el día que estamos evaluando es hoy. */
            $is_today = $date_key === $today_key;

            $available = [];
            foreach ($all_slots as $slot) {
                [$sh, $sm]  = explode(':', $slot);
                $slot_start = (int) $sh * 60 + (int) $sm;
                $slot_end   = $slot_start + $duracion;

                /*
                 * Para el día de hoy, descartar los slots cuyo horario de inicio
                 * ya pasó o está demasiado cerca (margen mínimo de 30 minutos
                 * para que el lead tenga tiempo de prepararse).
                 */
                if ($is_today && $slot_start < $now_minutes + 30) {
                    continue;
                }

                /*
                 * El slot está disponible si al menos una demo no tiene solapamiento
                 * con ninguno de sus rangos bloqueados.
                 */
                $slot_free = false;
                foreach ($demos as $demo) {
                    $demo_blocked   = $blocked_by_demo[$demo->id][$date_key] ?? [];
                    $demo_slot_free = true;
                    foreach ($demo_blocked as [$bstart, $bend]) {
                        /* Solapamiento: el slot no está completamente fuera del rango bloqueado. */
                        if ($slot_start < $bend && $slot_end > $bstart) {
                            $demo_slot_free = false;
                            break;
                        }
                    }
                    if ($demo_slot_free) {
                        $slot_free = true;
                        break;
                    }
                }

                if ($slot_free) {
                    $available[] = $slot;
                }
            }

            if (empty($available)) {
                $any_full = true;
            }
            $result[$date_key] = $available;
        }

        /*
         * Si algún día quedó completamente ocupado, agregar el siguiente día hábil
         * para que Claude siempre tenga opciones concretas para ofrecer.
         */
        if ($any_full) {
            /* Avanzar cursor hasta el próximo día hábil (saltar domingos). */
            while ($cursor->dayOfWeek === 0) {
                $cursor->addDay();
            }
            $extra_key = $cursor->format('Y-m-d');

            /* Consultar leads con demo en el día extra. */
            $extra_leads = Lead::whereRaw(
                "DATE(CONVERT_TZ(demo_date, '+00:00', '-03:00')) = ?",
                [$extra_key]
            )
                ->whereNotNull('demo_start_time')
                ->whereNotNull('demo_id')
                ->get(['demo_id', 'demo_date', 'demo_start_time', 'demo_end_time']);

            /* Construir rangos bloqueados por demo para el día extra. */
            $extra_blocked = [];
            foreach ($demos as $demo) {
                $extra_blocked[$demo->id] = [];
            }
            foreach ($extra_leads as $el) {
                $demo_id = (int) $el->demo_id;
                if (! isset($extra_blocked[$demo_id])) {
                    continue;
                }
                if (! preg_match('/(\d{1,2}):(\d{2})/', (string) $el->demo_start_time, $m)) {
                    continue;
                }
                $s = (int) $m[1] * 60 + (int) $m[2];
                if ($el->demo_end_time && preg_match('/(\d{1,2}):(\d{2})/', (string) $el->demo_end_time, $m2)) {
                    $e = (int) $m2[1] * 60 + (int) $m2[2];
                } else {
                    $e = $s + $duracion;
                }
                $extra_blocked[$demo_id][] = [$s - $setup_antes, $e + $gracia_post];
            }

            /* Slots del día extra según día de semana. */
            $extra_all = $cursor->dayOfWeek === 6
                ? ['09:00', '10:00', '11:00']
                : ['09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00'];

            $extra_available = [];
            foreach ($extra_all as $slot) {
                [$sh, $sm] = explode(':', $slot);
                $ss        = (int) $sh * 60 + (int) $sm;
                $se        = $ss + $duracion;
                $free      = false;
                foreach ($demos as $demo) {
                    $db = $extra_blocked[$demo->id] ?? [];
                    $df = true;
                    foreach ($db as [$bs, $be]) {
                        if ($ss < $be && $se > $bs) {
                            $df = false;
                            break;
                        }
                    }
                    if ($df) {
                        $free = true;
                        break;
                    }
                }
                if ($free) {
                    $extra_available[] = $slot;
                }
            }
            $result[$extra_key] = $extra_available;
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

        $placeholders = implode(',', array_fill(0, count($date_strings), '?'));
        $booked_leads = Lead::whereRaw(
            "DATE(CONVERT_TZ(demo_date, '+00:00', '-03:00')) IN ({$placeholders})",
            $date_strings
        )
            ->whereNotNull('demo_start_time')
            ->get(['demo_date', 'demo_start_time']);

        /* Agrupar horarios ocupados por fecha. */
        $occupied_by_date = [];
        foreach ($booked_leads as $booked_lead) {
            $date_key = $booked_lead->demo_date->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d');
            $time_raw = trim((string) $booked_lead->demo_start_time);
            if (preg_match('/(\d{1,2}):(\d{2})/', $time_raw, $m)) {
                $occupied_by_date[$date_key][] = str_pad($m[1], 2, '0', STR_PAD_LEFT).':'.$m[2];
            }
        }

        $result   = [];
        $any_full = false;

        foreach ($working_days as $day) {
            $date_key  = $day->format('Y-m-d');
            $all_slots = $day->dayOfWeek === 6
                ? ['09:00', '10:00', '11:00']
                : ['09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00'];

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
            $extra_leads = Lead::whereRaw(
                "DATE(CONVERT_TZ(demo_date, '+00:00', '-03:00')) = ?",
                [$extra_key]
            )
                ->whereNotNull('demo_start_time')
                ->get(['demo_date', 'demo_start_time']);

            $extra_booked = [];
            foreach ($extra_leads as $el) {
                $time_raw = trim((string) $el->demo_start_time);
                if (preg_match('/(\d{1,2}):(\d{2})/', $time_raw, $m)) {
                    $extra_booked[] = str_pad($m[1], 2, '0', STR_PAD_LEFT).':'.$m[2];
                }
            }

            $extra_all_slots = $cursor->dayOfWeek === 6
                ? ['09:00', '10:00', '11:00']
                : ['09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00'];

            $result[$extra_key] = array_values(array_filter($extra_all_slots, function ($slot) use ($extra_booked) {
                return ! in_array($slot, $extra_booked, true);
            }));
        }

        return $result;
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

        /* Acción: agendar demo si Claude devolvió el objeto con todos los campos requeridos. */
        $agendar_demo = isset($parsed['agendar_demo']) && is_array($parsed['agendar_demo'])
            ? $parsed['agendar_demo']
            : null;
        if ($agendar_demo !== null) {
            /* Extraer campos del objeto agendar_demo. */
            $demo_id    = isset($agendar_demo['demo_id'])         ? (int) $agendar_demo['demo_id']                : null;
            $demo_date  = isset($agendar_demo['demo_date'])        ? trim((string) $agendar_demo['demo_date'])     : '';
            $demo_start = isset($agendar_demo['demo_start_time'])  ? trim((string) $agendar_demo['demo_start_time']) : '';
            $demo_end   = isset($agendar_demo['demo_end_time'])    ? trim((string) $agendar_demo['demo_end_time'])   : '';

            /* Solo persistir si llegaron todos los campos requeridos. */
            if ($demo_id && $demo_date !== '' && $demo_start !== '' && $demo_end !== '') {
                $lead->demo_id         = $demo_id;
                $lead->demo_date       = $demo_date;
                $lead->demo_start_time = $demo_start;
                $lead->demo_end_time   = $demo_end;
                Log::info('LeadAiService: demo agendada vía acción estructurada.', [
                    'lead_id'    => $lead->id,
                    'demo_id'    => $demo_id,
                    'demo_date'  => $demo_date,
                    'demo_start' => $demo_start,
                    'demo_end'   => $demo_end,
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

                \App\Models\AdminTask::create([
                    'created_by_admin_id' => $default_assignee?->id ?? \App\Models\Admin::first()?->id ?? 1,
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
            $sender = (string) $msg->sender;
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


