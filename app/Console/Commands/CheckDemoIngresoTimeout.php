<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\DemoCicloAdminNotificationService;
use App\Services\LeadBroadcastService;
use App\Services\LeadDemoSettings;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Marca como `demo_pendiente_de_ingreso` a los leads que no confirmaron su ingreso
 * dentro del tiempo límite configurado.
 *
 * Se ejecuta cada minuto. Busca leads en estado `ingresando_demo` que ya recibieron
 * el check de ingreso pero no confirmaron (`demo_ingreso_confirmado = false`) y
 * superaron el timeout de espera (`demo_ingreso_timeout_minutos` desde el inicio).
 *
 * No envía mensaje al lead (Claude ya está gestionando la conversación).
 * Solo cambia el estado para que el equipo lo vea y el comando 097 notifique a admins.
 */
class CheckDemoIngresoTimeout extends Command
{
    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'leads:check-demo-ingreso-timeout';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Pasa a demo_pendiente_de_ingreso los leads que no confirmaron el ingreso en el tiempo límite';

    /**
     * Procesa los leads con timeout de ingreso superado.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        /* Minutos de espera desde el inicio antes de considerar el ingreso fallido. */
        $timeout_minutos = LeadDemoSettings::get_ingreso_timeout_minutos();

        /* Momento actual en timezone Argentina. */
        $now = Carbon::now('America/Argentina/Buenos_Aires');

        /*
         * Límite temporal: el timeout empieza a contar desde el inicio de la demo (demo_datetime).
         * Un lead cuya demo_datetime es anterior a (now - timeout_minutos) ya superó el plazo.
         */
        $limite = $now->copy()->subMinutes($timeout_minutos);

        /* Buscar leads en ingresando_demo que no confirmaron y aún no fueron notificados. */
        $candidates = Lead::query()
            ->where('status', 'ingresando_demo')
            ->where('demo_check_ingreso_enviado', true)
            ->where('demo_ingreso_confirmado', false)
            ->where('demo_no_ingreso_notificado', false)
            ->whereNotNull('demo_date')
            ->whereNotNull('demo_start_time')
            ->get();

        /* Contador de leads procesados para el log final. */
        $processed = 0;

        foreach ($candidates as $lead) {
            /* Construir datetime de inicio de demo en timezone Argentina. */
            $demo_datetime = $this->parse_demo_datetime(
                $lead->demo_date->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d'),
                (string) $lead->demo_start_time
            );

            if ($demo_datetime === null) {
                continue;
            }

            /*
             * Verificar que ya pasó el timeout desde el inicio.
             * Si demo_datetime + timeout_minutos < now, el plazo venció.
             */
            if ($demo_datetime->gt($limite)) {
                continue;
            }

            /*
             * Pasar el lead a demo_pendiente_de_ingreso y marcar el flag anti-duplicado.
             * La notificación a admins se dispara inmediatamente después del update.
             */
            $lead->update([
                'status'                     => 'demo_pendiente_de_ingreso',
                'demo_no_ingreso_notificado' => true,
            ]);

            /* Notificar a admins suscritos vía WhatsApp que el lead no ingresó por timeout. */
            try {
                $ciclo_service = new DemoCicloAdminNotificationService(new WhatsappSendService());
                $ciclo_service->notify_no_ingreso($lead->fresh(), 'no respondió al check de ingreso');
            } catch (\Throwable $e) {
                Log::error('CheckDemoIngresoTimeout: error al notificar no_ingreso a admins.', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);
            }

            /* Notificar a admin-spa vía socket. */
            LeadBroadcastService::emit_conversation_updated((int) $lead->id);

            Log::info('CheckDemoIngresoTimeout: lead pasó a demo_pendiente_de_ingreso por timeout', [
                'lead_id'        => $lead->id,
                'contact_name'   => $lead->contact_name,
                'demo_datetime'  => $demo_datetime->toDateTimeString(),
                'timeout_minutos' => $timeout_minutos,
            ]);

            $processed++;
        }

        $this->info("Timeouts de ingreso procesados: {$processed}");

        return 0;
    }

    /**
     * Parsea el datetime de inicio de demo a partir de fecha (Y-m-d) y hora (H:i o similar).
     *
     * @param string $date Fecha en formato Y-m-d.
     * @param string $time Hora en texto libre.
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
