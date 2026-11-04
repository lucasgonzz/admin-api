<?php

namespace Tests\Feature\Prueba;

use App\Jobs\ArmarPruebaJob;
use App\Jobs\CrearInstanciaDePoolJob;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sin `Queue::fake()`: el despacho pasa por el `DatabaseQueue` de verdad y se mira la fila que queda en la tabla
 * `jobs` (misión `prueba-runner-timeout-y-cola`, 4/11/2026). Es lo que le asegura a los programas de supervisor
 * `queue:work database_prueba --queue=prueba` y `--queue=prueba-pool` que van a encontrar algo, y a `default` que no.
 */
class ColaRealDeLaPruebaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_cada_job_queda_en_la_tabla_jobs_con_su_cola_y_ninguno_en_default(): void
    {
        $antes_default = DB::table('jobs')->where('queue', 'default')->count();

        CrearInstanciaDePoolJob::dispatch(987654);
        ArmarPruebaJob::dispatch(987654);

        $this->assertSame(1, DB::table('jobs')->where('queue', 'prueba-pool')->where('payload', 'like', '%CrearInstanciaDePoolJob%')->count());
        $this->assertSame(1, DB::table('jobs')->where('queue', 'prueba')->where('payload', 'like', '%ArmarPruebaJob%')->count());
        $this->assertSame($antes_default, DB::table('jobs')->where('queue', 'default')->count(), 'El worker de default no ve estos jobs.');
    }
}
