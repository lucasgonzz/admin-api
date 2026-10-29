<?php

namespace App\Observers;

use App\Models\SupportCodexCase;
use App\Models\SupportTicket;
use App\Services\SupportAttentionNotificationService;
use Illuminate\Support\Facades\Log;

/** Observa los dos ejes: el ticket y la atención de Codex no se guardan siempre juntos. */
class SupportAttentionObserver
{
    public function saved($model): void
    {
        $fields = $model instanceof SupportTicket
            ? ['status', 'escalated_at', 'escalation_reason', 'assigned_admin_id']
            : ['state', 'review_reason', 'mission', 'mission_thread_id', 'mission_started_at'];
        if (! $model->wasRecentlyCreated && ! $model->wasChanged($fields)) {
            return;
        }
        $id = $model instanceof SupportCodexCase ? $model->support_ticket_id : $model->id;
        try {
            app(SupportAttentionNotificationService::class)->registrar((int) $id);
        } catch (\Throwable $exception) {
            // Una caída de la cola nunca revierte un WhatsApp ya aceptado ni pierde la escalación.
            Log::channel('daily')->error('Soporte: no se pudo registrar el aviso push.', [
                'ticket_id' => $id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
