<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Services\ClientImageSearchLogService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\AsistenteWhatsapp\BaseDelCanal;

/**
 * La plata del registro de consultas de imágenes (misión imagenes-catalogo-completo, §12.2): cómo
 * el admin le pone costo a lo que informa el `empresa-api` de cada cliente.
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 **Que lo que no tiene precio salga `null` y se NOMBRE, nunca cero.** Es la regla de
 *     `config/ia_precios.php` y de `config/busquedas_precios.php`: un renglón sin plata se ve y se
 *     pregunta; un total que dice cero porque nadie cargó el precio se cree.
 *  2. **Que se pague solo lo cobrado.** Una búsqueda que el proveedor rechazó no se paga, y cuando
 *     con dos proveedores no se puede saber de cuál eran las rechazadas, el número es un TECHO y
 *     se dice (`es_techo`), no una proporción inventada.
 *  3. **Que la IA se costee por modelo**, con la misma cuenta que la solapa Tokens (prefijo incluido).
 *  4. 🔴 **Que la tarjeta y la tabla por día cuenten la misma historia** (revisión del 27/9/2026): el
 *     total de búsquedas sale de las cobradas por proveedor si el cliente las manda (§13 del plan),
 *     si no de la suma de los días, y la cuenta sobre los totales queda solo para cuando los días no
 *     alcanzan.
 *
 * Precios con los que se hacen las cuentas (los de la config al 27/9/2026):
 *   serper → US$ 1,00 cada 1.000 búsquedas   ·   google → US$ 5,00 cada 1.000
 *   claude-haiku-4-5-20251001 → US$ 1,00 por millón de entrada · US$ 5,00 por millón de salida
 * Cada prueba que depende de ellos los fija explícitamente con `config()`, para que un cambio de
 * precio en la tabla real (que VA a pasar cuando Lucas confirme la factura de Serper) no rompa
 * estas pruebas por el motivo equivocado.
 */
class CostoDelRegistroDeImagenesTest extends BaseDelCanal
{
    /**
     * Fija las dos tablas de precios con los valores del encabezado.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'busquedas_precios' => ['serper' => 1.00, 'google' => 5.00],
            'ia_precios.claude-haiku-4-5-20251001' => [
                'input'       => 1.00,
                'output'      => 5.00,
                'cache_write' => 1.25,
                'cache_read'  => 0.10,
            ],
        ]);
    }

    /**
     * Cliente listo para que le pidan el registro.
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
     * Pide el resumen con un payload fakeado y devuelve los `datos` ya costeados.
     *
     * @param array<string, mixed> $totales      Bloque `totales`.
     * @param array<int, array>    $dias         Bloque `dias`.
     * @param array<int, array>    $modelos      Bloque `modelos`.
     *
     * @return array<string, mixed>
     */
    private function resumen_costeado(array $totales, array $dias = [], array $modelos = []): array
    {
        $this->fakear_http([
            '*/api/admin-sync/imagenes/resumen*' => Http::response([
                'desde'        => '2026-09-01',
                'hasta'        => '2026-09-27',
                'totales'      => $totales,
                'dias'         => $dias,
                'modelos'      => $modelos,
                'asignaciones' => [],
            ], 200),
        ]);

        $resultado = app(ClientImageSearchLogService::class)
            ->resumen($this->cliente_consultable(), '2026-09-01', '2026-09-27');

        $this->assertSame(ClientImageSearchLogService::ESTADO_OK, $resultado['estado'], (string) $resultado['mensaje']);

        return $resultado['datos'];
    }

    /**
     * Bloque `totales` con las claves exactas del contrato, todo en cero salvo lo que se pise.
     *
     * @param array<string, mixed> $pisar Claves a cambiar.
     *
     * @return array<string, mixed>
     */
    private function totales(array $pisar): array
    {
        return array_merge([
            'busquedas'                => 0,
            'busquedas_cobradas'       => 0,
            'busquedas_por_proveedor'  => ['serper' => 0, 'google' => 0],
            'validaciones_ia'          => 0,
            'validaciones_ia_cobradas' => 0,
            'errores'                  => 0,
            'tokens_entrada'           => 0,
            'tokens_salida'            => 0,
            'tokens_cache_escritura'   => 0,
            'tokens_cache_lectura'     => 0,
        ], $pisar);
    }

