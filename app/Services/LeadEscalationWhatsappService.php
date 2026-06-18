<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;

/**
 * Envía notificaciones WhatsApp a los admins cuando el agente (Claude) escala
 * una conversación de lead que no puede resolver.
 *
 * Usa send_template() para no depender de la ventana de 24hs de conversación activa.
 * La plantilla `lead_escalacion_humana` debe estar aprobada en Meta Business Manager.
 *
 * Variables de la plantilla (en orden):
 *   {{1}}  Nombre del lead (o identificador alternativo si no tiene nombre)
 *   {{2}}  Teléfono del lead
 *   {{3}}  Motivo de la escalación (breve, provisto por Claude en motivo_intervencion)
 */
class LeadEscalationWhatsappService
{
    /** Nombre de la plantilla aprobada en Meta Business Manager. */
    const TEMPLATE_NAME = 'lead_escalacion_humana';

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
     * Notifica a todos los admins suscritos que una conversación de lead fue escalada.
     *
     * Solo notifica admins que tengan:
     *   - notify_lead_escalation_whatsapp = true
     *   - phone_number cargado y no vacío
     *
     * Si algún envío falla, se loguea el error y se continúa con los demás admins
     * para no perder notificaciones por un único destinatario con problemas.
     *
     * @param Lead   $lead   Lead cuya conversación no pudo resolver el agente.
     * @param string $motivo Motivo breve provisto por Claude (campo motivo_intervencion).
     *
     * @return void
     */
    public function notify(Lead $lead, string $motivo): void
    {
        /* Obtener admins con flag activo y teléfono cargado. */
        $admins = Admin::where('notify_lead_escalation_whatsapp', true)
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '')
            ->get();

        if ($admins->isEmpty()) {
            Log::info('LeadEscalationWhatsappService: sin admins suscritos con teléfono cargado.', [
                'lead_id' => $lead->id,
            ]);
            return;
        }

        /* Construir identificador legible del lead: nombre > empresa > teléfono > ID. */
        $nombre_lead = '';
        if (! empty($lead->contact_name)) {
            $nombre_lead = $lead->contact_name;
        } elseif (! empty($lead->company_name)) {
            $nombre_lead = $lead->company_name;
        } else {
            $nombre_lead = "Lead #{$lead->id}";
        }

        /* Teléfono del lead como string para la variable de la plantilla. */
        $telefono_lead = ! empty($lead->phone) ? (string) $lead->phone : 'sin teléfono';

        /* Motivo de la escalación: usar el de Claude o un texto genérico si está vacío. */
        $motivo_limpio = $motivo !== ''
            ? $motivo
            : 'El agente detectó que la conversación requiere atención humana.';

        /* Enviar la notificación a cada admin suscrito. */
        foreach ($admins as $admin) {
            try {
                $this->sender->send_template(
                    (string) $admin->phone_number,
                    self::TEMPLATE_NAME,
                    [$nombre_lead, $telefono_lead, $motivo_limpio]
                );

                Log::info('LeadEscalationWhatsappService: notificación de escalación enviada.', [
                    'lead_id'   => $lead->id,
                    'admin_id'  => $admin->id,
                    'admin_tel' => $admin->phone_number,
                ]);
            } catch (\Throwable $e) {
                /* Un fallo individual no debe interrumpir el envío a los demás admins. */
                Log::error('LeadEscalationWhatsappService: error al notificar admin.', [
                    'lead_id'  => $lead->id,
                    'admin_id' => $admin->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }
    }
}
