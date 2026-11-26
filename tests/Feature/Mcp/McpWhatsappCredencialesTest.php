<?php

namespace Tests\Feature\Mcp;

use Illuminate\Support\Facades\DB;
use Tests\Feature\WhatsappPersonal\BaseDelNumeroPersonal;

/**
 * CA2 y CA3 de la misión dotin-whatsapp-admin: descubrimiento y consentimiento por recurso, y accesos
 * separados de lectura y envío.
 *
 * Un token queda atado a la audiencia (`resource`) para la que se emitió: sirve sólo en su endpoint. Los tokens
 * del conector de consulta que ya existen NO ganan acceso a WhatsApp, el de lectura no entra al envío y el de
 * envío no entra a la lectura ni a `/api/mcp`.
 */
class McpWhatsappCredencialesTest extends BaseDelNumeroPersonal
{
    use McpFixture, McpWhatsappFixture;

    /**
     * Interruptor prendido, cuentas listas y un admin autorizado a conectar WhatsApp.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_whatsapp_mcp(true);
        $this->sembrar_cuentas_y_chat_personal();
    }

    // ---------------------------------------------------------------------------------------------
    // CA2 — descubrimiento y consentimiento
    // ---------------------------------------------------------------------------------------------

    /**
     * Cada recurso publica su metadato (con su `resource` y su scope) y su 401 sin token apunta a ese metadato.
     *
     * @return void
     */
    public function test_metadatos_y_401_por_recurso(): void
    {
        $recursos = [
            ['ruta' => '/api/mcp/whatsapp', 'resource' => 'https://api-admin.test/api/mcp/whatsapp', 'scope' => 'whatsapp.read', 'otro_scope' => 'whatsapp.send'],
            ['ruta' => '/api/mcp/whatsapp-envio', 'resource' => 'https://api-admin.test/api/mcp/whatsapp-envio', 'scope' => 'whatsapp.send', 'otro_scope' => 'whatsapp.read'],
        ];

        foreach ($recursos as $caso) {
            $metadato = $this->getJson('/.well-known/oauth-protected-resource' . $caso['ruta']);
            $metadato->assertStatus(200);
            $this->assertSame($caso['resource'], $metadato->json('resource'), $caso['ruta']);
            $this->assertSame([$caso['scope']], $metadato->json('scopes_supported'), $caso['ruta']);
            $this->assertSame(['https://api-admin.test'], $metadato->json('authorization_servers'), $caso['ruta']);

            $sin_token = $this->mcp_post_a($caso['ruta'], null, $this->rpc('tools/list'));
            $sin_token->assertStatus(401);
            $encabezado = (string) $sin_token->headers->get('WWW-Authenticate');
            $this->assertStringContainsString('resource_metadata="https://api-admin.test/.well-known/oauth-protected-resource' . $caso['ruta'] . '"', $encabezado, $caso['ruta']);
            $this->assertStringContainsString($caso['scope'], $encabezado, $caso['ruta']);
            $this->assertStringNotContainsString($caso['otro_scope'], $encabezado, $caso['ruta']);
            $this->assertStringNotContainsString('comerciocity.read', $encabezado, $caso['ruta']);
        }

        // El del recurso de consulta no cambia: sigue apuntando a /api/mcp con su scope.
        $consulta = (string) $this->mcp_post(null, $this->rpc('tools/list'))->headers->get('WWW-Authenticate');
        $this->assertStringContainsString('comerciocity.read', $consulta);
        $this->assertStringNotContainsString('whatsapp', $consulta);

        // El servidor de autorización anuncia los dos scopes nuevos además de los de siempre.
        $anunciados = (array) $this->getJson('/.well-known/oauth-authorization-server')->json('scopes_supported');
        foreach (['comerciocity.read', 'offline_access', 'whatsapp.read', 'whatsapp.send'] as $scope) {
            $this->assertContains($scope, $anunciados);
        }
    }

    /**
     * La pantalla de consentimiento dice qué se autoriza según el recurso.
     *
     * @return void
     */
    public function test_pantalla_dice_que_autoriza_por_perfil(): void
    {
        $client_id = $this->registrar_cliente_de_chatgpt();
        list(, $challenge) = $this->par_pkce();

        $lectura = $this->get('/mcp/oauth/authorize?' . http_build_query($this->parametros_de_autorizacion($client_id, $challenge, [
            'resource' => 'https://api-admin.test/api/mcp/whatsapp', 'scope' => 'whatsapp.read',
        ])));
        $lectura->assertStatus(200);
        $lectura->assertSee('Leer tu WhatsApp', false);
        $lectura->assertSee('No marca nada como leído', false);
        $lectura->assertDontSee('Cada envío te pide confirmación', false);

        $envio = $this->get('/mcp/oauth/authorize?' . http_build_query($this->parametros_de_autorizacion($client_id, $challenge, [
            'resource' => 'https://api-admin.test/api/mcp/whatsapp-envio', 'scope' => 'whatsapp.send',
        ])));
        $envio->assertStatus(200);
        $envio->assertSee('Enviar mensajes de WhatsApp', false);
        $envio->assertSee('Cada envío te pide confirmación', false);
        $envio->assertDontSee('No marca nada como leído', false);

        // La pantalla del conector de consulta no se mueve: "Sólo lectura" y nada de WhatsApp.
        $consulta = $this->get('/mcp/oauth/authorize?' . http_build_query($this->parametros_de_autorizacion($client_id, $challenge)));
        $consulta->assertStatus(200);
        $consulta->assertSee('Sólo lectura', false);
        $consulta->assertDontSee('Cada envío te pide confirmación', false);
    }

