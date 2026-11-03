<?php

namespace App\Services;

use App\Jobs\RunDeploymentJob;
use App\Models\Client;
use App\Models\ClientVersionUpgrade;
use App\Models\SupportCodexCase;
use App\Models\SupportCodexMissionFixEvidence;
use App\Models\SupportCodexReleaseClaim;
use App\Models\SupportCodexScheduledUpgrade as Scheduled;
use App\Models\SupportCodexUpgradeAgreement;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\Version;
use Carbon\Carbon;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SupportScheduledUpgradeService
{
    private const POST_CLOSURE = 'run_seeders';
    private const CONFIGURE = 'update_default_version';
    private const BEFORE_LIVE_MARGIN = 45;
    private const COMMANDS_MARGIN = 15;
    private const CONFIGURE_MARGIN = 5;
    private $preflight;

    public function __construct(SupportUpgradePreflight $preflight)
    {
        $this->preflight = $preflight;
    }

    public static function enabled(): bool
    {
        return (bool) config('services.support_codex_scheduled_upgrades.enabled', false);
    }

    public function schedule(int $ticket_id, int $agreement_id, int $version_id): Scheduled
    {
        abort_unless(self::enabled(), 503, 'La ejecución programada está desactivada.');
        return DB::transaction(function () use ($ticket_id, $agreement_id, $version_id) {
            SupportTicket::lockForUpdate()->findOrFail($ticket_id);
            $agreement = SupportCodexUpgradeAgreement::where('support_ticket_id', $ticket_id)
                ->lockForUpdate()->findOrFail($agreement_id);
            $existing = Scheduled::where('agreement_id', $agreement_id)->first();
            if ($existing !== null) {
                abort_unless((int) $existing->version_id === $version_id, 409, 'Ya se programó otra versión para este acuerdo.');
                return $existing;
            }
            abort_unless(Carbon::parse($agreement->starts_at_utc, 'UTC')->greaterThan(now()), 409, 'Venció el horario acordado. Hay que proponer uno nuevo.');

            $preflight = $this->preflight->inspect($agreement_id, null, true);
            abort_unless($preflight['ready'], 409, $preflight['reason']);
            $release = SupportCodexReleaseClaim::where('agreement_id', $agreement_id)
                ->where('mission_fix_evidence_id', $agreement->mission_fix_evidence_id)
                ->where('version_id', $version_id)->whereNotNull('published_at')->first();
            $version = Version::find($version_id);
            abort_unless($release !== null && $version !== null && $version->status === 'published'
                && $release->version === $version->version, 409, 'El arreglo no tiene esa versión publicada vinculada.');
            $artifacts = $release->proof['artifacts'] ?? [];
            $tag = 'v' . $version->version;
            $this->assert_artifacts($artifacts, $tag);
            $client = $preflight['client'];
            abort_unless($client->current_version !== null
                && version_compare($client->current_version->version, $version->version, '<'), 409, 'El cliente ya tiene esta versión o una posterior.');

            return Scheduled::create([
                'agreement_id' => $agreement_id,
                'support_ticket_id' => $ticket_id,
                'client_id' => $client->id,
                'client_api_id' => $preflight['api']->id,
                'from_version_id' => $client->current_version_id,
                'version_id' => $version_id,
                'version' => $version->version,
                'artifacts' => $artifacts,
                'status' => Scheduled::SCHEDULED,
                'starts_at_utc' => $agreement->starts_at_utc,
                'ends_at_utc' => $agreement->ends_at_utc,
            ]);
        });
    }

    public static function owns_upgrade(int $upgrade_id): bool
    {
        return Scheduled::where('upgrade_id', $upgrade_id)->exists();
    }

    public static function assert_manual_deployment_allowed(ClientVersionUpgrade $upgrade): void
    {
        abort_if(self::owns_upgrade((int) $upgrade->id), 409, 'Este upgrade pertenece a un acuerdo de soporte y requiere conciliación en ese circuito.');
    }

    public function tick(): void
    {
        if (! self::enabled()) {
            return;
        }
        $due = Scheduled::where('status', Scheduled::SCHEDULED)
            ->where('starts_at_utc', '<=', now()->utc()->toDateTimeString())
            ->orderBy('starts_at_utc')->limit(15)->pluck('id');
        $running = Scheduled::whereIn('status', Scheduled::EXECUTING)
            ->orderBy('updated_at')->limit(15)->pluck('id');
        $due = $due->merge($running);
        foreach ($due as $id) {
            try {
                $this->advance((int) $id);
            } catch (\Throwable $error) {
                Log::warning('No avanzó el upgrade de soporte.', ['schedule_id' => $id, 'error' => get_class($error)]);
            }
        }
        $notify = Scheduled::where('status', Scheduled::VERIFIED)->whereNull('notification_requested_at')
            ->orderBy('id')->limit(25)->pluck('id');
        foreach ($notify as $id) {
            $this->request_confirmation((int) $id);
        }
    }

    private function advance(int $id): void
    {
        DB::transaction(function () use ($id) {
            $row = Scheduled::lockForUpdate()->findOrFail($id);
            if ($row->status === Scheduled::SCHEDULED) {
                if (Carbon::parse($row->starts_at_utc, 'UTC')->greaterThan(now())) {
                    return;
                }
                Client::lockForUpdate()->findOrFail($row->client_id);
                $check = $this->preflight->inspect((int) $row->agreement_id, $row);
                if (! $check['ready']) {
                    $row->update(['status' => Scheduled::BLOCKED, 'reason' => $check['reason']]);
                    return;
                }
                $client = $check['client'];
                $version = Version::findOrFail($row->version_id);
                $service = app(ClientVersionUpgradeCreationService::class);
                $suggested = VersionPathService::candidatesBetween($client->current_version, $version)
                    ->filter(function (Version $candidate) use ($version) {
                        return ClientVersionUpgradeCreationService::es_sugerida_por_defecto($candidate, $version);
                    })->pluck('id')->all();
                $confirmed = $service->resolve_confirmed_version_ids($client, $version, $suggested);
                $upgrade = $service->create($client, $version, $confirmed, [
                    'target_client_api_id' => $row->client_api_id,
                    'scheduled_date' => Carbon::parse($row->starts_at_utc, 'UTC')
                        ->setTimezone('America/Argentina/Buenos_Aires')->toDateString(),
                    'created_via' => ClientVersionUpgrade::CREATED_VIA_CLAUDE,
                    'notes' => 'Actualización acordada en ticket de soporte #' . $row->support_ticket_id,
                ]);
                $row->upgrade_id = $upgrade->id;
                $this->enqueue($row, $upgrade, Scheduled::STARTING, null);
                return;
            }
            if (! in_array($row->status, Scheduled::EXECUTING, true)) {
                return;
            }
            $upgrade = $row->upgrade()->lockForUpdate()->first();
            if ($upgrade === null || $upgrade->deployment_status === 'failed') {
                $row->update(['status' => Scheduled::FAILED, 'reason' => 'pipeline_fallido']);
                return;
            }
            if ($row->status === Scheduled::STARTING && $upgrade->deployment_status === 'paused') {
                if (! $this->ready_for_post_closure($upgrade)) {
                    $row->update(['status' => Scheduled::FAILED, 'reason' => 'post_cierre_requiere_revision']);
                    return;
                }
                if (! $this->continue_allowed($row, self::COMMANDS_MARGIN, false)) {
                    return;
                }
                $this->enqueue($row, $upgrade, Scheduled::POST_CLOSURE, self::POST_CLOSURE);
            } elseif ($row->status === Scheduled::POST_CLOSURE && $upgrade->deployment_status === 'paused_post_tasks') {
                if (DeploymentService::en_el_lugar_sin_llegar_al_up($upgrade)) {
                    $row->update(['status' => Scheduled::FAILED, 'reason' => 'sistema_en_mantenimiento']);
                    return;
                }
                if (! $this->continue_allowed($row, self::CONFIGURE_MARGIN, false)) {
                    return;
                }
                $this->enqueue($row, $upgrade, Scheduled::CONFIGURING, self::CONFIGURE);
            } elseif ($row->status === Scheduled::CONFIGURING && $upgrade->deployment_status === 'completed') {
                if (! $this->database_completion($row, $upgrade)) {
                    $row->update(['status' => Scheduled::FAILED, 'reason' => 'configuracion_sin_confirmar']);
                    return;
                }
                if ($this->http_completion($upgrade)) {
                    $row->update(['status' => Scheduled::VERIFICATION_PENDING, 'reason' => null]);
                } elseif ($row->reason !== 'salud_http_sin_confirmar') {
                    $row->update(['reason' => 'salud_http_sin_confirmar']);
                } elseif ($row->updated_at->lessThanOrEqualTo(now()->subMinutes(15))) {
                    $row->update(['status' => Scheduled::FAILED]);
                }
            }
        });
    }

    private function enqueue(Scheduled $row, ClientVersionUpgrade $upgrade, string $stage, ?string $step): void
    {
        $row->status = $stage;
        $row->reason = null;
        $row->save();
        $upgrade->update([
            'deployment_status' => 'running',
            'deployment_started_at' => $upgrade->deployment_started_at ?? now(),
            'deployment_running_since' => now(),
        ]);
        // Se encola en la misma base y transacción que conserva el cambio de etapa.
        $job = (new RunDeploymentJob($upgrade, $step, (int) $row->id))->onConnection('database');
        app(Dispatcher::class)->dispatch($job);
    }

    public function prepare_job(int $id, ClientVersionUpgrade $upgrade, ?string $step): ?Scheduled
    {
        $row = Scheduled::findOrFail($id);
        $stage = $step === null ? Scheduled::STARTING : ($step === self::POST_CLOSURE ? Scheduled::POST_CLOSURE : Scheduled::CONFIGURING);
        if ($row->status !== $stage || $upgrade->deployment_status !== 'running') {
            return null;
        }
        if (! self::enabled() || (int) $row->upgrade_id !== (int) $upgrade->id
            || (int) $upgrade->client_id !== (int) $row->client_id || (int) $upgrade->target_client_api_id !== (int) $row->client_api_id
            || (int) $upgrade->to_version_id !== (int) $row->version_id
            || $upgrade->client === null || $upgrade->client->modo_de_despliegue_hacia($row->client_api_id) !== Client::MODO_EN_EL_LUGAR
            || (int) $upgrade->client->current_version_id !== (int) $row->from_version_id
            || ($step !== null && ! in_array($step, [self::POST_CLOSURE, self::CONFIGURE], true))) {
            throw new \RuntimeException('La tarea ya no corresponde a la actualización acordada.');
        }
        if ($step === null) {
            $check = $this->preflight->inspect((int) $row->agreement_id, $row);
            if (! $check['ready']) {
                $this->block_before_live($row, $check['reason']);
            }
            $keys = app(ClientAdminSyncClaveService::class)->ejecutar($check['client'], ClientAdminSyncClaveService::ACCION_VER);
            if (($keys['listo'] ?? null) !== true) {
                $this->block_before_live($row, 'clave_admin_sync_sin_confirmar');
            }
        } else {
            $margin = $step === self::POST_CLOSURE ? self::COMMANDS_MARGIN : self::CONFIGURE_MARGIN;
            if (! $this->continue_allowed($row, $margin, true)) {
                throw new \RuntimeException('La etapa quedó pendiente de conciliación.');
            }
            if ($step === self::POST_CLOSURE && ! $this->ready_for_post_closure($upgrade)) {
                throw new \RuntimeException('El post-cierre requiere revisión.');
            }
            if ($step === self::CONFIGURE && DeploymentService::en_el_lugar_sin_llegar_al_up($upgrade)) {
                throw new \RuntimeException('El sistema sigue en mantenimiento.');
            }
        }
        return $row;
    }

    public function before_live_write(int $id): void
    {
        $row = Scheduled::findOrFail($id);
        $check = $this->preflight->inspect((int) $row->agreement_id, $row, false, self::BEFORE_LIVE_MARGIN);
        if (! $check['ready']) {
            $this->block_before_live($row, $check['reason']);
        }
        $touched = DB::transaction(function () use ($id) {
            $fresh = Scheduled::lockForUpdate()->findOrFail($id);
            SupportTicket::lockForUpdate()->findOrFail($fresh->support_ticket_id);
            Client::lockForUpdate()->findOrFail($fresh->client_id);
            if (! self::enabled() || $fresh->status !== Scheduled::STARTING || $fresh->live_touched_at !== null) {
                return false;
            }
            $recheck = $this->preflight->inspect((int) $fresh->agreement_id, $fresh, false, self::BEFORE_LIVE_MARGIN, false);
            if (! $recheck['ready']) {
                $fresh->update(['status' => Scheduled::BLOCKED, 'reason' => $recheck['reason']]);
                return false;
            }
            $fresh->update(['live_touched_at' => now()]);
            return true;
        });
        if (! $touched) {
            throw new \RuntimeException('La última verificación no habilitó tocar producción.');
        }
    }

    public function before_staging(int $id): void
    {
        $row = Scheduled::findOrFail($id);
        if (! self::enabled() || $row->status !== Scheduled::STARTING || $row->live_touched_at !== null) {
            throw new \RuntimeException('La etapa no está habilitada para subir archivos al hosting.');
        }
        $check = $this->preflight->inspect((int) $row->agreement_id, $row, false, self::BEFORE_LIVE_MARGIN);
        if (! $check['ready']) {
            $this->block_before_live($row, $check['reason']);
        }
    }

    public function before_continuation(int $id, string $step): void
    {
        $row = Scheduled::findOrFail($id);
        if ($row->status === Scheduled::POST_CLOSURE && ! $this->ready_for_post_closure($row->upgrade)) {
            throw new \RuntimeException('El post-cierre requiere revisión.');
        }
        $margin = $step === self::CONFIGURE ? self::CONFIGURE_MARGIN : self::COMMANDS_MARGIN;
        if (! $this->continue_allowed($row, $margin, true)) {
            throw new \RuntimeException('La etapa quedó pendiente de conciliación.');
        }
        $allowed = DB::transaction(function () use ($id, $margin) {
            $fresh = Scheduled::lockForUpdate()->findOrFail($id);
            SupportTicket::lockForUpdate()->findOrFail($fresh->support_ticket_id);
            Client::lockForUpdate()->findOrFail($fresh->client_id);
            return $this->continue_allowed($fresh, $margin, false);
        });
        if (! $allowed) {
            throw new \RuntimeException('La última verificación dejó la etapa pendiente de conciliación.');
        }
    }

    private function continue_allowed(Scheduled $row, int $margin, bool $read_activity): bool
    {
        $check = $this->preflight->inspect((int) $row->agreement_id, $row, false, $margin, $read_activity);
        if (self::enabled() && $row->live_touched_at !== null && in_array($row->status, Scheduled::EXECUTING, true)
            && $check['ready']) {
            return true;
        }
        $reason = $check['reason'] ?? 'etapa_no_elegible';
        Scheduled::whereKey($row->id)->whereIn('status', Scheduled::EXECUTING)
            ->update(['status' => Scheduled::FAILED, 'reason' => 'reconciliar_' . $reason]);
        return false;
    }

    private function block_before_live(Scheduled $row, string $reason): void
    {
        Scheduled::whereKey($row->id)->where('status', Scheduled::STARTING)->whereNull('live_touched_at')
            ->update(['status' => Scheduled::BLOCKED, 'reason' => $reason]);
        throw new \RuntimeException('La actualización se frenó antes de tocar producción: ' . $reason);
    }

    private function ready_for_post_closure(ClientVersionUpgrade $upgrade): bool
    {
        $vps = optional($upgrade->target_client_api)->hosting_type === 'vps';
        return ($vps ? ! empty($upgrade->vps_supervisor_moved_at) : ! empty($upgrade->crons_supervisor_at))
            && ! DeploymentService::en_el_lugar_sin_llegar_al_up($upgrade)
            && ! $upgrade->deployment_logs()->where('step', 'restart_queue_workers')->where('level', 'warning')->exists()
            && ! DeploymentService::upgrade_has_pending_automatic_tasks($upgrade);
    }

    private function database_completion(Scheduled $row, ClientVersionUpgrade $upgrade): bool
    {
        $client = $upgrade->client;
        return $row->live_touched_at !== null && $upgrade->deployment_status === 'completed'
            && $upgrade->status === 'terminada' && $upgrade->default_version_sync_status === 'success'
            && $upgrade->sistema_configurado_at !== null && $client !== null
            && (int) $client->current_version_id === (int) $row->version_id
            && (int) $client->active_client_api_id === (int) $row->client_api_id;
    }

    private function http_completion(ClientVersionUpgrade $upgrade): bool
    {
        $target = $upgrade->target_client_api;
        if ($target === null) {
            return false;
        }
        $spa = rtrim((string) $target->spa_url, '/');
        $api = rtrim((new ClientEmpresaApiUrlResolver())->build_api_url_for_env($target), '/');
        if (parse_url($spa, PHP_URL_SCHEME) !== 'https' || parse_url($api, PHP_URL_SCHEME) !== 'https') {
            return false;
        }
        try {
            $client = Http::timeout(5)->withOptions(['allow_redirects' => false]);
            $config = $client->get($spa . '/config.js');
            if (! $config->successful() || ! preg_match('/^window\.__CC_CONFIG__\s*=\s*(\{.*\});\s*$/s', $config->body(), $matches)) {
                return false;
            }
            $vars = json_decode($matches[1], true);
            $configured_api = rtrim((string) ($vars['VUE_APP_API_URL'] ?? ''), '/');
            if ($configured_api !== $api || rtrim((string) ($vars['VUE_APP_APP_URL'] ?? ''), '/') !== $spa) {
                return false;
            }
            $index = $client->get($spa . '/');
            $user = $client->acceptJson()->get($api . '/api/user');
            return $index->successful() && preg_match('/\bid=["\']app["\']/', $index->body())
                && $user->status() === 401 && is_array($user->json());
        } catch (\Throwable $error) {
            return false;
        }
    }

    public function verify(int $ticket_id, array $data): Scheduled
    {
        return DB::transaction(function () use ($ticket_id, $data) {
            $row = Scheduled::where('support_ticket_id', $ticket_id)->where('agreement_id', $data['agreement_id'])
                ->where('upgrade_id', $data['upgrade_id'])->lockForUpdate()->firstOrFail();
            $ticket = SupportTicket::lockForUpdate()->findOrFail($ticket_id);
            if ($row->status === Scheduled::VERIFIED) {
                $evidence = SupportCodexMissionFixEvidence::findOrFail($row->agreement->mission_fix_evidence_id);
            } else {
                $case = SupportCodexCase::where('support_ticket_id', $ticket_id)->first();
                $evidence = SupportCodexMissionFixEvidence::current_for($ticket, $case);
            }
            abort_unless($evidence !== null && (int) $evidence->id === (int) $row->agreement->mission_fix_evidence_id
                && $data['mission_thread_id'] === $evidence->mission_thread_id
                && hash_equals($evidence->mission_hash, $data['mission_hash']), 409, 'Cambió la misión de la corrección.');
            if ($row->status === Scheduled::VERIFIED) {
                return $row;
            }
            abort_unless($row->status === Scheduled::VERIFICATION_PENDING && $row->upgrade !== null
                && $this->database_completion($row, $row->upgrade), 409, 'El despliegue no está técnicamente verificado.');
            if (isset($data['motivo']) && $row->verification_evidence !== null) {
                return $row;
            }
            $row->verification_evidence = $data['evidencia'];
            $row->reason = $data['resultado'] === 'verificado' ? null : ($data['motivo'] ?? 'reproduccion_inconclusa');
            if ($data['resultado'] === 'verificado') {
                $row->status = Scheduled::VERIFIED;
            }
            $row->save();
            return $row;
        });
    }

    private function request_confirmation(int $id): void
    {
        try {
            DB::transaction(function () use ($id) {
                $row = Scheduled::lockForUpdate()->findOrFail($id);
                if ($row->status !== Scheduled::VERIFIED || $row->notification_requested_at !== null) {
                    return;
                }
                $ticket = SupportTicket::lockForUpdate()->findOrFail($row->support_ticket_id);
                $case = SupportCodexCase::where('support_ticket_id', $ticket->id)->first();
                $evidence = SupportCodexMissionFixEvidence::current_for($ticket, $case);
                if ($evidence === null || (int) $evidence->id !== (int) $row->agreement->mission_fix_evidence_id) {
                    $row->update(['notification_requested_at' => now(), 'reason' => 'ticket_o_mision_cambio']);
                    return;
                }
                $messages = SupportMessage::where('support_ticket_id', $ticket->id);
                SupportMessage::apply_mensaje_real_conditions($messages, 'support_messages');
                $last = $messages->orderByDesc('id')->first();
                if ($last === null || ($last->sender_type === 'user' && (int) $last->id > (int) $case->handled_message_id
                    && (int) $last->id !== (int) $row->agreement->confirmation_message_id)) {
                    return;
                }
                app(SupportCodexService::class)->resume((int) $ticket->id, [
                    'expected_message_id' => $last->id,
                    'reason' => 'Actualización aplicada y corrección verificada; pedí al negocio que confirme.',
                    'request_confirmation' => true,
                ], 'Actualización verificada');
                $row->update(['notification_requested_at' => now()]);
            });
        } catch (\Throwable $error) {
            Log::warning('La confirmación del upgrade espera que se libere el ticket.', ['schedule_id' => $id, 'error' => get_class($error)]);
        }
    }

    private function assert_artifacts(array $artifacts, string $tag): void
    {
        foreach (['empresa-api' => "empresa-api-{$tag}.zip", 'empresa-spa' => "empresa-spa-{$tag}-dist.zip"] as $repo => $name) {
            $artifact = $artifacts[$repo] ?? [];
            abort_unless(($artifact['name'] ?? null) === $name && (int) ($artifact['id'] ?? 0) > 0
                && (int) ($artifact['size'] ?? 0) > 0 && preg_match('/^[a-f0-9]{64}$/D', $artifact['sha256'] ?? ''),
                409, 'Falta la identidad exacta del artefacto: ' . $repo);
        }
    }
}
