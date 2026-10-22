<?php

namespace Tests\Feature\AgentesIa;

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
 * `GET` y `POST api/admin/client/{clientId}/agentes-ia` (misión `agentes-ia-extension-unica`, 22/10/2026): el bloque
 * «Agentes de IA» de la ficha del cliente. El admin le pregunta (GET) o le ordena (PUT, body `{activa: bool}`) al
 * empresa-api del cliente (frente ACTIVO) y devuelve `{estado, mensaje, activa}`.
 *
 * Lo que se protege, en orden de importancia:
 *
 *  1. Un estado por cada caso del contrato, siempre con HTTP 200 (`ok`, `version_vieja`, `sin_clave`,
 *     `clave_rechazada`, `sin_frente`, `sin_conexion`): empresa vieja NO rompe nada.
 *  2. 🔴 La clave del cliente NO sale (ni en la respuesta ni en el log).
 *  3. El pedido sale al frente ACTIVO, con el método, la ruta, el header y el cuerpo del contrato.
 *  4. El endpoint exige sesión (Sanctum).
 *
 * Nada sale a internet: `HttpFactorySinSalida` frena todo pedido que ningún `Http::fake()` atienda.
 */
class ElBloqueAgentesIaDeLaFichaTest extends BaseDelCatalogoPorClaude
{
    /** @var string Clave del cliente de los tests. */
    private $clave;

    /** @var Client Cliente con su frente VPS activo. */
    private $cliente;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clave   = 'sk-agentes-' . Str::random(30);
        $this->cliente = $this->crear_cliente('Ferretotal', $this->clave);

