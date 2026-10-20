<?php

namespace Tests\Feature;

use App\Models\DemoMedia;
use App\Models\Lead;
use App\Models\SyncedGithubFile;
use App\Services\DemoCatalogoService;
use App\Services\DemoChatCatalogoService;
use App\Services\DemoPlanResolver;
use App\Services\LeadDemoFormMapper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
}
