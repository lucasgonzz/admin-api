<?php

namespace Tests\Unit;

use App\Models\ClientEcommerce;
use App\Models\ClientEcommerceInstallation;
use App\Services\EcommerceInstallationService;
use ReflectionClass;
use Tests\TestCase;
use ZipArchive;

/**
 * El deploy del SPA de la tienda respeta el `.htaccess` que trae el build (misión seo-tiendas,
 * 23/9/2026).
 *
 * POR QUÉ: desde esa misión tienda-spa versiona su propio `public/.htaccess`, que manda las rutas
 * a `seo.php` —la capa que le arma a cada URL título, descripción, datos estructurados y contenido
 * para los buscadores— en vez de a `index.html`. Hasta acá el deploy escribía SIEMPRE su bloque de
 * history mode encima, y eso apagaba la capa sin ningún error: la tienda seguía andando y Google
 * seguía indexando el HTML vacío.
 *
 * Los dos lados tienen que seguir andando con la versión vieja del otro:
 *  - build nuevo (trae `.htaccess`) → gana el del build;
 *  - build viejo (no trae `.htaccess`) → se escribe el de siempre, como antes.
 *
 * Estos tests CORREN el script de verdad en bash (el mismo que se le manda al hosting) sobre una
 * carpeta temporal y un zip real, en vez de mirar el string: lo que importa es qué archivo queda
 * en el docroot después del swap. Si no hay `bash` + `unzip` en la máquina, se saltean.
 */
