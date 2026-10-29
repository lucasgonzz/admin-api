<?php

namespace Tests\Feature\IaApp;

use App\Models\Client;
use App\Models\IaDirectorio;
use App\Services\IaApp\IaDirectorioService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * `POST api/ia/login`, el login público de la app `ia.comerciocity.com` (contrato C4.1 de la misión cruzada
 * `app-ia-y-memoria`, 29/10/2026).
 *
 * Lo que se protege, en orden de importancia:
 *  1. 🔴 La contraseña no queda en ningún log, en ninguna tabla ni en ninguna respuesta, ni cuando el sistema
 *     del cliente la devuelve volcada en una página de error.
 *  2. 🔴 Credenciales malas = 401 con el MISMO cuerpo, haya o no candidatos en el directorio.
 *  3. Se elige bien el sistema: por `negocio` (slug o host), o por el directorio (1 o varios candidatos).
 *  4. Cada respuesta del sistema del cliente se traduce a su `code` (version_vieja, sin_extension…), y la
 *     clave del admin rechazada NO se confunde con una contraseña mala.
 *  5. Los topes: 5 por minuto por usuario y 10 por minuto por IP.
 */
class LoginDeLaAppIaTest extends BaseDeLaAppIa
{
    const PASSWORD = 'Secreta-Nro-9931!';

    /**
     * El 200 del contrato C1.1.
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    private function token_ok(string $token = '41|textoDelToken')
    {
        return Http::response([
            'token'       => $token,
            'usuario'     => ['id' => 7, 'nombre' => 'Pablo'],
            'negocio'     => ['nombre' => 'Ferre Total', 'logo_url' => 'https://img.test/logo.png'],
            'capacidades' => ['version_api' => '1', 'funciones' => ['modelo', 'adjuntos']],
        ], 200);
    }

    /**
     * @param array<string, mixed> $cuerpo
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function login(array $cuerpo)
    {
        return $this->postJson('/api/ia/login', $cuerpo);
    }

    /**
     * Deja al usuario como dueño de un cliente en el directorio.
     *
     * @param Client $client
     * @param string $usuario
     * @param bool   $extension
     *
     * @return void
     */
    private function en_el_directorio(Client $client, string $usuario, bool $extension = true): void
    {
        IaDirectorio::create([
            'client_id'        => $client->id,
            'usuario_hash'     => IaDirectorioService::hash_usuario($usuario),
            'extension_activa' => $extension,
            'sincronizado_en'  => now(),
        ]);
    }

    /* ------------------------------------------------------------------------------------------ */

    public function test_por_negocio_entra_devuelve_el_contrato_y_aprende_el_directorio(): void
    {
        $client = $this->crear_cliente('ferretotal');
        $this->fakear([$this->base($client) . '/api/admin-sync/ia-app/token' => $this->token_ok()]);

        $respuesta = $this->login(['usuario' => '20.123.456', 'password' => self::PASSWORD, 'negocio' => $client->slug]);

        $respuesta->assertStatus(200)
            ->assertJsonPath('token', '41|textoDelToken')
            ->assertJsonPath('api_url', $this->base($client))
            ->assertJsonPath('negocio.nombre', 'Ferre Total')
            ->assertJsonPath('negocio.slug', $client->slug)
            ->assertJsonPath('negocio.logo_url', 'https://img.test/logo.png')
            ->assertJsonPath('usuario.id', 7)
            ->assertJsonPath('usuario.nombre', 'Pablo')
            ->assertJsonPath('capacidades.version_api', '1');

        Http::assertSent(function (Request $pedido) use ($client) {
            return $pedido->url() === $this->base($client) . '/api/admin-sync/ia-app/token'
                && $pedido->method() === 'POST'
                && $pedido->header('X-Admin-Api-Key')[0] === $client->api_key
                && $pedido['doc_number'] === '20.123.456'
                && $pedido['password'] === self::PASSWORD
                && is_string($pedido['dispositivo']) && $pedido['dispositivo'] !== '';
        });

        $fila = IaDirectorio::where('client_id', $client->id)->first();
        $this->assertNotNull($fila, 'El login por negocio tiene que aprender la entrada del directorio.');
        $this->assertSame(IaDirectorioService::hash_usuario('20123456'), $fila->usuario_hash);
        $this->assertTrue($fila->extension_activa);
        $this->assertSame('Pablo', $fila->nombre_dueno);
    }

