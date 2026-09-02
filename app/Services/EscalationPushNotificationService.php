<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\AdminPushSubscription;
use App\Models\Lead;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Log;

/**
 * Avisa por Web Push cuando un agente escala una conversación a una persona.
 *
 * POR QUÉ EXISTE. Hasta el 27/8/2026 el escalado avisaba por dos canales y ninguno llegaba al
 * teléfono de forma confiable: un evento de Pusher —que solo sirve si alguien tiene el admin
 * abierto justo en ese momento— y un WhatsApp con plantilla aprobada en Meta. El push llega con
 * la aplicación cerrada, no cuesta por mensaje y no depende de que una plantilla siga aprobada.
 * Decisión de Lucas del 27/8/2026, al pedir que los agentes escalen todo lo que no puedan
 * respaldar con el repositorio: si el escalado va a ser el camino normal y no la excepción, el
 * aviso tiene que llegar sí o sí.
 *
 * RED DE SEGURIDAD, y es deliberada. A un admin suscrito a los escalados que no tenga NINGÚN
 * device registrado se le manda el WhatsApp de siempre; si no, no se enteraría de nada. No son
 * dos canales en paralelo: para un admin con device registrado el WhatsApp no sale, aunque el
 * push falle. Un push que falla es un problema de entrega y queda en el log del push service; un
 * admin sin device es un problema de alcance y no se ve en ningún lado. Es el mismo criterio ya
 * probado en {@see LeadMessagePushNotificationService}, y se copia a propósito en vez de
 * inventar uno nuevo.
 *
 * SOBRE EL FLAG, y acá los dos casos dejaron de ser el mismo (2/9/2026):
 *
 *   - Tickets de soporte: siguen saliendo por `notify_support_escalation_whatsapp`. Un ticket no
 *     tiene estados de pipeline ni dueño por rol, así que no hay nada que rutear.
 *   - Leads: pasaron a resolverse por ROL, con LeadNotificationAudienceResolver::for_escalado(),
 *     que manda siempre a los `es_setter`. `notify_lead_escalation_whatsapp` era una suscripción
 *     opt-in disfrazada de ruteo: un setter que no marcó ese checkbox no se enteraba de ningún
 *     escalado, que es exactamente el modo de falla que este servicio existe para evitar. El flag
 *     no se borró: lo siguen usando los tres avisos al closer que reciclan la plantilla
 *     `lead_escalacion_humana` sin ser escalados (llamada agendada, seguimiento post-demo, demo
 *     realizada), y esos no se tocaron.
 *
 * A diferencia del envío por WhatsApp, para el push NO se exige `phone_number` cargado: un device
 * registrado no necesita teléfono, y filtrar por él dejaría sin aviso justo a quien mejor podría
 * recibirlo.
 */
class EscalationPushNotificationService
{
    /**
     * Avisa del escalado de un ticket de soporte.
     *
     * @param SupportTicket $ticket Ticket que el agente no pudo resolver.
     * @param string        $motivo Motivo breve, el que va a leer el operador.
     *
     * @return array{push: array<int, int>, sin_device: array<int, int>} Admin ids por canal.
     */
    public function notificar_ticket(SupportTicket $ticket, string $motivo): array
    {
        $ticket->loadMissing('client', 'client_employee');

        $titulo = 'Soporte: ' . $ticket->resolve_contact_display_name();

        $cuerpo = trim($motivo) !== ''
            ? trim($motivo)
            : 'El agente no pudo resolver la consulta y pidió revisión humana.';

        /* Los tickets de soporte siguen saliendo por el flag opt-in: no tienen estados de pipeline
         * ni dueño por rol, así que el ruteo por rol del 2/9/2026 no aplica acá. */
        return $this->notificar(
            $this->ids_por_flag('notify_support_escalation_whatsapp'),
            $titulo,
            $cuerpo,
            '/soporte?ticket_id=' . $ticket->id,
            ['ticket_id' => (int) $ticket->id]
        );
    }

