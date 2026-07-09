<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\DemoCicloAdminNotificationService;
use App\Services\LeadBroadcastService;
use App\Helpers\AppTime;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Envía automáticamente el check de ingreso en el minuto exacto de inicio de la demo
 * y transiciona el lead al estado `ingresando_demo`.
 *
 * Se ejecuta cada minuto. Busca leads en `demo_agendada` cuya `demo_datetime`
 * cae dentro de la ventana ±2 min del instante actual, sin check de ingreso enviado.
 *
 * A partir del prompt 096, ya no se usa el retardo configurable
 * `demo_check_ingreso_minutos_post`; el check se manda en el minuto exacto de inicio.
 * La confirmación de ingreso la hace Claude (prompt 095) al interpretar la respuesta del lead.
 */
class CheckDemoIngress extends Command
{
    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'leads:check-demo-ingress';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Transiciona el lead a ingresando_demo y envía el check de ingreso en el minuto exacto de inicio';

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
     * Procesa candidatos: transiciona a `ingresando_demo` y envía el check de ingreso.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        /* Momento actual en timezone Argentina. */
        $now = AppTime::now();

        /*
         * Ventana de ±2 minutos alrededor del inicio exacto de la demo
         * para no perder el trigger con el scheduler de 1 minuto.
         * Un lead cuya demo_datetime esté en [now-2, now+2] es candidato.
         */
        $window_start = $now->copy()->subMinutes(2);
        $window_end   = $now->copy()->addMinutes(2);

        /* Buscar leads con demo agendada, sin check enviado y sin sugerencia pendiente. */
        $candidates = Lead::query()
            ->where('status', 'demo_agendada')
            // Gate del prompt 322: la automatización solo corre si el master y el flag
            // específico de esta operación están activos para el lead (prompt 318).
            ->where('automatizaciones_demo_activas', true)
            ->where('auto_check_ingreso_demo', true)
            ->where('demo_check_ingreso_enviado', false)
            ->where('tiene_sugerencia_pendiente', false)
            ->whereNotNull('demo_date')
            ->whereNotNull('demo_start_time')
            ->whereDate('demo_date', $now->format('Y-m-d'))
            ->get();

        /* Contador de checks enviados para el log final. */
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
             * Verificar que el inicio de la demo caiga dentro de la ventana ±2 min.
             * demo_datetime debe estar entre window_start y window_end.
             */
            if ($demo_datetime->lt($window_start) || $demo_datetime->gt($window_end)) {
                continue;
            }

            /* Transicionar el lead a ingresando_demo antes de enviar el mensaje. */
            $lead->update(['status' => 'ingresando_demo']);

            /* Texto natural del check de ingreso (texto libre, ventana 24hs activa). */
            $contact_name = $lead->contact_name ?? 'cliente';
            $content      = "{$contact_name}, ¿cómo vas? ¿Pudiste entrar a la demo?";

            /* Enviar por WhatsApp si el lead tiene teléfono. */
            $whatsapp_message_id = null;
            $phone = trim((string) $lead->phone);
            if ($phone !== '') {
                $whatsapp_message_id = $this->whatsapp_send_service->send_text($phone, $content);
            } else {
                Log::warning('CheckDemoIngress: lead sin teléfono', [
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

            /* Marcar flag anti-duplicado para que este comando no vuelva a procesarlo. */
            $lead->update(['demo_check_ingreso_enviado' => true]);

            /* Notificar a admins suscritos vía WhatsApp que se envió el check de ingreso. */
            try {
                $ciclo_service = new DemoCicloAdminNotificationService($this->whatsapp_send_service);
                $ciclo_service->notify_check_ingreso_enviado($lead->fresh());
            } catch (\Throwable $e) {
                Log::error('CheckDemoIngress: error al notificar check_ingreso_enviado a admins.', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);
            }

            /* Notificar a admin-spa vía socket. */
            LeadBroadcastService::emit_conversation_updated((int) $lead->id);

            Log::info('CheckDemoIngress: lead pasó a ingresando_demo + check enviado', [
                'lead_id'       => $lead->id,
                'contact_name'  => $lead->contact_name,
                'demo_datetime' => $demo_datetime->toDateTimeString(),
            ]);

            $sent++;
        }

        $this->info("Checks de ingreso enviados: {$sent}");

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
