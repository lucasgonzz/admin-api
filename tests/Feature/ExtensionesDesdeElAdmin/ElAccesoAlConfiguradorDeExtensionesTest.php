<?php

namespace Tests\Feature\ExtensionesDesdeElAdmin;

use App\Models\Admin;
use App\Models\Client;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\HttpFactorySinSalida;
use Tests\Feature\CatalogoDelClientePorClaude\BaseDelCatalogoPorClaude;

/**
 * `POST api/admin/client/{clientId}/extensiones/acceso` (misión `extensiones-desde-el-admin`, 15/10/2026): el botón
 * «Gestionar extensiones» de la ficha del cliente. El admin le pide al empresa-api del cliente (frente ACTIVO) un acceso
 * de un solo uso y devuelve la URL para canjearlo en el navegador.
 *
 * Lo que se protege, en orden de importancia:
 *
 *  1. 🔴 La clave del cliente NO sale (ni en la respuesta ni en el log), y el token NO se loguea.
 *  2. Un estado por cada caso del contrato, siempre con HTTP 200.
 *  3. La `url` sale del frente ACTIVO, no de otro frente del mismo cliente.
 *  4. El endpoint exige sesión (Sanctum).
 *
 * Nada sale a internet: `HttpFactorySinSalida` frena todo pedido que ningún `Http::fake()` atienda.
 */
class ElAccesoAlConfiguradorDeExtensionesTest extends BaseDelCatalogoPorClaude
{
    /** @var string Clave del cliente de los tests. */
    private $clave;

    /** @var Client Cliente con su frente VPS activo. */
    private $cliente;

    /** @var string Token que "emite" el cliente (64 hex). */
    private $token;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clave   = 'sk-ext-' . Str::random(30);
        $this->token   = bin2hex(random_bytes(32));
        $this->cliente = $this->crear_cliente('San Cayetano', $this->clave);

