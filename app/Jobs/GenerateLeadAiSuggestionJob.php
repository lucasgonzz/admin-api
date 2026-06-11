<?php

namespace App\Jobs;

use App\Events\LeadAiSuggestionFinished;
use App\Events\LeadAiSuggestionGenerating;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadAiService;
use App\Services\LeadAiSuggestionScheduler;
use App\Services\LeadConversationAiState;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Genera sugerencia de Claude tras la demora configurada, solo si no hubo nuevos mensajes del lead (debounce).
 */
class GenerateLeadAiSuggestionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int ID del lead.
     */
    private $lead_id;

    /**
     * @var int Token de programación; debe coincidir con caché al ejecutar.
     */
    private $schedule_token;

    /**
     * @param int $lead_id
     * @param int $schedule_token
     */
    public function __construct(int $lead_id, int $schedule_token)
    {
        $this->lead_id = $lead_id;
        $this->schedule_token = $schedule_token;
    }

    /**
     * Llama a Claude si el token sigue vigente; descarta el resultado si hubo mensajes nuevos durante la API.
     *
     * @param LeadAiService              $lead_ai_service
     * @param LeadAiSuggestionScheduler  $scheduler
     *
     * @return void
     */
    public function handle(LeadAiService $lead_ai_service, LeadAiSuggestionScheduler $scheduler): void
    {
        if (! $scheduler->is_schedule_token_current($this->lead_id, $this->schedule_token)) {
            Log::channel('daily')->debug('GenerateLeadAiSuggestionJob: omitido (token de debounce obsoleto).', [
                'lead_id'         => $this->lead_id,
                'schedule_token'  => $this->schedule_token,
            ]);

            return;
        }

        $lead = Lead::query()->with('messages')->find($this->lead_id);
        if ($lead === null) {
            Log::channel('daily')->warning('GenerateLeadAiSuggestionJob: lead no encontrado.', [
                'lead_id' => $this->lead_id,
            ]);

            return;
        }

        $lead_inbound_count = LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('sender', 'lead')
            ->count();

        if ($lead_inbound_count <= 1) {
            return;
        }

        if (! LeadConversationAiState::has_unanswered_lead_messages($lead)) {
            Log::channel('daily')->debug('GenerateLeadAiSuggestionJob: omitido (sin mensajes del lead sin responder).', [
                'lead_id' => $this->lead_id,
            ]);

            return;
        }

        if (LeadConversationAiState::has_pending_non_followup_suggestion($lead)) {
            Log::channel('daily')->debug('GenerateLeadAiSuggestionJob: omitido (sugerencia pendiente de revisión).', [
                'lead_id' => $this->lead_id,
            ]);

            return;
        }

        event(new LeadAiSuggestionGenerating($this->lead_id));

        try {
            $suggestion_message = $lead_ai_service->generate_suggestion($lead, false);

            if (! $scheduler->is_schedule_token_current($this->lead_id, $this->schedule_token)) {
                $scheduler->discard_obsolete_suggestion($suggestion_message);
            }
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('GenerateLeadAiSuggestionJob: error al generar sugerencia IA.', [
                'lead_id' => $this->lead_id,
                'error'   => $exception->getMessage(),
            ]);
        } finally {
            event(new LeadAiSuggestionFinished($this->lead_id));
        }
    }
}
