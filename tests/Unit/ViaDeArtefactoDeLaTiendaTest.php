<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Services\Concerns\ArtefactosDeRelease;
use App\Services\Concerns\ArtefactosDeReleaseDeTienda;
use App\Services\EcommerceDeploymentService;
use App\Services\EcommerceInstallationService;
use App\Services\EcommerceReleaseArtifacts;
use App\Services\SpaRuntimeConfig;
use ReflectionClass;
use Tests\TestCase;

/**
 * Las piezas sin SSH de la vía de artefacto del pipeline de ecommerce (misión cruzada
 * `versiones-tienda`, 1/10/2026).
 *
 * Lo que se protege:
 *  1. 🔴 Que `config.js` (vía de artefacto) y el `.env` del build (vía vieja) salgan de la MISMA
 *     fuente: si fueran dos cálculos, una tienda podría quedar apuntando a otra API según por dónde
 *     se desplegó, sin ningún error.
 *  2. Que `config.js` lleve exactamente las once claves del contrato (sección 1.3 del plan), todas
 *     string, y sin `VUE_APP_ICONS_VERSION`.
 *  3. Que el `unzip` de la API excluya `public/*`, `storage/*` y `.env` en una actualización y
 *     descomprima todo en una instalación.
 *  4. Que los nombres de repo, tag y asset sean los del contrato.
 *  5. Que el pipeline use los dos traits y que el default siga sin permitir compilar en el VPS.
 */
