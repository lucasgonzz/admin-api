<?php

namespace Tests\Unit;

use App\Services\DeploymentService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Verifica el comando remoto que corrige el dueño de storage/app/afip/ en un cliente VPS después
 * de que provision() (AfipCertificateProvisionService) la haya creado por SFTP con la credencial
 * raíz compartida del VPS. Encontrado en arfren y ananda el 8/9/2026: esa carpeta quedaba dueña
 * de root, y AfipWSAAHelper::define() (empresa-api) no podía crear storage/app/afip/wsaa/ al
 * facturar. DeploymentService requiere SSH y DB real en su constructor, así que se instancia sin
 * constructor (mismo patrón que AfipCertSyncCommandTest) y se invoca el método privado
 * build_afip_ownership_fix_command() directamente por reflection.
 *
 * 🔴 La primera versión de este test aserteba 'arfren:arfren' (sin el prefijo api- del usuario
 * real del sitio) y pasaba en verde con una implementación que apuntaba al usuario equivocado —
 * lo encontró el chequeo independiente antes de mergear. extract_owner_argument() abajo no
 * depende de qué comilla usó escapeshellarg(): en Linux (producción real de admin-api) escapa con
 * comillas simples, pero corriendo los tests con el PHP de Windows de esta máquina escapa con
 * comillas dobles — el mismo síntoma que ya está anotado en memoria ("escapeshellarg de Windows
 * rompe comandos de Linux"). Comparar con comillas fijas hubiera hecho que el test fallara acá sin
 * que el comando esté mal.
 */
class AfipOwnershipFixCommandTest extends TestCase
{
    private function build_command(string $api_path, string $vps_path): string
    {
        $reflection = new ReflectionClass(DeploymentService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $method = $reflection->getMethod('build_afip_ownership_fix_command');
        $method->setAccessible(true);

        return $method->invoke($service, $api_path, $vps_path);
    }

    /**
     * Extrae el argumento que sigue a "chown -R ", sin importar si escapeshellarg() lo envolvió
     * con comillas simples (Linux, producción real) o dobles (Windows, esta máquina de test).
     */
    private function extract_owner_argument(string $command): string
    {
        $matched = preg_match('/chown -R\s+([\'"])(.*?)\1/', $command, $matches);
        $this->assertSame(1, $matched, 'No se encontró un argumento quoteado después de "chown -R" en: ' . $command);

        return $matches[2];
    }

    public function test_command_chowns_recursively_to_the_site_own_user_with_the_api_prefix(): void
    {
        $command = $this->build_command('/home/api-arfren/empresa-api', 'arfren');

        $this->assertStringContainsString('chown -R', $command);
        $this->assertSame('api-arfren:api-arfren', $this->extract_owner_argument($command));
        $this->assertStringContainsString('storage/app/afip', $command);
    }

    public function test_command_only_touches_the_afip_directory(): void
    {
        $command = $this->build_command('/home/api-arfren/empresa-api', 'arfren');

        $this->assertStringNotContainsString('storage/app/public', $command);
        $this->assertStringNotContainsString('storage/logs', $command);
    }

    public function test_command_guards_against_missing_directory_and_reports_both_outcomes(): void
    {
        $command = $this->build_command('/home/api-arfren/empresa-api', 'arfren');

        $this->assertStringContainsString('[ -d', $command);
        $this->assertStringContainsString('AFIP_OWNERSHIP_FIX_OK', $command);
        $this->assertStringContainsString('AFIP_OWNERSHIP_FIX_SKIP_NO_DIR', $command);
    }

    public function test_command_prefixes_vps_path_with_api_dash_for_a_different_client(): void
    {
        $command = $this->build_command('/home/api-ananda/empresa-api', 'ananda');

        $this->assertSame('api-ananda:api-ananda', $this->extract_owner_argument($command));
        $this->assertStringNotContainsString('arfren', $command);
    }
}
