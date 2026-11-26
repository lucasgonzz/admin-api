<?php

namespace App\Services;

use App\Models\ClientUpgradeMark;
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

    /**
     * La misión quedó instalada por una actualización del cliente, sin acuerdo de horario (misión
     * martin-descripcion-y-actualizaciones, 25/11/2026): la marca «requiere actualización» de su corrección se
     * resolvió porque el cliente instaló una versión que la contiene. Deja `installed_at` sin
     * `installed_schedule_id`, y con eso el admin le ofrece a Martín `mission_delivery_verification`.
     *
     * @param SupportCodexMission $mission
     *
     * @return void
     */
    public function mark_installed(SupportCodexMission $mission): void
    {
        if ($mission->installed_at !== null) { return; }
        $mission->update(['completed_at' => $mission->completed_at ?: now(), 'installed_schedule_id' => null, 'installed_at' => now()]);
    }

    /**
     * Contexto de entrega de la misión (contrato C5). `schedule_id` es null y `installed_via` es `actualizacion`
     * cuando la instaló la actualización del cliente (sin acuerdo); la versión y el destino salen entonces de la
     * marca resuelta.
     *
     * @param SupportCodexMission|null $mission
     *
     * @return array<string, mixed>|null
     */
    public function context(?SupportCodexMission $mission): ?array
    {
        if ($mission === null || $mission->installed_at === null) { return null; }
        $schedule = $mission->installed_schedule_id === null ? null : SupportCodexScheduledUpgrade::find($mission->installed_schedule_id);
        $target = $schedule === null ? null : ($schedule->target ?: 'sistema');
        $version = $schedule === null ? null : $schedule->version;
        if ($mission->installed_schedule_id === null) {
            $mark = ClientUpgradeMark::with('resolved_version')->where('support_codex_mission_id', $mission->id)
                ->whereNotNull('resolved_at')->orderByRaw("target = 'sistema' desc")->orderBy('id')->first();
            $target = $mark === null ? null : $mark->target;
            $version = $mark === null || $mark->resolved_version === null ? null : $mark->resolved_version->version;
        }
        return ['mission_id' => (int) $mission->id,
            'schedule_id' => $mission->installed_schedule_id === null ? null : (int) $mission->installed_schedule_id,
            'status' => $mission->delivery_verified_at === null ? 'installed_pending_verification' : 'verified',
            'installed_via' => $mission->installed_schedule_id === null ? 'actualizacion' : 'acuerdo',
            'target' => $target,
            'version' => $version,
            'verification_evidence' => $mission->delivery_verification_evidence];
    }

    public function verify(int $ticket_id, array $input): array
    {
        return DB::transaction(function () use ($ticket_id, $input) {
            $mission = SupportCodexMission::where('support_ticket_id', $ticket_id)->lockForUpdate()->findOrFail($input['mission_id']);
            abort_unless($mission->installed_at !== null, 409, 'La misión todavía no está instalada.');
            // Sin acuerdo (instalada por la actualización del cliente) no hay programación que citar; con acuerdo,
            // `schedule_id` sigue siendo obligatorio y tiene que coincidir (contrato C5).
            if ($mission->installed_schedule_id !== null) {
                $schedule = SupportCodexScheduledUpgrade::where('support_ticket_id', $ticket_id)->findOrFail($input['schedule_id'] ?? 0);
                abort_unless($schedule->status === SupportCodexScheduledUpgrade::VERIFIED
                    && (int) $mission->installed_schedule_id === (int) $schedule->id, 409, 'La misión no tiene esa instalación comprobada.');
            }
            if ($mission->delivery_verified_at === null) {
                $mission->update(['delivery_verified_at' => now(), 'delivery_verification_evidence' => trim($input['evidencia'])]);
            }
            return ['mission_delivery' => $this->context($mission->fresh())];
        });
    }
}
