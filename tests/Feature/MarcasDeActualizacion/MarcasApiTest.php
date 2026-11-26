<?php

namespace Tests\Feature\MarcasDeActualizacion;

use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PanelAgent\AgentePanelTestHelpers;
use Tests\TestCase;

/**
 * CA6 — API del panel de marcas «requiere actualización» (contrato C6): alta idempotente por `external_key`,
 * listado de abiertas con estado y recomendación, resolución manual que apaga `clients.requires_upgrade`, y
 * acceso con el token de un agente (lectura y escritura sin código de confirmación, auditadas).
 */
class MarcasApiTest extends TestCase
{
    use DatabaseTransactions;
    use AgentePanelTestHelpers;

    /** Ruta base del recurso en el panel. */
    private $ruta = '/api/admin/client-upgrade-marks';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-25 13:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_alta_idempotente_listado_y_resolucion_manual_con_token_de_agente(): void
    {
        $cliente = $this->crear_cliente(null, ['company_name' => 'Ferretería Marcas']);
        $agente = $this->crear_agente();
        $token = $this->token_de($agente);
        $marca = [
            'client_id' => $cliente->id, 'title' => 'Remitos con lista de precios propia', 'kind' => 'funcionalidad',
            'external_key' => 'cc:x', 'commits' => ['empresa-api' => str_repeat('e', 40)],
        ];

        $alta = $this->pedir_como($token, 'POST', $this->ruta, $marca)->assertStatus(201)
            ->assertJsonPath('created', true)
            ->assertJsonPath('mark.external_key', 'cc:x')
            ->assertJsonPath('mark.source', 'claude_code')
            ->assertJsonPath('mark.target', 'sistema')
            ->assertJsonPath('mark.estado', 'falta_release');
        $id = $alta->json('mark.id');
        $this->pedir_como($token, 'POST', $this->ruta, array_replace($marca, ['title' => 'Otro título']))->assertStatus(200)
            ->assertJsonPath('created', false)->assertJsonPath('mark.id', $id)
            ->assertJsonPath('mark.title', 'Remitos con lista de precios propia');
        $this->assertSame(1, DB::table('client_upgrade_marks')->where('external_key', 'cc:x')->count());
        $this->assertTrue((bool) Client::findOrFail($cliente->id)->requires_upgrade);

        $listado = $this->pedir_como($token, 'GET', $this->ruta, ['estado' => 'abiertas', 'client_id' => $cliente->id])->assertOk();
        $this->assertSame($cliente->id, $listado->json('clients.0.client_id'));
        $this->assertSame('falta_release', $listado->json('clients.0.recomendacion'));
        $this->assertNull($listado->json('clients.0.version_objetivo'));
        $this->assertSame('falta_release', $listado->json('clients.0.marks.0.estado'));
        $this->assertSame(['empresa-api' => str_repeat('e', 40)], $listado->json('clients.0.marks.0.commits'));
        $this->assertSame(1, $listado->json('totales.clientes'));
        $this->assertSame(1, $listado->json('totales.falta_release'));

        $this->pedir_como($token, 'PATCH', $this->ruta . '/' . $id . '/resolve', ['reason' => 'manual', 'note' => 'Lo instalé a mano.'])
            ->assertOk()->assertJsonPath('mark.resolved_reason', 'manual')->assertJsonPath('mark.estado', 'resuelta');
        $this->assertFalse((bool) Client::findOrFail($cliente->id)->requires_upgrade);
        $this->pedir_como($token, 'GET', $this->ruta, ['client_id' => $cliente->id])->assertOk()->assertJsonCount(0, 'clients');
        $this->pedir_como($token, 'GET', $this->ruta, ['estado' => 'resueltas', 'client_id' => $cliente->id])->assertOk()
            ->assertJsonPath('clients.0.marks.0.resolved_reason', 'manual');

        $auditoria = $this->auditoria_de($agente)->filter(function ($fila) {
            return strpos($fila->route_uri, 'client-upgrade-marks') === 0;
        })->values();
        $this->assertGreaterThanOrEqual(5, $auditoria->count());
        $this->assertSame(['ejecutada'], $auditoria->pluck('result')->unique()->values()->all());
        $this->assertContains('lectura', $auditoria->pluck('category')->all());
        $this->assertContains('escritura', $auditoria->pluck('category')->all());
    }

    public function test_alta_valida_cliente_titulo_y_repos(): void
    {
        $cliente = $this->crear_cliente();
        $token = $this->token_de($this->crear_humano());
        $this->pedir_como($token, 'POST', $this->ruta, ['client_id' => 999999999, 'title' => 'Algo'])->assertStatus(422);
        $this->pedir_como($token, 'POST', $this->ruta, ['client_id' => $cliente->id, 'title' => 'ab'])->assertStatus(422);
        $this->pedir_como($token, 'POST', $this->ruta, ['client_id' => $cliente->id, 'title' => 'Arreglo',
            'commits' => ['admin-api' => str_repeat('a', 40)]])->assertStatus(422);
        $this->pedir_como($token, 'POST', $this->ruta, ['client_id' => $cliente->id, 'title' => 'Arreglo',
            'external_key' => 'con espacios'])->assertStatus(422);
        $this->pedir_como($token, 'POST', $this->ruta, ['client_id' => $cliente->id, 'title' => 'Arreglo de tienda',
            'target' => 'tienda'])->assertStatus(201)->assertJsonPath('mark.estado', 'tienda_manual')
            ->assertJsonPath('mark.source', 'manual');
    }
}
