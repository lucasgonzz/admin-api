<?php

namespace Tests\Feature\Prueba;

use App\Jobs\GenerateDemoChatReplyJob;
use App\Models\Client;
use App\Models\DemoChatConversacion;
use App\Models\Lead;
use App\Models\LeadPipelineStatus;
use App\Models\TrialInstance;
use App\Services\DemoChatAiService;
use App\Services\DemoChatService;
use App\Services\ManualRepositoryService;
use App\Services\Prueba\PruebaCicloService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class PruebaChatTest extends TestCase
{
    use DatabaseTransactions;

    private function prueba(array $atributos = []): array
    {
        $client = Client::create(array_replace([
            'name' => 'Negocio de prueba', 'slug' => 'chat-' . Str::random(12),
            'api_url' => '', 'api_key' => Str::random(40), 'inbound_api_key' => Str::random(40),
            'estado' => PruebaCicloService::CLIENTE_PRUEBA, 'is_active' => true, 'trial_ends_at' => now()->addDays(7),
        ], $atributos));
        $lead = Lead::create([
            'uuid' => (string) Str::uuid(), 'contact_name' => 'Julia',
            'status' => LeadPipelineStatus::PRUEBA_ACTIVA,
            'promoted_client_id' => $client->id, 'demo_eventos_token' => Str::random(64),
        ]);
        $instancia = TrialInstance::create([
            'nombre' => 'chat-' . Str::random(12),
            'estado' => TrialInstance::ESTADO_ASIGNADA, 'lead_id' => $lead->id, 'client_id' => $client->id,
            'erp_vps_path' => 'chat-prueba', 'ecommerce_vps_path' => 'chat-tienda',
        ]);

        return [$lead, $client, $instancia];
    }

    private function headers(Lead $lead, Client $client): array
    {
        return ['X-Demo-Eventos-Key' => $lead->demo_eventos_token, 'X-Admin-Api-Key' => $client->inbound_api_key];
    }

    public function test_exige_ambas_credenciales_del_mismo_negocio_y_prueba_vigente(): void
    {
        [$lead, $client, $instancia] = $this->prueba();
        [, $otro] = $this->prueba();
        [$lead_vencido, $cliente_vencido] = $this->prueba(['trial_ends_at' => now()->subSecond()]);
        [$lead_contratado, $cliente_contratado] = $this->prueba(['estado' => PruebaCicloService::CLIENTE_ACTIVO]);

        $this->getJson('/api/prueba-chat')->assertUnauthorized();
        $this->getJson('/api/prueba-chat', ['X-Demo-Eventos-Key' => $lead->demo_eventos_token])->assertUnauthorized();
        $this->getJson('/api/prueba-chat', ['X-Admin-Api-Key' => $client->inbound_api_key])->assertUnauthorized();
        $this->getJson('/api/prueba-chat', $this->headers($lead, $otro))->assertUnauthorized();
        $this->getJson('/api/prueba-chat', $this->headers($lead_vencido, $cliente_vencido))->assertUnauthorized();
        $this->getJson('/api/prueba-chat', $this->headers($lead_contratado, $cliente_contratado))->assertUnauthorized();
        $this->getJson('/api/prueba-chat', $this->headers($lead, $client))->assertOk()
            ->assertJsonPath('disponible', true)->assertJsonPath('version', 1)->assertJsonCount(0, 'mensajes');
        $this->assertDatabaseMissing('demo_chat_conversaciones', ['prueba_cliente_id' => $client->id]);

        $instancia->update(['dormida_at' => now()]);
        $this->postJson('/api/prueba-chat/iniciar', [], $this->headers($lead, $client))->assertUnauthorized();
    }

    public function test_una_demo_previa_con_el_mismo_token_conserva_su_hilo_y_su_cuota(): void
    {
        config(['services.demo_chat.rate_limit_per_minute' => 2]);
        [$lead, $client] = $this->prueba();
        $lead->update(['demo_experiencia' => Lead::EXPERIENCIA_NUEVA]);
        $headers = $this->headers($lead, $client);
        $demo_headers = ['X-Demo-Eventos-Key' => $lead->demo_eventos_token];

        $this->postJson('/api/demo-chat/iniciar', [], $demo_headers)->assertOk()->assertJsonCount(1, 'mensajes');
        $this->postJson('/api/prueba-chat/iniciar', [], $headers)->assertOk()->assertJsonCount(1, 'mensajes')
            ->assertJsonCount(0, 'catalogo')->assertJsonCount(0, 'mensajes.0.videos');
        $this->postJson('/api/prueba-chat/iniciar', [], $headers)->assertOk()->assertJsonCount(1, 'mensajes');
        $this->getJson('/api/prueba-chat', $headers)->assertStatus(429);
        $demo = $this->getJson('/api/demo-chat', $demo_headers)->assertOk()->assertJsonCount(1, 'mensajes');
        $this->assertStringContainsString('asistente de la demo', $demo->json('mensajes.0.contenido'));
        $this->assertDatabaseHas('demo_chat_conversaciones', ['prueba_cliente_id' => $client->id, 'lead_id' => null]);
        $this->postJson('/api/prueba-chat/videos/seleccionar', [], $headers)->assertNotFound();
    }

    public function test_responde_con_el_manual_y_no_activa_guias_aunque_el_payload_las_pida(): void
    {
        Queue::fake();
        [$lead, $client] = $this->prueba();
        $headers = $this->headers($lead, $client);
        $ruta = 'manual_sistema/compras/escaneo.md';
        $this->mock(ManualRepositoryService::class, function ($mock) use ($ruta) {
            $mock->shouldReceive('file_list_cacheada')->once()->andReturn($ruta);
            $mock->shouldReceive('get_file')->once()->with($ruta)->andReturn('Entrá a Proveedores y elegí Compras.');
        });
        config(['services.deepseek.api_key' => 'clave-falsa']);
        Http::fake(['api.deepseek.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'tool_use', 'id' => 'tool-1', 'name' => 'leer_manual', 'input' => ['ruta' => $ruta]]]])
            ->push(['content' => [['type' => 'text', 'text' => json_encode([
                'contenido' => 'Entrá a Proveedores y elegí Compras.', 'fuentes' => [$ruta], 'videos' => [],
                'guia' => ['accion' => 'iniciar', 'pasos' => [['id' => 'abrir_buscador', 'texto' => 'Abrí el buscador']]],
            ])]]])]);

        $this->postJson('/api/prueba-chat/mensajes', ['contenido' => 'Cómo escaneo una compra'], $headers)->assertStatus(422);
        $mensaje = [
            'contenido' => 'Cómo escaneo una compra', 'cliente_uuid' => (string) Str::uuid(),
            'guia' => ['modulo' => 'vender'], 'lead_id' => 999999999,
        ];
        $this->postJson('/api/prueba-chat/mensajes', $mensaje, $headers)->assertStatus(202)->assertJsonCount(2, 'mensajes');
        $this->postJson('/api/prueba-chat/mensajes', $mensaje, $headers)->assertStatus(202)->assertJsonCount(2, 'mensajes');
        Queue::assertPushed(GenerateDemoChatReplyJob::class, 1);

        $chat = DemoChatConversacion::where('prueba_cliente_id', $client->id)->firstOrFail();
        $this->assertNull($chat->mensajes()->where('rol', 'lead')->firstOrFail()->guia);
        $this->travel(11)->seconds();
        app(DemoChatService::class)->generar($chat->id, $chat->generacion);

        $respuesta = $this->getJson('/api/prueba-chat', $headers)->assertOk()->assertJsonCount(3, 'mensajes')
            ->assertJsonPath('mensajes.2.contenido', 'Entrá a Proveedores y elegí Compras.')
            ->assertJsonCount(0, 'mensajes.2.videos')->assertJsonPath('conversacion.estado', 'inactivo');
        $this->assertArrayNotHasKey('guia', $respuesta->json('mensajes.2'));
        Http::assertSent(function ($request) {
            return strpos($request['system'], 'asistente de la prueba gratuita') !== false
                && array_column($request['tools'], 'name') === ['leer_manual'];
        });
    }

    public function test_el_worker_revalida_el_vencimiento_antes_de_consultar_al_modelo(): void
    {
        Queue::fake();
        [$lead, $client] = $this->prueba(['trial_ends_at' => now()->addSeconds(5)]);
        $this->postJson('/api/prueba-chat/mensajes', [
            'contenido' => 'Cómo cargo una compra', 'cliente_uuid' => (string) Str::uuid(),
        ], $this->headers($lead, $client))->assertStatus(202);
        $this->mock(DemoChatAiService::class)->shouldNotReceive('responder');
        $chat = DemoChatConversacion::where('prueba_cliente_id', $client->id)->firstOrFail();
        $this->travel(11)->seconds();

        app(DemoChatService::class)->generar($chat->id, $chat->generacion);

        $this->assertSame('inactivo', $chat->fresh()->estado);
        $this->assertSame(2, $chat->mensajes()->count());
    }
}
