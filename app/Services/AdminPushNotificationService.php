<?php

namespace App\Services;

use App\Models\AdminPushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Envío de Web Push a devices registrados de un admin.
 *
 * Uso típico: AdminPushNotificationService::send_to_admin($admin_id, 'Título', 'Cuerpo', ['url' => '/leads/123']);
 */
class AdminPushNotificationService
{
    /**
     * Envía una notificación push a todos los devices activos de un admin.
     * Si un endpoint devuelve error de expiración/invalidez, se elimina la suscripción.
     *
     * @param int                  $admin_id
     * @param string               $title
     * @param string               $body
     * @param array<string, mixed> $data Payload adicional (ej. ['url' => '/leads/123'] para deep link).
     *
     * @return void
     */
    public static function send_to_admin(int $admin_id, string $title, string $body, array $data = []): void
    {
        // Devices registrados del admin. Si no hay ninguno, no-op silencioso.
        $subscriptions = AdminPushSubscription::where('admin_id', $admin_id)->get();
        if ($subscriptions->isEmpty()) {
            return;
        }

        // Cliente Web Push firmado con las claves VAPID del backend.
        $web_push = new WebPush([
            'VAPID' => [
                'subject'    => config('services.vapid.subject'),
                'publicKey'  => config('services.vapid.public_key'),
                'privateKey' => config('services.vapid.private_key'),
            ],
        ]);

        // Payload JSON que recibe el service worker del navegador.
        $payload = json_encode(array_merge(['title' => $title, 'body' => $body], $data));

        // Encolar una notificación por cada device del admin.
        foreach ($subscriptions as $sub) {
            $web_push->queueNotification(
                Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'keys'     => ['p256dh' => $sub->p256dh, 'auth' => $sub->auth],
                ]),
                $payload
            );
        }

        // Enviar todo el lote; cada report corresponde a un device.
        // En minishlink/web-push v7 el report expone getEndpoint() directamente.
        foreach ($web_push->flush() as $report) {
            if (! $report->isSuccess()) {
                $endpoint = $report->getEndpoint();

                Log::channel('daily')->warning('Web Push: envío fallido, eliminando suscripción.', [
                    'admin_id' => $admin_id,
                    'endpoint' => $endpoint,
                    'reason'   => $report->getReason(),
                ]);

                // Endpoint expirado o inválido: limpiar la suscripción muerta.
                AdminPushSubscription::where('endpoint', $endpoint)->delete();
            }
        }
    }
}
