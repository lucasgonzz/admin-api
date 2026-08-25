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
        // Una vez por día alcanza: un cliente se queda sin teléfono cargado una vez, no cada cinco minutos.
        $schedule->command('support:check-clients-without-phone')->dailyAt('09:00');
        $schedule->command('leads:check-followups')->everyTwoHours();

        // Sincroniza desde GitHub identidad, system prompt y protocolo de WhatsApp a la BD.
        // Red de seguridad por si Lucas olvida apretar el botón manual del admin.
        $schedule->command('agent-prompts:sync')->everyTenMinutes();

        // Envía recordatorio de mañana de demo por WhatsApp el día de la demo (hora configurable).
        $schedule->command('leads:send-morning-demo-reminder')->everyFiveMinutes();

        // Genera recordatorios pre-demo para leads con demo en los próximos X minutos (configurable).
        // Cada 5 minutos garantiza que ninguna demo se pierda dentro de la ventana.
        $schedule->command('leads:send-demo-reminders')->everyFiveMinutes();

        /* Corre demo setup automático X minutos antes del inicio de cada demo.
         *
         * 🔴 `runInBackground()` desde la misión cruzada del 25/8/2026, y no es cosmético. Este
         * comando llama a `RunDemoSetupService::run()` INLINE (`RunDemoSetup.php:161`), no despacha
         * el job — verificado, no supuesto: `RunDemoSetupJob` sólo lo despacha el POST del
         * formulario inmersivo. Y `$schedule->command()` corre el proceso sincrónicamente adentro
         * de `schedule:run`, así que mientras el setup está en vuelo esa invocación del scheduler
         * queda clavada y NO ejecuta ninguno de los ~20 comandos que vienen después.
         *
         * Hasta hoy eso podía durar 300 s. Al subir el techo del service a 900 lo triplicamos
         * nosotros, y entre los comandos que quedaban sin correr está
         * `leads:vencer-demo-setups-colgados` — o sea, justo la red que atrapa un setup colgado.
         * El arreglo se comía a sí mismo. Es el mismo razonamiento, y el mismo daño, que ya está
         * escrito abajo para `queue:work` a raíz de `RunDeploymentJob` en la misión 61.
         *
         * 🔴 Y NO `withoutOverlapping()`, a pesar de que la corrida en segundo plano puede apilar
         * hasta quince procesos. Serializar acá es peor que apilar: un tick que tarda quince
         * minutos dejaría sin disparar el setup de TODOS los demás leads durante esa ventana, y el
         * turno de un lead no espera —la ventana de preparación se mide en minutos—. El apilamiento
         * es seguro por otro lado: el claim atómico de `run($lead, true)` hace que dos procesos
         * concurrentes no puedan tomar el mismo lead, que es la única carrera que importa. Y sin
         * `runInBackground()` los procesos se apilaban IGUAL (un `schedule:run` clavado por tick);
         * la diferencia es que además se llevaban puesto el resto del scheduler. */
        $schedule->command('leads:run-demo-setup')->everyMinute()->runInBackground();

        // Saca del limbo los setups que quedaron en `ejecutandose` o en `sin_confirmar` y nunca
        // reportaron (misión 60; `sin_confirmar` desde la misión cruzada del 25/8/2026 — un estado
        // intermedio sin nadie que lo destrabe es una fuga, no un estado).
        // Cada minuto y no cada cinco: el turno del lead dura una hora y el reintento único que
        // habilita este vencimiento tiene que llegar a tiempo para servirle de algo.
        $schedule->command('leads:vencer-demo-setups-colgados')->everyMinute();

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

        // Revierte a calificado los leads en demo_pendiente_de_ingreso que superaron el timeout de horas configurado.
        $schedule->command('leads:check-demo-pendiente-ingreso-timeout')->hourly();

        // Pasa a closer_activo los leads en demo_pendiente_de_terminar que superaron el timeout desde el fin de la demo.
        $schedule->command('leads:check-demo-pendiente-terminar-timeout')->everyMinute();

        // Destraba los deployments que quedaron en `running` sin reportar actividad. Es el
        // equivalente de `leads:vencer-demo-setups-colgados` para la máquina de estados del
        // deployment: sin esto, `running` es un estado del que no se sale — lo rechazan las dos
        // puertas (el panel y `claude/*`) y había que tocar la base a mano.
        // Cada cinco minutos y no cada minuto, a diferencia del molde: acá no hay ningún reintento
        // que tenga que llegar a tiempo, y el umbral es de 45 minutos.
        $schedule->command('deployments:vencer-colgados')->everyFiveMinutes();

        // Genera el reporte diario del agente a la hora configurada en admin_settings.
        // La hora se lee cada vez que corre el scheduler; si cambió desde la última corrida, usa la nueva.
        $report_hour = (int) \App\Models\AdminSetting::get('agent_report_hour', 8);
        $schedule->command('agent:generate-daily-report')
            ->dailyAt("{$report_hour}:00")
            ->withoutOverlapping();

        /* 🔴 `database` explícito, y no `queue:work` a secas (misión 60). Sin el nombre de la
         * conexión, el worker toma la default, que es `env('QUEUE_CONNECTION', 'sync')` — o sea
         * `sync`, que no tiene backend que consumir: el comando corría cada minuto y **no miraba la
         * tabla `jobs`**. Medido el 14/8/2026: un job encolado en `database` sobrevive intacto a
         * `queue:work --stop-when-empty` y lo procesa recién `queue:work database`.
         *
         * No es teórico ni nuevo: en la base de producción había tres `RunDemoSetupJob` encolados
         * con `attempts = 0`, es decir jamás intentados. Alguien ya encolaba a `database` y nadie
         * los consumía. */
        /* 🔴 `runInBackground()` desde la misión 61, y no es cosmético. `$schedule->command()` corre
         * el proceso SINCRÓNICAMENTE adentro de `schedule:run`: mientras el worker está ocupado, esa
         * invocación del scheduler queda clavada y NO ejecuta ninguno de los ~20 comandos que vienen
         * antes ni después. Hasta la misión 61 el job más largo de esta conexión era
         * `RunDemoSetupJob` (600 s) y se toleraba; con `RunDeploymentJob` (1800 s) serían treinta
         * minutos de scheduler parado por cada deployment, y se apilarían hasta treinta procesos
         * `schedule:run` vivos. En shared hosting eso toca el límite de procesos concurrentes y lo
         * que se cae es el scheduler ENTERO — incluido `deployments:vencer-colgados`, que es
         * justamente la red que atrapa un deployment colgado. El arreglo se comía a sí mismo.
         *
         * Y NO `withoutOverlapping()`: eso serializaría toda la conexión, y un deployment de treinta
         * minutos dejaría sin worker a los `RunDemoSetupJob`, que sí tienen un turno que cumplir.
         * Es exactamente el daño que costó tres demos mudas. */
        $schedule->command('queue:work database --stop-when-empty')
            ->everyMinute()
            ->runInBackground();
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
