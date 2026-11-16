<?php

namespace App\Services;

use App\Models\SupportCodexMission;
use App\Models\SupportCodexMissionFixEvidence;
use App\Models\SupportCodexReleaseClaim;
use App\Models\SupportCodexScheduledUpgrade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Instalar el mismo release puede entregar varias correcciones; cada flujo conserva su verificación. */
class SupportMissionDeliveryService
{
    public function record_verified_schedule(SupportCodexScheduledUpgrade $schedule): void
    {
        if ($schedule->status !== SupportCodexScheduledUpgrade::VERIFIED) { return; }
        foreach (SupportCodexMission::where('support_ticket_id', $schedule->support_ticket_id)->whereNotNull('mission_started_at')->get() as $mission) {
            $this->cover($mission, $schedule);
        }
    }

    public function refresh(SupportCodexMission $mission): void
    {
        if ($mission->installed_at !== null) { return; }
        foreach (SupportCodexScheduledUpgrade::where('support_ticket_id', $mission->support_ticket_id)
            ->where('status', SupportCodexScheduledUpgrade::VERIFIED)->latest('id')->get() as $schedule) {
            if ($this->cover($mission, $schedule)) { return; }
        }
    }

    private function cover(SupportCodexMission $mission, SupportCodexScheduledUpgrade $schedule): bool
    {
        if ($mission->installed_at !== null) { return true; }
        $evidence = SupportCodexMissionFixEvidence::where('support_ticket_id', $mission->support_ticket_id)
            ->where('mission_thread_id', $mission->mission_thread_id)->where('mission_hash', $mission->mission_hash)->first();
        if ($evidence === null) { return false; }
        $direct = (int) $schedule->agreement->mission_fix_evidence_id === (int) $evidence->id;
        if (! $direct) {
            $commits = $evidence->payload['commits'] ?? [];
            $claim = SupportCodexReleaseClaim::where('agreement_id', $schedule->agreement_id)->whereNotNull('published_at')->first();
            if ($commits === [] || $claim === null || $claim->version !== $schedule->version
                || (int) $claim->version_id !== (int) $schedule->version_id
                || ($claim->target ?: 'sistema') !== ($schedule->target ?: 'sistema')
                || ($claim->proof['artifacts'] ?? []) != $schedule->artifacts
                || array_diff(array_keys($commits), SupportMaintenancePolicy::repos($schedule->target ?: 'sistema')) !== []) { return false; }
            try {
                foreach ($commits as $repo => $commit) {
                    $tag = $claim->proof['tag_commits'][$repo] ?? null;
                    if ($tag === null || ! app(ReleaseArtifactService::class)->support_fix_is_ancestor($repo, $commit, $tag)) { return false; }
                }
            } catch (\Throwable $error) {
                Log::warning('No se confirmó que el release instalado incluya otra misión del ticket.', ['mission_id' => $mission->id, 'schedule_id' => $schedule->id]);
                return false;
            }
        }
        // La prueba funcional de A no acredita B. Solo la pertenencia de los commits al release instalado.
        $mission->update(['completed_at' => $mission->completed_at ?: $evidence->created_at,
            'installed_schedule_id' => $schedule->id, 'installed_at' => now(),
            'delivery_verified_at' => $direct ? now() : null,
            'delivery_verification_evidence' => $direct ? $schedule->verification_evidence : null]);
        return true;
    }

    public function context(?SupportCodexMission $mission): ?array
    {
        if ($mission === null || $mission->installed_at === null) { return null; }
        $schedule = SupportCodexScheduledUpgrade::find($mission->installed_schedule_id);
        return ['mission_id' => (int) $mission->id, 'schedule_id' => (int) $mission->installed_schedule_id,
            'status' => $mission->delivery_verified_at === null ? 'installed_pending_verification' : 'verified',
            'target' => $schedule === null ? null : ($schedule->target ?: 'sistema'),
            'version' => $schedule === null ? null : $schedule->version,
            'verification_evidence' => $mission->delivery_verification_evidence];
    }

    public function verify(int $ticket_id, array $input): array
    {
        return DB::transaction(function () use ($ticket_id, $input) {
            $mission = SupportCodexMission::where('support_ticket_id', $ticket_id)->lockForUpdate()->findOrFail($input['mission_id']);
            $schedule = SupportCodexScheduledUpgrade::where('support_ticket_id', $ticket_id)->findOrFail($input['schedule_id']);
            abort_unless($schedule->status === SupportCodexScheduledUpgrade::VERIFIED && $mission->installed_at !== null
                && (int) $mission->installed_schedule_id === (int) $schedule->id, 409, 'La misión no tiene esa instalación comprobada.');
            if ($mission->delivery_verified_at === null) {
                $mission->update(['delivery_verified_at' => now(), 'delivery_verification_evidence' => trim($input['evidencia'])]);
            }
            return ['mission_delivery' => $this->context($mission->fresh())];
        });
    }
}
