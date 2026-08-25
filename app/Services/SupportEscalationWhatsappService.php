<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Log;

/**
 * Avisa por WhatsApp a los operadores cuando el agente escala un ticket de soporte.
 *
 * Hasta ahora el escalado solo emitía un evento Pusher: si el operador no tenía el admin
 * abierto en ese momento, el ticket quedaba esperando y nadie se enteraba. Es el mismo agujero
 * que ya había resuelto `LeadEscalationWhatsappService` del lado de leads, y esta clase es su
 * espejo para soporte.
 *
 * Usa `send_template()` y no texto libre porque el aviso va al teléfono de un operador, con el
 * que casi nunca hay una conversación abierta: fuera de la ventana de 24hs, Meta rechaza
 * cualquier cosa que no sea una plantilla aprobada.
 *
 * Variables de la plantilla, en orden:
 *   {{1}}  Nombre del cliente (o del contacto que escribió)
 *   {{2}}  Motivo del escalado, que redacta el propio agente
 *   {{3}}  Link directo al ticket en admin-spa
 */
class SupportEscalationWhatsappService
{
    /** Nombre de la plantilla aprobada en Meta Business Manager. */
    const TEMPLATE_NAME = 'soporte_escalacion_humana';

    /** @var WhatsappSendService Servicio encargado del envío efectivo a la API de Meta. */
    private $sender;

    /**
     * @param WhatsappSendService $sender Instancia del servicio de envío WhatsApp.
     */
    public function __construct(WhatsappSendService $sender)
    {
        $this->sender = $sender;
    }

    /**
     * Notifica a los operadores suscritos que el agente escaló un ticket.
     *
     * Solo notifica a los admins que tengan `notify_support_escalation_whatsapp` prendido y un
     * `phone_number` cargado. Un fallo con un destinatario no corta el envío a los demás: perder
     * los tres avisos porque uno tiene el teléfono mal cargado sería peor que el problema.
     *
     * @param SupportTicket $ticket Ticket que el agente no pudo resolver.
     * @param string        $motivo Motivo breve que redactó el agente.
     *
     * @return array<int, string> Nombres de los operadores efectivamente notificados.
     */
    public function notify(SupportTicket $ticket, string $motivo): array
    {
        $admins = Admin::where('notify_support_escalation_whatsapp', true)
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '')
            ->get();

        if ($admins->isEmpty()) {
            Log::channel('daily')->info('SupportEscalationWhatsappService: sin operadores suscritos con teléfono cargado.', [
                'ticket_id' => $ticket->id,
            ]);

            return [];
        }

        $ticket->loadMissing('client', 'client_employee');
        $client_name = $ticket->resolve_contact_display_name();

        $motivo_limpio = trim($motivo) !== ''
            ? trim($motivo)
            : 'El agente no pudo resolver la consulta y pidió revisión humana.';

        $admin_spa_url = rtrim((string) config('services.admin_spa.url'), '/');
        $link_ticket = $admin_spa_url . '/soporte?ticket_id=' . $ticket->id;

        $notified = [];

        foreach ($admins as $admin) {
            try {
                $whatsapp_message_id = $this->sender->send_template(
                    (string) $admin->phone_number,
                    self::TEMPLATE_NAME,
                    [
                        $this->sanitize_variable($client_name),
                        $this->sanitize_variable($motivo_limpio),
                        $link_ticket,
                    ],
                    'es_AR',
                    'Escalado de soporte del ticket #' . $ticket->id
                );

                if ($whatsapp_message_id === null) {
                    Log::channel('daily')->warning('SupportEscalationWhatsappService: Kapso no confirmó el aviso.', [
                        'ticket_id' => $ticket->id,
                        'admin_id'  => $admin->id,
                        'error'     => $this->sender->last_send_error,
                    ]);

                    continue;
                }

                $notified[] = (string) $admin->name;

                Log::channel('daily')->info('SupportEscalationWhatsappService: aviso de escalado enviado.', [
                    'ticket_id' => $ticket->id,
                    'admin_id'  => $admin->id,
                ]);
            } catch (\Throwable $exception) {
                Log::channel('daily')->error('SupportEscalationWhatsappService: error al avisar a un operador.', [
                    'ticket_id' => $ticket->id,
                    'admin_id'  => $admin->id,
                    'error'     => $exception->getMessage(),
                ]);
            }
        }

        return $notified;
    }

    /**
     * Adapta un texto para que Meta lo acepte como parámetro de plantilla.
     *
     * Meta rechaza el envío entero si un parámetro trae saltos de línea, tabs o cuatro o más
     * espacios seguidos. El motivo lo redacta el agente en texto libre, así que puede traerlos.
     *
     * @param string $value Texto crudo.
     *
     * @return string
     */
    private function sanitize_variable(string $value): string
    {
        $flattened = preg_replace('/[\r\n\t]+/u', ' ', $value);
        if ($flattened === null) {
            $flattened = $value;
        }

        $collapsed = preg_replace('/\s{2,}/u', ' ', $flattened);
        if ($collapsed === null) {
            $collapsed = $flattened;
        }

        $collapsed = trim($collapsed);

        if (mb_strlen($collapsed) > 300) {
            $collapsed = rtrim(mb_substr($collapsed, 0, 299)) . '…';
        }

        return $collapsed;
    }
}
