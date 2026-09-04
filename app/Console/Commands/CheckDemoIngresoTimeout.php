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
 * Pasa a demo_pendiente_de_ingreso a los leads que no entraron de verdad a la demo dentro del
 * tiempo límite, sin mandarles ningún mensaje.
 *
 * Se ejecuta cada minuto. Busca leads en `demo_agendada` (ya no hay un estado intermedio
 * `ingresando_demo`: se sacó del catálogo porque el ingreso real ahora se detecta solo, vía el
 * evento `demo.ingreso` que aplica DemoEventosController::avanzar_pipeline_por_ingreso_real() —
 * ver esa clase, misión demo-v2-estados-automaticos) cuyo `demo_start_time +
 * demo_ingreso_timeout_minutos` ya pasó y que todavía no tienen `demo_ingreso_confirmado`.
 *
 * Si el lead entró de verdad, ya está en `demo_en_curso` (lo puso el evento) y esta query ni lo
 * mira: el filtro por `status = 'demo_agendada'` alcanza solo.
 *
 * No envía mensaje al lead (Claude ya está gestionando la conversación, si hace falta).
 * Solo cambia el estado para que el equipo lo vea y notifica a admins.
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
    protected $description = 'Pasa a demo_pendiente_de_ingreso los leads que no entraron de verdad a la demo en el tiempo límite';

    /**
     * Procesa los leads con timeout de ingreso superado.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        /* Minutos de espera antes de considerar el ingreso fallido, desde demo_start_time. */
        $timeout_minutos = LeadDemoSettings::get_ingreso_timeout_minutos();

        /* Momento actual en timezone Argentina. */
        $now = AppTime::now();

        /* Buscar leads en demo_agendada que no confirmaron el ingreso y aún no fueron notificados. */
        $candidates = Lead::query()
            ->where('status', 'demo_agendada')
            // Gate del prompt 322: la automatización solo corre si el master y el flag
            // específico de esta operación están activos para el lead (prompt 318).
            ->where('automatizaciones_demo_activas', true)
            ->where('auto_check_ingreso_demo', true)
            // Mismo gate que usaba el extinto CheckDemoIngress (misión demo-v2-estados-automaticos,
            // 4/9/2026) para no competir con una sugerencia del agente en vuelo. Tiene más sentido
            // ahora que nunca hay un mensaje de por medio que "reinicie el reloj".
            ->where('tiene_sugerencia_pendiente', false)
            ->where('demo_ingreso_confirmado', false)
            ->where('demo_no_ingreso_notificado', false)
            /* Ventana extendida afuera (misión 47): a un lead al que le ofrecimos "de 20:00 a
             * 23:59, entrá cuando puedas" este comando no puede mandarlo a demo_pendiente_de_ingreso
             * mientras su ventana sigue abierta.
             *
             * Las dos condiciones, por el mismo motivo de siempre: `demo_flexible` es una columna
             * preexistente que Lucas también marca a mano en leads de la dinámica actual, y esos
             * tienen que seguir pasando por acá. */
            ->where(function ($query) {
                $query->where('demo_flexible', false)
                    ->orWhere(function ($otra_dinamica) {
                        $otra_dinamica->where('demo_experiencia', '!=', Lead::EXPERIENCIA_NUEVA)
                            ->orWhereNull('demo_experiencia');
                    });
            })
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

            /* El timeout vence cuando demo_start_time + timeout_minutos ya pasó. Ya no se corre la
             * referencia por el último mensaje del lead (así funcionaba con el check de ingreso
             * que se mandaba por WhatsApp): esa lógica existía para no interrumpir una conversación
             * activa sobre un mensaje que ya no se manda, y deja de tener sentido (misión
             * demo-v2-estados-automaticos, 4/9/2026). */
            if ($demo_datetime->copy()->addMinutes($timeout_minutos)->gt($now)) {
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
                $ciclo_service->notify_no_ingreso($lead->fresh(), 'no entró a la demo dentro del tiempo límite');
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
