<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Reintenta cada 5 minutos los mensajes de soporte no sincronizados a clientes.
        $schedule->command('support:retry-pending-syncs')->everyFiveMinutes();
        $schedule->command('support:check-response-alerts')->everyFiveMinutes();
        $schedule->command('leads:check-followups')->everyTwoHours();

        // Genera recordatorios pre-demo para leads con demo en los próximos 15 minutos.
        // Cada 5 minutos garantiza que ninguna demo se pierda dentro de la ventana.
        $schedule->command('leads:send-demo-reminders')->everyFiveMinutes();

        $schedule->command('queue:work --stop-when-empty')->everyMinute();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
