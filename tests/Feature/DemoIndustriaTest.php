<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Demo;
use App\Models\Lead;
use App\Services\DemoIndustriaCatalogo;
use App\Services\LeadDemoFormMapper;
use App\Services\RunDemoSetupService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DemoIndustriaTest extends TestCase
{
    use DatabaseTransactions;

    private function lead(): Lead
    {
        Queue::fake();
        Http::fake();
        return Lead::create(['uuid' => (string) Str::uuid(), 'contact_name' => 'Silvia',
            'business_type' => 'Mi rubro comercial escrito libremente', 'demo_experiencia' => Lead::EXPERIENCIA_NUEVA]);
    }

    public function test_formulario_publico_guarda_la_industria_y_no_modifica_el_rubro_comercial(): void
    {
        $lead = $this->lead();
        $url = '/api/demo-experiencia/' . $lead->uuid . '/formulario';
        $this->postJson($url, ['demo_industria' => 'autopartes'])->assertOk()->assertJsonPath('formulario.demo_industria', 'autopartes');
        $this->assertSame('autopartes', $lead->fresh()->demo_industria);
        $this->assertSame('Mi rubro comercial escrito libremente', $lead->fresh()->business_type);
        $this->postJson($url, ['usa_presupuestos' => true])->assertOk();
        $this->assertSame('autopartes', $lead->fresh()->demo_industria, 'El formulario viejo no pisa una elección nueva.');
        $this->postJson($url, ['demo_industria' => 'inventada'])->assertStatus(422);
    }

    public function test_formulario_del_admin_valida_y_mapea_los_catorce_rubros(): void
    {
        $lead = $this->lead();
        Sanctum::actingAs(Admin::create(['name' => 'Operador', 'email' => Str::random(12) . '@demo.test', 'password' => 'inaccesible']));
        $ruta = '/api/admin/lead/' . $lead->id . '/demo-form';
        $this->putJson($ruta, ['demo_industria' => 'pesca'])->assertOk();
        $this->assertSame('pesca', LeadDemoFormMapper::respuestas_efectivas($lead->fresh())['demo_industria']);
        $this->putJson($ruta, ['demo_industria' => 'desconocido'])->assertStatus(422);
        $this->assertCount(14, DemoIndustriaCatalogo::OPCIONES);
        foreach (array_keys(DemoIndustriaCatalogo::OPCIONES) as $slug) {
            LeadDemoFormMapper::to_lead($lead, ['demo_industria' => $slug]);
            $this->assertSame($slug, LeadDemoFormMapper::from_lead($lead)['demo_industria']);
        }
    }

    public function test_setup_conserva_el_payload_legado_y_agrega_solo_la_eleccion_opcional(): void
    {
        $lead = $this->lead();
        $lead->setRelation('demo', new Demo(['ecommerce_spa_url' => 'https://tienda-demo.test']));
        $metodo = new \ReflectionMethod(RunDemoSetupService::class, 'build_payload');
        $metodo->setAccessible(true);
        $servicio = app(RunDemoSetupService::class);
        $antes = $metodo->invoke($servicio, $lead);
        $this->assertArrayNotHasKey('demo_industria', $antes);
        $lead->demo_industria = 'belleza';
        $despues = $metodo->invoke($servicio, $lead);
        $this->assertSame('belleza', $despues['demo_industria']);
        unset($despues['demo_industria']);
        $this->assertSame($antes, $despues);
        $this->assertSame('ferreteria', DemoIndustriaCatalogo::normalizar(null));
    }
}
