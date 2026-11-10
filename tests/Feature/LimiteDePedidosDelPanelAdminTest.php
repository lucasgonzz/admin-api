<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Los topes de pedidos de /api/admin (misión admin-too-many-attempts, 10/11/2026).
 *
 * Con 60 pedidos por minuto, pasar por los tickets de soporte cada 2 segundos apilaba carteles rojos
 * de «Too Many Attempts.» y las conversaciones dejaban de cargar. Le pega a endpoints reales porque
 * lo que se cuida es el armado entero: que `auth` corra antes que el throttle, y que las rutas
 * firmadas hayan salido de verdad de la cubeta por IP.
 */
class LimiteDePedidosDelPanelAdminTest extends TestCase
{
    use DatabaseTransactions;

    /** Tope general que rige sin API_RATE_LIMIT_PER_MINUTE, fuera de `local`. */
    private const TOPE_GENERAL = 60;

    /**
     * @return Admin Operador nuevo, ya autenticado para los próximos pedidos.
     */
    private function entrar_como_operador()
    {
        $admin = Admin::create([
            'name' => 'Operador de soporte',
            'email' => Str::random(15) . '@test.local',
            'password' => bcrypt(Str::random(32)),
        ]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Un pedido autenticado a un endpoint real del panel.
     */
    private function pedir_del_panel()
    {
        return $this->getJson('/api/admin/me');
    }

    /**
     * Un pedido sin usuario que también cuelga del limitador `api`.
     */
    private function pedir_sin_usuario()
    {
        return $this->postJson('/api/admin/login', []);
    }

    public function test_un_operador_supera_los_60_por_minuto_sin_429()
    {
        $this->entrar_como_operador();

        for ($pedido = 1; $pedido <= self::TOPE_GENERAL + 10; $pedido++) {
            $this->pedir_del_panel()->assertOk()->assertHeader('X-RateLimit-Limit', '1200');
        }
    }

    /**
     * Con un token real y sin `Sanctum::actingAs`, que deja al guard resuelto antes del pedido: si
     * el throttle corriera antes que `auth`, `$request->user()` sería null y caería en la cubeta
     * general por IP. Por eso se olvidan los guards en cada vuelta.
     */
    public function test_con_token_real_el_auth_corre_antes_que_el_limitador()
    {
        $admin = Admin::create([
            'name' => 'Operador con token',
            'email' => Str::random(15) . '@test.local',
            'password' => bcrypt(Str::random(32)),
        ]);
        $token = $admin->createToken('prueba-limite')->plainTextToken;

        for ($pedido = 1; $pedido <= self::TOPE_GENERAL + 5; $pedido++) {
            $this->app['auth']->forgetGuards();
            $this->app['auth']->setDefaultDriver('web');

            $this->withHeaders(['Authorization' => 'Bearer ' . $token])
                ->getJson('/api/admin/me')
                ->assertOk()
                ->assertHeader('X-RateLimit-Limit', '1200');
        }
    }

    public function test_con_el_tope_agotado_recibe_429_con_retry_after()
    {
        /* Un pedido arriba del general: es el valor más bajo que el `max()` deja configurar. */
        config(['services.panel_admin.rate_limit_per_minute' => self::TOPE_GENERAL + 1]);
        $this->entrar_como_operador();

        for ($pedido = 1; $pedido <= self::TOPE_GENERAL + 1; $pedido++) {
            $this->pedir_del_panel()->assertOk();
        }

        $rechazado = $this->pedir_del_panel();
        $rechazado->assertStatus(429);
        /* El SPA decide reintentar mirando este texto exacto, y espera lo que diga este header. */
        $rechazado->assertJson(['message' => 'Too Many Attempts.']);
        $rechazado->assertHeader('Retry-After');
    }

    public function test_el_tope_general_funciona_de_piso()
    {
        config(['services.panel_admin.rate_limit_per_minute' => 1]);
        $this->entrar_como_operador();

        $this->pedir_del_panel()->assertOk()->assertHeader('X-RateLimit-Limit', (string) self::TOPE_GENERAL);
    }

    public function test_dos_operadores_no_comparten_cubeta()
    {
        config(['services.panel_admin.rate_limit_per_minute' => self::TOPE_GENERAL + 1]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.70']);

        $this->entrar_como_operador();
        for ($pedido = 1; $pedido <= self::TOPE_GENERAL + 1; $pedido++) {
            $this->pedir_del_panel()->assertOk();
        }
        $this->pedir_del_panel()->assertStatus(429);

        /* Misma IP, otra persona: la oficina entera ya no se reparte una sola cubeta. */
        $this->entrar_como_operador();
        $this->pedir_del_panel()->assertOk()->assertHeader('X-RateLimit-Remaining', (string) self::TOPE_GENERAL);
    }

    public function test_sin_usuario_sigue_limitado_por_ip_a_su_valor()
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.71']);

        for ($pedido = 1; $pedido <= self::TOPE_GENERAL; $pedido++) {
            $this->pedir_sin_usuario()->assertStatus(422)->assertHeader('X-RateLimit-Limit', (string) self::TOPE_GENERAL);
        }
        $this->pedir_sin_usuario()->assertStatus(429);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.72']);
        $this->pedir_sin_usuario()->assertStatus(422);
    }

    /**
     * @dataProvider rutas_firmadas
     */
    public function test_los_adjuntos_firmados_no_gastan_la_cubeta_por_ip($nombre_de_ruta)
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.73']);
        $url_firmada = URL::signedRoute($nombre_de_ruta, ['id' => 999999999]);

        /* 404 y no 403: pasó la firma y llegó al controller, que no encuentra ese adjunto. */
        for ($pedido = 1; $pedido <= self::TOPE_GENERAL + 5; $pedido++) {
            $this->getJson($url_firmada)->assertStatus(404)->assertHeader('X-RateLimit-Limit', '600');
        }

        /* La cubeta por IP sigue intacta: este es recién el primer pedido que la toca. */
        $this->pedir_sin_usuario()->assertHeader('X-RateLimit-Remaining', (string) (self::TOPE_GENERAL - 1));
    }

    /**
     * @dataProvider rutas_firmadas
     */
    public function test_los_adjuntos_siguen_exigiendo_firma($nombre_de_ruta)
    {
        $url_firmada = URL::signedRoute($nombre_de_ruta, ['id' => 999999999]);
        $url_sin_firma = strtok($url_firmada, '?');

        $this->getJson($url_sin_firma)->assertStatus(403);
        $this->getJson($url_firmada . 'adulterada')->assertStatus(403);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rutas_firmadas()
    {
        return [
            'adjunto de lead' => ['lead.message.attachment.file'],
            'adjunto de soporte' => ['support.message.attachment.file'],
        ];
    }
}
