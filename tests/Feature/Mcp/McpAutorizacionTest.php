<?php

namespace Tests\Feature\Mcp;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CA3 de la misión dots-openclaw: la pantalla de consentimiento (GET y POST de `/mcp/oauth/authorize`).
 *
 * Hasta validar cliente y redirect un error se muestra en la página (400) y nunca redirige; después los errores
 * redirigen con `error`, `state` e `iss`. El POST se revalida entero y emite un código de un solo uso.
 */
class McpAutorizacionTest extends TestCase
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
     * El GET válido muestra el nombre del cliente registrado y el host del redirect.
     *
     * @return void
     */
    public function test_pantalla_muestra_cliente_y_host(): void
    {
        $client_id = $this->registrar_cliente_de_chatgpt();
        list(, $challenge) = $this->par_pkce();

        $respuesta = $this->get('/mcp/oauth/authorize?' . http_build_query($this->parametros_de_autorizacion($client_id, $challenge)));

        $respuesta->assertStatus(200);
        $respuesta->assertSee('ChatGPT de prueba');
        $respuesta->assertSee('chatgpt.com');
        $respuesta->assertSee('Sólo lectura', false);
    }

    /**
     * Cliente inexistente o redirect no registrado: 400 sin `Location` (nunca se redirige a un destino sin validar).
     *
     * @return void
     */
    public function test_cliente_o_redirect_invalido_no_redirige(): void
    {
        $client_id = $this->registrar_cliente_de_chatgpt();
        list(, $challenge) = $this->par_pkce();

        $con_cliente_ajeno = $this->get('/mcp/oauth/authorize?' . http_build_query($this->parametros_de_autorizacion('ccm_c_noexiste', $challenge)));
        $con_cliente_ajeno->assertStatus(400);
        $con_cliente_ajeno->assertHeaderMissing('Location');

        $con_redirect_ajeno = $this->get('/mcp/oauth/authorize?' . http_build_query(
            $this->parametros_de_autorizacion($client_id, $challenge, ['redirect_uri' => 'https://chatgpt.com/otro-camino'])
        ));
        $con_redirect_ajeno->assertStatus(400);
        $con_redirect_ajeno->assertHeaderMissing('Location');

        // Control positivo: con los datos buenos sí muestra la pantalla.
        $this->get('/mcp/oauth/authorize?' . http_build_query($this->parametros_de_autorizacion($client_id, $challenge)))->assertStatus(200);
    }

    /**
     * Falta de PKCE, método distinto de S256, `resource` ajeno o scope desconocido: 302 al redirect con
     * `error`, el `state` y el `iss`.
     *
     * @return void
     */
    public function test_errores_redirigen_con_state_e_iss(): void
    {
        $client_id = $this->registrar_cliente_de_chatgpt();
        list(, $challenge) = $this->par_pkce();

        // Pares [error esperado, cambio sobre el pedido válido] (un valor null saca el parámetro).
        $casos = [
            ['invalid_request', ['code_challenge' => null]],
            ['invalid_request', ['code_challenge_method' => 'plain']],
            ['invalid_target', ['resource' => 'https://otro.test/api/mcp']],
            ['invalid_scope', ['scope' => 'comerciocity.read escribir_todo']],
        ];

        foreach ($casos as $caso) {
            list($esperado, $cambio) = $caso;
            $respuesta = $this->get('/mcp/oauth/authorize?' . http_build_query($this->parametros_de_autorizacion($client_id, $challenge, $cambio)));

            $respuesta->assertStatus(302);
            $ubicacion = (string) $respuesta->headers->get('Location');
            $this->assertStringStartsWith('https://chatgpt.com/connector_platform_oauth_redirect?', $ubicacion);
            $consulta = $this->consulta_del_redirect($respuesta);
            $this->assertSame($esperado, $consulta['error'], json_encode($cambio));
            $this->assertSame('estado-abc-123', $consulta['state'], json_encode($cambio));
            $this->assertSame('https://api-admin.test', $consulta['iss'], json_encode($cambio));
            $this->assertArrayNotHasKey('code', $consulta, json_encode($cambio));
        }
    }

    /**
     * Credenciales buenas y "Permitir": 302 con un código de 64 hex, el `state` y el `iss`; en la base queda sólo
     * el hash del código.
     *
     * @return void
     */
    public function test_login_correcto_emite_codigo(): void
    {
        $admin     = $this->admin_de_mcp();
        $client_id = $this->registrar_cliente_de_chatgpt();
        list(, $challenge) = $this->par_pkce();

        $respuesta = $this->enviar_consentimiento(
            $this->parametros_de_autorizacion($client_id, $challenge),
            $admin->email,
            'clave-segura-mcp-1'
        );

        $respuesta->assertStatus(302);
        $this->assertStringStartsWith('https://chatgpt.com/connector_platform_oauth_redirect?', (string) $respuesta->headers->get('Location'));
        $consulta = $this->consulta_del_redirect($respuesta);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $consulta['code']);
        $this->assertSame('estado-abc-123', $consulta['state']);
        $this->assertSame('https://api-admin.test', $consulta['iss']);

        $fila = DB::table('mcp_oauth_codes')->where('admin_id', $admin->id)->first();
        $this->assertNotNull($fila, 'Falta la fila del código en mcp_oauth_codes.');
        $this->assertSame(hash('sha256', $consulta['code']), $fila->code_hash);
        $this->assertSame($client_id, $fila->client_id);
        $this->assertSame($challenge, $fila->code_challenge);
        $this->assertNull($fila->used_at);
        $this->assertNotContains($consulta['code'], array_values((array) $fila), 'El código no puede guardarse en claro.');
    }

    /**
     * Contraseña mala: 422 con el mensaje, sin código y sin filas.
     *
     * @return void
     */
    public function test_password_mala(): void
    {
        $admin     = $this->admin_de_mcp();
        $client_id = $this->registrar_cliente_de_chatgpt();
        list(, $challenge) = $this->par_pkce();

        $respuesta = $this->enviar_consentimiento(
            $this->parametros_de_autorizacion($client_id, $challenge),
            $admin->email,
            'otra-clave-que-no-es'
        );

        $respuesta->assertStatus(422);
        $respuesta->assertSee('Email o contraseña incorrectos.');
        $respuesta->assertHeaderMissing('Location');
        $this->assertSame(0, DB::table('mcp_oauth_codes')->count());
    }

    /**
     * Los perfiles `solo_leads` y `es_agente` no pueden conectar: 403 y ninguna fila (con su control positivo).
     *
     * @return void
     */
    public function test_perfiles_restringidos(): void
    {
        $client_id = $this->registrar_cliente_de_chatgpt();
        list(, $challenge) = $this->par_pkce();

        foreach (['solo_leads', 'es_agente'] as $columna) {
            $restringido = $this->admin_de_mcp([$columna => 1]);

            $respuesta = $this->enviar_consentimiento(
                $this->parametros_de_autorizacion($client_id, $challenge),
                $restringido->email,
                'clave-segura-mcp-1'
            );

            $respuesta->assertStatus(403);
            $respuesta->assertSee('Tu usuario no puede conectar el conector.');
            $this->assertSame(0, DB::table('mcp_oauth_codes')->count(), $columna);
        }

        $normal = $this->admin_de_mcp();
        $this->enviar_consentimiento($this->parametros_de_autorizacion($client_id, $challenge), $normal->email, 'clave-segura-mcp-1')
            ->assertStatus(302);
        $this->assertSame(1, DB::table('mcp_oauth_codes')->count());
    }

    /**
     * Con `mcp.admin_emails` sólo entran esos emails; los otros reciben 403.
     *
     * @return void
     */
    public function test_lista_de_emails(): void
    {
        $permitido = $this->admin_de_mcp(['email' => 'permitido-' . uniqid() . '@test.local']);
        $otro      = $this->admin_de_mcp();
        config(['mcp.admin_emails' => $permitido->email . ',alguien@x.com']);

        $client_id = $this->registrar_cliente_de_chatgpt();
        list(, $challenge) = $this->par_pkce();

        $this->enviar_consentimiento($this->parametros_de_autorizacion($client_id, $challenge), $otro->email, 'clave-segura-mcp-1')
            ->assertStatus(403);
        $this->assertSame(0, DB::table('mcp_oauth_codes')->count());

        $this->enviar_consentimiento($this->parametros_de_autorizacion($client_id, $challenge), $permitido->email, 'clave-segura-mcp-1')
            ->assertStatus(302);
        $this->assertSame(1, DB::table('mcp_oauth_codes')->count());
    }

    /**
     * "No permitir": 302 con `error=access_denied`, `state` e `iss`, sin código.
     *
     * @return void
     */
    public function test_no_permitir(): void
    {
        $admin     = $this->admin_de_mcp();
        $client_id = $this->registrar_cliente_de_chatgpt();
        list(, $challenge) = $this->par_pkce();

        $respuesta = $this->enviar_consentimiento(
            $this->parametros_de_autorizacion($client_id, $challenge),
            $admin->email,
            'clave-segura-mcp-1',
            'deny'
        );

        $respuesta->assertStatus(302);
        $consulta = $this->consulta_del_redirect($respuesta);
        $this->assertSame('access_denied', $consulta['error']);
        $this->assertSame('estado-abc-123', $consulta['state']);
        $this->assertSame('https://api-admin.test', $consulta['iss']);
        $this->assertArrayNotHasKey('code', $consulta);
        $this->assertSame(0, DB::table('mcp_oauth_codes')->count());
    }
}
