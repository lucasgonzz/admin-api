<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadBroadcastService;
use App\Services\LeadDemoSettings;
use App\Services\SystemErrorWhatsappService;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Envía automáticamente el recordatorio de mañana de demo por WhatsApp (plantilla Meta).
 *
 * Se ejecuta cada 5 minutos. Busca leads con demo agendada hoy y envía el template
 * `cc_recordatorio_manana_demo` a la hora configurable (default 09:00 Argentina).
 * El flag `recordatorio_manana_enviado` evita duplicar el envío por demo.
 */
class SendMorningDemoReminder extends Command
{
    /**
     * Nombre del template Meta aprobado para el recordatorio de mañana.
     * v2: incluye hora de la demo como segunda variable {{2}}.
     *
     * @var string
     */
    private const TEMPLATE_NAME = 'cc_recordatorio_manana_demo_v2';

    /**
     * Ventana en minutos alrededor de la hora configurada para no perder el trigger.
     *
     * @var int
     */
    private const WINDOW_MINUTES = 4;

    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'leads:send-morning-demo-reminder';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Envía recordatorio de mañana de demo por WhatsApp a leads con demo hoy';

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
     * Procesa leads candidatos y envía el recordatorio de mañana si corresponde.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        // Momento actual en timezone Argentina.
        $now = Carbon::now('America/Argentina/Buenos_Aires');

        // Hora configurada en admin (formato H:i, ej. 09:00).
        $configured_hour = LeadDemoSettings::get_recordatorio_manana_hora();

        // Si la hora actual no está dentro de la ventana ±4 min, no procesar.
        if (! $this->is_within_configured_window($now, $configured_hour)) {
            $this->info('Fuera de ventana horaria configurada ('.$configured_hour.'). Sin envíos.');

            return 0;
        }

        // Leads candidatos: demo agendada hoy, sin recordatorio de mañana enviado.
        $candidates = Lead::query()
            ->where('status', 'demo_agendada')
            ->where('recordatorio_manana_enviado', false)
            ->whereNotNull('demo_date')
            ->whereNotNull('demo_start_time')
            ->whereDate('demo_date', $now->format('Y-m-d'))
            ->get();

        // Contador de envíos exitosos para el log final.
        $sent = 0;

        foreach ($candidates as $lead) {
            // Enviar template y registrar mensaje en la conversación del lead.
            $this->send_morning_reminder($lead);
            $sent++;

            Log::info('SendMorningDemoReminder: recordatorio enviado', [
                'lead_id'      => $lead->id,
                'contact_name' => $lead->contact_name,
                'demo_date'    => $lead->demo_date ? $lead->demo_date->format('Y-m-d') : null,
            ]);
        }

        // Notificar a admin-spa que las conversaciones cambiaron (una emisión por lead ya ocurrió en send_morning_reminder).
        $this->info("Recordatorios de mañana enviados: {$sent}");

