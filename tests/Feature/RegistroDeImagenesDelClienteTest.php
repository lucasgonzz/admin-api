<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Services\ClientImageSearchLogService;
use Carbon\Carbon;
use Illuminate\Http\Client\Request as PedidoSaliente;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\AsistenteWhatsapp\BaseDelCanal;

/**
 * El registro de consultas de imágenes de un cliente, visto desde el admin (misión
 * imagenes-catalogo-completo, §12.2): cómo sale la llamada al `empresa-api` del cliente y qué
 * devuelve el admin en cada desenlace.
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 **Que un cliente con una versión vieja se vea como `no_soportado` y no como un error.** Es
 *     el caso MAYORITARIO durante semanas después del deploy: cuarenta y cinco clientes y casi
 *     ninguno actualizado. Si el 404 cayera en `error`, la solapa se llenaría de rojos que no hay
 *     que arreglar; si se reintentara, cada apertura de la solapa pegaría dos veces para enterarse
 *     de lo mismo.
 *  2. **Que cada fallo nombre su causa** (api_key, USER_ID, timeout, página del hosting) y que
 *     ninguno lance: la solapa tiene que poder decir qué pasó.
 *  3. **Que los filtros viajen tal cual los pide el contrato, y nada más.** El `empresa-api` de cada
 *     cliente está en su propia versión: el admin le manda solo lo que el contrato nombra.
 *  4. **Que la `api_key` no aparezca nunca en un mensaje.**
 *
 * Los payloads se escriben a mano con las claves EXACTAS del contrato del plan (§12.1) y no se
 * derivan de ninguna constante del admin: si alguien renombra una clave de este lado, estos tests
 * se tienen que poner en rojo.
 *
 * Hereda de `BaseDelCanal` por su `fakear_http()`, que hace `Http::swap()` antes del `fake` (ver su
 * docblock: `Http::fake()` acumula y gana el primero que matchea) y deja un comodín para que nada
 * salga a la red de verdad.
 */
class RegistroDeImagenesDelClienteTest extends BaseDelCanal
{
    /**
     * Patrón de la URL del resumen en el `empresa-api` del cliente.
     */
    const PATRON_RESUMEN = '*/api/admin-sync/imagenes/resumen*';

    /**
     * Patrón de la URL del registro paginado.
     */
    const PATRON_CONSULTAS = '*/api/admin-sync/imagenes/consultas*';