    /**
     * Una fila del bloque `modelos`.
     *
     * @param string $modelo  Modelo.
     * @param int    $entrada Tokens de entrada.
     * @param int    $salida  Tokens de salida.
     *
     * @return array<string, mixed>
     */
    private function modelo(string $modelo, int $entrada, int $salida = 0): array
    {
        return [
            'modelo'                 => $modelo,
            'llamadas'               => 10,
            'tokens_entrada'         => $entrada,
            'tokens_salida'          => $salida,
            'tokens_cache_escritura' => 0,
            'tokens_cache_lectura'   => 0,
        ];
    }

    /**
     * El caso completo: búsquedas de dos proveedores sin rechazos, IA de un modelo con precio.
     *
     * Las cuentas:
     *   búsquedas → 1.000 de Serper × 1,00 / 1.000 = 1,00  +  12 de Google × 5,00 / 1.000 = 0,06  = 1,06
     *   IA        → 1.000.000 de entrada × 1,00 / 1.000.000 = 1,00 + 200.000 de salida × 5,00 / 1.000.000 = 1,00 = 2,00
     *   total     → 3,06
     *   por día   → el 26: 1.000 de Serper = 1,00 · el 27: 12 de Google = 0,06
     *
     * @return void
     */
    public function test_el_resumen_costea_busquedas_por_proveedor_e_ia_por_modelo(): void
    {
        $datos = $this->resumen_costeado(
            $this->totales([
                'busquedas'               => 1012,
                'busquedas_cobradas'      => 1012,
                'busquedas_por_proveedor' => ['serper' => 1000, 'google' => 12],
                'tokens_entrada'          => 1000000,
                'tokens_salida'           => 200000,
            ]),
            [
                [
                    'fecha' => '2026-09-26', 'busquedas' => 1000, 'busquedas_cobradas' => 1000,
                    'busquedas_serper' => 1000, 'busquedas_google' => 0,
                    'validaciones_ia' => 0, 'validaciones_ia_cobradas' => 0, 'errores' => 0,
                ],
                [
                    'fecha' => '2026-09-27', 'busquedas' => 12, 'busquedas_cobradas' => 12,
                    'busquedas_serper' => 0, 'busquedas_google' => 12,
                    'validaciones_ia' => 0, 'validaciones_ia_cobradas' => 0, 'errores' => 0,
                ],
            ],
            [$this->modelo('claude-haiku-4-5-20251001', 1000000, 200000)]
        );

        $totales = $datos['totales'];

        $this->assertEqualsWithDelta(1.06, $totales['costo_busquedas_usd'], 0.000001);
        $this->assertFalse($totales['costo_busquedas_es_techo']);
        $this->assertSame([], $totales['proveedores_sin_precio']);
        $this->assertEqualsWithDelta(2.00, $totales['costo_ia_usd'], 0.000001);
        $this->assertSame([], $totales['modelos_sin_precio']);
        $this->assertEqualsWithDelta(3.06, $totales['costo_usd'], 0.000001);
        $this->assertSame(1200000, $totales['tokens']);

        // Lo que vino del cliente sigue ahí, sin tocar.
        $this->assertSame(1012, $totales['busquedas']);
        $this->assertSame(['serper' => 1000, 'google' => 12], $totales['busquedas_por_proveedor']);

        $this->assertEqualsWithDelta(1.00, $datos['dias'][0]['costo_busquedas_usd'], 0.000001);
        $this->assertEqualsWithDelta(0.06, $datos['dias'][1]['costo_busquedas_usd'], 0.000001);

        $this->assertEqualsWithDelta(2.00, $datos['modelos'][0]['costo_usd'], 0.000001);
        $this->assertTrue($datos['modelos'][0]['tiene_precio']);
    }