        return 0;
    }

    /**
     * Verifica si el momento actual cae dentro de la ventana ±N minutos de la hora configurada.
     *
     * @param Carbon $now             Momento actual en timezone Argentina.
     * @param string $configured_hour Hora en formato H:i (ej. 09:00).
     *
     * @return bool
     */
    protected function is_within_configured_window(Carbon $now, string $configured_hour): bool
    {
        try {
            // Construir datetime objetivo con la fecha de hoy y la hora configurada.
            $target = Carbon::createFromFormat(
                'Y-m-d H:i',
                $now->format('Y-m-d').' '.$configured_hour,
                'America/Argentina/Buenos_Aires'
            );
        } catch (\Exception $e) {
            Log::warning('SendMorningDemoReminder: hora configurada inválida', [
                'configured_hour' => $configured_hour,
            ]);

            return false;
        }

        $window_start = $target->copy()->subMinutes(self::WINDOW_MINUTES);
        $window_end   = $target->copy()->addMinutes(self::WINDOW_MINUTES);

        return $now->gte($window_start) && $now->lte($window_end);
    }

    /**
     * Envía el template de recordatorio de mañana y persiste el LeadMessage correspondiente.
     *
     * Utiliza la plantilla v2 que acepta dos variables: {{1}} nombre del contacto
     * y {{2}} hora de la demo (ej: "10:00"). Si la hora no está disponible, se envía
     * cadena vacía como fallback y el texto del mensaje muestra "hoy" sin hora.
     *
     * @param Lead $lead Lead con demo agendada hoy.
     *
     * @return void
     */
    protected function send_morning_reminder(Lead $lead): void
    {
        // Nombre del contacto para personalizar el saludo y la variable {{1}} del template.
        $contact_name = $lead->contact_name ?? 'Cliente';

        // Hora de inicio de la demo para la variable {{2}} del template (ej: "10:00").
        $demo_start_time = $lead->demo_start_time ?? '';

        // Texto renderizado para trazabilidad en la conversación del lead.
        $content = $this->build_morning_reminder_content($contact_name, $demo_start_time);

        // Envío directo por WhatsApp vía plantilla Meta aprobada.
        $whatsapp_message_id = null;
        $phone = trim((string) $lead->phone);
        if ($phone !== '') {
            $whatsapp_message_id = $this->whatsapp_send_service->send_template(
                $phone,
                self::TEMPLATE_NAME,
                [$contact_name, $demo_start_time],
                'es_AR'
            );

            /* Si el envío falló (id null), notificar a los admins suscritos a errores. */
            if ($whatsapp_message_id === null) {
                app(SystemErrorWhatsappService::class)->notify_send_error(
                    "Recordatorio mañana demo - Lead #{$lead->id} ({$lead->contact_name})",
                    'send_template() retornó null para plantilla ' . self::TEMPLATE_NAME
                );
            }
        } else {
            Log::warning('SendMorningDemoReminder: lead sin teléfono', [
                'lead_id' => $lead->id,
            ]);
        }

        // Registrar el mensaje enviado en el hilo del lead.
        LeadMessage::create([
            'lead_id'             => $lead->id,
            'sender'              => 'sistema',
            'content'             => $content,
            'status'              => 'enviado',
            'is_followup'         => false,
            'whatsapp_message_id' => $whatsapp_message_id,
        ]);

        // Marcar flag para no reenviar el recordatorio de mañana en la misma demo.
        $lead->update(['recordatorio_manana_enviado' => true]);

        // Notificar a admin-spa vía socket para refrescar la conversación.
        LeadBroadcastService::emit_conversation_updated((int) $lead->id);
    }

    /**
     * Construye el texto del recordatorio de mañana con nombre y hora de la demo sustituidos.
     *
     * Refleja el cuerpo de la plantilla Meta cc_recordatorio_manana_demo_v2.
     * Si la hora de la demo no está disponible, el fallback omite la hora y dice "hoy".
     *
     * @param string $contact_name    Nombre del lead para personalizar el saludo (variable {{1}}).
     * @param string $demo_start_time Hora de inicio de la demo en formato HH:MM (variable {{2}}). Puede ser vacía.
     *
     * @return string Texto completo del mensaje tal como lo recibe el contacto.
     */
    protected function build_morning_reminder_content(string $contact_name, string $demo_start_time): string
    {
        // Línea de hora: si hay hora disponible muestra "hoy a las HH:MM", si no solo "hoy".
        $hora_line = $demo_start_time !== '' ? "hoy a las {$demo_start_time}" : 'hoy';

        return "Hola {$contact_name}! Te recuerdo que {$hora_line} tenés agendada la demo de ComercioCity. 👋\n\n"
            . "Revisá el mail que te enviamos para tener todo listo antes de entrar.\n\n"
            . "Reservá unos 60 minutos sin interrupciones para aprovecharla al máximo.\n\n"
            . "¡Cualquier consulta estoy por acá! 😊";
    }
}
