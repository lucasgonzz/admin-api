<?php

namespace Tests\Feature;

use App\Console\Commands\VencerDeploymentsColgados;
use App\Http\Controllers\Api\ClaudeClientOpsController;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Los dos endpoints que este test cubre son los que se usan CUANDO ALGO YA SALIÓ MAL:
 * `POST claude/upgrades/{id}/deploy/retry-commands` y `.../deploy/expire-stuck`.
 *
 * Lo que se protege, en orden de importancia:
 *
 *  1. 🔴 Que `retry-commands` lleve el GATE DE HORARIO, que el panel NO tiene. `run_commands` corre
 *     comandos de artisan sobre el sistema EN USO del cliente: es la segunda mitad exacta del
 *     post-cierre. Si el post-cierre no arranca con el negocio abierto, un reintento de esos mismos
 *     comandos tampoco. Es la única divergencia deliberada con el botón del panel, y si alguien la
 *     "corrige" por parecerle de más, estos dos tests se ponen en rojo.
 *  2. 🔴 Que `expire-stuck` exija el umbral DESTRUCTIVO (el de `deployments:vencer-colgados`, 45 min
 *     por defecto) y no el de REPORTE (`ClaudeClientOpsController::STALE_MINUTOS`, 15). Vencer marca
 *     `failed`, y `failed` es un estado del que las dos puertas dejan arrancar de nuevo: si el
 *     pipeline seguía vivo quedarían dos `DeploymentService` por SSH sobre el mismo hosting.
 *  3. 🔴 Que el claim atómico siga cerrando la carrera con el worker: si el tramo cambió entre la
 *     medición y la escritura, el endpoint devuelve 409 y NO toca nada. Sin eso, este endpoint
 *     mataría un tramo recién nacido.
 *  4. Que el `dispatch()` del reintento vaya a la conexión `database`. ⚠️ El
 *     `return $job->connection === 'database'` NO es decorativo: `QueueFake::connection()` devuelve
 *     `$this` sin mirar el nombre, así que un `assertPushed` pelado pasaría igual con un dispatch
 *     sin `onConnection` (documentado en `tests/Feature/DemoSetupFueraDelRequestTest.php:148-152`).
 *  5. Que todo freno que rechaza devuelva 422 (o 409) sin escribir absolutamente nada.
 */
