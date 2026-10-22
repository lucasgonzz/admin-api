<?php

namespace App\Jobs;

use App\Helpers\AppTime;
use App\Mail\Helpers\LeadDemoAccesoMailHelper;
use App\Mail\Helpers\RechazosDeCorreoHelper;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadEventoMeta;
use App\Models\LeadMessage;
use App\Models\WhatsappConfig;
use App\Services\DemoScheduledWhatsappService;
use App\Services\LeadAiService;
use App\Services\LeadBroadcastService;
use App\Services\MetaConversionesService;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Lo que sigue a una reserva desde la landing y NO puede hacer esperar al visitante (misión
 * demo-autogestionada-landing, 22/10/2026, D9 del plan): la carta de acceso por correo, el WhatsApp de
 * confirmación al lead, el aviso a los admins y el envío de los eventos a Meta.
 *
 * 🔴 POR QUÉ corre después de responder y no en la cola. Son cuatro salidas a la red (SMTP, Kapso dos
 * veces, Meta) con timeouts de hasta quince segundos cada una: adentro del request, el visitante miraría
 * un botón girando medio minuto con la demo ya reservada. Y no va a la cola de siempre porque la reserva
 * no puede depender de que el worker esté vivo: un lead que reservó y no recibe ni la carta ni el
 * WhatsApp es peor que uno que no reservó. Se despacha con `dispatchAfterResponse()`, que lo corre en el
 * mismo proceso, en el `terminate()` del kernel, después de que PHP-FPM ya le entregó la respuesta al
 * navegador. Es el mismo mecanismo que usa `LeadAiSuggestionScheduler` para la sugerencia del agente.
 *
 * Por eso NO implementa `ShouldQueue`: nadie tiene que poder mandarlo a la cola por error.
 *
 * Cada paso es independiente y deja su rastro si falla (D9): la carta en `demo_mail_last_error` y en el
 * evento del hilo, el WhatsApp como mensaje con `whatsapp_send_error` o como evento, el aviso a los
 * admins y Meta en el log y en la fila del evento de Meta. Nunca tira: la reserva ya está hecha.
 */
class ConfirmarReservaLandingJob
{
    use Dispatchable;

    /** @var int */
    public $lead_id;

    /**
     * @var int|null Evento `sistema` del hilo creado por la reserva (ahí se anota lo que pasó). Lo completa
     *               la reserva DESPUÉS de despachar el trabajo (el trabajo se despacha apenas la reserva
     *               queda escrita, antes de cualquier paso que pueda tirar): por eso es público y nullable.
     */
    public $evento_id;

    /** @var array<int, int> Filas de `lead_eventos_meta` que nacieron en la reserva (las completa la reserva, igual). */
    public $filas_meta;

    /** @var string El turno en texto («jueves 23/10 de 15:00 a 17:00»). */
    public $texto_turno;

    /**
     * @param int             $lead_id
     * @param int|null        $evento_id
     * @param array<int, int> $filas_meta
     * @param string          $texto_turno
     */
    public function __construct(int $lead_id, ?int $evento_id, array $filas_meta, string $texto_turno)
    {
        $this->lead_id     = $lead_id;
        $this->evento_id   = $evento_id;
        $this->filas_meta  = $filas_meta;
        $this->texto_turno = $texto_turno;
    }

