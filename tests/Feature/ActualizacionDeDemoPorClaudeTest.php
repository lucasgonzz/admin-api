<?php

namespace Tests\Feature;

use App\Jobs\RunDemoUpdateJob;
use App\Models\Demo;
use App\Models\DemoUpdate;
use App\Models\Version;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Los frenos de `POST claude/demo-updates`, el endpoint que actualiza la versión de una demo.
 *
 * Existe por un motivo puntual y no por completitud: este endpoint arranca un pipeline SSH REAL
 * contra el servidor de una demo —compila el SPA, sube SPA y API, corre migraciones y reinicia los
 * workers— y una corrida arrancada no se deshace. Lo que se protege, en orden de importancia:
 *
 *  1. 🔴 Que el `dispatch()` vaya a la conexión `database` y NO corra el pipeline adentro del
 *     request. Con `QUEUE_CONNECTION=sync` un dispatch pelado ejecuta el pipeline entero dentro del
 *     request HTTP y lo mata `max_execution_time`. ⚠️ El `$job->connection === 'database'` de la
 *     aserción NO es decorativo: `QueueFake::connection()` devuelve `$this` sin mirar el nombre, así
 *     que un `assertPushed` pelado pasaría igual con un dispatch SIN `onConnection` —o sea, no
 *     probaría nada—. Y es la regresión más probable acá, porque el PANEL despacha pelado
 *     (`DemoUpdateController::store_json`).
 *  2. 🔴 Que el `$timeout` del job quede por debajo del `retry_after` de la conexión. Es el
 *     invariante que `RobustezDelDeploymentDesatendidoTest` ya vigilaba para los otros jobs y que
 *     este endpoint activa al encolar en `database` por primera vez.
 *  3. Que `dry_run` sea true por defecto: sin `dry_run=false` explícito no se escribe ni se encola
 *     nada.
 *  4. Que `confirm_demo_name` sea exacto y que su error NO revele la URL correcta.
 *  5. Que no se arranquen dos pipelines sobre la misma demo.
 *  6. Que TODO freno que rechaza devuelva 422 y no deje ni fila ni job.
 */
class ActualizacionDeDemoPorClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-claude-demos';

    /** La URL con la que se confirma la demo del escenario. */
    const URL_DEMO = 'https://demo-de-prueba.comerciocity.com';

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
     * Una demo de prueba, con la URL contra la que se confirma.
     *
     * @return Demo
     */
    private function demo(): Demo
    {
        /* Los cuatro campos de ecommerce van aunque el test no los use: la tabla los tiene NOT NULL
           sin default, así que omitirlos rompe el INSERT y no el endpoint. */
        return Demo::create([
            'erp_spa_url'            => self::URL_DEMO,
            'erp_api_url'            => 'https://api-demo-de-prueba.comerciocity.com',
            'erp_hosting_type'       => 'vps',
            'ecommerce_spa_url'      => 'https://tienda-de-prueba.comerciocity.store',
            'ecommerce_api_url'      => 'https://api-tienda-de-prueba.comerciocity.store',
            'ecommerce_hosting_type' => 'vps',
        ]);
    }

    /**
     * Una versión de prueba. El número se aleatoriza porque `versions.version` es unique y el test
     * corre sobre la base del slot, que ya tiene versiones cargadas.
     *
     * @return Version
     */
    private function version(): Version
    {
        return Version::create([
            'version' => '99.' . rand(100, 999) . '.' . rand(100, 999),
            'title'   => 'Versión de prueba del test de demos',
        ]);
    }

    /* ------------------------------------------------------------------------------------------
     | 1. El dispatch, que es lo que no puede regresionar
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 El job se encola en `database` y NO corre adentro del request.
     *
     * @return void
     */
    public function test_el_job_se_encola_en_la_conexion_database(): void
    {
        Queue::fake();

        $demo    = $this->demo();
        $version = $this->version();

        $respuesta = $this->postJson('/api/claude/demo-updates', [
            'demo_id'           => $demo->id,
            'version_id'        => $version->id,
            'confirm_demo_name' => self::URL_DEMO,
            'dry_run'           => false,
        ], $this->headers());

        $respuesta->assertStatus(202);

        Queue::assertPushed(RunDemoUpdateJob::class, function ($job) {
            /* ⚠️ Esta comparación es el test. Sin ella, `assertPushed` pasaría también con un
               dispatch pelado, que es exactamente la regresión que se quiere impedir. */
            return $job->connection === 'database';
        });
    }

    /**
     * 🔴 El `$timeout` del job tiene que quedar por debajo del `retry_after` de la conexión.
     *
     * Por encima, la cola vuelve a marcar el job disponible mientras el primer worker lo sigue
     * corriendo: el siguiente lo reserva, ve `attempts > tries` y lo manda a `failed_jobs`. La demo
     * aparece fallida sin haber fallado.
     *
     * @return void
     */
    public function test_el_timeout_del_job_no_supera_el_retry_after_de_la_cola(): void
    {
        $retry_after = (int) config('queue.connections.database.retry_after');

        $reflexion = new \ReflectionClass(RunDemoUpdateJob::class);
        $timeout   = (int) $reflexion->getDefaultProperties()['timeout'];

        $this->assertLessThan(
            $retry_after,
            $timeout,
            'RunDemoUpdateJob se encola en `database` con $timeout = ' . $timeout . ', por encima del '
                . 'retry_after de ' . $retry_after . ': va a terminar en failed_jobs sin haber fallado.'
        );
    }

    /* ------------------------------------------------------------------------------------------
     | 2. dry_run
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Sin `dry_run=false` no se escribe ni se encola nada.
     *
     * @return void
     */
    public function test_por_defecto_simula_y_no_escribe_ni_encola(): void
    {
        Queue::fake();

        $demo    = $this->demo();
        $version = $this->version();

        $antes = DemoUpdate::where('demo_id', $demo->id)->count();

        $respuesta = $this->postJson('/api/claude/demo-updates', [
            'demo_id'           => $demo->id,
            'version_id'        => $version->id,
            'confirm_demo_name' => self::URL_DEMO,
        ], $this->headers());

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('dry_run', true);

        $this->assertSame($antes, DemoUpdate::where('demo_id', $demo->id)->count(), 'El dry run escribió una fila.');

        Queue::assertNothingPushed();
    }

    /* ------------------------------------------------------------------------------------------
     | 3. El freno del nombre
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Con la URL equivocada se rechaza, no se escribe nada, y el error NO revela la correcta.
     *
     * @return void
     */
    public function test_rechaza_si_la_url_no_confirma_y_no_revela_la_correcta(): void
    {
        Queue::fake();

        $demo    = $this->demo();
        $version = $this->version();

        $respuesta = $this->postJson('/api/claude/demo-updates', [
            'demo_id'           => $demo->id,
            'version_id'        => $version->id,
            'confirm_demo_name' => 'https://otra-demo.comerciocity.com',
            'dry_run'           => false,
        ], $this->headers());

        $respuesta->assertStatus(422);

        /* El freno pierde todo su sentido si el error dice cuál era la URL buena: quien se equivocó
           de demo la copiaría sin darse cuenta de que está por actualizar otra instancia. */
        $this->assertStringNotContainsString(
            self::URL_DEMO,
            (string) $respuesta->getContent(),
            'El error reveló la URL correcta: deja de ser un freno y pasa a ser un formulario a completar.'
        );

        $this->assertSame(0, DemoUpdate::where('demo_id', $demo->id)->count());

        Queue::assertNothingPushed();
    }

    /**
     * Sin `confirm_demo_name` tampoco pasa.
     *
     * @return void
     */
    public function test_rechaza_si_falta_la_confirmacion(): void
    {
        Queue::fake();

        $demo    = $this->demo();
        $version = $this->version();

        $respuesta = $this->postJson('/api/claude/demo-updates', [
            'demo_id'    => $demo->id,
            'version_id' => $version->id,
            'dry_run'    => false,
        ], $this->headers());

        $respuesta->assertStatus(422);

        $this->assertSame(0, DemoUpdate::where('demo_id', $demo->id)->count());

        Queue::assertNothingPushed();
    }

    /* ------------------------------------------------------------------------------------------
     | 4. Una sola corrida viva por demo
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 No se arrancan dos pipelines sobre la misma demo: se pisan los archivos.
     *
     * @return void
     */
    public function test_rechaza_si_esa_demo_ya_tiene_una_actualizacion_viva(): void
    {
        Queue::fake();

        $demo    = $this->demo();
        $version = $this->version();

        DemoUpdate::create([
            'demo_id'    => $demo->id,
            'version_id' => $version->id,
            'status'     => 'ejecutandose',
        ]);

        $respuesta = $this->postJson('/api/claude/demo-updates', [
            'demo_id'           => $demo->id,
            'version_id'        => $version->id,
            'confirm_demo_name' => self::URL_DEMO,
            'dry_run'           => false,
        ], $this->headers());

        $respuesta->assertStatus(422);

        $this->assertSame(1, DemoUpdate::where('demo_id', $demo->id)->count(), 'Se creó una segunda corrida.');

        Queue::assertNothingPushed();
    }

    /**
     * Una corrida ya terminada NO bloquea: si bloqueara, la demo quedaría sin poder actualizarse
     * nunca más después del primer update.
     *
     * @return void
     */
    public function test_una_actualizacion_terminada_no_bloquea_la_siguiente(): void
    {
        Queue::fake();

        $demo    = $this->demo();
        $version = $this->version();

        DemoUpdate::create([
            'demo_id'    => $demo->id,
            'version_id' => $version->id,
            'status'     => 'completado',
        ]);

        $respuesta = $this->postJson('/api/claude/demo-updates', [
            'demo_id'           => $demo->id,
            'version_id'        => $version->id,
            'confirm_demo_name' => self::URL_DEMO,
            'dry_run'           => false,
        ], $this->headers());

        $respuesta->assertStatus(202);

        Queue::assertPushed(RunDemoUpdateJob::class);
    }

    /* ------------------------------------------------------------------------------------------
     | 5. Lectura y autenticación
     |----------------------------------------------------------------------------------------- */

    /**
     * El listado de demos devuelve la URL con la que después hay que confirmar.
     *
     * @return void
     */
    public function test_el_listado_de_demos_trae_la_url_para_confirmar(): void
    {
        $demo = $this->demo();

        $respuesta = $this->getJson('/api/claude/demos', $this->headers());

        $respuesta->assertStatus(200);
        $respuesta->assertJsonFragment(['erp_spa_url' => self::URL_DEMO]);
    }

    /**
     * El detalle mide la salud, que es lo que distingue "no arrancó todavía" de "no hay worker".
     *
     * @return void
     */
    public function test_el_detalle_trae_la_salud_de_la_corrida(): void
    {
        $demo    = $this->demo();
        $version = $this->version();

        $update = DemoUpdate::create([
            'demo_id'    => $demo->id,
            'version_id' => $version->id,
            'status'     => 'pendiente',
        ]);

        $respuesta = $this->getJson('/api/claude/demo-updates/' . $update->id, $this->headers());

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('demo_update.status', 'pendiente');
        $respuesta->assertJsonPath('demo_update.salud.activa', true);
    }

    /**
     * 🔴 Sin la clave, ninguna de las cuatro rutas contesta. El middleware es fail-closed y esto lo
     * fija: es lo único que separa estos endpoints de internet.
     *
     * @return void
     */
    public function test_sin_la_clave_no_contesta_ninguna_ruta(): void
    {
        Queue::fake();

        $demo = $this->demo();

        $this->getJson('/api/claude/demos', ['Accept' => 'application/json'])->assertStatus(401);
        $this->getJson('/api/claude/demo-updates', ['Accept' => 'application/json'])->assertStatus(401);

        $this->postJson('/api/claude/demo-updates', [
            'demo_id'  => $demo->id,
            'dry_run'  => false,
        ], ['Accept' => 'application/json'])->assertStatus(401);

        Queue::assertNothingPushed();
    }
}
