<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminPushSubscription;
use App\Services\AdminPushNotificationService;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Minishlink\WebPush\MessageSentReport;
use Tests\TestCase;

/**
 * Cuándo se borra una suscripción push y cuándo no.
 *
 * Hasta el 11/8/2026 se borraba ante CUALQUIER fallo de envío: un 429 o un 500 transitorio
 * del push service de Apple dejaba al admin sin notificaciones para siempre, sin ningún
 * aviso. Ahora solo se borra con 404/410, que es "este device ya no existe".
 */
class AdminPushSubscriptionCleanupTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Crea un admin con un device registrado y devuelve el endpoint.
     *
     * @param string $email
     *
     * @return array{0: Admin, 1: string}
     */
    private function admin_con_device(string $email): array
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = $email;
        $admin->password = bcrypt('secret');
        $admin->save();

        $endpoint = 'https://web.push.apple.com/' . str_repeat('A', 300);

        $sub                = new AdminPushSubscription();
        $sub->admin_id      = $admin->id;
        $sub->endpoint      = $endpoint;
        $sub->endpoint_hash = hash('sha256', $endpoint);
        $sub->p256dh        = 'p';
        $sub->auth          = 'a';
        $sub->save();

        return [$admin, $endpoint];
    }

    /**
     * Arma el report que devuelve minishlink/web-push para un envío fallido.
     *
     * @param string $endpoint
     * @param int    $status_code Status con el que contestó el push service.
     *
     * @return MessageSentReport
     */
    private function report_fallido(string $endpoint, int $status_code): MessageSentReport
    {
        return new MessageSentReport(
            new Request('POST', $endpoint),
            new Response($status_code),
            false,
            'fallo simulado ' . $status_code
        );
    }

    /**
     * Un 410 Gone es una suscripción muerta: la fila se borra.
     *
     * @return void
     */
    public function test_un_410_borra_la_suscripcion()
    {
        list($admin, $endpoint) = $this->admin_con_device('cleanup-410@test.local');

        AdminPushNotificationService::handle_send_report(
            (int) $admin->id,
            $this->report_fallido($endpoint, 410)
        );

        $this->assertSame(
            0,
            AdminPushSubscription::where('endpoint_hash', hash('sha256', $endpoint))->count(),
            'Un 410 tiene que borrar la suscripción muerta.'
        );
    }

    /**
     * Un 404 Not Found también es una suscripción muerta.
     *
     * @return void
     */
    public function test_un_404_borra_la_suscripcion()
    {
        list($admin, $endpoint) = $this->admin_con_device('cleanup-404@test.local');

        AdminPushNotificationService::handle_send_report(
            (int) $admin->id,
            $this->report_fallido($endpoint, 404)
        );

        $this->assertSame(
            0,
            AdminPushSubscription::where('endpoint_hash', hash('sha256', $endpoint))->count()
        );
    }

    /**
     * Un 500 del push service es transitorio: la suscripción se conserva.
     *
     * @return void
     */
    public function test_un_500_no_borra_la_suscripcion()
    {
        list($admin, $endpoint) = $this->admin_con_device('cleanup-500@test.local');

        AdminPushNotificationService::handle_send_report(
            (int) $admin->id,
            $this->report_fallido($endpoint, 500)
        );

        $this->assertSame(
            1,
            AdminPushSubscription::where('endpoint_hash', hash('sha256', $endpoint))->count(),
            'Un 500 transitorio no puede borrar el device del admin.'
        );
    }

    /**
     * Un 429 (rate limit) tampoco borra: es el caso más probable de un pico de envíos.
     *
     * @return void
     */
    public function test_un_429_no_borra_la_suscripcion()
    {
        list($admin, $endpoint) = $this->admin_con_device('cleanup-429@test.local');

        AdminPushNotificationService::handle_send_report(
            (int) $admin->id,
            $this->report_fallido($endpoint, 429)
        );

        $this->assertSame(
            1,
            AdminPushSubscription::where('endpoint_hash', hash('sha256', $endpoint))->count()
        );
    }

    /**
     * Un fallo sin respuesta (timeout de red, DNS caído) tampoco borra: sin status code
     * no hay ninguna evidencia de que el device haya dejado de existir.
     *
     * @return void
     */
    public function test_un_fallo_sin_respuesta_no_borra_la_suscripcion()
    {
        list($admin, $endpoint) = $this->admin_con_device('cleanup-timeout@test.local');

        $report = new MessageSentReport(new Request('POST', $endpoint), null, false, 'timeout');

        AdminPushNotificationService::handle_send_report((int) $admin->id, $report);

        $this->assertSame(
            1,
            AdminPushSubscription::where('endpoint_hash', hash('sha256', $endpoint))->count()
        );
    }

    /**
     * Un envío exitoso no toca nada.
     *
     * @return void
     */
    public function test_un_envio_exitoso_conserva_la_suscripcion()
    {
        list($admin, $endpoint) = $this->admin_con_device('cleanup-ok@test.local');

        $report = new MessageSentReport(new Request('POST', $endpoint), new Response(201), true, 'OK');

        AdminPushNotificationService::handle_send_report((int) $admin->id, $report);

        $this->assertSame(
            1,
            AdminPushSubscription::where('endpoint_hash', hash('sha256', $endpoint))->count()
        );
    }
}
