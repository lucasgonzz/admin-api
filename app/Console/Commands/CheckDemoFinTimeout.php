<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\LeadBroadcastService;
use App\Services\LeadDemoSettings;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Marca como `demo_pendiente_de_terminar` a los leads que no confirmaron
 * la finalización de la demo dentro del tiempo límite.
 *
 * Se ejecuta cada minuto. Busca leads en `demo_en_curso` que ya recibieron el
 * check de fin (`demo_fin_check_enviado = true`) pero no confirmaron que terminaron
 * (`demo_terminada_confirmada = false`) y aún no fueron marcados con timeout
 * (`demo_pendiente_terminar_notificado = false`).
 *
 * La referencia temporal es `demo_datetime + duración` (momento del check de fin).
 * Si desde ese punto pasaron más de `fin_timeout_minutos`, el lead pasa al estado
 * `demo_pendiente_de_terminar` para visibilidad del equipo.
 *
 * El lead queda reactivable: si más adelante confirma que terminó, la acción
 * `confirmar_fin_demo` de Claude (prompt 095) lo mueve a `demo_realizada`.
 * No se envía ningún mensaje al lead; el prompt 097 notificará a los admins.
 */
class CheckDemoFinTimeout extends Command
{
    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'leads:check-demo-fin-timeout';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Pasa a demo_pendiente_de_terminar los leads que no confirmaron el fin en el tiempo límite';

    /**
     * Procesa los leads con timeout de fin superado.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        /* Duración estimada de la demo en minutos según configuración. */
        $duracion_minutos = LeadDemoSettings::get_duracion_minutos();

        /* Minutos desde el check de fin antes de marcar demo_pendiente_de_terminar. */
        $timeout_minutos = LeadDemoSettings::get_fin_timeout_minutos();

        /* Momento actual en timezone Argentina. */
        $now = Carbon::now('America/Argentina/Buenos_Aires');

        /* Buscar leads en demo_en_curso con check de fin enviado, sin confirmación y sin timeout previo. */
        $candidates = Lead::query()
            ->where('status', 'demo_en_curso')
            ->where('demo_fin_check_enviado', true)
            ->where('demo_terminada_confirmada', false)
            ->where('demo_pendiente_terminar_notificado', false)
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
             * Referencia: check de fin se mandó en demo_datetime + duracion.
             * El timeout se dispara cuando ese punto + timeout_minutos <= now.
             */
            $check_fin_datetime = $demo_datetime->copy()->addMinutes($duracion_minutos);
            $trigger_timeout    = $check_fin_datetime->copy()->addMinutes($timeout_minutos);

            if ($trigger_timeout->gt($now)) {
                continue;
            }

            /*
             * Pasar el lead a demo_pendiente_de_terminar y marcar el flag anti-duplicado.
             * El prompt 097 escucha este cambio de estado para notificar a los admins.
             */
            $lead->update([
                'status'                             => 'demo_pendiente_de_terminar',
                'demo_pendiente_terminar_notificado' => true,
            ]);

            /* Notificar a admin-spa vía socket. */
            LeadBroadcastService::emit_conversation_updated((int) $lead->id);

            Log::info('CheckDemoFinTimeout: lead pasó a demo_pendiente_de_terminar por timeout', [
                'lead_id'           => $lead->id,
                'contact_name'      => $lead->contact_name,
                'check_fin_datetime' => $check_fin_datetime->toDateTimeString(),
                'trigger_timeout'   => $trigger_timeout->toDateTimeString(),
            ]);

            $processed++;
        }

        $this->info("Timeouts de fin procesados: {$processed}");

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