class ViaDeArtefactoDeLaTiendaTest extends TestCase
{
    /** Las once claves de config.js del contrato (sección 1.3). */
    const CLAVES_DEL_CONTRATO = [
        'VUE_APP_API_URL',
        'VUE_APP_COMMERCE_ID',
        'VUE_APP_APP_URL',
        'VUE_APP_SITE_NAME',
        'VUE_APP_SITE_DESCRIPTION',
        'VUE_APP_SITE_IMAGE',
        'VUE_APP_SITE_URL',
        'VUE_APP_PUSHER_KEY',
        'VUE_APP_PUSHER_CLUSTER',
        'VUE_APP_GOOGLE_MAPS_API_KEY',
        'VUE_APP_FIREBASE_API_KEY',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.deploy.spa_pusher_key'     => 'key-central',
            'services.deploy.spa_pusher_cluster' => 'sa1',
            'services.deploy.tienda_build_env'   => [
                'VUE_APP_GOOGLE_MAPS_API_KEY' => 'maps-key',
                'VUE_APP_FIREBASE_API_KEY'    => 'firebase-key',
            ],
        ]);
    }

    /**
     * Servicio sin constructor (no hace falta corrida ni SSH para estos cálculos), con un cliente
     * dueño cargado.
     *
     * @return array{0: EcommerceInstallationService, 1: ReflectionClass}
     */
    private function servicio(): array
    {
        $reflection = new ReflectionClass(EcommerceInstallationService::class);
        $service    = $reflection->newInstanceWithoutConstructor();

        $client          = new Client();
        $client->user_id = 4200;

        $prop = $reflection->getProperty('client');
        $prop->setAccessible(true);
        $prop->setValue($service, $client);

        return [$service, $reflection];
    }

    /**
     * Invoca un método protegido o privado.
     *
     * @param string       $metodo
     * @param array<mixed> $args
     *
     * @return mixed
     */
    private function invocar(string $metodo, array $args = [])
    {
        list($service, $reflection) = $this->servicio();

        $method = $reflection->getMethod($metodo);
        $method->setAccessible(true);

        return $method->invokeArgs($service, $args);
    }

    /**
     * Parsea el `.env` que arma la vía vieja (KEY=valor o KEY="valor con espacios").
     *
     * @param string $contenido
     *
     * @return array<string, string>
     */
    private function parsear_env(string $contenido): array
    {
        $vars = [];
        foreach (explode("\n", $contenido) as $linea) {
            $pos = strpos($linea, '=');
            if ($pos === false) {
                continue;
            }
            $valor = substr($linea, $pos + 1);
            if (strlen($valor) >= 2 && $valor[0] === '"' && substr($valor, -1) === '"') {
                $valor = str_replace(['\\"', '\\\\'], ['"', '\\'], substr($valor, 1, -1));
            }
            $vars[substr($linea, 0, $pos)] = $valor;
        }

        return $vars;
    }

    /** Argumentos de ejemplo para las dos vías. */
    private function args(): array
    {
        return [
            'https://api.mitienda.com.ar/public',
            'https://mitienda.com.ar/',
            'Ferretería "El Tornillo"',
            "Todo para la obra\ncon envío",
        ];
    }

    /* ------------------------------------------------------------------------------------------ */

    /** 🔴 El `.env` de la vía vieja es exactamente el render de `build_spa_env_vars()`. */
    public function test_el_env_de_la_via_vieja_sale_de_build_spa_env_vars(): void
    {
        $vars = $this->invocar('build_spa_env_vars', $this->args());
        $env  = $this->parsear_env((string) $this->invocar('build_spa_env_file_content', $this->args()));

        // VUE_APP_ICONS_VERSION es date('YmdHis'): puede cambiar de segundo entre las dos llamadas.
        unset($vars['VUE_APP_ICONS_VERSION'], $env['VUE_APP_ICONS_VERSION']);

        $this->assertSame($vars, $env);
    }

    /**
     * 🔴 config.js = las mismas variables y valores que el `.env`, menos VUE_APP_ICONS_VERSION; y son
     * exactamente las once del contrato, todas string.
     */
    public function test_config_js_y_el_env_salen_de_la_misma_fuente_y_cumplen_el_contrato(): void
    {
        $env_vars    = $this->invocar('build_spa_env_vars', $this->args());
        $config_vars = $this->invocar('build_spa_runtime_config_vars', [$env_vars]);

        $esperadas = $env_vars;
        unset($esperadas['VUE_APP_ICONS_VERSION']);

        $this->assertSame($esperadas, $config_vars, 'config.js no tiene los mismos valores que el .env.');
        $this->assertArrayNotHasKey('VUE_APP_ICONS_VERSION', $config_vars);
        $this->assertEqualsCanonicalizing(self::CLAVES_DEL_CONTRATO, array_keys($config_vars));

        foreach ($config_vars as $clave => $valor) {
            $this->assertIsString($valor, "{$clave} no viaja como string.");
        }

        $this->assertSame('https://api.mitienda.com.ar/public', $config_vars['VUE_APP_API_URL']);
        $this->assertSame('4200', $config_vars['VUE_APP_COMMERCE_ID']);
        $this->assertSame('https://mitienda.com.ar', $config_vars['VUE_APP_SITE_URL']);
        $this->assertSame('https://mitienda.com.ar/img/og-image.png', $config_vars['VUE_APP_SITE_IMAGE']);
        $this->assertSame('Todo para la obra con envío', $config_vars['VUE_APP_SITE_DESCRIPTION']);

        $render = SpaRuntimeConfig::render($config_vars);
        $this->assertStringNotContainsString(':true', $render);
        $this->assertStringNotContainsString(':false', $render);
    }

    /** En una actualización el unzip deja afuera public/, storage/ y .env, y tolera el exit 11. */
    public function test_el_unzip_de_la_actualizacion_no_toca_public_storage_ni_env(): void
    {
        $comando = $this->invocar('tienda_comando_unzip_api', ['domains/x.com.ar/public_html/api', 'tienda_api_release_u.zip', true]);

        $this->assertStringStartsWith("cd 'domains/x.com.ar/public_html/api' && ", $comando);
        $this->assertStringContainsString("unzip -o 'tienda_api_release_u.zip' -x 'public/*' 'storage/*' '.env'", $comando);
        $this->assertStringContainsString('-ne 11', $comando);
        $this->assertStringContainsString("rm -f 'tienda_api_release_u.zip'", $comando);
    }

    /** En una instalación se descomprime todo: public/ y storage/ todavía no existen en el hosting. */
    public function test_el_unzip_de_la_instalacion_descomprime_todo(): void
    {
        $comando = $this->invocar('tienda_comando_unzip_api', ['domains/x.com.ar/public_html/api', 'z.zip', false]);

        $this->assertSame("cd 'domains/x.com.ar/public_html/api' && unzip -o 'z.zip' && rm -f 'z.zip' 2>&1", $comando);
        $this->assertStringNotContainsString('-x', $comando);
    }

    /** Repos, tags y assets del contrato (sección 1.1). */
    public function test_los_nombres_de_los_artefactos_son_los_del_contrato(): void
    {
        config([
            'services.deploy_tienda.release_repo_spa' => 'tienda-spa',
            'services.deploy_tienda.release_repo_api' => 'tienda-api',
        ]);

        $this->assertSame([
            'spa' => ['repo' => 'tienda-spa', 'tag' => 'v1.0.0', 'asset' => 'tienda-spa-v1.0.0-dist.zip'],
            'api' => ['repo' => 'tienda-api', 'tag' => 'v1.0.0', 'asset' => 'tienda-api-v1.0.0.zip'],
        ], EcommerceReleaseArtifacts::expected('1.0.0'));

        config(['services.deploy_tienda.release_repo_spa' => '']);
        $this->assertSame('tienda-spa', EcommerceReleaseArtifacts::spa_repo(), 'Una config vacía no puede dejar el repo vacío.');
    }

    /** El pipeline usa los dos traits, y por defecto no se compila en el VPS. */
    public function test_el_pipeline_usa_los_traits_y_no_compila_en_el_vps_por_defecto(): void
    {
        $traits = array_keys((new ReflectionClass(EcommerceInstallationService::class))->getTraits());

        $this->assertContains(ArtefactosDeRelease::class, $traits);
        $this->assertContains(ArtefactosDeReleaseDeTienda::class, $traits);
        $this->assertTrue(is_subclass_of(EcommerceDeploymentService::class, EcommerceInstallationService::class));

        $this->assertFalse(
            config('services.deploy.permitir_build_en_vps'),
            'El default de services.deploy.permitir_build_en_vps tiene que ser false.'
        );
        $this->assertFalse($this->invocar('artefacto_build_en_vps_permitido'));
    }

    /** El mensaje sin versión publicada dice qué falta, cómo se publica y cuál es la salida de emergencia. */
    public function test_el_mensaje_sin_version_publicada_dice_como_seguir(): void
    {
        $mensaje = $this->invocar('tienda_mensaje_sin_version_publicada');

        $this->assertStringContainsString('No hay ninguna versión de ecommerce publicada', $mensaje);
        $this->assertStringContainsString('POST claude/ecommerce/versions', $mensaje);
        $this->assertStringContainsString('Versiones de ecommerce', $mensaje);
        $this->assertStringContainsString('DEPLOY_PERMITIR_BUILD_EN_VPS', $mensaje);
    }

    /** La carpeta de branding no puede ser una que se lleve puesto otra cosa con el rm -rf. */
    public function test_la_carpeta_de_branding_tiene_que_ser_propia(): void
    {
        config(['services.deploy_tienda.branding_work_path' => '/home/builds/tienda-branding/']);
        $this->assertSame('/home/builds/tienda-branding', $this->invocar('tienda_branding_work_path'));

        foreach (['', '/', '/home/builds', 'relativa/branding'] as $mala) {
            config(['services.deploy_tienda.branding_work_path' => $mala]);

            try {
                $this->invocar('tienda_branding_work_path');
                $this->fail('Se aceptó la carpeta de branding «' . $mala . '».');
            } catch (\ReflectionException $e) {
                throw $e;
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('branding_work_path', $e->getMessage());
            }
        }
    }
}
