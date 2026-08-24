<?php

namespace Tests\Feature;

use App\Jobs\SyncClientScheduleJob;
use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientScheduleDay;
use App\Models\ClientScheduleRange;
use App\Services\ClientScheduleResolver;
use App\Services\ClientScheduleSyncService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El push de los horarios comerciales al empresa-api de cada cliente (§16 del plan).
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 Que el push NO corra adentro del request que guarda los horarios, y que se encole en la
 *     conexión `database`. La aserción verifica `$job->connection === 'database'` EXPLÍCITAMENTE:
 *     `QueueFake::connection()` devuelve `$this` sin mirar el nombre, así que un `assertPushed`
 *     pelado pasaría igual con un `dispatch()` sin `onConnection` — o sea que no protegería
 *     justamente el requisito, que es la regresión más probable acá (documentado en
 *     tests/Feature/DemoSetupFueraDelRequestTest.php:148-152).
 *  2. Que la `semana` viaje YA RESUELTA, con el día puntual pisando a "Todos los días". Si el
 *     payload viajara crudo, empresa-api tendría que reimplementar la regla de precedencia y el día
 *     que cambie quedarían dos criterios y uno se olvidaría.
 *  3. 🔴 Que `configurado: false` ("no hay dato") NO se confunda con "cerrado". Un agente que los
 *     mezcle le va a decir a un comprador que el comercio está cerrado un martes a las 10.
 *  4. Que el 404 —el caso ESPERADO hasta que salga la mitad de empresa-api— se degrade limpio a
 *     `manual_required` sin lanzar excepción y sin pisar `schedule_synced_at`.
 */
class SincronizacionDeHorariosAlClienteTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests contra las rutas claude/*. */
    const CLAVE = 'clave-de-prueba-sync-horarios';

    /**
     * Setea la clave de ingesta: en el .env del slot está vacía y el middleware es fail-closed, así
     * que sin esto las rutas claude/* devolverían 401 y se estaría midiendo el middleware.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Headers con la clave de ingesta de Claude.
     *
     * @return array<string, string>
     */
    private function headers_claude(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ];
    }

    /**
     * Admin logueado por Sanctum (las rutas admin/* viven bajo auth:sanctum).
     *
     * @return Admin
     */
    private function admin_logueado(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de sincronización';
        $admin->email    = 'sync-horarios-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Cliente mínimo con URL y api_key cargadas (los dos cortes previos al HTTP resueltos).
     *
     * @param bool $activo Si el cliente está activo.
     *
     * @return Client
     */
    private function crear_cliente(bool $activo = true): Client
    {
        $client                  = new Client();
        $client->name            = 'Cliente sync horarios';
        $client->slug            = 'cliente-sync-horarios-' . Str::random(8);
        $client->api_url         = 'https://api-cliente-sync.test';
        $client->api_key         = 'clave-api-del-cliente';
        $client->inbound_api_key = 'clave-inbound';
        $client->is_active       = $activo;
        $client->save();

        return $client;
    }

    /**
     * Carga una fila de día con sus rangos directamente en la base.
     *
     * @param Client                         $client  Cliente dueño del horario.
     * @param string                         $day_key Clave del día ('todos', 'martes', …).
     * @param array<int, array<int, string>> $rangos  Pares [desde, hasta] en formato 'H:i'.
     *
     * @return ClientScheduleDay
     */
    private function cargar_dia(Client $client, string $day_key, array $rangos = []): ClientScheduleDay
    {
        $dia            = new ClientScheduleDay();
        $dia->client_id = $client->id;
        $dia->day_key   = $day_key;
        $dia->save();

        $orden = 0;
        foreach ($rangos as $par) {
            $rango                         = new ClientScheduleRange();
            $rango->client_schedule_day_id = $dia->id;
            $rango->start_time             = $par[0];
            $rango->end_time               = $par[1];
            $rango->sort_order             = $orden;
            $rango->save();
            $orden++;
        }

        return $dia;
    }

    /**
     * El servicio, resuelto por el contenedor.
     *
     * @return ClientScheduleSyncService
     */
    private function servicio(): ClientScheduleSyncService
    {
        return app(ClientScheduleSyncService::class);
    }

    /**
     * Busca la entrada de un día en la semana resuelta del payload.
     *
     * @param array  $semana  Array `semana` del payload.
     * @param string $day_key Clave del día buscado.
     *
     * @return array
     */
    private function dia_de_la_semana(array $semana, string $day_key): array
    {
        foreach ($semana as $dia) {
            if ($dia['dia'] === $day_key) {
                return $dia;
            }
        }

        $this->fail('El payload no trae el día "' . $day_key . '" en la semana resuelta.');
    }

    // ---------------------------------------------------------------------
    // 33) El PUT encola y no llama a nadie adentro del request
    // ---------------------------------------------------------------------

    /**
     * 33. Guardar horarios encola el push en la conexión `database` y no hace ningún HTTP adentro
     * del request.
     *
     * 🔴 Se afirma la CONEXIÓN, no sólo que se despachó: `QueueFake::connection()` devuelve `$this`
     * sin mirar el nombre, así que un `assertPushed` pelado pasaría igual sin `onConnection` y el
     * test no protegería nada.
     */
    public function test_guardar_horarios_encola_el_push_en_la_conexion_database_y_no_llama_a_nadie()
    {
        Queue::fake();
        Http::fake();

        $this->admin_logueado();
        $client = $this->crear_cliente();

        $respuesta = $this->putJson('/api/admin/client/' . $client->id . '/horarios', [
            'dias' => [
                ['dia' => 'todos', 'rangos' => [['desde' => '09:00', 'hasta' => '18:00']]],
            ],
        ]);

        $respuesta->assertStatus(200);

        $encolados = 0;

        Queue::assertPushed(SyncClientScheduleJob::class, function ($job) use (&$encolados, $client) {
            $encolados++;

            // 🔴 La conexión explícita es TODO el punto: sin esto el push correría inline.
            return $job->connection === 'database';
        });

        // Guarda propia: si el closure de arriba nunca corriera, assertPushed pasaría igual y este
        // test se estaría midiendo a sí mismo.
        $this->assertSame(1, $encolados, 'Se esperaba exactamente un SyncClientScheduleJob encolado.');

        // El request no habló con nadie: el push quedó encolado, no ejecutado.
        Http::assertNothingSent();

        // Y no se escribió ningún estado de sincronización: todavía no se intentó nada.
        $this->assertNull($client->refresh()->schedule_sync_status);
    }

    /** El endpoint de reintento a mano (Sanctum) encola con la misma conexión y no llama a nadie. */
    public function test_el_reintento_a_mano_encola_con_la_misma_conexion()
    {
        Queue::fake();
        Http::fake();

        $this->admin_logueado();
        $client = $this->crear_cliente();

        $respuesta = $this->postJson('/api/admin/client/' . $client->id . '/horarios/sync');

        $respuesta->assertStatus(202);
        $this->assertTrue($respuesta->json('encolado'));

        $encolados = 0;

        Queue::assertPushed(SyncClientScheduleJob::class, function ($job) use (&$encolados) {
            $encolados++;

            return $job->connection === 'database';
        });

        $this->assertSame(1, $encolados, 'Se esperaba exactamente un SyncClientScheduleJob encolado.');
        Http::assertNothingSent();
    }

    /** El reintento por claude/* hace lo mismo: encola en `database` y devuelve 202. */
    public function test_el_reintento_por_claude_encola_con_la_misma_conexion()
    {
        Queue::fake();
        Http::fake();

        $client = $this->crear_cliente();

        $respuesta = $this->postJson(
            '/api/claude/clients/' . $client->id . '/schedule/sync',
            [],
            $this->headers_claude()
        );

        $respuesta->assertStatus(202);
        $this->assertSame('database', $respuesta->json('conexion'));
        $this->assertSame((int) $client->id, $respuesta->json('client.id'));

        $encolados = 0;

        Queue::assertPushed(SyncClientScheduleJob::class, function ($job) use (&$encolados) {
            $encolados++;

            return $job->connection === 'database';
        });

        $this->assertSame(1, $encolados, 'Se esperaba exactamente un SyncClientScheduleJob encolado.');
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------
    // 34) La semana viaja resuelta y el día puntual pisa a "Todos los días"
    // ---------------------------------------------------------------------

    /**
     * 34. El payload lleva los SIETE días resueltos, y el día puntual pisa a "Todos los días".
     *
     * Cliente con `todos` 9–18 + `martes` 8–13 ⇒ el martes viaja 8–13 y los otros seis, 9–18.
     */
    public function test_el_payload_lleva_la_semana_resuelta_con_el_dia_puntual_pisando_a_todos()
    {
        $client = $this->crear_cliente();

        $this->cargar_dia($client, 'todos', [['09:00', '18:00']]);
        $this->cargar_dia($client, 'martes', [['08:00', '13:00']]);

        $payload = $this->servicio()->build_payload($client);

        $this->assertTrue($payload['configurado']);
        $this->assertSame(config('app.timezone'), $payload['timezone']);
        $this->assertCount(7, $payload['semana'], 'La semana tiene que viajar con los siete días.');

        // Los dia_semana son los de Carbon::dayOfWeek, 0 = domingo, y viajan en orden.
        $this->assertSame([0, 1, 2, 3, 4, 5, 6], array_column($payload['semana'], 'dia_semana'));
        $this->assertSame(
            ClientScheduleDay::DAY_KEYS_BY_DOW,
            array_column($payload['semana'], 'dia')
        );

        $martes = $this->dia_de_la_semana($payload['semana'], 'martes');
        $this->assertTrue($martes['abierto']);
        $this->assertSame(ClientScheduleResolver::ORIGEN_DIA_PROPIO, $martes['origen']);
        $this->assertSame([['desde' => '08:00', 'hasta' => '13:00']], $martes['rangos']);
        $this->assertSame('13:00', $martes['cierre']);

        // Los otros seis heredan de "Todos los días".
        $heredados = 0;
        foreach ($payload['semana'] as $dia) {
            if ($dia['dia'] === 'martes') {
                continue;
            }

            $heredados++;
            $this->assertTrue($dia['abierto']);
            $this->assertSame(ClientScheduleResolver::ORIGEN_TODOS_LOS_DIAS, $dia['origen']);
            $this->assertSame([['desde' => '09:00', 'hasta' => '18:00']], $dia['rangos']);
        }

        $this->assertSame(6, $heredados, 'Se esperaban seis días heredando de "Todos los días".');

        // `dias_crudos` viaja como comodidad de lectura, no como fuente de verdad.
        $this->assertSame(['todos', 'martes'], array_column($payload['dias_crudos'], 'dia'));
    }

    // ---------------------------------------------------------------------
    // 35) Día cerrado vs. cliente sin configurar: dos cosas distintas
    // ---------------------------------------------------------------------

    /**
     * 35. Un día cargado SIN rangos viaja `abierto: false` con `rangos: []`, y un cliente sin
     * ningún día viaja `configurado: false` con `semana: []`.
     *
     * 🔴 Son dos estados distintos: "cerrado" es un dato, "sin configurar" es la ausencia de dato.
     * Un agente que los confunda le va a decir a un comprador que el comercio está cerrado un
     * martes a las 10 de la mañana.
     */
    public function test_un_dia_sin_rangos_es_cerrado_y_un_cliente_sin_dias_es_sin_configurar()
    {
        // Mitad A: cliente con configuración, y un día cerrado adentro.
        $con_horarios = $this->crear_cliente();
        $this->cargar_dia($con_horarios, 'todos', [['09:00', '18:00']]);
        $this->cargar_dia($con_horarios, 'domingo', []);

        $payload_a = $this->servicio()->build_payload($con_horarios);

        $this->assertTrue($payload_a['configurado']);

        $domingo = $this->dia_de_la_semana($payload_a['semana'], 'domingo');
        $this->assertFalse($domingo['abierto']);
        $this->assertSame([], $domingo['rangos']);
        $this->assertSame(ClientScheduleResolver::ESTADO_CERRADO, $domingo['estado']);
        $this->assertSame(ClientScheduleResolver::ORIGEN_DIA_PROPIO, $domingo['origen']);
        $this->assertNull($domingo['cierre']);

        // El día cerrado igual viaja en `dias_crudos`, con rangos vacíos: existir es el hecho.
        $crudo_domingo = null;
        foreach ($payload_a['dias_crudos'] as $dia) {
            if ($dia['dia'] === 'domingo') {
                $crudo_domingo = $dia;
            }
        }
        $this->assertNotNull($crudo_domingo, 'El día cerrado tiene que viajar en dias_crudos.');
        $this->assertSame([], $crudo_domingo['rangos']);

        // Mitad B: cliente sin NINGÚN día cargado. No hay dato, y no es lo mismo que cerrado.
        $sin_horarios = $this->crear_cliente();

        $payload_b = $this->servicio()->build_payload($sin_horarios);

        $this->assertFalse($payload_b['configurado']);
        $this->assertSame([], $payload_b['semana']);
        $this->assertSame([], $payload_b['dias_crudos']);
    }

    // ---------------------------------------------------------------------
    // 36-38) Los desenlaces del push
    // ---------------------------------------------------------------------

    /**
     * 36. Un 404 del cliente degrada a `manual_required` con el motivo cargado, sin tocar
     * `schedule_synced_at` y sin lanzar excepción.
     *
     * 🔴 Es el caso ESPERADO hasta que salga la mitad de empresa-api: hoy ninguna instancia tiene
     * la ruta admin-sync/business-hours. Que se degrade limpio es lo que hace que esta mitad se
     * pueda mergear sola.
     */
    public function test_un_404_del_cliente_degrada_a_manual_required_sin_tocar_la_fecha()
    {
        Http::fake([
            '*' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $client = $this->crear_cliente();
        $this->cargar_dia($client, 'todos', [['09:00', '18:00']]);

        $resultado = $this->servicio()->sync($client);

        $this->assertSame(ClientScheduleSyncService::ESTADO_MANUAL_REQUIRED, $resultado['status']);

        $client->refresh();
        $this->assertSame('manual_required', (string) $client->schedule_sync_status);
        $this->assertNotEmpty((string) $client->schedule_sync_message);
        $this->assertStringContainsString('404', (string) $client->schedule_sync_message);
        // La fecha del último éxito no se pisa con un fallo: sigue siendo null porque nunca hubo uno.
        $this->assertNull($client->schedule_synced_at);

        // Guarda propia: se midió una llamada real al endpoint del contrato, no otra cosa.
        $enviadas = 0;
        Http::assertSent(function ($request) use (&$enviadas) {
            $enviadas++;

            return strpos($request->url(), 'api/admin-sync/business-hours') !== false;
        });
        $this->assertGreaterThanOrEqual(1, $enviadas, 'Se esperaba al menos una llamada HTTP.');
    }

    /**
     * 37. Un 200 del cliente deja `success` y estampa `schedule_synced_at`, con el payload del
     * contrato viajando en el cuerpo del PUT y la api_key en el header.
     */
    public function test_un_200_del_cliente_deja_success_y_estampa_la_fecha()
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        $client = $this->crear_cliente();
        $this->cargar_dia($client, 'todos', [['09:00', '18:00']]);
        $this->cargar_dia($client, 'sabado', [['09:00', '13:00']]);

        $resultado = $this->servicio()->sync($client);

        $this->assertSame(ClientScheduleSyncService::ESTADO_SUCCESS, $resultado['status']);
        $this->assertNull($resultado['message']);

        $client->refresh();
        $this->assertSame('success', (string) $client->schedule_sync_status);
        $this->assertNull($client->schedule_sync_message);
        $this->assertNotNull($client->schedule_synced_at);

        $verificadas = 0;

        Http::assertSent(function ($request) use (&$verificadas, $client) {
            if (strpos($request->url(), 'api/admin-sync/business-hours') === false) {
                return false;
            }

            $verificadas++;

            $cuerpo = $request->data();

            $this->assertSame('PUT', $request->method());
            $this->assertSame($client->api_key, $request->header('X-Admin-Api-Key')[0]);
            $this->assertTrue($cuerpo['configurado']);
            $this->assertCount(7, $cuerpo['semana']);
            $this->assertSame(config('app.timezone'), $cuerpo['timezone']);
            $this->assertNotEmpty($cuerpo['actualizado_en']);
            $this->assertSame(['todos', 'sabado'], array_column($cuerpo['dias_crudos'], 'dia'));

            return true;
        });

        // Guarda propia: si el closure no hubiera corrido, assertSent pasaría sin verificar nada.
        $this->assertGreaterThanOrEqual(1, $verificadas, 'Se esperaba al menos una llamada verificada.');
    }

    /**
     * 38. Un cliente inactivo se saltea: `skipped` y CERO llamadas HTTP.
     *
     * Mismo criterio que PublishVersionService::syncExisting(). No es un fallo: es que no
     * corresponde llamar a nadie.
     */
    public function test_un_cliente_inactivo_se_saltea_sin_llamar_a_nadie()
    {
        Http::fake();

        $client = $this->crear_cliente(false);
        $this->cargar_dia($client, 'todos', [['09:00', '18:00']]);

        $resultado = $this->servicio()->sync($client);

        $this->assertSame(ClientScheduleSyncService::ESTADO_SKIPPED, $resultado['status']);

        $client->refresh();
        $this->assertSame('skipped', (string) $client->schedule_sync_status);
        $this->assertNotEmpty((string) $client->schedule_sync_message);
        $this->assertNull($client->schedule_synced_at);

        Http::assertNothingSent();
    }

    /** Sin api_key el push ni se intenta: `manual_required` y cero llamadas. */
    public function test_sin_api_key_no_se_intenta_el_push()
    {
        Http::fake();

        $client          = $this->crear_cliente();
        $client->api_key = '';
        $client->save();

        $this->cargar_dia($client, 'todos', [['09:00', '18:00']]);

        $resultado = $this->servicio()->sync($client);

        $this->assertSame(ClientScheduleSyncService::ESTADO_MANUAL_REQUIRED, $resultado['status']);
        $this->assertSame('manual_required', (string) $client->refresh()->schedule_sync_status);

        Http::assertNothingSent();
    }

    /** Un 500 del cliente termina en `failed` (no en manual_required) y no lanza excepción. */
    public function test_un_500_del_cliente_termina_en_failed()
    {
        Http::fake([
            '*' => Http::response('explotó', 500),
        ]);

        $client = $this->crear_cliente();
        $this->cargar_dia($client, 'todos', [['09:00', '18:00']]);

        $resultado = $this->servicio()->sync($client);

        $this->assertSame(ClientScheduleSyncService::ESTADO_FAILED, $resultado['status']);

        $client->refresh();
        $this->assertSame('failed', (string) $client->schedule_sync_status);
        $this->assertStringContainsString('500', (string) $client->schedule_sync_message);
        $this->assertNull($client->schedule_synced_at);
    }

    /** El job lee el cliente por id al correr y delega en el servicio; un cliente borrado no rompe. */
    public function test_el_job_corre_el_push_y_tolera_un_cliente_inexistente()
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        $client = $this->crear_cliente();
        $this->cargar_dia($client, 'todos', [['09:00', '18:00']]);

        $job = new SyncClientScheduleJob($client->id);
        $job->handle($this->servicio());

        $this->assertSame('success', (string) $client->refresh()->schedule_sync_status);

        // Con un id que no existe el job se va sin hacer nada: no lanza y no llama a nadie.
        $llamadas_antes = 0;
        Http::assertSent(function () use (&$llamadas_antes) {
            $llamadas_antes++;

            return true;
        });

        $job_fantasma = new SyncClientScheduleJob(999999999);
        $job_fantasma->handle($this->servicio());

        $llamadas_despues = 0;
        Http::assertSent(function () use (&$llamadas_despues) {
            $llamadas_despues++;

            return true;
        });

        $this->assertSame($llamadas_antes, $llamadas_despues, 'El cliente inexistente no puede generar llamadas.');
    }
}
