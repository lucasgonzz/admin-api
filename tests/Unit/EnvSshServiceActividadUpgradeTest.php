<?php

namespace Tests\Unit;

use App\Models\ClientApi;
use App\Services\EnvSshService;
use phpseclib3\Net\SSH2;
use PHPUnit\Framework\TestCase;

class EnvSshServiceActividadUpgradeTest extends TestCase
{
    public function test_usa_el_usuario_del_frente_y_devuelve_solo_senales(): void
    {
        $this->assertSame(
            ['usuarios_activos' => 1, 'ventas_recientes' => 0],
            $this->medir('{"usuarios_activos":1,"ventas_recientes":0}')
        );
    }

    public function test_no_confunde_un_timeout_con_ausencia_de_actividad(): void
    {
        $this->expectExceptionMessage('No se pudo medir la actividad');
        $this->medir('{"usuarios_activos":0,"ventas_recientes":0}', true);
    }

    public function test_no_confunde_una_respuesta_parcial_con_ausencia_de_actividad(): void
    {
        $this->expectExceptionMessage('La medición de actividad del negocio es inválida');
        $this->medir('{"usuarios_activos":0}');
    }

    private function medir(string $output, bool $timeout = false): array
    {
        $ssh = $this->getMockBuilder(SSH2::class)->disableOriginalConstructor()
            ->onlyMethods(['exec', 'setTimeout', 'isTimeout', 'getExitStatus', 'disconnect', '__destruct'])->getMock();
        $ssh->expects($this->once())->method('setTimeout')->with(15);
        $ssh->expects($this->once())->method('disconnect');
        $ssh->expects($this->once())->method('exec')->with($this->callback(function (string $command) {
            return strpos($command, "sudo -n -H -u 'api-negocio'") === 0
                && strpos($command, '/home/api-negocio/empresa-api') !== false
                && strpos($command, 'SET TRANSACTION READ ONLY') !== false
                && strpos($command, ' -- 101 2>/dev/null') !== false;
        }))->willReturn($output);
        $ssh->method('getExitStatus')->willReturn(0);
        $ssh->method('isTimeout')->willReturn($timeout);
        $service = new EnvSshService();
        foreach (['ssh' => $ssh, 'connected_credential_type' => 'vps'] as $key => $value) {
            $property = new \ReflectionProperty(EnvSshService::class, $key);
            $property->setAccessible(true);
            $property->setValue($service, $value);
        }
        $api = new ClientApi(['hosting_type' => 'vps', 'vps_path' => 'negocio']);
        return $service->read_upgrade_activity_for($api, 101);
    }
}
