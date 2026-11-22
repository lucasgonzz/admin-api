<?php

namespace Tests\Feature\PreciosVigentes;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Services\PreciosVigentesSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Ajuste «oferta simple» de la etapa E1 (Lucas, 21/11/2026): tres productos al mismo precio, los tres
 * juntos con 30 % de descuento, y Agentes como un solo paquete con tres paquetes de tokens que se cotizan
 * al dólar blue. Criterios CA1-bis, CA2-bis, CA2-ter, CA3-bis y CA4-bis de `.mision/AJUSTE-OFERTA-SIMPLE.md`.
 *
 * Lo que se custodia: los números los calcula la API (mensualidades y tres juntos), no las puntas; un JSON
 * viejo con `lite`/`full` se lee sin 500; y Martín jamás ve los USD ni la ganancia.
 *
 * La IA no interviene en este archivo.
 */
class OfertaSimpleDePreciosTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Deja la base sin la fuente guardada.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        AdminSetting::query()->where('key', PreciosVigentesSettingsTest::CLAVE)->delete();
        AdminSetting::flush_memo();
    }

    /**
     * Autentica un admin por Sanctum.
     *
     * @return Admin
     */
    private function admin_autenticado(): Admin
    {
        $admin = Admin::create([
            'name'     => 'Editor oferta simple',
            'email'    => 'oferta-simple-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);

        $this->actingAs($admin, 'sanctum');

        return $admin;
    }

    /**
     * CA1-bis. Con la base vacía: versión 2, tres items a 628.400, tres juntos, tres paquetes de tokens
     * con su mensualidad calculada, cotización, ganancia y aviso; sin `lite` ni `full`.
     *
     * @return void
     */
    public function test_sin_guardar_devuelve_la_oferta_simple(): void
    {
        $this->admin_autenticado();

        $c = $this->getJson(PreciosVigentesSettingsTest::RUTA)->assertStatus(200)->json();

        $this->assertSame(2, $c['version']);

        $this->assertCount(3, $c['implementaciones']['items']);
        $this->assertSame(['gestion', 'tienda', 'agentes'], array_column($c['implementaciones']['items'], 'clave'));
        $this->assertSame([628400, 628400, 628400], array_column($c['implementaciones']['items'], 'precio'));
        $this->assertSame(
            ['descuento_porcentaje' => 30, 'precio_lista' => 1885200, 'precio' => 1319640],
            $c['implementaciones']['tres_juntos']
        );

        $paquetes = $c['agentes']['paquetes'];
        $this->assertSame(['inicial', 'pro', 'max'], array_column($paquetes, 'clave'));
        $this->assertSame([10, 30, 70], array_column($paquetes, 'tokens_usd'));
        $this->assertSame([46750, 80250, 147250], array_column($paquetes, 'mensualidad'));

        $this->assertSame(1675, $c['agentes']['cotizacion_usd']['valor']);
        $this->assertSame('Dólar blue, venta', $c['agentes']['cotizacion_usd']['referencia']);
        $this->assertSame('2026-11-20', $c['agentes']['cotizacion_usd']['fecha']);
        $this->assertSame(30000, $c['agentes']['ganancia_por_paquete']);
        $this->assertSame(80, $c['agentes']['aviso_consumo_porcentaje']);

        $this->assertArrayNotHasKey('lite', $c['agentes']);
        $this->assertArrayNotHasKey('full', $c['agentes']);
        $this->assertEquals(PreciosVigentesSettingsTest::contrato_por_defecto(), $this->sin_sellos($c));
    }

    /**
     * CA2-bis. Cotización en 1700: las mensualidades se recalculan en la API y quedan guardadas.
     *
     * @return void
     */
    public function test_cambiar_la_cotizacion_recalcula_las_mensualidades(): void
    {
        $this->admin_autenticado();

        $cuerpo = PreciosVigentesSettingsTest::contrato_por_defecto();
        $cuerpo['agentes']['cotizacion_usd']['valor'] = 1700;

        $respuesta = $this->putJson(PreciosVigentesSettingsTest::RUTA, $cuerpo)->assertStatus(200);

        $this->assertSame([47000, 81000, 149000], array_column($respuesta->json('agentes.paquetes'), 'mensualidad'));

        $leido = $this->getJson(PreciosVigentesSettingsTest::RUTA);
        $this->assertSame([47000, 81000, 149000], array_column($leido->json('agentes.paquetes'), 'mensualidad'));

        $guardado = json_decode((string) AdminSetting::query()->where('key', PreciosVigentesSettingsTest::CLAVE)->value('value'), true);
        $this->assertSame(47000, $guardado['agentes']['paquetes'][0]['mensualidad']);
        $this->assertSame(2, $guardado['version']);
    }

    /**
     * CA2-bis. Descuento de los tres juntos en 25 %: el precio es 1.413.900 y la lista no cambia. Cambiar el
     * precio de un item mueve la lista y el precio.
     *
     * @return void
     */
    public function test_cambiar_el_descuento_o_un_item_recalcula_los_tres_juntos(): void
    {
        $this->admin_autenticado();

        $cuerpo = PreciosVigentesSettingsTest::contrato_por_defecto();
        $cuerpo['implementaciones']['tres_juntos']['descuento_porcentaje'] = 25;

        $r = $this->putJson(PreciosVigentesSettingsTest::RUTA, $cuerpo)->assertStatus(200);

        $this->assertSame(1885200, $r->json('implementaciones.tres_juntos.precio_lista'));
        $this->assertSame(1413900, $r->json('implementaciones.tres_juntos.precio'));

        $cuerpo['implementaciones']['items'][2]['precio'] = 700000;
        $r = $this->putJson(PreciosVigentesSettingsTest::RUTA, $cuerpo)->assertStatus(200);

        $this->assertSame(1956800, $r->json('implementaciones.tres_juntos.precio_lista'));
        $this->assertSame(1467600, $r->json('implementaciones.tres_juntos.precio'));
    }

    /**
     * Revisión ronda 1. Con un item con centavos la lista se suma SIN redondear a pesos enteros (a 2 decimales,
     * como la vista previa del SPA) y sólo el precio con descuento se redondea a pesos enteros. Items 628400,
     * 628400 y 628400,50 con 30 %: lista 1885200,5 y precio 1319640. El bloque de Martín muestra la lista
     * coherente con ese valor.
     *
     * @return void
     */
    public function test_la_lista_de_los_tres_juntos_conserva_los_centavos(): void
    {
        $this->admin_autenticado();

        $cuerpo = PreciosVigentesSettingsTest::contrato_por_defecto();
        $cuerpo['implementaciones']['items'][2]['precio'] = 628400.5;

        $r = $this->putJson(PreciosVigentesSettingsTest::RUTA, $cuerpo)->assertStatus(200);

        $this->assertSame(1885200.5, $r->json('implementaciones.tres_juntos.precio_lista'));
        $this->assertSame(1319640, $r->json('implementaciones.tres_juntos.precio'));

        $bloque = PreciosVigentesSettings::bloque_para_el_agente();

        $this->assertStringContainsString('Los tres juntos: 30 % de descuento, $1.319.640 en lugar de $1.885.200,50 ($659.820).', $bloque);
    }

    /**
     * CA2-bis. Si el PUT manda `mensualidad` o `tres_juntos.precio` a mano, se ignoran y se recalculan.
     *
     * @return void
     */
    public function test_lo_calculado_que_llega_en_el_put_se_ignora(): void
    {
        $this->admin_autenticado();

        $cuerpo = PreciosVigentesSettingsTest::contrato_por_defecto();
        $cuerpo['agentes']['paquetes'][0]['mensualidad']          = 1;
        $cuerpo['agentes']['paquetes'][2]['mensualidad']          = 'abc';
        $cuerpo['implementaciones']['tres_juntos']['precio']       = 5;
        $cuerpo['implementaciones']['tres_juntos']['precio_lista'] = 9;
        $cuerpo['version']                                         = 1;

        $r = $this->putJson(PreciosVigentesSettingsTest::RUTA, $cuerpo)->assertStatus(200);

        $this->assertSame([46750, 80250, 147250], array_column($r->json('agentes.paquetes'), 'mensualidad'));
        $this->assertSame(1885200, $r->json('implementaciones.tres_juntos.precio_lista'));
        $this->assertSame(1319640, $r->json('implementaciones.tres_juntos.precio'));
        $this->assertSame(2, $r->json('version'));
    }

    /**
     * Casos inválidos del PUT: cada uno da 422 con el error en SU clave y no guarda nada.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function puts_invalidos(): array
    {
        return [
            'cotizacion = 0' => ['agentes.cotizacion_usd.valor', function (array $c) {
                $c['agentes']['cotizacion_usd']['valor'] = 0;

                return $c;
            }],
            'tokens de pro = -1' => ['agentes.paquetes.pro.tokens_usd', function (array $c) {
                $c['agentes']['paquetes'][1]['tokens_usd'] = -1;

                return $c;
            }],
            'tokens de max = 0' => ['agentes.paquetes.max.tokens_usd', function (array $c) {
                $c['agentes']['paquetes'][2]['tokens_usd'] = 0;

                return $c;
            }],
            'aviso = 0' => ['agentes.aviso_consumo_porcentaje', function (array $c) {
                $c['agentes']['aviso_consumo_porcentaje'] = 0;

                return $c;
            }],
            'aviso = 101' => ['agentes.aviso_consumo_porcentaje', function (array $c) {
                $c['agentes']['aviso_consumo_porcentaje'] = 101;

                return $c;
            }],
            'descuento tres juntos = 120' => ['implementaciones.tres_juntos.descuento_porcentaje', function (array $c) {
                $c['implementaciones']['tres_juntos']['descuento_porcentaje'] = 120;

                return $c;
            }],
            'fecha = "ayer"' => ['agentes.cotizacion_usd.fecha', function (array $c) {
                $c['agentes']['cotizacion_usd']['fecha'] = 'ayer';

                return $c;
            }],
            'ganancia negativa' => ['agentes.ganancia_por_paquete', function (array $c) {
                $c['agentes']['ganancia_por_paquete'] = -1;

                return $c;
            }],
            'incluye vacío' => ['agentes.incluye', function (array $c) {
                $c['agentes']['incluye'] = '   ';

                return $c;
            }],
            'uso_de_tokens de 301 caracteres' => ['agentes.uso_de_tokens', function (array $c) {
                $c['agentes']['uso_de_tokens'] = str_repeat('a', 301);

                return $c;
            }],
            'referencia de 61 caracteres' => ['agentes.cotizacion_usd.referencia', function (array $c) {
                $c['agentes']['cotizacion_usd']['referencia'] = str_repeat('a', 61);

                return $c;
            }],
            'nombre de paquete de 41 caracteres' => ['agentes.paquetes.inicial.nombre', function (array $c) {
                $c['agentes']['paquetes'][0]['nombre'] = str_repeat('a', 41);

                return $c;
            }],
            'precio de un item negativo' => ['implementaciones.items.agentes.precio', function (array $c) {
                $c['implementaciones']['items'][2]['precio'] = -5;

                return $c;
            }],
        ];
    }

    /**
     * CA2-bis. Un valor inválido da 422 con el error por clave y no guarda nada.
     *
     * @dataProvider puts_invalidos
     *
     * @param string   $clave_con_error Clave que tiene que traer el error.
     * @param callable $deformar        Deforma el contrato por defecto.
     *
     * @return void
     */
    public function test_los_valores_invalidos_dan_422_por_clave(string $clave_con_error, callable $deformar): void
    {
        $this->admin_autenticado();

        $r = $this->putJson(PreciosVigentesSettingsTest::RUTA, $deformar(PreciosVigentesSettingsTest::contrato_por_defecto()));

        $r->assertStatus(422);
        $this->assertArrayHasKey($clave_con_error, $r->json('errors'), 'Errores devueltos: ' . json_encode($r->json('errors')));
        $this->assertNull(AdminSetting::query()->where('key', PreciosVigentesSettingsTest::CLAVE)->first(), 'El PUT inválido guardó algo.');
    }

    /**
     * CA2-ter. Un JSON viejo guardado a mano (con `agentes.lite/full`, seis items y version 1) se lee sin 500
     * y devuelve la forma nueva: lo de gestión que sí encaja se conserva, el resto se completa con los
     * defaults, y queda un warning.
     *
     * @return void
     */
    public function test_un_json_viejo_se_normaliza_a_la_forma_nueva(): void
    {
        $this->admin_autenticado();

        $viejo = PreciosVigentesSettingsTest::contrato_por_defecto();
        $viejo['version']                         = 1;
        $viejo['gestion']['planes'][1]['abono']   = 86000;
        $viejo['agentes']                          = [
            'lite' => ['mensualidad' => 30000, 'implementacion' => 0],
            'full' => ['mensualidad' => 94700],
        ];
        $viejo['implementaciones']['items'] = [
            ['clave' => 'gestion', 'nombre' => 'Sistema de gestión', 'precio' => 600000],
            ['clave' => 'tienda', 'nombre' => 'Tienda online', 'precio' => 628400],
            ['clave' => 'agentes_full', 'nombre' => 'Agentes Full', 'precio' => 938600],
            ['clave' => 'gestion_tienda', 'nombre' => 'Sistema + tienda', 'precio' => 946800],
            ['clave' => 'gestion_agentes_full', 'nombre' => 'Sistema + Agentes Full', 'precio' => 1254600],
            ['clave' => 'gestion_tienda_agentes_full', 'nombre' => 'Sistema + tienda + Agentes Full', 'precio' => 1574300],
        ];
        unset($viejo['implementaciones']['tres_juntos']);

        AdminSetting::set(PreciosVigentesSettingsTest::CLAVE, json_encode($viejo, JSON_UNESCAPED_UNICODE));
        Log::spy();

        $r = $this->getJson(PreciosVigentesSettingsTest::RUTA);
        $r->assertStatus(200);

        $c = $r->json();

        $this->assertSame(2, $c['version']);
        $this->assertArrayNotHasKey('lite', $c['agentes']);
        $this->assertArrayNotHasKey('full', $c['agentes']);
        $this->assertSame([46750, 80250, 147250], array_column($c['agentes']['paquetes'], 'mensualidad'));
        $this->assertSame(['gestion', 'tienda', 'agentes'], array_column($c['implementaciones']['items'], 'clave'));
        $this->assertSame(86000, $c['gestion']['planes'][1]['abono'], 'Lo de gestión que encaja se conserva.');
        $this->assertSame(600000, $c['implementaciones']['items'][0]['precio'], 'El precio guardado de un item que sigue existiendo se conserva.');
        $this->assertSame(628400, $c['implementaciones']['items'][2]['precio']);
        $this->assertSame(1856800, $c['implementaciones']['tres_juntos']['precio_lista']);
        $this->assertSame(1299760, $c['implementaciones']['tres_juntos']['precio']);

        Log::shouldHaveReceived('warning')->atLeast()->once();

        /* El endpoint público también lo lee sin 500 y con la forma nueva. */
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/landing/precios')->assertStatus(200)->assertJsonPath('version', 2);
    }

    /**
     * CA3-bis. La landing recibe las mensualidades de Agentes y los tres juntos ya calculados, sin
     * `actualizado_por`.
     *
     * @return void
     */
    public function test_el_endpoint_publico_trae_lo_calculado(): void
    {
        $r = $this->getJson('/api/landing/precios')->assertStatus(200);

        $this->assertSame([46750, 80250, 147250], array_column($r->json('agentes.paquetes'), 'mensualidad'));
        $this->assertSame(1319640, $r->json('implementaciones.tres_juntos.precio'));
        $this->assertSame(1885200, $r->json('implementaciones.tres_juntos.precio_lista'));
        $this->assertArrayNotHasKey('actualizado_por', $r->json());
        $this->assertArrayNotHasKey('lite', $r->json('agentes'));
    }

    /**
     * CA4-bis. El bloque de Martín con los defaults: las frases exactas y ni rastro de lo viejo ni de los
     * USD o la ganancia.
     *
     * @return void
     */
    public function test_el_bloque_de_martin_dice_la_oferta_simple_y_nunca_los_usd(): void
    {
        $bloque = PreciosVigentesSettings::bloque_para_el_agente();

        foreach ([
            'Inicial: $46.750 por mes.',
            'Pro: $80.250 por mes (3 veces los tokens de Inicial).',
            'Max: $147.250 por mes (7 veces los tokens de Inicial).',
            'generar imágenes para el negocio',
            'hacerle preguntas y pedirle cosas a los agentes',
            'Agentes: $628.400 ($314.200).',
            'Sistema de gestión: $628.400 ($314.200).',
            'Tienda online: $628.400 ($314.200).',
            'Los tres juntos: 30 % de descuento, $1.319.640 en lugar de $1.885.200 ($659.820).',
            'No hay combos de dos: dos productos se suman sin descuento.',
            'Al llegar al 80 % de los tokens del mes se le avisa y se le propone pasar al paquete siguiente, con su OK; el agente no se corta.',
            'migración o configuración inicial del número',
            'desarrollos a medida de por vida',
        ] as $frase) {
            $this->assertStringContainsString($frase, $bloque);
        }

        foreach (['Lite', 'Full', 'USD 10', 'USD 30', 'USD 70', '30.000 de ganancia', 'ganancia', 'Sistema + tienda', '1.675', '94.700'] as $prohibida) {
            $this->assertStringNotContainsString($prohibida, $bloque, "El bloque dice «{$prohibida}».");
        }

        /* Lo de Meta sí sigue en USD (es de Meta, no de la ganancia): se conserva. */
        $this->assertStringContainsString('Plantilla de marketing: USD 0,0618', $bloque);
    }

    /**
     * CA4-bis. Con la cotización en 1700 el bloque dice «Inicial: $47.000 por mes.»; el aviso sale de la
     * fuente; sin descuento de tres juntos no hay línea de descuento.
     *
     * @return void
     */
    public function test_el_bloque_sigue_a_la_fuente(): void
    {
        $this->admin_autenticado();

        $cuerpo = PreciosVigentesSettingsTest::contrato_por_defecto();
        $cuerpo['agentes']['cotizacion_usd']['valor']     = 1700;
        $cuerpo['agentes']['aviso_consumo_porcentaje']    = 90;
        $cuerpo['agentes']['paquetes'][1]['tokens_usd']   = 25;

        $this->putJson(PreciosVigentesSettingsTest::RUTA, $cuerpo)->assertStatus(200);

        $bloque = PreciosVigentesSettings::bloque_para_el_agente();

        $this->assertStringContainsString('Inicial: $47.000 por mes.', $bloque);
        $this->assertStringContainsString('Pro: $72.500 por mes (2,5 veces los tokens de Inicial).', $bloque);
        $this->assertStringContainsString('Al llegar al 90 % de los tokens del mes', $bloque);

        $cuerpo['implementaciones']['tres_juntos']['descuento_porcentaje'] = 0;
        $this->putJson(PreciosVigentesSettingsTest::RUTA, $cuerpo)->assertStatus(200);

        $bloque = PreciosVigentesSettings::bloque_para_el_agente();

        $this->assertStringNotContainsString('Los tres juntos', $bloque);
        $this->assertStringContainsString('No hay combos: cada producto se suma.', $bloque);
    }

    /**
     * Le saca los sellos del servidor al contrato.
     *
     * @param array<string, mixed> $contrato Respuesta.
     *
     * @return array<string, mixed>
     */
    private function sin_sellos(array $contrato): array
    {
        unset($contrato['actualizado_en'], $contrato['actualizado_por']);

        return $contrato;
    }
}
