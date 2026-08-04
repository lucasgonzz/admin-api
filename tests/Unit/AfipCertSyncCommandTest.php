<?php

namespace Tests\Unit;

use App\Services\DeploymentService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Verifica el comando remoto que sincroniza storage/app/afip/ entre versiones v1/v2 en cada
 * deploy (prompt 01, grupo 334). DeploymentService requiere SSH y DB real en su constructor,
 * así que se instancia sin constructor (ReflectionClass::newInstanceWithoutConstructor) y se
 * invoca el método privado build_afip_sync_command() directamente por reflection.
 */
class AfipCertSyncCommandTest extends TestCase
{
    private function build_command(string $source_path, string $target_path): string
    {
        $reflection = new ReflectionClass(DeploymentService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $method = $reflection->getMethod('build_afip_sync_command');
        $method->setAccessible(true);

        return $method->invoke($service, $source_path, $target_path);
    }

    public function test_command_only_references_afip_subdirectory(): void
    {
        $command = $this->build_command(
            'domains/comerciocity.com/public_html/colman/api',
            'domains/comerciocity.com/public_html/colman2/api'
        );

        $this->assertStringContainsString('storage/app/afip', $command);
        $this->assertStringNotContainsString('storage/app/public', $command);
    }

    public function test_command_never_overwrites_existing_destination_files(): void
    {
        $command = $this->build_command(
            'domains/comerciocity.com/public_html/colman/api',
            'domains/comerciocity.com/public_html/colman2/api'
        );

        $this->assertStringContainsString('[ ! -e', $command);
        $this->assertStringNotContainsString(' -n ', $command);
        $this->assertStringNotContainsString('--no-clobber', $command);
    }

    public function test_command_skips_gracefully_when_source_has_no_afip_dir(): void
    {
        $command = $this->build_command(
            'domains/comerciocity.com/public_html/colman/api',
            'domains/comerciocity.com/public_html/colman2/api'
        );

        $this->assertStringContainsString('AFIP_SYNC_SKIP_NO_SOURCE', $command);
        $this->assertStringContainsString('AFIP_SYNC_OK', $command);
    }
}
