<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Models\AgentImplementation;
use App\Models\EcommerceImplementation;
use App\Models\EcommerceImplementationStage;
use App\Models\ImplementationMail;
use App\Models\ImplementationStageConfig;
use App\Models\SupportTicket;
use App\Services\AgentImplementationService;

class LineaDeTiempoDeImplementacionesTest extends BaseDeImplementaciones
{
    private const URI = '/api/admin/implementation-timeline';

    public function test_requiere_sesion_del_admin(): void
    {
        $this->getJson(self::URI)->assertUnauthorized();
    }

    public function test_agrupa_los_tres_tipos_y_ordena_por_el_alta_mas_reciente(): void
    {
        $anterior = $this->crear_cliente('Negocio anterior');
        $this->crear_implementacion($anterior, ['created_at' => now()->subDays(5)]);
        $cliente = $this->crear_cliente('Negocio reciente', [
            'logo_url' => 'https://example.test/logo.png',
            'setup_data' => ['logo_url' => 'https://example.test/anterior.png', 'clave_privada' => 'dato-reservado'],
        ]);
        $sistema = $this->crear_implementacion_anterior($cliente, [
            'created_at' => now()->subDays(3),
            'form_submitted_at' => now()->subDays(2),
        ]);
        $sistema->stages()->where('stage_number', 1)->update(['status' => 'completed', 'completed_at' => now()->subDays(2)]);
        ImplementationStageConfig::updateOrCreate(['stage_number' => 6], ['name' => 'Capacitación']);

        $tienda = EcommerceImplementation::create(['client_id' => $cliente->id]);
        $tienda->forceFill(['created_at' => now()->subDays(2)])->save();
        EcommerceImplementationStage::create([
            'ecommerce_implementation_id' => $tienda->id,
            'stage_number' => 1,
            'status' => 'completed',
            'data' => ['current_question' => 'completed', 'completed' => true],
            'completed_at' => now()->subDay(),
        ]);
        $tienda->messages()->create([
            'stage_number' => 1,
            'direction' => 'outbound',
            'body' => 'Contenido privado del hilo',
            'whatsapp_message_id' => 'wamid.tienda',
            'sent_at' => now()->subDay(),
        ]);

        $agentes = app(AgentImplementationService::class)->create($cliente, AgentImplementation::CAMINO_NUEVO)['implementation'];
        $cancelada = AgentImplementation::create([
            'client_id' => $cliente->id,
            'status' => AgentImplementation::STATUS_CANCELLED,
        ]);
        $cancelada->forceFill(['created_at' => now()->subDay()])->save();
        $agentes->stages()->where('stage_number', 2)->update(['status' => 'skipped', 'completed_at' => now()]);

        $respuesta = $this->actingAs($this->crear_admin(), 'sanctum')->getJson(self::URI)->assertOk();
        $negocios = collect($respuesta->json('data'))->whereIn('id', [$cliente->id, $anterior->id])->values();

        $this->assertSame([$cliente->id, $anterior->id], $negocios->pluck('id')->all());
        $this->assertSame($cliente->company_name, $negocios[0]['name']);
        $this->assertSame('https://example.test/logo.png', $negocios[0]['logo_url']);
        $ramas = collect($negocios[0]['implementations'])->keyBy('type');
        $this->assertSame(['sistema', 'tienda', 'agentes'], $ramas->keys()->all());
        $this->assertCount(7, $ramas['sistema']['stages']);
        $this->assertSame('completed', $ramas['sistema']['stages'][0]['status']);
        $this->assertSame('Formulario de implementación', $ramas['sistema']['stages'][0]['completed_source']);
        $this->assertStringStartsWith('Catálogo', $ramas['sistema']['stages'][5]['name']);
        $this->assertSame('Conversación de implementación', $ramas['tienda']['stages'][0]['sent_source']);
        $this->assertSame('Conversación de implementación', $ramas['tienda']['stages'][0]['completed_source']);
        $this->assertNull($ramas['tienda']['stages'][0]['sent_ticket_id']);
        $this->assertSame($agentes->id, $ramas['agentes']['id']);
        $this->assertSame('skipped', $ramas['agentes']['stages'][1]['status']);
        $this->assertNull($ramas['agentes']['stages'][1]['completed_at']);
        $respuesta->assertDontSee('dato-reservado')->assertDontSee('Contenido privado del hilo');
    }

    public function test_enlaza_envios_confirmados_y_no_convierte_notas_o_fallas_en_evidencia(): void
    {
        $cliente = $this->crear_cliente();
        $otro = $this->crear_cliente('Otro negocio');
        $implementacion = $this->crear_implementacion($cliente);
        $ticket = SupportTicket::create(['client_id' => $cliente->id, 'client_user_id' => 0]);
        $ticket_ajeno = SupportTicket::create(['client_id' => $otro->id, 'client_user_id' => 0]);
        $cuando = now()->utc();
        $operacion = ['estado' => 'sent', 'ticket_id' => $ticket->id, 'terminada_at' => $cuando->toISOString()];

        $this->escribir_data_de_la_etapa($implementacion, 1, ['whatsapp_ops' => ['confirmado' => $operacion]]);
        $this->escribir_data_de_la_etapa($implementacion, 2, ['whatsapp_ops' => ['fallido' => array_merge($operacion, ['estado' => 'failed'])]]);
        $this->escribir_data_de_la_etapa($implementacion, 3, ['whatsapp_ops' => ['ajeno' => array_merge($operacion, ['ticket_id' => $ticket_ajeno->id])]]);
        $this->escribir_data_de_la_etapa($implementacion, 4, ['actions' => [['action' => 'nota', 'support_ticket_id' => $ticket->id]]]);
        $implementacion->stages()->where('stage_number', 4)->update(['status' => 'completed', 'completed_at' => now()]);
        $implementacion->messages()->create([
            'stage_number' => 2,
            'direction' => 'outbound',
            'body' => 'Intento fallido',
            'sent_at' => now(),
        ]);
        ImplementationMail::create([
            'implementation_id' => $implementacion->id,
            'hito' => 'bienvenida',
            'email' => 'privado@example.test',
            'asunto' => 'Link de implementación',
            'estado' => ImplementationMail::ESTADO_ENVIADO,
            'enviado_at' => now()->addMinute(),
        ]);
        ImplementationMail::create([
            'implementation_id' => $implementacion->id,
            'hito' => 'categorias',
            'email' => 'privado@example.test',
            'asunto' => 'Opciones de categorías',
            'estado' => ImplementationMail::ESTADO_ENVIADO,
            'enviado_at' => now(),
        ]);

        $respuesta = $this->actingAs($this->crear_admin(), 'sanctum')->getJson(self::URI)->assertOk();
        $etapas = collect($respuesta->json('data'))->firstWhere('id', $cliente->id)['implementations'][0]['stages'];

        $this->assertSame($ticket->id, $etapas[0]['sent_ticket_id']);
        $this->assertSame($cuando->toIso8601String(), $etapas[0]['sent_at']);
        $this->assertNull($etapas[1]['sent_at']);
        $this->assertNull($etapas[2]['sent_ticket_id']);
        $this->assertNull($etapas[3]['completed_ticket_id']);
        $this->assertNull($etapas[3]['completed_source']);
        $this->assertNotNull($etapas[3]['completed_at']);
        $this->assertSame('Correo de implementación', $etapas[5]['sent_source']);
        $this->assertNotNull($etapas[5]['sent_at']);
        $respuesta->assertDontSee('privado@example.test')->assertDontSee('Opciones de categorías');
    }
}
