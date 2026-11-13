<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un avance no es una resolución: se conserva separado del diagnóstico y de su entrega final. */
class SupportCodexMissionProgress extends Model
{
    protected $table = 'support_codex_mission_progress';
    protected $guarded = [];
    protected $casts = ['payload' => 'array', 'announced_at' => 'datetime', 'dismissed_at' => 'datetime'];

    public static function current_for(SupportTicket $ticket, ?SupportCodexCase $case): ?self
    {
        if ($ticket->status !== 'open' || $case === null || ($case->mission_lifecycle()['status'] ?? null) !== 'started') {
            return null;
        }
        $query = self::where('support_ticket_id', $ticket->id)->where('mission_thread_id', $case->mission_thread_id)
            ->where('mission_hash', hash('sha256', (string) $case->mission));
        // La causa puede llegar antes de que Martín avise el inicio. No debe borrar ese compromiso.
        $start = (clone $query)->whereNull('announced_at')->whereNull('dismissed_at')
            ->where('payload->tipo', 'inicio_mision')->oldest('id')->first();
        return $start ?? $query->orderByDesc('id')->first();
    }

    /** Se llama solamente después de una entrega confirmada, incluso al conciliarla manualmente. */
    public static function announced_by(SupportCodexOperation $operation): void
    {
        if (in_array($operation->status, ['sent', 'reconciled_sent'], true)) {
            self::where('support_codex_operation_id', $operation->id)->whereNull('announced_at')->update(['announced_at' => now()]);
        }
    }
}
