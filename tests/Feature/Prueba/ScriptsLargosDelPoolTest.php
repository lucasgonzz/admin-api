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
        $this->assertSame([PruebaVpsService::timeout_del_script($script)], $this->runner->timeouts);
        $this->assertSame([true], $this->runner->exits_obligatorios, 'Un script cortado no puede pasar por éxito.');
        $this->assertGreaterThan(3600, PruebaVpsService::timeout_del_script($script), 'Los largos van por encima del job del pool (3600).');
    }

    /**
     * @dataProvider scripts_cortos
     */
    public function test_los_scripts_cortos_tienen_un_tope_propio_y_tambien_exit_obligatorio(string $script, \Closure $llamar, int $tope): void
    {
        $this->runner->responder($script, json_encode(['ok' => true]) . "\n", 0);

        $llamar(app(PruebaVpsService::class));

        $this->assertSame([$tope], $this->runner->timeouts);
        $this->assertSame([true], $this->runner->exits_obligatorios);
        $this->assertLessThan(PruebaVpsService::TIMEOUT_DE_SCRIPT_SEGUNDOS, $tope, 'Un VPS colgado no tiene que tomar una hora a quien llama.');
    }

    /** @return array<string, array> */
    public function scripts_cortos(): array
    {
        return [
            'prueba-estado'    => ['prueba-estado.sh', function (PruebaVpsService $v) { return $v->prueba_estado('prueba-01'); }, 120],
            'prueba-dormir'    => ['prueba-dormir.sh', function (PruebaVpsService $v) { return $v->prueba_dormir('prueba-01'); }, 300],
            'prueba-despertar' => ['prueba-despertar.sh', function (PruebaVpsService $v) { return $v->prueba_despertar('prueba-01'); }, 300],
            'prueba-borrar'    => ['prueba-borrar.sh', function (PruebaVpsService $v) { return $v->prueba_borrar('prueba-01', 'ferre-demo'); }, 900],
        ];
    }

    /**
     * Una instancia del pool que nunca se asignó no tiene alias: `PruebaCicloService::borrar()` le pasa el nombre
     * como slug y el script lo acepta. La validación del servicio real rechazaba ese caso y la instancia quedaba
     * `fallida`, frenando el armado del pool (renovación a 4.3.30, 5/11/2026).
     */
    public function test_una_instancia_sin_asignar_se_borra_con_su_nombre_como_slug(): void
    {
        $this->runner->responder('prueba-borrar.sh', json_encode(['ok' => true, 'respaldo' => '/root/respaldos-pruebas/prueba-02']) . "
", 0);

        $resultado = app(PruebaVpsService::class)->prueba_borrar('prueba-02', 'prueba-02');

        $this->assertSame('/root/respaldos-pruebas/prueba-02', $resultado['respaldo']);
    }

    public function test_el_nombre_de_otra_instancia_no_vale_como_slug_para_borrar(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(PruebaVpsService::class)->prueba_borrar('prueba-02', 'prueba-03');
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
        $this->assertSame([false], $this->runner->exits_obligatorios);
    }
}