class HtaccessDelBuildDeLaTiendaTest extends TestCase
{
    /** @var string */
    private $directorio;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->hay_bash_con_unzip()) {
            $this->markTestSkipped('Hace falta bash con unzip en el PATH para correr el script de deploy.');
        }

        $this->directorio = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'htaccess-tienda-' . uniqid();
        mkdir($this->directorio);
    }

    protected function tearDown(): void
    {
        if ($this->directorio && is_dir($this->directorio)) {
            $this->borrar_recursivo($this->directorio);
        }

        parent::tearDown();
    }

    /**
     * Build nuevo de tienda-spa: su `.htaccess` llega intacto al docroot.
     *
     * @return void
     */
    public function test_si_el_build_trae_htaccess_se_respeta(): void
    {
        $propio = "# htaccess del build\nDirectoryIndex seo.php index.html\n";
        $this->armar_zip(['index.html' => '<html></html>', 'seo.php' => '<?php', '.htaccess' => $propio]);

        $salida = $this->correr_deploy();

        $this->assertStringContainsString('SPA_HTACCESS_DEL_BUILD', $salida);
        $this->assertStringContainsString('SPA_DEPLOY_OK', $salida);
        $this->assertSame($propio, file_get_contents($this->directorio . '/public_html/.htaccess'));
    }

    /**
     * Build viejo de tienda-spa (sin `.htaccess`): se escribe el de history mode, como siempre.
     *
     * @return void
     */
    public function test_si_el_build_no_trae_htaccess_se_escribe_el_de_siempre(): void
    {
        $this->armar_zip(['index.html' => '<html></html>']);

        $salida = $this->correr_deploy();

        $this->assertStringNotContainsString('SPA_HTACCESS_DEL_BUILD', $salida);
        $this->assertStringContainsString('SPA_HTACCESS_OK', $salida);
        $this->assertSame($this->htaccess_de_siempre(), file_get_contents($this->directorio . '/public_html/.htaccess'));
    }

    /**
     * Un `.htaccess` vacío en el build no cuenta: se escribe el de siempre (una tienda sin reglas
     * de reescritura da 404 en cualquier ruta interna).
     *
     * @return void
     */
    public function test_un_htaccess_vacio_en_el_build_no_cuenta(): void
    {
        $this->armar_zip(['index.html' => '<html></html>', '.htaccess' => '']);

        $salida = $this->correr_deploy();

        $this->assertStringNotContainsString('SPA_HTACCESS_DEL_BUILD', $salida);
        $this->assertSame($this->htaccess_de_siempre(), file_get_contents($this->directorio . '/public_html/.htaccess'));
    }

    /**
     * La API anidada en el docroot se sigue rescatando igual (lo que protege el swap no cambió).
     *
     * @return void
     */
    public function test_la_api_anidada_se_sigue_preservando(): void
    {
        mkdir($this->directorio . '/public_html/api', 0777, true);
        file_put_contents($this->directorio . '/public_html/api/.env', 'APP_KEY=x');
        $this->armar_zip(['index.html' => '<html></html>', '.htaccess' => "RewriteEngine On\n"]);

        $this->correr_deploy();

        $this->assertSame('APP_KEY=x', file_get_contents($this->directorio . '/public_html/api/.env'));
    }

    /**
     * Arma `dist.zip` en la carpeta temporal con los archivos dados.
     *
     * @param array<string, string> $archivos Nombre => contenido.
     *
     * @return void
     */
    private function armar_zip(array $archivos): void
    {
        $zip = new ZipArchive();
        $zip->open($this->directorio . '/dist.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($archivos as $nombre => $contenido) {
            $zip->addFromString($nombre, $contenido);
        }
        $zip->close();
    }

    /**
     * Corre el script de deploy real en bash, parado en la carpeta temporal.
     *
     * @return string Salida del script (stdout + stderr).
     */
    private function correr_deploy(): string
    {
        $script = $this->script_de_deploy('public_html', 'dist.zip');

        $descriptores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proceso = proc_open(['bash', '-c', $script], $descriptores, $tuberias, $this->directorio);
        $salida = stream_get_contents($tuberias[1]) . stream_get_contents($tuberias[2]);
        fclose($tuberias[1]);
        fclose($tuberias[2]);
        $codigo = proc_close($proceso);

        $this->assertSame(0, $codigo, 'El script de deploy terminó con error: ' . $salida);

        return $salida;
    }

    /**
     * Script de despliegue atómico del SPA, sin tocar base ni SSH (mismo patrón que
     * GuardasDelPathDeInstalacionDeLaTiendaTest).
     *
     * @param string $docroot Docroot del SPA.
     * @param string $zip     Zip ya "subido".
     *
     * @return string
     */
    private function script_de_deploy(string $docroot, string $zip): string
    {
        $ecommerce           = new ClientEcommerce();
        $ecommerce->spa_path = 'cliente.com.ar/public_html';
        $ecommerce->api_path = 'cliente.com.ar/public_html/api';

        $installation       = new ClientEcommerceInstallation();
        $installation->uuid = 'uuid-de-prueba';

        $reflexion = new ReflectionClass(EcommerceInstallationService::class);
        $servicio  = $reflexion->newInstanceWithoutConstructor();

        foreach (['installation' => $installation, 'ecommerce' => $ecommerce] as $nombre => $valor) {
            $propiedad = $reflexion->getProperty($nombre);
            $propiedad->setAccessible(true);
            $propiedad->setValue($servicio, $valor);
        }

        $metodo = $reflexion->getMethod('build_spa_atomic_deploy_shell');
        $metodo->setAccessible(true);

        return $metodo->invoke($servicio, $docroot, $zip);
    }

    /**
     * @return string El `.htaccess` de history mode que escribe el deploy cuando el build no trae uno.
     */
    private function htaccess_de_siempre(): string
    {
        $reflexion = new ReflectionClass(EcommerceInstallationService::class);
        $metodo = $reflexion->getMethod('spa_htaccess_content');
        $metodo->setAccessible(true);

        return $metodo->invoke($reflexion->newInstanceWithoutConstructor());
    }

    /**
     * @return bool
     */
    private function hay_bash_con_unzip(): bool
    {
        $salida = [];
        $codigo = 1;
        @exec('bash -c "command -v unzip" 2>&1', $salida, $codigo);

        return $codigo === 0;
    }

    /**
     * @param string $ruta
     *
     * @return void
     */
    private function borrar_recursivo(string $ruta): void
    {
        foreach (array_diff(scandir($ruta), ['.', '..']) as $entrada) {
            $completa = $ruta . DIRECTORY_SEPARATOR . $entrada;
            is_dir($completa) ? $this->borrar_recursivo($completa) : unlink($completa);
        }
        rmdir($ruta);
    }
}
