<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Lead;
use App\Models\LeadMessage;
use Illuminate\Support\Facades\Log;

/**
 * Notifica cuando un mensaje requiere verificación por el motivo "agendamiento" (el lead está
 * coordinando la agenda de la demo, no porque hubo un error — ver LeadAiService, tramo
 * solicita_disponibilidad..demo_pendiente_de_terminar).
 *
 * Dos canales, independientes:
 *   1. Push a TODOS los admins con suscripción push activa (AdminPushNotificationService ya
 *      hace no-op silencioso si un admin no tiene ningún device registrado). Es el "hacer
 *      ruido" — no depende de ningún flag, si tenés push activado en el navegador lo recibís.
 *   2. WhatsApp SOLO a admins con notify_verificacion_agendamiento_whatsapp = true — opcional,
 *      separado de notify_verificacion_whatsapp (que es exclusivo del motivo "error").
 *
 * Reutiliza la plantilla lead_verificacion_pendiente ya aprobada en Meta (mismo contenido que
 * el aviso de error tiene sentido para el admin: "hay una sugerencia pendiente para el lead X").
 */
class LeadVerificacionAgendamientoNotificationService
{
    /** Nombre de la plantilla aprobada en Meta Business Manager (reutilizada, no es una nueva). */
    const TEMPLATE_NAME = 'lead_verificacion_pendiente';

    /** @var WhatsappSendService Servicio encargado del envío efectivo a la API de Meta. */
    private $sender;

    /**
     * Constructor.
     *
     * @param WhatsappSendService|null $sender Instancia del servicio de envío WhatsApp.
     */
    public function __construct(?WhatsappSendService $sender = null)
    {
        $this->sender = $sender ?? new WhatsappSendService();
    }

    /**
     * Notifica a admins: push siempre, WhatsApp solo a los suscritos al flag de agendamiento.
     *
     * @param Lead        $lead    Lead con sugerencia pendiente de verificación (motivo agendamiento).
     * @param LeadMessage $message Mensaje sugerido que requiere aprobación.
     *
     * @return array<int, string> Nombres de los admins notificados por WhatsApp (el push no se cuenta acá).
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

        /* --- Canal 1: push a todos los admins (no-op silencioso si no tienen device registrado) --- */
        $todos_los_admins = Admin::all();
        foreach ($todos_los_admins as $admin) {
            try {
                AdminPushNotificationService::send_to_admin(
                    (int) $admin->id,
                    'Lead coordinando agenda — revisar mensaje',
                    "{$nombre_lead} está coordinando la demo. Hay un mensaje esperando tu aprobación.",
                    ['url' => '/leads?lead_id=' . $lead->id]
                );
            } catch (\Throwable $e) {
                Log::error('LeadVerificacionAgendamientoNotificationService: error al enviar push.', [
                    'lead_id'  => $lead->id,
                    'admin_id' => $admin->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        /* --- Canal 2: WhatsApp solo a admins con el flag nuevo activo y teléfono cargado --- */
        $admins_whatsapp = Admin::where('notify_verificacion_agendamiento_whatsapp', true)
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '')
            ->get();

        /* Acumula los nombres de admins a los que se envió WhatsApp exitosamente. */
        $notified = [];
        foreach ($admins_whatsapp as $admin) {
            try {
                $this->sender->send_template(
                    (string) $admin->phone_number,
                    self::TEMPLATE_NAME,
                    [$nombre_lead, $link_lead]
                );
                $notified[] = $admin->name;
                Log::info('LeadVerificacionAgendamientoNotificationService: WhatsApp enviado.', [
                    'lead_id'  => $lead->id,
                    'admin_id' => $admin->id,
                ]);
            } catch (\Throwable $e) {
                /* Un fallo individual no debe interrumpir el envío a los demás admins. */
                Log::error('LeadVerificacionAgendamientoNotificationService: error al notificar admin por WhatsApp.', [
                    'lead_id'  => $lead->id,
                    'admin_id' => $admin->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $notified;
    }
}
