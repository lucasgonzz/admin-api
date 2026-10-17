<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Lead;
use App\Models\LeadMessage;
use Illuminate\Support\Facades\Log;

/**
 * Avisa a los admins que una sugerencia del agente quedó esperando aprobación FUERA del tramo de
 * agenda (escudo por lead, `requiere_verificacion` explícito de Claude o una guarda del servidor).
 * El tramo de agenda tiene su propio aviso: {@see LeadVerificacionAgendamientoNotificationService}.
 *
 * 🔴 CAMBIÓ EL 17/10/2026 (misión flujo-lead-landing-closer, pedido de Lucas: «cuando algo espera mi
 * ok, push al teléfono, no WhatsApp»). Hasta entonces este servicio mandaba SOLO WhatsApp, a los
 * admins con `notify_verificacion_whatsapp`, y ningún push: un mensaje esperando el ok de Lucas
 * fuera del tramo le llegaba como un WhatsApp de plantilla y nada más. Ahora son dos canales, y no
 * en paralelo:
 *
 *   1. PUSH a la misma audiencia que usa el aviso de agendamiento
 *      ({@see LeadNotificationAudienceResolver::for_verificacion()}: los setters, más los suscritos
 *      por campanita, con la red de último recurso a todos los admins). Es la tarea del setter, haya
 *      o no agenda de por medio.
 *   2. WHATSAPP de respaldo SOLO para quien no tiene ningún dispositivo de push registrado (mismo
 *      criterio que {@see LeadMessagePushNotificationService}): un admin sin device no se enteraría
 *      de nada. El flag `notify_verificacion_whatsapp` se mantiene como CONDICIÓN del respaldo (más
 *      el teléfono cargado): es un opt-in explícito y sigue siéndolo; lo que cambia es que ya no le
 *      llega a quien tiene con qué recibir el push. Para un admin con device el WhatsApp no sale,
 *      aunque el push falle: un push que falla es un problema de entrega y se ve en el log; un admin
 *      sin device es un problema de alcance y no se ve en ningún lado.
 *
 * La plantilla `lead_verificacion_pendiente` (aprobada en Meta) sigue siendo la del respaldo.
 * Variables, en orden: {{1}} nombre del lead (o identificador alternativo), {{2}} link al lead en admin-spa.
 */
class LeadVerificacionWhatsappService
{
    /** Nombre de la plantilla aprobada en Meta Business Manager. */
    const TEMPLATE_NAME = 'lead_verificacion_pendiente';

    /** @var WhatsappSendService Servicio encargado del envío efectivo a la API de Meta. */
    private $sender;

    /**
     * Constructor.
     *
     * @param WhatsappSendService $sender Instancia del servicio de envío WhatsApp.
     */
    public function __construct(WhatsappSendService $sender)
    {
        $this->sender = $sender;
    }

