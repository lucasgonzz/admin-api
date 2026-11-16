<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Models\Client;
use App\Models\ClientEcommerce;
use App\Models\EcommerceImplementation;
use App\Models\EcommerceImplementationStage;
use App\Services\EcommerceImplementationConversationService;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regla de Lucas del 16/11/2026 en la implementación de TIENDA: el cliente se saluda por el primer nombre de la
 * persona (`clients.name`), nunca por el nombre del negocio.
 */
class ConfirmacionDeTiendaConPrimerNombreTest extends TestCase
{
    use DatabaseTransactions;

    private function confirmacion(string $nombre, string $negocio): string
    {
        $cliente = new Client();
        $cliente->name = $nombre;
        $cliente->company_name = $negocio;
        $cliente->slug = 'tienda-nombre-' . Str::random(8);
        $cliente->is_active = true;
        $cliente->save();

        $tienda = ClientEcommerce::create(['client_id' => $cliente->id, 'domain' => 'tienda-nombre.test']);
        $implementacion = EcommerceImplementation::create([
            'client_id' => $cliente->id,
            'client_ecommerce_id' => $tienda->id,
            'status' => 'in_progress',
            'current_stage' => 1,
        ]);
        EcommerceImplementationStage::create([
            'ecommerce_implementation_id' => $implementacion->id,
            'stage_number' => 1,
            'status' => 'in_progress',
            'data' => ['domain' => 'tienda-nombre.test'],
        ]);
        Event::fake([
            \App\Events\EcommerceImplementationStageCompleted::class,
            \App\Events\EcommerceImplementationMessageReceived::class,
        ]);

        $enviado = [];
        $whatsapp = Mockery::mock(WhatsappSendService::class);
        $whatsapp->shouldReceive('send_text')->andReturnUsing(function ($telefono, $cuerpo) use (&$enviado) {
            $enviado[] = $cuerpo;
            return 'wamid-simulado';
        });
        $servicio = new EcommerceImplementationConversationService($whatsapp);
        $metodo = new ReflectionMethod($servicio, 'finish_stage_1');
        $metodo->setAccessible(true);
        $metodo->invoke($servicio, $implementacion, '5491112345678', $cliente);

        $this->assertCount(1, $enviado);

        return $enviado[0];
    }

    public function test_saluda_por_el_primer_nombre_de_la_persona_y_no_por_el_negocio(): void
    {
        $texto = $this->confirmacion('Sebastián Pérez', 'Plazoleta SRL');

        $this->assertStringStartsWith('¡Listo Sebastián!', $texto);
        $this->assertStringNotContainsString('Plazoleta', $texto);
    }

    public function test_un_nombre_cargado_en_mayusculas_se_saluda_como_nombre_propio(): void
    {
        $this->assertStringStartsWith('¡Listo Sebastián!', $this->confirmacion('SEBASTIÁN', 'Plazoleta SRL'));
    }

    public function test_sin_nombre_de_persona_no_cae_al_negocio(): void
    {
        $texto = $this->confirmacion('', 'Plazoleta SRL');

        $this->assertStringNotContainsString('Plazoleta', $texto);
    }
}
