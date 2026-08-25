<?php

namespace App\Services;

use App\Exceptions\AprobacionEnCursoException;
use App\Exceptions\HorarioYaNoDisponibleException;
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
use App\Helpers\AppTime;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
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
    /** Recursos válidos que Claude puede solicitar via tool. */
    private const PROTOCOLO_RECURSOS = [
        'calificacion', 'posicionamiento', 'precios',
        'demo_agenda', 'demo_ciclo', 'post_demo',
        'reglas', 'referidos',
    ];

    /** Máximo de iteraciones del agentic loop de tools. */
    private const MAX_TOOL_ITERATIONS = 3;

    /**
     * Bloque de instrucciones para la IA sobre un WhatsApp Flow (formulario nativo de Meta,
     * externo a ComercioCity). Es la contraparte del texto que hasta el grupo 186 (prompt 02,
     * 22/7/2026) se guardaba tal cual en `lead_messages.content` (ver prompt 252, 3/7/2026) y
     * el setter veía en el chat como si fuera un mensaje del lead. Ahora en base solo se guarda
     * una nota corta (kind = 'flow', ver WhatsappWebhookController::format_whatsapp_flow_note()),
     * y este texto se reconstruye acá, en build_user_content(), componiéndolo con esa nota
     * corta — así el agente recibe exactamente la misma información y prohibición que antes.
     */
    private const FLOW_NOTE_INSTRUCCION = 'Formulario de WhatsApp Flow de origen externo, no iniciado ni controlado por ComercioCity. '
        . 'NO tomar ninguna acción automática a partir de este mensaje (no guardar_nombre, no guardar_email, no agendar_demo, etc.). '
        . 'Si el lead confirma estos datos por texto en un mensaje normal, se procesan como cualquier otro dato que dé por WhatsApp.';

    /**
     * Restricción explícita para la primera llamada: el agente no puede inventar rangos horarios
     * sin haber recibido el JSON de disponibilidad en una segunda llamada previa.
     * Complementa el protocolo de WhatsApp y evita alucinaciones tipo "tengo de 18 a 20 hs".
     */
    private const PROHIBICION_RANGO_HORARIO_SIN_JSON = <<<'TXT'