    /**
     * Notifica que hay una sugerencia pendiente de verificación: push a los setters (y campanita),
     * WhatsApp solo a quien no tiene device y tiene el flag.
     *
     * Si algún envío falla, se loguea el error y se sigue con los demás admins, para no perder
     * avisos por un único destinatario con problemas.
     *
     * @param Lead        $lead    Lead con sugerencia pendiente de verificación manual.
     * @param LeadMessage $message Mensaje sugerido que requiere aprobación del setter.
     *
     * @return array<int, string> Nombres de los admins avisados, por cualquiera de los dos canales, sin repetir.
     */
    public function notify(Lead $lead, LeadMessage $message): array
    {
        /* Construir identificador legible del lead: nombre > empresa > "Lead #ID". */
        $nombre_lead = '';
        if (! empty($lead->contact_name)) {
            $nombre_lead = $lead->contact_name;
        } elseif (! empty($lead->company_name)) {
            $nombre_lead = $lead->company_name;
        } else {
            $nombre_lead = "Lead #{$lead->id}";
        }

        /* Link directo al modal del lead en admin-spa (abre automáticamente vía query param lead_id). */
        $admin_spa_url = rtrim((string) config('services.admin_spa.url'), '/');
        $link_lead     = $admin_spa_url . '/leads?lead_id=' . $lead->id;

        /* Acumula los nombres de los admins avisados, por el canal que sea. */
        $notified = [];

        /* --- Canal 1: push a los setters (misma audiencia que el aviso de agendamiento) --- */
        $admin_ids  = LeadNotificationAudienceResolver::for_verificacion($lead);
        $con_device = LeadNotificationAudienceResolver::ids_con_device_de_push($admin_ids);
        $nombres    = Admin::whereIn('id', $admin_ids)->pluck('name', 'id');

        foreach ($admin_ids as $admin_id) {
            if (! in_array($admin_id, $con_device, true)) {
                /* Sin device no hay push. Si además tiene el flag y teléfono, lo alcanza el respaldo de abajo. */
                continue;
            }

            try {
                $this->send_push(
                    $admin_id,
                    'Mensaje esperando tu aprobación',
                    "{$nombre_lead}: el agente dejó un mensaje que necesita tu ok antes de salir.",
                    ['url' => '/leads?lead_id=' . $lead->id]
                );

                $notified[] = (string) ($nombres[$admin_id] ?? ('Admin #' . $admin_id));

                /* "Despachado", no "entregado": send_to_admin() es void y el resultado real de cada
                 * device lo loguea AdminPushNotificationService. */
                Log::info('LeadVerificacionWhatsappService: push despachado.', [
                    'lead_id'    => $lead->id,
                    'message_id' => $message->id,
                    'admin_id'   => $admin_id,
                ]);
            } catch (\Throwable $e) {
                Log::error('LeadVerificacionWhatsappService: error al enviar push.', [
                    'lead_id'  => $lead->id,
                    'admin_id' => $admin_id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        /* --- Canal 2: WhatsApp de respaldo, solo para quien no tiene device ---
         *
         * Los candidatos son los de siempre (flag prendido y teléfono cargado); lo nuevo es que a los
         * que tienen un device registrado se los saltea, porque ya recibieron (o deberían haber
         * recibido) el push. */
        $candidatos = Admin::where('notify_verificacion_whatsapp', true)
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '')
            ->get();

        $candidatos_con_device = LeadNotificationAudienceResolver::ids_con_device_de_push(
            $candidatos->pluck('id')->map(function ($id) {
                return (int) $id;
            })->all()
        );

        foreach ($candidatos as $admin) {
            if (in_array((int) $admin->id, $candidatos_con_device, true)) {
                Log::info('LeadVerificacionWhatsappService: admin con device de push, no se le manda el WhatsApp de respaldo.', [
                    'lead_id'  => $lead->id,
                    'admin_id' => $admin->id,
                ]);
                continue;
            }

            try {
                $this->sender->send_template(
                    (string) $admin->phone_number,
                    self::TEMPLATE_NAME,
                    [$nombre_lead, $link_lead]
                );

                $notified[] = (string) $admin->name;

                Log::info('LeadVerificacionWhatsappService: WhatsApp de respaldo enviado.', [
                    'lead_id'    => $lead->id,
                    'message_id' => $message->id,
                    'admin_id'   => $admin->id,
                ]);
            } catch (\Throwable $e) {
                /* Un fallo individual no debe interrumpir el envío a los demás admins. */
                Log::error('LeadVerificacionWhatsappService: error al notificar admin por WhatsApp.', [
                    'lead_id'  => $lead->id,
                    'admin_id' => $admin->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        if (empty($notified)) {
            Log::info('LeadVerificacionWhatsappService: nadie para avisar (sin devices en la audiencia y sin admins con el flag y teléfono).', [
                'lead_id'    => $lead->id,
                'message_id' => $message->id,
            ]);
        }

        return array_values(array_unique($notified));
    }

    /**
     * Envío efectivo del push.
     *
     * Aislado en su propio método para que los tests puedan sustituirlo: el envío real necesita las
     * claves VAPID de producción y una llamada de red al push service de Apple o Google, ninguna de
     * las dos disponible en la suite. Mismo molde que
     * {@see LeadVerificacionAgendamientoNotificationService::send_push()} y
     * {@see LeadMessagePushNotificationService::send_push()}.
     *
     * @param int                  $admin_id
     * @param string               $titulo
     * @param string               $cuerpo
     * @param array<string, mixed> $data
     *
     * @return void
     */
    protected function send_push(int $admin_id, string $titulo, string $cuerpo, array $data): void
    {
        AdminPushNotificationService::send_to_admin($admin_id, $titulo, $cuerpo, $data);
    }
}
