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
 *
 * Grupo 307, prompt 01: antes de mandar el check, verifica que la conversación no esté
 * viva (sugerencia pendiente, o un mensaje entrante/saliente reciente). Si lo está, NO
 * envía nada: pospone reprogramando `demo_fin_check_reprogramado_para`, que mientras
 * esté seteada reemplaza a `demo_datetime + duración` como objetivo temporal. Sin esa
 * reprogramación, un check automático puede interrumpir al lead en medio de una
 * conversación en curso (con el agente o con Lucas contestando a mano).
 */
class CheckDemoFin extends Command
{
    /**
     * Nombre del template Meta aprobado para el check de fin de demo (prompt 353).
     *
     * @var string
     */
    private const TEMPLATE_NAME = 'cc_check_fin_demo';

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
    protected $description = 'Envía pregunta automática de fin de demo al lead en demo_en_curso al cumplirse la duración, salvo conversación viva';

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
     * Procesa candidatos: pospone si hay conversación viva, o envía el check de fin.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        /* Duración estimada de la demo en minutos según configuración. */
        $duracion_minutos = LeadDemoSettings::get_duracion_minutos();

        /* Ventana de "conversación viva" y demora por defecto al posponer (prompt 307-01). */
        $silencio_minutos       = LeadDemoSettings::get_fin_check_silencio_minutos();
        $demora_default_minutos = LeadDemoSettings::get_fin_check_demora_default_minutos();

        /* Momento actual en timezone Argentina. */
        $now = AppTime::now();

        /*
         * Ventana de ±2 minutos alrededor del objetivo (calculado o reprogramado)
         * para no perder el trigger con el scheduler de 1 minuto.
         */
        $target_before = $now->copy()->addMinutes(2);
        $target_after  = $now->copy()->subMinutes(2);

        /*
         * Buscar leads en demo_en_curso (ingreso ya confirmado) y check de fin sin enviar.
         * El estado demo_en_curso lo setea Claude al confirmar el ingreso (prompt 095).
         *
         * Dos casos, unidos con OR:
         * - Sin reprogramación: el filtro de siempre, acotado a demo_date = hoy (evita escanear
         *   todo el histórico de demo_en_curso).
         * - Con reprogramación: el objetivo temporal ya no es demo_datetime, así que no se puede
         *   acotar por demo_date -- una demo de las 23:30 reprogramada 15 minutos cae al día
         *   siguiente y ese filtro nunca la encontraría (detalle 4 del prompt 307-01).
         */
        $candidates = Lead::query()
            ->where('status', 'demo_en_curso')
            // Gate del prompt 322: la automatización solo corre si el master y el flag
            // específico de esta operación están activos para el lead (prompt 318).
            ->where('automatizaciones_demo_activas', true)
            ->where('auto_check_fin_demo', true)
            ->where('demo_fin_check_enviado', false)
            /* Ventana extendida afuera (misión 47): el check de fin se dispara al cumplirse la
             * duración desde demo_start_time, así que a un flexible que entró a las 23:00 le
             * preguntaría a las 21:00 si terminó — dos horas antes de que empezara. */
            ->where('demo_flexible', false)
            ->whereNotNull('demo_date')
            ->whereNotNull('demo_start_time')
            ->where(function ($query) use ($now) {
                $query->where(function ($sin_reprogramar) use ($now) {
                    $sin_reprogramar->whereNull('demo_fin_check_reprogramado_para')
                        ->whereDate('demo_date', $now->format('Y-m-d'));
                })->orWhereNotNull('demo_fin_check_reprogramado_para');
            })
            ->get();

        /* Contadores para el log final. */
        $sent      = 0;
        $postponed = 0;

