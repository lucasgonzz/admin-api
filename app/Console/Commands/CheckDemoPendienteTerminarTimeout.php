<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\LeadBroadcastService;
use App\Services\LeadDemoSettings;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Pasa a `closer_activo` los leads en `demo_pendiente_de_terminar` que superaron
 * el timeout configurado desde el final de la demo.
 *
 * Lógica: si el lead estuvo en demo_en_curso hasta el final pero nunca confirmó
 * que terminó, asumimos que hizo la demo y lo enviamos al closer.
 *
 * Referencia temporal: demo_datetime + duración (momento en que terminó la demo).
 * Trigger: esa referencia + pendiente_terminar_timeout_minutos <= now.
 */
class CheckDemoPendienteTerminarTimeout extends Command
{
    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'leads:check-demo-pendiente-terminar-timeout';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Pasa a closer_activo los leads en demo_pendiente_de_terminar que superaron el timeout desde el fin de la demo';

    /**
     * Procesa leads varados en demo_pendiente_de_terminar y los envía a closer_activo.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        /* Duración de la demo y timeout configurable desde el fin de la misma. */
        $duracion_minutos = LeadDemoSettings::get_duracion_minutos();
        $timeout_minutos  = LeadDemoSettings::get_pendiente_terminar_timeout_minutos();
        $now              = Carbon::now('America/Argentina/Buenos_Aires');

        /* Candidatos: leads en demo_pendiente_de_terminar con fecha y hora de demo cargadas. */
        $candidates = Lead::query()
            ->where('status', 'demo_pendiente_de_terminar')
            ->whereNotNull('demo_date')
            ->whereNotNull('demo_start_time')
            ->get();

        $processed = 0;

        foreach ($candidates as $lead) {
            $date_str = $lead->demo_date
                ? $lead->demo_date->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d')
                : null;

            if ($date_str === null) {
                continue;
            }

            $demo_datetime = null;
            try {
                $demo_datetime = Carbon::parse($date_str . ' ' . (string) $lead->demo_start_time);
            } catch (\Exception $e) {
                continue;
            }

            /* La demo terminó en demo_datetime + duración. */
            $fin_demo = $demo_datetime->copy()->addMinutes($duracion_minutos);

            /* El trigger vence en fin_demo + timeout; si aún no venció, no procesar. */
            if ($fin_demo->copy()->addMinutes($timeout_minutos)->gt($now)) {
                continue;
            }

            $lead->update(['status' => 'closer_activo']);

            LeadBroadcastService::emit_conversation_updated((int) $lead->id);

            Log::info('CheckDemoPendienteTerminarTimeout: lead pasó a closer_activo automáticamente.', [
                'lead_id'         => $lead->id,
                'contact_name'    => $lead->contact_name,
                'fin_demo'        => $fin_demo->toDateTimeString(),
                'timeout_minutos' => $timeout_minutos,
            ]);

            $processed++;
        }

        $this->info("Leads enviados a closer_activo: {$processed}");

        return 0;
    }
}