class ReintentoYDestrabeDeDeploymentPorClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-claude-reintento';

    /** Nombre del cliente del escenario: es lo que confirma `confirm_client_name`. */
    const NOMBRE = 'Ferretería Rioplatense';

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
     * Deja el reloj como estaba: casi todos los tests lo congelan.
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
     * Cuerpo de la respuesta como texto, con los acentos SIN escapar.
     *
     * 🔴 `getContent()` devuelve el JSON crudo, donde "Ferretería" viaja escapado como
     * "Ferretería". Un `assertStringNotContainsString('Ferretería', $r->getContent())` sobre eso
     * pasa SIEMPRE —incluso si el nombre estuviera en la respuesta—, o sea que el test que verifica
     * que el freno no revela el nombre no verificaría NADA. Copiado de
     * `ActualizacionDelEcommercePorClaudeTest::cuerpo()`.
     *
     * @param \Illuminate\Testing\TestResponse $respuesta Respuesta a leer.
     *
     * @return string
     */
    private function cuerpo($respuesta): string
    {
        return (string) json_encode($respuesta->json(), JSON_UNESCAPED_UNICODE);
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

    /**
     * Versión publicada del catálogo.
     *
     * @param string $codigo Número de versión.
     *
     * @return Version
     */
    private function crear_version(string $codigo): Version
    {
        $version               = new Version();
        $version->version      = $codigo;
        $version->title        = 'Versión ' . $codigo;
        $version->status       = 'published';
        $version->is_hotfix    = false;
        $version->published_at = now();
        $version->save();

        return $version;
    }

    /**
     * Escenario completo: cliente con dos APIs, versión origen y destino, un seeder y un comando.
     *
     * @return array<string, mixed>
     */
    private function armar_escenario(): array
    {
        $from = $this->crear_version('5.0.0');
        $to   = $this->crear_version('5.0.1');

        $client                     = new Client();
        $client->name               = self::NOMBRE;
        $client->company_name       = 'Empresa ' . self::NOMBRE;
        $client->slug               = 'ferreteria-' . Str::random(8);
        $client->api_url            = 'https://ejemplo.test';
        $client->api_key            = 'clave-api';
        $client->inbound_api_key    = 'clave-inbound';
        $client->is_active          = true;
        $client->current_version_id = $from->id;
        $client->save();

        $api_activa  = $this->crear_api($client, 'https://api-activa.ejemplo.test');
        $api_destino = $this->crear_api($client, 'https://api-destino.ejemplo.test');

        $client->active_client_api_id = $api_activa->id;
        $client->save();

        $version_seeder                  = new VersionSeeder();
        $version_seeder->version_id      = $to->id;
        $version_seeder->seeder_class    = 'Database\\Seeders\\Falso' . Str::random(6) . 'Seeder';
        $version_seeder->execution_order = 1;
        $version_seeder->save();

        $version_command                  = new VersionCommand();
        $version_command->version_id      = $to->id;
        $version_command->command         = 'comando:falso-' . Str::random(6);
        $version_command->execution_order = 1;
        $version_command->save();

        return compact('from', 'to', 'client', 'api_activa', 'api_destino', 'version_seeder', 'version_command');
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
     * @return void
     */
    private function cargar_dia(Client $client, string $day_key, array $rangos = []): void
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
    }

    /**
     * Upgrade del escenario.
     *
     * @param array<string, mixed> $escenario Escenario base.
     * @param array<string, mixed> $atributos Atributos a pisar.
     *
     * @return ClientVersionUpgrade
     */
    private function crear_upgrade(array $escenario, array $atributos = []): ClientVersionUpgrade
    {
        return ClientVersionUpgrade::create(array_merge([
            'client_id'            => $escenario['client']->id,
            'from_version_id'      => $escenario['from']->id,
            'to_version_id'        => $escenario['to']->id,
            'status'               => 'pendiente',
            'scheduled_date'       => now()->toDateString(),
            'target_client_api_id' => $escenario['api_destino']->id,
        ], $atributos));
    }

    /**
     * Upgrade en el estado exacto desde el que se reintentan comandos: pausado después de las tareas
     * post-cierre, con el seeder ya exitoso y el comando fallado.
     *
     * @param array<string, mixed> $escenario     Escenario base.
     * @param string               $estado_seeder Estado del UpdateSeeder.
     * @param bool                 $seeder_skipped Si el seeder va marcado como saltado.
     *
     * @return ClientVersionUpgrade
     */
    private function upgrade_listo_para_reintentar(
        array $escenario,
        string $estado_seeder = 'exitoso',
        bool $seeder_skipped = false
    ): ClientVersionUpgrade {
        $upgrade = $this->crear_upgrade($escenario, [
            'deployment_status'     => 'paused_post_tasks',
            'crons_supervisor_at'   => now(),
            'seeders_ejecutados_at' => now(),
        ]);

        UpdateSeeder::create([
            'client_version_upgrade_id' => $upgrade->id,
            'version_seeder_id'         => $escenario['version_seeder']->id,
            'status'                    => $estado_seeder,
            'skipped'                   => $seeder_skipped,
        ]);

        UpdateCommand::create([
            'client_version_upgrade_id' => $upgrade->id,
            'version_command_id'        => $escenario['version_command']->id,
            'status'                    => 'fallido',
        ]);

        return $upgrade;
    }

    /**
     * Etapa de reanudación con la que se despachó un RunDeploymentJob.
     *
     * Se lee por reflexión porque la propiedad es privada: el contrato del job no se cambia para que
     * un test pueda mirarlo, y la etapa es justo lo que distingue el reintento de comandos del resto.
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
     * Cantidad de líneas de vencimiento escritas para un upgrade.
     *
     * @param ClientVersionUpgrade $upgrade Upgrade a mirar.
     *
     * @return int
     */
    private function lineas_de_vencimiento(ClientVersionUpgrade $upgrade): int
    {
        return DeploymentLog::where('client_version_upgrade_id', $upgrade->id)
            ->where('step', VencerDeploymentsColgados::STEP_VENCIMIENTO)
            ->count();
    }

    /* ==========================================================================================
     | La puerta
     |========================================================================================= */

    /** Sin el header X-Claude-Task-Key, las dos rutas nuevas devuelven 401. */
    public function test_sin_clave_las_dos_rutas_devuelven_401(): void
    {
        $rutas = [
            '/api/claude/upgrades/1/deploy/retry-commands',
            '/api/claude/upgrades/1/deploy/expire-stuck',
        ];

        foreach ($rutas as $ruta) {
            $this->postJson($ruta, [])->assertStatus(401);
        }
    }

    /* ==========================================================================================
     | retry-commands
     |========================================================================================= */

    /** Con el negocio cerrado y comandos fallados: 202, etapa `run_commands`, conexión `database`. */
    public function test_retry_commands_encola_run_commands_en_la_conexion_database(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->momento_base());

        $e = $this->armar_escenario();
        /* Cierra a las 11: al mediodía la jornada de hoy ya terminó. */
        $this->cargar_dia($e['client'], 'todos', [['09:00', '11:00']]);

        $upgrade = $this->upgrade_listo_para_reintentar($e);

        $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/retry-commands', [
            'confirm_client_name' => self::NOMBRE,
        ], $this->headers())->assertStatus(202)->json();

        $this->assertSame('database', $cuerpo['conexion']);
        $this->assertSame('run_commands', $cuerpo['desde_etapa']);
        $this->assertSame('cerrado', $cuerpo['horario_cliente']['estado_ahora']);
        $this->assertCount(1, $cuerpo['comandos_a_reintentar']);
        $this->assertSame('fallido', $cuerpo['comandos_a_reintentar'][0]['status']);

        /* ⚠️ El endpoint NO borra los logs, a diferencia de `start`, y lo declara. */
        $this->assertStringContainsString('NO borra los logs', $cuerpo['nota_logs']);

        $etapas = [];
        Queue::assertPushed(RunDeploymentJob::class, function ($job) use (&$etapas) {
            $etapas[] = $this->etapa_del_job($job);

            return $job->connection === 'database';
        });

        $this->assertCount(1, $etapas, 'No se midió ningún job despachado.');
        $this->assertSame('run_commands', $etapas[0]);
        $this->assertSame('running', (string) $upgrade->refresh()->deployment_status);
        $this->assertNotNull($upgrade->deployment_running_since, 'El sello del tramo acompaña siempre a `running`.');
    }

    /**
     * `paused` y `paused_post_tasks` SÍ pasan: el espejo del panel rechaza sólo `running`. Es la
     * mitad del espejo que un "por las dudas" rompería, y por eso está medida.
     */
    public function test_retry_commands_acepta_paused_ademas_de_paused_post_tasks(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->momento_base());

        $e = $this->armar_escenario();
        $this->cargar_dia($e['client'], 'todos', [['09:00', '11:00']]);

        $upgrade = $this->upgrade_listo_para_reintentar($e);
        $upgrade->update(['deployment_status' => 'paused']);

        $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/retry-commands', [
            'confirm_client_name' => self::NOMBRE,
        ], $this->headers())->assertStatus(202);

        Queue::assertPushed(RunDeploymentJob::class, function ($job) {
            return $job->connection === 'database';
        });
    }

    /** Con un deployment `running` no se reintenta nada: 422 y cero jobs. */
    public function test_retry_commands_con_un_deployment_running_no_encola_nada(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->momento_base());

        $e = $this->armar_escenario();
        $this->cargar_dia($e['client'], 'todos', [['09:00', '11:00']]);

        $upgrade = $this->upgrade_listo_para_reintentar($e);
        $upgrade->update(['deployment_status' => 'running', 'deployment_running_since' => now()]);

        $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/retry-commands', [
            'confirm_client_name' => self::NOMBRE,
        ], $this->headers())->assertStatus(422)->json();

        $this->assertSame('running', $cuerpo['deployment_status']);

        Queue::assertNothingPushed();
    }

    /**
     * Con un seeder incompleto no se reintentan los comandos; con ese mismo seeder marcado `skipped`
     * sí, porque un seeder saltado cuenta como completo. Las dos mitades del espejo, en un test.
     */
    public function test_retry_commands_con_seeders_incompletos_no_encola_nada(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->momento_base());

        $e = $this->armar_escenario();
        $this->cargar_dia($e['client'], 'todos', [['09:00', '11:00']]);

        $upgrade = $this->upgrade_listo_para_reintentar($e, 'pendiente', false);

        $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/retry-commands', [
            'confirm_client_name' => self::NOMBRE,
        ], $this->headers())->assertStatus(422)->json();

        $this->assertSame(1, $cuerpo['seeders_incompletos']);
        Queue::assertNothingPushed();
        $this->assertSame('paused_post_tasks', (string) $upgrade->refresh()->deployment_status);

        /* Mismo seeder pendiente, pero saltado: cuenta como completo y el reintento pasa. */
        UpdateSeeder::where('client_version_upgrade_id', $upgrade->id)->update(['skipped' => true]);

        $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/retry-commands', [
            'confirm_client_name' => self::NOMBRE,
        ], $this->headers())->assertStatus(202);

        Queue::assertPushed(RunDeploymentJob::class, function ($job) {
            return $job->connection === 'database';
        });
    }

    /** Sin ningún comando retriable (todos exitosos, manuales o saltados): 422 y cero jobs. */
    public function test_retry_commands_sin_comandos_retriables_no_encola_nada(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->momento_base());

        $e = $this->armar_escenario();
        $this->cargar_dia($e['client'], 'todos', [['09:00', '11:00']]);

        $upgrade = $this->upgrade_listo_para_reintentar($e);
        UpdateCommand::where('client_version_upgrade_id', $upgrade->id)->update(['status' => 'exitoso']);

        $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/retry-commands', [
            'confirm_client_name' => self::NOMBRE,
        ], $this->headers())->assertStatus(422);

        Queue::assertNothingPushed();

        /* Y el comando marcado para ejecución manual tampoco cuenta como retriable. */
        UpdateCommand::where('client_version_upgrade_id', $upgrade->id)->update(['status' => 'fallido']);
        VersionCommand::where('id', $e['version_command']->id)->update(['run_manually' => true]);

        $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/retry-commands', [
            'confirm_client_name' => self::NOMBRE,
        ], $this->headers())->assertStatus(422);

        Queue::assertNothingPushed();
    }

    /**
     * 🔴 EL FRENO QUE EL PANEL NO TIENE. Con el negocio ABIERTO no se reintentan los comandos:
     * `run_commands` corre sobre el sistema en uso del cliente.
     */
    public function test_retry_commands_con_el_negocio_abierto_no_encola_nada(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->momento_base());

        $e = $this->armar_escenario();
        $this->cargar_dia($e['client'], 'todos', [['09:00', '18:00']]);

        $upgrade = $this->upgrade_listo_para_reintentar($e);

        $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/retry-commands', [
            'confirm_client_name' => self::NOMBRE,
        ], $this->headers())->assertStatus(422)->json();

        $this->assertSame('abierto', $cuerpo['estado_ahora']);

        Queue::assertNothingPushed();
        $this->assertSame('paused_post_tasks', (string) $upgrade->refresh()->deployment_status);
    }

    /** 🔴 Sin horarios cargados tampoco: `sin_configurar` no es `cerrado`. "No sé" no es "está cerrado". */
    public function test_retry_commands_sin_horarios_cargados_no_encola_nada(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->momento_base());

        $e       = $this->armar_escenario();
        $upgrade = $this->upgrade_listo_para_reintentar($e);

        $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/retry-commands', [
            'confirm_client_name' => self::NOMBRE,
        ], $this->headers())->assertStatus(422)->json();

        $this->assertSame('sin_configurar', $cuerpo['estado_ahora']);

        Queue::assertNothingPushed();
    }

    /** El gate se saltea con `force=true` + un motivo de largo suficiente, y queda declarado. */
    public function test_retry_commands_se_saltea_el_gate_solo_con_force_y_un_motivo(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->momento_base());

        $e = $this->armar_escenario();
        $this->cargar_dia($e['client'], 'todos', [['09:00', '18:00']]);

        $upgrade = $this->upgrade_listo_para_reintentar($e);

        /* force sin motivo: sigue siendo 422 y no encola nada. */
        $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/retry-commands', [
            'confirm_client_name' => self::NOMBRE,
            'force'               => true,
        ], $this->headers())->assertStatus(422);

        Queue::assertNothingPushed();
        $this->assertSame('paused_post_tasks', (string) $upgrade->refresh()->deployment_status);

        $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/retry-commands', [
            'confirm_client_name' => self::NOMBRE,
            'force'               => true,
            'force_reason'        => 'Lucas confirmó por teléfono que el local ya cerró.',
        ], $this->headers())->assertStatus(202)->json();

        $this->assertSame('abierto', $cuerpo['gate_de_horario_salteado']['estado_ahora']);
        $this->assertTrue($cuerpo['gate_de_horario_salteado']['registrado']);

        Queue::assertPushed(RunDeploymentJob::class, function ($job) {
            return $job->connection === 'database';
        });
    }

    /** Con el nombre equivocado no se reintenta nada, y el error no revela el nombre correcto. */
    public function test_retry_commands_con_el_nombre_equivocado_no_encola_nada_ni_revela_el_nombre(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->momento_base());

        $e = $this->armar_escenario();
        $this->cargar_dia($e['client'], 'todos', [['09:00', '11:00']]);

        $upgrade = $this->upgrade_listo_para_reintentar($e);

        $respuesta = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/retry-commands', [
            'confirm_client_name' => 'Otro Negocio',
        ], $this->headers())->assertStatus(422);

        $this->assertStringNotContainsString(self::NOMBRE, $this->cuerpo($respuesta));

        Queue::assertNothingPushed();
        $this->assertSame('paused_post_tasks', (string) $upgrade->refresh()->deployment_status);
    }

    /* ==========================================================================================
     | expire-stuck
     |========================================================================================= */

    /**
     * 🔴 EL TEST DE LOS DOS UMBRALES. Un deployment sin actividad hace 20 minutos ya figura
     * `deployment_stale` (el umbral de REPORTE son 15), pero está por debajo del umbral de
     * VENCIMIENTO: se rechaza con 422 y no se toca nada. El 422 devuelve los dos números.
     */
    public function test_expire_stuck_rechaza_un_deployment_que_todavia_no_esta_vencido(): void
    {
        Carbon::setTestNow($this->momento_base());

        $umbral  = VencerDeploymentsColgados::timeout_minutos_efectivo();
        $minutos = ClaudeClientOpsController::STALE_MINUTOS + 5;

        $this->assertLessThan(
            $umbral,
            $minutos,
            'El escenario tiene que caer ENTRE los dos umbrales; si no, el test no mide lo que dice medir.'
        );

        $e       = $this->armar_escenario();
        $upgrade = $this->crear_upgrade($e, [
            'deployment_status'        => 'running',
            'deployment_started_at'    => $this->momento_base()->copy()->subMinutes($minutos),
            'deployment_running_since' => $this->momento_base()->copy()->subMinutes($minutos),
        ]);

        $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/expire-stuck', [
            'confirm_client_name' => self::NOMBRE,
        ], $this->headers())->assertStatus(422)->json();

        $this->assertTrue($cuerpo['deployment_stale'], 'A los 20 minutos el umbral de REPORTE ya avisa.');
        $this->assertSame($minutos, $cuerpo['minutos_sin_actividad']);
        $this->assertSame(ClaudeClientOpsController::STALE_MINUTOS, $cuerpo['stale_minutos_reporte']);
        $this->assertSame($umbral, $cuerpo['vencimiento_minutos']);

        /* No se tocó NADA. */
        $this->assertSame('running', (string) $upgrade->refresh()->deployment_status);
        $this->assertSame(0, $this->lineas_de_vencimiento($upgrade));
    }

    /** Pasado el umbral destructivo: 200, `failed`, y el motivo escrito como línea de log. */
    public function test_expire_stuck_vence_un_deployment_pasado_el_umbral_y_escribe_el_motivo(): void
    {
        Carbon::setTestNow($this->momento_base());

        $umbral = VencerDeploymentsColgados::timeout_minutos_efectivo();

        $e       = $this->armar_escenario();
        $upgrade = $this->crear_upgrade($e, [
            'deployment_status'        => 'running',
            'deployment_started_at'    => $this->momento_base()->copy()->subMinutes($umbral + 30),
            'deployment_running_since' => $this->momento_base()->copy()->subMinutes($umbral + 30),
        ]);

        $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/expire-stuck', [
            'confirm_client_name' => self::NOMBRE,
        ], $this->headers())->assertStatus(200)->json();

        $this->assertTrue($cuerpo['vencido']);
        $this->assertSame('failed', $cuerpo['deployment_status']);
        $this->assertSame($umbral + 30, $cuerpo['minutos_sin_actividad']);
        $this->assertSame($umbral, $cuerpo['timeout_minutos']);
        $this->assertStringContainsString('estado DESCONOCIDO', $cuerpo['advertencia']);

        $this->assertSame('failed', (string) $upgrade->refresh()->deployment_status);

        $linea = DeploymentLog::where('client_version_upgrade_id', $upgrade->id)
            ->where('step', VencerDeploymentsColgados::STEP_VENCIMIENTO)
            ->first();

        $this->assertNotNull($linea, 'El motivo va a deployment_logs, igual que en la corrida del scheduler.');
        $this->assertSame('error', (string) $linea->level);
        $this->assertStringContainsString('no reportó actividad', (string) $linea->line);
        $this->assertStringContainsString('estado quedó el servidor del cliente', (string) $linea->line);
    }

    /** Un upgrade sin ancla no se puede medir: 422, y sólo se sale con `force` + motivo. */
    public function test_expire_stuck_sobre_un_upgrade_sin_ancla_exige_force(): void
    {
        Carbon::setTestNow($this->momento_base());

        $e       = $this->armar_escenario();
        $upgrade = $this->crear_upgrade($e, [
            'deployment_status'        => 'running',
            'deployment_started_at'    => $this->momento_base()->copy()->subDays(3),
            'deployment_running_since' => null,
        ]);

        $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/expire-stuck', [
            'confirm_client_name' => self::NOMBRE,
        ], $this->headers())->assertStatus(422);

        $this->assertSame('running', (string) $upgrade->refresh()->deployment_status);
        $this->assertSame(0, $this->lineas_de_vencimiento($upgrade));

        /* Con force pero sin motivo suficiente, sigue siendo 422. */
        $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/expire-stuck', [
            'confirm_client_name' => self::NOMBRE,
            'force'               => true,
            'force_reason'        => 'corto',
        ], $this->headers())->assertStatus(422);

        $this->assertSame('running', (string) $upgrade->refresh()->deployment_status);

        $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/expire-stuck', [
            'confirm_client_name' => self::NOMBRE,
            'force'               => true,
            'force_reason'        => 'El worker se murió y lo verifiqué a mano en el servidor.',
        ], $this->headers())->assertStatus(200)->json();

        $this->assertTrue($cuerpo['vencido']);
        $this->assertTrue($cuerpo['vencimiento_forzado']['registrado']);
        $this->assertSame('failed', (string) $upgrade->refresh()->deployment_status);
        $this->assertSame(1, $this->lineas_de_vencimiento($upgrade));
    }

    /** Con el nombre equivocado no se vence nada, y el error no revela el nombre correcto. */
    public function test_expire_stuck_con_el_nombre_equivocado_no_toca_nada(): void
    {
        Carbon::setTestNow($this->momento_base());

        $umbral = VencerDeploymentsColgados::timeout_minutos_efectivo();

        $e       = $this->armar_escenario();
        $upgrade = $this->crear_upgrade($e, [
            'deployment_status'        => 'running',
            'deployment_running_since' => $this->momento_base()->copy()->subMinutes($umbral + 30),
        ]);

        $respuesta = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/expire-stuck', [
            'confirm_client_name' => 'Otro Negocio',
        ], $this->headers())->assertStatus(422);

        $this->assertStringNotContainsString(self::NOMBRE, $this->cuerpo($respuesta));

        $this->assertSame('running', (string) $upgrade->refresh()->deployment_status);
        $this->assertSame(0, $this->lineas_de_vencimiento($upgrade));
    }

    /** Un deployment que no está en `running` no tiene nada que destrabar: 422 y cero escrituras. */
    public function test_expire_stuck_sobre_un_estado_que_no_es_running_no_toca_nada(): void
    {
        Carbon::setTestNow($this->momento_base());

        $e       = $this->armar_escenario();
        $upgrade = $this->crear_upgrade($e, ['deployment_status' => 'paused']);

        $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/expire-stuck', [
            'confirm_client_name' => self::NOMBRE,
        ], $this->headers())->assertStatus(422)->json();

        $this->assertSame('running', $cuerpo['deployment_status_esperado']);
        $this->assertSame('paused', (string) $upgrade->refresh()->deployment_status);
        $this->assertSame(0, $this->lineas_de_vencimiento($upgrade));
    }

    /**
     * 🔴 EL CLAIM ATÓMICO. Si el tramo cambia entre la medición y la escritura —el worker terminó y
     * alguien volvió a arrancar, así que `deployment_running_since` es otro—, el UPDATE condicionado
     * no afecta ninguna fila y el endpoint devuelve 409 SIN tocar nada. Sin ese caso, este endpoint
     * mataría un tramo recién nacido con el motivo de una medición que ya no aplica.
     *
     * La carrera se simula con el evento `retrieved` del modelo: cuando el controlador lee el
     * upgrade, se le mueve el ancla en la base por debajo. Es la única forma de meter una escritura
     * concurrente adentro de un request de test, y no toca ni una línea del código de producción.
     */
    public function test_expire_stuck_no_pisa_un_tramo_que_arranco_recien(): void
    {
        Carbon::setTestNow($this->momento_base());

        $umbral = VencerDeploymentsColgados::timeout_minutos_efectivo();

        $e       = $this->armar_escenario();
        $upgrade = $this->crear_upgrade($e, [
            'deployment_status'        => 'running',
            'deployment_running_since' => $this->momento_base()->copy()->subMinutes($umbral + 30),
        ]);

        $ancla_nueva = $this->momento_base()->copy()->subMinute();
        $movida      = false;

        Event::listen('eloquent.retrieved: ' . ClientVersionUpgrade::class, function ($modelo) use ($upgrade, $ancla_nueva, &$movida) {
            if ($movida || (int) $modelo->id !== (int) $upgrade->id) {
                return;
            }

            $movida = true;

            /* Escritura cruda: no dispara eventos de Eloquent y deja la instancia que el
               controlador tiene en memoria con el ancla vieja, que es justo la carrera. */
            DB::table('client_version_upgrades')
                ->where('id', $upgrade->id)
                ->update(['deployment_running_since' => $ancla_nueva]);
        });

        $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/expire-stuck', [
            'confirm_client_name' => self::NOMBRE,
        ], $this->headers())->assertStatus(409)->json();

        $this->assertStringContainsString('cambió de estado', $cuerpo['error']);

        /* El upgrade quedó intacto: sigue en `running` y sin línea de vencimiento. */
        $this->assertSame('running', (string) $upgrade->refresh()->deployment_status);
        $this->assertSame(0, $this->lineas_de_vencimiento($upgrade));
    }
}
