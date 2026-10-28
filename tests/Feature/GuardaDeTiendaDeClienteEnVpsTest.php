<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\EcommerceInstallationController;
use App\Jobs\RunEcommerceInstallationJob;
use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientEcommerce;
use App\Models\ClientEcommerceInstallation;
use App\Services\EcommerceDeploymentService;
use App\Services\EcommerceInstallationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class GuardaDeTiendaDeClienteEnVpsTest extends TestCase
{
    use DatabaseTransactions;

    private function tienda($datos): ClientEcommerce
    {
        $cliente = new Client();
        $cliente->name = 'Tienda migrada';
        $cliente->slug = 'tienda-migrada-' . Str::random(8);
        $cliente->is_active = true;
        $cliente->save();

        return ClientEcommerce::create([
            'client_id' => $cliente->id,
            'domain' => 'tienda-ejemplo.test',
            'spa_url' => 'https://tienda-ejemplo.test',
            'api_url' => 'https://api.tienda-ejemplo.test',
            'status' => 'active',
            'ecommerce_setup_data' => $datos,
        ]);
    }

    public function test_los_dos_servicios_rechazan_la_misma_tienda_migrada_antes_de_poder_correr(): void
    {
        $tienda = $this->tienda(['hosting_type' => 'vps']);
        foreach (['install' => EcommerceInstallationService::class, 'update' => EcommerceDeploymentService::class] as $modo => $clase) {
            $corrida = ClientEcommerceInstallation::create([
                'client_ecommerce_id' => $tienda->id,
                'mode' => $modo,
                'status' => 'pendiente',
            ]);
            try {
                new $clase($corrida);
                $this->fail('El constructor debe rechazar antes de abrir SSH o ejecutar el pipeline.');
            } catch (\RuntimeException $e) {
                $this->assertSame(ClientEcommerce::CLIENTE_VPS_NO_SOPORTADO, $e->getMessage());
            }
        }
    }

    public function test_una_tienda_sin_marca_de_vps_conserva_la_construccion_del_pipeline_shared(): void
    {
        foreach ([null, ['hosting_type' => 'shared_hosting'], ['otra_configuracion' => true]] as $datos) {
            $tienda = $this->tienda($datos);
            $this->assertFalse($tienda->fresh()->is_vps());
            foreach (['install' => EcommerceInstallationService::class, 'update' => EcommerceDeploymentService::class] as $modo => $clase) {
                $corrida = ClientEcommerceInstallation::create([
                    'client_ecommerce_id' => $tienda->id,
                    'mode' => $modo,
                    'status' => 'pendiente',
                ]);
                $servicio = new $clase($corrida);
                foreach (['ssh', 'build_ssh'] as $campo) {
                    $propiedad = new ReflectionProperty(EcommerceInstallationService::class, $campo);
                    $propiedad->setAccessible(true);
                    $this->assertNull($propiedad->getValue($servicio));
                }
            }
        }
    }

    public function test_el_panel_rechaza_ambos_modos_sin_necesitar_credenciales(): void
    {
        $tienda = $this->tienda(['hosting_type' => 'vps']);
        $controlador = new EcommerceInstallationController();
        $metodo = new ReflectionMethod($controlador, 'assert_deploy_prerequisites');
        $metodo->setAccessible(true);
        foreach (['install', 'update'] as $modo) {
            $respuesta = $metodo->invoke($controlador, $tienda, $modo);
            $this->assertSame(422, $respuesta->getStatusCode());
            $this->assertSame(ClientEcommerce::CLIENTE_VPS_NO_SOPORTADO, $respuesta->getData(true)['error']);
        }
    }

    public function test_las_rutas_del_panel_no_crean_corridas_ni_jobs_para_la_tienda_migrada(): void
    {
        Queue::fake();
        $tienda = $this->tienda(['hosting_type' => 'vps']);
        $admin = new Admin();
        $admin->name = 'Admin de prueba';
        $admin->email = 'guarda-vps-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();
        Sanctum::actingAs($admin);
        $antes = ClientEcommerceInstallation::count();

        foreach (['start-install', 'start-update'] as $accion) {
            $respuesta = $this->postJson('/api/admin/ecommerce-installations/' . $accion, [
                'client_id' => $tienda->client_id,
            ]);
            $respuesta->assertStatus(422);
            $this->assertSame(ClientEcommerce::CLIENTE_VPS_NO_SOPORTADO, $respuesta->json('error'));
        }

        $this->assertSame($antes, ClientEcommerceInstallation::count());
        Queue::assertNotPushed(RunEcommerceInstallationJob::class);
    }
}
