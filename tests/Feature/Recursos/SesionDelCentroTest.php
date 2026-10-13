<?php

namespace Tests\Feature\Recursos;

use App\Models\ClientApi;
use App\Models\RecursosLink;
use App\Models\RecursosSesion;
use App\Models\RecursosUsuario;
use App\Services\RecursosLinkService;
use Illuminate\Http\Client\Request as PedidoHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Cómo se entra al centro de recursos y qué protege la sesión (misión centro-de-recursos-clientes,
 * 13/10/2026): el canje del pase contra el `empresa-api` del cliente (contrato C3), el link personal
 * del dueño, y el middleware propio.
 *
 * Lo que más importa, en orden:
 *  1. 🔴 El canje se le pide a la URL del cliente que tiene el ADMIN en su base, nunca a la `api` que
 *     manda el navegador (si no, cualquiera apunta el admin a un servidor propio que dice "soy el
 *     dueño" y se lleva la `api_key` del cliente en el header).
 *  2. 🔴 Un token del centro no entra al panel del admin (por eso la sesión no es Sanctum).
 *  3. El 404 de un cliente viejo (sin la ruta) es `pase_invalido`, y la página sigue sin sesión.
 */
class SesionDelCentroTest extends BaseDelCentroDeRecursos
{
    /**
     * Un pase de la forma que genera empresa-api (Str::random(64)).
     *
     * @return string
     */
    private function pase(): string
    {
        return Str::random(64);
    }

    /**
     * Respuesta 200 del canje (contrato C3).
     *
     * @param int      $id
     * @param string   $nombre
     * @param int|null $owner_id
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    private function canje_ok(int $id, string $nombre = 'Marcela Gómez', ?int $owner_id = null)
    {
        return Http::response([
            'user'  => ['id' => $id, 'name' => $nombre, 'email' => 'marcela@ferreteria.test', 'owner_id' => $owner_id],
            'video' => 'T1.29',
        ], 200);
    }

    /* ------------------------------------------------------------------------------------------
     | Pase
     |----------------------------------------------------------------------------------------- */

    public function test_un_pase_valido_crea_el_usuario_y_la_sesion(): void
    {
        $client = $this->crear_cliente('api-tornillo.test');

        Http::fake(['https://api-tornillo.test/api/admin-sync/recursos/canjear-pase' => $this->canje_ok(123)]);

        $respuesta = $this->postJson('/api/recursos/sesion/pase', [
            'pase' => $this->pase(),
            'api'  => 'https://api-tornillo.test',
        ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonStructure(['token', 'usuario' => ['nombre', 'nombre_pila', 'es_dueno', 'negocio']]);
        $respuesta->assertJsonPath('usuario.nombre', 'Marcela Gómez');
        $respuesta->assertJsonPath('usuario.nombre_pila', 'Marcela');
        $respuesta->assertJsonPath('usuario.es_dueno', true);
        $respuesta->assertJsonPath('usuario.negocio', 'Ferretería El Tornillo');

        $token = (string) $respuesta->json('token');
        $this->assertSame(64, strlen($token));

        $usuario = RecursosUsuario::where('client_id', $client->id)->where('empresa_user_id', 123)->first();
        $this->assertInstanceOf(RecursosUsuario::class, $usuario);
        $this->assertTrue($usuario->es_dueno);
        $this->assertNotNull($usuario->ultimo_ingreso_at);

        // Se guarda el hash, nunca el token en claro.
        $this->assertSame(1, RecursosSesion::where('token_hash', hash('sha256', $token))->count());
        $this->assertSame(0, RecursosSesion::where('token_hash', $token)->count());

        // Y el token sirve.
        $this->getJson('/api/recursos/yo', $this->con_sesion($token))
            ->assertStatus(200)
            ->assertJsonPath('usuario.nombre', 'Marcela Gómez');

        Http::assertSent(function (PedidoHttp $pedido) use ($client) {
            return $pedido->url() === 'https://api-tornillo.test/api/admin-sync/recursos/canjear-pase'
                && $pedido->method() === 'POST'
                && $pedido->header('X-Admin-Api-Key') === [$client->api_key]
                && strlen((string) $pedido['pase']) === 64;
        });
    }

    public function test_un_empleado_entra_como_no_dueno(): void
    {
        $this->crear_cliente('api-tornillo.test');

        Http::fake(['https://api-tornillo.test/*' => $this->canje_ok(124, 'Juan Pérez', 123)]);

        $this->postJson('/api/recursos/sesion/pase', ['pase' => $this->pase(), 'api' => 'https://api-tornillo.test'])
            ->assertStatus(200)
            ->assertJsonPath('usuario.es_dueno', false)
            ->assertJsonPath('usuario.nombre_pila', 'Juan');
    }

    /**
     * 🔴 La `api` del navegador solo elige el cliente. La llamada va a la URL que el admin tiene para ese
     * cliente (su API activa), con su clave: aunque el navegador mande la de otro frente del mismo
     * cliente, o una con path inventado, el admin le pega a la suya.
     */
    public function test_el_canje_va_a_la_url_de_la_base_del_admin_y_no_a_la_del_navegador(): void
    {
        $client = $this->crear_cliente('api-tornillo.test');

        // El segundo frente del mismo cliente (las dos carpetas del shared hosting).
        $otro               = new ClientApi();
        $otro->client_id    = $client->id;
        $otro->url          = 'https://api-tornillo2.test';
        $otro->path         = 'tornillo2/api';
        $otro->hosting_type = 'vps';
        $otro->save();

        Http::fake(['https://api-tornillo.test/api/admin-sync/recursos/canjear-pase' => $this->canje_ok(123)]);

        $this->postJson('/api/recursos/sesion/pase', [
            'pase' => $this->pase(),
            'api'  => 'https://API-Tornillo2.test/algo/raro/que/manda/el/navegador',
        ])->assertStatus(200);

        Http::assertSentCount(1);
        Http::assertNotSent(function (PedidoHttp $pedido) {
            return strpos($pedido->url(), 'tornillo2') !== false || strpos($pedido->url(), 'raro') !== false;
        });
    }

    public function test_un_404_del_cliente_es_pase_invalido_y_no_crea_nada(): void
    {
        $client = $this->crear_cliente('api-tornillo.test');

        Http::fake(['https://api-tornillo.test/*' => Http::response(['error' => 'pase_invalido'], 404)]);

        $this->postJson('/api/recursos/sesion/pase', ['pase' => $this->pase(), 'api' => 'https://api-tornillo.test'])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'pase_invalido']);

        $this->assertSame(0, RecursosUsuario::where('client_id', $client->id)->count());
    }

    /**
     * Un cliente con una versión sin la ruta contesta el 404 de Laravel (HTML o JSON genérico): para la
     * página es lo mismo, `pase_invalido`, y sigue sin sesión (compatibilidad hacia atrás, plan §2).
     */
    public function test_un_cliente_viejo_sin_la_ruta_da_pase_invalido(): void
    {
        $this->crear_cliente('api-viejo.test');

        Http::fake(['https://api-viejo.test/*' => Http::response('<html>Not Found</html>', 404)]);

        $this->postJson('/api/recursos/sesion/pase', ['pase' => $this->pase(), 'api' => 'https://api-viejo.test/public'])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'pase_invalido']);
    }

    public function test_un_host_desconocido_es_cliente_no_encontrado_sin_llamar_a_nadie(): void
    {
        $this->crear_cliente('api-tornillo.test');

        Http::fake();

        $this->postJson('/api/recursos/sesion/pase', ['pase' => $this->pase(), 'api' => 'https://api-desconocido.test'])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'cliente_no_encontrado']);

        $this->postJson('/api/recursos/sesion/pase', ['pase' => $this->pase(), 'api' => 'no es una url'])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'cliente_no_encontrado']);

        // Un host que CONTIENE al de un cliente no es ese cliente.
        $this->postJson('/api/recursos/sesion/pase', ['pase' => $this->pase(), 'api' => 'https://x-api-tornillo.test'])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'cliente_no_encontrado']);

        Http::assertNothingSent();
    }

    public function test_el_host_se_compara_sin_www_ni_mayusculas_y_tambien_contra_la_url_legacy(): void
    {
        $client = $this->crear_cliente('api-tornillo.test');
        ClientApi::where('client_id', $client->id)->update(['url' => 'https://api-otra-cosa.test']);
        $client->api_url = 'https://www.Api-Legacy.test';
        $client->save();

        Http::fake(['https://api-otra-cosa.test/*' => $this->canje_ok(9)]);

        $this->postJson('/api/recursos/sesion/pase', ['pase' => $this->pase(), 'api' => 'https://api-legacy.test/public'])
            ->assertStatus(200);
    }

    public function test_un_host_de_dos_clientes_se_desempata_por_el_path_y_si_no_alcanza_no_se_adivina(): void
    {
        $uno = $this->crear_cliente('comun.test', [], 'shared_hosting');
        $dos = $this->crear_cliente('comun.test', [], 'shared_hosting');

        ClientApi::where('client_id', $uno->id)->update(['url' => 'https://comun.test/uno/api']);
        ClientApi::where('client_id', $dos->id)->update(['url' => 'https://comun.test/dos/api/public']);

        Http::fake(['https://comun.test/dos/api/public/api/admin-sync/recursos/canjear-pase' => $this->canje_ok(5)]);

        // El navegador manda la base con /public: se compara sin él.
        $this->postJson('/api/recursos/sesion/pase', ['pase' => $this->pase(), 'api' => 'https://comun.test/dos/api/public'])
            ->assertStatus(200);

        $this->assertSame(1, RecursosUsuario::where('client_id', $dos->id)->count());
        $this->assertSame(0, RecursosUsuario::where('client_id', $uno->id)->count());

        // Mismo host, path que no es de ninguno: no se elige.
        $this->postJson('/api/recursos/sesion/pase', ['pase' => $this->pase(), 'api' => 'https://comun.test/tres/api'])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'cliente_no_encontrado']);
    }

    public function test_si_el_cliente_no_responde_bien_es_cliente_no_responde(): void
    {
        $this->crear_cliente('api-cae.test');

        Http::fake(['https://api-cae.test/*' => Http::sequence()
            ->push(['error' => 'algo'], 500)
            ->push(['message' => 'Unauthorized'], 401)
            ->push('ok pero no json', 200)
            ->push([], 302, ['Location' => 'https://otro-lado.test/'])]);

        foreach (range(1, 4) as $intento) {
            $this->postJson('/api/recursos/sesion/pase', ['pase' => $this->pase(), 'api' => 'https://api-cae.test'])
                ->assertStatus(422)
                ->assertExactJson(['error' => 'cliente_no_responde']);
        }

        // La redirección NO se siguió (la clave del cliente no viaja a otro host).
        Http::assertNotSent(function (PedidoHttp $pedido) {
            return strpos($pedido->url(), 'otro-lado') !== false;
        });
    }

    public function test_un_pase_sin_forma_de_pase_no_se_le_manda_al_cliente(): void
    {
        $this->crear_cliente('api-tornillo.test');

        Http::fake();

        foreach (['', 'corto', str_repeat('a', 200), 'con espacios ' . str_repeat('a', 60)] as $pase) {
            $this->postJson('/api/recursos/sesion/pase', ['pase' => $pase, 'api' => 'https://api-tornillo.test'])
                ->assertStatus(422)
                ->assertExactJson(['error' => 'pase_invalido']);
        }

        Http::assertNothingSent();
    }

    /**
     * Si el panel ya le había generado el link al dueño sin saber su id, el primer pase del dueño le
     * completa el id a ESA fila: es la misma persona y su avance va en un solo lugar.
     */
    public function test_el_dueno_creado_por_el_link_sin_id_se_completa_con_el_pase(): void
    {
        $client = $this->crear_cliente('api-tornillo.test');

        (new RecursosLinkService())->link_del_dueno($client);

        $dueno = RecursosUsuario::where('client_id', $client->id)->first();
        $this->assertNull($dueno->empresa_user_id);
        $this->assertTrue($dueno->es_dueno);

        Http::fake(['https://api-tornillo.test/*' => $this->canje_ok(77, 'Marcela Gómez', null)]);

        $this->postJson('/api/recursos/sesion/pase', ['pase' => $this->pase(), 'api' => 'https://api-tornillo.test'])
            ->assertStatus(200);

        $this->assertSame(1, RecursosUsuario::where('client_id', $client->id)->count());
        $this->assertSame(77, (int) $dueno->fresh()->empresa_user_id);
    }

    public function test_el_mismo_usuario_entra_siempre_a_la_misma_fila(): void
    {
        $client = $this->crear_cliente('api-tornillo.test');

        Http::fake(['https://api-tornillo.test/*' => Http::sequence()
            ->push(['user' => ['id' => 50, 'name' => 'Juan Pérez', 'email' => null, 'owner_id' => 1]], 200)
            ->push(['user' => ['id' => 50, 'name' => 'Juan Carlos Pérez', 'email' => null, 'owner_id' => 1]], 200)]);

        $this->postJson('/api/recursos/sesion/pase', ['pase' => $this->pase(), 'api' => 'https://api-tornillo.test'])->assertStatus(200);
        $this->postJson('/api/recursos/sesion/pase', ['pase' => $this->pase(), 'api' => 'https://api-tornillo.test'])->assertStatus(200);

        $this->assertSame(1, RecursosUsuario::where('client_id', $client->id)->count());
        $this->assertSame('Juan Carlos Pérez', RecursosUsuario::where('client_id', $client->id)->value('nombre'));
        $usuario_id = RecursosUsuario::where('client_id', $client->id)->value('id');
        $this->assertSame(2, RecursosSesion::where('recursos_usuario_id', $usuario_id)->count());
    }

    /* ------------------------------------------------------------------------------------------
     | Link personal del dueño
     |----------------------------------------------------------------------------------------- */

    public function test_el_link_vigente_abre_sesion_y_se_puede_reusar(): void
    {
        $client = $this->crear_cliente('api-tornillo.test');
        $link   = (new RecursosLinkService())->link_del_dueno($client);

        parse_str((string) parse_url($link['url'], PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('https://recursos.comerciocity.com/?t=', $link['url']);

        foreach ([1, 2] as $vez) {
            $respuesta = $this->postJson('/api/recursos/sesion/link', ['t' => $query['t']]);
            $respuesta->assertStatus(200)->assertJsonPath('usuario.es_dueno', true)->assertJsonPath('usuario.nombre', 'Marcela Gómez');
            $this->getJson('/api/recursos/yo', $this->con_sesion((string) $respuesta->json('token')))->assertStatus(200);
        }

        $this->assertSame(2, (int) RecursosLink::first()->usos);
    }

    public function test_un_link_vencido_o_inventado_es_link_invalido(): void
    {
        $client = $this->crear_cliente('api-tornillo.test');
        $link   = (new RecursosLinkService())->link_del_dueno($client);
        parse_str((string) parse_url($link['url'], PHP_URL_QUERY), $query);

        RecursosLink::query()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/recursos/sesion/link', ['t' => $query['t']])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'link_invalido']);

        $this->postJson('/api/recursos/sesion/link', ['t' => 'cualquiera'])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'link_invalido']);

        $this->postJson('/api/recursos/sesion/link', [])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'link_invalido']);
    }

    /* ------------------------------------------------------------------------------------------
     | Middleware
     |----------------------------------------------------------------------------------------- */

    public function test_sin_token_o_con_token_invalido_las_rutas_con_sesion_dan_401(): void
    {
        $this->getJson('/api/recursos/yo')->assertStatus(401)->assertExactJson(['error' => 'sin_sesion']);
        $this->getJson('/api/recursos/yo', $this->con_sesion('inventado'))->assertStatus(401)->assertExactJson(['error' => 'sin_sesion']);
        $this->postJson('/api/recursos/progreso', ['codigo' => 'T1.1'])->assertStatus(401)->assertExactJson(['error' => 'sin_sesion']);
        $this->postJson('/api/recursos/salir')->assertStatus(401)->assertExactJson(['error' => 'sin_sesion']);
    }

    public function test_un_token_vencido_da_401(): void
    {
        $client = $this->crear_cliente('api-tornillo.test');
        list(, $token) = $this->usuario_con_sesion($client);

        RecursosSesion::where('token_hash', hash('sha256', $token))->update(['expires_at' => now()->subSecond()]);

        $this->getJson('/api/recursos/yo', $this->con_sesion($token))->assertStatus(401)->assertExactJson(['error' => 'sin_sesion']);
    }

    /**
     * 🔴 Por esto la sesión del centro no es Sanctum: el token de un usuario de un cliente no puede
     * pasar el `auth:sanctum` del panel.
     */
    public function test_un_token_del_centro_no_entra_al_panel(): void
    {
        $client = $this->crear_cliente('api-tornillo.test');
        list(, $token) = $this->usuario_con_sesion($client);

        $this->getJson('/api/recursos/yo', $this->con_sesion($token))->assertStatus(200);

        $this->getJson('/api/admin/me', $this->con_sesion($token))->assertStatus(401);
        $this->getJson('/api/admin/client', $this->con_sesion($token))->assertStatus(401);
    }

    public function test_salir_invalida_solo_ese_token(): void
    {
        $client = $this->crear_cliente('api-tornillo.test');
        list($usuario, $token) = $this->usuario_con_sesion($client);
        $otro_token = (new \App\Services\RecursosSesionService())->crear_sesion($usuario);

        $this->postJson('/api/recursos/salir', [], $this->con_sesion($token))->assertStatus(200)->assertExactJson(['ok' => true]);

        $this->getJson('/api/recursos/yo', $this->con_sesion($token))->assertStatus(401);
        $this->getJson('/api/recursos/yo', $this->con_sesion($otro_token))->assertStatus(200);
    }

    /* ------------------------------------------------------------------------------------------
     | Limitador
     |----------------------------------------------------------------------------------------- */

    /**
     * Las rutas del centro no pasan por `throttle:api` (una sola cubeta por IP para todo /api), sino
     * por su limitador propio.
     */
    public function test_las_rutas_del_centro_no_usan_el_limitador_api(): void
    {
        foreach (Route::getRoutes() as $ruta) {
            if (strpos($ruta->uri(), 'api/recursos/') !== 0) {
                continue;
            }

            $middleware = app('router')->gatherRouteMiddleware($ruta);

            $this->assertNotContains('Illuminate\Routing\Middleware\ThrottleRequests:api', $middleware, $ruta->uri());

            $propios = array_filter($middleware, function ($nombre) {
                return is_string($nombre) && strpos($nombre, 'App\Http\Middleware\RecursosThrottle:') === 0;
            });
            $this->assertCount(1, $propios, $ruta->uri() . ' no tiene su limitador propio.');
        }
    }

    public function test_entrar_tiene_un_tope_de_diez_por_minuto_por_ip(): void
    {
        foreach (range(1, 10) as $intento) {
            $this->postJson('/api/recursos/sesion/link', ['t' => 'cualquiera'])->assertStatus(422);
        }

        $this->postJson('/api/recursos/sesion/link', ['t' => 'cualquiera'])->assertStatus(429);

        // Otra cubeta: el catálogo sigue andando.
        $this->getJson('/api/recursos/catalogo')->assertStatus(200);
    }
}
