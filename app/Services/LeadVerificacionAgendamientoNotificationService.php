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
 *   1. Push a los admins con es_setter = true, más los suscritos por campanita a ese lead
 *      (LeadNotificationAudienceResolver::for_verificacion). Hasta el 2/9/2026 iba a Admin::all():
 *      con el closer trabajando en paralelo eso significaba despertarlo por una tarea que no es
 *      suya. Va a los setters SIEMPRE, incluso con el lead en closer_activo — el que aprueba lo
 *      que sale es el setter. AdminPushNotificationService ya hace no-op silencioso si un admin
 *      no tiene ningún device registrado.
 *   2. WhatsApp SOLO a admins con notify_verificacion_agendamiento_whatsapp = true — opcional,
 *      separado de notify_verificacion_whatsapp (que es exclusivo del motivo "error"). Este canal
 *      no se tocó: es un opt-in explícito y no un rol.
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

        /* --- Canal 1: push a los setters (ruteo por rol, 2/9/2026) ---
         *
         * Antes iba a Admin::all(), que era "que se entere todo el mundo". Ahora va a los setters
         * SIEMPRE, aunque el lead ya esté en closer_activo: un mensaje que espera aprobación no es
         * un aviso de "mirá esto", es una tarea, y el que la hace es el setter, no el closer. Los
         * suscritos por campanita se suman (lo resuelve el resolver).
         *
         * send_to_admin() ya es no-op silencioso para el que no tiene device registrado. */
        $admin_ids = LeadNotificationAudienceResolver::for_verificacion($lead);

        foreach ($admin_ids as $admin_id) {
            try {
                $this->send_push(
                    $admin_id,
                    'Lead coordinando agenda — revisar mensaje',
                    "{$nombre_lead} está coordinando la demo. Hay un mensaje esperando tu aprobación.",
                    ['url' => '/leads?lead_id=' . $lead->id]
                );
            } catch (\Throwable $e) {
                Log::error('LeadVerificacionAgendamientoNotificationService: error al enviar push.', [
                    'lead_id'  => $lead->id,
                    'admin_id' => $admin_id,
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

    /**
     * Envío efectivo del push.
     *
     * Aislado en su propio método para que los tests puedan sustituirlo: el envío real necesita las
     * claves VAPID de producción y una llamada de red al push service de Apple o Google, ninguna de
     * las dos disponible en la suite. Mismo criterio y mismo molde que
     * {@see LeadMessagePushNotificationService::send_push()} y {@see EscalationPushNotificationService}.
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
