<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\EcommerceInstallationController;
use App\Jobs\RunEcommerceInstallationJob;
use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientEcommerce;
use App\Models\ClientEcommerceInstallation;
use App\Models\EcommerceImplementation;
use App\Models\EcommerceImplementationStage;
use App\Services\EcommerceDeploymentService;
use App\Services\EcommerceInstallationService;
use App\Services\EcommerceImplementationConversationService;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Event;
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

    public function test_repetir_el_setup_conserva_alojamiento_vps_pero_reemplaza_las_respuestas_comerciales(): void
    {
        $rutas_vps = ['api_path' => '/home/api-tienda/htdocs/api.tienda.test', 'spa_path' => '/home/tienda/htdocs/tienda.test'];
        $tienda = $this->tienda([
            'hosting_type' => 'vps',
            'vps' => $rutas_vps,
            'has_delivery' => true,
            'color' => '#000000',
            'respuesta_obsoleta' => 'no conservar',
        ]);
        $this->completar_configuracion($tienda, [
            'domain' => 'dominio-elegido.test',
            'has_delivery' => false,
            'color' => '#ffffff',
            // Una respuesta de la etapa tampoco puede pisar el destino operativo existente.
            'hosting_type' => 'shared_hosting',
            'vps' => ['api_path' => 'ruta de una respuesta'],
        ]);

        $guardada = $tienda->fresh();
        $this->assertSame('dominio-elegido.test', $guardada->domain);
        $esperada = [
            'domain' => 'dominio-elegido.test',
            'has_delivery' => false,
            'color' => '#ffffff',
            'hosting_type' => 'vps',
            'vps' => $rutas_vps,
        ];
        $actual = $guardada->ecommerce_setup_data;
        // MySQL normaliza el orden de claves de objetos JSON. Conservamos tipos y contenido exactos.
        ksort($esperada);
        ksort($actual);
        $this->assertSame($esperada, $actual);
        $this->assertTrue($guardada->is_vps());
        $corrida = ClientEcommerceInstallation::create([
            'client_ecommerce_id' => $tienda->id,
            'mode' => 'update',
            'status' => 'pendiente',
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(ClientEcommerce::CLIENTE_VPS_NO_SOPORTADO);
        new EcommerceDeploymentService($corrida);
    }

    public function test_el_setup_shared_sigue_reemplazando_la_configuracion_comercial_anterior(): void
    {
        $tienda = $this->tienda(['color' => '#000000', 'respuesta_obsoleta' => 'no conservar']);
        $nueva_configuracion = ['domain' => 'tienda-shared.test', 'color' => '#123456'];

        $this->completar_configuracion($tienda, $nueva_configuracion);

        $actual = $tienda->fresh()->ecommerce_setup_data;
        ksort($nueva_configuracion);
        ksort($actual);
        $this->assertSame($nueva_configuracion, $actual);
        $this->assertFalse($tienda->fresh()->is_vps());
    }

    private function completar_configuracion(ClientEcommerce $tienda, array $datos): void
    {
        $implementacion = EcommerceImplementation::create([
            'client_id' => $tienda->client_id,
            'client_ecommerce_id' => $tienda->id,
            'status' => 'in_progress',
            'current_stage' => 1,
        ]);
        $etapa = EcommerceImplementationStage::create([
            'ecommerce_implementation_id' => $implementacion->id,
            'stage_number' => 1,
            'status' => 'in_progress',
            'data' => $datos,
        ]);
        Event::fake([
            \App\Events\EcommerceImplementationStageCompleted::class,
            \App\Events\EcommerceImplementationMessageReceived::class,
        ]);
        $whatsapp = \Mockery::mock(WhatsappSendService::class);
        $whatsapp->shouldReceive('send_text')->once()->andReturn('wamid-simulado');
        $servicio = new EcommerceImplementationConversationService($whatsapp);
        $metodo = new ReflectionMethod($servicio, 'finish_stage_1');
        $metodo->setAccessible(true);
        $metodo->invoke($servicio, $implementacion, '5491112345678', $tienda->client);
        $this->assertSame('completed', $etapa->fresh()->status);
        $this->assertNotNull($etapa->fresh()->completed_at);
    }
}
