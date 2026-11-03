<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Evidencia de una corrección integrada, ligada al diagnóstico y al hilo vigentes. */
class SupportCodexMissionFixEvidence extends Model
{
    protected $table = 'support_codex_mission_fix_evidence';
    protected $guarded = [];
    protected $casts = ['payload' => 'array'];

    public static function current_for(SupportTicket $ticket, ?SupportCodexCase $case): ?self
    {
        if ($ticket->status !== 'open' || $case === null || ($case->mission_lifecycle()['status'] ?? null) !== 'started') {
            return null;
        }

        return self::where('support_ticket_id', $ticket->id)
            ->where('mission_thread_id', $case->mission_thread_id)
            ->where('mission_hash', hash('sha256', (string) $case->mission))->first();
    }
}
