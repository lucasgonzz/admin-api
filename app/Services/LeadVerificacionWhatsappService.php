<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Lead;
use App\Models\LeadMessage;
use Illuminate\Support\Facades\Log;

/**
 * Envía notificaciones WhatsApp a los admins cuando una sugerencia del agente
 * queda marcada como requiere_verificacion = true y necesita aprobación manual.
 *
 * Usa send_template() para no depender de la ventana de 24hs de conversación activa.
 * La plantilla `lead_verificacion_pendiente` debe estar aprobada en Meta Business Manager.
 *
 * Variables de la plantilla (en orden):
 *   {{1}}  Nombre del lead (o identificador alternativo si no tiene nombre)
 *   {{2}}  Link directo al lead en admin-spa
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
     * Notifica a todos los admins suscritos que hay una sugerencia pendiente de verificación.
     *
     * Solo notifica admins que tengan:
     *   - notify_verificacion_whatsapp = true
     *   - phone_number cargado y no vacío
     *
     * Si algún envío falla, se loguea el error y se continúa con los demás admins
     * para no perder notificaciones por un único destinatario con problemas.
     *
     * @param Lead        $lead    Lead con sugerencia pendiente de verificación manual.
     * @param LeadMessage $message Mensaje sugerido que requiere aprobación del setter.
     *
     * @return void
     */
    public function notify(Lead $lead, LeadMessage $message): void
    {
        /* Obtener admins con flag activo y teléfono cargado. */
        $admins = Admin::where('notify_verificacion_whatsapp', true)
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '')
            ->get();

        if ($admins->isEmpty()) {
            Log::info('LeadVerificacionWhatsappService: sin admins suscritos con teléfono cargado.', [
                'lead_id'    => $lead->id,
                'message_id' => $message->id,
            ]);
            return;
        }

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

        /* Enviar la notificación a cada admin suscrito. */
        foreach ($admins as $admin) {
            try {
                $this->sender->send_template(
                    (string) $admin->phone_number,
                    self::TEMPLATE_NAME,
                    [$nombre_lead, $link_lead]
                );

                Log::info('LeadVerificacionWhatsappService: notificación enviada.', [
                    'lead_id'    => $lead->id,
                    'message_id' => $message->id,
                    'admin_id'   => $admin->id,
                ]);
            } catch (\Throwable $e) {
                /* Un fallo individual no debe interrumpir el envío a los demás admins. */
                Log::error('LeadVerificacionWhatsappService: error al notificar admin.', [
                    'lead_id'  => $lead->id,
                    'admin_id' => $admin->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }
    }
}
