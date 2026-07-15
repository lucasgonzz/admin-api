<?php

namespace App\Services;

use App\Jobs\AutoSendLeadAiSuggestionJob;
use App\Models\LeadMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Programa el envío automático por WhatsApp de una sugerencia de Claude tras la demora configurada.
 *
 * Si el setter envía o rechaza antes, o la sugerencia queda obsoleta, el token invalida el job pendiente.
 */
class LeadAiSuggestionAutoSendScheduler
{
    /** Prefijo de clave de caché por mensaje sugerido. */
    private const CACHE_KEY_PREFIX = 'lead_ai_suggestion_auto_send_token:';

    /** TTL de la clave de token. */
    private const CACHE_TTL_SECONDS = 7200;

    /**
     * Encola el envío automático de una sugerencia recién creada por Claude.
     *
     * Los mensajes que requieren verificación (tramo de agenda) usan su propia demora de respaldo,
     * salvo que el lead esté derivado a intervención humana (en ese caso no se programa nada).
     * Con demora 0 encola el envío de inmediato (sin timer ni ai_auto_send_at).
     *
     * @param LeadMessage $message Mensaje en estado `sugerido`.
     *
     * @return void
     */
    public function schedule_for_suggested_message(LeadMessage $message): void
    {
        if ((string) $message->status !== 'sugerido') {
            return;
        }

        if ((string) $message->sender !== 'sistema') {
            return;
        }

        if ($message->requiere_verificacion) {
            /*
             * Decisión de Lucas (6/7/2026, revisada - prompt 276): los mensajes de verificación de agenda
             * vuelven a tener auto-envío de RESPALDO con su propia demora (más larga y configurable). Es
             * una red de seguridad para que un lead no se enfríe si el setter no llega a aprobar a tiempo,
             * NO el comportamiento default.
             *
             * RESGUARDO: si el lead fue derivado a intervención humana (requiere_intervencion_humana, o
             * claude_auto_reply apagado), el respaldo NO aplica: ese mensaje espera aprobación humana sí o
             * sí y nunca se auto-envía.
             */
            $lead = $message->lead;
            if ($lead && ((bool) $lead->requiere_intervencion_humana === true || (bool) $lead->claude_auto_reply === false)) {
                return;
            }

            /*
             * FIX (prompt 337): si el paquete de acciones pendientes trae agendar_demo o
             * cancelar_demo, el texto de Claude confirma (o cancela) un horario al lead — ese
             * mensaje espera aprobación humana sí o sí, nunca respaldo automático (ver Caso A de
             * LeadSuggestionSendService::send_suggestion()). No tiene sentido mostrarle al setter
             * un countdown ("se envía solo en X") que después el job va a frenar en seco: directo
             * no se programa nada acá (ni ai_auto_send_at ni el job). El Caso A del job queda
             * igual como red de seguridad para mensajes que ya hubieran quedado programados antes
             * de este cambio.
             */
            $pending_actions = $message->pending_actions;
            if (! empty($pending_actions['agendar_demo']) || ! empty($pending_actions['cancelar_demo'])) {
                return;
            }

            // La config de origen está en minutos (decisión 13/7/2026); se convierte a segundos acá
            // porque el resto del método sigue trabajando con segundos (addSeconds, log, etc).
            $delay_minutes = LeadWhatsappOnboardingSettings::get_verificacion_agendamiento_auto_send_delay_minutes();
            $delay_seconds = $delay_minutes * 60;
        } else {
            // Sin verificación = envío inmediato, sin demora. Se eliminó la demora general de auto-envío
            // (decisión de Lucas, 15/7/2026): la única demora del sistema es la de los mensajes que
            // requieren verificación (rama de arriba, en minutos). El bloque `if ($delay_seconds <= 0)`
            // de más abajo despacha el envío inmediato.
            $delay_seconds = 0;
        }
        $message_id = (int) $message->id;
        $auto_send_token = $this->bump_auto_send_token($message_id);

        /* 0 = enviar por WhatsApp de inmediato, sin countdown ni ai_auto_send_at. */
        if ($delay_seconds <= 0) {
            AutoSendLeadAiSuggestionJob::dispatch($message_id, $auto_send_token)
                ->onConnection('sync')
                ->afterResponse();

            Log::channel('daily')->debug('LeadAiSuggestionAutoSendScheduler: envío automático inmediato programado.', [
                'message_id'      => $message_id,
                'lead_id'         => $message->lead_id,
                'auto_send_token' => $auto_send_token,
            ]);

            return;
        }

        $auto_send_at = now()->addSeconds($delay_seconds);

        $message->ai_auto_send_at = $auto_send_at;
        $message->save();

        AutoSendLeadAiSuggestionJob::dispatch($message_id, $auto_send_token)
            ->delay($auto_send_at);

        Log::channel('daily')->debug('LeadAiSuggestionAutoSendScheduler: envío automático programado.', [
            'message_id'      => $message_id,
            'lead_id'         => $message->lead_id,
            'delay_seconds'   => $delay_seconds,
            'auto_send_at'    => $auto_send_at->toIso8601String(),
            'auto_send_token' => $auto_send_token,
        ]);

        /* Reemitir el mensaje con ai_auto_send_at para clientes que ya recibieron el alta sin timer. */
        LeadBroadcastService::emit_conversation_updated((int) $message->lead_id, $message_id);
    }

    /**
     * Invalida jobs de auto-envío pendientes para un mensaje (rechazo u obsolescencia).
     *
     * @param int $message_id
     *
     * @return void
     */
    public function cancel_for_message(int $message_id): void
    {
        $this->bump_auto_send_token($message_id);

        LeadMessage::query()
            ->where('id', $message_id)
            ->update(['ai_auto_send_at' => null]);
    }

    /**
     * Indica si el token del job sigue vigente para ese mensaje.
     *
     * @param int $message_id
     * @param int $auto_send_token
     *
     * @return bool
     */
    public function is_auto_send_token_current(int $message_id, int $auto_send_token): bool
    {
        $current = Cache::get($this->cache_key($message_id));

        return (int) $current === $auto_send_token;
    }

    /**
     * Incrementa el token de auto-envío del mensaje.
     *
     * @param int $message_id
     *
     * @return int
     */
    private function bump_auto_send_token(int $message_id): int
    {
        $cache_key = $this->cache_key($message_id);
        $current = (int) Cache::get($cache_key, 0);
        $next = $current + 1;

        Cache::put($cache_key, $next, self::CACHE_TTL_SECONDS);

        return $next;
    }

    /**
     * @param int $message_id
     *
     * @return string
     */
    private function cache_key(int $message_id): string
    {
        return self::CACHE_KEY_PREFIX.$message_id;
    }
}
