<?php

namespace Tests\Unit;

use App\Models\Demo;
use App\Services\Concerns\ArtefactosDeRelease;
use App\Services\DemoInstallationService;
use App\Services\DemoPathResolver;
use App\Services\DemoUpdateService;
use App\Services\InstallationService;
use App\Services\SpaRuntimeConfig;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Los tres pipelines que INSTALAN dejaron de compilar en el VPS de builds (misión
 * `instalar-sin-el-vps`, 10/9/2026).
 *
 * La misión hermana del 9/9 sacó del VPS las ACTUALIZACIONES de clientes. Lo que seguía clavando un
 * núcleo de la máquina donde viven los 12 clientes durante 5-10 minutos —`npm ci` + `npm run build`—
 * eran las instalaciones nuevas y las demos: `InstallationService`, `DemoInstallationService` y
 * `DemoUpdateService`.
 *
 * Lo que fija esta clase es el DEFAULT, que es la parte fácil de perder: alcanza con que alguien
 * saque el trait "porque el artefacto no estaba" y el carril entero vuelve al VPS sin que nada lo
 * denuncie, porque el síntoma es una instalación que termina bien.
 */
class InstalarSinElVpsTest extends TestCase
{
    /** Los tres pipelines de esta misión. */
    const SERVICIOS = [
        InstallationService::class,
        DemoInstallationService::class,
        DemoUpdateService::class,
    ];

    /** URL de API de ejemplo, con el `/public` del hosting compartido. */
    const API_URL = 'https://api-x.comerciocity.com/public';

    /** URL del SPA de ejemplo. */
    const SPA_URL = 'https://x.comerciocity.com';

    /**
     * Invoca un método privado de un service sobre una instancia sin constructor.
     *
     * Mismo criterio que ConfigJsDelSpaEnElDeploymentTest: estos métodos no tocan el pipeline SSH,
     * así que construir el service de verdad pediría una instalación persistida y una credencial de
     * hosting que este cálculo no usa.
     *
     * @param string       $clase  Clase del service.
     * @param string       $metodo Nombre del método.
     * @param array<mixed> $args   Argumentos.
     * @param array<string, mixed> $props Propiedades a sembrar antes de invocar.
     *
     * @return mixed
     */
    private function invocar(string $clase, string $metodo, array $args = [], array $props = [])
    {
        $reflection = new ReflectionClass($clase);
        $service    = $reflection->newInstanceWithoutConstructor();

        foreach ($props as $nombre => $valor) {
            $prop = $reflection->getProperty($nombre);
            $prop->setAccessible(true);
            $prop->setValue($service, $valor);
        }

        $method = $reflection->getMethod($metodo);
        $method->setAccessible(true);

        return $method->invokeArgs($service, $args);
    }

