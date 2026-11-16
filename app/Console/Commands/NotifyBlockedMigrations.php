<?php
namespace App\Console\Commands;
use App\Services\SupportMigrationBlockedNoticeService;
use Illuminate\Console\Command;
class NotifyBlockedMigrations extends Command
{
    protected $signature = 'support:notify-blocked-migrations';
    protected $description = 'Avisa a Lucas las migraciones que Martín dejó pendientes de revisión';
    public function handle(SupportMigrationBlockedNoticeService $service): int
    {
        $service->conciliar();
        return 0;
    }
}