⚠️ PROHIBIDO — Nunca anunciar un rango de horario propio sin JSON de disponibilidad:
Cuando el lead pregunta por disponibilidad en términos generales ("la semana que viene por la tarde", "¿podés mañana?", "¿tenés algo el finde?") sin mencionar un día puntual, la única acción válida es devolver solicita_disponibilidad: true con dia_solicitado (vocabulario cerrado: 'manana', 'pasado_manana', un día de semana, un día de semana con sufijo _proximo, o '+N' días — nunca una fecha calculada por vos). NO responder con frases como "tengo disponibilidad de X a Y hs" ni ninguna variante que afirme conocer el horario disponible. Esa información solo puede venir del JSON que el sistema devuelve en la segunda llamada. Si el agente no tiene ese JSON en el contexto actual, no tiene información de disponibilidad.
TXT;

    /**
     * Estados del pipeline que, entre solicitar disponibilidad y terminar la demo, requieren
     * supervisión humana del mensaje ANTES de enviarse (regla de negocio, 1/7/2026, ver
     * apply_parsed_response()). Desde el 2/7/2026 también se usa en
     * requires_agendamiento_verification_gate() para decidir, sin correr ninguna acción,
     * si hay que diferir el paquete completo (mensaje + acciones) hasta la aprobación humana.
     * closer_activo en adelante ya es 100% manual (Tommy), no se incluye acá.
     *
     * @var string[]
     */
    public const ESTADOS_REQUIEREN_SUPERVISION_AGENDAMIENTO = [
        'solicita_disponibilidad',
        'demo_agendada',
        'demo_pendiente_de_ingreso',
        'ingresando_demo',
        'demo_en_curso',
        'demo_pendiente_de_terminar',
    ];

    /**
     * Cantidad de días CORRIDOS (no hábiles) que cubre el JSON de disponibilidad que se le
     * envía a Claude, contados desde mañana. Se eligió una ventana amplia (7 días) para que
     * la fecha que pida el lead esté prácticamente siempre dentro del JSON: antes, con una
     * ventana de 3 días hábiles, un pedido para "el jueves" podía caer fuera del rango y
     * Claude terminaba confirmando un horario que el sistema nunca le ofreció (lead #12,
     * 13/7/2026). El costo en tokens de 7 días de slots es marginal frente al resto del prompt.
     */
    const DIAS_DISPONIBILIDAD = 7;

    /**
     * Ventana en la que un horario que YA ARRANCÓ todavía se puede reagendar solo al próximo slot.
     *
     * Decisión del 25/8/2026: un lead que contesta "dale" a las 23:40 sobre un horario de las 17:05
     * no está aceptando un turno, está contestando tarde. Agendarle a las 23:45 y mandarle el link
     * quema una instancia para alguien que probablemente no entre. Pasada esta ventana va el
     * correctivo, que ahora sí le dice el motivo real y lo deja decidir a él.
     *
     * 🔴 NO convertir esto en una setting: el grupo 330 prohíbe explícitamente agregar settings o
     * banderas de instancia a este flujo (una bandera con estado se filtra a la próxima llamada del
     * mismo request y convierte esto en un bug intermitente imposible de reproducir).
     */
    const REAGENDADO_VENTANA_MINUTOS = 60;

    /**
     * Último minuto del día, en minutos desde medianoche (23:59). Es el techo duro de una ventana
     * extendida: no existe fecha de fin, así que la ventana no puede cruzar la medianoche.
     */
    const FIN_DEL_DIA_MINUTOS = 1439;

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

        /* Primera llamada a Claude para obtener sugerencia base (con soporte de tool use). */
        $system_payload = [
            [
                'type'          => 'text',
                'text'          => $system,
                'cache_control' => ['type' => 'ephemeral'],
            ],
        ];

        $text = $this->run_with_tools($system_payload, $user_content, 1000, $http, $model, $lead);

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
         * PHP resuelve la fecha (prompt 350, lead #12, 13/7/2026). Claude devuelve
         * `dia_solicitado` (vocabulario cerrado, ej. 'jueves', 'manana', '+5') y NUNCA un
         * Y-m-d: resolve_dia_solicitado() lo traduce a fecha concreta con Carbon, sin que
         * el modelo tenga que hacer aritmética de calendario (la causa raíz del bug: Claude
         * calculó "jueves" como dos fechas distintas, ambas incorrectas, en el mismo turno).
         */
        $fecha_solicitada = (string) $this->resolve_dia_solicitado(
            isset($parsed['dia_solicitado']) ? $parsed['dia_solicitado'] : null
        );

        /* Diagnóstico: si Claude devolvió el campo viejo y prohibido `fecha_solicitada` sin
         * `dia_solicitado`, se ignora a propósito (no se usa como fecha) y queda logueado para
         * poder auditar si el prompt operativo sigue sin actualizarse en algún lado. */
        if ($fecha_solicitada === '' && ! empty($parsed['fecha_solicitada'])) {
            Log::channel('disponibilidad')->warning(
                '[DISPONIBILIDAD] Claude devolvió fecha_solicitada (campo prohibido) y no devolvió dia_solicitado. Se ignora la fecha y se usa la ventana por defecto.',
                [
                    'lead_id'          => $lead->id,
                    'fecha_solicitada' => $parsed['fecha_solicitada'],
                ]
            );
        }

        /*
         * FIX (bug real, 2/7/2026 — lead 232 "Pablo") — ELIMINADO (prompt 350, 13/7/2026,
         * lead #12): este fallback tomaba agendar_demo.demo_date (una fecha que puede venir
         * directamente de una alucinación de Claude) y la usaba para definir qué días
         * consultar en la segunda llamada. Eso es exactamente lo que pasó con el lead #12: el
         * servidor terminaba validando la fecha inventada contra una ventana de disponibilidad
         * construida a partir de esa misma fecha inventada. Con la ventana fija de 7 días
         * corridos (prompt 349) el motivo original del fallback — una demo ya acordada que
         * caía fuera de la ventana de 3 días — desaparece: la fecha real de cualquier demo ya
         * acordada está casi siempre dentro de la ventana por defecto.
         */

        /*
         * RED DE SEGURIDAD (hueco #2, 6/7/2026): el protocolo solo registra/pide el email en el
         * tramo final del agendamiento, atado a un slot ya confirmado (agendar_demo presente en el
         * mismo paquete). Si Claude devuelve guardar_email SIN agendar_demo, está coordinando la
         * agenda fuera de la secuencia estructurada (casos leads #3 y #5: pidió el mail antes de
         * confirmar horario, o inventó un horario sin pasar por solicita_disponibilidad). En ese
         * caso no se confía en la respuesta: se frena el mensaje y se deriva a intervención humana
         * para que el closer lo maneje al 100%. Excepción: reagendado (cancelar_demo presente),
         * donde el email ya puede existir y el flujo es distinto.
         */
        $guardar_email_raw = isset($parsed['guardar_email']) ? trim((string) $parsed['guardar_email']) : '';
        $tiene_agendar     = ! empty($parsed['agendar_demo']);
        if ($guardar_email_raw !== '' && ! $tiene_agendar && ! $cancelar_demo_flag) {
            Log::channel('daily')->warning('LeadAiService: guardar_email sin agendar_demo — agenda fuera de secuencia, derivando a intervención humana.', [
                'lead_id'         => $lead->id,
                'estado_sugerido' => $estado_sugerido,
                'lead_status'     => (string) $lead->status,
            ]);
            $parsed['requiere_intervencion_humana'] = true;
            $parsed['motivo_intervencion'] = 'El agente intentó registrar el email sin un horario de demo confirmado (agenda fuera de secuencia). Revisar y coordinar el agendamiento manualmente.';
            /*
             * Frenar EXPLÍCITAMENTE el mensaje de este turno. El bloque existente de
             * requiere_intervencion_humana en apply_parsed_response() crea la AdminTask, notifica
             * y apaga claude_auto_reply (respuestas FUTURAS), pero NO setea requiere_verificacion
             * sobre el mensaje actual. Sin este flag, el mensaje problemático de este mismo turno se
             * auto-enviaría igual. Por eso lo forzamos acá.
             */
            $parsed['requiere_verificacion'] = true;
            /* Neutralizar la acción fuera de secuencia para que no se ejecute al diferir/enviar. */
            $parsed['guardar_email'] = null;
            /* No disparar la segunda llamada de disponibilidad para este paquete: va a intervención. */
            $needs_availability_check = false;
        }

        if ($needs_availability_check) {
            try {
                /* Pasar la fecha solicitada (o null si no viene) para ampliar el rango del JSON. */
                return $this->generate_suggestion_with_availability(
                    $lead,
                    $is_followup,
                    $fecha_solicitada !== '' ? $fecha_solicitada : null,
                    true
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
     * Cuando $specific_date tiene valor (Claude devolvió dia_solicitado en la primera llamada
     * y PHP lo resolvió a Y-m-d con resolve_dia_solicitado()), se amplía el JSON de
     * disponibilidad para cubrir el rango hasta esa fecha.
     *
     * @param Lead        $lead                            Lead con relación `messages` cargada.
     * @param bool        $is_followup                     true si lo disparó el scheduler de inactividad.
     * @param string|null $specific_date                   Fecha objetivo en formato Y-m-d, o null para los 3 días por defecto.
     * @param bool        $came_from_availability_request  true cuando esta llamada nació de solicita_disponibilidad
     *                                                      en la primera llamada (ver FIX hueco #1, 6/7/2026, más abajo).
     *
     * @throws \RuntimeException Si falla la llamada HTTP o el JSON es inválido.
     *
     * @return LeadMessage Mensaje creado con los horarios sugeridos por Claude.
     */
    protected function generate_suggestion_with_availability(Lead $lead, bool $is_followup, ?string $specific_date = null, bool $came_from_availability_request = false): LeadMessage
    {
        /* Dinámica de este lead (grupo 306): decide, y SOLO acá, si la demo usa su franja propia
         * o sigue gobernada por el closer. No leer la setting global en ningún punto de este flujo —
         * la dinámica se resuelve por la columna del lead, igual que en el grupo 293. */
        $usa_experiencia_nueva = $lead->usa_experiencia_demo_nueva();

        /* JSON estructurado por demo para que Claude interprete disponibilidad sin regex.
         * Se pasa $specific_date para ampliar el rango cuando el lead pidió una fecha lejana.
         * El snapshot de Google Calendar se captura en la misma consulta de disponibilidad. */
        $calendar_snapshot    = null;
        /* Config con la que se calculó la grilla de ESTA request (misión 46): de acá sale el número
         * de minutos que el bloque de instrucciones le dice al agente, más abajo. */
        $slot_config          = null;
        /* Hasta dónde se puede extender cada slot (misión 47), calculado por el servidor sobre los
         * mismos rangos bloqueados que la grilla. El agente lo COPIA, nunca lo calcula. */
        $ventanas_extendidas  = null;
        $availability_data    = $this->build_availability_json(self::DIAS_DISPONIBILIDAD, $calendar_snapshot, $specific_date, $lead->id, $usa_experiencia_nueva, null, $slot_config, $ventanas_extendidas);

        /* Minutos que el lead tiene para completar el formulario y mirar el video de introducción.
         *
         * Sale del mismo valor con el que se calculó la grilla de esta request (incluido el
         * override). Si acá se releyera la setting, la frase que el agente le dice al lead y los
         * horarios que el sistema le ofrece podrían discrepar — y la regla dura 2 del protocolo
         * obliga a que mande el JSON, así que el agente quedaría prometiendo una cosa y confirmando
         * otra. Misión 46. */
        $minimo_minutos_desde_ahora = isset($slot_config['demo_minimo_minutos_desde_ahora'])
            ? (int) $slot_config['demo_minimo_minutos_desde_ahora']
            : null;

        /*
         * Ampliar snapshot con demos agendadas, slots enviados a Claude y config del closer
         * para debug completo de disponibilidad (prompt 123).
         */
        $demos_agendadas = Lead::query()
            ->whereNotNull('demo_date')
            ->whereNotNull('demo_start_time')
            ->whereNotNull('demo_id')
            ->where('demo_date', '>=', AppTime::now()->toDateString())
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

        /* Bloque OFERTA PRIMARIA (grupo 306, prompt 03): el sistema ya resolvió el primer momento
         * disponible real — el agente solo lo redacta, no elige entre una lista. Va PRIMERO de todo
         * (antes que CALENDARIO y el JSON): lo primero que lee el modelo pesa más que lo último.
         * Nunca se deja vacío si no hay disponibilidad — un bloque vacío es exactamente el escenario
         * donde el modelo se pone a inventar (§3.22). Solo aplica a la dinámica nueva. */
        $availability_context = '';
        if ($usa_experiencia_nueva) {
            $oferta_primaria = $this->resolve_oferta_primaria($availability_data, $usa_experiencia_nueva);

            if ($oferta_primaria['hay_disponibilidad']) {
                $availability_context .= "OFERTA PRIMARIA (resuelta por el sistema — es LA que tenés que ofrecer, con la hora exacta):"
                    . "\n- Ofrecé ESTE momento: {$oferta_primaria['texto_referencia']} (demo_id {$oferta_primaria['demo_id']})"
                    . "\n- El mensaje TIENE que decir esa hora. No la reemplaces por una franja (\"a la tarde\", \"más tarde\", \"mañana\") ni por una pregunta abierta (\"¿a qué hora te queda cómodo?\").";

                if (! empty($oferta_primaria['oferta_manana']['hay_disponibilidad'])) {
                    $availability_context .= "\n\nGUARDADO PARA EL TURNO SIGUIENTE (NO mencionar en este mensaje):"
                        . "\n- Recién si el lead contesta que en ese horario no puede: {$oferta_primaria['oferta_manana']['texto_referencia']} (demo_id {$oferta_primaria['oferta_manana']['demo_id']})";
                }
            } else {
                $availability_context .= 'OFERTA PRIMARIA: no hay disponibilidad en la ventana consultada. '
                    . 'Pedile al lead qué día le queda cómodo y usá esa fecha para volver a consultar — nunca inventes un horario.';
            }

            $availability_context .= "\n\n";
        }

        /* Bloque CALENDARIO (prompt 350, lead #12, 13/7/2026): tabla de fechas resuelta por PHP,
         * antes del JSON. Es barato de generar y le saca a Claude toda excusa para calcular una
         * fecha por su cuenta — el bug de origen fue Claude razonando "hoy es lunes 13/07/2026,
         * jueves es 15/07/2026" (mal) y después "17/07/2026" (mal otra vez, en el mismo turno). */
        $availability_context .= "CALENDARIO (resuelto por el sistema — NO calcular fechas, leer de acá):\n";
        $availability_context .= $this->build_tabla_fechas();

        $availability_context .= "\n\nDISPONIBILIDAD DE DEMOS (JSON):\n"
            .json_encode($availability_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        /* Disponibilidad ya agrupada en rangos legibles por fecha, para que el agente la OFREZCA
         * como bloques por turno ("de 8 a 9 de la mañana, y de 13 a 16:30 de la tarde") en vez de
         * enumerar todos los slots. El agrupamiento en bloques contiguos lo hace el backend de forma
         * determinista (no el modelo): la disponibilidad puede tener huecos, así que un rango a secas
         * ofrecería horarios inexistentes. El JSON de arriba se sigue usando SOLO para validar el
         * horario que elige el lead y para el demo_id. */
        $disponibilidad_legible = $this->format_availability_readable($availability_data);
        if (! empty($disponibilidad_legible)) {
            /* El encabezado imperativo ("usar ESTO para ofrecer horarios") es correcto para la
             * dinámica actual, que ofrece rangos por turno, y es la causa verificada del mensaje
             * del lead 30 (4/8/2026) en la dinámica nueva, donde la oferta es un único horario.
             * Unificar los dos textos vuelve a romper uno de los dos flujos. */
            $availability_context .= $usa_experiencia_nueva
                ? "\n\nRANGOS DEL DÍA (material de consulta — NO uses esto para la oferta inicial; solo si el lead pide expresamente ver qué opciones hay):"
                : "\n\nDISPONIBILIDAD EN RANGOS LEGIBLES (usar ESTO para ofrecer horarios, NO enumerar los slots del JSON):";
            foreach ($disponibilidad_legible as $date_label => $texto) {
                if ($texto !== '') {
                    $availability_context .= "\n- {$date_label}: {$texto}";
                }
            }
        }

        /* Bloque VENTANA EXTENDIDA (misión 47). Mismo patrón que OFERTA PRIMARIA: el sistema
         * resuelve y el agente redacta. Si el agente calculara el "hasta" y se equivocara, el
         * mensaje ya salió — y encima le habríamos prometido al lead una instancia que no
         * podemos reservar. Solo la dinámica nueva, y solo los slots que admiten ventana. */
        if ($usa_experiencia_nueva && ! empty($ventanas_extendidas)) {
            $lineas_ventana = [];
            foreach ($ventanas_extendidas as $demo_id_ventana => $por_fecha) {
                foreach ($por_fecha as $date_label => $por_slot) {
                    foreach ($por_slot as $slot_inicio => $hasta) {
                        $lineas_ventana[] = "\n- {$date_label}, empezando {$slot_inicio} (demo_id {$demo_id_ventana}): se puede extender hasta {$hasta}";
                    }
                }
            }

            if (! empty($lineas_ventana)) {
                $availability_context .= "\n\nVENTANA EXTENDIDA (resuelta por el sistema — el \"hasta\" de esta lista es el TOPE de cada horario: nunca lo calculás ni lo estirás):";
                $availability_context .= implode('', $lineas_ventana);
                $availability_context .= "\n- Un horario de inicio que no figure en esta lista NO admite ventana extendida: para ese horario solo se puede agendar la demo normal.";
            }
        }

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

        /* Instrucciones para agendar demo usando el JSON de disponibilidad.
         * Dinámica nueva (grupo 306, prompt 03): el agente ya no elige entre una lista, solo
         * redacta la oferta primaria que ya resolvió el sistema — bloque PROPIO, sin tocar el de
         * abajo (los leads de la dinámica actual lo siguen usando tal cual, byte a byte). */
        if ($usa_experiencia_nueva) {
            $availability_context .= "\n\nINSTRUCCIONES PARA AGENDAR:";
            if ($oferta_primaria['hay_disponibilidad']) {
                $availability_context .= "\n- Ofrecé LA OFERTA PRIMARIA nombrando la hora: el mensaje tiene que sonar a \"si querés te la dejo lista para {$oferta_primaria['texto_referencia']} — ¿te sirve?\". Si el lead te dice que a esa hora no puede, recién ahí ofrecés el momento siguiente, otra vez uno solo y con hora.";
                $availability_context .= "\n- PROHIBIDO ofrecer franjas del día. \"A la tarde\", \"a la mañana\", \"más tarde\", \"cuando quieras\" no son ofertas: son la forma vieja de agendar. Siempre una hora concreta copiada del JSON.";
                $availability_context .= "\n- PROHIBIDO devolver la pregunta abierta sin haber ofrecido nada (\"¿a qué hora te queda cómodo?\", \"¿qué día te sirve?\") cuando el bloque OFERTA PRIMARIA trae un momento disponible. Esa pregunta solo vale si el bloque dice que NO hay disponibilidad.";
            } else {
                $availability_context .= "\n- Ofrecé LA OFERTA PRIMARIA de arriba, no una lista de horarios. El mensaje tiene que sonar a \"si querés, hoy mismo te la preparo para que la pruebes — ¿en qué horario te queda cómodo?\".";
            }
            $availability_context .= "\n- PROHIBIDO enumerar rangos de horarios, salvo que el lead pida explícitamente qué opciones hay.";
            $availability_context .= "\n- Si el lead propone un horario concreto, verificalo en el JSON granular de disponibilidad de abajo.";
            $availability_context .= "\n- Si el horario que pidió no está disponible, ofrecé el horario más cercano que sí lo esté — de nuevo, uno solo, no una lista.";
            $availability_context .= "\n- La demo se hace desde una COMPUTADORA. Si el lead está en el teléfono, puede ver la página y el video ahí, pero tiene que avisar a qué hora va a poder sentarse frente a la compu — esa es la hora que se agenda.";
            /* El número NO se escribe a mano: viene del mismo valor que armó la grilla (ver el
             * comentario largo de $minimo_minutos_desde_ahora, más arriba). Hasta la misión 46 acá
             * decía "Mínimo 15 minutos" literal y encima le PROHIBÍA al agente decir "ahora mismo",
             * contradiciendo a agentes/lead/recursos/v2/demo_agenda.md, que le manda decir
             * exactamente eso. Dos fuentes para el mismo invariante, y ganaba la equivocada.
             * Si el valor no llegó (sólo posible por un bug del armado), se omite la línea entera:
             * mejor sin instrucción que con un número inventado. */
            if ($minimo_minutos_desde_ahora !== null) {
                $availability_context .= "\n- Mínimo {$minimo_minutos_desde_ahora} minutos desde ahora: son los que el lead tarda en completar el formulario y "
                    . "mirar el video de introducción, mientras la demo se prepara sola por debajo. Eso NO es una espera "
                    . "antes de entrar: el lead entra a la página en el momento en que le pasás el link. Podés decirle "
                    . "que la puede hacer ahora mismo.";
            }
            $availability_context .= "\n- Si el slot está disponible: confirmalo al lead y devolvé agendar_demo con demo_id, demo_date (formato Y-m-d), demo_start_time (formato HH:MM). NO incluyas demo_end_time; el servidor lo calcula.";
            $availability_context .= "\n- El demo_id debe corresponder a una demo que tenga ese slot disponible en el JSON.";
            /* Ventana extendida (misión 47, franja negociable desde la tarea 62): NO es la oferta
             * por defecto. Aparece solo cuando el lead dice que no se puede comprometer a un
             * horario. El "hasta" del bloque VENTANA EXTENDIDA es el TOPE; si el lead nombra un
             * "hasta" propio más corto ("de 12 a 18"), esa franja se pide con `ventana_hasta` y
             * el SERVIDOR la valida y la escribe — el modelo sigue sin escribir demo_end_time. */
            $availability_context .= "\n- VENTANA EXTENDIDA: no la ofrezcas de entrada. El comportamiento normal sigue siendo un horario de inicio con una hora de duración.";
            $availability_context .= "\n- Solo si el lead te dice que no puede comprometerse a un horario puntual: preguntale A PARTIR DE QUÉ HORA ya estaría disponible y HASTA QUÉ HORA quiere poder probarla. El tope para ese horario es el \"hasta\" que figure en el bloque VENTANA EXTENDIDA: no lo calculás, no lo redondeás y no lo estirás.";
            $availability_context .= "\n- Si el lead nombra su propio \"hasta\" (\"la voy a probar hasta más o menos las seis\"), usá ESA hora como fin de la ventana, siempre que no pase el tope del bloque. Si pide más que el tope, ofrecele hasta el tope y decíselo con naturalidad.";
            $availability_context .= "\n- Cuando ofrezcas la ventana extendida, dejá claro el compromiso sin sonar a reproche: le reservamos la instancia todo ese tiempo para él, y a cambio necesitamos que se tome alrededor de una hora de verdad para recorrerla. La ventana es HASTA CUÁNDO PUEDE ENTRAR, no cuánto dura la demo.";
            $availability_context .= "\n- Para confirmar una ventana extendida devolvé agendar_demo con ventana_extendida: true, además de demo_id, demo_date y demo_start_time. Si el lead nombró su propio \"hasta\", agregá ventana_hasta: \"HH:MM\" con esa hora (copiada de lo que dijo el lead, nunca mayor que el tope del bloque); si no lo nombró, NO mandes ventana_hasta y el servidor reserva hasta el tope. La hora de fin la valida y la escribe siempre el servidor: vos NUNCA mandás demo_end_time, ni en la ventana extendida ni en una demo normal.";
            $availability_context .= "\n- PROHIBIDO calcular una fecha por tu cuenta. No hagas aritmética de calendario nunca. Para saber qué fecha es 'el jueves', 'mañana' o 'el viernes que viene', leé la tabla CALENDARIO de arriba o la clave del JSON de disponibilidad (las claves ya vienen con el nombre del día: \"jueves 2026-07-16\").";
            $availability_context .= "\n- demo_date se COPIA LITERALMENTE de la parte Y-m-d de una clave del JSON de disponibilidad. No se escribe de memoria, no se deduce, no se calcula.";
            $availability_context .= "\n- demo_start_time se COPIA LITERALMENTE de un horario que figure en la lista de slots de ESA demo en ESA fecha. Si el horario que pidió el lead no está en esa lista, NO está disponible — punto. No importa cuán claro haya sido el lead ni cuánto lo haya pedido: no se confirma.";
            $availability_context .= "\n- Si la fecha o el horario que querés confirmar no están en el JSON, NO confirmes nada: informale al lead con naturalidad y ofrecé el más cercano que sí figure — uno solo, no una lista.";
            $availability_context .= "\n- El servidor verifica cada agendar_demo contra los slots que te mandó. Un horario que no salga exactamente de esa lista se descarta y el mensaje no sale — no hay forma de forzarlo.";
            $availability_context .= "\n- DECLARÁ en el campo \"horarios_ofrecidos\" del JSON cada horario o rango que tu mensaje MENCIONA (no lo que está disponible en general, solo lo que el texto ofrece). Un ítem por horario: {\"fecha\": \"Y-m-d\", \"desde\": \"HH:MM\", \"hasta\": \"HH:MM\"} (la oferta primaria es un solo ítem con desde igual a hasta). Si tu mensaje no ofrece ningún horario, mandá un array vacío []. Esta declaración NO es opcional cuando el mensaje ofrece horarios: es la única forma que tiene el sistema de saber qué prometiste sin leer prosa, y lo revalida justo antes de enviar.";
        } else {
            $availability_context .= "\n\nINSTRUCCIONES PARA AGENDAR:";
            $availability_context .= "\n- Analizá el historial de la conversación para determinar qué fecha y hora quiere el lead (puede decir \"hoy\", \"mañana\", \"el jueves\", \"a las 16\", etc.).";
            $availability_context .= "\n- Verificá que ese slot esté disponible en el JSON de arriba para la demo correspondiente.";
            $availability_context .= "\n- Si el slot está disponible: confirmalo al lead y devolvé agendar_demo con demo_id, demo_date (formato Y-m-d), demo_start_time (formato HH:MM). NO incluyas demo_end_time; el servidor lo calcula.";
            $availability_context .= "\n- Si el slot NO está disponible: informale al lead con naturalidad y ofrecé las alternativas más cercanas disponibles.";
            $availability_context .= "\n- El demo_id debe corresponder a una demo que tenga ese slot disponible en el JSON.";
            $availability_context .= "\n- Nunca confirmes un horario que no aparezca en el JSON de disponibilidad.";
            $availability_context .= "\n- Para OFRECER horarios al lead, usá SIEMPRE el texto de 'DISPONIBILIDAD EN RANGOS LEGIBLES' (bloques por turno) — nunca enumeres todos los slots del JSON uno por uno, queda un mensaje larguísimo y robótico. El JSON granular es solo para validar el horario que el lead elige y para el demo_id.";
            $availability_context .= "\n- PROHIBIDO calcular una fecha por tu cuenta. No hagas aritmética de calendario nunca. Para saber qué fecha es 'el jueves', 'mañana' o 'el viernes que viene', leé la tabla CALENDARIO de arriba o la clave del JSON de disponibilidad (las claves ya vienen con el nombre del día: \"jueves 2026-07-16\").";
            $availability_context .= "\n- demo_date se COPIA LITERALMENTE de la parte Y-m-d de una clave del JSON de disponibilidad. No se escribe de memoria, no se deduce, no se calcula.";
            $availability_context .= "\n- demo_start_time se COPIA LITERALMENTE de un horario que figure en la lista de slots de ESA demo en ESA fecha. Si el horario que pidió el lead no está en esa lista, NO está disponible — punto. No importa cuán claro haya sido el lead ni cuánto lo haya pedido: no se confirma.";
            $availability_context .= "\n- Si la fecha o el horario que querés confirmar no están en el JSON, NO confirmes nada: informale al lead con naturalidad y ofrecé las alternativas reales más cercanas que sí figuren.";
            $availability_context .= "\n- El servidor verifica cada agendar_demo contra los slots que te mandó. Un horario que no salga exactamente de esta lista se descarta y el mensaje no sale — no hay forma de forzarlo.";
        }

        if ($lead_proposed_time !== '') {
            $availability_context .= "\n- El lead propuso el horario: \"{$lead_proposed_time}\". Verificá si ese horario aparece en el JSON de disponibilidad.";
        }

        /*
         * Instrucción crítica para la segunda llamada: Claude ya tiene los slots en el JSON.
         * Se reemplaza la prohibición absoluta de solicita_disponibilidad por una regla
         * matizada: solo puede devolverla si el lead pidió una fecha que NO está en el JSON
         * (demasiado lejana), junto con dia_solicitado (vocabulario cerrado) para que el
         * sistema la resuelva y la consulte. Para cualquier fecha que SÍ aparece en el JSON
         * (con o sin slots), debe responder
         * directamente sin volver a pedir disponibilidad.
         */
        $availability_context .= "\n\n⚠️ ATENCIÓN - SEGUNDA LLAMADA: El sistema YA te trajo los horarios disponibles en el JSON de arriba.";
        $availability_context .= "\n- Si la fecha que pidió el lead SÍ aparece en el JSON (con o sin slots): usá esa info. Si tiene slots, ofrecelos. Si aparece SIN slots, significa que no hay disponibilidad ese día: informá al lead y ofrecé alternativas cercanas del JSON. NO vuelvas a pedir disponibilidad para una fecha que ya está en el JSON.";
        $availability_context .= "\n- Si el lead pidió un día que NO aparece en el JSON (más lejano que la ventana que te mandamos): devolvé solicita_disponibilidad: true junto con dia_solicitado. NUNCA una fecha: dia_solicitado acepta solo estos valores — 'manana', 'pasado_manana', un día de semana ('lunes'..'domingo', que el sistema resuelve como la próxima ocurrencia a partir de mañana), un día de semana con sufijo _proximo ('jueves_proximo' = el jueves de la semana siguiente), o '+N' (N días desde hoy, ej. '+10'). El sistema calcula la fecha; vos no.";
        $availability_context .= "\n- Si el lead pidió un día sin especificar hora (ej: 'el sábado') y ese día está en el JSON con slots: ofrecele directamente los horarios disponibles de ese día.";
        /* FIX (correctivo grupo 308/prompt 03, fix A): en la dinámica nueva el email no se pide
         * para hacer la demo -- el acceso es el link de la página inmersiva (ver el bloque que
         * arma build_demo_experiencia_context() en build_user_content()). Pedirlo acá, sin
         * condicionar por dinámica, contradecía de frente a ese bloque. La rama $usa_experiencia_nueva
         * ya existe en este mismo método (línea ~348, grupo 306) y gobierna el resto del bloque
         * INSTRUCCIONES PARA AGENDAR de acá abajo: se reutiliza la misma variable, no se introduce
         * una segunda forma de decidir la dinámica. */
        $availability_context .= $usa_experiencia_nueva
            ? "\n- Si el lead pidió un horario concreto disponible: confirmalo aclarando si es mañana o tarde."
            : "\n- Si el lead pidió un horario concreto disponible: confirmalo aclarando si es mañana o tarde, y pedile el email (Paso 3 del protocolo).";

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

        /* Segunda llamada a Claude con disponibilidad como contexto adicional (con soporte de tool use). */
        $system_payload = [
            [
                'type'          => 'text',
                'text'          => $system,
                'cache_control' => ['type' => 'ephemeral'],
            ],
        ];

        $text = $this->run_with_tools($system_payload, $user_content, 3000, $http, $model, $lead);

        /* Log de diagnóstico: respuesta cruda de Claude en la segunda llamada. */
        Log::debug('LeadAiService [SEGUNDA LLAMADA - con disponibilidad] - respuesta Claude', [
            'lead_id'  => $lead->id,
            'response' => $text,
        ]);

        $parsed = $this->parse_json_response($text);

        /*
         * GUARD DURO (lead #12, 13/7/2026): el payload de Claude no es autoritativo sobre el
         * calendario. Antes de dejar que un agendar_demo siga viaje (y sobre todo antes de que el
         * mensaje que confirma ese horario quede escrito y con countdown de auto-envío), se verifica
         * que la fecha y la hora existan LITERALMENTE en los slots que el servidor le mandó a Claude
         * en esta misma llamada. No se re-consulta el calendario: se compara contra $availability_data,
         * que es exactamente lo que el modelo tuvo delante. Cualquier otra cosa es una alucinación.
         */
        $parsed = $this->descartar_agendamiento_fuera_de_slots($lead, $parsed, $availability_data, is_array($ventanas_extendidas) ? $ventanas_extendidas : []);

        /*
         * COHERENCIA DE FECHA BLOQUEANTE (hueco #4, 6/7/2026 — ahora bloqueante desde el prompt
         * 350, lead #12, 13/7/2026): antes este check solo logueaba. El slot puede ser válido (pasó
         * el guard de arriba) y aun así el TEXTO del mensaje nombrarle al lead un día de semana
         * distinto al que quedó reservado — exactamente el caso del lead #12, cuyo mensaje decía
         * "jueves 17" cuando el 17 de julio de 2026 era viernes. En ese caso el agendamiento se
         * conserva (el slot es real), pero el mensaje se deriva a revisión humana antes de salir. */
        $parsed = $this->verificar_coherencia_dia_mensaje($lead, $parsed);

        /*
         * FIX (hueco #1, 6/7/2026): esta respuesta es la SEGUNDA llamada de la cadena de
         * agendamiento (came_from_availability_request = true). Desde la óptica de Claude la
         * disponibilidad "ya se resolvió", así que devuelve solicita_disponibilidad: false y
         * estado calificado. Pero el mensaje resultante es justamente el que ofrece los horarios
         * al lead y, por regla de negocio, tiene que quedar en el tramo de agenda para que
         * requiera verificación humana (delay 1800s) antes de enviarse. Se fuerza el estado y la
         * verificación acá, salvo que Claude ya haya escalado el estado por su cuenta (ej.
         * demo_agendada tras confirmar el slot, o pidió otra vez disponibilidad porque la fecha
         * cae fuera del JSON). No pisar esos casos: solo elevar cuando el estado sugerido quedó
         * por debajo del tramo (típicamente 'calificado' o vacío).
         */
        if ($came_from_availability_request) {
            $estado_segunda = isset($parsed['estado_sugerido']) ? trim((string) $parsed['estado_sugerido']) : '';
            $ya_pidio_disp  = ! empty($parsed['solicita_disponibilidad']);
            $ya_en_tramo    = false;
            if ($estado_segunda !== '') {
                $ps_segunda  = LeadPipelineStatus::ensure_exists($estado_segunda);
                $ya_en_tramo = in_array($ps_segunda->slug, self::ESTADOS_REQUIEREN_SUPERVISION_AGENDAMIENTO, true);
            }
            if (! $ya_en_tramo && ! $ya_pidio_disp) {
                $parsed['estado_sugerido']       = 'solicita_disponibilidad';
                $parsed['requiere_verificacion'] = true;
                Log::channel('daily')->debug('LeadAiService: mensaje de disponibilidad elevado a solicita_disponibilidad (herencia de tramo).', [
                    'lead_id'         => $lead->id,
                    'estado_original' => $estado_segunda,
                ]);
            }
        }

        /*
         * GUARD (grupo 328, prompt 02): un mensaje que no declara horarios es invisible para la
         * revalidación del grupo 306 (no hay nada que revalidar), así que es exactamente el
         * mensaje mal formado que ningún control mira. Caso de origen: lead 30, 4/8/2026, "puede
         * ser hoy a la tarde o mañana" -- horarios_ofrecidos quedó vacío y el mensaje salió solo.
         * Sin la condición de hay_disponibilidad, esto marcaría también los mensajes legítimos
         * que preguntan qué día le sirve al lead porque no hay slots.
         */
        if ($usa_experiencia_nueva
            && ! empty($oferta_primaria['hay_disponibilidad'])
            && empty($parsed['agendar_demo'])
            && empty($parsed['horarios_ofrecidos'])
        ) {
            $parsed['requiere_verificacion'] = true;
            $parsed['nota_para_setter']       = 'El mensaje no ofrece un horario concreto pese a que el sistema '
                . 'resolvió una oferta primaria disponible (' . $oferta_primaria['texto_referencia'] . '). '
                . 'Revisá antes de enviar.';

            Log::channel('disponibilidad')->warning(
                '[DISPONIBILIDAD] Mensaje sin horarios_ofrecidos con oferta primaria disponible: marcado para revisión.',
                [
                    'lead_id'           => $lead->id,
                    'texto_referencia'  => $oferta_primaria['texto_referencia'],
                    'mensaje_sugerido'  => isset($parsed['mensaje_sugerido']) ? $parsed['mensaje_sugerido'] : '',
                ]
            );
        }

        return $this->create_message_and_update_lead($lead, $parsed, $is_followup, $calendar_snapshot);
    }

    /**
     * Regenera la sugerencia que reemplaza a un mensaje rechazado por horario ofrecido caducado
     * (grupo 330, prompt 02, lead 30 el 4/8/2026). El mensaje que reemplaza a una oferta caducada
     * es OTRO mensaje de oferta: si se regenera por la primera llamada (generate_suggestion(), sin
     * JSON de disponibilidad ni oferta primaria), el modelo queda sin horario y contesta un ack sin
     * agendar nada ni pasar el link ("Dale, Brisa... ahora mismo te lo preparo" -- frase que además
     * el guion prohíbe explícitamente). La regeneración tiene que entrar por el mismo camino que
     * produjo el mensaje original.
     *
     * Envuelve generate_suggestion_with_availability() (protected, no se le cambia la visibilidad
     * ni se duplica su lógica) con came_from_availability_request = true, igual que si el modelo
     * hubiera pedido disponibilidad. Sin fecha específica: la ventana por defecto ya incluye hoy.
     *
     * Fail-safe obligatorio: si la generación con disponibilidad tira excepción, cae a
     * generate_suggestion() (el camino viejo) y lo deja logueado en el canal `disponibilidad` --
     * misma degradación segura que ya usa el resto del flujo. Nunca deja al lead sin ninguna
     * sugerencia.
     *
     * Se llama SOLO desde LeadSuggestionSendService::send_suggestion(), en el bloque de
     * revalidación de horarios caducados (grupo 306, prompt 04) -- esta regeneración no dispara
     * otra revalidación dentro del MISMO request: el bloque de caducidad corre en send_suggestion()
     * (al aprobar/enviar un mensaje), no acá (al generarlo). La sugerencia nueva vuelve a declarar
     * horarios_ofrecidos y va a pasar por SU PROPIA revalidación recién cuando alguien la apruebe a
     * su vez -- son dos aprobaciones distintas, no una recursión.
     *
     * @param Lead $lead        Lead fresco (el llamador pasa $lead->fresh()).
     * @param bool $is_followup true si el mensaje original que caducó era un seguimiento.
     *
     * @return LeadMessage
     */
    public function regenerar_sugerencia_por_horario_caducado(Lead $lead, bool $is_followup): LeadMessage
    {
        try {
            return $this->generate_suggestion_with_availability($lead, $is_followup, null, true);
        } catch (\Throwable $e) {
            Log::channel('disponibilidad')->warning('[DISPONIBILIDAD] Fallo al regenerar sugerencia con disponibilidad fresca tras horario caducado; se genera por el camino viejo.', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);

            return $this->generate_suggestion($lead, $is_followup);
        }
    }

    /**
     * Traduce el `dia_solicitado` que devuelve Claude (vocabulario cerrado, sin fechas) a una
     * fecha Y-m-d concreta, calculada por PHP con Carbon en timezone Argentina.
     *
     * Claude NO calcula fechas. Nunca. En testing (lead #12, 13/7/2026) calculó dos veces mal el
     * día de la semana en el mismo turno ("jueves" → 15/07 y después 17/07, cuando era el 16/07),
     * y sobre esa fecha inventada terminó confirmándole un horario al lead. La aritmética de
     * calendario es determinista y no tiene por qué pasar por un modelo de lenguaje.
     *
     * Vocabulario aceptado (case-insensitive, tolerante a acentos y guiones bajos):
     *   - 'manana' / 'mañana'                 → mañana
     *   - 'pasado_manana' / 'pasado mañana'   → hoy + 2
     *   - 'lunes' .. 'domingo'                → próxima ocurrencia de ese día de semana, contando desde MAÑANA
     *                                           (si hoy es lunes, 'lunes' = el lunes que viene, nunca hoy)
     *   - 'lunes_proximo' .. 'domingo_proximo'→ la ocurrencia SIGUIENTE a la anterior (una semana más)
     *   - '+N' (N entero, 1..60)              → hoy + N días
     *
     * Cualquier otro valor (incluido un Y-m-d, que Claude tiene prohibido emitir) devuelve null:
     * sin fecha específica, la ventana por defecto de 7 días de build_availability_json() ya cubre
     * el caso, y Claude puede volver a pedir disponibilidad en el turno siguiente.
     *
     * @param string|null $dia Valor crudo de `dia_solicitado` en el JSON de Claude.
     *
     * @return string|null Fecha Y-m-d, o null si no se pudo resolver.
     */
    protected function resolve_dia_solicitado(?string $dia): ?string
    {
        if ($dia === null) {
            return null;
        }

        $raw = trim((string) $dia);
        if ($raw === '') {
            return null;
        }

        /* Normalizar: minúsculas, sin acentos, espacios → guion bajo. */
        $norm = mb_strtolower($raw, 'UTF-8');
        $norm = strtr($norm, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u',
        ]);
        $norm = preg_replace('/[\s\-]+/', '_', $norm);
        $norm = trim((string) $norm, '_');

        $now      = AppTime::now()->startOfDay();
        $tomorrow = $now->copy()->addDay();

        if ($norm === 'manana') {
            return $tomorrow->format('Y-m-d');
        }

        if ($norm === 'pasado_manana') {
            return $now->copy()->addDays(2)->format('Y-m-d');
        }

        /* '+N' o 'N' días desde hoy. */
        if (preg_match('/^\+?(\d{1,2})$/', $norm, $m_dias)) {
            $n = (int) $m_dias[1];
            if ($n >= 1 && $n <= 60) {
                return $now->copy()->addDays($n)->format('Y-m-d');
            }

            return null;
        }

        /* Días de semana (convención Carbon: 0=domingo .. 6=sábado). */
        $dias_semana = [
            'domingo'   => 0,
            'lunes'     => 1,
            'martes'    => 2,
            'miercoles' => 3,
            'jueves'    => 4,
            'viernes'   => 5,
            'sabado'    => 6,
        ];

        $es_proximo = false;
        $clave      = $norm;
        if (preg_match('/^(.+)_proximo$/', $norm, $m_prox)) {
            $es_proximo = true;
            $clave      = $m_prox[1];
        }

        if (! isset($dias_semana[$clave])) {
            return null;
        }

        /* Próxima ocurrencia de ese día de semana contando desde MAÑANA (nunca hoy: la regla de
         * negocio es que jamás se agenda para el día en curso). */
        $target = $tomorrow->copy();
        $limite = 0;
        while ($target->dayOfWeek !== $dias_semana[$clave] && $limite < 7) {
            $target->addDay();
            $limite++;
        }

        if ($es_proximo) {
            $target->addWeek();
        }

        return $target->format('Y-m-d');
    }

    /**
     * Descarta un `agendar_demo` cuya fecha u hora no figure EXACTAMENTE entre los slots que el
     * servidor le envió a Claude en esta llamada.
     *
     * Distingue dos fallas, ambas observadas en el lead #12 (13/7/2026):
     *   a) FECHA fuera de la ventana enviada  → Claude inventó un día que ni siquiera vio.
     *   b) HORA no disponible en esa fecha    → Claude ofreció un horario que el sistema descartó
     *                                            (por Google Calendar, otra demo, o el horario del closer).
     *
     * En ambos casos el paquete de agendamiento se descarta entero (agendar_demo + guardar_email,
     * que por protocolo viajan juntos), el estado baja a `solicita_disponibilidad` y el mensaje se
     * reemplaza por una respuesta correctiva con las alternativas reales. Si la llamada correctiva
     * falla, el paquete queda sin mensaje y se deriva a intervención humana: preferimos que Martín
     * escriba a mano antes que mandarle al lead una confirmación de un horario que no existe.
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $parsed            Paquete JSON parseado de Claude.
     * @param array<string, mixed> $availability_data Salida de build_availability_json() de ESTA llamada.
     *
     * @return array<string, mixed> Paquete corregido.
     */
    protected function descartar_agendamiento_fuera_de_slots(Lead $lead, array $parsed, array $availability_data, array $ventanas_extendidas = []): array
    {
        if (empty($parsed['agendar_demo']) || ! is_array($parsed['agendar_demo'])) {
            return $parsed;
        }

        $agendar    = $parsed['agendar_demo'];
        $demo_id    = isset($agendar['demo_id'])         ? (int) $agendar['demo_id']                 : 0;
        $demo_date  = isset($agendar['demo_date'])       ? trim((string) $agendar['demo_date'])      : '';
        $demo_start = isset($agendar['demo_start_time']) ? trim((string) $agendar['demo_start_time']) : '';

        /* Normalizar hora a HH:MM (mismo criterio que apply_parsed_response()). */
        if ($demo_start !== '' && preg_match('/(\d{1,2}):(\d{2})/', $demo_start, $m_hora)) {
            $demo_start = str_pad($m_hora[1], 2, '0', STR_PAD_LEFT) . ':' . $m_hora[2];
        }

        $demos_json = isset($availability_data['demos']) && is_array($availability_data['demos'])
            ? $availability_data['demos']
            : [];

        /* Fechas (Y-m-d) efectivamente enviadas a Claude, y slots de la demo elegida en esa fecha.
         * Las claves del JSON vienen como "jueves 2026-07-16": se extrae el Y-m-d del final. */
        $fechas_enviadas = [];
        $slots_de_esa_demo_y_fecha = [];

        foreach ($demos_json as $demo_id_json => $slots_por_fecha) {
            if (! is_array($slots_por_fecha)) {
                continue;
            }
            foreach ($slots_por_fecha as $date_label => $slots) {
                if (! preg_match('/(\d{4}-\d{2}-\d{2})$/', (string) $date_label, $m_fecha)) {
                    continue;
                }
                $fecha = $m_fecha[1];
                $fechas_enviadas[$fecha] = true;

                if ((int) $demo_id_json === $demo_id && $fecha === $demo_date && is_array($slots)) {
                    $slots_de_esa_demo_y_fecha = array_map('strval', $slots);
                }
            }
        }

        $fecha_en_ventana = ($demo_date !== '' && isset($fechas_enviadas[$demo_date]));
        $hora_disponible  = ($demo_start !== '' && in_array($demo_start, $slots_de_esa_demo_y_fecha, true));

        /* El margen mínimo de anticipación decide qué se puede OFRECER, no si lo ya ofrecido sigue
         * en pie. La oferta primaria es SIEMPRE el primer slot que sobrevive al margen, así que
         * nace pegada al borde: al turno siguiente, cuando el lead acepta, el reloj ya la sacó de
         * la grilla y este guard la lee como "no disponible". Pasó con la lead Brisa el 25/8/2026:
         * 17:05 ofrecido 16:57, aceptado 16:58, y el sistema le contestó "uh, justo se ocupó" con
         * el horario libre.
         *
         * Este rescate sólo aplica si ESE (fecha, hora) figura en un mensaje que YA se le envió a
         * ESTE lead (lead_messages.horarios_ofrecidos); un horario que el lead pide por su cuenta y
         * no da el margen se sigue rechazando.
         *
         * 🔴 No "simplificar" esto pasándole margen 0 a la grilla de arriba: esa grilla es la que
         * el agente usa para OFRECER, y con margen 0 empieza a ofrecer turnos a un minuto vista que
         * la instancia no llega a preparar (el setup son 15 minutos antes). */
        if ($demo_id > 0 && $fecha_en_ventana && ! $hora_disponible) {
            $ventanas_sin_margen = null;
            if ($this->rescate_del_margen_seguro($lead, $demo_id, $demo_date, $demo_start, $ventanas_sin_margen)) {
                $hora_disponible = true;
                /* La ventana extendida del slot rescatado tiene que salir de la MISMA grilla
                 * margen-0: en la grilla con margen ese slot no existe, así que su entrada en
                 * $ventanas_extendidas tampoco. */
                $ventanas_extendidas = is_array($ventanas_sin_margen) ? $ventanas_sin_margen : [];

                Log::channel('disponibilidad')->info(
                    '[DISPONIBILIDAD] Horario ya ofrecido rescatado del margen de anticipación (generación).',
                    [
                        'lead_id'    => $lead->id,
                        'demo_id'    => $demo_id,
                        'demo_date'  => $demo_date,
                        'demo_start' => $demo_start,
                    ]
                );
            }
        }

        /* Ventana extendida (misión 47): el mismo criterio que para el horario. Si el agente la
         * pidió sobre un inicio que el bloque VENTANA EXTENDIDA no ofrecía, es una ventana que el
         * sistema nunca le mostró — y el mensaje que la acompaña ya le prometió al lead una hora
         * de fin. Se descarta el paquete entero, igual que un horario inventado. */
        /* El `usa_experiencia_demo_nueva()` va acá y no solo en el bloque que persiste, para que los
         * DOS traten la misma entrada igual: sin él, un `ventana_extendida: true` alucinado en un
         * lead de la dinámica actual hacía que este guard descartara el paquete entero mientras el
         * otro bloque lo agendaba como demo normal. Uno rechazaba y el otro aceptaba lo mismo. */
        $pidio_ventana     = ! empty($agendar['ventana_extendida']) && $lead->usa_experiencia_demo_nueva();
        $ventana_ofrecida  = true;
        /* `ventana_hasta` (tarea 62): la franja que el agente negoció con el lead ("de 12 a 18").
         * Solo se evalúa junto con `ventana_extendida: true`, y con el MISMO criterio que el
         * horario: si el "hasta" pedido no es una hora legible, no es posterior al inicio o se
         * pasa del tope que el sistema ofreció para ese slot, es una franja que nunca se mostró
         * — y el mensaje ya se la prometió al lead. Se descarta el paquete entero, igual que un
         * horario inventado, y la llamada correctiva reofrece. */
        $ventana_hasta_valida = true;
        if ($pidio_ventana) {
            $hasta_maximo     = $this->buscar_ventana_ofrecida($ventanas_extendidas, $demo_id, $demo_date, $demo_start);
            $ventana_ofrecida = $hasta_maximo !== null;

            /* isset y no array_key_exists: un `ventana_hasta: null` explícito se trata como
             * ausente (cae al tope automático), no como franja inválida. */
            if ($ventana_ofrecida && isset($agendar['ventana_hasta'])) {
                $ventana_hasta_valida = $this->normalizar_ventana_hasta($agendar['ventana_hasta'], $demo_start, $hasta_maximo) !== null;
            }
        }

        /* 🔴 Reagendado automático al próximo slot (misión reagendado-al-proximo-slot, 25/8/2026).
         *
         * Cuando el horario que el lead ACEPTA ya arrancó, el rescate del margen de acá arriba no
         * puede salvarlo: la grilla margen-0 sigue descartando lo que empezó
         * (compute_day_slots_for_demo(): `$is_today && $slot_start < $now_minutes`). Hasta el
         * 25/8/2026 acá se le devolvía la pelota al lead ("¿cuál te sirve?"), que es la peor
         * respuesta posible para alguien que acaba de decir que sí. Ahora se le corre el turno al
         * próximo slot del día y se le CONFIRMA, con el motivo real y el link.
         *
         * 🔴 Va acá, en GENERACIÓN, y NO en la aprobación: reagendar es cambiar la acción Y el texto
         * a la vez, y eso sólo se puede hacer mientras el mensaje es un borrador que nadie firmó.
         * Después de la aprobación, pisar el texto lo manda al lead con el nombre de un admin que
         * nunca lo leyó (clase registrada el 25/8/2026).
         *
         * Va DESPUÉS de evaluar la ventana extendida porque necesita saber si el paquete la pidió
         * (un paquete con ventana extendida no se reagenda: la franja ya se le prometió al lead con
         * un inicio concreto), y ANTES del `if` de éxito de abajo para que ese `if` vea el paquete
         * ya corregido y lo devuelva por el camino de éxito, sin tocar el bloque de descarte. */
        if (! $hora_disponible) {
            $reagendado = $this->reagendar_al_proximo_slot(
                $lead,
                $parsed,
                $availability_data,
                $demo_id,
                $demo_date,
                $demo_start,
                $fecha_en_ventana,
                $pidio_ventana
            );

            if ($reagendado !== null) {
                $parsed          = $reagendado;
                $hora_disponible = true;
            }
        }

        if ($demo_id > 0 && $fecha_en_ventana && $hora_disponible && $ventana_ofrecida && $ventana_hasta_valida) {
            /* Todo en orden: la fecha y la hora salen de los slots que le mandamos nosotros. */
            return $parsed;
        }

        $motivo = ! $fecha_en_ventana
            ? 'fecha fuera de la ventana enviada a Claude'
            : (! $hora_disponible
                ? 'horario no disponible en esa fecha'
                : (! $ventana_ofrecida
                    ? 'ventana extendida sobre un horario que no la admite'
                    : (! $ventana_hasta_valida ? 'ventana_hasta fuera de la franja ofrecida (otro día o pasado el tope)' : 'demo_id inválido')));

        Log::channel('disponibilidad')->error(
            '[DISPONIBILIDAD] agendar_demo DESCARTADO: ' . $motivo . '. Claude confirmó un slot que el sistema nunca le ofreció.',
            [
                'lead_id'                   => $lead->id,
                'demo_id'                   => $demo_id,
                'demo_date'                 => $demo_date,
                'demo_start_time'           => $demo_start,
                'fechas_enviadas'           => array_keys($fechas_enviadas),
                'slots_de_esa_demo_y_fecha' => $slots_de_esa_demo_y_fecha,
                'mensaje_sugerido_original' => isset($parsed['mensaje_sugerido']) ? $parsed['mensaje_sugerido'] : '',
                'razonamiento_original'     => isset($parsed['razonamiento']) ? $parsed['razonamiento'] : '',
            ]
        );

        /* El paquete de agendamiento se cae entero. guardar_email va atado a agendar_demo por
         * protocolo (regla 4 del recurso demo_agenda): si no hay slot, no hay email que guardar. */
        $parsed['agendar_demo']  = null;
        $parsed['guardar_email'] = null;

        /* Mensaje correctivo con las alternativas reales (misma llamada aislada que ya se usa
         * cuando el slot se ocupa entre la sugerencia y la aprobación).
         *
         * El motivo real viaja SIEMPRE: sin él el modelo lo inventa (ver el comentario en
         * call_corrective_availability_response()). El clasificador ya distingue "fecha fuera de la
         * ventana" y "la franja extendida no entra" de los motivos de hora, igual que $motivo. */
        $mensaje_correctivo = $this->call_corrective_availability_response(
            $lead,
            $demo_start,
            $demo_date,
            $slots_de_esa_demo_y_fecha,
            $this->motivo_real_del_horario_descartado(
                $lead,
                $demo_date,
                $demo_start,
                $slots_de_esa_demo_y_fecha,
                $fecha_en_ventana,
                (! $ventana_ofrecida || ! $ventana_hasta_valida)
            )
        );

        if ($mensaje_correctivo !== '') {
            $parsed['mensaje_sugerido'] = $mensaje_correctivo;
        } else {
            $parsed['mensaje_sugerido']             = '';
            $parsed['requiere_intervencion_humana'] = true;
        }

        $parsed['estado_sugerido']       = 'solicita_disponibilidad';
        $parsed['requiere_verificacion'] = true;
        $parsed['nota_para_setter']      = 'El sistema descartó un agendamiento que la IA confirmó sin respaldo ('
            . $motivo . ': ' . ($demo_date !== '' ? $demo_date : 'sin fecha') . ' ' . ($demo_start !== '' ? $demo_start : 'sin hora')
            . '). Revisá el horario con el lead antes de enviar.';

        return $parsed;
    }

    /**
     * Reagenda el paquete al próximo slot del día cuando el horario que el lead aceptó YA ARRANCÓ.
     *
     * Devuelve el paquete corregido si el reagendado prosperó, o null si no se reagenda (y entonces
     * el llamador sigue por el camino de descarte de siempre, que ahora dice el motivo real).
     *
     * 🔴 El orden es PRIMERO EL TEXTO Y DESPUÉS LA MUTACIÓN, y no es un detalle de estilo: si la
     * llamada al modelo falla, se sale sin haber tocado nada y no queda estado a medias que
     * revertir. Tampoco se inventa un texto fijo de PHP en la voz del agente.
     *
     * Las seis condiciones van de barata a cara, igual que en oferta_vigente_sin_margen(): la
     * consulta a base (la del permiso) va última.
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $parsed            Paquete del modelo, ya con agendar_demo array.
     * @param array<string, mixed> $availability_data Grilla de ESTA request (la que vio el modelo).
     * @param int                  $demo_id           Instancia que pidió el modelo.
     * @param string               $demo_date         Fecha pedida, en Y-m-d.
     * @param string               $demo_start        Horario pedido y ya normalizado, en HH:MM.
     * @param bool                 $fecha_en_ventana  Si la fecha estaba en la ventana consultada.
     * @param bool                 $pidio_ventana     Si el paquete pidió ventana extendida.
     *
     * @return array<string, mixed>|null Paquete corregido, o null si no se reagenda.
     */
    protected function reagendar_al_proximo_slot(Lead $lead, array $parsed, array $availability_data, int $demo_id, string $demo_date, string $demo_start, bool $fecha_en_ventana, bool $pidio_ventana): ?array
    {
        /* 1. Dinámica nueva y nada más. En la actual el margen es 30 hardcodeado y el protocolo es
         *    otro (ofrece listas, no un momento): reagendar ahí cambiaría un flujo que no pidió
         *    nadie. Mismo gate que el rescate del margen. */
        if (! $lead->usa_experiencia_demo_nueva()) {
            return null;
        }

        /* 2. El descarte tiene que ser POR LA HORA: si falló la fecha o el demo_id, correr el turno
         *    no arregla nada y taparía un error distinto. */
        if ($demo_id <= 0 || ! $fecha_en_ventana || $demo_date === '' || $demo_start === '') {
            return null;
        }

        /* 3. El reagendado es siempre DENTRO DE HOY. Correrle el turno a otro día es cambiarle el
         *    día al lead, no correrle el horario. */
        if ($demo_date !== AppTime::now()->format('Y-m-d')) {
            return null;
        }

        /* 4. Ya arrancó, y hace poco. Si todavía no arrancó, el caso es del RESCATE DEL MARGEN de
         *    acá arriba y no se toca. Y pasada la ventana, un "dale" a las 23:40 sobre un horario
         *    de las 17:05 no es aceptar un turno: es contestar tarde (ver
         *    REAGENDADO_VENTANA_MINUTOS). */
        $slot_min = $this->hhmm_a_minutos($demo_start);
        if ($slot_min === null) {
            return null;
        }
        $now_min = (int) AppTime::now()->format('H') * 60 + (int) AppTime::now()->format('i');
        if ($now_min <= $slot_min || ($now_min - $slot_min) > self::REAGENDADO_VENTANA_MINUTOS) {
            return null;
        }

        /* 5. Ventana extendida: NO se reagenda. La franja es un trato negociado con el lead ("te la
         *    dejo de 20 a 23:59") y moverle el inicio cambia el trato que el mensaje ya describía;
         *    el protocolo v2 prohíbe recortarla en silencio. Además es incompatible por
         *    construcción: la ventana extendida es del lead que NO se compromete a un horario
         *    concreto, o sea exactamente el que no cae en este caso. */
        if ($pidio_ventana) {
            return null;
        }

        /* 6. El permiso de siempre, y último porque es el único que toca la base: sólo se reagenda
         *    un horario que le ofrecimos NOSOTROS en un mensaje realmente enviado. Un horario
         *    pasado que el lead inventó se sigue descartando como hasta hoy. */
        if (! LeadMessage::horario_figura_como_ofrecido((int) $lead->id, $demo_date, $demo_start)) {
            return null;
        }

        $slot_nuevo = $this->proximo_slot_del_dia($availability_data, $demo_date, $slot_min, $demo_id);
        if ($slot_nuevo === null) {
            /* No queda nada hoy: cae al correctivo, que ahora dice el motivo real. */
            return null;
        }

        /* 🔴 Primero el texto. Si el modelo no lo redacta, no se reagenda y el paquete queda
         * intacto: no hay mutación que revertir. */
        $texto = $this->call_reagendado_al_proximo_slot_response($lead, $demo_start, $slot_nuevo['hora'], $demo_date);
        if ($texto === '') {
            return null;
        }

        $parsed['agendar_demo']['demo_start_time'] = $slot_nuevo['hora'];
        $parsed['agendar_demo']['demo_id']         = $slot_nuevo['demo_id'];
        /* demo_date NO se toca: el reagendado es siempre dentro de hoy. */

        /* 🔴 `reagendado_desde`: el permiso que viaja hasta la aprobación. El permiso para saltar
         * el margen es "este horario se lo ofrecimos nosotros" y vive en
         * `lead_messages.horarios_ofrecidos` de un mensaje ENVIADO. El slot nuevo no está en
         * ninguno: lo eligió PHP hace un minuto y el mensaje todavía es `sugerido`. Por eso lo que
         * viaja no es un permiso, es el horario VIEJO: en la aprobación se consulta
         * `horario_figura_como_ofrecido()` con ÉL, o sea el mismo criterio de siempre y contra la
         * misma base. Un modelo que invente esta clave no se auto-otorga nada. Sin esto, el
         * reagendado se frena solo en los 5 minutos previos al slot nuevo — que es justo cuando el
         * admin aprueba. */
        $parsed['agendar_demo']['reagendado_desde'] = $demo_start;

        /* El texto nuevo pisa al del modelo, que decía "te confirmo las 17:05". Dejarlo sería la
         * misma falsificación que cerró la misión anterior, en versión nueva. */
        $parsed['mensaje_sugerido'] = $texto;

        /* 🔴 Esto NO es cosmético y no se puede sacar. LeadSuggestionSendService::send_suggestion()
         * revalida `horarios_ofrecidos` con margen 0 ANTES de aplicar las acciones: si acá quedara
         * el horario viejo (el que arrancó), ese bloque lo marcaría caducado y TUMBARÍA el mensaje
         * reagendado antes de enviarlo, regenerando otra sugerencia. Reescribirlo hace además que
         * esa revalidación proteja el horario que el texto realmente promete: si el slot nuevo se
         * pasa mientras espera aprobación, lo agarra ahí y el lead recibe una oferta fresca. */
        $parsed['horarios_ofrecidos'] = [
            [
                'fecha' => $demo_date,
                'desde' => $slot_nuevo['hora'],
                'hasta' => $slot_nuevo['hora'],
            ],
        ];

        /* El razonamiento se ANEXA, no se pisa: el del modelo explica por qué aceptó, y sin esta
         * línea el `ai_reasoning` del panel seguiría diciendo "confirmo las 17:05" arriba de un
         * texto que dice 17:15. */
        $razonamiento_original = isset($parsed['razonamiento']) ? trim((string) $parsed['razonamiento']) : '';
        $linea_sistema         = '[sistema] El horario ofrecido (' . $demo_start . ') ya había arrancado cuando el lead aceptó: '
            . 'se reagendó automáticamente al próximo slot disponible (' . $slot_nuevo['hora'] . ') y el mensaje se reescribió para confirmarlo.';
        $parsed['razonamiento'] = $razonamiento_original !== ''
            ? $razonamiento_original . "\n" . $linea_sistema
            : $linea_sistema;

        /* El admin tiene que poder ver por qué el texto no es el que pidió el modelo. */
        $parsed['nota_para_setter'] = 'El sistema reagendó solo: el horario que el lead aceptó ('
            . $demo_start . ' del ' . $demo_date . ') ya había arrancado, así que se le corrió el turno a las '
            . $slot_nuevo['hora'] . ' y el mensaje se reescribió para confirmárselo con el motivo real. Revisá el texto antes de enviar.';

        /* estado_sugerido y guardar_email NO se tocan: el paquete no se cae, sigue siendo una demo
         * agendada y la regla 4 del protocolo (email atado a agendar_demo) se cumple sola. */

        Log::channel('disponibilidad')->info(
            '[DISPONIBILIDAD] Horario ya arrancado: se reagendó automáticamente al próximo slot del día (generación).',
            [
                'lead_id'          => $lead->id,
                'demo_date'        => $demo_date,
                'slot_que_arranco' => $demo_start,
                'slot_nuevo'       => $slot_nuevo['hora'],
                'demo_id_anterior' => $demo_id,
                'demo_id_nuevo'    => $slot_nuevo['demo_id'],
                'minutos_tarde'    => $now_min - $slot_min,
            ]
        );

        return $parsed;
    }

    /**
     * Elige el próximo slot de HOY, estrictamente posterior al horario que arrancó.
     *
     * 🔴 Sale de $availability_data —la MISMA grilla que el modelo tuvo delante en esta request— y
     * se elige con primer_slot_disponible(), que es el método que ya resuelve la oferta primaria.
     * NO recalcular una grilla fresca acá: serían dos verdades sobre "cuál es el primer slot" en el
     * mismo request (la clase registrada "el mismo invariante decidido con dos criterios
     * distintos"), y además cuesta ~365 queries / ~475 ms por demo en el camino caliente, para
     * obtener lo mismo. El desfasaje de los segundos que tardó la llamada al modelo lo cubre la
     * revalidación de la aprobación.
     *
     * `exclude_lead_id` no hay que tocarlo: la grilla de origen ya se armó con $lead->id, así que
     * el propio lead no se bloquea a sí mismo y los demás sí.
     *
     * @param array<string, mixed> $availability_data Grilla de esta request.
     * @param string               $demo_date         Fecha, en Y-m-d (hoy).
     * @param int                  $slot_min          Horario que arrancó, en minutos del día.
     * @param int                  $demo_id_original  Instancia que traía el lead.
     *
     * @return array{fecha: string, dia_label: string, hora: string, demo_id: int}|null
     */
    private function proximo_slot_del_dia(array $availability_data, string $demo_date, int $slot_min, int $demo_id_original): ?array
    {
        $demos_json = isset($availability_data['demos']) && is_array($availability_data['demos'])
            ? $availability_data['demos']
            : [];

        /* Recorte: sólo la fecha $demo_date y sólo los slots ESTRICTAMENTE POSTERIORES al horario
         * que arrancó. Después ese recorte se le entrega al mismo primer_slot_disponible() que
         * resuelve la oferta primaria, sin lógica nueva de disponibilidad. */
        $recorte_todas    = [];
        $recorte_original = [];

        foreach ($demos_json as $demo_id_json => $slots_por_fecha) {
            if (! is_array($slots_por_fecha)) {
                continue;
            }

            foreach ($slots_por_fecha as $date_label => $slots) {
                if (! is_array($slots) || ! preg_match('/(\d{4}-\d{2}-\d{2})$/', (string) $date_label, $m_fecha) || $m_fecha[1] !== $demo_date) {
                    continue;
                }

                $posteriores = [];
                foreach ($slots as $slot) {
                    $min = $this->hhmm_a_minutos((string) $slot);
                    if ($min !== null && $min > $slot_min) {
                        $posteriores[] = (string) $slot;
                    }
                }

                if (empty($posteriores)) {
                    continue;
                }

                $recorte_todas[$demo_id_json][$date_label] = $posteriores;
                if ((int) $demo_id_json === $demo_id_original) {
                    $recorte_original[$demo_id_json][$date_label] = $posteriores;
                }
            }
        }

        $mejor_todas = $this->primer_slot_disponible($recorte_todas, null);
        if ($mejor_todas === null) {
            return null;
        }

        /* El demo_id PUEDE cambiar y no pasa nada: el link de la experiencia sale del `uuid` del
         * LEAD (Lead::getDemoExperienciaUrlAttribute()), no de la instancia, y el lead nunca ve el
         * demo_id — es una instancia física del pool, y el protocolo v2 dice explícito que "la
         * instancia dejó de ser un recurso escaso". Se toma el más temprano, porque el objetivo es
         * el corrimiento MÍNIMO: quedarse pegado a la instancia original cuando otra tiene un slot
         * diez minutos antes le cuesta esos diez minutos al lead sin comprar nada. Y sólo ante
         * EMPATE de horario se queda en la original, para no mover la instancia porque sí (ruido en
         * los logs y en el panel). */
        $mejor_original = $this->primer_slot_disponible($recorte_original, null);
        if ($mejor_original !== null && $mejor_original['hora'] === $mejor_todas['hora']) {
            return $mejor_original;
        }

        return $mejor_todas;
    }

    /**
     * Busca el "hasta" que el sistema ofreció para un (demo, fecha, horario de inicio), o null si
     * ese horario no admitía ventana extendida (misión 47).
     *
     * Las claves de fecha del mapa vienen con el nombre del día pegado adelante ("jueves
     * 2026-08-20"), igual que en el JSON de disponibilidad, así que se compara por el sufijo Y-m-d
     * — mismo criterio que ya usa el resto del archivo.
     *
     * @param array<int, array<string, array<string, string>>> $ventanas_extendidas
     * @param int    $demo_id
     * @param string $demo_date  Y-m-d.
     * @param string $demo_start HH:MM.
     *
     * @return string|null
     */
    protected function buscar_ventana_ofrecida(array $ventanas_extendidas, int $demo_id, string $demo_date, string $demo_start)
    {
        if ($demo_id <= 0 || $demo_date === '' || $demo_start === '') {
            return null;
        }

        $por_fecha = isset($ventanas_extendidas[$demo_id]) ? $ventanas_extendidas[$demo_id] : [];
        if (! is_array($por_fecha)) {
            return null;
        }

        foreach ($por_fecha as $date_label => $por_slot) {
            $label = (string) $date_label;
            $coincide = ($label === $demo_date)
                || (strlen($demo_date) <= strlen($label) && substr($label, -strlen($demo_date)) === $demo_date);

            if (! $coincide || ! is_array($por_slot)) {
                continue;
            }

            if (isset($por_slot[$demo_start])) {
                return (string) $por_slot[$demo_start];
            }
        }

        return null;
    }

    /**
     * Normaliza y valida el `ventana_hasta` que negoció el agente (tarea 62): la hora hasta la
     * que el lead pidió tener reservada la ventana extendida ("de 12 a 18" → "18:00").
     *
     * 🔴 El modelo sigue sin escribir `demo_end_time`: este valor es un PEDIDO que el servidor
     * valida contra el tope que él mismo resolvió con calcular_fin_ventana_extendida() — que ya
     * trae los clamps de la misión 47 (máximo de horas de la setting, corte a las 23:59 y franja
     * libre en la grilla). Un "hasta" menor que ese tope siempre deja franja libre por inclusión,
     * así que validar `inicio < hasta <= tope` es validar los tres clamps a la vez.
     *
     * Devuelve null (inválido) cuando:
     * - no parsea como hora del día (un valor con fecha o "02:00 del día siguiente" no existe acá:
     *   la demo nunca cruza de día, decisión de Lucas 13/8/2026),
     * - no es estrictamente posterior al inicio (cruzar la medianoche cae en este caso), o
     * - se pasa del tope ofrecido para ese slot.
     *
     * @param mixed  $crudo       Lo que mandó el modelo en `ventana_hasta`.
     * @param string $slot_inicio Inicio del slot en HH:MM.
     * @param string $tope        Tope resuelto por el servidor para ese slot (HH:MM).
     *
     * @return string|null "HH:MM" normalizado, o null si el pedido no entra en lo ofrecido.
     */
    protected function normalizar_ventana_hasta($crudo, string $slot_inicio, string $tope)
    {
        /* Estricto a propósito, sin la regex tolerante del resto del archivo: acá un "resto" que
         * acompañe a la hora (una fecha adelante, un "del día siguiente" atrás) cambia el
         * significado, no es ruido de formato. */
        if (! is_string($crudo) && ! is_numeric($crudo)) {
            return null;
        }
        if (! preg_match('/^\s*([01]?\d|2[0-3]):([0-5]\d)\s*$/', (string) $crudo, $m)) {
            return null;
        }

        $hasta_min = (int) $m[1] * 60 + (int) $m[2];

        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $slot_inicio, $mi)
            || ! preg_match('/^(\d{1,2}):(\d{2})$/', $tope, $mt)) {
            return null;
        }
        $inicio_min = (int) $mi[1] * 60 + (int) $mi[2];
        $tope_min   = (int) $mt[1] * 60 + (int) $mt[2];

        if ($hasta_min <= $inicio_min || $hasta_min > $tope_min) {
            return null;
        }

        return sprintf('%02d:%02d', intdiv($hasta_min, 60), $hasta_min % 60);
    }

    /**
     * Verifica que el día de semana mencionado en el texto del mensaje coincida con el
     * `demo_date` que efectivamente quedó confirmado (el que ya pasó el guard de
     * descartar_agendamiento_fuera_de_slots(), o sea: un slot real).
     *
     * Antes (hueco #4, 6/7/2026) este check solo logueaba. Pasa a ser bloqueante desde el
     * prompt 350 (lead #12, 13/7/2026): el slot puede ser válido y el TEXTO igual nombrarle
     * al lead un día de semana distinto ("jueves 17" cuando el 17 de julio de 2026 era
     * viernes). En ese caso no se descarta el agendamiento (el slot es real, no hay nada que
     * corregir del lado del calendario), pero el mensaje no puede salir solo: se deriva a
     * revisión humana antes de enviarse.
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $parsed Paquete ya procesado por descartar_agendamiento_fuera_de_slots().
     *
     * @return array<string, mixed> Paquete, con banderas de verificación si hay discrepancia.
     */
    protected function verificar_coherencia_dia_mensaje(Lead $lead, array $parsed): array
    {
        /* Si el agendamiento no sobrevivió al guard anterior, no hay nada que verificar acá. */
        if (empty($parsed['agendar_demo']) || ! is_array($parsed['agendar_demo'])) {
            return $parsed;
        }

        $demo_date = isset($parsed['agendar_demo']['demo_date'])
            ? trim((string) $parsed['agendar_demo']['demo_date'])
            : '';

        if ($demo_date === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $demo_date)) {
            return $parsed;
        }

        $mensaje = isset($parsed['mensaje_sugerido']) ? (string) $parsed['mensaje_sugerido'] : '';
        if (trim($mensaje) === '') {
            return $parsed;
        }

        /* Nombre del día (en español) que corresponde a demo_date, calculado por PHP con Carbon. */
        $dias_nombre  = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $fecha_carbon = Carbon::createFromFormat('Y-m-d', $demo_date);
        $dia_correcto = $dias_nombre[$fecha_carbon->dayOfWeek];

        /* Patrones case-insensitive, tolerantes a la falta de tilde en "miércoles"/"sábado". */
        $patrones = [
            'domingo'   => '/domingo/i',
            'lunes'     => '/lunes/i',
            'martes'    => '/martes/i',
            'miércoles' => '/mi[eé]rcoles/i',
            'jueves'    => '/jueves/i',
            'viernes'   => '/viernes/i',
            'sábado'    => '/s[aá]bado/i',
        ];

        /* Buscar todos los días de semana mencionados en el mensaje (puede haber cero, uno o varios). */
        $dias_mencionados = [];
        foreach ($patrones as $nombre => $patron) {
            if (preg_match($patron, $mensaje)) {
                $dias_mencionados[] = $nombre;
            }
        }

        if (count($dias_mencionados) === 0) {
            /* No menciona ningún día de semana: no hay ambigüedad que verificar. */
            return $parsed;
        }

        if (count($dias_mencionados) === 1 && $dias_mencionados[0] === $dia_correcto) {
            /* Menciona exactamente un día y coincide con la fecha confirmada: OK. */
            return $parsed;
        }

        /* Discrepancia (o más de un día mencionado): el slot es válido, pero el texto puede
         * confundir al lead sobre qué día quedó reservado. Se conserva el agendamiento y se
         * deriva el mensaje a revisión humana antes de enviarlo. */
        Log::channel('disponibilidad')->error(
            '[DISPONIBILIDAD] Discrepancia entre el día mencionado en el mensaje y demo_date confirmado.',
            [
                'lead_id'          => $lead->id,
                'demo_date'        => $demo_date,
                'dia_correcto'     => $dia_correcto,
                'dias_mencionados' => $dias_mencionados,
                'mensaje_sugerido' => $mensaje,
            ]
        );

        $parsed['requiere_verificacion']        = true;
        $parsed['requiere_intervencion_humana'] = true;
        $parsed['nota_para_setter']             = 'El mensaje menciona un día de semana que no coincide con la fecha confirmada ('
            . $dia_correcto . ' ' . $demo_date . '). Revisá el texto antes de enviarlo.';

        return $parsed;
    }

    /**
     * Arma la tabla de fechas resueltas por PHP (hoy + próximos 10 días corridos), con el
     * nombre del día en español, para inyectarla al principio del contexto de disponibilidad.
     *
     * Es barato de generar y le saca a Claude toda excusa para calcular una fecha por su
     * cuenta (ver PROHIBIDO calcular fechas en generate_suggestion_with_availability()).
     *
     * @return string Tabla en texto plano, una línea por día.
     */
    protected function build_tabla_fechas(): string
    {
        /* Nombres de día en español, mismo orden que usa Carbon (0=domingo..6=sábado). */
        $dias_nombre = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $hoy         = AppTime::now()->startOfDay();

        $lineas   = [];
        $lineas[] = 'hoy: ' . $dias_nombre[$hoy->dayOfWeek] . ' ' . $hoy->format('Y-m-d')
            . ' (NO agendable — nunca se agenda para el día en curso)';

        /* Próximos 10 días corridos a partir de mañana. */
        for ($i = 1; $i <= 10; $i++) {
            $dia        = $hoy->copy()->addDays($i);
            $lineas[]   = $dias_nombre[$dia->dayOfWeek] . ' ' . $dia->format('Y-m-d');
        }

        return implode("\n", $lineas);
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
     * @param int         $days_ahead        Cantidad de días CORRIDOS (no hábiles) a incluir desde mañana
     *                                       (default: self::DIAS_DISPONIBILIDAD = 7). $specific_date puede
     *                                       ampliar este rango, nunca recortarlo.
     * @param array|null  $calendar_snapshot Referencia opcional para recibir el snapshot de Google Calendar.
     * @param string|null $specific_date     Fecha objetivo en formato Y-m-d, o null para comportamiento por defecto.
     * @param bool        $usa_experiencia_nueva Si true, la demo usa su franja propia y el closer no
     *                                       la gobierna (grupo 306). Default false para no romper
     *                                       ningún caller existente.
     * @param int|null    $margen_hoy_override Reemplaza el margen mínimo de anticipación SOLO para
     *                                       esta llamada (grupo 330, prompt 01). null = usa la
     *                                       setting configurada, igual que siempre.
     * @param array|null  $slot_config       Referencia opcional para recibir la config con la que se
     *                                       calculó ESTA grilla (misión 46). Se expone por referencia
     *                                       y no dentro del array de retorno a propósito: ese array
     *                                       se serializa entero al prompt del agente, así que una
     *                                       clave nueva ahí le cambiaría el JSON que lee el modelo.
     *                                       Mismo patrón que $calendar_snapshot.
     * @param array|null  $ventanas_extendidas Referencia opcional para recibir, por demo y por
     *                                       fecha, hasta qué hora se puede extender cada slot de
     *                                       inicio (misión 47). Estructura:
     *                                       [demo_id][date_label][slot_inicio] => "HH:MM".
     *                                       Solo se llena para la dinámica nueva. Fuera del array
     *                                       de retorno por el mismo motivo que $slot_config: ese
     *                                       array se serializa entero al prompt del agente.
     *
     * @return array<string, mixed> Estructura: hoy, duration_demo_minutos, demos.
     */
    public function build_availability_json(int $days_ahead = self::DIAS_DISPONIBILIDAD, &$calendar_snapshot = null, ?string $specific_date = null, ?int $exclude_lead_id = null, bool $usa_experiencia_nueva = false, ?int $margen_hoy_override = null, &$slot_config = null, &$ventanas_extendidas = null): array
    {
        /* Contexto compartido: días hábiles, rangos bloqueados y parámetros de demo.
         * Se pasa $specific_date para que, si el lead pidió una fecha lejana, se amplíe el rango.
         * $margen_hoy_override (grupo 330, prompt 01): null = comportamiento actual (usa la
         * setting). Un valor explícito reemplaza el margen SOLO para esta llamada, sin tocar
         * la setting ni ninguna otra llamada del mismo request -- ver revalidar_horarios_ofrecidos(). */
        $context = $this->prepare_slot_availability_context($days_ahead, $specific_date, $exclude_lead_id, $usa_experiencia_nueva, $margen_hoy_override);

        /* Exponer snapshot de calendario al llamador (segunda llamada con disponibilidad). */
        $calendar_snapshot = $context['google_calendar_snapshot'] ?? null;

        /* Config efectiva de ESTA grilla (misión 46). Se expone acá arriba, apenas se resuelve el
         * contexto, para que el llamador la tenga aunque más abajo cambie algo del armado. */
        $slot_config = $context['slot_config'] ?? [];

        /*
         * Garantizar snapshot mínimo de diagnóstico cuando se consultó disponibilidad
         * pero no hubo datos de Google Calendar (p. ej. sin closers conectados).
         */
        if (empty($calendar_snapshot)) {
            $calendar_snapshot = [
                'consultado_en' => AppTime::now()->toIso8601String(),
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
                    $context['slot_config'] ?? [],
                    $usa_experiencia_nueva
                );

                /* Hasta dónde se puede extender cada uno de esos slots (misión 47). Se calcula acá
                 * y no afuera porque los rangos bloqueados de esta instancia en esta fecha viven
                 * en este scope; exponerlos enteros al llamador sería darle a otro la
                 * responsabilidad de volver a interpretarlos. Solo la dinámica nueva. */
                if ($usa_experiencia_nueva) {
                    foreach ($demos_json[$demo_id][$date_label] as $slot) {
                        $hasta = $this->calcular_fin_ventana_extendida(
                            (string) $slot,
                            $blocked_ranges,
                            $context['duracion'],
                            $context['gracia_post'],
                            $context['setup_antes'],
                            LeadDemoSettings::get_ventana_extendida_max_horas()
                        );

                        if ($hasta !== null) {
                            $ventanas[$demo_id][$date_label][(string) $slot] = $hasta;
                        }
                    }
                }
            }
        }

        /* Se asigna al final y de una sola vez: si el llamador pasó una referencia, recibe el mapa
         * completo o un array vacío, nunca uno a medio llenar. */
        $ventanas_extendidas = isset($ventanas) ? $ventanas : [];

        return [
            'hoy'                   => $hoy_label,
            'duration_demo_minutos' => $context['duracion'],
            'demos'                 => $demos_json,
        ];
    }

    /**
     * Hasta qué hora se puede extender la ventana de un slot de inicio, o null si ese slot no
     * admite ventana extendida (misión 47).
     *
     * 🔴 ES LA ÚNICA FUENTE del "hasta". La usan los tres lugares que necesitan la respuesta: el
     * bloque VENTANA EXTENDIDA que se le manda al agente, el guard que descarta un agendamiento
     * que el sistema nunca ofreció, y la revalidación bajo lock antes de persistir. Si cada uno
     * calculara lo suyo, el agente podría prometerle al lead una hora que la validación después
     * rechaza — y el mensaje ya salió.
     *
     * Es todo o nada: si la ventana completa no entra, ese slot no admite extendida. NO se recorta
     * en silencio para que entre, porque el lead ya recibió un mensaje que dice hasta qué hora la
     * tiene, y darle menos sin decírselo es peor que reofrecer.
     *
     * @param string                 $slot_inicio    Slot de inicio en HH:MM.
     * @param array<int, array{0:int,1:int}> $blocked_ranges Rangos ocupados de ESA instancia en ESA
     *                                                fecha, en minutos desde medianoche.
     * @param int                    $duracion       Duración de una demo completa, en minutos.
     * @param int                    $gracia_post    Gracia posterior, en minutos.
     * @param int                    $setup_antes    Minutos previos que la instancia queda tomada.
     * @param int                    $max_horas      Tope de la ventana, en horas.
     *
     * @return string|null "HH:MM" hasta donde llega la ventana, o null si no admite.
     */
    protected function calcular_fin_ventana_extendida(string $slot_inicio, array $blocked_ranges, int $duracion, int $gracia_post, int $setup_antes, int $max_horas)
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $slot_inicio, $m)) {
            return null;
        }

        $inicio_min = (int) $m[1] * 60 + (int) $m[2];

        /* El corte en 23:59 no es una decisión estética: la demo se ubica en el tiempo con
         * demo_date (una fecha) + demo_start_time/demo_end_time (dos strings HH:MM). Una ventana
         * que cruce la medianoche necesitaría una fecha de fin, y todo el cálculo de bloqueo, el
         * vencimiento del token y los comandos del ciclo dan por sentado que empieza y termina el
         * mismo día. Decisión de Lucas, 13/8/2026. Misión 47. */
        $fin_min = min($inicio_min + $max_horas * 60, self::FIN_DEL_DIA_MINUTOS);

        /* Si desde este inicio no entra ni una demo completa con su gracia, no hay ventana que
         * ofrecer: sería reservarle la instancia para algo que no llega a pasar. */
        if (($fin_min - $inicio_min) < ($duracion + $gracia_post)) {
            return null;
        }

        /* La instancia queda tomada desde setup_antes previo al inicio y hasta la gracia posterior
         * al fin. Si ese bloque pisa cualquier rango ya ocupado, este slot no admite extendida. */
        $bloque_inicio = $inicio_min - $setup_antes;
        $bloque_fin    = $fin_min + $gracia_post;

        foreach ($blocked_ranges as $rango) {
            $bstart = isset($rango[0]) ? (int) $rango[0] : 0;
            $bend   = isset($rango[1]) ? (int) $rango[1] : 0;
            if ($bloque_inicio < $bend && $bloque_fin > $bstart) {
                return null;
            }
        }

        return sprintf('%02d:%02d', intdiv($fin_min, 60), $fin_min % 60);
    }

    /**
     * Resuelve la OFERTA PRIMARIA (grupo 306, prompt 03): el primer momento disponible real, para
     * que el agente solo lo redacte en vez de elegir entre una lista. Solo tiene sentido para leads
     * en la dinámica nueva — se devuelve `hay_disponibilidad: false` si no lo es, como resguardo si
     * algún llamador se equivoca de bandera.
     *
     * @param array<string, mixed> $availability_data     Estructura devuelta por build_availability_json().
     * @param bool                 $usa_experiencia_nueva
     *
     * @return array<string, mixed> Ver ejemplo en el prompt: hay_disponibilidad, es_hoy, fecha,
     *                              dia_label, hora, demo_id, texto_referencia, y oferta_manana
     *                              (misma forma, primer slot en una fecha posterior a hoy).
     */
    protected function resolve_oferta_primaria(array $availability_data, bool $usa_experiencia_nueva): array
    {
        if (! $usa_experiencia_nueva) {
            return ['hay_disponibilidad' => false];
        }

        $demos_json = isset($availability_data['demos']) && is_array($availability_data['demos'])
            ? $availability_data['demos']
            : [];

        $hoy_key    = AppTime::now()->format('Y-m-d');
        $manana_key = AppTime::now()->copy()->addDay()->format('Y-m-d');

        $primaria = $this->primer_slot_disponible($demos_json, null);
        if ($primaria === null) {
            return ['hay_disponibilidad' => false];
        }

        $result = [
            'hay_disponibilidad' => true,
            'es_hoy'             => $primaria['fecha'] === $hoy_key,
            'fecha'              => $primaria['fecha'],
            'dia_label'          => $primaria['dia_label'],
            'hora'               => $primaria['hora'],
            'demo_id'            => $primaria['demo_id'],
            'texto_referencia'   => $this->texto_referencia_oferta($primaria['fecha'], $primaria['hora'], $hoy_key, $manana_key),
        ];

        /* Primer slot en una fecha POSTERIOR a hoy (no necesariamente mañana: si mañana está lleno,
         * es el próximo día con lugar). Es lo que el agente necesita para "si hoy no podés...". */
        $manana = $this->primer_slot_disponible($demos_json, $hoy_key);
        $result['oferta_manana'] = $manana === null
            ? ['hay_disponibilidad' => false]
            : [
                'hay_disponibilidad' => true,
                'es_hoy'             => false,
                'fecha'              => $manana['fecha'],
                'dia_label'          => $manana['dia_label'],
                'hora'               => $manana['hora'],
                'demo_id'            => $manana['demo_id'],
                'texto_referencia'   => $this->texto_referencia_oferta($manana['fecha'], $manana['hora'], $hoy_key, $manana_key),
            ];

        return $result;
    }

    /**
     * Encuentra, entre todas las demos, el slot más temprano (fecha y hora) — opcionalmente
     * excluyendo una fecha puntual (para buscar "el primero después de hoy").
     *
     * Las fechas de las claves de $demos_json[demo_id] vienen como "día Y-m-d" (ej.
     * "lunes 2026-08-03"); se comparan por el sufijo Y-m-d, que ordena cronológicamente como
     * string. Los horarios "HH:MM" también ordenan cronológicamente como string.
     *
     * @param array<int, array<string, array<int, string>>> $demos_json
     * @param string|null                                    $fecha_excluida Y-m-d a saltear, o null.
     *
     * @return array{fecha: string, dia_label: string, hora: string, demo_id: int}|null
     */
    private function primer_slot_disponible(array $demos_json, ?string $fecha_excluida): ?array
    {
        $mejor = null;

        foreach ($demos_json as $demo_id => $slots_por_fecha) {
            if (! is_array($slots_por_fecha)) {
                continue;
            }
            foreach ($slots_por_fecha as $date_label => $slots) {
                if (! is_array($slots) || empty($slots)) {
                    continue;
                }
                if (! preg_match('/(\d{4}-\d{2}-\d{2})$/', (string) $date_label, $m)) {
                    continue;
                }
                $fecha = $m[1];
                if ($fecha_excluida !== null && $fecha === $fecha_excluida) {
                    continue;
                }

                $slots_ordenados = array_map('strval', $slots);
                sort($slots_ordenados);
                $primer_hora = $slots_ordenados[0];

                if ($mejor === null || $fecha < $mejor['fecha'] || ($fecha === $mejor['fecha'] && $primer_hora < $mejor['hora'])) {
                    $mejor = [
                        'fecha'     => $fecha,
                        'dia_label' => (string) $date_label,
                        'hora'      => $primer_hora,
                        'demo_id'   => (int) $demo_id,
                    ];
                }
            }
        }

        return $mejor;
    }

    /**
     * Texto de referencia para el modelo ("hoy a las 18:05", "mañana a las 09:00", "el 2026-08-10 a
     * las 10:00") — material para que el agente redacte, no un texto que se envía tal cual al lead.
     *
     * @param string $fecha      Y-m-d del slot.
     * @param string $hora       HH:MM del slot.
     * @param string $hoy_key    Y-m-d de hoy.
     * @param string $manana_key Y-m-d de mañana.
     *
     * @return string
     */
    private function texto_referencia_oferta(string $fecha, string $hora, string $hoy_key, string $manana_key): string
    {
        if ($fecha === $hoy_key) {
            $dia_texto = 'hoy';
        } elseif ($fecha === $manana_key) {
            $dia_texto = 'mañana';
        } else {
            $dia_texto = 'el ' . $fecha;
        }

        return "{$dia_texto} a las {$hora}";
    }

    /**
     * Revalida los horarios que el TEXTO del mensaje declaró haber ofrecido (grupo 306, prompt 04)
     * contra un cálculo FRESCO de disponibilidad, justo antes de enviar.
     *
     * La disponibilidad se calcula al GENERAR la sugerencia, pero el mensaje se envía recién
     * después de la aprobación humana — minutos u horas más tarde. Hasta este prompt, la única
     * revalidación era la del horario que el lead ELIGIÓ (agendar_demo, con lock por demo_id); los
     * horarios que el MENSAJE ofrece nunca se revalidaban, así que una sugerencia aprobada tarde
     * podía ofrecer horarios ya tomados o ya pasados.
     *
     * @param Lead                              $lead
     * @param array<int, array<string, string>> $horarios_ofrecidos Declarados por el modelo:
     *                                           [{fecha: Y-m-d, desde: HH:MM, hasta: HH:MM}, ...].
     *
     * @return array<int, mixed> Los ítems de $horarios_ofrecidos que YA NO están disponibles en
     *                           ninguna demo. Vacío = todos siguen en pie.
     */
    public function revalidar_horarios_ofrecidos(Lead $lead, array $horarios_ofrecidos): array
    {
        /* Ampliar la ventana si algún horario declarado cae más allá de los 7 días default (mismo
         * fix que el bug del lead #232, 2/7/2026): la oferta primaria puede reflejar una fecha
         * lejana si el lead pidió explícitamente "en 10 días" y eso ensanchó la ventana al generar
         * la sugerencia. Sin esto, esa fecha nunca aparecería en el recálculo por defecto y se
         * marcaría como caducada aunque siga libre. */
        $fecha_mas_lejana = null;
        foreach ($horarios_ofrecidos as $item_fecha) {
            if (! is_array($item_fecha) || ! isset($item_fecha['fecha'])) {
                continue;
            }
            $fecha_item = trim((string) $item_fecha['fecha']);
            if ($fecha_item !== '' && ($fecha_mas_lejana === null || $fecha_item > $fecha_mas_lejana)) {
                $fecha_mas_lejana = $fecha_item;
            }
        }

        $usa_experiencia_nueva    = $lead->usa_experiencia_demo_nueva();
        $calendar_snapshot_unused = null;
        /* El margen mínimo de anticipación decide qué se puede OFRECER, no si lo ya ofrecido sigue
         * en pie. Como la oferta primaria es siempre el primer slot que sobrevive a ese margen,
         * revalidar con el margen puesto cancela la oferta más cercana ante cualquier demora de
         * aprobación mayor a un minuto -- pasó con el lead 30 el 4/8/2026: 11:30 ofrecido 11:14,
         * cancelado al aprobar, con el slot todavía libre. Acá el margen va en 0 a propósito; lo
         * que sigue vigente es "ya pasó" (filtro de slots pasados, sin tocar) y "se ocupó" (capa 1
         * de bloqueo por demo_id, sin tocar).
         *
         * Caso borde DECIDIDO a propósito, no un olvido: si el horario ofrecido queda a menos del
         * margen pero todavía no pasó (ej. 11:30 aprobado 11:29), el mensaje SALE igual. Es lo
         * correcto -- el lead recibe un horario real y la instancia se prepara con lo que haya. El
         * costo de mandarlo es que el setup arranque justo; el costo de cancelarlo es un lead
         * caliente sin respuesta. No "arreglar" esto reintroduciendo el margen acá: es exactamente
         * el bug que este comentario describe arriba. */
        $availability_fresca      = $this->build_availability_json(self::DIAS_DISPONIBILIDAD, $calendar_snapshot_unused, $fecha_mas_lejana, (int) $lead->id, $usa_experiencia_nueva, 0);
        $demos_json = isset($availability_fresca['demos']) && is_array($availability_fresca['demos'])
            ? $availability_fresca['demos']
            : [];

        $caducados = [];
        foreach ($horarios_ofrecidos as $item) {
            if (! is_array($item)) {
                $caducados[] = $item;
                continue;
            }

            $fecha     = isset($item['fecha']) ? trim((string) $item['fecha']) : '';
            $desde_raw = isset($item['desde']) ? trim((string) $item['desde']) : '';
            /* Normalizar a HH:MM (mismo criterio que descartar_agendamiento_fuera_de_slots()). */
            $desde = '';
            if (preg_match('/(\d{1,2}):(\d{2})/', $desde_raw, $m)) {
                $desde = str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2];
            }

            if ($fecha === '' || $desde === '') {
                $caducados[] = $item;
                continue;
            }

            $sigue_disponible = false;
            foreach ($demos_json as $slots_por_fecha) {
                if (! is_array($slots_por_fecha)) {
                    continue;
                }
                foreach ($slots_por_fecha as $date_label => $slots) {
                    if (! is_array($slots)) {
                        continue;
                    }
                    if (! preg_match('/(\d{4}-\d{2}-\d{2})$/', (string) $date_label, $m_fecha) || $m_fecha[1] !== $fecha) {
                        continue;
                    }
                    if (in_array($desde, array_map('strval', $slots), true)) {
                        $sigue_disponible = true;
                        break 2;
                    }
                }
            }

            if (! $sigue_disponible) {
                $caducados[] = $item;
            }
        }

        return $caducados;
    }

    /**
     * ¿Este (fecha, hora) sigue en pie aunque el margen mínimo de anticipación ya lo haya sacado
     * de la grilla, porque es un horario que YA le ofrecimos a ESTE lead en un mensaje enviado?
     *
     * El margen decide qué se puede OFRECER, no si lo ya ofrecido sigue en pie. Como la oferta
     * primaria es siempre el primer slot que sobrevive al margen, nace pegada al borde: al turno
     * siguiente, cuando el lead acepta, el reloj ya la sacó de la grilla. Este método es el
     * segundo chequeo, acotado, que rescata exactamente ese caso — y sólo ese.
     *
     * El orden del cuerpo importa y es barato primero: gate por dinámica, después la consulta a
     * `horarios_ofrecidos` (una alucinación del modelo muere ahí, sin pagar el recálculo), y recién
     * al final la grilla margen-0.
     *
     * 🔴 Lo que este método NO relaja: "ya pasó" (la grilla margen-0 sigue descartando un slot que
     * arrancó) y "lo ocupó otro" (la grilla pasa igual por la capa 1 de bloqueo por demo_id, con
     * exclude_lead_id = este lead). Sólo puede convertir un "no" en un "sí" cuando las tres cosas
     * se dan juntas — está en un mensaje enviado a ESTE lead, no pasó, y nadie más lo tiene.
     *
     * ⚠️ Alcance exacto de esa garantía, para no afirmar de más: este método corre ADENTRO del lock
     * `demo_slot_hold_{demo_id}`, así que ÉL no abre ninguna ventana de doble-booking. Pero existe
     * una ventana PREEXISTENTE, anterior a este fix y fuera de su alcance: el lock se libera bastante
     * antes del único $lead->save() que persiste demo_date/demo_start_time, así que dos aprobaciones
     * concurrentes sobre la misma instancia pueden validar las dos y recién chocar al escribir. Ese
     * agujero no se toca acá (es cirugía sobre el ciclo de vida del lock, decisión aparte): lo que
     * este método garantiza es que no la ensancha.
     *
     * @param Lead       $lead                 Lead que está aceptando el horario.
     * @param int        $demo_id              Instancia física de demo.
     * @param string     $demo_date            Fecha en Y-m-d.
     * @param string     $demo_start           Horario de inicio en HH:MM.
     * @param array|null $ventanas_sin_margen  Referencia de salida: las ventanas extendidas de la
     *                                         MISMA grilla margen-0. Si el slot se rescata, su
     *                                         ventana tiene que salir de acá y no de la grilla con
     *                                         margen, donde ese slot ni existe.
     * @param string|null $permiso_por_horario Horario VIEJO con el que se consulta el permiso, en
     *                                         vez de $demo_start (misión reagendado-al-proximo-slot).
     *                                         Default null = comportamiento de siempre.
     *
     * @return bool true si el horario se rescata del margen.
     */
    protected function oferta_vigente_sin_margen(Lead $lead, int $demo_id, string $demo_date, string $demo_start, &$ventanas_sin_margen = null, ?string $permiso_por_horario = null): bool
    {
        $ventanas_sin_margen = [];

        if ($demo_id <= 0 || $demo_date === '' || $demo_start === '') {
            return false;
        }

        /* Gate por dinámica. En la dinámica actual el margen es 30 hardcodeado en
         * compute_day_slots_for_demo() y el override no lo toca, así que el recálculo daría
         * exactamente lo mismo: el gate ahorra una grilla entera y deja escrito que este fix es de
         * la dinámica nueva (igual que el del grupo 330). */
        if (! $lead->usa_experiencia_demo_nueva()) {
            return false;
        }

        /* 🔴 Gate por fecha, y va ANTES de la consulta y de la grilla porque es el que saca el
         * costo del camino mayoritario. El margen mínimo de anticipación SÓLO filtra slots de HOY:
         * el filtro de compute_day_slots_for_demo() es `if ($is_today && $slot_start < $now_minutes
         * + $margen)`. Para cualquier otra fecha, la grilla margen-0 devuelve exactamente lo mismo
         * que la grilla con margen, así que el rescate no puede cambiar nada y recalcular sería
         * pagar una grilla entera (N+1 medido: ~365 queries / ~475ms con una sola demo, y en
         * producción hay 3) adentro de un lock con TTL de 8s, para nada.
         *
         * No sacar esto por creerlo redundante: agendar para mañana o más adelante es el caso
         * mayoritario, y es justo el que este gate deja afuera. */
        if ($demo_date !== AppTime::now()->format('Y-m-d')) {
            return false;
        }

        /* Primero la pregunta barata: ¿se lo ofrecimos nosotros? Una alucinación del modelo (un
         * horario que el lead nunca recibió) muere acá, sin pagar el recálculo de la grilla.
         *
         * 🔴 $permiso_por_horario (misión reagendado-al-proximo-slot): el permiso para saltar el
         * margen es "este horario se lo ofrecimos nosotros" y vive en `lead_messages.horarios_ofrecidos`
         * de un mensaje ENVIADO. Cuando el sistema le corre el turno al próximo slot, ese slot nuevo
         * no está en ninguno: lo eligió PHP hace un minuto y el mensaje todavía es `sugerido`. Por
         * eso lo que viaja en el paquete no es un permiso, es el horario VIEJO —el que sí se le
         * ofreció— y acá se consulta con ÉL: mismo criterio de siempre, misma consulta a la misma
         * base. Un modelo que invente esa clave no se auto-otorga nada. Sin esto, el reagendado se
         * frena solo en los 5 minutos previos al slot nuevo, que es justo cuando el admin aprueba. */
        $hora_del_permiso = ($permiso_por_horario !== null && $permiso_por_horario !== '')
            ? $permiso_por_horario
            : $demo_start;

        if (! LeadMessage::horario_figura_como_ofrecido((int) $lead->id, $demo_date, $hora_del_permiso)) {
            /* 🔴 Alerta a propósito, y en warning: este es el único camino por el que el rescate
             * NO dispara sin que haya pasado nada raro con el horario. Hay un guard conocido (ver
             * el bloque que exige `horarios_ofrecidos` en el paquete del agente) que existe
             * justamente porque el modelo a veces NO declara los horarios que ofreció; cuando eso
             * pasa, el permiso no existe, el rescate no dispara y el síntoma del 25/8/2026 vuelve
             * — sólo que ahora el lead queda mudo en vez de recibir un correctivo falso. Queremos
             * poder verlo en el log en vez de deducirlo. */
            Log::channel('disponibilidad')->warning(
                '[DISPONIBILIDAD] El horario no se rescató del margen: no figura como ofrecido a este lead en ningún mensaje enviado.',
                [
                    'lead_id'          => $lead->id,
                    'demo_id'          => $demo_id,
                    'demo_date'        => $demo_date,
                    'demo_start'       => $demo_start,
                    'hora_del_permiso' => $hora_del_permiso,
                ]
            );

            return false;
        }

        /* 🔴 El 0 va acá, como argumento explícito de ESTA llamada, y no como bandera de instancia
         * ni como setting: una bandera con estado se filtra a la próxima llamada del mismo request
         * y convierte esto en un bug intermitente imposible de reproducir (grupo 330, prompt 01,
         * textual). El resto del sistema sigue leyendo la setting como siempre. */
        $snapshot_unused = null;
        $config_unused   = null;
        $ventanas_grilla = null;
        /* Ventana de UN día en vez de self::DIAS_DISPONIBILIDAD, y es seguro: acá $demo_date ya es
         * HOY (lo garantiza el gate de arriba), y prepare_slot_availability_context() con
         * $specific_date recorre desde la fecha mínima aceptable —que en la dinámica nueva es hoy—
         * hasta el mayor entre (mínima + days_ahead - 1) y la fecha pedida. Con days_ahead = 1 los
         * dos extremos son hoy, así que working_days queda en [hoy]. Los slots de una fecha se
         * calculan por fecha (blocked_by_demo[$demo_id][$fecha], closer_busy[$fecha]) y no dependen
         * de qué otras fechas haya en la ventana, así que el resultado para $demo_date es idéntico
         * al de la ventana completa — con una fracción de las queries, que es lo que importa
         * cuando esto corre adentro de un lock con TTL de 8s.
         *
         * La llamada va en UNA sola línea, igual que la de revalidar_horarios_ofrecidos(): el
         * detector del §7 de la misión enumera los call sites que NO pasan margen, y es un awk
         * línea por línea. Partida en varias líneas, este call site aparecería como candidato en
         * cada triage futuro aunque el 0 esté puesto. */
        $fresca = $this->build_availability_json(1, $snapshot_unused, $demo_date, (int) $lead->id, true, 0, $config_unused, $ventanas_grilla);

        $slots_por_fecha = isset($fresca['demos'][$demo_id]) && is_array($fresca['demos'][$demo_id])
            ? $fresca['demos'][$demo_id]
            : [];

        $rescatado = false;
        foreach ($slots_por_fecha as $date_label => $slots) {
            /* Las claves vienen como "martes 2026-08-25": mismo criterio de sufijo Y-m-d que ya
             * usan descartar_agendamiento_fuera_de_slots(), revalidar_horarios_ofrecidos() y la
             * revalidación bajo lock. */
            if (! is_array($slots) || ! preg_match('/(\d{4}-\d{2}-\d{2})$/', (string) $date_label, $m_fecha) || $m_fecha[1] !== $demo_date) {
                continue;
            }

            if (in_array($demo_start, array_map('strval', $slots), true)) {
                $rescatado = true;
                break;
            }
        }

        /* Se asigna al final y de una sola vez: si el rescate no prosperó, el llamador recibe un
         * array vacío y nunca un mapa a medio llenar (mismo criterio que build_availability_json). */
        $ventanas_sin_margen = ($rescatado && is_array($ventanas_grilla)) ? $ventanas_grilla : [];

        return $rescatado;
    }

    /**
     * Envoltorio fail-safe de oferta_vigente_sin_margen(): si el rescate revienta, se degrada a
     * "no rescata" y el flujo sigue por el camino normal.
     *
     * 🔴 Por qué existe, y por qué los DOS llamadores entran por acá y no por el método directo:
     * el rescate corre adentro del lock `demo_slot_hold_{demo_id}` y hace una consulta a base más
     * una grilla completa. Si cualquiera de las dos tira (deadlock de MySQL, error en el armado de
     * la grilla), la excepción se propaga, se saltea el release() del lock, y el lock queda tomado
     * sus 8s de TTL: en esa ventana toda otra aprobación sobre esa misma instancia cae en el camino
     * de "no se pudo tomar el lock". Un error de infraestructura no puede dejar un lock colgado ni
     * tumbar una aprobación.
     *
     * Es la misma degradación segura que ya usa LeadSuggestionSendService alrededor de
     * revalidar_horarios_ofrecidos(): se sigue, y queda constancia en el log.
     *
     * @param Lead       $lead
     * @param int        $demo_id
     * @param string     $demo_date
     * @param string     $demo_start
     * @param array|null  $ventanas_sin_margen Referencia de salida (ver oferta_vigente_sin_margen()).
     * @param string|null $permiso_por_horario Horario VIEJO con el que se consulta el permiso (ver
     *                                         oferta_vigente_sin_margen()). Default null = de siempre.
     *
     * @return bool true si el horario se rescata del margen; false también si el rescate falló.
     */
    protected function rescate_del_margen_seguro(Lead $lead, int $demo_id, string $demo_date, string $demo_start, &$ventanas_sin_margen = null, ?string $permiso_por_horario = null): bool
    {
        try {
            return $this->oferta_vigente_sin_margen($lead, $demo_id, $demo_date, $demo_start, $ventanas_sin_margen, $permiso_por_horario);
        } catch (\Throwable $e) {
            Log::channel('disponibilidad')->warning(
                '[DISPONIBILIDAD] Falló el rescate del margen de anticipación; se sigue por el camino normal (sin rescate).',
                [
                    'lead_id'    => $lead->id,
                    'demo_id'    => $demo_id,
                    'demo_date'  => $demo_date,
                    'demo_start' => $demo_start,
                    'error'      => $e->getMessage(),
                ]
            );

            $ventanas_sin_margen = [];

            return false;
        }
    }

    /**
     * Frena una APROBACIÓN cuyo horario ya no está disponible: no se le envía nada al lead, se le
     * avisa al admin y se tira, para que LeadController devuelva 422.
     *
     * 🔴 La regla, desde el 25/8/2026: nunca más se envía un texto reescrito firmado con el nombre
     * del admin que aprobó otra cosa. Hasta acá, cuando el slot se caía entre la aprobación y la
     * escritura, el sistema pisaba el `content` del mensaje aprobado con un correctivo y lo mandaba
     * igual — y como el mensaje ya tenía `sent_by_admin_id`, al lead le salía "Sugerido por la IA ·
     * aprobado por <admin>" arriba de un texto que ese admin nunca leyó. Peor todavía con texto
     * editado: approve_message_with_edit_json() usa el texto del admin y descarta el correctivo, o
     * sea que el lead recibía "confirmado 17:05" sin que hubiera demo agendada.
     *
     * Es, además, lo que el docblock de LeadSuggestionSendService::send_suggestion() ya declaraba
     * ("si la validación falla no se envía nada al lead: el error se propaga para que
     * LeadController devuelva 422") y el código contradecía. Esto no cambia un contrato: hace que
     * el código cumpla el que ya tenía escrito.
     *
     * Mismo mecanismo que el bloque de horarios caducados de LeadSuggestionSendService (pasa el
     * mensaje a `rechazado` + requiere_verificacion, marca el lead, deja bloque rojo en el hilo,
     * refresca el panel), que ya cumplía esta regla.
     *
     * 🔴 Esto es SÓLO para descartes LEGÍTIMOS y definitivos: el turno ya pasó, lo tomó otro lead,
     * o la franja prometida ya no entra. Reintentar no los cambia, y por eso acá se paga el precio
     * completo (lead marcado, tarea abierta, mensaje rechazado). Un timeout del lock de la
     * instancia NO entra por acá: es contención transitoria y reintentable, y tiene su propia
     * excepción (AprobacionEnCursoException), que no marca nada y sólo le pide al admin que vuelva
     * a aprobar en unos segundos.
     *
     * @param Lead             $lead             Lead dueño de la conversación.
     * @param LeadMessage|null $existing_message Mensaje aprobado que se estaba aplicando.
     * @param int              $demo_id          Instancia física de demo (solo para el log).
     * @param string           $demo_date        Fecha en Y-m-d.
     * @param string           $demo_start       Horario de inicio en HH:MM.
     * @param string           $motivo           Motivo legible, va al log y al bloque rojo.
     *
     * @throws HorarioYaNoDisponibleException Siempre. Es el punto de la función.
     *
     * @return void
     */
    private function frenar_por_horario_no_disponible(Lead $lead, ?LeadMessage $existing_message, int $demo_id, string $demo_date, string $demo_start, string $motivo): void
    {
        Log::channel('disponibilidad')->error(
            '[DISPONIBILIDAD] Aprobación frenada: el horario que el mensaje confirmaba ya no está disponible. No se envió nada al lead.',
            [
                'lead_id'    => $lead->id,
                'demo_id'    => $demo_id,
                'demo_date'  => $demo_date,
                'demo_start' => $demo_start,
                'message_id' => $existing_message !== null ? $existing_message->id : null,
                'motivo'     => $motivo,
            ]
        );

        /* Cancela el token del job y limpia ai_auto_send_at: la burbuja no puede seguir mostrando
         * un countdown que va a fallar igual (mismo criterio que
         * LeadSuggestionSendService::handle_auto_send_agendamiento_gate()). */
        if ($existing_message !== null) {
            (new LeadAiSuggestionAutoSendScheduler())->cancel_for_message((int) $existing_message->id);
        }

        /* 🔴 El mensaje NO puede quedar en `sugerido`, y esto no es cosmético del panel.
         * build_user_content() sólo tiene rama especial para `rechazado` ("SISTEMA (sugerencia no
         * enviada al lead)"); un `sugerido` cae en la rama genérica y se le manda a Claude como
         * "[fecha] SISTEMA: <texto que confirma las 17:05>", así que la PRÓXIMA generación cree que
         * al lead se le confirmó el turno — cuando no se le envió nada y no hay demo en la base.
         * Además el panel seguiría mostrando el botón de aprobar sobre un paquete que ya se frenó,
         * y LeadAiSuggestionScheduler::clear_stale_pending_suggestions() BORRA los `sugerido` en el
         * próximo inbound, con lo que se perdería el rastro.
         *
         * Se reutiliza `rechazado` + requiere_verificacion, exactamente como el bloque de horarios
         * caducados de LeadSuggestionSendService: no se inventa un estado nuevo, y el registro
         * queda en el hilo (no se borra) para poder auditar qué se había aprobado y por qué no
         * salió. */
        if ($existing_message !== null) {
            $existing_message->status                = 'rechazado';
            $existing_message->requiere_verificacion = true;
            $existing_message->save();
        }

        /* 🔴 Update acotado sobre el query builder, y NO $lead->save(). Para cuando se llega acá,
         * $lead ya tiene mutaciones en memoria de bloques anteriores del mismo método
         * (cancelar_demo pudo haber limpiado demo_date, guardar_nombre pudo haber cambiado
         * contact_name). Un save() las persistiría A MEDIAS: la demo vieja cancelada y la nueva sin
         * agendar. Esto es exactamente lo que alguien "simplificaría" a $lead->save(): no se hace.
         *
         * 🔴 Y se marca SOLO requiere_intervencion_humana: claude_auto_reply NO se apaga. Apagarlo
         * deja al lead mudo — LeadAiSuggestionScheduler corta al toque si claude_auto_reply es
         * false, así que el lead escribe "¿che, quedó lo de las 17:05?" y no pasa nada; y resolver
         * requiere_intervencion_humana desde el panel NO lo vuelve a prender (LeadController lo
         * documenta). Lucas pidió que quede pendiente, marcado para intervención humana y con el
         * error visible en la conversación; apagar el agente es una consecuencia extra que no
         * pidió. */
        Lead::query()->whereKey($lead->id)->update([
            'requiere_intervencion_humana' => true,
        ]);

        /* Bajar `tiene_sugerencia_pendiente` ahora que el mensaje pasó a `rechazado`: si queda
         * prendida, LeadFollowupService y los comandos de recordatorio saltean el lead hasta el
         * próximo ciclo de sugerencias.
         *
         * 🔴 Va sobre un $lead->fresh() y NO sobre $lead: sync_suggestion_flags() termina en un
         * save(), y el $lead de este método ya tiene mutaciones a medias en memoria (ver el
         * comentario de arriba). El fresh() es un modelo recién leído de la base — trae el
         * requiere_intervencion_humana que se acaba de escribir y no arrastra nada sin persistir. */
        $lead_fresco = $lead->fresh();
        if ($lead_fresco !== null) {
            $lead_fresco->sync_suggestion_flags();
        }

        /* 🔴 Y alguien se tiene que enterar fuera del navegador. El camino normal de
         * requiere_intervencion_humana (apply_parsed_response) crea una AdminTask, busca el
         * is_default_task_assignee y notifica; acá, sin eso, el único aviso sería el toast del 422
         * en la pantalla del admin que apretó aprobar — si cerró la pestaña o estaba aprobando en
         * tanda, el lead se enfría sin que nada lo denuncie. Es el MISMO mecanismo, no una copia:
         * el bloque se extrajo a crear_tarea_de_intervencion_humana() y lo llaman los dos. No tira
         * nunca (try/catch adentro): fallar creando una tarea no puede impedir el freno. */
        $this->crear_tarea_de_intervencion_humana(
            $lead,
            'No se envió la sugerencia aprobada: el turno del ' . $demo_date . ' a las ' . $demo_start
            . ' ya no está disponible (' . $motivo . '). La demo no se agendó. Revisá la conversación y '
            . 'pedí una sugerencia nueva con disponibilidad fresca.'
        );

        /* El log del hilo emite emit_conversation_updated() por dentro (ver
         * LeadConversationErrorLogger::log()), así que acá NO se vuelve a emitir: serían dos
         * eventos Pusher y dos GET de la conversación desde el SPA por un solo cambio. */
        (new LeadConversationErrorLogger())->log(
            (int) $lead->id,
            'No se envió: el horario que este mensaje confirmaba ya no está disponible',
            'El turno del ' . $demo_date . ' a las ' . $demo_start . ' ya no está libre (' . $motivo . '). '
            . 'No se le envió nada al lead y la demo no se agendó. Pedí una sugerencia nueva con disponibilidad fresca.'
        );

        throw new HorarioYaNoDisponibleException(
            'El horario que este mensaje confirmaba ya no está disponible. No se envió nada al lead: pedí una sugerencia nueva.'
        );
    }

    /**
     * Crea la AdminTask de "revisar conversación" para un lead que quedó marcado para intervención
     * humana, la asigna a los setters y notifica.
     *
     * 🔴 Es el bloque que antes vivía inline en apply_parsed_response(), extraído tal cual y sin
     * cambiarle una línea de comportamiento, porque ahora tiene DOS llamadores: el camino normal
     * (`requiere_intervencion_humana` en el paquete del agente) y el freno por horario ya no
     * disponible (frenar_por_horario_no_disponible()). Ese segundo camino marca el lead igual que
     * el primero, y sin la tarea el único aviso sería el toast del 422 en el navegador del admin
     * que apretó aprobar: si cerró la pestaña o estaba aprobando en tanda, el lead se enfría sin
     * que nada lo denuncie.
     *
     * No tira nunca: todo el cuerpo está envuelto en try/catch, porque fallar creando una tarea no
     * puede impedir ni el procesamiento del mensaje ni el freno de la aprobación.
     *
     * @param Lead   $lead                Lead que requiere revisión humana.
     * @param string $motivo_intervencion Motivo legible; vacío cae a un texto por defecto.
     *
     * @return void
     */
    private function crear_tarea_de_intervencion_humana(Lead $lead, string $motivo_intervencion): void
    {
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

            /* Se crea sin asignar por defecto: la asignación real (si corresponde) se
             * resuelve más abajo vía la pivot admin_task_assignees. "Sin asignar" significa
             * que la puede tomar cualquier admin, no que la vean todos (corrección de Lucas,
             * grupo 180 prompt 05). */
            $task = \App\Models\AdminTask::create([
                'created_by_admin_id' => $created_by_admin_id,
                'assigned_admin_id'   => null,
                'lead_id'             => $lead->id,
                'title'               => $task_title,
                'content'             => $task_content,
                'todos'               => null,
                'is_done'             => false,
                'sort_order'          => 0,
                /* Origen de la tarea: alerta automática generada por LeadAiService. */
                'created_via'         => 'lead_alert',
            ]);

            /* Regla nueva (grupo 180, prompt 05): las tareas que nacen de conversaciones de
             * leads se asignan a todos los admins marcados como "setter". Si no hay ninguno
             * configurado, la tarea queda sin asignar (comportamiento previo, "la puede
             * tomar cualquiera") y no se rompe nada. */
            $setter_ids = \App\Services\AdminTaskAssignmentResolver::for_lead_task();
            if (! empty($setter_ids)) {
                $task->assigned_admins()->sync($setter_ids);

                /* Mantener sincronizada la columna legacy assigned_admin_id con el primer id
                 * de la lista, mismo criterio que el resto de los orígenes de AdminTask
                 * (AdminTaskController, ClaudeTaskIngestController). */
                $task->assigned_admin_id = $setter_ids[0];
                $task->save();
            }

            /* Notificaciones in-app (+ broadcast / Web Push) para los admins asignados (o
             * todos, si quedó sin asignar). Envuelto en try/catch propio: un fallo acá no
             * debe impedir que la tarea recién creada quede persistida ni que el mensaje del
             * lead se siga procesando. */
            try {
                \App\Services\AdminTaskNotificationService::create_for_task($task);
            } catch (\Throwable $e) {
                Log::error('LeadAiService: error al crear notificaciones de tarea de alerta.', [
                    'lead_id' => $lead->id,
                    'task_id' => $task->id,
                    'error'   => $e->getMessage(),
                ]);
            }

            Log::info('LeadAiService: tarea de alerta creada por intervención humana requerida.', [
                'lead_id'            => $lead->id,
                'motivo'             => $task_content,
                'assigned_admin_ids' => $setter_ids,
            ]);
        } catch (\Throwable $e) {
            Log::error('LeadAiService: error al crear tarea de alerta de intervención humana.', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * A partir del JSON de disponibilidad (build_availability_json), arma por cada fecha un texto
     * legible con los horarios agrupados en bloques contiguos y partidos por turno (mañana/tarde),
     * para que el agente los ofrezca como rangos ("de 8 a 9 de la mañana, y de 13 a 16:30 de la
     * tarde") en vez de enumerar todos los slots.
     *
     * La unión se hace entre demos (un horario es ofrecible si está libre en cualquier demo física).
     * El corte de bloque usa la frecuencia de slots configurada: dos horarios consecutivos pertenecen
     * al mismo bloque solo si están separados por <= frecuencia_slots_min; cualquier hueco mayor abre
     * un bloque nuevo. Los slots se separan primero por turno (mañana < 12:00, tarde >= 12:00) y luego
     * se agrupan, para que ningún bloque cruce el mediodía. Un bloque de un solo horario se expresa
     * como "a las HH" en vez de "de HH a HH".
     *
     * @param array<string, mixed> $availability_data Estructura devuelta por build_availability_json().
     *
     * @return array<string, string> Mapa "día Y-m-d" => texto legible (o '' si no hay slots ese día).
     */
    private function format_availability_readable(array $availability_data): array
    {
        $frecuencia = (int) LeadDemoSettings::get_frecuencia_slots_minutos();
        if ($frecuencia <= 0) {
            $frecuencia = 30;
        }

        $demos = isset($availability_data['demos']) && is_array($availability_data['demos'])
            ? $availability_data['demos']
            : [];

        /* Unir los slots de todas las demos por fecha (ofrecible si está libre en cualquiera). */
        $slots_por_fecha = [];
        foreach ($demos as $demo_slots_by_date) {
            if (! is_array($demo_slots_by_date)) {
                continue;
            }
            foreach ($demo_slots_by_date as $date_label => $slots) {
                if (! is_array($slots)) {
                    continue;
                }
                if (! isset($slots_por_fecha[$date_label])) {
                    $slots_por_fecha[$date_label] = [];
                }
                foreach ($slots as $slot) {
                    /* Clave por valor para deduplicar entre demos. */
                    $slots_por_fecha[$date_label][(string) $slot] = true;
                }
            }
        }

        $result = [];
        foreach ($slots_por_fecha as $date_label => $slot_set) {
            /* Pasar cada "HH:MM" a minutos desde medianoche. */
            $minutos = [];
            foreach (array_keys($slot_set) as $hhmm) {
                if (preg_match('/^(\d{1,2}):(\d{2})$/', (string) $hhmm, $m)) {
                    $minutos[] = (int) $m[1] * 60 + (int) $m[2];
                }
            }
            if (empty($minutos)) {
                $result[$date_label] = '';
                continue;
            }
            sort($minutos, SORT_NUMERIC);

            /* Separar por turno ANTES de agrupar, para que ningún bloque cruce el mediodía. */
            $manana = [];
            $tarde  = [];
            foreach ($minutos as $mn) {
                if ($mn < 12 * 60) {
                    $manana[] = $mn;
                } else {
                    $tarde[] = $mn;
                }
            }

            $partes = array_merge(
                $this->describe_slot_blocks($manana, $frecuencia, 'de la mañana'),
                $this->describe_slot_blocks($tarde, $frecuencia, 'de la tarde')
            );

            $result[$date_label] = implode(', y ', $partes);
        }

        return $result;
    }

    /**
     * Agrupa una lista de minutos ya ordenada en bloques contiguos (corte cuando el salto supera la
     * frecuencia) y devuelve la descripción legible de cada bloque, con la etiqueta de turno dada.
     *
     * @param int[]  $minutos_ordenados Minutos desde medianoche, ordenados ascendente.
     * @param int    $frecuencia        Frecuencia de slots en minutos (separación esperada entre contiguos).
     * @param string $turno             Etiqueta de turno ("de la mañana" / "de la tarde").
     *
     * @return string[] Descripciones de cada bloque contiguo.
     */
    private function describe_slot_blocks(array $minutos_ordenados, int $frecuencia, string $turno): array
    {
        if (empty($minutos_ordenados)) {
            return [];
        }

        $partes = [];
        $inicio = $minutos_ordenados[0];
        $prev   = $minutos_ordenados[0];
        $count  = count($minutos_ordenados);

        for ($i = 1; $i < $count; $i++) {
            if ($minutos_ordenados[$i] - $prev > $frecuencia) {
                $partes[] = $this->describe_single_block($inicio, $prev, $turno);
                $inicio   = $minutos_ordenados[$i];
            }
            $prev = $minutos_ordenados[$i];
        }
        $partes[] = $this->describe_single_block($inicio, $prev, $turno);

        return $partes;
    }

    /**
     * Describe un bloque contiguo como texto legible. Un bloque de un solo horario ("desde" ==
     * "hasta") se expresa como "a las HH"; un rango como "de HH a HH". Ambos con el turno.
     *
     * @param int    $desde Minuto de inicio del bloque.
     * @param int    $hasta Minuto de fin del bloque (último slot ofrecible).
     * @param string $turno Etiqueta de turno.
     *
     * @return string
     */
    private function describe_single_block(int $desde, int $hasta, string $turno): string
    {
        if ($desde === $hasta) {
            return 'a las ' . self::format_minutes_to_hhmm($desde) . ' ' . $turno;
        }

        return 'de ' . self::format_minutes_to_hhmm($desde) . ' a ' . self::format_minutes_to_hhmm($hasta) . ' ' . $turno;
    }

    /**
     * Resuelve el horario configurado (H:i-H:i) para un día de semana dado, según la dinámica.
     *
     * En la dinámica nueva la franja es la propia de la demo (grupo 306) — no tiene días sin
     * trabajo, porque la demo se ofrece siempre. En la dinámica actual sigue siendo el horario
     * laboral del closer (vacío = no trabaja ese día).
     *
     * @param int                   $dow                   Día de semana Carbon (0=domingo … 6=sábado).
     * @param bool                  $usa_experiencia_nueva
     * @param array<string, string> $horario_closer_por_dia Claves 'lv' | 'sab' | 'dom'.
     * @param array<string, string> $horario_demo_por_dia   Mismas claves, franja propia de la demo.
     *
     * @return string
     */
    private function horario_por_dia_semana(int $dow, bool $usa_experiencia_nueva, array $horario_closer_por_dia, array $horario_demo_por_dia): string
    {
        $tabla = $usa_experiencia_nueva ? $horario_demo_por_dia : $horario_closer_por_dia;

        if ($dow === 0) {
            return $tabla['dom'];
        }
        if ($dow === 6) {
            return $tabla['sab'];
        }

        return $tabla['lv'];
    }

    /**
     * Prepara días hábiles, consulta de bloqueos y mapa de fechas para disponibilidad.
     *
     * Centraliza la lógica compartida entre get_available_slots() y build_availability_json().
     * Si algún día queda sin slots libres en la unión de demos, agrega un día hábil extra.
     *
     * Cuando se provee $specific_date, la fecha objetivo AMPLÍA la ventana en vez de
     * recortarla: el rango recorrido va desde mañana hasta el mayor entre
     * (mañana + $days_ahead - 1 días) y la fecha solicitada, inclusive (solo días con
     * horario configurado). Esto evita que una fecha pedida por el lead cercana a hoy
     * achique la ventana y deje afuera días que el lead podía llegar a pedir después
     * (causa raíz del bug del lead #12, 13/7/2026).
     *
     * @param int         $days_ahead    Cantidad de días CORRIDOS (no hábiles) a recorrer desde mañana.
     *                                   Antes representaba días hábiles a juntar; desde el 13/7/2026
     *                                   representa el largo fijo de la ventana en días corridos
     *                                   (self::DIAS_DISPONIBILIDAD). Los días sin horario configurado
     *                                   se excluyen del resultado pero no extienden el recorrido.
     * @param string|null $specific_date Fecha objetivo en formato Y-m-d, o null para comportamiento por defecto.
     * @param int|null    $margen_hoy_override Reemplaza el margen mínimo de anticipación SOLO para
     *                                   esta llamada (grupo 330, prompt 01). null = usa la setting
     *                                   configurada, igual que siempre.
     *
     * @return array<string, mixed>
     */
    protected function prepare_slot_availability_context(int $days_ahead = self::DIAS_DISPONIBILIDAD, ?string $specific_date = null, ?int $exclude_lead_id = null, bool $usa_experiencia_nueva = false, ?int $margen_hoy_override = null): array
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
        /* Franja propia de la demo por día de semana (grupo 306): independiente del closer. */
        $demo_horario_lv   = LeadDemoSettings::get_demo_horario_lunes_viernes();
        $demo_horario_sab  = LeadDemoSettings::get_demo_horario_sabado();
        $demo_horario_dom  = LeadDemoSettings::get_demo_horario_domingo();
        /* Frecuencia en minutos entre slots candidatos (ej. 30 = :00 y :30). */
        $frecuencia_slots  = LeadDemoSettings::get_frecuencia_slots_minutos();
        /* Checkbox: si true la llamada del closer también debe terminar dentro del horario. */
        $llamada_termina   = LeadDemoSettings::get_llamada_debe_terminar_en_horario();
        /* Duración de la llamada del closer post-demo en minutos. */
        $duracion_closer   = LeadDemoSettings::get_duracion_llamada_closer_minutos();
        /* Margen mínimo para ofrecer un horario de HOY (grupo 306, prompt 02). Solo aplica en la
         * dinámica nueva; la actual sigue sin ofrecer horarios de hoy.
         * $margen_hoy_override (grupo 330, prompt 01): distinto de null solo cuando el llamador
         * pide explícitamente reemplazar la setting para ESTA llamada (revalidar_horarios_ofrecidos()
         * pasa 0) -- el resto de los llamadores no lo pasan, así que siguen leyendo la setting igual
         * que siempre. */
        $demo_minimo_minutos_desde_ahora = $margen_hoy_override !== null
            ? $margen_hoy_override
            : LeadDemoSettings::get_demo_minimo_minutos_desde_ahora();

        /*
         * Config agrupada para pasarla a compute_day_slots_for_demo() y get_all_slots_for_day()
         * sin tener que extender la firma con 6+ parámetros individuales.
         */
        $slot_config = [
            'horario_lv'                       => $horario_lv,
            'horario_sab'                      => $horario_sab,
            'horario_dom'                      => $horario_dom,
            'demo_horario_lv'                  => $demo_horario_lv,
            'demo_horario_sab'                 => $demo_horario_sab,
            'demo_horario_dom'                 => $demo_horario_dom,
            'usa_experiencia_nueva'            => $usa_experiencia_nueva,
            'frecuencia_slots'                 => $frecuencia_slots,
            'duracion'                         => $duracion,
            'gracia_post'                      => $gracia_post,
            'duracion_llamada_closer'          => $duracion_closer,
            'llamada_debe_terminar_en_horario' => $llamada_termina,
            'demo_minimo_minutos_desde_ahora'  => $demo_minimo_minutos_desde_ahora,
        ];

        /* Tabla horario por día de semana, una para el closer y otra para la demo (grupo 306):
         * la dinámica nueva resuelve el día contra la franja propia de la demo, que no tiene
         * días sin trabajo — el closer no gobierna cuándo se ofrece la demo. */
        $horario_closer_por_dia = ['lv' => $horario_lv, 'sab' => $horario_sab, 'dom' => $horario_dom];
        $horario_demo_por_dia   = ['lv' => $demo_horario_lv, 'sab' => $demo_horario_sab, 'dom' => $demo_horario_dom];

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
        $now         = AppTime::now();
        $now_minutes = $now->hour * 60 + $now->minute;
        $today_key   = $now->copy()->startOfDay()->format('Y-m-d');
        /* El cursor arranca HOY en la dinámica nueva (grupo 306, prompt 02): el closer ya no
         * participa de la decisión de cuándo se hace la demo, así que no hace falta un día de
         * anticipación — la demo se ofrece lo antes posible, incluso ahora mismo. En la dinámica
         * actual el cursor sigue arrancando en mañana, sin cambios. */
        $cursor      = $usa_experiencia_nueva
            ? $now->copy()->startOfDay()
            : $now->copy()->startOfDay()->addDay();

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

            /* Fecha mínima aceptable: HOY en la dinámica nueva (un lead puede pedir "hoy a las
             * 20" y eso ahora es válido), mañana en la dinámica actual — grupo 306, prompt 02. */
            $fecha_minima_aceptable = $usa_experiencia_nueva
                ? $now->copy()->startOfDay()
                : $now->copy()->startOfDay()->addDay();

            if ($target_date !== null && $target_date->gte($fecha_minima_aceptable)) {
                /* La ventana por defecto (N días corridos desde la fecha mínima aceptable) es el
                 * PISO: una fecha pedida dentro de ese rango no la achica. Solo una fecha
                 * posterior la extiende. Antes, una fecha_solicitada cercana recortaba la ventana
                 * y dejaba afuera días que el lead sí podía llegar a pedir — causa raíz del bug
                 * del lead #12 (13/7/2026). */
                $ventana_default_fin = $fecha_minima_aceptable->copy()->addDays($days_ahead - 1);
                $end_date            = $target_date->gt($ventana_default_fin) ? $target_date->copy() : $ventana_default_fin;

                /* Recorrer desde la fecha mínima aceptable hasta $end_date inclusive, incluyendo
                 * solo días con horario configurado. */
                $cursor_specific = $fecha_minima_aceptable->copy();
                while ($cursor_specific->lte($end_date)) {
                    $horario_dia = $this->horario_por_dia_semana($cursor_specific->dayOfWeek, $usa_experiencia_nueva, $horario_closer_por_dia, $horario_demo_por_dia);
                    if ($horario_dia !== '') {
                        $working_days[] = $cursor_specific->copy();
                    }
                    $cursor_specific->addDay();
                }

                /* Si el rango produjo al menos un día hábil, usarlo; de lo contrario caer al default. */
                if (! empty($working_days)) {
                    $use_specific_date = true;
                    /* Adelantar el cursor principal al día siguiente del fin de ventana
                     * para que la lógica de "día extra" arranque desde ahí si es necesaria. */
                    $cursor = $end_date->copy()->addDay();
                }
            }
        }

        /* Comportamiento por defecto: ventana fija de $days_ahead días CORRIDOS desde mañana.
         * A diferencia del comportamiento anterior (días hábiles a juntar), acá el recorrido
         * tiene largo fijo: un día sin horario configurado consume su lugar en la ventana y
         * se descarta, en vez de forzar al cursor a seguir avanzando más allá de los N días. */
        if (! $use_specific_date) {
            for ($i = 0; $i < $days_ahead; $i++) {
                /* 0=domingo, 6=sábado, 1-5=lunes a viernes (convención Carbon). Horario según la
                 * franja propia de la demo o la del closer, según la dinámica del lead. */
                $horario_dia = $this->horario_por_dia_semana($cursor->dayOfWeek, $usa_experiencia_nueva, $horario_closer_por_dia, $horario_demo_por_dia);

                /* Incluir el día solo si tiene rango horario configurado (no vacío).
                 * A diferencia del comportamiento anterior, un día sin horario NO extiende la
                 * ventana: consume su lugar en los N días corridos y se descarta. */
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

        /* Log de diagnóstico: ventana efectiva de fechas consultadas, para poder auditar
         * de un vistazo (sin recorrer el JSON completo) qué rango se le mandó a Claude y si
         * el dia_solicitado resuelto por PHP amplió la ventana por defecto (bug lead #12,
         * 13/7/2026). $specific_date acá ya es la fecha Y-m-d resuelta, nunca lo que Claude
         * mandó crudo. */
        Log::channel('disponibilidad')->info(
            '[DISPONIBILIDAD] Ventana consultada: '
            . (empty($date_strings) ? '(vacía)' : reset($date_strings) . ' a ' . end($date_strings))
            . ' — ' . count($date_strings) . ' día(s) con horario configurado'
            . ' (ventana pedida: ' . $days_ahead . ' días corridos desde ' . ($usa_experiencia_nueva ? 'hoy' : 'mañana')
            . ($specific_date !== null ? ', fecha_resuelta: ' . $specific_date : '')
            . ')'
        );

        /* Rangos bloqueados por demo y rangos de closer ocupado para los días iniciales.
         * Ambas estructuras se construyen en un solo recorrido sobre la misma query de leads. */
        $load_result     = $this->load_blocked_ranges_by_demo($demos, $date_strings, $duracion, $setup_antes, $gracia_post, $exclude_lead_id);
        $blocked_by_demo = $load_result['blocked_by_demo'];
        $closer_busy     = $load_result['closer_busy'];

        /* Snapshot legible de eventos Google del closer (solo para debug en admin-spa). */
        $google_calendar_snapshot = null;

        /*
         * Tercera capa de bloqueo: eventos del calendario Google del closer.
         * Si la API de Google falla, se degrada de forma segura (continúa sin esta capa)
         * para no romper el flujo de WhatsApp por un error externo.
         *
         * En la dinámica nueva esta capa no aplica (grupo 306): el closer no participa de la
         * decisión de cuándo se hace la demo, así que su calendario no puede descartar un slot.
         * Se salta la consulta (ahorra una llamada a la API de Google por mensaje) y se deja un
         * snapshot mínimo con nota explícita, porque el panel de debug de admin-spa lo consume y
         * un null suelto ahí se ve como error.
         */
        if ($usa_experiencia_nueva) {
            $google_calendar_snapshot = ['nota' => 'no_aplica_experiencia_nueva'];
            Log::channel('disponibilidad')->info(
                '[DISPONIBILIDAD] Dinámica nueva: NO se consultó Google Calendar del closer '
                . '(el closer no gobierna la franja de la demo, ver contexto/demo_experiencia.md §3.19).'
            );
        } else {
            try {
                $google_busy_service = new CloserGoogleCalendarBusyService(
                    app(\App\Services\GoogleCalendarOAuthService::class)
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
                    $slot_config,
                    $usa_experiencia_nueva
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
                $horario_extra = $this->horario_por_dia_semana($cursor->dayOfWeek, $usa_experiencia_nueva, $horario_closer_por_dia, $horario_demo_por_dia);
                if ($horario_extra === '') {
                    $cursor->addDay();
                }
            }
            $extra_key  = $cursor->format('Y-m-d');
            $dates_map[$extra_key] = $cursor->copy();

            /* Cargar bloqueos del día extra y fusionarlos con los ya existentes. */
            $extra_result = $this->load_blocked_ranges_by_demo($demos, [$extra_key], $duracion, $setup_antes, $gracia_post, $exclude_lead_id);
            foreach ($demos as $demo) {
                $blocked_by_demo[$demo->id][$extra_key] = $extra_result['blocked_by_demo'][$demo->id][$extra_key] ?? [];
            }
            /* Fusionar rangos de closer del día extra (agenda interna). */
            $closer_busy[$extra_key] = $extra_result['closer_busy'][$extra_key] ?? [];

            /* Agregar también la tercera capa (Google Calendar) para el día extra — salvo en la
             * dinámica nueva, donde esta capa no aplica (ver el bloque de arriba). */
            if (! $usa_experiencia_nueva) {
                try {
                    $google_busy_service_extra = new CloserGoogleCalendarBusyService(
                        app(\App\Services\GoogleCalendarOAuthService::class)
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
            /* Minutos que la instancia queda tomada ANTES del inicio. Ya se lee arriba para el
             * bloqueo; se expone para que el cálculo de la ventana extendida (misión 47) use ese
             * mismo valor en vez de releer la setting por su cuenta. */
            'setup_antes'              => $setup_antes,
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
            'consultado_en' => $base_snapshot['consultado_en'] ?? ($extra_snapshot['consultado_en'] ?? AppTime::now()->toIso8601String()),
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
    protected function load_blocked_ranges_by_demo($demos, array $date_strings, int $duracion, int $setup_antes, int $gracia_post, ?int $exclude_lead_id = null): array
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
        $booked_query = Lead::whereIn('demo_date', $date_strings)
            ->whereNotNull('demo_start_time')
            ->whereNotNull('demo_id');

        /* FIX (auto-colisión de horarios — detectado en testing 6/7/2026, lead #10):
         * la disponibilidad se calculaba contra TODAS las demos agendadas, incluida la del
         * propio lead que se está atendiendo. Cuando un lead ya tenía una demo (reconfirmar el
         * mismo slot, reagendar, o cualquier re-disparo del agendamiento en la misma
         * conversación), su propia reserva bloqueaba su propio horario y el slot se rechazaba
         * como "no disponible" — un choque del lead consigo mismo, que disparaba la tercera
         * llamada correctiva y reescribía el mensaje ya aprobado por el operador. Al excluir su
         * propia demo, un lead nunca colisiona contra sí mismo; sigue chocando con las de otros
         * leads. Un lead tiene a lo sumo una demo (su propia fila), que se sobreescribe al
         * agendar de nuevo, así que nunca hay doble reserva para el mismo lead. */
        if ($exclude_lead_id !== null) {
            $booked_query->where('id', '!=', $exclude_lead_id);
        }

        $booked_leads = $booked_query
            ->get(['id', 'demo_id', 'demo_date', 'demo_start_time', 'demo_end_time', 'demo_flexible']);

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

            /* Bloqueo por demo: impide que dos leads usen el mismo entorno técnico en simultáneo.
             * Sin cambios: usa $end_minutes (que ya respeta demo_end_time real, incluido un rango
             * amplio manual) — esto ya bloqueaba correctamente el caso de demo_flexible. */
            if (isset($blocked_by_demo[$demo_id][$date_key])) {
                $blocked_by_demo[$demo_id][$date_key][] = [$start_minutes - $setup_antes, $end_minutes + $gracia_post];
            }

            /*
             * Si el lead tiene demo_flexible = true, NO reservar ventana de closer. La demo se le
             * deja abierta en un rango amplio (ej. todo un día) para que la use cuando pueda; la
             * llamada del closer se coordina aparte, manualmente, cuando el lead confirma que
             * terminó — no es una ventana fija post-gracia como en el caso normal. Sin este
             * chequeo, el sistema reservaba automáticamente una ventana de closer justo después
             * del fin del rango (ej. justo después de las 18:00), un bloqueo fantasma que le
             * restaba disponibilidad real a otros leads sin que nadie fuera a usar esa ventana.
             */
            if (! $bl->demo_flexible && isset($closer_busy[$date_key])) {
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
    protected function compute_day_slots_for_demo(Carbon $day, array $blocked_ranges, Carbon $now, string $today_key, int $now_minutes, int $duracion, array $closer_busy_ranges_for_date = [], int $gracia_post = 0, array $slot_config = [], bool $usa_experiencia_nueva = false): array
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

            /* Hoy: descartar slots pasados o con menos del margen configurado.
             * Dinámica nueva (grupo 306, prompt 02): el margen es la setting configurable
             * (default 15 min, tiempo en que el lead entra a la página y completa el formulario).
             * Dinámica actual: se mantiene el margen fijo de 30 min, sin cambios. */
            $margen_hoy_minutos = ($usa_experiencia_nueva && isset($slot_config['demo_minimo_minutos_desde_ahora']))
                ? (int) $slot_config['demo_minimo_minutos_desde_ahora']
                : 30;
            if ($is_today && $slot_start < $now_minutes + $margen_hoy_minutos) {
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
             *
             * En la dinámica nueva esta capa no aplica (grupo 306): el closer no participa de la
             * decisión de cuándo se hace la demo, así que su agenda proyectada no puede descartar
             * un slot de demo. La capa 1 (bloqueo por demo_id) sigue intacta arriba.
             */
            if (! $usa_experiencia_nueva && $slot_free && ! empty($closer_busy_ranges_for_date)) {
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
     * @param int $days_ahead Cantidad de días CORRIDOS (no hábiles) a incluir desde mañana
     *                        (default: self::DIAS_DISPONIBILIDAD). Antes representaba días hábiles
     *                        a juntar; desde el 13/7/2026 es el largo fijo de la ventana en días
     *                        corridos (ver prepare_slot_availability_context()).
     * @param bool $usa_experiencia_nueva Si true, la demo usa su franja propia y el closer no la
     *                        gobierna (grupo 306). Default false para no romper ningún caller.
     *
     * @return array<string, string[]> Mapa fecha (Y-m-d) → array de slots disponibles ('HH:MM').
     */
    public function get_available_slots(int $days_ahead = self::DIAS_DISPONIBILIDAD, bool $usa_experiencia_nueva = false): array
    {
        /* Obtener todas las demos registradas para el cálculo multi-demo. */
        $demos = \App\Models\Demo::orderBy('id')->get();

        /*
         * Fallback: si no hay demos registradas, usar el algoritmo legacy
         * (bloquea exactamente el slot de inicio sin márgenes). El algoritmo legacy no conoce la
         * franja propia de la demo: es un camino de respaldo para cuando no hay ninguna demo
         * registrada, escenario que no aplica a la dinámica nueva.
         */
        if ($demos->isEmpty()) {
            return $this->get_available_slots_legacy($days_ahead);
        }

        /* Contexto compartido con build_availability_json(). */
        $context = $this->prepare_slot_availability_context($days_ahead, null, null, $usa_experiencia_nueva);
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
                    $context['slot_config'] ?? [],
                    $usa_experiencia_nueva
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
     * @param int $days_ahead Cantidad de días CORRIDOS (no hábiles) a incluir desde mañana
     *                        (default: self::DIAS_DISPONIBILIDAD). Antes representaba días hábiles
     *                        a juntar; desde el 13/7/2026 es el largo fijo de la ventana en días
     *                        corridos, en línea con get_available_slots() y build_availability_json().
     *
     * @return array<string, string[]> Mapa fecha (Y-m-d) → array de slots disponibles ('HH:MM').
     */
    public function get_available_slots_legacy(int $days_ahead = self::DIAS_DISPONIBILIDAD): array
    {
        /* Construir lista de días hábiles a partir de HOY. */
        $working_days = [];
        /* Instante actual en Argentina; se usa para filtrar slots de hoy ya pasados. */
        $now = AppTime::now();
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
     *                                          horario_dom, demo_horario_lv, demo_horario_sab,
     *                                          demo_horario_dom, usa_experiencia_nueva,
     *                                          frecuencia_slots, duracion, gracia_post,
     *                                          duracion_llamada_closer,
     *                                          llamada_debe_terminar_en_horario). Con
     *                                          usa_experiencia_nueva=true (grupo 306) el día se
     *                                          resuelve contra la franja propia de la demo
     *                                          (demo_horario_*), no contra el horario del closer.
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
        /* Dinámica nueva (grupo 306): la demo tiene franja propia, el closer no la gobierna. */
        $usa_experiencia_nueva = isset($slot_config['usa_experiencia_nueva']) ? (bool) $slot_config['usa_experiencia_nueva'] : false;
        $demo_horario_lv  = isset($slot_config['demo_horario_lv'])  ? (string) $slot_config['demo_horario_lv']  : '00:00-23:59';
        $demo_horario_sab = isset($slot_config['demo_horario_sab']) ? (string) $slot_config['demo_horario_sab'] : '00:00-23:59';
        $demo_horario_dom = isset($slot_config['demo_horario_dom']) ? (string) $slot_config['demo_horario_dom'] : '00:00-23:59';

        /*
         * Grilla fina para HOY en la dinámica nueva (grupo 306, prompt 02): con la frecuencia
         * configurada (30 min por defecto) un lead que escribe a las 17:47 recibe como "lo antes
         * posible" las 18:30 — 43 minutos de espera para una demo que podría empezar a las 18:05.
         * Los días futuros siguen con la frecuencia configurada, sin cambios.
         */
        if ($usa_experiencia_nueva && $day->format('Y-m-d') === AppTime::now()->format('Y-m-d')) {
            $frecuencia = 5;
        }

        /*
         * Frecuencia mínima de 5 minutos para evitar loops infinitos o listas exageradamente largas.
         * En producción siempre vendrá un valor del conjunto {5, 10, 15, 30, 60}.
         */
        if ($frecuencia < 5) {
            $frecuencia = 5;
        }

        /* Seleccionar el horario según día de semana (0=domingo, 6=sábado). */
        $dow = $day->dayOfWeek;

        /* La grilla de demos se genera desde la franja de la DEMO, no desde el horario del closer.
         * La demo es autogestionada: el closer no participa. Atarla a su horario dejaba sin ningún
         * slot al lead que escribe a las 20:00, que es justo el lead caliente que hay que atender.
         * Ver contexto/demo_experiencia.md §3.19. */
        if ($usa_experiencia_nueva) {
            if ($dow === 0) {
                $horario_raw = $demo_horario_dom;
            } elseif ($dow === 6) {
                $horario_raw = $demo_horario_sab;
            } else {
                $horario_raw = $demo_horario_lv;
            }
        } else {
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
            if ($usa_experiencia_nueva) {
                /* El closer no participa de esta decisión: el slot vale si ENTRA COMPLETO en la
                 * franja propia de la demo (inicio Y fin), sin proyectar ninguna llamada.
                 *
                 * Correctivo grupo 310 (checker del grupo 306): validar solo el inicio dejaba
                 * ofrecer un slot que cruza medianoche (ej. 23:30 con duración 60), y ese slot
                 * no aparece en los rangos bloqueados de MAÑANA porque load_blocked_ranges_by_demo()
                 * los guarda por date_key en minutos del día — debilitaba la capa 1 de bloqueo por
                 * demo_id, permitiendo doble reserva de la misma instancia técnica. */
                if ($slot_min < $horario_inicio || $slot_min + $duracion > $horario_fin) {
                    continue;
                }
            } else {
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
            'anthropic-beta'    => 'prompt-caching-2024-07-31',
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
     * Operación compartida entre la primera y segunda llamada a Claude. Punto de entrada
     * delgado: decide si el paquete (mensaje + acciones) puede aplicarse de una, o si por el
     * motivo "agendamiento" tiene que quedar pendiente de aprobación humana (ver
     * requires_agendamiento_verification_gate(), decisión de negocio del 2/7/2026). El chequeo
     * se hace ANTES de correr guardar_nombre/agendar_demo/etc. para que ninguna acción con
     * efectos secundarios (WhatsApp a admins, escritura de demo, evento de Google Calendar,
     * mail) corra todavía cuando el resultado va a quedar pendiente.
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
        /*
         * GENERALIZACIÓN (prompt 319, 9/7/2026): hasta acá solo el tramo de agenda difería sus
         * acciones (pending_actions sin aplicar) hasta la aprobación humana. Lucas quiere que
         * CUALQUIER mensaje que vaya a quedar retenido para aprobación difiera igual, para que el
         * panel de "esto es lo que va a pasar" (y su edición) sirva en todos los casos. Un mensaje
         * se considera "retenido para verificación" si se cumple cualquiera de estas tres condiciones:
         *
         *   1. El tramo de agenda lo exige (comportamiento histórico, ver
         *      requires_agendamiento_verification_gate()).
         *   2. Claude pidió verificación explícita en su propia respuesta (requiere_verificacion: true).
         *   3. El lead tiene requiere_verificacion_mensajes = true (toggle por-lead / auto-encendido al
         *      entrar al tramo de agenda, ver Lead::booted, prompt 406).
         *
         * Si ninguna aplica, el mensaje se envía de inmediato y aplica sus acciones en el acto,
         * como siempre (apply_parsed_response()).
         */
        $retenido_para_verificacion = $this->requires_agendamiento_verification_gate($lead, $parsed)
            || ! empty($parsed['requiere_verificacion'])
            || (bool) $lead->requiere_verificacion_mensajes;

        if ($retenido_para_verificacion) {
            return $this->create_pending_agendamiento_message($lead, $parsed, $is_followup, $calendar_snapshot);
        }

        return $this->apply_parsed_response($lead, $parsed, $is_followup, $calendar_snapshot);
    }

    /**
     * Predice, sin ejecutar ninguna acción ni tocar el lead, si esta respuesta de Claude va a
     * requerir verificación humana por el motivo "agendamiento" (ver
     * ESTADOS_REQUIEREN_SUPERVISION_AGENDAMIENTO). Espeja las mismas condiciones que fuerzan el
     * estado dentro de apply_parsed_response() — agendar_demo, cancelar_demo, confirmar_ingreso,
     * marcar_no_ingreso — pero sin correr el lock de disponibilidad, sin escribir el lead y sin
     * disparar notificaciones. confirmar_fin_demo (→ demo_realizada) queda deliberadamente
     * afuera: ese estado no está en la lista gateada (closer_activo en adelante es 100% manual).
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $parsed
     *
     * @return bool
     */
    protected function requires_agendamiento_verification_gate(Lead $lead, array $parsed): bool
    {
        /*
         * FIX (6/7/2026, decisión de Lucas — zona manual por estado del lead): si el lead YA está
         * en el tramo de agenda (solicita_disponibilidad en adelante), cualquier respuesta se
         * difiere y requiere aprobación, sin importar a qué estado apunte el mensaje. Cierra el
         * hueco donde, con el lead ya en solicita_disponibilidad, Claude sugería un estado fuera
         * del tramo (ej. en_pausa, cerrado_perdido) y ese mensaje se auto-enviaba sin supervisión.
         */
        if (in_array((string) $lead->status, self::ESTADOS_REQUIEREN_SUPERVISION_AGENDAMIENTO, true)) {
            return true;
        }

        $estado_raw = isset($parsed['estado_sugerido']) ? trim((string) $parsed['estado_sugerido']) : '';
        if ($estado_raw !== '') {
            $pipeline_status = LeadPipelineStatus::ensure_exists($estado_raw);
            if (in_array($pipeline_status->slug, self::ESTADOS_REQUIEREN_SUPERVISION_AGENDAMIENTO, true)) {
                return true;
            }
        }

        /* agendar_demo siempre termina en 'demo_agendada' (slot válido) o 'solicita_disponibilidad'
         * (slot inválido / lock ocupado por otra request) — ambos ya están en la lista gateada. */
        if (! empty($parsed['agendar_demo'])) {
            return true;
        }

        if (! empty($parsed['cancelar_demo'])) {
            return true;
        }

        $lead_status = (string) $lead->status;

        /* confirmar_ingreso fuerza el estado a demo_en_curso (ver apply_parsed_response). */
        if (! empty($parsed['confirmar_ingreso']) && in_array($lead_status, ['ingresando_demo', 'demo_agendada'], true)) {
            return true;
        }

        /* marcar_no_ingreso fuerza el estado a demo_pendiente_de_ingreso (ver apply_parsed_response). */
        if (! empty($parsed['marcar_no_ingreso']) && $lead_status === 'ingresando_demo') {
            return true;
        }

        return false;
    }

    /**
     * Valida los campos obligatorios de la respuesta de Claude. Compartido entre
     * create_pending_agendamiento_message() y apply_parsed_response() para no duplicar la regla.
     *
     * @param array<string, mixed> $parsed
     *
     * @throws \RuntimeException Si el mensaje o el estado sugerido vienen vacíos.
     *
     * @return void
     */
    private function validate_parsed_response(array $parsed): void
    {
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
    }

    /**
     * Crea el LeadMessage pendiente de aprobación cuando create_message_and_update_lead() detecta
     * que el paquete (mensaje + acciones) tiene que esperar aprobación humana — ya sea por el tramo
     * de agenda, por requiere_verificacion explícito de Claude, o por demora general de auto-envío
     * (ver GENERALIZACIÓN en create_message_and_update_lead(), prompt 319). No corre NINGUNA acción
     * (guardar_nombre, agendar_demo, cancelar_demo, etc.) — se guarda el $parsed crudo en
     * pending_actions y se aplica recién al aprobar, vía apply_pending_actions(), que revalida
     * disponibilidad en ese momento (no la de cuando Claude respondió acá).
     *
     * La notificación al admin usa el mismo criterio que apply_parsed_response(): si el estado
     * sugerido/actual cae dentro del tramo de agenda (ESTADOS_REQUIEREN_SUPERVISION_AGENDAMIENTO) se
     * notifica con LeadVerificacionAgendamientoNotificationService (incluye alerta de sonido); fuera
     * del tramo se reutiliza LeadVerificacionWhatsappService, el mismo canal que ya usa
     * apply_parsed_response() para requiere_verificacion fuera de agenda. No se inventa un canal nuevo.
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $parsed
     * @param bool                 $is_followup
     * @param array|null           $calendar_snapshot
     *
     * @return LeadMessage
     */
    protected function create_pending_agendamiento_message(Lead $lead, array $parsed, bool $is_followup, ?array $calendar_snapshot): LeadMessage
    {
        $this->validate_parsed_response($parsed);

        $mensaje_sugerido = isset($parsed['mensaje_sugerido']) ? trim((string) $parsed['mensaje_sugerido']) : '';
        $razonamiento     = isset($parsed['razonamiento']) ? (string) $parsed['razonamiento'] : null;
        $estado_raw       = isset($parsed['estado_sugerido']) ? trim((string) $parsed['estado_sugerido']) : '';
        $previous_status  = (string) $lead->status;

        $pipeline_status       = LeadPipelineStatus::ensure_exists($estado_raw);
        $estado                = $pipeline_status->slug;
        $suggested_lead_status = $estado !== $previous_status ? $estado : null;

        /*
         * FIX (prompt 275): guardia "no retroceder de tramo". Una vez que el lead entró al tramo de
         * agenda/demo (solicita_disponibilidad en adelante, hasta antes del cierre manual del closer),
         * el agente devuelve estado base "calificado" durante toda la coordinación. Eso NO debe hacer
         * retroceder al lead ni mostrar chip de cambio de estado. Si el estado sugerido tiene menor
         * rango que el estado actual dentro de ese tramo (y no es un reagendado explícito), se conserva
         * el estado actual. Reagendado (cancelar_demo presente) sí puede volver a calificado a propósito.
         */
        $es_reagendado = ! empty($parsed['cancelar_demo']);
        if (! $es_reagendado) {
            $previous_ps       = LeadPipelineStatus::ensure_exists($previous_status);
            $tramo_agenda_rank = (int) LeadPipelineStatus::ensure_exists('solicita_disponibilidad')->sort_order;
            $closer_rank       = (int) LeadPipelineStatus::ensure_exists('closer_activo')->sort_order;
            $prev_rank         = (int) $previous_ps->sort_order;
            $sug_rank          = (int) $pipeline_status->sort_order;

            if ($prev_rank >= $tramo_agenda_rank && $prev_rank < $closer_rank && $sug_rank < $prev_rank) {
                $estado                = $previous_status;
                $suggested_lead_status = null;
            }
        }

        $msg = LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'sistema',
            'content'               => $mensaje_sugerido,
            'ai_reasoning'          => $razonamiento,
            /* Snapshot de eventos Google del closer al ofrecer disponibilidad (debug admin-spa). */
            'calendar_snapshot'     => $calendar_snapshot
                ? json_encode($calendar_snapshot, JSON_UNESCAPED_UNICODE)
                : null,
            'suggested_lead_status' => $suggested_lead_status,
            /* $parsed crudo de Claude, sin aplicar; apply_pending_actions() lo consume al aprobar. */
            'pending_actions'       => $parsed,
            /* Horarios que el TEXTO de este mensaje declara ofrecer (grupo 306, prompt 04). Null si
             * el modelo no declaró el campo (dinámica actual); array (posiblemente vacío []) si lo
             * declaró. Se revalida en LeadSuggestionSendService::send_suggestion() justo antes de
             * enviar, contra un cálculo fresco de disponibilidad. */
            'horarios_ofrecidos'    => array_key_exists('horarios_ofrecidos', $parsed) ? $parsed['horarios_ofrecidos'] : null,
            'status'                => 'sugerido',
            'is_followup'           => $is_followup,
            'requiere_verificacion' => true,
            'sent_at'               => null,
        ]);

        $lead->tiene_sugerencia_pendiente = true;
        if ($is_followup) {
            $lead->requiere_seguimiento      = true;
            $lead->tiene_seguimiento_sin_ver = true;
        }

        /*
         * FIX (6/7/2026, decisión de Lucas): solicita_disponibilidad es la ÚNICA transición que se
         * aplica al lead de forma automática, en el mismo momento en que el agente detecta el
         * pedido de horarios — aunque el mensaje quede pendiente de aprobación. Así el lead entra
         * al filtro "Solicita disponibilidad" que mira el setter, sin esperar a que se apruebe el
         * mensaje. El resto del tramo (demo_agendada, ciclo de demo) NO se aplica acá: su estado
         * cambia recién al aprobar (ver apply_pending_actions / apply_parsed_response). El badge de
         * cambio de estado se conserva porque suggested_lead_status ya se calculó contra el estado
         * previo, y apply_suggested_pipeline_status() tiene guardia para no repetir el save.
         * $estado y $previous_status ya están calculados más arriba en este mismo método.
         */
        if ($estado === 'solicita_disponibilidad' && $estado !== $previous_status) {
            $lead->status = 'solicita_disponibilidad';
        }

        $lead->save();

        /*
         * GENERALIZACIÓN (prompt 319): antes este bloque solo cubría el tramo de agenda. Ahora
         * $msg puede quedar diferido también fuera de ese tramo (requiere_verificacion explícito
         * de Claude, o demora general de auto-envío > 0). Se elige el canal de notificación con el
         * mismo criterio que ya usa apply_parsed_response() para requiere_verificacion: dentro del
         * tramo de agenda va por LeadVerificacionAgendamientoNotificationService (con alerta de
         * sonido); fuera del tramo se reutiliza LeadVerificacionWhatsappService, sin inventar un
         * canal nuevo.
         */
        $es_tramo_agenda = in_array($estado, self::ESTADOS_REQUIEREN_SUPERVISION_AGENDAMIENTO, true);

        $admin_notifications_log = [];
        try {
            if ($es_tramo_agenda) {
                $agendamiento_service = new \App\Services\LeadVerificacionAgendamientoNotificationService(
                    new \App\Services\WhatsappSendService()
                );
                $verif_notified = $agendamiento_service->notify($lead->fresh(), $msg);
                $evento_label   = 'Requiere verificación (coordinando agenda)';

                /* Sonido en el navegador para admins con la pestaña abierta (solo tramo de agenda). */
                event(new \App\Events\LeadVerificacionAgendamientoAlert($lead->fresh(), $msg));
            } else {
                $verificacion_service = new \App\Services\LeadVerificacionWhatsappService(
                    new \App\Services\WhatsappSendService()
                );
                $verif_notified = $verificacion_service->notify($lead->fresh(), $msg);
                $evento_label   = 'Requiere verificación humana';
            }

            if (! empty($verif_notified)) {
                $admin_notifications_log[] = ['evento' => $evento_label, 'admins' => $verif_notified];
            }
        } catch (\Throwable $e) {
            Log::error('LeadAiService: error al notificar verificacion pendiente (acciones diferidas).', [
                'lead_id'    => $lead->id,
                'message_id' => $msg->id,
                'error'      => $e->getMessage(),
            ]);
        }

        if (! empty($admin_notifications_log)) {
            $msg->update(['admin_notifications' => $admin_notifications_log]);
        }

        /* Mismo timer de respaldo que el flujo normal: si nadie aprueba a tiempo, se envía solo
         * (con la demora propia y más larga de LeadWhatsappOnboardingSettings). Al dispararse,
         * AutoSendLeadAiSuggestionJob llama a LeadSuggestionSendService::send_suggestion(), que
         * ahora aplica pending_actions antes de enviar (ver Paso 3). */
        (new LeadAiSuggestionAutoSendScheduler())->schedule_for_suggested_message($msg);
        $msg = $msg->fresh();

        LeadSuggestionCreated::dispatch($lead->id);
        LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $msg->id);

        return $msg;
    }

    /**
     * Aplica las acciones que quedaron pendientes de un mensaje con pending_actions (motivo
     * agendamiento) tras la aprobación del admin — llamado desde
     * LeadSuggestionSendService::send_suggestion() antes de enviar por WhatsApp.
     *
     * Revalida disponibilidad en este momento, no la de cuando Claude respondió: dentro de
     * apply_parsed_response(), el bloque de agendar_demo vuelve a llamar build_availability_json()
     * de forma fresca, con el mismo lock por demo_id que usa el flujo normal (ver el FIX de
     * colisión de horarios de apply_parsed_response). Actualiza el mensaje pendiente in-place en
     * vez de crear uno nuevo, para que la conversación no muestre un mensaje duplicado.
     *
     * FIX (prompt 320): además de las pending_actions originales de Claude, el admin puede haber
     * editado/desactivado acciones antes de aprobar (payload `final_actions`, ver contrato abajo).
     * Cuando viene, se mergea sobre la base de Claude (el admin manda) y se guarda el diff en
     * `actions_override_log` del mensaje, para poder auditar después dónde el agente se equivocó.
     *
     * Contrato de `$final_actions` (armado por admin-spa):
     * ```
     * final_actions: {
     *   estado_sugerido: string|null,               // null = no tocó el estado, se conserva el de Claude
     *   agendar_demo: {demo_id, demo_date, demo_start_time, ventana_extendida?, ventana_hasta?} | null, // null = admin desactivó la demo
     *                                                // ventana_extendida (bool, misión 47): el modelo
     *                                                // pide la MODALIDAD; la hora de fin la calcula
     *                                                // el servidor. demo_end_time nunca se lee de acá.
     *                                                // ventana_hasta (HH:MM, tarea 62): la franja que
     *                                                // negoció el agente; solo válida junto con
     *                                                // ventana_extendida y validada por el servidor
     *                                                // contra el tope de la 47.
     *   forzar_slot: bool,                           // true = agendar aunque el slot figure ocupado
     *   enviar_mail_demo: bool,                       // Mail 1 on/off (solo aplica si hay demo)
     *   guardar_nombre: string|null,
     *   guardar_email: string|null,
     *   cancelar_demo: bool,
     *   requiere_intervencion_humana: bool,
     *   motivo_intervencion: string|null
     * }
     * ```
     *
     * @param LeadMessage $message       Mensaje `sugerido` con pending_actions poblado.
     * @param array|null  $final_actions Acciones editadas por el admin (opcional); ver contrato arriba.
     *
     * @throws \InvalidArgumentException Si el mensaje no tiene pending_actions válido (ej. ya se aplicó,
     *                                    o el horario que Claude había ofrecido ya no está disponible).
     *
     * @return LeadMessage Mismo mensaje, actualizado in-place con el resultado real de aplicar las acciones.
     */
    public function apply_pending_actions(LeadMessage $message, ?array $final_actions = null): LeadMessage
    {
        /* Base = lo que Claude había sugerido originalmente (pending_actions). */
        $parsed = $message->pending_actions;
        if (empty($parsed) || ! is_array($parsed)) {
            throw new \InvalidArgumentException('Este mensaje no tiene acciones pendientes de aplicar.');
        }

        $lead = $message->lead ?? Lead::find($message->lead_id);
        if ($lead === null) {
            throw new \InvalidArgumentException('Lead no encontrado para el mensaje.');
        }

        /* Paquete efectivo a aplicar: por defecto, el de Claude sin cambios. */
        $parsed_efectivo = $parsed;

        /*
         * FIX (prompt 409): true cuando el admin eligió, desde el panel, un estado DISTINTO al que
         * sugirió Claude. Viaja a apply_parsed_response() para que, si ese estado colapsa
         * suggested_lead_status a null (porque coincide con el estado ACTUAL del lead: caso "dejar
         * como está"), NO se restaure el estado crudo de Claude y por ende no se re-aplique al enviar.
         */
        $admin_override_estado = false;

        if ($final_actions !== null) {
            /* Campos del contrato `final_actions` que tienen equivalente directo en el esquema de
             * Claude ($parsed) y se mergean 1 a 1: el admin manda sobre lo sugerido. */

            /* estado_sugerido: null significa "el admin no tocó el estado" (se conserva el de
             * Claude), porque apply_parsed_response()/validate_parsed_response() exige un estado
             * no vacío para poder procesar el paquete. */
            if (array_key_exists('estado_sugerido', $final_actions)) {
                $estado_admin = $final_actions['estado_sugerido'];
                if ($estado_admin !== null && trim((string) $estado_admin) !== '') {
                    $parsed_efectivo['estado_sugerido'] = $estado_admin;
                    /*
                     * FIX (prompt 409): solo es override real si el admin eligió un estado DISTINTO al
                     * que sugirió Claude. Si dejó el que Claude proponía, no es override (se respeta el
                     * fallback de badge de apply_parsed_response). Si es distinto, la bandera evita que
                     * ese fallback restaure el estado crudo de Claude cuando el admin pidió a propósito
                     * conservar el estado actual (estado elegido == estado actual → suggested null).
                     */
                    $estado_claude = isset($parsed['estado_sugerido']) ? trim((string) $parsed['estado_sugerido']) : '';
                    if (trim((string) $estado_admin) !== $estado_claude) {
                        $admin_override_estado = true;
                    }
                }
            }

            /* agendar_demo: el admin puede desactivarla (null) o dejar la que Claude propuso editada
             * (array con demo_id/demo_date/demo_start_time). En ambos casos, manda directo.
             *
             * 🔴 Con UNA excepción, y no es un detalle (misión 47): el panel RECONSTRUYE este objeto
             * clave por clave en el front, así que cualquier clave que no esté en esa lista llega
             * ausente y, pisando el objeto entero, se pierde para siempre. `ventana_extendida` no es
             * un campo que el admin edite: es la MODALIDAD que pidió el agente, y perderla convierte
             * una ventana de seis horas en una demo normal de una hora, con el mensaje que le
             * prometía la ventana ya enviado al lead. Si el panel no la manda, se conserva la del
             * paquete original. Si la manda, gana el panel — ahí sí es una decisión explícita. */
            if (array_key_exists('agendar_demo', $final_actions)) {
                $agendar_admin = $final_actions['agendar_demo'];

                if (is_array($agendar_admin)
                    && ! array_key_exists('ventana_extendida', $agendar_admin)
                    && isset($parsed['agendar_demo']['ventana_extendida'])) {
                    $agendar_admin['ventana_extendida'] = $parsed['agendar_demo']['ventana_extendida'];
                }

                /* `ventana_hasta` (tarea 62): misma lección que `ventana_extendida` — el panel
                 * reconstruye el objeto clave por clave, así que un SPA que no conozca la clave la
                 * dejaría caer y la franja negociada ("de 12 a 18") degradaría en silencio al tope
                 * automático. Si el panel no la manda, se conserva la del paquete original. */
                if (is_array($agendar_admin)
                    && ! array_key_exists('ventana_hasta', $agendar_admin)
                    && isset($parsed['agendar_demo']['ventana_hasta'])) {
                    $agendar_admin['ventana_hasta'] = $parsed['agendar_demo']['ventana_hasta'];
                }

                /* `reagendado_desde` (misión reagendado-al-proximo-slot): TERCERA clave de la serie
                 * de `ventana_extendida` y `ventana_hasta`, por el mismo motivo — el panel
                 * reconstruye agendar_demo clave por clave y lo que no conoce se pierde. Sin esto,
                 * la marca se cae al aprobar y el turno que el sistema corrió se frena solo en los
                 * 5 minutos previos al slot nuevo.
                 *
                 * 🔴 Con una salvaguarda propia: se preserva SÓLO si el horario efectivo es el
                 * mismo que el que el sistema eligió. Si el admin movió la hora (o la fecha) a
                 * mano, el reagendado del sistema ya no aplica y el permiso no puede viajar con una
                 * hora que nadie eligió: ahí manda su `forzar_slot`, como con cualquier otra
                 * edición manual. */
                if (is_array($agendar_admin)
                    && ! array_key_exists('reagendado_desde', $agendar_admin)
                    && ! empty($parsed['agendar_demo']['reagendado_desde'])
                    && isset($agendar_admin['demo_start_time'], $parsed['agendar_demo']['demo_start_time'])
                    && trim((string) $agendar_admin['demo_start_time']) === trim((string) $parsed['agendar_demo']['demo_start_time'])
                    && isset($agendar_admin['demo_date'], $parsed['agendar_demo']['demo_date'])
                    && trim((string) $agendar_admin['demo_date']) === trim((string) $parsed['agendar_demo']['demo_date'])) {
                    $agendar_admin['reagendado_desde'] = $parsed['agendar_demo']['reagendado_desde'];
                }

                $parsed_efectivo['agendar_demo'] = $agendar_admin;
            }

            /* guardar_nombre / guardar_email: string editable o null para suprimir la acción.
             * Nota: un valor null en el array hace que isset() lo trate como "no seteado" más abajo
             * en apply_parsed_response(), que es exactamente el comportamiento de "acción desactivada". */
            if (array_key_exists('guardar_nombre', $final_actions)) {
                $parsed_efectivo['guardar_nombre'] = $final_actions['guardar_nombre'];
            }
            if (array_key_exists('guardar_email', $final_actions)) {
                $parsed_efectivo['guardar_email'] = $final_actions['guardar_email'];
            }

            /* cancelar_demo / requiere_intervencion_humana: flags booleanas, el admin las prende o apaga. */
            if (array_key_exists('cancelar_demo', $final_actions)) {
                $parsed_efectivo['cancelar_demo'] = (bool) $final_actions['cancelar_demo'];
            }
            if (array_key_exists('requiere_intervencion_humana', $final_actions)) {
                $parsed_efectivo['requiere_intervencion_humana'] = (bool) $final_actions['requiere_intervencion_humana'];
            }
            if (array_key_exists('motivo_intervencion', $final_actions)) {
                $parsed_efectivo['motivo_intervencion'] = $final_actions['motivo_intervencion'];
            }

            /* forzar_slot / enviar_mail_demo: no existen en el esquema de Claude, son flags nuevas del
             * admin que apply_parsed_response() lee directo de $parsed (ver bloques agendar_demo y
             * Mail 1). Default: no forzar, y enviar el mail (comportamiento actual) si no viene. */
            $parsed_efectivo['forzar_slot']      = ! empty($final_actions['forzar_slot']);
            $parsed_efectivo['enviar_mail_demo']  = array_key_exists('enviar_mail_demo', $final_actions)
                ? (bool) $final_actions['enviar_mail_demo']
                : true;

            /* reenviar_mail_demo (grupo 212, prompt 01): a diferencia de forzar_slot/enviar_mail_demo,
             * esta flag SÍ puede venir sugerida por Claude directamente (ver
             * build_demo_access_context() en build_user_content()). Por eso, si el admin no la tocó
             * en el panel (no vino en final_actions), se respeta el valor sugerido por Claude en vez
             * de forzar un default fijo; si el admin la tocó, manda su valor. */
            $parsed_efectivo['reenviar_mail_demo'] = array_key_exists('reenviar_mail_demo', $final_actions)
                ? (bool) $final_actions['reenviar_mail_demo']
                : ! empty($parsed['reenviar_mail_demo']);

            /* --- Diff campo por campo (base de Claude vs efectivo del admin), para auditoría --- */
            $diff              = [];
            $campos_a_comparar = [
                'estado_sugerido',
                'agendar_demo',
                'guardar_nombre',
                'guardar_email',
                'cancelar_demo',
                'requiere_intervencion_humana',
                'motivo_intervencion',
                'reenviar_mail_demo',
            ];
            foreach ($campos_a_comparar as $campo) {
                $valor_claude = $parsed[$campo] ?? null;
                $valor_admin  = $parsed_efectivo[$campo] ?? null;
                /* Comparación laxa a propósito: normaliza diferencias de tipo (ej. "1" vs true)
                 * que no representan un cambio real de decisión del admin. */
                if ($valor_claude != $valor_admin) {
                    $diff[] = [
                        'campo'               => $campo,
                        'sugerido_por_claude' => $valor_claude,
                        'elegido_por_admin'   => $valor_admin,
                    ];
                }
            }
            /* forzar_slot / enviar_mail_demo no tienen base en Claude (siempre false/true implícito);
             * solo se registran en el diff cuando el admin las usó activamente. */
            if (! empty($parsed_efectivo['forzar_slot'])) {
                $diff[] = ['campo' => 'forzar_slot', 'sugerido_por_claude' => false, 'elegido_por_admin' => true];
            }
            if ($parsed_efectivo['enviar_mail_demo'] === false) {
                $diff[] = ['campo' => 'enviar_mail_demo', 'sugerido_por_claude' => true, 'elegido_por_admin' => false];
            }

            /* Persistir el diff en el mensaje (null si el admin no cambió nada realmente). */
            $message->actions_override_log = ! empty($diff) ? $diff : null;
        }

        return $this->apply_parsed_response(
            $lead,
            $parsed_efectivo,
            (bool) $message->is_followup,
            null,
            $message,
            true,
            $admin_override_estado
        );
    }

    /**
     * Aplica de una todas las acciones estructuradas del JSON de Claude (guardar_nombre,
     * guardar_email, cancelar_demo, agendar_demo, confirmar_ingreso, confirmar_fin_demo,
     * posponer_check_fin_demo, marcar_no_ingreso, agendar_llamada_closer, descartar_llamada_closer,
     * sugerir_socio, requiere_intervencion_humana) y crea (o actualiza,
     * cuando viene de una aprobación diferida) el LeadMessage con el resultado.
     *
     * Cuando $for_approval es true (llamado desde apply_pending_actions()), NO se vuelve a forzar
     * requiere_verificacion=true por el motivo agendamiento (ya se aprobó) ni se programa un nuevo
     * timer de auto-envío (LeadSuggestionSendService::send_suggestion() ya envía a continuación,
     * en el mismo request).
     *
     * @param Lead                 $lead              Lead a actualizar.
     * @param array<string, mixed> $parsed            JSON decodificado de la respuesta de Claude.
     * @param bool                 $is_followup        true si el trigger fue el scheduler de inactividad.
     * @param array|null           $calendar_snapshot Snapshot de Google Calendar de esta consulta; si es null
     *                                                 y $existing_message trae uno propio, se conserva el existente.
     * @param LeadMessage|null     $existing_message  Mensaje pendiente a actualizar in-place, o null para crear uno nuevo.
     * @param bool                 $for_approval      true cuando se llama tras la aprobación humana de un paquete diferido.
     *
     * @throws \RuntimeException Si el mensaje o el estado sugerido vienen vacíos.
     *
     * @return LeadMessage Mensaje creado o actualizado con status `sugerido` (sin envío a WhatsApp).
     */
    protected function apply_parsed_response(
        Lead $lead,
        array $parsed,
        bool $is_followup,
        ?array $calendar_snapshot = null,
        ?LeadMessage $existing_message = null,
        bool $for_approval = false,
        bool $admin_override_estado = false
    ): LeadMessage {
        $this->validate_parsed_response($parsed);

        /* Extraer los campos obligatorios de la respuesta (ya validados por validate_parsed_response). */
        $mensaje    = isset($parsed['mensaje_sugerido']) ? trim((string) $parsed['mensaje_sugerido']) : '';
        $estado_raw = isset($parsed['estado_sugerido']) ? trim((string) $parsed['estado_sugerido']) : '';

        /* Estado del lead antes de aplicar la sugerencia (para badge en el mensaje). */
        $previous_status = (string) $lead->status;

        /* Crea el estado en catálogo si Claude devolvió uno nuevo; normaliza slug. */
        $pipeline_status = LeadPipelineStatus::ensure_exists($estado_raw);
        $estado          = $pipeline_status->slug;

        $razonamiento = isset($parsed['razonamiento']) ? (string) $parsed['razonamiento'] : null;
        $req_verif    = ! empty($parsed['requiere_verificacion']);

        /* Solo marcamos el mensaje si la sugerencia implica un cambio de estado del lead. */
        $suggested_lead_status = $estado !== $previous_status ? $estado : null;

        /*
         * FIX (prompt 275): guardia "no retroceder de tramo". Una vez que el lead entró al tramo de
         * agenda/demo (solicita_disponibilidad en adelante, hasta antes del cierre manual del closer),
         * el agente devuelve estado base "calificado" durante toda la coordinación. Eso NO debe hacer
         * retroceder al lead ni mostrar chip de cambio de estado. Si el estado sugerido tiene menor
         * rango que el estado actual dentro de ese tramo (y no es un reagendado explícito), se conserva
         * el estado actual. Reagendado (cancelar_demo presente) sí puede volver a calificado a propósito.
         */
        /* Nota: nombre distinto de la variable $es_reagendado que se declara más abajo (línea ~2015)
         * para el flag de reagendado real de la demo (usado en el template del mail); esta es solo
         * una lectura puntual de $parsed para la guardia de arriba, no debe pisar esa otra variable. */
        $es_reagendado_pipeline_guard = ! empty($parsed['cancelar_demo']);
        if (! $es_reagendado_pipeline_guard) {
            $previous_ps       = LeadPipelineStatus::ensure_exists($previous_status);
            $tramo_agenda_rank = (int) LeadPipelineStatus::ensure_exists('solicita_disponibilidad')->sort_order;
            $closer_rank       = (int) LeadPipelineStatus::ensure_exists('closer_activo')->sort_order;
            $prev_rank         = (int) $previous_ps->sort_order;
            $sug_rank          = (int) $pipeline_status->sort_order;

            if ($prev_rank >= $tramo_agenda_rank && $prev_rank < $closer_rank && $sug_rank < $prev_rank) {
                $estado                = $previous_status;
                $suggested_lead_status = null;
            }
        }

        /* --- Procesar acciones estructuradas devueltas por Claude --- */

        /*
         * Acción: guardar nombre del lead.
         * - Flujo automático de Claude ($for_approval = false): solo se completa si el lead NO tiene
         *   nombre (guard anti-alucinación: Claude no pisa un nombre bueno por su cuenta).
         * - FIX (prompt 410): en APROBACIÓN HUMANA ($for_approval = true) el admin revisó el panel, así
         *   que puede CORREGIR un nombre ya cargado. Solo se escribe si el valor realmente cambió.
         */
        $guardar_nombre       = isset($parsed['guardar_nombre']) ? trim((string) $parsed['guardar_nombre']) : '';
        $puede_pisar_contacto = $for_approval;
        $tenia_nombre_previo  = ! empty($lead->contact_name);
        if ($guardar_nombre !== ''
            && ($puede_pisar_contacto || ! $tenia_nombre_previo)
            && $guardar_nombre !== (string) $lead->contact_name) {
            $lead->contact_name = $guardar_nombre;
            Log::info('LeadAiService: nombre del lead guardado vía acción estructurada.', [
                'lead_id'      => $lead->id,
                'nombre'       => $guardar_nombre,
                'sobreescrito' => $tenia_nombre_previo,
            ]);
        }

        /*
         * Acción: guardar email del lead. Mismo criterio que el nombre (prompt 410): en aprobación
         * humana el admin puede corregir un email ya cargado; en el flujo automático solo se completa
         * si estaba vacío. Al corregirlo se marca $email_nuevo para que el Mail 1 salga a la dirección
         * corregida (ver disparo del Mail 1 más abajo en este método).
         */
        $guardar_email       = isset($parsed['guardar_email']) ? trim((string) $parsed['guardar_email']) : '';
        $tenia_email_previo  = ! empty($lead->email);
        /* Bandera para disparar Mail 1 después del save. */
        $email_nuevo = false;
        if ($guardar_email !== ''
            && filter_var($guardar_email, FILTER_VALIDATE_EMAIL)
            && ($puede_pisar_contacto || ! $tenia_email_previo)
            && $guardar_email !== (string) $lead->email) {
            $lead->email = $guardar_email;
            $email_nuevo = true;
            Log::info('LeadAiService: email del lead guardado vía acción estructurada.', [
                'lead_id'      => $lead->id,
                'email'        => $guardar_email,
                'sobreescrito' => $tenia_email_previo,
            ]);
        }

        /*
         * Flag para detectar si el agendar_demo que sigue es un reagendado (el lead ya tenía demo
         * y pidió cambiar el horario). Se usa para elegir el template correcto en DemoScheduledWhatsappService.
         * Se marca true dentro del bloque cancelar_demo cuando efectivamente había una demo previa.
         */
        $es_reagendado = false;

        /*
         * FIX (mail de demo sin autorización, 3/7/2026, prompt 251): el protocolo
         * (agentes/lead/recursos/demo_agenda.md, Paso 4) exige que guardar_email y
         * agendar_demo viajen juntos en la misma respuesta de Claude — el mail de acceso
         * a la demo (Mail 1) solo debe salir cuando la demo quedó realmente confirmada y
         * validada contra disponibilidad EN ESTE MISMO TURNO. Antes de este fix, el envío
         * del mail dependía únicamente de que hubiera un guardar_email nuevo (o un
         * reagendado con mail ya cargado), sin verificar que el agendar_demo del mismo
         * turno haya sido válido. Se marca true únicamente en el bloque de agendar_demo
         * exitoso (slot validado contra disponibilidad real), más abajo en este método.
         */
        $demo_confirmada_este_turno = false;

        /*
         * FIX (coherencia del path correctivo de slot inválido — detectado en testing 6/7/2026,
         * lead #10): cuando el servidor descarta el agendar_demo porque el slot no está disponible
         * (o no pudo tomar el lock), el mensaje se reescribe a la disculpa correctiva y el estado se
         * fuerza a solicita_disponibilidad, PERO el resumen de acciones (applied_actions_summary) y el
         * badge de cambio de estado se seguían computando desde el $parsed original de Claude (que
         * todavía trae agendar_demo + estado_sugerido: demo_agendada). Resultado: el mensaje decía
         * "ese horario se ocupó" y al mismo tiempo mostraba "Agendar demo 08:00" y "Demo agendada".
         * Este flag marca que el agendar se descartó, para sanear el resumen y no restaurar el badge
         * viejo más abajo. */
        $agendar_descartado_por_slot_invalido = false;

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
        // Flag: se debe liberar la reserva preventiva del closer (grupo 306, prompt 05).
        $closer_hold_release_needed = false;
        // Flag: se debe actualizar la reserva preventiva del closer a "demo en curso" (grupo 306, prompt 07).
        $closer_hold_mark_demo_en_curso_needed = false;
        // Fecha Y-m-d a invalidar en caché al liberar la reserva (null = usar demo_date del lead
        // fresco; se completa solo cuando el bloque de cancelar_demo limpia demo_date antes).
        $closer_hold_demo_date_anterior = null;
        // Flags de agendar_llamada_closer (grupo 307, prompt 03): se ejecuta en el bloque
        // POST-save de mas abajo, igual que el resto de las operaciones de Google Calendar.
        $closer_call_agendar_needed    = false;
        // true = promover el hold vigente (mismo horario); false = crear un evento nuevo en el
        // horario que el lead confirmo (y liberar el hold viejo, si habia uno, antes de crearlo).
        $closer_call_promover_hold     = false;
        $closer_call_inicio_confirmado = null;

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

            /* Reserva preventiva del closer (grupo 306, prompt 05, dinámica nueva): marcar para
             * liberar en el bloque POST-save de más abajo, junto con las demás operaciones de
             * Google Calendar — no acá, porque release_hold_for_lead() guarda el lead por su
             * cuenta y en este punto de la función $lead puede tener mutaciones en memoria
             * (contact_name, email) que todavía no deben persistirse (ver "Único save del lead"
             * más abajo). No hace falta guardar el id ni limpiarlo en memoria: el bloque post-save
             * relee el lead fresco de la base, que todavía tiene closer_hold_event_id cargado.
             * La fecha SÍ hay que guardarla acá: unas líneas más abajo se limpia demo_date, así
             * que el lead fresco del post-save ya no la va a tener (mismo motivo que
             * $google_event_demo_date_anterior arriba). */
            if (! empty($lead->closer_hold_event_id)) {
                $closer_hold_release_needed      = true;
                $closer_hold_demo_date_anterior  = $demo_date_anterior;
            }

            /* Limpiar los campos de demo: libera el slot y deja al lead listo para reagendar. */
            $lead->demo_id         = null;
            $lead->demo_date       = null;
            $lead->demo_start_time = null;
            $lead->demo_end_time   = null;
            /* La modalidad se apaga con el turno (misión 47). Si quedara prendida, el lead seguiría
             * excluido de los relojes del ciclo después de reagendar una demo normal.
             *
             * Solo en la dinámica nueva: en la actual esta columna es el checkbox manual de "sin
             * horario de closer" y no le corresponde a una cancelación de demo desactivarlo. */
            if ($lead->usa_experiencia_demo_nueva()) {
                $lead->demo_flexible = false;
            }

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

            /*
             * FIX (prompt 320): flag `forzar_slot` del admin (agendar/reagendar demo a mano desde
             * la aprobación con acciones editadas). Cuando viene true, se saltea únicamente la
             * validación de "el slot figura en la lista de libres" más abajo — el lock exclusivo por
             * demo_id se sigue tomando igual, así que dos requests concurrentes sobre la misma demo
             * física siguen serializándose. Solo aplica al camino de slot no disponible; si no se
             * pudo tomar el lock (timeout), se trata igual que hoy (ver bloque siguiente).
             */
            $forzar_slot = ! empty($parsed['forzar_slot']);

            if ($demo_id && $demo_date !== '' && $demo_start !== '') {
                /*
                 * FIX (bug de colisión de horarios — leads 65, 70, 93, 192, 197, 234 en
                 * producción, detectado 1/7/2026): la validación de disponibilidad (leer
                 * slots libres + decidir si el pedido de Claude es válido) y la escritura
                 * del slot en el lead no eran atómicas. Dos leads pidiendo casi al mismo
                 * tiempo el mismo horario para la misma demo física podían leer el slot como
                 * libre antes de que cualquiera escribiera, generando colisiones repetidas
                 * (hasta 3 seguidas con un mismo lead). Se toma un lock exclusivo por demo_id
                 * (solo hay 3 físicas en el pool) que cubre lectura + validación + escritura,
                 * así dos requests concurrentes sobre la misma demo física se serializan en
                 * vez de pisarse. El bloque original (sin cambios de lógica) queda adentro del
                 * "else" de abajo; se libera en el punto de salida — ver "FIN DEL LOCK" más
                 * abajo, en el punto 3 de este prompt.
                 */
                $demo_slot_lock = Cache::lock("demo_slot_hold_{$demo_id}", 8);

                /* 🔴 block() NO devuelve false cuando vence el tiempo de espera: tira
                 * LockTimeoutException (ver Illuminate\Cache\Lock::block(), que solo puede devolver
                 * true). Sin este try/catch la rama de "no se pudo tomar el lock" era INALCANZABLE
                 * y un timeout de contención salía como error no manejado (500), sin pasar por
                 * ninguno de los dos caminos previstos. Se traduce a la bandera booleana que el
                 * resto del bloque ya esperaba, y de ahí en más la decisión la toma $for_approval. */
                $demo_slot_lock_acquired = false;
                try {
                    $demo_slot_lock_acquired = (bool) $demo_slot_lock->block(5);
                } catch (LockTimeoutException $e) {
                    $demo_slot_lock_acquired = false;
                }

                if (! $demo_slot_lock_acquired) {
                    /* No se pudo tomar el lock en 5s: otra request está asignando esta misma
                     * demo física en este instante. En el camino de GENERACIÓN se trata igual que
                     * un slot recién ocupado, reutilizando la misma tercera llamada correctiva que
                     * ya existe para ese caso, en vez de arriesgar una doble escritura. En el de
                     * APROBACIÓN, ver el bloque de abajo: se pide reintentar. */
                    Log::warning('LeadAiService: no se pudo tomar el lock de demo_id para validar/asignar slot (timeout 5s).', [
                        'lead_id' => $lead->id,
                        'demo_id' => $demo_id,
                    ]);

                    /* 🔴 Camino de APROBACIÓN: no se reescribe ni se envía nada. El mensaje ya lo
                     * firmó un humano, y pisarle el texto con un correctivo lo manda al lead con el
                     * nombre de ese admin arriba de algo que nunca leyó.
                     *
                     * 🔴 Pero esto NO es un descarte legítimo, y por eso NO entra por
                     * frenar_por_horario_no_disponible(). Un block(5) que vence es contención
                     * transitoria y REINTENTABLE: no es "el turno ya pasó" ni "lo ocupó otro lead",
                     * que son los dos únicos motivos por los que un horario se cae de verdad. Si
                     * acá se frenara como allá, un timeout de cinco segundos quemaría la
                     * conversación para siempre — lead marcado, tarea abierta y mensaje rechazado
                     * por un problema de concurrencia que se resuelve apretando aprobar de nuevo.
                     * Así que se tira una excepción distinta, que el admin lee como "reintentá":
                     * no marca el lead, no crea tarea, no toca el status del mensaje y no deja
                     * bloque rojo. Lo único que comparte con el otro camino es que tampoco envía
                     * nada. La diferencia está escrita también en las dos clases de excepción.
                     *
                     * $for_approval es exactamente la pregunta "¿hay un mensaje que un humano ya
                     * firmó?": solo es true cuando el llamador es apply_pending_actions(). Acá no
                     * hay lock que liberar: no se tomó.
                     *
                     * ⚠️ La rama de abajo (el correctivo) EN PRODUCCIÓN NO SE ALCANZA: el gate de
                     * requires_agendamiento_verification_gate() difiere todo paquete con
                     * agendar_demo, así que ningún paquete llega acá por el camino de generación.
                     * La ejercitan sólo los tests que llaman a apply_parsed_response() directo
                     * (DemoExtendidaHastaElFinDelDiaTest). Se conserva en vez de borrarse porque es
                     * el comportamiento probado de esa rama y borrarla rompería esos tests, no
                     * porque "los tests la ejerciten" la vuelva código vivo. */
                    if ($for_approval) {
                        throw new AprobacionEnCursoException(
                            'Se está procesando otra aprobación sobre esta misma instancia de demo. '
                            . 'Esperá unos segundos y volvé a aprobar. No se le envió nada al lead.'
                        );
                    }

                    /* Motivo fijo y deliberadamente opaco: esto es contención técnica (otra request
                     * está asignando la misma instancia física en este instante), no un horario que
                     * se cayó. El lead no tiene por qué enterarse de la concurrencia del pool, y el
                     * prompt para este caso prohíbe explicar la causa — pero el motivo viaja igual,
                     * porque el hueco vacío es justamente lo que hace inventar al modelo. */
                    $mensaje_correctivo = $this->call_corrective_availability_response($lead, $demo_start, $demo_date, [], 'no pudimos confirmar ese horario en este momento');
                    $mensaje            = $mensaje_correctivo !== '' ? $mensaje_correctivo : 'Ese horario se acaba de ocupar. Decime otro día u horario y lo confirmamos.';
                    $estado_raw         = 'solicita_disponibilidad';
                    $agendar_descartado_por_slot_invalido = true;

                    $pipeline_status       = LeadPipelineStatus::ensure_exists($estado_raw);
                    $estado                = $pipeline_status->slug;
                    $suggested_lead_status = $estado !== $previous_status ? $estado : null;
                } else {

                /* Validar que el slot exista en la disponibilidad real para esa demo.
                 * Las claves del JSON incluyen el nombre del día ("domingo 2026-06-28"),
                 * pero Claude devuelve demo_date en formato Y-m-d. Buscar la clave que
                 * contenga la fecha solicitada.
                 *
                 * FIX (bug real, 2/7/2026 — lead 232 "Pablo"): antes de este fix, acá se
                 * llamaba a build_availability_json() sin argumentos, que arma el JSON solo
                 * con los próximos 3 días hábiles desde mañana. Si $demo_date caía fuera de
                 * esa ventana (ej. el lead había agendado para la semana siguiente), el slot
                 * NUNCA aparecía en $slots_demo aunque estuviera libre, y se rechazaba como
                 * "no disponible" — disparando el camino de slot inválido de más abajo con
                 * una demo que en realidad sí se podía agendar. Se pasa $demo_date como
                 * $specific_date para que la consulta cubra el día real que se está
                 * confirmando (prepare_slot_availability_context ya sabe ampliar el rango
                 * hasta esa fecha cuando se le pasa). */
                /* Se recalcula con la MISMA bandera de experiencia del lead. Si esta validación usara
                 * la grilla por defecto, un horario legítimo de hoy (18:05, grilla de 5 min) no
                 * figuraría en la lista y se descartaría como alucinación del modelo. Ver grupo 306
                 * prompt 02. */
                $availability_snapshot_unused = null;
                /* Ventanas extendidas recalculadas en ESTE instante, bajo el lock (misión 47): es
                 * lo que hace que la revalidación cubra la ventana COMPLETA y no solo el slot de
                 * inicio. Entre la oferta y la confirmación otro lead pudo agarrar un horario de
                 * adentro, y entonces la ventana que le prometimos ya no entra. */
                $slot_config_unused    = null;
                $ventanas_revalidadas  = null;
                $availability = $this->build_availability_json(self::DIAS_DISPONIBILIDAD, $availability_snapshot_unused, $demo_date, $lead->id, $lead->usa_experiencia_demo_nueva(), null, $slot_config_unused, $ventanas_revalidadas);
                $slots_demo   = [];
                $demo_slots_by_date = $availability['demos'][$demo_id] ?? [];
                foreach ($demo_slots_by_date as $date_label => $slots) {
                    /* La clave puede ser "Y-m-d" (legacy) o "nombre Y-m-d" (nuevo formato). */
                    if ($date_label === $demo_date || (strlen($demo_date) <= strlen($date_label) && substr($date_label, -strlen($demo_date)) === $demo_date)) {
                        $slots_demo = $slots;
                        break;
                    }
                }

                /* Slot presente en la disponibilidad real leída recién arriba. */
                $slot_disponible = in_array($demo_start, $slots_demo, true);

                /* Mismo rescate que en descartar_agendamiento_fuera_de_slots(): el margen mínimo de
                 * anticipación decide qué se puede OFRECER, no si lo ya ofrecido sigue en pie. La
                 * oferta primaria nace pegada al borde del margen, así que entre que se le ofrece y
                 * que el lead acepta, el reloj sola la saca de la grilla (lead Brisa, 25/8/2026:
                 * 17:05 ofrecido 16:57, aceptado 16:58, con el slot libre). Sólo se rescata si ese
                 * (fecha, hora) figura en un mensaje que YA se le envió a ESTE lead.
                 *
                 * 🔴 Va adentro del lock y ANTES del bloque de ventana extendida a propósito: si el
                 * slot se rescata, su ventana tiene que salir de la MISMA grilla margen-0 (en la
                 * grilla con margen ese slot no existe, y su ventana tampoco). Y no se toca la
                 * grilla de arriba: $slots_demo sigue alimentando las alternativas del mensaje
                 * correctivo, que con margen 0 le ofrecerían al lead horarios imposibles. */
                /* 🔴 `reagendado_desde` (misión reagendado-al-proximo-slot): cuando el turno lo
                 * corrió el SISTEMA en generación, el permiso se consulta con el horario VIEJO.
                 * El permiso para saltar el margen es "este horario se lo ofrecimos nosotros" y
                 * vive en `lead_messages.horarios_ofrecidos` de un mensaje ENVIADO; el slot nuevo
                 * no está en ninguno (lo eligió PHP y el mensaje todavía era `sugerido`). Por eso
                 * lo que viaja en el paquete no es un permiso, es el horario viejo: acá se llama a
                 * `horario_figura_como_ofrecido()` con ÉL, o sea el mismo criterio de siempre y
                 * contra la misma base — un modelo que invente esta clave no se auto-otorga nada, y
                 * la grilla margen-0 sigue exigiendo que el slot nuevo no haya arrancado y esté
                 * libre. Sin esto, el reagendado se frena solo en los 5 minutos previos al slot
                 * nuevo, que es justo la ventana en que el admin aprueba. */
                $permiso_reagendado = isset($agendar_demo['reagendado_desde']) && trim((string) $agendar_demo['reagendado_desde']) !== ''
                    ? trim((string) $agendar_demo['reagendado_desde'])
                    : null;

                if (! $slot_disponible) {
                    $ventanas_sin_margen = null;
                    if ($this->rescate_del_margen_seguro($lead, $demo_id, $demo_date, $demo_start, $ventanas_sin_margen, $permiso_reagendado)) {
                        $slot_disponible      = true;
                        $ventanas_revalidadas = is_array($ventanas_sin_margen) ? $ventanas_sin_margen : [];

                        Log::channel('disponibilidad')->info(
                            '[DISPONIBILIDAD] Horario ya ofrecido rescatado del margen de anticipación (aprobación).',
                            [
                                'lead_id'    => $lead->id,
                                'demo_id'    => $demo_id,
                                'demo_date'  => $demo_date,
                                'demo_start' => $demo_start,
                            ]
                        );
                    }
                }

                /* Ventana extendida (misión 47). El "hasta" lo resuelve el servidor: el modelo solo
                 * pide la modalidad con un booleano. Si pidió ventana y en este instante ya no
                 * entra, el agendamiento cae por el MISMO camino de slot inválido que ya está
                 * construido y probado — que reofrece. 🔴 Prohibido recortarla en silencio para que
                 * entre: el lead ya recibió un mensaje que dice hasta qué hora la tiene. */
                $quiere_ventana_extendida = ! empty($agendar_demo['ventana_extendida'])
                    && $lead->usa_experiencia_demo_nueva();
                $fin_ventana_extendida    = null;
                /* Bandera propia del `ventana_hasta` inválido (tarea 62): fuerza el camino de
                 * slot inválido INCLUSO con forzar_slot. El panel no edita esta clave (solo la
                 * conserva), así que un valor fuera del tope nunca es una decisión del admin:
                 * escribir otro fin en silencio es justo lo que la 47 prohíbe. */
                $ventana_hasta_invalida = false;

                if ($quiere_ventana_extendida) {
                    $fin_ventana_extendida = $this->buscar_ventana_ofrecida(
                        is_array($ventanas_revalidadas) ? $ventanas_revalidadas : [],
                        $demo_id,
                        $demo_date,
                        $demo_start
                    );

                    if ($fin_ventana_extendida === null && ! $forzar_slot) {
                        Log::error('LeadAiService: ventana extendida ya no disponible al confirmar. Se descarta el agendamiento.', [
                            'lead_id'    => $lead->id,
                            'demo_id'    => $demo_id,
                            'demo_date'  => $demo_date,
                            'demo_start' => $demo_start,
                        ]);

                        /* Cae por el camino de slot inválido: se marca como no disponible y el
                         * bloque de abajo se encarga del mensaje correctivo y del estado. */
                        $slot_disponible = false;
                    } elseif ($fin_ventana_extendida === null && $forzar_slot) {
                        /* El admin forzó el slot: la ventana se calcula igual, pero SIN validar
                         * contra los rangos ocupados —esa validación es justo la que él decidió
                         * saltear— conservando el tope de horas y el corte a las 23:59.
                         *
                         * La alternativa era degradar a demo normal, y es peor de la peor manera:
                         * silenciosa. El mensaje aprobado ya le prometió la ventana al lead, así que
                         * agendarle una hora sin decir nada deja al sistema afirmando una cosa y
                         * haciendo otra. Queda el warning para que el forzado sea rastreable. */
                        $fin_ventana_extendida = $this->calcular_fin_ventana_extendida(
                            $demo_start,
                            [],
                            LeadDemoSettings::get_duracion_minutos(),
                            LeadDemoSettings::get_gracia_minutos_post(),
                            LeadDemoSettings::get_setup_minutos_antes(),
                            LeadDemoSettings::get_ventana_extendida_max_horas()
                        );

                        Log::warning('LeadAiService: ventana extendida forzada por el admin sobre un horario que ya no la admitía.', [
                            'lead_id'    => $lead->id,
                            'demo_id'    => $demo_id,
                            'demo_date'  => $demo_date,
                            'demo_start' => $demo_start,
                            'fin_forzado' => $fin_ventana_extendida,
                        ]);
                    }

                    /* `ventana_hasta` (tarea 62): la franja que el agente negoció con el lead
                     * ("de 12 a 18"). El modelo SIGUE sin escribir demo_end_time: esto es un
                     * pedido que se valida acá, bajo el mismo lock y contra el tope que el
                     * servidor acaba de revalidar (o de forzar) — que ya trae los clamps de la 47
                     * (máximo de horas, 23:59, franja libre). Un "hasta" menor que el tope deja
                     * franja libre por inclusión; uno que no entra (otro día, cruza medianoche,
                     * pasado el tope) tira el agendamiento por el camino de slot inválido, que
                     * reofrece. Si la clave no viene, el fin es el tope calculado — el
                     * comportamiento de la 47, intacto y compatible hacia atrás. */
                    /* isset y no array_key_exists: un `ventana_hasta: null` explícito se trata
                     * como ausente (cae al tope automático), no como franja inválida. */
                    if ($fin_ventana_extendida !== null && isset($agendar_demo['ventana_hasta'])) {
                        $hasta_pedido = $this->normalizar_ventana_hasta(
                            $agendar_demo['ventana_hasta'],
                            $demo_start,
                            $fin_ventana_extendida
                        );

                        if ($hasta_pedido === null) {
                            Log::error('LeadAiService: ventana_hasta fuera de la franja ofrecida. Se descarta el agendamiento.', [
                                'lead_id'       => $lead->id,
                                'demo_id'       => $demo_id,
                                'demo_date'     => $demo_date,
                                'demo_start'    => $demo_start,
                                'ventana_hasta' => (string) $agendar_demo['ventana_hasta'],
                                'tope_ofrecido' => $fin_ventana_extendida,
                            ]);

                            $ventana_hasta_invalida = true;
                            $fin_ventana_extendida  = null;
                        } else {
                            $fin_ventana_extendida = $hasta_pedido;
                        }
                    }
                }

                if (($slot_disponible === false && ! $forzar_slot) || $ventana_hasta_invalida) {
                    Log::error('LeadAiService: Claude devolvió un agendar_demo con slot no disponible. Se ignora.', [
                        'lead_id'            => $lead->id,
                        'demo_id'            => $demo_id,
                        'demo_date'          => $demo_date,
                        'demo_start'         => $demo_start,
                        'slots_disponibles'  => $slots_demo,
                    ]);

                    /* 🔴 Camino de APROBACIÓN: no se reescribe ni se envía nada. Ver el porqué
                     * completo en frenar_por_horario_no_disponible(). El correctivo de abajo se
                     * conserva SOLO para el camino de generación (borrador que nadie firmó), que
                     * es donde pisar el texto es lo correcto. */
                    if ($for_approval) {
                        /* 🔴 El lock se libera ANTES de tirar: el throw se saltea el "FIN DEL LOCK"
                         * de más abajo. Tiene TTL de 8s y se soltaría solo, pero dejarlo colgado
                         * serializa de más a cualquier otra aprobación sobre la misma instancia
                         * física. */
                        if ($demo_slot_lock_acquired) {
                            $demo_slot_lock->release();
                        }

                        $this->frenar_por_horario_no_disponible(
                            $lead,
                            $existing_message,
                            $demo_id,
                            $demo_date,
                            $demo_start,
                            $ventana_hasta_invalida
                                ? 'la franja que se le prometió ya no entra'
                                : 'el turno ya pasó o lo tomó otro lead'
                        );
                    }

                    /*
                     * Camino "slot inválido detectado por servidor":
                     * Claude alucinó un horario que no figura en el JSON de disponibilidad.
                     * El agendado en BD ya quedó descartado arriba, pero el mensaje sugerido
                     * todavía confirma ese horario falso al lead. Para no enviar una confirmación
                     * mentirosa, se hace una tercera llamada correctiva a Claude (aislada del
                     * historial) para que redacte una disculpa natural con las alternativas reales.
                     */
                    $agendar_descartado_por_slot_invalido = true;

                    /* El motivo real viaja también acá (ver call_corrective_availability_response()).
                     * `fecha_en_ventana` es true por construcción: la grilla de arriba se armó con
                     * $demo_date como $specific_date, así que esa fecha siempre está adentro. Lo que
                     * falla es la hora, o la franja extendida. */
                    $mensaje_correctivo = $this->call_corrective_availability_response(
                        $lead,
                        $demo_start,
                        $demo_date,
                        $slots_demo,
                        $this->motivo_real_del_horario_descartado(
                            $lead,
                            $demo_date,
                            $demo_start,
                            array_map('strval', is_array($slots_demo) ? $slots_demo : []),
                            true,
                            ($ventana_hasta_invalida || ($quiere_ventana_extendida && $fin_ventana_extendida === null))
                        )
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
                    /* FIX (prompt 320): si se llegó acá por forzar_slot (el slot NO estaba en la
                     * disponibilidad real, el admin decidió agendarlo igual), dejar constancia en el
                     * log — no es el camino normal de slot validado. */
                    if (! $slot_disponible && $forzar_slot) {
                        Log::warning('LeadAiService: slot forzado por admin (no figuraba en disponibilidad real).', [
                            'lead_id'    => $lead->id,
                            'demo_id'    => $demo_id,
                            'demo_date'  => $demo_date,
                            'demo_start' => $demo_start,
                        ]);
                    }

                    /* FIX (prompt 251): slot validado contra disponibilidad real y a punto de
                     * persistirse — recién acá es cierto que hay una demo confirmada este turno. */
                    $demo_confirmada_este_turno = true;

                    /* Fin de demo: inicio + duración configurada (Claude no debe enviar demo_end_time).
                     *
                     * Con ventana extendida (misión 47) el fin sale del valor que el servidor ya
                     * había resuelto y revalidado bajo este mismo lock — nunca de algo que haya
                     * mandado el modelo. Si el JSON parseado trae un demo_end_time, no se lee acá
                     * ni en ningún lado: la regla dura existe porque el agente ya inventó horarios
                     * frente a un lead real (lead #232, 2/7/2026), y darle una hora de fin para
                     * escribir reabre exactamente esa puerta. */
                    $duracion  = LeadDemoSettings::get_duracion_minutos();
                    $demo_end  = Carbon::createFromFormat('H:i', $demo_start)
                        ->addMinutes($duracion)
                        ->format('H:i');

                    $es_ventana_extendida = ($quiere_ventana_extendida && $fin_ventana_extendida !== null);
                    if ($es_ventana_extendida) {
                        $demo_end = $fin_ventana_extendida;
                    }

                    if (isset($agendar_demo['demo_end_time'])) {
                        Log::warning('LeadAiService: el modelo mandó demo_end_time en agendar_demo. Se descarta: la hora de fin la calcula el servidor.', [
                            'lead_id'                => $lead->id,
                            'demo_end_time_modelo'   => (string) $agendar_demo['demo_end_time'],
                            'demo_end_time_servidor' => $demo_end,
                        ]);
                    }

                    $lead->demo_id         = $demo_id;
                    $lead->demo_date       = $demo_date;
                    $lead->demo_start_time = $demo_start;
                    $lead->demo_end_time   = $demo_end;
                    /* La modalidad se escribe siempre que el lead sea de la dinámica NUEVA, no solo
                     * cuando es true: un reagendamiento de un lead que antes tenía ventana extendida
                     * a un turno normal tiene que apagar la columna, o quedaría fuera de los relojes
                     * del ciclo para siempre.
                     *
                     * 🔴 Y NO se toca en la dinámica actual, aunque ahí el valor calculado siempre
                     * sea false. `demo_flexible` es una columna preexistente (2/7/2026) con otro
                     * significado —"no reservar ventana de closer"— y es un checkbox que Lucas marca
                     * a mano. Pisarla en cada agendamiento le apagaría esa decisión al reagendar por
                     * WhatsApp, y el lead volvería a reservar ventana de closer automática: el
                     * "bloqueo fantasma" que el fix del 2/7 vino a eliminar. */
                    if ($lead->usa_experiencia_demo_nueva()) {
                        $lead->demo_flexible = $es_ventana_extendida;
                    }

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

                    // Incrementar scheduled_count en la variante A/B al agendar la demo.
                    if ($lead->welcome_variant_id) {
                        $ab_variant_sched = \App\Models\MessageVariant::find($lead->welcome_variant_id);
                        if ($ab_variant_sched) {
                            $ab_variant_sched->increment_scheduled();
                        }
                    }

                    /* Marcar que se debe crear el evento en Google Calendar del closer
                     * después del save() principal del lead. */
                    $google_event_create_needed = true;

                    /* Notificar por WhatsApp a los admins suscritos a demos agendadas.
                     * Si $es_reagendado = true se usa el template de cambio de horario. */
                    try {
                        $demo_notify_service = new \App\Services\DemoScheduledWhatsappService(
                            new \App\Services\WhatsappSendService()
                        );
                        $demo_notified = $demo_notify_service->notify($lead, $demo_date, $demo_start, $es_reagendado);
                        if (! empty($demo_notified)) {
                            $admin_notifications_log[] = [
                                'evento' => $es_reagendado ? 'Demo reagendada' : 'Demo agendada',
                                'admins' => $demo_notified,
                            ];
                        }
                    } catch (\Throwable $e) {
                        Log::error('LeadAiService: error al notificar demo agendada por WhatsApp.', [
                            'lead_id'       => $lead->id,
                            'is_reagendado' => $es_reagendado,
                            'error'         => $e->getMessage(),
                        ]);
                    }
                }
                } // cierra el "else" del lock adquirido (ver FIX de colisión de horarios, punto 2)

                /* FIN DEL LOCK: se libera apenas termina la validación + escritura del slot,
                 * sin retenerlo durante el resto de create_message_and_update_lead (nombre,
                 * email, etc. no dependen de este demo_id puntual). */
                if ($demo_slot_lock_acquired) {
                    $demo_slot_lock->release();
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
        $notificar_llamada_agendada   = false;

        /* Acumula los eventos de notificación a admins disparados por este mensaje.
         * Cada elemento: ['evento' => string, 'admins' => string[]].
         * Se persiste en $msg->admin_notifications al finalizar. */
        $admin_notifications_log = [];

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
                    $lead->demo_ingreso_confirmado_at = AppTime::now();
                    /* Habilitar la notificación a admins (se dispara después del save). */
                    $notificar_ingreso_confirmado = true;
                    /* Actualizar la reserva preventiva del closer a "demo en curso" (grupo 306,
                     * prompt 07), junto con las demás operaciones de Google Calendar del
                     * post-save. Adentro del anti-duplicado: si el agente vuelve a inferir un
                     * ingreso ya confirmado -y lo hace, porque la inferencia conversacional se
                     * evalúa en cada mensaje- no tiene que pegarle a Google una vez por mensaje. */
                    $closer_hold_mark_demo_en_curso_needed = true;
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
                    $lead->demo_terminada_confirmada_at = AppTime::now();
                    /* En la dinamica nueva el agente NO se apaga al terminar la demo: le pregunta
                     * al lead si le sirvio y le coordina la llamada con el closer. Se apaga recien
                     * cuando la llamada queda agendada (agendar_llamada_closer) o cuando el lead
                     * dice que no quiere avanzar (descartar_llamada_closer).
                     * Ver contexto/demo_experiencia.md 3.19. */
                    if (! $lead->usa_experiencia_demo_nueva()) {
                        /* El closer toma el control tras la demo: Claude deja de responder automáticamente. */
                        $lead->claude_auto_reply = false;
                    }
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

        /* Acción: posponer el check automático de fin de demo (grupo 307, prompt 01). Claude la
         * devuelve cuando el lead avisa explícitamente que le falta un rato ("estoy viendo lo de
         * compras", "dame 20 minutos"). Válida en los mismos estados que confirmar_fin_demo. No
         * cambia el estado del lead: solo reprograma CheckDemoFin::handle(). */
        $posponer_check_fin_demo_minutos = isset($parsed['posponer_check_fin_demo']) ? (int) $parsed['posponer_check_fin_demo'] : null;
        // Un modelo que devuelve todas las claves de acción puede mandar `false`/`0` cuando NO
        // corresponde posponer -- eso no es "posponer 0 minutos", es "no pedido". Igual que el resto
        // de las acciones booleanas de este método (que usan !empty en vez de isset).
        if ($posponer_check_fin_demo_minutos !== null && $posponer_check_fin_demo_minutos > 0) {
            $estados_validos_posponer = ['demo_en_curso', 'demo_pendiente_de_terminar'];
            if (in_array((string) $lead->status, $estados_validos_posponer, true)) {
                /* Acotar a un rango sano: viene de un modelo interpretando lenguaje natural, y
                 * "dame un rato" no puede convertirse en 8 horas. */
                $minutos = $posponer_check_fin_demo_minutos;
                if ($minutos < 5 || $minutos > 120) {
                    Log::warning('LeadAiService: posponer_check_fin_demo fuera de rango, se acota.', [
                        'lead_id'           => $lead->id,
                        'minutos_recibidos' => $posponer_check_fin_demo_minutos,
                    ]);
                    $minutos = max(5, min(120, $minutos));
                }

                $lead->demo_fin_check_reprogramado_para = AppTime::now()->addMinutes($minutos);

                Log::info('LeadAiService: check de fin de demo pospuesto a pedido del lead.', [
                    'lead_id' => $lead->id,
                    'minutos' => $minutos,
                ]);
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

            /* Liberar la reserva preventiva del closer (grupo 306, prompt 05): el lead nunca
             * entró, así que ese hueco no le sirve a nadie colgado — es justo el que el grupo 307
             * va a necesitar para el próximo lead. Se marca acá y se ejecuta en el bloque
             * POST-save de más abajo, mismo motivo que en el bloque de cancelar_demo: no persistir
             * nada a mitad de función. */
            if (! empty($lead->closer_hold_event_id)) {
                $closer_hold_release_needed = true;
            }
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

        /* Acción: el agente coordina la llamada del closer con el lead (grupo 307, prompt 03,
         * dinámica nueva). Reemplaza al viejo "el closer toma el control" de confirmar_fin_demo:
         * acá el agente sigue vivo y agenda él mismo, recién soltando el control cuando la
         * LeadCall queda creada. Solo válida en demo_realizada -- el mismo estado al que
         * confirmar_fin_demo ya fuerza al lead. */
        $agendar_llamada = isset($parsed['agendar_llamada_closer']) && is_array($parsed['agendar_llamada_closer'])
            ? $parsed['agendar_llamada_closer']
            : null;

        if ($agendar_llamada !== null && $lead->usa_experiencia_demo_nueva() && (string) $lead->status === 'demo_realizada') {
            /* Idempotencia (criterio de éxito 9): un lead que confirma "dale" varias veces no
             * puede terminar con varias LeadCall ni varios eventos en el calendario del closer. */
            $ya_tiene_llamada_pendiente = $lead->calls()->where('estado', 'pendiente')->exists();

            if ($ya_tiene_llamada_pendiente) {
                Log::info('LeadAiService: agendar_llamada_closer ignorado, el lead ya tiene una llamada pendiente.', [
                    'lead_id' => $lead->id,
                ]);
            } else {
                $inicio_raw    = isset($agendar_llamada['inicio']) ? trim((string) $agendar_llamada['inicio']) : '';
                $inicio_parsed = null;
                if ($inicio_raw !== '') {
                    try {
                        $inicio_parsed = Carbon::parse($inicio_raw, 'America/Argentina/Buenos_Aires');
                    } catch (\Exception $e) {
                        $inicio_parsed = null;
                    }
                }

                if ($inicio_parsed === null) {
                    $parsed['requiere_intervencion_humana'] = true;
                    $parsed['motivo_intervencion']           = 'El agente intentó agendar la llamada del closer con un horario ilegible.';
                } else {
                    /* Defensa (misma razón que ya protege agendar_demo en
                     * descartar_agendamiento_fuera_de_slots()): el horario confirmado tiene que
                     * salir de lo que el sistema ofreció de verdad, recalculado AHORA -- el
                     * modelo no calcula horarios, los copia. Dos formas de ser válido: coincide
                     * con la reserva preventiva vigente (grupo 306), o coincide con uno de los
                     * próximos huecos reales recalculados con CloserAgendaService (grupo 307,
                     * prompt 02). */
                    $agenda_service = app(CloserAgendaService::class);

                    $hold_start   = null;
                    $hold_vigente = false;
                    if (! empty($lead->closer_hold_event_id)) {
                        $hold_vigente = $agenda_service->is_hold_still_valid($lead);
                        if ($hold_vigente) {
                            $google_oauth_service_hold = app(GoogleCalendarOAuthService::class);
                            $calendar_service_hold     = new CloserGoogleCalendarEventService(
                                $google_oauth_service_hold,
                                new CloserGoogleCalendarBusyService($google_oauth_service_hold)
                            );
                            [$hold_start, ] = $calendar_service_hold->get_call_event_times($lead);
                        }
                    }

                    $inicio_coincide_con_hold = $hold_vigente && $hold_start !== null
                        && $hold_start->format('Y-m-d H:i') === $inicio_parsed->format('Y-m-d H:i');

                    $slot_ofrecido_valido = false;
                    if (! $inicio_coincide_con_hold) {
                        foreach ($agenda_service->next_slots(8) as $slot_candidato) {
                            if ($slot_candidato['inicio']->format('Y-m-d H:i') === $inicio_parsed->format('Y-m-d H:i')) {
                                $slot_ofrecido_valido = true;
                                break;
                            }
                        }
                    }

                    if (! $inicio_coincide_con_hold && ! $slot_ofrecido_valido) {
                        Log::channel('disponibilidad')->error(
                            '[CLOSER_AGENDA] agendar_llamada_closer DESCARTADO: el horario confirmado no sale de los huecos reales.',
                            [
                                'lead_id'       => $lead->id,
                                'inicio_pedido' => $inicio_raw,
                            ]
                        );
                        $parsed['requiere_intervencion_humana'] = true;
                        $parsed['motivo_intervencion']           = 'El agente confirmó un horario de llamada con el closer que el sistema nunca ofreció.';
                    } else {
                        /* Se ejecuta en el bloque POST-save de más abajo: crear/promover el
                         * evento de Google requiere el lead ya persistido. */
                        $closer_call_agendar_needed    = true;
                        $closer_call_promover_hold     = $inicio_coincide_con_hold;
                        $closer_call_inicio_confirmado = $inicio_parsed;

                        /* Forzar el estado a closer_activo: es el punto de entrega al closer.
                         * Recién ACÁ se apaga el agente -- a diferencia de confirmar_fin_demo en
                         * la dinámica actual, que lo apaga al toque, acá el agente siguió vivo
                         * hasta tener la llamada agendada de verdad (criterio de éxito 11). */
                        $estado_raw      = 'closer_activo';
                        $pipeline_status = LeadPipelineStatus::ensure_exists($estado_raw);
                        $estado          = $pipeline_status->slug;
                        $suggested_lead_status = $estado !== $previous_status ? $estado : null;
                        $lead->claude_auto_reply = false;

                        Log::info('LeadAiService: llamada con el closer confirmada por el lead.', [
                            'lead_id' => $lead->id,
                            'inicio'  => $inicio_parsed->toDateTimeString(),
                        ]);
                    }
                }
            }
        }

        /* Acción: el lead dice que no quiere avanzar con la llamada del closer (grupo 307,
         * prompt 03, dinámica nueva). Libera la reserva preventiva (si había) -- es el caso más
         * frecuente de reserva colgada comiéndole un hueco a Tommy -- y deja el lead donde lo
         * mire un humano, reusando el mecanismo ya existente de requiere_intervencion_humana. */
        $descartar_llamada = ! empty($parsed['descartar_llamada_closer']);
        if ($descartar_llamada && $lead->usa_experiencia_demo_nueva() && (string) $lead->status === 'demo_realizada') {
            $motivo_descarte = isset($parsed['motivo_descarte_llamada']) && trim((string) $parsed['motivo_descarte_llamada']) !== ''
                ? trim((string) $parsed['motivo_descarte_llamada'])
                : 'El lead no quiere avanzar con la llamada del closer.';

            if (! empty($lead->closer_hold_event_id)) {
                $closer_hold_release_needed = true;
            }

            $lead->claude_auto_reply = false;

            $parsed['requiere_intervencion_humana'] = true;
            $parsed['motivo_intervencion']           = $motivo_descarte;

            Log::info('LeadAiService: lead descartó la llamada con el closer.', [
                'lead_id' => $lead->id,
                'motivo'  => $motivo_descarte,
            ]);
        }

        /* --- Fin de acciones estructuradas --- */

        /* Acción: crear tarea de alerta si Claude detectó que se requiere intervención humana. */
        $requiere_intervencion = ! empty($parsed['requiere_intervencion_humana']);
        $motivo_intervencion   = isset($parsed['motivo_intervencion']) ? trim((string) $parsed['motivo_intervencion']) : '';

        if ($requiere_intervencion) {
            // Persistir la flag de intervención humana y desactivar respuesta automática de Claude.
            // Ambos campos se salvan en el único $lead->save() de más abajo.
            $lead->requiere_intervencion_humana = true;
            $lead->claude_auto_reply            = false;

            /* La tarea vive en su propio método porque este mismo bloque lo necesita también el
             * freno por horario ya no disponible (frenar_por_horario_no_disponible()): ese camino
             * marca el lead igual que este, y sin tarea el único aviso sería un toast en el
             * navegador del admin que apretó aprobar. Extraído sin cambiarle el comportamiento. */
            $this->crear_tarea_de_intervencion_humana($lead, $motivo_intervencion);

            /* Notificar por WhatsApp a los admins suscritos a escalaciones de lead.
             * Se ejecuta en bloque separado para que un fallo en WhatsApp no afecte
             * el AdminTask ya creado ni el flujo principal del mensaje. */
            try {
                $escalation_service = new \App\Services\LeadEscalationWhatsappService(
                    new \App\Services\WhatsappSendService()
                );
                $escalation_notified = $escalation_service->notify($lead, $motivo_intervencion);
                if (! empty($escalation_notified)) {
                    $admin_notifications_log[] = ['evento' => 'Escalación a humano requerida', 'admins' => $escalation_notified];
                }
            } catch (\Throwable $e) {
                Log::error('LeadAiService: error al notificar escalación por WhatsApp.', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        /*
         * REGLA DE NEGOCIO (1/7/2026, decisión de Lucas): desde que un lead entra a coordinar la
         * agenda de la demo hasta que llega a closer_activo, todo mensaje que arma Claude requiere
         * revisión humana antes de salir — es el tramo de mayor riesgo (bugs de colisión de
         * horario y confusión de fecha, ver prompts 226/227) y de leads más valiosos. Se fuerza
         * sin importar lo que haya devuelto Claude en su propio campo requiere_verificacion.
         * Se evalúa acá, al final de la función, sobre el $estado ya recalculado por todas las
         * inferencias conversacionales de arriba (confirmar_ingreso, confirmar_fin_demo,
         * marcar_no_ingreso, colisión/slot inválido) — es el valor que realmente termina
         * aplicándose al lead como suggested_lead_status al enviarse el mensaje, no el estado
         * crudo que sugirió Claude en un primer momento. closer_activo en adelante ya es 100%
         * manual (Tommy), no se toca acá.
         *
         * Cuando $for_approval es true, este bloque se salta: el paquete ya pasó por
         * requires_agendamiento_verification_gate() y fue aprobado por un humano, así que no
         * corresponde volver a marcarlo como pendiente de verificación (ver apply_pending_actions()).
         */
        /*
         * Regla de negocio (actualizada 15/7/2026, prompt 407): la verificación ya no depende de si
         * el lead salió de la "zona automática" por estado. Ahora la maneja el flag por-lead
         * requiere_verificacion_mensajes (toggle manual / auto-encendido al entrar al tramo de
         * agenda, ver Lead::booted, prompt 406), sumado al tramo de agenda propiamente dicho
         * (ESTADOS_REQUIEREN_SUPERVISION_AGENDAMIENTO), que sigue forzando verificación como
         * respaldo del gate de agendamiento.
         */
        if (! $for_approval && ((bool) $lead->requiere_verificacion_mensajes
            || in_array($estado, self::ESTADOS_REQUIEREN_SUPERVISION_AGENDAMIENTO, true))) {
            $req_verif = true;
        }

        /* Payload común a la creación (mensaje nuevo) y a la actualización in-place (aprobación
         * de un paquete diferido, ver $existing_message). calendar_snapshot: si esta llamada no
         * consultó disponibilidad de nuevo (por ejemplo al aprobar un agendar_demo ya resuelto),
         * conservar el snapshot que ya tenía el mensaje pendiente en vez de pisarlo con null. */
        /*
         * FIX (prompt 275): al aprobar un mensaje diferido, el estado del lead ya fue avanzado en la
         * creación del mensaje (ej. solicita_disponibilidad se aplica al toque en
         * create_pending_agendamiento_message). Recalcular suggested_lead_status contra ese estado ya
         * avanzado lo deja en null y borra el chip de cambio de estado que el mensaje mostró al crearse.
         * Cuando esto pasa (aprobación de un mensaje existente y el recálculo lo anularía), preservar el
         * valor que el mensaje ya tenía guardado.
         */
        /*
         * FIX (prompt 409): si el admin eligió a propósito un estado distinto al de Claude y ese
         * estado coincide con el actual del lead (suggested_lead_status quedó null = "no mover"), NO
         * restaurar el estado crudo de Claude. De lo contrario apply_suggested_pipeline_status() lo
         * re-aplicaría al enviar y pisaría la decisión del admin de conservar el estado.
         */
        if ($for_approval && $existing_message !== null
            && $suggested_lead_status === null
            && ! empty($existing_message->suggested_lead_status)
            && ! $agendar_descartado_por_slot_invalido
            && ! $admin_override_estado) {
            $suggested_lead_status = $existing_message->suggested_lead_status;
        }

        /* Registro de acciones ejecutadas por este mensaje, para mostrarlas en la burbuja una vez
         * aprobado/enviado (prompt 277). Se computa desde el mismo $parsed que se acaba de aplicar.
         * Se pasa $previous_status como "lead_status" de referencia para que el ítem "Cambiar estado"
         * refleje el cambio respecto al estado que el lead tenía ANTES de aplicar este mensaje. */
        /* Coherencia con el path correctivo de slot inválido: si el agendar se descartó, el
         * resumen no debe listar "Agendar demo" ni "Cambiar estado a Demo agendada" (que salían
         * del $parsed original de Claude). Se computa sobre una copia saneada que refleja lo que
         * realmente pasó: sin agendar_demo y con el estado neutro que se forzó. */
        $parsed_para_resumen = $parsed;
        if ($agendar_descartado_por_slot_invalido) {
            unset($parsed_para_resumen['agendar_demo']);
            $parsed_para_resumen['estado_sugerido'] = 'solicita_disponibilidad';
        }
        $applied_actions_summary = \App\Models\LeadMessage::build_actions_summary($parsed_para_resumen, $previous_status);

        $message_payload = [
            'lead_id'               => $lead->id,
            'sender'                => 'sistema',
            'content'               => $mensaje,
            'ai_reasoning'          => $razonamiento,
            /* Snapshot de eventos Google del closer al ofrecer disponibilidad (debug admin-spa). */
            'calendar_snapshot'     => $calendar_snapshot
                ? json_encode($calendar_snapshot, JSON_UNESCAPED_UNICODE)
                : ($existing_message ? $existing_message->calendar_snapshot : null),
            'suggested_lead_status'           => $suggested_lead_status,
            /* Marca en el mensaje si el agente confirmó ingreso/fin de demo en esta respuesta. */
            'marca_demo_ingreso_confirmado'   => $notificar_ingreso_confirmado,
            'marca_demo_terminada_confirmada' => $notificar_fin_confirmado,
            'status'                          => 'sugerido',
            'is_followup'           => $is_followup,
            'requiere_verificacion' => $req_verif,
            'sent_at'               => null,
            /* Acciones efectivamente aplicadas por este mensaje (prompt 277), persistidas para
             * seguir mostrándose en la burbuja aunque pending_actions ya se haya limpiado a null. */
            'applied_actions_summary' => ! empty($applied_actions_summary) ? $applied_actions_summary : null,
            /* Horarios que el TEXTO de este mensaje declara ofrecer (grupo 306, prompt 04). A
             * diferencia de pending_actions, esto NO se limpia cuando el mensaje ya aplicó sus
             * acciones: el envío real sigue pasando por LeadSuggestionSendService::send_suggestion(),
             * que revalida este campo justo antes de mandar por WhatsApp. */
            'horarios_ofrecidos'      => array_key_exists('horarios_ofrecidos', $parsed) ? $parsed['horarios_ofrecidos'] : null,
        ];

        if ($existing_message !== null) {
            /* Ya se aplicaron las acciones: limpiar pending_actions para que no vuelva a ofrecerse
             * (y para que la burbuja en admin-spa deje de mostrar el aviso de "acciones pendientes"). */
            $message_payload['pending_actions'] = null;
            $existing_message->update($message_payload);
            $msg = $existing_message;
        } else {
            $msg = LeadMessage::create($message_payload);
        }

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
         * Cuatro escenarios posibles:
         *   1. Solo cancelar_demo sin agendar_demo: eliminar el evento existente.
         *   2. cancelar_demo + agendar_demo (reagendado): eliminar el viejo y crear el nuevo.
         *   3. Solo agendar_demo (primer agendado): crear el evento nuevo.
         *   4. Reserva preventiva del closer (grupo 306, prompt 05, dinámica nueva) a liberar por
         *      cancelación/reagendado o por no-ingreso — independiente de los tres de arriba.
         */
        if ($google_event_delete_needed || $google_event_create_needed || $closer_hold_release_needed || $closer_hold_mark_demo_en_curso_needed) {
            try {
                $google_oauth_service = app(GoogleCalendarOAuthService::class);
                $google_event_service = new CloserGoogleCalendarEventService(
                    $google_oauth_service,
                    new CloserGoogleCalendarBusyService($google_oauth_service)
                );

                if ($google_event_delete_needed) {
                    // Eliminar el evento anterior usando el ID guardado antes de limpiar el lead.
                    // (google_event_id ya está null en el lead por lo que pasamos el ID guardado).
                    $google_event_service->delete_event_by_id(
                        $google_event_id_anterior,
                        $google_event_demo_date_anterior
                    );
                }

                if ($closer_hold_release_needed) {
                    // Lead fresco: closer_hold_event_id todavía está cargado en la base (no se
                    // limpió en memoria arriba), release_hold_for_lead() lo borra y persiste.
                    // $closer_hold_demo_date_anterior cubre el caso cancelación/reagendado, donde
                    // demo_date ya está en null en ese lead fresco.
                    $google_event_service->release_hold_for_lead($lead->fresh(), $closer_hold_demo_date_anterior);
                }

                if ($closer_hold_mark_demo_en_curso_needed) {
                    /* Gate por dinámica (grupo 306, prompt 07): los leads de la dinámica actual
                     * nunca tienen reserva preventiva (create_hold_for_lead() solo corre para
                     * demo_experiencia = nueva) -- mark_hold_as_demo_en_curso() ya es un no-op sin
                     * closer_hold_event_id, pero el gate explícito documenta la intención y evita
                     * un fresh() + llamada de más para el caso más común (dinámica actual). */
                    $lead_fresco_ingreso = $lead->fresh();
                    if ($lead_fresco_ingreso->usa_experiencia_demo_nueva()) {
                        $google_event_service->mark_hold_as_demo_en_curso($lead_fresco_ingreso);
                    }
                }

                if ($google_event_create_needed) {
                    /* Gate por dinámica (grupo 306, prompt 05): en la dinámica nueva el closer no
                     * participa de la decisión de cuándo se hace la demo, así que no se le crea
                     * una llamada confirmada con invitación — solo una reserva preventiva
                     * condicional (create_hold_for_lead()). La dinámica actual sigue exactamente
                     * igual que siempre. */
                    $lead_fresco = $lead->fresh();
                    if ($lead_fresco->usa_experiencia_demo_nueva()) {
                        $google_event_service->create_hold_for_lead($lead_fresco);
                    } else {
                        // Crear el nuevo evento usando el lead fresco con los datos de demo persistidos.
                        $google_event_service->create_event_for_lead($lead_fresco);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('LeadAiService: error en operaciones de Google Calendar del closer.', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        /*
         * Coordinación de la llamada del closer (grupo 307, prompt 03): promueve la reserva
         * preventiva a llamada real, o crea un evento nuevo en el horario que el lead confirmó, y
         * crea (o reutiliza, idempotencia) la LeadCall correspondiente. Después del save() del
         * lead por el mismo motivo que el resto de las operaciones de Google Calendar de arriba:
         * necesita demo_date/demo_start_time ya persistidos para calcular el rango del hold.
         */
        if ($closer_call_agendar_needed) {
            try {
                $lead_fresco_llamada = $lead->fresh();

                $google_oauth_service_llamada = app(GoogleCalendarOAuthService::class);
                $google_event_service_llamada = new CloserGoogleCalendarEventService(
                    $google_oauth_service_llamada,
                    new CloserGoogleCalendarBusyService($google_oauth_service_llamada)
                );

                if ($closer_call_promover_hold) {
                    $resultado_evento_llamada = $google_event_service_llamada->promote_hold_to_call($lead_fresco_llamada);
                } else {
                    /* No hay reserva vigente para este horario: liberar la vieja si existía (si
                     * no, le queda un bloqueo fantasma a Tommy comiéndole un hueco) y crear el
                     * evento en el horario que el lead confirmó. */
                    if (! empty($lead_fresco_llamada->closer_hold_event_id)) {
                        $google_event_service_llamada->release_hold_for_lead($lead_fresco_llamada);
                    }

                    $duracion_llamada_closer = LeadDemoSettings::get_duracion_llamada_closer_minutos();
                    $evento_fin_llamada      = $closer_call_inicio_confirmado->copy()->addMinutes($duracion_llamada_closer);

                    $resultado_evento_llamada = $google_event_service_llamada->create_call_event_at(
                        $lead_fresco_llamada->fresh(),
                        $closer_call_inicio_confirmado,
                        $evento_fin_llamada
                    );
                }

                /* Best-effort, igual que LeadCallService::create_new_call_now(): si Google falló,
                 * la LeadCall igual se crea (sin meet_url/google_event_id) para que quede
                 * registrada la intención de llamada; no se pierde el agendamiento por un fallo
                 * de la API externa. */
                $call_service_llamada = app(\App\Services\LeadCallService::class);
                $call_service_llamada->schedule_closer_call(
                    $lead_fresco_llamada,
                    $closer_call_inicio_confirmado,
                    $resultado_evento_llamada['google_event_id'] ?? null,
                    $resultado_evento_llamada['meet_url'] ?? null
                );

                $notificar_llamada_agendada = true;

                Log::channel('disponibilidad')->info(
                    '[CLOSER_AGENDA] Llamada del closer coordinada por el agente.',
                    [
                        'lead_id'   => $lead->id,
                        'inicio'    => $closer_call_inicio_confirmado->toDateTimeString(),
                        'promovido' => $closer_call_promover_hold,
                    ]
                );
            } catch (\Throwable $e) {
                Log::error('LeadAiService: error al coordinar la llamada del closer.', [
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
                $ingreso_notified = $ciclo_service->notify_ingreso_confirmado($lead->fresh());
                if (! empty($ingreso_notified)) {
                    $admin_notifications_log[] = ['evento' => 'Ingreso a demo confirmado', 'admins' => $ingreso_notified];
                }
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
                $fin_notified = $ciclo_service->notify_fin_confirmado($lead->fresh());
                if (! empty($fin_notified)) {
                    $admin_notifications_log[] = ['evento' => 'Fin de demo confirmado', 'admins' => $fin_notified];
                }
            } catch (\Throwable $e) {
                Log::error('LeadAiService: error al notificar fin_confirmado a admins.', [
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
                $no_ingreso_notified = $ciclo_service->notify_no_ingreso($lead->fresh(), 'el lead indicó que no podía ingresar');
                if (! empty($no_ingreso_notified)) {
                    $admin_notifications_log[] = ['evento' => 'Lead no pudo ingresar a la demo', 'admins' => $no_ingreso_notified];
                }
            } catch (\Throwable $e) {
                Log::error('LeadAiService: error al notificar no_ingreso a admins.', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        if ($notificar_llamada_agendada) {
            /* Notificar a Tommy por los mismos canales que ya se usan para el escalado a
             * humano (grupo 307, prompt 03) -- no hay un canal específico de "llamada agendada",
             * y este es exactamente ese caso: el lead pasa a manos del closer. */
            try {
                $escalation_service_llamada = new \App\Services\LeadEscalationWhatsappService(
                    new \App\Services\WhatsappSendService()
                );
                $llamada_notified = $escalation_service_llamada->notify(
                    $lead->fresh(),
                    'El lead confirmó la llamada post-demo. Revisá el calendario para coordinarla.'
                );
                if (! empty($llamada_notified)) {
                    $admin_notifications_log[] = ['evento' => 'Llamada con el closer agendada', 'admins' => $llamada_notified];
                }
            } catch (\Throwable $e) {
                Log::error('LeadAiService: error al notificar llamada agendada al closer.', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        /*
         * Disparar Mail 1 (videos + acceso a la demo) en dos casos:
         *   1. $email_nuevo: primera vez que el lead da su email — comportamiento original.
         *   2. FIX (1/7/2026): $es_reagendado y el lead ya tiene email cargado — antes esto NO
         *      reenviaba el mail (solo se reenviaba el email_nuevo), mientras que el evento de
         *      Google Calendar SÍ se recreaba con el horario nuevo. El lead quedaba con un
         *      invite de Calendar actualizado y el mail de la demo desactualizado/perdido.
         *
         * FIX (prompt 251, 3/7/2026): ninguno de los dos casos alcanza por sí solo — además
         * se exige $demo_confirmada_este_turno (ver flag más arriba). Sin esto, un
         * guardar_email suelto (sin agendar_demo validado en el mismo turno, ej. un dato
         * raro colado en el historial) disparaba el mail con datos de acceso a una demo que
         * no existía. Con reagendado, evita el mismo problema si Claude cancela
         * (cancelar_demo) sin coordinar el horario nuevo en la misma respuesta.
         */
        /*
         * FIX (hueco #3, 6/7/2026, prompt 267): $demo_confirmada_este_turno (prompt 251) solo es
         * true cuando agendar_demo se valida EN ESTE MISMO turno. Eso dejaba un hueco: si la demo
         * ya había quedado agendada en un turno anterior y el email llega recién ahora (turno
         * distinto, sin agendar_demo en el $parsed actual), el mail nunca se disparaba porque
         * $demo_confirmada_este_turno quedaba en false — el caso del lead #3. Se agrega
         * $lead_ya_agendado como fuente alternativa: si el lead ya está en demo_agendada o una
         * etapa posterior del ciclo de demo, hay un slot confirmado real (de este turno o de uno
         * anterior) y el mail puede salir igual. No reemplaza $demo_confirmada_este_turno: ambas
         * protegen contra el caso original (guardar_email suelto sin ningún slot confirmado, ni
         * ahora ni antes).
         */
        $lead_ya_agendado = in_array((string) $lead->status, [
            'demo_agendada',
            'demo_pendiente_de_ingreso',
            'ingresando_demo',
            'demo_en_curso',
            'demo_pendiente_de_terminar',
        ], true);
        $demo_lista_para_mail  = $demo_confirmada_este_turno || $lead_ya_agendado;
        /*
         * FIX (prompt 320): `enviar_mail_demo` es una flag del paquete efectivo de acciones
         * (viene de final_actions del admin al aprobar). Si no está presente (flujos viejos, o
         * ejecución automática sin aprobación editada), se comporta como hoy: enviar siempre que
         * corresponda. Si el admin la puso en false, se suprime el Mail 1 aunque el resto de las
         * condiciones (demo lista + email nuevo/reagendado) se cumplan.
         */
        $enviar_mail_demo_flag = array_key_exists('enviar_mail_demo', $parsed) ? (bool) $parsed['enviar_mail_demo'] : true;
        /*
         * FIX (prompt 411): permitir que el check "enviar Mail 1" FUERCE el envío, no solo lo suprima.
         * Antes $enviar_mail_demo_flag solo entraba como `&& $flag` sobre un disparo que exigía
         * $email_nuevo o reagendado; si el lead ya tenía el email cargado y se agendaba la demo desde
         * el panel, tildar el check no mandaba nada. Ahora, SOLO en aprobación humana ($for_approval,
         * para no cambiar el flujo automático de Claude), con el check en true, demo lista, email
         * cargado y el Mail 1 todavía no enviado (demo_mail_sent_at vacío), se dispara igual. La
         * guardia de demo_mail_sent_at evita reenvíos accidentales.
         */
        $mail_forzado_por_admin = $for_approval
            && array_key_exists('enviar_mail_demo', $parsed)
            && (bool) $parsed['enviar_mail_demo'] === true
            && $demo_lista_para_mail
            && ! empty($lead->email)
            && empty($lead->demo_mail_sent_at);
        /*
         * FIX (correctivo grupo 308/prompt 03): en la dinámica nueva el Mail 1 nunca sale -- manda
         * credenciales (usuario/contraseña de acceso) que en esta dinámica no existen, el acceso
         * es el link de la página inmersiva. Es una decisión del SERVIDOR, no del modelo: se
         * gatea la expresión entera (incluido $mail_forzado_por_admin) para que ni siquiera el
         * agente devolviendo enviar_mail_demo: true lo dispare. El reenvío manual explícito
         * (reenviar_mail_demo, grupo 212) queda AFUERA de este gate a propósito -- es la salida
         * de emergencia de Lucas, se resuelve más abajo, en su propio bloque.
         */
        $debe_enviar_mail_demo = ! $lead->usa_experiencia_demo_nueva() && (
            (
                $demo_lista_para_mail
                && ($email_nuevo || ($es_reagendado && ! empty($lead->email)))
                && $enviar_mail_demo_flag
            ) || $mail_forzado_por_admin
        );
        if ($debe_enviar_mail_demo) {
            try {
                $lead->loadMissing('demo');
                $mailable = \App\Mail\Helpers\LeadDemoMailHelper::build($lead);
                \Illuminate\Support\Facades\Mail::to($lead->email)->send($mailable);
                $lead->update(['demo_mail_sent_at' => AppTime::now()]);
                Log::info('LeadAiService: Mail 1 enviado.', [
                    'lead_id'       => $lead->id,
                    'email'         => $lead->email,
                    'es_reagendado' => $es_reagendado,
                    'email_nuevo'   => $email_nuevo,
                ]);
                $admin_notifications_log[] = [
                    'evento' => $email_nuevo ? 'Mail de demo enviado' : 'Mail de demo reenviado (reagendado)',
                    'admins' => [],
                ];
            } catch (\Throwable $e) {
                Log::error('LeadAiService: error al enviar Mail 1.', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        /*
         * FIX (grupo 212, prompt 01, 24/7/2026 — bug real, lead #451): reenvío explícito del
         * Mail 1 a pedido del lead (ver build_demo_access_context() en build_user_content()). Es
         * un camino independiente del envío automático de arriba: ahí solo se manda en
         * agendamiento/reagendado, y una vez que demo_mail_sent_at ya está cargado no había forma
         * de reenviarlo desde la conversación. Acá SÍ se reenvía aunque demo_mail_sent_at ya esté
         * seteado, con una guardia anti-ráfaga de 5 minutos.
         */
        // Flag sugerida por Claude (o forzada por el admin vía final_actions/apply_pending_actions).
        $reenviar_mail_flag = ! empty($parsed['reenviar_mail_demo']);

        // Evitar el doble envío: si ya se mandó el Mail 1 en este mismo paquete (arriba), no reenviar de nuevo.
        if ($reenviar_mail_flag && ! $debe_enviar_mail_demo) {
            // Datos mínimos indispensables para poder armar y mandar el mail sin romper el helper/blade.
            $tiene_datos_para_reenviar = ! empty($lead->email)
                && ! empty($lead->demo_id)
                && ! empty($lead->doc_number)
                && ! empty($lead->demo_date);

            if ($tiene_datos_para_reenviar) {
                // Guardia anti-ráfaga: si el Mail 1 se mandó hace menos de 5 minutos, no reenviar
                // (evita que dos mensajes seguidos del lead disparen dos mails idénticos).
                $reenviado_recientemente = $lead->demo_mail_sent_at
                    && $lead->demo_mail_sent_at->diffInMinutes(AppTime::now()) < 5;

                if ($reenviado_recientemente) {
                    Log::warning('LeadAiService: se pidió reenviar el Mail 1 pero se envió hace menos de 5 minutos, se omite.', [
                        'lead_id'           => $lead->id,
                        'demo_mail_sent_at' => optional($lead->demo_mail_sent_at)->toIso8601String(),
                    ]);
                } else {
                    try {
                        $lead->loadMissing('demo');
                        $mailable = \App\Mail\Helpers\LeadDemoMailHelper::build($lead);
                        \Illuminate\Support\Facades\Mail::to($lead->email)->send($mailable);
                        $lead->update(['demo_mail_sent_at' => AppTime::now()]);
                        Log::info('LeadAiService: Mail 1 reenviado a pedido del lead.', [
                            'lead_id' => $lead->id,
                            'email'   => $lead->email,
                        ]);
                        $admin_notifications_log[] = [
                            'evento' => 'Mail de demo reenviado (pedido del lead)',
                            'admins' => [],
                        ];
                    } catch (\Throwable $e) {
                        Log::error('LeadAiService: error al reenviar el Mail 1 a pedido del lead.', [
                            'lead_id' => $lead->id,
                            'error'   => $e->getMessage(),
                        ]);
                    }
                }
            } else {
                // Faltan datos indispensables: no reenviar y derivar a intervención humana enumerando qué falta.
                $faltantes_reenvio = [];
                if (empty($lead->email)) {
                    $faltantes_reenvio[] = 'email';
                }
                if (empty($lead->demo_id)) {
                    $faltantes_reenvio[] = 'demo asignada';
                }
                if (empty($lead->doc_number)) {
                    $faltantes_reenvio[] = 'documento de prueba';
                }
                if (empty($lead->demo_date)) {
                    $faltantes_reenvio[] = 'fecha de demo';
                }

                Log::warning('LeadAiService: se pidió reenviar el Mail 1 pero faltan datos, se deriva a intervención humana.', [
                    'lead_id'   => $lead->id,
                    'faltantes' => $faltantes_reenvio,
                ]);

                /* Mismo mecanismo que ya usa el archivo para setear esta flag desde $parsed (ver
                 * guardar_email sin agendar_demo, más arriba en este método). El bloque que crea
                 * la AdminTask de intervención humana ya corrió para este paquete antes de llegar
                 * acá, así que además de dejar la marca en $parsed (por consistencia/auditoría) se
                 * persiste directo sobre el lead para que quede efectivamente marcado. */
                $parsed['requiere_intervencion_humana'] = true;
                $parsed['motivo_intervencion'] = 'Se pidió reenviar el Mail 1 de demo pero faltan datos: '
                    . implode(', ', $faltantes_reenvio) . '.';
                $lead->update([
                    'requiere_intervencion_humana' => true,
                    'claude_auto_reply'            => false,
                ]);
            }
        }

        /*
         * Notificar cuando la sugerencia requiere verificación manual. Dos motivos posibles,
         * dos servicios distintos (ver prompt 230):
         *   - Agendamiento: el lead está en el tramo solicita_disponibilidad..demo_pendiente_de_terminar
         *     (regla de negocio forzada más arriba en este método, no un error). Push siempre +
         *     WhatsApp opcional vía notify_verificacion_agendamiento_whatsapp.
         *   - Error: cualquier otro caso (ej. fallback de disponibilidad). WhatsApp vía el flag
         *     viejo notify_verificacion_whatsapp, comportamiento sin cambios.
         */
        if ($req_verif) {
            try {
                if (in_array($estado, self::ESTADOS_REQUIEREN_SUPERVISION_AGENDAMIENTO, true)) {
                    $agendamiento_service = new \App\Services\LeadVerificacionAgendamientoNotificationService(
                        new \App\Services\WhatsappSendService()
                    );
                    $verif_notified = $agendamiento_service->notify($lead->fresh(), $msg);
                    $evento_label   = 'Requiere verificación (coordinando agenda)';

                    /* Sonido en el navegador para admins con la pestaña abierta (canal aparte del push/WhatsApp). */
                    event(new \App\Events\LeadVerificacionAgendamientoAlert($lead->fresh(), $msg));
                } else {
                    $verificacion_service = new \App\Services\LeadVerificacionWhatsappService(
                        new \App\Services\WhatsappSendService()
                    );
                    $verif_notified = $verificacion_service->notify($lead->fresh(), $msg);
                    $evento_label   = 'Requiere verificación humana';
                }
                if (! empty($verif_notified)) {
                    $admin_notifications_log[] = ['evento' => $evento_label, 'admins' => $verif_notified];
                }
            } catch (\Throwable $e) {
                Log::error('LeadAiService: error al notificar verificacion pendiente.', [
                    'lead_id'    => $lead->id,
                    'message_id' => $msg->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        /* Persistir el resumen de notificaciones a admins disparadas por este mensaje, si hubo alguna. */
        if (! empty($admin_notifications_log)) {
            $msg->update(['admin_notifications' => $admin_notifications_log]);
        }

        /* Programar auto-envío antes del broadcast: el payload Pusher debe incluir ai_auto_send_at.
         * Si $for_approval es true, LeadSuggestionSendService::send_suggestion() ya va a enviar el
         * mensaje a continuación en el mismo request: programar un timer acá sería redundante. */
        if (! $for_approval) {
            (new LeadAiSuggestionAutoSendScheduler())->schedule_for_suggested_message($msg);
            $msg = $msg->fresh();
        }

        // Notificar a admin-spa vía socket para actualizar la fila del lead en tiempo real.
        LeadSuggestionCreated::dispatch($lead->id);
        LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $msg->id);

        return $msg;
    }

    /**
     * Convierte "HH:MM" (o "H:MM") a minutos desde medianoche, o null si no es una hora legible.
     *
     * Contraparte de format_minutes_to_hhmm(). Existe para que el clasificador del motivo y el
     * reagendado no repitan la misma regex tolerante que ya usa el resto del archivo.
     *
     * @param string $hora
     *
     * @return int|null
     */
    private function hhmm_a_minutos(string $hora): ?int
    {
        if (! preg_match('/^\s*(\d{1,2}):(\d{2})\s*$/', $hora, $m)) {
            return null;
        }

        $minutos = (int) $m[1] * 60 + (int) $m[2];

        return ($minutos < 0 || $minutos > self::FIN_DEL_DIA_MINUTOS) ? null : $minutos;
    }

    /**
     * Traduce a una frase en castellano llano POR QUÉ se descartó un horario, para que el prompt
     * correctivo pueda dárselo al modelo en vez de dejarlo inventar la causa.
     *
     * 🔴 Nunca devuelve "se ocupó". El sistema no puede distinguir hoy "lo tomó otro lead" de
     * "nunca fue un slot de la grilla" sin recalcular, y afirmarlo es exactamente el bug que Lucas
     * reportó el 25/8/2026 (lead Brisa: el horario había caído por el margen y el modelo escribió
     * "uh, justo se ocupó el de las 17:05"). Cuando no se sabe, la frase es descriptiva ("no figura
     * entre los disponibles") y el prompt le prohíbe explicar.
     *
     * El orden de evaluación va de la causa más específica a la más genérica: primero la fecha,
     * después la franja extendida, después el reloj (ya arrancó / está demasiado cerca), y recién
     * al final las dos descriptivas.
     *
     * @param Lead     $lead                Lead que está aceptando el horario (decide el margen).
     * @param string   $demo_date           Fecha pedida, en Y-m-d.
     * @param string   $demo_start          Horario pedido, en HH:MM.
     * @param string[] $slots_de_esa_fecha  Slots realmente disponibles para esa demo y fecha.
     * @param bool     $fecha_en_ventana    Si la fecha estaba dentro de la ventana consultada.
     * @param bool     $ventana_invalida    Si lo que falló fue la franja extendida, no la hora.
     *
     * @return string Frase llana, sin punto final, lista para inyectar en el prompt.
     */
    private function motivo_real_del_horario_descartado(Lead $lead, string $demo_date, string $demo_start, array $slots_de_esa_fecha, bool $fecha_en_ventana, bool $ventana_invalida): string
    {
        if (! $fecha_en_ventana) {
            return 'la fecha que pidió no está dentro de la ventana de disponibilidad que consultamos';
        }

        if ($ventana_invalida) {
            return 'la franja extendida que se le había prometido ya no entra completa';
        }

        $slot_min = $this->hhmm_a_minutos($demo_start);
        $es_hoy   = ($demo_date !== '' && $demo_date === AppTime::now()->format('Y-m-d'));

        if ($es_hoy && $slot_min !== null) {
            $now_min = (int) AppTime::now()->format('H') * 60 + (int) AppTime::now()->format('i');

            if ($slot_min < $now_min) {
                /* El caso central de esta misión: el horario ya arrancó. */
                return 'ese horario ya arrancó';
            }

            /* Mismo margen que aplica compute_day_slots_for_demo(): la setting en la dinámica
             * nueva, los 30 minutos fijos en la actual. */
            $margen = $lead->usa_experiencia_demo_nueva()
                ? LeadDemoSettings::get_demo_minimo_minutos_desde_ahora()
                : 30;

            if ($slot_min < $now_min + $margen) {
                return 'ese horario queda demasiado cerca: la demo necesita unos minutos de anticipación para prepararse';
            }
        }

        if (! empty($slots_de_esa_fecha)) {
            return 'ese horario no figura entre los disponibles de esa fecha';
        }

        return 'no quedan horarios disponibles en esa fecha';
    }

    /**
     * Llamada aislada que redacta el mensaje del REAGENDADO: el horario que el lead aceptó ya
     * arrancó, el sistema le corrió el turno al próximo slot y este texto se lo CONFIRMA.
     *
     * Es hermana de call_corrective_availability_response() —misma estructura, mismo aislamiento
     * del historial, mismo rechazo de respuesta estructurada, mismo `''` como señal de fallo— pero
     * con un prompt propio, y no se pueden unificar: el correctivo OFRECE y pide disculpas, este
     * CONFIRMA un hecho consumado; y este lleva el link de la experiencia, que allá no va nunca.
     *
     * 🔴 El prompt dice el motivo REAL y prohíbe explícitamente los inventados ("se ocupó", "se
     * llenó"). Es la misma lección del correctivo: un prompt que afirma un hecho negativo sin su
     * causa obliga al modelo a inventarla.
     *
     * Si devuelve `''` el llamador NO reagenda y no toca el paquete. Ese es el motivo por el que
     * esta llamada va ANTES de cualquier mutación: no hay estado a medias que revertir. Y no se
     * inventa un texto fijo de PHP en la voz del agente.
     *
     * @param Lead   $lead              Lead al que se le confirma el turno nuevo.
     * @param string $slot_que_arranco  Horario que el lead aceptó y que ya arrancó (HH:MM).
     * @param string $slot_nuevo        Horario que el sistema le dejó agendado (HH:MM).
     * @param string $demo_date         Fecha, en Y-m-d. Siempre hoy.
     *
     * @return string Texto del mensaje al lead, o string vacío si falló.
     */
    private function call_reagendado_al_proximo_slot_response(Lead $lead, string $slot_que_arranco, string $slot_nuevo, string $demo_date): string
    {
        try {
            $hora_actual = AppTime::now()->format('H:i');

            /*
             * Igual que en el correctivo: NO se usa build_user_content() a propósito. Ese arma el
             * prompt completo con el historial de la conversación, que es justo lo que hace que el
             * modelo vuelva a confirmar el horario viejo.
             */
            $user_content  = "El lead aceptó la demo para hoy {$demo_date} a las {$slot_que_arranco}, pero ese horario YA ARRANCÓ (ahora son las {$hora_actual}) y la demo necesita unos minutos de anticipación para prepararse.\n\n";
            $user_content .= "YA LE DEJAMOS AGENDADA la demo para HOY a las {$slot_nuevo}. Está hecho: no es una propuesta.\n\n";
            $user_content .= "Redactá el mensaje que le confirma eso, en este orden:\n";
            $user_content .= "1. Que la de las {$slot_que_arranco} ya arrancó y que necesitamos unos minutos de antelación.\n";
            $user_content .= "   🔴 Ese es el motivo REAL y es el único que podés dar. PROHIBIDO decir que \"se ocupó\", que \"se llenó\", que \"lo tomó otro\" o cualquier otra causa: es falso.\n";
            $user_content .= "2. Que se la dejaste lista para las {$slot_nuevo} de hoy. UN SOLO HORARIO. Prohibido enumerar alternativas, prohibido preguntarle si le sirve, prohibido pedirle que confirme.\n";
            $user_content .= "3. El link, copiado TEXTUAL de acá abajo.\n";
            $user_content .= $this->build_demo_experiencia_context($lead);
            $user_content .= "\n\nNunca menciones un horario que no sea {$slot_que_arranco} o {$slot_nuevo}. No devuelvas JSON ni bloques de código: sólo el texto del mensaje al lead.";

            /* Mismo system prompt que el flujo normal; max_tokens acotado a un mensaje corto. */
            $system = $this->build_system_prompt();
            $model  = (string) config('services.anthropic.model', 'claude-sonnet-4-20250514');
            $http   = $this->build_http_client();

            $response = $http->post('https://api.anthropic.com/v1/messages', [
                'model'      => $model,
                'max_tokens' => 400,
                'system'     => [
                    [
                        'type'          => 'text',
                        'text'          => $system,
                        'cache_control' => ['type' => 'ephemeral'],
                    ],
                ],
                'messages'   => [
                    ['role' => 'user', 'content' => $user_content],
                ],
            ]);

            if ($response->failed()) {
                Log::error('LeadAiService: fallo HTTP en la llamada del reagendado al próximo slot.', [
                    'lead_id' => $lead->id,
                    'status'  => $response->status(),
                    'body'    => $response->body(),
                ]);
                return '';
            }

            $texto = trim($this->extract_response_text($response->json()));

            /*
             * Si la respuesta empieza con bloque de código o JSON, el modelo ignoró la restricción
             * (intentó devolver estructura). Se trata como fallo: el llamador no reagenda.
             */
            if ($texto === '' || strncmp($texto, '```', 3) === 0 || strncmp($texto, '{', 1) === 0) {
                Log::error('LeadAiService: la llamada del reagendado devolvió contenido estructurado o vacío. No se reagenda.', [
                    'lead_id'  => $lead->id,
                    'response' => $texto,
                ]);
                return '';
            }

            return $texto;
        } catch (\Throwable $e) {
            Log::error('LeadAiService: excepción en la llamada del reagendado al próximo slot.', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
            return '';
        }
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
     * 🔴 Desde el 25/8/2026 este método sirve SOLO al camino de GENERACIÓN: los llamadores reales
     * son descartar_agendamiento_fuera_de_slots() y las ramas `$for_approval === false` de
     * apply_parsed_response(). En la APROBACIÓN ya no se reescribe nada: si el horario que el
     * mensaje confirmaba ya no está disponible, se frena y se avisa (ver
     * frenar_por_horario_no_disponible()). El porqué es de Lucas: pisar in-place el contenido de un
     * mensaje que un admin ya aprobó hace que el correctivo herede la autoría de lo que reemplazó,
     * y el lead termina recibiendo —con el nombre de esa persona— un texto que esa persona nunca
     * leyó. En generación no hay nadie que haya firmado el borrador, así que ahí pisarlo sigue
     * siendo lo correcto.
     *
     * @param Lead     $lead                Lead al que se le responde.
     * @param string   $slot_invalido       Horario alucinado que el servidor descartó (HH:MM).
     * @param string   $demo_date           Fecha de la demo propuesta (Y-m-d).
     * @param string[] $slots_disponibles   Slots realmente disponibles para esa demo y fecha.
     * @param string   $motivo_real         Frase llana con el motivo del descarte (ver
     *                                      motivo_real_del_horario_descartado()). Vacío = no se
     *                                      sabe, y entonces el prompt le prohíbe explicar.
     *
     * @return string Mensaje natural al lead, o string vacío si falló (activa fallback).
     */
    private function call_corrective_availability_response(Lead $lead, string $slot_invalido, string $demo_date, array $slots_disponibles, string $motivo_real = ''): string
    {
        try {
            /* Lista legible de alternativas para inyectar en el prompt (ej: "18:00, 19:00, 20:00"). */
            $alternativas_legibles = implode(', ', $slots_disponibles);

            /*
             * Prompt de usuario mínimo y restringido. NO se usa build_user_content() a propósito:
             * ese arma el prompt completo con historial, que es lo que hace alucinar al modelo.
             * Esta llamada debe estar completamente aislada de la conversación.
             */
            /* 🔴 El motivo real, y por qué es obligatorio que viaje. Hasta el 25/8/2026 este prompt
             * le decía al modelo "ese horario ya no está disponible" SIN DECIRLE POR QUÉ, y el
             * modelo rellenaba el hueco con la causa más plausible: le escribió a la lead Brisa
             * "Uh, justo se ocupó el de las 17:05" — y era falso, el horario estaba libre y había
             * caído por el margen de anticipación. Un prompt que afirma un hecho negativo sin su
             * causa NO deja un hueco: obliga a inventarla, y la invención sale firmada por el
             * sistema y le llega al lead como un hecho. El motivo viaja siempre, y cuando no se
             * sabe, la instrucción explícita es NO explicar. */
            $user_content = "El lead propuso agendar a las {$slot_invalido} para el {$demo_date}, pero ese horario no se pudo confirmar.\n";

            if ($motivo_real !== '') {
                $user_content .= "MOTIVO REAL, y es el único que podés dar: {$motivo_real}.\n";
                $user_content .= "🔴 PROHIBIDO inventar otra causa. Si acá no dice que se ocupó, NO digas que se ocupó ni que se llenó la agenda: es falso y ya pasó (lead Brisa, 25/8/2026).\n";
            } else {
                $user_content .= "No tenés el motivo. NO lo inventes: no expliques por qué, simplemente ofrecé el horario nuevo.\n";
            }

            /* 🔴 Los dos textos NO se unifican, y la bifurcación no es cosmética. El protocolo v2
             * de la dinámica nueva dice textual "el mensaje ofrece UN momento, no una lista"
             * ("enumerar rangos por turno era la forma vieja: alarga el mensaje, suena a robot y le
             * da al lead una decisión que no pidió tomar"); la dinámica actual, en cambio, ofrece
             * la lista. Es el mismo par que ya obligó a bifurcar el encabezado de RANGOS DEL DÍA
             * más arriba en este archivo — unificarlos rompe uno de los dos. Y lo que está en el
             * prompt se usa: pasarle la lista entera al modelo de la dinámica nueva es garantizar
             * que la enumere. */
            if ($lead->usa_experiencia_demo_nueva()) {
                $primero = ! empty($slots_disponibles) ? trim((string) reset($slots_disponibles)) : '';

                $user_content .= $primero !== ''
                    ? "El próximo horario disponible es: {$primero}. Ofrecele ESE, uno solo. PROHIBIDO enumerar alternativas ni mencionar ningún otro horario.\n"
                    : "No tenés horarios reales para ofrecer en este momento. NO inventes fechas ni horarios bajo ningún motivo — pedile al lead que te confirme qué día prefiere, para volver a consultar la disponibilidad real antes de ofrecerle algo.\n";
                $user_content .= "Redactá un mensaje natural y breve para el lead: el motivo de arriba y el horario nuevo (o el pedido de que confirme otro día, si no tenés horario real).\n";
            } else {
                $user_content .= $alternativas_legibles !== ''
                    ? "Los próximos horarios disponibles son: {$alternativas_legibles}.\n"
                    : "No tenés horarios reales para ofrecer en este momento. NO inventes fechas ni horarios bajo ningún motivo — pedile al lead que te confirme qué día prefiere, para volver a consultar la disponibilidad real antes de ofrecerle algo.\n";
                $user_content .= "Redactá un mensaje natural y breve para el lead disculpándote y ofreciéndole esas alternativas (o pidiéndole que confirme otro día, si no tenés alternativas reales).\n";
            }

            $user_content .= "No uses `agendar_demo`. Solo devolvé el texto del mensaje, sin JSON, sin estructura, solo el mensaje al lead. Nunca menciones un horario o fecha que no te haya sido dado explícitamente arriba.";

            /* Mismo system prompt que el flujo normal; max_tokens acotado a un mensaje corto. */
            $system = $this->build_system_prompt();
            $model  = (string) config('services.anthropic.model', 'claude-sonnet-4-20250514');
            $http   = $this->build_http_client();

            $response = $http->post('https://api.anthropic.com/v1/messages', [
                'model'      => $model,
                'max_tokens' => 400,
                'system'     => [
                [
                    'type'          => 'text',
                    'text'          => $system,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
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
     * Define la tool get_protocolo_recurso que Claude puede usar para pedir
     * secciones del protocolo bajo demanda.
     *
     * @return array<int, array<string, mixed>> Definición de tools para la API de Anthropic.
     */
    private function build_tools(): array
    {
        return [
            [
                'name'        => 'get_protocolo_recurso',
                'description' => 'Devuelve el contenido de un recurso del protocolo de ventas. ' .
                                 'Usá esta tool cuando necesitás información específica para ' .
                                 'responder al lead y esa información no está en tu contexto actual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'nombre' => [
                            'type'        => 'string',
                            'description' => 'Nombre del recurso. Valores válidos: ' .
                                            implode(', ', self::PROTOCOLO_RECURSOS),
                            'enum'        => self::PROTOCOLO_RECURSOS,
                        ],
                    ],
                    'required'   => ['nombre'],
                ],
            ],
        ];
    }

    /**
     * Ejecuta la tool get_protocolo_recurso y devuelve el contenido del recurso solicitado.
     *
     * @param string               $tool_name  Nombre de la tool invocada por Claude.
     * @param array<string, mixed> $tool_input Parámetros de entrada de la tool.
     * @param Lead|null            $lead       Lead en curso, para resolver la variante de la
     *                                         dinámica de demo (grupo 293). Se pasa explícito
     *                                         por parámetro (NO como propiedad de instancia):
     *                                         el servicio se resuelve del contenedor y una
     *                                         propiedad podría sobrevivir entre leads distintos
     *                                         dentro de un mismo worker de cola.
     * @return string Contenido del recurso, o mensaje de error si el recurso es desconocido.
     */
    private function execute_tool(string $tool_name, array $tool_input, ?Lead $lead = null): string
    {
        if ($tool_name !== 'get_protocolo_recurso') {
            return 'Error: tool desconocida.';
        }

        /* Validar que el nombre del recurso sea uno de los válidos. */
        $nombre = isset($tool_input['nombre']) ? (string) $tool_input['nombre'] : '';

        if (! in_array($nombre, self::PROTOCOLO_RECURSOS, true)) {
            return 'Error: recurso desconocido. Recursos válidos: ' . implode(', ', self::PROTOCOLO_RECURSOS);
        }

        /* Dinámica de demo estampada en el lead (grupo 293). Sin lead en alcance, la vigente. */
        $variante  = $lead ? $lead->demo_experiencia_efectiva() : Lead::EXPERIENCIA_ACTUAL;
        $contenido = app(WhatsappProtocolService::class)->getRecurso($nombre, $variante);

        if ($contenido === '') {
            return "El recurso '{$nombre}' no está disponible todavía. Intentá responder con la información que tenés o marcá requiere_verificacion: true.";
        }

        return $contenido;
    }

    /**
     * Ejecuta la llamada a Claude con soporte de tool use.
     *
     * Si Claude responde con tool_use, resuelve el recurso solicitado, agrega el resultado
     * al historial de mensajes y repite hasta MAX_TOOL_ITERATIONS.
     * Devuelve el texto JSON final de Claude (igual que extract_response_text devolvía antes).
     *
     * @param array<int, array<string, mixed>> $system_payload Bloque system con cache_control.
     * @param string                           $user_content   Contenido del mensaje user inicial.
     * @param int                              $max_tokens     Límite de tokens de la respuesta.
     * @param PendingRequest                   $http           Cliente HTTP configurado.
     * @param string                           $model          Modelo de Claude a usar.
     * @param Lead|null                        $lead           Lead en curso (grupo 293), threading
     *                                                          explícito hasta execute_tool() para
     *                                                          resolver la variante de protocolo
     *                                                          que le corresponde. Ver docblock de
     *                                                          execute_tool() sobre por qué NO se
     *                                                          guarda como propiedad de instancia.
     *
     * @throws \RuntimeException Si falla HTTP o se superan las iteraciones sin respuesta final.
     *
     * @return string Texto JSON de la respuesta final de Claude.
     */
    private function run_with_tools(
        array $system_payload,
        string $user_content,
        int $max_tokens,
        PendingRequest $http,
        string $model,
        ?Lead $lead = null
    ): string {
        /* Historial de mensajes del loop: arranca con el mensaje inicial del usuario. */
        $messages   = [['role' => 'user', 'content' => $user_content]];
        $tools      = $this->build_tools();
        $iterations = 0;

        while ($iterations < self::MAX_TOOL_ITERATIONS) {
            $iterations++;

            $response = $http->post('https://api.anthropic.com/v1/messages', [
                'model'      => $model,
                'max_tokens' => $max_tokens,
                'system'     => $system_payload,
                'tools'      => $tools,
                'messages'   => $messages,
            ]);

            if ($response->failed()) {
                Log::error('LeadAiService run_with_tools: Anthropic error', [
                    'status'    => $response->status(),
                    'body'      => $response->body(),
                    'iteration' => $iterations,
                ]);
                throw new \RuntimeException('Error Anthropic HTTP ' . $response->status() . ': ' . $response->body());
            }

            $data        = $response->json();
            $stop_reason = isset($data['stop_reason']) ? (string) $data['stop_reason'] : '';
            $content     = isset($data['content']) && is_array($data['content']) ? $data['content'] : [];

            /* Claude terminó sin tool_use: extraer el bloque de texto y retornar. */
            if ($stop_reason === 'end_turn') {
                foreach ($content as $block) {
                    $type = isset($block['type']) ? (string) $block['type'] : '';
                    if ($type === 'text') {
                        return (string) $block['text'];
                    }
                }
                return '';
            }

            /* Claude pausó para usar una tool: ejecutarla y continuar el loop. */
            if ($stop_reason === 'tool_use') {
                /* Agregar la respuesta de Claude (con los bloques tool_use) al historial. */
                $messages[] = ['role' => 'assistant', 'content' => $content];

                /* Procesar cada bloque tool_use y acumular los resultados. */
                $tool_results = [];
                foreach ($content as $block) {
                    $type = isset($block['type']) ? (string) $block['type'] : '';
                    if ($type !== 'tool_use') {
                        continue;
                    }

                    $tool_id    = isset($block['id'])    ? (string) $block['id']    : '';
                    $tool_name  = isset($block['name'])  ? (string) $block['name']  : '';
                    $tool_input = isset($block['input']) && is_array($block['input']) ? $block['input'] : [];

                    $recurso_nombre = isset($tool_input['nombre']) ? $tool_input['nombre'] : '?';

                    /* Variante servida (grupo 293): se loguea para poder verificar desde
                     * producción qué variante recibió cada lead, sin tocar la base. */
                    $variante_log = $lead ? $lead->demo_experiencia_efectiva() : Lead::EXPERIENCIA_ACTUAL;
                    Log::debug('LeadAiService: tool_use', [
                        'tool'     => $tool_name,
                        'recurso'  => $recurso_nombre,
                        'iter'     => $iterations,
                        'variante' => $variante_log,
                    ]);

                    $tool_result  = $this->execute_tool($tool_name, $tool_input, $lead);
                    $tool_results[] = [
                        'type'        => 'tool_result',
                        'tool_use_id' => $tool_id,
                        'content'     => $tool_result,
                    ];
                }

                /* Agregar los resultados de las tools al historial para la siguiente iteración. */
                if (! empty($tool_results)) {
                    $messages[] = ['role' => 'user', 'content' => $tool_results];
                }

                continue;
            }

            /* stop_reason inesperado (p. ej. max_tokens): loguear y salir del loop. */
            Log::warning('LeadAiService: stop_reason inesperado en run_with_tools', [
                'stop_reason' => $stop_reason,
                'iteration'   => $iterations,
            ]);
            break;
        }

        throw new \RuntimeException(
            'LeadAiService: se superaron las iteraciones de tool use (' . self::MAX_TOOL_ITERATIONS . ') sin respuesta final.'
        );
    }

    /**
     * Arma el system prompt: identidad + system prompt BD + system base modular (tool use).
     *
     * Requiere que el system base modular esté sincronizado; si no lo está, lanza
     * RuntimeException en vez de caer a un fallback (ver prompt 271).
     *
     * @throws \RuntimeException Si el system base modular no está sincronizado en BD.
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

        /*
         * El agente opera exclusivamente en modo modular (tool use): el system base (índice de
         * recursos) más los recursos que Claude pide bajo demanda. El protocolo monolítico viejo
         * (comercial/leads_protocolo_whatsapp.md) fue deprecado el 6/7/2026 (ver prompt 271): la
         * estructura modular cubre todo su contenido vigente y el viejo tenía reglas
         * desactualizadas. Ya no se usa como fallback: si el system base no está sincronizado,
         * es un error de configuración que hay que resolver sincronizando los prompts del agente,
         * NO operar con un protocolo viejo silenciosamente.
         */
        $system_base = app(WhatsappProtocolService::class)->getSystemBase();

        if ($system_base === '') {
            throw new \RuntimeException(
                'El system base modular del agente de leads no está sincronizado (whatsapp_system_base '
                . 'vacío en SyncedGithubFile). Sincronizá los prompts del agente desde Cuenta → '
                . '"Prompts desde GitHub" antes de generar sugerencias. El protocolo monolítico viejo '
                . 'fue deprecado (ver prompt 271) y ya no se usa como fallback.'
            );
        }

        /* Modo tool use: system base pequeño con índice de recursos integrado. */
        $contenido .= "\n\n" . $system_base;

        /*
         * Regla de código adicional (prompt 151): refuerza que sin JSON de disponibilidad
         * en el contexto actual el agente no puede afirmar rangos horarios propios.
         */
        $contenido .= "\n\n" . self::PROHIBICION_RANGO_HORARIO_SIN_JSON;

        return $contenido;
    }

    /**
     * Normaliza la URL de la demo del lead para asegurar protocolo absoluto (http/https), igual
     * que hace el mail de demo.
     *
     * FIX (grupo 212, prompt 01, 24/7/2026): misma lógica que
     * `LeadDemoMailHelper::normalize_mail_url()`, que es privado en esa clase y no se puede
     * reutilizar directo desde acá. Se reimplementaba localmente en vez de acoplar ambas clases;
     * si el criterio de normalización cambia, hay que actualizar los dos lugares.
     *
     * ✅ 17/8/2026: el criterio cambió (los hosts locales van por HTTP) y esa deuda se pagó. Las
     * dos copias delegan ahora en `DemoUrlNormalizer::absolute()`, que es el único lugar donde
     * vive la regla. Este método queda como envoltorio para no tocar sus llamadores.
     *
     * @param string $raw_url URL cruda tal como está guardada en `demos.erp_spa_url`.
     *
     * @return string URL normalizada con protocolo absoluto, o cadena vacía si $raw_url es vacía.
     */
    private function normalize_demo_url(string $raw_url): string
    {
        return DemoUrlNormalizer::absolute($raw_url);
    }

    /**
     * Arma el bloque "DATOS DE ACCESO DEL LEAD" que se inyecta en el contexto de Claude durante
     * todo el tramo de demo (ver `ESTADOS_REQUIEREN_SUPERVISION_AGENDAMIENTO`), no solo en
     * `ingresando_demo`.
     *
     * FIX (grupo 212, prompt 01, 24/7/2026 — bug real, lead #451): antes este bloque solo se
     * armaba dentro del `if` de `ingresando_demo`, así que un lead que pedía los datos de acceso
     * estando en cualquier otro estado del tramo (ej. `demo_agendada`) se quedaba sin esa
     * información en el contexto. Además, el link salía de `config('services.demo_url')` (clave
     * inexistente, siempre caía al default hardcodeado) en vez del link real de la demo asignada
     * al lead (`$lead->demo->erp_spa_url`, la misma que usa el Mail 1).
     *
     * @param Lead $lead Lead para el que se arma el bloque (puede no tener demo/doc_number aún).
     *
     * @return string Bloque de texto listo para concatenar al prompt, o cadena vacía si no aplica.
     */
    private function build_demo_access_context(Lead $lead): string
    {
        // Asegurar que la relación demo esté cargada antes de leerla.
        $lead->loadMissing('demo');

        // URL de la landing pública de la demo (prompt 213/02): mismo dato que ve el Mail 1
        // como link de respaldo. Solo se calcula acá (no en el caso incompleto de más abajo):
        // si todavía no hay acceso armado, mandar la landing solo confunde al lead.
        $url_landing = !empty($lead->uuid) ? route('demo.landing', ['uuid' => $lead->uuid]) : '';

        // Link real de la demo asignada al lead (no un config genérico).
        $demo_url_raw = $lead->demo ? trim((string) $lead->demo->erp_spa_url) : '';
        $demo_url     = $demo_url_raw !== '' ? $this->normalize_demo_url($demo_url_raw) : '';

        // Documento de prueba del lead: es a la vez usuario y contraseña de acceso a la demo.
        $doc_number = trim((string) ($lead->doc_number ?? ''));

        // Caso incompleto: falta el link, el documento, o los dos. Nunca inventar ni aproximar.
        if ($demo_url === '' || $doc_number === '') {
            // Enumerar explícitamente qué dato falta, para que el motivo de intervención sea claro.
            $faltantes = [];
            if ($demo_url === '') {
                $faltantes[] = 'link de la demo';
            }
            if ($doc_number === '') {
                $faltantes[] = 'documento de prueba';
            }
            $faltantes_txt = implode(' y ', $faltantes);

            return "\n\nDATOS DE ACCESO DEL LEAD: NO DISPONIBLES (falta: {$faltantes_txt}).\n"
                . "PROHIBIDO inventar un link o un documento de prueba, y PROHIBIDO prometer pasarlos\n"
                . "\"en un momento\" como si los tuvieras: no los tenés.\n"
                . "Si el lead pide los datos de acceso, respondele solo que en un momento le confirman el\n"
                . "acceso, y devolvé requiere_intervencion_humana: true con motivo_intervencion indicando\n"
                . "exactamente qué falta ({$faltantes_txt}).";
        }

        // Fecha de envío del Mail 1 (si ya se mandó), para que el agente sepa si insistir con reenviarlo.
        $mail_enviado_texto = $lead->demo_mail_sent_at
            ? 'si (' . $lead->demo_mail_sent_at->format('d/m/Y H:i') . ')'
            : 'no';

        // Caso completo: link y documento presentes, se arma el bloque completo para el agente.
        // Línea de la landing (prompt 213/02): solo si hay uuid; se agrega debajo del link de
        // la demo, dentro del mismo bloque de datos de acceso.
        $landing_linea = $url_landing !== ''
            ? "  Pagina con todo (videos + acceso): {$url_landing}\n"
            : '';

        // Instrucción adicional (prompt 213/02): cuándo y por qué ofrecer la landing como
        // alternativa al mail. Solo tiene sentido si hay landing para ofrecer.
        $landing_instruccion = $url_landing !== ''
            ? "Si el lead dice que no le llega el mail o que no lo encuentra, ademas de reenviarlo podes pasarle\n"
                . "esta pagina: tiene los mismos videos tutoriales y los mismos datos de acceso que el mail, y se abre\n"
                . "desde el celular sin instalar nada. Es la forma mas rapida de destrabarlo.\n"
            : '';

        return "\n\nDATOS DE ACCESO DEL LEAD (USAR SIEMPRE ESTOS, TEXTUALES — NUNCA INVENTAR NI APROXIMAR):\n"
            . "  Link de la demo: {$demo_url}\n"
            . $landing_linea
            . "  Usuario: {$doc_number}\n"
            . "  Contraseña: {$doc_number}\n"
            . "  Mail 1 enviado: {$mail_enviado_texto}\n"
            . "Estos datos SOLO se le pasan al lead si el lead reporta que no le llegó el mail, que no lo\n"
            . "encuentra o que no puede entrar. No los ofrezcas por iniciativa propia: el canal normal es\n"
            . "el Mail 1.\n"
            . "Cuando los pases, en el MISMO mensaje pedile que igual busque el mail (revisando spam y\n"
            . "\"No deseados\") porque los videos tutoriales están solo ahí, y ofrecele reenviárselo.\n"
            . "Si el lead dice que no le llegó, que no lo encuentra o pide que se lo manden de nuevo,\n"
            . "devolvé reenviar_mail_demo: true en el JSON.\n"
            . $landing_instruccion
            . "PROHIBIDO escribir un placeholder entre corchetes o prometer pasar estos datos más tarde.";
    }

    /**
     * Arma el bloque de acceso a la demo para leads en la dinámica NUEVA (correctivo del grupo
     * 308, prompt 03): REEMPLAZA a `build_demo_access_context()` (nunca conviven -- el agente no
     * puede ofrecer las dos cosas en el mismo mensaje). Sin credenciales ni mail: el acceso es el
     * link de la página inmersiva (`/experiencia/{uuid}`, grupo 300), que se manda apenas se
     * confirma el horario.
     *
     * A diferencia de `build_demo_access_context()`, el llamador de este método NO lo gatea por
     * `ESTADOS_REQUIEREN_SUPERVISION_AGENDAMIENTO` (fix B del correctivo): el único dato que
     * necesita es el `uuid` del lead, que existe desde que el lead se crea.
     *
     * @param Lead $lead Lead para el que se arma el bloque.
     *
     * @return string Bloque de texto listo para concatenar al prompt, o cadena vacía si no aplica.
     */
    private function build_demo_experiencia_context(Lead $lead): string
    {
        // El uuid existe desde que se crea el lead, así que esto solo da vacío en un caso
        // anómalo (columna sin backfillear). Sin link, no hay nada que pasarle al agente.
        $url = $lead->demo_experiencia_url;
        if (empty($url)) {
            return '';
        }

        return "\n\nPAGINA DE ACCESO A LA DEMO (dinamica nueva -- NO se pide email para esto):\n"
            . "  Pagina: {$url}\n"
            . "Apenas confirmes un horario con el lead (agendar_demo), pasale esta pagina en el MISMO\n"
            . "mensaje: ahi ve el scroll de la demo, completa un formulario corto de configuracion, mira\n"
            . "el video de introduccion y entra con un boton propio cuando llegue su turno.\n"
            . "NUNCA pidas el email para poder hacer la demo: en esta dinamica no hace falta (el email\n"
            . "se pide mas adelante, para el contrato y la facturacion, y no lo maneja este flujo).\n"
            . "No existen usuario ni contraseña para ofrecer: todo el acceso pasa por el boton de la\n"
            . "pagina, nunca por credenciales sueltas.";
    }

    /**
     * Bloque "COORDINACIÓN DE LA LLAMADA CON EL CLOSER" (grupo 307, prompt 04): huecos reales del
     * closer (armados con CloserAgendaService, grupo 307, prompt 02) y las instrucciones del tramo
     * post-demo. Sin este bloque el agente tiene las acciones (agendar_llamada_closer,
     * descartar_llamada_closer, posponer_check_fin_demo) pero no sabe qué horarios existen, y va a
     * inventarlos (lead #232, @contexto/estado_y_decisiones.md §3.22) -- por eso el caso de agenda
     * vacía se dice EXPLÍCITAMENTE en vez de omitirse.
     *
     * @param Lead $lead
     *
     * @return string
     */
    private function build_coordinacion_llamada_closer_context(Lead $lead): string
    {
        $agenda_service = app(CloserAgendaService::class);

        $libre_ahora = false;
        try {
            $libre_ahora = $agenda_service->is_free_now($lead);
        } catch (\Throwable $e) {
            Log::error('LeadAiService: error al consultar is_free_now() para el contexto del closer.', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }

        $slots = [];
        try {
            $slots = $agenda_service->next_slots(3);
        } catch (\Throwable $e) {
            Log::error('LeadAiService: error al consultar next_slots() para el contexto del closer.', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }

        $txt = "\n\nCOORDINACIÓN DE LA LLAMADA CON EL CLOSER:\n"
            . '- El closer puede tomar la llamada AHORA MISMO: ' . ($libre_ahora ? 'sí' : 'no') . "\n";

        if (empty($slots)) {
            /* Caso explícito a propósito (detalle 2 del prompt): un bloque vacío es el escenario
             * donde el modelo improvisa horarios que no existen. */
            $txt .= "- No hay próximos horarios disponibles del closer en este momento: NO inventes\n"
                . "  ningún horario. Decile al lead que se le confirma el horario a la brevedad y\n"
                . "  devolvé requiere_intervencion_humana: true.\n";
        } else {
            $txt .= "- Próximos horarios disponibles (copialos literalmente, no calcules ninguno):\n";
            $n = 1;
            foreach ($slots as $slot_disponible) {
                $txt .= "  {$n}. {$slot_disponible['label']} -> inicio: "
                    . $slot_disponible['inicio']->format('Y-m-d\TH:i:s') . "\n";
                $n++;
            }
        }

        $txt .= "\nInstrucciones de este tramo:\n"
            . "- Cuando el lead confirme que terminó la demo, preguntale primero si le sirvió y qué le\n"
            . "  pareció. No saltes directo a agendar: la llamada se ofrece sobre su respuesta, no\n"
            . "  sobre el reloj.\n"
            . "- Si le interesa y el closer está libre AHORA MISMO, ofrecele la llamada para ese\n"
            . "  momento: es el caso de mayor valor, el lead recién vio el sistema y tiene las\n"
            . "  preguntas frescas.\n"
            . "- Si el closer no está libre, ofrecele el PRIMER horario de la lista, uno solo, igual\n"
            . "  que en el agendamiento de la demo. Si no le sirve, ofrecele el siguiente.\n"
            . "- Confirmado un horario, devolvé agendar_llamada_closer con el inicio copiado literal\n"
            . "  de la lista de arriba.\n"
            . "- Si el lead dice que no quiere avanzar, devolvé descartar_llamada_closer con el\n"
            . "  motivo, sin insistir ni intentar rebatirlo: esa conversación es del closer, no tuya.\n"
            . "- Si el lead dice que todavía no terminó la demo, devolvé posponer_check_fin_demo con\n"
            . "  los minutos que él mismo dé a entender (\"dame 20 minutos\" -> 20; \"estoy viendo\n"
            . "  compras\" -> estimá un valor razonable). No vuelvas a preguntarle en el mismo turno.\n"
            . "- ⛔ Prohibido inventar horarios del closer. Si este bloque vino vacío arriba, no\n"
            . "  prometas ninguno: decí que se lo confirmamos enseguida y devolvé\n"
            . "  requiere_intervencion_humana: true.\n"
            . '- La llamada dura entre 15 y 20 minutos y es por videollamada. No prometas nada sobre'
            . " precios ni condiciones: eso es del closer.";

        return $txt;
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

            /* FIX (grupo 186, prompt 02, 22/7/2026): un WhatsApp Flow (formulario nativo de
             * Meta, externo a ComercioCity) se guarda en base con `kind = 'flow'` y una nota
             * corta y legible (ver WhatsappWebhookController::format_whatsapp_flow_note()), para
             * que el setter la vea limpia en el chat. Acá, al armar el contexto que recibe la
             * IA, se reconstruye el bloque completo de instrucciones (self::FLOW_NOTE_INSTRUCCION)
             * componiéndolo con esa nota corta, para que el agente siga recibiendo exactamente la
             * misma información y la misma prohibición que antes de este cambio. */
            if ((string) ($msg->kind ?? '') === 'flow') {
                $fecha_flow = $msg->created_at ? $msg->created_at->format('d/m/Y H:i') : '';
                $nota_flow  = trim((string) $msg->content);
                $historial .= "[{$fecha_flow}] NOTA DE SISTEMA (no es un mensaje escrito por el lead): "
                    . "{$nota_flow}. " . self::FLOW_NOTE_INSTRUCCION . "\n";

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
        $now_ar    = AppTime::now();
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

        /*
         * FIX (grupo 212, prompt 01, 24/7/2026 — bug real, lead #451): los datos de acceso a la
         * demo (link + documento) se inyectan ahora en TODO el tramo de demo
         * (ESTADOS_REQUIEREN_SUPERVISION_AGENDAMIENTO), no solo en ingresando_demo, para que el
         * agente los tenga disponibles apenas el lead los pida, en cualquier estado del ciclo.
         * Se agrega antes de resolver el objetivo puntual de cada estado (el if de abajo).
         */
        /*
         * FIX (correctivo grupo 308/prompt 03, fix B): para la dinámica NUEVA el link de la
         * página inmersiva no depende de ningún dato que se genere recién con la demo agendada
         * -- es solo el `uuid` del lead, que existe desde que el lead se crea. Por eso este bloque
         * NO se gatea por ESTADOS_REQUIEREN_SUPERVISION_AGENDAMIENTO (ese gate usa el status YA
         * PERSISTIDO, y hay un turno real donde el status persistido todavía no refleja que Claude
         * está por confirmar demo_agendada en la respuesta que se está armando ahora mismo). Se
         * inyecta siempre que el lead use la dinámica nueva, sin importar su status.
         */
        if ($lead->usa_experiencia_demo_nueva()) {
            $demo_experiencia_context = $this->build_demo_experiencia_context($lead);
            if ($demo_experiencia_context !== '') {
                $txt .= $demo_experiencia_context;
            }
        } elseif (in_array($lead_status_for_context, self::ESTADOS_REQUIEREN_SUPERVISION_AGENDAMIENTO, true)) {
            // Dinámica actual: el bloque viejo sigue gateado por el tramo de agendamiento, porque
            // depende del link real de la demo y del doc_number -- datos que solo existen una vez
            // que hay demo asignada. Los dos bloques son excluyentes (nunca los dos a la vez).
            $demo_access_context = $this->build_demo_access_context($lead);
            if ($demo_access_context !== '') {
                $txt .= $demo_access_context;
            }
        }

        if ($lead_status_for_context === 'ingresando_demo') {
            /* El lead está en el momento de intentar entrar al sistema demo. */
            $txt .= "\n\nCONTEXTO DE DEMO - INGRESO:\n"
                . "El lead tiene la demo en curso de inicio y se le preguntó si pudo ingresar al sistema.\n"
                . "\n"
                . "Tu objetivo es asegurarte de que entre. Si dice que tuvo un problema para entrar,\n"
                . "pasale los datos exactos que figuran en DATOS DE ACCESO DEL LEAD (link, usuario y contraseña).\n"
                . "NUNCA uses un número de documento diferente al que figura arriba.\n"
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
                . "responder, y dejá que el sistema lo avance.\n"
                . "Si en cambio te dice explícitamente que le falta un rato (\"estoy viendo lo de compras\",\n"
                . "\"dame 20 minutos\"), devolvé posponer_check_fin_demo con los minutos que pidió (entre 5 y 120;\n"
                . "si no dio un número, usá tu criterio dentro de ese rango) para que el sistema no lo interrumpa\n"
                . "antes de esa demora.";
        } elseif ($lead_status_for_context === 'demo_pendiente_de_terminar') {
            /* El lead volvió a escribir después de que el sistema no pudo confirmar el fin de la demo. */
            $txt .= "\n\nCONTEXTO DE DEMO - PENDIENTE DE TERMINAR:\n"
                . "Se había dado por no confirmada la finalización de la demo de este lead, pero volvió a escribir.\n"
                . "Si de su mensaje se infiere que efectivamente terminó la demo, devolvé confirmar_fin_demo: true.\n"
                . "Si todavía está en la demo, seguí ayudándolo y volvé a perseguir saber cuándo termina.\n"
                . "Si te dice explícitamente que le falta un rato, devolvé posponer_check_fin_demo con los minutos\n"
                . "que pidió (entre 5 y 120).";
        } elseif ($lead_status_for_context === 'closer_activo') {
            /* Post-llamada: el lead ya tuvo la demo con el closer y puede mencionar socios u otros contactos. */
            $txt .= "\n\nCONTEXTO POST-LLAMADA - CLOSER ACTIVO:\n"
                . "El lead ya tuvo la llamada de cierre con el closer. Si en su mensaje menciona explícitamente\n"
                . "a otra persona que participa en la decisión (socio, cónyuge, contador, etc.) con nombre\n"
                . "y/o número de teléfono, devolvé la acción sugerir_socio con los datos detectados.\n"
                . "Solo usar cuando el lead lo mencione con datos de contacto concretos. Si no hay socio nuevo,\n"
                . "omití sugerir_socio o ponelo en null.";
        }

        /*
         * Coordinación de la llamada con el closer (grupo 307, prompt 04). Independiente del
         * if/elseif de arriba a propósito: se inyecta ADEMÁS del bloque de cada estado (no lo
         * reemplaza), en los TRES estados del tramo post-demo -- demo_en_curso,
         * demo_pendiente_de_terminar y demo_realizada -- no solo el último. Mismo bug que hubo
         * que corregir en el grupo 320: el turno en que el lead dice "sí, me sirvió" puede ser el
         * MISMO en que se confirma el fin de la demo, y en ese momento el status persistido
         * todavía puede ser demo_en_curso. Si el bloque solo se inyectara desde demo_realizada,
         * faltaría justo en el turno que más importa. Solo dinámica nueva.
         */
        $estados_coordinacion_llamada = ['demo_en_curso', 'demo_pendiente_de_terminar', 'demo_realizada'];
        if ($lead->usa_experiencia_demo_nueva() && in_array($lead_status_for_context, $estados_coordinacion_llamada, true)) {
            $coordinacion_llamada_context = $this->build_coordinacion_llamada_closer_context($lead);
            if ($coordinacion_llamada_context !== '') {
                $txt .= $coordinacion_llamada_context;
            }
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



