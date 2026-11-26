<?php

namespace Tests\Feature\Mcp;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CA4 de la misión dots-openclaw: el canje del código por tokens (`POST /api/mcp/oauth/token`), PKCE, un solo uso,
 * vencimiento, atadura a `redirect_uri` y `resource`, y la rotación del refresh. En la base sólo hashes.
 */
class McpTokenTest extends TestCase
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
     * Un código listo para canjear, con su verifier y su cliente.
     *
     * @return array{0: string, 1: string, 2: string} [client_id, code, verifier]
     */
    private function codigo_para_canjear()
    {
        $admin     = $this->admin_de_mcp();
        $client_id = $this->registrar_cliente_de_chatgpt();
        list($verifier, $challenge) = $this->par_pkce();

        return [$client_id, $this->emitir_codigo($admin, $client_id, $challenge), $verifier];
    }

    /**
     * El canje correcto devuelve el par de tokens con la forma del contrato y el token anda en `/api/mcp`.
     *
     * @return void
     */
    public function test_canje_con_pkce(): void
    {
        list($client_id, $code, $verifier) = $this->codigo_para_canjear();

        $respuesta = $this->canjear_codigo($client_id, $code, $verifier);

        $respuesta->assertStatus(200);
        $this->assertSame('Bearer', $respuesta->json('token_type'));
        $this->assertSame(3600, $respuesta->json('expires_in'));
        $this->assertStringStartsWith('ccm_at_', (string) $respuesta->json('access_token'));
        $this->assertStringStartsWith('ccm_rt_', (string) $respuesta->json('refresh_token'));
        $this->assertStringContainsString('comerciocity.read', (string) $respuesta->json('scope'));
        $this->assertStringContainsString('no-store', (string) $respuesta->headers->get('Cache-Control'));

        $this->mcp_post($respuesta->json('access_token'), $this->rpc('tools/list'))->assertStatus(200);
    }

    /**
     * En `mcp_oauth_tokens` no aparece ningún token en claro: sólo los sha256.
     *
     * @return void
     */
    public function test_solo_hashes_en_base(): void
    {
        list($client_id, $code, $verifier) = $this->codigo_para_canjear();
        $respuesta = $this->canjear_codigo($client_id, $code, $verifier);
        $respuesta->assertStatus(200);

        $access  = (string) $respuesta->json('access_token');
        $refresh = (string) $respuesta->json('refresh_token');

        $filas = DB::table('mcp_oauth_tokens')->get();
        $this->assertCount(1, $filas);
        foreach ($filas as $fila) {
            $this->assertNotContains($access, array_map('strval', array_values((array) $fila)), 'El access token no puede guardarse en claro.');
            $this->assertNotContains($refresh, array_map('strval', array_values((array) $fila)), 'El refresh token no puede guardarse en claro.');
            $this->assertSame(hash('sha256', $access), $fila->access_hash);
            $this->assertSame(hash('sha256', $refresh), $fila->refresh_hash);
        }
    }

    /**
     * Un verifier equivocado es `invalid_grant`; de control, el correcto con otro código sí canjea.
     *
     * @return void
     */
    public function test_verifier_malo(): void
    {
        list($client_id, $code, $verifier) = $this->codigo_para_canjear();

        $malo = $this->canjear_codigo($client_id, $code, str_repeat('a', 64));
        $malo->assertStatus(400);
        $this->assertSame('invalid_grant', $malo->json('error'));
        $this->assertSame(0, DB::table('mcp_oauth_tokens')->count());

        // Control positivo: con el verifier verdadero el mismo flujo emite tokens.
        list($otro_client, $otro_code, $otro_verifier) = $this->codigo_para_canjear();
        $this->canjear_codigo($otro_client, $otro_code, $otro_verifier)->assertStatus(200);
    }

    /**
     * Canjear dos veces el mismo código: la segunda es `invalid_grant` y revoca los tokens de la primera.
     *
     * @return void
     */
    public function test_reuso_revoca(): void
    {
        list($client_id, $code, $verifier) = $this->codigo_para_canjear();

        $primero = $this->canjear_codigo($client_id, $code, $verifier);
        $primero->assertStatus(200);
        $this->mcp_post($primero->json('access_token'), $this->rpc('tools/list'))->assertStatus(200);

        $segundo = $this->canjear_codigo($client_id, $code, $verifier);
        $segundo->assertStatus(400);
        $this->assertSame('invalid_grant', $segundo->json('error'));

        $this->mcp_post($primero->json('access_token'), $this->rpc('tools/list'))->assertStatus(401);
    }

    /**
     * El código vence a los 300 segundos (a los 299 todavía canjea).
     *
     * @return void
     */
    public function test_codigo_vencido(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-26 12:00:00', 'America/Argentina/Buenos_Aires'));

        list($client_id, $code, $verifier) = $this->codigo_para_canjear();
        list($vivo_client, $vivo_code, $vivo_verifier) = $this->codigo_para_canjear();

        Carbon::setTestNow(Carbon::parse('2026-11-26 12:04:59', 'America/Argentina/Buenos_Aires'));
        $this->canjear_codigo($vivo_client, $vivo_code, $vivo_verifier)->assertStatus(200);

        Carbon::setTestNow(Carbon::parse('2026-11-26 12:05:01', 'America/Argentina/Buenos_Aires'));
        $vencido = $this->canjear_codigo($client_id, $code, $verifier);
        $vencido->assertStatus(400);
        $this->assertSame('invalid_grant', $vencido->json('error'));
    }

    /**
     * Un `redirect_uri` distinto al del código es `invalid_grant`; un `resource` distinto es `invalid_target`.
     *
     * @return void
     */
    public function test_redirect_y_resource_distintos(): void
    {
        list($client_a, $code_a, $verifier_a) = $this->codigo_para_canjear();
        $con_redirect = $this->canjear_codigo($client_a, $code_a, $verifier_a, ['redirect_uri' => 'https://chatgpt.com/otro-camino']);
        $con_redirect->assertStatus(400);
        $this->assertSame('invalid_grant', $con_redirect->json('error'));

        list($client_b, $code_b, $verifier_b) = $this->codigo_para_canjear();
        $con_resource = $this->canjear_codigo($client_b, $code_b, $verifier_b, ['resource' => 'https://otro.test/api/mcp']);
        $con_resource->assertStatus(400);
        $this->assertSame('invalid_target', $con_resource->json('error'));

        $this->assertSame(0, DB::table('mcp_oauth_tokens')->count());
    }

    /**
     * El refresh rota: devuelve un par nuevo, el refresh viejo es `invalid_grant` y su reuso revoca la familia.
     *
     * @return void
     */
    public function test_refresh_rota(): void
    {
        list($client_id, $code, $verifier) = $this->codigo_para_canjear();
        $inicial = $this->canjear_codigo($client_id, $code, $verifier);
        $inicial->assertStatus(200);
        $refresh_viejo = (string) $inicial->json('refresh_token');

        $nuevo = $this->post('/api/mcp/oauth/token', [
            'grant_type' => 'refresh_token', 'refresh_token' => $refresh_viejo, 'client_id' => $client_id,
        ]);
        $nuevo->assertStatus(200);
        $this->assertStringStartsWith('ccm_at_', (string) $nuevo->json('access_token'));
        $this->assertStringStartsWith('ccm_rt_', (string) $nuevo->json('refresh_token'));
        $this->assertNotSame($inicial->json('access_token'), $nuevo->json('access_token'));
        $this->assertNotSame($refresh_viejo, $nuevo->json('refresh_token'));
        $this->mcp_post($nuevo->json('access_token'), $this->rpc('tools/list'))->assertStatus(200);

        // Reusar el refresh viejo: invalid_grant y la familia entera queda revocada.
        $reuso = $this->post('/api/mcp/oauth/token', [
            'grant_type' => 'refresh_token', 'refresh_token' => $refresh_viejo, 'client_id' => $client_id,
        ]);
        $reuso->assertStatus(400);
        $this->assertSame('invalid_grant', $reuso->json('error'));
        $this->mcp_post($nuevo->json('access_token'), $this->rpc('tools/list'))->assertStatus(401);
    }
}
