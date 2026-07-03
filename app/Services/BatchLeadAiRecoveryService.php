<?php

namespace App\Services;

use App\Jobs\GenerateLeadAiSuggestionJob;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadConversationAiState;
use App\Services\LeadFollowupService;
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

    /**
     * Reintenta seguimientos automáticos por plantilla que fallaron al enviarse (whatsapp_message_id
     * null pero followup_template_id seteado — ver LeadFollowupService::send_followup_via_template(),
     * prompt 245). Es la contraparte de dispatch_unanswered_leads() para el mismo botón de recovery:
     * mientras esa reintenta respuestas de Claude sin job, esta reintenta seguimientos que el scheduler
     * ya intentó pero nunca llegaron al lead por un error de Kapso/Meta.
     *
     * No hace falta esperar al próximo ciclo del cron (leads:check-followups): al no consumir el cupo de
     * max_followups (fix del prompt 245), el cron también reintentaría, pero solo cuando vuelva a
     * cumplirse horas_espera de la regla — este método lo hace de inmediato, a pedido.
     *
     * @return array{retried:int, skipped_followups:int}
     */
    public function retry_failed_followups(): array
    {
        /* Solo cuenta como "fallido reintentable" un LeadMessage que vino de send_followup_via_template
           (followup_template_id no null) y nunca obtuvo whatsapp_message_id. Los LeadMessage de
           notify_closer_for_followup() nunca tienen followup_template_id y no entran acá. */
        $failed_messages = LeadMessage::query()
            ->where('is_followup', true)
            ->whereNotNull('followup_template_id')
            ->whereNull('whatsapp_message_id')
            ->where('status', '!=', 'rechazado')
            ->where('created_at', '<=', now()->subMinutes(self::BATCH_MIN_ELAPSED_MINUTES))
            ->orderBy('id')
            ->get();

        $retried = 0;
        $skipped = 0;

        /* Un lead puede tener más de un intento fallido acumulado (varios clicks de recovery, o varios
           ciclos de cron mientras Kapso estuvo caído): solo reintentamos el más reciente por lead. */
        $latest_per_lead = $failed_messages->groupBy('lead_id')->map(function ($group) {
            return $group->sortByDesc('id')->first();
        });

        foreach ($latest_per_lead as $lead_id => $failed_message) {
            $lead = Lead::query()->find($lead_id);

            /* Lead borrado, o ya cerrado/pausado desde el fallo: no tiene sentido reintentar. */
            if (! $lead || in_array($lead->status, ['cerrado_ganado', 'cerrado_perdido', 'en_pausa'], true)) {
                $skipped++;
                continue;
            }

            /* Si ya hay CUALQUIER mensaje más nuevo que el fallido (otro seguimiento que sí salió, una
               respuesta del lead, un mensaje manual del setter), la conversación ya siguió después del
               fallo — no reintentar por encima para no mandar una plantilla desactualizada. */
            $already_resolved = LeadMessage::query()
                ->where('lead_id', $lead_id)
                ->where('id', '>', $failed_message->id)
                ->exists();

            if ($already_resolved) {
                $skipped++;
                continue;
            }

            $template = FollowupTemplate::query()->find($failed_message->followup_template_id);
            if (! $template || ! $template->activa) {
                $skipped++;
                continue;
            }

            /* followup_number no se usa dentro de send_followup_via_template(); 0 es válido para un
               reintento manual (ver docblock del método en LeadFollowupService). */
            app(LeadFollowupService::class)->send_followup_via_template($lead, $template, 0);

            $retried++;
        }

        Log::channel('daily')->info('BatchLeadAiRecoveryService: reintento de seguimientos fallidos completado.', [
            'retried' => $retried,
            'skipped' => $skipped,
        ]);

        return [
            'retried'           => $retried,
            'skipped_followups' => $skipped,
        ];
    }
}
