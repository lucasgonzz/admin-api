<?php

namespace Tests\Unit;

use App\Services\DeploymentService;
use ReflectionClass;
use Tests\TestCase;

/**
 * Decision de Lucas, 9/9/2026: *"no quiero que se vuelva a usar mas el VPS para actualizar
 * clientes"*.
 *
 * Hasta ese dia, un release sin su asset hacia que `step_compile_spa()` y `step_upload_api()`
 * cayeran a compilar en el VPS de builds, con una linea de log. El problema es que ese camino no
 * duele en el momento -el upgrade termina bien- y clava un nucleo de la maquina donde viven los 12
 * clientes del VPS durante 5-10 minutos por frente. El 9/9 eso hizo fallar un upgrade por timeout y
 * obligo a cortar otro.
 *
 * Lo que esta clase protege es el DEFAULT, que es la parte facil de perder: alcanza con que alguien
 * invierta la bandera "para que no falle" y el carril entero vuelve al VPS sin que nada lo denuncie,
 * porque el sintoma es un upgrade que termina bien.
 */
class SinArtefactoNoSeCompilaEnElVpsTest extends TestCase
{
    /**
     * Invoca un metodo privado de DeploymentService sobre una instancia sin constructor.
     *
     * Mismo criterio que ConfigJsDelSpaEnElDeploymentTest: estos dos metodos no tocan el upgrade
     * ni el pipeline SSH, asi que construir el service de verdad pediria un upgrade persistido y
     * una credencial de hosting que este calculo no usa.
     *
     * @param string       $metodo Nombre del metodo.
     * @param array<mixed> $args   Argumentos.
     *
     * @return mixed
     */
    private function invocar(string $metodo, array $args = [])
    {
        $reflection = new ReflectionClass(DeploymentService::class);
        $service    = $reflection->newInstanceWithoutConstructor();

        $method = $reflection->getMethod($metodo);
        $method->setAccessible(true);

        return $method->invokeArgs($service, $args);
    }

    /**
     * 🔴 El test que importa: sin nadie tocando nada, no se compila en el VPS.
     *
     * @return void
     */
    public function test_por_defecto_no_se_permite_compilar_en_el_vps()
    {
        $this->assertFalse(
            config('services.deploy.permitir_build_en_vps'),
            'El default de services.deploy.permitir_build_en_vps tiene que ser false: '
            . 'con true, un release sin assets vuelve a compilar en el VPS de produccion.'
        );

        $this->assertFalse($this->invocar('build_en_vps_permitido'));
    }

    /**
     * La salida de emergencia existe y se prende desde el .env, no editando codigo.
     *
     * @return void
     */
    public function test_la_bandera_del_env_habilita_el_vps()
    {
        config(['services.deploy.permitir_build_en_vps' => true]);

        $this->assertTrue($this->invocar('build_en_vps_permitido'));
    }

    /**
     * Un valor de .env llega como string: 'false' tiene que leerse como false, no como "string no
     * vacio". Es el modo de falla clasico de una bandera booleana en un .env.
     *
     * @return void
     */
    public function test_el_string_false_del_env_no_habilita_el_vps()
    {
        config(['services.deploy.permitir_build_en_vps' => filter_var('false', FILTER_VALIDATE_BOOLEAN)]);

        $this->assertFalse($this->invocar('build_en_vps_permitido'));
    }

    /**
     * El mensaje del corte tiene que servir para diagnosticar sin ir a buscar nada: los tres datos
     * con los que se distingue un Action fallido de una version escrita distinta en el commit y en
     * el admin, mas con que reanudar.
     *
     * @return void
     */
    public function test_el_mensaje_nombra_el_asset_el_tag_el_repo_y_como_reanudar()
    {
        $mensaje = $this->invocar('mensaje_sin_artefacto', [
            'compile_spa',
            'empresa-spa-v4.0.23-dist.zip',
            'v4.0.23',
            'empresa-spa',
        ]);

        $this->assertStringContainsString('empresa-spa-v4.0.23-dist.zip', $mensaje);
        $this->assertStringContainsString('v4.0.23', $mensaje);
        $this->assertStringContainsString('empresa-spa', $mensaje);
        $this->assertStringContainsString('resume_from_step=compile_spa', $mensaje);
        $this->assertStringContainsString('DEPLOY_PERMITIR_BUILD_EN_VPS', $mensaje);
    }

    /**
     * El mensaje de la otra etapa nombra SU etapa, no la de la SPA: reanudar por la etapa
     * equivocada volveria a bajar y desplegar el SPA por nada.
     *
     * @return void
     */
    public function test_el_mensaje_de_la_api_reanuda_por_su_propia_etapa()
    {
        $mensaje = $this->invocar('mensaje_sin_artefacto', [
            'upload_api',
            'empresa-api-v4.0.23.zip',
            'v4.0.23',
            'empresa-api',
        ]);

        $this->assertStringContainsString('resume_from_step=upload_api', $mensaje);
        $this->assertStringNotContainsString('resume_from_step=compile_spa', $mensaje);
    }
}
