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

    /**
     * Carpetas temporales que crearon los tests de bash real: se borran en tearDown(), pase lo que
     * pase con el test (rechequeo independiente del 2/10/2026: quedaban `cc-tienda-bash-*` en el TEMP).
     *
     * @var array<int, string>
     */
    private $carpetas_temporales = [];

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

    protected function tearDown(): void
    {
        foreach ($this->carpetas_temporales as $carpeta) {
            $this->borrar_arbol($carpeta);
        }
        $this->carpetas_temporales = [];

        parent::tearDown();
    }

    /**
     * Borra una carpeta entera, archivos ocultos incluidos.
     *
     * @param string $ruta
     *
     * @return void
     */
    private function borrar_arbol(string $ruta): void
    {
        if (! is_dir($ruta)) {
            return;
        }

        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($ruta, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterador as $archivo) {
            $archivo->isDir() ? @rmdir($archivo->getPathname()) : @unlink($archivo->getPathname());
        }
        @rmdir($ruta);
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
        /* `**` y no `*` (chequeo del 2/10/2026): con un unzip compilado con WILD_STOP_AT_DIR,
           `public/*` no excluye lo anidado. El comportamiento real lo fija el test con unzip de
           verdad, más abajo. */
        $this->assertStringContainsString("unzip -o 'tienda_api_release_u.zip' -x 'public/**' 'storage/**' '.env'", $comando);
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

    /* ------------------------------------------------------------------------------------------
     | Chequeos independientes del 2/10/2026: unzip real, config.js antes del swap y sharp
     |----------------------------------------------------------------------------------------- */

    /**
     * Un `bash` que tenga `unzip` (en Windows, el de Git for Windows; si no, el del PATH), o null.
     *
     * @return string|null
     */
    private function bash_con_unzip(): ?string
    {
        $candidatos = [];
        if (PHP_OS_FAMILY === 'Windows') {
            foreach ([getenv('ProgramFiles'), 'C:\\Program Files'] as $program_files) {
                if (is_string($program_files) && $program_files !== '') {
                    $candidatos[] = $program_files . '\\Git\\bin\\bash.exe';
                }
            }
        }
        $candidatos[] = 'bash';

        foreach ($candidatos as $bash) {
            if ($bash !== 'bash' && ! is_file($bash)) {
                continue;
            }
            $resultado = $this->correr_en_bash($bash, 'command -v unzip', sys_get_temp_dir());
            if ($resultado['codigo'] === 0 && trim($resultado['salida']) !== '') {
                return $bash;
            }
        }

        return null;
    }

    /**
     * Corre un script en bash parado en una carpeta.
     *
     * @param string $bash
     * @param string $script
     * @param string $cwd
     *
     * @return array{codigo: int, salida: string}
     */
    private function correr_en_bash(string $bash, string $script, string $cwd): array
    {
        $proceso = @proc_open([$bash, '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias, $cwd);
        if (! is_resource($proceso)) {
            return ['codigo' => -1, 'salida' => ''];
        }
        $salida = stream_get_contents($tuberias[1]) . stream_get_contents($tuberias[2]);
        fclose($tuberias[1]);
        fclose($tuberias[2]);

        return ['codigo' => proc_close($proceso), 'salida' => $salida];
    }

    /**
     * Carpeta temporal nueva para un test que escribe en disco.
     *
     * @return string
     */
    private function carpeta_temporal(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cc-tienda-bash-' . uniqid();
        mkdir($dir, 0755, true);
        $this->carpetas_temporales[] = $dir;

        return $dir;
    }

    /**
     * Arma un zip con las entradas dadas.
     *
     * @param string                $ruta
     * @param array<string, string> $entradas
     *
     * @return void
     */
    private function armar_zip(string $ruta, array $entradas): void
    {
        $zip = new \ZipArchive();
        $zip->open($ruta, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entradas as $nombre => $contenido) {
            $zip->addFromString($nombre, $contenido);
        }
        $zip->close();
    }

    /**
     * 🔴 Con un `unzip` de verdad: la actualización NO descomprime nada de lo que cuelga de public/
     * ni de storage/ (tampoco lo anidado), no pisa el .env, y sí descomprime el código. Es el caso
     * que el patrón viejo `public/*` rompía con un unzip compilado con WILD_STOP_AT_DIR (el de Git
     * for Windows lo trae: en esta máquina, `public/*` descomprimía `public/css/app.css`).
     */
    public function test_el_unzip_real_de_la_actualizacion_no_descomprime_nada_de_public_ni_storage(): void
    {
        $bash = $this->bash_con_unzip();
        if ($bash === null) {
            $this->markTestSkipped('Hace falta bash con unzip para correr el comando real.');
        }

        $dir = $this->carpeta_temporal();
        mkdir($dir . '/public', 0755, true);
        mkdir($dir . '/storage/app', 0755, true);
        file_put_contents($dir . '/public/index.php', 'INDEX-DE-LA-TIENDA');
        file_put_contents($dir . '/storage/app/x', 'DATOS-DE-LA-TIENDA');
        file_put_contents($dir . '/.env', 'SECRETO-DE-LA-TIENDA');

        $this->armar_zip($dir . '/z.zip', [
            'artisan'                       => 'ARTISAN-NUEVO',
            'vendor/autoload.php'           => 'AUTOLOAD-NUEVO',
            'app/Http/Kernel.php'           => 'KERNEL-NUEVO',
            'public/index.php'              => 'INDEX-DEL-RELEASE',
            'public/css/app.css'            => 'CSS-DEL-RELEASE',
            'storage/app/x'                 => 'STORAGE-DEL-RELEASE',
            'storage/framework/cache/a.txt' => 'CACHE-DEL-RELEASE',
        ]);

        $resultado = $this->correr_en_bash($bash, $this->invocar('tienda_comando_unzip_api', ['.', 'z.zip', true]), $dir);

        $this->assertSame(0, $resultado['codigo'], 'El unzip de la actualización falló: ' . $resultado['salida']);
        $this->assertSame('KERNEL-NUEVO', file_get_contents($dir . '/app/Http/Kernel.php'));
        $this->assertSame('AUTOLOAD-NUEVO', file_get_contents($dir . '/vendor/autoload.php'));
        $this->assertSame('INDEX-DE-LA-TIENDA', file_get_contents($dir . '/public/index.php'));
        $this->assertFileDoesNotExist($dir . '/public/css/app.css', 'Se descomprimió algo anidado bajo public/.');
        $this->assertSame('DATOS-DE-LA-TIENDA', file_get_contents($dir . '/storage/app/x'));
        $this->assertFileDoesNotExist($dir . '/storage/framework/cache/a.txt', 'Se descomprimió algo anidado bajo storage/.');
        $this->assertSame('SECRETO-DE-LA-TIENDA', file_get_contents($dir . '/.env'));
        $this->assertFileDoesNotExist($dir . '/z.zip', 'El zip quedó en el directorio de la API.');

        /* La instalación, en cambio, descomprime todo. */
        $this->armar_zip($dir . '/z.zip', ['public/css/app.css' => 'CSS-DEL-RELEASE', 'storage/app/y' => 'NUEVO']);
        $resultado = $this->correr_en_bash($bash, $this->invocar('tienda_comando_unzip_api', ['.', 'z.zip', false]), $dir);

        $this->assertSame(0, $resultado['codigo'], $resultado['salida']);
        $this->assertSame('CSS-DEL-RELEASE', file_get_contents($dir . '/public/css/app.css'));
        $this->assertSame('NUEVO', file_get_contents($dir . '/storage/app/y'));
    }

    /**
     * Script de deploy del SPA de una tienda con la API anidada (como las del shared).
     *
     * @param string|null|false $archivo_requerido False = llamarlo con dos argumentos (como la vía vieja).
     *
     * @return string
     */
    private function script_de_deploy_del_spa($archivo_requerido): string
    {
        $ecommerce           = new \App\Models\ClientEcommerce();
        $ecommerce->spa_path = 'cliente.com.ar/public_html';
        $ecommerce->api_path = 'cliente.com.ar/public_html/api';

        $installation       = new \App\Models\ClientEcommerceInstallation();
        $installation->uuid = 'uuid-de-prueba';

        list($service, $reflection) = $this->servicio();
        foreach (['installation' => $installation, 'ecommerce' => $ecommerce] as $nombre => $valor) {
            $prop = $reflection->getProperty($nombre);
            $prop->setAccessible(true);
            $prop->setValue($service, $valor);
        }

        $method = $reflection->getMethod('build_spa_atomic_deploy_shell');
        $method->setAccessible(true);

        $args = ['public_html', 'dist.zip'];
        if ($archivo_requerido !== false) {
            $args[] = $archivo_requerido;
        }

        return $method->invokeArgs($service, $args);
    }

    /**
     * Sin archivo requerido el script es el de siempre (la vía vieja no cambia), y con `config.js` el
     * chequeo va ANTES del `mv` del docroot.
     */
    public function test_el_deploy_del_spa_chequea_config_js_antes_del_swap_solo_si_se_pide(): void
    {
        $de_siempre = $this->script_de_deploy_del_spa(false);

        $this->assertSame($de_siempre, $this->script_de_deploy_del_spa(null), 'El default cambió el script de la vía vieja.');
        $this->assertStringNotContainsString('REQUIRED', $de_siempre);

        $con_config = $this->script_de_deploy_del_spa('config.js');
        $chequeo    = strpos($con_config, 'test -s "$STAGING/$REQUIRED"');
        $swap       = strpos($con_config, 'mv "$STAGING" "$DOCROOT"');

        $this->assertNotFalse($chequeo);
        $this->assertNotFalse($swap);
        $this->assertLessThan($swap, $chequeo, 'El chequeo de config.js tiene que ir antes del swap.');
    }

    /**
     * 🔴 Con bash y unzip de verdad: un dist sin config.js NO se publica (la tienda vieja sigue
     * sirviendo y la API anidada intacta), y uno con config.js sí.
     */
    public function test_el_deploy_real_sin_config_js_no_toca_la_tienda_que_esta_sirviendo(): void
    {
        $bash = $this->bash_con_unzip();
        if ($bash === null) {
            $this->markTestSkipped('Hace falta bash con unzip para correr el script de deploy real.');
        }

        $dir = $this->carpeta_temporal();
        mkdir($dir . '/public_html/api', 0755, true);
        file_put_contents($dir . '/public_html/index.html', 'TIENDA-VIEJA');
        file_put_contents($dir . '/public_html/api/artisan', 'API-DE-LA-TIENDA');

        $this->armar_zip($dir . '/dist.zip', ['index.html' => 'TIENDA-NUEVA', '.htaccess' => 'RewriteEngine On']);
        $resultado = $this->correr_en_bash($bash, $this->script_de_deploy_del_spa('config.js'), $dir);

        $this->assertNotSame(0, $resultado['codigo'], 'Se publicó un dist sin config.js.');
        $this->assertStringContainsString('SPA_STAGING_MISSING_REQUIRED', $resultado['salida']);
        $this->assertSame('TIENDA-VIEJA', file_get_contents($dir . '/public_html/index.html'));
        $this->assertSame('API-DE-LA-TIENDA', file_get_contents($dir . '/public_html/api/artisan'));

        $this->armar_zip($dir . '/dist.zip', [
            'index.html' => 'TIENDA-NUEVA',
            '.htaccess'  => 'RewriteEngine On',
            'config.js'  => 'window.__CC_CONFIG__ = {};',
        ]);
        $resultado = $this->correr_en_bash($bash, $this->script_de_deploy_del_spa('config.js'), $dir);

        $this->assertSame(0, $resultado['codigo'], $resultado['salida']);
        $this->assertSame('TIENDA-NUEVA', file_get_contents($dir . '/public_html/index.html'));
        $this->assertSame('window.__CC_CONFIG__ = {};', file_get_contents($dir . '/public_html/config.js'));
        $this->assertSame('API-DE-LA-TIENDA', file_get_contents($dir . '/public_html/api/artisan'), 'El swap se llevó la API.');
    }

    /**
     * La instalación de sharp escribe TODO (el package.json y el npm install) adentro del flock: el
     * comando arranca con el flock y lo único que queda afuera es la verificación final.
     */
    public function test_la_instalacion_de_sharp_escribe_todo_adentro_del_flock(): void
    {
        $comando = $this->invocar('tienda_comando_asegurar_sharp', ['/home/builds/tienda-branding']);

        $this->assertStringStartsWith("flock -w 600 '/home/builds/tienda-branding/.sharp.lock' -c '", $comando);

        $adentro = substr($comando, 0, strpos($comando, " && node -e 'require(\"sharp\")'"));
        $this->assertStringContainsString('package.json', $adentro);
        $this->assertStringContainsString('npm install sharp --no-save', $adentro);
        $this->assertStringEndsWith(' && echo SHARP_LISTO', $comando);
    }
}