        $this->crear_frente($this->cliente, 'sancayetano', 'vps', '/home/api-sancayetano/empresa-api', true);
    }

    /**
     * Admin logueado por Sanctum.
     *
     * @return void
     */
    private function loguear_admin(): void
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de extensiones';
        $admin->email    = 'ext-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);
    }

    /**
     * @param Client|null $cliente Cliente (el de los tests si es null).
     *
     * @return string
     */
    private function ruta($cliente = null): string
    {
        $cliente = $cliente === null ? $this->cliente : $cliente;

        return '/api/admin/client/' . $cliente->id . '/extensiones/acceso';
    }

    /**
     * Llama al endpoint logueado.
     *
     * @param Client|null $cliente Cliente (el de los tests si es null).
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function pedir($cliente = null)
    {
        $this->loguear_admin();

        return $this->postJson($this->ruta($cliente), [], ['Accept' => 'application/json']);
    }

    /**
     * Falsea al cliente con una fábrica limpia que no deja salir nada más.
     *
     * @param mixed $stubs Lo que se le pasa a `Http::fake()`.
     *
     * @return void
     */
    private function http_falso($stubs): void
    {
        Http::swap(new HttpFactorySinSalida());

        Http::fake($stubs);
    }

    /**
     * El cliente contesta lo dado a cualquier pedido.
     *
     * @param mixed $cuerpo Cuerpo.
     * @param int   $status HTTP.
     *
     * @return void
     */
    private function el_cliente_contesta($cuerpo, int $status = 200): void
    {
        $this->http_falso(['*' => Http::response($cuerpo, $status)]);
    }

    /**
     * 🔴 Ni la clave ni el token aparecen en el log.
     *
     * @param \Psr\Log\AbstractLogger $log Registro de `capturar_el_log()`.
     *
     * @return void
     */
    private function assertLogSinClaveNiToken($log): void
    {
        $this->assertLogSinLaClave($log, $this->clave);

        foreach ($log->lineas as $linea) {
            $this->assertStringNotContainsString($this->token, $linea, 'Una línea de log lleva el token de acceso.');
        }
    }

    /**
     * @return void
     */
    public function test_ok_devuelve_la_url_de_canje_del_frente_activo_y_manda_la_clave_en_el_header(): void
    {
        $this->el_cliente_contesta(['ok' => true, 'acceso' => $this->token, 'expira_en' => 120, 'ruta' => '/extensiones']);

        $respuesta = $this->pedir();

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('estado', 'ok');
        $this->assertSame(
            'https://api-sancayetano.' . self::DOMINIO . '/extensiones?acceso=' . $this->token,
            $respuesta->json('url')
        );
        $this->assertNotSame('', (string) $respuesta->json('mensaje'));

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://api-sancayetano.' . self::DOMINIO . '/api/admin-sync/extensiones/acceso'
                && $request->header('X-Admin-Api-Key') === [$this->clave];
        });
    }

    /**
     * @return void
     */
    public function test_la_url_sale_del_frente_activo_y_no_de_otro_frente_del_cliente(): void
    {
        // Un segundo frente, NO activo: no es al que se le habla.
        $this->crear_frente($this->cliente, 'sancayetano2', 'vps', '/home/api-sancayetano2/empresa-api', false);

        $this->el_cliente_contesta(['ok' => true, 'acceso' => $this->token]);

        $respuesta = $this->pedir();

        $respuesta->assertJsonPath('estado', 'ok');
        $this->assertStringStartsWith('https://api-sancayetano.' . self::DOMINIO . '/extensiones?acceso=', $respuesta->json('url'));
        $this->assertStringNotContainsString('sancayetano2', $respuesta->json('url'));

        Http::assertSent(function (Request $request) {
            return strpos($request->url(), 'api-sancayetano2') === false;
        });
    }

    /**
     * @return void
     */
    public function test_version_vieja_si_el_cliente_contesta_404_o_405(): void
    {
        foreach ([404, 405] as $status) {
            $this->el_cliente_contesta('', $status);

            $respuesta = $this->pedir();

            $respuesta->assertStatus(200);
            $respuesta->assertJsonPath('estado', 'version_vieja');
            $this->assertNull($respuesta->json('url'));
        }
    }

    /**
     * @return void
     */
    public function test_sin_clave_si_el_cliente_no_tiene_api_key_y_no_le_pega_a_nadie(): void
    {
        $sin_clave = $this->crear_cliente('Sin Clave', '');
        $this->crear_frente($sin_clave, 'sinclave', 'vps', '/home/api-sinclave/empresa-api', true);

        $this->http_falso(['*' => Http::response(['acceso' => $this->token], 200)]);

        $respuesta = $this->pedir($sin_clave);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('estado', 'sin_clave');
        $this->assertNull($respuesta->json('url'));
        Http::assertNothingSent();
    }

    /**
     * @return void
     */
    public function test_clave_rechazada_si_el_cliente_contesta_401(): void
    {
        $this->el_cliente_contesta(['error' => 'unauthorized'], 401);

        $respuesta = $this->pedir();

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('estado', 'clave_rechazada');
        $this->assertNull($respuesta->json('url'));
    }

    /**
     * @return void
     */
    public function test_sin_frente_si_el_cliente_no_tiene_ninguna_api_valida_y_no_le_pega_a_nadie(): void
    {
        $sin_frente = $this->crear_cliente('Sin Frente', 'sk-sin-frente-' . Str::random(20));

        $this->http_falso(['*' => Http::response(['acceso' => $this->token], 200)]);

        $respuesta = $this->pedir($sin_frente);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('estado', 'sin_frente');
        $this->assertNull($respuesta->json('url'));
        Http::assertNothingSent();
    }

    /**
     * @return void
     */
    public function test_sin_conexion_si_el_cliente_da_5xx(): void
    {
        $this->el_cliente_contesta('boom', 500);

        $respuesta = $this->pedir();

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('estado', 'sin_conexion');
        $this->assertNull($respuesta->json('url'));
    }

    /**
     * @return void
     */
    public function test_sin_conexion_si_hay_timeout_o_falla_de_red_y_el_error_no_lleva_la_clave(): void
    {
        $log = $this->capturar_el_log();

        $this->http_falso(['*' => function () {
            throw new ConnectionException('cURL error 28: timeout https://api-sancayetano.' . self::DOMINIO . '/api/admin-sync/extensiones/acceso?k=' . $this->clave);
        }]);

        $respuesta = $this->pedir();

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('estado', 'sin_conexion');
        $this->assertNull($respuesta->json('url'));
        $this->assertSinLaClave($respuesta, $this->clave);
        $this->assertLogSinClaveNiToken($log);
    }

    /**
     * @return void
     */
    public function test_sin_conexion_si_el_cliente_no_devuelve_un_acceso_de_64_hex(): void
    {
        $invalidos = [
            ['ok' => true],
            ['ok' => true, 'acceso' => 'corto'],
            ['ok' => true, 'acceso' => str_repeat('z', 64)],
            ['ok' => true, 'acceso' => str_repeat('a', 65)],
            ['ok' => true, 'acceso' => ['a']],
            ['ok' => true, 'acceso' => $this->token . '&evil=1'],
        ];

        foreach ($invalidos as $cuerpo) {
            $this->el_cliente_contesta($cuerpo, 200);

            $respuesta = $this->pedir();

            $respuesta->assertStatus(200);
            $respuesta->assertJsonPath('estado', 'sin_conexion');
            $this->assertNull($respuesta->json('url'));
        }
    }

    /**
     * @return void
     */
    public function test_un_redireccionamiento_no_se_sigue_y_no_arma_ninguna_url(): void
    {
        $this->http_falso([
            'https://api-sancayetano.' . self::DOMINIO . '/*' => Http::response('', 302, ['Location' => 'https://otro-host.test/robar']),
        ]);

        $respuesta = $this->pedir();

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('estado', 'sin_conexion');
        $this->assertNull($respuesta->json('url'));
        Http::assertSentCount(1);
    }

    /**
     * 🔴 La clave del cliente no está en la respuesta en ningún estado, y ni la clave ni el token están en el log.
     *
     * @return void
     */
    public function test_ni_la_clave_ni_el_token_salen_en_ningun_estado_ni_en_el_log(): void
    {
        $log = $this->capturar_el_log();

        // El cliente "eco" repite la clave en su cuerpo de error: no puede llegar a la respuesta.
        $casos = [
            [200, ['ok' => true, 'acceso' => $this->token]],
            [401, ['error' => 'unauthorized', 'echo' => $this->clave]],
            [404, 'no existe ' . $this->clave],
            [500, 'falla ' . $this->clave],
        ];

        foreach ($casos as $caso) {
            $this->el_cliente_contesta($caso[1], $caso[0]);

            $respuesta = $this->pedir();

            $respuesta->assertStatus(200);
            $this->assertSinLaClave($respuesta, $this->clave, 'HTTP ' . $caso[0] . '.');
        }

        $this->assertLogSinClaveNiToken($log);
    }

    /**
     * @return void
     */
    public function test_el_endpoint_exige_autenticacion(): void
    {
        $this->http_falso(['*' => Http::response(['acceso' => $this->token], 200)]);

        $respuesta = $this->postJson($this->ruta(), [], ['Accept' => 'application/json']);

        $respuesta->assertStatus(401);
        Http::assertNothingSent();
    }
}
