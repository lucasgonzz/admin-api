<?php

namespace App\Services;

use App\Models\FollowupRule;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Helpers\AppTime;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Evalúa leads activos y dispara seguimientos automáticos vía {@see LeadAiService}
 * o pausa el lead si se agotaron los intentos.
 *
 * Para los estados demo_realizada y mail2_enviado no existen plantillas Meta aprobadas:
 * el seguimiento lo maneja el closer de forma personalizada, por lo que el sistema
 * notifica al closer vía WhatsApp en lugar de generar una sugerencia de Claude.
 */
class LeadFollowupService
{
    /**
     * Estados en los que el seguimiento automático delega al closer,
     * en vez de enviar una plantilla o generar sugerencia de Claude.
     *
     * Las plantillas de estos estados no están creadas en Meta Business Manager.
     */
    protected const ESTADOS_CLOSER = ['demo_realizada', 'mail2_enviado'];

    /**
     * Procesa todos los leads que no están cerrados / en pausa final.
     *
     * @return array{processed:int,suggestions:int,paused:int,errors:int}
     */
    public function process_all_active_leads(): array
    {
        $stats = ['processed' => 0, 'suggestions' => 0, 'paused' => 0, 'errors' => 0];
        $rules = FollowupRule::query()->where('activa', true)->get()->keyBy('estado');

        $leads = Lead::query()
            ->whereNotIn('status', ['cerrado_ganado', 'cerrado_perdido', 'en_pausa'])
            ->get();

        foreach ($leads as $lead) {
            $stats['processed']++;
            try {
                $result = $this->process_lead($lead, $rules);
                if ($result === 'suggestion') {
                    $stats['suggestions']++;
                }
                if ($result === 'paused') {
                    $stats['paused']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::error('LeadFollowupService error', ['lead_id' => $lead->id, 'msg' => $e->getMessage()]);

                /* Notificar a admins suscritos que falló el procesamiento del seguimiento. */
                app(SystemErrorWhatsappService::class)->notify_send_error(
                    "Seguimiento automático - Lead #{$lead->id}",
                    $e->getMessage()
                );
            }
        }

        return $stats;
    }

    /**
     * Punto de entrada público para procesar un único lead.
     *
     * Carga las reglas activas y delega en {@see process_lead}.
     * Diseñado para uso desde comandos de testing local que necesitan
     * disparar el seguimiento sin esperar el cron.
     *
     * @param Lead $lead Lead a procesar (con o sin mensajes precargados).
     *
     * @return string|null 'suggestion' si Claude generó sugerencia, 'paused' si se pausó, null si se omitió.
     */
    public function process_single_lead(Lead $lead): ?string
    {
        /* Cargar todas las reglas activas indexadas por estado para la evaluación */
        $rules = FollowupRule::query()->where('activa', true)->get()->keyBy('estado');

        return $this->process_lead($lead, $rules);
    }

    /**
     * Fuerza el envío del seguimiento que corresponde a un lead AHORA MISMO,
     * ignorando horas_espera y tiene_sugerencia_pendiente. Pensado para testing
     * manual desde el panel admin. El resto de la lógica (conteo de followups,
     * elección de template, pausado por límite alcanzado) es idéntica a producción.
     *
     * @param Lead $lead
     *
     * @return array{result:string, followup_number:int|null, via:string|null}
     *   result: 'suggestion'|'paused'|'no_rule'|'limit_reached_already_paused'
     *   via: 'template'|'claude'|null
     */
    public function force_followup_now(Lead $lead): array
    {
        $rules_by_estado = FollowupRule::query()->where('activa', true)->get()->keyBy('estado');

        if (! $rules_by_estado->has($lead->status)) {
            return ['result' => 'no_rule', 'followup_number' => null, 'via' => null];
        }

        /** @var FollowupRule $rule */
        $rule = $rules_by_estado->get($lead->status);

        // FIX (2/7/2026): mismo criterio que en process_lead() — un seguimiento por plantilla fallido
        // no consume el cupo. Ver comentario completo en process_lead().
        $followups = LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('is_followup', true)
            ->where('status', '!=', 'rechazado')
            ->where(function ($q) {
                $q->whereNull('followup_template_id')
                    ->orWhereNotNull('whatsapp_message_id');
            })
            ->count();

        if ($followups >= (int) $rule->max_followups) {
            $this->pause_lead($lead);
            return ['result' => 'paused', 'followup_number' => null, 'via' => null];
        }

        $fresh = Lead::query()->with('messages')->where('id', $lead->id)->first();
        if (! $fresh) {
            return ['result' => 'no_rule', 'followup_number' => null, 'via' => null];
        }

        $followup_number = $followups + 1;

        /*
         * Mismo criterio de bifurcación que process_lead():
         * si el lead confirmó ingreso a la demo, usar las plantillas "en curso".
         */
        $ingreso_confirmado = (bool) ($lead->demo_ingreso_confirmado ?? false);
        $template = $this->find_template_for($lead->status, $followup_number, $ingreso_confirmado);

        if ($template !== null) {
            if (in_array($fresh->status, LeadAiService::ESTADOS_REQUIEREN_SUPERVISION_AGENDAMIENTO, true)) {
                $this->create_pending_followup_for_verification($fresh, $template, $followup_number);
                $via = 'verificacion';
            } else {
                $this->send_followup_via_template($fresh, $template, $followup_number);
                $via = 'template';
            }
        } else {
            if (in_array($fresh->status, self::ESTADOS_CLOSER, true)) {
                // Para demo_realizada y mail2_enviado: notificar al closer en vez de envío automático.
                $this->notify_closer_for_followup($fresh, $followup_number);
                $via = 'closer_notified';
            } else {
                // Fallback general: generar sugerencia de Claude para aprobación manual.
                app(LeadAiService::class)->generate_suggestion($fresh, true);
                $via = 'claude';
            }
        }

        return ['result' => 'suggestion', 'followup_number' => $followup_number, 'via' => $via];
    }

    /**
     * @param Lead                                       $lead
     * @param \Illuminate\Support\Collection $rules_by_estado
     *
     * @return string|null suggestion|paused|null
     */
    protected function process_lead(Lead $lead, $rules_by_estado): ?string
    {
        if ($lead->tiene_sugerencia_pendiente) {
            return null;
        }
        if (! $rules_by_estado->has($lead->status)) {
            return null;
        }

        /*
         * Guard para demo_agendada: si la demo aún no llegó, no disparar seguimiento.
         * Las plantillas cc_seg_demo_agendada_* son para leads que no se presentaron
         * DESPUÉS del horario acordado — no para los que todavía esperan su turno.
         */
        if ($lead->status === 'demo_agendada' && isset($lead->demo_date)) {
            $demo_date_str = $lead->demo_date->format('Y-m-d');
            if (!empty($lead->demo_start_time)) {
                $demo_start = Carbon::parse($demo_date_str . ' ' . $lead->demo_start_time);
            } else {
                $demo_start = Carbon::parse($demo_date_str . ' 23:59:59');
            }
            if ($demo_start->isFuture()) {
                return null;
            }
        }

        /** @var FollowupRule $rule */
        $rule = $rules_by_estado->get($lead->status);
        $last_at = $this->last_message_at($lead);
        $hours = $last_at->diffInHours(AppTime::now());
        if ($hours < (int) $rule->horas_espera) {
            return null;
        }

        // FIX (2/7/2026): un seguimiento por plantilla que falló al enviarse (whatsapp_message_id null)
        // no debe consumir el cupo de max_followups — el lead nunca lo recibió. Se identifica por tener
        // followup_template_id seteado (viene de send_followup_via_template) y whatsapp_message_id null.
        // Los seguimientos que no pasan por plantilla (ej: notify_closer_for_followup, sin
        // followup_template_id) siguen contando igual que siempre.
        $followups = LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('is_followup', true)
            ->where('status', '!=', 'rechazado')
            ->where(function ($q) {
                $q->whereNull('followup_template_id')
                    ->orWhereNotNull('whatsapp_message_id');
            })
            ->count();

        if ($followups >= (int) $rule->max_followups) {
            $this->pause_lead($lead);

            return 'paused';
        }

        $fresh = Lead::query()->with('messages')->where('id', $lead->id)->first();
        if (! $fresh) {
            return null;
        }

        // El número de seguimiento que vamos a enviar ahora es $followups + 1
        // pero usamos $followups como índice del día ya que empieza en 0 antes del primer envío.
        $followup_number = $followups + 1; // 1-based: primer seguimiento = 1

        /*
         * Para leads en demo_agendada bifurcamos por demo_ingreso_confirmado:
         * true  → el lead entró a la demo pero no la terminó (plantillas cc_seg_demo_en_curso_*)
         * false → el lead nunca llegó a entrar (plantillas cc_seg_demo_agendada_*)
         */
        $ingreso_confirmado = (bool) ($lead->demo_ingreso_confirmado ?? false);

        // Buscamos la plantilla Meta que corresponde a este estado, número de seguimiento y flag de ingreso.
        $template = $this->find_template_for($lead->status, $followup_number, $ingreso_confirmado);

        if ($template !== null) {
            if (in_array($fresh->status, LeadAiService::ESTADOS_REQUIEREN_SUPERVISION_AGENDAMIENTO, true)) {
                // Tramo de agenda (solicita_disponibilidad en adelante): el seguimiento NO se auto-envía.
                // Se crea como sugerencia pendiente de aprobación del setter y se enviará por su plantilla
                // al aprobar / al vencer el timer de respaldo (ver create_pending_followup_for_verification).
                $this->create_pending_followup_for_verification($fresh, $template, $followup_number);
            } else {
                // Fuera del tramo: envío directo por plantilla como siempre.
                $this->send_followup_via_template($fresh, $template, $followup_number);
            }
        } else {
            if (in_array($fresh->status, self::ESTADOS_CLOSER, true)) {
                // Para demo_realizada y mail2_enviado: notificar al closer en vez de envío automático.
                $this->notify_closer_for_followup($fresh, $followup_number);
            } else {
                // Fallback general: generar sugerencia de Claude para aprobación manual.
                app(LeadAiService::class)->generate_suggestion($fresh, true);
            }
        }

        return 'suggestion';
    }

    /**
     * Notifica al closer vía WhatsApp que un lead en etapa avanzada
     * (demo_realizada o mail2_enviado) requiere seguimiento personalizado.
     *
     * Reutiliza LeadEscalationWhatsappService y la plantilla `lead_escalacion_humana`
     * (ya aprobada en Meta), pasando un motivo contextual según el estado del lead.
     *
     * @param Lead $lead             Lead que requiere atención del closer.
     * @param int  $followup_number  Número de seguimiento (1-based), para dar contexto en el motivo.
     *
     * @return void
     */
    protected function notify_closer_for_followup(Lead $lead, int $followup_number): void
    {
        /* Motivo contextual según el estado del lead para que el closer entienda el contexto. */
        $motivos = [
            'demo_realizada' => "Lead hizo la demo - seguimiento #{$followup_number}",
            'mail2_enviado'  => "Lead en etapa de cierre - seguimiento #{$followup_number}",
        ];

        /* Usar el motivo específico del estado o uno genérico de fallback. */
        $motivo = $motivos[$lead->status] ?? "Seguimiento #{$followup_number} requerido";

        /* Enviar notificación al closer usando el servicio de escalación existente. */
        app(LeadEscalationWhatsappService::class)->notify($lead, $motivo);

        /*
         * Registrar el seguimiento como LeadMessage con is_followup=true para que el
         * contador de followups (is_followup=true AND status!='rechazado') suba.
         * Sin este registro el scheduler reprocesaría el mismo lead cada 2 horas y
         * reenviaría la notificación al closer indefinidamente, en vez de pausarlo
         * cuando se agote max_followups.
         */
        LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'sistema',
            'content'               => "[Notificación al closer — {$motivo}]",
            'status'                => 'enviado',
            'is_followup'           => true,
            'requiere_verificacion' => false,
        ]);

