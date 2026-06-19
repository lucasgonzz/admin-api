<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\LeadDemoSettings;
use App\Services\RunDemoSetupService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Corre automáticamente el demo setup para leads cuya demo arranca pronto.
 *
 * Se ejecuta cada minuto. Busca leads en estado `demo_agendada` con
 * `demo_setup_status = pendiente` cuya demo comience dentro de los
 * próximos X minutos (configurado en LeadDemoSettings::get_setup_minutos_antes()).
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
        /* Minutos antes del inicio para correr el setup según configuración. */
        $setup_minutos = LeadDemoSettings::get_setup_minutos_antes();

        /* Momento actual en timezone Argentina. */
        $now = Carbon::now('America/Argentina/Buenos_Aires');

        /* Límite superior de la ventana: inicio de demo debe estar dentro de los próximos X minutos. */
        $window_end = $now->copy()->addMinutes($setup_minutos);

        /* Buscar leads con demo agendada, setup pendiente y demo en la ventana de tiempo. */
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
            /* Construir el datetime completo de inicio de demo en timezone Argentina. */
            $demo_datetime = $this->parse_demo_datetime(
                $lead->demo_date->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d'),
                (string) $lead->demo_start_time
            );

            /* Si el formato de hora es inválido, saltear sin romper el batch. */
            if ($demo_datetime === null) {
                Log::warning('RunDemoSetup: no se pudo parsear demo_start_time', [
                    'lead_id'         => $lead->id,
                    'demo_start_time' => $lead->demo_start_time,
                ]);
                continue;
            }

            /* Solo ejecutar si la demo está dentro de la ventana [ahora, ahora + setup_minutos]. */
            if ($demo_datetime->lt($now) || $demo_datetime->gt($window_end)) {
                continue;
            }

            Log::info('RunDemoSetup: ejecutando setup automático', [
                'lead_id'       => $lead->id,
                'contact_name'  => $lead->contact_name,
                'demo_datetime' => $demo_datetime->toDateTimeString(),
            ]);

            /* Delegar al servicio existente que ya maneja HTTP, retries y estados. */
            $service->run($lead);

            $executed++;
        }

        $this->info("Demo setups ejecutados: {$executed}");

        return 0;
    }

    /**
     * Parsea el datetime de inicio de demo a partir de fecha (Y-m-d) y hora (H:i o similar).
     *
     * Devuelve null si el formato no es válido para evitar errores en el batch.
     *
     * @param string $date Fecha en formato Y-m-d.
     * @param string $time Hora en texto libre (ej: "09:00" o "9:30").
     *
     * @return Carbon|null
     */
    protected function parse_demo_datetime(string $date, string $time): ?Carbon
    {
        try {
            return Carbon::parse("{$date} {$time}");
        } catch (\Exception $e) {
            return null;
        }
    }
}
