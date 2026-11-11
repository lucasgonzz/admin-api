<?php

namespace Tests\Feature\Prueba;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadPipelineStatus;
use App\Models\TrialInstance;
use App\Services\DemoChatAiService;
use App\Services\DemoChatCatalogoService;
use App\Services\DemoChatService;
use App\Services\ManualRepositoryService;
use App\Services\Prueba\PruebaCicloService;
use App\Services\Prueba\PruebaVideoCatalogoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogoVideosDePruebaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_keys(PruebaVideoCatalogoService::PIEZAS) as $codigo) {
            AdminSetting::where('key', PruebaVideoCatalogoService::key($codigo))->delete();
        }
        $this->mock(DemoChatCatalogoService::class)->shouldNotReceive('videos');
    }

    public function test_el_catalogo_es_optativo_y_no_acepta_piezas_fuera_de_las_seis_practicas(): void
    {
        AdminSetting::set('prueba_video_url', 'https://videos.test/anterior.mp4');
        AdminSetting::set(PruebaVideoCatalogoService::key('03'), 'https://videos.test/bienvenida.mp4');
        AdminSetting::set(PruebaVideoCatalogoService::key('10'), 'https://videos.test/seguimiento.mp4');
        $this->assertSame([], app(PruebaVideoCatalogoService::class)->videos());

        foreach (array_keys(PruebaVideoCatalogoService::PIEZAS) as $codigo) {
            AdminSetting::set(PruebaVideoCatalogoService::key($codigo), ' ' . PruebaVideoCatalogoService::URLS_APROBADAS[$codigo] . ' ');
        }
        $videos = app(PruebaVideoCatalogoService::class)->videos();
        $this->assertSame(['prueba:04', 'prueba:05', 'prueba:06', 'prueba:07', 'prueba:08', 'prueba:09'], array_keys($videos));
        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($videos, 'posicion'));
        $this->assertSame([6, 6, 6, 6, 6, 6], array_column($videos, 'cantidad'));
        $this->assertSame(PruebaVideoCatalogoService::URLS_APROBADAS['04'], $videos['prueba:04']['url']);
    }

    /** @dataProvider urls_no_publicables */
    public function test_no_expone_urls_distintas_de_la_pieza_exacta_aprobada(string $url): void
    {
        AdminSetting::set(PruebaVideoCatalogoService::key('04'), $url);
        AdminSetting::set(PruebaVideoCatalogoService::key('08'), PruebaVideoCatalogoService::URLS_APROBADAS['08']);
        $videos = app(PruebaVideoCatalogoService::class)->videos();
        $this->assertSame(['prueba:08'], array_keys($videos));
        $this->assertSame(1, $videos['prueba:08']['posicion']);
        $this->assertSame(1, $videos['prueba:08']['cantidad']);
    }

    public function urls_no_publicables(): array
    {
        return [[''], ['  '], ['http://videos.test/video.mp4'], ['https://usuario:clave@videos.test/video.mp4'],
            ['https://videos.test/pagina.html'], ['javascript:alert(1)'], ['/video.mp4'], ['https://videos.test/video con espacios.mp4'],
            [PruebaVideoCatalogoService::URLS_APROBADAS['04'] . '?otro=1'],
            [PruebaVideoCatalogoService::URLS_APROBADAS['04'] . '#fragmento'],
            [str_replace('videos.comerciocity.store', 'otro.test', PruebaVideoCatalogoService::URLS_APROBADAS['04'])],
            [str_replace('/20261111/', '/20261107/', PruebaVideoCatalogoService::URLS_APROBADAS['04'])],
            [str_replace('https://', 'https://usuario:clave@', PruebaVideoCatalogoService::URLS_APROBADAS['04'])],
            [PruebaVideoCatalogoService::URLS_APROBADAS['05']]];
    }

    public function test_snapshot_transporta_catalogo_y_adjuntos_propios_sin_cambiar_el_estado_del_negocio(): void
    {
        [$lead, $client] = $this->prueba();
        $headers = ['X-Demo-Eventos-Key' => $lead->demo_eventos_token, 'X-Admin-Api-Key' => $client->inbound_api_key];
        AdminSetting::set(PruebaVideoCatalogoService::key('04'), PruebaVideoCatalogoService::URLS_APROBADAS['04']);
        $service = app(DemoChatService::class);
        $chat = $service->conversacion($lead, $client->id);
        $chat->mensajes()->create(['rol' => 'asistente', 'contenido' => 'Elegí qué querés explorar.',
            'videos' => ['prueba:04', 'demo:0.1', 'recurso:0.1', 'prueba:10'], 'origen' => 'ia', 'estado' => 'publicado']);
        $antes = $client->fresh()->toArray();

        $respuesta = $this->getJson('/api/prueba-chat', $headers)->assertOk()
            ->assertJsonCount(1, 'catalogo')->assertJsonPath('catalogo.0.clave', 'prueba:04')
            ->assertJsonPath('catalogo.0.completado', false)->assertJsonPath('catalogo.0.porcentaje', 0)
            ->assertJsonCount(1, 'mensajes.0.videos')->assertJsonPath('mensajes.0.videos.0.clave', 'prueba:04');
        $this->assertArrayNotHasKey('descripcion', $respuesta->json('catalogo.0'));
        $this->assertSame($antes, $client->fresh()->toArray());
        $this->assertSame(0, $chat->progresos()->count());
        $this->assertSame(1, $chat->mensajes()->count());
        $this->postJson('/api/prueba-chat/videos/seleccionar', [], $headers)->assertNotFound();
        $this->postJson('/api/prueba-chat/videos/progreso', [], $headers)->assertNotFound();

        AdminSetting::set(PruebaVideoCatalogoService::key('04'), '');
        $this->getJson('/api/prueba-chat', $headers)->assertOk()->assertJsonCount(0, 'catalogo')->assertJsonCount(0, 'mensajes.0.videos');
        $this->assertSame(1, $chat->mensajes()->count(), 'Retirar un video no borra el historial.');
    }

    public function test_la_ia_adjunta_solo_un_video_de_prueba_y_no_habilita_guias_ni_herramientas_de_accion(): void
    {
        [$lead, $client] = $this->prueba();
        $chat = app(DemoChatService::class)->conversacion($lead, $client->id);
        AdminSetting::set(PruebaVideoCatalogoService::key('05'), PruebaVideoCatalogoService::URLS_APROBADAS['05']);
        $this->preparar_ia(['prueba:05'], ['prueba:05']);

        $salida = app(DemoChatAiService::class)->responder($lead, $chat);
        $this->assertSame(['prueba:05'], $salida['videos']);
        $this->assertArrayNotHasKey('guia', $salida);
        Http::assertSent(function ($request) {
            return array_column($request['tools'], 'name') === ['leer_manual']
                && strpos($request['system'], 'prueba:05') !== false
                && strpos($request['system'], 'demo:0.1') === false
                && strpos($request['system'], 'No avances una secuencia automáticamente') !== false;
        });
    }

    /** @dataProvider claves_ajenas */
    public function test_la_ia_rechaza_claves_de_demo_recursos_o_videos_no_configurados(string $clave): void
    {
        [$lead, $client] = $this->prueba();
        $chat = app(DemoChatService::class)->conversacion($lead, $client->id);
        AdminSetting::set(PruebaVideoCatalogoService::key('04'), PruebaVideoCatalogoService::URLS_APROBADAS['04']);
        $this->preparar_ia([$clave], ['prueba']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('video no consultado');
        app(DemoChatAiService::class)->responder($lead, $chat);
    }

    public function claves_ajenas(): array
    {
        return [['demo:0.1'], ['recurso:0.1'], ['prueba:05'], ['prueba:10'], ['https://videos.test/inventado.mp4']];
    }

    private function preparar_ia(array $videos, array $fuentes): void
    {
        config(['services.deepseek.api_key' => 'clave-falsa']);
        $this->mock(ManualRepositoryService::class)->shouldReceive('file_list_cacheada')->once()->andReturn('manual_sistema/README.md');
        Http::fake(['api.deepseek.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode([
            'contenido' => 'Podés mirar este video y después elegir qué probar.', 'videos' => $videos, 'fuentes' => $fuentes,
            'guia' => ['accion' => 'iniciar', 'pasos' => [['id' => 'abrir_buscador', 'texto' => 'Abrí el buscador']]],
        ])]]])]);
    }

    private function prueba(): array
    {
        $client = Client::create(['name' => 'Negocio sintético', 'slug' => 'video-' . Str::random(12),
            'api_url' => '', 'api_key' => Str::random(40), 'inbound_api_key' => Str::random(40),
            'estado' => PruebaCicloService::CLIENTE_PRUEBA, 'is_active' => true, 'trial_ends_at' => now()->addDays(7)]);
        $lead = Lead::create(['uuid' => (string) Str::uuid(), 'contact_name' => 'Julia',
            'status' => LeadPipelineStatus::PRUEBA_ACTIVA, 'promoted_client_id' => $client->id, 'demo_eventos_token' => Str::random(64)]);
        TrialInstance::create(['nombre' => 'video-' . Str::random(12), 'estado' => TrialInstance::ESTADO_ASIGNADA,
            'lead_id' => $lead->id, 'client_id' => $client->id, 'erp_vps_path' => 'prueba-sintetica', 'ecommerce_vps_path' => 'tienda-sintetica']);

        return [$lead, $client];
    }
}
