<?php

namespace App\Services;

use App\Models\ClientVersionUpgrade;
use App\Models\SupportCodexCase;
use App\Models\SupportCodexMissionFixEvidence;
use App\Models\SupportCodexUpgradeAgreement;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Carbon\Carbon;

/** Inspección sin efectos. Un resultado de esta lectura no autoriza encolar ni tocar el ERP. */
class SupportUpgradePreflight
{
    private const TIMEZONE = 'America/Argentina/Buenos_Aires';

    private $schedule;
    private $agreements;
    private $ssh;

    public function __construct(ClientScheduleResolver $schedule, SupportCodexUpgradeAgreementService $agreements, EnvSshService $ssh)
    {
        $this->schedule = $schedule;
        $this->agreements = $agreements;
        $this->ssh = $ssh;
    }

    public function inspect(int $agreement_id): array
    {
        $agreement = SupportCodexUpgradeAgreement::with('operation')->find($agreement_id);
        if ($agreement === null || $agreement->accepted_at === null || empty($agreement->confirmation_message_id)) {
            return $this->blocked('acuerdo_sin_aceptar');
        }

        $ticket = SupportTicket::with('client.active_client_api')->find($agreement->support_ticket_id);
        if ($ticket === null || $ticket->status !== 'open' || $ticket->client_employee_id !== null
            || ! $ticket->claude_auto_reply || ! SupportCodexSettings::manages($ticket)) {
            return $this->blocked('ticket_no_elegible');
        }

        $case = SupportCodexCase::where('support_ticket_id', $ticket->id)->first();
        $evidence = SupportCodexMissionFixEvidence::current_for($ticket, $case);
        if ($evidence === null || (int) $evidence->id !== (int) $agreement->mission_fix_evidence_id) {
            return $this->blocked('evidencia_obsoleta');
        }

        $operation = $agreement->operation;
        if ($operation === null || ! in_array($operation->status, ['sent', 'reconciled_sent'], true)
            || (int) $operation->support_ticket_id !== (int) $ticket->id) {
            return $this->blocked('propuesta_no_enviada');
        }
        $latest = SupportMessage::where('support_ticket_id', $ticket->id)->where('sender_type', 'user');
        SupportMessage::apply_mensaje_real_conditions($latest, 'support_messages');
        $latest = $latest->orderByDesc('id')->first();
        $outbound = SupportMessage::find($operation->message_id);
        if ($latest === null || (int) $latest->id !== (int) $agreement->confirmation_message_id
            || ! $this->agreements->is_exact_confirmation($agreement, $latest, $outbound)) {
            return $this->blocked('confirmacion_obsoleta');
        }

        $client = $ticket->client;
        $api = $client === null ? null : $client->active_client_api;
        if ($client === null || ! $client->is_active || empty($client->current_version_id) || empty($client->user_id)
            || $client->shared_database_group_id !== null || $api === null
            || (int) $api->client_id !== (int) $client->id
            || (int) $api->id !== $client->resolver_frente_principal_client_api_id()) {
            return $this->blocked('frente_o_cliente_no_elegible');
        }

        if (ClientVersionUpgrade::where('client_id', $client->id)
            ->whereIn('deployment_status', ['running', 'paused', 'paused_post_tasks'])->exists()) {
            return $this->blocked('otro_despliegue_en_curso');
        }

        $now = now();
        $start = Carbon::parse($agreement->starts_at_utc, 'UTC');
        $end = Carbon::parse($agreement->ends_at_utc, 'UTC');
        $minutes = (int) $agreement->minimum_minutes;
        if ($minutes < 1 || $minutes > 1440 || $now->lessThan($start) || $now->greaterThanOrEqualTo($end)
            || $now->copy()->addMinutes($minutes)->greaterThan($end)) {
            return $this->blocked('ventana_inactiva_o_sin_margen');
        }
        if (SupportCodexUpgradeAgreement::where('id', '!=', $agreement->id)->whereNotNull('accepted_at')
            ->whereIn('support_ticket_id', SupportTicket::select('id')->where('client_id', $client->id))
            ->where('starts_at_utc', '<', $agreement->ends_at_utc)
            ->where('ends_at_utc', '>', $agreement->starts_at_utc)->exists()) {
            return $this->blocked('otro_acuerdo_superpuesto');
        }

        $minute = $now->copy()->setTimezone(self::TIMEZONE)->startOfMinute();
        $current = $this->schedule->primera_ventana_cerrada($client, $minute, $minutes, self::TIMEZONE)['window'];
        if ($current === null || $current['starts_at'] !== $minute->toIso8601String()
            || Carbon::parse($current['ends_at'])->lessThan($end)) {
            return $this->blocked('horario_cambio');
        }

        try {
            $activity = $this->ssh->read_upgrade_activity_for($api, (int) $client->user_id);
        } catch (\Throwable $error) {
            return $this->blocked('actividad_no_verificada');
        }
        if ($activity['usuarios_activos'] > 0 || $activity['ventas_recientes'] > 0) {
            return $this->blocked('actividad_reciente');
        }

        // Todavía no existe la vinculación durable evidencia → release publicado exacto.
        // Tampoco se verifica aquí el tráfico HTTP, las migraciones ni la segunda guarda del worker.
        return $this->blocked('version_no_vinculada');
    }

    private function blocked(string $reason): array
    {
        return ['ready' => false, 'reason' => $reason];
    }
}
