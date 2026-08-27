<?php

namespace App\Jobs;

use App\Events\SupportAiSuggestionGenerating;
use App\Events\SupportAiSuggestionPending;
use App\Events\SupportTicketEscalated;
use App\Events\SupportTicketUpdated;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\SupportAiSettings;
use App\Services\SupportAiSuggestionDraftService;
use App\Services\SupportAiSuggestionDeliveryService;
use App\Services\SupportAiSuggestionScheduler;
use App\Services\SupportAiSuggestionService;
use App\Services\EscalationPushNotificationService;
use App\Services\SupportEscalationWhatsappService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Genera sugerencia de Claude tras un mensaje entrante de WhatsApp (si la configuración está activa).
 */
class SendSupportAiSuggestion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Texto que se le manda al cliente cuando el gate descarta la respuesta del agente.
     *
     * Es el mismo mensaje de espera que define `manual_sistema/escalation_rules.md` para
     * cualquier escalado. Está duplicado acá a propósito: el del repositorio se lo dicta al
     * agente, y este es el que usa el sistema cuando decide escalar POR SU CUENTA, sin pedirle
     * nada al agente — un camino que tiene que funcionar aunque el repositorio no esté
     * disponible, que es justo uno de los motivos por los que se llega hasta acá.
     */
    const MENSAJE_DE_ESPERA = 'Dame un momento, por favor — lo estamos revisando con más detalle y te respondemos enseguida.';

    /**
     * @var int ID del ticket de soporte.
     */
    private $ticket_id;

    /**
     * @var int Token de programación; debe coincidir con caché al ejecutar.
     */
    private $schedule_token;

    /**
     * @param int $ticket_id
     * @param int $schedule_token
     */
    public function __construct(int $ticket_id, int $schedule_token)
    {
        $this->ticket_id = $ticket_id;
        $this->schedule_token = $schedule_token;
    }

    /**
     * Genera la sugerencia y envía o programa el envío según support_ai_auto_send_delay.
     *
     * @param SupportAiSuggestionService         $suggestion_service
     * @param SupportAiSuggestionDeliveryService $delivery_service
     * @param SupportAiSuggestionScheduler       $scheduler
     *
     * @return void
     */
    public function handle(
        SupportAiSuggestionService $suggestion_service,
        SupportAiSuggestionDeliveryService $delivery_service,
        SupportAiSuggestionScheduler $scheduler,
        SupportAiSuggestionDraftService $draft_service
    ): void {
        if (! $scheduler->is_schedule_token_current($this->ticket_id, $this->schedule_token)) {
            Log::channel('daily')->debug('SendSupportAiSuggestion: omitido (token de debounce obsoleto).', [
                'ticket_id'        => $this->ticket_id,
                'schedule_token'   => $this->schedule_token,
            ]);

            return;
        }

        $ticket = SupportTicket::query()->with('client')->find($this->ticket_id);
        if ($ticket === null) {
            return;
        }

        if ($ticket->status !== 'open') {
            return;
        }

        if ($this->last_message_is_from_admin($ticket->id)) {
            return;
        }

        // Interruptor por ticket. Se chequea ANTES de llamar a la API: con el agente apagado no
        // tiene sentido pagar una consulta a Claude para después tirar el resultado.
        if (! (bool) $ticket->claude_auto_reply) {
            Log::channel('daily')->debug('SendSupportAiSuggestion: omitido (agente apagado en este ticket).', [
                'ticket_id' => $ticket->id,
            ]);

            return;
        }

        event(new SupportAiSuggestionGenerating($ticket->id));

        $result = $suggestion_service->generate($ticket);

        if (! $scheduler->is_schedule_token_current($this->ticket_id, $this->schedule_token)) {
            Log::channel('daily')->info('SendSupportAiSuggestion: sugerencia descartada (mensajes nuevos del cliente durante la API).', [
                'ticket_id'      => $ticket->id,
                'schedule_token' => $this->schedule_token,
            ]);

            return;
        }

        /* 🔴 La instancia que se cargó arriba quedó vieja: entre medio hubo una llamada a
         * Claude con tool use contra GitHub, que tarda decenas de segundos, y en ese rato el
         * operador puede haber tocado cualquiera de los dos interruptores. Sin este refresh,
         * prender "con verificación" mientras el agente está pensando no frena nada: el
         * mensaje sale igual, con el candado prendido en la pantalla. El token del debounce ya
         * se revalida acá arriba justo por esto; los flags necesitaban lo mismo. */
        $ticket->refresh();

        if (! (bool) $ticket->claude_auto_reply) {
            Log::channel('daily')->info('SendSupportAiSuggestion: descartada (apagaron el agente durante la generación).', [
                'ticket_id' => $ticket->id,
            ]);

            return;
        }

        /* Mensaje sugerido por Claude para enviar al cliente (puede ser vacío). */
        $suggested_message = trim((string) ($result['suggested_message'] ?? ''));

        /* --- Gate de respaldo documental (27/8/2026) ---
         *
         * El agente afirmó algo del sistema sin poder citar un archivo del manual que lo
         * respalde. El texto que redactó NO sale: se descarta y el caso se escala con el motivo
         * del gate, para que lo conteste una persona y de paso el repositorio se complete.
         *
         * Se exige que `gate_permitido` esté presente y sea false. Su AUSENCIA significa "nadie
         * evaluó" —un espía de tests, o una versión del servicio anterior a este cambio— y es
         * distinto de "evaluó y rechazó": tratarla como rechazo frenaría todo mensaje generado
         * por un servicio sustituido. La decisión de escalar ante un campo faltante ya la toma el
         * gate, adentro del servicio, sobre lo que devolvió el modelo. */
        if (array_key_exists('gate_permitido', $result) && $result['gate_permitido'] === false) {
            $motivo_gate = $this->motivo_con_la_consulta(
                $ticket->id,
                trim((string) ($result['gate_motivo'] ?? ''))
            );

            Log::channel('daily')->warning('SendSupportAiSuggestion: respuesta descartada por falta de respaldo documental.', [
                'ticket_id' => $ticket->id,
                'motivo'    => $motivo_gate,
            ]);

            /* El mensaje de espera del protocolo, no lo que había redactado el agente. Si el
             * agente ya venía escalando por su cuenta, su texto YA es el mensaje de espera y se
             * respeta; si venía a afirmar algo sin respaldo, ese texto es exactamente lo que no
             * puede salir.
             *
             * El chequeo de vacío no es defensivo de más: es el camino del repositorio caído, que
             * escala sin llegar a consultar a Claude y por lo tanto no tiene ningún texto que
             * respetar. Sin esto el cliente se quedaría en silencio justo cuando el sistema no
             * puede contestarle. */
            $mensaje_de_espera = (! empty($result['should_escalate']) && $suggested_message !== '')
                ? $suggested_message
                : self::MENSAJE_DE_ESPERA;

            $result['escalation_reason'] = $motivo_gate;

            $this->handle_escalation($ticket, $result, $mensaje_de_espera, $delivery_service, $draft_service);

            return;
        }

        /* --- Manejo de escalado a humano --- */
        if (! empty($result['should_escalate'])) {
            $this->handle_escalation($ticket, $result, $suggested_message, $delivery_service, $draft_service);

            return;
        }

        /* --- Manejo de cierre de ticket --- */
        if (! empty($result['should_close'])) {
            $this->handle_close($ticket, $suggested_message, $delivery_service, $draft_service);

            return;
        }

        /* --- Flujo normal: sugerencia o draft --- */
        if ($suggested_message === '') {
            Log::channel('daily')->info('SendSupportAiSuggestion: sugerencia vacía, no se envía.', [
                'ticket_id' => $ticket->id,
                'reasoning' => $result['reasoning'] ?? '',
            ]);

            return;
        }

        $suggested_title = trim((string) ($result['suggested_title'] ?? ''));
        if ($suggested_title !== '' && trim((string) ($ticket->name ?? '')) === '') {
            $ticket->name = $suggested_title;
            $ticket->save();
        }

        $this->entregar_o_dejar_en_borrador($ticket, $suggested_message, $delivery_service, $draft_service);
    }

    /**
     * Resuelve qué hacer con un texto que el agente quiere mandarle al cliente.
     *
     * Son tres modos y hasta esta misión existían solo los dos últimos:
     *
     *   1. **Requiere verificación** (`support_tickets.requiere_verificacion_mensajes`, prendido
     *      por defecto): queda como borrador SIN `ai_auto_send_at` y no se manda nunca solo. Lo
     *      manda una persona desde la conversación, con o sin ajustes.
     *   2. **Auto-envío demorado** (`support_ai_auto_send_delay` > 0): borrador con fecha, que un
     *      job encolado envía al cumplirse, salvo que el operador lo cancele antes escribiendo.
     *   3. **Inmediato**: sale derecho al cliente.
     *
     * @param SupportTicket                      $ticket
     * @param string                             $suggested_message Texto del agente, ya trimeado y no vacío.
     * @param SupportAiSuggestionDeliveryService $delivery_service
     * @param SupportAiSuggestionDraftService    $draft_service
     *
     * @return bool True si quedó un borrador sin entregar al cliente.
     */
    private function entregar_o_dejar_en_borrador(
        SupportTicket $ticket,
        string $suggested_message,
        SupportAiSuggestionDeliveryService $delivery_service,
        SupportAiSuggestionDraftService $draft_service
    ): bool {
        if ((bool) $ticket->requiere_verificacion_mensajes) {
            // create_draft() con demora 0 deja ai_auto_send_at en null: el borrador espera.
            $draft_service->create_draft($ticket, $suggested_message, 0);

            event(new SupportAiSuggestionPending($ticket->id));

            Log::channel('daily')->info('SendSupportAiSuggestion: sugerencia en espera de aprobación humana.', [
                'ticket_id' => $ticket->id,
            ]);

            return true;
        }

        $delay = SupportAiSettings::get_auto_send_delay_seconds();

        if ($delay <= 0) {
            $delivery_service->deliver_text_reply($ticket, $suggested_message);

            return false;
        }

        $draft_message = $draft_service->create_draft($ticket, $suggested_message, $delay);

        event(new SupportAiSuggestionPending($ticket->id));

        if ($draft_message->ai_auto_send_at !== null) {
            AutoSendPendingSupportSuggestion::dispatch($ticket->id)
                ->delay($draft_message->ai_auto_send_at);
        }

        return true;
    }

    /**
     * Persiste el escalado en el ticket, emite los eventos Pusher correspondientes
     * y, si Claude generó un mensaje de espera, lo envía al cliente.
     *
     * @param SupportTicket                      $ticket
     * @param array<string, mixed>               $result           Resultado de SupportAiSuggestionService::generate().
     * @param string                             $suggested_message Mensaje de espera sugerido por Claude (puede ser vacío).
     * @param SupportAiSuggestionDeliveryService $delivery_service
     * @param SupportAiSuggestionDraftService    $draft_service
     *
     * @return void
     */
    private function handle_escalation(
        SupportTicket $ticket,
        array $result,
        string $suggested_message,
        SupportAiSuggestionDeliveryService $delivery_service,
        SupportAiSuggestionDraftService $draft_service
    ): void {
        /* Motivo del escalado: texto libre generado por Claude. */
        $escalation_reason = trim((string) ($result['escalation_reason'] ?? ''));

        /* Claude puede volver a escalar el mismo ticket con cada mensaje del cliente, y el
         * aviso repetido se vuelve ruido que se ignora. Pero "ya estaba escalado" no alcanza
         * como freno: `escalated_at` solo se limpia al CERRAR el ticket, así que un escalado
         * nuevo por un motivo distinto, semanas después, no avisaría a nadie. Se compara el
         * motivo: mismo motivo, no se repite; motivo nuevo, se avisa. */
        // Se comparan los motivos tal cual, incluso vacíos: exigir que el anterior no fuera
        // vacío dejaba el freno sin efecto cuando Claude escala sin llenar el motivo —el
        // prompt lo pide, no lo garantiza—, y volvía a un WhatsApp por cada mensaje del cliente.
        $motivo_anterior = trim((string) ($ticket->escalation_reason ?? ''));
        $motivo_nuevo = trim((string) ($result['escalation_reason'] ?? ''));
        $es_el_mismo_escalado = $ticket->escalated_at !== null && $motivo_anterior === $motivo_nuevo;

        /* Persistir el escalado en el ticket. */
        $ticket->escalated_at      = now();
        $ticket->escalation_reason = $escalation_reason !== '' ? $escalation_reason : null;
        $ticket->save();

        Log::channel('daily')->info('SendSupportAiSuggestion: ticket escalado a humano.', [
            'ticket_id'        => $ticket->id,
            'escalation_reason' => $escalation_reason,
        ]);

        /* Nombre del ticket para el payload del evento. */
        $ticket_name = trim((string) ($ticket->name ?? ''));
        if ($ticket_name === '') {
            $ticket_name = 'Ticket #'.$ticket->id;
        }

        /* Nombre del cliente: usar relación si está cargada. */
        $client_name = $ticket->resolve_contact_display_name();

        /* Emitir alerta de escalado para toast en admin-spa. */
        event(new SupportTicketEscalated(
            $ticket->id,
            $ticket_name,
            $client_name,
            $escalation_reason !== '' ? $escalation_reason : 'Claude no pudo resolver este caso.'
        ));

        /* Emitir actualización de la fila en la bandeja para reflejar escalated_at. */
        event(new SupportTicketUpdated($ticket->id));

        /* Avisar por WhatsApp a los operadores suscritos. El Pusher de arriba solo sirve si
         * alguien tiene el admin abierto en ese momento; el escalado no puede depender de eso.
         * Va en su propio try: si el aviso falla, el ticket YA quedó escalado y con badge, y
         * perder eso por un problema de Meta sería peor que quedarse sin el WhatsApp. */
        try {
            if (! $es_el_mismo_escalado) {
                /* Web Push primero: llega al teléfono con el admin cerrado y no gasta plantilla.
                 * El WhatsApp queda como red de seguridad para los operadores que no tienen
                 * ningún device registrado — si no, no se enterarían de nada. No son dos canales
                 * en paralelo. Ver EscalationPushNotificationService. */
                $reparto = app(EscalationPushNotificationService::class)->notificar_ticket($ticket, $escalation_reason);

                app(SupportEscalationWhatsappService::class)->notify($ticket, $escalation_reason, $reparto['sin_device']);
            }
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('SendSupportAiSuggestion: el ticket quedó escalado pero el aviso falló.', [
                'ticket_id' => $ticket->id,
                'error'     => $exception->getMessage(),
            ]);
        }

        /* Enviar mensaje de espera al cliente si Claude lo generó. */
        if ($suggested_message !== '') {
            $this->entregar_o_dejar_en_borrador($ticket, $suggested_message, $delivery_service, $draft_service);
        }
    }

    /**
     * Envía el mensaje de cierre al cliente (si existe) y luego cierra el ticket,
     * emitiendo SupportTicketUpdated para que la bandeja refleje el nuevo estado.
     *
     * @param SupportTicket                      $ticket
     * @param string                             $suggested_message Mensaje final sugerido por Claude (puede ser vacío).
     * @param SupportAiSuggestionDeliveryService $delivery_service
     * @param SupportAiSuggestionDraftService    $draft_service
     *
     * @return void
     */
    private function handle_close(
        SupportTicket $ticket,
        string $suggested_message,
        SupportAiSuggestionDeliveryService $delivery_service,
        SupportAiSuggestionDraftService $draft_service
    ): void {
        /* Enviar mensaje de cierre al cliente antes de cerrar el ticket. */
        $quedo_borrador = false;
        if ($suggested_message !== '') {
            $quedo_borrador = $this->entregar_o_dejar_en_borrador($ticket, $suggested_message, $delivery_service, $draft_service);
        }

        /* Si quedó un borrador sin mandar, el ticket NO se cierra. Vale para los DOS modos que
         * dejan borrador —el que espera aprobación y el que espera el timer—, no solo para el
         * primero: cerrar acá deja al cliente sin la última respuesta y vuelve inentregable el
         * borrador, porque tanto deliver_draft_message() como approve_ai_draft() exigen el
         * ticket abierto. Lo cierra la persona desde el selector de estado. */
        if ($quedo_borrador) {
            Log::channel('daily')->info('SendSupportAiSuggestion: Claude propuso cerrar, pero el ticket espera aprobación humana.', [
                'ticket_id' => $ticket->id,
            ]);

            return;
        }

        /* Cerrar el ticket, limpiar escalado y notificar la bandeja. */
        $ticket->status            = 'closed';
        $ticket->closed_at         = now();
        $ticket->escalated_at      = null;
        $ticket->escalation_reason = null;
        $ticket->save();

        Log::channel('daily')->info('SendSupportAiSuggestion: ticket cerrado por Claude.', [
            'ticket_id' => $ticket->id,
        ]);

        event(new SupportTicketUpdated($ticket->id));
    }

    /**
     * Le pega al motivo del gate la consulta que el cliente no pudo ver respondida.
     *
     * Cumple dos funciones a la vez, y las dos importan:
     *
     * 1. **Es lo que Lucas necesita leer.** El objetivo de escalar es que la pregunta se conteste
     *    y que el repositorio quede completo para la próxima; sin saber qué preguntaron, el aviso
     *    obliga a abrir el ticket para entender de qué se trata.
     * 2. **Hace que el aviso no se pierda.** `handle_escalation()` no vuelve a avisar cuando el
     *    motivo es idéntico al del escalado anterior. Los motivos que redacta el gate son
     *    genéricos por naturaleza ("afirmó algo sin citar ningún documento"), así que tres
     *    preguntas distintas sin respuesta producirían el mismo texto y Lucas se enteraría solo
     *    de la primera — justo lo contrario de lo que se busca. Con la consulta adentro, cada
     *    caso nuevo es un motivo nuevo.
     *
     * @param int    $ticket_id Ticket en curso.
     * @param string $motivo    Motivo que redactó el gate.
     *
     * @return string
     */
    private function motivo_con_la_consulta(int $ticket_id, string $motivo): string
    {
        $ultima = SupportMessage::query()
            ->where('support_ticket_id', $ticket_id)
            ->where('sender_type', 'user')
            ->where('is_ai_suggestion_draft', false)
            ->orderBy('id', 'desc')
            ->first();

        $consulta = $ultima !== null ? trim((string) $ultima->body) : '';

        if ($consulta === '') {
            return $motivo;
        }

        /* Acotado: esto viaja como variable de una plantilla de Meta y como cuerpo de una
         * notificación push, y ninguno de los dos muestra un párrafo entero. */
        return $motivo . ' Preguntó: "' . mb_strimwidth($consulta, 0, 180, '…') . '".';
    }

    /**
     * Indica si el último mensaje del hilo ya es del operador (cancela auto-respuesta).
     *
     * @param int $ticket_id
     *
     * @return bool
     */
    private function last_message_is_from_admin(int $ticket_id): bool
    {
        $last_message = SupportMessage::query()
            ->where('support_ticket_id', $ticket_id)
            ->where('is_ai_suggestion_draft', false)
            ->orderBy('id', 'desc')
            ->first();

        return $last_message !== null && $last_message->sender_type === 'admin';
    }
}
