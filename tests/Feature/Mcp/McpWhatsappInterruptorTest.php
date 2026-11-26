<?php

namespace Tests\Feature\Mcp;

use Tests\Feature\WhatsappPersonal\BaseDelNumeroPersonal;

/**
 * CA1 de la misión dotin-whatsapp-admin: el acceso directo a WhatsApp nace APAGADO (`mcp.whatsapp_enabled`).
 *
 * Con el interruptor apagado un deploy es inerte: los dos endpoints y sus metadatos dan 404, la pantalla de
 * consentimiento rechaza el recurso, los scopes de WhatsApp no se anuncian y un token de WhatsApp ya emitido
 * deja de servir. El conector de consulta (`/api/mcp`) no cambia en ningún caso (eso lo cubren los tests
 * existentes de `tests/Feature/Mcp`, CA12).
 *
 * Cada caso de "apagado" lleva antes su control "prendido": un 404 o un `invalid_target` que ya existían hoy no
 * pueden hacer pasar el test sin que la funcionalidad exista.
 */
class McpWhatsappInterruptorTest extends BaseDelNumeroPersonal
{
    use McpFixture, McpWhatsappFixture;

    /**
     * Deja a los números listos; cada test decide si prende o no el interruptor.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->sembrar_cuentas_y_chat_personal();
    }

    /**
     * Por defecto está apagado y, apagado, los endpoints y los metadatos de WhatsApp dan 404.
     *
     * @return void
     */
    public function test_apagado_por_defecto_los_endpoints_y_metadatos_dan_404(): void
    {
        // El default de la config (antes de pisar nada) tiene que ser exactamente false.
        $this->assertSame(false, config('mcp.whatsapp_enabled'), 'mcp.whatsapp_enabled tiene que existir y valer false por defecto.');

        // Control: prendido, los endpoints responden (401 sin token) y los metadatos existen (200).
        $this->preparar_whatsapp_mcp(true);
        $endpoints = ['/api/mcp/whatsapp', '/api/mcp/whatsapp-envio'];
        $metadatos = ['/.well-known/oauth-protected-resource/api/mcp/whatsapp', '/.well-known/oauth-protected-resource/api/mcp/whatsapp-envio'];
        foreach ($endpoints as $ruta) {
            $this->mcp_post_a($ruta, null, $this->rpc('tools/list'))->assertStatus(401);
        }
        foreach ($metadatos as $ruta) {
            $this->getJson($ruta)->assertStatus(200);
        }

        // Apagado: todo da 404.
        config(['mcp.whatsapp_enabled' => false]);
        foreach ($endpoints as $ruta) {
            $this->mcp_post_a($ruta, null, $this->rpc('tools/list'))->assertStatus(404);
        }
        foreach ($metadatos as $ruta) {
            $this->getJson($ruta)->assertStatus(404);
        }

        // El conector de consulta sigue pidiendo token como siempre.
        $this->mcp_post(null, $this->rpc('tools/list'))->assertStatus(401);
    }

    /**
     * Apagado, autorizar con el recurso de WhatsApp es `invalid_target` y los scopes de WhatsApp no se anuncian.
     *
     * @return void
     */
    public function test_autorizar_whatsapp_apagado_es_invalid_target(): void
    {
        $this->preparar_whatsapp_mcp(true);
        $client_id = $this->registrar_cliente_de_chatgpt();
        list(, $challenge) = $this->par_pkce();

        $cambios_de_lectura = ['resource' => 'https://api-admin.test/api/mcp/whatsapp', 'scope' => 'whatsapp.read'];
        $cambios_de_envio   = ['resource' => 'https://api-admin.test/api/mcp/whatsapp-envio', 'scope' => 'whatsapp.send'];

        // Control: prendido, la pantalla de consentimiento de WhatsApp abre (200) y el servidor anuncia los scopes.
        foreach ([$cambios_de_lectura, $cambios_de_envio] as $cambios) {
            $this->get('/mcp/oauth/authorize?' . http_build_query($this->parametros_de_autorizacion($client_id, $challenge, $cambios)))
                ->assertStatus(200);
        }
        $anunciados = (array) $this->getJson('/.well-known/oauth-authorization-server')->json('scopes_supported');
        $this->assertContains('whatsapp.read', $anunciados);
        $this->assertContains('whatsapp.send', $anunciados);

        // Apagado: 302 con error=invalid_target (con el recurso de WhatsApp) y sin scopes de WhatsApp anunciados.
        config(['mcp.whatsapp_enabled' => false]);
        foreach ([$cambios_de_lectura, $cambios_de_envio] as $cambios) {
            $respuesta = $this->get('/mcp/oauth/authorize?' . http_build_query($this->parametros_de_autorizacion($client_id, $challenge, $cambios)));
            $respuesta->assertStatus(302);
            $consulta = $this->consulta_del_redirect($respuesta);
            $this->assertSame('invalid_target', $consulta['error'] ?? null, json_encode($cambios));
            $this->assertArrayNotHasKey('code', $consulta);
        }

        $anunciados = (array) $this->getJson('/.well-known/oauth-authorization-server')->json('scopes_supported');
        $this->assertNotContains('whatsapp.read', $anunciados);
        $this->assertNotContains('whatsapp.send', $anunciados);
        $this->assertContains('comerciocity.read', $anunciados);
    }

    /**
     * Un token de WhatsApp emitido con el interruptor prendido deja de servir al apagarlo.
     *
     * El plan pide 401 (CA1) pero también 404 para el endpoint apagado (§2.1): se acepta cualquiera de los dos,
     * lo que NO se acepta es que siga sirviendo. Queda anotado en Pendientes.
     *
     * @return void
     */
    public function test_token_emitido_deja_de_servir_al_apagar(): void
    {
        $this->preparar_whatsapp_mcp(true);
        $lectura = $this->token_whatsapp($this->admin_de_whatsapp, 'lectura');
        $envio   = $this->token_whatsapp($this->admin_de_whatsapp, 'envio');

        // Control: prendido sirven.
        $this->mcp_post_a('/api/mcp/whatsapp', $lectura['access_token'], $this->rpc('tools/list'))->assertStatus(200);
        $this->mcp_post_a('/api/mcp/whatsapp-envio', $envio['access_token'], $this->rpc('tools/list'))->assertStatus(200);

        config(['mcp.whatsapp_enabled' => false]);

        $con_lectura = $this->mcp_post_a('/api/mcp/whatsapp', $lectura['access_token'], $this->rpc('tools/list'));
        $con_envio   = $this->mcp_post_a('/api/mcp/whatsapp-envio', $envio['access_token'], $this->rpc('tools/list'));
        $this->assertContains($con_lectura->getStatusCode(), [401, 404], 'El token de lectura no puede servir con el interruptor apagado.');
        $this->assertContains($con_envio->getStatusCode(), [401, 404], 'El token de envío no puede servir con el interruptor apagado.');

        // Y el token viejo de consulta (otro recurso) no gana ni pierde nada: sigue sirviendo en /api/mcp.
        $consulta = $this->token_para($this->admin_de_mcp());
        $this->mcp_post($consulta['access_token'], $this->rpc('tools/list'))->assertStatus(200);
    }
}
