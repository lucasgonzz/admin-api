<?php

namespace App\Services;

use App\Models\SupportCodexCase;
use App\Models\SupportCodexMissionFixEvidence;
use App\Models\SupportCodexReleaseClaim;
use App\Models\SupportCodexUpgradeAgreement;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\Version;
use App\Models\EcommerceVersion;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupportCodexReleaseService
{
    public const REPOS = ['empresa-api', 'empresa-spa'];

    public function work(): array
    {
        $work = [];
        $agreements = SupportCodexUpgradeAgreement::select('support_codex_upgrade_agreements.*')
            ->leftJoin('support_codex_release_claims as release_claim', 'release_claim.agreement_id', '=', 'support_codex_upgrade_agreements.id')
            ->whereNotNull('accepted_at')->whereNotExists(function ($query) {
            $query->selectRaw('1')->from('support_codex_scheduled_upgrades')
                ->whereColumn('agreement_id', 'support_codex_upgrade_agreements.id');
        })->orderByRaw('CASE WHEN release_claim.active_key IS NOT NULL THEN 0 WHEN release_claim.published_at IS NOT NULL THEN 1 ELSE 2 END')
            ->oldest('support_codex_upgrade_agreements.id')->get();
        foreach ($agreements as $agreement) {
            $ticket = SupportTicket::find($agreement->support_ticket_id);
            if ($ticket === null) {
                continue;
            }
            $evidence = $this->current_evidence($ticket, $agreement);
            if ($evidence === null) {
                continue;
            }
            $claim = SupportCodexReleaseClaim::where('agreement_id', $agreement->id)->first();
            if ($claim !== null && $claim->blocked_at !== null) {
                continue;
            }
            $reusable = $claim === null ? $this->reusable($agreement) : null;
            if ($claim === null && $reusable === null && Carbon::parse($agreement->starts_at_utc, 'UTC')->lessThanOrEqualTo(now())) {
                continue;
            }
            $work[] = [
                'ticket_id' => (int) $ticket->id, 'client_id' => (int) $ticket->client_id,
                'target' => $agreement->target ?: 'sistema', 'maintenance_mode' => $agreement->maintenance_mode ?: 'closed',
                'client_ecommerce_id' => $agreement->client_ecommerce_id === null ? null : (int) $agreement->client_ecommerce_id,
                'agreement_id' => (int) $agreement->id, 'accepted_message_id' => (int) $agreement->confirmation_message_id,
                'window' => ['starts_at' => Carbon::parse($agreement->starts_at_utc, 'UTC')->toIso8601String(),
                    'ends_at' => Carbon::parse($agreement->ends_at_utc, 'UTC')->toIso8601String()],
                'evidence' => $evidence->payload,
                'release' => $claim !== null ? $this->present($claim) : ($reusable === null ? null : $this->present($reusable, 'reusable')),
            ];
            if (count($work) >= 20) {
                break;
            }
        }

        $active = SupportCodexReleaseClaim::where('active_key', 'empresa')->first();
        $blocked = $active !== null && ! in_array((int) $active->agreement_id, array_column($work, 'agreement_id'), true);

        return ['work' => $work, 'blocked_release' => $blocked ? [
            'agreement_id' => (int) $active->agreement_id, 'version_id' => (int) $active->version_id,
            'version' => $active->version, 'reason' => $active->blocked_reason ?? 'release_agreement_changed',
        ] : null];
    }

    public function claim(int $ticket_id, array $data, ReleaseArtifactService $github): array
    {
        ksort($data['develop']);
        $agreement = $this->agreement($ticket_id, (int) $data['agreement_id']);
        $this->assert_target($agreement, $data);
        $evidence = $this->require_current_evidence(SupportTicket::findOrFail($ticket_id), $agreement);
        if (! SupportCodexReleaseClaim::where('agreement_id', $agreement->id)->exists() && $this->reusable($agreement) === null) {
            foreach (array_intersect_key($evidence->payload['commits'], $data['develop']) as $repo => $fix) {
                $this->require_github(function () use ($github, $repo, $fix, $data) {
                    return $github->support_fix_is_ancestor($repo, $fix, $data['develop'][$repo]);
                });
            }
        }

        try {
            return DB::transaction(function () use ($ticket_id, $data) {
                $ticket = SupportTicket::whereKey($ticket_id)->lockForUpdate()->firstOrFail();
                $agreement = $this->agreement($ticket_id, (int) $data['agreement_id'], true);
                $model = $this->version_class($agreement->target ?: 'sistema');
                $this->require_current_evidence($ticket, $agreement);
                $claim = SupportCodexReleaseClaim::where('agreement_id', $agreement->id)->lockForUpdate()->first();
                if ($claim !== null) {
                    $this->assert_claim_matches($claim, $data, 'draft_version_id', 'draft_version');
                    if ($claim->published_at === null && $claim->blocked_at === null) {
                        $this->assert_current_draft((int) $claim->version_id, $claim->draft_code ?? $claim->version, $agreement->target ?: 'sistema');
                    }
                    return ['release' => $this->present($claim)];
                }

                $reusable = $this->reusable($agreement);
                if ($reusable !== null) {
                    $this->assert_claim_matches($reusable, $data, 'draft_version_id', 'draft_version');
                    $claim = $reusable->replicate();
                    $claim->agreement_id = $agreement->id;
                    $claim->blocked_at = null;
                    $claim->blocked_reason = null;
                    $claim->save();
                    return ['release' => $this->present($claim)];
                }
                if (Carbon::parse($agreement->starts_at_utc, 'UTC')->lessThanOrEqualTo(now())) {
                    $this->fail(409, 'release_window_expired', 'La ventana acordada ya empezó.');
                }
                if ($data['draft_version_id'] === null) {
                    if ($model::where('status', 'draft')->latest('id')->lockForUpdate()->first() !== null) {
                        $this->fail(409, 'release_draft_changed', 'Ya existe un borrador distinto del snapshot sin borrador.');
                    }
                    $this->assert_code_available(0, $data['draft_version'], $agreement->target ?: 'sistema');
                    $payload = ['version' => $data['draft_version'], 'status' => 'draft'];
                    if (($agreement->target ?: 'sistema') !== 'tienda') { $payload['is_hotfix'] = VersionNumberComparator::isHotfix($data['draft_version']); }
                    $version = $model::create($payload);
                } else {
                    $version = $this->assert_current_draft((int) $data['draft_version_id'], $data['draft_version'], $agreement->target ?: 'sistema');
                }
                $claim = SupportCodexReleaseClaim::create((SupportMaintenancePolicy::available() ? ['target' => $agreement->target ?: 'sistema'] : []) + [
                    'agreement_id' => $agreement->id, 'mission_fix_evidence_id' => $agreement->mission_fix_evidence_id,
                    'version_id' => $version->id, 'version' => $data['draft_version'],
                    'draft_code' => $data['draft_version_id'] === null ? null : $version->version,
                    'active_key' => ($agreement->target ?: 'sistema') === 'tienda' ? 'tienda' : 'empresa', 'develop' => $data['develop'],
                ]);

                return ['release' => $this->present($claim)];
            });
        } catch (QueryException $error) {
            if ((string) $error->getCode() === '23000' && SupportCodexReleaseClaim::where('active_key', ($agreement->target ?: 'sistema') === 'tienda' ? 'tienda' : 'empresa')->exists()) {
                $this->fail(409, 'release_busy', 'Ya hay un release de empresa en curso.');
            }
            throw $error;
        }
    }

    public function publish(int $ticket_id, array $data, ReleaseArtifactService $github): array
    {
        $agreement = $this->agreement($ticket_id, (int) $data['agreement_id']);
        $this->assert_target($agreement, $data);
        $claim = SupportCodexReleaseClaim::where('agreement_id', $agreement->id)->first();
        $proof_hash = hash('sha256', json_encode($this->sorted($data), JSON_THROW_ON_ERROR));
        $this->assert_claim_matches($claim, $data, 'version_id', 'version');
        if ($claim->published_at !== null) {
            return $this->replay_publication($claim, $proof_hash);
        }
        if ($claim->active_key !== (($agreement->target ?: 'sistema') === 'tienda' ? 'tienda' : 'empresa')) {
            $this->fail(409, 'release_claim_conflict', 'El reclamo de release ya fue liberado.');
        }
        $this->require_current_evidence(SupportTicket::findOrFail($ticket_id), $agreement);
        foreach (SupportMaintenancePolicy::repos($agreement->target ?: 'sistema') as $repo) {
            $this->require_github(function () use ($github, $repo, $data) {
                return $github->verify_support_release($repo, $data['version'], $data['develop'][$repo],
                    $data['trigger_commits'][$repo], $data['tag_commits'][$repo], $data['artifacts'][$repo]);
            });
        }

        return DB::transaction(function () use ($ticket_id, $data, $proof_hash) {
            $ticket = SupportTicket::whereKey($ticket_id)->lockForUpdate()->firstOrFail();
            $agreement = $this->agreement($ticket_id, (int) $data['agreement_id'], true);
                $model = $this->version_class($agreement->target ?: 'sistema');
            $claim = SupportCodexReleaseClaim::where('agreement_id', $agreement->id)->lockForUpdate()->first();
            $this->assert_claim_matches($claim, $data, 'version_id', 'version');
            if ($claim->published_at !== null) {
                return $this->replay_publication($claim, $proof_hash);
            }
            if ($claim->active_key !== (($agreement->target ?: 'sistema') === 'tienda' ? 'tienda' : 'empresa')) {
                $this->fail(409, 'release_claim_conflict', 'El reclamo de release ya fue liberado.');
            }
            $this->require_current_evidence($ticket, $agreement);
            $version = $this->assert_current_draft((int) $data['version_id'], $claim->draft_code ?? $claim->version, $agreement->target ?: 'sistema');
            $this->assert_code_available((int) $version->id, $claim->version, $agreement->target ?: 'sistema');
            $version->version = $claim->version;
            if (($agreement->target ?: 'sistema') !== 'tienda') {
                $version->is_hotfix = VersionNumberComparator::isHotfix($claim->version);
            }
            $version->status = 'published';
            $version->published_at = now();
            $version->title = $data['title'] ?? $version->title;
            $version->description = $data['description'] ?? $version->description;
            $version->save();

            $claim->proof = array_intersect_key($data, array_flip(['trigger_commits', 'tag_commits', 'artifacts']));
            $claim->proof_hash = $proof_hash;
            $claim->active_key = null;
            $claim->published_at = now();
            $claim->blocked_at = null;
            $claim->blocked_reason = null;
            $claim->save();

            return ['release' => $this->present($claim)];
        });
    }

    public function block(int $ticket_id, array $data, ReleaseArtifactService $github): array
    {
        $agreement = $this->agreement($ticket_id, (int) $data['agreement_id']);
        $this->assert_target($agreement, $data);
        $claim = SupportCodexReleaseClaim::where('agreement_id', $agreement->id)->firstOrFail();
        $untouched = ! $data['partial'] && $claim->published_at === null && $claim->active_key === (($agreement->target ?: 'sistema') === 'tienda' ? 'tienda' : 'empresa');
        if ($untouched) {
            try {
                foreach (SupportMaintenancePolicy::repos($agreement->target ?: 'sistema') as $repo) {
                    if (! $github->support_release_untouched($repo, $claim->version, $claim->develop[$repo])) {
                        $untouched = false;
                        break;
                    }
                }
            } catch (\RuntimeException $error) {
                $untouched = false;
            }
        }

        return DB::transaction(function () use ($ticket_id, $data, $untouched) {
            SupportTicket::whereKey($ticket_id)->lockForUpdate()->firstOrFail();
            $agreement = $this->agreement($ticket_id, (int) $data['agreement_id'], true);
                $model = $this->version_class($agreement->target ?: 'sistema');
            $claim = SupportCodexReleaseClaim::where('agreement_id', $agreement->id)->lockForUpdate()->firstOrFail();
            if ($claim->published_at !== null && ($data['reason'] !== 'window_expired'
                || Carbon::parse($agreement->starts_at_utc, 'UTC')->greaterThan(now()))) {
                $this->fail(409, 'release_already_published', 'Solo se puede bloquear la programación vencida de un release publicado.');
            }
            if ($claim->blocked_at === null) {
                $claim->blocked_at = now();
                $claim->blocked_reason = $data['reason'];
            }
            if ($untouched && $claim->published_at === null) {
                $claim->active_key = null;
            }
            $claim->save();

            return ['release' => $this->present($claim), 'active' => $claim->active_key !== null];
        });
    }

    private function current_evidence(SupportTicket $ticket, SupportCodexUpgradeAgreement $agreement): ?SupportCodexMissionFixEvidence
    {
        if (! $ticket->claude_auto_reply || $ticket->source !== 'whatsapp' || $ticket->client_employee_id !== null || ! SupportCodexSettings::manages($ticket)
            || $agreement->accepted_at === null || $agreement->confirmation_message_id === null) {
            return null;
        }
        $case = SupportCodexCase::where('support_ticket_id', $ticket->id)->first();
        $evidence = SupportCodexMissionFixEvidence::current_for($ticket, $case);
        if ($evidence === null || (int) $evidence->id !== (int) $agreement->mission_fix_evidence_id
            || array_intersect(array_keys($evidence->payload['commits']), SupportMaintenancePolicy::repos($agreement->target ?: 'sistema')) === []
            || array_diff(array_keys($evidence->payload['commits']), SupportMaintenancePolicy::repos($agreement->target ?: 'sistema')) !== []) {
            return null;
        }
        $operation = $agreement->operation;
        if ($operation === null || ! in_array($operation->status, ['sent', 'reconciled_sent'], true)) {
            return null;
        }
        $messages = SupportMessage::where('support_ticket_id', $ticket->id)->where('sender_type', 'user');
        SupportMessage::apply_mensaje_real_conditions($messages, 'support_messages');
        $inbound = $messages->latest('id')->first();
        if ($inbound === null || (int) $inbound->id !== (int) $agreement->confirmation_message_id) {
            return null;
        }
        $outbound = SupportMessage::find($operation->message_id);

        return app(SupportCodexUpgradeAgreementService::class)->is_exact_confirmation($agreement, $inbound, $outbound) ? $evidence : null;
    }

    private function require_current_evidence(SupportTicket $ticket, SupportCodexUpgradeAgreement $agreement): SupportCodexMissionFixEvidence
    {
        $evidence = $this->current_evidence($ticket, $agreement);
        if ($evidence === null) {
            $this->fail(409, 'release_agreement_changed', 'El acuerdo o la evidencia del arreglo dejaron de estar vigentes.');
        }

        return $evidence;
    }

    private function agreement(int $ticket_id, int $agreement_id, bool $lock = false): SupportCodexUpgradeAgreement
    {
        $query = SupportCodexUpgradeAgreement::where('support_ticket_id', $ticket_id)->whereKey($agreement_id);
        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function reusable(SupportCodexUpgradeAgreement $agreement): ?SupportCodexReleaseClaim
    {
        $target = $agreement->target ?: 'sistema';
        $table = $target === 'tienda' ? 'ecommerce_versions' : 'versions';
        $query = SupportCodexReleaseClaim::where('mission_fix_evidence_id', $agreement->mission_fix_evidence_id);
        if (SupportMaintenancePolicy::available()) { $query->where('target', $target); }
        return $query->whereNotNull('published_at')->whereNotNull('proof')->whereExists(function ($query) use ($table) {
                $query->selectRaw('1')->from($table)->where($table . '.status', 'published')
                    ->whereColumn($table . '.id', 'support_codex_release_claims.version_id')
                    ->whereColumn($table . '.version', 'support_codex_release_claims.version');
            })->latest('id')->first();
    }

    private function assert_current_draft(int $id, string $code, string $target = 'sistema')
    {
        $model = $this->version_class($target);
        $draft = $model::where('status', 'draft')->latest('id')->lockForUpdate()->first();
        if ($draft === null || (int) $draft->id !== $id || $draft->version !== $code) {
            $this->fail(409, 'release_draft_changed', 'El borrador vigente ya no coincide con el release reclamado.');
        }

        return $draft;
    }

    private function assert_code_available(int $id, string $code, string $target = 'sistema'): void
    {
        $model = $this->version_class($target);
        if ($model::where('version', $code)->where('id', '!=', $id)->exists()) {
            $this->fail(409, 'release_version_taken', 'El código reservado ya corresponde a otra versión.');
        }
    }

    private function assert_claim_matches(?SupportCodexReleaseClaim $claim, array $data, string $id, string $code): void
    {
        $same_id = $claim !== null && ($data[$id] === null
            ? $id === 'draft_version_id' && $claim->draft_code === null : (int) $claim->version_id === (int) $data[$id]);
        if (! $same_id || $claim->version !== $data[$code]
            || $this->sorted($claim->develop) !== $this->sorted($data['develop'])) {
            $this->fail(409, 'release_claim_conflict', 'El release reclamado no coincide con la versión o los commits indicados.');
        }
    }

    private function replay_publication(SupportCodexReleaseClaim $claim, string $proof_hash): array
    {
        if ($claim->proof_hash === null || ! hash_equals($claim->proof_hash, $proof_hash)) {
            $this->fail(409, 'release_proof_conflict', 'El release ya tiene otra evidencia registrada.');
        }

        return ['release' => $this->present($claim)];
    }

    private function require_github(callable $verify): void
    {
        try {
            $verified = $verify();
        } catch (\RuntimeException $error) {
            $this->fail(502, 'release_proof_unavailable', 'No se pudo comprobar la evidencia de GitHub.');
        }
        if (! $verified) {
            $this->fail(422, 'release_proof_invalid', 'La evidencia de GitHub no coincide con el release reclamado.');
        }
    }

    private function sorted(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sorted($item);
            }
        }

        return $value;
    }

    private function present(SupportCodexReleaseClaim $claim, ?string $status = null): array
    {
        return ['target' => $claim->target ?: 'sistema', 'version_id' => (int) $claim->version_id, 'version' => $claim->version,
            'status' => $status ?? ($claim->published_at !== null ? 'published' : ($claim->blocked_at === null ? 'claimed' : 'blocked')),
            'develop' => $claim->develop];
    }

    private function version_class(string $target): string
    {
        return $target === 'tienda' ? EcommerceVersion::class : Version::class;
    }

    private function assert_target(SupportCodexUpgradeAgreement $agreement, array $data): void
    {
        $target = $agreement->target ?: 'sistema';
        abort_unless(($data['target'] ?? 'sistema') === $target, 409, 'El destino no coincide con el acuerdo.');
        if (isset($data['develop'])) {
            $keys = array_keys($data['develop']); sort($keys);
            $expected = SupportMaintenancePolicy::repos($target); sort($expected);
            abort_unless($keys === $expected, 422, 'Los repositorios no corresponden al destino acordado.');
        }
    }

    private function fail(int $status, string $code, string $message): void
    {
        throw new HttpException($status, $code . ': ' . $message);
    }
}
