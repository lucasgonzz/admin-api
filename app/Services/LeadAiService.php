<?php

namespace App\Services;

use App\Events\LeadSuggestionCreated;
use App\Services\LeadBroadcastService;
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

        /* String base que se inyecta como contexto en el user content. */
        $availability_context = "Slots disponibles (demos de 1 hora, lunes a viernes 9-18hs, sábado 9-12hs):\n{$availability_lines}";

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
        /*
         * Construir la lista de días hábiles a partir de mañana.
         * Se excluyen los domingos; los sábados tienen slots reducidos.
         */
        $working_days = [];
        /*
         * Cursor empieza mañana al inicio del día en timezone Argentina.
         * Usar la timezone local evita que diferencias UTC adelanten o atrasen el corte de día.
         */
        $cursor = now('America/Argentina/Buenos_Aires')->startOfDay()->addDay();

        while (count($working_days) < $days_ahead) {
            /* 0 = domingo, se omite */
            if ($cursor->dayOfWeek !== 0) {
                $working_days[] = $cursor->copy();
            }
            $cursor->addDay();
        }
        /* Al salir del while, $cursor apunta al día siguiente al último día incluido */

        /* Construir array de strings de fecha para la consulta SQL. */
        $date_strings = array_map(function ($day) {
            return $day->format('Y-m-d');
        }, $working_days);

        /*
         * Consultar leads con demo agendada en esos días.
         * Se usa CONVERT_TZ para comparar en timezone Argentina:
         * demo_date se guarda como datetime UTC en MySQL, hay que convertir
         * a -03:00 antes de extraer la fecha con DATE().
         */
        $placeholders = implode(',', array_fill(0, count($date_strings), '?'));
        $booked_leads = Lead::whereRaw(
            "DATE(CONVERT_TZ(demo_date, '+00:00', '-03:00')) IN ({$placeholders})",
            $date_strings
        )
            ->whereNotNull('demo_start_time')
            ->get(['demo_date', 'demo_start_time']);

        /* Agrupar horarios ocupados por fecha en formato 'HH:MM'. */
        $occupied_by_date = [];
        foreach ($booked_leads as $booked_lead) {
            /*
             * Convertir a timezone Argentina antes de formatear: demo_date se guarda en UTC
             * y Carbon lo castea como tal. Sin la conversión, leads con demo entre las 00:00
             * y las 02:59 Argentina aparecen un día antes en UTC y el slot no se bloquea.
             */
            $date_key = $booked_lead->demo_date->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d');
            $time_raw = trim((string) $booked_lead->demo_start_time);

            /* Normalizar la hora a formato 'HH:MM' independientemente de cómo fue guardada. */
            if (preg_match('/(\d{1,2}):(\d{2})/', $time_raw, $m)) {
                $normalized                    = str_pad($m[1], 2, '0', STR_PAD_LEFT).':'.$m[2];
                $occupied_by_date[$date_key][] = $normalized;
            }
        }

        /* Construir el resultado con slots disponibles por día. */
        $result   = [];
        $any_full = false;

        foreach ($working_days as $day) {
            $date_key = $day->format('Y-m-d');

            /* Slots posibles según día de semana: 6 = sábado. */
            $all_slots = $day->dayOfWeek === 6
                ? ['09:00', '10:00', '11:00']
                : ['09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00'];

            /* Slots ocupados para este día (default array vacío si ninguno está reservado). */
            $booked = isset($occupied_by_date[$date_key]) ? $occupied_by_date[$date_key] : [];

            /* Filtrar los slots que no están ocupados. */
            $available = array_values(array_filter($all_slots, function ($slot) use ($booked) {
                return ! in_array($slot, $booked, true);
            }));

            if (empty($available)) {
                $any_full = true;
            }

            $result[$date_key] = $available;
        }

        /*
         * Si algún día quedó completamente ocupado, agregar el siguiente día hábil
         * para asegurar que Claude siempre tenga opciones concretas para ofrecer.
         */
        if ($any_full) {
            /* Avanzar el cursor hasta el próximo día hábil (saltar domingos). */
            while ($cursor->dayOfWeek === 0) {
                $cursor->addDay();
            }

            $extra_key = $cursor->format('Y-m-d');

            /* Consultar ocupados del día extra (query puntual). */
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

            /* Slots del día extra según día de semana. */
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

        $lead->save();

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

