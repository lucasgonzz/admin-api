<?php

namespace App\Console\Commands;

use App\Helpers\AppTime;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\DemoCicloAdminNotificationService;
use App\Services\LeadBroadcastService;
use App\Services\LeadDemoSettings;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Le pregunta al lead si pudo entrar a la demo, unos minutos después de que TERMINÓ EL VIDEO de
 * introducción y todavía no entró (misión demo-agendado-directo, 10/9/2026).
 *
 * Es el sucesor del `CheckDemoIngress` borrado el 4/9/2026, con otro disparador. Aquél preguntaba
 * en el minuto exacto de inicio del turno, que en la demo directa no significa nada: el lead recibe
 * el link, entra a su página cuando puede, mira el video y recién ahí se le habilita el botón. El
 * momento en que tiene sentido preguntar es cuando ya terminó el video (`intro_visto_at`, que
 * sella DemoExperienciaController::store_intro_progreso_json() al cruzar el umbral) y pasaron N
 * minutos sin que llegara el evento `demo.ingreso` (que lo hubiera movido a `demo_en_curso`).
 * Textual de Lucas: *"el mensaje de chequeo de si pudo entrar a la demo quiero que se envíe recién
 * a los diez minutos desde que el lead completó el video [...] y en caso de que esté dentro del
 * horario en el que tiene que hacer la demo [...] siempre y cuando no se haya estado hablando con
 * ese lead en los últimos diez minutos"*.
 *
 * Se ejecuta cada minuto. Sólo dinámica nueva (en la actual no hay video en una página ni
 * `intro_visto_at`). Plantilla Meta aprobada `cc_check_ingreso_demo` —la misma del comando
 * anterior— para no depender de la ventana de 24 hs. Los flags `automatizaciones_demo_activas`,
 * `auto_check_ingreso_demo` y `demo_check_ingreso_enviado` son los mismos que gobernaban al
 * comando viejo (y el botón manual del panel, LeadController::check_demo_ingress_json()).
 */
class CheckDemoIngresoPostVideo extends Command
{
    /** Plantilla Meta aprobada: "¡Hola {{1}}! ¿Cómo vas? ¿Pudiste entrar a la demo?". */
    private const TEMPLATE_NAME = 'cc_check_ingreso_demo';

    /** Zona horaria de todo el cálculo, la misma que el resto del ciclo de demo. */
    private const TZ = 'America/Argentina/Buenos_Aires';

    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'leads:check-demo-ingreso-post-video';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Pregunta si pudo entrar a la demo al lead que terminó el video de introducción y no entró';

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
     * Procesa los candidatos y manda el check a los que cumplen las cuatro condiciones.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        $now = AppTime::now(self::TZ);

        $demora_minutos   = LeadDemoSettings::get_check_ingreso_minutos_post();
        $silencio_minutos = LeadDemoSettings::get_check_ingreso_silencio_minutos();
        $gracia_minutos   = LeadDemoSettings::get_gracia_minutos_post();
        $duracion_minutos = LeadDemoSettings::get_duracion_minutos();

        /* Sólo leads que terminaron el video hace al menos N minutos. La condición del video va en
         * la consulta: es lo que vuelve chico el lote (un lead sin `intro_visto_at` nunca califica). */
        $candidates = Lead::query()
            ->where('status', 'demo_agendada')
            ->where('demo_experiencia', Lead::EXPERIENCIA_NUEVA)
            ->where('automatizaciones_demo_activas', true)
            ->where('auto_check_ingreso_demo', true)
            ->where('demo_check_ingreso_enviado', false)
            ->where('demo_ingreso_confirmado', false)
            ->where('tiene_sugerencia_pendiente', false)
            /* Si la demo todavía se está preparando (o falló), el botón no se habilita y
             * preguntarle "¿pudiste entrar?" es preguntarle por algo que no pudo hacer. */
            ->where('demo_setup_status', 'exitoso')
            ->whereNotNull('intro_visto_at')
            ->where('intro_visto_at', '<=', $now->copy()->subMinutes($demora_minutos))
            ->whereNotNull('demo_date')
            ->whereNotNull('demo_start_time')
            ->get();

        $sent = 0;

        if ($candidates->isEmpty()) {
            $this->info("Checks de ingreso post-video enviados: {$sent}");

            return 0;
        }

        /* Último entrante y último saliente por lead, en una sola consulta (misma técnica que
         * CheckDemoFin): la ventana de silencio se evalúa con esto. */
        /* Los eventos técnicos del hilo ("completó el formulario", "terminó el video", los bloques
         * de error) son filas de `sistema` con is_status_event: no son "hablar con el lead" y no
         * cuentan para el silencio. Sin esta condición, terminar el video postergaba el propio
         * check que ese hito dispara. */
        $ultimos_mensajes = LeadMessage::query()
            ->whereIn('lead_id', $candidates->pluck('id'))
            ->where(function ($query) {
                $query->whereNull('is_status_event')->orWhere('is_status_event', false);
            })
            ->selectRaw("lead_id, MAX(CASE WHEN sender = 'lead' THEN created_at END) as ultimo_entrante, MAX(CASE WHEN sender != 'lead' THEN created_at END) as ultimo_saliente")
            ->groupBy('lead_id')
            ->get()
            ->keyBy('lead_id');