    public function test_por_host_del_sistema_tambien_lo_encuentra(): void
    {
        $client = $this->crear_cliente('ferrehost');
        $this->fakear([$this->base($client) . '/api/admin-sync/ia-app/token' => $this->token_ok()]);

        // El host de la SPA (spa_url) y el de la API, con esquema y barra.
        $this->login(['usuario' => 'pablo', 'password' => self::PASSWORD, 'negocio' => $client->slug . '.test'])
            ->assertStatus(200)->assertJsonPath('api_url', $this->base($client));
        $this->login(['usuario' => 'pablo', 'password' => self::PASSWORD, 'negocio' => 'https://api-' . $client->slug . '.test/'])
            ->assertStatus(200);
    }

    public function test_sin_negocio_usa_el_directorio_con_un_candidato(): void
    {
        $client = $this->crear_cliente('directo');
        $this->en_el_directorio($client, '30111222');
        $this->fakear([$this->base($client) . '/api/admin-sync/ia-app/token' => $this->token_ok()]);

        $this->login(['usuario' => ' 30.111.222 ', 'password' => self::PASSWORD])
            ->assertStatus(200)
            ->assertJsonPath('api_url', $this->base($client));
    }

    public function test_con_varios_candidatos_gana_el_primero_que_acepta(): void
    {
        $primero = $this->crear_cliente('uno');
        $segundo = $this->crear_cliente('dos');
        // El de la extensión activa se prueba primero.
        $this->en_el_directorio($primero, 'juanperez', true);
        $this->en_el_directorio($segundo, 'juanperez', false);

        $this->fakear([
            $this->base($primero) . '/api/admin-sync/ia-app/token' => Http::response(['code' => 'credenciales_invalidas', 'message' => 'No.'], 401),
            $this->base($segundo) . '/api/admin-sync/ia-app/token' => $this->token_ok('99|delSegundo'),
        ]);

        $this->login(['usuario' => 'Juan.Perez', 'password' => self::PASSWORD])
            ->assertStatus(200)
            ->assertJsonPath('token', '99|delSegundo')
            ->assertJsonPath('api_url', $this->base($segundo));

        Http::assertSentCount(2);
    }

