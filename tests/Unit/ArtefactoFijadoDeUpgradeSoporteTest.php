<?php

namespace Tests\Unit;

use App\Services\DeploymentService;
use PHPUnit\Framework\TestCase;

class ArtefactoFijadoDeUpgradeSoporteTest extends TestCase
{
    public function test_rechaza_el_zip_cambiado_aunque_conserve_el_id_y_tamano(): void
    {
        $class = new \ReflectionClass(DeploymentService::class);
        $service = $class->newInstanceWithoutConstructor();
        $service->configure_support_schedule(['empresa-api' => [
            'id' => 42, 'size' => 3, 'name' => 'empresa-api-v4.3.99.zip', 'sha256' => hash('sha256', 'abc'),
        ]], function () {});
        $asset = ['url' => 'https://api.github.com/repos/example/empresa-api/releases/assets/42',
            'name' => 'empresa-api-v4.3.99.zip', 'size' => 3];
        $method = $class->getMethod('assert_support_artifact');
        $method->setAccessible(true);
        $file = tempnam(sys_get_temp_dir(), 'cc-pin-');
        try {
            file_put_contents($file, 'abc');
            $method->invoke($service, 'empresa-api', $asset, $file);
            file_put_contents($file, 'xyz');
            $this->expectExceptionMessage('El artefacto no coincide con el release acordado: empresa-api');
            $method->invoke($service, 'empresa-api', $asset, $file);
        } finally {
            unlink($file);
        }
    }
}