    /**
     * 🔴 Un modelo sin precio cargado sale en null, se nombra, y el total de la IA es lo que SÍ se
     * pudo costear (un piso, con el faltante a la vista). Si ningún modelo tiene precio, el costo
     * de la IA es null: no se sabe, que no es lo mismo que cero.
     *
     * @return void
     */
    public function test_un_modelo_sin_precio_sale_en_null_se_nombra_y_no_suma(): void
    {
        $datos = $this->resumen_costeado(
            $this->totales(['tokens_entrada' => 1500000]),
            [],
            [
                $this->modelo('claude-haiku-4-5-20251001', 1000000),
                $this->modelo('modelo-inventado-9', 500000),
            ]
        );

        $por_modelo = [];
        foreach ($datos['modelos'] as $fila) {
            $por_modelo[$fila['modelo']] = $fila;
        }

        $this->assertNull(
            $por_modelo['modelo-inventado-9']['costo_usd'],
            'Un modelo sin precio cargado tiene que salir en null: cero significaría que no costó nada.'
        );
        $this->assertFalse($por_modelo['modelo-inventado-9']['tiene_precio']);
        $this->assertEqualsWithDelta(1.00, $por_modelo['claude-haiku-4-5-20251001']['costo_usd'], 0.000001);

        $this->assertEqualsWithDelta(1.00, $datos['totales']['costo_ia_usd'], 0.000001);
        $this->assertSame(['modelo-inventado-9'], $datos['totales']['modelos_sin_precio']);

        // Solo el modelo sin precio: la IA no es cero, es "no sé".
        $solo_desconocido = $this->resumen_costeado(
            $this->totales(['tokens_entrada' => 500000]),
            [],
            [$this->modelo('modelo-inventado-9', 500000)]
        );

        $this->assertNull($solo_desconocido['totales']['costo_ia_usd']);
        $this->assertSame(['modelo-inventado-9'], $solo_desconocido['totales']['modelos_sin_precio']);
    }

    /**
     * El total general respeta el null igual que sus partes: si lo único que se sabe es cero (no
     * hubo búsquedas) y la IA no tiene precio, el total es "no sé", no cero. Y si las búsquedas sí
     * costaron algo, el total es ese piso, con el faltante nombrado aparte.
     *
     * @return void
     */
    public function test_el_total_no_es_cero_cuando_lo_unico_que_se_sabe_es_cero(): void
    {
        $sin_busquedas = $this->resumen_costeado(
            $this->totales(['tokens_entrada' => 500000]),
            [],
            [$this->modelo('modelo-inventado-9', 500000)]
        );

        $this->assertEqualsWithDelta(0.0, $sin_busquedas['totales']['costo_busquedas_usd'], 0.000001);
        $this->assertNull($sin_busquedas['totales']['costo_ia_usd']);
        $this->assertNull(
            $sin_busquedas['totales']['costo_usd'],
            'Cero de búsquedas más una IA sin precio no es cero dólares: es "no sé".'
        );

        $con_busquedas = $this->resumen_costeado(
            $this->totales([
                'busquedas'               => 100,
                'busquedas_cobradas'      => 100,
                'busquedas_por_proveedor' => ['serper' => 100, 'google' => 0],
                'tokens_entrada'          => 500000,
            ]),
            [],
            [$this->modelo('modelo-inventado-9', 500000)]
        );

        $this->assertEqualsWithDelta(0.10, $con_busquedas['totales']['costo_usd'], 0.000001);
        $this->assertSame(['modelo-inventado-9'], $con_busquedas['totales']['modelos_sin_precio']);

        // Y al revés: búsquedas de un proveedor sin precio y ninguna validación con IA.
        $busquedas_sin_precio = $this->resumen_costeado(
            $this->totales([
                'busquedas'               => 10,
                'busquedas_cobradas'      => 10,
                'busquedas_por_proveedor' => ['bing' => 10],
            ])
        );

        $this->assertNull($busquedas_sin_precio['totales']['costo_busquedas_usd']);
        $this->assertSame(['bing'], $busquedas_sin_precio['totales']['proveedores_sin_precio']);
        $this->assertEqualsWithDelta(0.0, $busquedas_sin_precio['totales']['costo_ia_usd'], 0.000001);
        $this->assertNull(
            $busquedas_sin_precio['totales']['costo_usd'],
            'Búsquedas sin precio más cero de IA no es cero dólares: es "no sé".'
        );
    }

