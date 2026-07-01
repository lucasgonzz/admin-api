<?php

namespace App\Jobs;

use App\Models\LeadMessage;
use App\Services\LeadAiSuggestionAutoSendScheduler;
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
     * @param int $message_id
     * @param int $auto_send_token
     */
    public function __construct(int $message_id, int $auto_send_token)
    {
        $this->message_id = $message_id;
        $this->auto_send_token = $auto_send_token;
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

        if ($message->requiere_verificacion) {
            return;
        }

        try {
            $updated = $send_service->send_suggestion($message);

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
