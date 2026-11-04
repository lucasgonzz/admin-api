<?php

namespace App\Services\Local;

use App\Models\SupportCodexCase;
use App\Models\SupportTicket;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MisionesDelLocal
{
    public static function pendientes(): Builder
    {
        return DB::table('support_codex_cases as c')
            ->join('support_tickets as t', 't.id', '=', 'c.support_ticket_id')
            ->where('t.status', SupportTicket::STATUS_ABIERTO)
            ->where('c.state', 'mission_pending')
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

    public function solicitar(int $id, ?string $hash): array
    {
        $resultado = DB::transaction(function () use ($id, $hash) {
            $ticket = SupportTicket::query()->lockForUpdate()->findOrFail($id);
            $caso = $this->bloquear_caso($id);

            abort_if($ticket->status !== SupportTicket::STATUS_ABIERTO || $caso === null
                || trim((string) $caso->mission) === '', 409, 'El ticket ya no tiene una misión pendiente.');

            abort_if($hash !== null && ! hash_equals(hash('sha256', $caso->mission), $hash),
                409, 'El diagnóstico cambió. Se está actualizando la bandeja.');

            if ($caso->mission_started_at !== null) {
                return ['estado_inicio' => 'iniciada', 'hilo_id' => $caso->mission_thread_id];
            }

            abort_if($caso->state !== 'mission_pending', 409, 'La misión ya no está pendiente de inicio.');

            if ($caso->local_mission_request_id === null) {
                $caso->local_mission_request_id = (string) Str::uuid();
                $caso->save();
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
            ->get(['c.support_ticket_id', 'c.local_mission_request_id', 'c.local_mission_claim_token', 'c.mission', 'c.thread_id'])
            ->map(function ($fila) {
                return [
                    'ticket_id' => (int) $fila->support_ticket_id,
                    'solicitud_id' => $fila->local_mission_request_id,
                    'estado_inicio' => self::estado($fila->local_mission_request_id, $fila->local_mission_claim_token !== null),
                    'mission' => $fila->mission,
                    'mission_sha256' => hash('sha256', $fila->mission),
                    'thread_id' => $fila->thread_id,
                ];
            })
            ->all();
    }

    public function reclamar(int $id, string $solicitud, string $token): bool
    {
        $tomada = DB::transaction(function () use ($id, $solicitud, $token) {
            $ticket = SupportTicket::query()->lockForUpdate()->findOrFail($id);
            $caso = $this->bloquear_caso($id);

            if ($ticket->status !== SupportTicket::STATUS_ABIERTO || $caso === null
                || $caso->state !== 'mission_pending' || $caso->mission_started_at !== null
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

            return true;
        });

        if ($tomada) {
            Cache::forget(EscenaService::CACHE_KEY);
        }

        return $tomada;
    }

    private function bloquear_caso(int $id): ?SupportCodexCase
    {
        return SupportCodexCase::query()->where('support_ticket_id', $id)->lockForUpdate()->first();
    }
}
