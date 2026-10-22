<?php

namespace Tests\Unit;

use App\Services\RuntimeProfileService;
use PHPUnit\Framework\TestCase;

class RuntimeProfileServiceTest extends TestCase
{
    private function files(): array
    {
        $zip = new \ZipArchive();
        $zip->open(__DIR__ . '/../Fixtures/runtime-vps-real.zip');
        $files = [];
        foreach (['composer.json', 'composer.lock', 'composer.vps.json', 'composer.vps.lock'] as $name) {
            $files[$name] = $zip->getFromName($name);
        }
        $zip->close();
        return $files;
    }

    public function test_shared_no_consulta_marcador_y_vps_ausente_conserva_legado(): void
    {
        $service = new RuntimeProfileService();
        $this->assertNull($service->discover('shared_hosting', 'sitio/api', function () {
            $this->fail('El shared no puede consultar el marcador.');
        }));
        $this->assertNull($service->discover('vps', '/home/api-sitio/empresa-api', function ($command) {
            $this->assertStringContainsString('/home/api-sitio/.comerciocity-runtime.json', $command);
            $this->assertStringNotContainsString('empresa-api/.comerciocity-runtime', $command);
            return 'CC_RUNTIME_AUSENTE';
        }));
    }

    public function invalidMarkers(): array
    {
        return [[''], ['{}'], ['{"schema":1,"profile":"desconocido"}'],
            ['{"schema":"1","profile":"vps-php84-v1"}'],
            ['{"schema":1,"profile":"vps-php84-v1","php":"/tmp/php"}']];
    }

    /** @dataProvider invalidMarkers */
    public function test_marcador_invalido_falla_cerrado(string $marker): void
    {
        $this->expectException(\RuntimeException::class);
        (new RuntimeProfileService())->discover('vps', '/home/api-sitio/empresa-api', function () use ($marker) {
            return $marker;
        });
    }

    public function test_par_de_manifiestos_valido_y_plataforma_sin_instalacion(): void
    {
        $service = new RuntimeProfileService();
        $requirements = $service->assertManifests($this->files());
        $this->assertContains('zip', $requirements['extensions']);
        $this->assertContains('mbstring', $requirements['extensions']);
        $commands = [];
        $service->assertRuntime(function ($command) use (&$commands) {
            $commands[] = $command;
            if (strpos($command, 'get_loaded_extensions') !== false) {
                return '{"php":"8.4.27","extensions":["json","fileinfo","mbstring","openssl","gd","ctype","tokenizer","dom","filter","libxml","xmlreader","zip","iconv","simplexml","xml","xmlwriter","zlib","curl","pcre"]}';
            }
            if (strpos($command, '--version') !== false) {
                return 'Composer version 2.8.12';
            }
            return 'CC_RUNTIME_OK';
        }, $requirements);
        $this->assertCount(3, $commands);
        $this->assertStringContainsString('--no-plugins --no-scripts check-platform-reqs --lock --no-dev', $commands[2]);
        $this->assertStringNotContainsString(' install ', implode('\n', $commands));
        $this->assertStringContainsString('mktemp -d', $commands[2]);
    }

