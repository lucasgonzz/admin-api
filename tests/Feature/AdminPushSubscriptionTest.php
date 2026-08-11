<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminPushSubscription;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Alta, idempotencia y consulta de estado de las suscripciones Web Push.
 *
 * El caso del endpoint largo es el que importa: hasta el 11/8/2026 la columna era
 * varchar(191) y con strict mode MySQL abortaba con SQLSTATE[22001], así que ninguna
 * suscripción de iPhone llegaba a guardarse nunca.
 */
class AdminPushSubscriptionTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Endpoint de 327 caracteres con la forma de los que emite Apple.
     *
     * @return string
     */
    private function endpoint_largo(): string
    {
        return 'https://web.push.apple.com/' . str_repeat('A', 300);
    }

    /**
     * Crea un admin para autenticar con Sanctum.
     *
     * @param string $email
     *
     * @return Admin
     */
    private function crear_admin(string $email): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = $email;
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Un endpoint de 327 caracteres se guarda entero, sin truncar y sin error.
     *
     * @return void
     */
    public function test_subscribe_guarda_endpoint_largo_completo()
    {
        $admin    = $this->crear_admin('push-largo@test.local');
        $endpoint = $this->endpoint_largo();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/push/subscribe', [
            'endpoint' => $endpoint,
            'keys'     => ['p256dh' => 'clave-p256dh', 'auth' => 'clave-auth'],
        ]);

        $response->assertStatus(200);
        $response->assertJson(['ok' => true]);

        $fila = AdminPushSubscription::where('admin_id', $admin->id)->first();

        $this->assertNotNull($fila, 'La suscripción no quedó guardada.');
        $this->assertSame($endpoint, $fila->endpoint, 'El endpoint quedó truncado en la base.');
        $this->assertSame(327, mb_strlen($fila->endpoint));
        $this->assertSame(hash('sha256', $endpoint), $fila->endpoint_hash);
    }

    /**
     * Repetir el POST con el mismo endpoint no crea una segunda fila.
     *
     * De esto depende el re-registro automático del arranque de la app, que postea
     * el mismo endpoint en cada sesión.
     *
     * @return void
     */
    public function test_subscribe_es_idempotente_por_endpoint()
    {
        $admin    = $this->crear_admin('push-idempotente@test.local');
        $endpoint = $this->endpoint_largo();
        $payload  = [
            'endpoint' => $endpoint,
            'keys'     => ['p256dh' => 'clave-p256dh', 'auth' => 'clave-auth'],
        ];

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/push/subscribe', $payload)->assertStatus(200);
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/push/subscribe', $payload)->assertStatus(200);

        $this->assertSame(
            1,
            AdminPushSubscription::where('endpoint_hash', hash('sha256', $endpoint))->count(),
            'El segundo POST con el mismo endpoint creó una fila duplicada.'
        );
    }

    /**
     * subscription-status devuelve true solo para un endpoint registrado del admin autenticado.
     *
     * @return void
     */
    public function test_subscription_status_solo_es_true_para_el_device_propio_registrado()
    {
        $admin = $this->crear_admin('push-status@test.local');
        $otro  = $this->crear_admin('push-status-otro@test.local');

        $endpoint_propio = $this->endpoint_largo();
        $endpoint_ajeno  = 'https://web.push.apple.com/' . str_repeat('B', 300);

        /* Device propio registrado. */
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/push/subscribe', [
            'endpoint' => $endpoint_propio,
            'keys'     => ['p256dh' => 'p', 'auth' => 'a'],
        ])->assertStatus(200);

        /* Device de otro admin, registrado por ese otro admin. */
        $this->actingAs($otro, 'sanctum')->postJson('/api/admin/push/subscribe', [
            'endpoint' => $endpoint_ajeno,
            'keys'     => ['p256dh' => 'p', 'auth' => 'a'],
        ])->assertStatus(200);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/push/subscription-status', ['endpoint' => $endpoint_propio])
            ->assertStatus(200)
            ->assertJson(['registered' => true]);

        /* El device de otro admin no cuenta como registrado para este. */
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/push/subscription-status', ['endpoint' => $endpoint_ajeno])
            ->assertStatus(200)
            ->assertJson(['registered' => false]);

        /* Un endpoint que no existe en ningún lado. */
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/push/subscription-status', ['endpoint' => 'https://web.push.apple.com/inexistente'])
            ->assertStatus(200)
            ->assertJson(['registered' => false]);
    }

    /**
     * La baja borra la suscripción del device del admin autenticado.
     *
     * @return void
     */
    public function test_unsubscribe_borra_la_suscripcion_del_admin()
    {
        $admin    = $this->crear_admin('push-baja@test.local');
        $endpoint = $this->endpoint_largo();

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/push/subscribe', [
            'endpoint' => $endpoint,
            'keys'     => ['p256dh' => 'p', 'auth' => 'a'],
        ])->assertStatus(200);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/push/unsubscribe', ['endpoint' => $endpoint])
            ->assertStatus(200);

        $this->assertSame(
            0,
            AdminPushSubscription::where('endpoint_hash', hash('sha256', $endpoint))->count()
        );
    }
}