    /**
     * Con un solo proveedor, las búsquedas que no se cobraron son todas suyas: el número es exacto.
     * 1.000 hechas, 700 cobradas → 700 × 1,00 / 1.000 = 0,70 (y NO 1,00).
     *
     * @return void
     */
    public function test_con_un_solo_proveedor_las_no_cobradas_no_se_pagan_y_el_numero_es_exacto(): void
    {
        $costo = ClientImageSearchLogService::costo_de_busquedas(['serper' => 1000, 'google' => 0], 1000, 700);

        $this->assertEqualsWithDelta(0.70, $costo['costo_usd'], 0.000001);
        $this->assertFalse($costo['es_techo']);
        $this->assertSame([], $costo['proveedores_sin_precio']);
    }

    /**
     * 🔴 Con dos proveedores y búsquedas no cobradas, no se puede saber de cuál eran las rechazadas:
     * el costo es un TECHO —las cobradas se asignan primero al más caro— y se marca `es_techo`.
     *
     * Serper 1.000 + Google 12, cobradas 1.000 (12 rechazadas de algún lado):
     *   techo → Google 12 × 5,00 / 1.000 = 0,06 + Serper 988 × 1,00 / 1.000 = 0,988 = 1,048
     *   (el piso sería Serper 1.000 = 1,00: la diferencia es chica, pero se dice)
     *
     * @return void
     */
    public function test_con_dos_proveedores_y_rechazos_el_costo_es_un_techo_y_se_dice(): void
    {
        $costo = ClientImageSearchLogService::costo_de_busquedas(['serper' => 1000, 'google' => 12], 1012, 1000);

        $this->assertEqualsWithDelta(1.048, $costo['costo_usd'], 0.000001);
        $this->assertTrue($costo['es_techo']);

        // Sin rechazos, con los mismos dos proveedores, el número vuelve a ser exacto.
        $exacto = ClientImageSearchLogService::costo_de_busquedas(['serper' => 1000, 'google' => 12], 1012, 1012);

        $this->assertEqualsWithDelta(1.06, $exacto['costo_usd'], 0.000001);
        $this->assertFalse($exacto['es_techo']);
    }

    /**
     * Un proveedor sin precio cargado no se inventa: se nombra y no suma. Si es el único con
     * búsquedas cobradas, el costo es null. Y las búsquedas que el total cuenta y ningún proveedor
     * reclama van bajo el proveedor '' (sin precio), para que el número no salga corto en silencio.
     *
     * @return void
     */
    public function test_un_proveedor_sin_precio_se_nombra_y_no_se_inventa(): void
    {
        $solo = ClientImageSearchLogService::costo_de_busquedas(['bing' => 10], 10, 10);

        $this->assertNull($solo['costo_usd']);
        $this->assertSame(['bing'], $solo['proveedores_sin_precio']);

        $mezcla = ClientImageSearchLogService::costo_de_busquedas(['serper' => 100, 'bing' => 10], 110, 110);

        $this->assertEqualsWithDelta(0.10, $mezcla['costo_usd'], 0.000001);
        $this->assertSame(['bing'], $mezcla['proveedores_sin_precio']);

        $sin_atribuir = ClientImageSearchLogService::costo_de_busquedas(['serper' => 100], 130, 130);

        $this->assertEqualsWithDelta(0.10, $sin_atribuir['costo_usd'], 0.000001);
        $this->assertSame([''], $sin_atribuir['proveedores_sin_precio']);

        // Sin búsquedas no hay nada que costear: eso sí es cero.
        $nada = ClientImageSearchLogService::costo_de_busquedas(['serper' => 0, 'google' => 0], 0, 0);

        $this->assertSame(0.0, $nada['costo_usd']);
        $this->assertSame([], $nada['proveedores_sin_precio']);
    }

    /**
     * El precio sale de la config y de ningún otro lado: cuando Lucas confirme la factura de Serper
     * (que puede ser 0,30), se cambia UNA línea y la pantalla cambia sola.
     *
     * @return void
     */
    public function test_el_precio_sale_de_la_config(): void
    {
        config(['busquedas_precios.serper' => 0.30]);

        $costo = ClientImageSearchLogService::costo_de_busquedas(['serper' => 1000], 1000, 1000);

        $this->assertEqualsWithDelta(0.30, $costo['costo_usd'], 0.000001);
    }

