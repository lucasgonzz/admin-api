<?php

namespace Tests\Unit;

use App\Services\DeploymentService;
use App\Services\InstallationService;
use App\Services\DemoUpdateService;
use App\Services\DemoInstallationService;
use Tests\TestCase;

class PerfilRuntimeEnLosPipelinesTest extends TestCase
{
    public function test_comando_personalizado_de_seeder_no_salta_la_gramatica(): void
    {
        $reflection = new \ReflectionClass(DeploymentService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('get_seeder_command');
        $method->setAccessible(true);
        $seeder = new \App\Models\VersionSeeder();
        $seeder->command = 'COMPOSER=composer.json composer install';
        $command = $method->invoke($service, $seeder);
        $this->assertSame($seeder->command, $command);
        $this->expectException(\RuntimeException::class);
        (new \App\Services\RuntimeProfileService())->assertPublishedCommand($command);
    }

    public function pipelines(): array
    {
        return [[DeploymentService::class, false], [InstallationService::class, true],
            [DemoUpdateService::class, false], [DemoInstallationService::class, true]];
    }

    /** @dataProvider pipelines */
    public function test_opt_in_conserva_scripts_segun_etapa_y_nunca_usa_composer_normal(string $class, bool $installation): void
    {
        $reflection = new \ReflectionClass($class);
        $service = $reflection->newInstanceWithoutConstructor();
        $profile = $reflection->getProperty('runtime_profile');
        $profile->setAccessible(true);
        $profile->setValue($service, 'vps-php84-v1');
        $method = $reflection->getMethod('build_composer_install_command');
        $method->setAccessible(true);
        $hosting = $method->invoke($service, '/home/api-sitio/empresa-api', false);
        $build = $method->invoke($service, '/home/builds/empresa-api', true);
        $this->assertStringContainsString('COMPOSER=composer.vps.json', $hosting);
        $this->assertStringContainsString('/usr/bin/php8.4 /usr/local/bin/composer install', $hosting);
        $this->assertSame($installation, strpos($hosting, '--no-scripts') !== false);
        $this->assertStringContainsString('--no-scripts', $build);
    }

    /** @dataProvider pipelines */
    public function test_sin_opt_in_composer_del_hosting_conserva_comando_original(string $class, bool $installation): void
    {
        config(['services.deploy.composer_bin' => 'composer-legado']);
        $reflection = new \ReflectionClass($class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('build_composer_install_command');
        $method->setAccessible(true);
        $command = $method->invoke($service, '/ruta/api', false);
        $this->assertStringContainsString('composer-legado', $command);
        $this->assertStringNotContainsString('composer.vps', $command);
        $this->assertStringNotContainsString('php8.4', $command);
        $this->assertSame($installation, strpos($command, '--no-scripts') !== false);
    }
}