    /**
     * Sin `resource`, el recurso se infiere del scope pedido (`whatsapp.read` o `whatsapp.send`).
     *
     * @return void
     */
    public function test_resource_ausente_se_infiere_del_scope(): void
    {
        $lectura = $this->token_whatsapp($this->admin_de_whatsapp, 'lectura', ['resource' => null]);
        $this->assertSame(
            ['whatsapp_get_messages', 'whatsapp_list_accounts', 'whatsapp_list_chats'],
            $this->nombres_de_herramientas('/api/mcp/whatsapp', $lectura['access_token'])
        );
        $this->mcp_post_a('/api/mcp/whatsapp-envio', $lectura['access_token'], $this->rpc('tools/list'))->assertStatus(401);

        $envio = $this->token_whatsapp($this->admin_de_whatsapp, 'envio', ['resource' => null]);
        $this->assertSame(
            ['whatsapp_confirm_send', 'whatsapp_prepare_send'],
            $this->nombres_de_herramientas('/api/mcp/whatsapp-envio', $envio['access_token'])
        );
        $this->mcp_post_a('/api/mcp/whatsapp', $envio['access_token'], $this->rpc('tools/list'))->assertStatus(401);
    }

    /**
     * Mezclar scopes de recursos distintos (o pedir el scope de consulta para el recurso de envío) es
     * `invalid_scope`: no se emite ningún código.
     *
     * @return void
     */
    public function test_scope_mezclado_es_invalid_scope(): void
    {
        $client_id = $this->registrar_cliente_de_chatgpt();
        list(, $challenge) = $this->par_pkce();

        $casos = [
            ['resource' => 'https://api-admin.test/api/mcp/whatsapp-envio', 'scope' => 'comerciocity.read'],
            ['resource' => 'https://api-admin.test/api/mcp/whatsapp', 'scope' => 'whatsapp.read whatsapp.send'],
            ['resource' => 'https://api-admin.test/api/mcp/whatsapp', 'scope' => 'whatsapp.send'],
            ['resource' => 'https://api-admin.test/api/mcp', 'scope' => 'whatsapp.read'],
        ];

        foreach ($casos as $cambios) {
            $respuesta = $this->get('/mcp/oauth/authorize?' . http_build_query($this->parametros_de_autorizacion($client_id, $challenge, $cambios)));
            $respuesta->assertStatus(302);
            $consulta = $this->consulta_del_redirect($respuesta);
            $this->assertSame('invalid_scope', $consulta['error'] ?? null, json_encode($cambios));
            $this->assertArrayNotHasKey('code', $consulta, json_encode($cambios));
        }

        $this->assertSame(0, DB::table('mcp_oauth_codes')->count());
    }

    /**
     * Sólo los admins de `mcp.whatsapp_admin_emails` pueden conectar WhatsApp; con la lista vacía, nadie.
     *
     * @return void
     */
    public function test_admin_fuera_de_la_lista_no_conecta_whatsapp(): void
    {
        $client_id = $this->registrar_cliente_de_chatgpt();
        list(, $challenge) = $this->par_pkce();
        $parametros = $this->parametros_de_autorizacion($client_id, $challenge, [
            'resource' => 'https://api-admin.test/api/mcp/whatsapp', 'scope' => 'whatsapp.read',
        ]);

        // Un admin humano cualquiera (no está en la lista de WhatsApp).
        $ajeno = $this->admin_de_mcp();
        $this->enviar_consentimiento($parametros, $ajeno->email, 'clave-segura-mcp-1')
            ->assertStatus(403)
            ->assertSee('Tu usuario no puede conectar WhatsApp.');

        // Lista vacía = nadie, ni siquiera el que estaba.
        config(['mcp.whatsapp_admin_emails' => '']);
        $this->enviar_consentimiento($parametros, $this->admin_de_whatsapp->email, 'clave-segura-mcp-1')
            ->assertStatus(403)
            ->assertSee('Tu usuario no puede conectar WhatsApp.');
        $this->assertSame(0, DB::table('mcp_oauth_codes')->count());

        // Control: en la lista, conecta; y el conector de consulta sigue abierto para el admin ajeno.
        config(['mcp.whatsapp_admin_emails' => $this->admin_de_whatsapp->email]);
        $this->enviar_consentimiento($parametros, $this->admin_de_whatsapp->email, 'clave-segura-mcp-1')->assertStatus(302);
        $this->assertSame(1, DB::table('mcp_oauth_codes')->count());
        $this->token_para($ajeno);
    }

