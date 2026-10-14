<?php

namespace Tests\Feature\Recursos;

use App\Http\Middleware\RecursosEscritura;
use App\Http\Middleware\RecursosSesion as RecursosSesionMiddleware;
use App\Http\Middleware\RecursosThrottle;
use App\Models\Client;
use App\Models\RecursosEncuesta;
use App\Models\RecursosPregunta;
use App\Models\RecursosPreguntaMeGusta;
use App\Models\RecursosProgreso;
use App\Models\RecursosSesion;
use App\Models\RecursosUsuario;
use App\Models\RecursosVoto;
use App\Services\RecursosLinkService;
use Illuminate\Http\Client\Request as PedidoHttp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * El login maestro entra al centro de recursos en SOLO LECTURA (misión centro-recursos-soporte-y-tema,
 * 14/10/2026, decisión de Lucas): soporte entra como el dueño, se ve su nombre y su avance, pero no se
 * guarda nada a su nombre (ni avance, ni votos, ni preguntas, ni me gusta, ni encuesta) y no se toca su
 * ficha.
 *
 * El `empresa-api` avisa que el pase es de soporte con `soporte: true` en la respuesta del canje
 * (contrato C3, aditivo: ausente = false). El admin responde con `usuario.solo_lectura: true` SOLO cuando
 * es true (contrato C2): para todos los demás la forma del JSON no cambia.
 *
 * Lo que más importa, en orden:
 *  1. 🔴 Las cinco rutas que escriben dan 403 `solo_lectura` y no escriben NADA (se mira la base, no solo
 *     el status).
 *  2. 🔴 En soporte no se toca la ficha del dueño: ni nombre, ni mail, ni `ultimo_ingreso_at` (el panel
 *     lo lee como "cuándo entró el cliente").
 *  3. 🔴 Un canje SIN `soporte` (un empresa-api viejo) da exactamente lo de antes, incluida la forma del
 *     JSON.
 *
 * Cada `Http::fake` se verifica con `assertSent`/`assertSentCount`: una respuesta falsa que nadie
 * consume da un verde falso.
 */
class SoporteSoloLecturaTest extends BaseDelCentroDeRecursos
{
    /**
     * La URL del canje del cliente que arma `crear_cliente('api-tornillo.test')`.
     */
    const URL_CANJE = 'https://api-tornillo.test/api/admin-sync/recursos/canjear-pase';

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
     * Respuesta 200 del canje (contrato C3). `$soporte = null` NO manda la clave (un empresa-api viejo).
     *
     * @param int         $id
     * @param mixed       $soporte
     * @param int|null    $owner_id
     * @param string      $nombre
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    private function canje_con(int $id, $soporte = null, ?int $owner_id = null, string $nombre = 'Marcela Gómez')
    {
        $cuerpo = [
            'user'  => ['id' => $id, 'name' => $nombre, 'email' => 'marcela@ferreteria.test', 'owner_id' => $owner_id],
            'video' => 'T1.29',
        ];

        if ($soporte !== null) {
            $cuerpo['soporte'] = $soporte;
        }

        return Http::response($cuerpo, 200);
    }

    /**
     * El pedido de canje que hace la página al admin (`recursos/sesion/pase`).
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function canjear_pase()
    {
        return $this->postJson('/api/recursos/sesion/pase', [
            'pase' => $this->pase(),
            'api'  => 'https://api-tornillo.test',
        ]);
    }

    /**
     * Entra como soporte: un cliente, el canje con `soporte: true` y la respuesta del admin.
     *
     * @param int $empresa_user_id
     *
     * @return array{0: Client, 1: \Illuminate\Testing\TestResponse}
     */
    private function entrar_como_soporte(int $empresa_user_id = 123): array
    {
        $client = $this->crear_cliente('api-tornillo.test');

        Http::fake([self::URL_CANJE => $this->canje_con($empresa_user_id, true)]);

        $respuesta = $this->canjear_pase();
        $respuesta->assertStatus(200);

        Http::assertSentCount(1);

        return [$client, $respuesta];
    }

    /**
     * Cuántas filas hay de todo lo que la página puede escribir.
     *
     * @return array<string, int>
     */
    private function lo_que_se_escribe(): array
    {
        return [
            'progresos' => RecursosProgreso::count(),
            'votos'     => RecursosVoto::count(),
            'preguntas' => RecursosPregunta::count(),
            'me_gusta'  => RecursosPreguntaMeGusta::count(),
            'encuestas' => RecursosEncuesta::count(),
        ];
    }

