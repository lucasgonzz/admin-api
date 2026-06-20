<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\Log;

/**
 * Envía un WhatsApp de aviso a los admins suscritos cuando llega un mensaje de un lead.
 *
 * Usa send_template() con la plantilla cc_notif_mensaje_lead para no depender
 * de la ventana de conversación activa de 24hs.
 *
 * Variables de la plantilla (en orden):
 *   {{1}}  Nombre del lead (o "Lead #ID" si no tiene nombre)
 *   {{2}}  Preview del mensaje entrante (máx. 120 caracteres)
 *   {{3}}  Link directo al lead en admin-spa
 *
 * Solo notifica admins que tengan phone_number cargado.
 * Si no hay admins suscritos o ninguno tiene teléfono, el método termina silenciosamente.
 */
class LeadMessageNotificationWhatsappService
{
    /** Nombre de la plantilla aprobada en Meta Business Manager. */
    const TEMPLATE_NAME = 'cc_notif_mensaje_lead';

    /**
     * Servicio de envío de WhatsApp reutilizado por toda la app.
     *
     * @var WhatsappSendService
     */
    private $sender;

    /**
     * @param WhatsappSendService $sender Inyectado o instanciado directamente según el contexto.
     */
    public function __construct(WhatsappSendService $sender)
    {
        $this->sender = $sender;
    }

    /**
     * Notifica a todos los admins suscritos al lead que llegó un mensaje nuevo.
     *
     * Pasos internos:
     * 1. Carga admins suscritos con phone_number válido desde la tabla pivot.
     * 2. Construye las variables de la plantilla (nombre, preview, link).
     * 3. Llama a send_template() por cada admin; los errores individuales se loguean sin romper el flujo.
     *
     * @param Lead   $lead    Lead que envió el mensaje.
     * @param string $content Contenido del mensaje (puede ser transcripción de audio, imagen, etc.)
     *
     * @return void
     */
    public function notify(Lead $lead, string $content): void
    {
        /* Admins suscritos al lead que tengan teléfono cargado. */
        $admins = $lead->notification_admins()
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '')
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        /* Identificador legible del lead: nombre de contacto, empresa o fallback "Lead #ID". */
        $nombre = '';
        if (! empty($lead->contact_name)) {
            $nombre = $lead->contact_name;
        } elseif (! empty($lead->company_name)) {
            $nombre = $lead->company_name;
        } else {
            $nombre = "Lead #{$lead->id}";
        }

        /* Preview del contenido: máximo 120 caracteres para no saturar la notificación. */
        $preview = mb_strimwidth(trim($content), 0, 120, '…');

        /* Link directo al lead en admin-spa para acceso rápido desde el WhatsApp. */
        $admin_spa_url = rtrim((string) config('services.admin_spa.url'), '/');
        $link          = $admin_spa_url . '/leads?lead_id=' . $lead->id;

        foreach ($admins as $admin) {
            try {
                $this->sender->send_template(
                    (string) $admin->phone_number,
                    self::TEMPLATE_NAME,
                    [$nombre, $preview, $link]
                );

                Log::info('LeadMessageNotificationWhatsappService: notificación enviada.', [
                    'lead_id'  => $lead->id,
                    'admin_id' => $admin->id,
                ]);
            } catch (\Throwable $e) {
                /* Error individual: se loguea pero no interrumpe las notificaciones al resto de admins. */
                Log::error('LeadMessageNotificationWhatsappService: error al notificar admin.', [
                    'lead_id'  => $lead->id,
                    'admin_id' => $admin->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }
    }
}
