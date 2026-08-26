<?php

namespace Tests\Unit;

use App\Models\Demo;
use App\Services\DemoUpdateService;
use ReflectionClass;
use Tests\TestCase;

/**
 * El .env con el que se compila el SPA de una demo tiene que ser el MISMO que el de cualquier
 * cliente real, más lo específico de esa demo.
 *
 * Hasta el 25/8/2026 no lo era: `build_demo_spa_env_content()` armaba a mano un array de cinco
 * variables y nunca mergeaba `config('services.deploy.spa_build_env')` —que es lo que sí hacen
 * `InstallationService` y `DeploymentService`—, así que el SPA de la demo se compilaba sin
 * `VUE_APP_HAS_EXTRA_CONFIG` y sin otras diez variables. Síntoma visible: "Configuración online"
 * no aparecía en la barra de navegación de la demo.
 *
 * Se prueba por reflexión porque el método es privado y no depende del pipeline SSH: construir el
 * service de verdad pediría un DemoUpdate persistido y una credencial de hosting que este cálculo
 * no usa.
 */
class DemoUpdateSpaBuildEnvTest extends TestCase
{
    /**
     * Genera el .env de una demo de prueba y lo devuelve ya parseado a clave => valor.
     *
     * @return array<string, string>
     */
    private function env_de_la_demo(): array
    {
        $demo                = new Demo();
        $demo->slug          = 'demo3';
        $demo->erp_api_url   = 'https://demo3.comerciocity.com/api';
        $demo->erp_spa_url   = 'https://demo3.comerciocity.com';

        $reflection = new ReflectionClass(DemoUpdateService::class);
        $service    = $reflection->newInstanceWithoutConstructor();

        $propiedad_demo = $reflection->getProperty('demo');
        $propiedad_demo->setAccessible(true);
        $propiedad_demo->setValue($service, $demo);

        $metodo = $reflection->getMethod('build_demo_spa_env_content');
        $metodo->setAccessible(true);

        return $this->parsear_env((string) $metodo->invoke($service));
    }

    /**
     * Parseo mínimo del formato que escribe el service (una línea `CLAVE=valor` por variable,
     * con comillas dobles cuando el valor tiene espacios).
     *
     * @param string $contenido Contenido del archivo .env.
     *
     * @return array<string, string>
     */
    private function parsear_env(string $contenido): array
    {
        $variables = [];
        foreach (explode("\n", $contenido) as $linea) {
            if (trim($linea) === '') {
                continue;
            }
            $partes = explode('=', $linea, 2);
            $valor  = isset($partes[1]) ? $partes[1] : '';
            if (strlen($valor) >= 2 && substr($valor, 0, 1) === '"' && substr($valor, -1) === '"') {
                $valor = str_replace('\\"', '"', substr($valor, 1, -1));
            }
            $variables[$partes[0]] = $valor;
        }

        return $variables;
    }

    /**
     * La regresión concreta: las once variables fijas que tiene cualquier cliente real ahora
     * también están en el build de la demo, con el valor que dice `config/services.php`.
     *
     * `VUE_APP_HAS_EXTRA_CONFIG` es la que destraba "Configuración online" en la barra de
     * navegación (`empresa-spa`: `has_extra_config` gatea `ConfigurationDropdown.vue`).
     *
     * @return void
     */
    public function test_el_env_de_la_demo_trae_las_variables_fijas_de_un_cliente(): void
    {
        $variables = $this->env_de_la_demo();

        $esperadas = config('services.deploy.spa_build_env');
        $this->assertIsArray($esperadas);
        $this->assertArrayHasKey('VUE_APP_HAS_EXTRA_CONFIG', $esperadas);

        foreach ($esperadas as $clave => $valor) {
            $this->assertArrayHasKey(
                $clave,
                $variables,
                'El .env de la demo se compila sin ' . $clave . ', que cualquier cliente real sí tiene.'
            );
            $this->assertSame(trim((string) $valor), $variables[$clave]);
        }
    }

    /**
     * Lo específico de la demo se sigue escribiendo, y con el valor calculado en runtime.
     *
     * @return void
     */
    public function test_el_env_de_la_demo_sigue_trayendo_lo_suyo(): void
    {
        $variables = $this->env_de_la_demo();

        $this->assertArrayHasKey('VUE_APP_API_URL', $variables);
        $this->assertArrayHasKey('VUE_APP_APP_URL', $variables);
        $this->assertNotSame('', $variables['VUE_APP_API_URL']);
        $this->assertSame('https://demo3.comerciocity.com', $variables['VUE_APP_APP_URL']);
        $this->assertNotFalse(strpos($variables['VUE_APP_API_URL'], 'demo3.comerciocity.com'));
        $this->assertSame(
            trim((string) config('services.deploy.spa_pusher_cluster')),
            $variables['VUE_APP_PUSHER_CLUSTER']
        );
    }

    /**
     * 🔴 El orden del merge: el array fijo aporta defaults, y lo que se calcula para ESTA demo
     * lo pisa. Hoy no hay colisión de claves, así que se planta una a propósito para fijar la
     * precedencia — si alguien invierte el orden del merge, este test se pone rojo.
     *
     * @return void
     */
    public function test_lo_especifico_de_la_demo_le_gana_al_array_fijo(): void
    {
        config()->set('services.deploy.spa_build_env', [
            'VUE_APP_API_URL'         => 'https://no-tiene-que-ganar.example',
            'VUE_APP_APP_URL'         => 'https://tampoco-esta.example',
            'VUE_APP_PUSHER_CLUSTER'  => 'us-que-no-va',
            'VUE_APP_HAS_EXTRA_CONFIG' => 'true',
        ]);

        $variables = $this->env_de_la_demo();

        $this->assertNotSame('https://no-tiene-que-ganar.example', $variables['VUE_APP_API_URL']);
        $this->assertSame('https://demo3.comerciocity.com', $variables['VUE_APP_APP_URL']);
        $this->assertNotSame('us-que-no-va', $variables['VUE_APP_PUSHER_CLUSTER']);

        // Y la que no colisiona sigue llegando desde el array fijo.
        $this->assertSame('true', $variables['VUE_APP_HAS_EXTRA_CONFIG']);
    }

    /**
     * `VUE_APP_ATTEMPT_TEXT` es la única variable fija con espacios en su default. Sin comillas,
     * dotenv/vue-cli se comerían todo lo que va después del primer espacio.
     *
     * @return void
     */
    public function test_un_valor_con_espacios_queda_entrecomillado(): void
    {
        $demo              = new Demo();
        $demo->slug        = 'demo3';
        $demo->erp_api_url = 'https://demo3.comerciocity.com/api';
        $demo->erp_spa_url = 'https://demo3.comerciocity.com';

        $reflection = new ReflectionClass(DemoUpdateService::class);
        $service    = $reflection->newInstanceWithoutConstructor();

        $propiedad_demo = $reflection->getProperty('demo');
        $propiedad_demo->setAccessible(true);
        $propiedad_demo->setValue($service, $demo);

        $metodo = $reflection->getMethod('build_demo_spa_env_content');
        $metodo->setAccessible(true);
        $contenido = (string) $metodo->invoke($service);

        $this->assertNotFalse(
            strpos($contenido, 'VUE_APP_ATTEMPT_TEXT="numero de documento"'),
            'El valor con espacios tiene que ir entre comillas dobles.'
        );
    }
}
