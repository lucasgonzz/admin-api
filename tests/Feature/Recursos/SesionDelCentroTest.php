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
     * 🔴 La `api` del navegador solo ELIGE el frente. La llamada va a la URL de ese frente tal como está
     * en la base del admin (normalizada con su hosting_type), con la clave del cliente: el path que
     * inventa el navegador no viaja.
     *
     * Y va al frente que matcheó, no al activo: el pase vive en la instancia que lo emitió, y si el
     * frente activo corre una versión vieja, preguntarle a él daría un `pase_invalido` engañoso.
     */
    public function test_el_canje_va_al_frente_que_matcheo_con_la_url_de_la_base_del_admin(): void
    {
        $client = $this->crear_cliente('api-tornillo.test');

        // El segundo frente del mismo cliente (las dos carpetas del shared hosting), NO activo.
        $otro               = new ClientApi();
        $otro->client_id    = $client->id;
        $otro->url          = 'https://api-tornillo2.test';
        $otro->path         = 'tornillo2/api';
        $otro->hosting_type = 'shared_hosting';
        $otro->save();

        Http::fake(['https://api-tornillo2.test/public/api/admin-sync/recursos/canjear-pase' => $this->canje_ok(123)]);

        $this->postJson('/api/recursos/sesion/pase', [
            'pase' => $this->pase(),
            'api'  => 'https://API-Tornillo2.test/algo/raro/que/manda/el/navegador',
        ])->assertStatus(200);

        Http::assertSentCount(1);
        Http::assertSent(function (PedidoHttp $pedido) use ($client) {
            return $pedido->url() === 'https://api-tornillo2.test/public/api/admin-sync/recursos/canjear-pase'
                && $pedido->header('X-Admin-Api-Key') === [$client->api_key];
        });
        Http::assertNotSent(function (PedidoHttp $pedido) {
            return strpos($pedido->url(), 'raro') !== false || strpos($pedido->url(), 'https://api-tornillo.test') === 0;
        });
    }

    /**
     * Dos clientes con el MISMO frente (uno cargado apuntando a la API del otro): es la misma
     * instancia. Se canjea una vez (probando la clave de cada uno: un 401 no gasta el pase) y gana el
     * cliente cuyo `user_id` es el dueño del usuario que volvió. Si ninguno o más de uno, no se adivina.
     */
    public function test_un_frente_de_dos_clientes_se_resuelve_por_el_dueno_del_usuario(): void
    {
        $a = $this->crear_cliente('api-compartida.test', ['user_id' => 100]);
        $b = $this->crear_cliente('api-compartida.test', ['user_id' => 200]);

        // La instancia solo reconoce la clave de B.
        Http::fake(['https://api-compartida.test/*' => function ($pedido) use ($b) {
            if ($pedido->header('X-Admin-Api-Key') !== [$b->api_key]) {
                return Http::response(['message' => 'Unauthorized'], 401);
            }

            return Http::response(['user' => ['id' => 205, 'name' => 'Juan Pérez', 'email' => null, 'owner_id' => 200]], 200);
        }]);

        $this->postJson('/api/recursos/sesion/pase', ['pase' => $this->pase(), 'api' => 'https://api-compartida.test'])
            ->assertStatus(200)
            ->assertJsonPath('usuario.nombre', 'Juan Pérez');

        $this->assertSame(1, RecursosUsuario::where('client_id', $b->id)->where('empresa_user_id', 205)->count());
        $this->assertSame(0, RecursosUsuario::where('client_id', $a->id)->count());

        // Un usuario cuyo dueño no es ninguno de los dos: no se elige.
        Http::swap(new \Tests\Fakes\HttpFactorySinSalida());
        Http::fake(['https://api-compartida.test/*' => Http::response(['user' => ['id' => 305, 'name' => 'X', 'owner_id' => 300]], 200)]);

        $this->postJson('/api/recursos/sesion/pase', ['pase' => $this->pase(), 'api' => 'https://api-compartida.test'])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'cliente_no_encontrado']);

        $this->assertSame(0, RecursosUsuario::where('client_id', $a->id)->count());
        $this->assertSame(1, RecursosUsuario::where('client_id', $b->id)->count());
    }

    /**
     * 🔴 Base compartida: el usuario que devuelve el canje tiene que ser de ESTE dueño. Si no, es
     * `pase_invalido` y no se crea ni se completa ningún usuario del centro.
     */
    public function test_en_una_base_compartida_el_usuario_tiene_que_ser_del_dueno(): void
    {
        $grupo = \App\Models\SharedDatabaseGroup::create(['name' => 'u767360347_empresa de prueba']);
        $client = $this->crear_cliente('api-compartida.test', ['user_id' => 300, 'shared_database_group_id' => $grupo->id]);

        // El dueño ya tenía el link (fila sin id): un usuario ajeno no se la puede completar.
        (new RecursosLinkService())->link_del_dueno($client);
        \App\Models\RecursosUsuario::where('client_id', $client->id)->update(['empresa_user_id' => null]);

        Http::fake(['https://api-compartida.test/*' => Http::sequence()
            ->push(['user' => ['id' => 401, 'name' => 'De otro comercio', 'owner_id' => null]], 200)
            ->push(['user' => ['id' => 450, 'name' => 'Empleado de otro', 'owner_id' => 400]], 200)
            ->push(['user' => ['id' => 301, 'name' => 'Empleado propio', 'owner_id' => 300]], 200)]);

        foreach ([1, 2] as $vez) {
            $this->postJson('/api/recursos/sesion/pase', ['pase' => $this->pase(), 'api' => 'https://api-compartida.test'])
                ->assertStatus(422)
                ->assertExactJson(['error' => 'pase_invalido']);
        }

        $this->assertSame(1, RecursosUsuario::where('client_id', $client->id)->count());
        $this->assertNull(RecursosUsuario::where('client_id', $client->id)->value('empresa_user_id'));

        $this->postJson('/api/recursos/sesion/pase', ['pase' => $this->pase(), 'api' => 'https://api-compartida.test'])
            ->assertStatus(200)
            ->assertJsonPath('usuario.nombre', 'Empleado propio');
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
        // Ficha sin nombre de contacto: el link crea al dueño como "Dueño", nunca con el negocio.
        $client = $this->crear_cliente('api-tornillo.test', ['name' => '']);

        (new RecursosLinkService())->link_del_dueno($client);

        $dueno = RecursosUsuario::where('client_id', $client->id)->first();
        $this->assertNull($dueno->empresa_user_id);
        $this->assertTrue($dueno->es_dueno);
        $this->assertSame('Dueño', $dueno->nombre);
        $this->assertStringNotContainsString('Tornillo', $dueno->nombre);

        Http::fake(['https://api-tornillo.test/*' => $this->canje_ok(77, 'Marcela Gómez', null)]);

        $this->postJson('/api/recursos/sesion/pase', ['pase' => $this->pase(), 'api' => 'https://api-tornillo.test'])
            ->assertStatus(200)
            ->assertJsonPath('usuario.nombre', 'Marcela Gómez');

        // La misma fila, con el id, el nombre y el mail que devolvió el sistema.
        $this->assertSame(1, RecursosUsuario::where('client_id', $client->id)->count());
        $dueno->refresh();
        $this->assertSame(77, (int) $dueno->empresa_user_id);
        $this->assertSame('Marcela Gómez', $dueno->nombre);
        $this->assertSame('marcela@ferreteria.test', $dueno->email);
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

    /**
     * Y al revés: un token de Sanctum de un admin no es una sesión del centro.
     */
    public function test_un_token_de_admin_no_entra_al_centro(): void
    {
        $admin           = new \App\Models\Admin();
        $admin->name     = 'Admin con token';
        $admin->email    = 'token-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        $token = $admin->createToken('prueba')->plainTextToken;

        $this->getJson('/api/admin/me', $this->con_sesion($token))->assertStatus(200);
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/recursos/yo', $this->con_sesion($token))->assertStatus(401)->assertExactJson(['error' => 'sin_sesion']);
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

    /**
     * 🔴 Un token inventado no tiene cubeta propia: si la tuviera, mandar un token distinto en cada
     * pedido sería cupo infinito. Cae en la cubeta de la IP; una sesión vigente sigue con la suya.
     */
    public function test_los_tokens_invalidos_comparten_la_cubeta_de_la_ip(): void
    {
        $client = $this->crear_cliente('api-tornillo.test');
        list(, $valido) = $this->usuario_con_sesion($client);

        foreach (range(1, 60) as $intento) {
            $this->getJson('/api/recursos/yo', $this->con_sesion('inventado-' . $intento))->assertStatus(401);
        }

        $this->getJson('/api/recursos/yo', $this->con_sesion('inventado-61'))->assertStatus(429);
        $this->getJson('/api/recursos/yo')->assertStatus(429);

        // La sesión de verdad tiene su propia cubeta.
        $this->getJson('/api/recursos/yo', $this->con_sesion($valido))->assertStatus(200);
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
