<?php

namespace App\Console\Commands;

use App\Services\LeadFollowupService;
use Illuminate\Console\Command;

/**
 * Evalúa leads activos y dispara sugerencias IA o pausa según {@see LeadFollowupService}.
 */
class CheckLeadFollowups extends Command
{
    /**
     * @var string
     */
    protected $signature = 'leads:check-followups';

    /**
     * @var string
     */
    protected $description = 'Procesa seguimientos automáticos de leads (reglas + Claude)';

    /**
     * @param LeadFollowupService $followup_service
     *
     * @return int
     */
    public function handle(LeadFollowupService $followup_service)
    {
        $stats = $followup_service->process_all_active_leads();
        $this->info('Leads procesados: '.$stats['processed']);
        $this->info('Sugerencias generadas: '.$stats['suggestions']);
        $this->info('Leads pausados: '.$stats['paused']);
        $this->info('Errores: '.$stats['errors']);

        return 0;
    }
}
