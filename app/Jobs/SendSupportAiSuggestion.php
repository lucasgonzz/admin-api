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

        event(new SupportAiSuggestionGenerating($ticket->id));

        $result = $suggestion_service->generate($ticket);

        if (! $scheduler->is_schedule_token_current($this->ticket_id, $this->schedule_token)) {
            Log::channel('daily')->info('SendSupportAiSuggestion: sugerencia descartada (mensajes nuevos del cliente durante la API).', [
                'ticket_id'      => $ticket->id,
                'schedule_token' => $this->schedule_token,
            ]);

            return;
        }

        /* Mensaje sugerido por Claude para enviar al cliente (puede ser vacío). */
        $suggested_message = trim((string) ($result['suggested_message'] ?? ''));

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

        $delay = SupportAiSettings::get_auto_send_delay_seconds();

        if ($delay <= 0) {
            $delivery_service->deliver_text_reply($ticket, $suggested_message);

            return;
        }

        $draft_message = $draft_service->create_draft($ticket, $suggested_message, $delay);

        event(new SupportAiSuggestionPending($ticket->id));

        if ($draft_message->ai_auto_send_at !== null) {
            AutoSendPendingSupportSuggestion::dispatch($ticket->id)
                ->delay($draft_message->ai_auto_send_at);
        }
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

        /* Enviar mensaje de espera al cliente si Claude lo generó. */
        if ($suggested_message !== '') {
            $delay = SupportAiSettings::get_auto_send_delay_seconds();

            if ($delay <= 0) {
                $delivery_service->deliver_text_reply($ticket, $suggested_message);
            } else {
                $draft_message = $draft_service->create_draft($ticket, $suggested_message, $delay);

                event(new SupportAiSuggestionPending($ticket->id));

                if ($draft_message->ai_auto_send_at !== null) {
                    AutoSendPendingSupportSuggestion::dispatch($ticket->id)
                        ->delay($draft_message->ai_auto_send_at);
                }
            }
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
        if ($suggested_message !== '') {
            $delay = SupportAiSettings::get_auto_send_delay_seconds();

            if ($delay <= 0) {
                $delivery_service->deliver_text_reply($ticket, $suggested_message);
            } else {
                $draft_message = $draft_service->create_draft($ticket, $suggested_message, $delay);

                event(new SupportAiSuggestionPending($ticket->id));

                if ($draft_message->ai_auto_send_at !== null) {
                    AutoSendPendingSupportSuggestion::dispatch($ticket->id)
                        ->delay($draft_message->ai_auto_send_at);
                }
            }
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