    /**
     * Cada fila del registro trae su costo, y cada regla se ve en una fila:
     *
     *   - búsqueda de Serper cobrada             → 1,00 / 1.000 = 0,001
     *   - búsqueda de Google cobrada             → 5,00 / 1.000 = 0,005
     *   - búsqueda de Serper rechazada           → 0 (no se paga), con precio conocido
     *   - validación con Haiku 4.5 (3.000 + 150) → 3.000 × 1,00 / 1M + 150 × 5,00 / 1M = 0,00375
     *   - validación con un modelo sin precio    → null, sin precio
     *   - validación fallida, sin tokens         → 0 aunque el modelo no tenga precio
     *
     * @return void
     */
    public function test_cada_fila_del_registro_trae_su_costo(): void
    {
        $this->fakear_http([
            '*/api/admin-sync/imagenes/consultas*' => Http::response([
                'models' => [
                    'current_page' => 1,
                    'data'         => [
                        ['id' => 1, 'tipo' => 'busqueda', 'proveedor' => 'serper', 'modelo' => null, 'ok' => true, 'cobrada' => true],
                        ['id' => 2, 'tipo' => 'busqueda', 'proveedor' => 'google', 'modelo' => null, 'ok' => true, 'cobrada' => true],
                        ['id' => 3, 'tipo' => 'busqueda', 'proveedor' => 'serper', 'modelo' => null, 'ok' => false, 'cobrada' => false, 'error' => 'HTTP 403: Not enough credits'],
                        [
                            'id' => 4, 'tipo' => 'validacion_ia', 'proveedor' => 'anthropic',
                            'modelo' => 'claude-haiku-4-5-20251001', 'ok' => true, 'cobrada' => true,
                            'tokens_entrada' => 3000, 'tokens_salida' => 150,
                            'tokens_cache_escritura' => 0, 'tokens_cache_lectura' => 0,
                        ],
                        [
                            'id' => 5, 'tipo' => 'validacion_ia', 'proveedor' => 'anthropic',
                            'modelo' => 'modelo-inventado-9', 'ok' => true, 'cobrada' => true,
                            'tokens_entrada' => 1000, 'tokens_salida' => 0,
                            'tokens_cache_escritura' => 0, 'tokens_cache_lectura' => 0,
                        ],
                        [
                            'id' => 6, 'tipo' => 'validacion_ia', 'proveedor' => 'anthropic',
                            'modelo' => 'modelo-inventado-9', 'ok' => false, 'cobrada' => false,
                            'tokens_entrada' => null, 'tokens_salida' => null,
                            'tokens_cache_escritura' => null, 'tokens_cache_lectura' => null,
                            'error' => 'Timeout de la IA',
                        ],
                    ],
                    'last_page' => 1,
                    'per_page'  => 50,
                    'total'     => 6,
                ],
            ], 200),
        ]);

        $servicio  = app(ClientImageSearchLogService::class);
        $resultado = $servicio->consultas($this->cliente_consultable(), $servicio->filtros_de_consultas([
            'desde' => '2026-09-01',
            'hasta' => '2026-09-27',
        ]));

        $this->assertSame(ClientImageSearchLogService::ESTADO_OK, $resultado['estado'], (string) $resultado['mensaje']);

        $por_id = [];
        foreach ($resultado['datos']['models']['data'] as $fila) {
            $por_id[$fila['id']] = $fila;
        }

        $this->assertEqualsWithDelta(0.001, $por_id[1]['costo_usd'], 0.0000001);
        $this->assertTrue($por_id[1]['tiene_precio']);

        $this->assertEqualsWithDelta(0.005, $por_id[2]['costo_usd'], 0.0000001);

        $this->assertSame(0.0, $por_id[3]['costo_usd'], 'Una búsqueda rechazada no se paga.');
        $this->assertTrue($por_id[3]['tiene_precio']);

        $this->assertEqualsWithDelta(0.00375, $por_id[4]['costo_usd'], 0.0000001);
        $this->assertTrue($por_id[4]['tiene_precio']);

        $this->assertNull($por_id[5]['costo_usd'], 'Una validación con un modelo sin precio no es gratis: es "no sé".');
        $this->assertFalse($por_id[5]['tiene_precio']);

        $this->assertSame(0.0, $por_id[6]['costo_usd'], 'Sin tokens no hay nada que pagar, con o sin precio.');

        // Lo demás de la fila viaja intacto.
        $this->assertSame('HTTP 403: Not enough credits', $por_id[3]['error']);
        $this->assertSame(6, $resultado['datos']['models']['total']);
    }

