<?php

namespace App\Console\Commands;

use App\Helpers\AppTime;
use App\Http\Controllers\DemoExperienciaController;
use App\Models\DemoEventoRecibido;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadBroadcastService;
use App\Services\LeadDemoSettings;
use App\Services\WhatsappSendService;
use App\Services\WhatsappSessionWindowService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Le manda un único seguimiento al lead que ABRIÓ su página de experiencia sin tener turno y, pasadas
 * N horas, no pidió la demo ni volvió a escribir (misión experiencia-landing, 11/9/2026).
 *
 * Contexto: desde esta misión la página (`/experiencia/<telefono>`) es la landing que Martín le pasa al
 * lead en la primera respuesta, ANTES de ofrecerle la demo. Un lead que la abre y no vuelve es el hueco
 * que ningún seguimiento cubría: `leads:check-followups` manda plantillas por estado cada dos horas
 * pensadas para el que no contesta, y acá el lead sí hizo algo —miró la página— y nadie se lo nombra.
 *
 * Calcado en estructura de {@see CheckDemoIngresoPostVideo}: candidatos por consulta, últimos mensajes
 * de todos los candidatos en una sola query, ventana de silencio con la misma setting
 * (`demo_check_ingreso_silencio_minutos`). Dos diferencias de fondo:
 *
 *  - Texto libre con `send_text()`, NO una plantilla de Meta, y por eso exige la ventana de 24 hs
 *    abierta (`WhatsappSessionWindowService::window_state()`): fuera de la ventana Meta rechaza el
 *    texto y el lead no ve nada. No hay plantilla aprobada para este mensaje, y un lead que abrió la
 *    página hace dos horas casi siempre escribió hace menos de 24.
 *  - Un solo envío por lead, PARA SIEMPRE (`pagina_seguimiento_enviado_at`), también cuando el envío
 *    falla: reintentar cada cinco minutos contra un número que rebota quema el canal.
 *
 * Se ejecuta cada cinco minutos (Kernel). Sólo dinámica nueva: en la actual la página no es landing.
 */
class CheckPaginaSinDemo extends Command
{
    /** Zona horaria de todo el cálculo, la misma que el resto del ciclo de demo. */
    private const TZ = 'America/Argentina/Buenos_Aires';

    /**
     * Estados del pipeline en los que el lead todavía no tiene demo y tiene sentido ofrecérsela: ya
     * hubo contacto (`contactado`) o ya contó su negocio (`calificado`). Un lead `nuevo` no recibió
     * el link todavía; uno más adelante ya tiene demo o ya la hizo.
     *
     * @var array<int, string>
     */
    private const ESTADOS_CANDIDATOS = ['contactado', 'calificado'];

    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'leads:check-pagina-sin-demo';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Manda el seguimiento único al lead que abrió su página de experiencia sin turno y no pidió la demo';

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
     * Procesa los candidatos y manda el seguimiento a los que cumplen todas las condiciones.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        $now = AppTime::now(self::TZ);

        $espera_minutos   = LeadDemoSettings::get_pagina_seguimiento_minutos();
        $silencio_minutos = LeadDemoSettings::get_check_ingreso_silencio_minutos();
        $limite_apertura  = $now->copy()->subMinutes($espera_minutos);