        $silencio_limite = $now->copy()->subMinutes($silencio_minutos);

        foreach ($candidates as $lead) {
            /* "Dentro del horario en el que tiene que hacer la demo": entre el inicio del turno y
             * el fin de la ventana (+ gracia). Antes del inicio no tiene sentido preguntar (el botón
             * todavía no se habilita); después, la demo venció y ya no se puede entrar. */
            $fecha  = $lead->demo_date->setTimezone(self::TZ)->format('Y-m-d');
            $inicio = $this->parse_demo_datetime($fecha, (string) $lead->demo_start_time);
            if ($inicio === null) {
                continue;
            }
            $fin = $this->parse_demo_datetime($fecha, (string) $lead->demo_end_time);
            if ($fin === null) {
                $fin = $inicio->copy()->addMinutes($duracion_minutos);
            }
            if ($now->lt($inicio) || $now->gt($fin->copy()->addMinutes($gracia_minutos))) {
                continue;
            }

            /* Ventana de silencio: un mensaje reciente en cualquier dirección lo posterga. No se
             * marca nada, se reevalúa en el próximo tick. */
            $mensajes        = $ultimos_mensajes->get($lead->id);
            $ultimo_entrante = ($mensajes && $mensajes->ultimo_entrante) ? Carbon::parse($mensajes->ultimo_entrante) : null;
            $ultimo_saliente = ($mensajes && $mensajes->ultimo_saliente) ? Carbon::parse($mensajes->ultimo_saliente) : null;
            if (($ultimo_entrante !== null && $ultimo_entrante->gt($silencio_limite))
                || ($ultimo_saliente !== null && $ultimo_saliente->gt($silencio_limite))) {
                continue;
            }

            /* Primer nombre, no el completo (decisión de Lucas, 8/9/2026). */
            $contact_name = trim((string) $lead->contact_first_name);
            if ($contact_name === '') {
                $contact_name = 'cliente';
            }
            $content = "¡Hola {$contact_name}! ¿Cómo vas? ¿Pudiste entrar a la demo?";

            $whatsapp_message_id = null;
            $phone = trim((string) $lead->phone);
            if ($phone !== '') {
                $whatsapp_message_id = $this->whatsapp_send_service->send_template(
                    $phone,
                    self::TEMPLATE_NAME,
                    [$contact_name],
                    'es_AR',
                    "Check de ingreso post-video - Lead #{$lead->id} ({$lead->contact_name})"
                );
            } else {
                Log::warning('CheckDemoIngresoPostVideo: lead sin teléfono', ['lead_id' => $lead->id]);
            }

            LeadMessage::create([
                'lead_id'             => $lead->id,
                'sender'              => 'sistema',
                'status'              => 'enviado',
                'is_followup'         => false,
                'content'             => $content,
                'whatsapp_message_id' => $whatsapp_message_id,
            ]);

            /* Flag anti-duplicado: es el mismo que lee el detector de respuesta del webhook
             * (WhatsappWebhookController::handle_demo_confirmation_if_needed(), "Caso A"), así que
             * un "sí" o un "no" del lead a este mensaje se procesa como confirmación de ingreso o
             * como no-ingreso, igual que con el comando anterior. */
            $lead->update([
                'demo_check_ingreso_enviado'    => true,
                'demo_check_ingreso_enviado_at' => $now->copy(),
            ]);

            try {
                $ciclo_service = new DemoCicloAdminNotificationService($this->whatsapp_send_service);
                $ciclo_service->notify_check_ingreso_enviado($lead->fresh());
            } catch (\Throwable $e) {
                Log::error('CheckDemoIngresoPostVideo: error al notificar check_ingreso_enviado a admins.', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);
            }

            LeadBroadcastService::emit_conversation_updated((int) $lead->id);

            Log::info('CheckDemoIngresoPostVideo: check de ingreso enviado', [
                'lead_id'        => $lead->id,
                'contact_name'   => $lead->contact_name,
                'intro_visto_at' => $lead->intro_visto_at ? $lead->intro_visto_at->toDateTimeString() : null,
            ]);

            $sent++;
        }

        $this->info("Checks de ingreso post-video enviados: {$sent}");

        return 0;
    }

    /**
     * Combina fecha (Y-m-d) y hora (HH:MM en texto libre) en la zona horaria de referencia.
     *
     * @param string $date Fecha en formato Y-m-d.
     * @param string $time Hora en texto libre; vacía = sin dato.
     *
     * @return Carbon|null Null si la hora está vacía o no parsea (ese lead se saltea, no rompe).
     */
    protected function parse_demo_datetime(string $date, string $time): ?Carbon
    {
        if (trim($time) === '') {
            return null;
        }
        try {
            return Carbon::parse("{$date} {$time}", self::TZ);
        } catch (\Exception $e) {
            return null;
        }
    }
}