    /**
     * Avisa del escalado de una conversación de lead.
     *
     * @param Lead   $lead   Lead cuya conversación no pudo resolver el agente.
     * @param string $motivo Motivo breve.
     *
     * @return array{push: array<int, int>, sin_device: array<int, int>} Admin ids por canal.
     */
    public function notificar_lead(Lead $lead, string $motivo): array
    {
        /* Mismo orden de resolución del nombre que usa el aviso por WhatsApp, para que el admin
         * vea el mismo nombre por cualquiera de los dos canales. */
        if (! empty($lead->contact_name)) {
            $nombre = (string) $lead->contact_name;
        } elseif (! empty($lead->company_name)) {
            $nombre = (string) $lead->company_name;
        } else {
            $nombre = 'Lead #' . $lead->id;
        }

        $cuerpo = trim($motivo) !== ''
            ? trim($motivo)
            : 'El agente detectó que la conversación requiere atención humana.';

        /* Ruteo por rol (2/9/2026): el escalado a humano de un lead va SIEMPRE a los setters, esté
         * el lead donde esté — incluso en closer_activo. Antes salía por
         * notify_lead_escalation_whatsapp, que es una suscripción opt-in y no un rol: un setter
         * nuevo que no marcó ese checkbox no se enteraba de ningún escalado. El flag sigue
         * gobernando los tickets de soporte y los tres avisos al closer que reciclan la misma
         * plantilla (llamada agendada, seguimiento post-demo, demo realizada), que no son escalados. */
        return $this->notificar(
            LeadNotificationAudienceResolver::for_escalado($lead),
            'Lead: ' . $nombre,
            $cuerpo,
            '/leads?lead_id=' . $lead->id,
            ['lead_id' => (int) $lead->id]
        );
    }

    /**
     * Reparte el aviso entre push y WhatsApp según qué admins tengan device registrado.
     *
     * @param array<int, int>      $admin_ids Destinatarios ya resueltos por el llamador: los tickets
     *                                        los sacan de un flag opt-in, los leads del rol (ver
     *                                        LeadNotificationAudienceResolver). Este método no decide
     *                                        a quién avisar, solo por qué canal.
     * @param string               $titulo    Título de la notificación.
     * @param string               $cuerpo    Texto de la notificación.
     * @param string               $ruta      Ruta dentro del admin, ya con su query param.
     * @param array<string, mixed> $extra     Datos adicionales del payload.
     *
     * @return array{push: array<int, int>, sin_device: array<int, int>}
     */
    private function notificar(array $admin_ids, string $titulo, string $cuerpo, string $ruta, array $extra): array
    {
        $resultado = ['push' => [], 'sin_device' => []];

        if (empty($admin_ids)) {
            return $resultado;
        }

        /* Qué admins tienen al menos un device, en una sola consulta. */
        $con_device = AdminPushSubscription::whereIn('admin_id', $admin_ids)
            ->distinct()
            ->pluck('admin_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();

        /* Preview acotado: una notificación con un párrafo entero queda cortada por el sistema
         * operativo de cualquier forma, y el texto completo está en el ticket. */
        $preview = mb_strimwidth($cuerpo, 0, 160, '…');

        $data = array_merge(['url' => $ruta], $extra);

        foreach ($admin_ids as $admin_id) {
            if (! in_array($admin_id, $con_device, true)) {
                $resultado['sin_device'][] = $admin_id;
                continue;
            }

            try {
                $this->send_push($admin_id, $titulo, $preview, $data);

                $resultado['push'][] = $admin_id;

                /* "Despachado", no "entregado": send_to_admin() es void y el resultado real de
                 * cada device lo resuelve el report del push service, que se loguea aparte en
                 * AdminPushNotificationService. Este log no sabe si llegó. */
                Log::channel('daily')->info('EscalationPushNotificationService: push despachado.', [
                    'admin_id' => $admin_id,
                    'ruta'     => $ruta,
                ]);
            } catch (\Throwable $exception) {
                /* Un fallo individual no interrumpe el aviso al resto. */
                Log::channel('daily')->error('EscalationPushNotificationService: error al enviar push.', [
                    'admin_id' => $admin_id,
                    'error'    => $exception->getMessage(),
                ]);
            }
        }

        if (! empty($resultado['sin_device'])) {
            Log::channel('daily')->info('EscalationPushNotificationService: admins sin device, van por WhatsApp.', [
                'admin_ids' => $resultado['sin_device'],
            ]);
        }

        return $resultado;
    }

    /**
     * Envío efectivo del push.
     *
     * Aislado en su propio método para que los tests puedan sustituirlo: el envío real necesita
     * las claves VAPID de producción y una llamada de red al push service de Apple o Google,
     * ninguna de las dos disponible en la suite.
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

    /**
     * Ids de los admins suscritos a un canal de escalado por flag opt-in.
     *
     * Quedó como método propio cuando notificar() dejó de resolver destinatarios (2/9/2026): los
     * leads ahora los saca del rol y los tickets siguen sacándolos de acá.
     *
     * @param string $flag Columna de `admins`.
     *
     * @return array<int, int>
     */
    private function ids_por_flag(string $flag): array
    {
        return Admin::where($flag, true)
            ->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();
    }
}