        /* Las condiciones que se pueden expresar en SQL van en la consulta, y son las que vuelven chico
         * el lote: la apertura de la página con la antigüedad pedida (EXISTS sobre
         * demo_eventos_recibidos: "hay una apertura de hace más de N minutos" equivale a "la PRIMERA
         * apertura tiene más de N minutos") y la ausencia del CTA (si tocó el botón, ya pidió la demo
         * por WhatsApp y el agente está en eso). */
        $candidates = Lead::query()
            ->where('demo_experiencia', Lead::EXPERIENCIA_NUEVA)
            ->whereIn('status', self::ESTADOS_CANDIDATOS)
            ->whereNull('demo_date')
            ->whereNull('pagina_seguimiento_enviado_at')
            /* Marca manual "este lead ya no recibe mensajes" (número bloqueado o dado de baja). */
            ->whereNull('no_recibe_mensajes_at')
            /* El interruptor maestro de automatizaciones del lead (el del modal de operaciones):
             * los demás Check* del ciclo de demo lo respetan, y este mensaje es una automatización
             * más. Apagado a mano por Lucas, nada sale solo. */
            ->where('automatizaciones_demo_activas', true)
            /* Con una sugerencia del agente esperando aprobación, la conversación ya tiene algo en
             * vuelo: mandar esto encima sería hablarle dos veces. */
            ->where('tiene_sugerencia_pendiente', false)
            ->whereExists(function ($query) use ($limite_apertura) {
                $query->select(DB::raw(1))
                    ->from('demo_eventos_recibidos')
                    ->whereColumn('demo_eventos_recibidos.lead_id', 'leads.id')
                    ->where('demo_eventos_recibidos.nombre', DemoExperienciaController::EVENTO_PAGINA_ABIERTA)
                    ->where('demo_eventos_recibidos.ocurrido_at', '<=', $limite_apertura);
            })
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('demo_eventos_recibidos')
                    ->whereColumn('demo_eventos_recibidos.lead_id', 'leads.id')
                    ->where('demo_eventos_recibidos.nombre', DemoExperienciaController::EVENTO_CTA_TOCADO);
            })
            ->get();

        $sent = 0;

        if ($candidates->isEmpty()) {
            $this->info("Seguimientos de página enviados: {$sent}");

            return 0;
        }

        $ids = $candidates->pluck('id');

        /* Primera apertura por lead, en una sola consulta: contra ella se mide si el lead volvió a
         * escribir DESPUÉS de mirar la página. */
        $primeras_aperturas = DemoEventoRecibido::query()
            ->whereIn('lead_id', $ids)
            ->where('nombre', DemoExperienciaController::EVENTO_PAGINA_ABIERTA)
            ->selectRaw('lead_id, MIN(ocurrido_at) as primera_apertura')
            ->groupBy('lead_id')
            ->get()
            ->keyBy('lead_id');

        /* Último entrante y último saliente por lead, en una sola consulta (misma técnica que
         * CheckDemoIngresoPostVideo). Los eventos técnicos del hilo ("abrió su página", "completó el
         * formulario") son filas de `sistema` con is_status_event: no son "hablar con el lead" y no
         * cuentan. Y el saliente cuenta sólo si se DESPACHÓ (enviado/aprobado): una sugerencia
         * rechazada nunca le llegó al lead, así que no puede valer como "ya le contestamos". */
        $ultimos_mensajes = LeadMessage::query()
            ->whereIn('lead_id', $ids)
            ->where(function ($query) {
                $query->whereNull('is_status_event')->orWhere('is_status_event', false);
            })
            ->selectRaw(
                "lead_id, MAX(CASE WHEN sender = 'lead' THEN created_at END) as ultimo_entrante, "
                . "MAX(CASE WHEN sender != 'lead' AND status IN ('" . implode("','", LeadMessage::STATUSES_SALIENTE_DESPACHADO) . "') THEN created_at END) as ultimo_saliente"
            )
            ->groupBy('lead_id')
            ->get()
            ->keyBy('lead_id');

        $silencio_limite = $now->copy()->subMinutes($silencio_minutos);
        $ventana_service = app(WhatsappSessionWindowService::class);

        foreach ($candidates as $lead) {
            $apertura = $primeras_aperturas->get($lead->id);
            if ($apertura === null || empty($apertura->primera_apertura)) {
                /* No debería pasar (el EXISTS de arriba garantiza la apertura), pero un lead sin
                 * fecha de apertura no se puede evaluar: se saltea, no se marca. */
                continue;
            }
            $primera_apertura = Carbon::parse((string) $apertura->primera_apertura, self::TZ);

            $mensajes        = $ultimos_mensajes->get($lead->id);
            $ultimo_entrante = ($mensajes && $mensajes->ultimo_entrante) ? Carbon::parse($mensajes->ultimo_entrante, self::TZ) : null;
            $ultimo_saliente = ($mensajes && $mensajes->ultimo_saliente) ? Carbon::parse($mensajes->ultimo_saliente, self::TZ) : null;

            /* Si escribió DESPUÉS de abrir la página, la conversación siguió por otro lado (le
             * preguntó algo al agente, contó más de su negocio): no se lo interrumpe con un mensaje
             * que habla de la página como si fuera lo último que pasó. No se marca —la columna dice
             * "enviado", no "descartado"—; el costo es reevaluar cada cinco minutos un lote chico. */
            if ($ultimo_entrante !== null && $ultimo_entrante->gt($primera_apertura)) {
                continue;
            }

            /* Si el último mensaje real del hilo es del lead, la pelota es NUESTRA: hay algo suyo sin
             * contestar (o una sugerencia todavía sin aprobar) y este mensaje lo pisaría. No se
             * marca: cuando le contestemos, el próximo tick lo vuelve a evaluar. */
            if ($ultimo_entrante !== null && ($ultimo_saliente === null || $ultimo_entrante->gt($ultimo_saliente))) {
                continue;
            }

            /* Ventana de silencio: un mensaje reciente en cualquier dirección lo posterga. No se
             * marca nada, se reevalúa en el próximo tick. */
            if (($ultimo_entrante !== null && $ultimo_entrante->gt($silencio_limite))
                || ($ultimo_saliente !== null && $ultimo_saliente->gt($silencio_limite))) {
                continue;
            }

            /* Texto libre: sólo dentro de la ventana de 24 hs de Meta. Fuera de ella no se manda y NO
             * se marca —si el lead vuelve a escribir, la ventana se abre y el próximo tick lo
             * reevalúa—, aunque para entonces lo más probable es que su entrante posterior a la
             * apertura lo saque de la lista por el primer descarte. */
            $phone = trim((string) $lead->phone);
            if ($phone === '') {
                continue;
            }
            $estado_ventana = $ventana_service->window_state($phone);
            if (empty($estado_ventana['open'])) {
                continue;
            }

            /* Primer nombre, no el completo (decisión de Lucas, 8/9 y 11/9/2026). */
            $primer_nombre = trim((string) $lead->contact_first_name);
            $saludo        = $primer_nombre !== '' ? "¡Hola {$primer_nombre}!" : '¡Hola!';
            $content       = "{$saludo} Vi que le pegaste una mirada a tu página... Si querés, te preparo la demo con la configuración de tu negocio y en diez minutos la tenés lista. ¿Arrancamos?";

            $whatsapp_message_id = $this->whatsapp_send_service->send_text(
                $phone,
                $content,
                "Seguimiento de pagina - Lead #{$lead->id}"
            );

            /* Se registra haya salido o no (mismo criterio que LeadFollowupService): con
             * whatsapp_message_id null el hilo muestra el banner de error de entrega y el motivo.
             *
             * 🔴 `is_followup` en FALSE, como el check de ingreso y no como una plantilla de
             * cadencia. LeadFollowupService cuenta las filas con is_followup=true para consumir el
             * cupo de max_followups del estado y para elegir la plantilla siguiente
             * (followup_number = contados + 1): con true, este mensaje se comía un cupo de
             * contactado/calificado y le hacía saltear la plantilla d1 al lead, y encima un envío
             * fallido (sin wamid ni followup_template_id) también contaba. Este texto es un aviso
             * del sistema, no un paso de la cadencia. */
            LeadMessage::create([
                'lead_id'             => $lead->id,
                'sender'              => 'sistema',
                'status'              => 'enviado',
                'is_followup'         => false,
                'content'             => $content,
                'whatsapp_message_id' => $whatsapp_message_id,
                'whatsapp_send_error' => $whatsapp_message_id === null ? $this->whatsapp_send_service->last_send_error : null,
            ]);

            /* La marca va SIEMPRE, también con el envío fallido: un solo intento por lead, para
             * siempre. Reintentar cada cinco minutos contra un número que rebota quema el canal, y
             * el fallo ya quedó visible en el hilo. */
            $lead->update(['pagina_seguimiento_enviado_at' => $now->copy()]);

            LeadBroadcastService::emit_conversation_updated((int) $lead->id);

            if ($whatsapp_message_id === null) {
                Log::warning('CheckPaginaSinDemo: el seguimiento de página no se pudo enviar; no se reintenta.', [
                    'lead_id' => $lead->id,
                    'error'   => $this->whatsapp_send_service->last_send_error,
                ]);

                continue;
            }

            Log::info('CheckPaginaSinDemo: seguimiento de página enviado', [
                'lead_id'          => $lead->id,
                'contact_name'     => $lead->contact_name,
                'primera_apertura' => $primera_apertura->toDateTimeString(),
            ]);

            $sent++;
        }

        $this->info("Seguimientos de página enviados: {$sent}");

        return 0;
    }
}
