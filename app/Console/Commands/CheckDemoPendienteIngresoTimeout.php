<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadPipelineStatus;
use App\Services\LeadBroadcastService;
use App\Services\LeadDemoSettings;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Revierte a `calificado` los leads en estado `demo_pendiente_de_ingreso`
 * que llevan más de X horas (configurable) sin confirmar el ingreso a la demo.
 *
 * Se ejecuta cada hora. Permite que la instancia 4 de seguimiento retome
 * el contacto con el lead para reagendar.
 *
 * El threshold es `demo_pendiente_ingreso_horas_timeout` en admin_settings.
 */
class CheckDemoPendienteIngresoTimeout extends Command
{
    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'leads:check-demo-pendiente-ingreso-timeout';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Revierte a calificado los leads en demo_pendiente_de_ingreso que superaron el timeout de horas configurado';

    /**
     * Procesa leads varados en demo_pendiente_de_ingreso y los revierte a calificado.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        /* Horas de espera desde el inicio de la demo antes de revertir a calificado. */
        $horas_timeout = LeadDemoSettings::get_pendiente_ingreso_horas_timeout();

        /* Momento actual y límite: demos cuyo inicio fue antes de este instante se revierten. */
        $now    = Carbon::now('America/Argentina/Buenos_Aires');
        $limite = $now->copy()->subHours($horas_timeout);

        /*
         * Buscar leads en demo_pendiente_de_ingreso cuya demo_date + demo_start_time
         * sea anterior al límite calculado.
         * Solo procesar leads con fecha y hora de demo cargadas.
         */
        $candidates = Lead::query()
            ->where('status', 'demo_pendiente_de_ingreso')
            ->whereNotNull('demo_date')
            ->whereNotNull('demo_start_time')
            ->get();

        $pipeline_status = LeadPipelineStatus::ensure_exists('calificado');
        $processed       = 0;

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

            /* Si la demo empezó hace menos de X horas, aún no corresponde revertir. */
            if ($demo_datetime === null || $demo_datetime->gt($limite)) {
                continue;
            }

            $lead->update(['status' => $pipeline_status->slug]);

            LeadBroadcastService::emit_conversation_updated((int) $lead->id);

            Log::info('CheckDemoPendienteIngresoTimeout: lead revertido a calificado.', [
                'lead_id'       => $lead->id,
                'contact_name'  => $lead->contact_name,
                'demo_datetime' => $demo_datetime->toDateTimeString(),
                'horas_timeout' => $horas_timeout,
            ]);

            $processed++;
        }

        $this->info("Leads revertidos a calificado: {$processed}");

        return 0;
    }
}
