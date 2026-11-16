<?php

namespace App\Services\Local;

use App\Models\SupportCodexCase;
use App\Models\SupportCodexMission;
use App\Models\SupportTicket;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MisionesDelLocal
{
    public static function pendientes(): Builder
    {
        return DB::table('support_codex_missions as c')
            ->join('support_tickets as t', 't.id', '=', 'c.support_ticket_id')
            ->where(function ($query) { $query->where('t.status', SupportTicket::STATUS_ABIERTO)->orWhere('c.mission_type', 'funcionalidad'); })
            ->whereNull('c.mission_started_at')
            ->whereRaw("TRIM(c.mission) <> ''");
    }

    public static function estado(?string $solicitud, bool $tomada): string
    {
        if ($solicitud === null) {
            return 'pendiente';
        }

        return $tomada ? 'iniciando' : 'solicitada';
    }

    public function solicitar(int $id, ?string $hash, ?int $mission_id = null): array
    {
        $resultado = DB::transaction(function () use ($id, $hash, $mission_id) {
            $ticket = SupportTicket::query()->lockForUpdate()->findOrFail($id);
            abort_if($mission_id === null && $hash === null && SupportCodexMission::where('support_ticket_id', $id)
                ->whereNull('mission_started_at')->count() > 1, 409, 'Elegí la misión que querés iniciar.');
            $caso = $this->bloquear_caso($id, $mission_id, $hash);

            abort_if($caso === null
                || trim((string) $caso->mission) === '', 409, 'El ticket ya no tiene una misión pendiente.');

            abort_if($hash !== null && ! hash_equals(hash('sha256', $caso->mission), $hash),
                409, 'El diagnóstico cambió. Se está actualizando la bandeja.');

            if ($caso->mission_started_at !== null) {
                return ['estado_inicio' => 'iniciada', 'hilo_id' => $caso->mission_thread_id];
            }


            if ($caso->local_mission_request_id === null) {
                $caso->local_mission_request_id = (string) Str::uuid();
                $caso->save();
                $this->reflejar_inicio_legacy($caso);
            }

            return ['estado_inicio' => self::estado($caso->local_mission_request_id, $caso->local_mission_claim_token !== null)];
        });

        Cache::forget(EscenaService::CACHE_KEY);

        return $resultado;
    }

    public function solicitudes(): array
    {
        return self::pendientes()
            ->whereNotNull('c.local_mission_request_id')
            ->orderByRaw('c.local_mission_claim_token IS NOT NULL')
            ->orderBy('c.updated_at')
            ->limit(50)
            ->get(['c.id', 'c.mission_type', 'c.support_ticket_id', 'c.local_mission_request_id', 'c.local_mission_claim_token', 'c.mission'])
            ->map(function ($fila) {
                return [
                    'ticket_id' => (int) $fila->support_ticket_id,
                    'mission_id' => (int) $fila->id, 'mission_type' => $fila->mission_type,
                    'solicitud_id' => $fila->local_mission_request_id,
                    'estado_inicio' => self::estado($fila->local_mission_request_id, $fila->local_mission_claim_token !== null),
                    'mission' => $fila->mission,
                    'mission_sha256' => hash('sha256', $fila->mission),
                    'thread_id' => optional(SupportCodexCase::where('support_ticket_id', $fila->support_ticket_id)->first())->thread_id,
                ];
            })
            ->all();
    }

    public function reclamar(int $id, string $solicitud, string $token, ?int $mission_id = null): bool
    {
        $tomada = DB::transaction(function () use ($id, $solicitud, $token, $mission_id) {
            $ticket = SupportTicket::query()->lockForUpdate()->findOrFail($id);
            $caso = SupportCodexMission::where('support_ticket_id', $id)->where('local_mission_request_id', $solicitud)->when($mission_id !== null, function ($q) use ($mission_id) { $q->whereKey($mission_id); })->lockForUpdate()->first();

            if ($caso === null
                || $caso->mission_started_at !== null
                || $caso->local_mission_request_id !== $solicitud
                || trim((string) $caso->mission) === '') {
                return false;
            }

            if ($caso->local_mission_claim_token !== null) {
                return $caso->local_mission_claim_token === $token;
            }

            // Un resultado incierto se concilia por el ID en Codex. Otro monitor no repite la apertura.
            $caso->local_mission_claim_token = $token;
            $caso->save();
            $this->reflejar_inicio_legacy($caso);

            return true;
        });

        if ($tomada) {
            Cache::forget(EscenaService::CACHE_KEY);
        }

        return $tomada;
    }

    private function reflejar_inicio_legacy(SupportCodexMission $mission): void
    {
        SupportCodexCase::where('support_ticket_id', $mission->support_ticket_id)->where('mission', $mission->mission)
            ->update(['local_mission_request_id' => $mission->local_mission_request_id, 'local_mission_claim_token' => $mission->local_mission_claim_token]);
    }

    private function bloquear_caso(int $id, ?int $mission_id = null, ?string $hash = null): ?SupportCodexMission
    {
        return SupportCodexMission::where('support_ticket_id', $id)->when($mission_id !== null, function ($q) use ($mission_id) { $q->whereKey($mission_id); })->when($hash !== null, function ($q) use ($hash) { $q->where('mission_hash', $hash); })->latest('id')->lockForUpdate()->first();
    }
}
