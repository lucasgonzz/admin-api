<?php

namespace Tests\Feature\Mcp;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Ronda 2 de la misión dots-openclaw (REVISION-1, hallazgos D5 a D8): cada test falla antes del arreglo.
 *
 * - D5: los límites de pedidos del conector no comparten contador entre sí (registro, token, consentimiento, /api/mcp).
 * - D6: el login del consentimiento hace siempre un `Hash::check`, exista o no el email y venga o no la clave.
 * - D7: `state` completo (hasta 2048, nunca recortado) y respeto de los `grant_types` registrados.
 * - D8: `get_request_status` informa el recorte de misiones para que un avance incompleto no pase por completo.
 *
 * Nota D7: el punto "refresh token sólo con `offline_access`" NO está acá: contradice aserciones existentes
 * (McpTokenTest::test_canje_con_pkce, test_refresh_rota y token_para() canjean con `comerciocity.read` solo y esperan
 * refresh token). Queda pendiente de autorización del Coordinador.
 */
class McpRevisionDosTest extends TestCase
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
     * @return void
     */
    protected function tearDown(): void
    {
        $this->soltar_reloj();

        parent::tearDown();
    }

    /**
     * D5: después de agotar el registro (20 por hora) desde una IP, esa misma IP todavía tiene su cupo completo en
     * el token (60 por minuto) y en `/api/mcp` (120 por minuto); y el registro sí se corta en el 21.
     *
     * @return void
     */
    public function test_d5_los_limites_no_comparten_contador(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/mcp/oauth/register', ['redirect_uris' => [self::$redirect_de_chatgpt]])->assertStatus(201);
        }
        $this->postJson('/api/mcp/oauth/register', ['redirect_uris' => [self::$redirect_de_chatgpt]])->assertStatus(429);

        for ($i = 0; $i < 60; $i++) {
            $estado = $this->post('/api/mcp/oauth/token', ['grant_type' => 'authorization_code'])->getStatusCode();
            $this->assertNotSame(429, $estado, 'El token quedó sin cupo por el contador del registro (llamada ' . ($i + 1) . ').');
        }

        for ($i = 0; $i < 100; $i++) {
            $estado = $this->mcp_post(null, $this->rpc('tools/list'))->getStatusCode();
            $this->assertNotSame(429, $estado, '/api/mcp quedó sin cupo por los contadores de arriba (llamada ' . ($i + 1) . ').');
        }
    }

    /**
     * D6: el login del consentimiento llama a `Hash::check` en los tres casos: email inexistente, clave vacía y
     * admin existente con clave mala.
     *
     * @return void
     */
    public function test_d6_siempre_hay_un_hash_check(): void
    {
        $admin     = $this->admin_de_mcp();
        $client_id = $this->registrar_cliente_de_chatgpt();
        list(, $challenge) = $this->par_pkce();
        $parametros = $this->parametros_de_autorizacion($client_id, $challenge);

        // Se cambia el hasher del contenedor por uno que cuenta las llamadas a `check` y delega en el real.
        $contador = new class($this->app) extends \Illuminate\Hashing\HashManager {
            /** @var int Cantidad de llamadas a `check`. */
            public $llamadas = 0;

            public function check($value, $hashedValue, array $options = [])
            {
                $this->llamadas++;

                return parent::check($value, $hashedValue, $options);
            }
        };
        $this->app->instance('hash', $contador);
        Hash::clearResolvedInstance('hash');

        $this->enviar_consentimiento($parametros, 'no-existe-' . uniqid() . '@test.local', 'cualquiera')->assertStatus(422);
        $this->assertSame(1, $contador->llamadas, 'Email inexistente: tiene que haber un Hash::check.');
        $this->enviar_consentimiento($parametros, $admin->email, '')->assertStatus(422);
        $this->assertSame(2, $contador->llamadas, 'Clave vacía: tiene que haber un Hash::check.');
        $this->enviar_consentimiento($parametros, $admin->email, 'clave-equivocada')->assertStatus(422);
        $this->assertSame(3, $contador->llamadas, 'Admin existente con clave mala: tiene que haber un Hash::check.');
    }

    /**
     * D7: un `state` de más de 500 caracteres (hasta 2048) vuelve completo; uno de más de 2048 se rechaza con
     * `invalid_request` en vez de recortarse.
     *
     * @return void
     */
    public function test_d7_state_completo_o_rechazado(): void
    {
        $admin     = $this->admin_de_mcp();
        $client_id = $this->registrar_cliente_de_chatgpt();
        list(, $challenge) = $this->par_pkce();

        $largo = str_repeat('s', 2048);
        $ok = $this->enviar_consentimiento($this->parametros_de_autorizacion($client_id, $challenge, ['state' => $largo]), $admin->email, self::$clave_del_admin);
        $ok->assertStatus(302);
        $this->assertSame($largo, $this->consulta_del_redirect($ok)['state'], 'El state se recortó.');

        $demasiado = str_repeat('s', 2049);
        $malo = $this->enviar_consentimiento($this->parametros_de_autorizacion($client_id, $challenge, ['state' => $demasiado]), $admin->email, self::$clave_del_admin);
        $malo->assertStatus(302);
        $consulta = $this->consulta_del_redirect($malo);
        $this->assertSame('invalid_request', $consulta['error']);
        $this->assertArrayNotHasKey('code', $consulta);
        $this->assertNotSame(substr($demasiado, 0, 500), $consulta['state'] ?? null, 'Nunca se devuelve un state recortado.');

        $pantalla = $this->get('/mcp/oauth/authorize?' . http_build_query($this->parametros_de_autorizacion($client_id, $challenge, ['state' => $demasiado])));
        $pantalla->assertStatus(302);
        $this->assertSame('invalid_request', $this->consulta_del_redirect($pantalla)['error']);
    }

    /**
     * D7: un cliente registrado sólo con `authorization_code` no recibe refresh token ni puede usar el grant
     * `refresh_token` (`unauthorized_client`).
     *
     * @return void
     */
    public function test_d7_respeta_los_grant_types_registrados(): void
    {
        $registro = $this->postJson('/api/mcp/oauth/register', [
            'redirect_uris' => [self::$redirect_de_chatgpt],
            'grant_types'   => ['authorization_code'],
        ]);
        $registro->assertStatus(201);
        $client_id = (string) $registro->json('client_id');
        $this->assertSame(['authorization_code'], $registro->json('grant_types'));

        $admin = $this->admin_de_mcp();
        list($verifier, $challenge) = $this->par_pkce();
        $code = $this->emitir_codigo($admin, $client_id, $challenge);
        $canje = $this->canjear_codigo($client_id, $code, $verifier);
        $canje->assertStatus(200);
        $this->assertArrayNotHasKey('refresh_token', $canje->json(), 'Sin el grant refresh_token no se emite refresh token.');

        $renovar = $this->post('/api/mcp/oauth/token', [
            'grant_type' => 'refresh_token', 'refresh_token' => 'ccm_rt_' . str_repeat('a', 43), 'client_id' => $client_id,
        ]);
        $renovar->assertStatus(400);
        $this->assertSame('unauthorized_client', $renovar->json('error'));
    }

    /**
     * D8: con recorte de misiones, `get_request_status` (con y sin pedido) trae `misiones_recorte` y una
     * `advertencia`; sin recorte, `recortado` es false y no hay advertencia.
     *
     * @return void
     */
    public function test_d8_get_request_status_informa_el_recorte(): void
    {
        $admin = $this->admin_de_mcp();
        Carbon::setTestNow(Carbon::parse('2026-11-26 12:00:00', 'America/Argentina/Buenos_Aires'));

        // Sin recorte: la escena normal del CA6.
        $this->enviar_v2($this->reporte_del_ca6())->assertStatus(200);
        $token = $this->token_para($admin)['access_token'];
        $normal = $this->datos_de_herramienta($token, 'get_request_status', ['pedido_id' => self::$pedido_de_prueba]);
        $this->assertArrayHasKey('misiones_recorte', $normal);
        $this->assertSame(['recortado' => false, 'omitidas' => 0], $normal['misiones_recorte']);
        $this->assertArrayNotHasKey('advertencia', $normal);

        // Con recorte: 60 misiones, la escena muestra sólo las más recientes.
        $misiones = [];
        for ($i = 1; $i <= 60; $i++) {
            $misiones[] = $this->mision_con_etapa('m' . $i, 'trabajando', 'construccion', '2026-11-26 11:00:00');
        }
        $this->enviar_v2($this->reporte_v2(['misiones' => $misiones]))->assertStatus(200);
        \Illuminate\Support\Facades\Cache::flush();

        foreach ([['pedido_id' => self::$pedido_de_prueba], []] as $argumentos) {
            $datos = $this->datos_de_herramienta($token, 'get_request_status', $argumentos);
            $this->assertArrayHasKey('misiones_recorte', $datos, json_encode($argumentos));
            $this->assertTrue($datos['misiones_recorte']['recortado'], json_encode($argumentos));
            $this->assertGreaterThan(0, $datos['misiones_recorte']['omitidas']);
            $this->assertIsString($datos['advertencia']);
            $this->assertNotSame('', trim($datos['advertencia']));
        }
    }
}