    /**
     * Código fuente de un método, para las guardas de orden.
     *
     * @param string $clase  Clase del service.
     * @param string $metodo Nombre del método.
     *
     * @return string
     */
    private function fuente_de(string $clase, string $metodo): string
    {
        $reflection = new ReflectionMethod($clase, $metodo);
        $lineas     = file($reflection->getFileName());

        return implode('', array_slice(
            $lineas,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }

    /**
     * Una demo de mentira, sin persistir: alcanza para las dos URLs.
     *
     * @return Demo
     */
    private function demo_de_mentira(): Demo
    {
        return new Demo([
            'slug'         => 'demo9',
            'erp_api_url'  => self::API_URL,
            'erp_spa_url'  => self::SPA_URL,
            'hosting_type' => 'shared_hosting',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // El carril: los tres bajan artefactos
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 El test que importa: los tres pipelines usan el trait de artefactos.
     *
     * @return void
     */
    public function test_los_tres_pipelines_usan_el_trait_de_artefactos()
    {
        foreach (self::SERVICIOS as $clase) {
            $this->assertContains(
                ArtefactosDeRelease::class,
                array_keys((new ReflectionClass($clase))->getTraits()),
                $clase . ' dejó de usar ArtefactosDeRelease: ese pipeline volvió a compilar en el VPS.'
            );
        }
    }

    /**
     * Por defecto no se compila en el VPS, y la salida de emergencia se prende desde el `.env`.
     *
     * @return void
     */
    public function test_por_defecto_no_se_compila_en_el_vps()
    {
        $this->assertFalse(
            config('services.deploy.permitir_build_en_vps'),
            'El default de services.deploy.permitir_build_en_vps tiene que ser false.'
        );

        foreach (self::SERVICIOS as $clase) {
            $this->assertFalse(
                $this->invocar($clase, 'artefacto_build_en_vps_permitido'),
                $clase . ' permite compilar en el VPS con la config por defecto.'
            );
        }

        config(['services.deploy.permitir_build_en_vps' => true]);
        $this->assertTrue($this->invocar(InstallationService::class, 'artefacto_build_en_vps_permitido'));
        config(['services.deploy.permitir_build_en_vps' => false]);
    }

    /**
     * 🔴 El mensaje del freno nombra el archivo, el tag y el repo.
     *
     * Los cuatro motivos que esconde un 404 de GitHub —release sin asset, Action que falló, versión
     * escrita distinto en el commit que en el admin, token sin acceso— no se distinguen sin esos
     * tres datos, y ninguno se arregla compilando en producción.
     *
     * @return void
     */
    public function test_el_freno_nombra_archivo_tag_y_repo()
    {
        $mensaje = $this->invocar(
            InstallationService::class,
            'artefacto_mensaje_sin_artefacto',
            ['empresa-spa-v4.0.23-dist.zip', 'v4.0.23', 'empresa-spa']
        );

        $this->assertStringContainsString('empresa-spa-v4.0.23-dist.zip', $mensaje);
        $this->assertStringContainsString('v4.0.23', $mensaje);
        $this->assertStringContainsString('empresa-spa', $mensaje);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // config.js: el contrato con empresa-spa
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 Los valores viajan SIEMPRE como string.
     *
     * `src/runtime_config.js` de `empresa-spa` compara con `'true'` / `'false'`: un booleano JSON
     * rompe el gate en silencio y la variable queda como si no estuviera.
     *
     * @return void
     */
    public function test_ninguna_variable_viaja_como_booleano()
    {
        $conjuntos = [
            'InstallationService' => $this->invocar(
                InstallationService::class,
                'build_spa_env_vars',
                [self::API_URL, self::SPA_URL]
            ),
            'DemoInstallationService' => $this->invocar(
                DemoInstallationService::class,
                'build_demo_spa_env_vars',
                [],
                ['demo' => $this->demo_de_mentira(), 'path_resolver' => new DemoPathResolver()]
            ),
            'DemoUpdateService' => $this->invocar(
                DemoUpdateService::class,
                'build_demo_spa_env_vars',
                [],
                ['demo' => $this->demo_de_mentira()]
            ),
        ];

        foreach ($conjuntos as $quien => $vars) {
            $this->assertNotEmpty($vars, $quien . ' no armó ninguna variable para el config.js.');

            foreach ($vars as $clave => $valor) {
                $this->assertIsString(
                    $valor,
                    $quien . ' manda ' . $clave . ' como ' . gettype($valor)
                    . ': el config.js las necesita todas como string.'
                );
            }

            $this->assertArrayHasKey('VUE_APP_API_URL', $vars, $quien . ' no manda VUE_APP_API_URL.');
            $this->assertArrayHasKey('VUE_APP_APP_URL', $vars, $quien . ' no manda VUE_APP_APP_URL.');
        }
    }

    /**
     * El `config.js` de una instalación es, byte a byte, lo que rinde `SpaRuntimeConfig::render()`.
     *
     * @return void
     */
    public function test_el_config_js_de_la_instalacion_es_el_de_spa_runtime_config()
    {
        $vars = $this->invocar(
            InstallationService::class,
            'build_spa_env_vars',
            [self::API_URL, self::SPA_URL]
        );

        $esperado = SpaRuntimeConfig::render($vars);

        $this->assertStringContainsString(self::API_URL, $esperado);
        $this->assertStringContainsString(self::SPA_URL, $esperado);
        $this->assertStringStartsWith('window.__CC_CONFIG__ = {', $esperado);
        $this->assertStringEndsWith("};\n", $esperado);
    }

    /**
     * 🔴 El `config.js` se escribe DESPUÉS del deploy shell que vacía el directorio del SPA.
     *
     * Ese shell hace `cd "$SPA_DIR"` y después `find . -mindepth 1 -delete`. Escrito antes, el
     * `config.js` se lo lleva puesto — y el frente queda arriba sin saber a qué API pegarle, que es
     * un síntoma que no se parece en nada a la causa.
     *
     * Se mide sobre el código fuente de la etapa: es una guarda de ORDEN, y no hay forma de
     * observarla sin un servidor del otro lado.
     *
     * @return void
     */
    public function test_el_config_js_va_despues_del_deploy_shell()
    {
        foreach (self::SERVICIOS as $clase) {
            $fuente = $this->fuente_de($clase, 'step_upload_spa');

            $deploy_shell = strpos($fuente, 'build_spa_hosting_deploy_shell');
            $config_js    = strpos($fuente, 'artefacto_escribir_config_js');

            $this->assertNotFalse(
                $deploy_shell,
                $clase . '::step_upload_spa() ya no arma el deploy shell: revisá esta guarda.'
            );
            $this->assertNotFalse(
                $config_js,
                $clase . '::step_upload_spa() no escribe config.js: con el bundle del release el '
                . 'frente queda sin saber a qué API pegarle.'
            );
            $this->assertLessThan(
                $config_js,
                $deploy_shell,
                $clase . '::step_upload_spa() escribe el config.js ANTES del deploy shell: '
                . 'el `find . -mindepth 1 -delete` se lo lleva puesto.'
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Las guardas de orden que quedaron adentro de las etapas
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 En las demos, `compiled_api_url` se sigue asignando por las DOS vías.
     *
     * `build_demo_spa_env_vars()` no sólo arma las variables: adentro asigna `compiled_api_url`, que
     * `step_verify()` / `step_verify_demo()` leen después (y en DemoUpdateService, si queda vacía,
     * TIRA). Con el artefacto no hay ningún `.env` que escribir, así que es fácil borrar esa llamada
     * por "no se usa" y romper la verificación de la demo.
     *
     * @return void
     */
    public function test_las_demos_asignan_compiled_api_url_por_las_dos_vias()
    {
        foreach ([DemoInstallationService::class, DemoUpdateService::class] as $clase) {
            $fuente = $this->fuente_de($clase, 'step_compile_spa');

            $llamada  = strpos($fuente, 'build_demo_spa_env_vars()');
            $artefacto = strpos($fuente, 'artefacto_bajar_dist_spa');

            $this->assertNotFalse(
                $llamada,
                $clase . '::step_compile_spa() dejó de llamar a build_demo_spa_env_vars(): '
                . 'compiled_api_url queda vacía y la verificación de la demo se rompe.'
            );
            $this->assertNotFalse($artefacto, $clase . '::step_compile_spa() no baja el artefacto del SPA.');
            $this->assertLessThan(
                $artefacto,
                $llamada,
                $clase . '::step_compile_spa() asigna compiled_api_url DESPUÉS de bajar el artefacto: '
                . 'por la vía del artefacto la etapa termina antes y nunca se asigna.'
            );
        }
    }

    /**
     * 🔴 El zip local de un intento anterior se borra ANTES de decidir la vía.
     *
     * `step_upload_spa()` toma "hay zip local" como "vino del artefacto". Un zip viejo que sobrevive
     * a un intento fallido se sube como si fuera el nuevo — y el cliente queda con el frente de otra
     * versión, sin un solo error en el log.
     *
     * @return void
     */
    public function test_el_zip_viejo_se_borra_antes_de_decidir_la_via()
    {
        foreach (self::SERVICIOS as $clase) {
            $fuente = $this->fuente_de($clase, 'step_compile_spa');

            $borrado   = strpos($fuente, 'unlink($local_zip)');
            if ($borrado === false) {
                $borrado = strpos($fuente, 'cleanup_local_zip($local_zip)');
            }
            $artefacto = strpos($fuente, 'artefacto_bajar_dist_spa');

            $this->assertNotFalse(
                $borrado,
                $clase . '::step_compile_spa() no borra el zip local previo: uno viejo pasa por nuevo.'
            );
            $this->assertLessThan(
                $artefacto,
                $borrado,
                $clase . '::step_compile_spa() borra el zip local DESPUÉS de intentar bajar el '
                . 'artefacto: si la bajada falla, el zip viejo sobrevive y se sube como nuevo.'
            );
        }
    }
}