    // ---------------------------------------------------------------------------------------------
    // CA3 — accesos separados
    // ---------------------------------------------------------------------------------------------

    /**
     * Un token del conector de consulta (el que ya usa ChatGPT) no entra a ninguno de los dos endpoints nuevos.
     *
     * @return void
     */
    public function test_token_de_consulta_no_entra_a_whatsapp(): void
    {
        $consulta = $this->token_para($this->admin_de_whatsapp);

        // Control: en su endpoint sigue sirviendo.
        $this->mcp_post($consulta['access_token'], $this->rpc('tools/list'))->assertStatus(200);

        foreach (['/api/mcp/whatsapp', '/api/mcp/whatsapp-envio'] as $ruta) {
            $respuesta = $this->mcp_post_a($ruta, $consulta['access_token'], $this->rpc('tools/list'));
            $respuesta->assertStatus(401);
            $this->assertStringContainsString('error="invalid_token"', (string) $respuesta->headers->get('WWW-Authenticate'), $ruta);

            $llamada = $this->mcp_post_a($ruta, $consulta['access_token'], $this->rpc('tools/call', ['name' => 'whatsapp_list_accounts', 'arguments' => new \stdClass()]));
            $llamada->assertStatus(401);
        }
    }

    /**
     * Token de lectura: sólo sirve en su endpoint, ve exactamente las tres herramientas de lectura y no puede
     * llamar a las de envío.
     *
     * @return void
     */
    public function test_token_de_lectura_no_entra_al_envio_ni_ve_sus_herramientas(): void
    {
        $lectura = $this->token_whatsapp($this->admin_de_whatsapp, 'lectura');

        // En el endpoint de envío ni siquiera llega a tools/list.
        $this->mcp_post_a('/api/mcp/whatsapp-envio', $lectura['access_token'], $this->rpc('tools/list'))->assertStatus(401);
        $this->mcp_post_a('/api/mcp/whatsapp-envio', $lectura['access_token'], $this->rpc('tools/call', ['name' => 'whatsapp_prepare_send', 'arguments' => new \stdClass()]))
            ->assertStatus(401);

        // En el suyo: exactamente las tres de lectura.
        $this->assertSame(
            ['whatsapp_get_messages', 'whatsapp_list_accounts', 'whatsapp_list_chats'],
            $this->nombres_de_herramientas('/api/mcp/whatsapp', $lectura['access_token'])
        );

        // Y una herramienta de envío llamada en el endpoint de lectura es "herramienta desconocida" (-32602).
        foreach (['whatsapp_confirm_send', 'whatsapp_prepare_send'] as $nombre) {
            $respuesta = $this->mcp_post_a('/api/mcp/whatsapp', $lectura['access_token'], $this->rpc('tools/call', ['name' => $nombre, 'arguments' => new \stdClass()]));
            $this->assertSame(-32602, $respuesta->json('error.code'), $nombre . ': ' . $respuesta->getContent());
        }

        // Tampoco sirve en el conector de consulta.
        $this->mcp_post($lectura['access_token'], $this->rpc('tools/list'))->assertStatus(401);
    }

    /**
     * Token de envío: sólo sirve en su endpoint (no en lectura ni en `/api/mcp`) y ve únicamente preparar y confirmar.
     *
     * @return void
     */
    public function test_token_de_envio_solo_ve_preparar_y_confirmar(): void
    {
        $envio = $this->token_whatsapp($this->admin_de_whatsapp, 'envio');

        $this->mcp_post_a('/api/mcp/whatsapp', $envio['access_token'], $this->rpc('tools/list'))->assertStatus(401);
        $this->mcp_post($envio['access_token'], $this->rpc('tools/list'))->assertStatus(401);

        $this->assertSame(
            ['whatsapp_confirm_send', 'whatsapp_prepare_send'],
            $this->nombres_de_herramientas('/api/mcp/whatsapp-envio', $envio['access_token'])
        );

        // Las de lectura llamadas en el endpoint de envío: herramienta desconocida.
        $respuesta = $this->mcp_post_a('/api/mcp/whatsapp-envio', $envio['access_token'], $this->rpc('tools/call', ['name' => 'whatsapp_get_messages', 'arguments' => new \stdClass()]));
        $this->assertSame(-32602, $respuesta->json('error.code'), $respuesta->getContent());
    }
}
