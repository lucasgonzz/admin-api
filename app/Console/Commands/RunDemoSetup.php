<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Helpers\AppTime;
use App\Services\RunDemoSetupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Corre automáticamente el demo setup para leads cuya demo arranca pronto.
 *
 * Se ejecuta cada minuto sobre los leads en `demo_agendada` con `demo_setup_status = pendiente` y
 * demo agendada para hoy. Quién dispara y quién no lo decide
 * {@see RunDemoSetupService::evaluar_disparo()}, que desde la misión 46 comparte esa regla con el
 * POST del formulario de la página inmersiva.
 *
 * Para la dinámica ACTUAL el criterio sigue siendo el de siempre: la demo tiene que empezar dentro
 * de los próximos `LeadDemoSettings::get_setup_minutos_antes()` minutos.
 */
class RunDemoSetup extends Command
{
    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'leads:run-demo-setup';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Corre automáticamente el demo setup para leads cuya demo arranca pronto';

    /**
     * Procesa todos los leads candidatos y dispara el demo setup.
     *
     * @param RunDemoSetupService $service Servicio que ejecuta el setup remoto.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(RunDemoSetupService $service): int
    {
        /* Momento actual en timezone Argentina. */
        $now = AppTime::now();

        /* Buscar leads con demo agendada, setup pendiente y demo del día de hoy. La decisión de
         * disparar o no ya no se toma acá: vive en RunDemoSetupService::evaluar_disparo(), porque
         * la comparte con el POST del formulario de la página inmersiva (misión 46, pieza 2). Ahí
         * está el comentario completo de la regla y de por qué desapareció el corte por hora de
         * inicio para la dinámica nueva. */
        $candidates = Lead::query()
            ->where('status', 'demo_agendada')
            ->where('demo_setup_status', 'pendiente')
            ->whereNotNull('demo_date')
            ->whereNotNull('demo_start_time')
            ->whereDate('demo_date', $now->format('Y-m-d'))
            ->get();

        /* Contador de setups ejecutados para el log final. */
        $executed = 0;

        foreach ($candidates as $lead) {
            $evaluacion = $service->evaluar_disparo($lead, $now);

            /* Si el formato de hora es inválido, saltear sin romper el batch. */
            if ($evaluacion['motivo'] === 'sin_hora_de_inicio') {
                Log::warning('RunDemoSetup: no se pudo parsear demo_start_time', [
                    'lead_id'         => $lead->id,
                    'demo_start_time' => $lead->demo_start_time,
                ]);
                continue;
            }

            if (! $evaluacion['disparar']) {
                continue;
            }

            Log::info('RunDemoSetup: ejecutando setup automático', [
                'lead_id'       => $lead->id,
                'contact_name'  => $lead->contact_name,
                'demo_datetime' => $evaluacion['inicio']->toDateTimeString(),
                'motivo'        => $evaluacion['motivo'],
            ]);

            /* Delegar al servicio existente que ya maneja HTTP, retries y estados. El `true` es el
             * claim atómico: desde la misión 46 el POST del formulario también dispara, así que el
             * comando tiene que poder llegar tarde y no hacer nada en vez de correr el setup dos
             * veces sobre la misma instancia. */
            $service->run($lead, true);

            $executed++;
        }

        $this->info("Demo setups ejecutados: {$executed}");

        return 0;
    }
}
