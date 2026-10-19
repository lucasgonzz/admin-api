<?php

namespace App\Jobs;

use App\Models\LeadMessage;
use App\Services\FranjaHorariaDeSeguimientos;
use App\Services\LeadAiSuggestionAutoSendScheduler;
use App\Services\LeadBroadcastService;
use App\Services\LeadSuggestionSendService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Envía por WhatsApp una sugerencia de Claude si el setter no confirmó antes del tiempo configurado.
 */
class AutoSendLeadAiSuggestionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int ID del LeadMessage sugerido.
     */
    private $message_id;

    /**
     * @var int Token de programación; debe coincidir con caché al ejecutar.
     */
    private $auto_send_token;

    /**
     * true si este job YA es el respaldo corrido a la mañana por la franja horaria de los seguimientos
     * (misión asistente-arreglos-segunda-prueba, N11, 19/10/2026): lo encola así el scheduler cuando
     * el vencimiento caía de noche, o `reprogramar_a_la_franja()` cuando un job corrió de noche.
     *
     * Sirve para cortar la cadena: un job reprogramado que igual corre fuera de la franja no se vuelve
     * a reprogramar, se queda quieto (la sugerencia sigue en la bandeja para una persona). Con la cola
     * `sync` —la de los tests, donde la demora no existe y el job corre en el acto— reprogramar desde
     * un job reprogramado sería un bucle infinito.
     *
     * 🔴 El default va EN LA DECLARACIÓN y no solo en el constructor: al deserializar un job que ya
     * estaba en la cola desde antes de este cambio el constructor no corre, y la propiedad tiene que
     * valer false igual.
     *
     * @var bool
     */
    private $reprogramado_por_la_franja = false;

    /**
     * @param int  $message_id
     * @param int  $auto_send_token
     * @param bool $reprogramado_por_la_franja Ver la propiedad. Default false: todos los llamadores de
     *                                         antes siguen igual.
     */
    public function __construct(int $message_id, int $auto_send_token, bool $reprogramado_por_la_franja = false)
    {
        $this->message_id = $message_id;
        $this->auto_send_token = $auto_send_token;
        $this->reprogramado_por_la_franja = $reprogramado_por_la_franja;
    }

    /**
     * Envía la sugerencia si sigue pendiente y el token no fue invalidado.
     *
     * @param LeadSuggestionSendService         $send_service
     * @param LeadAiSuggestionAutoSendScheduler $scheduler
     *
     * @return void
     */
    public function handle(LeadSuggestionSendService $send_service, LeadAiSuggestionAutoSendScheduler $scheduler): void
    {
        if (! $scheduler->is_auto_send_token_current($this->message_id, $this->auto_send_token)) {
            Log::channel('daily')->debug('AutoSendLeadAiSuggestionJob: omitido (token obsoleto).', [
                'message_id'      => $this->message_id,
                'auto_send_token' => $this->auto_send_token,
            ]);

            return;
        }

        $message = LeadMessage::query()->with('lead')->find($this->message_id);
        if ($message === null) {
            return;
        }

        if ((string) $message->status !== 'sugerido') {
            return;
        }

        /*
         * 🔴 Misión asistente-arreglos-segunda-prueba (N11, 19/10/2026): antes de mandar un SEGUIMIENTO
         * retenido se vuelve a mirar si todavía corresponde, con el lead tal como está AHORA. Entre que
         * se programó y que vence pueden pasar horas —con la franja horaria, toda una noche— y un
         * seguimiento retenido no lo borra nadie cuando el lead escribe
         * (LeadAiSuggestionScheduler::clear_stale_pending_suggestions() filtra `is_followup = false`).
         * La revisión independiente lo reprodujo: retenido a las 20:50, el lead escribe a las 23:10, el
         * agente le contesta, y a la mañana salía igual «Hace un par de días te escribimos…».
         *
         * Los motivos (uno solo decide, ver LeadAiSuggestionAutoSendScheduler::
         * motivo_para_no_auto_enviar_seguimiento()): el lead es un cliente; escribió después de creado
         * el seguimiento; está cerrado o en pausa; pide intervención humana; tiene la respuesta
         * automática apagada; o está marcado como "no recibe mensajes". Vale para TODOS los
         * seguimientos retenidos, no solo los que pasó a la mañana la franja.
         *
         * No se manda, no se rechaza: la sugerencia queda en la bandeja para una persona y se le saca el
         * contador (cancel_for_message), para que el panel no prometa un envío que no va a ocurrir.
         */
        if ($scheduler->es_seguimiento_retenido($message)) {
            $lead   = $message->lead;
            $motivo = $lead !== null ? $scheduler->motivo_para_no_auto_enviar_seguimiento($message, $lead) : null;
            if ($motivo !== null) {
                $scheduler->cancel_for_message($this->message_id);
                LeadBroadcastService::emit_conversation_updated((int) $message->lead_id, $this->message_id);

                Log::channel('daily')->info('AutoSendLeadAiSuggestionJob: el seguimiento retenido ya no corresponde; no se auto-envía y queda en la bandeja.', [
                    'message_id' => $this->message_id,
                    'lead_id'    => $message->lead_id,
                    'motivo'     => $motivo,
                ]);

                return;
            }

            /*
             * 🔴 Franja horaria (misma misión): un seguimiento automático no sale fuera de las 09:00 a
             * 21:00, hora de Argentina. El scheduler ya programa a las 09:00 lo que vencería de noche;
             * esto atrapa al job que igual corre de noche (worker atrasado, job encolado antes de la
             * franja): no manda y se reprograma, UNA vez, al próximo inicio de la franja. Si el que corre
             * de noche ya es la reprogramación, no se encadena otra: queda la sugerencia en la bandeja.
             */
            if (! FranjaHorariaDeSeguimientos::ahora_esta_dentro()) {
                if (! $this->reprogramado_por_la_franja) {
                    $scheduler->reprogramar_a_la_franja($message);
                } else {
                    Log::channel('daily')->warning('AutoSendLeadAiSuggestionJob: respaldo ya reprogramado que corrió fuera de la franja; queda para el setter.', [
                        'message_id' => $this->message_id,
                        'lead_id'    => $message->lead_id,
                    ]);
                }

                return;
            }
        }

        /*
         * FIX (prompt 337): antes de este cambio había acá un `return` que cortaba en seco todo
         * mensaje de verificación (tramo de agenda) — el resultado era que esos mensajes nunca se
         * auto-enviaban aunque la burbuja mostrara un countdown ("se envía solo en X"). Ahora el
         * job SIEMPRE intenta el auto-envío, pero avisándole a send_suggestion() que es un
         * respaldo automático (4º parámetro $is_auto_send) para que decida caso por caso: si el
         * paquete de acciones trae agendar_demo/cancelar_demo no se envía nada (Caso A, requiere
         * aprobación humana); si no, se envía aplicando solo las acciones sin efecto externo
         * (Caso B). Ver LeadSuggestionSendService::send_suggestion() para el detalle de ambos casos.
         */
        try {
            $updated = $send_service->send_suggestion($message, null, null, true);

            if ((string) $updated->status === 'enviado') {
                Log::channel('daily')->info('AutoSendLeadAiSuggestionJob: sugerencia enviada automáticamente por WhatsApp.', [
                    'message_id' => $this->message_id,
                    'lead_id'    => $message->lead_id,
                ]);
            } else {
                /*
                 * FIX (bug documentado): antes de este cambio se logueaba "enviada" sin revisar
                 * el resultado real, incluso cuando send_suggestion() marcaba el mensaje como
                 * 'rechazado' por un fallo de envío (ej: Meta devolvió 422). send_suggestion()
                 * ya se encarga de notificar a admins vía WhatsappSendService si correspondía.
                 */
                Log::channel('daily')->warning('AutoSendLeadAiSuggestionJob: el envío automático no se confirmó.', [
                    'message_id'   => $this->message_id,
                    'lead_id'      => $message->lead_id,
                    'final_status' => $updated->status,
                ]);
            }
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('AutoSendLeadAiSuggestionJob: error al enviar sugerencia.', [
                'message_id' => $this->message_id,
                'error'      => $exception->getMessage(),
            ]);
        }
    }
}
