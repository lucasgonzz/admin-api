<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\CommandBuilder;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Los comandos del scheduler que corren en segundo plano no pueden heredar el candado del cron
 * (misión demo-seguimiento-y-setup-rapido, 9/9/2026).
 *
 * El cron de Hostinger lanza `flock -n /tmp/schedule-admin.lock php artisan schedule:run`; el
 * descriptor del candado (el 3) lo heredaba el `queue:work` que `runInBackground()` dejaba
 * corriendo, y mientras ese worker vivía el scheduler entero dejaba de correr. Ver
 * {@see \App\Console\Kernel::artisan_sin_el_lock_del_cron()}.
 */
class SchedulerSinElLockDelCronTest extends TestCase
{
    /**
     * Los eventos del scheduler cuyo comando contiene el texto dado.
     *
     * @param string $texto
     *
     * @return array<int, \Illuminate\Console\Scheduling\Event>
     */
    private function eventos_con(string $texto): array
    {
        $schedule = $this->app->make(Schedule::class);

        return array_values(array_filter($schedule->events(), function ($evento) use ($texto) {
            return strpos((string) $evento->command, $texto) !== false;
        }));
    }

    public function test_el_worker_de_cola_corre_en_segundo_plano_y_cierra_el_descriptor_del_candado(): void
    {
        $eventos = $this->eventos_con('queue:work database --stop-when-empty');

        $this->assertCount(1, $eventos, 'Tiene que haber exactamente un worker de cola programado.');

        $evento = $eventos[0];

        $this->assertTrue($evento->runInBackground, 'El worker tiene que seguir corriendo en segundo plano: en primer plano clava el scheduler.');
        $this->assertStringStartsWith('exec 3>&- 4>&- 5>&- 6>&- 7>&- 8>&- 9>&-; ', $evento->command);
        $this->assertStringContainsString(escapeshellarg(base_path('artisan')) . ' queue:work database --stop-when-empty', $evento->command);
    }

    public function test_el_disparador_del_demo_setup_tambien_cierra_el_descriptor_del_candado(): void
    {
        $eventos = $this->eventos_con('leads:run-demo-setup');

        $this->assertCount(1, $eventos);
        $this->assertTrue($eventos[0]->runInBackground);
        $this->assertStringStartsWith('exec 3>&- 4>&- 5>&- 6>&- 7>&- 8>&- 9>&-; ', $eventos[0]->command);
    }

    /**
     * La línea que el scheduler termina ejecutando: el cierre de descriptores queda ADENTRO del
     * subshell que arma `runInBackground()`, antes del binario de PHP, y el `schedule:finish` del
     * final sigue estando. Si alguien vuelve a `command()`, esta aserción es la que lo dice.
     */
    public function test_la_linea_de_shell_cierra_los_descriptores_adentro_del_subshell(): void
    {
        $evento = $this->eventos_con('queue:work database --stop-when-empty')[0];

        $linea = (new CommandBuilder())->buildCommand($evento);

        /* En Windows (donde corren los slots) el builder envuelve todo en `start /b cmd /c "(...)"`;
         * en producción es `sh` y la línea arranca con el paréntesis. Lo que importa en los dos
         * casos es lo mismo: el cierre de descriptores es lo PRIMERO adentro del subshell. */
        $this->assertStringContainsString('(exec 3>&- 4>&- 5>&- 6>&- 7>&- 8>&- 9>&-; ', $linea);
        $this->assertStringContainsString('queue:work database --stop-when-empty', $linea);
        $this->assertStringContainsString('schedule:finish', $linea);

        if (! windows_os()) {
            $this->assertStringStartsWith('(exec 3>&- ', $linea);
            $this->assertStringEndsWith('&', trim($linea));
        }
    }
}
