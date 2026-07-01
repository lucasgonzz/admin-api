<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\AdminSetting;
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
     * Nombre del template Meta para el resumen matinal de demos del día a los admins.
     * Variable {{1}}: listado formateado de leads con demo hoy (puede contener saltos de línea).
     *
     * @var string
     */
    private const TEMPLATE_RESUMEN_ADMINS = 'cc_admin_resumen_demos_dia';

    /**
     * Clave en AdminSetting para registrar la fecha del último envío del resumen a admins.
     * Se usa como anti-duplicado para que el resumen salga una sola vez por día.
     *
     * @var string
     */
    private const SETTING_ULTIMO_ENVIO_RESUMEN = 'demo_resumen_admins_ultimo_envio';

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
        $now = AppTime::now();

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

        /*
         * Resumen matinal a admins: lista de todos los leads con demo hoy.
         * Se envía en la misma ventana horaria del recordatorio, una sola vez por día.
         * Anti-duplicado: se verifica que la fecha guardada en AdminSetting no sea hoy.
         */
        $this->send_morning_summary_to_admins($now);

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
     * Envía el resumen matinal de demos del día a los admins suscritos (una vez por día).
     *
     * Construye un listado de todos los leads con demo hoy ordenados por hora,
     * formatea el texto y lo envía como una sola variable del template a cada admin.
     * Si ya se envió hoy (según AdminSetting), omite el envío.
     * Si no hay demos hoy, omite el envío para evitar mensajes vacíos.
     *
     * @param Carbon $now Momento actual en timezone Argentina.
     *
     * @return void
     */
    protected function send_morning_summary_to_admins(Carbon $now): void
    {
        /* Anti-duplicado: verificar si ya se envió el resumen hoy. */
        $hoy           = $now->format('Y-m-d');
        $ultimo_envio  = AdminSetting::get(self::SETTING_ULTIMO_ENVIO_RESUMEN, '');
        if ($ultimo_envio === $hoy) {
            $this->info('Resumen matinal a admins ya enviado hoy. Se omite.');

            return;
        }

        /* Reunir todos los leads con demo hoy (sin importar sub-estado de demo), ordenados por hora de inicio. */
        $leads_hoy = Lead::query()
            ->whereNotNull('demo_date')
            ->whereNotNull('demo_start_time')
            ->whereDate('demo_date', $hoy)
            ->orderBy('demo_start_time')
            ->get();

        /* Si no hay demos hoy, no enviar para evitar mensajes vacíos. */
        if ($leads_hoy->isEmpty()) {
            $this->info('Sin demos hoy. No se envía resumen matinal a admins.');

            return;
        }

        /* Construir el listado de líneas formateadas (una por lead). */
        $lineas = $this->build_resumen_lineas($leads_hoy);

        /* Texto completo que se envía como variable {{1}} del template. */
        $listado = implode("\n", $lineas);

        /* Obtener admins suscritos con teléfono cargado. */
        $admins = Admin::where('notify_demo_scheduled_whatsapp', true)
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '')
            ->get();

        if ($admins->isEmpty()) {
            Log::info('SendMorningDemoReminder: sin admins suscritos para el resumen matinal.');

            return;
        }

        /* Enviar el resumen a cada admin suscrito. Los errores individuales no cortan el loop. */
        $enviados = 0;
        foreach ($admins as $admin) {
            try {
                $this->whatsapp_send_service->send_template(
                    (string) $admin->phone_number,
                    self::TEMPLATE_RESUMEN_ADMINS,
                    [$listado]
                );

                Log::info('SendMorningDemoReminder: resumen matinal enviado a admin.', [
                    'admin_id'     => $admin->id,
                    'total_demos'  => $leads_hoy->count(),
                ]);

                $enviados++;
            } catch (\Throwable $e) {
                Log::error('SendMorningDemoReminder: error al enviar resumen matinal a admin.', [
                    'admin_id' => $admin->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        /* Registrar fecha de envío para el anti-duplicado del día siguiente. */
        if ($enviados > 0) {
            AdminSetting::set(self::SETTING_ULTIMO_ENVIO_RESUMEN, $hoy);
        }

        $this->info("Resumen matinal enviado a {$enviados} admin(s). Total demos hoy: {$leads_hoy->count()}");
    }

    /**
     * Construye el array de líneas formateadas para el resumen matinal de admins.
     *
     * Formato de cada línea: "HH:MM - Nombre (rubro)"
     * El rubro se resuelve en orden: demo_summary_structured['empresa'] > company_name > '-'.
     *
     * @param \Illuminate\Support\Collection $leads Colección de leads con demo hoy, ordenados por hora.
     *
     * @return string[] Array de líneas formateadas, una por lead.
     */
    protected function build_resumen_lineas($leads): array
    {
        /* Construir cada línea como "HH:MM - Nombre (rubro)". */
        $lineas = [];
        foreach ($leads as $lead) {
            /* Hora de inicio de la demo. */
            $hora = $lead->demo_start_time ?? '??:??';

            /* Nombre legible del lead: contact_name > company_name > "Lead #ID". */
            $nombre = '';
            if (! empty($lead->contact_name)) {
                $nombre = $lead->contact_name;
            } elseif (! empty($lead->company_name)) {
                $nombre = $lead->company_name;
            } else {
                $nombre = "Lead #{$lead->id}";
            }

            /* Rubro: demo_summary_structured['empresa'] > company_name > '-'. */
            $rubro = '-';
            $structured = $lead->demo_summary_structured;
            if (is_array($structured) && ! empty($structured['empresa'])) {
                $rubro = (string) $structured['empresa'];
            } elseif (! empty($lead->company_name)) {
                $rubro = (string) $lead->company_name;
            }

            $lineas[] = "{$hora} - {$nombre} ({$rubro})";
        }

        return $lineas;
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
