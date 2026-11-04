<?php

namespace Tests\Feature\AgentesIa;

use App\Models\Admin;
use App\Models\AiImagePlan;
use App\Models\IaImagenPedido;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\AsistenteWhatsapp\BaseDelCanal;

class PaquetesDeImagenesTest extends BaseDelCanal
{
    protected function setUp(): void
    {
        parent::setUp();

        $admin = new Admin();
        $admin->name = 'Admin de imágenes';
        $admin->email = 'paquetes-imagenes-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);
    }

    public function test_los_paquetes_tienen_precio_en_pesos_y_tope_en_dolares(): void
    {
        $this->getJson('/api/admin/ai-image-plan')->assertOk()
            ->assertJsonFragment(['precio_ars' => '10000.00', 'tope_usd_mensual' => '5.00'])
            ->assertJsonFragment(['precio_ars' => '20000.00', 'tope_usd_mensual' => '10.00'])
            ->assertJsonFragment(['precio_ars' => '30000.00', 'tope_usd_mensual' => '15.00'])
            ->assertJsonPath('costos.medium', '0.065000')
            ->assertJsonPath('costos.high', '0.230000');

        $nuevo = ['nombre' => 'Plan de imágenes de prueba', 'precio_ars' => '50000.00', 'tope_usd_mensual' => '25.00'];
        $id = $this->postJson('/api/admin/ai-image-plan', $nuevo)->assertCreated()->json('ai_image_plan.id');
        $nuevo['tope_usd_mensual'] = '21.25';
        $nuevo['activo'] = false;
        $this->putJson('/api/admin/ai-image-plan/' . $id, $nuevo)->assertOk()
            ->assertJsonPath('ai_image_plan.tope_usd_mensual', '21.25')
            ->assertJsonPath('ai_image_plan.activo', false);

        $this->putJson('/api/admin/ai-image-plan/costos', ['medium' => '0.075501', 'high' => '0.240000'])
            ->assertOk()->assertJsonPath('costos.medium', '0.075501')->assertJsonPath('costos.high', '0.240000');
        $this->putJson('/api/admin/ai-image-plan/costos', ['medium' => '0', 'high' => '0.24'])
            ->assertStatus(422)->assertJsonValidationErrors('medium');
    }

    public function test_la_asignacion_de_imagenes_es_independiente_y_respeta_el_mes_y_el_cliente(): void
    {
        $cliente = $this->crear_cliente();
        $ruta = '/api/admin/client/' . $cliente->id . '/ai-image-plan';
        $plan = AiImagePlan::where('nombre', 'Imágenes Básico')->firstOrFail();
        $periodo = now('America/Argentina/Buenos_Aires')->format('Y-m');

        $this->getJson($ruta)->assertOk()->assertJsonPath('ai_image_plan_id', null)->assertJsonPath('ai_image_quality', 'medium');
        $this->registrar_pedido($cliente->id, $periodo, 65000, 65000);
        $this->registrar_pedido($cliente->id, $periodo, 230000, null);
        $this->registrar_pedido($cliente->id, '2000-01', 65000, 65000);

        $this->putJson($ruta, ['ai_image_plan_id' => $plan->id, 'ai_image_quality' => 'high'])
            ->assertOk()->assertJsonPath('ai_image_plan_id', $plan->id)->assertJsonPath('ai_image_quality', 'high')
            ->assertJsonPath('consumo_usd', 0.065)->assertJsonPath('reservado_usd', 0.23);
        $this->assertNull($cliente->fresh()->ai_plan_id);
        $this->putJson($ruta, ['ai_image_plan_id' => null, 'ai_image_quality' => 'medium'])
            ->assertOk()->assertJsonPath('ai_image_plan_id', null);

        $plan->update(['activo' => false]);
        $this->putJson($ruta, ['ai_image_plan_id' => $plan->id, 'ai_image_quality' => 'medium'])
            ->assertStatus(422)->assertJsonValidationErrors('ai_image_plan_id');
    }

    private function registrar_pedido($cliente_id, string $periodo, int $reservado, ?int $cobrado): void
    {
        IaImagenPedido::create([
            'client_id' => $cliente_id,
            'uuid' => (string) Str::uuid(),
            'prompt' => 'Imagen de prueba',
            'tamano' => 'cuadrada',
            'estado' => IaImagenPedido::ESTADO_PENDIENTE,
            'periodo_consumo' => $periodo,
            'costo_reservado_microusd' => $reservado,
            'costo_cobrado_microusd' => $cobrado,
        ]);
    }
}
