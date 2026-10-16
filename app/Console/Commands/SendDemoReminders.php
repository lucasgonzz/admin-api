<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadBroadcastService;
use App\Services\LeadDemoSettings;
use App\Services\LeadReminderComposer;
use App\Services\LeadReminderService;
use App\Services\SystemErrorWhatsappService;
use App\Helpers\AppTime;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Envía automáticamente el recordatorio de demo por WhatsApp.
 *
 * Se ejecuta cada 5 minutos, con dos comportamientos según la dinámica del lead:
 *
 * - Dinámica ACTUAL (Mail 1 con credenciales): leads con demo agendada en los próximos X minutos
 *   (configurable) reciben el recordatorio ("empezá por el video introductorio que te mandamos al
 *   mail").
 * - Dinámica NUEVA (demo directa, misión demo-agendado-directo, 10/9/2026): el lead ya tiene los
 *   links por WhatsApp y —si pasó el mail— la carta de acceso. El recordatorio es un empujón para
 *   el que todavía no entró (le recuerda que los accesos están en el mail, o en el chat si no hay
 *   mail, y que escriba por acá cualquier duda), elegible desde X minutos antes del inicio hasta
 *   que la ventana de la demo vence, y SOLO si no hubo mensajes en ninguna dirección en los últimos
 *   N minutos (setting, default 30).
 *
 * Canal (misión recordatorios-demo-con-conversacion, 16/10/2026, pedido de Lucas): lo decide
 * {@see LeadReminderService} igual para las dos dinámicas. Con la ventana de 24 hs de Meta abierta
 * el recordatorio es un texto libre que redacta la IA retomando la conversación con el lead; si la
 * IA está apagada, falla o el lead tiene el escudo de verificación prendido, sale lo de siempre
 * (la plantilla `cc_recordatorio_demo_` en la dinámica actual; el texto fijo en la nueva). Con la
 * ventana CERRADA sale la plantilla en las dos dinámicas. Hasta esa misión la dinámica nueva con la
 * ventana cerrada no mandaba nada y se reintentaba en cada tick, o sea, nunca salía.
 *
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

        /*
         * Último mensaje entrante y saliente por lead, en UNA consulta para todo el lote (misma
         * técnica que CheckDemoFin): alimenta la ventana de silencio de la dinámica nueva.
         */
        $ultimos_mensajes = collect();
        if ($candidates->isNotEmpty()) {
            /* Sin los eventos técnicos del hilo (is_status_event: "completó el formulario", "terminó
             * el video", bloques de error): no son "hablar con el lead", y contarlos postergaba el
             * recordatorio treinta minutos por cada hito de la página. */
            $ultimos_mensajes = LeadMessage::query()
                ->whereIn('lead_id', $candidates->pluck('id'))
                ->where(function ($query) {
                    $query->whereNull('is_status_event')->orWhere('is_status_event', false);
                })
                ->selectRaw("lead_id, MAX(CASE WHEN sender = 'lead' THEN created_at END) as ultimo_entrante, MAX(CASE WHEN sender != 'lead' THEN created_at END) as ultimo_saliente")
                ->groupBy('lead_id')
                ->get()
                ->keyBy('lead_id');
        }
        $silencio_minutos = LeadDemoSettings::get_recordatorio_silencio_minutos();
        $silencio_limite  = $now->copy()->subMinutes($silencio_minutos);
        $gracia_minutos   = LeadDemoSettings::get_gracia_minutos_post();

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

            if ($lead->usa_experiencia_demo_nueva()) {
                /*
                 * Dinámica nueva: elegible desde X minutos antes del inicio hasta el fin de la ventana
                 * (+ gracia). Un lead que ya entró no está acá (el evento demo.ingreso lo movió a
                 * demo_en_curso), así que el filtro por status alcanza.
                 */
                if ($now->lt($demo_datetime->copy()->subMinutes($window_minutes))) {
                    continue;
                }
                $demo_fin = $this->parse_demo_datetime(
                    $lead->demo_date->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d'),
                    (string) $lead->demo_end_time
                );
                if ($demo_fin === null) {
                    $demo_fin = $demo_datetime->copy()->addMinutes(LeadDemoSettings::get_duracion_minutos());
                }
                if ($now->gt($demo_fin->copy()->addMinutes($gracia_minutos))) {
                    continue;
                }

                /* Ventana de silencio: cualquier mensaje reciente, entrante o saliente, lo posterga.
                 * No se marca nada: se vuelve a evaluar en el próximo tick. */
                $mensajes        = $ultimos_mensajes->get($lead->id);
                $ultimo_entrante = ($mensajes && $mensajes->ultimo_entrante) ? Carbon::parse($mensajes->ultimo_entrante) : null;
                $ultimo_saliente = ($mensajes && $mensajes->ultimo_saliente) ? Carbon::parse($mensajes->ultimo_saliente) : null;
                if (($ultimo_entrante !== null && $ultimo_entrante->gt($silencio_limite))
                    || ($ultimo_saliente !== null && $ultimo_saliente->gt($silencio_limite))) {
                    continue;
                }

                /* La ventana de 24 hs ya no se mira acá: LeadReminderService elige el canal. Hasta el
                 * 16/10/2026 la ventana cerrada saltaba el recordatorio sin marcar (no había plantilla
                 * con el texto de esta dinámica) y no salía nunca; ahora sale la plantilla vigente. */
                if (! $this->send_reminder_message_directa($lead)) {
                    continue;
                }
            } else {
                // Verificar que la demo esté dentro de la ventana [ahora, ahora + X min].
                if ($demo_datetime->lt($now) || $demo_datetime->gt($window_end)) {
                    continue;
                }

                // Enviar el recordatorio pre-demo directo por WhatsApp.
                $this->send_reminder_message($lead);
            }

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
        $contact_name = $this->nombre_para_la_plantilla($lead);

        // Texto renderizado del template para trazabilidad en la conversación.
        $content = $this->build_reminder_content($contact_name);

        /*
         * Envío por el canal que corresponde (misión recordatorios-demo-con-conversacion,
         * 16/10/2026): con la ventana de 24 hs abierta, texto libre redactado por la IA retomando la
         * conversación; con la ventana cerrada —o si la IA no puede— la plantilla Meta aprobada de
         * siempre. Si el envío falló, WhatsappSendService ya notifica a admins de forma centralizada.
         */
        $envio = $this->reminder_service()->enviar(
            $lead,
            LeadReminderComposer::TIPO_PRE_DEMO,
            [
                'texto'     => null,
                'plantilla' => [
                    'nombre'    => self::TEMPLATE_NAME,
                    'variables' => [$contact_name],
                    'idioma'    => 'es_AR',
                    'contenido' => $content,
                ],
            ],
            "Recordatorio demo - Lead #{$lead->id} ({$lead->contact_name})"
        );

        if ($envio['motivo'] === 'sin_telefono') {
            Log::warning('SendDemoReminders: lead sin teléfono', [
                'lead_id' => $lead->id,
            ]);
        }

        // Se guarda en el hilo lo que REALMENTE se mandó (la plantilla o el texto de la IA).
        LeadMessage::create([
            'lead_id'             => $lead->id,
            'sender'              => 'sistema',
            'content'             => $envio['contenido'],
            'status'              => 'enviado',
            'is_followup'         => false,
            'whatsapp_message_id' => $envio['whatsapp_message_id'],
        ]);
    }

    /**
     * Servicio que elige el canal del recordatorio (IA con la ventana abierta, plantilla si no).
     * Se crea recién al primer uso, con el mismo servicio de envío que tiene el comando.
     *
     * @var LeadReminderService|null
     */
    private $reminder_service;

    /**
     * @return LeadReminderService
     */
    protected function reminder_service(): LeadReminderService
    {
        if ($this->reminder_service === null) {
            $this->reminder_service = new LeadReminderService($this->whatsapp_send_service);
        }

        return $this->reminder_service;
    }

    /**
     * Nombre para la variable {{1}} de la plantilla y para el texto renderizado.
     *
     * Primer nombre, no el completo (decisión de Lucas, 8/9/2026): el lead ve "Hola Guillermo", no
     * "Hola Guillermo González".
     *
     * 🔴 Un nombre vacío cuenta igual que uno ausente. `contact_first_name` devuelve '' (no null)
     * cuando el lead no tiene nombre, el `?? 'Cliente'` que había antes no lo reemplazaba, y Meta
     * rechaza una plantilla con un parámetro de texto vacío (`Required parameter is missing`): el
     * recordatorio no salía. Es el mismo modo de falla de los 2.933 seguimientos de julio-agosto
     * (ver `LeadFollowupService::NOMBRE_GENERICO`).
     *
     * @param Lead $lead
     *
     * @return string
     */
    protected function nombre_para_la_plantilla(Lead $lead): string
    {
        $nombre = trim((string) $lead->contact_first_name);

        return $nombre !== '' ? $nombre : 'Cliente';
    }

    /**
     * Recordatorio de la dinámica nueva (demo directa): texto libre por send_text().
     *
     * @param Lead $lead
     *
     * @return bool true si salió (y se persistió el LeadMessage); false si Meta lo rechazó, en cuyo
     *              caso no se marca `recordatorio_demo_enviado` y se reintenta en el próximo tick.
     */
    protected function send_reminder_message_directa(Lead $lead): bool
    {
        $contact_name = trim((string) $lead->contact_first_name);
        $content      = $this->build_reminder_content_directa($contact_name, ! empty($lead->demo_mail_sent_at));

        /* Lo que salía antes de la misión (16/10/2026) y sigue siendo el respaldo: el texto fijo de
         * arriba con la ventana abierta, y —nuevo— la plantilla vigente con la ventana cerrada. */
        $nombre_plantilla = $this->nombre_para_la_plantilla($lead);

        $envio = $this->reminder_service()->enviar(
            $lead,
            LeadReminderComposer::TIPO_PRE_DEMO,
            [
                'texto'     => $content,
                'plantilla' => [
                    'nombre'    => self::TEMPLATE_NAME,
                    'variables' => [$nombre_plantilla],
                    'idioma'    => 'es_AR',
                    'contenido' => $this->build_reminder_content($nombre_plantilla),
                ],
            ],
            "Recordatorio demo directa - Lead #{$lead->id} ({$lead->contact_name})"
        );

        if ($envio['motivo'] === 'sin_telefono') {
            Log::warning('SendDemoReminders: lead sin teléfono (dinámica nueva)', ['lead_id' => $lead->id]);

            return false;
        }

        /*
         * Qué pasa cuando no salió nada:
         *  - Por texto libre (ventana abierta): NO se marca y se reintenta en el próximo tick, como
         *    siempre en esta dinámica. Esos envíos van sin aviso a los admins (cuarto parámetro de
         *    send_text) justamente porque se repiten: un Kapso caído durante una hora mandaría doce
         *    avisos por lead.
         *  - Por plantilla (ventana cerrada): se registra y se marca igual que en la dinámica actual,
         *    SIN reintentar. send_template no tiene ese parámetro y avisa a los admins en cada
         *    fallo: reintentar cada cinco minutos con un nombre de plantilla mal puesto o un
         *    parámetro rechazado los inundaría.
         */
        if (! $envio['enviado'] && $envio['via'] !== LeadReminderService::VIA_PLANTILLA) {
            Log::warning('SendDemoReminders: el recordatorio de la dinámica nueva no salió; se reintenta en el próximo tick.', [
                'lead_id' => $lead->id,
                'error'   => $this->whatsapp_send_service->last_send_error,
            ]);

            return false;
        }

        // Se guarda en el hilo lo que REALMENTE se mandó (el texto de la IA, el fijo o la plantilla).
        LeadMessage::create([
            'lead_id'             => $lead->id,
            'sender'              => 'sistema',
            'content'             => $envio['contenido'],
            'status'              => 'enviado',
            'is_followup'         => false,
            'whatsapp_message_id' => $envio['whatsapp_message_id'],
        ]);

        return true;
    }

    /**
     * Texto del recordatorio de la dinámica nueva. Pedido de Lucas (10/9/2026): saludar por el
     * nombre de pila, recordar que los links de acceso están en el mail, y que cualquier duda
     * mientras recorre el sistema la escriba por acá. Si no hay mail enviado (el lead nunca pasó
     * su correo), los accesos están en el chat y se le ofrece mandárselos por mail.
     *
     * @param string $contact_name  Nombre de pila ('' si no lo tenemos).
     * @param bool   $mail_enviado  true si la carta de acceso ya salió.
     *
     * @return string
     */
    protected function build_reminder_content_directa(string $contact_name, bool $mail_enviado): string
    {
        $saludo = $contact_name !== '' ? "Hola {$contact_name}!" : 'Hola!';

        if ($mail_enviado) {
            $cuerpo = "Te recuerdo que los accesos a tu demo de ComercioCity están en el mail que te mandamos: "
                . "ahí tenés el botón para entrar al sistema y el de la tienda online conectada.";
        } else {
            $cuerpo = "Te recuerdo que el acceso a tu demo de ComercioCity es el link que te pasé más arriba en este chat. "
                . "Si querés tenerlo también en el mail para abrirlo desde la computadora, pasame tu correo.";
        }

        return "{$saludo} {$cuerpo}\n\n"
            . "Cualquier duda que te surja mientras recorrés el sistema, escribime por acá. 👋";
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
