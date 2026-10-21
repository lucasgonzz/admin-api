<?php

namespace Tests\Feature;

use App\Models\DemoMedia;
use App\Models\DemoChatConversacion;
use App\Models\Lead;
use App\Models\RecursosVideo;
use App\Models\SyncedGithubFile;
use App\Services\DemoCatalogoService;
use App\Services\DemoChatCatalogoService;
use App\Services\DemoChatAiService;
use App\Services\DemoChatService;
use App\Services\DemoPlanResolver;
use App\Services\LeadDemoFormMapper;
use App\Services\RecursosCatalogoService;
use App\Services\ManualRepositoryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class DemoCatalogoChatTest extends TestCase
{
    use DatabaseTransactions;

    private function catalogo(): void
    {
        $clips = [];
        foreach (['0.1' => 'Tu demo, acompañada por un asistente', '2.10' => 'Configurá el objetivo y probá a tu agente como cliente',
            '6.1' => 'Tu catálogo, también en tu tienda', '7.1' => 'Tu asistente personal durante la demo',
            '7.2' => 'Cargá una compra con la foto de la factura', '7.3' => 'Creá un artículo desde la foto de su código',
            '7.4' => 'Actualizá precios con una frase'] as $id => $titulo) {
            $seccion = substr($id, 0, 1) === '7' ? 'S7 - Asistente personal' : 'S' . substr($id, 0, 1);
            $clips[] = ['id' => $id, 'seccion' => $seccion, 'titulo' => $titulo, 'tipo' => 'nucleo', 'practica' => false,
                'condicion' => $id === '7.2' ? 'registra_compras' : null, 'evento_esperado' => null];
        }
        SyncedGithubFile::updateOrCreate(['key' => DemoCatalogoService::SYNCED_FILE_KEY], [
            'repo_path' => 'contexto/demo_catalogo.json', 'content' => json_encode(['version' => 7,
                'orden_secciones' => ['S0', 'S2', 'S6', 'S7 - Asistente personal'], 'clips' => $clips,
                'condiciones_secciones' => ['S0' => null, 'S2' => null, 'S6' => null, 'S7 - Asistente personal' => null]]),
        ]);
        DemoMedia::whereIn('slot_id', ['7.1', '7.2', '7.3', '7.4'])->delete();
    }

    public function test_tienda_y_asistente_son_generales_y_la_factura_sigue_el_formulario(): void
    {
        $this->catalogo();
        $lead = new Lead();
        LeadDemoFormMapper::to_lead($lead, ['usa_ecommerce' => false, 'registra_compras' => false]);
        $lead->demo_form_completado_at = now();
        $plan = DemoPlanResolver::resolver($lead);
        $ids = collect($plan['secciones'])->flatMap(function ($seccion) { return array_column($seccion['clips'], 'id'); })->all();
        $this->assertSame(7, $plan['version_catalogo']);
        foreach (['0.1', '2.10', '6.1', '7.1', '7.3', '7.4'] as $id) { $this->assertContains($id, $ids); }
        $this->assertNotContains('7.2', $ids);
        $this->assertSame([], $plan['condiciones_invalidas']);
        $lead->registra_compras = true;
        $con_compras = DemoPlanResolver::resolver($lead);
        $this->assertContains('7.2', collect($con_compras['secciones'])->flatMap(function ($seccion) { return array_column($seccion['clips'], 'id'); })->all());
    }

    public function test_un_clip_nuevo_se_descubre_al_publicarlo_aunque_el_plan_sea_anterior(): void
    {
        $this->catalogo();
        $lead = new Lead();
        $lead->demo_plan = ['version_catalogo' => 4, 'secciones' => [['clips' => [['id' => '1.1', 'tipo' => 'nucleo']]]]];
        $catalogo = app(DemoChatCatalogoService::class);
        $this->assertArrayNotHasKey('demo:7.2', $catalogo->videos($lead));
        DemoMedia::create(['slot_id' => '7.2', 'url' => 'https://videos.test/7.2.mp4']);
        $this->assertArrayHasKey('demo:7.2', $catalogo->videos($lead));
        $this->assertContains('demo:7.2', array_column($catalogo->buscar($lead, 'foto de la factura de compra'), 'clave'));
    }

    public function test_la_tarea_del_agente_prioriza_su_demo_y_los_permisos_conservan_su_recurso(): void
    {
        $this->catalogo();
        DemoMedia::updateOrCreate(['slot_id' => '2.10'], ['url' => 'https://videos.test/agente-demo.mp4']);
        RecursosVideo::query()->update(['activo' => false]);
        RecursosVideo::updateOrCreate(['codigo' => 'PERMISOS-E2E'], ['carpeta' => 1, 'carpeta_nombre' => 'Configuración', 'orden' => 1,
            'titulo' => 'Los permisos: qué habilita cada uno y por qué hay que recargar',
            'para_que' => 'Configurar permisos del agente que responde por WhatsApp a mis clientes.',
            'transcripcion' => 'Los permisos se configuran desde Usuarios. Cada empleado entra con su propia cuenta y sólo ve lo que tiene habilitado. '
                . 'Elegís un usuario, abrís sus permisos y marcás las pantallas que necesita: artículos, ventas, clientes, compras o reportes. '
                . 'Si el agente responde a clientes por WhatsApp, la atención del cliente no habilita por sí sola cambiar precios o borrar una venta. '
                . 'El dueño puede permitir consultar y mantener deshabilitadas las acciones de modificación. Guardá los cambios y pedile al empleado que recargue la página: '
                . 'las pantallas abiertas conservan la configuración anterior hasta recargar. Una prueba con la cuenta del empleado permite revisar qué opciones aparecen. '
                . 'Si el usuario no encuentra un botón, verificá primero la cuenta y sus permisos. No hace falta compartir la contraseña del dueño para atender a los clientes. '
                . 'Los permisos se pueden revisar de nuevo cuando cambian las tareas de cada persona.',
            'url' => 'https://videos.test/permisos.mp4', 'duracion' => 60, 'activo' => true]);
        RecursosVideo::updateOrCreate(['codigo' => 'BANCO-E2E'], ['carpeta' => 2, 'carpeta_nombre' => 'Administración', 'orden' => 2,
            'titulo' => 'Conciliación bancaria', 'para_que' => 'Revisar movimientos bancarios y conciliar diferencias.',
            'transcripcion' => 'El extracto bancario se compara con los movimientos.', 'url' => 'https://videos.test/bancos.mp4', 'duracion' => 40, 'activo' => true]);
        $recursos = app(RecursosCatalogoService::class); $recursos->olvidar();
        try {
            $lead = new Lead(); $catalogo = app(DemoChatCatalogoService::class);
            foreach (['¿Cómo pruebo el agente que responde a mis clientes por WhatsApp?',
                'Quiero probar mi agente como cliente en WhatsApp'] as $pregunta) {
                $hallados = $catalogo->buscar($lead, $pregunta);
                $this->assertSame('demo:2.10', $hallados[0]['clave'], $pregunta);
                $this->assertContains('recurso:PERMISOS-E2E', array_column($hallados, 'clave'));
            }
            $this->assertSame('recurso:PERMISOS-E2E', $catalogo->buscar($lead, '¿Qué permisos habilita cada empleado y por qué hay que recargar?')[0]['clave']);
            $this->assertSame('recurso:BANCO-E2E', $catalogo->buscar($lead, '¿Cómo hago una conciliación bancaria?')[0]['clave']);
            // La petición enviada al modelo debe conservar el contraste y excluir temas ya respondidos.
            // El stub no acredita calidad del modelo: sólo observa el catálogo que recibe.
            $lead = Lead::create(['uuid' => (string) Str::uuid(), 'contact_name' => 'Comercio sintético', 'demo_industria' => 'ferreteria']);
            $lead->demo_plan = ['secciones' => [['clips' => [['id' => '0.1', 'tipo' => 'nucleo']]]]]; $lead->save();
            DemoMedia::updateOrCreate(['slot_id' => '0.1'], ['url' => 'https://videos.test/inicio.mp4']);
            $chat = DemoChatConversacion::create(['lead_id' => $lead->id]);
            foreach ([['lead', 'Permisos habilita recargar empleados permisos recargar'], ['asistente', 'Ya vimos los permisos.'],
                ['lead', '¿Cómo pruebo el agente que responde a mis clientes por WhatsApp?']] as $turno) {
                $chat->mensajes()->create(['rol' => $turno[0], 'contenido' => $turno[1], 'estado' => 'publicado']);
            }
            $this->mock(ManualRepositoryService::class, function ($mock) { $mock->shouldReceive('file_list_cacheada')->andReturn(''); });
            config(['services.deepseek.api_key' => 'sintetica-no-utilizable']);
            $ofrecidos = [];
            Http::fake(['api.deepseek.com/*' => function ($r) use (&$ofrecidos) {
                $json = explode("\nClaves de fuentes", explode("\nVideos disponibles para esta pregunta: ", $r['system'])[1])[0];
                $ofrecidos = json_decode($json, true);
                return Http::response(['content' => [['type' => 'text', 'text' => json_encode(['contenido' => 'Probá la simulación como cliente.', 'videos' => ['demo:2.10'], 'fuentes' => ['demo:2.10']])]]]);
            }]);
            app(DemoChatAiService::class)->responder($lead, $chat);
            Http::assertSentCount(1);
            $this->assertSame('demo:2.10', $ofrecidos[0]['clave']);
            $this->assertContains('recurso:PERMISOS-E2E', array_column($ofrecidos, 'clave'));
            $this->assertNotContains('demo:0.1', array_column($ofrecidos, 'clave'));
            DemoMedia::where('slot_id', '2.10')->delete();
            $this->assertSame('recurso:PERMISOS-E2E', $catalogo->buscar($lead, 'agente WhatsApp clientes')[0]['clave']);
        } finally { $recursos->olvidar(); }
    }

    public function test_elegir_un_video_mientras_espera_no_borra_la_pregunta_que_debe_responder(): void
    {
        $this->catalogo(); Queue::fake();
        RecursosVideo::query()->update(['activo' => false]); $recursos = app(RecursosCatalogoService::class); $recursos->olvidar();
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00'));
        try {
            foreach (['0.1', '2.10'] as $id) { DemoMedia::updateOrCreate(['slot_id' => $id], ['url' => 'https://videos.test/' . $id . '.mp4']); }
            $lead = Lead::create(['uuid' => (string) Str::uuid(), 'contact_name' => 'Demo sintética', 'demo_industria' => 'ferreteria']);
            $lead->demo_plan = ['secciones' => [['clips' => [['id' => '0.1', 'tipo' => 'nucleo']]]]]; $lead->save();
            $service = app(DemoChatService::class);
            $pregunta = '¿Cómo pruebo el agente que responde a mis clientes por WhatsApp?';
            $service->recibir($lead, $pregunta, (string) Str::uuid());
            $chat = DemoChatConversacion::where('lead_id', $lead->id)->firstOrFail(); $generacion = $chat->generacion;
            $service->seleccionar($lead, 'demo:0.1', (string) Str::uuid());
            $chat->refresh(); $this->assertSame('esperando', $chat->estado); $this->assertSame($generacion, $chat->generacion);
            $tarjeta = $chat->mensajes()->where('origen', 'seleccion')->firstOrFail();
            $this->mock(ManualRepositoryService::class, function ($mock) { $mock->shouldReceive('file_list_cacheada')->andReturn(''); });
            config(['services.deepseek.api_key' => 'sintetica-no-utilizable']);
            $ofrecidos = []; $historial = [];
            Http::fake(['api.deepseek.com/*' => function ($r) use (&$ofrecidos, &$historial) {
                $ofrecidos = json_decode(explode("\nClaves de fuentes", explode("\nVideos disponibles para esta pregunta: ", $r['system'])[1])[0], true);
                $historial = json_decode($r['messages'][0]['content'], true)['historial'];
                return Http::response(['content' => [['type' => 'text', 'text' => json_encode(['contenido' => 'Probá la simulación como cliente.', 'videos' => ['demo:2.10'], 'fuentes' => ['demo:2.10']])]]]);
            }]);
            Carbon::setTestNow(now()->addSeconds(11)); $service->generar($chat->id, $generacion);
            Http::assertSentCount(1);
            $this->assertSame('demo:2.10', $ofrecidos[0]['clave']);
            $this->assertNotContains('demo:0.1', array_column($ofrecidos, 'clave'));
            $this->assertContains($pregunta, array_column($historial, 'contenido'));
            $this->assertSame(['rol' => 'asistente', 'contenido' => $tarjeta->contenido, 'videos' => ['demo:0.1']], $historial[count($historial) - 1]);
            $respuesta = $chat->mensajes()->where('origen', 'ia')->firstOrFail();
            $this->assertSame('publicado', $respuesta->estado); $this->assertSame(['demo:2.10'], $respuesta->videos);
            $this->assertSame('inactivo', $chat->fresh()->estado);
        } finally { Carbon::setTestNow(); $recursos->olvidar(); }
    }
}
