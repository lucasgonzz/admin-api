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
 * Envía automáticamente el mensaje de fin de demo preguntando al lead si terminó.
 *
 * Se ejecuta cada minuto. Busca leads en estado `demo_en_curso` (ingreso ya
 * confirmado por Claude en prompt 095) a los que aún no se les envió el check de fin
 * (`demo_fin_check_enviado = false`), cuya demo termina ahora mismo (dentro de una
 * ventana de ±2 minutos alrededor del fin calculado = inicio + duración).
 *
 * A partir del prompt 096, el filtro usa `demo_en_curso` en lugar de `demo_agendada`
 * con `demo_ingreso_confirmado = true`, porque el estado `demo_en_curso` ya implica
 * que el ingreso fue confirmado.
 */
class CheckDemoFin extends Command
{
    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'leads:check-demo-fin';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Envía pregunta automática de fin de demo al lead en demo_en_curso al cumplirse la duración';

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
     * Procesa candidatos y envía el check de fin directo si corresponde.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        /* Duración estimada de la demo en minutos según configuración. */
        $duracion_minutos = LeadDemoSettings::get_duracion_minutos();

        /* Momento actual en timezone Argentina. */
        $now = AppTime::now();

        /*
         * Ventana de ±2 minutos alrededor del fin de la demo
         * para no perder el trigger con el scheduler de 1 minuto.
         */
        $target_fin_before = $now->copy()->addMinutes(2);
        $target_fin_after  = $now->copy()->subMinutes(2);

        /*
         * Buscar leads en demo_en_curso (ingreso ya confirmado) y check de fin sin enviar.
         * El estado demo_en_curso lo setea Claude al confirmar el ingreso (prompt 095).
         */
        $candidates = Lead::query()
            ->where('status', 'demo_en_curso')
            // Gate del prompt 322: la automatización solo corre si el master y el flag
            // específico de esta operación están activos para el lead (prompt 318).
            ->where('automatizaciones_demo_activas', true)
            ->where('auto_check_fin_demo', true)
            ->where('demo_fin_check_enviado', false)
            ->whereNotNull('demo_date')
            ->whereNotNull('demo_start_time')
            ->whereDate('demo_date', $now->format('Y-m-d'))
            ->get();

        /* Contador de mensajes enviados para el log final. */
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

            /* Datetime de fin de la demo = inicio + duración estimada. */
            $demo_fin_datetime = $demo_datetime->copy()->addMinutes($duracion_minutos);

            /*
             * Verificar que el fin de la demo esté dentro de la ventana de ±2 minutos
             * alrededor del momento actual ($target_fin_after..$target_fin_before).
             */
            if ($demo_fin_datetime->gt($target_fin_before) || $demo_fin_datetime->lt($target_fin_after)) {
                continue;
            }

            /* Enviar check de fin directo por WhatsApp (texto libre, ventana activa). */
            $contact_name = $lead->contact_name ?? 'cliente';
            $content = "¡Hola {$contact_name}! ¿Pudiste recorrer la demo completa? 😊";

            $whatsapp_message_id = null;
            $phone = trim((string) $lead->phone);
            if ($phone !== '') {
                $whatsapp_message_id = $this->whatsapp_send_service->send_text($phone, $content);
            } else {
                Log::warning('CheckDemoFin: lead sin teléfono', [
                    'lead_id' => $lead->id,
                ]);
            }

            LeadMessage::create([
                'lead_id'             => $lead->id,
                'sender'              => 'sistema',
                'status'              => 'enviado',
                'is_followup'         => false,
                'content'             => $content,
                'whatsapp_message_id' => $whatsapp_message_id,
            ]);

            /* Marcar flag de check de fin enviado. */
            $lead->update(['demo_fin_check_enviado' => true]);

            /* Notificar a admin-spa vía socket. */
            LeadBroadcastService::emit_conversation_updated((int) $lead->id);

            Log::info('CheckDemoFin: check de fin enviado', [
                'lead_id'           => $lead->id,
                'contact_name'      => $lead->contact_name,
                'demo_fin_datetime' => $demo_fin_datetime->toDateTimeString(),
            ]);

            $sent++;
        }

        $this->info("Checks de fin enviados: {$sent}");

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
