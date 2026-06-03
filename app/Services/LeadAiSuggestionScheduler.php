<?php

namespace App\Services;

use App\Jobs\GenerateLeadAiSuggestionJob;
use App\Models\Lead;
use App\Models\LeadMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Programa la sugerencia de Claude tras mensajes entrantes del lead, con demora configurable y reinicio (debounce).
 *
 * Cada nuevo inbound incrementa un token en caché; solo el job cuyo token coincide ejecuta la llamada a Claude.
 * Si llegan más mensajes antes de que termine la espera o la API, el token queda obsoleto y el resultado se descarta.
 */
class LeadAiSuggestionScheduler
{
    /** Prefijo de clave de caché por lead. */
    private const CACHE_KEY_PREFIX = 'lead_ai_suggestion_schedule_token:';

    /** TTL de la clave de token (cubre demoras largas y cola lenta). */
    private const CACHE_TTL_SECONDS = 7200;

    /**
     * Reinicia la espera y programa un job diferido para pedir sugerencia a Claude.
     *
     * No hace nada en el primer mensaje entrante del lead (onboarding sin IA).
     *
     * @param int $lead_id ID del lead que recibió el inbound.
     *
     * @return void
     */
    public function schedule_after_lead_inbound(int $lead_id): void
    {
        $lead_inbound_count = LeadMessage::query()
            ->where('lead_id', $lead_id)
            ->where('sender', 'lead')
            ->count();

        if ($lead_inbound_count <= 1) {
            return;
        }

        $delay_seconds = LeadWhatsappOnboardingSettings::get_ai_suggestion_delay_seconds();
        $this->clear_stale_pending_suggestions($lead_id);
        $schedule_token = $this->bump_schedule_token($lead_id);

        GenerateLeadAiSuggestionJob::dispatch($lead_id, $schedule_token)
            ->delay(now()->addSeconds($delay_seconds));

        Log::channel('daily')->debug('LeadAiSuggestionScheduler: sugerencia IA reprogramada.', [
            'lead_id'         => $lead_id,
            'delay_seconds'   => $delay_seconds,
            'schedule_token'  => $schedule_token,
            'inbound_count'   => $lead_inbound_count,
        ]);
    }

    /**
     * Indica si el token del job sigue siendo el último programado para ese lead.
     *
     * @param int $lead_id
     * @param int $schedule_token Token capturado al encolar el job.
     *
     * @return bool
     */
    public function is_schedule_token_current(int $lead_id, int $schedule_token): bool
    {
        $current = Cache::get($this->cache_key($lead_id));

        return (int) $current === $schedule_token;
    }

    /**
     * Descarta una sugerencia obsoleta y sincroniza flags del lead.
     *
     * @param LeadMessage $message Mensaje `sugerido` creado por un job ya invalidado.
     *
     * @return void
     */
    public function discard_obsolete_suggestion(LeadMessage $message): void
    {
        $lead_id = (int) $message->lead_id;
        $message_id = (int) $message->id;

        (new LeadAiSuggestionAutoSendScheduler())->cancel_for_message($message_id);

        $message->delete();

        $lead = Lead::query()->find($lead_id);
        if ($lead !== null) {
            $lead->sync_suggestion_flags();
        }

        LeadBroadcastService::emit_conversation_updated($lead_id, $message_id);

        Log::channel('daily')->info('LeadAiSuggestionScheduler: sugerencia obsoleta descartada (mensajes nuevos del lead).', [
            'lead_id'    => $lead_id,
            'message_id' => $message_id,
        ]);
    }

    /**
     * Elimina sugerencias IA pendientes de aprobación cuando el lead sigue escribiendo.
     *
     * @param int $lead_id
     *
     * @return void
     */
    private function clear_stale_pending_suggestions(int $lead_id): void
    {
        $pending_ids = LeadMessage::query()
            ->where('lead_id', $lead_id)
            ->where('status', 'sugerido')
            ->where('is_followup', false)
            ->pluck('id');

        if ($pending_ids->isEmpty()) {
            return;
        }

        $auto_send_scheduler = new LeadAiSuggestionAutoSendScheduler();
        foreach ($pending_ids as $pending_id) {
            $auto_send_scheduler->cancel_for_message((int) $pending_id);
        }

        LeadMessage::query()->whereIn('id', $pending_ids)->delete();

        $lead = Lead::query()->find($lead_id);
        if ($lead !== null) {
            $lead->sync_suggestion_flags();
        }

        LeadBroadcastService::emit_conversation_updated($lead_id);
    }

    /**
     * Incrementa el token de programación del lead y lo persiste en caché.
     *
     * @param int $lead_id
     *
     * @return int Token nuevo asignado al job.
     */
    private function bump_schedule_token(int $lead_id): int
    {
        $cache_key = $this->cache_key($lead_id);
        $current = (int) Cache::get($cache_key, 0);
        $next = $current + 1;

        Cache::put($cache_key, $next, self::CACHE_TTL_SECONDS);

        return $next;
    }

    /**
     * Arma la clave de caché para el token de debounce del lead.
     *
     * @param int $lead_id
     *
     * @return string
     */
    private function cache_key(int $lead_id): string
    {
        return self::CACHE_KEY_PREFIX.$lead_id;
    }
}
