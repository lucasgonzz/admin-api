<?php

namespace App\Services;

use App\Jobs\RunEcommerceInstallationJob;
use App\Models\ClientEcommerce;
use App\Models\ClientEcommerceInstallation;
use App\Models\EcommerceVersion;
use App\Models\SupportCodexReleaseClaim;
use App\Models\SupportCodexScheduledUpgrade as Scheduled;
use App\Models\SupportCodexUpgradeAgreement;
use Carbon\Carbon;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/** Tienda usa su versión y su corrida; el acuerdo conserva la misma identidad durable del ticket. */
class SupportStoreUpgradeService
{
    public function schedule(int $ticket, SupportCodexUpgradeAgreement $agreement, int $version_id): Scheduled
    {
        $existing = Scheduled::where('agreement_id', $agreement->id)->first();
        if ($existing !== null) {
            abort_unless($existing->target === 'tienda' && (int) $existing->version_id === $version_id, 409, 'Ya se programó otro destino para este acuerdo.');
            return $existing;
        }
        abort_unless(Carbon::parse($agreement->starts_at_utc, 'UTC')->greaterThan(now()), 409, 'Venció el corte acordado.');
        $check = app(SupportUpgradePreflight::class)->inspect((int) $agreement->id, null, true);
        abort_unless($check['ready'], 409, $check['reason']);
        $store = ClientEcommerce::lockForUpdate()->findOrFail($agreement->client_ecommerce_id);
        $version = EcommerceVersion::published()->findOrFail($version_id);
        $release = SupportCodexReleaseClaim::where('agreement_id', $agreement->id)->where('target', 'tienda')
            ->where('mission_fix_evidence_id', $agreement->mission_fix_evidence_id)->where('version_id', $version_id)
            ->where('version', $version->version)->whereNotNull('published_at')->first();
        abort_unless($release !== null && $store->ecommerce_version !== null
            && VersionNumberComparator::compare($store->ecommerce_version->version, $version->version) < 0,
            409, 'La tienda no tiene una actualización publicada y verificable para este acuerdo.');
        abort_if($store->installations()->whereIn('status', ['pendiente', 'instalando'])->exists(), 409, 'Hay otra actualización de la tienda en curso.');
        $artifacts = $release->proof['artifacts'] ?? [];
        foreach (['tienda-api' => EcommerceReleaseArtifacts::api_asset_name($version->version), 'tienda-spa' => EcommerceReleaseArtifacts::spa_asset_name($version->version)] as $repo => $name) {
            $asset = $artifacts[$repo] ?? [];
            abort_unless(($asset['name'] ?? null) === $name && (int) ($asset['id'] ?? 0) > 0 && (int) ($asset['size'] ?? 0) > 0
                && preg_match('/^[a-f0-9]{64}$/D', $asset['sha256'] ?? ''), 409, 'Falta el artefacto exacto de tienda.');
        }
        return Scheduled::create([
            'target' => 'tienda', 'client_ecommerce_id' => $store->id, 'agreement_id' => $agreement->id,
            'support_ticket_id' => $ticket, 'client_id' => $check['client']->id, 'client_api_id' => $check['api']->id,
            'from_version_id' => $store->ecommerce_version_id, 'version_id' => $version_id, 'version' => $version->version,
            'artifacts' => $artifacts, 'status' => Scheduled::SCHEDULED,
            'starts_at_utc' => $agreement->starts_at_utc, 'ends_at_utc' => $agreement->ends_at_utc,
        ]);
    }

    public function advance(Scheduled $row): void
    {
        if ($row->status === Scheduled::SCHEDULED) {
            // Dejar drenar las peticiones que estaban terminando al empezar el corte.
            if (now()->lessThan(Carbon::parse($row->starts_at_utc, 'UTC')->addSeconds(30))) { return; }
            $check = app(SupportUpgradePreflight::class)->inspect((int) $row->agreement_id, $row);
            if (! $check['ready']) { $row->update(['status' => Scheduled::BLOCKED, 'reason' => $check['reason']]); return; }
            $store = ClientEcommerce::lockForUpdate()->findOrFail($row->client_ecommerce_id);
            if ($store->installations()->whereIn('status', ['pendiente', 'instalando'])->exists()) {
                $row->update(['status' => Scheduled::BLOCKED, 'reason' => 'otra_actualizacion_de_tienda']); return;
            }
            $installation = $store->installations()->create(['mode' => 'update', 'status' => 'pendiente',
                'created_via' => ClientEcommerceInstallation::CREATED_VIA_CLAUDE,
                'ecommerce_version_id' => $row->version_id, 'ecommerce_version_requested' => true]);
            $row->update(['ecommerce_installation_id' => $installation->id, 'status' => Scheduled::STARTING]);
            app(Dispatcher::class)->dispatch((new RunEcommerceInstallationJob($installation, (int) $row->id))->onConnection('database'));
            return;
        }
        if ($row->status !== Scheduled::STARTING) { return; }
        $installation = ClientEcommerceInstallation::find($row->ecommerce_installation_id);
        if ($installation === null || $installation->status === 'fallida') {
            $row->update(['status' => Scheduled::FAILED, 'reason' => $row->reason ?: 'pipeline_tienda_fallido']);
            if ($row->live_touched_at !== null) {
                $this->request_review($row, 'La actualización de tienda falló después de iniciar el corte. Verificar disponibilidad y el resultado de su restauración antes de coordinar otro intento.');
            }
            return;
        }
        if ($installation->status === 'completada' && $this->completed($row) && $this->healthy($row)) {
            $row->update(['status' => Scheduled::VERIFICATION_PENDING, 'reason' => null]);
        } elseif (Carbon::parse($row->ends_at_utc, 'UTC')->lessThan(now())) {
            $this->recover_abandoned($row, $installation);
        }
    }

