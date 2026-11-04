<?php

namespace Tests\Feature\Prueba;

use App\Console\Commands\PruebasPlantillaCommand;
use Illuminate\Support\Facades\Artisan;

/**
 * `pruebas:plantilla` (misión `prueba-runner-timeout-y-cola`, 4/11/2026): la opción de la versión de empresa
 * se llamaba `--version`, que es una opción GLOBAL de artisan, y el comando ni cargaba («An option named
 * "version" already exists»), ni con `--help`.
 */
class PruebasPlantillaCommandTest extends BaseDePruebaGratuita
{
    public function test_el_comando_carga_y_no_declara_opciones_que_choquen_con_las_globales_de_artisan(): void
    {
        $comando    = new PruebasPlantillaCommand();
        $definicion = $comando->getDefinition();

        $this->assertTrue($definicion->hasOption('empresa'));
        $this->assertTrue($definicion->hasOption('tienda'));
        $this->assertFalse($definicion->hasOption('version'), '--version es global de artisan.');

        /* Armar la definición completa (con las globales de la aplicación) es lo que reventaba. */
        $this->assertSame(0, Artisan::call('pruebas:plantilla', ['--help' => true]));
        $this->assertStringContainsString('--empresa', Artisan::output());
    }

    public function test_pasa_las_versiones_pedidas_al_servicio(): void
    {
        $this->artisan('pruebas:plantilla', ['--empresa' => '4.3.29', '--tienda' => '1.3.1'])->assertExitCode(0);

        $comandos = $this->vps->comandos_de('pool-plantilla.sh');
        $this->assertCount(1, $comandos);
        $this->assertStringContainsString("pool-plantilla.sh '4.3.29' '1.3.1'", $comandos[0]);
    }

    public function test_sin_opciones_usa_la_ultima_publicada_de_cada_una(): void
    {
        $this->artisan('pruebas:plantilla')->assertExitCode(0);

        $comandos = $this->vps->comandos_de('pool-plantilla.sh');
        $this->assertCount(1, $comandos);
        $this->assertStringContainsString("pool-plantilla.sh '4.3.99' '1.9.99'", $comandos[0]);
    }

    public function test_el_error_del_servicio_da_exit_1(): void
    {
        $this->vps->fallar('pool-plantilla.sh', 'composer no pudo');

        $this->artisan('pruebas:plantilla', ['--empresa' => '4.3.29', '--tienda' => '1.3.1'])->assertExitCode(1);
    }
}