    /**
     * Una fila del bloque `dias` con las claves exactas del contrato (sin el corte de §13).
     *
     * @param string $fecha    Día.
     * @param int    $serper   Búsquedas de Serper hechas.
     * @param int    $google   Búsquedas de Google hechas.
     * @param int    $cobradas Búsquedas cobradas del día (entre los dos).
     *
     * @return array<string, mixed>
     */
    private function dia(string $fecha, int $serper, int $google, int $cobradas): array
    {
        return [
            'fecha'                    => $fecha,
            'busquedas'                => $serper + $google,
            'busquedas_cobradas'       => $cobradas,
            'busquedas_serper'         => $serper,
            'busquedas_google'         => $google,
            'validaciones_ia'          => 0,
            'validaciones_ia_cobradas' => 0,
            'errores'                  => ($serper + $google) - $cobradas,
        ];
    }

    /**
     * 🔴 El caso que encontró la revisión del 27/9/2026: 200 búsquedas de Google RECHAZADAS un día y
     * 1.000 de Serper cobradas otro. Mirando solo los totales ("1.200 hechas, 1.000 cobradas") no
     * se sabe de quién eran las rechazadas y el techo decía US$ 1,80; cada día, con un solo
     * proveedor, es exacto: 0 y 1,00. La tarjeta tiene que decir lo mismo que la tabla: 1,00, sin
     * techo, y el total igual a la suma de los días.
     *
     * @return void
     */
    public function test_el_total_de_busquedas_es_la_suma_de_los_dias_y_no_un_techo_global(): void
    {
        $datos = $this->resumen_costeado(
            $this->totales([
                'busquedas'               => 1200,
                'busquedas_cobradas'      => 1000,
                'busquedas_por_proveedor' => ['serper' => 1000, 'google' => 200],
                'errores'                 => 200,
            ]),
            [
                $this->dia('2026-09-26', 0, 200, 0),
                $this->dia('2026-09-27', 1000, 0, 1000),
            ]
        );

        $totales = $datos['totales'];

        $this->assertEqualsWithDelta(1.00, $totales['costo_busquedas_usd'], 0.000001);
        $this->assertFalse($totales['costo_busquedas_es_techo'], 'Cada día es exacto: la suma también.');
        $this->assertSame([], $totales['proveedores_sin_precio']);

        // El día de los rechazos cuesta cero exacto (no "techo"); el de Serper, 1,00.
        $this->assertSame(0.0, $datos['dias'][0]['costo_busquedas_usd']);
        $this->assertFalse($datos['dias'][0]['costo_busquedas_es_techo']);
        $this->assertEqualsWithDelta(1.00, $datos['dias'][1]['costo_busquedas_usd'], 0.000001);
        $this->assertFalse($datos['dias'][1]['costo_busquedas_es_techo']);

        $this->assertEqualsWithDelta(
            $datos['dias'][0]['costo_busquedas_usd'] + $datos['dias'][1]['costo_busquedas_usd'],
            $totales['costo_busquedas_usd'],
            0.000001,
            'El total de la tarjeta tiene que ser la suma de la tabla por día.'
        );

        // Lo que decía la cuenta sobre los totales, para que se vea por qué no se usa acá.
        $global = ClientImageSearchLogService::costo_de_busquedas(['serper' => 1000, 'google' => 200], 1200, 1000);
        $this->assertEqualsWithDelta(1.80, $global['costo_usd'], 0.000001);
        $this->assertTrue($global['es_techo']);
    }

