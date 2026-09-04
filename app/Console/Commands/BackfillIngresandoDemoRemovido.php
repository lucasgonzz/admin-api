<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadPipelineStatus;
use Illuminate\Console\Command;

/**
 * Comando de un solo uso: mueve los leads que quedaron en `ingresando_demo` (estado retirado del
 * catálogo en la misión demo-v2-estados-automaticos, 4/9/2026) a un estado real, y borra la fila
 * del catálogo.
 *
 * NO se programa en Kernel.php. Lucas lo corre una sola vez en producción después de desplegar
 * esta misión: puede haber leads reales, en el momento del deploy, con `status = 'ingresando_demo'`
 * en la base de producción, y sacar el estado del catálogo sin moverlos los deja en un estado que
 * ya no existe en ningún lado del código nuevo.
 */
class BackfillIngresandoDemoRemovido extends Command
{
    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'leads:backfill-ingresando-demo-removido';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Uso único: mueve los leads en ingresando_demo (estado retirado) a demo_en_curso o demo_pendiente_de_ingreso, y borra la fila del catálogo.';

    /**
     * Mueve los leads en `ingresando_demo` a `demo_en_curso` (si ya habían confirmado el ingreso)
     * o a `demo_pendiente_de_ingreso` (si no), y borra la fila del catálogo. Idempotente: correrlo
     * de nuevo con el catálogo ya limpio y sin leads en ese estado no hace nada.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        $leads = Lead::query()->where('status', 'ingresando_demo')->get();

        $a_demo_en_curso = 0;
        $a_pendiente     = 0;

        foreach ($leads as $lead) {
            if ($lead->demo_ingreso_confirmado) {
                $lead->update(['status' => 'demo_en_curso']);
                $a_demo_en_curso++;
            } else {
                $lead->update(['status' => 'demo_pendiente_de_ingreso']);
                $a_pendiente++;
            }
        }

        // Sin efectos secundarios (Google Calendar, notificaciones a admins): es un backfill de
        // datos viejos, no un evento nuevo, y correrlo sobre leads posiblemente ya fríos no debe
        // spamear WhatsApp ni tocar calendarios reales.
        LeadPipelineStatus::query()->where('slug', 'ingresando_demo')->delete();

        $this->info("Leads movidos a demo_en_curso: {$a_demo_en_curso}");
        $this->info("Leads movidos a demo_pendiente_de_ingreso: {$a_pendiente}");
        $this->info('Fila de catálogo ingresando_demo eliminada (si existía).');

        return 0;
    }
}
