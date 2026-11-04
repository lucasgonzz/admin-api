<?php

namespace Tests\Feature\Prueba;

use App\Services\Prueba\PruebaVpsService;
use App\Services\RemoteCommandRunner;
use Tests\Fakes\RemoteCommandRunnerFake;

/**
 * Los scripts del pool corren con timeout alto y exit obligatorio (misión `prueba-runner-timeout-y-cola`,
 * 4/11/2026), y lo corto de `PruebaVpsService` NO cambia. Acá `PruebaVpsService` es el REAL (no el fake de la
 * base): lo falso es el runner SSH.
 */
class ScriptsLargosDelPoolTest extends BaseDePruebaGratuita
{
    /** @var RemoteCommandRunnerFake */
    private $runner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runner = new RemoteCommandRunnerFake($this->crear_credencial_vps());
        $this->app->bind(RemoteCommandRunner::class, function () {
            return $this->runner;
        });
        $this->app->instance(PruebaVpsService::class, new PruebaVpsService());
    }

    /** @return array<string, array> nombre => [script, llamada al servicio]. */
    public function scripts_largos(): array
    {
        return [
            'pool-plantilla'  => ['pool-plantilla.sh', function (PruebaVpsService $v) { return $v->pool_plantilla('4.3.29', '1.3.1'); }],
            'pool-crear'      => ['pool-crear.sh', function (PruebaVpsService $v) { return $v->pool_crear('prueba-01', '4.3.29', '1.3.1', 900100); }],
            'pool-configurar' => ['pool-configurar.sh', function (PruebaVpsService $v) { return $v->pool_configurar('prueba-01'); }],
            'prueba-ssl'      => ['prueba-ssl.sh', function (PruebaVpsService $v) { return $v->prueba_ssl('prueba-01', 'ferre-demo'); }],
            'prueba-asignar'  => ['prueba-asignar.sh', function (PruebaVpsService $v) { return $v->prueba_asignar('prueba-01', 'ferre-demo', 900100); }],
            'prueba-borrar'   => ['prueba-borrar.sh', function (PruebaVpsService $v) { return $v->prueba_borrar('prueba-01', 'ferre-demo'); }],
        ];
    }

    /**
     * @dataProvider scripts_largos
     */
    public function test_cada_script_largo_pide_el_tope_alto_y_exit_obligatorio(string $script, \Closure $llamar): void
    {
        $this->runner->responder($script, "progreso\n" . json_encode(['ok' => true]) . "\n", 0);

        $json = $llamar(app(PruebaVpsService::class));

        $this->assertTrue($json['ok']);
        $this->assertCount(1, $this->runner->crudos_con($script));
        $this->assertSame([PruebaVpsService::TIMEOUT_DE_SCRIPT_SEGUNDOS], $this->runner->timeouts);
        $this->assertGreaterThan(3600, PruebaVpsService::TIMEOUT_DE_SCRIPT_SEGUNDOS, 'Por encima del job del pool (3600).');
    }

    public function test_un_script_cortado_sin_exit_status_es_error_y_no_un_exito_parcial(): void
    {
        /* Lo que pasó en producción: phpseclib cortó a los 10 s y devolvió la salida a medias, exit false. */
        $this->runner->responder('pool-plantilla.sh', "descargando empresa 4.3.29...\n", false);

        try {
            app(PruebaVpsService::class)->pool_plantilla('4.3.29', '1.3.1');
            $this->fail('Un script cortado no puede darse por bueno.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('pool-plantilla.sh falló', $e->getMessage());
            $this->assertStringContainsString('no devolvió exit status', $e->getMessage());
        }
    }

    public function test_lo_corto_de_pruebavpsservice_no_cambia_su_timeout(): void
    {
        $this->runner->responder('ARCHIVO_OK', 'ARCHIVO_OK', 0);

        app(PruebaVpsService::class)->escribir_archivo('/home/x/archivo.txt', 'contenido', 'usuariox');

        $this->assertSame([null], $this->runner->timeouts, 'Escribir un archivo sigue con el tiempo de la sesión.');
    }
}
