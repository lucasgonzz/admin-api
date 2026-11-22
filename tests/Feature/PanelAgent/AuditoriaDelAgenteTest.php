<?php

namespace Tests\Feature\PanelAgent;

use App\Services\PanelAgent\PanelAgentAuditLogger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * Misión admin-control-total — la auditoría del agente: sin secretos, legible por API con filtros, y
 * fail-closed (si no se puede auditar, las escrituras no se ejecutan).
 *
 * Cubre CA9, CA11 y CA15 del PLAN.
 */
class AuditoriaDelAgenteTest extends TestCase
{
    use DatabaseTransactions;
    use AgentePanelTestHelpers;

    /**
     * CA9 — Ni el payload ni el extracto de respuesta guardan secretos; lo inocente sigue visible.
     *
     * @return void
     */
    public function test_la_auditoria_no_guarda_secretos(): void
    {
        $agente  = $this->crear_agente();
        $token   = $this->token_de($agente);
        // El cliente ya tiene una api_key propia: si la respuesta del panel la incluye, tampoco puede quedar en la auditoría.
        $cliente = $this->crear_cliente(null, ['api_key' => 'SECRETO-CA9-D']);

        $respuesta = $this->pedir_como($token, 'PUT', '/api/admin/client/' . $cliente->id, [
            'is_active' => true,
            'api_key'   => 'SECRETO-CA9-A',
            'extra'     => [
                'password' => 'SECRETO-CA9-B',
                'lista'    => [['token' => 'SECRETO-CA9-C']],
            ],
        ]);
        $respuesta->assertOk();

        $fila = $this->ultima_auditoria_de($agente);
        $this->assertNotNull($fila);

        foreach (['SECRETO-CA9-A', 'SECRETO-CA9-B', 'SECRETO-CA9-C', 'SECRETO-CA9-D'] as $secreto) {
            $this->assertFalse($this->contiene($fila->request_payload, $secreto), "$secreto no puede estar en request_payload.");
            $this->assertFalse($this->contiene($fila->response_excerpt, $secreto), "$secreto no puede estar en response_excerpt.");
        }

        $this->assertTrue($this->contiene($fila->request_payload, '[REDACTADO]'), 'El payload muestra que se redactó algo.');
        $this->assertTrue($this->contiene($fila->request_payload, '"is_active":true'), 'Lo inocente sigue visible.');
        $this->assertNotEmpty($fila->response_excerpt, 'La respuesta JSON queda como extracto.');

        // Si el panel devolvió la api_key del cliente, el extracto la tiene que traer redactada.
        if ($this->contiene($respuesta->getContent(), 'SECRETO-CA9-D')) {
            $this->assertTrue($this->contiene($fila->response_excerpt, '[REDACTADO]'), 'La clave devuelta por el panel se redacta en el extracto.');
        }
    }

    /**
     * CA11 — La auditoría se lee por API: orden id desc, límite y filtros; un humano también puede leerla.
     *
     * Quien lee es el humano (el agente que lee se auditaría a sí mismo y contaminaría la cuenta).
     *
     * @return void
     */
    public function test_la_auditoria_se_lee_por_api_con_filtros(): void
    {
        $agente   = $this->crear_agente();
        $token    = $this->token_de($agente);
        $humano   = $this->crear_humano();
        $token_h  = $this->token_de($humano);
        $variante = $this->crear_variante();

        $this->pedir_como($token, 'GET', '/api/admin/client')->assertOk();
        $this->pedir_como($token, 'GET', '/api/admin/me')->assertOk();
        $this->pedir_como($token, 'DELETE', '/api/admin/message-variant/' . $variante->id)->assertStatus(428);

        $todas = $this->auditoria_de($agente);
        $this->assertCount(3, $todas);
        $ultimo_id = $todas->last()->id;

        // Las 2 más nuevas, con la forma que lee el MCP.
        $dos = $this->pedir_como($token_h, 'GET', '/api/admin/agente/auditoria', ['admin_id' => $agente->id, 'limite' => 2]);
        $dos->assertOk();
        $filas = $dos->json('auditoria');
        $this->assertCount(2, $filas, 'limite=2 devuelve dos filas.');
        $this->assertSame((int) $ultimo_id, (int) $filas[0]['id'], 'Orden id desc: primero la más nueva.');
        $this->assertGreaterThan((int) $filas[1]['id'], (int) $filas[0]['id']);
        foreach (['id', 'metodo', 'ruta', 'categoria', 'resultado', 'status_code', 'created_at'] as $clave) {
            $this->assertArrayHasKey($clave, $filas[0], "Cada fila trae $clave.");
        }
        $this->assertSame('DELETE', $filas[0]['metodo']);
        $this->assertSame('message-variant/{id}', $filas[0]['ruta']);
        $this->assertSame('borrado', $filas[0]['categoria']);
        $this->assertSame('frenada', $filas[0]['resultado']);
        $this->assertSame(428, (int) $filas[0]['status_code']);

        // Filtro por resultado.
        $frenadas = $this->pedir_como($token_h, 'GET', '/api/admin/agente/auditoria', ['admin_id' => $agente->id, 'resultado' => 'frenada']);
        $frenadas->assertOk();
        $this->assertCount(1, $frenadas->json('auditoria'));
        $this->assertSame('frenada', $frenadas->json('auditoria.0.resultado'));

        // Filtro por categoría.
        $lecturas = $this->pedir_como($token_h, 'GET', '/api/admin/agente/auditoria', ['admin_id' => $agente->id, 'categoria' => 'lectura']);
        $lecturas->assertOk();
        $this->assertCount(2, $lecturas->json('auditoria'));
    }

    /**
     * CA15 — Si la auditoría no puede abrirse, las escrituras quedan cerradas (503) y las lecturas siguen.
     *
     * @return void
     */
    public function test_sin_auditoria_las_escrituras_quedan_cerradas(): void
    {
        $this->assertTrue(class_exists(PanelAgentAuditLogger::class), 'Falta el servicio PanelAgentAuditLogger.');

        $agente  = $this->crear_agente();
        $token   = $this->token_de($agente);
        $cliente = $this->crear_cliente();

        // Un auditor que revienta al abrir la fila (disco lleno, tabla caída…).
        $auditor_roto = Mockery::mock(PanelAgentAuditLogger::class)->shouldIgnoreMissing();
        $auditor_roto->shouldReceive('open')->andThrow(new \RuntimeException('auditoría caída (simulada por el test)'));
        $this->app->instance(PanelAgentAuditLogger::class, $auditor_roto);

        $escritura = $this->pedir_como($token, 'PUT', '/api/admin/client/' . $cliente->id, ['is_active' => false]);
        $escritura->assertStatus(503);
        $escritura->assertJsonPath('code', 'agente_auditoria_no_disponible');
        $this->assertSame(1, (int) DB::table('clients')->where('id', $cliente->id)->value('is_active'), 'La escritura no se ejecutó.');

        // Una lectura sigue funcionando aunque no se pueda auditar.
        $this->pedir_como($token, 'GET', '/api/admin/client')->assertOk();
    }
}