        Log::info('LeadFollowupService: closer notificado por WhatsApp por seguimiento en etapa avanzada.', [
            'lead_id'         => $lead->id,
            'estado'          => $lead->status,
            'followup_number' => $followup_number,
        ]);
    }

    /**
     * Resuelve la plantilla Meta a usar para un estado y número de seguimiento.
     *
     * Las plantillas activas del estado se ordenan por dia_numero ascendente y se
     * indexan 1-based: el primer seguimiento usa la primera plantilla, el segundo
     * la segunda, etc. Si no existe esa posición, retorna null.
     *
     * Para el estado demo_agendada, el flag $ingreso_confirmado bifurca el conjunto
     * de plantillas: false → "¿pudiste hacer la demo?" | true → "¿pudiste terminarla?".
     *
     * @param string $estado             Estado del lead.
     * @param int    $followup_number    Número de seguimiento (1-based).
     * @param bool   $ingreso_confirmado Si el lead confirmó ingreso a la demo (default false).
     *
     * @return FollowupTemplate|null
     */
    protected function find_template_for(string $estado, int $followup_number, bool $ingreso_confirmado = false): ?FollowupTemplate
    {
        /*
         * Filtrar por solo_si_ingreso_confirmado para bifurcar el seguimiento
         * cuando el lead en demo_agendada ya confirmó haber ingresado.
         */
        $templates = FollowupTemplate::query()
            ->where('estado', $estado)
            ->where('activa', true)
            ->where('solo_si_ingreso_confirmado', $ingreso_confirmado)
            ->orderBy('dia_numero', 'asc')
            ->get();

        // Índice 0-based correspondiente al número de seguimiento (1-based).
        $index = $followup_number - 1;

        return $templates->get($index);
    }

    /**
     * Crea el seguimiento como sugerencia PENDIENTE de aprobación del setter, sin enviarlo.
     *
     * Aplica a los leads que ya están en el tramo de agenda (solicita_disponibilidad en adelante,
     * ver LeadAiService::ESTADOS_REQUIEREN_SUPERVISION_AGENDAMIENTO): en ese tramo, la regla de
     * negocio (6/7/2026) es que cada mensaje al lead lo aprueba un humano. Este método deja el
     * seguimiento en estado 'sugerido' + requiere_verificacion, guardando followup_template_id para
     * que, al aprobarlo (o al vencer el timer de respaldo), se envíe por su plantilla Meta —
     * LeadSuggestionSendService::send_suggestion() detecta el seguimiento y usa send_template() en vez
     * de send_text(), imprescindible porque la ventana de 24hs suele estar cerrada cuando dispara un
     * seguimiento.
     *
     * Mecánicamente se comporta igual que un mensaje de verificación conversacional del tramo: mismas
     * notificaciones (push + WhatsApp opcional + sonido) y mismo timer de auto-envío de respaldo
     * (LeadAiSuggestionAutoSendScheduler, que ya respeta el guard de intervención humana del prompt 276).
     *
     * @param Lead             $lead
     * @param FollowupTemplate $template
     * @param int              $followup_number
     *
     * @return void
     */
    protected function create_pending_followup_for_verification(Lead $lead, FollowupTemplate $template, int $followup_number): void
    {
        $msg = LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'sistema',
            'content'               => $this->render_template_body($template, $lead),
            'status'                => 'sugerido',
            'is_followup'           => true,
            'followup_template_id'  => $template->id,
            'requiere_verificacion' => true,
            'whatsapp_message_id'   => null,
            'sent_at'               => null,
        ]);

        /*
         * Marca la sugerencia pendiente para que el scheduler no genere otro seguimiento mientras
         * espera aprobación (process_lead() corta temprano si tiene_sugerencia_pendiente). NO se marca
         * tiene_seguimiento_sin_ver: ese flag es para seguimientos YA enviados sin revisar; acá el aviso
         * es el badge violeta de "por aprobar" (prompt 284).
         */
        $lead->tiene_sugerencia_pendiente = true;
        $lead->requiere_seguimiento       = true;
        $lead->save();

        /* Aviso al setter: mismo combo que un mensaje de verificación conversacional del tramo
         * (push siempre + WhatsApp opcional + sonido en el navegador). */
        try {
            $agendamiento_service = new LeadVerificacionAgendamientoNotificationService(
                new WhatsappSendService()
            );
            $agendamiento_service->notify($lead->fresh(), $msg);
            event(new \App\Events\LeadVerificacionAgendamientoAlert($lead->fresh(), $msg));
        } catch (\Throwable $e) {
            Log::error('LeadFollowupService: error al notificar seguimiento pendiente de verificación.', [
                'lead_id'    => $lead->id,
                'message_id' => $msg->id,
                'error'      => $e->getMessage(),
            ]);
        }

        /* Timer de auto-envío de respaldo (mismo que los mensajes de verificación conversacional). El
         * scheduler ya respeta el guard de intervención humana (prompt 276). Al vencer, send_suggestion()
         * enviará el seguimiento por su plantilla. */
        (new LeadAiSuggestionAutoSendScheduler())->schedule_for_suggested_message($msg);

        LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $msg->id);

        Log::info('LeadFollowupService: seguimiento del tramo de agenda creado como pendiente de verificación.', [
            'lead_id'         => $lead->id,
            'estado'          => $lead->status,
            'followup_number' => $followup_number,
            'template'        => $template->template_name,
        ]);
    }

    /**
     * Envía un seguimiento directo vía plantilla Meta y registra el mensaje.
     *
     * Público (no protected) porque BatchLeadAiRecoveryService::retry_failed_followups() (prompt 246) lo
     * invoca directamente para reintentar, sin pasar por process_lead()/force_followup_now(), un
     * seguimiento que falló en un intento anterior (mismo lead, misma plantilla ya resuelta).
     *
     * FIX (2/7/2026): antes este método marcaba SIEMPRE `status = 'enviado'` sin revisar si
     * send_template() había devuelto null (falló el envío real). Eso hacía que un seguimiento que nunca
     * llegó al lead igual consumiera el cupo de max_followups. Ahora, si el envío falla, no se marca
     * tiene_seguimiento_sin_ver ni se dispara el broadcast (nada cambió todavía para el setter), y el
     * mensaje queda identificable como fallido por whatsapp_message_id null + followup_template_id no
     * null. La UI (MessageBubble.vue) ya muestra el banner de error de entrega para ese caso — no hace
     * falta ningún cambio de frontend.
     *
     * @param Lead             $lead
     * @param FollowupTemplate $template
     * @param int              $followup_number Número de seguimiento (1-based). No se usa dentro del
     *                                            método (se conserva por compatibilidad de firma con las
     *                                            llamadas existentes); en un reintento manual puede
     *                                            pasarse 0.
     *
     * @return void
     */
    public function send_followup_via_template(Lead $lead, FollowupTemplate $template, int $followup_number): void
    {
        // Nombre del contacto como variable {{1}} de la plantilla (vacío si no hay).
        $contact_name = $lead->contact_name ?? '';

        // Envío directo del template aprobado a través de Kapso/Meta.
        // El contexto se pasa directo para que, si falla, WhatsappSendService notifique a
        // los admins de forma centralizada (con throttle de máx 1 aviso cada 10 min).
        // Se retiene la instancia ($sender) para poder leer su last_send_error si el envío falla (prompt 336).
        $sender = app(WhatsappSendService::class);
        $whatsapp_message_id = $sender->send_template(
            $lead->phone,
            $template->template_name,
            [$contact_name],
            $template->language_code,
            "Seguimiento automático - Lead #{$lead->id} ({$lead->contact_name})"
        );

        // Registramos el seguimiento en la conversación del lead (trazabilidad), haya fallado o no.
        // Usamos el texto real de la plantilla (con nombre sustituido) si está disponible.
        // followup_template_id queda grabado siempre: identifica qué plantilla se intentó enviar, tanto
        // para el fix del conteo de cupo (más arriba en este archivo) como para que un reintento manual
        // sepa qué plantilla reenviar sin volver a resolverla por estado del lead.
        LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'sistema',
            'content'               => $this->render_template_body($template, $lead),
            'status'                => 'enviado',
            'is_followup'           => true,
            'whatsapp_message_id'   => $whatsapp_message_id,
            'followup_template_id'  => $template->id,
            'requiere_verificacion' => false,
            // Motivo real del fallo (prompt 336): solo se persiste si el envío no se confirmó.
            'whatsapp_send_error'   => $whatsapp_message_id === null ? $sender->last_send_error : null,
        ]);

        if ($whatsapp_message_id === null) {
            // Envío fallido: WhatsappSendService ya notificó a los admins de forma centralizada
            // (throttle de máx 1 aviso cada 10 min). Acá solo logueamos y cortamos: no marcamos
            // tiene_seguimiento_sin_ver (no hay nada nuevo que el setter deba revisar) ni disparamos
            // el broadcast — el mensaje sigue apareciendo en el hilo con el banner de error de entrega
            // que ya renderiza MessageBubble.vue.
            Log::channel('daily')->warning('LeadFollowupService: seguimiento por plantilla falló al enviarse.', [
                'lead_id'  => $lead->id,
                'template' => $template->template_name,
            ]);

            // Deja asentado en el hilo que el seguimiento automático falló al enviarse (prompt 299),
            // para que quede visible al operador aunque nadie haya disparado la acción. Se usa el motivo
            // real capturado por WhatsappSendService (prompt 336), con fallback al texto genérico si no
            // se pudo capturar ninguno.
            (new LeadConversationErrorLogger())->log(
                (int) $lead->id,
                'No se pudo enviar el seguimiento automático por WhatsApp',
                $sender->last_send_error ?: 'El envío por plantilla no se confirmó (revisar conexión con WhatsApp/Kapso).'
            );

            return;
        }

        // Marcamos que el lead tiene un seguimiento que el setter todavía no vio.
        $lead->tiene_seguimiento_sin_ver = true;
        $lead->save();

        // Notificamos a admin-spa que la conversación cambió.
        LeadBroadcastService::emit_conversation_updated((int) $lead->id);
    }

    /**
     * Timestamp del último mensaje no rechazado, o creación del lead.
     *
     * @param Lead $lead
     *
     * @return Carbon
     */
    protected function last_message_at(Lead $lead): Carbon
    {
        $m = LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('status', '!=', 'rechazado')
            ->orderByDesc('id')
            ->first();
        if ($m && $m->created_at) {
            return Carbon::parse($m->created_at);
        }

        return $lead->created_at ? Carbon::parse($lead->created_at) : AppTime::now();
    }

    /**
     * Pasa el lead a en_pausa y registra mensaje de sistema.
     *
     * @param Lead $lead
     *
     * @return void
     */
    protected function pause_lead(Lead $lead): void
    {
        $lead->status = 'en_pausa';
        $lead->requiere_seguimiento = false;
        $lead->tiene_sugerencia_pendiente = false;
        $lead->tiene_seguimiento_sin_ver = false;
        $lead->save();

        LeadMessage::create([
            'lead_id'          => $lead->id,
            'sender'           => 'sistema',
            'content'          => 'Lead pasado a En Pausa automáticamente por inactividad.',
            'status'           => 'enviado',
            'is_followup'      => false,
            /* Marcado como evento de estado para que no actualice last_message_at
               ni aparezca en el listado de notificaciones del panel. */
            'is_status_event'  => true,
            'requiere_verificacion' => false,
        ]);
    }

    /**
     * Devuelve el texto del mensaje a mostrar en la conversación del lead.
     *
     * Si la plantilla tiene `body_template` cargado, reemplaza {{1}} con el nombre
     * del contacto y devuelve el texto real enviado al lead.
     * Si no tiene body_template (ej: estados gestionados por el closer), usa el
     * placeholder clásico para mantener trazabilidad del nombre de plantilla.
     *
     * @param FollowupTemplate $template  Plantilla usada para el envío.
     * @param Lead             $lead      Lead destinatario del mensaje.
     *
     * @return string Texto a registrar en LeadMessage::content.
     */
    private function render_template_body(FollowupTemplate $template, Lead $lead): string
    {
        /* Texto literal de la plantilla (puede ser null si no fue cargado). */
        $body = trim((string) ($template->body_template ?? ''));

        if ($body === '') {
            /* Sin body_template: usar placeholder para no perder trazabilidad del nombre de plantilla. */
            return "[Seguimiento automático — plantilla: {$template->template_name}]";
        }

        /* Nombre del contacto para sustituir {{1}} (variable de Meta). */
        $contact_name = trim((string) ($lead->contact_name ?? ''));

        return str_replace('{{1}}', $contact_name, $body);
    }
}

