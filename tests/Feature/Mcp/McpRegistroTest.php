<?php

namespace Tests\Feature\Mcp;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CA2 de la misión dots-openclaw: el registro dinámico de clientes (DCR) sólo acepta redirects https del host
 * de ChatGPT, sin fragmento.
 */
class McpRegistroTest extends TestCase
{
    use DatabaseTransactions;
    use McpFixture;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_mcp();
    }

    /**
     * El redirect de ChatGPT se registra: 201, `client_id` con prefijo `ccm_c_` y una fila nueva.
     *
     * @return void
     */
    public function test_registra_redirect_de_chatgpt(): void
    {
        $antes = DB::table('mcp_oauth_clients')->count();

        $respuesta = $this->postJson('/api/mcp/oauth/register', [
            'redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect'],
            'client_name'   => 'ChatGPT',
        ]);

        $respuesta->assertStatus(201);
        $this->assertStringStartsWith('ccm_c_', (string) $respuesta->json('client_id'));
        $this->assertSame(['https://chatgpt.com/connector_platform_oauth_redirect'], $respuesta->json('redirect_uris'));
        $this->assertSame('none', $respuesta->json('token_endpoint_auth_method'));
        $this->assertSame($antes + 1, DB::table('mcp_oauth_clients')->count());
        $this->assertSame(1, DB::table('mcp_oauth_clients')->where('client_id', $respuesta->json('client_id'))->count());
    }

    /**
     * http, otro host, fragmento y lista vacía: 400 y ninguna fila.
     *
     * @return void
     */
    public function test_rechaza_redirects_invalidos(): void
    {
        $antes = DB::table('mcp_oauth_clients')->count();

        $casos = [
            'http en vez de https' => ['http://chatgpt.com/x'],
            'host que no es de ChatGPT' => ['https://evil.com/cb'],
            'con fragmento' => ['https://chatgpt.com/cb#f'],
            'lista vacía' => [],
        ];

        foreach ($casos as $nombre => $uris) {
            $respuesta = $this->postJson('/api/mcp/oauth/register', ['redirect_uris' => $uris, 'client_name' => 'Intruso']);
            $respuesta->assertStatus(400);
            $this->assertContains($respuesta->json('error'), ['invalid_redirect_uri', 'invalid_client_metadata'], $nombre);
        }

        $this->assertSame($antes, DB::table('mcp_oauth_clients')->count(), 'Ningún registro inválido puede dejar una fila.');
    }
}
