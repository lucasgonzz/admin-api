<?php

namespace App\Services;

use App\Models\AdminPushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\MessageSentReport;
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
        foreach ($web_push->flush() as $report) {
            self::handle_send_report($admin_id, $report);
        }
    }

    /**
     * Decide qué hacer con el resultado del envío a un device.
     *
     * Está separado del envío para poder probarlo: el criterio de "cuándo se borra una
     * suscripción" es la parte con consecuencias (un borrado de más deja al admin sin
     * notificaciones para siempre) y no se puede ejercitar sin salir a la red.
     *
     * @param int               $admin_id
     * @param MessageSentReport $report Resultado del envío a un endpoint.
     *
     * @return void
     */
    public static function handle_send_report(int $admin_id, MessageSentReport $report): void
    {
        if ($report->isSuccess()) {
            return;
        }

        // En minishlink/web-push v7 el report expone getEndpoint() directamente.
        $endpoint = $report->getEndpoint();

        /* Suscripción realmente muerta: el push service contestó 404 o 410, o sea que
         * ese device ya no existe y no va a volver. isSubscriptionExpired() es de
         * minishlink/web-push v7 (MessageSentReport::isSubscriptionExpired, que mira
         * exactamente esos dos status codes). Solo en este caso se borra la fila. */
        if ($report->isSubscriptionExpired()) {
            Log::channel('daily')->warning('Web Push: suscripción expirada, se elimina el device.', [
                'admin_id' => $admin_id,
                'endpoint' => $endpoint,
                'reason'   => $report->getReason(),
            ]);

            AdminPushSubscription::where('endpoint_hash', AdminPushSubscription::hash_endpoint($endpoint))
                ->delete();

            return;
        }

        /* Cualquier otro fallo (429 por rate limit, 500 del push service, timeout de red)
         * es transitorio: la suscripción se conserva. Borrarla acá dejaba al admin sin
         * notificaciones para siempre por una caída de cinco minutos de Apple, y sin
         * ningún aviso — la pantalla de Cuenta le seguía mostrando el badge verde. */
        Log::channel('daily')->warning('Web Push: envío fallido transitorio, se conserva la suscripción.', [
            'admin_id' => $admin_id,
            'endpoint' => $endpoint,
            'reason'   => $report->getReason(),
        ]);
    }
}
