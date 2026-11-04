<?php

namespace Tests\Feature\Prueba;

use App\Jobs\ArmarPruebaJob;
use App\Jobs\CrearInstanciaDePoolJob;
use App\Models\TrialInstance;
use App\Services\Prueba\PruebaPoolService;
use Illuminate\Support\Facades\Queue;

/**
 * Los jobs de la prueba van por su propia conexión y cola (misión `prueba-runner-timeout-y-cola`,
 * 4/11/2026): duran de 25 a 60 minutos y el admin tiene UN worker en `default`.
 */
class ColaDeLaPruebaTest extends BaseDePruebaGratuita
{
    public function test_los_dos_jobs_nacen_en_la_conexion_y_la_cola_de_la_prueba(): void
    {
        foreach ([new CrearInstanciaDePoolJob(1), new ArmarPruebaJob(1)] as $job) {
            $this->assertSame('database_prueba', $job->connection, get_class($job));
            $this->assertSame('prueba', $job->queue, get_class($job));
        }
    }

    public function test_el_despacho_real_deja_el_job_en_esa_cola_sin_que_el_llamador_lo_pida(): void
    {
        $instancia = app(PruebaPoolService::class)->crear_fila('4.3.99', '1.9.99');
        CrearInstanciaDePoolJob::dispatch($instancia->id);
        ArmarPruebaJob::dispatch($instancia->id);

        Queue::assertPushedOn('prueba', CrearInstanciaDePoolJob::class);
        Queue::assertPushedOn('prueba', ArmarPruebaJob::class);
        Queue::assertPushed(CrearInstanciaDePoolJob::class, function ($job) {
            return $job->connection === 'database_prueba';
        });
    }

    public function test_la_conexion_de_la_prueba_comparte_la_tabla_y_tiene_un_retry_after_mayor_que_ambos_timeouts(): void
    {
        $conexion = config('queue.connections.database_prueba');

        $this->assertNotNull($conexion);
        $this->assertSame('database', $conexion['driver']);
        $this->assertSame(config('queue.connections.database.table'), $conexion['table'], 'Misma tabla `jobs`.');
        $this->assertSame('prueba', $conexion['queue']);

        foreach ([CrearInstanciaDePoolJob::class, ArmarPruebaJob::class] as $clase) {
            $timeout = (new \ReflectionClass($clase))->getDefaultProperties()['timeout'];
            $this->assertGreaterThan($timeout, (int) $conexion['retry_after'], $clase . ': retry_after tiene que superar el timeout o la cola lo reencola vivo.');
        }
    }

    public function test_la_conexion_database_no_se_toca(): void
    {
        /* RobustezDelDeploymentDesatendidoTest depende de este número. */
        $this->assertSame(2400, (int) config('queue.connections.database.retry_after'));
    }

    public function test_mantener_pool_sigue_encolando_en_la_cola_de_la_prueba(): void
    {
        $this->artisan('pruebas:mantener-pool')->assertExitCode(0);

        Queue::assertPushedOn('prueba', CrearInstanciaDePoolJob::class);
        $this->assertGreaterThan(0, TrialInstance::count());
    }
}
