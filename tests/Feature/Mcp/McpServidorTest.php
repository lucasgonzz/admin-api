<?php

namespace Tests\Feature\Mcp;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CA5 de la misión dots-openclaw: el endpoint `POST /api/mcp` (JSON-RPC sobre HTTP): autenticación con bearer y
 * `WWW-Authenticate`, Origin, negociación de versión, notificaciones, catálogo de herramientas de sólo lectura y
 * errores JSON-RPC.
 */
class McpServidorTest extends TestCase
{
    use DatabaseTransactions;
    use McpFixture;

    /** Las ocho herramientas del conector, en el orden del plan. */
    private static $herramientas = [
        'get_overview', 'list_missions', 'get_mission', 'get_request_status',
        'get_workboard', 'get_scheduled_tasks', 'get_agenda', 'get_mission_queue',
    ];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_mcp();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->soltar_reloj();

        parent::tearDown();
    }

    /**
     * Sin token: 401 con el `WWW-Authenticate` que apunta a los metadatos del recurso y pide el scope.
     *
     * @return void
     */
    public function test_401_con_resource_metadata(): void
    {
        $respuesta = $this->mcp_post(null, $this->rpc('tools/list'));

        $respuesta->assertStatus(401);
        $encabezado = (string) $respuesta->headers->get('WWW-Authenticate');
        $this->assertStringContainsString('Bearer', $encabezado);
        $this->assertStringContainsString('resource_metadata="https://api-admin.test/.well-known/oauth-protected-resource/api/mcp"', $encabezado);
        $this->assertStringContainsString('scope="comerciocity.read"', $encabezado);
    }

    /**
     * Un token vencido (3601 s) da 401 con `error="invalid_token"`; un token con otro `resource` también da 401.
     *
     * @return void
     */
    public function test_token_vencido_y_ajeno(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-26 12:00:00', 'America/Argentina/Buenos_Aires'));
        $token = $this->token_para($this->admin_de_mcp());
        $this->mcp_post($token['access_token'], $this->rpc('tools/list'))->assertStatus(200);

        // Token con un resource ajeno: se pisa la fila para simular uno emitido para otro recurso.
        $ajeno = $this->token_para($this->admin_de_mcp());
        DB::table('mcp_oauth_tokens')->where('access_hash', hash('sha256', $ajeno['access_token']))->update(['resource' => 'https://otro.test/api/mcp']);
        $this->mcp_post($ajeno['access_token'], $this->rpc('tools/list'))->assertStatus(401);

        Carbon::setTestNow(Carbon::parse('2026-11-26 13:00:01', 'America/Argentina/Buenos_Aires'));
        $vencido = $this->mcp_post($token['access_token'], $this->rpc('tools/list'));
        $vencido->assertStatus(401);
        $this->assertStringContainsString('error="invalid_token"', (string) $vencido->headers->get('WWW-Authenticate'));
    }

    /**
     * Un `Origin` fuera de la lista es 403; el de ChatGPT pasa.
     *
     * @return void
     */
    public function test_origin(): void
    {
        $token = $this->token_para($this->admin_de_mcp());

        $this->mcp_post($token['access_token'], $this->rpc('tools/list'), ['Origin' => 'https://evil.com'])->assertStatus(403);
        $this->mcp_post($token['access_token'], $this->rpc('tools/list'), ['Origin' => 'https://chatgpt.com'])->assertStatus(200);
    }

    /**
     * `initialize` negocia la versión del protocolo y devuelve capacidades, `serverInfo` e instrucciones; un
     * `MCP-Protocol-Version` no soportado es 400.
     *
     * @return void
     */
    public function test_initialize_negocia_version(): void
    {
        $token = $this->token_para($this->admin_de_mcp())['access_token'];

        $pedida = $this->mcp_post($token, $this->rpc('initialize', [
            'protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'ChatGPT', 'version' => '1'],
        ]));
        $pedida->assertStatus(200);
        $this->assertSame('2025-06-18', $pedida->json('result.protocolVersion'));
        $this->assertSame('comerciocity-admin', $pedida->json('result.serverInfo.name'));
        $this->assertArrayHasKey('tools', $pedida->json('result.capabilities'));
        $this->assertNotEmpty($pedida->json('result.instructions'));
        $this->assertSame(1, $pedida->json('id'));

        $desconocida = $this->mcp_post($token, $this->rpc('initialize', [
            'protocolVersion' => '1999-01-01', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'ChatGPT', 'version' => '1'],
        ]));
        $desconocida->assertStatus(200);
        $this->assertSame('2025-11-25', $desconocida->json('result.protocolVersion'));

        $this->mcp_post($token, $this->rpc('tools/list'), ['MCP-Protocol-Version' => '1999-01-01'])->assertStatus(400);
        $this->mcp_post($token, $this->rpc('tools/list'), ['MCP-Protocol-Version' => '2025-06-18'])->assertStatus(200);
    }

    /**
     * Una notificación (sin `id`) se responde 202 sin cuerpo.
     *
     * @return void
     */
    public function test_notificacion_202(): void
    {
        $token = $this->token_para($this->admin_de_mcp())['access_token'];

        $respuesta = $this->mcp_post($token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        $respuesta->assertStatus(202);
        $this->assertSame('', (string) $respuesta->getContent());
    }

    /**
     * `tools/list` devuelve exactamente las ocho herramientas, todas de sólo lectura y no destructivas, con título,
     * descripción y esquema de entrada.
     *
     * @return void
     */
    public function test_tools_list_solo_lectura(): void
    {
        $token = $this->token_para($this->admin_de_mcp())['access_token'];

        $respuesta = $this->mcp_post($token, $this->rpc('tools/list'));
        $respuesta->assertStatus(200);

        $herramientas = (array) $respuesta->json('result.tools');
        $nombres      = array_column($herramientas, 'name');
        sort($nombres);
        $esperadas = self::$herramientas;
        sort($esperadas);
        $this->assertSame($esperadas, $nombres);

        foreach ($herramientas as $herramienta) {
            $nombre = $herramienta['name'];
            $this->assertTrue($herramienta['annotations']['readOnlyHint'], $nombre);
            $this->assertFalse($herramienta['annotations']['destructiveHint'], $nombre);
            $this->assertTrue($herramienta['annotations']['idempotentHint'], $nombre);
            $this->assertFalse($herramienta['annotations']['openWorldHint'], $nombre);
            $this->assertNotEmpty($herramienta['title'], $nombre);
            $this->assertNotEmpty($herramienta['description'], $nombre);
            $this->assertSame('object', $herramienta['inputSchema']['type'], $nombre);
        }
    }

    /**
     * Errores JSON-RPC: método desconocido (-32601), cuerpo array (-32600), herramienta desconocida (-32602) y
     * `ping` vacío.
     *
     * @return void
     */
    public function test_errores_jsonrpc(): void
    {
        $token = $this->token_para($this->admin_de_mcp())['access_token'];

        $metodo = $this->mcp_post($token, $this->rpc('foo/bar'));
        $metodo->assertStatus(200);
        $this->assertSame(-32601, $metodo->json('error.code'));

        $lote = $this->mcp_post($token, [$this->rpc('tools/list', [], 1), $this->rpc('tools/list', [], 2)]);
        $this->assertSame(-32600, $lote->json('error.code'));

        $herramienta = $this->mcp_post($token, $this->rpc('tools/call', ['name' => 'borrar_todo', 'arguments' => new \stdClass()]));
        $herramienta->assertStatus(200);
        $this->assertSame(-32602, $herramienta->json('error.code'));

        $ping = $this->mcp_post($token, $this->rpc('ping'));
        $ping->assertStatus(200);
        $this->assertNull($ping->json('error'));
        $this->assertEmpty((array) $ping->json('result'));
    }

    /**
     * `GET` y `DELETE` sobre `/api/mcp` son 405 con `Allow: POST` (no hay stream SSE ni sesión).
     *
     * @return void
     */
    public function test_get_405(): void
    {
        $token = $this->token_para($this->admin_de_mcp())['access_token'];

        $get = $this->getJson('/api/mcp', ['Authorization' => 'Bearer ' . $token]);
        $get->assertStatus(405);
        $this->assertStringContainsString('POST', (string) $get->headers->get('Allow'));

        $this->deleteJson('/api/mcp', [], ['Authorization' => 'Bearer ' . $token])->assertStatus(405);
    }

    /**
     * Si el admin pasa a `solo_leads` después de emitido el token, el token deja de servir (401).
     *
     * @return void
     */
    public function test_admin_restringido_despues(): void
    {
        $admin = $this->admin_de_mcp();
        $token = $this->token_para($admin)['access_token'];
        $this->mcp_post($token, $this->rpc('tools/list'))->assertStatus(200);

        $admin->update(['solo_leads' => 1]);

        $this->mcp_post($token, $this->rpc('tools/list'))->assertStatus(401);
    }
}