    /* ------------------------------------------------------------------------------------------
     | La sesión de soporte
     |----------------------------------------------------------------------------------------- */

    public function test_un_pase_de_soporte_abre_una_sesion_de_solo_lectura_y_lo_dice(): void
    {
        list($client, $respuesta) = $this->entrar_como_soporte(123);

        // El contrato C2: `usuario.solo_lectura === true` (booleano, no "1" ni truthy) en el canje...
        $this->assertSame(true, $respuesta->json('usuario.solo_lectura'));
        $respuesta->assertJsonPath('usuario.nombre', 'Marcela Gómez');
        $respuesta->assertJsonPath('usuario.negocio', 'Ferretería El Tornillo');
        $this->assertSame(['nombre', 'nombre_pila', 'es_dueno', 'negocio', 'solo_lectura'], array_keys($respuesta->json('usuario')));

        $token = (string) $respuesta->json('token');

        // ...la sesión de la base está marcada...
        $sesion = RecursosSesion::where('token_hash', hash('sha256', $token))->first();
        $this->assertInstanceOf(RecursosSesion::class, $sesion);
        $this->assertTrue($sesion->solo_lectura);

        // ...y `yo` también lo dice.
        $yo = $this->getJson('/api/recursos/yo', $this->con_sesion($token));
        $yo->assertStatus(200);
        $this->assertSame(true, $yo->json('usuario.solo_lectura'));
        $yo->assertJsonPath('usuario.nombre', 'Marcela Gómez');

        // El canje fue el de siempre: a la URL del cliente que tiene el admin, con su clave.
        Http::assertSent(function (PedidoHttp $pedido) use ($client) {
            return $pedido->url() === self::URL_CANJE
                && $pedido->method() === 'POST'
                && $pedido->header('X-Admin-Api-Key') === [$client->api_key];
        });
    }

    public function test_las_cinco_rutas_que_escriben_dan_403_solo_lectura_y_no_escriben_nada(): void
    {
        $video = $this->crear_video('T1.1', ['duracion' => 100.0]);

        $client = $this->crear_cliente('api-tornillo.test');

        // Una pregunta respondida de otra persona: es visible para cualquiera, así que el me gusta
        // sería válido para una sesión normal.
        list($autor) = $this->usuario_con_sesion($client);
        $pregunta = RecursosPregunta::create([
            'recursos_video_id' => $video->id, 'recursos_usuario_id' => $autor->id,
            'texto' => '¿Se puede importar desde Excel?', 'respuesta' => 'Sí.', 'respondido_at' => now(),
        ]);

        Http::fake([self::URL_CANJE => $this->canje_con(123, true)]);
        $canje = $this->canjear_pase();
        $canje->assertStatus(200);
        Http::assertSentCount(1);

        $token = (string) $canje->json('token');
        $antes = $this->lo_que_se_escribe();

        $pedidos = [
            ['/api/recursos/progreso', ['codigo' => 'T1.1', 'posicion' => 30, 'tramos' => [[0, 10], [10, 30]]]],
            ['/api/recursos/votos', ['codigo' => 'T1.1', 'valor' => 1]],
            ['/api/recursos/preguntas', ['codigo' => 'T1.1', 'texto' => 'Una pregunta de soporte que no tiene que quedar']],
            ['/api/recursos/preguntas/' . $pregunta->id . '/me-gusta', []],
            ['/api/recursos/encuestas', ['codigo' => 'T1.1', 'util' => 'si', 'entendio' => 'si', 'mejora' => 'nada']],
        ];

        foreach ($pedidos as $pedido) {
            $this->postJson($pedido[0], $pedido[1], $this->con_sesion($token))
                ->assertStatus(403)
                ->assertExactJson(['error' => 'solo_lectura']);
        }

        // 🔴 No es solo el status: no se escribió ni una fila.
        $this->assertSame($antes, $this->lo_que_se_escribe());
        $usuario = RecursosUsuario::where('empresa_user_id', 123)->first();
        $this->assertSame(0, RecursosProgreso::where('recursos_usuario_id', $usuario->id)->count());
        $this->assertSame(0, RecursosVoto::where('recursos_usuario_id', $usuario->id)->count());
        $this->assertSame(0, RecursosPregunta::where('recursos_usuario_id', $usuario->id)->count());
        $this->assertSame(0, RecursosPreguntaMeGusta::where('recursos_usuario_id', $usuario->id)->count());
        $this->assertSame(0, RecursosEncuesta::where('recursos_usuario_id', $usuario->id)->count());

        // Y `yo` sigue mostrando un avance vacío: no quedó nada marcado.
        $yo = $this->getJson('/api/recursos/yo', $this->con_sesion($token))->assertStatus(200)->json();
        $this->assertSame([], $yo['progreso']);
        $this->assertSame([], $yo['votos']);
        $this->assertSame([], $yo['encuestas']);
        $this->assertSame([], $yo['me_gusta']);

        // Control: los MISMOS pedidos con una sesión normal sí funcionan. Así el 403 de arriba es por la
        // sesión de solo lectura y no porque el pedido estuviera mal armado.
        list(, $token_normal) = $this->usuario_con_sesion($client);

        foreach ($pedidos as $pedido) {
            $this->assertContains(
                $this->postJson($pedido[0], $pedido[1], $this->con_sesion($token_normal))->status(),
                [200, 201],
                $pedido[0] . ' tendría que andar con una sesión normal.'
            );
        }

        // Cada uno de los cinco pedidos de la sesión normal escribió exactamente una fila.
        $diferencia = [];

        foreach ($this->lo_que_se_escribe() as $tabla => $cantidad) {
            $diferencia[$tabla] = $cantidad - $antes[$tabla];
        }

        $this->assertSame(['progresos' => 1, 'votos' => 1, 'preguntas' => 1, 'me_gusta' => 1, 'encuestas' => 1], $diferencia);
    }