    private function recover_abandoned(Scheduled $row, ClientEcommerceInstallation $installation): void
    {
        if ($row->live_touched_at === null) {
            $row->update(['status' => Scheduled::BLOCKED, 'reason' => 'ventana_vencida_sin_escritura']);
            return;
        }
        // Un worker vivo tiene este mismo candado por más tiempo que su timeout. No se restaura por encima.
        $lock = Cache::lock('client-deployment-' . $row->client_id, 1860);
        if (! $lock->get()) {
            $row->update(['reason' => 'reconciliar_tienda_en_ejecucion']);
            $this->request_review($row, 'Terminó el corte y el trabajador de la tienda todavía tiene el candado. Verificar su estado y la disponibilidad antes de intervenir.');
            return;
        }
        try {
            if (in_array($row->reason, ['restauracion_tienda_iniciada', 'restauracion_tienda_sin_confirmar'], true)) {
                $this->request_review($row, 'La restauración de tienda no tiene resultado confirmado; conservar el respaldo y conciliar antes de repetir.');
                return;
            }
            $row->update(['reason' => 'restauracion_tienda_iniciada']);
            app()->makeWith(SupportEcommerceDeploymentService::class, ['installation' => $installation, 'row' => $row])->recover();
            $this->request_review($row, 'La actualización de tienda se interrumpió y se restauraron los archivos respaldados. Verificar funcionalmente y coordinar otro corte; no anunciar el arreglo disponible.');
        } catch (\Throwable $error) {
            $row->update(['status' => Scheduled::FAILED, 'reason' => 'restauracion_tienda_sin_confirmar']);
            $this->request_review($row, 'El corte de tienda se interrumpió y no se confirmó la restauración. Revisar disponibilidad y respaldo antes de repetir una escritura.');
        } finally { $lock->release(); }
    }

    public function request_review(Scheduled $row, string $reason): void
    {
        $case = \App\Models\SupportCodexCase::where('support_ticket_id', $row->support_ticket_id)->first();
        if ($case === null) { return; }
        $case->update(['state' => 'manual_review', 'review_reason' => $reason]);
        $ticket = \App\Models\SupportTicket::find($row->support_ticket_id);
        if ($ticket !== null) { $ticket->update(['escalated_at' => now(), 'escalation_reason' => $reason]); }
    }

    public function prepare(int $id, ClientEcommerceInstallation $installation): ?Scheduled
    {
        $row = Scheduled::findOrFail($id);
        if ($row->status !== Scheduled::STARTING || $installation->status !== 'pendiente') { return null; }
        abort_unless(SupportScheduledUpgradeService::enabled() && $row->target === 'tienda'
            && (int) $row->ecommerce_installation_id === (int) $installation->id && $installation->mode === 'update'
            && (int) $row->client_ecommerce_id === (int) $installation->client_ecommerce_id
            && (int) $row->version_id === (int) $installation->ecommerce_version_id,
            409, 'La corrida no corresponde al corte acordado.');
        $this->before_write($row, false);
        return $row;
    }

    public function before_write(Scheduled $row, bool $live): void
    {
        $check = app(SupportUpgradePreflight::class)->inspect((int) $row->agreement_id, $row, false, 1);
        if (! SupportScheduledUpgradeService::enabled() || ! $check['ready']) {
            $row->update(['status' => $row->live_touched_at === null ? Scheduled::BLOCKED : Scheduled::FAILED,
                'reason' => $check['reason'] ?? 'ejecutor_desactivado']);
            throw new \RuntimeException('La escritura de tienda quedó bloqueada por el acuerdo o la actividad.');
        }
        if ($live && $row->live_touched_at === null) {
            abort_unless($row->backup_path && $row->backup_sha256 && $row->backup_size > 0, 409, 'Falta el respaldo verificado de la tienda.');
            $row->update(['live_touched_at' => now()]);
            Log::notice('Mantenimiento coordinado de tienda con force de horario.', ['schedule_id' => $row->id,
                'force' => true, 'force_reason' => SupportMaintenancePolicy::force_reason($row->agreement)]);
        }
    }

    public function completed(Scheduled $row): bool
    {
        $installation = ClientEcommerceInstallation::find($row->ecommerce_installation_id);
        $store = ClientEcommerce::find($row->client_ecommerce_id);
        return $row->live_touched_at !== null && $row->backup_path !== null && $row->backup_size > 0
            && $installation !== null && $installation->status === 'completada' && $store !== null && $store->status === 'active'
            && (int) $store->client_id === (int) $row->client_id && (int) $store->ecommerce_version_id === (int) $row->version_id;
    }

    private function healthy(Scheduled $row): bool
    {
        $store = ClientEcommerce::find($row->client_ecommerce_id);
        if ($store === null || parse_url($store->spa_url, PHP_URL_SCHEME) !== 'https' || parse_url($store->api_url, PHP_URL_SCHEME) !== 'https') { return false; }
        try {
            $http = Http::timeout(5)->withOptions(['allow_redirects' => false]);
            $version = $http->get(rtrim($store->spa_url, '/') . '/version.json');
            $page = $http->get(rtrim($store->spa_url, '/') . '/');
            $api = rtrim($store->api_url, '/');
            if (substr($api, -7) !== '/public') { $api .= '/public'; }
            $commerce = $http->get($api . '/api/commerce/' . (int) $store->client->user_id);
            return $version->successful() && $version->json('version') === $row->version && $page->successful()
                && preg_match('/\bid=["\']app["\']/', $page->body()) && $commerce->successful() && is_array($commerce->json('commerce'));
        } catch (\Throwable $error) { return false; }
    }
}
