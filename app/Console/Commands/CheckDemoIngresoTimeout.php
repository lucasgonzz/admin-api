<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\CloserGoogleCalendarBusyService;
use App\Services\CloserGoogleCalendarEventService;
use App\Services\DemoCicloAdminNotificationService;
use App\Services\GoogleCalendarOAuthService;
use App\Services\LeadBroadcastService;
use App\Services\LeadDemoSettings;
use App\Helpers\AppTime;
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
 * superaron el timeout de espera (`demo_ingreso_timeout_minutos` desde el último mensaje
 * del lead posterior al inicio, o desde el inicio si el lead no respondió).
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
        /* Minutos de espera antes de considerar el ingreso fallido (desde último mensaje del lead o inicio). */
        $timeout_minutos = LeadDemoSettings::get_ingreso_timeout_minutos();

        /* Momento actual en timezone Argentina. */
        $now = AppTime::now();

        /* Buscar leads en ingresando_demo que no confirmaron y aún no fueron notificados. */
        $candidates = Lead::query()
            ->where('status', 'ingresando_demo')
            // Gate del prompt 322: la automatización solo corre si el master y el flag
            // específico de esta operación están activos para el lead (prompt 318).
            ->where('automatizaciones_demo_activas', true)
            ->where('auto_check_ingreso_demo', true)
            ->where('demo_check_ingreso_enviado', true)
            ->where('demo_ingreso_confirmado', false)
            ->where('demo_no_ingreso_notificado', false)
            /* Ventana extendida afuera (misión 47), y no alcanza con que CheckDemoIngress ya los
             * excluya: a `ingresando_demo` también se llega por la vía conversacional
             * (confirmar_ingreso), así que un flexible puede estar en ese estado sin que este
             * comando lo haya puesto ahí. El timeout cuelga de demo_start_time y lo mandaría a
             * demo_pendiente_de_ingreso mientras su ventana sigue abierta. */
            ->where('demo_flexible', false)
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
             * Referencia temporal: último mensaje del lead posterior al inicio de la demo.
             * Si el lead no envió nada desde el check de ingreso, usar el inicio de la demo.
             */
            $ultimo_lead_msg = \App\Models\LeadMessage::query()
                ->where('lead_id', $lead->id)
                ->where('sender', 'lead')
                ->where('created_at', '>=', $demo_datetime)
                ->orderByDesc('created_at')
                ->first();

            $referencia = $ultimo_lead_msg
                ? $ultimo_lead_msg->created_at->setTimezone('America/Argentina/Buenos_Aires')
                : $demo_datetime;

            /* El timeout vence cuando referencia + timeout_minutos ya pasó. */
            if ($referencia->copy()->addMinutes($timeout_minutos)->gt($now)) {
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

            /*
             * Liberar la reserva preventiva del closer (grupo 306, prompt 05, correctivo del
             * 3/8/2026): este es el timeout de ingreso REAL — el lead se hizo fantasma y nadie lo
             * marcó a mano. demo_date no se toca en este comando (el lead vuelve a
             * demo_pendiente_de_ingreso con la misma demo cargada), así que $lead->fresh() todavía
             * tiene la fecha para invalidar la caché correcta; no hace falta pasarla explícita.
             * Best-effort: una falla de Google no puede frenar el procesamiento del resto de la
             * cola de timeouts.
             */
            try {
                $oauth_service_hold = app(GoogleCalendarOAuthService::class);
                (new CloserGoogleCalendarEventService(
                    $oauth_service_hold,
                    new CloserGoogleCalendarBusyService($oauth_service_hold)
                ))->release_hold_for_lead($lead->fresh());
            } catch (\Throwable $e) {
                Log::error('CheckDemoIngresoTimeout: error al liberar la reserva preventiva del closer.', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);
            }

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
