<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Jobs\AutoSendLeadAiSuggestionJob;
use App\Models\Lead;
use App\Models\LeadMessage;
use Carbon\Carbon;
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
     * Estados en los que el lead ya no está para seguimientos: los mismos que
     * `LeadFollowupService::process_all_active_leads()` saltea siempre (cierres y pausa). Los usa
     * motivo_para_no_auto_enviar_seguimiento() (misión asistente-arreglos-segunda-prueba, 19/10/2026).
     *
     * @var array<int, string>
     */
    public const ESTADOS_SIN_SEGUIMIENTOS = ['cerrado_ganado', 'cerrado_perdido', 'en_pausa'];

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

            /*
             * 🔴 Misión asistente-arreglos-segunda-prueba (N11, 19/10/2026): el respaldo automático de
             * un SEGUIMIENTO retenido no se programa si el lead es un cliente (su teléfono es el de un
             * cliente activo, de su equipo o de un admin, o ya fue promovido). Es la misma guarda que
             * el cron de seguimientos (ClientPhoneDirectory::es_lead_de_un_cliente()): el seguimiento
             * queda en la bandeja para que lo vea una persona, sin contador, en vez de salir solo al
             * número de un dueño como salió el del 18/10 al de demo3. Solo los seguimientos: una
             * respuesta conversacional retenida es otra cosa (el lead escribió, alguien le contesta).
             */
            if ($lead && $this->es_seguimiento_retenido($message) && (new ClientPhoneDirectory())->es_lead_de_un_cliente($lead)) {
                Log::channel('daily')->info('LeadAiSuggestionAutoSendScheduler: seguimiento retenido de un lead que es cliente; no se programa el respaldo.', [
                    'message_id' => (int) $message->id,
                    'lead_id'    => $message->lead_id,
                ]);

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

        /*
         * 🔴 Franja horaria de los seguimientos automáticos (misión asistente-arreglos-segunda-prueba,
         * N11, 19/10/2026): el respaldo de un SEGUIMIENTO retenido que vencería de noche se programa
         * para el próximo inicio de la franja (09:00, hora de Argentina) y no para la hora de
         * vencimiento. Así el contador del panel ya dice la hora real a la que va a salir y el job no
         * tiene que despertarse de noche para reprogramarse. El momento se mide sobre AppTime::now()
         * (el reloj que mueven los tests y el reloj virtual de local) más la demora, que es una duración.
         *
         * Al moverlo, el token tiene que vivir hasta esa hora: con el TTL fijo de dos horas
         * (CACHE_TTL_SECONDS) el job de la mañana encontraría el token vencido y se descartaría como
         * obsoleto, o sea que el seguimiento no saldría nunca. Por eso el TTL pasa a ser la demora más
         * el margen de siempre. Y el job sale contando una reprogramación por la franja, para que si
         * igual corriera de noche las reprogramaciones tengan tope (ver AutoSendLeadAiSuggestionJob).
         *
         * 🔴 La hora es la apertura MÁS UN MINUTO (09:01:00, FranjaHorariaDeSeguimientos::
         * momento_para_reprogramar()) y la demora se redondea PARA ARRIBA (segundos_hasta()): con
         * microsegundos en el reloj, `diffInSeconds()` truncaba y dejaba el job para las 08:59:59,x,
         * fuera de la franja (lo reprodujo la revisión independiente).
         */
        $ttl_del_token                = self::CACHE_TTL_SECONDS;
        $reprogramaciones_por_franja  = 0;
        if ($this->es_seguimiento_retenido($message)) {
            $ahora       = AppTime::now(FranjaHorariaDeSeguimientos::ZONA_HORARIA);
            $vencimiento = $ahora->copy()->addSeconds(max(0, $delay_seconds));

            if (! FranjaHorariaDeSeguimientos::esta_dentro($vencimiento)) {
                $permitido                   = FranjaHorariaDeSeguimientos::momento_para_reprogramar($vencimiento);
                $delay_seconds               = FranjaHorariaDeSeguimientos::segundos_hasta($ahora, $permitido);
                $ttl_del_token               = $delay_seconds + self::CACHE_TTL_SECONDS;
                $reprogramaciones_por_franja = 1;
            }
        }

        $message_id = (int) $message->id;
        $auto_send_token = $this->bump_auto_send_token($message_id, $ttl_del_token);

        /* 0 = listo para el próximo worker, sin countdown ni ai_auto_send_at.
         * La generación ya corre dentro de un worker persistente: afterResponse espera a que
         * termine ESE proceso, no a que termine su job, y dejaba respuestas sin enviar. */
        if ($delay_seconds <= 0) {
            AutoSendLeadAiSuggestionJob::dispatch($message_id, $auto_send_token)
                ->onConnection('database');

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

        AutoSendLeadAiSuggestionJob::dispatch($message_id, $auto_send_token, $reprogramaciones_por_franja)
            ->onConnection('database')
            ->delay($auto_send_at);

        Log::channel('daily')->debug('LeadAiSuggestionAutoSendScheduler: envío automático programado.', [
            'message_id'           => $message_id,
            'lead_id'              => $message->lead_id,
            'delay_seconds'        => $delay_seconds,
            'auto_send_at'         => $auto_send_at->toIso8601String(),
            'auto_send_token'      => $auto_send_token,
            'reprogramaciones'     => $reprogramaciones_por_franja,
        ]);

        /* Reemitir el mensaje con ai_auto_send_at para clientes que ya recibieron el alta sin timer. */
        LeadBroadcastService::emit_conversation_updated((int) $message->lead_id, $message_id);
    }

    /**
     * ¿Es un SEGUIMIENTO del cron retenido para verificación, de los que tienen respaldo automático?
     *
     * Son los que deja `LeadFollowupService::create_pending_followup_for_verification()` (plantilla del
     * tramo de agenda, o escudo por-lead prendido) y los seguimientos que Claude redacta con
     * `requiere_verificacion`: `is_followup` + `requiere_verificacion`, del sistema. Las guardas de los
     * seguimientos automáticos (teléfono de cliente y, desde la misma misión, la franja horaria) se
     * aplican solo a estos: una respuesta conversacional retenida no es un seguimiento, y un
     * seguimiento sin retener (`requiere_verificacion = false`) sale en el acto por el camino que lo
     * generó, que ya tiene sus propias guardas (el cron) o es una persona apretando un botón.
     *
     * @param LeadMessage $message
     *
     * @return bool
     */
    public function es_seguimiento_retenido(LeadMessage $message): bool
    {
        return (string) $message->sender === 'sistema'
            && (bool) $message->is_followup
            && (bool) $message->requiere_verificacion;
    }

    /**
     * ¿Por qué ya NO hay que auto-enviar este seguimiento retenido? Null si corresponde mandarlo.
     *
     * 🔴 Misión asistente-arreglos-segunda-prueba (N11, 19/10/2026), a raíz de la revisión
     * independiente: el respaldo se programa en un momento y vence en otro —con la franja horaria,
     * a veces a la mañana siguiente—, y en el medio el lead puede haber cambiado. Un seguimiento
     * retenido no lo limpia nadie cuando el lead escribe (`clear_stale_pending_suggestions()` filtra
     * `is_followup = false`), así que esta revisión al vencer es la única que hay. Lo llama
     * `AutoSendLeadAiSuggestionJob` antes de mandar, con el lead recién leído de la base.
     *
     * Motivos, en este orden (el primero que aplica es el que queda en el log):
     *   - `es_cliente`: {@see ClientPhoneDirectory::es_lead_de_un_cliente()} — el número es de un
     *     cliente activo, de su equipo o de un admin, o el lead ya fue promovido;
     *   - `lead_escribio_despues`: hay un entrante del lead (sin contar eventos de estado) posterior al
     *     seguimiento. Se mide por `id` y no por `created_at`: el `id` es el orden real en que se
     *     guardaron las filas y no empata nunca, mientras que dos `created_at` en el mismo segundo sí.
     *     Es el mismo criterio de «contestó» de `LeadFollowupService::lead_respondio()` (entrante con
     *     `id` mayor que un saliente), tomando como saliente este seguimiento;
     *   - `lead_cerrado_o_en_pausa`: {@see ESTADOS_SIN_SEGUIMIENTOS};
     *   - `intervencion_humana`: `requiere_intervencion_humana` prendido;
     *   - `respuesta_automatica_apagada`: `claude_auto_reply` apagado (los mismos dos que ya frenan el
     *     respaldo al programarlo, ver schedule_for_suggested_message(); acá se vuelven a mirar porque
     *     pueden haber cambiado después);
     *   - `no_recibe_mensajes`: la marca manual de número bloqueado o dado de baja.
     *
     * @param LeadMessage $message Seguimiento retenido (ver es_seguimiento_retenido()).
     * @param Lead        $lead    Su lead, leído ahora.
     *
     * @return string|null Motivo, o null si el seguimiento todavía corresponde.
     */
    public function motivo_para_no_auto_enviar_seguimiento(LeadMessage $message, Lead $lead): ?string
    {
        if ((new ClientPhoneDirectory())->es_lead_de_un_cliente($lead)) {
            return 'es_cliente';
        }

        $escribio_despues = LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('sender', 'lead')
            ->where(function ($query) {
                $query->whereNull('is_status_event')->orWhere('is_status_event', false);
            })
            ->where('id', '>', (int) $message->id)
            ->exists();
        if ($escribio_despues) {
            return 'lead_escribio_despues';
        }

        if (in_array((string) $lead->status, self::ESTADOS_SIN_SEGUIMIENTOS, true)) {
            return 'lead_cerrado_o_en_pausa';
        }

        if ((bool) $lead->requiere_intervencion_humana) {
            return 'intervencion_humana';
        }

        if (! (bool) $lead->claude_auto_reply) {
            return 'respuesta_automatica_apagada';
        }

        if (! empty($lead->no_recibe_mensajes_at)) {
            return 'no_recibe_mensajes';
        }

        return null;
    }

    /**
     * Reprograma el respaldo de un seguimiento retenido para el próximo inicio de la franja horaria
     * (misión asistente-arreglos-segunda-prueba, N11, 19/10/2026).
     *
     * Lo llama `AutoSendLeadAiSuggestionJob` cuando le toca correr de noche: un job que se programó
     * de día y el worker tomó tarde, o uno encolado antes de que existiera la franja. En vez de
     * mandar, se invalida el token viejo, se saca uno nuevo que vive hasta la hora nueva (más el
     * margen de siempre) y se encola un job NUEVO que cuenta una reprogramación más, con la demora
     * hasta la apertura de la franja más un minuto (09:01:00, redondeada para arriba: ver
     * FranjaHorariaDeSeguimientos::MARGEN_AL_ABRIR_SEGUNDOS). `ai_auto_send_at` pasa a esa hora, así
     * el contador del panel dice la verdad.
     *
     * 🔴 Un job nuevo y no `release()`: el worker de producción corre sin `--tries` (vale 1) y el job
     * no declara `$tries`, así que un `release()` volvería a la cola con el intento ya gastado y el
     * worker lo daría por fallido al tomarlo de nuevo. Un dispatch nuevo arranca de cero.
     *
     * @param LeadMessage $message                 Seguimiento retenido todavía en `sugerido`.
     * @param int         $reprogramaciones_previas Cuántas veces ya se había reprogramado el job que
     *                                             llama (el nuevo sale con una más). Default 0.
     *
     * @return Carbon Momento al que quedó reprogramado.
     */
    public function reprogramar_a_la_franja(LeadMessage $message, int $reprogramaciones_previas = 0): Carbon
    {
        $ahora     = AppTime::now(FranjaHorariaDeSeguimientos::ZONA_HORARIA);
        $permitido = FranjaHorariaDeSeguimientos::momento_para_reprogramar($ahora);
        $demora    = FranjaHorariaDeSeguimientos::segundos_hasta($ahora, $permitido);

        $message_id       = (int) $message->id;
        $auto_send_token  = $this->bump_auto_send_token($message_id, $demora + self::CACHE_TTL_SECONDS);
        $auto_send_at     = now()->addSeconds($demora);
        $reprogramaciones = $reprogramaciones_previas + 1;

        $message->ai_auto_send_at = $auto_send_at;
        $message->save();

        AutoSendLeadAiSuggestionJob::dispatch($message_id, $auto_send_token, $reprogramaciones)
            ->delay($auto_send_at);

        Log::channel('daily')->info('LeadAiSuggestionAutoSendScheduler: respaldo de seguimiento reprogramado a la franja horaria.', [
            'message_id'       => $message_id,
            'lead_id'          => $message->lead_id,
            'auto_send_at'     => $auto_send_at->toIso8601String(),
            'auto_send_token'  => $auto_send_token,
            'reprogramaciones' => $reprogramaciones,
        ]);

        LeadBroadcastService::emit_conversation_updated((int) $message->lead_id, $message_id);

        return $auto_send_at;
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
     * @param int $ttl_seconds Vida del token en caché. Default: CACHE_TTL_SECONDS (dos horas), como
     *                         siempre. Solo el respaldo que la franja horaria corrió a la mañana pasa
     *                         uno más largo, que cubra la demora hasta las 09:00 (ver
     *                         schedule_for_suggested_message() y reprogramar_a_la_franja()).
     *
     * @return int
     */
    private function bump_auto_send_token(int $message_id, int $ttl_seconds = self::CACHE_TTL_SECONDS): int
    {
        $cache_key = $this->cache_key($message_id);
        $current = (int) Cache::get($cache_key, 0);
        $next = $current + 1;

        Cache::put($cache_key, $next, $ttl_seconds);

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
