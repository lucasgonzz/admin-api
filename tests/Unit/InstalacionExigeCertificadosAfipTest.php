<?php

namespace Tests\Unit;

use App\Models\ClientApi;
use App\Services\Afip\AfipCertificateProvisionService;
use App\Services\ClientApiPathResolver;
use App\Services\InstallationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Una instalación no se puede dar por buena si el cliente quedó sin los certificados de AFIP:
 * se ve perfecta hasta que el cliente intenta emitir su primera factura, y ningún upgrade
 * posterior los repone solo (los ZIPs de upgrade excluyen storage/).
 *
 * InstallationService necesita SSH y base real en su constructor, así que se instancia sin
 * constructor y se llama al método privado por reflection — mismo patrón que
 * AfipCertSyncCommandTest.
 */
class InstalacionExigeCertificadosAfipTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function rutas_requeridas(): array
    {
        $reflection = new ReflectionClass(InstallationService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $method = $reflection->getMethod('required_installation_paths');
        $method->setAccessible(true);

        return $method->invoke($service);
    }

    public function test_las_rutas_de_los_certificados_afip_estan_entre_las_requeridas(): void
    {
        $requeridas = $this->rutas_requeridas();

        foreach (AfipCertificateProvisionService::rutas_destino() as $ruta_afip) {
            $this->assertContains(
                $ruta_afip,
                $requeridas,
                "La verificación de instalación no exige {$ruta_afip}: un cliente podría quedar instalado sin poder facturar."
            );
        }
    }

    public function test_siguen_estando_las_rutas_que_ya_se_verificaban(): void
    {
        $requeridas = $this->rutas_requeridas();

        foreach (['public/index.php', '.env', 'vendor/autoload.php', 'storage/app/public'] as $ruta) {
            $this->assertContains($ruta, $requeridas);
        }
    }

    public function test_el_resolver_de_paths_arma_las_rutas_de_cada_tipo_de_hosting(): void
    {
        $resolver = new ClientApiPathResolver();

        $shared = new ClientApi();
        $shared->hosting_type = 'shared_hosting';
        $shared->path = 'colman/api';

        $this->assertSame(
            'domains/comerciocity.com/public_html/colman/api',
            $resolver->resolve($shared)
        );
        $this->assertSame('shared_hosting', $resolver->credential_type($shared));

        $vps = new ClientApi();
        $vps->hosting_type = 'vps';
        $vps->vps_path = 'colman';

        $this->assertSame('/home/api-colman/empresa-api', $resolver->resolve($vps));
        $this->assertSame('vps', $resolver->credential_type($vps));
    }

    public function test_un_vps_sin_vps_path_no_devuelve_una_ruta_inventada(): void
    {
        $resolver = new ClientApiPathResolver();

        $vps = new ClientApi();
        $vps->hosting_type = 'vps';
        $vps->vps_path = '';

        $this->expectException(\RuntimeException::class);
        $resolver->resolve($vps);
    }
}
