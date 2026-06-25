<?php

namespace App\Services;

use App\Jobs\GenerateLeadAiSuggestionJob;
use App\Models\Lead;
use Illuminate\Support\Facades\Cache;

/**
 * Servicio de recovery masivo de leads sin respuesta.
 *
 * Encola GenerateLeadAiSuggestionJob para todos los leads elegibles:
 * leads con claude_auto_reply activo, mensajes entrantes suficientes,
 * sin sugerencia pendiente y cuyo último mensaje entrante sea de hace
 * más de BATCH_MIN_ELAPSED_MINUTES minutos.
 *
 * Útil para recuperar leads que quedaron sin job por errores del servidor.
 */
class BatchLeadAiRecoveryService
{
    /**
     * Minutos mínimos transcurridos desde el último mensaje del lead
     * para considerarlo elegible para recovery (evita pisar debounces activos).
     */
    private const BATCH_MIN_ELAPSED_MINUTES = 3;

    /**
     * Segundos de separación entre cada job encolado para no saturar la API de Claude.
     */
    private const STAGGER_SECONDS = 4;

    /**
     * Prefijo de clave de caché para el token de scheduling por lead.
     * Debe coincidir con LeadAiSuggestionScheduler::CACHE_KEY_PREFIX.
     */
    private const CACHE_KEY_PREFIX = 'lead_ai_suggestion_schedule_token:';

    /**
     * Encola GenerateLeadAiSuggestionJob para todos los leads sin respuesta elegibles.
     *
     * Criterios de elegibilidad:
     *  1. claude_auto_reply = true
     *  2. Al menos 2 mensajes entrantes del lead (excluyendo el primero de onboarding)
     *  3. Sin sugerencia pendiente de revisión (no followup)
     *  4. Último mensaje entrante hace más de BATCH_MIN_ELAPSED_MINUTES minutos
     *  5. Tiene mensajes sin responder según LeadConversationAiState::has_unanswered_lead_messages()
     *
     * @return array{dispatched: int, skipped: int}
     */
    public function dispatch_unanswered_leads(): array
    {
        /* Umbral de tiempo: el último mensaje debe ser anterior a este instante. */
        $cutoff = now()->subMinutes(self::BATCH_MIN_ELAPSED_MINUTES);

        /* Cargamos todos los leads con auto reply activo y sus mensajes en una sola query. */
        $leads = Lead::query()
            ->with('messages')
            ->where('claude_auto_reply', true)
            ->get();

        /* Contadores de resultado. */
        $dispatched = 0;
        $skipped    = 0;

        /* Delay acumulado para escalonar los jobs en la cola. */
        $delay_seconds = 0;

        foreach ($leads as $lead) {

            /* Condición 2: al menos 2 mensajes entrantes reales del lead. */
            $inbound_count = LeadConversationAiState::count_lead_inbound_messages($lead->id);
            if ($inbound_count <= 1) {
                $skipped++;
                continue;
            }

            /* Condición 3: sin sugerencia pendiente de revisión (no followup). */
            if (LeadConversationAiState::has_pending_non_followup_suggestion($lead)) {
                $skipped++;
                continue;
            }

            /* Condición 4: obtener el último mensaje entrante del lead. */
            $last_lead_message = $lead->messages
                ->filter(function ($m) {
                    /* Solo mensajes enviados del lead que no sean reacciones. */
                    return (string) $m->sender === 'lead'
                        && (string) $m->status === 'enviado'
                        && ((string) ($m->kind ?? '') !== 'reaction');
                })
                ->sortByDesc('id')
                ->first();

            /* Si no hay mensaje entrante relevante, omitir. */
            if ($last_lead_message === null) {
                $skipped++;
                continue;
            }

            /* Verificar que el último mensaje sea más antiguo que el cutoff. */
            if ($last_lead_message->created_at > $cutoff) {
                /* Mensaje muy reciente: puede haber un job legítimo en tránsito. */
                $skipped++;
                continue;
            }

            /* Condición 5: verificar que haya mensajes sin responder. */
            if (!LeadConversationAiState::has_unanswered_lead_messages($lead)) {
                $skipped++;
                continue;
            }

            /* Generar nuevo token de scheduling para este lead. */
            $schedule_token = $this->bump_schedule_token($lead->id);

            /* Encolar el job con el stagger correspondiente. */
            GenerateLeadAiSuggestionJob::dispatch($lead->id, $schedule_token)
                ->delay(now()->addSeconds($delay_seconds));

            $delay_seconds += self::STAGGER_SECONDS;
            $dispatched++;
        }

        return [
            'dispatched' => $dispatched,
            'skipped'    => $skipped,
        ];
    }

    /**
     * Incrementa el token de scheduling del lead en la caché para invalidar jobs anteriores.
     *
     * @param int $lead_id ID del lead.
     *
     * @return int Nuevo token asignado.
     */
    private function bump_schedule_token(int $lead_id): int
    {
        /* Construir clave de caché idéntica a la del scheduler principal. */
        $cache_key = self::CACHE_KEY_PREFIX . $lead_id;

        /* Incrementar el token; si no existe, parte de 0. */
        $current = (int) Cache::get($cache_key, 0);
        $next    = $current + 1;

        /* TTL de 2 horas, igual que el scheduler. */
        Cache::put($cache_key, $next, 7200);

        return $next;
    }
}
