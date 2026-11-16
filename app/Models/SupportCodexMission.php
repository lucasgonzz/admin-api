<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cada encargo conserva su identidad aunque el ticket continúe con otro tema. */
class SupportCodexMission extends Model
{
    protected $guarded = [];
    protected $casts = ['mission_started_at' => 'datetime', 'completed_at' => 'datetime', 'installed_at' => 'datetime', 'delivery_verified_at' => 'datetime'];

    public function summary(bool $private = false): array
    {
        $result = ['id' => (int) $this->id, 'mission_type' => $this->mission_type,
            'status' => $this->completed_at !== null ? 'completed' : ($this->mission_started_at !== null ? 'started' : 'pending'),
            'thread_id' => $this->mission_thread_id,
            'started_at' => $this->mission_started_at === null ? null : $this->mission_started_at->toIso8601String(),
            'completed_at' => $this->completed_at === null ? null : $this->completed_at->toIso8601String(),
            'delivery_status' => $this->installed_at === null ? 'pending' : ($this->delivery_verified_at === null ? 'installed_pending_verification' : 'verified'),
            'installed_schedule_id' => $this->installed_schedule_id === null ? null : (int) $this->installed_schedule_id,
            'installed_at' => $this->installed_at === null ? null : $this->installed_at->toIso8601String()];
        if ($private) {
            $result += ['mission' => $this->mission, 'mission_hash' => $this->mission_hash,
                'scope_confirmed_message_id' => $this->scope_confirmed_message_id];
        }
        return $result;
    }

    public static function capture(SupportCodexCase $case, array $attributes = []): ?self
    {
        if (trim((string) $case->mission) === '') { return null; }
        $mission = self::firstOrCreate(['support_ticket_id' => $case->support_ticket_id,
            'mission_hash' => hash('sha256', (string) $case->mission)], [
            'mission' => $case->mission, 'mission_type' => $case->close_on_mission_start ? 'funcionalidad' : 'bug']);
        $mission->fill($attributes + ['mission_thread_id' => $mission->mission_thread_id ?: $case->mission_thread_id,
            'mission_started_at' => $mission->mission_started_at ?: $case->mission_started_at,
            'local_mission_request_id' => $mission->local_mission_request_id ?: $case->local_mission_request_id,
            'local_mission_claim_token' => $mission->local_mission_claim_token ?: $case->local_mission_claim_token])->save();
        return $mission;
    }

    public static function resolve(int $ticket, array $input): ?self
    {
        $query = self::where('support_ticket_id', $ticket);
        if (isset($input['mission_id'])) { $query->whereKey($input['mission_id']); }
        if (isset($input['mission_hash'])) { $query->where('mission_hash', $input['mission_hash']); }
        if (isset($input['mission_thread_id'])) {
            $query->where(function ($q) use ($input) { $q->whereNull('mission_thread_id')->orWhere('mission_thread_id', $input['mission_thread_id']); });
        }
        return $query->latest('id')->first();
    }

    /** Proyección privada, nunca se guarda sobre el caso de atención compartido. */
    public function project(SupportCodexCase $case): SupportCodexCase
    {
        $copy = clone $case;
        $copy->mission = $this->mission;
        $copy->mission_thread_id = $this->mission_thread_id;
        $copy->mission_started_at = $this->mission_started_at;
        $copy->close_on_mission_start = $this->mission_type === 'funcionalidad';
        return $copy;
    }

    public static function evidence_for_agreement(SupportCodexUpgradeAgreement $agreement): ?SupportCodexMissionFixEvidence
    {
        $evidence = SupportCodexMissionFixEvidence::where('support_ticket_id', $agreement->support_ticket_id)->find($agreement->mission_fix_evidence_id);
        if ($evidence === null) { return null; }
        $mission = self::resolve((int) $agreement->support_ticket_id, ['mission_thread_id' => $evidence->mission_thread_id, 'mission_hash' => $evidence->mission_hash]);
        return $mission !== null && $mission->mission_started_at !== null ? $evidence : null;
    }
}
