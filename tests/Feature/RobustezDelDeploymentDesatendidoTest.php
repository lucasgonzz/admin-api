<?php

namespace Tests\Feature;

use App\Console\Commands\VencerDeploymentsColgados;
use App\Jobs\RunDeploymentJob;
use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientVersionUpgrade;
use App\Models\DeploymentLog;
use App\Models\UpdateCommand;
use App\Models\Version;
use App\Models\VersionCommand;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Las dos mitades que hacen seguro dejar corriendo una fila de actualizaciones desatendida.
 *
 * Vienen de `informes/20260824-claude-actualizaciones-y-horarios-cliente.md` §7, puntos 1 y 2, y de
 * la clase del 13/8/2026 de `APRENDER_NO_PARCHEAR.md`: *trabajo largo va a un worker de verdad, y
 * todo estado intermedio necesita un proceso que lo destrabe que no sea el mismo que lo puso ahí*.
 *
 *  1. 🔴 El panel **encola** el pipeline en la conexión `database` en vez de correrlo adentro del
 *     request. Con `QUEUE_CONNECTION=sync` un `dispatch()` pelado corría el pipeline SSH entero
 *     —`compile_spa` con `npm ci` y `npm run build` incluidos— colgado del request HTTP, y bajo
 *     mod_php lo mataba `max_execution_time` a los 120 segundos. Un fatal por tiempo no es
 *     capturable: el `catch (\Throwable)` de `RunDeploymentJob` nunca corría y el upgrade quedaba
 *     en `running` para siempre. `ClaudeUpgradeOpsController` ya lo hacía bien; el panel había
 *     quedado afuera.
 *  2. 🔴 Un deployment que dejó de reportar actividad **vence** y pasa a `failed` con el motivo
 *     escrito como línea de log. Antes lo rechazaban las dos puertas (el panel con
 *     `$active_deployment_statuses` y `claude/*` con su equivalente) y solo se salía tocando la
 *     base a mano.
 *  3. Y el ancla del vencimiento es `deployment_running_since`, sellada en las SIETE entradas a
 *     `running`. Sin ella, un post-cierre recién apretado sobre un upgrade que estuvo días en
 *     `paused` se vencería en el primer tick, con el worker todavía sin levantarlo: el comando
 *     rompería justo el flujo que viene a proteger.
 */
class RobustezDelDeploymentDesatendidoTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Contador para que cada upgrade del mismo test tenga sus propias versiones:
     * `versions.version` es UNIQUE.
     *
     * @var int
     */
    private $contador_de_versiones = 0;

    /**
     * Libera el reloj fijado por los tests que lo mueven.
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
     * Momento base fijo: la suite no puede cambiar de comportamiento según la hora a la que corra.
     *
     * @return Carbon
     */
    private function momento_base(): Carbon
    {
        return Carbon::parse('2026-08-25 10:00:00');
    }

    /**
     * Admin autenticado por sanctum: las rutas del panel viven bajo `auth:sanctum`.
     *
     * @return Admin
     */
    private function autenticar(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = 'robustez-deploy-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Upgrade listo para los endpoints de deployment, con su cliente, sus versiones y su API destino.
     *
     * @param array<string, mixed> $atributos Atributos del upgrade (estado, sellos, pasos…).
     *
     * @return ClientVersionUpgrade
     */
    private function crear_upgrade(array $atributos = []): ClientVersionUpgrade
    {
        $this->contador_de_versiones += 2;
        $from = $this->crear_version('5.' . $this->contador_de_versiones . '.0');
        $to   = $this->crear_version('5.' . $this->contador_de_versiones . '.1');

        $client                     = new Client();
        $client->name               = 'Cliente de prueba';
        $client->company_name       = 'Empresa de prueba';
        $client->slug               = 'cliente-robustez-' . Str::random(8);
        $client->api_url            = 'https://ejemplo.test';
        $client->api_key            = 'clave-api';
        $client->inbound_api_key    = 'clave-inbound';
        $client->is_active          = true;
        $client->current_version_id = $from->id;
        $client->save();

        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = 'https://api-destino.ejemplo.test';
        $api->path         = 'ejemplo/' . Str::random(6);
        $api->hosting_type = 'shared_hosting';
        $api->save();

        return ClientVersionUpgrade::create(array_merge([
            'client_id'            => $client->id,
            'from_version_id'      => $from->id,
            'to_version_id'        => $to->id,
            'status'               => 'pendiente',
            'scheduled_date'       => $this->momento_base()->toDateString(),
            'target_client_api_id' => $api->id,
        ], $atributos));
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
        $version->published_at = $this->momento_base();
        $version->save();

        return $version;
    }

    /**
     * Deja el upgrade con un comando automatizado reintentable y sin seeders incompletos, que son
     * las dos precondiciones de `retry_commands_json`.
     *
     * @param ClientVersionUpgrade $upgrade Upgrade a preparar.
     *
     * @return void
     */
    private function preparar_comando_reintentable(ClientVersionUpgrade $upgrade): void
    {
        $version_command                  = new VersionCommand();
        $version_command->version_id      = $upgrade->to_version_id;
        $version_command->command         = 'comando:falso-' . Str::random(6);
        $version_command->execution_order = 1;
        $version_command->run_manually    = false;
        $version_command->save();

        $update_command                            = new UpdateCommand();
        $update_command->uuid                      = (string) Str::uuid();
        $update_command->client_version_upgrade_id = $upgrade->id;
        $update_command->version_command_id        = $version_command->id;
        $update_command->status                    = 'fallido';
        $update_command->save();
    }

    /**
     * Línea de log de este upgrade con la fecha que pida el caso.
     *
     * @param ClientVersionUpgrade $upgrade Upgrade dueño.
     * @param Carbon               $cuando  Momento de la línea.
     *
     * @return DeploymentLog
     */
    private function registrar_log(ClientVersionUpgrade $upgrade, Carbon $cuando): DeploymentLog
    {
        return DeploymentLog::create([
            'client_version_upgrade_id' => $upgrade->id,
            'step'                      => 'upload_api',
            'line'                      => 'Subiendo empresa-api…',
            'level'                     => 'info',
            'created_at'                => $cuando,
        ]);
    }

    /* ------------------------------------------------------------------------------------------
     | 1. El pipeline sale del request
     |----------------------------------------------------------------------------------------- */

    /**
     * 1. El `start` del panel ENCOLA el pipeline: no lo corre adentro del request.
     *
     * 🔴 El `return $job->connection === 'database'` NO es decorativo: `QueueFake::connection()`
     * devuelve `$this` sin mirar el nombre, así que un `assertPushed` pelado pasaría igual con un
     * `dispatch()` sin `onConnection` — o sea que no probaría justamente el requisito de esta
     * misión. Está documentado en `DemoSetupFueraDelRequestTest:148-152`.
     *
     * @return void
     */
    public function test_el_start_del_panel_encola_en_database_y_no_corre_el_pipeline(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->momento_base());
        $this->autenticar();

        $upgrade = $this->crear_upgrade();

        $this->postJson('/api/admin/update/' . $upgrade->id . '/deploy/start')
            ->assertStatus(200);

        Queue::assertPushed(RunDeploymentJob::class, function ($job) {
            return $job->connection === 'database';
        });

        $upgrade->refresh();

        // El estado se escribe ANTES del despacho: es lo que arranca el poleo de la SPA.
        $this->assertSame('running', (string) $upgrade->deployment_status);
        $this->assertNotNull($upgrade->deployment_running_since, 'El sello del tramo es la condición que decide el vencimiento.');
    }

    /**
     * 2. Las CUATRO entradas del panel encolan en `database` y sellan el ancla.
     *
     * Las cuatro juntas y no solo el `start`, porque el punto 1 del informe nombraba las cuatro
     * líneas y porque el sello que falte es un upgrade sano que se vence solo a los 45 minutos.
     *
     * @return void
     */
    public function test_las_cuatro_entradas_del_panel_encolan_en_database_y_sellan_el_ancla(): void
    {
        Carbon::setTestNow($this->momento_base());
        $this->autenticar();

        $casos = [
            // [ruta, atributos del upgrade, prepara comandos]
            ['deploy/start', [], false],
            ['deploy/start-post-closure', ['deployment_status' => 'paused', 'crons_supervisor_at' => $this->momento_base()], false],
            ['deploy/configure-system', ['deployment_status' => 'paused_post_tasks'], false],
            ['deploy/retry-commands', ['deployment_status' => 'failed'], true],
        ];

        foreach ($casos as $caso) {
            Queue::fake();

            $upgrade = $this->crear_upgrade($caso[1]);
            if ($caso[2]) {
                $this->preparar_comando_reintentable($upgrade);
            }

            $this->postJson('/api/admin/update/' . $upgrade->id . '/' . $caso[0])
                ->assertStatus(200);

            Queue::assertPushed(RunDeploymentJob::class, function ($job) {
                return $job->connection === 'database';
            });

            $upgrade->refresh();

            $this->assertSame('running', (string) $upgrade->deployment_status, 'Ruta: ' . $caso[0]);
            $this->assertNotNull($upgrade->deployment_running_since, 'Sin sello en ' . $caso[0] . ': ese upgrade se vencería solo estando sano.');
        }
    }

    /* ------------------------------------------------------------------------------------------
     | 2. `running` deja de ser un pozo
     |----------------------------------------------------------------------------------------- */

    /**
     * 3. Un deployment sin actividad por encima del umbral pasa a `failed` con el motivo escrito.
     *
     * @return void
     */
    public function test_un_deployment_sin_actividad_vence_y_deja_el_motivo_escrito(): void
    {
        Carbon::setTestNow($this->momento_base());

        $timeout = VencerDeploymentsColgados::DEFAULT_TIMEOUT_MINUTOS;

        $upgrade = $this->crear_upgrade([
            'deployment_status'        => 'running',
            'deployment_started_at'    => $this->momento_base()->copy()->subMinutes($timeout + 30),
            'deployment_running_since' => $this->momento_base()->copy()->subMinutes($timeout + 30),
        ]);
        $this->registrar_log($upgrade, $this->momento_base()->copy()->subMinutes($timeout + 10));

        $this->artisan('deployments:vencer-colgados')->assertExitCode(0);

        $this->assertSame('failed', (string) $upgrade->refresh()->deployment_status);

        $linea = DeploymentLog::where('client_version_upgrade_id', $upgrade->id)
            ->where('step', VencerDeploymentsColgados::STEP_VENCIMIENTO)
            ->first();

        $this->assertNotNull($linea, 'El motivo va a deployment_logs: no hay columna de error en client_version_upgrades.');
        $this->assertSame('error', (string) $linea->level, 'Sin level=error el panel no lo pinta en rojo.');

        // El texto dice que NO SE SABE cómo terminó, no que falló…
        $this->assertStringContainsString('no reportó actividad', (string) $linea->line);
        // …y avisa que el servidor del cliente quedó en un estado desconocido.
        $this->assertStringContainsString('estado quedó el servidor del cliente', (string) $linea->line);
    }

    /**
     * 4. Un deployment con actividad reciente NO se toca, aunque el sello sea viejo.
     *
     * Es la mitad que importa del test anterior: un pipeline largo que sigue escribiendo logs está
     * sano, y vencerlo lo dejaría `failed` a mitad de subir archivos al servidor de un cliente.
     *
     * @return void
     */
    public function test_un_deployment_con_actividad_reciente_no_se_toca(): void
    {
        Carbon::setTestNow($this->momento_base());

        $timeout = VencerDeploymentsColgados::DEFAULT_TIMEOUT_MINUTOS;

        $upgrade = $this->crear_upgrade([
            'deployment_status'        => 'running',
            'deployment_running_since' => $this->momento_base()->copy()->subMinutes($timeout * 3),
        ]);
        $this->registrar_log($upgrade, $this->momento_base()->copy()->subMinute());

        $this->artisan('deployments:vencer-colgados')->assertExitCode(0);

        $this->assertSame('running', (string) $upgrade->refresh()->deployment_status);
    }

    /**
     * 5. 🔴 Un post-cierre recién arrancado NO se vence, por viejos que sean sus logs.
     *
     * Es el caso que motivó la columna `deployment_running_since` entera. Un upgrade puede estar
     * días en `paused` esperando que cierre el negocio; cuando Lucas aprieta "post-cierre", su
     * último `deployment_log` y su `deployment_started_at` tienen esos mismos días de antigüedad, y
     * el worker tarda hasta un tick del scheduler en escribir la primera línea. Un vencimiento
     * anclado ahí lo mataría antes de empezar.
     *
     * @return void
     */
    public function test_un_post_cierre_recien_arrancado_no_se_vence(): void
    {
        Carbon::setTestNow($this->momento_base());
        $this->autenticar();

        $hace_dos_dias = $this->momento_base()->copy()->subDays(2);

        $upgrade = $this->crear_upgrade([
            'deployment_status'     => 'paused',
            'deployment_started_at' => $hace_dos_dias,
            'crons_supervisor_at'   => $hace_dos_dias,
        ]);
        $this->registrar_log($upgrade, $hace_dos_dias);

        Queue::fake();
        $this->postJson('/api/admin/update/' . $upgrade->id . '/deploy/start-post-closure')
            ->assertStatus(200);

        // El worker todavía no escribió nada: es el minuto exacto en que el bug pegaría.
        $this->artisan('deployments:vencer-colgados')->assertExitCode(0);

        $this->assertSame('running', (string) $upgrade->refresh()->deployment_status);
    }

    /**
     * 6. Un upgrade que quedó en `running` ANTES de la migración no se vence.
     *
     * Sin sello no se sabe hace cuánto está ahí, y vencerlo sería inventar la medición. Esos siguen
     * saliendo a mano, una sola vez.
     *
     * @return void
     */
    public function test_no_se_vence_un_upgrade_sin_ancla(): void
    {
        Carbon::setTestNow($this->momento_base());

        $upgrade = $this->crear_upgrade([
            'deployment_status'        => 'running',
            'deployment_started_at'    => $this->momento_base()->copy()->subDays(3),
            'deployment_running_since' => null,
        ]);
        $this->registrar_log($upgrade, $this->momento_base()->copy()->subDays(3));

        $this->artisan('deployments:vencer-colgados')->assertExitCode(0);

        $this->assertSame('running', (string) $upgrade->refresh()->deployment_status);
        $this->assertSame(
            0,
            DeploymentLog::where('client_version_upgrade_id', $upgrade->id)
                ->where('step', VencerDeploymentsColgados::STEP_VENCIMIENTO)
                ->count()
        );
    }

    /**
     * 7. El vencimiento no toca ningún estado que no sea `running`.
     *
     * `paused` y `paused_post_tasks` son esperas legítimas de días: son exactamente los estados que
     * un umbral mal aplicado destrozaría.
     *
     * @return void
     */
    public function test_el_vencimiento_no_toca_los_otros_estados(): void
    {
        Carbon::setTestNow($this->momento_base());

        $viejo   = $this->momento_base()->copy()->subDays(5);
        $estados = ['paused', 'paused_post_tasks', 'failed', 'success'];

        $upgrades = [];
        foreach ($estados as $estado) {
            $upgrade = $this->crear_upgrade([
                'deployment_status'        => $estado,
                'deployment_running_since' => $viejo,
            ]);
            $this->registrar_log($upgrade, $viejo);
            $upgrades[$estado] = $upgrade;
        }

        $this->artisan('deployments:vencer-colgados')->assertExitCode(0);

        foreach ($estados as $estado) {
            $this->assertSame($estado, (string) $upgrades[$estado]->refresh()->deployment_status);
        }
    }

    /**
     * 8. 🔴 El invariante que hace seguro a todo el comando: el umbral NO puede bajar del `$timeout`
     * del job, ni con un `--minutos` a mano ni con un valor mal cargado en `admin_settings`.
     *
     * Si bajara, este comando marcaría `failed` un upgrade cuyo worker está corriendo
     * `npm run build` en ese mismo momento — y desde `failed` las dos puertas dejan arrancar de
     * nuevo, así que quedarían dos `DeploymentService` por SSH sobre el hosting del mismo cliente.
     * Es la única forma de que esta misión haga más daño del que arregla.
     *
     * @return void
     */
    public function test_el_umbral_nunca_baja_del_timeout_del_job(): void
    {
        Carbon::setTestNow($this->momento_base());

        // El piso se deriva del job, no es un número suelto: si alguien sube el timeout, sube solo.
        $piso = VencerDeploymentsColgados::min_timeout_minutos();
        $this->assertGreaterThan(
            RunDeploymentJob::TIMEOUT_SEGUNDOS / 60,
            $piso,
            'Un piso por debajo del timeout del job deja que este comando mate procesos vivos.'
        );

        // Un deployment que arrancó hace la mitad del piso: el worker todavía puede estar vivo.
        $vivo = $this->crear_upgrade([
            'deployment_status'        => 'running',
            'deployment_running_since' => $this->momento_base()->copy()->subMinutes((int) floor($piso / 2)),
        ]);

        // Y uno que ya pasó el piso holgado.
        $muerto = $this->crear_upgrade([
            'deployment_status'        => 'running',
            'deployment_running_since' => $this->momento_base()->copy()->subMinutes($piso + 10),
        ]);

        // Un 0 tipeado a mano: el clamp lo tiene que subir al piso, no obedecerlo.
        $this->artisan('deployments:vencer-colgados', ['--minutos' => 0])->assertExitCode(0);

        $this->assertSame('running', (string) $vivo->refresh()->deployment_status, 'El clamp no obedeció al 0: mató un deployment que podía estar vivo.');
        $this->assertSame('failed', (string) $muerto->refresh()->deployment_status);
    }

    /**
     * 9. 🔴 Cuando el worker da por fallado el job, `failed()` escribe el estado y el motivo.
     *
     * Es el primer piso, y cubre los dos caminos que el `catch (\Throwable)` de `handle()` NO puede
     * cubrir: el `$timeout` del worker (un `SIGALRM` que mata el proceso — la misma clase que el
     * `max_execution_time` del 13/8/2026) y `MaxAttemptsExceededException`, que Laravel tira antes
     * de entrar a `handle()`. Sin esto, esos dos dejaban el upgrade en `running` hasta que el
     * vencimiento lo levantara más de una hora después.
     *
     * @return void
     */
    public function test_el_job_fallado_deja_el_upgrade_en_failed_con_el_motivo(): void
    {
        Carbon::setTestNow($this->momento_base());

        $upgrade = $this->crear_upgrade([
            'deployment_status'        => 'running',
            'deployment_running_since' => $this->momento_base(),
        ]);

        $job = new RunDeploymentJob($upgrade->uuid);
        $job->failed(new \RuntimeException('El worker mató el job por timeout'));

        $this->assertSame('failed', (string) $upgrade->refresh()->deployment_status);

        $linea = DeploymentLog::where('client_version_upgrade_id', $upgrade->id)
            ->where('step', VencerDeploymentsColgados::STEP_VENCIMIENTO)
            ->first();

        $this->assertNotNull($linea);
        $this->assertSame('error', (string) $linea->level);
        $this->assertStringContainsString('El worker mató el job por timeout', (string) $linea->line);
        $this->assertStringContainsString('estado quedó el servidor del cliente', (string) $linea->line);
    }

    /**
     * 10. Y `failed()` NO pisa una pausa legítima que el pipeline ya escribió — pero SÍ deja
     * constancia.
     *
     * Son dos cosas separadas y conviene que se lean separadas: el ESTADO no se toca (`paused` y
     * `paused_post_tasks` los escribe `DeploymentService` a propósito al terminar una etapa, y son
     * la verdad), pero la LÍNEA sí se escribe, porque que el job haya muerto después de la pausa es
     * información que el operador necesita. Desacoplar las dos es justo lo que arregló el agujero
     * del test 13.
     *
     * @return void
     */
    public function test_el_job_fallado_no_pisa_una_pausa_legitima_pero_deja_constancia(): void
    {
        Carbon::setTestNow($this->momento_base());

        $upgrade = $this->crear_upgrade([
            'deployment_status'        => 'paused',
            'deployment_running_since' => $this->momento_base(),
        ]);

        $job = new RunDeploymentJob($upgrade->uuid);
        $job->failed(new \RuntimeException('Cualquier cosa'));

        $this->assertSame('paused', (string) $upgrade->refresh()->deployment_status, 'La pausa es la verdad: el fallo del job no la pisa.');

        $this->assertSame(
            1,
            DeploymentLog::where('client_version_upgrade_id', $upgrade->id)
                ->where('step', VencerDeploymentsColgados::STEP_VENCIMIENTO)
                ->count(),
            'El estado no se toca, pero el operador tiene que enterarse igual de que el job murió.'
        );
    }

    /**
     * 11. 🔴 El ORDEN de los tres umbrales que actúan sobre el mismo deployment.
     *
     * De menor a mayor, y el criterio es cuánto sabe cada uno antes de escribir:
     *
     *   1. `RunDeploymentJob::TIMEOUT_SEGUNDOS` — MATA el proceso vivo.
     *   2. `VencerDeploymentsColgados::min_timeout_minutos()` — marca `failed`, pero solo si no
     *      hubo actividad de logs: mira evidencia.
     *   3. `retry_after` — marca `failed` A CIEGAS, sin mirar logs ni ancla.
     *
     * El que menos sabe va último. La primera versión de esta misión dejó `retry_after` en 31
     * minutos, o sea metido entre el 1 y el 2: el camino de `MaxAttemptsExceededException` marcaba
     * `failed` sin evidencia de nada mientras el worker original podía seguir con el SSH abierto
     * contra el hosting del cliente — y desde `failed` las dos puertas invitan a reintentar.
     *
     * @return void
     */
    public function test_los_tres_umbrales_se_disparan_en_el_orden_correcto(): void
    {
        $timeout_job  = RunDeploymentJob::TIMEOUT_SEGUNDOS;
        $piso_vencer  = VencerDeploymentsColgados::min_timeout_minutos() * 60;
        $retry_after  = (int) config('queue.connections.database.retry_after');

        $this->assertGreaterThan(
            $timeout_job,
            $piso_vencer,
            'El vencimiento tiene que quedar por encima del techo del job, si no marca failed procesos vivos.'
        );

        $this->assertGreaterThan(
            $piso_vencer,
            $retry_after,
            'retry_after escribe failed a ciegas: tiene que ser el MÁS ALTO de los tres.'
        );
    }

    /**
     * 12. Y ningún OTRO job de la conexión `database` puede tener un `$timeout` por encima de
     * `retry_after`.
     *
     * Comparar contra `RunDeploymentJob` a mano no alcanza: hoy `RunClientInstallationGroupJob`
     * ($timeout = 3900) y `RunDemoUpdateJob` ($timeout = 3600) corren en `sync`, pero están a un
     * `->onConnection('database')` de romper el invariante sin que nada lo denuncie. Este test
     * recorre `app/Jobs/` por reflexión para que el día que alguien los mueva, se entere acá.
     *
     * @return void
     */
    public function test_ningun_job_de_la_conexion_supera_el_retry_after(): void
    {
        $retry_after = (int) config('queue.connections.database.retry_after');

        $conexiones_explicitas = [];
        foreach (glob(app_path('Jobs/*.php')) as $archivo) {
            $conexiones_explicitas[basename($archivo, '.php')] = strpos(
                (string) file_get_contents($archivo),
                "onConnection('database')"
            ) !== false;
        }

        /* Los despachos viven en los controladores, no en los jobs, así que se barre todo `app/`
         * buscando qué clase de job se encola explícitamente en `database`. */
        $fuentes = '';
        $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($iterador as $archivo) {
            if ($archivo->isFile() && $archivo->getExtension() === 'php') {
                $fuentes .= (string) file_get_contents($archivo->getPathname());
            }
        }

        foreach (array_keys($conexiones_explicitas) as $clase_corta) {
            $clase = 'App\\Jobs\\' . $clase_corta;
            if (! class_exists($clase)) {
                continue;
            }

            /* Se encola en `database` si en algún lado aparece su despacho seguido del onConnection
             * explícito, o si el propio job lo declara. */
            $encolado_en_database = preg_match(
                '/' . preg_quote($clase_corta, '/') . '::dispatch\([^;]*onConnection\(\s*(self::CONEXION_DE_COLA|\'database\')/s',
                $fuentes
            ) === 1;

            if (! $encolado_en_database) {
                continue;
            }

            $reflexion = new \ReflectionClass($clase);
            $defaults  = $reflexion->getDefaultProperties();
            $timeout   = isset($defaults['timeout']) ? (int) $defaults['timeout'] : 0;

            $this->assertLessThan(
                $retry_after,
                $timeout,
                $clase_corta . ' corre en la conexión `database` con $timeout = ' . $timeout
                    . ', por encima del retry_after de ' . $retry_after
                    . ': va a terminar en failed_jobs sin haber fallado. Subí el retry_after.'
            );
        }
    }

    /**
     * 13. 🔴 `failed()` escribe el motivo AUNQUE el estado ya sea `failed`.
     *
     * Es el camino más común y la primera versión no lo cubría: el `catch` de `handle()` escribe
     * `failed` y re-tira, así que cuando el worker llama a `failed()` el CAS afecta 0 filas. Con el
     * `return` temprano que tenía, el operador se quedaba sin ningún motivo — y si lo que falló fue
     * `connect()`, `DeploymentService` tampoco había escrito una sola línea: el panel mostraba rojo
     * sin una pista.
     *
     * @return void
     */
    public function test_el_job_fallado_escribe_el_motivo_aunque_el_estado_ya_sea_failed(): void
    {
        Carbon::setTestNow($this->momento_base());

        $upgrade = $this->crear_upgrade([
            'deployment_status'        => 'failed',
            'deployment_running_since' => $this->momento_base(),
        ]);

        $job = new RunDeploymentJob($upgrade->uuid);
        $job->failed(new \RuntimeException('No se pudo conectar por SSH'));

        $linea = DeploymentLog::where('client_version_upgrade_id', $upgrade->id)
            ->where('step', VencerDeploymentsColgados::STEP_VENCIMIENTO)
            ->first();

        $this->assertNotNull($linea, 'Sin esta línea, un fallo de connect() deja el panel en rojo y vacío.');
        $this->assertStringContainsString('No se pudo conectar por SSH', (string) $linea->line);
    }

    /**
     * 14. 🔴 Un `MaxAttemptsExceededException` NO dice lo mismo que "el proceso murió".
     *
     * Significa que la cola dio el job por agotado, y el worker original puede seguir vivo con el
     * SSH abierto. Invitar a reintentar ahí es pedir un segundo `DeploymentService` encima del
     * primero.
     *
     * @return void
     */
    public function test_el_agotamiento_de_la_cola_avisa_que_puede_haber_un_proceso_vivo(): void
    {
        Carbon::setTestNow($this->momento_base());

        $upgrade = $this->crear_upgrade([
            'deployment_status'        => 'running',
            'deployment_running_since' => $this->momento_base(),
        ]);

        $job = new RunDeploymentJob($upgrade->uuid);
        $job->failed(new MaxAttemptsExceededException('agotado'));

        $linea = DeploymentLog::where('client_version_upgrade_id', $upgrade->id)
            ->where('step', VencerDeploymentsColgados::STEP_VENCIMIENTO)
            ->first();

        $this->assertNotNull($linea);
        $this->assertStringContainsString('NO reintentes', (string) $linea->line);
        $this->assertStringNotContainsString('murió sin poder reportar', (string) $linea->line);
    }

    /**
     * 15. El backfill de la migración alcanza a los upgrades que ya estaban colgados.
     *
     * Sin él, todo lo que hoy está en `running` en producción quedaba fuera del vencimiento para
     * siempre — o sea, el problema que motivó la misión seguía saliendo a mano justo para los casos
     * que ya existen.
     *
     * @return void
     */
    public function test_la_columna_quedo_rellenada_para_los_running_previos(): void
    {
        /* Se simula el estado post-migración a la inversa: una fila en `running` con el ancla en
         * NULL es exactamente lo que el backfill tenía que evitar que existiera. */
        $upgrade = $this->crear_upgrade(['deployment_status' => 'running']);

        \Illuminate\Support\Facades\DB::table('client_version_upgrades')
            ->where('id', $upgrade->id)
            ->update(['deployment_running_since' => null, 'updated_at' => $this->momento_base()->copy()->subDays(4)]);

        \Illuminate\Support\Facades\DB::table('client_version_upgrades')
            ->where('deployment_status', 'running')
            ->whereNull('deployment_running_since')
            ->update(['deployment_running_since' => \Illuminate\Support\Facades\DB::raw('COALESCE(`updated_at`, `deployment_started_at`)')]);

        $this->assertNotNull(
            $upgrade->refresh()->deployment_running_since,
            'El backfill de la migración es lo que saca del limbo a los upgrades colgados de hoy.'
        );
    }
}