    /**
     * @return void
     */
    public function handle(): void
    {
        $lead = Lead::query()->find($this->lead_id);
        if ($lead === null) {
            return;
        }

        $anotaciones = [];

        $this->ajustar_token($lead);
        $this->mandar_carta($lead, $anotaciones);
        $this->mandar_whatsapp_al_lead($lead, $anotaciones);
        $this->avisar_a_los_admins($lead, $anotaciones);
        $this->mandar_a_meta();

        /* Lo que pasó queda en el evento de la reserva, igual que el agente lo deja en su mensaje
         * (`admin_notifications`). */
        try {
            $evento = $this->evento_id !== null ? LeadMessage::query()->find($this->evento_id) : null;
            if ($evento !== null && ! empty($anotaciones)) {
                $previas = is_array($evento->admin_notifications) ? $evento->admin_notifications : [];
                $evento->update(['admin_notifications' => array_merge($previas, $anotaciones)]);
            }
            LeadBroadcastService::emit_conversation_updated((int) $lead->id, $evento !== null ? (int) $evento->id : null);
        } catch (\Throwable $e) {
            Log::error('ConfirmarReservaLanding: no se pudo anotar el resultado en el evento de la reserva.', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * El token de ingreso, si ya había uno vigente, acompaña al turno nuevo. Mismo paso que hace el agente
     * después de soltar el lock (`LeadAiService::ajustar_token_de_ingreso_al_turno`); para un lead que
     * nunca tuvo demo no hace nada (el token lo emite el setup, no la reserva).
     *
     * @param Lead $lead
     *
     * @return void
     */
    private function ajustar_token(Lead $lead): void
    {
        try {
            app(LeadAiService::class)->ajustar_token_de_ingreso_al_turno(
                $lead,
                (int) $lead->demo_id,
                $lead->demo_date !== null ? $lead->demo_date->format('Y-m-d') : '',
                (string) $lead->demo_end_time,
                false
            );
        } catch (\Throwable $e) {
            Log::error('ConfirmarReservaLanding: no se pudo ajustar el token de ingreso.', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * La carta de acceso (`demo_acceso`), como `LeadController::send_demo_mail_json`: armado, envío,
     * chequeo de rechazos del servidor y `demo_mail_sent_at`. Va al correo GUARDADO en la ficha (D5).
     *
     * @param Lead                             $lead
     * @param array<int, array<string, mixed>> $anotaciones
     *
     * @return void
     */
    private function mandar_carta(Lead $lead, array &$anotaciones): void
    {
        if (trim((string) $lead->email) === '' || empty($lead->demo_id)) {
            return;
        }

        try {
            $lead->load('demo');
            Mail::to($lead->email)->send(LeadDemoAccesoMailHelper::build($lead));

            /* 🔴 Que `send()` no tire NO quiere decir que el mail salió: un 550 en el RCPT TO no tira nada
             * (ver `RechazosDeCorreoHelper`). Va ANTES de anotar el éxito: con un rechazo anotado como
             * enviado, el agente le diría al lead que ya tiene la carta. */
            RechazosDeCorreoHelper::fallar_si_hubo_rechazos();

            $lead->update(['demo_mail_sent_at' => AppTime::now(), 'demo_mail_last_error' => null]);
            $anotaciones[] = ['evento' => 'Carta de acceso enviada', 'admins' => []];
        } catch (\Throwable $e) {
            Log::error('ConfirmarReservaLanding: no salió la carta de acceso.', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);

            try {
                $lead->update(['demo_mail_last_error' => $e->getMessage()]);
            } catch (\Throwable $segunda) {
                Log::error('ConfirmarReservaLanding: no se pudo anotar el error de la carta.', ['lead_id' => $lead->id]);
            }
            $anotaciones[] = [
                'evento' => 'Carta de acceso no enviada: ' . ($e instanceof \App\Exceptions\MailRechazadoPorElServidorException
                    ? 'el servidor de correo rechazó la casilla'
                    : 'falló el envío'),
                'admins' => [],
            ];
        }
    }

    /**
     * La confirmación por WhatsApp al lead (D10), con la plantilla `cc_demo_reservada_landing` y SOLO si
     * su fila de `followup_templates` está activa y aprobada por Meta (mismo gate que
     * `ClaudeLeadsOutboundController::plantilla_para_enviar`). Sale por el número institucional de
     * leads, el mismo de los recordatorios de la demo.
     *
     * Sin plantilla aprobada no se intenta: Meta la descartaría y quedaría un fallo rojo en el hilo y un
     * aviso a los admins por algo que no es una falla. Queda un evento que dice por qué no salió.
     *
     * @param Lead                             $lead
     * @param array<int, array<string, mixed>> $anotaciones
     *
     * @return void
     */
    private function mandar_whatsapp_al_lead(Lead $lead, array &$anotaciones): void
    {
        try {
            $nombre_plantilla = (string) config('landing_agenda.plantilla_confirmacion');
            $estado           = FollowupTemplate::estado_para_enviar($nombre_plantilla);

            if ($estado['motivo'] !== null) {
                LeadMessage::create([
                    'lead_id'               => $lead->id,
                    'sender'                => 'sistema',
                    'content'               => 'No salió el WhatsApp de confirmación de la reserva: la plantilla ' . $nombre_plantilla
                        . ' todavía no está aprobada (' . $estado['motivo'] . '). Tiene la carta por correo y su página.',
                    'status'                => 'enviado',
                    'is_followup'           => false,
                    'is_status_event'       => true,
                    'requiere_verificacion' => false,
                ]);
                $anotaciones[] = ['evento' => 'WhatsApp de confirmación no enviado: plantilla sin aprobar', 'admins' => []];

                return;
            }

            /** @var FollowupTemplate $fila */
            $fila      = $estado['fila'];
            $nombre    = trim((string) $lead->contact_first_name);
            if ($nombre === '') {
                $nombre = trim((string) $lead->contact_name);
            }
            $variables = [$nombre, $this->texto_turno, (string) $lead->demo_experiencia_url];

            $sender = app(WhatsappSendService::class);
            $sender->paraProposito(WhatsappConfig::PROPOSITO_PRINCIPAL);
            $whatsapp_message_id = $sender->send_template(
                (string) $lead->phone,
                (string) $fila->template_name,
                $variables,
                trim((string) $fila->language_code) !== '' ? (string) $fila->language_code : 'es_AR',
                'Confirmación de demo reservada desde la web - Lead #' . (int) $lead->id
            );

            $contenido = (string) $fila->body_template;
            foreach ($variables as $i => $valor) {
                $contenido = (string) preg_replace('/\{\{\s*' . ($i + 1) . '\s*\}\}/', str_replace(['\\', '$'], ['\\\\', '\\$'], (string) $valor), $contenido);
            }
            if (trim($contenido) === '') {
                $contenido = 'Confirmación de la demo reservada: ' . $this->texto_turno . ' — ' . (string) $lead->demo_experiencia_url;
            }

            LeadMessage::create([
                'lead_id'               => $lead->id,
                'sender'                => 'sistema',
                'kind'                  => 'text',
                'content'               => $contenido,
                'status'                => 'enviado',
                'whatsapp_message_id'   => $whatsapp_message_id,
                'whatsapp_send_error'   => $whatsapp_message_id === null
                    ? ($sender->last_send_error ? $sender->last_send_error : 'No se pudo enviar la plantilla.')
                    : null,
                'sent_at'               => $whatsapp_message_id !== null ? AppTime::now() : null,
                'is_followup'           => false,
                'followup_template_id'  => (int) $fila->id,
                'requiere_verificacion' => false,
                'system_message_kind'   => ConfirmarReservaLandingJob::KIND_CONFIRMACION,
            ]);

            $anotaciones[] = [
                'evento' => $whatsapp_message_id !== null ? 'WhatsApp de confirmación enviado' : 'WhatsApp de confirmación no enviado: falló el envío',
                'admins' => [],
            ];
        } catch (\Throwable $e) {
            Log::error('ConfirmarReservaLanding: error con el WhatsApp de confirmación al lead.', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /** `system_message_kind` del WhatsApp de confirmación: lo lee el bloque DEMO DIRECTA del agente. */
    const KIND_CONFIRMACION = 'demo_reservada_landing';

    /**
     * El aviso a los admins: el mismo `DemoScheduledWhatsappService::notify()` que dispara el agente al
     * asignar una demo directa (D6). No es un reagendado: un lead con demo vigente no llega acá (409).
     *
     * @param Lead                             $lead
     * @param array<int, array<string, mixed>> $anotaciones
     *
     * @return void
     */
    private function avisar_a_los_admins(Lead $lead, array &$anotaciones): void
    {
        try {
            $servicio  = new DemoScheduledWhatsappService(app(WhatsappSendService::class));
            $avisados  = $servicio->notify(
                $lead,
                $lead->demo_date !== null ? $lead->demo_date->format('Y-m-d') : '',
                substr((string) $lead->demo_start_time, 0, 5),
                false
            );
            if (! empty($avisados)) {
                $anotaciones[] = ['evento' => 'Demo agendada', 'admins' => $avisados];
            }
        } catch (\Throwable $e) {
            Log::error('ConfirmarReservaLanding: error al avisar a los admins de la demo reservada.', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Manda a Meta los eventos que nacieron en la reserva, por el mismo `enviar()` de siempre (reclamo
     * atómico, estados, errores en la fila). Si algo falla, el conciliador de cada diez minutos los
     * retoma como a cualquier otro.
     *
     * @return void
     */
    private function mandar_a_meta(): void
    {
        $servicio = app(MetaConversionesService::class);
        foreach ($this->filas_meta as $fila_id) {
            try {
                $fila = LeadEventoMeta::query()->find((int) $fila_id);
                if ($fila !== null) {
                    $servicio->enviar($fila);
                }
            } catch (\Throwable $e) {
                Log::channel('daily')->error('ConfirmarReservaLanding: error al mandar el evento a Meta.', [
                    'lead_evento_meta_id' => $fila_id,
                    'error'               => $servicio->sin_token($e->getMessage()),
                ]);
            }
        }
    }
}
