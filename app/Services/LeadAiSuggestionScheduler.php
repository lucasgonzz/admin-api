<?php

namespace App\Services;

use App\Jobs\GenerateLeadAiSuggestionJob;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadConversationAiState;
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

        $lead = Lead::query()->with('messages')->find($lead_id);
        if ($lead === null) {
            return;
        }

        // El setter puede desactivar Claude por lead; no programar sugerencia automática.
        if (! $lead->claude_auto_reply) {
            Log::channel('daily')->debug('LeadAiSuggestionScheduler: omitido (claude_auto_reply desactivado).', [
                'lead_id' => $lead_id,
            ]);

            return;
        }

        if (! LeadConversationAiState::has_unanswered_lead_messages($lead)) {
            Log::channel('daily')->debug('LeadAiSuggestionScheduler: omitido (sin mensajes del lead sin responder).', [
                'lead_id' => $lead_id,
            ]);

            return;
        }

        // Nuevo inbound del lead: descartar sugerencias pendientes obsoletas y reprogramar Claude.
        // (Mismo criterio que SupportAiSuggestionScheduler: el lead siguió escribiendo.)
        $delay_seconds = LeadWhatsappOnboardingSettings::get_ai_suggestion_delay_seconds();
        $this->clear_stale_pending_suggestions($lead_id);
        $schedule_token = $this->bump_schedule_token($lead_id);

        $this->dispatch_generate_job($lead_id, $schedule_token, $delay_seconds);

        Log::channel('daily')->debug('LeadAiSuggestionScheduler: sugerencia IA reprogramada.', [
            'lead_id'         => $lead_id,
            'delay_seconds'   => $delay_seconds,
            'schedule_token'  => $schedule_token,
            'inbound_count'   => $lead_inbound_count,
        ]);
    }

    /**
     * Invalida el job automático de sugerencia IA pendiente (p. ej. pedido manual del setter).
     *
     * No encola un reemplazo: el setter o el siguiente inbound volverán a programar si corresponde.
     *
     * @param int $lead_id
     *
     * @return void
     */
    public function cancel_scheduled_suggestion(int $lead_id): void
    {
        $this->bump_schedule_token($lead_id);

        Log::channel('daily')->debug('LeadAiSuggestionScheduler: sugerencia automática cancelada.', [
            'lead_id' => $lead_id,
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
     * Cancela también jobs de envío automático asociados y notifica a la conversación abierta.
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

        Log::channel('daily')->info('LeadAiSuggestionScheduler: sugerencias pendientes descartadas (nuevo mensaje del lead).', [
            'lead_id'      => $lead_id,
            'message_ids'  => $pending_ids->all(),
        ]);

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
     * Encola el job de sugerencia IA con demora en cola o ejecución inmediata tras el webhook.
     *
     * Con demora 0 usa conexión sync + afterResponse para no depender de `queue:work`
     * (QUEUE_CONNECTION=database).
     *
     * @param int $lead_id
     * @param int $schedule_token
     * @param int $delay_seconds
     *
     * @return void
     */
    private function dispatch_generate_job(int $lead_id, int $schedule_token, int $delay_seconds): void
    {
        $pending_dispatch = GenerateLeadAiSuggestionJob::dispatch($lead_id, $schedule_token);

        if ($delay_seconds > 0) {
            $pending_dispatch->delay(now()->addSeconds($delay_seconds));

            return;
        }

        $pending_dispatch->onConnection('sync')->afterResponse();
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