    /**
     * El reloj vuelve a la realidad después de cada prueba que lo congela.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Admin logueado por Sanctum: las rutas viven bajo auth:sanctum.
     *
     * @return Admin
     */
    private function admin_logueado(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de imágenes';
        $admin->email    = 'imagenes-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Cliente listo para que le pidan el registro: con ClientApi activa (shared hosting) y api_key.
     *
     * @return Client
     */
    private function cliente_consultable(): Client
    {
        $client = $this->crear_cliente();
        $this->crear_client_api($client, 'https://api-ferreteria.test', 'shared_hosting');

        return $client->fresh();
    }

    /**
     * Respuesta del resumen del cliente, con las claves exactas del contrato.
     *
     * @return array<string, mixed>
     */
    private function payload_de_resumen(): array
    {
        return [
            'desde'   => '2026-09-01',
            'hasta'   => '2026-09-27',
            'totales' => [
                'busquedas'                => 30,
                'busquedas_cobradas'       => 30,
                'busquedas_por_proveedor'  => ['serper' => 30, 'google' => 0],
                'validaciones_ia'          => 20,
                'validaciones_ia_cobradas' => 20,
                'errores'                  => 0,
                'tokens_entrada'           => 60000,
                'tokens_salida'            => 3000,
                'tokens_cache_escritura'   => 0,
                'tokens_cache_lectura'     => 0,
            ],
            'dias' => [
                [
                    'fecha'                    => '2026-09-27',
                    'busquedas'                => 30,
                    'busquedas_cobradas'       => 30,
                    'busquedas_serper'         => 30,
                    'busquedas_google'         => 0,
                    'validaciones_ia'          => 20,
                    'validaciones_ia_cobradas' => 20,
                    'errores'                  => 0,
                ],
            ],
            'modelos' => [
                [
                    'modelo'                 => 'claude-haiku-4-5-20251001',
                    'llamadas'               => 20,
                    'tokens_entrada'         => 60000,
                    'tokens_salida'          => 3000,
                    'tokens_cache_escritura' => 0,
                    'tokens_cache_lectura'   => 0,
                ],
            ],
            'asignaciones' => [
                [
                    'id'              => 12,
                    'uuid'            => 'b0b7c3d4-0000-4000-8000-000000000012',
                    'created_at'      => '2026-09-27T12:00:00.000000Z',
                    'origen'          => 'catalogo',
                    'status'          => 'terminada',
                    'proveedor'       => 'serper',
                    'total_articulos' => 20,
                    'asignadas'       => 15,
                    'a_revisar'       => 3,
                    'no_asignadas'    => 2,
                    'busquedas'       => 30,
                    'validaciones_ia' => 20,
                ],
            ],
        ];
    }

    /**
     * Una fila del registro paginado, con las claves exactas del contrato.
     *
     * @param array<string, mixed> $pisar Claves a cambiar sobre la fila base (una búsqueda de Serper cobrada).
     *
     * @return array<string, mixed>
     */
    private function consulta(array $pisar = []): array
    {
        return array_merge([
            'id'                     => 1,
            'created_at'             => '2026-09-27T15:00:00.000000Z',
            'tipo'                   => 'busqueda',
            'origen'                 => 'asignacion',
            'proveedor'              => 'serper',
            'modelo'                 => null,
            'criterio'               => 'codigo_de_barras',
            'consulta'               => '7791234567898',
            'article_id'             => 123,
            'article_name'           => 'Martillo carpintero 16 oz',
            'run_id'                 => 12,
            'ok'                     => true,
            'cobrada'                => true,
            'http_status'            => 200,
            'error'                  => null,
            'resultados'             => 10,
            'candidatas'             => null,
            'resumen'                => '10 resultados',
            'tokens_entrada'         => null,
            'tokens_salida'          => null,
            'tokens_cache_escritura' => null,
            'tokens_cache_lectura'   => null,
            'duracion_ms'            => 850,
        ], $pisar);
    }

    /**
     * Una página del registro tal como la arma el paginador de Laravel del lado del cliente.
     *
     * @param array<int, array<string, mixed>> $filas Filas de la página.
     *
     * @return array<string, mixed>
     */
    private function pagina_de_consultas(array $filas): array
    {
        $base = 'https://api-ferreteria.test/public/api/admin-sync/imagenes/consultas';

        return [
            'models' => [
                'current_page'   => 2,
                'data'           => $filas,
                'first_page_url' => $base . '?page=1',
                'from'           => 26,
                'last_page'      => 4,
                'last_page_url'  => $base . '?page=4',
                'links'          => [['url' => $base . '?page=1', 'label' => '1', 'active' => false]],
                'next_page_url'  => $base . '?page=3',
                'path'           => $base,
                'per_page'       => 25,
                'prev_page_url'  => $base . '?page=1',
                'to'             => 25 + count($filas),
                'total'          => 90,
            ],
        ];
    }

    /**
     * La query de un pedido saliente, ya parseada.
     *
     * @param PedidoSaliente $pedido Pedido registrado por el fake.
     *
     * @return array<string, string>
     */
    private function query_de(PedidoSaliente $pedido): array
    {
        $query = [];
        parse_str((string) parse_url($pedido->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * El camino feliz del resumen: sale a la URL del cliente (con `/public` por ser shared hosting),
     * con la clave en el header y el rango en la query, y vuelve `ok` con los cuatro bloques.
     *
     * @return void
     */
    public function test_el_resumen_viaja_con_la_clave_y_el_rango_y_vuelve_ok(): void
    {
        $client = $this->cliente_consultable();

        $this->fakear_http([
            self::PATRON_RESUMEN => Http::response($this->payload_de_resumen(), 200),
        ]);

        $resultado = app(ClientImageSearchLogService::class)->resumen($client, '2026-09-01', '2026-09-27');

        $this->assertSame(ClientImageSearchLogService::ESTADO_OK, $resultado['estado']);
        $this->assertNull($resultado['mensaje']);
        $this->assertIsArray($resultado['datos']);

        foreach (['totales', 'dias', 'modelos', 'asignaciones'] as $bloque) {
            $this->assertArrayHasKey($bloque, $resultado['datos'], 'Falta el bloque ' . $bloque . ' en los datos.');
        }

        // Las asignaciones viajan tal cual: el admin no las toca.
        $this->assertSame(12, $resultado['datos']['asignaciones'][0]['id']);
        $this->assertSame(15, $resultado['datos']['asignaciones'][0]['asignadas']);

        Http::assertSentCount(1);

        Http::assertSent(function (PedidoSaliente $pedido) {
            $query = $this->query_de($pedido);

            return strpos($pedido->url(), 'https://api-ferreteria.test/public/api/admin-sync/imagenes/resumen') === 0
                && $pedido->method() === 'GET'
                && $pedido->hasHeader('X-Admin-Api-Key', 'clave-del-cliente')
                && ($query['desde'] ?? null) === '2026-09-01'
                && ($query['hasta'] ?? null) === '2026-09-27';
        });
    }

    /**
     * 404: el cliente todavía no tiene la versión que registra las consultas.
     *
     * 🔴 Es el caso ESPERADO, no un fallo: estado propio, sin datos, con un mensaje que no suena a
     * falla y SIN reintentar (una sola llamada, aunque la config permita dos). Vale para los dos
     * endpoints.
     *
     * @return void
     */
    public function test_un_cliente_sin_el_endpoint_queda_no_soportado_sin_reintentar(): void
    {
        config(['services.client_api.retries' => 2]);

        $client   = $this->cliente_consultable();
        $servicio = app(ClientImageSearchLogService::class);

        $this->fakear_http([
            '*' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $resumen = $servicio->resumen($client, '2026-09-01', '2026-09-27');

        $this->assertSame(ClientImageSearchLogService::ESTADO_NO_SOPORTADO, $resumen['estado']);
        $this->assertNull($resumen['datos']);
        $this->assertStringContainsString('actualice', (string) $resumen['mensaje']);
        Http::assertSentCount(1);

        $consultas = $servicio->consultas($client, $servicio->filtros_de_consultas([
            'desde' => '2026-09-01',
            'hasta' => '2026-09-27',
        ]));

        $this->assertSame(ClientImageSearchLogService::ESTADO_NO_SOPORTADO, $consultas['estado']);
        $this->assertNull($consultas['datos']);
        Http::assertSentCount(2);
    }

    /**
     * 401: la api_key del admin no coincide con la del cliente. Es un error, se dice con la causa
     * y no se reintenta (insistir da lo mismo).
     *
     * @return void
     */
    public function test_un_401_es_error_nombra_la_api_key_y_no_se_reintenta(): void
    {
        config(['services.client_api.retries' => 2]);

        $client = $this->cliente_consultable();

        $this->fakear_http([
            self::PATRON_RESUMEN => Http::response(['message' => 'Clave inválida.'], 401),
        ]);

        $resultado = app(ClientImageSearchLogService::class)->resumen($client, '2026-09-01', '2026-09-27');

        $this->assertSame(ClientImageSearchLogService::ESTADO_ERROR, $resultado['estado']);
        $this->assertNull($resultado['datos']);
        $this->assertStringContainsString('401', (string) $resultado['mensaje']);
        $this->assertStringContainsString('api_key', (string) $resultado['mensaje']);
        Http::assertSentCount(1);
    }

    /**
     * 409: la instancia vive en una base compartida sin `USER_ID` en su `.env`. El mensaje tiene
     * que nombrar la variable y dónde se carga, aunque el cuerpo del cliente no lo diga.
     *
     * @return void
     */
    public function test_un_409_es_error_y_nombra_el_user_id_que_falta(): void
    {
        $client = $this->cliente_consultable();

        $this->fakear_http([
            self::PATRON_RESUMEN => Http::response(['message' => 'Conflict'], 409),
        ]);

        $resultado = app(ClientImageSearchLogService::class)->resumen($client, '2026-09-01', '2026-09-27');

        $this->assertSame(ClientImageSearchLogService::ESTADO_ERROR, $resultado['estado']);
        $this->assertStringContainsString('USER_ID', (string) $resultado['mensaje']);
        $this->assertStringContainsString('.env', (string) $resultado['mensaje']);
        $this->assertStringContainsString('409', (string) $resultado['mensaje']);
    }

    /**
     * 500: puede ser pasajero, así que SÍ se reintenta (dos llamadas con la config en 2), y si
     * sigue igual queda en error con el código.
     *
     * @return void
     */
    public function test_un_500_es_error_y_se_reintenta_una_vez(): void
    {
        config(['services.client_api.retries' => 2]);

        $client = $this->cliente_consultable();

        $this->fakear_http([
            self::PATRON_RESUMEN => Http::response('Internal Server Error', 500),
        ]);

        $resultado = app(ClientImageSearchLogService::class)->resumen($client, '2026-09-01', '2026-09-27');

        $this->assertSame(ClientImageSearchLogService::ESTADO_ERROR, $resultado['estado']);
        $this->assertStringContainsString('500', (string) $resultado['mensaje']);
        Http::assertSentCount(2);
    }

    /**
     * Un timeout no tiene respuesta HTTP: es el único camino donde no hay código que leer, y tiene
     * que terminar en error con el motivo, sin excepción.
     *
     * @return void
     */
    public function test_un_timeout_es_error_con_el_motivo_y_sin_excepcion(): void
    {
        $client = $this->cliente_consultable();

        $this->fakear_http([
            self::PATRON_CONSULTAS => function () {
                throw new \Illuminate\Http\Client\ConnectionException(
                    'cURL error 28: Operation timed out after 15000 milliseconds'
                );
            },
        ]);

        $servicio  = app(ClientImageSearchLogService::class);
        $resultado = $servicio->consultas($client, $servicio->filtros_de_consultas([]));

        $this->assertSame(ClientImageSearchLogService::ESTADO_ERROR, $resultado['estado']);
        $this->assertNull($resultado['datos']);
        $this->assertStringContainsString('timed out', (string) $resultado['mensaje']);
    }

    /**
     * Un 200 que no es nuestro payload (la página genérica del hosting saturado) es un error, no
     * "un cliente sin consultas". Vale para los dos endpoints.
     *
     * @return void
     */
    public function test_un_200_que_no_es_el_payload_es_error_y_no_cero_consultas(): void
    {
        $client   = $this->cliente_consultable();
        $servicio = app(ClientImageSearchLogService::class);

        $this->fakear_http([
            self::PATRON_RESUMEN   => Http::response('<html><body>Account suspended</body></html>', 200),
            self::PATRON_CONSULTAS => Http::response(['otra' => 'cosa'], 200),
        ]);

        $resumen = $servicio->resumen($client, '2026-09-01', '2026-09-27');

        $this->assertSame(ClientImageSearchLogService::ESTADO_ERROR, $resumen['estado']);
        $this->assertNull($resumen['datos']);
        $this->assertStringContainsString('Account suspended', (string) $resumen['mensaje']);

        $consultas = $servicio->consultas($client, $servicio->filtros_de_consultas([]));

        $this->assertSame(ClientImageSearchLogService::ESTADO_ERROR, $consultas['estado']);
        $this->assertNull($consultas['datos']);
    }

    /**
     * Sin URL resoluble no hay a quién preguntarle: es error de configuración del admin, y no sale
     * ningún pedido a la red.
     *
     * @return void
     */
    public function test_un_cliente_sin_url_valida_es_error_sin_salir_a_la_red(): void
    {
        $client          = $this->crear_cliente();
        $client->api_url = null;
        $client->save();

        $this->fakear_http();

        $resultado = app(ClientImageSearchLogService::class)->resumen($client->fresh(), '2026-09-01', '2026-09-27');

        $this->assertSame(ClientImageSearchLogService::ESTADO_ERROR, $resultado['estado']);
        $this->assertStringContainsString('URL', (string) $resultado['mensaje']);
        Http::assertNothingSent();
    }

    /**
     * Sin api_key tampoco: el cliente la rechazaría con un 401, así que ni se intenta.
     *
     * @return void
     */
    public function test_un_cliente_sin_api_key_es_error_sin_salir_a_la_red(): void
    {
        $client = $this->crear_cliente('+5493411234567', true, '');
        $this->crear_client_api($client, 'https://api-ferreteria.test', 'shared_hosting');

        $this->fakear_http();

        $resultado = app(ClientImageSearchLogService::class)->resumen($client->fresh(), '2026-09-01', '2026-09-27');

        $this->assertSame(ClientImageSearchLogService::ESTADO_ERROR, $resultado['estado']);
        $this->assertStringContainsString('api_key', (string) $resultado['mensaje']);
        Http::assertNothingSent();
    }

    /**
     * 🔴 La clave del cliente no aparece NUNCA en un mensaje, aunque el cliente la devuelva en el
     * cuerpo de un error: se tapa antes de salir.
     *
     * @return void
     */
    public function test_la_clave_del_cliente_nunca_aparece_en_el_mensaje(): void
    {
        config(['services.client_api.retries' => 1]);

        $client = $this->cliente_consultable();

        $this->fakear_http([
            self::PATRON_RESUMEN => Http::response(
                'Error: la clave clave-del-cliente no pudo validarse contra la base.',
                500
            ),
        ]);

        $resultado = app(ClientImageSearchLogService::class)->resumen($client, '2026-09-01', '2026-09-27');

        $this->assertSame(ClientImageSearchLogService::ESTADO_ERROR, $resultado['estado']);
        $this->assertStringNotContainsString('clave-del-cliente', (string) $resultado['mensaje']);
        $this->assertStringContainsString('[clave oculta]', (string) $resultado['mensaje']);
    }

    /**
     * Los filtros y la paginación del registro viajan en la query EXACTAMENTE como los nombra el
     * contrato, y lo que el contrato no nombra no viaja. Se prueba de punta a punta, por la ruta del
     * admin, y de paso que la respuesta del admin tiene la forma `{estado, mensaje, datos}` y que el
     * paginador vuelve sin las URLs del cliente.
     *
     * @return void
     */
    public function test_los_filtros_y_la_paginacion_viajan_en_la_query_y_nada_mas(): void
    {
        $this->admin_logueado();

        $client = $this->cliente_consultable();

        $this->fakear_http([
            self::PATRON_CONSULTAS => Http::response($this->pagina_de_consultas([
                $this->consulta(['id' => 31]),
            ]), 200),
        ]);

        $response = $this->getJson(
            '/api/admin/client/' . $client->id . '/imagenes/consultas'
            . '?desde=2026-09-01&hasta=2026-09-27&tipo=validacion_ia&asignacion=12&solo_errores=1'
            . '&page=3&per_page=25&cualquiera=x'
        );

        $response->assertStatus(200);
        $response->assertJsonPath('estado', ClientImageSearchLogService::ESTADO_OK);
        $response->assertJsonPath('mensaje', null);
        $response->assertJsonPath('datos.models.data.0.id', 31);
        $response->assertJsonPath('datos.models.current_page', 2);
        $response->assertJsonPath('datos.models.last_page', 4);
        $response->assertJsonPath('datos.models.total', 90);
        $response->assertJsonPath('datos.models.per_page', 25);

        $paginador = $response->json('datos.models');

        foreach (['first_page_url', 'last_page_url', 'next_page_url', 'prev_page_url', 'path', 'links'] as $clave) {
            $this->assertArrayNotHasKey(
                $clave,
                $paginador,
                'El paginador no puede devolver ' . $clave . ': es una URL del empresa-api del cliente.'
            );
        }

        Http::assertSentCount(1);

        Http::assertSent(function (PedidoSaliente $pedido) {
            $query = $this->query_de($pedido);
            ksort($query);

            return strpos($pedido->url(), 'https://api-ferreteria.test/public/api/admin-sync/imagenes/consultas') === 0
                && $pedido->hasHeader('X-Admin-Api-Key', 'clave-del-cliente')
                && $query === [
                    'asignacion'   => '12',
                    'desde'        => '2026-09-01',
                    'hasta'        => '2026-09-27',
                    'page'         => '3',
                    'per_page'     => '25',
                    'solo_errores' => '1',
                    'tipo'         => 'validacion_ia',
                ];
        });
    }

    /**
     * `per_page` se acota a 10–200 (no se rechaza), y lo que no vino toma el default del contrato:
     * página 1, 50 por página, `solo_errores` en 0, y ni `tipo` ni `asignacion` (vacío es "todos").
     *
     * @return void
     */
    public function test_per_page_se_acota_y_lo_que_no_vino_toma_el_default(): void
    {
        $servicio = app(ClientImageSearchLogService::class);

        $mucho = $servicio->filtros_de_consultas(['desde' => '2026-09-01', 'hasta' => '2026-09-27', 'per_page' => '500']);
        $this->assertSame(200, $mucho['per_page']);

        $poco = $servicio->filtros_de_consultas(['desde' => '2026-09-01', 'hasta' => '2026-09-27', 'per_page' => '3']);
        $this->assertSame(10, $poco['per_page']);

        $nada = $servicio->filtros_de_consultas(['desde' => '2026-09-01', 'hasta' => '2026-09-27']);
        $this->assertSame(50, $nada['per_page']);
        $this->assertSame(1, $nada['page']);
        $this->assertSame(0, $nada['solo_errores']);
        $this->assertArrayNotHasKey('tipo', $nada);
        $this->assertArrayNotHasKey('asignacion', $nada);

        $vacios = $servicio->filtros_de_consultas([
            'desde'      => '2026-09-01',
            'hasta'      => '2026-09-27',
            'tipo'       => null,
            'asignacion' => null,
        ]);
        $this->assertArrayNotHasKey('tipo', $vacios);
        $this->assertArrayNotHasKey('asignacion', $vacios);
    }

    /**
     * Sin fechas, el rango son los últimos 30 días contando hoy, en la zona de la app.
     *
     * @return void
     */
    public function test_sin_fechas_el_rango_son_los_ultimos_30_dias(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 10:00:00', 'America/Argentina/Buenos_Aires'));

        $rango = app(ClientImageSearchLogService::class)->resolver_rango(null, null);

        $this->assertSame(['desde' => '2026-08-29', 'hasta' => '2026-09-27'], $rango);
    }

    /**
     * El endpoint del resumen, de punta a punta: 200 con `{estado, mensaje, datos}`, y los costos
     * ya puestos (el detalle de las cuentas está en `CostoDelRegistroDeImagenesTest`).
     *
     * @return void
     */
    public function test_el_endpoint_del_resumen_devuelve_estado_mensaje_y_datos_costeados(): void
    {
        $this->admin_logueado();

        $client = $this->cliente_consultable();

        $this->fakear_http([
            self::PATRON_RESUMEN => Http::response($this->payload_de_resumen(), 200),
        ]);

        $response = $this->getJson(
            '/api/admin/client/' . $client->id . '/imagenes/resumen?desde=2026-09-01&hasta=2026-09-27'
        );

        $response->assertStatus(200);
        $response->assertJsonPath('estado', ClientImageSearchLogService::ESTADO_OK);
        $response->assertJsonPath('mensaje', null);
        $response->assertJsonPath('datos.asignaciones.0.id', 12);

        // 30 búsquedas de Serper a US$ 1 cada 1.000 = 0,03; 60.000 + 3.000 tokens de Haiku 4.5 = 0,06 + 0,015.
        $this->assertEqualsWithDelta(0.03, $response->json('datos.totales.costo_busquedas_usd'), 0.000001);
        $this->assertEqualsWithDelta(0.075, $response->json('datos.totales.costo_ia_usd'), 0.000001);
        $this->assertEqualsWithDelta(0.105, $response->json('datos.totales.costo_usd'), 0.000001);
    }

    /**
     * Un cliente viejo por la ruta del admin: HTTP 200 (el admin hizo bien su parte) con
     * `no_soportado` adentro, que es lo que la solapa convierte en el cartel de "todavía no tiene
     * la versión".
     *
     * @return void
     */
    public function test_por_la_ruta_un_cliente_viejo_es_200_con_no_soportado(): void
    {
        $this->admin_logueado();

        $client = $this->cliente_consultable();

        $this->fakear_http([
            '*' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $response = $this->getJson('/api/admin/client/' . $client->id . '/imagenes/resumen');

        $response->assertStatus(200);
        $response->assertJsonPath('estado', ClientImageSearchLogService::ESTADO_NO_SOPORTADO);
        $response->assertJsonPath('datos', null);
    }

    /**
     * Un rango de más de 62 días es un 422 del admin y NO sale a la red: el cliente lo rechazaría
     * igual, y el operador se entera al toque y en castellano.
     *
     * @return void
     */
    public function test_un_rango_de_mas_de_62_dias_es_422_sin_salir_a_la_red(): void
    {
        $this->admin_logueado();

        $client = $this->cliente_consultable();

        $this->fakear_http();

        $response = $this->getJson(
            '/api/admin/client/' . $client->id . '/imagenes/resumen?desde=2026-07-01&hasta=2026-09-27'
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('62', (string) $response->json('errors.desde.0'));
        Http::assertNothingSent();
    }

    /**
     * Las otras entradas inválidas también son 422 sin salir a la red: `desde` posterior a `hasta`,
     * una fecha mal formada y un tipo que el contrato no conoce.
     *
     * @return void
     */
    public function test_las_entradas_invalidas_son_422_sin_salir_a_la_red(): void
    {
        $this->admin_logueado();

        $client = $this->cliente_consultable();

        $this->fakear_http();

        $base = '/api/admin/client/' . $client->id . '/imagenes/';

        $this->getJson($base . 'resumen?desde=2026-09-27&hasta=2026-09-01')->assertStatus(422);
        $this->getJson($base . 'resumen?desde=27/09/2026')->assertStatus(422);
        $this->getJson($base . 'consultas?tipo=otra_cosa')->assertStatus(422);
        $this->getJson($base . 'consultas?asignacion=abc')->assertStatus(422);

        Http::assertNothingSent();
    }

    /**
     * Sin sesión no hay registro: las dos rutas viven bajo auth:sanctum. Y un cliente que no existe
     * es un 404 del admin (que no hay que confundir con el 404 del cliente, que viaja adentro como
     * `no_soportado`).
     *
     * @return void
     */
    public function test_sin_sesion_es_401_y_un_cliente_inexistente_es_404(): void
    {
        $client = $this->cliente_consultable();

        $this->fakear_http();

        $this->getJson('/api/admin/client/' . $client->id . '/imagenes/resumen')->assertStatus(401);
        $this->getJson('/api/admin/client/' . $client->id . '/imagenes/consultas')->assertStatus(401);

        $this->admin_logueado();

        $this->getJson('/api/admin/client/99999999/imagenes/resumen')->assertStatus(404);

        Http::assertNothingSent();
    }
}
