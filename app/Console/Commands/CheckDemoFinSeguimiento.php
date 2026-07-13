<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadBroadcastService;
use App\Services\LeadDemoSettings;
use App\Helpers\AppTime;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Envía un único mensaje de seguimiento de fin de demo si el lead no confirmó
 * que terminó dentro del tiempo configurado.
 *
 * Se ejecuta cada minuto. Busca leads en `demo_en_curso` que ya recibieron el
 * check de fin (`demo_fin_check_enviado = true`) pero no confirmaron la terminación
 * (`demo_terminada_confirmada = false`) y aún no recibieron el seguimiento
 * (`demo_fin_seguimiento_enviado = false`).
 *
 * La referencia temporal es `demo_datetime + duración` (momento en que se envió el
 * check de fin). Si desde ese momento pasaron más de `fin_seguimiento_minutos`,
 * se envía 1 seguimiento. El flag anti-duplicado garantiza que no se repita.
 */
class CheckDemoFinSeguimiento extends Command
{
    /**
     * Nombre del template Meta aprobado para el seguimiento de fin de demo (prompt 353).
     *
     * @var string
     */
    private const TEMPLATE_NAME = 'cc_check_fin_seguimiento_demo';

    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'leads:check-demo-fin-seguimiento';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Envía seguimiento único de fin de demo si el lead no confirmó en el tiempo esperado';

    /**
     * Servicio de envío saliente vía Kapso/Meta.
     *
     * @var WhatsappSendService
     */
    private $whatsapp_send_service;

    /**
     * @param WhatsappSendService|null $whatsapp_send_service Inyección opcional (tests).
     */
    public function __construct(?WhatsappSendService $whatsapp_send_service = null)
    {
        parent::__construct();
        $this->whatsapp_send_service = $whatsapp_send_service ?? new WhatsappSendService();
    }

    /**
     * Procesa candidatos y envía el seguimiento de fin si corresponde.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        /* Duración estimada de la demo en minutos según configuración. */
        $duracion_minutos = LeadDemoSettings::get_duracion_minutos();

        /* Minutos desde el check de fin antes de enviar el seguimiento. */
        $seguimiento_minutos = LeadDemoSettings::get_fin_seguimiento_minutos();

        /* Momento actual en timezone Argentina. */
        $now = AppTime::now();

        /*
         * Referencia: el check de fin se mandó en el momento demo_datetime + duracion.
         * Si desde ese punto pasaron más de seguimiento_minutos, corresponde el seguimiento.
         * Límite: solo leads cuyo (demo_datetime + duracion + seguimiento_minutos) <= now.
         */
        $candidates = Lead::query()
            ->where('status', 'demo_en_curso')
            // Gate del prompt 322: la automatización solo corre si el master y el flag
            // específico de esta operación están activos para el lead (prompt 318).
            ->where('automatizaciones_demo_activas', true)
            ->where('auto_check_fin_demo', true)
            ->where('demo_fin_check_enviado', true)
            ->where('demo_terminada_confirmada', false)
            ->where('demo_fin_seguimiento_enviado', false)
            ->whereNotNull('demo_date')
            ->whereNotNull('demo_start_time')
            ->get();

        /* Contador de seguimientos enviados para el log final. */
        $sent = 0;

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
             * Momento en que se envió el check de fin = inicio + duración.
             * El seguimiento se dispara cuando ese momento + seguimiento_minutos <= now.
             */
            $check_fin_datetime    = $demo_datetime->copy()->addMinutes($duracion_minutos);
            $trigger_seguimiento   = $check_fin_datetime->copy()->addMinutes($seguimiento_minutos);

            if ($trigger_seguimiento->gt($now)) {
                continue;
            }

            /* Texto del seguimiento (prompt 353: plantilla Meta aprobada, no depende de ventana 24hs). */
            $contact_name = $lead->contact_name ?? 'cliente';
            $content      = "¡Hola {$contact_name}! ¿Pudiste terminar de recorrer la demo?";

            /* Enviar por WhatsApp si el lead tiene teléfono. */
            $whatsapp_message_id = null;
            $phone = trim((string) $lead->phone);
            if ($phone !== '') {
                $whatsapp_message_id = $this->whatsapp_send_service->send_template(
                    $phone,
                    self::TEMPLATE_NAME,
                    [$contact_name],
                    'es_AR',
                    "Seguimiento fin de demo - Lead #{$lead->id} ({$lead->contact_name})"
                );
            } else {
                Log::warning('CheckDemoFinSeguimiento: lead sin teléfono', [
                    'lead_id' => $lead->id,
                ]);
            }

            /* Registrar el mensaje en la conversación del lead. */
            LeadMessage::create([
                'lead_id'             => $lead->id,
                'sender'              => 'sistema',
                'status'              => 'enviado',
                'is_followup'         => false,
                'content'             => $content,
                'whatsapp_message_id' => $whatsapp_message_id,
            ]);

            /* Marcar flag anti-duplicado para que no se vuelva a enviar. */
            $lead->update(['demo_fin_seguimiento_enviado' => true]);

            /* Notificar a admin-spa vía socket. */
            LeadBroadcastService::emit_conversation_updated((int) $lead->id);

            Log::info('CheckDemoFinSeguimiento: seguimiento de fin enviado', [
                'lead_id'              => $lead->id,
                'contact_name'         => $lead->contact_name,
                'check_fin_datetime'   => $check_fin_datetime->toDateTimeString(),
                'trigger_seguimiento'  => $trigger_seguimiento->toDateTimeString(),
            ]);

            $sent++;
        }

        $this->info("Seguimientos de fin enviados: {$sent}");

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