    /**
     * Con el corte de §13 —las cobradas por proveedor, en el total y en cada día— el costo es
     * EXACTO aunque un mismo día mezcle proveedores con rechazos, que es justo el caso que sin él
     * solo tiene techo.
     *
     *   día 26: Serper 1.000 hechas y cobradas + Google 200 hechas, 100 cobradas
     *           → 1.000 × 1,00 / 1.000 + 100 × 5,00 / 1.000 = 1,00 + 0,50 = 1,50
     *           (sin el corte: techo 1,90 = Google 200 → 1,00 + Serper 900 → 0,90)
     *   día 27: Serper 500 hechas y cobradas → 0,50
     *   total:  Serper 1.500 + Google 100 cobradas → 1,50 + 0,50 = 2,00
     *
     * @return void
     */
    public function test_con_las_cobradas_por_proveedor_el_costo_es_exacto_por_dia_y_en_total(): void
    {
        $dia_mezclado = $this->dia('2026-09-26', 1000, 200, 1100);
        $dia_mezclado['busquedas_serper_cobradas'] = 1000;
        $dia_mezclado['busquedas_google_cobradas'] = 100;

        $dia_serper = $this->dia('2026-09-27', 500, 0, 500);
        $dia_serper['busquedas_serper_cobradas'] = 500;
        $dia_serper['busquedas_google_cobradas'] = 0;

        $datos = $this->resumen_costeado(
            $this->totales([
                'busquedas'                        => 1700,
                'busquedas_cobradas'               => 1600,
                'busquedas_por_proveedor'          => ['serper' => 1500, 'google' => 200],
                'busquedas_cobradas_por_proveedor' => ['serper' => 1500, 'google' => 100],
                'errores'                          => 100,
            ]),
            [$dia_mezclado, $dia_serper]
        );

        $this->assertEqualsWithDelta(1.50, $datos['dias'][0]['costo_busquedas_usd'], 0.000001);
        $this->assertFalse($datos['dias'][0]['costo_busquedas_es_techo']);
        $this->assertEqualsWithDelta(0.50, $datos['dias'][1]['costo_busquedas_usd'], 0.000001);
        $this->assertFalse($datos['dias'][1]['costo_busquedas_es_techo']);

        $this->assertEqualsWithDelta(2.00, $datos['totales']['costo_busquedas_usd'], 0.000001);
        $this->assertFalse($datos['totales']['costo_busquedas_es_techo']);
        $this->assertSame([], $datos['totales']['proveedores_sin_precio']);

        // Sin el corte, ese mismo día mezclado solo tiene techo.
        $sin_corte = ClientImageSearchLogService::costo_de_busquedas(['serper' => 1000, 'google' => 200], 1200, 1100);
        $this->assertEqualsWithDelta(1.90, $sin_corte['costo_usd'], 0.000001);
        $this->assertTrue($sin_corte['es_techo']);

        /* Y el total usa el corte aunque no vengan días: sin él, la única cuenta posible sería la de
         * los totales, que con rechazos mezclados solo da el techo de 1,90. */
        $sin_dias = $this->resumen_costeado(
            $this->totales([
                'busquedas'                        => 1200,
                'busquedas_cobradas'               => 1100,
                'busquedas_por_proveedor'          => ['serper' => 1000, 'google' => 200],
                'busquedas_cobradas_por_proveedor' => ['serper' => 1000, 'google' => 100],
            ])
        );

        $this->assertEqualsWithDelta(1.50, $sin_dias['totales']['costo_busquedas_usd'], 0.000001);
        $this->assertFalse($sin_dias['totales']['costo_busquedas_es_techo']);
    }

