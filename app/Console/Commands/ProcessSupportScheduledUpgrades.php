<?php

namespace App\Console\Commands;

use App\Services\SupportScheduledUpgradeService;
use Illuminate\Console\Command;

class ProcessSupportScheduledUpgrades extends Command
{
    protected $signature = 'support:process-scheduled-upgrades';
    protected $description = 'Procesa actualizaciones acordadas en soporte.';

    public function handle(SupportScheduledUpgradeService $service): int
    {
        $service->tick();
        return 0;
    }
}
