<?php

namespace Tests\Feature\CerrarAdminSync;

use App\Http\Controllers\Api\ClaudeClientAdminSyncController;
use App\Models\Client;
use App\Services\ClientAdminSyncPuenteService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\CatalogoDelClientePorClaude\BaseDelCatalogoPorClaude;
use Tests\Fakes\HttpFactorySinSalida;

/**
 * `POST claude/clients/{id}/admin-sync/puente` (A3, misión `cerrar-admin-sync`, 10/10/2026): el admin reenvía UN pedido de una lista blanca
 * de siete entradas a `admin-sync/*` del `empresa-api` del cliente, con la clave del cliente, y devuelve lo que contestó.
 *
 * Es el hermano de `catalogo/puente` y se prueba con el mismo criterio. En orden de importancia:
 *
 *  1. 🔴 **La lista blanca es la única puerta.** Las siete entradas pasan; cualquier otra combinación de método y ruta (las demás rutas de
 *     `admin-sync`, `..`, una barra de más, un segmento de más, un método que no corresponde) es 422 y NO le pega al cliente.
 *  2. 🔴 **La clave del cliente viaja en el header y NO sale por ningún lado**: ni en la respuesta, ni en un error, ni en el log.
 *  3. 🔴 **El HTTP del cliente viaja adentro** (200 `puente: true` con su `status`), incluido un 3xx que NO se sigue y un 202.
 *  4. **Un cliente sin `api_key` es 409 y no se le pega a nadie.**
 *
 * Nada sale a internet: el `TestCase` base cambia el cliente HTTP por uno que lanza ante todo pedido que ningún `Http::fake()` atienda.
 */
class ElPuenteDeAdminSyncPorClaudeTest extends BaseDelCatalogoPorClaude
{
    /** @var string La clave del cliente de los tests. */
    private $clave;

    /** @var Client El cliente de los tests, con su frente de shared hosting activo. */
    private $cliente;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clave   = 'sk-admin-sync-' . Str::random(30);
        $this->cliente = $this->crear_cliente('Doblep Distribuciones', $this->clave);

