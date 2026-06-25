<?php

namespace App\Console\Commands;

use App\Models\AdminSetting;
use App\Models\AgentDailyReport;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\MessageVariant;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Genera el archivo markdown de análisis diario o semanal del agente comercial.
 * No llama a la API de Anthropic; simplemente recopila datos y formatea el archivo
 * para que Lucas lo suba manualmente a Claude en su plan Max.
 */
class GenerateDailyAgentReportCommand extends Command
{
    /**
     * Firma del comando artisan.
     *
     * @var string
     */
    protected $signature = 'agent:generate-daily-report {--date= : Fecha específica YYYY-MM-DD}';

    /**
     * Descripción visible en php artisan list.
     *
     * @var string
     */
    protected $description = 'Genera el archivo markdown de análisis diario/semanal del agente comercial.';

    /**
     * Ejecuta el command: recopila datos, genera markdown, guarda archivo y registro en BD.
     *
     * @return int Código de salida: 0 = éxito, 1 = error.
     */
    public function handle(): int
    {
        try {
            /* Determinar la fecha del reporte: opción --date o ayer. */
            $date = $this->option('date')
                ? Carbon::parse($this->option('date'))
                : Carbon::yesterday();

            /* Los lunes generan reporte semanal; el resto diario. */
            $is_weekly   = $date->isMonday();
            $report_type = $is_weekly ? 'weekly' : 'daily';

            /* Evitar duplicados: si ya existe un reporte para esta fecha, omitir. */
            if (AgentDailyReport::where('report_date', $date->toDateString())->exists()) {
                $this->info("Ya existe el reporte para {$date->toDateString()}. Omitiendo.");
                return 0;
            }

            $this->info("Generando reporte {$report_type} para {$date->toDateString()}...");

            /* Asegurar que el directorio de almacenamiento existe. */
            Storage::makeDirectory('agent_reports');

            /* Recopilar todos los datos necesarios para el reporte. */
            $data = $this->collect_data($date, $is_weekly);

            /* Generar el contenido markdown con los datos recopilados. */
            $markdown = $this->build_markdown($data, $date, $is_weekly);

            /* Nombre y ruta del archivo dentro de storage/app/. */
            $filename = 'agent_reports/' . $date->toDateString() . ($is_weekly ? '-weekly' : '') . '.md';
            Storage::put($filename, $markdown);

            /* Crear registro en la base de datos con métricas clave y resumen ejecutivo. */
            AgentDailyReport::create([
                'report_date'        => $date->toDateString(),
                'report_type'        => $report_type,
                'file_path'          => $filename,
                'executive_summary'  => $this->build_executive_summary($data),
                'alert_count'        => count($data['alerts']),
                'active_leads_count' => count($data['active_leads']),
                'metrics_snapshot'   => $data['metrics'],
            ]);

            /* Eliminar archivos y registros viejos según la retención configurada. */
            $this->cleanup_old_reports();

            $this->info("Reporte generado: {$filename}");
            return 0;

        } catch (\Throwable $e) {
            Log::channel('daily')->error('GenerateDailyAgentReportCommand falló', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            $this->error("Error generando reporte: {$e->getMessage()}");
            return 1;
        }
    }

    /**
     * Recopila todos los datos necesarios para el reporte del período indicado.
     * Si es semanal, el período es la última semana; si es diario, solo el día anterior.
     *
     * @param Carbon $date      Fecha del reporte (ayer o la pasada con --date).
     * @param bool   $is_weekly Si true, recopila datos de los últimos 7 días.
     *
     * @return array Datos estructurados listos para build_markdown().
     */
    private function collect_data(Carbon $date, bool $is_weekly): array
    {
        /* Rango temporal del período. */
        $from = $is_weekly
            ? $date->copy()->subDays(7)->startOfDay()
            : $date->copy()->startOfDay();
        $to = $date->copy()->endOfDay();

        /* 1. Leads nuevos que ingresaron en el período. */
        $new_leads = Lead::whereBetween('created_at', [$from, $to])
            ->with(['messages', 'welcome_variant', 'partners'])
            ->get();

        /* 2. Leads con actividad: al menos un mensaje en el período. */
        $active_lead_ids = LeadMessage::whereBetween('created_at', [$from, $to])
            ->distinct()
            ->pluck('lead_id');

        $active_leads = Lead::whereIn('id', $active_lead_ids)
            ->with(['messages' => fn ($q) => $q->orderBy('id')])
            ->get();

        /* 3. Leads con demos agendadas en el período. */
        $leads_with_demo_scheduled = Lead::whereBetween(
            'demo_date',
            [$from->toDateString(), $to->toDateString()]
        )->get(['id', 'contact_name', 'company_name', 'status', 'demo_date', 'demo_start_time']);

        /* 4. Leads que confirmaron ingreso a la demo en el período. */
        $leads_demo_confirmed = Lead::where('demo_ingreso_confirmado', true)
            ->whereBetween('demo_ingreso_confirmado_at', [$from, $to])
            ->get(['id', 'contact_name', 'demo_date']);

        /* 5. Leads cuya demo terminó confirmada en el período. */
        $leads_demo_finished = Lead::where('demo_terminada_confirmada', true)
            ->whereBetween('demo_terminada_confirmada_at', [$from, $to])
            ->get(['id', 'contact_name', 'closer_called_at']);

        /* 6. Leads con call_summary generado recientemente (transcripciones nuevas). */
        $leads_with_new_summary = Lead::whereNotNull('call_summary')
            ->whereBetween('updated_at', [$from, $to])
            ->whereNotNull('recall_bot_id')
            ->get(['id', 'contact_name', 'company_name', 'call_summary']);

        /* 7. Alertas: mensajes que fallaron el envío por WhatsApp (columna opcional). */
        $error_messages = $this->get_error_messages($from, $to);

        /* 8. Alertas: leads atascados sin actividad hace más de 48 horas en etapas activas. */
        $stuck_leads = Lead::whereIn('status', ['contactado', 'calificado', 'closer_activo'])
            ->where('last_message_at', '<', now()->subHours(48))
            ->whereNull('promoted_client_id')
            ->get(['id', 'contact_name', 'status', 'last_message_at']);

        /* 9. Todas las variantes de mensaje para tabla A/B. */
        $variants = MessageVariant::all();

        /* 10. Métricas generales del funnel completo (acumulado histórico + nuevos del período). */
        $funnel = [
            'total_leads'       => Lead::count(),
            'respondieron'      => Lead::whereHas('messages', fn ($q) => $q->where('sender', 'lead'))->count(),
            'demo_agendada'     => Lead::whereNotNull('demo_date')->count(),
            'demo_confirmada'   => Lead::where('demo_ingreso_confirmado', true)->count(),
            'demo_terminada'    => Lead::where('demo_terminada_confirmada', true)->count(),
            'promovidos'        => Lead::whereNotNull('promoted_client_id')->count(),
            'nuevos_ayer'       => $new_leads->count(),
        ];

        /* 11. Presupuesto Meta Ads para calcular costo por lead. */
        $meta_budget = (float) AdminSetting::get('meta_daily_budget_usd', 7);

        /* 12. [SOLO SEMANAL] Evolución histórica semanal de las últimas 5 semanas. */
        $weekly_history = [];
        if ($is_weekly) {
            for ($i = 4; $i >= 0; $i--) {
                $week_start       = $date->copy()->subWeeks($i)->startOfWeek();
                $week_end         = $week_start->copy()->endOfWeek();
                $weekly_history[] = [
                    'semana'      => $week_start->format('d/m'),
                    'leads'       => Lead::whereBetween('created_at', [$week_start, $week_end])->count(),
                    'respondieron'=> Lead::whereBetween('created_at', [$week_start, $week_end])
                        ->whereHas('messages', fn ($q) => $q->where('sender', 'lead'))->count(),
                    'demos'       => Lead::whereBetween(
                        'demo_date',
                        [$week_start->toDateString(), $week_end->toDateString()]
                    )->count(),
                ];
            }
        }

        /* Consolidar todas las alertas en un array unificado. */
        $alerts = array_merge(
            $error_messages->map(fn ($m) => [
                'tipo'    => 'error_envio',
                'lead_id' => $m->lead_id,
                'lead'    => ($m->lead ? $m->lead->contact_name : null) ?? "Lead #{$m->lead_id}",
                'detalle' => $m->whatsapp_send_error ?? 'Error desconocido',
            ])->toArray(),
            $stuck_leads->map(fn ($l) => [
                'tipo'    => 'lead_sin_respuesta',
                'lead_id' => $l->id,
                'lead'    => $l->contact_name ?? "Lead #{$l->id}",
                'detalle' => 'Sin actividad hace ' . now()->diffInHours($l->last_message_at) . 'hs (estado: ' . $l->status . ')',
            ])->toArray()
        );

        /* Métricas compactas para el metrics_snapshot del registro en BD. */
        $metrics = [
            'funnel'       => $funnel,
            'meta_budget'  => $meta_budget,
            'new_leads'    => $new_leads->count(),
            'active_leads' => $active_leads->count(),
        ];

        return compact(
            'new_leads', 'active_leads', 'leads_with_demo_scheduled',
            'leads_demo_confirmed', 'leads_demo_finished', 'leads_with_new_summary',
            'error_messages', 'stuck_leads', 'variants', 'funnel',
            'meta_budget', 'weekly_history', 'alerts', 'from', 'to', 'metrics'
        );
    }

    /**
     * Obtiene mensajes con error de envío WhatsApp.
     * Usa try/catch porque la columna whatsapp_send_error puede no existir en todos los entornos.
     *
     * @param Carbon $from Inicio del período.
     * @param Carbon $to   Fin del período.
     *
     * @return \Illuminate\Support\Collection
     */
    private function get_error_messages(Carbon $from, Carbon $to): \Illuminate\Support\Collection
    {
        try {
            return LeadMessage::whereBetween('created_at', [$from, $to])
                ->whereNotNull('whatsapp_send_error')
                ->with('lead:id,contact_name,phone,status')
                ->get();
        } catch (\Throwable $e) {
            /* Si la columna no existe aún, retornar colección vacía sin romper el reporte. */
            Log::channel('daily')->warning('get_error_messages: columna whatsapp_send_error no disponible', [
                'error' => $e->getMessage(),
            ]);
            return collect();
        }
    }

    /**
     * Construye el contenido del archivo markdown completo del reporte.
     *
     * @param array  $data      Datos recopilados por collect_data().
     * @param Carbon $date      Fecha del reporte.
     * @param bool   $is_weekly Si true, el reporte es semanal.
     *
     * @return string Contenido markdown listo para guardar.
     */
    private function build_markdown(array $data, Carbon $date, bool $is_weekly): string
    {
        /* Cabecera del tipo de reporte para el título. */
        $type_label    = $is_weekly ? 'SEMANAL' : 'DIARIO';
        $date_label    = $date->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY');
        $generated_at  = now()->format('Y-m-d H:i:s');
        $period_label  = $is_weekly ? 'semanal' : 'diario';

        /* Costo por lead del día con base en el presupuesto Meta. */
        $new_leads_count = $data['new_leads']->count();
        $cost_per_lead   = $new_leads_count > 0
            ? round($data['meta_budget'] / $new_leads_count, 2)
            : 0;

        /* Tasa de respuesta al welcome: leads que respondieron vs total de leads. */
        $total_leads         = $data['funnel']['total_leads'];
        $respondieron        = $data['funnel']['respondieron'];
        $response_rate       = $total_leads > 0 ? round(($respondieron / $total_leads) * 100, 1) : 0;

        /* Tasa de cierre: demos terminadas vs total leads. */
        $demos_terminadas    = $data['funnel']['demo_terminada'];
        $closure_rate        = $total_leads > 0 ? round(($demos_terminadas / $total_leads) * 100, 1) : 0;

        /* Demos necesarias por día para lograr 1 venta si la tasa de cierre es la actual. */
        $demos_needed_per_day = $closure_rate > 0 ? round(100 / $closure_rate, 1) : 'N/A';

        $md = "# Reporte ComercioCity - {$type_label} - {$date_label}\n";
        $md .= "_Generado: {$generated_at}. Subir a Claude en el proyecto ComercioCity con el mensaje: \"Análisis {$period_label} {$date->toDateString()}\"_\n\n";

        /* --- Métricas del período --- */
        $md .= "## 📊 Métricas del período\n\n";
        $md .= "| Métrica | Valor |\n";
        $md .= "|---|---|\n";
        $md .= "| Leads totales acumulados | {$total_leads} |\n";
        $md .= "| Leads nuevos en el período | {$new_leads_count} |\n";
        $md .= "| Respondieron al welcome | {$respondieron} ({$response_rate}%) |\n";
        $md .= "| Demos agendadas (total) | {$data['funnel']['demo_agendada']} |\n";
        $md .= "| Demos confirmadas ingreso (total) | {$data['funnel']['demo_confirmada']} |\n";
        $md .= "| Demos terminadas (total) | {$demos_terminadas} |\n";
        $md .= "| Promovidos a clientes (total) | {$data['funnel']['promovidos']} |\n";
        $md .= "| Leads activos en el período | " . count($data['active_leads']) . " |\n\n";

        /* --- Contexto de inversión --- */
        $md .= "## 💰 Contexto de inversión\n\n";
        $md .= "Presupuesto diario Meta: \${$data['meta_budget']} USD | ";
        $md .= "Costo por lead hoy: \${$cost_per_lead} USD | ";
        $md .= "Para llegar a 1 venta/día con tasa de cierre actual ({$closure_rate}%) necesitás {$demos_needed_per_day} demos/día.\n\n";

        /* --- Movimientos del pipeline --- */
        $md .= "## 🔄 Movimientos del pipeline\n\n";

        if ($data['leads_with_demo_scheduled']->isNotEmpty()) {
            $md .= "### Demos agendadas en el período\n\n";
            $data['leads_with_demo_scheduled']->each(function ($lead) use (&$md) {
                /* Extraer empresa antes de interpolar para evitar ?? en string interpolation. */
                $empresa = $lead->company_name ?: 'sin empresa';
                $md .= "- **{$lead->contact_name}** ({$empresa}) — {$lead->demo_date} {$lead->demo_start_time} — status: {$lead->status}\n";
            });
            $md .= "\n";
        }

        if ($data['leads_demo_confirmed']->isNotEmpty()) {
            $md .= "### Ingresos confirmados\n\n";
            $data['leads_demo_confirmed']->each(function ($lead) use (&$md) {
                $md .= "- **{$lead->contact_name}** — demo: {$lead->demo_date}\n";
            });
            $md .= "\n";
        }

        if ($data['leads_demo_finished']->isNotEmpty()) {
            $md .= "### Demos terminadas\n\n";
            $data['leads_demo_finished']->each(function ($lead) use (&$md) {
                $closer_at = $lead->closer_called_at ? $lead->closer_called_at->format('H:i') : 'N/A';
                $md .= "- **{$lead->contact_name}** — closer llamado a las {$closer_at}\n";
            });
            $md .= "\n";
        }

        if (
            $data['leads_with_demo_scheduled']->isEmpty()
            && $data['leads_demo_confirmed']->isEmpty()
            && $data['leads_demo_finished']->isEmpty()
        ) {
            $md .= "_Sin movimientos de pipeline en el período._\n\n";
        }

        /* --- Alertas --- */
        $alert_count = count($data['alerts']);
        $md .= "## ⚠️ Alertas ({$alert_count} detectadas)\n\n";

        if ($alert_count === 0) {
            $md .= "_Sin alertas en el período._\n\n";
        } else {
            foreach ($data['alerts'] as $alert) {
                $md .= "- **[{$alert['tipo']}]** {$alert['lead']} (Lead #{$alert['lead_id']}): {$alert['detalle']}\n";
            }
            $md .= "\n";
        }

        /* --- Conversaciones con actividad --- */
        $md .= "## 💬 Conversaciones con actividad\n\n";

        if ($data['active_leads']->isEmpty()) {
            $md .= "_Sin conversaciones activas en el período._\n\n";
        } else {
            $data['active_leads']->each(function ($lead) use (&$md) {
                /* Extraer empresa para evitar ?? en string interpolation. */
                $empresa = $lead->company_name ?: 'sin empresa';
                $md .= "### {$lead->contact_name} ({$empresa}) — status: {$lead->status}\n\n";

                /* Incluir todos los mensajes del lead sin truncar; Claude necesita el contexto completo. */
                $lead->messages->each(function ($msg) use (&$md) {
                    /* Marcar mensajes excluidos del contexto para que Claude sepa que el agente no los vio. */
                    $context_note = $msg->deleted_from_context ? ' [EXCLUIDO DEL CONTEXTO]' : '';

                    /* Marcar mensajes con error de envío. */
                    $error_note = '';
                    if (isset($msg->whatsapp_send_error) && $msg->whatsapp_send_error) {
                        $error_note = " [ERROR DE ENVÍO: {$msg->whatsapp_send_error}]";
                    }

                    $ts      = $msg->created_at ? $msg->created_at->format('Y-m-d H:i') : '??:??';
                    $content = $msg->content ?? $msg->body ?? '';
                    $md .= "**{$msg->sender} {$ts}:{$context_note}{$error_note}** {$content}\n\n";
                });
            });
        }

        /* --- Transcripciones de llamadas --- */
        $md .= "## 📞 Transcripciones de llamadas\n\n";

        if ($data['leads_with_new_summary']->isEmpty()) {
            $md .= "_Sin transcripciones nuevas en el período._\n\n";
        } else {
            $data['leads_with_new_summary']->each(function ($lead) use (&$md) {
                /* Extraer empresa para evitar ?? en string interpolation. */
                $empresa = $lead->company_name ?: 'sin empresa';
                $md .= "### {$lead->contact_name} ({$empresa})\n\n";

                /* Incluir el JSON completo del call_summary para análisis de Claude. */
                $summary_json = json_encode($lead->call_summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $md .= "```json\n{$summary_json}\n```\n\n";

                /* Si hay personas adicionales detectadas, listarlas aparte. */
                $personas = $lead->call_summary['personas_adicionales'] ?? null;
                if (!empty($personas)) {
                    $md .= "**Personas adicionales detectadas:**\n\n";
                    foreach ($personas as $persona) {
                        $nombre = $persona['nombre'] ?? 'N/A';
                        $rol    = $persona['rol'] ?? 'N/A';
                        $md .= "- {$nombre} (rol: {$rol})\n";
                    }
                    $md .= "\n";
                }
            });
        }

        /* --- Variantes A/B acumuladas --- */
        $md .= "## 🧪 Variantes A/B - Métricas acumuladas\n\n";

        if ($data['variants']->isEmpty()) {
            $md .= "_Sin variantes configuradas._\n\n";
        } else {
            $md .= "| Slug | Nombre | Tipo | Activa | Enviados | Respondieron | Demos | Asistieron |\n";
            $md .= "|---|---|---|---|---|---|---|---|\n";

            $data['variants']->each(function ($v) use (&$md) {
                $activa  = $v->active ? 'sí' : 'no';
                $sent    = $v->sent_count ?? 0;
                $resp    = $v->responded_count ?? 0;
                $sched   = $v->scheduled_count ?? 0;
                $att     = $v->attended_count ?? 0;
                $md .= "| {$v->slug} | {$v->name} | {$v->message_type} | {$activa} | {$sent} | {$resp} | {$sched} | {$att} |\n";
            });
            $md .= "\n";
        }

        /* --- Histórico semanal (solo lunes) --- */
        if ($is_weekly && !empty($data['weekly_history'])) {
            $md .= "## 📈 Histórico semanal (últimas 5 semanas)\n\n";
            $md .= "| Semana | Leads | Respondieron | Demos |\n";
            $md .= "|---|---|---|---|\n";
            foreach ($data['weekly_history'] as $week) {
                $md .= "| {$week['semana']} | {$week['leads']} | {$week['respondieron']} | {$week['demos']} |\n";
            }
            $md .= "\n";
        }

        return $md;
    }

    /**
     * Genera el resumen ejecutivo de 2-3 líneas para mostrar en el panel sin descargar el archivo.
     *
     * @param array $data Datos recopilados por collect_data().
     *
     * @return string Texto plano con el resumen ejecutivo.
     */
    private function build_executive_summary(array $data): string
    {
        /* Leads nuevos y tasa de respuesta. */
        $new_count     = $data['new_leads']->count();
        $total_leads   = $data['funnel']['total_leads'];
        $respondieron  = $data['funnel']['respondieron'];
        $response_rate = $total_leads > 0 ? round(($respondieron / $total_leads) * 100, 1) : 0;

        $summary = "Ayer entraron {$new_count} leads. Tasa de respuesta al welcome: {$response_rate}%.";

        /* Mencionar alertas si las hay. */
        $alert_count = count($data['alerts']);
        if ($alert_count > 0) {
            $first_alert = $data['alerts'][0];
            $summary .= " {$alert_count} alerta(s): {$first_alert['lead']} — {$first_alert['detalle']}.";
        } else {
            $summary .= " Sin alertas detectadas.";
        }

        /* Mencionar la última llamada con el closer si la hubo. */
        if ($data['leads_with_new_summary']->isNotEmpty()) {
            $last_call = $data['leads_with_new_summary']->first();
            $escenario = $last_call->call_summary['escenario'] ?? 'desconocido';
            $summary .= " Tommy tuvo llamada con {$last_call->contact_name}, escenario: {$escenario}.";
        }

        return $summary;
    }

    /**
     * Elimina archivos y registros de reportes más viejos que el período de retención configurado.
     *
     * @return void
     */
    private function cleanup_old_reports(): void
    {
        /* Días de retención configurables desde admin_settings. */
        $retention_days = (int) AdminSetting::get('agent_report_retention_days', 90);
        $cutoff_date    = now()->subDays($retention_days);

        /* Obtener registros viejos para eliminar sus archivos físicos primero. */
        $old_reports = AgentDailyReport::where('report_date', '<', $cutoff_date->toDateString())->get();

        $old_reports->each(function ($report) {
            /* Eliminar el archivo del disco si existe. */
            if ($report->file_path && Storage::exists($report->file_path)) {
                Storage::delete($report->file_path);
            }

            /* Eliminar el registro de la BD. */
            $report->delete();
        });

        if ($old_reports->isNotEmpty()) {
            $this->info("Eliminados {$old_reports->count()} reporte(s) viejos (retención: {$retention_days} días).");
        }
    }
}
