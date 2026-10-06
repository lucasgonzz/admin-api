<?php

namespace Tests\Feature\CatalogoDelClientePorClaude;

use App\Http\Controllers\Api\ClaudeClientCatalogoController;
use App\Models\Client;
use App\Services\ClientCatalogoPuenteService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Fakes\HttpFactorySinSalida;

/**
 * `POST claude/clients/{id}/catalogo/puente` (C2, misión `implementacion-dos-sistemas`, 6/10/2026): el
 * admin reenvía UN pedido de una lista blanca de nueve rutas al `admin-sync/catalogo/*` del
 * `empresa-api` del cliente, con la clave del cliente, y devuelve lo que contestó.
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 **La lista blanca es la única puerta.** Cada una de las nueve rutas pasa; cualquier otra
 *     combinación de método y ruta (incluidas las que intentan escaparse con `..`, una barra de más o
 *     un segmento de más) es 422 y NO le pega al cliente (`Http::assertNothingSent()`).
 *  2. 🔴 **La clave del cliente viaja en el header y NO sale por ningún lado**: ni en la respuesta,
 *     ni en un error, ni en el log — aunque el cuerpo que devuelve el cliente la repita, o la
 *     excepción de conexión la traiga adentro. Cada test afirma contra el VALOR de la clave.
 *  3. 🔴 **El HTTP del cliente viaja adentro**: un 401/404/422/500 del cliente vuelve como 200
 *     `puente: true` con su `status` y su `cuerpo`. Solo cuando el admin no llegó (sin clave, sin URL,
 *     timeout) son 409/502 con un código en `error`.
 *  4. **Fidelidad**: el cuerpo de un POST llega como el JSON original (un objeto con claves numéricas
 *     sigue siendo un objeto) y un `{}` del cliente vuelve como `{}`.
 *
 * Nada sale a internet: el `TestCase` base cambia el cliente HTTP por uno que lanza ante todo pedido
 * que ningún `Http::fake()` atienda, y hace fallar el test que lo intentó.
 */
class ElPuenteDelCatalogoPorClaudeTest extends BaseDelCatalogoPorClaude
{
    /**
     * La clave del cliente de los tests: larga y con un prefijo reconocible.
     *
     * @var string
     */
    private $clave;

    /**
     * El cliente de los tests, con su frente de shared hosting activo.
     *
     * @var Client
     */
    private $cliente;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clave   = 'sk-puente-' . Str::random(30);
        $this->cliente = $this->crear_cliente('Doblep Distribuciones', $this->clave);