    public function test_soporte_puede_leer_el_catalogo_el_video_el_yo_y_salir(): void
    {
        $this->crear_video('T1.1');

        list(, $canje) = $this->entrar_como_soporte(123);
        $token         = (string) $canje->json('token');

        // Lo público.
        $this->getJson('/api/recursos/catalogo')->assertStatus(200);
        $this->getJson('/api/recursos/buscar-indice')->assertStatus(200);
        $this->getJson('/api/recursos/videos/T1.1')->assertStatus(200);

        // Con la sesión de soporte.
        $this->getJson('/api/recursos/videos/T1.1', $this->con_sesion($token))->assertStatus(200)->assertJsonPath('video.codigo', 'T1.1');
        $this->getJson('/api/recursos/yo', $this->con_sesion($token))->assertStatus(200);

        // Salir es de lectura: la sesión se cierra y el token deja de servir.
        $this->postJson('/api/recursos/salir', [], $this->con_sesion($token))->assertStatus(200)->assertExactJson(['ok' => true]);
        $this->getJson('/api/recursos/yo', $this->con_sesion($token))->assertStatus(401)->assertExactJson(['error' => 'sin_sesion']);
        $this->assertSame(0, RecursosSesion::where('token_hash', hash('sha256', $token))->count());
    }

    /* ------------------------------------------------------------------------------------------
     | La ficha del dueño no se toca
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 El panel lee `ultimo_ingreso_at` como "cuándo entró el cliente": si soporte la pisara, el panel
     * diría que el dueño entró hoy cuando entró soporte.
     */
    public function test_en_soporte_no_se_toca_la_ficha_de_un_usuario_que_ya_existe(): void
    {
        $client = $this->crear_cliente('api-tornillo.test');

        $ultimo = Carbon::now()->subDays(10)->startOfSecond();
        $ficha  = Carbon::now()->subDays(30)->startOfSecond();

        $usuario = RecursosUsuario::create([
            'client_id'         => $client->id,
            'empresa_user_id'   => 123,
            'nombre'            => 'Nombre de la ficha',
            'email'             => 'ficha@ferreteria.test',
            'es_dueno'          => false,
            'ultimo_ingreso_at' => $ultimo,
        ]);
        RecursosUsuario::where('id', $usuario->id)->update(['updated_at' => $ficha]);

        // El sistema devuelve otro nombre, otro mail y dice que es el dueño: en soporte nada de eso se escribe.
        Http::fake([self::URL_CANJE => $this->canje_con(123, true, null, 'Marcela Gómez')]);

        $respuesta = $this->canjear_pase();
        $respuesta->assertStatus(200);
        Http::assertSentCount(1);

        // Se entra como el usuario que ya estaba, con lo que ya tenía.
        $respuesta->assertJsonPath('usuario.nombre', 'Nombre de la ficha');
        $this->assertSame(true, $respuesta->json('usuario.solo_lectura'));

        $this->assertSame(1, RecursosUsuario::where('client_id', $client->id)->count());

        $despues = RecursosUsuario::find($usuario->id);
        $this->assertSame('Nombre de la ficha', $despues->nombre);
        $this->assertSame('ficha@ferreteria.test', $despues->email);
        $this->assertFalse($despues->es_dueno);
        $this->assertSame($ultimo->toDateTimeString(), $despues->ultimo_ingreso_at->toDateTimeString());
        $this->assertSame($ficha->toDateTimeString(), $despues->updated_at->toDateTimeString());
    }