        $this->crear_frente($this->cliente, 'ferretotal', 'vps', '/home/api-ferretotal/empresa-api', true);
    }

    /**
     * @return string URL de la puerta del cliente.
     */
    private function url_de_la_puerta(): string
    {
        return 'https://api-ferretotal.' . self::DOMINIO . '/api/admin-sync/agentes-ia';
    }

    /**
     * Admin logueado por Sanctum.
     *
     * @return void
     */
    private function loguear_admin(): void
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de agentes ia';
        $admin->email    = 'agentes-' . Str::random(8) . '@test.local';
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

        return '/api/admin/client/' . $cliente->id . '/agentes-ia';
    }

    /**
     * GET logueado.
     *
     * @param Client|null $cliente Cliente (el de los tests si es null).
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function leer($cliente = null)
    {
        $this->loguear_admin();

        return $this->getJson($this->ruta($cliente), ['Accept' => 'application/json']);
    }

    /**
     * POST logueado.
     *
     * @param bool        $activa  Lo que se manda en el cuerpo.
     * @param Client|null $cliente Cliente (el de los tests si es null).
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function cambiar(bool $activa, $cliente = null)
    {
        $this->loguear_admin();

        return $this->postJson($this->ruta($cliente), ['activa' => $activa], ['Accept' => 'application/json']);
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
     * @return void
     */
    public function test_get_ok_activa_devuelve_true_y_pega_al_frente_activo_con_la_clave_en_el_header(): void
    {
        $this->el_cliente_contesta(['ok' => true, 'activa' => true]);

        $respuesta = $this->leer();

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('estado', 'ok');
        $respuesta->assertJsonPath('activa', true);
        $this->assertNotSame('', (string) $respuesta->json('mensaje'));

        Http::assertSent(function (Request $request) {
            return $request->method() === 'GET'
                && $request->url() === $this->url_de_la_puerta()
                && $request->header('X-Admin-Api-Key') === [$this->clave];
        });
        Http::assertSentCount(1);
    }

    /**
     * @return void
     */
    public function test_get_ok_no_activa_devuelve_false(): void
    {
        $this->el_cliente_contesta(['ok' => true, 'activa' => false]);

        $respuesta = $this->leer();

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('estado', 'ok');
        $respuesta->assertJsonPath('activa', false);
    }

    /**
     * @return void
     */
    public function test_post_activa_manda_un_put_con_activa_true_y_devuelve_el_estado_que_confirma_el_cliente(): void
    {
        $this->el_cliente_contesta(['ok' => true, 'activa' => true]);

        $respuesta = $this->cambiar(true);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('estado', 'ok');
        $respuesta->assertJsonPath('activa', true);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'PUT'
                && $request->url() === $this->url_de_la_puerta()
                && $request->header('X-Admin-Api-Key') === [$this->clave]
                && $request->data() === ['activa' => true];
        });
        Http::assertSentCount(1);
    }

    /**
     * @return void
     */
    public function test_post_quita_manda_un_put_con_activa_false(): void
    {
        $this->el_cliente_contesta(['ok' => true, 'activa' => false]);

        $respuesta = $this->cambiar(false);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('estado', 'ok');
        $respuesta->assertJsonPath('activa', false);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'PUT' && $request->data() === ['activa' => false];
        });
    }

    /**
     * El pedido sale al frente ACTIVO, no a otro frente del mismo cliente.
     *
     * @return void
     */
    public function test_el_pedido_sale_al_frente_activo_y_no_a_otro_frente_del_cliente(): void
    {
        $this->crear_frente($this->cliente, 'ferretotal2', 'vps', '/home/api-ferretotal2/empresa-api', false);

        $this->el_cliente_contesta(['ok' => true, 'activa' => true]);

        $this->leer()->assertJsonPath('estado', 'ok');

        Http::assertSent(function (Request $request) {
            return strpos($request->url(), 'api-ferretotal2') === false;
        });
    }

    /**
     * 🔴 Empresa vieja: no existe la puerta (404/405) y el admin lo dice sin romper nada, en el GET y en el POST.
     *
     * @return void
     */
    public function test_version_vieja_si_el_cliente_contesta_404_o_405_en_el_get_y_en_el_post(): void
    {
        foreach ([404, 405] as $status) {
            $this->el_cliente_contesta('', $status);

            $lectura = $this->leer();
            $lectura->assertStatus(200);
            $lectura->assertJsonPath('estado', 'version_vieja');
            $this->assertNull($lectura->json('activa'));

            $cambio = $this->cambiar(true);
            $cambio->assertStatus(200);
            $cambio->assertJsonPath('estado', 'version_vieja');
            $this->assertNull($cambio->json('activa'));
        }
    }

    /**
     * @return void
     */
    public function test_sin_clave_si_el_cliente_no_tiene_api_key_y_no_le_pega_a_nadie(): void
    {
        $sin_clave = $this->crear_cliente('Sin Clave', '');
        $this->crear_frente($sin_clave, 'sinclave', 'vps', '/home/api-sinclave/empresa-api', true);

        $this->http_falso(['*' => Http::response(['ok' => true, 'activa' => true], 200)]);

        $lectura = $this->leer($sin_clave);
        $lectura->assertStatus(200);
        $lectura->assertJsonPath('estado', 'sin_clave');
        $this->assertNull($lectura->json('activa'));

        $cambio = $this->cambiar(true, $sin_clave);
        $cambio->assertStatus(200);
        $cambio->assertJsonPath('estado', 'sin_clave');

        Http::assertNothingSent();
    }

    /**
     * @return void
     */
    public function test_clave_rechazada_si_el_cliente_contesta_401(): void
    {
        $this->el_cliente_contesta(['error' => 'unauthorized'], 401);

        foreach ([$this->leer(), $this->cambiar(true)] as $respuesta) {
            $respuesta->assertStatus(200);
            $respuesta->assertJsonPath('estado', 'clave_rechazada');
            $this->assertNull($respuesta->json('activa'));
        }
    }

    /**
     * @return void
     */
    public function test_sin_frente_si_el_cliente_no_tiene_ninguna_api_valida_y_no_le_pega_a_nadie(): void
    {
        $sin_frente = $this->crear_cliente('Sin Frente', 'sk-sin-frente-' . Str::random(20));

        $this->http_falso(['*' => Http::response(['ok' => true, 'activa' => true], 200)]);

        foreach ([$this->leer($sin_frente), $this->cambiar(true, $sin_frente)] as $respuesta) {
            $respuesta->assertStatus(200);
            $respuesta->assertJsonPath('estado', 'sin_frente');
            $this->assertNull($respuesta->json('activa'));
        }

        Http::assertNothingSent();
    }

    /**
     * @return void
     */
    public function test_sin_conexion_si_el_cliente_da_5xx(): void
    {
        $this->el_cliente_contesta('boom', 500);

        foreach ([$this->leer(), $this->cambiar(true)] as $respuesta) {
            $respuesta->assertStatus(200);
            $respuesta->assertJsonPath('estado', 'sin_conexion');
            $this->assertNull($respuesta->json('activa'));
        }
    }

    /**
     * El 409 (la instancia no sabe quién es su dueño) no es versión vieja ni red caída: es `sin_conexion` con su
     * propio mensaje, que nombra USER_ID.
     *
     * @return void
     */
    public function test_el_409_del_cliente_dice_que_falta_user_id(): void
    {
        $this->el_cliente_contesta(['ok' => false, 'error' => 'sin dueno'], 409);

        foreach ([$this->leer(), $this->cambiar(true)] as $respuesta) {
            $respuesta->assertStatus(200);
            $respuesta->assertJsonPath('estado', 'sin_conexion');
            $this->assertStringContainsString('USER_ID', (string) $respuesta->json('mensaje'));
            $this->assertNull($respuesta->json('activa'));
        }
    }

    /**
     * @return void
     */
    public function test_sin_conexion_si_hay_timeout_o_falla_de_red_y_el_error_no_lleva_la_clave(): void
    {
        $log = $this->capturar_el_log();

        $this->http_falso(['*' => function () {
            throw new ConnectionException('cURL error 28: timeout ' . $this->url_de_la_puerta() . '?k=' . $this->clave);
        }]);

        foreach ([$this->leer(), $this->cambiar(true)] as $respuesta) {
            $respuesta->assertStatus(200);
            $respuesta->assertJsonPath('estado', 'sin_conexion');
            $this->assertNull($respuesta->json('activa'));
            $this->assertSinLaClave($respuesta, $this->clave);
        }

        $this->assertLogSinLaClave($log, $this->clave);
    }

    /**
     * Un 200 cuyo cuerpo no confirma (`ok: true` y `activa` booleana) no se da por bueno: puede ser la página genérica
     * del hosting saturado.
     *
     * @return void
     */
    public function test_sin_conexion_si_el_200_no_confirma_con_ok_y_activa_booleana(): void
    {
        $invalidos = [
            '<html>Hostinger</html>',
            [],
            ['ok' => true],
            ['ok' => false, 'activa' => true],
            ['ok' => true, 'activa' => 'si'],
            ['ok' => true, 'activa' => 1],
            ['ok' => true, 'activa' => null],
        ];

        foreach ($invalidos as $cuerpo) {
            $this->el_cliente_contesta($cuerpo, 200);

            $respuesta = $this->leer();

            $respuesta->assertStatus(200);
            $respuesta->assertJsonPath('estado', 'sin_conexion');
            $this->assertNull($respuesta->json('activa'));
        }
    }

    /**
     * @return void
     */
    public function test_un_redireccionamiento_no_se_sigue(): void
    {
        $this->http_falso([
            'https://api-ferretotal.' . self::DOMINIO . '/*' => Http::response('', 302, ['Location' => 'https://otro-host.test/robar']),
        ]);

        $respuesta = $this->leer();

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('estado', 'sin_conexion');
        Http::assertSentCount(1);
    }

    /**
     * 🔴 La clave del cliente no está en la respuesta en ningún estado ni en el log.
     *
     * @return void
     */
    public function test_la_clave_no_sale_en_ningun_estado_ni_en_el_log(): void
    {
        $log = $this->capturar_el_log();

        // El cliente "eco" repite la clave en su cuerpo: no puede llegar a la respuesta.
        $casos = [
            [200, ['ok' => true, 'activa' => true, 'echo' => $this->clave]],
            [401, ['error' => 'unauthorized', 'echo' => $this->clave]],
            [404, 'no existe ' . $this->clave],
            [409, 'sin dueno ' . $this->clave],
            [500, 'falla ' . $this->clave],
        ];

        foreach ($casos as $caso) {
            $this->el_cliente_contesta($caso[1], $caso[0]);

            $this->assertSinLaClave($this->leer(), $this->clave, 'GET HTTP ' . $caso[0] . '.');
            $this->assertSinLaClave($this->cambiar(false), $this->clave, 'PUT HTTP ' . $caso[0] . '.');
        }

        $this->assertLogSinLaClave($log, $this->clave);
    }

    /**
     * El POST exige `activa` booleana: sin ella o con otra cosa es 422 y no sale ningún pedido al cliente.
     *
     * @return void
     */
    public function test_el_post_valida_activa_y_no_le_pega_al_cliente_si_es_invalida(): void
    {
        $this->http_falso(['*' => Http::response(['ok' => true, 'activa' => true], 200)]);
        $this->loguear_admin();

        $this->postJson($this->ruta(), [], ['Accept' => 'application/json'])->assertStatus(422);
        $this->postJson($this->ruta(), ['activa' => 'quizas'], ['Accept' => 'application/json'])->assertStatus(422);

        Http::assertNothingSent();
    }

    /**
     * @return void
     */
    public function test_un_cliente_que_no_existe_da_404(): void
    {
        $this->http_falso(['*' => Http::response(['ok' => true, 'activa' => true], 200)]);
        $this->loguear_admin();

        $this->getJson('/api/admin/client/99999999/agentes-ia', ['Accept' => 'application/json'])->assertStatus(404);
        Http::assertNothingSent();
    }

    /**
     * 🔴 Un PUT que el cliente contesta con ok:true pero con `activa` DISTINTA de lo pedido no es un éxito: la extensión
     * no quedó como se pidió (empresa ignoró el pedido, otro dueño, etc.). No se informa como `ok`.
     *
     * @return void
     */
    public function test_un_put_confirmado_con_otro_valor_del_pedido_no_es_exito(): void
    {
        foreach ([[true, false], [false, true]] as $par) {
            [$pedido, $devuelve] = $par;

            $this->el_cliente_contesta(['ok' => true, 'activa' => $devuelve]);

            $respuesta = $this->cambiar($pedido);

            $respuesta->assertStatus(200);
            $respuesta->assertJsonPath('estado', 'sin_conexion');
            $this->assertNull($respuesta->json('activa'));
            $this->assertStringContainsString('no dejó Agentes de IA como se pidió', (string) $respuesta->json('mensaje'));
        }
    }

    /**
     * El mismo valor en el GET no se compara contra nada: el GET solo informa.
     *
     * @return void
     */
    public function test_el_get_informa_el_valor_que_tenga_sin_compararlo(): void
    {
        foreach ([true, false] as $valor) {
            $this->el_cliente_contesta(['ok' => true, 'activa' => $valor]);

            $this->leer()->assertJsonPath('estado', 'ok')->assertJsonPath('activa', $valor);
        }
    }

    /**
     * @return void
     */
    public function test_los_endpoints_exigen_autenticacion(): void
    {
        $this->http_falso(['*' => Http::response(['ok' => true, 'activa' => true], 200)]);

        $this->getJson($this->ruta(), ['Accept' => 'application/json'])->assertStatus(401);
        $this->postJson($this->ruta(), ['activa' => true], ['Accept' => 'application/json'])->assertStatus(401);

        Http::assertNothingSent();
    }
}