        $this->crear_frente($this->cliente, 'doblep', 'shared_hosting', null, true);
    }

    /**
     * La URL del puente para un cliente.
     *
     * @param Client|int|string $cliente Cliente, id o uuid.
     *
     * @return string
     */
    private function url($cliente = null): string
    {
        $cliente = $cliente === null ? $this->cliente : $cliente;
        $id      = $cliente instanceof Client ? $cliente->id : $cliente;

        return '/api/claude/clients/' . $id . '/catalogo/puente';
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
     * Falsea al cliente: contesta lo mismo a cualquier pedido.
     *
     * @param mixed $cuerpo Cuerpo (array/objeto = JSON, string = texto crudo).
     * @param int   $status HTTP.
     *
     * @return void
     */
    private function el_cliente_contesta($cuerpo, int $status = 200): void
    {
        $this->http_falso(['*' => Http::response($cuerpo, $status)]);
    }

    /**
     * Pone una fábrica de HTTP LIMPIA (sin los stubs ni los pedidos de antes) y la falsea con lo dado.
     *
     * 🔴 Es necesario cuando un test falsea más de una vez: los stubs de `Http::fake()` se ACUMULAN y
     * gana el primero que responde, así que un segundo `fake()` no pisa al primero. Se parte de
     * `HttpFactorySinSalida` y NO de la fábrica pelada de Laravel, para que lo que ningún stub atienda
     * siga sin poder salir a internet.
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
     * El último pedido que llegó al cliente (casi todos los tests mandan uno solo).
     *
     * @return \Illuminate\Http\Client\Request
     */
    private function pedido_al_cliente()
    {
        $enviados = [];

        Http::assertSent(function ($request) use (&$enviados) {
            $enviados[] = $request;

            return true;
        });

        return $enviados[count($enviados) - 1];
    }

    /* ------------------------------------------------------------------------------------------
     | 1. La puerta y los errores del admin
     |----------------------------------------------------------------------------------------- */

    /**
     * El bloque es fail-closed: sin el header de ingesta no contesta, y no le pega a nadie.
     *
     * @return void
     */
    public function test_sin_la_clave_de_ingesta_devuelve_401_y_no_le_pega_al_cliente(): void
    {
        Http::fake();

        $this->postJson($this->url(), ['metodo' => 'GET', 'ruta' => '/resumen'])->assertStatus(401);

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

        $respuesta = $this->postJson($this->url(987654321), ['metodo' => 'GET', 'ruta' => '/resumen'], $this->headers());

        $respuesta->assertStatus(404);
        $this->assertSame('cliente_inexistente', $respuesta->json('error'));

        $por_uuid = $this->postJson($this->url((string) Str::uuid()), ['metodo' => 'GET', 'ruta' => '/resumen'], $this->headers());
        $por_uuid->assertStatus(404);
        $this->assertSame('cliente_inexistente', $por_uuid->json('error'));

        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------------------------------
     | 2. La lista blanca: las nueve rutas pasan
     |----------------------------------------------------------------------------------------- */

    /**
     * Las nueve rutas de la lista blanca: método, ruta que pide el motor y la URL que tiene que
     * recibir el cliente (shared hosting: la API vive bajo `/public`).
     *
     * @return array<string, array<int, string>>
     */
    public function las_rutas_de_la_lista_blanca(): array
    {
        $base = 'https://api-doblep.ejemplo.test/public/api/admin-sync/catalogo';

        return [
            'GET resumen'                        => ['GET', '/resumen', $base . '/resumen'],
            'GET articulos'                      => ['GET', '/articulos', $base . '/articulos'],
            'POST propuestas'                    => ['POST', '/categorias/propuestas', $base . '/categorias/propuestas'],
            'GET propuesta actual'               => ['GET', '/categorias/propuestas/actual', $base . '/categorias/propuestas/actual'],
            'GET propuesta por numero'           => ['GET', '/categorias/propuestas/7', $base . '/categorias/propuestas/7'],
            'POST asignaciones'                  => ['POST', '/categorias/propuestas/7/asignaciones', $base . '/categorias/propuestas/7/asignaciones'],
            'GET pendientes'                     => ['GET', '/categorias/propuestas/7/pendientes', $base . '/categorias/propuestas/7/pendientes'],
            'POST listo'                         => ['POST', '/categorias/propuestas/7/listo', $base . '/categorias/propuestas/7/listo'],
            'POST descartar'                     => ['POST', '/categorias/propuestas/7/descartar', $base . '/categorias/propuestas/7/descartar'],
            'GET propuesta con varios digitos'   => ['GET', '/categorias/propuestas/1234567890', $base . '/categorias/propuestas/1234567890'],
        ];
    }

    /**
     * 🔴 Cada ruta de la lista blanca pasa: llega al cliente con el método y la URL correctos, con la
     * clave del cliente en `X-Admin-Api-Key` y `Accept: application/json`, y la respuesta es 200
     * `puente: true` con el status y el cuerpo del cliente — y sin la clave.
     *
     * @dataProvider las_rutas_de_la_lista_blanca
     *
     * @param string $metodo       Método del pedido.
     * @param string $ruta         Ruta que pide el motor.
     * @param string $url_esperada URL que tiene que recibir el cliente.
     *
     * @return void
     */
    public function test_cada_ruta_de_la_lista_blanca_pasa_con_la_clave_en_el_header(string $metodo, string $ruta, string $url_esperada): void
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
     * El método se acepta sin importar mayúsculas ni espacios.
     *
     * @return void
     */
    public function test_el_metodo_se_acepta_en_minusculas(): void
    {
        $this->el_cliente_contesta(['ok' => true]);

        $this->puente(['metodo' => ' get ', 'ruta' => '/resumen'])->assertStatus(200);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET';
        });
    }

    /**
     * La query se reenvía tal cual, en una ruta GET y en una POST. El PATH sigue siendo el de la lista.
     *
     * @return void
     */
    public function test_la_query_se_reenvia_tal_cual(): void
    {
        $this->el_cliente_contesta(['ok' => true]);

        $this->puente(['metodo' => 'GET', 'ruta' => '/articulos?desde_id=100&limite=500&orden=desc'])->assertStatus(200);
        $this->puente(['metodo' => 'POST', 'ruta' => '/categorias/propuestas/7/listo?forzar=0'])->assertStatus(200);

        $base = 'https://api-doblep.ejemplo.test/public/api/admin-sync/catalogo';

        Http::assertSent(function ($request) use ($base) {
            return $request->method() === 'GET' && $request->url() === $base . '/articulos?desde_id=100&limite=500&orden=desc';
        });
        Http::assertSent(function ($request) use ($base) {
            return $request->method() === 'POST' && $request->url() === $base . '/categorias/propuestas/7/listo?forzar=0';
        });
    }

    /**
     * Un frente de VPS no lleva `/public`: la URL es la de la API tal cual.
     *
     * @return void
     */
    public function test_un_cliente_de_vps_le_pega_a_su_api_sin_public(): void
    {
        $cliente = $this->crear_cliente('Cliente del VPS', 'sk-vps-' . Str::random(30));
        $this->crear_frente($cliente, 'delvps', 'vps', 'delvps', true);

        $this->el_cliente_contesta(['ok' => true]);

        $this->puente(['metodo' => 'GET', 'ruta' => '/resumen'], $cliente)->assertStatus(200);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api-delvps.ejemplo.test/api/admin-sync/catalogo/resumen';
        });
    }

    /**
     * Se le habla a la API ACTIVA del cliente, no a cualquiera de sus frentes.
     *
     * @return void
     */
    public function test_se_le_habla_a_la_api_activa(): void
    {
        $segunda = $this->crear_frente($this->cliente, 'doblep2');

        $this->cliente->active_client_api_id = $segunda->id;
        $this->cliente->save();

        $this->el_cliente_contesta(['ok' => true]);

        $this->puente(['metodo' => 'GET', 'ruta' => '/resumen'])->assertStatus(200);

        Http::assertSent(function ($request) {
            return strpos($request->url(), 'https://api-doblep2.ejemplo.test/public/') === 0;
        });
    }

    /* ------------------------------------------------------------------------------------------
     | 3. El cuerpo del POST
     |----------------------------------------------------------------------------------------- */

    /**
     * Un POST reenvía el cuerpo tal cual, con `Content-Type: application/json`, sin escapar unicode
     * ni barras.
     *
     * @return void
     */
    public function test_un_post_reenvia_el_cuerpo_tal_cual(): void
    {
        $this->el_cliente_contesta(['ok' => true]);

        $cuerpo = [
            'asignaciones' => [
                ['articulo_id' => 12, 'categoria' => 'Fijaciones > Tornillos'],
                ['articulo_id' => 13, 'categoria' => 'Camión / Ñandú'],
            ],
            'nota' => 'con "comillas" y 3.0',
        ];

        $this->puente(['metodo' => 'POST', 'ruta' => '/categorias/propuestas/7/asignaciones', 'cuerpo' => $cuerpo])->assertStatus(200);

        $pedido = $this->pedido_al_cliente();

        $this->assertSame(json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $pedido->body());
        $this->assertStringContainsString('application/json', implode(',', $pedido->header('Content-Type')));
    }

    /**
     * 🔴 Un cuerpo con claves numéricas ({"12": "Fijaciones"}, artículo → categoría) llega como OBJETO.
     * `Http::post($url, $array)` pasa por `array_merge`, que renumera las claves enteras de arriba, y
     * el cliente recibiría `[0 => ...]` sin que nada lo avisara.
     *
     * @return void
     */
    public function test_un_objeto_con_claves_numericas_llega_como_objeto(): void
    {
        $this->el_cliente_contesta(['ok' => true]);

        $this->puente([
            'metodo' => 'POST',
            'ruta'   => '/categorias/propuestas/7/asignaciones',
            'cuerpo' => (object) ['12' => 'Fijaciones', '13' => 'Pinturas', '9' => 'Herramientas'],
        ])->assertStatus(200);

        $this->assertSame('{"12":"Fijaciones","13":"Pinturas","9":"Herramientas"}', $this->pedido_al_cliente()->body());
    }

    /**
     * Un objeto que parece una lista ({"0": "a", "1": "b"}) sigue siendo un objeto, un `{}` sigue siendo
     * `{}`, una lista sigue siendo una lista y un POST sin cuerpo viaja como `{}`.
     *
     * @return void
     */
    public function test_el_cuerpo_conserva_su_forma_de_objeto_o_de_lista(): void
    {
        $casos = [
            'objeto que parece lista' => [(object) ['0' => 'a', '1' => 'b'], '{"0":"a","1":"b"}'],
            'objeto vacio'            => [(object) [], '{}'],
            'lista'                   => [[1, 2, 3], '[1,2,3]'],
            'lista de objetos'        => [[(object) ['id' => 1]], '[{"id":1}]'],
        ];

        foreach ($casos as $nombre => $caso) {
            $this->el_cliente_contesta(['ok' => true]);

            $this->puente(['metodo' => 'POST', 'ruta' => '/categorias/propuestas', 'cuerpo' => $caso[0]])->assertStatus(200);

            $this->assertSame($caso[1], $this->pedido_al_cliente()->body(), $nombre);
        }

        /* Sin `cuerpo`: un POST viaja con `{}`. */
        $this->el_cliente_contesta(['ok' => true]);

        $this->puente(['metodo' => 'POST', 'ruta' => '/categorias/propuestas/7/listo'])->assertStatus(200);

        $this->assertSame('{}', $this->pedido_al_cliente()->body());
    }

    /**
     * Un GET con un `cuerpo` VACÍO se acepta (es lo mismo que no mandarlo) y no viaja nada.
     *
     * @return void
     */
    public function test_un_cuerpo_vacio_en_un_get_se_ignora(): void
    {
        $this->el_cliente_contesta(['ok' => true]);

        $this->puente(['metodo' => 'GET', 'ruta' => '/resumen', 'cuerpo' => []])->assertStatus(200);

        $this->assertSame('', $this->pedido_al_cliente()->body());
    }

    /* ------------------------------------------------------------------------------------------
     | 4. Fuera de la lista blanca: 422 y NO se le pega al cliente
     |----------------------------------------------------------------------------------------- */

    /**
     * Combinaciones de método y ruta que NO están en la lista blanca. Incluye los intentos de salirse
     * por el path (`..`, barra doble, un segmento de más, una ruta de otro bloque del cliente).
     *
     * @return array<string, array<int, string>>
     */
    public function pedidos_fuera_de_la_lista_blanca(): array
    {
        return [
            'POST a una ruta de lectura'              => ['POST', '/resumen'],
            'GET a una ruta de escritura'             => ['GET', '/categorias/propuestas'],
            'GET asignaciones (es POST)'              => ['GET', '/categorias/propuestas/7/asignaciones'],
            'POST pendientes (es GET)'                => ['POST', '/categorias/propuestas/7/pendientes'],
            'POST de una propuesta (es GET)'          => ['POST', '/categorias/propuestas/7'],
            'POST actual (es GET)'                    => ['POST', '/categorias/propuestas/actual'],
            'numero que no es numero'                 => ['GET', '/categorias/propuestas/abc'],
            'numero con letras'                       => ['GET', '/categorias/propuestas/12abc'],
            'numero negativo'                         => ['GET', '/categorias/propuestas/-1'],
            'segmento de mas'                         => ['GET', '/categorias/propuestas/7/otra'],
            'segmento de mas despues de actual'       => ['GET', '/categorias/propuestas/actual/algo'],
            'barra final'                             => ['GET', '/articulos/'],
            'mayusculas'                              => ['GET', '/Articulos'],
            'subir un nivel'                          => ['GET', '/../resumen'],
            'subir dos niveles'                       => ['GET', '/resumen/../../articulos'],
            'subir hacia otra ruta del cliente'       => ['GET', '/../user-setup'],
            'barra doble'                             => ['GET', '//resumen'],
            'barra doble adentro'                     => ['GET', '/categorias//propuestas/7'],
            'barra codificada'                        => ['GET', '/categorias/propuestas/7%2F..%2Fresumen'],
            'punto y coma'                            => ['GET', '/resumen;otra'],
            'con la ruta completa'                    => ['GET', '/api/admin-sync/catalogo/resumen'],
            'otro bloque del cliente'                 => ['GET', '/user-setup'],
            'otro bloque de admin-sync'               => ['POST', '/modelos-ia'],
            'raiz'                                    => ['GET', '/'],
            'otro host'                               => ['GET', '//ejemplo.test/resumen'],
        ];
    }

    /**
     * 🔴 Todo lo que no está en la lista blanca es 422 `validacion` con la lista de rutas permitidas, y
     * NO se le pega al cliente: ni una llamada.
     *
     * @dataProvider pedidos_fuera_de_la_lista_blanca
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
        $this->assertContains('GET /resumen', $respuesta->json('rutas_permitidas'));
        $this->assertCount(9, $respuesta->json('rutas_permitidas'));

        Http::assertNothingSent();
        $this->assertSinLaClave($respuesta, $this->clave);
    }

    /**
     * Los métodos que no son GET ni POST son 422, aunque la ruta sea de la lista.
     *
     * @return void
     */
    public function test_un_metodo_que_no_es_get_ni_post_es_422(): void
    {
        Http::fake();

        foreach (['PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS', 'TRACE', 'CONNECT', 'GETS', 'POST, GET'] as $metodo) {
            $respuesta = $this->puente(['metodo' => $metodo, 'ruta' => '/resumen']);

            $respuesta->assertStatus(422);
            $this->assertSame('validacion', $respuesta->json('error'), $metodo);
            $this->assertArrayHasKey('metodo', $respuesta->json('detalle'), $metodo);
        }

        Http::assertNothingSent();
    }

    /**
     * La ruta sin `/` inicial es 422 (es relativa a /api/admin-sync/catalogo y empieza con barra).
     *
     * @return void
     */
    public function test_una_ruta_sin_barra_inicial_es_422(): void
    {
        Http::fake();

        $respuesta = $this->puente(['metodo' => 'GET', 'ruta' => 'resumen']);

        $respuesta->assertStatus(422);
        $this->assertSame('validacion', $respuesta->json('error'));
        $this->assertArrayHasKey('ruta', $respuesta->json('detalle'));

        Http::assertNothingSent();
    }

    /**
     * Una ruta con caracteres de control, un `#` o demasiado larga es 422.
     *
     * @return void
     */
    public function test_una_ruta_con_caracteres_raros_o_demasiado_larga_es_422(): void
    {
        Http::fake();

        $malas = [
            'salto de linea adentro' => "/resumen?x=1\r\nX-Otro: 1",
            'nulo adentro'           => "/resumen?x=\0y",
            'tabulacion adentro'     => "/resumen?x=1\t2",
            'fragmento'              => '/resumen?x=1#algo',
            'demasiado larga'        => '/articulos?q=' . str_repeat('a', ClaudeClientCatalogoController::MAX_RUTA),
            'no es un texto'         => ['/resumen'],
        ];

        foreach ($malas as $nombre => $ruta) {
            $respuesta = $this->puente(['metodo' => 'GET', 'ruta' => $ruta]);

            $respuesta->assertStatus(422);
            $this->assertArrayHasKey('ruta', $respuesta->json('detalle'), $nombre);
        }

        Http::assertNothingSent();
    }

    /**
     * Sin `metodo` ni `ruta`: 422 con el detalle de los dos campos.
     *
     * @return void
     */
    public function test_sin_metodo_ni_ruta_es_422_con_el_detalle_de_los_dos(): void
    {
        Http::fake();

        $respuesta = $this->puente([]);

        $respuesta->assertStatus(422);
        $this->assertSame('validacion', $respuesta->json('error'));
        $this->assertSame(['metodo', 'ruta'], array_keys($respuesta->json('detalle')));

        Http::assertNothingSent();
    }

    /**
     * 🔴 Un `cuerpo` con contenido en un GET es 422; un `cuerpo` que no es objeto ni lista, también.
     *
     * @return void
     */
    public function test_un_cuerpo_en_un_get_o_que_no_es_objeto_ni_lista_es_422(): void
    {
        Http::fake();

        $en_un_get = $this->puente(['metodo' => 'GET', 'ruta' => '/resumen', 'cuerpo' => ['a' => 1]]);
        $en_un_get->assertStatus(422);
        $this->assertArrayHasKey('cuerpo', $en_un_get->json('detalle'));

        foreach (['texto', 12, true] as $malo) {
            $respuesta = $this->puente(['metodo' => 'POST', 'ruta' => '/categorias/propuestas', 'cuerpo' => $malo]);

            $respuesta->assertStatus(422);
            $this->assertArrayHasKey('cuerpo', $respuesta->json('detalle'), json_encode($malo));
        }

        Http::assertNothingSent();
    }

    /**
     * 🔴 La lista de parámetros es cerrada: nada de `url`, `headers`, `timeout` ni cualquier otra cosa
     * con la que alguien intente cambiar adónde o cómo se le pega al cliente.
     *
     * @return void
     */
    public function test_un_parametro_de_mas_es_422_y_no_llama_al_cliente(): void
    {
        Http::fake();

        $respuesta = $this->puente([
            'metodo'  => 'GET',
            'ruta'    => '/resumen',
            'url'     => 'https://otro.ejemplo.test/robar',
            'headers' => ['X-Admin-Api-Key' => 'otra'],
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame('validacion', $respuesta->json('error'));
        $this->assertSame(['url', 'headers'], array_keys($respuesta->json('detalle')));

        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------------------------------
     | 4 bis. `_method`: el método simulado de Laravel no se cuela por la query ni por el cuerpo
     |----------------------------------------------------------------------------------------- */

    /**
     * Pedidos que traen `_method` por la query de la `ruta` o por el primer nivel del `cuerpo`, escrito
     * de todas las formas en las que PHP o Laravel lo van a reconocer del lado del cliente.
     *
     * El `empresa-api` del cliente es una app Laravel con el "method override" prendido: en un POST,
     * `_method` pasa a ser el método REAL con el que se resuelve la ruta. Un `POST .../listo?_method=DELETE`
     * esquivaría la lista blanca de métodos. Cada fila: método, ruta, cuerpo y el campo del 422.
     *
     * @return array<string, array<int, mixed>>
     */
    public function pedidos_con_method_override(): array
    {
        $propuesta = '/categorias/propuestas/7/listo';

        return [
            'query _method'                              => ['POST', $propuesta . '?_method=DELETE', null, 'ruta'],
            'query _METHOD en mayúsculas'                => ['POST', $propuesta . '?_METHOD=delete', null, 'ruta'],
            'query _MeThOd mezclado'                     => ['POST', $propuesta . '?_MeThOd=PUT', null, 'ruta'],
            'query con otros parámetros antes'           => ['POST', $propuesta . '?forzar=0&_method=PUT', null, 'ruta'],
            'query en un GET (igual se rechaza)'         => ['GET', '/articulos?desde_id=1&_method=PUT', null, 'ruta'],
            'query con la barra baja codificada'         => ['POST', $propuesta . '?%5Fmethod=DELETE', null, 'ruta'],
            'query con la barra baja codificada y mayús' => ['POST', $propuesta . '?%5fMeThOd=DELETE', null, 'ruta'],
            'query con una letra codificada'             => ['POST', $propuesta . '?_met%68od=DELETE', null, 'ruta'],
            'query con punto: PHP lo convierte en _'     => ['POST', $propuesta . '?.method=PATCH', null, 'ruta'],
            'query con un espacio adelante'              => ['POST', $propuesta . '?%20_method=PUT', null, 'ruta'],
            'query con corchetes'                        => ['POST', $propuesta . '?_method[]=PUT', null, 'ruta'],
            'query con el nombre cortado por un nulo'    => ['POST', $propuesta . '?_method%00x=DELETE', null, 'ruta'],
            'cuerpo con _method'                         => ['POST', '/categorias/propuestas', ['_method' => 'DELETE'], 'cuerpo'],
            'cuerpo con _METHOD en mayúsculas'           => ['POST', '/categorias/propuestas', ['_METHOD' => 'PUT'], 'cuerpo'],
            'cuerpo con _Method y otras claves'          => ['POST', '/categorias/propuestas/7/asignaciones', ['asignaciones' => [], '_Method' => 'PATCH'], 'cuerpo'],
        ];
    }

    /**
     * 🔴 `_method` en la query de la `ruta` o en el primer nivel del `cuerpo` es 422 `validacion` (sin
     * importar mayúsculas ni cómo venga escrito) y NO se le pega al cliente.
     *
     * @dataProvider pedidos_con_method_override
     *
     * @param string                    $metodo Método del pedido.
     * @param string                    $ruta   Ruta del pedido.
     * @param array<string, mixed>|null $cuerpo Cuerpo del pedido.
     * @param string                    $campo  Campo del 422 donde se espera el motivo.
     *
     * @return void
     */
    public function test_method_en_la_query_o_en_el_cuerpo_es_422_y_no_llama_al_cliente(string $metodo, string $ruta, $cuerpo, string $campo): void
    {
        Http::fake();

        $pedido = ['metodo' => $metodo, 'ruta' => $ruta];

        if ($cuerpo !== null) {
            $pedido['cuerpo'] = $cuerpo;
        }

        $respuesta = $this->puente($pedido);

        $respuesta->assertStatus(422);
        $this->assertSame('validacion', $respuesta->json('error'));
        $this->assertSame([$campo], array_keys($respuesta->json('detalle')));
        $this->assertStringContainsString('_method', (string) $respuesta->json('detalle.' . $campo));

        Http::assertNothingSent();
        $this->assertSinLaClave($respuesta, $this->clave);
    }

    /**
     * Lo que se parece a `_method` pero no lo es, y el `_method` que está más adentro del cuerpo (donde
     * Laravel no lo lee), NO se rechazan: el 422 es solo para el que de verdad cambiaría el método.
     *
     * @return void
     */
    public function test_lo_que_no_es_method_o_esta_mas_adentro_del_cuerpo_pasa(): void
    {
        $this->el_cliente_contesta(['ok' => true]);

        $this->puente(['metodo' => 'GET', 'ruta' => '/articulos?x_method=1&metodo=GET&method=PUT'])->assertStatus(200);

        $this->puente([
            'metodo' => 'POST',
            'ruta'   => '/categorias/propuestas/7/asignaciones',
            'cuerpo' => ['asignaciones' => [['articulo_id' => 1, '_method' => 'no-es-del-primer-nivel']], 'metodo' => 'DELETE'],
        ])->assertStatus(200);

        Http::assertSentCount(2);
    }

    /* ------------------------------------------------------------------------------------------
     | 4 ter. Bytes que no son UTF-8: 422 (o 404), nunca 500
     |----------------------------------------------------------------------------------------- */

    /**
     * Pedidos con un byte que no es UTF-8 en el `metodo` o en la `ruta`. Un JSON con UTF-8 inválido no
     * se decodifica, así que estos bytes llegan por la query de la URL (`%FF` crudo): es por donde un
     * pedido a mano puede traerlos.
     *
     * Antes, el mensaje de error repetía el texto tal cual, `json_encode` fallaba con un solo byte
     * inválido y Laravel contestaba un 500 en lugar del 422 que se estaba armando.
     *
     * @return array<string, array<int, string>>
     */
    public function pedidos_con_utf8_invalido(): array
    {
        return [
            'metodo con un byte suelto'                   => ['?metodo=%FF&ruta=/resumen', 'metodo'],
            'metodo con una secuencia cortada'            => ['?metodo=GE%C3&ruta=/resumen', 'metodo'],
            'ruta con un byte suelto despues de la barra' => ['?metodo=GET&ruta=/x%FF', 'ruta'],
            'ruta con un byte suelto en la query'         => ['?metodo=GET&ruta=/articulos%3Fq%3D%FF', 'ruta'],
            'ruta que es solo un byte'                    => ['?metodo=GET&ruta=%FF', 'ruta'],
            'metodo y ruta, los dos'                      => ['?metodo=%FE&ruta=%FF', 'metodo'],
        ];
    }

    /**
     * 🔴 Bytes que no son UTF-8 en el `metodo` o en la `ruta` son 422 `validacion` (no 500), y no se le
     * pega al cliente.
     *
     * @dataProvider pedidos_con_utf8_invalido
     *
     * @param string $query Query de la URL del endpoint, con los bytes crudos.
     * @param string $campo Campo del 422 donde se espera el motivo.
     *
     * @return void
     */
    public function test_bytes_que_no_son_utf8_en_metodo_o_ruta_son_422_y_no_500(string $query, string $campo): void
    {
        Http::fake();

        $respuesta = $this->postJson($this->url() . $query, [], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertSame('validacion', $respuesta->json('error'));
        $this->assertArrayHasKey($campo, $respuesta->json('detalle'));

        Http::assertNothingSent();
    }

    /**
     * El NOMBRE de un parámetro de más también se repite en el error (es la clave de `detalle`): con
     * bytes que no son UTF-8 sale saneado, como 422, y no como un 500.
     *
     * @return void
     */
    public function test_un_nombre_de_parametro_con_bytes_invalidos_es_422_y_no_500(): void
    {
        Http::fake();

        $respuesta = $this->postJson($this->url() . '?metodo=GET&ruta=/resumen&%FF%FE=1', [], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertSame('validacion', $respuesta->json('error'));
        $this->assertCount(1, $respuesta->json('detalle'));

        Http::assertNothingSent();
    }

    /**
     * Un `cuerpo` con bytes que no son UTF-8 (solo puede llegar por un formulario: en JSON no se
     * decodifica) es 422 y no se manda. Si pasara, `json_encode` fallaría y al cliente le llegaría un
     * `{}` en lugar de lo que el motor mandó, sin ningún aviso.
     *
     * @return void
     */
    public function test_un_cuerpo_con_bytes_invalidos_es_422_y_no_se_manda(): void
    {
        Http::fake();

        $respuesta = $this->post($this->url(), [
            'metodo' => 'POST',
            'ruta'   => '/categorias/propuestas',
            'cuerpo' => ['nombre' => "Fijaciones \xFF"],
        ], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertSame('validacion', $respuesta->json('error'));
        $this->assertArrayHasKey('cuerpo', $respuesta->json('detalle'));

        Http::assertNothingSent();
    }

    /**
     * El `{id}` de la URL que no es un número ni un uuid no puede ser un cliente: 404
     * `cliente_inexistente` (sin consultar la base) y sin llamar a nadie. Con bytes que no son UTF-8
     * (`%FF`) el ruteador de Laravel ni siquiera matchea la ruta (sus patrones son UTF-8) y contesta su
     * 404 de siempre, sin `error`: lo que importa es que no sea un 500.
     *
     * @return void
     */
    public function test_un_id_que_no_es_numero_ni_uuid_es_404_y_no_500(): void
    {
        Http::fake();

        foreach (['abc', 'no-es-un-uuid', '%C3%B1and%C3%BA', '123e4567-e89b-12d3-a456-42661417400g'] as $id) {
            $respuesta = $this->postJson('/api/claude/clients/' . $id . '/catalogo/puente', ['metodo' => 'GET', 'ruta' => '/resumen'], $this->headers());

            $respuesta->assertStatus(404);
            $this->assertSame('cliente_inexistente', $respuesta->json('error'), $id);
        }

        $con_bytes_invalidos = $this->postJson('/api/claude/clients/%FF%FE/catalogo/puente', ['metodo' => 'GET', 'ruta' => '/resumen'], $this->headers());

        $con_bytes_invalidos->assertStatus(404);

        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------------------------------
     | 5. Lo que contesta el cliente viaja adentro
     |----------------------------------------------------------------------------------------- */

    /**
     * Los códigos con los que el cliente puede contestar.
     *
     * @return array<string, array<int, mixed>>
     */
    public function codigos_del_cliente(): array
    {
        return [
            '401 clave rechazada'          => [401, ['message' => 'Unauthenticated.']],
            '403 prohibido'                => [403, ['message' => 'Forbidden']],
            '404 versión vieja'            => [404, ['message' => 'The route api/admin-sync/catalogo/resumen could not be found.']],
            '409 conflicto'                => [409, ['error' => 'ya_hay_una_propuesta_activa']],
            '422 validación del cliente'   => [422, ['message' => 'The given data was invalid.', 'errors' => ['nombre' => ['Es obligatorio.']]]],
            '500 error del cliente'        => [500, ['message' => 'Server Error']],
            '503 en mantenimiento'         => [503, ['message' => 'Service Unavailable']],
        ];
    }

    /**
     * 🔴 Un 401/403/404/409/422/500/503 del CLIENTE vuelve como 200 `puente: true` con su `status` y su
     * `cuerpo`: si el puente devolviera su código, un 404 del cliente se confundiría con uno del admin.
     *
     * @dataProvider codigos_del_cliente
     *
     * @param int                  $status HTTP del cliente.
     * @param array<string, mixed> $cuerpo Cuerpo del cliente.
     *
     * @return void
     */
    public function test_un_codigo_del_cliente_vuelve_como_200_con_su_status_y_su_cuerpo(int $status, array $cuerpo): void
    {
        $this->el_cliente_contesta($cuerpo, $status);

        $respuesta = $this->puente(['metodo' => 'GET', 'ruta' => '/resumen']);

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('puente'));
        $this->assertSame($status, $respuesta->json('status'));
        $this->assertSame($cuerpo, $respuesta->json('cuerpo'));
        $this->assertNull($respuesta->json('cuerpo_crudo'));

        Http::assertSentCount(1);
        $this->assertSinLaClave($respuesta, $this->clave);
    }

    /**
     * Un cuerpo que NO es JSON (la página genérica del hosting, con 200) vuelve como `cuerpo: null` y
     * `cuerpo_crudo` con el texto, cortado a 2000 caracteres.
     *
     * @return void
     */
    public function test_un_cuerpo_que_no_es_json_vuelve_en_cuerpo_crudo_cortado_a_2000(): void
    {
        $html = '<html><body>' . str_repeat('Cuenta saturada. ', 400) . '</body></html>';

        $this->el_cliente_contesta($html, 200);

        $respuesta = $this->puente(['metodo' => 'GET', 'ruta' => '/resumen']);

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('puente'));
        $this->assertSame(200, $respuesta->json('status'));
        $this->assertNull($respuesta->json('cuerpo'));
        $this->assertSame(mb_substr($html, 0, ClientCatalogoPuenteService::MAX_CRUDO), $respuesta->json('cuerpo_crudo'));
        $this->assertSame(2000, mb_strlen($respuesta->json('cuerpo_crudo')));
    }

    /**
     * Un cuerpo vacío es "no era JSON": `cuerpo` null y `cuerpo_crudo` vacío (no null).
     *
     * @return void
     */
    public function test_un_cuerpo_vacio_vuelve_con_cuerpo_crudo_vacio(): void
    {
        $this->el_cliente_contesta('', 204);

        $respuesta = $this->puente(['metodo' => 'POST', 'ruta' => '/categorias/propuestas/7/listo']);

        $respuesta->assertStatus(200);
        $this->assertSame(204, $respuesta->json('status'));
        $this->assertNull($respuesta->json('cuerpo'));
        $this->assertSame('', $respuesta->json('cuerpo_crudo'));
    }

    /**
     * La respuesta del cliente se decodifica como objetos: un `{}` vuelve como `{}`, un `[]` como `[]`,
     * un objeto con claves numéricas conserva sus claves y un número grande no pierde precisión.
     *
     * @return void
     */
    public function test_la_respuesta_del_cliente_conserva_objetos_listas_y_numeros_grandes(): void
    {
        $this->el_cliente_contesta('{"vacio":{},"lista_vacia":[],"por_articulo":{"12":"Fijaciones","13":"Pinturas"},"grande":12345678901234567890}', 200);

        $respuesta = $this->puente(['metodo' => 'GET', 'ruta' => '/resumen']);

        $respuesta->assertStatus(200);

        $contenido = (string) $respuesta->getContent();

        $this->assertStringContainsString('"vacio":{}', $contenido);
        $this->assertStringContainsString('"lista_vacia":[]', $contenido);
        $this->assertStringContainsString('"por_articulo":{"12":"Fijaciones","13":"Pinturas"}', $contenido);
        $this->assertStringContainsString('"grande":"12345678901234567890"', $contenido);
    }

    /**
     * Un cuerpo JSON que es un valor suelto (un texto, un número, `null`) también es JSON.
     *
     * @return void
     */
    public function test_un_json_suelto_es_json(): void
    {
        $this->el_cliente_contesta('"listo"', 200);

        $respuesta = $this->puente(['metodo' => 'GET', 'ruta' => '/resumen']);

        $this->assertSame('listo', $respuesta->json('cuerpo'));
        $this->assertNull($respuesta->json('cuerpo_crudo'));
    }

    /**
     * No sigue redirecciones: un 302 vuelve con su `status` y el puente NO le pega al destino.
     *
     * @return void
     */
    public function test_no_sigue_redirecciones(): void
    {
        Http::fake([
            'api-doblep.ejemplo.test/*' => Http::response('', 302, ['Location' => 'https://otro.ejemplo.test/robar']),
            'otro.ejemplo.test/*'       => Http::response(['visto' => true], 200),
        ]);

        $respuesta = $this->puente(['metodo' => 'GET', 'ruta' => '/resumen']);

        $respuesta->assertStatus(200);
        $this->assertSame(302, $respuesta->json('status'));

        Http::assertSentCount(1);
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), 'otro.ejemplo.test') !== false;
        });
    }

    /* ------------------------------------------------------------------------------------------
     | 6. Cuando el admin no llega al cliente
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Un timeout es 502 `cliente_no_responde`, sin reintentar, y el mensaje de la excepción —aunque
     * traiga la clave adentro— sale con la clave tapada.
     *
     * @return void
     */
    public function test_un_timeout_es_502_sin_reintentar_y_sin_filtrar_la_clave(): void
    {
        $clave    = $this->clave;
        $intentos = 0;

        $this->http_falso(['*' => function () use ($clave, &$intentos) {
            $intentos++;

            throw new ConnectionException('cURL error 28: Operation timed out after 60001 milliseconds (X-Admin-Api-Key: ' . $clave . ')');
        }]);

        $registro = $this->capturar_el_log();

        $respuesta = $this->puente(['metodo' => 'POST', 'ruta' => '/categorias/propuestas/7/asignaciones', 'cuerpo' => ['asignaciones' => []]]);

        $respuesta->assertStatus(502);
        $this->assertSame('cliente_no_responde', $respuesta->json('error'));
        $this->assertStringContainsString('timed out', (string) $respuesta->json('mensaje'));
        $this->assertStringContainsString('[clave oculta]', (string) $respuesta->json('mensaje'));

        $this->assertSame(1, $intentos, 'Un timeout NO se reintenta: un POST del catálogo no es idempotente.');

        $this->assertSinLaClave($respuesta, $this->clave);
        $this->assertLogSinLaClave($registro, $this->clave);
    }

    /**
     * Una falla de conexión (DNS, conexión rechazada) también es 502 `cliente_no_responde`.
     *
     * @return void
     */
    public function test_una_falla_de_conexion_es_502(): void
    {
        $this->http_falso(['*' => function () {
            throw new ConnectionException('cURL error 6: Could not resolve host: api-doblep.ejemplo.test');
        }]);

        $respuesta = $this->puente(['metodo' => 'GET', 'ruta' => '/resumen']);

        $respuesta->assertStatus(502);
        $this->assertSame('cliente_no_responde', $respuesta->json('error'));
        $this->assertStringContainsString('resolve host', (string) $respuesta->json('mensaje'));
    }

    /**
     * Sin `api_key` en el admin es 409 `sin_api_key`, ANTES de llamar a nadie. Una clave de solo
     * espacios cuenta como vacía.
     *
     * @return void
     */
    public function test_sin_api_key_es_409_y_no_llama_al_cliente(): void
    {
        Http::fake();

        foreach (['', '   '] as $vacia) {
            $cliente = $this->crear_cliente('Cliente sin clave', $vacia);
            $this->crear_frente($cliente, 'sinclave' . strlen($vacia), 'shared_hosting', null, true);

            $respuesta = $this->puente(['metodo' => 'GET', 'ruta' => '/resumen'], $cliente);

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

        $respuesta = $this->puente(['metodo' => 'GET', 'ruta' => '/resumen'], $cliente);

        $respuesta->assertStatus(409);
        $this->assertSame('sin_url', $respuesta->json('error'));

        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------------------------------
     | 7. La clave no sale por ningún lado
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Si el cuerpo que devuelve el cliente REPITE la clave (una página de error con los headers
     * volcados, un 401 que cita lo que recibió), el puente la tapa: en el JSON decodificado y en el
     * texto crudo.
     *
     * @return void
     */
    public function test_si_el_cliente_repite_la_clave_el_puente_la_tapa(): void
    {
        $clave = $this->clave;

        /* En un JSON, anidada. */
        $this->el_cliente_contesta(['message' => 'Unauthenticated', 'debug' => ['headers' => ['x-admin-api-key' => $clave]]], 401);

        $json = $this->puente(['metodo' => 'GET', 'ruta' => '/resumen']);

        $json->assertStatus(200);
        $this->assertSame('[clave oculta]', $json->json('cuerpo.debug.headers.x-admin-api-key'));
        $this->assertSinLaClave($json, $clave, 'JSON.');

        /* En un texto que no es JSON. */
        $this->el_cliente_contesta('<pre>X-Admin-Api-Key: ' . $clave . '</pre>', 500);

        $texto = $this->puente(['metodo' => 'GET', 'ruta' => '/resumen']);

        $texto->assertStatus(200);
        $this->assertNull($texto->json('cuerpo'));
        $this->assertStringContainsString('[clave oculta]', (string) $texto->json('cuerpo_crudo'));
        $this->assertSinLaClave($texto, $clave, 'Texto.');
    }

    /**
     * Una clave con caracteres que JSON escapa (`/`, `"`, `\`) también se tapa cuando el cliente la
     * repite dentro de un JSON.
     *
     * @return void
     */
    public function test_una_clave_con_caracteres_especiales_tambien_se_tapa(): void
    {
        $clave   = 'sk/con"barras\\y-comillas-' . Str::random(20);
        $cliente = $this->crear_cliente('Cliente de clave rara', $clave);
        $this->crear_frente($cliente, 'clarar', 'shared_hosting', null, true);

        $this->el_cliente_contesta(['eco' => $clave], 401);

        $respuesta = $this->puente(['metodo' => 'GET', 'ruta' => '/resumen'], $cliente);

        $respuesta->assertStatus(200);
        $this->assertSame('[clave oculta]', $respuesta->json('cuerpo.eco'));
        $this->assertSinLaClave($respuesta, $clave);
        $this->assertStringNotContainsString(json_encode($clave), (string) $respuesta->getContent());
    }

    /**
     * 🔴 El log de cada llamada lleva método, ruta SIN query, status y milisegundos: nunca el cuerpo
     * ni la clave. Una llamada fallida lleva `status: null`.
     *
     * @return void
     */
    public function test_el_log_lleva_metodo_ruta_sin_query_status_y_ms_y_nunca_el_cuerpo_ni_la_clave(): void
    {
        $registro = $this->capturar_el_log();

        $this->el_cliente_contesta(['secreto_del_cuerpo' => 'no-debe-estar-en-el-log', 'eco' => $this->clave], 200);

        $this->puente(['metodo' => 'GET', 'ruta' => '/articulos?desde_id=100&filtro=lo-que-sea'])->assertStatus(200);

        $propias = array_values(array_filter($registro->registros, function ($r) {
            return strpos($r['mensaje'], 'ClientCatalogoPuenteService') === 0;
        }));

        $this->assertCount(1, $propias);
        $this->assertSame('info', $propias[0]['nivel']);
        $this->assertSame('GET', $propias[0]['contexto']['metodo']);
        $this->assertSame('/articulos', $propias[0]['contexto']['ruta'], 'La ruta del log va SIN query.');
        $this->assertSame(200, $propias[0]['contexto']['status']);
        $this->assertIsInt($propias[0]['contexto']['ms']);
        $this->assertGreaterThanOrEqual(0, $propias[0]['contexto']['ms']);

        $todo = implode("\n", $registro->lineas);
        $this->assertStringNotContainsString('no-debe-estar-en-el-log', $todo, 'El log nunca lleva el cuerpo.');
        $this->assertStringNotContainsString('desde_id', $todo, 'El log nunca lleva la query.');
        $this->assertLogSinLaClave($registro, $this->clave);

        /* Una llamada que falla: status null. */
        $this->http_falso(['*' => function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        }]);

        $this->puente(['metodo' => 'POST', 'ruta' => '/categorias/propuestas'])->assertStatus(502);

        $fallidas = array_values(array_filter($registro->registros, function ($r) {
            return strpos($r['mensaje'], 'ClientCatalogoPuenteService') === 0 && $r['nivel'] === 'warning';
        }));

        $this->assertCount(1, $fallidas);
        $this->assertNull($fallidas[0]['contexto']['status']);
        $this->assertSame('POST', $fallidas[0]['contexto']['metodo']);
        $this->assertSame('/categorias/propuestas', $fallidas[0]['contexto']['ruta']);
        $this->assertLogSinLaClave($registro, $this->clave);
    }

    /* ------------------------------------------------------------------------------------------
     | 8. El catálogo dice lo mismo que el controlador
     |----------------------------------------------------------------------------------------- */

    /**
     * Los parámetros que `config/claude_catalog.php` declara para C2 son EXACTAMENTE la lista cerrada
     * del controlador, y el catálogo nombra las nueve rutas de la lista blanca del servicio.
     *
     * @return void
     */
    public function test_el_catalogo_declara_los_mismos_parametros_y_las_mismas_rutas(): void
    {
        $endpoint = config('claude_catalog.endpoints.POST api/claude/clients/{id}/catalogo/puente');

        $this->assertIsArray($endpoint, 'El catálogo no tiene la entrada de C2.');
        $this->assertTrue($endpoint['escribe']);

        $declarados = [];
        foreach ($endpoint['parametros'] as $parametro) {
            if (strpos($parametro['nombre'], '(en la ruta)') === false) {
                $declarados[] = $parametro['nombre'];
            }
        }

        $aceptados = ClaudeClientCatalogoController::PARAMETROS_DEL_PUENTE;

        sort($declarados);
        sort($aceptados);

        $this->assertSame($aceptados, $declarados);

        $this->assertCount(9, ClientCatalogoPuenteService::rutas_permitidas());

        foreach (ClientCatalogoPuenteService::rutas_permitidas() as $ruta) {
            $this->assertStringContainsString($ruta, $endpoint['para_que'], 'El catálogo no nombra la ruta de la lista blanca: ' . $ruta);
        }
    }
}
