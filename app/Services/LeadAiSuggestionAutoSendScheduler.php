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
     * No programa mensajes que requieren verificación manual.
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
             * FIX (1/7/2026): antes, CUALQUIER mensaje con requiere_verificacion=true se quedaba
             * esperando aprobación manual para siempre, sin timer. Eso sigue siendo lo correcto
             * para el caso de error (ej. fallback de disponibilidad — ahí preferimos que espere
             * indefinido antes que mandar algo raro solo). Pero para el tramo de coordinación de
             * agenda (regla de negocio agregada en LeadAiService::create_message_and_update_lead),
             * se programa un auto-envío igual, con demora propia más larga, para no dejar a un
             * lead esperando horas si Martín no lo ve a tiempo.
             */
            /*
             * Usar suggested_lead_status (el estado resultante que LeadAiService calculó para
             * este mensaje) en vez de lead->status directo: al momento de programar el auto-envío,
             * lead->status todavía es el estado PREVIO — apply_suggested_pipeline_status() recién
             * lo actualiza cuando el mensaje se envía (ver LeadSuggestionSendService). Si se
             * chequeara lead->status acá, la primera entrada al tramo (ej. calificado ->
             * solicita_disponibilidad) no calificaría para el auto-envío de respaldo, justo el
             * caso de mayor valor. suggested_lead_status es null cuando la sugerencia no cambia
             * el estado, en cuyo caso el estado resultante sigue siendo el actual del lead.
             */
            $lead = $message->lead;
            $suggested_status = trim((string) $message->suggested_lead_status);
            $lead_status = $suggested_status !== '' ? $suggested_status : ($lead ? (string) $lead->status : '');
            $estados_con_auto_send_tras_verificacion = [
                'solicita_disponibilidad',
                'demo_agendada',
                'demo_pendiente_de_ingreso',
                'ingresando_demo',
                'demo_en_curso',
                'demo_pendiente_de_terminar',
            ];
            if (! in_array($lead_status, $estados_con_auto_send_tras_verificacion, true)) {
                return;
            }

            $delay_seconds = LeadWhatsappOnboardingSettings::get_verificacion_agendamiento_auto_send_delay_seconds();
        } else {
            $delay_seconds = LeadWhatsappOnboardingSettings::get_ai_suggestion_auto_send_delay_seconds();
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
