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
 * SOBRE EL FLAG. Se reutilizan `notify_lead_escalation_whatsapp` y
 * `notify_support_escalation_whatsapp`, que ya existen y significan "quiero enterarme de los
 * escalados". El nombre quedó atado al canal de aquel entonces, pero agregar una columna nueva
 * obligaría a Lucas a volver a marcar en la pantalla de Usuarios admin algo que ya tiene marcado,
 * y el precio de eso es perderse escalados durante la ventana en que nadie la marcó.
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

        return $this->notificar(
            'notify_support_escalation_whatsapp',
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

        return $this->notificar(
            'notify_lead_escalation_whatsapp',
            'Lead: ' . $nombre,
            $cuerpo,
            '/leads?lead_id=' . $lead->id,
            ['lead_id' => (int) $lead->id]
        );
    }

    /**
     * Reparte el aviso entre push y WhatsApp según qué admins tengan device registrado.
     *
     * @param string               $flag   Columna de `admins` que marca la suscripción a escalados.
     * @param string               $titulo Título de la notificación.
     * @param string               $cuerpo Texto de la notificación.
     * @param string               $ruta   Ruta dentro del admin, ya con su query param.
     * @param array<string, mixed> $extra  Datos adicionales del payload.
     *
     * @return array{push: array<int, int>, sin_device: array<int, int>}
     */
    private function notificar(string $flag, string $titulo, string $cuerpo, string $ruta, array $extra): array
    {
        $resultado = ['push' => [], 'sin_device' => []];

        $admin_ids = Admin::where($flag, true)->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();

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
}
