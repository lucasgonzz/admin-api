<?php

namespace App\Services;

use App\Events\SupportAiSuggestionScheduled;
use App\Jobs\SendSupportAiSuggestion;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Programa la sugerencia de Claude tras mensajes entrantes del cliente en soporte WhatsApp.
 *
 * Cada nuevo inbound incrementa un token en caché; solo el job cuyo token coincide ejecuta la llamada a Claude.
 * Si llegan más mensajes antes de que termine la espera o la API, el token queda obsoleto y el resultado se descarta.
 */
class SupportAiSuggestionScheduler
{
    /** Prefijo de clave de caché por ticket de soporte. */
    private const CACHE_KEY_PREFIX = 'support_ai_suggestion_schedule_token:';

    /** TTL de la clave de token (cubre demoras largas y cola lenta). */
    private const CACHE_TTL_SECONDS = 7200;

    /**
     * Reinicia la espera y programa un job diferido para pedir sugerencia a Claude.
     *
     * @param int $ticket_id ID del ticket que recibió el inbound del cliente.
     *
     * @return void
     */
    public function schedule_after_client_inbound(int $ticket_id): void
    {
        // Demora configurable antes de contactar a Claude (debounce tras el último mensaje).
        $delay_seconds = SupportAiSettings::get_suggestion_delay_seconds();

        $this->clear_stale_pending_suggestion($ticket_id);
        $schedule_token = $this->bump_schedule_token($ticket_id);

        $consult_at = now()->addSeconds($delay_seconds);

        SendSupportAiSuggestion::dispatch($ticket_id, $schedule_token)
            ->delay($consult_at);

        event(new SupportAiSuggestionScheduled(
            $ticket_id,
            $delay_seconds,
            $consult_at->toIso8601String(),
            $schedule_token
        ));

        Log::channel('daily')->debug('SupportAiSuggestionScheduler: sugerencia IA reprogramada.', [
            'ticket_id'       => $ticket_id,
            'delay_seconds'   => $delay_seconds,
            'schedule_token'  => $schedule_token,
        ]);
    }

    /**
     * Indica si el token del job sigue siendo el último programado para ese ticket.
     *
     * @param int $ticket_id
     * @param int $schedule_token Token capturado al encolar el job.
     *
     * @return bool
     */
    public function is_schedule_token_current(int $ticket_id, int $schedule_token): bool
    {
        $current = Cache::get($this->cache_key($ticket_id));

        return (int) $current === $schedule_token;
    }

    /**
     * Limpia sugerencia pendiente de envío automático cuando el cliente sigue escribiendo.
     *
     * @param int $ticket_id
     *
     * @return void
     */
    private function clear_stale_pending_suggestion(int $ticket_id): void
    {
        $ticket = SupportTicket::query()->find($ticket_id);
        if ($ticket === null) {
            return;
        }

        $pending_text = trim((string) ($ticket->ai_pending_suggestion ?? ''));
        $has_drafts = SupportMessage::query()
            ->where('support_ticket_id', $ticket_id)
            ->where('is_ai_suggestion_draft', true)
            ->exists();

        if ($pending_text === '' && ! $has_drafts) {
            return;
        }

        (new SupportAiSuggestionDraftService())->clear_ticket_pending_state($ticket);

        Log::channel('daily')->info('SupportAiSuggestionScheduler: sugerencia pendiente descartada (nuevo mensaje del cliente).', [
            'ticket_id' => $ticket_id,
        ]);
    }

    /**
     * Incrementa el token de programación del ticket y lo persiste en caché.
     *
     * @param int $ticket_id
     *
     * @return int Token nuevo asignado al job.
     */
    private function bump_schedule_token(int $ticket_id): int
    {
        $cache_key = $this->cache_key($ticket_id);
        $current = (int) Cache::get($cache_key, 0);
        $next = $current + 1;

        Cache::put($cache_key, $next, self::CACHE_TTL_SECONDS);

        return $next;
    }

    /**
     * Arma la clave de caché para el token de debounce del ticket.
     *
     * @param int $ticket_id
     *
     * @return string
     */
    private function cache_key(int $ticket_id): string
    {
        return self::CACHE_KEY_PREFIX.$ticket_id;
    }
}