        $this->crear_frente($this->cliente, 'doblep', 'shared_hosting', null, true);
    }

    /**
     * La URL del puente para un cliente.
     *
     * @param Client|int|string|null $cliente Cliente, id o uuid (el de los tests si es null).
     *
     * @return string
     */
    private function url($cliente = null): string
    {
        $cliente = $cliente === null ? $this->cliente : $cliente;
        $id      = $cliente instanceof Client ? $cliente->id : $cliente;

        return '/api/claude/clients/' . $id . '/admin-sync/puente';
    }

    /**
     * Llama al puente.
     *
     * @param array<string, mixed> $pedido  Cuerpo del pedido.
     * @param Client|null          $cliente Cliente (el de los tests si es null).
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function puente(array $pedido, Client $cliente = null)
    {
        return $this->postJson($this->url($cliente), $pedido, $this->headers());
    }

    /**
     * Pone una fábrica de HTTP LIMPIA y la falsea con lo dado (los stubs de `Http::fake()` se acumulan: un segundo `fake()` no pisa al
     * primero). Parte de `HttpFactorySinSalida` para que lo que ningún stub atienda no pueda salir a internet.
     *
     * @param array<string, mixed> $stubs Lo que se le pasa a `Http::fake()`.
     *
     * @return void
     */
    private function http_falso(array $stubs): void
    {
        Http::swap(new HttpFactorySinSalida());

        Http::fake($stubs);
    }

    /**
     * Falsea al cliente: contesta lo mismo a cualquier pedido.
     *
     * @param mixed $cuerpo Cuerpo (array = JSON, string = texto crudo).
     * @param int   $status HTTP.
     *
     * @return void
     */
    private function el_cliente_contesta($cuerpo, int $status = 200): void
    {
        $this->http_falso(['*' => Http::response($cuerpo, $status)]);
    }

    /* ------------------------------------------------------------------------------------------
     | 1. La puerta y los errores del admin
     |----------------------------------------------------------------------------------------- */

    /**
     * Fail-closed: sin el header de ingesta no contesta, y no le pega a nadie.
     *
     * @return void
     */
    public function test_sin_la_clave_de_ingesta_devuelve_401_y_no_le_pega_al_cliente(): void
    {
        Http::fake();

        $this->postJson($this->url(), ['metodo' => 'GET', 'ruta' => '/mostrador/duenos'])->assertStatus(401);

        Http::assertNothingSent();
    }

    /**
     * Un cliente que no existe es 404 con el CÓDIGO `cliente_inexistente` en `error`.
     *
     * @return void
     */
    public function test_cliente_inexistente_es_404_con_el_codigo(): void
    {
        Http::fake();

        $respuesta = $this->postJson($this->url(987654321), ['metodo' => 'GET', 'ruta' => '/mostrador/duenos'], $this->headers());

        $respuesta->assertStatus(404);
        $this->assertSame('cliente_inexistente', $respuesta->json('error'));

        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------------------------------
     | 2. La lista blanca: las siete entradas pasan
     |----------------------------------------------------------------------------------------- */

    /**
     * Las siete entradas de la lista blanca: método, ruta que pide quien llama y la URL que tiene que recibir el cliente (shared hosting:
     * la API vive bajo `/public`).
     *
     * @return array<string, array<int, string>>
     */
    public function las_rutas_de_la_lista_blanca(): array
    {
        $base = 'https://api-doblep.ejemplo.test/public/api/admin-sync';

        return [
            'GET mostrador/duenos'          => ['GET', '/mostrador/duenos', $base . '/mostrador/duenos'],
            'POST mostrador/hechos'         => ['POST', '/mostrador/hechos', $base . '/mostrador/hechos'],
            'GET mostrador/reportes/{n}'    => ['GET', '/mostrador/reportes/15', $base . '/mostrador/reportes/15'],
            'PUT mostrador/reportes/{n}'    => ['PUT', '/mostrador/reportes/15', $base . '/mostrador/reportes/15'],
            'GET mostrador/contexto/{n}'    => ['GET', '/mostrador/contexto/4242', $base . '/mostrador/contexto/4242'],
            'PUT mostrador/memoria/{n}'     => ['PUT', '/mostrador/memoria/4242', $base . '/mostrador/memoria/4242'],
            'PUT update-default-version'    => ['PUT', '/update-default-version', $base . '/update-default-version'],
            'GET reportes con varios dígitos' => ['GET', '/mostrador/reportes/1234567890', $base . '/mostrador/reportes/1234567890'],
        ];
    }

    /**
     * 🔴 Cada entrada de la lista blanca pasa: llega al cliente con el método y la URL correctos, con la clave del cliente en
     * `X-Admin-Api-Key` y `Accept: application/json`, y la respuesta es 200 `puente: true` con el status y el cuerpo del cliente — y sin
     * la clave.
     *
     * @dataProvider las_rutas_de_la_lista_blanca
     *
     * @param string $metodo       Método del pedido.
     * @param string $ruta         Ruta que pide quien llama.
     * @param string $url_esperada URL que tiene que recibir el cliente.
     *
     * @return void
     */
    public function test_cada_entrada_de_la_lista_blanca_pasa_con_la_clave_en_el_header(string $metodo, string $ruta, string $url_esperada): void
    {
        $this->el_cliente_contesta(['ok' => true, 'dato' => 'del cliente'], 200);

        $respuesta = $this->puente(['metodo' => $metodo, 'ruta' => $ruta]);

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('puente'));
        $this->assertSame(200, $respuesta->json('status'));
        $this->assertSame(['ok' => true, 'dato' => 'del cliente'], $respuesta->json('cuerpo'));
        $this->assertNull($respuesta->json('cuerpo_crudo'));
        $this->assertSame(['puente', 'status', 'cuerpo', 'cuerpo_crudo'], array_keys($respuesta->json()), 'La forma del contrato: cuatro claves.');

        $clave = $this->clave;

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($metodo, $url_esperada, $clave) {
            return $request->method() === $metodo
                && $request->url() === $url_esperada
                && $request->hasHeader('X-Admin-Api-Key', $clave)
                && $request->hasHeader('Accept', 'application/json');
        });

        $this->assertSinLaClave($respuesta, $this->clave);
    }

    /**
     * El método se acepta sin importar mayúsculas ni espacios, y la query viaja tal cual.
     *
     * @return void
     */
    public function test_el_metodo_va_en_minusculas_y_la_query_se_reenvia_tal_cual(): void
    {
        $this->el_cliente_contesta(['ok' => true]);

        $this->puente(['metodo' => ' get ', 'ruta' => '/mostrador/duenos?a=1&b=dos'])->assertStatus(200);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && $request->url() === 'https://api-doblep.ejemplo.test/public/api/admin-sync/mostrador/duenos?a=1&b=dos';
        });
    }

    /**
     * Un POST y un PUT reenvían el cuerpo tal cual (un objeto con claves numéricas sigue siendo un objeto) y un cuerpo ausente viaja como `{}`.
     *
     * @return void
     */
    public function test_un_post_y_un_put_reenvian_el_cuerpo_tal_cual(): void
    {
        $this->el_cliente_contesta(['ok' => true]);

        $this->postJson($this->url(), [
            'metodo' => 'PUT',
            'ruta'   => '/mostrador/reportes/15',
            'cuerpo' => ['titulo' => 'Ayer', 'orden' => ['12' => 'a', '7' => 'b']],
        ], $this->headers())->assertStatus(200);

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && $request->body() === '{"titulo":"Ayer","orden":{"12":"a","7":"b"}}'
                && $request->hasHeader('Content-Type', 'application/json');
        });

        $this->el_cliente_contesta(['ok' => true]);

        $this->puente(['metodo' => 'POST', 'ruta' => '/mostrador/hechos'])->assertStatus(200);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST' && $request->body() === '{}';
        });
    }

    /**
     * Un cliente de VPS le pega a su API SIN `/public`; se le habla a la API activa.
     *
     * @return void
     */
    public function test_un_cliente_de_vps_le_pega_a_su_api_sin_public(): void
    {
        $vps = $this->crear_cliente('Cliente del VPS', 'sk-vps-' . Str::random(30));
        $this->crear_frente($vps, 'delvps', 'vps', 'delvps', true);

        $this->el_cliente_contesta(['ok' => true]);

        $this->puente(['metodo' => 'GET', 'ruta' => '/mostrador/duenos'], $vps)->assertStatus(200);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api-delvps.ejemplo.test/api/admin-sync/mostrador/duenos';
        });
    }

    /* ------------------------------------------------------------------------------------------
     | 3. Fuera de la lista blanca: 422 y no se le pega al cliente
     |----------------------------------------------------------------------------------------- */

    /**
     * Pedidos que NO están en la lista blanca: otras rutas de admin-sync (las que escriben datos del negocio), el método equivocado para
     * una ruta que sí está, y las formas de escaparse del path.
     *
     * @return array<string, array<int, string>>
     */
    public function las_rutas_fuera_de_la_lista_blanca(): array
    {
        return [
            'sistema-query (lee datos del negocio)'    => ['POST', '/sistema-query'],
            'mensualidad-update'                       => ['PUT', '/mensualidad-update/1'],
            'mensualidad-info'                         => ['GET', '/mensualidad-info'],
            'user-setup'                               => ['POST', '/user-setup'],
            'ai-excel-import/import'                   => ['POST', '/ai-excel-import/import'],
            'publish-version'                          => ['POST', '/publish-version'],
            'employees'                                => ['GET', '/employees'],
            'demo-token'                               => ['POST', '/demo-token'],
            'catalogo/resumen (es del otro puente)'    => ['GET', '/catalogo/resumen'],
            'update-default-version por POST'          => ['POST', '/update-default-version'],
            'update-default-version por GET'           => ['GET', '/update-default-version'],
            'mostrador/duenos por POST'                => ['POST', '/mostrador/duenos'],
            'mostrador/hechos por GET'                 => ['GET', '/mostrador/hechos'],
            'mostrador/hechos por PUT'                 => ['PUT', '/mostrador/hechos'],
            'mostrador/reportes/{n} por POST'          => ['POST', '/mostrador/reportes/15'],
            'mostrador/contexto/{n} por PUT'           => ['PUT', '/mostrador/contexto/15'],
            'mostrador/memoria/{n} por GET'            => ['GET', '/mostrador/memoria/15'],
            'mostrador sin nada más'                   => ['GET', '/mostrador'],
            'reportes con id no numérico'              => ['GET', '/mostrador/reportes/abc'],
            'reportes con .. adentro'                  => ['GET', '/mostrador/reportes/15/../../sistema-query'],
            'reportes con id y un segmento de más'     => ['GET', '/mostrador/reportes/15/extra'],
            'reportes sin id'                          => ['GET', '/mostrador/reportes/'],
            'barra doble'                              => ['GET', '//mostrador/duenos'],
            'barra de más al final'                    => ['GET', '/mostrador/duenos/'],
            'id con signo'                             => ['PUT', '/mostrador/memoria/-5'],
            'update-default-version con segmento'      => ['PUT', '/update-default-version/x'],
            'mayúsculas en la ruta'                    => ['GET', '/Mostrador/Duenos'],
        ];
    }

    /**
     * 🔴 Una ruta fuera de la lista blanca es 422 `validacion` con `rutas_permitidas` y NO le pega al cliente.
     *
     * @dataProvider las_rutas_fuera_de_la_lista_blanca
     *
     * @param string $metodo Método del pedido.
     * @param string $ruta   Ruta del pedido.
     *
     * @return void
     */
    public function test_una_ruta_fuera_de_la_lista_blanca_es_422_y_no_llama_al_cliente(string $metodo, string $ruta): void
    {
        Http::fake();

        $respuesta = $this->puente(['metodo' => $metodo, 'ruta' => $ruta]);

        $respuesta->assertStatus(422);
        $this->assertSame('validacion', $respuesta->json('error'));
        $this->assertArrayHasKey('ruta', $respuesta->json('detalle'));

        Http::assertNothingSent();
    }

    /**
     * La lista blanca que se devuelve en el 422 son EXACTAMENTE las siete entradas del servicio.
     *
     * @return void
     */
    public function test_el_422_trae_las_siete_entradas_de_la_lista_blanca(): void
    {
        Http::fake();

        $respuesta = $this->puente(['metodo' => 'GET', 'ruta' => '/sistema-query']);

        $respuesta->assertStatus(422);
        $this->assertSame(ClientAdminSyncPuenteService::rutas_permitidas(), $respuesta->json('rutas_permitidas'));
        $this->assertCount(7, $respuesta->json('rutas_permitidas'));
    }

    /**
     * Un método que no es GET, POST ni PUT (DELETE, PATCH, uno inventado) es 422, y también un pedido sin método ni ruta o con ruta sin barra.
     *
     * @return void
     */
    public function test_un_metodo_que_no_es_get_post_ni_put_y_los_pedidos_mal_formados_son_422(): void
    {
        Http::fake();

        foreach (['DELETE', 'PATCH', 'OPTIONS', 'HACK'] as $metodo) {
            $this->puente(['metodo' => $metodo, 'ruta' => '/mostrador/duenos'])->assertStatus(422)->assertJsonPath('detalle.metodo', 'Tiene que ser GET, POST o PUT: el puente no reenvía ningún otro método (llegó "' . $metodo . '").');
        }

        $sin = $this->puente([]);
        $sin->assertStatus(422);
        $this->assertArrayHasKey('metodo', $sin->json('detalle'));
        $this->assertArrayHasKey('ruta', $sin->json('detalle'));

        $this->puente(['metodo' => 'GET', 'ruta' => 'mostrador/duenos'])->assertStatus(422)->assertJsonStructure(['detalle' => ['ruta']]);
        $this->puente(['metodo' => 'GET', 'ruta' => '/mostrador/duenos#x'])->assertStatus(422)->assertJsonStructure(['detalle' => ['ruta']]);
        $this->puente(['metodo' => 'GET', 'ruta' => '/mostrador/duenos' . str_repeat('a', 2100)])->assertStatus(422)->assertJsonStructure(['detalle' => ['ruta']]);

        Http::assertNothingSent();
    }

    /**
     * Un parámetro de más, un cuerpo en un GET o que no es objeto ni lista, y `_method` por la query o por el cuerpo son 422 y no llaman.
     *
     * @return void
     */
    public function test_parametros_de_mas_cuerpos_invalidos_y_method_override_son_422(): void
    {
        Http::fake();

        $this->puente(['metodo' => 'GET', 'ruta' => '/mostrador/duenos', 'headers' => ['X' => 'y']])->assertStatus(422)->assertJsonStructure(['detalle' => ['headers']]);
        $this->puente(['metodo' => 'GET', 'ruta' => '/mostrador/duenos', 'cuerpo' => ['a' => 1]])->assertStatus(422)->assertJsonStructure(['detalle' => ['cuerpo']]);
        $this->puente(['metodo' => 'PUT', 'ruta' => '/mostrador/reportes/1', 'cuerpo' => 'texto'])->assertStatus(422)->assertJsonStructure(['detalle' => ['cuerpo']]);

        foreach (['_method=DELETE', '.method=DELETE', '%5Fmethod=DELETE', '_METHOD=DELETE'] as $query) {
            $this->puente(['metodo' => 'PUT', 'ruta' => '/mostrador/reportes/1?' . $query])->assertStatus(422)->assertJsonStructure(['detalle' => ['ruta']]);
        }

        $this->puente(['metodo' => 'PUT', 'ruta' => '/update-default-version', 'cuerpo' => ['_method' => 'DELETE']])->assertStatus(422)->assertJsonStructure(['detalle' => ['cuerpo']]);

        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------------------------------
     | 4. El HTTP del cliente viaja adentro
     |----------------------------------------------------------------------------------------- */

    /**
     * Un 401, un 404, un 422, un 202 y un 500 del cliente vuelven como 200 `puente: true` con su `status` y su `cuerpo`.
     *
     * @return array<string, array<int, mixed>>
     */
    public function los_codigos_del_cliente(): array
    {
        return [
            '401' => [401, ['message' => 'Unauthenticated.']],
            '404' => [404, ['message' => 'Not Found']],
            '422' => [422, ['errors' => ['x' => ['mal']]]],
            '202' => [202, ['estado' => 'calculando', 'reporte_id' => 15]],
            '500' => [500, ['message' => 'Server Error']],
        ];
    }

    /**
     * @dataProvider los_codigos_del_cliente
     *
     * @param int                  $status HTTP del cliente.
     * @param array<string, mixed> $cuerpo Su cuerpo.
     *
     * @return void
     */
    public function test_un_codigo_del_cliente_vuelve_como_200_con_su_status_y_su_cuerpo(int $status, array $cuerpo): void
    {
        $this->el_cliente_contesta($cuerpo, $status);

        $respuesta = $this->puente(['metodo' => 'POST', 'ruta' => '/mostrador/hechos']);

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('puente'));
        $this->assertSame($status, $respuesta->json('status'));
        $this->assertSame($cuerpo, $respuesta->json('cuerpo'));
        $this->assertNull($respuesta->json('error'), 'El código del cliente no se confunde con un error del admin.');
    }

    /**
     * Un cuerpo que no es JSON vuelve en `cuerpo_crudo` (hasta 2000 caracteres) y `cuerpo` es null.
     *
     * @return void
     */
    public function test_un_cuerpo_que_no_es_json_vuelve_en_cuerpo_crudo_cortado_a_2000(): void
    {
        $this->el_cliente_contesta(str_repeat('x', 5000), 502);

        $respuesta = $this->puente(['metodo' => 'GET', 'ruta' => '/mostrador/duenos']);

        $respuesta->assertStatus(200);
        $this->assertSame(502, $respuesta->json('status'));
        $this->assertNull($respuesta->json('cuerpo'));
        $this->assertSame(2000, strlen((string) $respuesta->json('cuerpo_crudo')));
    }

    /**
     * 🔴 No sigue redirecciones: un 302 vuelve con su `status` y el puente NO le pega al destino ni le manda la clave.
     *
     * @return void
     */
    public function test_no_sigue_redirecciones(): void
    {
        $this->http_falso([
            'api-doblep.ejemplo.test/*' => Http::response('', 302, ['Location' => 'https://otro.ejemplo.test/robar']),
            'otro.ejemplo.test/*'       => Http::response(['visto' => true], 200),
        ]);

        $respuesta = $this->puente(['metodo' => 'PUT', 'ruta' => '/update-default-version', 'cuerpo' => ['default_version' => 'x']]);

        $respuesta->assertStatus(200);
        $this->assertSame(302, $respuesta->json('status'));

        Http::assertSentCount(1);
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), 'otro.ejemplo.test') !== false;
        });
    }

    /* ------------------------------------------------------------------------------------------
     | 5. Cuando el admin no llega al cliente
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Sin `api_key` en el admin es 409 `sin_api_key`, ANTES de llamar a nadie: una clave vacía o de solo espacios cuenta como vacía.
     *
     * @return void
     */
    public function test_sin_api_key_es_409_y_no_llama_al_cliente(): void
    {
        Http::fake();

        foreach (['', '   '] as $vacia) {
            $cliente = $this->crear_cliente('Cliente sin clave', $vacia);
            $this->crear_frente($cliente, 'sinclave' . strlen($vacia), 'shared_hosting', null, true);

            $respuesta = $this->puente(['metodo' => 'GET', 'ruta' => '/mostrador/duenos'], $cliente);

            $respuesta->assertStatus(409);
            $this->assertSame('sin_api_key', $respuesta->json('error'));
            $this->assertStringContainsString('catalogo/clave', (string) $respuesta->json('mensaje'));
        }

        Http::assertNothingSent();
    }

    /**
     * Sin una URL de `empresa-api` resoluble es 409 `sin_url`, ANTES de llamar a nadie.
     *
     * @return void
     */
    public function test_sin_url_es_409_y_no_llama_al_cliente(): void
    {
        Http::fake();

        $cliente = $this->crear_cliente('Cliente sin API', 'sk-sin-url-' . Str::random(30));

        $respuesta = $this->puente(['metodo' => 'GET', 'ruta' => '/mostrador/duenos'], $cliente);

        $respuesta->assertStatus(409);
        $this->assertSame('sin_url', $respuesta->json('error'));

        Http::assertNothingSent();
    }

    /**
     * 🔴 Un timeout es 502 `cliente_no_responde`, sin reintentar, y el mensaje de la excepción —aunque traiga la clave adentro— sale con la
     * clave tapada. Tampoco lleva la query de la URL.
     *
     * @return void
     */
    public function test_un_timeout_es_502_sin_reintentar_sin_filtrar_la_clave_ni_la_query(): void
    {
        $clave    = $this->clave;
        $intentos = 0;

        $this->http_falso(['*' => function () use ($clave, &$intentos) {
            $intentos++;

            throw new ConnectionException(
                'cURL error 28: Operation timed out after 600001 milliseconds (X-Admin-Api-Key: ' . $clave . ') for '
                . 'https://api-doblep.ejemplo.test/public/api/admin-sync/mostrador/duenos?secreto=DATO-DEL-NEGOCIO'
            );
        }]);

        $registro = $this->capturar_el_log();

        $respuesta = $this->puente(['metodo' => 'POST', 'ruta' => '/mostrador/hechos']);

        $respuesta->assertStatus(502);
        $this->assertSame('cliente_no_responde', $respuesta->json('error'));
        $this->assertStringContainsString('timed out', (string) $respuesta->json('mensaje'));
        $this->assertStringContainsString('[clave oculta]', (string) $respuesta->json('mensaje'));
        $this->assertStringNotContainsString('DATO-DEL-NEGOCIO', (string) $respuesta->getContent());

        $this->assertSame(1, $intentos, 'Un timeout NO se reintenta.');

        $this->assertSinLaClave($respuesta, $this->clave);
        $this->assertLogSinLaClave($registro, $this->clave);
        $this->assertStringNotContainsString('DATO-DEL-NEGOCIO', implode("\n", $registro->lineas));
    }

    /**
     * El techo de la llamada es el de `mostrador.php` (600 s) salvo que la config diga otra cosa.
     *
     * @return void
     */
    public function test_el_timeout_por_defecto_es_de_600_segundos_y_se_puede_cambiar(): void
    {
        $opciones = [];

        $this->http_falso(['*' => function ($request, $o) use (&$opciones) {
            $opciones[] = isset($o['timeout']) ? $o['timeout'] : null;

            return Http::response(['ok' => true], 200);
        }]);

        $this->puente(['metodo' => 'GET', 'ruta' => '/mostrador/duenos'])->assertStatus(200);

        config(['services.client_api.admin_sync_puente_timeout' => 90]);

        $this->puente(['metodo' => 'GET', 'ruta' => '/mostrador/duenos'])->assertStatus(200);

        $this->assertSame([600, 90], $opciones);
    }

    /* ------------------------------------------------------------------------------------------
     | 6. La clave no sale por ningún lado
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Si el cuerpo que devuelve el cliente REPITE la clave, el puente la tapa: en el JSON decodificado y en el texto crudo.
     *
     * @return void
     */
    public function test_si_el_cliente_repite_la_clave_el_puente_la_tapa(): void
    {
        $clave = $this->clave;

        $this->el_cliente_contesta(['message' => 'Unauthenticated', 'debug' => ['headers' => ['x-admin-api-key' => $clave]]], 401);

        $json = $this->puente(['metodo' => 'GET', 'ruta' => '/mostrador/duenos']);

        $json->assertStatus(200);
        $this->assertSame('[clave oculta]', $json->json('cuerpo.debug.headers.x-admin-api-key'));
        $this->assertSinLaClave($json, $clave, 'JSON.');

        $this->el_cliente_contesta('<pre>X-Admin-Api-Key: ' . $clave . '</pre>', 500);

        $texto = $this->puente(['metodo' => 'GET', 'ruta' => '/mostrador/duenos']);

        $texto->assertStatus(200);
        $this->assertNull($texto->json('cuerpo'));
        $this->assertStringContainsString('[clave oculta]', (string) $texto->json('cuerpo_crudo'));
        $this->assertSinLaClave($texto, $clave, 'Texto.');
    }

    /**
     * 🔴 El log de cada llamada lleva método, ruta SIN query, status y milisegundos: nunca el cuerpo ni la clave.
     *
     * @return void
     */
    public function test_el_log_lleva_metodo_ruta_sin_query_status_y_ms_y_nunca_el_cuerpo_ni_la_clave(): void
    {
        $registro = $this->capturar_el_log();

        $this->el_cliente_contesta(['secreto_del_cuerpo' => 'no-debe-estar-en-el-log', 'eco' => $this->clave], 200);

        $this->puente(['metodo' => 'GET', 'ruta' => '/mostrador/reportes/15?fecha=2026-10-10'])->assertStatus(200);

        $propias = array_values(array_filter($registro->registros, function ($r) {
            return strpos($r['mensaje'], 'ClientAdminSyncPuenteService') === 0;
        }));

        $this->assertCount(1, $propias);
        $this->assertSame('GET', $propias[0]['contexto']['metodo']);
        $this->assertSame('/mostrador/reportes/15', $propias[0]['contexto']['ruta'], 'La ruta del log va SIN query.');
        $this->assertSame(200, $propias[0]['contexto']['status']);
        $this->assertIsInt($propias[0]['contexto']['ms']);

        $todo = implode("\n", $registro->lineas);
        $this->assertStringNotContainsString('no-debe-estar-en-el-log', $todo);
        $this->assertStringNotContainsString('fecha=', $todo);
        $this->assertLogSinLaClave($registro, $this->clave);
    }

    /* ------------------------------------------------------------------------------------------
     | 7. El catálogo dice lo mismo que el controlador
     |----------------------------------------------------------------------------------------- */

    /**
     * Los parámetros que `config/claude_catalog.php` declara para A3 son EXACTAMENTE la lista cerrada del controlador, y el catálogo nombra
     * las siete entradas de la lista blanca del servicio.
     *
     * @return void
     */
    public function test_el_catalogo_declara_los_mismos_parametros_y_las_mismas_rutas(): void
    {
        $endpoint = config('claude_catalog.endpoints.POST api/claude/clients/{id}/admin-sync/puente');

        $this->assertIsArray($endpoint, 'El catálogo no tiene la entrada de A3.');
        $this->assertTrue($endpoint['escribe']);

        $declarados = [];
        foreach ($endpoint['parametros'] as $parametro) {
            if (strpos($parametro['nombre'], '(en la ruta)') === false) {
                $declarados[] = $parametro['nombre'];
            }
        }

        $aceptados = ClaudeClientAdminSyncController::PARAMETROS_DEL_PUENTE_DE_ADMIN_SYNC;

        sort($declarados);
        sort($aceptados);

        $this->assertSame($aceptados, $declarados);

        foreach (ClientAdminSyncPuenteService::rutas_permitidas() as $entrada) {
            $this->assertStringContainsString($entrada, $endpoint['para_que'], 'El catálogo no nombra ' . $entrada);
        }
    }
}
