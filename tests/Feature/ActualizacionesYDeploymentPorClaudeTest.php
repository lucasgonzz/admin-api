<?php

namespace Tests\Feature;

use App\Jobs\RunDeploymentJob;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientScheduleDay;
use App\Models\ClientScheduleRange;
use App\Models\ClientVersionUpgrade;
use App\Models\DeploymentLog;
use App\Models\UpdateCommand;
use App\Models\UpdateSeeder;
use App\Models\Version;
use App\Models\VersionCommand;
use App\Models\VersionSeeder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Los endpoints `claude/*` de ESCRITURA: crear una actualización y arrancar el deployment.
 *
 * Son los más peligrosos del sistema: crean upgrades sobre clientes REALES y arrancan procesos por
 * SSH que pueden dejar un negocio sin sistema. Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 Que los tres `dispatch()` de escritura (start, post-cierre y configuración) vayan a la
 *     conexión `database` y NO corran el pipeline adentro del request. Con `QUEUE_CONNECTION=sync`, un dispatch pelado ejecuta el pipeline SSH entero
 *     dentro del request HTTP y lo mata `max_execution_time` a los 120 segundos.
 *     ⚠️ El `return $job->connection === 'database'` de cada aserción NO es decorativo:
 *     `QueueFake::connection()` devuelve `$this` sin mirar el nombre, así que un `assertPushed`
 *     pelado pasaría igual con un `dispatch()` sin `onConnection` — o sea, no probaría nada. Está
 *     documentado en `tests/Feature/DemoSetupFueraDelRequestTest.php:148-152`.
 *  2. 🔴 Que el gate de horario del post-cierre rechace con el negocio ABIERTO y también cuando el
 *     cliente no tiene horarios cargados (`sin_configurar`). "No sé" no es "está cerrado": ese es
 *     exactamente el caso donde adivinar corre seeders sobre un negocio lleno de gente.
 *  3. Que TODO freno que rechaza devuelva 422 y no escriba absolutamente nada: ni estado, ni log,
 *     ni job encolado.
 *  4. Que `confirm_client_name` no revele el nombre correcto cuando falla: si lo revelara, dejaría
 *     de ser un freno y sería un formulario a completar.
 */
class ActualizacionesYDeploymentPorClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-claude-upgrades';

    /**
     * Setea la clave de ingesta: en el .env del slot está vacía y el middleware es fail-closed, así
     * que sin esto todo devolvería 401 y los tests medirían el middleware, no el endpoint.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
    }

    /**
     * Deja el reloj como estaba: varios tests lo congelan para poder decidir si el negocio está
     * abierto o cerrado.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------------------------------
     | Armado del escenario
     |----------------------------------------------------------------------------------------- */

    /**
     * Headers con la clave de ingesta.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ];
    }

    /**
     * Versión del catálogo.
     *
     * @param string $codigo    Número de versión.
     * @param bool   $is_hotfix Si es hotfix.
     * @param string $status    draft | published | archived.
     *
     * @return Version
     */
    private function crear_version(string $codigo, bool $is_hotfix = false, string $status = 'published'): Version
    {
        $version               = new Version();
        $version->version      = $codigo;
        $version->title        = 'Versión ' . $codigo;
        $version->status       = $status;
        $version->is_hotfix    = $is_hotfix;
        $version->published_at = $status === 'published' ? now() : null;
        $version->save();

        return $version;
    }

    /**
     * Seeder de una versión, sin restricción de clientes (aplica a todos).
     *
     * @param Version $version Versión dueña.
     *
     * @return VersionSeeder
     */
    private function agregar_seeder(Version $version): VersionSeeder
    {
        $seeder                  = new VersionSeeder();
        $seeder->version_id      = $version->id;
        $seeder->seeder_class    = 'Database\\Seeders\\Falso' . Str::random(6) . 'Seeder';
        $seeder->execution_order = 1;
        $seeder->save();

        return $seeder;
    }

    /**
     * Comando de una versión.
     *
     * @param Version $version Versión dueña.
     *
     * @return VersionCommand
     */
    private function agregar_comando(Version $version): VersionCommand
    {
        $comando                  = new VersionCommand();
        $comando->version_id      = $version->id;
        $comando->command         = 'comando:falso-' . Str::random(6);
        $comando->execution_order = 1;
        $comando->save();

        return $comando;
    }

    /**
     * Cliente con dos APIs: la activa en producción y la de destino.
     *
     * @param string   $nombre     Nombre del cliente (el que confirma el freno 1).
     * @param int|null $version_id Versión actual.
     *
     * @return Client
     */
    private function crear_cliente(string $nombre, $version_id = null): Client
    {
        $client                     = new Client();
        $client->name               = $nombre;
        $client->company_name       = 'Empresa ' . $nombre;
        $client->slug               = Str::slug($nombre) . '-' . Str::random(8);
        $client->api_url            = 'https://ejemplo.test';
        $client->api_key            = 'clave-api';
        $client->inbound_api_key    = 'clave-inbound';
        $client->is_active          = true;
        $client->current_version_id = $version_id;
        $client->save();

        return $client;
    }

    /**
     * API de un cliente.
     *
     * @param Client $client Cliente dueño.
     * @param string $url    URL de la API.
     *
     * @return ClientApi
     */
    private function crear_api(Client $client, string $url): ClientApi
    {
        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = $url;
        $api->path         = 'ejemplo/' . Str::random(6);
        $api->hosting_type = 'shared_hosting';
        $api->save();

        return $api;
    }

    /**
     * Carga una fila de día con sus rangos. Sin rangos = ese día cerrado.
     *
     * @param Client                        $client  Cliente dueño.
     * @param string                        $day_key Clave del día.
     * @param array<int, array<int, string>> $rangos  Pares [desde, hasta] en 'H:i'.
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
     * Escenario base de creación: cliente en 4.0.0, rango publicado hasta 4.0.2 con un hotfix
     * intermedio, seeders en las dos troncales y un comando en la primera.
     *
     * @return array<string, mixed>
     */
    private function armar_escenario(): array
    {
        $from   = $this->crear_version('4.0.0');
        $uno    = $this->crear_version('4.0.1');
        $hotfix = $this->crear_version('4.0.1.1', true);
        $dos    = $this->crear_version('4.0.2');

        $seeder_uno = $this->agregar_seeder($uno);
        $seeder_dos = $this->agregar_seeder($dos);
        $comando    = $this->agregar_comando($uno);

        $client = $this->crear_cliente('Cliente Rioplatense', $from->id);

        $api_activa  = $this->crear_api($client, 'https://api-activa.ejemplo.test');
        $api_destino = $this->crear_api($client, 'https://api-destino.ejemplo.test');

        $client->active_client_api_id = $api_activa->id;
        $client->save();

        return compact(
            'from', 'uno', 'hotfix', 'dos',
            'seeder_uno', 'seeder_dos', 'comando',
            'client', 'api_activa', 'api_destino'
        );
    }

    /**
     * Upgrade ya creado, listo para los endpoints de deployment.
     *
     * @param array<string, mixed> $escenario Escenario base.
     * @param array<string, mixed> $atributos Atributos del upgrade (estado del deployment, pasos…).
     *
     * @return ClientVersionUpgrade
     */
    private function crear_upgrade(array $escenario, array $atributos = []): ClientVersionUpgrade
    {
        return ClientVersionUpgrade::create(array_merge([
            'client_id'            => $escenario['client']->id,
            'from_version_id'      => $escenario['from']->id,
            'to_version_id'        => $escenario['dos']->id,
            'status'               => 'pendiente',
            'scheduled_date'       => now()->toDateString(),
            'target_client_api_id' => $escenario['api_destino']->id,
        ], $atributos));
    }

    /**
     * Cuerpo mínimo de una creación real (sin dry_run).
     *
     * @param array<string, mixed> $escenario Escenario base.
     * @param array<string, mixed> $extra     Campos a pisar.
     *
     * @return array<string, mixed>
     */
    private function cuerpo_de_creacion(array $escenario, array $extra = []): array
    {
        return array_merge([
            'client_id'             => $escenario['client']->id,
            'to_version_id'         => $escenario['dos']->id,
            'confirmed_version_ids' => [$escenario['uno']->id, $escenario['dos']->id],
            'dry_run'               => false,
            'confirm_version_count' => 2,
            'confirm_client_name'   => 'Cliente Rioplatense',
        ], $extra);
    }

    /**
     * Etapa de reanudación con la que se despachó un RunDeploymentJob.
     *
     * Se lee por reflexión porque la propiedad es privada: el contrato del job no se cambia para que
     * un test pueda mirarlo, y la etapa es justo lo que distingue el pre-cierre del post-cierre.
     *
     * @param RunDeploymentJob $job Job despachado.
     *
     * @return string|null
     */
    private function etapa_del_job(RunDeploymentJob $job)
    {
        $propiedad = new \ReflectionProperty(RunDeploymentJob::class, 'resume_from_step');
        $propiedad->setAccessible(true);

        return $propiedad->getValue($job);
    }

    /**
     * Momento base de los tests de horario: martes 25/8/2026 al mediodía, hora de Buenos Aires.
     *
     * @return Carbon
     */
    private function momento_base(): Carbon
    {
        return Carbon::parse('2026-08-25 12:00:00', config('app.timezone'));
    }

    /* ==========================================================================================
     | El middleware
     |========================================================================================= */

    /** Sin el header X-Claude-Task-Key, las seis rutas de escritura devuelven 401. */
    public function test_sin_clave_de_ingesta_las_rutas_de_escritura_devuelven_401(): void
    {
        $rutas = [
            '/api/claude/upgrades/preview',
            '/api/claude/upgrades',
            '/api/claude/upgrades/1/deploy/start',
            '/api/claude/upgrades/1/mark-crons',
            '/api/claude/upgrades/1/deploy/start-post-closure',
            '/api/claude/upgrades/1/deploy/configure-system',
        ];

        foreach ($rutas as $ruta) {
            $this->postJson($ruta, [])->assertStatus(401);
        }
    }

    /* ==========================================================================================
     | 9) El preview
     |========================================================================================= */

    /** El preview devuelve las candidatas del rango y no persiste absolutamente nada. */
    public function test_preview_devuelve_las_candidatas_y_no_crea_nada(): void
    {
        $e = $this->armar_escenario();

        $antes = ClientVersionUpgrade::count();

        $cuerpo = $this->postJson('/api/claude/upgrades/preview', [
            'client_id'     => $e['client']->id,
            'to_version_id' => $e['dos']->id,
        ], $this->headers())->assertStatus(200)->json();

        $versiones = array_column($cuerpo['candidates'], 'version');
        $this->assertSame(['4.0.1', '4.0.1.1', '4.0.2'], $versiones);

        /* default_checked: troncal sí, hotfix no, destino siempre. */
        $por_version = [];
        foreach ($cuerpo['candidates'] as $candidata) {
            $por_version[$candidata['version']] = $candidata;
        }

        $this->assertTrue($por_version['4.0.1']['default_checked']);
        $this->assertFalse($por_version['4.0.1.1']['default_checked']);
        $this->assertTrue($por_version['4.0.2']['default_checked']);
        $this->assertTrue($por_version['4.0.2']['is_target']);

        $this->assertSame($antes, ClientVersionUpgrade::count(), 'El preview no puede persistir nada.');
    }

    /* ==========================================================================================
     | 27-32) La creación
     |========================================================================================= */

    /**
     * 27) El default es `dry_run`: no crea el upgrade, ni los UpdateSeeder, ni los UpdateCommand, y
     * devuelve los conteos de lo que crearía.
     */
    public function test_creacion_en_dry_run_por_defecto_no_escribe_nada(): void
    {
        $e = $this->armar_escenario();

        $upgrades_antes = ClientVersionUpgrade::count();
        $seeders_antes  = UpdateSeeder::count();
        $comandos_antes = UpdateCommand::count();

        $cuerpo = $this->postJson('/api/claude/upgrades', [
            'client_id'             => $e['client']->id,
            'to_version_id'         => $e['dos']->id,
            'confirmed_version_ids' => [$e['uno']->id, $e['dos']->id],
        ], $this->headers())->assertStatus(200)->json();

        $this->assertTrue($cuerpo['dry_run']);
        $this->assertSame(2, $cuerpo['crearia']['cantidad']);

        /* Guarda: si los conteos fueran cero, el test pasaría midiéndose a sí mismo. */
        $this->assertGreaterThan(0, $cuerpo['crearia']['seeders_que_se_crearian']);
        $this->assertGreaterThan(0, $cuerpo['crearia']['comandos_que_se_crearian']);
        $this->assertSame(2, $cuerpo['crearia']['seeders_que_se_crearian']);
        $this->assertSame(1, $cuerpo['crearia']['comandos_que_se_crearian']);
        $this->assertSame('claude', $cuerpo['crearia']['created_via']);

        $this->assertSame($upgrades_antes, ClientVersionUpgrade::count(), 'El dry_run creó un upgrade.');
        $this->assertSame($seeders_antes, UpdateSeeder::count(), 'El dry_run creó UpdateSeeders.');
        $this->assertSame($comandos_antes, UpdateCommand::count(), 'El dry_run creó UpdateCommands.');
    }

    /** 28) `confirm_version_count` equivocado → 422 y cero filas creadas. */
    public function test_creacion_con_confirm_version_count_equivocado_no_crea_nada(): void
    {
        $e = $this->armar_escenario();

        $antes = ClientVersionUpgrade::count();

        $cuerpo = $this->postJson(
            '/api/claude/upgrades',
            $this->cuerpo_de_creacion($e, ['confirm_version_count' => 5]),
            $this->headers()
        )->assertStatus(422)->json();

        $this->assertSame(2, $cuerpo['versiones_que_se_confirmarian']);
        $this->assertSame($antes, ClientVersionUpgrade::count());
    }

    /**
     * 28.b) Sin `confirm_version_count`, con dry_run=false, tampoco crea nada: el freno no se
     * satisface por omisión.
     */
    public function test_creacion_sin_confirm_version_count_no_crea_nada(): void
    {
        $e = $this->armar_escenario();

        $cuerpo_pedido = $this->cuerpo_de_creacion($e);
        unset($cuerpo_pedido['confirm_version_count']);

        $antes = ClientVersionUpgrade::count();

        $this->postJson('/api/claude/upgrades', $cuerpo_pedido, $this->headers())->assertStatus(422);

        $this->assertSame($antes, ClientVersionUpgrade::count());
    }

    /**
     * 29) `confirm_client_name` equivocado → 422 y cero filas creadas. Y el error NO dice cómo se
     * llama el cliente: si lo dijera, dejaría de ser un freno.
     */
    public function test_creacion_con_confirm_client_name_equivocado_no_crea_nada_ni_revela_el_nombre(): void
    {
        $e = $this->armar_escenario();

        $antes = ClientVersionUpgrade::count();

        $cuerpo = $this->postJson(
            '/api/claude/upgrades',
            $this->cuerpo_de_creacion($e, ['confirm_client_name' => 'Otro Cliente Cualquiera']),
            $this->headers()
        )->assertStatus(422)->json();

        $this->assertSame($antes, ClientVersionUpgrade::count());
        $this->assertStringNotContainsStringIgnoringCase(
            'Cliente Rioplatense',
            json_encode($cuerpo, JSON_UNESCAPED_UNICODE),
            'El error del freno no puede revelar el nombre correcto del cliente.'
        );
    }

    /**
     * 29.b) Cliente SIN nombre cargado: el freno sigue cerrado (ningún `confirm_client_name` puede
     * coincidir con un nombre vacío), pero el error dice LA CAUSA REAL en vez de hablar de que el
     * nombre "no coincide".
     */
    public function test_un_cliente_sin_nombre_cargado_explica_por_que_no_se_puede_confirmar(): void
    {
        $e = $this->armar_escenario();

        /* `clients.name` es NOT NULL en la base: el caso real es el vacío (o solo espacios). */
        $e['client']->name = '   ';
        $e['client']->save();

        $antes = ClientVersionUpgrade::count();

        $cuerpo = $this->postJson(
            '/api/claude/upgrades',
            $this->cuerpo_de_creacion($e, ['confirm_client_name' => 'Cliente Rioplatense']),
            $this->headers()
        )->assertStatus(422)->json();

        $this->assertSame($antes, ClientVersionUpgrade::count(), 'No se puede haber creado nada.');
        $this->assertStringContainsString('NO tiene nombre cargado', $cuerpo['error']);
        $this->assertSame((int) $e['client']->id, $cuerpo['client_id']);

        /* Y un string vacío tampoco pasa: fail-closed, no se afloja. */
        $this->postJson(
            '/api/claude/upgrades',
            $this->cuerpo_de_creacion($e, ['confirm_client_name' => ' ']),
            $this->headers()
        )->assertStatus(422);

        $this->assertSame($antes, ClientVersionUpgrade::count());
    }

    /**
     * 29.b.2) 🔴 Crear el upgrade NO puede devolver 422 después de haberlo creado.
     *
     * El payload de la respuesta lo arma el endpoint de LECTURA, que valida su propio `timezone`.
     * Si se le pasara el Request del POST de escritura, un `timezone` inválido en ese body haría
     * que la creación conteste 422 con el upgrade YA creado — y quien lee un 422 asume que no se
     * escribió nada y reintenta, dejando un duplicado.
     */
    public function test_un_timezone_invalido_en_el_body_no_convierte_la_creacion_en_un_422(): void
    {
        $e = $this->armar_escenario();

        $antes = ClientVersionUpgrade::count();

        $this->postJson(
            '/api/claude/upgrades',
            $this->cuerpo_de_creacion($e, ['timezone' => str_repeat('z', 120)]),
            $this->headers()
        )->assertStatus(201);

        $this->assertSame($antes + 1, ClientVersionUpgrade::count(), 'Se tiene que haber creado exactamente uno.');
    }

    /**
     * 29.c) Un `target_client_api_id` de OTRO cliente sale por el 422 del bloque (con `error` y
     * `ayuda`), no por el `abort(422)` crudo del servicio. Vale también en dry-run, que no escribe
     * nada: el contrato del bloque tiene que ser uno solo.
     */
    public function test_una_api_destino_de_otro_cliente_sale_por_el_422_del_bloque(): void
    {
        $e     = $this->armar_escenario();
        $ajeno = $this->crear_cliente('Cliente Ajeno');
        $api   = $this->crear_api($ajeno, 'https://api-ajena.ejemplo.test');

        $antes = ClientVersionUpgrade::count();

        /* Dry-run: el caso que salía por el handler de Laravel, sin `error` ni `ayuda`. */
        $cuerpo = $this->postJson(
            '/api/claude/upgrades',
            $this->cuerpo_de_creacion($e, [
                'dry_run'              => true,
                'target_client_api_id' => $api->id,
            ]),
            $this->headers()
        )->assertStatus(422)->json();

        $this->assertArrayHasKey('error', $cuerpo);
        $this->assertArrayHasKey('ayuda', $cuerpo);
        $this->assertSame((int) $api->id, $cuerpo['target_client_api_id']);

        /* Y en la escritura real tampoco crea nada. */
        $this->postJson(
            '/api/claude/upgrades',
            $this->cuerpo_de_creacion($e, ['target_client_api_id' => $api->id]),
            $this->headers()
        )->assertStatus(422);

        $this->assertSame($antes, ClientVersionUpgrade::count());
    }

    /**
     * 30) La creación real: upgrade con `created_by_admin_id = null` y `created_via = 'claude'`, con
     * los UpdateSeeder y UpdateCommand del camino confirmado.
     */
    public function test_creacion_real_deja_el_upgrade_marcado_como_creado_por_claude(): void
    {
        $e = $this->armar_escenario();

        /* Con espacios y en minúsculas: el freno compara con trim + mb_strtolower en las dos puntas. */
        $cuerpo = $this->postJson(
            '/api/claude/upgrades',
            $this->cuerpo_de_creacion($e, ['confirm_client_name' => '  cliente rioplatense  ', 'notes' => 'Prueba']),
            $this->headers()
        )->assertStatus(201)->json();

        $upgrade_id = (int) $cuerpo['upgrade']['id'];
        $this->assertGreaterThan(0, $upgrade_id);
        $this->assertSame('claude', $cuerpo['upgrade']['created_via']);
        $this->assertNull($cuerpo['upgrade']['created_by_admin_id']);

        /* La respuesta es el mismo payload que GET claude/upgrades/{id}. */
        $this->assertArrayHasKey('siguiente_accion', $cuerpo);
        $this->assertArrayHasKey('salud', $cuerpo);

        $fila = DB::table('client_version_upgrades')->where('id', $upgrade_id)->first();
        $this->assertSame('claude', $fila->created_via);
        $this->assertNull($fila->created_by_admin_id);
        $this->assertSame((int) $e['api_destino']->id, (int) $fila->target_client_api_id);

        $confirmadas = DB::table('client_version_upgrade_versions')
            ->where('client_version_upgrade_id', $upgrade_id)
            ->pluck('version_id')
            ->map('intval')
            ->sort()
            ->values()
            ->all();

        $esperadas = collect([$e['uno']->id, $e['dos']->id])->sort()->values()->all();
        $this->assertSame($esperadas, $confirmadas);
        $this->assertNotContains((int) $e['hotfix']->id, $confirmadas, 'El hotfix no confirmado entró igual.');

        $this->assertSame(2, UpdateSeeder::where('client_version_upgrade_id', $upgrade_id)->count());
        $this->assertSame(1, UpdateCommand::where('client_version_upgrade_id', $upgrade_id)->count());
    }

    /** 31) Hacia una versión que no está publicada → 422 y nada creado. */
    public function test_creacion_hacia_una_version_no_publicada_devuelve_422(): void
    {
        $e       = $this->armar_escenario();
        $borrador = $this->crear_version('4.0.3', false, 'draft');

        $antes = ClientVersionUpgrade::count();

        $this->postJson('/api/claude/upgrades', $this->cuerpo_de_creacion($e, [
            'to_version_id'         => $borrador->id,
            'confirmed_version_ids' => [$borrador->id],
            'confirm_version_count' => 1,
        ]), $this->headers())->assertStatus(422);

        $this->assertSame($antes, ClientVersionUpgrade::count());
    }

    /** 32) Con una versión fuera del rango calculado → 422 y nada creado. */
    public function test_creacion_con_una_version_fuera_del_rango_devuelve_422(): void
    {
        $e       = $this->armar_escenario();
        $vieja   = $this->crear_version('3.9.0');

        $antes = ClientVersionUpgrade::count();

        $this->postJson('/api/claude/upgrades', $this->cuerpo_de_creacion($e, [
            'confirmed_version_ids' => [$e['uno']->id, $vieja->id, $e['dos']->id],
            'confirm_version_count' => 3,
        ]), $this->headers())->assertStatus(422);

        $this->assertSame($antes, ClientVersionUpgrade::count());
    }

    /** `confirmed_version_ids` es obligatorio: no tiene fallback ni por la puerta de atrás. */
    public function test_creacion_sin_confirmed_version_ids_devuelve_422(): void
    {
        $e = $this->armar_escenario();

        $cuerpo_pedido = $this->cuerpo_de_creacion($e);
        unset($cuerpo_pedido['confirmed_version_ids']);

        $antes = ClientVersionUpgrade::count();

        $this->postJson('/api/claude/upgrades', $cuerpo_pedido, $this->headers())->assertStatus(422);

        $this->assertSame($antes, ClientVersionUpgrade::count());
    }

    /* ==========================================================================================
     | 33-36) El arranque del pre-cierre
     |========================================================================================= */

    /**
     * 33) 🔴 El start encola en la conexión `database` y NO corre el pipeline adentro del request.
     *
     * ⚠️ La comprobación de `$job->connection` es obligatoria: `QueueFake::connection()` devuelve
     * `$this` sin mirar el nombre, así que un `assertPushed` pelado pasaría igual con un dispatch
     * sin `onConnection` y este test no probaría nada.
     */
    public function test_el_start_encola_en_la_conexion_database_y_no_corre_el_pipeline(): void
    {
        Queue::fake();

        $e       = $this->armar_escenario();
        $upgrade = $this->crear_upgrade($e);

        $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/start', [
            'confirm_client_name' => 'Cliente Rioplatense',
        ], $this->headers())->assertStatus(202);

        $etapas = [];
        Queue::assertPushed(RunDeploymentJob::class, function ($job) use (&$etapas) {
            $etapas[] = $this->etapa_del_job($job);

            return $job->connection === 'database';
        });

        /* Guarda: si no se hubiera despachado nada, el closure de arriba nunca habría corrido. */
        $this->assertCount(1, $etapas, 'No se midió ningún job despachado.');
        $this->assertNull($etapas[0], 'El pre-cierre arranca desde el principio, sin etapa de reanudación.');

        /* El estado quedó en running y el pipeline NO corrió: si hubiera corrido inline, el fake no
           lo habría interceptado y el upgrade no estaría esperando al worker. */
        $this->assertSame('running', (string) $upgrade->refresh()->deployment_status);
        $this->assertNotNull($upgrade->deployment_started_at);
    }

    /** 34) El start responde 202 con el cuerpo del contrato y borra los logs del intento anterior. */
    public function test_el_start_responde_202_y_borra_los_logs_del_intento_anterior(): void
    {
        Queue::fake();

        $e       = $this->armar_escenario();
        $upgrade = $this->crear_upgrade($e, ['deployment_status' => 'failed']);

        DeploymentLog::create([
            'client_version_upgrade_id' => $upgrade->id,
            'step'                      => 'compile_spa',
            'level'                     => 'error',
            'line'                      => 'npm ERR! el build del intento anterior se cayó',
        ]);

        $this->assertSame(1, DB::table('deployment_logs')->where('client_version_upgrade_id', $upgrade->id)->count());

        $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/start', [
            'confirm_client_name' => 'Cliente Rioplatense',
        ], $this->headers())->assertStatus(202)->json();

        $this->assertTrue($cuerpo['encolado']);
        $this->assertSame('database', $cuerpo['conexion']);
        $this->assertSame('compile_spa', $cuerpo['desde_etapa']);
        $this->assertSame('GET claude/upgrades/' . $upgrade->id, $cuerpo['consultar_estado_en']);
        $this->assertArrayHasKey('horario_cliente', $cuerpo);

        $this->assertSame(
            0,
            DB::table('deployment_logs')->where('client_version_upgrade_id', $upgrade->id)->count(),
            'El start tiene que borrar los logs del intento anterior, igual que el panel.'
        );
    }

    /** 35) Con un deployment en curso → 422 y nada encolado. */
    public function test_el_start_con_un_deployment_en_curso_no_encola_nada(): void
    {
        Queue::fake();

        $e       = $this->armar_escenario();
        $upgrade = $this->crear_upgrade($e, ['deployment_status' => 'running']);

        $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/start', [
            'confirm_client_name' => 'Cliente Rioplatense',
        ], $this->headers())->assertStatus(422);

        Queue::assertNothingPushed();
    }

    /** El freno del nombre también corta el start: 422, nada encolado y sin revelar el nombre. */
    public function test_el_start_con_el_nombre_equivocado_no_encola_nada(): void
    {
        Queue::fake();

        $e       = $this->armar_escenario();
        $upgrade = $this->crear_upgrade($e);

        $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/start', [
            'confirm_client_name' => 'Cliente Equivocado',
        ], $this->headers())->assertStatus(422)->json();

        Queue::assertNothingPushed();

        $this->assertNull($upgrade->refresh()->deployment_status, 'El freno no puede dejar el estado tocado.');
        $this->assertStringNotContainsStringIgnoringCase(
            'Cliente Rioplatense',
            json_encode($cuerpo, JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 36) Si la API destino ES la API activa en producción, el start rechaza; con
     * `allow_deploy_to_active_api=true` explícito, pasa.
     */
    public function test_el_start_sobre_la_api_activa_exige_el_flag_explicito(): void
    {
        Queue::fake();

        $e       = $this->armar_escenario();
        $upgrade = $this->crear_upgrade($e, ['target_client_api_id' => $e['api_activa']->id]);

        $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/start', [
            'confirm_client_name' => 'Cliente Rioplatense',
        ], $this->headers())->assertStatus(422)->json();

        $this->assertSame((int) $e['api_activa']->id, $cuerpo['active_client_api_id']);
        Queue::assertNothingPushed();
        $this->assertNull($upgrade->refresh()->deployment_status);

        $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/start', [
            'confirm_client_name'        => 'Cliente Rioplatense',
            'allow_deploy_to_active_api' => true,
        ], $this->headers())->assertStatus(202);

        Queue::assertPushed(RunDeploymentJob::class, function ($job) {
            return $job->connection === 'database';
        });
    }

    /* ==========================================================================================
     | 37) Marcar los crons
     |========================================================================================= */

    /** 37) mark-crons setea `crons_supervisor_at`; con `unmark=true` lo vuelve a null. */
    public function test_mark_crons_marca_y_desmarca_el_paso(): void
    {
        $e       = $this->armar_escenario();
        $upgrade = $this->crear_upgrade($e, ['deployment_status' => 'paused']);

        $this->assertNull($upgrade->crons_supervisor_at);

        $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/mark-crons', [
            'confirm_client_name' => 'Cliente Rioplatense',
        ], $this->headers())->assertStatus(200);

        $this->assertNotNull($upgrade->refresh()->crons_supervisor_at);

        $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/mark-crons', [
            'confirm_client_name' => 'Cliente Rioplatense',
            'unmark'              => true,
        ], $this->headers())->assertStatus(200);

        $this->assertNull($upgrade->refresh()->crons_supervisor_at);
    }

    /** El freno del nombre corta el mark-crons: no se marca nada. */
    public function test_mark_crons_con_el_nombre_equivocado_no_marca_nada(): void
    {
        $e       = $this->armar_escenario();
        $upgrade = $this->crear_upgrade($e, ['deployment_status' => 'paused']);

        $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/mark-crons', [
            'confirm_client_name' => 'Cliente Equivocado',
        ], $this->headers())->assertStatus(422);

        $this->assertNull($upgrade->refresh()->crons_supervisor_at);
    }

    /* ==========================================================================================
     | 38-42) El post-cierre y su gate de horario
     |========================================================================================= */

    /**
     * Upgrade pausado con los crons ya marcados: el estado exacto desde el que se arranca el
     * post-cierre.
     *
     * @param array<string, mixed> $escenario Escenario base.
     *
     * @return ClientVersionUpgrade
     */
    private function upgrade_listo_para_post_cierre(array $escenario): ClientVersionUpgrade
    {
        return $this->crear_upgrade($escenario, [
            'deployment_status'   => 'paused',
            'crons_supervisor_at' => now(),
        ]);
    }

    /** 38) 🔴 Con el negocio ABIERTO no se arrancan las tareas post-cierre: 422 y nada encolado. */
    public function test_el_post_cierre_con_el_negocio_abierto_no_encola_nada(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->momento_base());

        $e = $this->armar_escenario();
        $this->cargar_dia($e['client'], 'todos', [['09:00', '18:00']]);

        $upgrade = $this->upgrade_listo_para_post_cierre($e);

        $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/start-post-closure', [
            'confirm_client_name' => 'Cliente Rioplatense',
        ], $this->headers())->assertStatus(422)->json();

        $this->assertSame('abierto', $cuerpo['estado_ahora']);
        $this->assertSame([['desde' => '09:00', 'hasta' => '18:00']], $cuerpo['rangos_de_hoy']);
        $this->assertNotNull($cuerpo['proximo_cierre']);

        Queue::assertNothingPushed();
        $this->assertSame('paused', (string) $upgrade->refresh()->deployment_status);
    }

    /**
     * 39) 🔴 Con el cliente SIN horarios cargados tampoco se arranca: `sin_configurar` NO es
     * `cerrado`. Es el test que impide que "no sé" se lea como "está cerrado" y se corran seeders
     * sobre un negocio lleno de gente.
     */
    public function test_el_post_cierre_sin_horarios_cargados_no_encola_nada(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->momento_base());

        $e       = $this->armar_escenario();
        $upgrade = $this->upgrade_listo_para_post_cierre($e);

        $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/start-post-closure', [
            'confirm_client_name' => 'Cliente Rioplatense',
        ], $this->headers())->assertStatus(422)->json();

        $this->assertSame('sin_configurar', $cuerpo['estado_ahora']);

        Queue::assertNothingPushed();
        $this->assertSame('paused', (string) $upgrade->refresh()->deployment_status);
    }

    /** 40) Con el negocio CERRADO: 202, encolado en `database` y desde la etapa `run_seeders`. */
    public function test_el_post_cierre_con_el_negocio_cerrado_encola_desde_run_seeders(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->momento_base());

        $e = $this->armar_escenario();
        /* Cierra a las 11: al mediodía el negocio ya está cerrado. */
        $this->cargar_dia($e['client'], 'todos', [['09:00', '11:00']]);

        $upgrade = $this->upgrade_listo_para_post_cierre($e);

        $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/start-post-closure', [
            'confirm_client_name' => 'Cliente Rioplatense',
        ], $this->headers())->assertStatus(202)->json();

        $this->assertSame('database', $cuerpo['conexion']);
        $this->assertSame('run_seeders', $cuerpo['desde_etapa']);
        $this->assertSame('cerrado', $cuerpo['horario_cliente']['estado_ahora']);
        $this->assertArrayNotHasKey('gate_de_horario_salteado', $cuerpo);

        $etapas = [];
        Queue::assertPushed(RunDeploymentJob::class, function ($job) use (&$etapas) {
            $etapas[] = $this->etapa_del_job($job);

            return $job->connection === 'database';
        });

        $this->assertCount(1, $etapas, 'No se midió ningún job despachado.');
        $this->assertSame('run_seeders', $etapas[0]);
        $this->assertSame('running', (string) $upgrade->refresh()->deployment_status);
    }

    /**
     * 41) El gate se saltea con `force=true` + `force_reason`; sin motivo (o con uno demasiado
     * corto) rechaza y no encola nada.
     */
    public function test_el_gate_de_horario_se_saltea_solo_con_force_y_un_motivo(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->momento_base());

        $e = $this->armar_escenario();
        $this->cargar_dia($e['client'], 'todos', [['09:00', '18:00']]);

        $upgrade = $this->upgrade_listo_para_post_cierre($e);

        /* force sin motivo: sigue siendo 422 y no encola nada. */
        $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/start-post-closure', [
            'confirm_client_name' => 'Cliente Rioplatense',
            'force'               => true,
        ], $this->headers())->assertStatus(422);

        Queue::assertNothingPushed();
        $this->assertSame('paused', (string) $upgrade->refresh()->deployment_status);

        /* Con motivo válido: pasa, y la respuesta declara que el gate se salteó. */
        $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/start-post-closure', [
            'confirm_client_name' => 'Cliente Rioplatense',
            'force'               => true,
            'force_reason'        => 'Lucas confirmó por teléfono que el local ya cerró.',
        ], $this->headers())->assertStatus(202)->json();

        $this->assertSame('abierto', $cuerpo['gate_de_horario_salteado']['estado_ahora']);
        $this->assertTrue($cuerpo['gate_de_horario_salteado']['registrado']);

        Queue::assertPushed(RunDeploymentJob::class, function ($job) {
            return $job->connection === 'database';
        });
    }

    /* ==========================================================================================
     | 41.b) 🔴 El gate pregunta si la JORNADA DE HOY TERMINÓ, no si está cerrado en este instante
     |
     | `estado_en()` es un chequeo de INSTANTE: devuelve `cerrado` a las 14:00 de un negocio
     | 8–13 / 16–21 (que reabre a las 16) y a las 08:00 de uno 9–18 (que abre en una hora). En los
     | dos casos el post-cierre correría seeders y comandos, y el negocio abriría con eso a medio
     | camino. Estos cinco tests fijan la regla correcta.
     |========================================================================================= */

    /**
     * Momento del día del 25/8/2026 (martes), en el timezone de la app.
     *
     * @param string $hora Hora en formato 'HH:MM'.
     *
     * @return Carbon
     */
    private function momento_del_dia(string $hora): Carbon
    {
        return Carbon::parse('2026-08-25 ' . $hora . ':00', config('app.timezone'));
    }

    /** `day_key` del día de la fecha de prueba, sin hardcodearlo. */
    private function day_key_de_hoy(): string
    {
        return ClientScheduleDay::DAY_KEYS_BY_DOW[$this->momento_del_dia('12:00')->dayOfWeek];
    }

    /**
     * 43) 🔴 EL HUECO DEL MEDIODÍA. Cliente 08:00–13:00 y 16:00–21:00, a las 14:00: el negocio
     * está cerrado en este instante pero REABRE A LAS 16. Rechaza.
     *
     * Y con `force` válido sí pasa, dejando constancia: el bloque `gate_de_horario_salteado` tiene
     * que aparecer también en este caso, que es justo el que el gate viejo no veía.
     */
    public function test_el_post_cierre_en_el_hueco_entre_turnos_no_encola_nada(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->momento_del_dia('14:00'));

        $e = $this->armar_escenario();
        $this->cargar_dia($e['client'], 'todos', [['08:00', '13:00'], ['16:00', '21:00']]);

        $upgrade = $this->upgrade_listo_para_post_cierre($e);

        $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/start-post-closure', [
            'confirm_client_name' => 'Cliente Rioplatense',
        ], $this->headers())->assertStatus(422)->json();

        $this->assertSame('cerrado', $cuerpo['estado_ahora'], 'A las 14:00 el instante es "cerrado"…');
        $this->assertFalse($cuerpo['jornada_de_hoy_termino'], '…pero la jornada NO terminó.');
        $this->assertSame('16:00', $cuerpo['reabre_a_las']);
        $this->assertSame('21:00', $cuerpo['cierre_del_dia']);
        $this->assertStringContainsString('HUECO ENTRE TURNOS', $cuerpo['error']);

        Queue::assertNothingPushed();
        $this->assertSame('paused', (string) $upgrade->refresh()->deployment_status);

        /* Con force + motivo sí pasa, y queda declarado que se salteó el gate. */
        $forzado = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/start-post-closure', [
            'confirm_client_name' => 'Cliente Rioplatense',
            'force'               => true,
            'force_reason'        => 'Lucas avisó que hoy no reabren a la tarde.',
        ], $this->headers())->assertStatus(202)->json();

        $this->assertSame('hueco_entre_turnos', $forzado['gate_de_horario_salteado']['motivo_del_gate']);
        $this->assertTrue($forzado['gate_de_horario_salteado']['registrado']);

        Queue::assertPushed(RunDeploymentJob::class, function ($job) {
            return $job->connection === 'database';
        });
    }

    /**
     * 44) 🔴 ANTES DE ABRIR, que es el caso más común. Cliente 09:00–18:00 a las 08:00: cerrado en
     * este instante, pero abre en una hora.
     */
    public function test_el_post_cierre_antes_de_que_el_negocio_abra_no_encola_nada(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->momento_del_dia('08:00'));

        $e = $this->armar_escenario();
        $this->cargar_dia($e['client'], 'todos', [['09:00', '18:00']]);

        $upgrade = $this->upgrade_listo_para_post_cierre($e);

        $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/start-post-closure', [
            'confirm_client_name' => 'Cliente Rioplatense',
        ], $this->headers())->assertStatus(422)->json();

        $this->assertSame('cerrado', $cuerpo['estado_ahora']);
        $this->assertFalse($cuerpo['jornada_de_hoy_termino']);
        $this->assertSame('09:00', $cuerpo['primera_apertura_de_hoy']);
        $this->assertSame('09:00', $cuerpo['reabre_a_las']);
        $this->assertStringContainsString('TODAVÍA NO ABRIÓ', $cuerpo['error']);

        Queue::assertNothingPushed();
        $this->assertSame('paused', (string) $upgrade->refresh()->deployment_status);
    }

    /** 45) Después del cierre del día (22:00 con 09:00–18:00): la jornada terminó, pasa. */
    public function test_el_post_cierre_despues_del_cierre_del_dia_si_encola(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->momento_del_dia('22:00'));

        $e = $this->armar_escenario();
        $this->cargar_dia($e['client'], 'todos', [['09:00', '18:00']]);

        $upgrade = $this->upgrade_listo_para_post_cierre($e);

        $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/start-post-closure', [
            'confirm_client_name' => 'Cliente Rioplatense',
        ], $this->headers())->assertStatus(202)->json();

        $this->assertSame('run_seeders', $cuerpo['desde_etapa']);
        $this->assertArrayNotHasKey('gate_de_horario_salteado', $cuerpo);

        Queue::assertPushed(RunDeploymentJob::class, function ($job) {
            return $job->connection === 'database';
        });
        $this->assertSame('running', (string) $upgrade->refresh()->deployment_status);
    }

    /**
     * 46) Día cerrado ENTERO (fila propia del día de hoy con cero rangos): pasa a cualquier hora.
     *
     * Es la diferencia con los dos casos de arriba: acá no hay ninguna jornada que esperar, porque
     * hoy el negocio no abre nunca. El próximo cierre cae otro día.
     */
    public function test_el_post_cierre_con_el_dia_cerrado_entero_encola_a_cualquier_hora(): void
    {
        Carbon::setTestNow($this->momento_del_dia('12:00'));

        $e = $this->armar_escenario();
        $this->cargar_dia($e['client'], 'todos', [['09:00', '18:00']]);
        /* La fila del día de hoy, con CERO rangos: así se dice "hoy cerramos". */
        $this->cargar_dia($e['client'], $this->day_key_de_hoy(), []);

        $upgrade = $this->upgrade_listo_para_post_cierre($e);

        foreach (['10:00', '14:00', '22:00'] as $hora) {
            Queue::fake();
            Carbon::setTestNow($this->momento_del_dia($hora));

            $upgrade->update(['deployment_status' => 'paused', 'crons_supervisor_at' => now()]);

            $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/start-post-closure', [
                'confirm_client_name' => 'Cliente Rioplatense',
            ], $this->headers())->assertStatus(202)->json();

            $this->assertSame('run_seeders', $cuerpo['desde_etapa'], 'Falló a las ' . $hora . '.');
            $this->assertSame('running', (string) $upgrade->refresh()->deployment_status);
        }
    }

    /** 47) Sin ninguna fila cargada sigue siendo 422 a cualquier hora: "no sé" no es "cerrado". */
    public function test_el_post_cierre_sin_ninguna_fila_rechaza_a_cualquier_hora(): void
    {
        Carbon::setTestNow($this->momento_del_dia('12:00'));

        $e       = $this->armar_escenario();
        $upgrade = $this->upgrade_listo_para_post_cierre($e);

        foreach (['08:00', '14:00', '22:00'] as $hora) {
            Queue::fake();
            Carbon::setTestNow($this->momento_del_dia($hora));

            $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/start-post-closure', [
                'confirm_client_name' => 'Cliente Rioplatense',
            ], $this->headers())->assertStatus(422)->json();

            $this->assertSame('sin_configurar', $cuerpo['estado_ahora'], 'Falló a las ' . $hora . '.');

            Queue::assertNothingPushed();
            $this->assertSame('paused', (string) $upgrade->refresh()->deployment_status);
        }
    }

    /** 42) Sin `crons_supervisor_at` no arranca, aunque el negocio esté cerrado. */
    public function test_el_post_cierre_sin_los_crons_marcados_no_encola_nada(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->momento_base());

        $e = $this->armar_escenario();
        $this->cargar_dia($e['client'], 'todos', [['09:00', '11:00']]);

        $upgrade = $this->crear_upgrade($e, ['deployment_status' => 'paused']);

        $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/start-post-closure', [
            'confirm_client_name' => 'Cliente Rioplatense',
        ], $this->headers())->assertStatus(422);

        Queue::assertNothingPushed();
        $this->assertSame('paused', (string) $upgrade->refresh()->deployment_status);
    }

    /** Y desde un estado que no es `paused` tampoco, aunque esté todo lo demás en orden. */
    public function test_el_post_cierre_desde_un_estado_que_no_es_paused_no_encola_nada(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->momento_base());

        $e = $this->armar_escenario();
        $this->cargar_dia($e['client'], 'todos', [['09:00', '11:00']]);

        $upgrade = $this->crear_upgrade($e, [
            'deployment_status'   => 'running',
            'crons_supervisor_at' => now(),
        ]);

        $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/start-post-closure', [
            'confirm_client_name' => 'Cliente Rioplatense',
        ], $this->headers())->assertStatus(422);

        Queue::assertNothingPushed();
    }

    /* ==========================================================================================
     | 43) La etapa final de configuración
     |========================================================================================= */

    /** 43) Desde `paused_post_tasks` encola la etapa final; desde `running` rechaza. */
    public function test_configure_system_encola_la_etapa_final_solo_desde_los_estados_validos(): void
    {
        Queue::fake();

        $e       = $this->armar_escenario();
        $upgrade = $this->crear_upgrade($e, ['deployment_status' => 'paused_post_tasks']);

        $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/configure-system', [
            'confirm_client_name' => 'Cliente Rioplatense',
        ], $this->headers())->assertStatus(202)->json();

        $this->assertSame('database', $cuerpo['conexion']);
        $this->assertSame('update_default_version', $cuerpo['desde_etapa']);

        $etapas = [];
        Queue::assertPushed(RunDeploymentJob::class, function ($job) use (&$etapas) {
            $etapas[] = $this->etapa_del_job($job);

            return $job->connection === 'database';
        });

        $this->assertCount(1, $etapas, 'No se midió ningún job despachado.');
        $this->assertSame('update_default_version', $etapas[0]);
        $this->assertSame('running', (string) $upgrade->refresh()->deployment_status);

        /* Ahora quedó en running: un segundo intento tiene que rechazar y no encolar nada más. */
        $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/configure-system', [
            'confirm_client_name' => 'Cliente Rioplatense',
        ], $this->headers())->assertStatus(422);

        Queue::assertPushed(RunDeploymentJob::class, 1);
    }

    /** Desde `failed` sí se reintenta la etapa final: es la regla del panel y se espeja tal cual. */
    public function test_configure_system_acepta_reintentar_desde_failed(): void
    {
        Queue::fake();

        $e       = $this->armar_escenario();
        $upgrade = $this->crear_upgrade($e, ['deployment_status' => 'failed']);

        $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/configure-system', [
            'confirm_client_name' => 'Cliente Rioplatense',
        ], $this->headers())->assertStatus(202);

        Queue::assertPushed(RunDeploymentJob::class, function ($job) {
            return $job->connection === 'database';
        });
    }
}
