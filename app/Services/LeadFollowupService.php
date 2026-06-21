<?php

namespace App\Services;

use App\Models\FollowupRule;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadMessage;
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

        $followups = LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('is_followup', true)
            ->where('status', '!=', 'rechazado')
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
        $template = $this->find_template_for($lead->status, $followup_number);

        if ($template !== null) {
            $this->send_followup_via_template($fresh, $template, $followup_number);
            $via = 'template';
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

        /** @var FollowupRule $rule */
        $rule = $rules_by_estado->get($lead->status);
        $last_at = $this->last_message_at($lead);
        $hours = $last_at->diffInHours(Carbon::now());
        if ($hours < (int) $rule->horas_espera) {
            return null;
        }

        $followups = LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('is_followup', true)
            ->where('status', '!=', 'rechazado')
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

        // Buscamos la plantilla Meta que corresponde a este estado y número de seguimiento.
        $template = $this->find_template_for($lead->status, $followup_number);

        if ($template !== null) {
            // Hay plantilla aprobada: enviamos directo por WhatsApp sin pasar por Claude.
            $this->send_followup_via_template($fresh, $template, $followup_number);
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
     * @param string $estado          Estado del lead.
     * @param int    $followup_number Número de seguimiento (1-based).
     *
     * @return FollowupTemplate|null
     */
    protected function find_template_for(string $estado, int $followup_number): ?FollowupTemplate
    {
        // Plantillas activas de este estado, ordenadas por día (define la secuencia de envío).
        $templates = FollowupTemplate::query()
            ->where('estado', $estado)
            ->where('activa', true)
            ->orderBy('dia_numero', 'asc')
            ->get();

        // Índice 0-based correspondiente al número de seguimiento (1-based).
        $index = $followup_number - 1;

        return $templates->get($index);
    }

    /**
     * Envía un seguimiento directo vía plantilla Meta y registra el mensaje.
     *
     * @param Lead             $lead
     * @param FollowupTemplate $template
     * @param int              $followup_number Número de seguimiento (1-based) para etiquetar el registro.
     *
     * @return void
     */
    protected function send_followup_via_template(Lead $lead, FollowupTemplate $template, int $followup_number): void
    {
        // Nombre del contacto como variable {{1}} de la plantilla (vacío si no hay).
        $contact_name = $lead->contact_name ?? '';

        // Envío directo del template aprobado a través de Kapso/Meta.
        $whatsapp_message_id = app(WhatsappSendService::class)->send_template(
            $lead->phone,
            $template->template_name,
            [$contact_name],
            $template->language_code
        );

        // Registramos el seguimiento en la conversación del lead (trazabilidad).
        // Usamos el texto real de la plantilla (con nombre sustituido) si está disponible.
        LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'sistema',
            'content'               => $this->render_template_body($template, $lead),
            'status'                => 'enviado',
            'is_followup'           => true,
            'whatsapp_message_id'   => $whatsapp_message_id,
            'requiere_verificacion' => false,
        ]);

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

        return $lead->created_at ? Carbon::parse($lead->created_at) : Carbon::now();
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
            'lead_id' => $lead->id,
            'sender' => 'sistema',
            'content' => 'Lead pasado a En Pausa automáticamente por inactividad.',
            'status' => 'enviado',
            'is_followup' => false,
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
