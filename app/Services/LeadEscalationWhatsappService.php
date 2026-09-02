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
 *   {{2}}  Motivo de la escalación (breve, provisto por Claude en motivo_intervencion)
 *   {{3}}  Link directo al lead en admin-spa (abre el modal de conversación)
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
     * A quién le llega depende de si el llamador pasa lista explícita:
     *   - SIN lista: admins con notify_lead_escalation_whatsapp = true (comportamiento histórico).
     *   - CON lista: exactamente esos admins, sin mirar el flag — la lista ya es la decisión de
     *     ruteo, resuelta por rol desde el 2/9/2026. Ver el comentario adentro del método.
     *
     * En los dos casos se exige phone_number cargado y no vacío.
     *
     * Si algún envío falla, se loguea el error y se continúa con los demás admins
     * para no perder notificaciones por un único destinatario con problemas.
     *
     * @param Lead            $lead            Lead cuya conversación no pudo resolver el agente.
     * @param string          $motivo          Motivo breve provisto por Claude (campo motivo_intervencion).
     * @param array<int, int> $solo_admin_ids  Restringe el envío a estos admins. Por defecto null:
     *                                         van todos los suscritos, que es el comportamiento
     *                                         histórico y el que usan los llamadores viejos. Desde
     *                                         el 27/8/2026 el escalado avisa por Web Push y usa
     *                                         este parámetro para dejar el WhatsApp solo para los
     *                                         admins sin ningún device registrado
     *                                         ({@see EscalationPushNotificationService}); mandar
     *                                         los dos canales a todos sería pagar una plantilla
     *                                         por un aviso que ya llegó al teléfono.
     *
     * @return array<int, string> Nombres de los admins efectivamente notificados.
     */
    public function notify(Lead $lead, string $motivo, ?array $solo_admin_ids = null): array
    {
        /* Admins con teléfono cargado. A QUIÉN se le manda depende de si vino lista explícita. */
        $query = Admin::query()
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '');

        /* Lista explícita vacía: no hay a quién avisar. Se distingue de null —que significa "sin
         * restricción"— porque un whereIn con array vacío no devuelve filas, pero llegar hasta la
         * consulta para eso es trabajo al pedo en un camino que corre dentro del webhook. */
        if ($solo_admin_ids !== null) {
            if (empty($solo_admin_ids)) {
                return [];
            }

            /* 🔴 La lista YA ES la decisión de ruteo, no un filtro sobre el flag.
             *
             * Desde el 2/9/2026 los destinatarios del escalado de un lead los resuelve
             * LeadNotificationAudienceResolver por rol (los `es_setter`), y un setter puede no
             * tener marcado notify_lead_escalation_whatsapp — que era la decisión de ruteo del
             * mundo viejo. Si acá volviéramos a filtrar por ese flag, ese setter perdería el
             * WhatsApp, y encima es alguien que ya sabemos que NO tiene device (por eso está en
             * esta lista): se quedaría sin ningún aviso por ningún canal. */
            $query->whereIn('id', $solo_admin_ids);
        } else {
            /* Sin lista: comportamiento histórico intacto. Es el que usan los tres avisos al
             * closer que reciclan esta misma plantilla sin ser escalados. */
            $query->where('notify_lead_escalation_whatsapp', true);
        }

        $admins = $query->get();

        if ($admins->isEmpty()) {
            Log::info('LeadEscalationWhatsappService: sin admins suscritos con teléfono cargado.', [
                'lead_id' => $lead->id,
            ]);
            return [];
        }

        /* Acumula los nombres de admins a los que se envió exitosamente. */
        $notified = [];

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
                    [$nombre_lead, $motivo_limpio, $link_lead]
                );

                /* Registrar al admin como notificado exitosamente. */
                $notified[] = $admin->name;

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

        return $notified;
    }
}
