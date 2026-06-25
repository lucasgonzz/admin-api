<?php

namespace App\Services;

use App\Jobs\GenerateLeadAiSuggestionJob;
use App\Models\Lead;
use App\Services\LeadConversationAiState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Recovery masivo de leads sin respuesta.
 *
 * Despacha GenerateLeadAiSuggestionJob en modo sync+afterResponse para cada
 * lead elegible, sin depender de queue:work.
 */
class BatchLeadAiRecoveryService
{
    /** Minutos mínimos desde el último mensaje del lead para considerarlo elegible. */
    const BATCH_MIN_ELAPSED_MINUTES = 3;

    /** Prefijo de clave de caché del token de debounce (debe coincidir con LeadAiSuggestionScheduler). */
    const CACHE_KEY_PREFIX = 'lead_ai_suggestion_schedule_token:';

    /** TTL del token en caché. */
    const CACHE_TTL_SECONDS = 7200;

    /**
     * Identifica leads sin respuesta elegibles y despacha un job de sugerencia por cada uno.
     *
     * Criterios de elegibilidad:
     *  1. claude_auto_reply = true
     *  2. Al menos 2 mensajes entrantes del lead (el primero es onboarding sin IA)
     *  3. Sin sugerencia pendiente de revisión (no followup)
     *  4. Último mensaje entrante hace más de BATCH_MIN_ELAPSED_MINUTES minutos
     *  5. Tiene mensajes sin responder según LeadConversationAiState
     *
     * @return array
     */
    public function dispatch_unanswered_leads()
    {
        $cutoff = now()->subMinutes(self::BATCH_MIN_ELAPSED_MINUTES);

        $leads = Lead::query()
            ->with('messages')
            ->where('claude_auto_reply', true)
            ->get();

        $dispatched = 0;
        $skipped    = 0;

        foreach ($leads as $lead) {

            /* Condición 2: al menos 2 mensajes entrantes reales del lead. */
            $inbound_count = LeadConversationAiState::count_lead_inbound_messages($lead->id);
            if ($inbound_count <= 1) {
                $skipped++;
                continue;
            }

            /* Condición 3: sin sugerencia pendiente de revisión. */
            if (LeadConversationAiState::has_pending_non_followup_suggestion($lead)) {
                $skipped++;
                continue;
            }

            /* Condición 4: último mensaje entrante del lead. */
            $last_lead_message = $lead->messages
                ->filter(function ($m) {
                    $kind = $m->kind !== null ? (string) $m->kind : '';
                    return (string) $m->sender === 'lead'
                        && (string) $m->status === 'enviado'
                        && $kind !== 'reaction';
                })
                ->sortByDesc('id')
                ->first();

            if ($last_lead_message === null) {
                $skipped++;
                continue;
            }

            /* Mensaje muy reciente: puede haber un job legítimo en tránsito. */
            if ($last_lead_message->created_at > $cutoff) {
                $skipped++;
                continue;
            }

            /* Condición 5: tiene mensajes sin responder. */
            if (! LeadConversationAiState::has_unanswered_lead_messages($lead)) {
                $skipped++;
                continue;
            }

            /* Bumpeamos token para invalidar cualquier job previo colgado. */
            $schedule_token = $this->bump_schedule_token((int) $lead->id);

            /* Despacho inmediato post-respuesta HTTP: no requiere queue:work. */
            GenerateLeadAiSuggestionJob::dispatch((int) $lead->id, $schedule_token)
                ->onConnection('sync')
                ->afterResponse();

            Log::channel('daily')->info('BatchLeadAiRecoveryService: job despachado.', [
                'lead_id'        => $lead->id,
                'schedule_token' => $schedule_token,
            ]);

            $dispatched++;
        }

        Log::channel('daily')->info('BatchLeadAiRecoveryService: recovery completado.', [
            'dispatched' => $dispatched,
            'skipped'    => $skipped,
        ]);

        return [
            'dispatched' => $dispatched,
            'skipped'    => $skipped,
        ];
    }

    /**
     * Incrementa el token de debounce del lead en caché.
     *
     * @param int $lead_id
     *
     * @return int Token nuevo.
     */
    private function bump_schedule_token($lead_id)
    {
        $cache_key = self::CACHE_KEY_PREFIX . $lead_id;
        $current   = (int) Cache::get($cache_key, 0);
        $next      = $current + 1;

        Cache::put($cache_key, $next, self::CACHE_TTL_SECONDS);

        return $next;
    }
}