    /**
     * 🔴 La fila que generó el panel (link personal del dueño, sin `empresa_user_id`): en soporte se usa
     * tal cual, SIN completarle el id. Completárselo es escribir sobre la ficha del dueño.
     */
    public function test_en_soporte_no_se_le_completa_el_id_a_la_fila_que_genero_el_panel(): void
    {
        $client = $this->crear_cliente('api-tornillo.test', ['name' => '']);

        (new RecursosLinkService())->link_del_dueno($client);

        $dueno = RecursosUsuario::where('client_id', $client->id)->first();
        $this->assertNull($dueno->empresa_user_id);
        $this->assertNull($dueno->ultimo_ingreso_at);

        Http::fake([self::URL_CANJE => $this->canje_con(77, true, null, 'Marcela Gómez')]);

        $respuesta = $this->canjear_pase();
        $respuesta->assertStatus(200);
        Http::assertSentCount(1);

        // Entra como esa misma fila, con su nombre de la ficha.
        $respuesta->assertJsonPath('usuario.nombre', 'Dueño');
        $this->assertSame(true, $respuesta->json('usuario.solo_lectura'));

        $this->assertSame(1, RecursosUsuario::where('client_id', $client->id)->count());

        $dueno->refresh();
        $this->assertNull($dueno->empresa_user_id);
        $this->assertSame('Dueño', $dueno->nombre);
        $this->assertNull($dueno->email);
        $this->assertNull($dueno->ultimo_ingreso_at);

        $this->assertSame(1, RecursosSesion::where('recursos_usuario_id', $dueno->id)->where('solo_lectura', true)->count());
    }

    public function test_en_soporte_un_usuario_que_no_existia_se_crea_con_los_datos_del_sistema_y_sin_ultimo_ingreso(): void
    {
        $client = $this->crear_cliente('api-tornillo.test');

        $this->assertSame(0, RecursosUsuario::where('client_id', $client->id)->count());

        Http::fake([self::URL_CANJE => $this->canje_con(123, true, null, 'Marcela Gómez')]);

        $respuesta = $this->canjear_pase();
        $respuesta->assertStatus(200);
        Http::assertSentCount(1);

        $respuesta->assertJsonPath('usuario.nombre', 'Marcela Gómez');
        $respuesta->assertJsonPath('usuario.es_dueno', true);

        $usuario = RecursosUsuario::where('client_id', $client->id)->first();
        $this->assertInstanceOf(RecursosUsuario::class, $usuario);
        $this->assertSame(123, (int) $usuario->empresa_user_id);
        $this->assertSame('Marcela Gómez', $usuario->nombre);
        $this->assertSame('marcela@ferreteria.test', $usuario->email);
        $this->assertTrue($usuario->es_dueno);
        $this->assertNull($usuario->ultimo_ingreso_at);
    }

    /* ------------------------------------------------------------------------------------------
     | Vencimiento
     |----------------------------------------------------------------------------------------- */

    public function test_la_sesion_de_soporte_vence_a_las_doce_horas(): void
    {
        $ahora = Carbon::now()->startOfSecond();

        try {
            $this->travelTo($ahora);

            list(, $canje) = $this->entrar_como_soporte(123);
            $token         = (string) $canje->json('token');

            $sesion = RecursosSesion::where('token_hash', hash('sha256', $token))->first();
            $this->assertSame($ahora->copy()->addHours(12)->toDateTimeString(), $sesion->expires_at->toDateTimeString());

            $this->travelTo($ahora->copy()->addHours(11)->addMinutes(59));
            $this->getJson('/api/recursos/yo', $this->con_sesion($token))->assertStatus(200);

            $this->travelTo($ahora->copy()->addHours(12)->addMinutes(1));
            $this->getJson('/api/recursos/yo', $this->con_sesion($token))->assertStatus(401)->assertExactJson(['error' => 'sin_sesion']);
        } finally {
            $this->travelBack();
        }
    }