    /**
     * 🔴 Sin búsquedas cobradas el costo es cero EXACTO: ni "techo" ni proveedores sin precio que
     * nombrar, porque lo que no se cobró no cuesta nada. Y cuando todos los proveedores cuestan lo
     * mismo tampoco hay techo: cualquier reparto da el mismo número.
     *
     * @return void
     */
    public function test_sin_cobradas_el_cero_es_exacto_y_a_igual_precio_no_hay_techo(): void
    {
        $ninguna = ClientImageSearchLogService::costo_de_busquedas(['serper' => 10, 'google' => 5], 15, 0);

        $this->assertSame(0.0, $ninguna['costo_usd']);
        $this->assertFalse($ninguna['es_techo'], 'Cero cobradas es un cero exacto, no un techo.');
        $this->assertSame([], $ninguna['proveedores_sin_precio']);

        $ninguna_sin_precio = ClientImageSearchLogService::costo_de_busquedas(['bing' => 10, 'serper' => 5], 15, 0);

        $this->assertSame(0.0, $ninguna_sin_precio['costo_usd']);
        $this->assertFalse($ninguna_sin_precio['es_techo']);
        $this->assertSame([], $ninguna_sin_precio['proveedores_sin_precio']);

        // Dos proveedores al mismo precio, con rechazos: 15 cobradas × 2,00 / 1.000, sin importar de quién.
        $mismo_precio = ClientImageSearchLogService::costo_de_busquedas(
            ['uno' => 10, 'otro' => 10],
            20,
            15,
            ['uno' => 2.00, 'otro' => 2.00]
        );

        $this->assertEqualsWithDelta(0.03, $mismo_precio['costo_usd'], 0.000001);
        $this->assertFalse($mismo_precio['es_techo']);
    }

    /**
     * Un día con búsquedas de un proveedor que el corte por día no abre (el contrato abre Serper y
     * Google): ahí el día no sabe de quién son y el total del período sí (`busquedas_por_proveedor`),
     * así que se usa la cuenta sobre los totales. Se nota en el faltante: el total nombra a "bing",
     * que es lo que hay que cargar en la tabla de precios; la suma de los días diría "sin proveedor".
     *
     * @return void
     */
    public function test_un_dia_con_un_proveedor_que_el_dia_no_abre_usa_la_cuenta_del_total(): void
    {
        $dia = $this->dia('2026-09-27', 5, 0, 10);
        $dia['busquedas'] = 10;
        $dia['errores']   = 0;

        $datos = $this->resumen_costeado(
            $this->totales([
                'busquedas'               => 10,
                'busquedas_cobradas'      => 10,
                'busquedas_por_proveedor' => ['serper' => 5, 'bing' => 5],
            ]),
            [$dia]
        );

        $this->assertEqualsWithDelta(0.005, $datos['totales']['costo_busquedas_usd'], 0.0000001);
        $this->assertSame(['bing'], $datos['totales']['proveedores_sin_precio']);
    }

    /**
     * Si los días no suman las mismas búsquedas COBRADAS que el total (falta un día con búsquedas
     * pagas), la suma saldría CORTA: se usa la cuenta sobre los totales. Acá falta el día de Google:
     * la suma de los días diría 1,00 y el total es 1,06.
     *
     * Y al revés: si lo que falta son solo búsquedas rechazadas (las cobradas coinciden), esas no
     * costaron nada y la suma de los días sigue siendo exacta; la cuenta sobre los totales, en cambio,
     * no sabe de quién eran las rechazadas y daría un techo.
     *
     * @return void
     */
    public function test_si_los_dias_no_suman_el_total_se_usa_la_cuenta_del_total(): void
    {
        // Falta un día con 200 búsquedas de Google, todas rechazadas: las cobradas coinciden.
        $faltan_rechazadas = $this->resumen_costeado(
            $this->totales([
                'busquedas'               => 1200,
                'busquedas_cobradas'      => 1000,
                'busquedas_por_proveedor' => ['serper' => 1000, 'google' => 200],
            ]),
            [$this->dia('2026-09-27', 1000, 0, 1000)]
        );

        $this->assertEqualsWithDelta(1.00, $faltan_rechazadas['totales']['costo_busquedas_usd'], 0.000001);
        $this->assertFalse($faltan_rechazadas['totales']['costo_busquedas_es_techo']);

        $datos = $this->resumen_costeado(
            $this->totales([
                'busquedas'               => 1012,
                'busquedas_cobradas'      => 1012,
                'busquedas_por_proveedor' => ['serper' => 1000, 'google' => 12],
            ]),
            [$this->dia('2026-09-26', 1000, 0, 1000)]
        );

        $this->assertEqualsWithDelta(
            1.06,
            $datos['totales']['costo_busquedas_usd'],
            0.000001,
            'Con un día faltante la suma de los días sale corta: manda el total.'
        );
        $this->assertFalse($datos['totales']['costo_busquedas_es_techo']);
    }
}
