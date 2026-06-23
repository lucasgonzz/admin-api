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

        // Sincroniza desde GitHub identidad, system prompt y protocolo de WhatsApp a la BD.
        // Red de seguridad por si Lucas olvida apretar el botón manual del admin.
        $schedule->command('agent-prompts:sync')->everyTenMinutes();

        // Envía recordatorio de mañana de demo por WhatsApp el día de la demo (hora configurable).
        $schedule->command('leads:send-morning-demo-reminder')->everyFiveMinutes();

        // Genera recordatorios pre-demo para leads con demo en los próximos X minutos (configurable).
        // Cada 5 minutos garantiza que ninguna demo se pierda dentro de la ventana.
        $schedule->command('leads:send-demo-reminders')->everyFiveMinutes();

        // Corre demo setup automático X minutos antes del inicio de cada demo.
        $schedule->command('leads:run-demo-setup')->everyMinute();

        // Envía check de ingreso X minutos después del inicio de la demo.
        $schedule->command('leads:check-demo-ingress')->everyMinute();

        // Genera resumen del lead con Claude X minutos antes del fin de la demo.
        $schedule->command('leads:generate-demo-summary')->everyMinute();

        // Envía pregunta de fin de demo al lead en demo_en_curso (al cumplirse la duración).
        $schedule->command('leads:check-demo-fin')->everyMinute();

        // Pasa a demo_pendiente_de_ingreso si el lead no confirmó el ingreso en el timeout configurado.
        $schedule->command('leads:check-demo-ingreso-timeout')->everyMinute();

        // Envía seguimiento único de fin si el lead no confirmó que terminó (demo_fin_seguimiento_minutos).
        $schedule->command('leads:check-demo-fin-seguimiento')->everyMinute();

        // Pasa a demo_pendiente_de_terminar si el lead no confirmó el fin en el timeout configurado.
        $schedule->command('leads:check-demo-fin-timeout')->everyMinute();

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