    public function test_rechaza_php_anterior_al_parche_y_no_ejecuta_composer(): void
    {
        $service = new RuntimeProfileService();
        $requirements = $service->assertManifests($this->files());
        $count = 0;
        try {
            $service->assertRuntime(function () use (&$count) {
                $count++;
                return '{"php":"8.4.17","extensions":["json","fileinfo","mbstring","openssl","gd","ctype","tokenizer","dom","filter","libxml","xmlreader","zip","iconv","simplexml","xml","xmlwriter","zlib","curl","pcre"]}';
            }, $requirements);
            $this->fail('PHP vulnerable no puede pasar.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('8.4.27', $e->getMessage());
        }
        $this->assertSame(1, $count);
    }

    public function test_drift_del_composer_normal_rechazado(): void
    {
        $files = $this->files();
        $files['composer.json'] = str_replace('^3.7', '^3.8', $files['composer.json']);
        $this->expectException(\RuntimeException::class);
        (new RuntimeProfileService())->assertManifests($files);
    }

    public function test_lock_viejo_rechazado_sin_degradacion(): void
    {
        $files = $this->files();
        $files['composer.vps.lock'] = $files['composer.lock'];
        $this->expectException(\RuntimeException::class);
        (new RuntimeProfileService())->assertManifests($files);
    }

    public function test_fixture_real_resuelta_por_composer_oficial_pasa_como_zip_de_release(): void
    {
        $requirements = (new RuntimeProfileService())->assertZip(__DIR__ . '/../Fixtures/runtime-vps-real.zip');
        $this->assertContains('xmlreader', $requirements['extensions']);
        $this->assertCount(93, $requirements['platform-lock']['packages']);
    }

    public function test_rechaza_autoload_inyectado_aunque_content_hash_no_lo_cubre(): void
    {
        $files = $this->files();
        $profile = json_decode($files['composer.vps.json'], true);
        $profile['autoload']['files'] = ['archivo-no-aprobado.php'];
        $files['composer.vps.json'] = json_encode($profile);
        $this->expectExceptionMessage('fuera de la lista permitida');
        (new RuntimeProfileService())->assertManifests($files);
    }

    public function test_rechaza_paquete_no_aprobado_aunque_el_lock_siga_con_el_mismo_content_hash(): void
    {
        $files = $this->files();
        $lock = json_decode($files['composer.vps.lock'], true);
        $lock['packages'][0]['version'] = 'v99.0.0';
        $files['composer.vps.lock'] = json_encode($lock);
        $this->expectExceptionMessage('paquete no permitido');
        (new RuntimeProfileService())->assertManifests($files);
    }

    public function test_rotacion_rechaza_perfiles_distintos(): void
    {
        $this->expectException(\RuntimeException::class);
        (new RuntimeProfileService())->assertSame(RuntimeProfileService::PROFILE, null);
    }

    public function test_composer_usa_php_explicito_y_scripts_solo_en_destino(): void
    {
        $service = new RuntimeProfileService();
        $build = $service->composer('/home/builds/empresa-api', true);
        $hosting = $service->composer('/home/api-sitio/empresa-api', false);
        $this->assertStringContainsString('/usr/bin/php8.4 /usr/local/bin/composer install', $hosting);
        $this->assertStringContainsString('COMPOSER=composer.vps.json', $hosting);
        $this->assertStringNotContainsString('--no-scripts', $hosting);
        $this->assertStringContainsString('--no-scripts', $build);
    }

    public function unsafeCommands(): array
    {
        return [['COMPOSER=composer.json composer install'], ['composer install'], ['composer update'],
            ['composer require openspout/openspout:^3'], ['env php artisan migrate'],
            ["bash -c 'php artisan migrate'"], ['/usr/bin/php7.4 artisan migrate'],
            ['php artisan primero; php artisan segundo'], ['php artisan primero | php artisan segundo'],
            ['php artisan primero > archivo'], ['USER_ID=$ID php artisan primero'],
            ['php artisan primero --texto="$(php -v)"'], ['php artisan primero &&']];
    }

    /** @dataProvider unsafeCommands */
    public function test_comando_publicado_no_puede_escapar_al_perfil(string $command): void
    {
        $this->expectException(\RuntimeException::class);
        (new RuntimeProfileService())->assertPublishedCommand($command);
    }

    public function test_gramatica_preserva_user_id_comillas_y_encadenado(): void
    {
        (new RuntimeProfileService())->assertPublishedCommand(
            "USER_ID=2700 php artisan ejemplo --texto='con espacios y && literales' && php artisan otro --class='Database\\Seeders\\Ejemplo'"
        );
        $this->addToAssertionCount(1);
    }
}
