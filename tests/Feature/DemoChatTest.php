<?php

namespace Tests\Feature;

use App\Jobs\GenerateDemoChatReplyJob;
use App\Models\Admin;
use App\Models\DemoChatConversacion;
use App\Models\DemoChatMensaje;
use App\Models\DemoMedia;
use App\Models\Lead;
use App\Models\RecursosVideo;
use App\Models\SyncedGithubFile;
use App\Services\DemoCatalogoService;
use App\Services\DemoChatAiService;
use App\Services\DemoChatService;
use App\Services\ManualRepositoryService;
use App\Services\RecursosCatalogoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DemoChatTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-19 15:00:00'));
        Queue::fake();
        app(RecursosCatalogoService::class)->olvidar();
        SyncedGithubFile::updateOrCreate(['key' => DemoCatalogoService::SYNCED_FILE_KEY], ['repo_path' => 'contexto/demo_catalogo.json', 'content' => json_encode([
            'orden_secciones' => ['S0 - Introducción'], 'clips' => [
                ['id' => '0.1', 'titulo' => 'Conocer el sistema', 'seccion' => 'S0 - Introducción'],
                ['id' => '1.1', 'titulo' => 'Crear un artículo', 'seccion' => 'S0 - Introducción'],
                ['id' => '2.1', 'titulo' => 'Hacer una venta', 'seccion' => 'S0 - Introducción'],
            ],
        ])]);
        foreach (['0.1', '1.1', '2.1'] as $id) {
            DemoMedia::updateOrCreate(['slot_id' => $id], ['url' => 'https://videos.test/' . $id . '.mp4']);
        }
        $this->mock(ManualRepositoryService::class, function ($mock) {
            $mock->shouldReceive('file_list_cacheada')->andReturn('manual_sistema/compras/escaneo.md');
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lead(): Lead
    {
        $lead = Lead::create(['uuid' => (string) Str::uuid(), 'contact_name' => 'Julia', 'demo_experiencia' => Lead::EXPERIENCIA_NUEVA,
            'demo_eventos_token' => Str::random(64), 'demo_industria' => 'jugueteria']);
        $lead->demo_plan = ['secciones' => [['clips' => [
            ['id' => '0.1', 'titulo' => 'Conocer el sistema', 'tipo' => 'nucleo'],
            ['id' => '1.1', 'titulo' => 'Crear un artículo', 'tipo' => 'nucleo'],
            ['id' => '2.1', 'titulo' => 'Hacer una venta', 'tipo' => 'nucleo'],
        ]]]];
        $lead->save();
        return $lead;
    }

    private function headers(Lead $lead): array { return ['X-Demo-Eventos-Key' => $lead->demo_eventos_token]; }

    private function entrar(Lead $lead)
    {
        return $this->postJson('/api/demo-chat/iniciar', [], $this->headers($lead))->assertOk();
    }

    private function mensaje(Lead $lead, string $texto = 'Quiero ver compras', ?string $uuid = null)
    {
        return $this->postJson('/api/demo-chat/mensajes', ['contenido' => $texto, 'cliente_uuid' => $uuid ?: (string) Str::uuid()], $this->headers($lead))->assertStatus(202);
    }

    private function admin(): void
    {
        $admin = Admin::create(['name' => 'Operador', 'email' => Str::random(12) . '@demo.test', 'password' => 'inaccesible']);
        Sanctum::actingAs($admin);
    }

    private function responder(Lead $lead): void
    {
        Carbon::setTestNow(now()->addSeconds(11));
        $chat = DemoChatConversacion::where('lead_id', $lead->id)->firstOrFail();
        app(DemoChatService::class)->generar($chat->id, $chat->generacion);
    }

    public function test_el_get_es_puro_el_inicio_idempotente_y_los_tokens_aislan_los_hilos(): void
    {
        $lead = $this->lead();
        $otro = $this->lead();
        $this->getJson('/api/demo-chat')->assertStatus(401);
        $this->getJson('/api/demo-chat', $this->headers($lead))->assertOk()->assertJsonCount(0, 'mensajes');
        $this->assertDatabaseMissing('demo_chat_conversaciones', ['lead_id' => $lead->id]);
        $this->entrar($lead)->assertJsonCount(1, 'mensajes')->assertJsonPath('mensajes.0.videos.0.clave', 'demo:0.1');
        $this->entrar($lead)->assertJsonCount(1, 'mensajes');
        $this->getJson('/api/demo-chat', $this->headers($otro))->assertJsonCount(0, 'mensajes');
        $this->getJson('/api/admin/lead/' . $lead->id . '/demo-chat')->assertStatus(401);
    }

    public function test_el_polling_de_una_demo_no_gasta_la_cuota_de_otra_en_la_misma_ip(): void
    {
        config(['services.demo_chat.rate_limit_per_minute' => 2]);
        $lead = $this->lead(); $otro = $this->lead();
        $this->getJson('/api/demo-chat', $this->headers($lead))->assertOk();
        $this->getJson('/api/demo-chat', $this->headers($lead))->assertOk();
        $this->getJson('/api/demo-chat', $this->headers($lead))->assertStatus(429);
        $this->getJson('/api/demo-chat', $this->headers($otro))->assertOk();
    }

    public function test_agrupa_diez_segundos_desde_el_ultimo_mensaje_y_no_duplica_un_reintento(): void
    {
        $lead = $this->lead(); $uuid = (string) Str::uuid();
        $this->mensaje($lead, 'Quiero ver', $uuid)->assertJsonPath('conversacion.estado', 'esperando');
        $this->mensaje($lead, 'Quiero ver', $uuid)->assertJsonCount(2, 'mensajes');
        Queue::assertPushed(GenerateDemoChatReplyJob::class, 1);
        $viejo = DemoChatConversacion::where('lead_id', $lead->id)->first();
        Carbon::setTestNow(now()->addSeconds(6));
        $this->mensaje($lead, 'cómo cargar una compra');
        $actual = $viejo->fresh();
        $this->assertSame(2, $actual->generacion);
        $this->assertSame(10, now()->diffInSeconds($actual->responder_desde));
        $this->mock(DemoChatAiService::class, function ($mock) {
            $mock->shouldReceive('responder')->once()->andReturn(['contenido' => 'Veamos cómo cargar una compra.', 'videos' => []]);
        });
        app(DemoChatService::class)->generar($actual->id, $viejo->generacion);
        app(DemoChatService::class)->generar($actual->id, $actual->generacion);
        $this->assertSame('esperando', $actual->fresh()->estado);
        $this->responder($lead);
        $this->getJson('/api/demo-chat', $this->headers($lead))->assertJsonCount(4, 'mensajes')->assertJsonPath('conversacion.estado', 'inactivo');
        Queue::assertPushed(GenerateDemoChatReplyJob::class, function ($job) { return $job->queue === 'asistente' && $job->connection === 'database'; });
    }

    public function test_cambiar_espera_en_vivo_reprograma_desde_ultimo_mensaje_e_invalida_jobs_viejos(): void
    {
        $lead = $this->lead(); $otro = $this->lead(); $this->admin();
        $ruta = '/api/admin/lead/' . $lead->id . '/demo-chat/configuracion';
        $this->mensaje($lead)->assertJsonPath('conversacion.espera_segundos', 10);
        Carbon::setTestNow(now()->addSeconds(4));
        $this->mensaje($lead, 'Otro dato');
        $viejo = DemoChatConversacion::where('lead_id', $lead->id)->firstOrFail();
        Carbon::setTestNow(now()->addSeconds(3));
        $this->putJson($ruta, ['espera_segundos' => 30])->assertOk()->assertJsonPath('conversacion.espera_segundos', 30);
        $largo = $viejo->fresh();
        $this->assertSame(27, now()->diffInSeconds($largo->responder_desde));
        $this->putJson($ruta, ['espera_segundos' => 2])->assertOk();
        $corto = $largo->fresh();
        $this->assertTrue($corto->responder_desde->isPast());
        $this->mock(DemoChatAiService::class, function ($mock) { $mock->shouldReceive('responder')->once()->andReturn(['contenido' => 'Respuesta vigente', 'videos' => []]); });
        $service = app(DemoChatService::class);
        $service->generar($viejo->id, $viejo->generacion);
        $service->generar($largo->id, $largo->generacion);
        $this->assertSame('esperando', $corto->fresh()->estado);
        $service->generar($corto->id, $corto->generacion);
        $service->generar($corto->id, $corto->generacion);
        $this->assertSame('inactivo', $corto->fresh()->estado);
        Queue::assertPushed(GenerateDemoChatReplyJob::class, function ($job) use ($corto) { return $job->generacion === $corto->generacion && $job->delay->equalTo(now()); });
        $this->getJson('/api/admin/lead/' . $otro->id . '/demo-chat')->assertJsonPath('conversacion.espera_segundos', 10);
        foreach ([-1, 121, 1.5, 'abc'] as $valor) { $this->putJson($ruta, ['espera_segundos' => $valor])->assertStatus(422); }
        $this->putJson($ruta, [])->assertStatus(422);
    }

    public function test_nuevo_mensaje_tras_cambio_de_espera_invalida_el_job_reprogramado(): void
    {
        $lead = $this->lead(); $this->admin(); $this->mensaje($lead);
        $this->putJson('/api/admin/lead/' . $lead->id . '/demo-chat/configuracion', ['espera_segundos' => 0])->assertOk();
        $viejo = DemoChatConversacion::where('lead_id', $lead->id)->firstOrFail();
        $this->mensaje($lead, 'Me faltaba esto');
        $this->mock(DemoChatAiService::class, function ($mock) { $mock->shouldReceive('responder')->once()->andReturn(['contenido' => 'Respuesta completa', 'videos' => []]); });
        app(DemoChatService::class)->generar($viejo->id, $viejo->generacion);
        $this->assertSame('esperando', $viejo->fresh()->estado);
        app(DemoChatService::class)->generar($viejo->id, $viejo->fresh()->generacion);
        $this->getJson('/api/demo-chat', $this->headers($lead))->assertJsonCount(4, 'mensajes');
    }

    public function test_cambiar_espera_mientras_piensa_no_descarta_respuesta_y_conserva_modo_manual(): void
    {
        $lead = $this->lead(); $this->admin(); $this->mensaje($lead);
        $this->mock(DemoChatAiService::class, function ($mock) use ($lead) {
            $mock->shouldReceive('responder')->once()->andReturnUsing(function () use ($lead) {
                $this->putJson('/api/admin/lead/' . $lead->id . '/demo-chat/configuracion', ['espera_segundos' => 120, 'auto_responder' => false])->assertOk()->assertJsonPath('conversacion.estado', 'pensando');
                return ['contenido' => 'Borrador vigente', 'videos' => []];
            });
        });
        $this->responder($lead);
        $this->getJson('/api/demo-chat', $this->headers($lead))->assertJsonCount(2, 'mensajes')->assertJsonPath('conversacion.estado', 'listo');
        $this->mensaje($lead, 'Nueva pregunta');
        $chat = DemoChatConversacion::where('lead_id', $lead->id)->firstOrFail();
        $this->assertSame(120, now()->diffInSeconds($chat->responder_desde));
    }

    public function test_manual_genera_borrador_oculto_y_el_operador_edita_envia_y_borra_sin_marcas_publicas(): void
    {
        $lead = $this->lead(); $this->admin();
        $base = '/api/admin/lead/' . $lead->id . '/demo-chat';
        $this->putJson($base . '/configuracion', ['auto_responder' => false])->assertOk();
        $this->mensaje($lead)->assertJsonPath('conversacion.estado', 'esperando');
        $this->mock(DemoChatAiService::class, function ($mock) { $mock->shouldReceive('responder')->once()->andReturn(['contenido' => 'Podés revisar la compra.', 'videos' => []]); });
        $this->responder($lead);
        $this->getJson('/api/demo-chat', $this->headers($lead))->assertJsonCount(2, 'mensajes')->assertJsonPath('conversacion.estado', 'listo');
        $panel = $this->getJson($base)->assertJsonCount(3, 'mensajes')->json();
        $id = $panel['mensajes'][2]['id'];
        $this->putJson($base . '/mensajes/' . $id, ['contenido' => 'Revisá los datos y confirmá.'])->assertOk();
        $this->postJson($base . '/mensajes/' . $id . '/enviar')->assertOk();
        $this->putJson($base . '/mensajes/' . $id, ['contenido' => 'Revisá la compra antes de confirmar.'])->assertOk();
        $publico = $this->getJson('/api/demo-chat', $this->headers($lead))->assertJsonCount(3, 'mensajes')->json('mensajes.2');
        $this->assertSame('Revisá la compra antes de confirmar.', $publico['contenido']);
        foreach (['revisiones', 'admin_id', 'estado', 'origen', 'editado_at'] as $campo) { $this->assertArrayNotHasKey($campo, $publico); }
        $this->deleteJson($base . '/mensajes/' . $id)->assertOk();
        $this->getJson('/api/demo-chat', $this->headers($lead))->assertJsonCount(2, 'mensajes');
        $this->assertSame('eliminado', DemoChatMensaje::find($id)->estado);
        $this->assertCount(4, DemoChatMensaje::find($id)->revisiones);
    }

    public function test_un_mensaje_nuevo_durante_la_llamada_descarta_la_respuesta_vieja(): void
    {
        $lead = $this->lead(); $this->mensaje($lead);
        $this->mock(DemoChatAiService::class, function ($mock) use ($lead) {
            $mock->shouldReceive('responder')->once()->andReturnUsing(function () use ($lead) {
                app(DemoChatService::class)->recibir($lead, 'Mejor quiero ver ventas', (string) Str::uuid());
                return ['contenido' => 'Respuesta vieja de compras', 'videos' => []];
            });
        });
        $this->responder($lead);
        $this->assertDatabaseMissing('demo_chat_mensajes', ['contenido' => 'Respuesta vieja de compras']);
        $this->getJson('/api/demo-chat', $this->headers($lead))->assertJsonPath('conversacion.estado', 'esperando')->assertJsonCount(3, 'mensajes');
    }

    public function test_intervenir_cancela_una_generacion_y_no_permite_editar_otro_lead(): void
    {
        $lead = $this->lead(); $otro = $this->lead(); $this->mensaje($lead); $this->admin();
        $viejo = DemoChatConversacion::where('lead_id', $lead->id)->first();
        $r = $this->postJson('/api/admin/lead/' . $lead->id . '/demo-chat/mensajes', ['contenido' => 'Te ayudo yo con esa duda.'])->assertOk();
        $id = $r->json('mensajes.2.id');
        $this->putJson('/api/admin/lead/' . $otro->id . '/demo-chat/mensajes/' . $id, ['contenido' => 'Ajeno'])->assertNotFound();
        $this->mock(DemoChatAiService::class, function ($mock) { $mock->shouldNotReceive('responder'); });
        Carbon::setTestNow(now()->addSeconds(20));
        app(DemoChatService::class)->generar($viejo->id, $viejo->generacion);
        $this->getJson('/api/demo-chat', $this->headers($lead))->assertJsonCount(3, 'mensajes')->assertJsonPath('conversacion.estado', 'inactivo');
    }

    public function test_progreso_cuenta_segundos_unicos_y_sugiere_siguiente_una_sola_vez(): void
    {
        $lead = $this->lead(); $this->entrar($lead); $h = $this->headers($lead);
        $ruta = '/api/demo-chat/videos/progreso';
        $this->postJson($ruta, ['clave' => 'demo:0.1', 'segundos_vistos' => range(0, 19), 'duracion' => 100], $h)->assertOk()->assertJsonPath('mensajes.0.videos.0.porcentaje', 20);
        $this->postJson($ruta, ['clave' => 'demo:0.1', 'segundos_vistos' => range(10, 89), 'duracion' => 100], $h)->assertOk()->assertJsonPath('mensajes.0.videos.0.porcentaje', 90)->assertJsonPath('mensajes.0.videos.0.completado', false)->assertJsonCount(1, 'mensajes');
        $this->postJson($ruta, ['clave' => 'demo:0.1', 'segundos_vistos' => [], 'duracion' => 100, 'terminado' => true], $h)->assertOk()->assertJsonPath('mensajes.0.videos.0.completado', true)->assertJsonCount(2, 'mensajes')->assertJsonPath('mensajes.1.videos.0.clave', 'demo:1.1');
        $this->postJson($ruta, ['clave' => 'demo:0.1', 'segundos_vistos' => range(10, 89), 'duracion' => 100, 'terminado' => true], $h)->assertJsonCount(2, 'mensajes');
        $this->postJson('/api/demo-chat/videos/omitir', ['clave' => 'demo:1.1'], $h)->assertOk()->assertJsonPath('mensajes.1.videos.0.omitido', true)->assertJsonPath('mensajes.2.videos.0.clave', 'demo:2.1');
        $this->postJson($ruta, ['clave' => 'recurso:ajeno', 'segundos_vistos' => [0], 'duracion' => 20], $h)->assertStatus(422);
    }

    public function test_llegar_al_final_sin_haber_mirado_el_video_no_lo_completa(): void
    {
        $lead = $this->lead(); $this->entrar($lead);
        $this->postJson('/api/demo-chat/videos/progreso', ['clave' => 'demo:0.1', 'segundos_vistos' => [98, 99], 'duracion' => 100, 'terminado' => true], $this->headers($lead))
            ->assertOk()->assertJsonPath('mensajes.0.videos.0.porcentaje', 2)->assertJsonPath('mensajes.0.videos.0.completado', false)->assertJsonCount(1, 'mensajes');
    }

    public function test_deepseek_usa_recursos_reales_y_no_filtra_su_clave(): void
    {
        RecursosVideo::create(['codigo' => 'PRUEBA-CHAT', 'carpeta' => 1, 'carpeta_nombre' => 'Compras', 'orden' => 1, 'titulo' => 'Escanear facturas de compra', 'para_que' => 'Cargar una factura de compra', 'transcripcion' => 'La compra se revisa antes de confirmar.', 'url' => 'https://videos.test/recurso.mp4', 'duracion' => 50, 'activo' => true]);
        app(RecursosCatalogoService::class)->olvidar();
        config(['services.deepseek.api_key' => 'clave-falsa', 'services.demo_chat.model' => 'deepseek-flash']);
        Http::fake(['api.deepseek.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['contenido' => 'Mirá cómo escanear y revisar la factura.', 'videos' => ['recurso:PRUEBA-CHAT'], 'fuentes' => ['recurso:PRUEBA-CHAT']])]]])]);
        $lead = $this->lead(); $this->mensaje($lead, 'Cómo escanear una factura de compra'); $this->responder($lead);
        $this->getJson('/api/demo-chat', $this->headers($lead))->assertJsonPath('mensajes.2.videos.0.clave', 'recurso:PRUEBA-CHAT');
        Http::assertSent(function ($r) { return $r->url() === 'https://api.deepseek.com/anthropic/v1/messages' && $r['model'] === 'deepseek-flash' && $r['thinking']['type'] === 'disabled'; });
        $this->assertStringNotContainsString('clave-falsa', json_encode(app(DemoChatService::class)->snapshot($lead, true)));
    }

    public function test_sin_deepseek_falla_visible_al_operador_sin_usar_otro_proveedor(): void
    {
        config(['services.deepseek.api_key' => '']);
        $lead = $this->lead(); $this->mensaje($lead); $this->responder($lead);
        Http::assertNothingSent();
        $this->getJson('/api/demo-chat', $this->headers($lead))->assertJsonPath('conversacion.estado', 'error')->assertJsonCount(2, 'mensajes');
        $this->assertNotEmpty(app(DemoChatService::class)->snapshot($lead, true)['conversacion']['ultimo_error']);
    }

    public function test_deepseek_corrige_una_cita_invalida_sin_publicar_el_intento(): void
    {
        config(['services.deepseek.api_key' => 'clave-falsa']);
        $mal = ['contenido' => 'Intento que no debe publicarse.', 'videos' => ['demo:1.1'], 'fuentes' => ['demo:1.1 Crear un artículo']];
        $bien = ['contenido' => 'Mirá cómo crear tu primer artículo.', 'videos' => ['demo:1.1'], 'fuentes' => ['demo:1.1']];
        Http::fake(['api.deepseek.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'text', 'text' => json_encode($mal)]]])
            ->push(['content' => [['type' => 'text', 'text' => json_encode($bien)]]])]);
        $lead = $this->lead(); $this->mensaje($lead, 'Quiero crear un artículo'); $this->responder($lead);
        $this->getJson('/api/demo-chat', $this->headers($lead))->assertJsonPath('mensajes.2.contenido', $bien['contenido']);
        $this->assertDatabaseMissing('demo_chat_mensajes', ['contenido' => $mal['contenido']]);
        Http::assertSentCount(2);
    }

    public function test_deepseek_no_publica_si_la_correccion_tambien_inventa_fuentes(): void
    {
        config(['services.deepseek.api_key' => 'clave-falsa']);
        Http::fake(['api.deepseek.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode([
            'contenido' => 'Afirmación sin respaldo.', 'videos' => [], 'fuentes' => ['manual_no_leido'],
        ])]]])]);
        $lead = $this->lead(); $this->mensaje($lead); $this->responder($lead);
        $this->getJson('/api/demo-chat', $this->headers($lead))->assertJsonPath('conversacion.estado', 'error')->assertJsonCount(2, 'mensajes');
        $this->assertDatabaseMissing('demo_chat_mensajes', ['contenido' => 'Afirmación sin respaldo.']);
        Http::assertSentCount(2);
    }
}
