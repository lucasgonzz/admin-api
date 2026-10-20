<?php

namespace Tests\Feature;

use App\Models\DemoChatConversacion;
use App\Models\DemoChatMensaje;
use App\Models\DemoMedia;
use App\Models\Lead;
use App\Models\RecursosVideo;
use App\Models\SyncedGithubFile;
use App\Services\DemoCatalogoService;
use App\Services\DemoChatService;
use App\Services\RecursosCatalogoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class DemoSelectorVideosTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        RecursosVideo::query()->update(['activo' => false]);
        app(RecursosCatalogoService::class)->olvidar();
        SyncedGithubFile::updateOrCreate(['key' => DemoCatalogoService::SYNCED_FILE_KEY], [
            'repo_path' => 'contexto/demo_catalogo.json',
            'content' => json_encode([
                'orden_secciones' => ['S2 - Ventas', 'S1 - Listado'],
                'clips' => [
                    ['id' => '1.9', 'titulo' => 'Sin publicar', 'seccion' => 'S1 - Listado'],
                    ['id' => '1.2', 'titulo' => 'Primero disponible', 'seccion' => 'S1 - Listado'],
                    ['id' => '1.7', 'titulo' => 'Segundo disponible', 'seccion' => 'S1 - Listado'],
                    ['id' => '1.8', 'titulo' => 'URL inválida', 'seccion' => 'S1 - Listado'],
                    ['id' => '2.8', 'titulo' => 'Una venta', 'seccion' => 'S2 - Ventas'],
                ],
                'videos_sueltos' => [['id' => 'cierre', 'titulo' => 'Cómo seguir']],
                'piezas_scroll' => [['id' => 'scroll', 'titulo' => 'Página comercial']],
            ]),
        ]);
        DemoMedia::whereIn('slot_id', ['1.9', '1.2', '1.7', '1.8', '2.8', 'cierre', 'scroll', 'viejo'])->delete();
        foreach (['1.2', '1.7', '2.8', 'cierre', 'scroll', 'viejo'] as $id) {
            DemoMedia::create(['slot_id' => $id, 'url' => 'https://videos.test/' . $id . '.mp4']);
        }
        DemoMedia::create(['slot_id' => '1.8', 'url' => 'https://videos.test/una-pagina']);
    }

    protected function tearDown(): void
    {
        app(RecursosCatalogoService::class)->olvidar();
        parent::tearDown();
    }

    private function lead(): Lead
    {
        $lead = Lead::create(['uuid' => (string) Str::uuid(), 'contact_name' => 'Demo sintética',
            'demo_experiencia' => Lead::EXPERIENCIA_NUEVA, 'demo_eventos_token' => Str::random(64)]);
        $lead->demo_plan = ['secciones' => [['id' => 'S1 - Listado', 'clips' => [
            ['id' => '1.2', 'titulo' => 'Primero disponible', 'tipo' => 'nucleo'],
            ['id' => 'viejo', 'titulo' => 'Clip retirado', 'tipo' => 'nucleo'],
        ]]]];
        $lead->save();
        return $lead;
    }

    private function headers(Lead $lead): array
    {
        return ['X-Demo-Eventos-Key' => $lead->demo_eventos_token];
    }

    private function seleccionar(Lead $lead, string $clave = 'demo:1.7', ?string $uuid = null, array $extra = [])
    {
        return $this->postJson('/api/demo-chat/videos/seleccionar', array_merge([
            'clave' => $clave, 'cliente_uuid' => $uuid ?: (string) Str::uuid(),
        ], $extra), $this->headers($lead));
    }

    public function test_ordena_y_cuenta_solo_el_catalogo_publicado_sin_confundir_codigo_con_posicion(): void
    {
        $lead = $this->lead();
        $respuesta = $this->getJson('/api/demo-chat', $this->headers($lead))->assertOk()->assertJsonCount(4, 'catalogo');
        $respuesta->assertJsonPath('catalogo.0.clave', 'demo:2.8')
            ->assertJsonPath('catalogo.1.clave', 'demo:1.2')->assertJsonPath('catalogo.1.seccion', 'Listado')
            ->assertJsonPath('catalogo.1.posicion', 1)->assertJsonPath('catalogo.1.cantidad', 2)
            ->assertJsonPath('catalogo.2.clave', 'demo:1.7')->assertJsonPath('catalogo.2.posicion', 2);
        $this->assertDatabaseMissing('demo_chat_conversaciones', ['lead_id' => $lead->id]);
        $claves = array_column($respuesta->json('catalogo'), 'clave');
        foreach (['demo:viejo', 'demo:1.9', 'demo:1.8', 'demo:scroll'] as $clave) {
            $this->assertNotContains($clave, $claves);
        }
    }

    public function test_los_recursos_respetan_carpeta_cero_orden_y_publicacion(): void
    {
        foreach ([['T0.9', 2, true, 'https://videos.test/dos.mp4'], ['T0.7', 1, true, 'https://videos.test/uno.webm'],
            ['T0.8', 0, false, 'https://videos.test/inactivo.mp4'], ['T0.6', 0, true, 'http://videos.test/inseguro.mp4']] as $fila) {
            RecursosVideo::updateOrCreate(['codigo' => $fila[0]], ['titulo' => $fila[0], 'carpeta' => 0, 'carpeta_nombre' => 'Empezá por acá',
                'seccion' => 'Introducción', 'orden' => $fila[1], 'duracion' => 10, 'activo' => $fila[2], 'url' => $fila[3]]);
        }
        $respuesta = $this->getJson('/api/demo-chat', $this->headers($this->lead()))->assertOk();
        $recursos = array_values(array_filter($respuesta->json('catalogo'), function ($video) { return $video['origen'] === 'recurso'; }));
        $this->assertSame(['recurso:T0.7', 'recurso:T0.9'], array_column($recursos, 'clave'));
        $this->assertSame('recurso:0', $recursos[0]['seccion_id']);
        $this->assertSame('Empezá por acá', $recursos[0]['seccion']);
        $this->assertSame(1, $recursos[0]['posicion']);
        $this->assertSame(2, $recursos[0]['cantidad']);
        $this->assertSame(2, $recursos[1]['posicion']);
    }

    public function test_seleccion_idempotente_conserva_progreso_y_queda_visible_en_admin(): void
    {
        $lead = $this->lead();
        app(DemoChatService::class)->configurar($lead, false);
        $uuid = (string) Str::uuid();
        $this->seleccionar($lead, 'demo:1.7', $uuid)->assertOk()->assertJsonCount(2, 'mensajes')
            ->assertJsonPath('mensajes.1.rol', 'asistente')->assertJsonPath('mensajes.1.videos.0.posicion', 2);
        $this->seleccionar($lead, 'demo:1.7', $uuid)->assertOk()->assertJsonCount(2, 'mensajes');
        $this->postJson('/api/demo-chat/videos/progreso', ['clave' => 'demo:1.7', 'segundos_vistos' => [0, 1, 2], 'duracion' => 10], $this->headers($lead))
            ->assertOk()->assertJsonPath('mensajes.1.videos.0.porcentaje', 30);
        $this->seleccionar($lead)->assertOk()->assertJsonCount(3, 'mensajes')->assertJsonPath('mensajes.2.videos.0.porcentaje', 30);
        $admin = app(DemoChatService::class)->snapshot($lead, true);
        $this->assertSame('seleccion', $admin['mensajes'][1]['origen']);
        $this->assertSame('publicado', $admin['mensajes'][1]['estado']);
        $this->assertSame(30, $admin['mensajes'][2]['videos'][0]['porcentaje']);
        Queue::assertNothingPushed();
    }

    public function test_el_token_impone_el_lead_y_el_servidor_impone_el_video(): void
    {
        $lead = $this->lead(); $otro = $this->lead();
        $this->postJson('/api/demo-chat/videos/seleccionar', ['clave' => 'demo:1.7', 'cliente_uuid' => (string) Str::uuid()])->assertUnauthorized();
        $this->seleccionar($lead, 'demo:1.7', null, ['lead_id' => $otro->id, 'url' => 'https://invasor.test/video.mp4'])->assertOk()
            ->assertJsonPath('mensajes.1.videos.0.url', 'https://videos.test/1.7.mp4');
        $this->assertDatabaseMissing('demo_chat_conversaciones', ['lead_id' => $otro->id]);
        foreach (['demo:1.9', 'demo:1.8', 'demo:scroll', 'demo:viejo', 'demo:inexistente'] as $clave) {
            $this->seleccionar($lead, $clave)->assertStatus(422);
        }
        $this->seleccionar($lead, 'demo:1.7', 'sin-uuid')->assertStatus(422);
        $this->assertSame(2, DemoChatMensaje::where('conversacion_id', DemoChatConversacion::where('lead_id', $lead->id)->value('id'))->count());
    }

    public function test_el_clip_retirado_conserva_historial_y_progreso_sin_inventar_ordinal(): void
    {
        $lead = $this->lead(); $service = app(DemoChatService::class);
        $service->iniciar($lead);
        $chat = DemoChatConversacion::where('lead_id', $lead->id)->firstOrFail();
        $chat->mensajes()->create(['rol' => 'asistente', 'contenido' => 'Video del plan previo', 'videos' => ['demo:viejo'], 'origen' => 'guia', 'estado' => 'publicado']);
        $service->progreso($lead, 'demo:viejo', [0, 1], 10);
        $respuesta = $this->getJson('/api/demo-chat', $this->headers($lead))->assertOk();
        $video = $respuesta->json('mensajes.1.videos.0');
        $this->assertSame('demo:viejo', $video['clave']);
        $this->assertSame('Listado', $video['seccion']);
        $this->assertSame(20, $video['porcentaje']);
        $this->assertArrayNotHasKey('posicion', $video);
        $this->assertArrayNotHasKey('cantidad', $video);
    }
}
