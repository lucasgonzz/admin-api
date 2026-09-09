<?php

namespace Tests\Feature;

use App\Jobs\RunDeploymentJob;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientVersionUpgrade;
use App\Models\DeploymentLog;
use App\Models\Version;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `POST claude/upgrades/{id}/deploy/start` con `resume_from_step` (misión `actualizar-sin-el-vps`,
 * 9/9/2026): reanudar un pre-cierre que quedó `failed` desde la etapa que falló, sin repetir las
 * que ya salieron bien.
 *
 * Por qué importa: con los artefactos del release, `compile_spa` baja un zip de GitHub y
 * `upload_api` otro. Si `upload_api` falla por un corte de SSH al hosting, reintentar desde
 * `compile_spa` vuelve a bajar y a desplegar la SPA por nada; y en la vía vieja, repetir el build en
 * el VPS es exactamente el costo que se vino a sacar.
 *
 * Lo que se protege, en orden:
 *
 *  1. 🔴 Que `resume_from_step` sólo se acepte sobre un deployment `failed`. Sobre cualquier otro
 *     estado es 422 sin escribir nada: reanudar "desde upload_api" un upgrade que nunca arrancó
 *     desplegaría una API sin haber subido la SPA.
 *  2. 🔴 Que sólo acepte las cuatro etapas del pre-cierre. `run_seeders` y lo que sigue corren sobre
 *     el sistema EN USO y tienen su propio endpoint con gate de horario: colarlas por acá sería
 *     saltear ese gate.
 *  3. Que con `resume_from_step` NO se borren los logs del intento anterior (el motivo del fallo es
 *     justo lo que hace falta para decidir desde dónde reanudar), se agregue una línea que lo
 *     declare, y el job se encole con esa etapa en la conexión `database`.
 *  4. Que sin `resume_from_step` todo siga exactamente igual: arranque limpio desde `compile_spa`,
 *     logs borrados, etapa `null` en el job.
 *
 * ⚠️ El `return $job->connection === 'database'` de cada aserción NO es decorativo:
 * `QueueFake::connection()` devuelve `$this` sin mirar el nombre (ver
 * `tests/Feature/DemoSetupFueraDelRequestTest.php:148-152`).
 */
class ReanudacionDelDeploymentPorClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-claude-reanudacion';

    /** Nombre del cliente del escenario: es lo que confirma `confirm_client_name`. */
    const NOMBRE = 'Distribuidora Rioplatense';

    /** Las cuatro etapas del pre-cierre que se pueden reanudar, en orden. */
    const ETAPAS_DEL_PRE_CIERRE = ['compile_spa', 'upload_spa', 'upload_api', 'run_migrations'];

    /**
     * Setea la clave de ingesta: en el .env del slot está vacía y el middleware es fail-closed.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
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
     * Escenario: cliente con la API activa y una API destino distinta, versión origen y destino.
     *
     * @return array<string, mixed>
     */
    private function armar_escenario(): array
    {
        $from = $this->crear_version('4.0.22');
        $to   = $this->crear_version('4.0.23');

        $client                     = new Client();
        $client->name               = self::NOMBRE;
        $client->company_name       = 'Empresa ' . self::NOMBRE;
        $client->slug               = 'distribuidora-' . Str::random(8);
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

        return compact('from', 'to', 'client', 'api_activa', 'api_destino');
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
     * Upgrade que quedó `failed` en `upload_api`, con los dos logs del intento anterior.
     *
     * @param array<string, mixed> $escenario Escenario base.
     *
     * @return ClientVersionUpgrade
     */
    private function upgrade_fallado_en_upload_api(array $escenario): ClientVersionUpgrade
    {
        $upgrade = $this->crear_upgrade($escenario, [
            'deployment_status'        => 'failed',
            'deployment_started_at'    => now()->subMinutes(20),
            'deployment_running_since' => now()->subMinutes(20),
        ]);

        DeploymentLog::create([
            'client_version_upgrade_id' => $upgrade->id,
            'step'                      => 'upload_spa',
            'level'                     => 'success',
            'line'                      => 'SPA desplegado en public_html (contenido anterior reemplazado)',
        ]);

        DeploymentLog::create([
            'client_version_upgrade_id' => $upgrade->id,
            'step'                      => 'upload_api',
            'level'                     => 'error',
            'line'                      => 'SFTP put falló al subir domains/comerciocity.com/public_html/x/api/api_abc.zip',
        ]);

        return $upgrade;
    }

    /**
     * Cantidad de líneas de log de un upgrade.
     *
     * @param ClientVersionUpgrade $upgrade Upgrade a mirar.
     *
     * @return int
     */
    private function lineas_de_log(ClientVersionUpgrade $upgrade): int
    {
        return DB::table('deployment_logs')->where('client_version_upgrade_id', $upgrade->id)->count();
    }

    /**
     * Etapa de reanudación con la que se despachó un RunDeploymentJob, leída por reflexión porque
     * la propiedad es privada y el contrato del job no se cambia para que un test pueda mirarlo.
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
     * Ruta del start de un upgrade.
     *
     * @param ClientVersionUpgrade $upgrade Upgrade.
     *
     * @return string
     */
    private function ruta(ClientVersionUpgrade $upgrade): string
    {
        return '/api/claude/upgrades/' . $upgrade->id . '/deploy/start';
    }

    /* ==========================================================================================
     | Reanudar
     |========================================================================================= */

    /**
     * 🔴 Sobre un `failed`, `resume_from_step=upload_api` encola el job desde esa etapa, en la
     * conexión `database`, CONSERVA los logs del intento anterior y agrega la línea que lo declara.
     */
    public function test_reanuda_desde_la_etapa_pedida_y_conserva_los_logs_del_intento_anterior(): void
    {
        Queue::fake();

        $e       = $this->armar_escenario();
        $upgrade = $this->upgrade_fallado_en_upload_api($e);

        $this->assertSame(2, $this->lineas_de_log($upgrade));

        $cuerpo = $this->postJson($this->ruta($upgrade), [
            'confirm_client_name' => self::NOMBRE,
            'resume_from_step'    => 'upload_api',
        ], $this->headers())->assertStatus(202)->json();

        $this->assertTrue($cuerpo['encolado']);
        $this->assertSame('database', $cuerpo['conexion']);
        $this->assertSame('upload_api', $cuerpo['desde_etapa'], '`desde_etapa` tiene que reflejar la etapa real, no `compile_spa`.');
        $this->assertStringContainsString('conserv', $cuerpo['nota_logs']);
        $this->assertStringNotContainsString('borraron', $cuerpo['nota_logs']);

        /* Los dos logs viejos siguen, más la línea de reanudación. */
        $this->assertSame(3, $this->lineas_de_log($upgrade), 'Con resume_from_step los logs del intento anterior NO se borran.');

        $linea = DeploymentLog::where('client_version_upgrade_id', $upgrade->id)
            ->orderByDesc('id')
            ->first();

        $this->assertStringContainsString('Reanudando desde upload_api', (string) $linea->line);
        $this->assertSame('upload_api', (string) $linea->step);

        $etapas = [];
        Queue::assertPushed(RunDeploymentJob::class, function ($job) use (&$etapas) {
            $etapas[] = $this->etapa_del_job($job);

            return $job->connection === 'database';
        });

        $this->assertCount(1, $etapas, 'No se midió ningún job despachado.');
        $this->assertSame('upload_api', $etapas[0]);

        $upgrade->refresh();
        $this->assertSame('running', (string) $upgrade->deployment_status);
        $this->assertNotNull($upgrade->deployment_running_since, 'El sello del tramo acompaña siempre a `running`.');
        /* La columna no tiene cast: se parsea a mano. */
        $this->assertTrue(
            Carbon::parse((string) $upgrade->deployment_running_since)->greaterThan(now()->subMinutes(5)),
            'La reanudación tiene que renovar el ancla: con la vieja, el vencimiento lo mataría en el primer tick.'
        );
    }

    /** Las cuatro etapas del pre-cierre se aceptan, cada una con su propio `failed`. */
    public function test_acepta_las_cuatro_etapas_del_pre_cierre(): void
    {
        Queue::fake();

        $e = $this->armar_escenario();

        foreach (self::ETAPAS_DEL_PRE_CIERRE as $etapa) {
            $upgrade = $this->crear_upgrade($e, ['deployment_status' => 'failed']);

            $cuerpo = $this->postJson($this->ruta($upgrade), [
                'confirm_client_name' => self::NOMBRE,
                'resume_from_step'    => $etapa,
            ], $this->headers())->assertStatus(202)->json();

            $this->assertSame($etapa, $cuerpo['desde_etapa']);
        }

        $etapas = [];
        Queue::assertPushed(RunDeploymentJob::class, function ($job) use (&$etapas) {
            $etapas[] = $this->etapa_del_job($job);

            return $job->connection === 'database';
        });

        $this->assertSame(self::ETAPAS_DEL_PRE_CIERRE, $etapas);
    }

    /* ==========================================================================================
     | Los frenos
     |========================================================================================= */

    /**
     * 🔴 `resume_from_step` sólo vale sobre un `failed`. Sobre un upgrade que nunca arrancó, o que
     * ya terminó, es 422 sin escribir nada: ni logs, ni estado, ni job.
     *
     * Un `paused` también rechaza, pero por el freno de siempre ("ya hay un deployment en curso"),
     * que corre antes: se mide aparte para dejar claro que la reanudación no lo saltea.
     */
    public function test_resume_from_step_sobre_un_estado_que_no_es_failed_no_encola_nada(): void
    {
        Queue::fake();

        $e = $this->armar_escenario();

        foreach ([null, 'completed'] as $estado) {
            $upgrade = $this->crear_upgrade($e, ['deployment_status' => $estado]);

            DeploymentLog::create([
                'client_version_upgrade_id' => $upgrade->id,
                'step'                      => 'compile_spa',
                'level'                     => 'info',
                'line'                      => 'una línea del intento anterior',
            ]);

            $cuerpo = $this->postJson($this->ruta($upgrade), [
                'confirm_client_name' => self::NOMBRE,
                'resume_from_step'    => 'upload_spa',
            ], $this->headers())->assertStatus(422)->json();

            $this->assertSame('failed', $cuerpo['deployment_status_esperado']);
            $this->assertSame($estado, $cuerpo['deployment_status']);
            $this->assertStringContainsString('resume_from_step', $cuerpo['error']);

            $this->assertSame(1, $this->lineas_de_log($upgrade), 'Un freno que rechaza no toca los logs.');
            $this->assertSame($estado, $upgrade->refresh()->deployment_status, 'Un freno que rechaza no toca el estado.');
        }

        /* `paused` es un deployment ACTIVO: lo frena el freno de siempre, antes que el de la reanudación. */
        $pausado = $this->crear_upgrade($e, ['deployment_status' => 'paused']);

        DeploymentLog::create([
            'client_version_upgrade_id' => $pausado->id,
            'step'                      => 'pause_for_crons',
            'level'                     => 'info',
            'line'                      => 'esperando los crons',
        ]);

        $cuerpo = $this->postJson($this->ruta($pausado), [
            'confirm_client_name' => self::NOMBRE,
            'resume_from_step'    => 'upload_spa',
        ], $this->headers())->assertStatus(422)->json();

        $this->assertStringContainsString('en curso', $cuerpo['error']);
        $this->assertSame('paused', $cuerpo['deployment_status']);
        $this->assertSame(1, $this->lineas_de_log($pausado));
        $this->assertSame('paused', (string) $pausado->refresh()->deployment_status);

        Queue::assertNothingPushed();
    }

    /**
     * 🔴 Sólo las etapas del pre-cierre. `run_seeders`, `run_commands` y lo que sigue corren sobre el
     * sistema en uso y tienen su propio endpoint con gate de horario: por acá no entran.
     */
    public function test_resume_from_step_con_una_etapa_que_no_es_del_pre_cierre_devuelve_422(): void
    {
        Queue::fake();

        $e       = $this->armar_escenario();
        $upgrade = $this->upgrade_fallado_en_upload_api($e);

        foreach (['run_seeders', 'run_commands', 'update_default_version', 'complete', 'cualquier_cosa'] as $etapa) {
            $cuerpo = $this->postJson($this->ruta($upgrade), [
                'confirm_client_name' => self::NOMBRE,
                'resume_from_step'    => $etapa,
            ], $this->headers())->assertStatus(422)->json();

            $this->assertSame('parámetros inválidos', $cuerpo['error']);
            $this->assertArrayHasKey('resume_from_step', $cuerpo['errores']);
        }

        Queue::assertNothingPushed();
        $this->assertSame(2, $this->lineas_de_log($upgrade));
        $this->assertSame('failed', (string) $upgrade->refresh()->deployment_status);
    }

    /** Reanudar no saltea los frenos de siempre: con el nombre equivocado es 422 y los logs quedan. */
    public function test_reanudar_no_saltea_el_freno_del_nombre(): void
    {
        Queue::fake();

        $e       = $this->armar_escenario();
        $upgrade = $this->upgrade_fallado_en_upload_api($e);

        $respuesta = $this->postJson($this->ruta($upgrade), [
            'confirm_client_name' => 'Otro Negocio',
            'resume_from_step'    => 'upload_api',
        ], $this->headers())->assertStatus(422);

        $this->assertStringNotContainsString(self::NOMBRE, (string) json_encode($respuesta->json(), JSON_UNESCAPED_UNICODE));

        Queue::assertNothingPushed();
        $this->assertSame(2, $this->lineas_de_log($upgrade));
        $this->assertSame('failed', (string) $upgrade->refresh()->deployment_status);
    }

    /* ==========================================================================================
     | Sin reanudar: nada cambió
     |========================================================================================= */

    /**
     * Sin `resume_from_step` el start es el de siempre: arranque limpio desde `compile_spa`, logs del
     * intento anterior borrados, etapa `null` en el job. Es el contrato que ya usa el panel y
     * `claude/*`; este test está para que la reanudación no lo mueva ni un milímetro.
     */
    public function test_sin_resume_from_step_arranca_limpio_y_borra_los_logs_como_siempre(): void
    {
        Queue::fake();

        $e       = $this->armar_escenario();
        $upgrade = $this->upgrade_fallado_en_upload_api($e);

        $cuerpo = $this->postJson($this->ruta($upgrade), [
            'confirm_client_name' => self::NOMBRE,
        ], $this->headers())->assertStatus(202)->json();

        $this->assertSame('compile_spa', $cuerpo['desde_etapa']);
        $this->assertStringContainsString('borraron', $cuerpo['nota_logs']);
        $this->assertSame(0, $this->lineas_de_log($upgrade), 'Sin resume_from_step los logs se borran, como siempre.');

        $etapas = [];
        Queue::assertPushed(RunDeploymentJob::class, function ($job) use (&$etapas) {
            $etapas[] = $this->etapa_del_job($job);

            return $job->connection === 'database';
        });

        $this->assertCount(1, $etapas);
        $this->assertNull($etapas[0], 'Sin resume_from_step el pipeline arranca desde el principio.');
    }

    /** Un `resume_from_step` vacío es lo mismo que no mandarlo. */
    public function test_un_resume_from_step_vacio_es_lo_mismo_que_no_mandarlo(): void
    {
        Queue::fake();

        $e       = $this->armar_escenario();
        $upgrade = $this->upgrade_fallado_en_upload_api($e);

        $cuerpo = $this->postJson($this->ruta($upgrade), [
            'confirm_client_name' => self::NOMBRE,
            'resume_from_step'    => '',
        ], $this->headers())->assertStatus(202)->json();

        $this->assertSame('compile_spa', $cuerpo['desde_etapa']);
        $this->assertSame(0, $this->lineas_de_log($upgrade));

        Queue::assertPushed(RunDeploymentJob::class, function ($job) {
            return $this->etapa_del_job($job) === null && $job->connection === 'database';
        });
    }
}
