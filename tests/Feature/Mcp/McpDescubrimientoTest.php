<?php

namespace Tests\Feature\Mcp;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * CA1 de la misión dots-openclaw: los metadatos de descubrimiento OAuth que ChatGPT lee antes de conectarse
 * (recurso protegido y servidor de autorización), y el interruptor `mcp.enabled`.
 */
class McpDescubrimientoTest extends TestCase
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
     * Metadatos del recurso (con y sin path) y del servidor de autorización (OAuth y OpenID).
     *
     * @return void
     */
    public function test_metadatos_de_recurso_y_servidor(): void
    {
        foreach (['/.well-known/oauth-protected-resource/api/mcp', '/.well-known/oauth-protected-resource'] as $ruta) {
            $recurso = $this->getJson($ruta);
            $recurso->assertStatus(200);
            $this->assertSame('https://api-admin.test/api/mcp', $recurso->json('resource'), $ruta);
            $this->assertSame(['https://api-admin.test'], $recurso->json('authorization_servers'), $ruta);
            $this->assertContains('comerciocity.read', $recurso->json('scopes_supported'), $ruta);
        }

        foreach (['/.well-known/oauth-authorization-server', '/.well-known/openid-configuration'] as $ruta) {
            $servidor = $this->getJson($ruta);
            $servidor->assertStatus(200);
            $this->assertSame('https://api-admin.test', $servidor->json('issuer'), $ruta);
            $this->assertSame(['S256'], $servidor->json('code_challenge_methods_supported'), $ruta);
            $this->assertSame('https://api-admin.test/api/mcp/oauth/register', $servidor->json('registration_endpoint'), $ruta);
            $this->assertSame('https://api-admin.test/mcp/oauth/authorize', $servidor->json('authorization_endpoint'), $ruta);
            $this->assertSame('https://api-admin.test/api/mcp/oauth/token', $servidor->json('token_endpoint'), $ruta);
            $this->assertSame(['none'], $servidor->json('token_endpoint_auth_methods_supported'), $ruta);
            $this->assertTrue($servidor->json('authorization_response_iss_parameter_supported'), $ruta);
            $this->assertContains('offline_access', $servidor->json('scopes_supported'), $ruta);
            $this->assertContains('comerciocity.read', $servidor->json('scopes_supported'), $ruta);
        }
    }

    /**
     * Con `mcp.enabled=false` los cuatro metadatos dan 404 (y, de control, con true dan 200).
     *
     * @return void
     */
    public function test_apagado_da_404(): void
    {
        $rutas = [
            '/.well-known/oauth-protected-resource/api/mcp',
            '/.well-known/oauth-protected-resource',
            '/.well-known/oauth-authorization-server',
            '/.well-known/openid-configuration',
        ];

        foreach ($rutas as $ruta) {
            $this->getJson($ruta)->assertStatus(200);
        }

        config(['mcp.enabled' => false]);

        foreach ($rutas as $ruta) {
            $this->getJson($ruta)->assertStatus(404);
        }
    }
}
