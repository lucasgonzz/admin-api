<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadBroadcastService;
use App\Services\LeadDemoSettings;
use App\Services\SystemErrorWhatsappService;
use App\Helpers\AppTime;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Envía automáticamente el recordatorio pre-demo por WhatsApp (plantilla Meta).
 *
 * Se ejecuta cada 5 minutos. Busca leads con demo agendada en los próximos X minutos
 * (configurable) y envía el template `cc_recordatorio_demo` directamente al lead.
 * El flag `recordatorio_demo_enviado` evita que se envíe más de un recordatorio por demo.
 */
class SendDemoReminders extends Command
{
    /**
     * Nombre del template Meta aprobado para el recordatorio pre-demo.
     *
     * @var string
     */
    private const TEMPLATE_NAME = 'cc_recordatorio_demo_';

    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'leads:send-demo-reminders';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Envía recordatorios pre-demo por WhatsApp a leads con demo próxima';

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
     * Procesa todos los leads candidatos y envía el recordatorio correspondiente.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        // Momento actual y límite superior de la ventana de anticipación (timezone Argentina).
        $now = AppTime::now();

        // Ventana de anticipación dinámica: se lee del setting configurable para poder ajustarla
        // sin redeploy; si no hay setting configurado, el default del servicio es 15 minutos.
        $window_minutes = LeadDemoSettings::get_recordatorio_minutos_antes();
        $window_end     = $now->copy()->addMinutes($window_minutes);

        // Leads candidatos: demo agendada hoy, sin recordatorio emitido y sin sugerencia pendiente.
        // demo_date es DATE (sin hora ni timezone), ya guardada como fecha calendario de Argentina.
        $candidates = Lead::query()
            ->where('status', 'demo_agendada')
            // Gate del prompt 322: la automatización solo corre si el master y el flag
            // específico de esta operación están activos para el lead (prompt 318).
            ->where('automatizaciones_demo_activas', true)
            ->where('auto_recordatorio_demo', true)
            ->where('recordatorio_demo_enviado', false)
            ->where('tiene_sugerencia_pendiente', false)
            ->whereNotNull('demo_date')
            ->whereNotNull('demo_start_time')
            ->whereDate('demo_date', $now->format('Y-m-d'))
            ->get();

        // Contador de recordatorios enviados para el log final.
        $sent = 0;

        foreach ($candidates as $lead) {
            // Construir el datetime completo de inicio de demo combinando fecha y hora.
            $demo_datetime = $this->parse_demo_datetime(
                $lead->demo_date->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d'),
                $lead->demo_start_time
            );

            // Si el formato de hora es inválido, saltear para no romper el batch.
            if ($demo_datetime === null) {
                Log::warning('SendDemoReminders: no se pudo parsear demo_start_time', [
                    'lead_id'         => $lead->id,
                    'demo_start_time' => $lead->demo_start_time,
                ]);

                continue;
            }

            // Verificar que la demo esté dentro de la ventana [ahora, ahora + X min].
            if ($demo_datetime->lt($now) || $demo_datetime->gt($window_end)) {
                continue;
            }

            // Enviar el recordatorio pre-demo directo por WhatsApp.
            $this->send_reminder_message($lead);

            // Marcar que ya se envió el recordatorio para esta demo.
            $lead->update(['recordatorio_demo_enviado' => true]);

            // Notificar a admin-spa vía socket para actualizar la conversación en tiempo real.
            LeadBroadcastService::emit_conversation_updated((int) $lead->id);

            Log::info('SendDemoReminders: recordatorio enviado', [
                'lead_id'       => $lead->id,
                'contact_name'  => $lead->contact_name,
                'demo_datetime' => $demo_datetime->toDateTimeString(),
            ]);

            $sent++;
        }

        $this->info("Recordatorios enviados: {$sent}");

        return 0;
    }

    /**
     * Envía el template pre-demo y persiste el LeadMessage correspondiente.
     *
     * @param Lead $lead Lead al que pertenece el mensaje.
     *
     * @return void
     */
    protected function send_reminder_message(Lead $lead): void
    {
        // Nombre de contacto del lead para personalizar el saludo y la variable {{1}} del template.
        $contact_name = $lead->contact_name ?? 'Cliente';

        // Texto renderizado del template para trazabilidad en la conversación.
        $content = $this->build_reminder_content($contact_name);

        // Envío directo por WhatsApp vía plantilla Meta aprobada.
        $whatsapp_message_id = null;
        $phone = trim((string) $lead->phone);
        if ($phone !== '') {
            $whatsapp_message_id = $this->whatsapp_send_service->send_template(
                $phone,
                self::TEMPLATE_NAME,
                [$contact_name],
                'es_AR',
                "Recordatorio demo - Lead #{$lead->id} ({$lead->contact_name})"
            );
            // Si el envío falló, WhatsappSendService ya notifica a admins de forma centralizada.
        } else {
            Log::warning('SendDemoReminders: lead sin teléfono', [
                'lead_id' => $lead->id,
            ]);
        }

        LeadMessage::create([
            'lead_id'             => $lead->id,
            'sender'              => 'sistema',
            'content'             => $content,
            'status'              => 'enviado',
            'is_followup'         => false,
            'whatsapp_message_id' => $whatsapp_message_id,
        ]);
    }

    /**
     * Construye el texto del recordatorio pre-demo con el nombre del contacto sustituido.
     *
     * @param string $contact_name Nombre del lead para personalizar el saludo.
     *
     * @return string
     */
    protected function build_reminder_content(string $contact_name): string
    {
        return "Hola {$contact_name}! En unos minutos ya tenés disponible el acceso a la demo de ComercioCity.\n\n"
            . "Un consejo antes de entrar: empezá por el video introductorio que te mandamos al mail, "
            . "son 3 minutos y te van a ayudar a entender qué mirar cuando entrés al sistema.\n\n"
            . "Cualquier duda que surja mientras recorrés la plataforma, escribime por acá. 👋";
    }

    /**
     * Parsea el datetime de inicio de demo a partir de fecha (Y-m-d) y hora (H:i o similar).
     *
     * Devuelve null si el formato no es válido para evitar errores en el batch.
     *
     * @param string $date  Fecha en formato Y-m-d (p. ej. "2026-05-20").
     * @param string $time  Hora en texto libre (p. ej. "09:00" o "9:30").
     *
     * @return Carbon|null
     */
    protected function parse_demo_datetime(string $date, string $time): ?Carbon
    {
        try {
            // Carbon::parse acepta formatos parciales como "9:00" además de "09:00".
            return Carbon::parse("{$date} {$time}");
        } catch (\Exception $e) {
            return null;
        }
    }
}
