<?php

namespace App\Services;

use App\Models\AdminPushSubscription;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;

/**
 * Avisa por Web Push cuando un lead manda un mensaje.
 *
 * Es el canal de la campanita de la conversación (decisión de Lucas, 11/8/2026). Entre el
 * 20/6/2026 y esa fecha el canal había sido WhatsApp (prompt 063); volvió a ser push porque
 * es lo que llega al teléfono con la app cerrada sin costo por mensaje ni plantilla aprobada.
 *
 * A QUIÉN LE LLEGA (cambió el 2/9/2026, ver LeadNotificationAudienceResolver). Antes iba derecho
 * a los suscritos por campanita. Ahora la campanita sigue siendo lo que DISPARA el aviso —si nadie
 * está suscrito a ese lead no se notifica a nadie, igual que siempre— pero QUIÉN lo recibe lo
 * decide el rol: si el lead está en closer_activo es del closer, en cualquier otro estado es del
 * setter. Los suscritos por campanita se suman en los dos casos.
 *
 * RED DE SEGURIDAD: a un admin destinatario que no tenga NINGÚN device registrado en
 * admin_push_subscriptions se le manda el WhatsApp de siempre — si no, no se enteraría de
 * nada. No son dos canales en paralelo: para un admin con device registrado el WhatsApp no
 * sale, aunque el envío del push falle. Un push que falla es un problema de entrega y se ve
 * en el log; un admin sin device es un problema de alcance y no se ve en ningún lado.
 */
class LeadMessagePushNotificationService
{
    /**
     * Notifica que llegó un mensaje nuevo del lead a quien corresponda por rol y campanita.
     *
     * @param Lead   $lead    Lead que envió el mensaje.
     * @param string $content Contenido del mensaje (puede ser transcripción de audio, imagen, etc.)
     *
     * @return void
     */
    public function notify(Lead $lead, string $content): void
    {
        /* Destinatarios ya decididos: rol según el estado del lead, más los suscritos por
         * campanita. Devuelve vacío si nadie tiene la campanita prendida — el disparo del aviso
         * sigue siendo la suscripción, el rol solo elige a quién. */
        $admin_ids = LeadNotificationAudienceResolver::for_mensaje_entrante($lead);

        if (empty($admin_ids)) {
            return;
        }

        /* Identificador legible del lead: nombre de contacto, empresa o fallback "Lead #ID".
         * Misma resolución que usa el aviso por WhatsApp, para que el admin vea el mismo
         * nombre por cualquiera de los dos canales. */
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

        /* Deep link que abre el modal del lead: lo resuelve el notificationclick de src/sw.js. */
        $data = ['url' => '/leads?lead_id=' . $lead->id];

        /* Qué destinatarios tienen al menos un device, en una sola consulta: esto corre adentro
         * del webhook de Meta, que es un camino sincrónico y con timeout. */
        $admin_ids_con_device = AdminPushSubscription::whereIn('admin_id', $admin_ids)
            ->distinct()
            ->pluck('admin_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();

        /* Admins sin ningún device registrado: no pueden recibir push, van por WhatsApp. */
        $admin_ids_sin_device = [];

        foreach ($admin_ids as $admin_id) {
            if (! in_array($admin_id, $admin_ids_con_device, true)) {
                $admin_ids_sin_device[] = $admin_id;
                continue;
            }

            try {
                $this->send_push($admin_id, $nombre, $preview, $data);

                /* "Despachado", no "enviado": send_to_admin() es void y el resultado real de
                 * cada device lo resuelve el report del push service, que se loguea aparte en
                 * AdminPushNotificationService. Este log no sabe si llegó. */
                Log::info('LeadMessagePushNotificationService: push despachado.', [
                    'lead_id'  => $lead->id,
                    'admin_id' => $admin_id,
                ]);
            } catch (\Throwable $e) {
                /* Un fallo individual no interrumpe el aviso al resto de los admins. */
                Log::error('LeadMessagePushNotificationService: error al enviar push.', [
                    'lead_id'  => $lead->id,
                    'admin_id' => $admin_id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        if (empty($admin_ids_sin_device)) {
            return;
        }

        Log::info('LeadMessagePushNotificationService: admins sin device registrado, se avisa por WhatsApp.', [
            'lead_id'   => $lead->id,
            'admin_ids' => $admin_ids_sin_device,
        ]);

        $this->send_whatsapp_fallback($lead, $content, $admin_ids_sin_device);
    }

    /**
     * Envío efectivo del push.
     *
     * Está aislado en su propio método para que los tests puedan sustituirlo: el envío
     * real necesita las claves VAPID de producción y una llamada de red al push service
     * de Apple/Google, ninguna de las dos disponible en la suite.
     *
     * @param int                  $admin_id
     * @param string               $title
     * @param string               $body
     * @param array<string, mixed> $data
     *
     * @return void
     */
    protected function send_push(int $admin_id, string $title, string $body, array $data): void
    {
        AdminPushNotificationService::send_to_admin($admin_id, $title, $body, $data);
    }

    /**
     * Envío del WhatsApp de respaldo a los admins que no tienen device registrado.
     *
     * Mismo motivo que send_push() para estar aislado: el envío real le pega a la API
     * de Meta.
     *
     * @param Lead            $lead
     * @param string          $content
     * @param array<int, int> $admin_ids
     *
     * @return void
     */
    protected function send_whatsapp_fallback(Lead $lead, string $content, array $admin_ids): void
    {
        (new LeadMessageNotificationWhatsappService(new WhatsappSendService()))
            ->notify($lead, $content, $admin_ids);
    }
}