        if ($candidates->isNotEmpty()) {
            /*
             * Una sola consulta agregada para TODOS los candidatos del minuto: último mensaje
             * entrante ('lead') y último saliente (cualquier otro sender) por lead_id. Nunca una
             * consulta por lead adentro del foreach -- el comando corre cada minuto y no puede
             * degradarse con el volumen de leads (criterio de éxito 10 del prompt 307-01).
             */
            $lead_ids = $candidates->pluck('id');

            $ultimos_mensajes = LeadMessage::query()
                ->whereIn('lead_id', $lead_ids)
                ->selectRaw("lead_id, MAX(CASE WHEN sender = 'lead' THEN created_at END) as ultimo_entrante, MAX(CASE WHEN sender != 'lead' THEN created_at END) as ultimo_saliente")
                ->groupBy('lead_id')
                ->get()
                ->keyBy('lead_id');

            /* Umbral: un mensaje entrante o saliente más nuevo que esto cuenta como "reciente". */
            $silencio_limite = $now->copy()->subMinutes($silencio_minutos);

            foreach ($candidates as $lead) {
                /*
                 * Objetivo temporal: la reprogramación, si existe, reemplaza a
                 * demo_datetime + duración (ver detalle 4 del prompt 307-01).
                 */
                if ($lead->demo_fin_check_reprogramado_para !== null) {
                    $target_datetime = $lead->demo_fin_check_reprogramado_para->copy();
                } else {
                    $demo_datetime = $this->parse_demo_datetime(
                        $lead->demo_date->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d'),
                        (string) $lead->demo_start_time
                    );

                    if ($demo_datetime === null) {
                        continue;
                    }

                    $target_datetime = $demo_datetime->copy()->addMinutes($duracion_minutos);
                }

                /* Verificar que el objetivo esté dentro de la ventana de ±2 minutos. */
                if ($target_datetime->gt($target_before) || $target_datetime->lt($target_after)) {
                    continue;
                }

                /*
                 * "Conversación viva": cualquiera de las tres condiciones alcanza para posponer en
                 * vez de mandar el check. La primera copia el filtro tiene_sugerencia_pendiente que
                 * ya usa CheckDemoIngress; las otras dos son ventanas de mensajes recientes (la
                 * saliente cubre a Lucas contestando a mano, no solo al agente).
                 */
                $motivo_viva = null;

                if ($lead->tiene_sugerencia_pendiente) {
                    $motivo_viva = 'sugerencia_pendiente';
                } else {
                    $mensajes        = $ultimos_mensajes->get($lead->id);
                    $ultimo_entrante = ($mensajes && $mensajes->ultimo_entrante) ? Carbon::parse($mensajes->ultimo_entrante) : null;
                    $ultimo_saliente = ($mensajes && $mensajes->ultimo_saliente) ? Carbon::parse($mensajes->ultimo_saliente) : null;

                    if ($ultimo_entrante !== null && $ultimo_entrante->gt($silencio_limite)) {
                        $motivo_viva = 'mensaje_entrante_reciente';
                    } elseif ($ultimo_saliente !== null && $ultimo_saliente->gt($silencio_limite)) {
                        $motivo_viva = 'mensaje_saliente_reciente';
                    }
                }

                if ($motivo_viva !== null) {
                    /* No se envía nada y no se toca demo_fin_check_enviado: sigue en false. */
                    $lead->update([
                        'demo_fin_check_reprogramado_para' => $now->copy()->addMinutes($demora_default_minutos),
                    ]);

                    Log::info('CheckDemoFin: check de fin pospuesto por conversación viva.', [
                        'lead_id'            => $lead->id,
                        'motivo'             => $motivo_viva,
                        'reprogramado_para'  => $lead->demo_fin_check_reprogramado_para->toDateTimeString(),
                    ]);

                    $postponed++;

                    continue;
                }

                /* Sin conversación viva: enviar el check de fin por WhatsApp como siempre
                 * (prompt 353: plantilla Meta aprobada, no depende de ventana 24hs). */
                $contact_name = $lead->contact_name ?? 'cliente';
                $content      = "¡Hola {$contact_name}! ¿Pudiste recorrer la demo completa? 😊";

                $whatsapp_message_id = null;
                $phone = trim((string) $lead->phone);
                if ($phone !== '') {
                    $whatsapp_message_id = $this->whatsapp_send_service->send_template(
                        $phone,
                        self::TEMPLATE_NAME,
                        [$contact_name],
                        'es_AR',
                        "Check de fin de demo - Lead #{$lead->id} ({$lead->contact_name})"
                    );
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
                    'lead_id'         => $lead->id,
                    'contact_name'    => $lead->contact_name,
                    'target_datetime' => $target_datetime->toDateTimeString(),
                ]);

                $sent++;
            }
        }

        $this->info("Checks de fin enviados: {$sent} (pospuestos por conversación viva: {$postponed})");

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