    public function test_el_vencimiento_de_soporte_sale_de_la_config(): void
    {
        $this->assertSame(12, (int) config('recursos.sesion_soporte_horas'));

        config(['recursos.sesion_soporte_horas' => 2]);

        $ahora = Carbon::now()->startOfSecond();

        try {
            $this->travelTo($ahora);

            list(, $canje) = $this->entrar_como_soporte(123);

            $sesion = RecursosSesion::where('token_hash', hash('sha256', (string) $canje->json('token')))->first();
            $this->assertSame($ahora->copy()->addHours(2)->toDateTimeString(), $sesion->expires_at->toDateTimeString());
        } finally {
            $this->travelBack();
        }
    }

    /* ------------------------------------------------------------------------------------------
     | Sin soporte: todo como antes
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Compatibilidad hacia atrás (regla de Lucas): un `empresa-api` viejo no manda `soporte`. Es una
     * sesión normal, la forma del JSON no cambia y se escribe como siempre.
     */
    public function test_un_canje_sin_soporte_es_una_sesion_normal_y_la_forma_del_json_no_cambia(): void
    {
        $this->crear_video('T1.1', ['duracion' => 100.0]);
        $client = $this->crear_cliente('api-tornillo.test');

        $ahora = Carbon::now()->startOfSecond();

        try {
            $this->travelTo($ahora);

            // Sin la clave `soporte`, como la responde un empresa-api viejo.
            Http::fake([self::URL_CANJE => $this->canje_con(123, null)]);

            $respuesta = $this->canjear_pase();
            $respuesta->assertStatus(200);
            Http::assertSentCount(1);

            $this->assertSame(['token', 'usuario'], array_keys($respuesta->json()));
            $this->assertSame(['nombre', 'nombre_pila', 'es_dueno', 'negocio'], array_keys($respuesta->json('usuario')));

            $token = (string) $respuesta->json('token');

            $sesion = RecursosSesion::where('token_hash', hash('sha256', $token))->first();
            $this->assertFalse($sesion->solo_lectura);
            $this->assertSame($ahora->copy()->addDays(365)->toDateTimeString(), $sesion->expires_at->toDateTimeString());

            // El usuario: `ultimo_ingreso_at` se marca como siempre.
            $usuario = RecursosUsuario::where('client_id', $client->id)->where('empresa_user_id', 123)->first();
            $this->assertSame($ahora->toDateTimeString(), $usuario->ultimo_ingreso_at->toDateTimeString());

            // `yo` tampoco trae la clave.
            $yo = $this->getJson('/api/recursos/yo', $this->con_sesion($token));
            $yo->assertStatus(200);
            $this->assertSame(['nombre', 'nombre_pila', 'es_dueno', 'negocio'], array_keys($yo->json('usuario')));

            // Y escribe: el voto queda.
            $this->postJson('/api/recursos/votos', ['codigo' => 'T1.1', 'valor' => 1], $this->con_sesion($token))->assertStatus(200);
            $this->assertSame(1, RecursosVoto::where('recursos_usuario_id', $usuario->id)->count());
        } finally {
            $this->travelBack();
        }
    }

    /**
     * Solo un `true` booleano abre una sesión de solo lectura: `false`, null, el texto "true", 1 o un
     * array se leen como sesión normal. Un cliente raro no puede, sin querer, dejar a un dueño en solo
     * lectura (ni, al revés, abrir una de soporte con un valor ambiguo).
     */
    public function test_solo_un_true_booleano_abre_una_sesion_de_solo_lectura(): void
    {
        $this->crear_cliente('api-tornillo.test');

        $valores = [false, null, 'true', 1, '1', ['true']];

        Http::fake([self::URL_CANJE => Http::sequence(array_map(function ($valor) {
            $cuerpo = ['user' => ['id' => 123, 'name' => 'Marcela Gómez', 'email' => null, 'owner_id' => null], 'soporte' => $valor];

            return Http::response($cuerpo, 200);
        }, $valores))]);

        foreach ($valores as $indice => $valor) {
            $respuesta = $this->canjear_pase();
            $respuesta->assertStatus(200);

            $this->assertArrayNotHasKey('solo_lectura', $respuesta->json('usuario'), 'soporte=' . json_encode($valor));

            $sesion = RecursosSesion::where('token_hash', hash('sha256', (string) $respuesta->json('token')))->first();
            $this->assertFalse($sesion->solo_lectura, 'soporte=' . json_encode($valor));
        }

        // La secuencia se consumió entera (si no, el verde sería falso).
        Http::assertSentCount(count($valores));
    }