    public function test_sin_candidatos_ni_negocio_pide_cual_es_el_sistema(): void
    {
        $this->fakear([]);

        $this->login(['usuario' => 'nadie', 'password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonPath('code', 'negocio_requerido');

        Http::assertNothingSent();
    }

    public function test_un_negocio_que_no_existe_es_404(): void
    {
        $this->fakear([]);

        $this->login(['usuario' => 'pablo', 'password' => self::PASSWORD, 'negocio' => 'no-existe-' . Str::random(6)])
            ->assertStatus(404)
            ->assertJsonPath('code', 'negocio_no_encontrado');

        // Un cliente inactivo tampoco se encuentra.
        $inactivo = $this->crear_cliente('dormido', null, false);
        $this->login(['usuario' => 'pablo', 'password' => self::PASSWORD, 'negocio' => $inactivo->slug])
            ->assertStatus(404);
    }

    public function test_credenciales_malas_dan_el_mismo_401_haya_o_no_candidatos(): void
    {
        $por_negocio   = $this->crear_cliente('pornegocio');
        $del_directorio = $this->crear_cliente('pordirectorio');
        $this->en_el_directorio($del_directorio, 'maria');

        $rechazo = Http::response(['code' => 'credenciales_invalidas', 'message' => 'Usuario o contraseña incorrectos.'], 401);
        $this->fakear([
            $this->base($por_negocio) . '/*'    => $rechazo,
            $this->base($del_directorio) . '/*' => $rechazo,
        ]);

        $a = $this->login(['usuario' => 'jose', 'password' => 'mala', 'negocio' => $por_negocio->slug]);
        $b = $this->login(['usuario' => 'maria', 'password' => 'mala']);

        $a->assertStatus(401)->assertJsonPath('code', 'credenciales_invalidas');
        $b->assertStatus(401)->assertJsonPath('code', 'credenciales_invalidas');
        $this->assertSame($a->json(), $b->json(), 'El 401 no puede decir distinto según haya o no candidatos.');
    }

    public function test_el_404_del_sistema_es_version_vieja(): void
    {
        $client = $this->crear_cliente('viejo');
        $this->fakear([$this->base($client) . '/*' => Http::response('<html>Not Found</html>', 404)]);

        $this->login(['usuario' => 'pablo', 'password' => self::PASSWORD, 'negocio' => $client->slug])
            ->assertStatus(409)
            ->assertJsonPath('code', 'version_vieja');
    }

    public function test_sin_extension_y_no_es_dueno_pasan_con_su_codigo(): void
    {
        $sin_extension = $this->crear_cliente('sinext');
        $empleado      = $this->crear_cliente('empleado');
        $this->fakear([
            $this->base($sin_extension) . '/*' => Http::response(['code' => 'sin_extension', 'message' => 'x'], 403),
            $this->base($empleado) . '/*'      => Http::response(['code' => 'no_es_dueno', 'message' => 'x'], 403),
        ]);

        $this->login(['usuario' => 'a', 'password' => self::PASSWORD, 'negocio' => $sin_extension->slug])
            ->assertStatus(403)->assertJsonPath('code', 'sin_extension');
        $this->login(['usuario' => 'b', 'password' => self::PASSWORD, 'negocio' => $empleado->slug])
            ->assertStatus(403)->assertJsonPath('code', 'no_es_dueno');
    }

    public function test_la_clave_del_admin_rechazada_no_se_confunde_con_una_contrasena_mala(): void
    {
        $client = $this->crear_cliente('sinclave');
        // El 401 de ClaveEstrictaDeAdmin: sin `code`.
        $this->fakear([$this->base($client) . '/*' => Http::response(['error' => 'unauthorized'], 401)]);

        $this->login(['usuario' => 'pablo', 'password' => self::PASSWORD, 'negocio' => $client->slug])
            ->assertStatus(502)
            ->assertJsonPath('code', 'sistema_no_disponible');
    }

    public function test_un_200_sin_token_no_es_un_login(): void
    {
        $client = $this->crear_cliente('saturado');
        $this->fakear([$this->base($client) . '/*' => Http::response('<html>Hosting saturado</html>', 200)]);

        $this->login(['usuario' => 'pablo', 'password' => self::PASSWORD, 'negocio' => $client->slug])
            ->assertStatus(502)
            ->assertJsonPath('code', 'sistema_no_disponible');
    }

    public function test_tope_de_cinco_por_minuto_por_usuario(): void
    {
        $client = $this->crear_cliente('tope');
        $this->fakear([$this->base($client) . '/*' => Http::response(['code' => 'credenciales_invalidas'], 401)]);

        for ($i = 0; $i < 5; $i++) {
            $this->login(['usuario' => '20.000.001', 'password' => 'mala' . $i, 'negocio' => $client->slug])->assertStatus(401);
        }

        // Escrito distinto, es el mismo usuario normalizado.
        $this->login(['usuario' => '20000001', 'password' => 'otra', 'negocio' => $client->slug])
            ->assertStatus(429)
            ->assertJsonPath('code', 'demasiados_intentos')
            ->assertHeader('Retry-After');

        Http::assertSentCount(5);
    }

    public function test_tope_de_diez_por_minuto_por_ip(): void
    {
        $this->fakear([]);

        for ($i = 0; $i < 10; $i++) {
            $this->login(['usuario' => 'usuario' . $i, 'password' => self::PASSWORD])->assertStatus(422);
        }

        $this->login(['usuario' => 'otro', 'password' => self::PASSWORD])
            ->assertStatus(429)
            ->assertJsonPath('code', 'demasiados_intentos');
    }

    public function test_validacion_sin_contrasena(): void
    {
        $this->login(['usuario' => 'pablo'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validacion');
    }

    /**
     * 🔴 La contraseña no queda en ningún log, en la base ni en ninguna respuesta, pase lo que pase.
     */
    public function test_la_contrasena_no_queda_en_logs_ni_en_la_base_ni_en_las_respuestas(): void
    {
        $logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $evento) use (&$logs) {
            $logs[] = $evento->message . ' ' . json_encode($evento->context);
        });

        $bien      = $this->crear_cliente('bien');
        $mal       = $this->crear_cliente('mal');
        $caido     = $this->crear_cliente('caido');
        $volcado   = $this->crear_cliente('volcado');
        $this->en_el_directorio($mal, 'pablo');

        $this->fakear([
            $this->base($bien) . '/*'  => $this->token_ok(),
            $this->base($mal) . '/*'   => Http::response(['code' => 'credenciales_invalidas'], 401),
            $this->base($caido) . '/*' => function () {
                throw new ConnectionException('cURL error 28: Operation timed out after 15000 milliseconds');
            },
            // Una instancia con APP_DEBUG que devuelve el request volcado, contraseña incluida.
            $this->base($volcado) . '/*' => Http::response('<pre>password=' . self::PASSWORD . '</pre>', 500),
        ]);

        $respuestas = [
            // Otro usuario: el login por negocio aprende el directorio, y con 'pablo' el segundo ganaría en $bien.
            $this->login(['usuario' => 'ana', 'password' => self::PASSWORD, 'negocio' => $bien->slug]),
            $this->login(['usuario' => 'pablo', 'password' => self::PASSWORD]),
            $this->login(['usuario' => 'pablo', 'password' => self::PASSWORD, 'negocio' => $caido->slug]),
            $this->login(['usuario' => 'pablo', 'password' => self::PASSWORD, 'negocio' => $volcado->slug]),
            $this->login(['usuario' => str_repeat('x', 80), 'password' => self::PASSWORD]),
        ];

        $this->assertSame([200, 401, 502, 502, 422], array_map(function ($r) {
            return $r->status();
        }, $respuestas));

        foreach ($respuestas as $respuesta) {
            $this->assertStringNotContainsString(self::PASSWORD, (string) $respuesta->getContent());
        }

        $this->assertNotEmpty($logs, 'El login tiene que dejar rastro (sin la contraseña).');
        foreach ($logs as $linea) {
            $this->assertStringNotContainsString(self::PASSWORD, $linea);
        }

        foreach (['ia_directorio', 'jobs'] as $tabla) {
            $this->assertStringNotContainsString(self::PASSWORD, json_encode(DB::table($tabla)->get()), 'La contraseña quedó en ' . $tabla . '.');
        }

        // Y el usuario tampoco queda en claro en el directorio.
        $this->assertStringNotContainsString('"pablo"', json_encode(IaDirectorio::all()->toArray()));
        $this->assertStringNotContainsString('"ana"', json_encode(IaDirectorio::all()->toArray()));
    }

    /**
     * 🔴 Sin tope de tiempo (CLI, workers) el login no le pone uno al proceso: lo hizo una vez y mató la suite.
     */
    public function test_el_login_no_le_pone_tope_de_tiempo_a_un_proceso_sin_tope(): void
    {
        $antes = ini_get('max_execution_time');
        $this->assertSame('0', (string) $antes, 'phpunit corre sin tope: si no, esta prueba no mide nada.');

        $client = $this->crear_cliente('sintope');
        $this->fakear([$this->base($client) . '/*' => $this->token_ok()]);
        $this->login(['usuario' => 'pablo', 'password' => self::PASSWORD, 'negocio' => $client->slug])->assertStatus(200);

        $this->assertSame('0', (string) ini_get('max_execution_time'));
    }

    public function test_el_cors_acepta_el_origen_de_la_app(): void
    {
        $this->assertContains('https://ia.comerciocity.com', (array) config('cors.allowed_origins'));

        $preflight = $this->call('OPTIONS', '/api/ia/login', [], [], [], [
            'HTTP_ORIGIN'                         => 'https://ia.comerciocity.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD'  => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ]);

        $this->assertSame('https://ia.comerciocity.com', $preflight->headers->get('Access-Control-Allow-Origin'));
    }

    /* -------------------------------------------------------------------- Correcciones del revisor */

    /**
     * Lo que se escribió en laravel.log desde `$desde` (bytes).
     *
     * @param int $desde
     *
     * @return string
     */
    private function log_desde(int $desde): string
    {
        $ruta = storage_path('logs/laravel.log');
        clearstatcache(true, $ruta);

        if (! is_file($ruta)) {
            return '';
        }

        $contenido = (string) file_get_contents($ruta);

        return (string) substr($contenido, min($desde, strlen($contenido)));
    }

    /**
     * @return int Tamaño actual de laravel.log.
     */
    private function tamano_del_log(): int
    {
        $ruta = storage_path('logs/laravel.log');
        clearstatcache(true, $ruta);

        return is_file($ruta) ? (int) filesize($ruta) : 0;
    }

    /**
     * 🔴 Una excepción en el medio del login (la tabla del directorio no está: una migración que falta) no deja
     * la contraseña en laravel.log, y la persona ve el 502 de siempre.
     */
    public function test_una_excepcion_en_el_medio_del_login_no_deja_la_contrasena_en_el_log(): void
    {
        $this->app->instance(IaDirectorioService::class, new class extends IaDirectorioService {
            public function candidatos($usuario, $maximo = 5)
            {
                // Migración ausente simulada: la consulta va a una tabla que no existe.
                return collect(DB::table('ia_directorio_que_no_existe')->where('usuario_hash', 'x')->get());
            }
        });
        $this->fakear([]);
        $desde = $this->tamano_del_log();

        $respuesta = $this->login(['usuario' => 'pablo', 'password' => self::PASSWORD]);

        $respuesta->assertStatus(502)->assertJsonPath('code', 'sistema_no_disponible');
        $this->assertStringNotContainsString(self::PASSWORD, (string) $respuesta->getContent());

        $log = $this->log_desde($desde);
        $this->assertStringContainsString('el login se cortó por un error inesperado', $log, 'El error se anota (sin la contraseña).');
        $this->assertStringContainsString('QueryException', $log);
        $this->assertStringNotContainsString(self::PASSWORD, $log);
    }

    /**
     * 🔴 La excepción sale justo donde la contraseña está en claro (armando el pedido al sistema del cliente), y
     * hasta con la contraseña en el mensaje: el log la tapa, la traza no lleva argumentos y el ini vuelve a
     * su valor al terminar.
     */
    public function test_la_excepcion_con_la_contrasena_a_mano_tampoco_la_deja_en_el_log(): void
    {
        $client = $this->crear_cliente('explota');
        $espia  = new class extends \App\Services\IaApp\IaEmpresaCliente {
            /** @var array<string, mixed> */
            public $visto = [];

            public function pedir(\App\Models\Client $client, $metodo, $ruta, array $cuerpo = [], $timeout = 15, $adjunto = null)
            {
                $frame = (new \Exception())->getTrace()[0];
                $this->visto = [
                    'ignore_args'      => (string) ini_get('zend.exception_ignore_args'),
                    'traza_con_args'   => array_key_exists('args', $frame),
                ];

                throw new \RuntimeException('falló armando el pedido con password=' . $cuerpo['password']);
            }
        };
        $this->app->instance(\App\Services\IaApp\IaEmpresaCliente::class, $espia);
        $antes = (string) ini_get('zend.exception_ignore_args');
        $desde = $this->tamano_del_log();

        $this->login(['usuario' => 'pablo', 'password' => self::PASSWORD, 'negocio' => $client->slug])
            ->assertStatus(502)->assertJsonPath('code', 'sistema_no_disponible');

        $this->assertSame('1', $espia->visto['ignore_args'], 'Durante el login las trazas no llevan argumentos.');
        $this->assertFalse($espia->visto['traza_con_args']);
        $this->assertSame($antes, (string) ini_get('zend.exception_ignore_args'), 'Al salir, el ini vuelve a su valor.');

        $log = $this->log_desde($desde);
        $this->assertStringContainsString('[oculta]', $log);
        $this->assertStringNotContainsString(self::PASSWORD, $log);
    }

    public function test_la_credencial_no_se_muestra_ni_se_serializa(): void
    {
        $credencial = new \App\Services\IaApp\CredencialDeLogin(self::PASSWORD);

        $this->assertSame(self::PASSWORD, $credencial->valor());
        $this->assertSame('[oculta]', (string) $credencial);
        $this->assertStringNotContainsString(self::PASSWORD, print_r($credencial, true));
        $this->assertStringNotContainsString(self::PASSWORD, json_encode(['c' => $credencial]));
        ob_start();
        var_dump($credencial);
        $this->assertStringNotContainsString(self::PASSWORD, (string) ob_get_clean());

        $this->expectException(\LogicException::class);
        serialize($credencial);
    }

    /**
     * 🔴 Si algún candidato no respondió, es 502 aunque otro haya dado 401: el suyo puede ser el que no contestó.
     */
    public function test_un_401_y_un_sistema_caido_dan_502(): void
    {
        $rechaza = $this->crear_cliente('rechaza');
        $caido   = $this->crear_cliente('caido');
        $this->en_el_directorio($rechaza, 'lucia', true);
        $this->en_el_directorio($caido, 'lucia', false);
        $this->fakear([
            $this->base($rechaza) . '/*' => Http::response(['code' => 'credenciales_invalidas'], 401),
            $this->base($caido) . '/*'   => Http::response('error', 500),
        ]);

        $this->login(['usuario' => 'lucia', 'password' => self::PASSWORD])
            ->assertStatus(502)->assertJsonPath('code', 'sistema_no_disponible');
    }

    public function test_un_candidato_que_queda_afuera_por_el_presupuesto_da_502(): void
    {
        config(['ia_app.login.presupuesto_segundos' => 0]);
        $primero = $this->crear_cliente('primero');
        $segundo = $this->crear_cliente('segundo');
        $this->en_el_directorio($primero, 'tomas', true);
        $this->en_el_directorio($segundo, 'tomas', false);
        $this->fakear([
            $this->base($primero) . '/*' => Http::response(['code' => 'credenciales_invalidas'], 401),
            $this->base($segundo) . '/*' => $this->token_ok(),
        ]);

        // Con presupuesto 0 alcanza para el primero (el timeout manda) y el segundo ya no entra.
        config(['ia_app.login.timeout' => 15]);
        $this->login(['usuario' => 'tomas', 'password' => self::PASSWORD])
            ->assertStatus(502)->assertJsonPath('code', 'sistema_no_disponible');

        Http::assertSentCount(1);
    }

    public function test_un_401_y_un_404_dan_version_vieja(): void
    {
        $nuevo = $this->crear_cliente('nuevo');
        $viejo = $this->crear_cliente('viejo2');
        $this->en_el_directorio($nuevo, 'sofia', true);
        $this->en_el_directorio($viejo, 'sofia', false);
        $this->fakear([
            $this->base($nuevo) . '/*' => Http::response(['code' => 'credenciales_invalidas'], 401),
            $this->base($viejo) . '/*' => Http::response('', 404),
        ]);

        $this->login(['usuario' => 'sofia', 'password' => self::PASSWORD])
            ->assertStatus(409)->assertJsonPath('code', 'version_vieja');
    }

    public function test_un_3xx_del_sistema_no_se_sigue(): void
    {
        $client   = $this->crear_cliente('redirige');
        $opciones = [];
        $this->fakear([
            $this->base($client) . '/*' => function ($pedido, $opts) use (&$opciones) {
                $opciones[] = $opts;

                return Http::response('', 302, ['Location' => 'https://servidor-ajeno.test/token']);
            },
            'https://servidor-ajeno.test/*' => $this->token_ok('1|robado'),
        ]);

        $this->login(['usuario' => 'pablo', 'password' => self::PASSWORD, 'negocio' => $client->slug])
            ->assertStatus(502)->assertJsonPath('code', 'sistema_no_disponible');

        $this->assertCount(1, $opciones);
        $this->assertFalse($opciones[0]['allow_redirects'], 'El login no sigue redirecciones: la contraseña y la clave viajarían.');
        Http::assertNotSent(function (Request $pedido) {
            return Str::contains($pedido->url(), 'servidor-ajeno.test');
        });
    }

    public function test_un_negocio_con_un_host_desconocido_es_404_sin_salir_a_la_red(): void
    {
        $this->fakear([]);

        $this->login(['usuario' => 'pablo', 'password' => self::PASSWORD, 'negocio' => 'https://host-que-nadie-tiene-' . Str::random(6) . '.test/'])
            ->assertStatus(404)->assertJsonPath('code', 'negocio_no_encontrado');
        $this->login(['usuario' => 'pablo2', 'password' => self::PASSWORD, 'negocio' => 'sistema-desconocido-' . Str::random(6)])
            ->assertStatus(404);

        Http::assertNothingSent();
    }

    /* -------------------------------------------------------------------- Ajustes del verificador del contrato */

    /**
     * La instalación del cliente mal configurada (no la contraseña ni la red): 503 sistema_no_configurado.
     */
    public function test_una_instalacion_mal_configurada_da_503_sistema_no_configurado(): void
    {
        $sin_clave  = $this->crear_cliente('malclave');
        $sin_dueno  = $this->crear_cliente('maldueno');
        $this->fakear([
            $this->base($sin_clave) . '/*' => Http::response(['code' => 'clave_invalida', 'message' => 'x'], 401),
            $this->base($sin_dueno) . '/*' => Http::response(['code' => 'dueno_no_resoluble', 'message' => 'x'], 409),
        ]);

        foreach ([$sin_clave, $sin_dueno] as $client) {
            $this->login(['usuario' => 'pablo-' . $client->id, 'password' => self::PASSWORD, 'negocio' => $client->slug])
                ->assertStatus(503)
                ->assertExactJson([
                    'code'    => 'sistema_no_configurado',
                    'message' => 'Tu sistema todavía no está listo para la app. Avisale a ComercioCity.',
                ]);
        }
    }

    /**
     * Prioridad: si alguno no respondió, 502 aunque otro esté mal configurado; mal configurado + credenciales
     * inválidas (no TODOS dijeron credenciales) es 503.
     */
    public function test_la_mala_configuracion_va_despues_de_la_falla_y_antes_del_resto(): void
    {
        $mal    = $this->crear_cliente('mal');
        $caido  = $this->crear_cliente('caido3');
        $niega  = $this->crear_cliente('niega');
        $this->en_el_directorio($mal, 'ana-maria', true);
        $this->en_el_directorio($caido, 'ana-maria', false);
        $this->en_el_directorio($niega, 'juana', true);
        $this->en_el_directorio($mal, 'juana', false);
        $this->fakear([
            $this->base($mal) . '/*'   => Http::response(['code' => 'clave_invalida'], 401),
            $this->base($caido) . '/*' => Http::response('error', 500),
            $this->base($niega) . '/*' => Http::response(['code' => 'credenciales_invalidas'], 401),
        ]);

        $this->login(['usuario' => 'ana-maria', 'password' => self::PASSWORD])
            ->assertStatus(502)->assertJsonPath('code', 'sistema_no_disponible');
        $this->login(['usuario' => 'juana', 'password' => self::PASSWORD])
            ->assertStatus(503)->assertJsonPath('code', 'sistema_no_configurado');
    }

    /**
     * El 429 del login se puede leer desde la app: `Retry-After` va expuesto por CORS.
     */
    public function test_el_retry_after_del_429_esta_expuesto_por_cors(): void
    {
        $this->assertSame(['Retry-After'], config('cors.exposed_headers'));
        $this->fakear([]);

        $origen = ['Origin' => 'https://ia.comerciocity.com'];
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/ia/login', ['usuario' => 'cors' . $i, 'password' => self::PASSWORD], $origen);
        }

        $respuesta = $this->postJson('/api/ia/login', ['usuario' => 'cors-final', 'password' => self::PASSWORD], $origen);

        $respuesta->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame('https://ia.comerciocity.com', $respuesta->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('Retry-After', (string) $respuesta->headers->get('Access-Control-Expose-Headers'));
    }
}