    public function test_el_link_personal_del_dueno_abre_una_sesion_normal(): void
    {
        $client = $this->crear_cliente('api-tornillo.test');
        $link   = (new RecursosLinkService())->link_del_dueno($client);

        parse_str((string) parse_url($link['url'], PHP_URL_QUERY), $query);

        $respuesta = $this->postJson('/api/recursos/sesion/link', ['t' => $query['t']]);
        $respuesta->assertStatus(200);

        $this->assertSame(['nombre', 'nombre_pila', 'es_dueno', 'negocio'], array_keys($respuesta->json('usuario')));

        $sesion = RecursosSesion::where('token_hash', hash('sha256', (string) $respuesta->json('token')))->first();
        $this->assertFalse($sesion->solo_lectura);
    }

    /* ------------------------------------------------------------------------------------------
     | El cableado del middleware
     |----------------------------------------------------------------------------------------- */

    public function test_sin_sesion_las_rutas_que_escriben_siguen_dando_401_y_no_403(): void
    {
        $this->postJson('/api/recursos/votos', ['codigo' => 'T1.1', 'valor' => 1])->assertStatus(401)->assertExactJson(['error' => 'sin_sesion']);
        $this->postJson('/api/recursos/votos', ['codigo' => 'T1.1', 'valor' => 1], $this->con_sesion('inventado'))->assertStatus(401);
    }

    /**
     * Las cinco rutas que escriben pasan por `recursos.escritura`, y después de la sesión y del
     * limitador; `yo` y `salir` no pasan por ahí. Si alguien agrega una ruta que escribe, este test es el
     * que le avisa que tiene que decidir en cuál de los dos grupos va.
     */
    public function test_las_rutas_que_escriben_pasan_por_la_reja_y_las_de_lectura_no(): void
    {
        $escriben = ['api/recursos/progreso', 'api/recursos/votos', 'api/recursos/preguntas', 'api/recursos/preguntas/{id}/me-gusta', 'api/recursos/encuestas'];
        $leen     = ['api/recursos/yo', 'api/recursos/salir'];
        $vistas   = [];

        foreach (Route::getRoutes() as $ruta) {
            $uri = $ruta->uri();

            if (strpos($uri, 'api/recursos/') !== 0) {
                continue;
            }

            $middleware = array_values(array_filter(app('router')->gatherRouteMiddleware($ruta), 'is_string'));

            $posicion = function ($clase) use ($middleware) {
                foreach ($middleware as $indice => $nombre) {
                    if ($nombre === $clase || strpos($nombre, $clase . ':') === 0) {
                        return $indice;
                    }
                }

                return false;
            };

            if (in_array($uri, $escriben, true)) {
                $vistas[] = $uri;

                $this->assertNotFalse($posicion(RecursosEscritura::class), $uri . ' tiene que pasar por recursos.escritura.');
                $this->assertLessThan($posicion(RecursosEscritura::class), $posicion(RecursosSesionMiddleware::class), $uri . ': la sesión va antes que la reja.');
                $this->assertLessThan($posicion(RecursosEscritura::class), $posicion(RecursosThrottle::class), $uri . ': el limitador va antes que la reja.');
            }

            if (in_array($uri, $leen, true)) {
                $vistas[] = $uri;

                $this->assertFalse($posicion(RecursosEscritura::class), $uri . ' NO tiene que pasar por recursos.escritura.');
            }
        }

        sort($vistas);
        $esperadas = array_merge($escriben, $leen);
        sort($esperadas);
        $this->assertSame($esperadas, $vistas);
    }

    /**
     * El limitador de las rutas que escriben sigue corriendo ANTES que la reja: los 403 de una sesión de
     * soporte también cuentan, así que no se puede martillar el servidor con una.
     */
    public function test_el_limitador_sigue_funcionando_con_una_sesion_de_solo_lectura(): void
    {
        list(, $canje) = $this->entrar_como_soporte(123);
        $token         = (string) $canje->json('token');

        foreach (range(1, 60) as $intento) {
            $this->postJson('/api/recursos/votos', ['codigo' => 'T1.1', 'valor' => 1], $this->con_sesion($token))->assertStatus(403);
        }

        $this->postJson('/api/recursos/votos', ['codigo' => 'T1.1', 'valor' => 1], $this->con_sesion($token))->assertStatus(429);
    }
}
