<?php

namespace Tests\Feature\PreciosVigentes;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Lead;
use App\Services\LeadAiService;
use App\Services\LeadOfertaDePruebaService;
use App\Services\PreciosVigentesSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Hallazgos de la revisión de la etapa E1 (misión embudo-whatsapp-precios).
 *
 * Archivo NUEVO: no toca los tests de aceptación de la carpeta. Custodia:
 *  1. Contenido hostil en `admin_settings.precios_vigentes` (un `nombre` que es arreglo, un número que es
 *     arreglo, una lista que es texto...): la lectura cae a los defaults sin excepción, el endpoint público
 *     responde 200, el contexto de Martín se arma y el PUT con esa entrada da 422 sin guardar. Los tests
 *     corren con el manejo de errores real de Laravel, que convierte un aviso «Array to string conversion»
 *     en `ErrorException`.
 *  2. La implementación asistida es OPCIONAL: el bloque lo dice y el mensaje por defecto de la oferta ya
 *     no promete que es gratis.
 *  3. Una sola forma de redondear y formatear dinero y porcentajes para el bloque y para la oferta.
 *  4. Una lista mandada como objeto suelto da 422 (antes se ignoraba con 200).
 *  5. La notación de las claves de `errors` del 422 (por `clave`) queda fijada.
 */
class PreciosVigentesRevisionE1Test extends TestCase
{
    use DatabaseTransactions;

    /** Ruta pública de la landing. */
    private const RUTA_PUBLICA = '/api/landing/precios';

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

    /* ==================================================================================
     * 1. Contenido hostil en la base
     * ================================================================================== */

    /**
     * Casos de contenido que nadie debería poder guardar, pero que un JSON editado a mano en la base sí
     * puede traer. Cada uno recibe el contrato por defecto y lo deforma.
     *
     * @return array<string, array<int, callable>>
     */
    public static function contenidos_hostiles(): array
    {
        return [
            'nombre de un plan como arreglo' => [function (array $c) {
                $c['gestion']['planes'][0]['nombre'] = ['x' => 'y'];

                return $c;
            }],
            'nombre de un plan como objeto vacío' => [function (array $c) {
                $c['gestion']['planes'][1]['nombre'] = [];

                return $c;
            }],
            'nombre de un ítem de implementación como arreglo' => [function (array $c) {
                $c['implementaciones']['items'][0]['nombre'] = ['Sistema'];

                return $c;
            }],
            'nombre de una tarifa de Meta como arreglo' => [function (array $c) {
                $c['costos_meta']['tarifas'][0]['nombre'] = ['marketing'];

                return $c;
            }],
            'abono de un plan como arreglo' => [function (array $c) {
                $c['gestion']['planes'][0]['abono'] = [55900];

                return $c;
            }],
            'hasta de un plan como arreglo (rangos)' => [function (array $c) {
                $c['gestion']['planes'][0]['hasta'] = [200];

                return $c;
            }],
            'precio de un ítem como arreglo' => [function (array $c) {
                $c['implementaciones']['items'][2]['precio'] = ['a' => 1];

                return $c;
            }],
            'usd de una tarifa como arreglo' => [function (array $c) {
                $c['costos_meta']['tarifas'][1]['usd'] = [0.026];

                return $c;
            }],
            'porcentaje del descuento como arreglo' => [function (array $c) {
                $c['gestion']['descuento_bienvenida']['porcentaje'] = [50];

                return $c;
            }],
            'planes como texto' => [function (array $c) {
                $c['gestion']['planes'] = 'inicial';

                return $c;
            }],
            'items de implementación como número' => [function (array $c) {
                $c['implementaciones']['items'] = 628400;

                return $c;
            }],
            'tarifas como texto' => [function (array $c) {
                $c['costos_meta']['tarifas'] = 'todas';

                return $c;
            }],
            'descuento_bienvenida como texto' => [function (array $c) {
                $c['gestion']['descuento_bienvenida'] = 'cincuenta';

                return $c;
            }],
            'reembolsable como arreglo' => [function (array $c) {
                $c['implementaciones']['reembolsable'] = [true];

                return $c;
            }],
            'vigente_desde como arreglo' => [function (array $c) {
                $c['costos_meta']['vigente_desde'] = ['2026-10-01'];

                return $c;
            }],
        ];
    }

    /**
     * La lectura con contenido hostil guardado devuelve los defaults, sin excepción, y deja un warning.
     *
     * @dataProvider contenidos_hostiles
     *
     * @param callable $deformar Deforma el contrato por defecto.
     *
     * @return void
     */
    public function test_la_lectura_con_contenido_hostil_cae_a_los_defaults(callable $deformar): void
    {
        $this->guardar_crudo($deformar(PreciosVigentesSettingsTest::contrato_por_defecto()));
        Log::spy();

        $leido = PreciosVigentesSettings::to_array();

        $this->assertEquals(PreciosVigentesSettingsTest::contrato_por_defecto(), $this->sin_sellos($leido));
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    /**
     * El endpoint público responde 200 con los defaults aunque la base traiga contenido hostil.
     *
     * @dataProvider contenidos_hostiles
     *
     * @param callable $deformar Deforma el contrato por defecto.
     *
     * @return void
     */
    public function test_el_endpoint_publico_con_contenido_hostil_da_200(callable $deformar): void
    {
        $this->guardar_crudo($deformar(PreciosVigentesSettingsTest::contrato_por_defecto()));

        $respuesta = $this->getJson(self::RUTA_PUBLICA);

        $respuesta->assertStatus(200);
        $this->assertEquals(PreciosVigentesSettingsTest::contrato_por_defecto(), $this->sin_sellos($respuesta->json()));
    }

    /**
     * El contexto de Martín se arma, con el bloque de los precios por defecto, y el «desde» de la oferta
     * también.
     *
     * @dataProvider contenidos_hostiles
     *
     * @param callable $deformar Deforma el contrato por defecto.
     *
     * @return void
     */
    public function test_el_contexto_de_martin_con_contenido_hostil_se_arma(callable $deformar): void
    {
        $this->guardar_crudo($deformar(PreciosVigentesSettingsTest::contrato_por_defecto()));

        $contexto = $this->contexto_del_turno($this->crear_lead());

        $this->assertStringContainsString('PRECIOS VIGENTES', $contexto);
        $this->assertStringContainsString('Inicial: hasta 200 operaciones por mes, 1 usuario: $55.900', $contexto);
        $this->assertSame('$27.950', LeadOfertaDePruebaService::precio_desde());
        $this->assertStringContainsString('50 % off', LeadOfertaDePruebaService::promo());
    }

    /**
     * El PUT con esa entrada da 422 (no 500) y no guarda nada.
     *
     * @dataProvider contenidos_hostiles
     *
     * @param callable $deformar Deforma el contrato por defecto.
     *
     * @return void
     */
    public function test_el_put_con_contenido_hostil_da_422_y_no_guarda(callable $deformar): void
    {
        $this->admin_autenticado();

        $respuesta = $this->putJson(PreciosVigentesSettingsTest::RUTA, $deformar(PreciosVigentesSettingsTest::contrato_por_defecto()));

        $respuesta->assertStatus(422);
        $respuesta->assertJsonStructure(['message', 'errors']);
        $this->assertNull(
            AdminSetting::query()->where('key', PreciosVigentesSettingsTest::CLAVE)->first(),
            'El PUT inválido guardó algo.'
        );
    }

    /**
     * Red de seguridad: si leer la fuente tira CUALQUIER excepción (acá, una que no tiene que ver con el
     * contenido), la lectura devuelve los defaults, loguea un warning y no tira abajo ni el bloque de
     * Martín ni el endpoint público.
     *
     * @return void
     */
    public function test_cualquier_excepcion_al_leer_cae_a_los_defaults(): void
    {
        $this->guardar_crudo(PreciosVigentesSettingsTest::contrato_por_defecto());
        Log::spy();

        $evento = 'eloquent.retrieved: ' . AdminSetting::class;
        AdminSetting::getEventDispatcher()->listen($evento, function () {
            throw new \RuntimeException('falla simulada al leer la base');
        });

        try {
            $bloque  = PreciosVigentesSettings::bloque_para_el_agente();
            $publico = PreciosVigentesSettings::to_array_publico();
            $desde   = LeadOfertaDePruebaService::precio_desde();
        } finally {
            /* Se saca sólo este oyente: el modelo registra los suyos al arrancar y no se pueden perder. */
            AdminSetting::getEventDispatcher()->forget($evento);
        }

        $this->assertStringContainsString('Inicial: hasta 200 operaciones por mes, 1 usuario: $55.900', $bloque);
        $this->assertEquals(PreciosVigentesSettingsTest::contrato_por_defecto(), $this->sin_sellos($publico));
        $this->assertSame('$27.950', $desde);
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    /* ==================================================================================
     * 2. La implementación asistida es opcional
     * ================================================================================== */

    /**
     * El bloque de Martín dice, en una línea fija, que la implementación asistida es opcional y qué
     * recibe quien la paga.
     *
     * @return void
     */
    public function test_el_bloque_dice_que_la_implementacion_es_opcional(): void
    {
        $bloque = PreciosVigentesSettings::bloque_para_el_agente();

        $this->assertStringContainsString('La implementación asistida es OPCIONAL', $bloque);
        $this->assertStringContainsString('por su cuenta', $bloque);
        $this->assertStringContainsString('video', $bloque);
        $this->assertStringContainsString('desarrollos a medida de por vida', $bloque);
        $this->assertStringContainsString('con la mitad', $bloque);
    }

    /**
     * El mensaje por defecto de la oferta ya no promete que la implementación es gratis, y conserva sus
     * placeholders.
     *
     * @return void
     */
    public function test_el_mensaje_por_defecto_ya_no_dice_que_la_implementacion_es_gratis(): void
    {
        $mensaje = LeadOfertaDePruebaService::DEFAULT_MENSAJE_1;

        $this->assertStringNotContainsString('la implementación son gratis', $mensaje);
        $this->assertStringNotContainsString('te cargamos tus productos nosotros y pagás solo', $mensaje);
        $this->assertStringContainsString('{precio_desde}', $mensaje);
        $this->assertStringContainsString('{promo}', $mensaje);
        $this->assertStringContainsString('{dias_prueba}', $mensaje);
    }

    /* ==================================================================================
     * 3. Una sola forma de redondear y formatear
     * ================================================================================== */

    /**
     * Con un abono impar la oferta y el bloque muestran el MISMO monto con descuento.
     *
     * @return void
     */
    public function test_la_oferta_y_el_bloque_muestran_el_mismo_monto(): void
    {
        $this->guardar_crudo(array_replace_recursive(PreciosVigentesSettingsTest::contrato_por_defecto(), [
            'gestion' => ['planes' => [0 => ['abono' => 55901]]],
        ]));

        $desde = LeadOfertaDePruebaService::precio_desde();

        $this->assertSame('$27.950,50', $desde);
        $this->assertStringContainsString('(' . $desde . ' los primeros 3 meses)', PreciosVigentesSettings::bloque_para_el_agente());
    }

    /**
     * Con un porcentaje con decimales la oferta y el bloque muestran el MISMO porcentaje, tal cual está
     * guardado.
     *
     * @return void
     */
    public function test_la_oferta_y_el_bloque_muestran_el_mismo_porcentaje(): void
    {
        $this->guardar_crudo(array_replace_recursive(PreciosVigentesSettingsTest::contrato_por_defecto(), [
            'gestion' => ['descuento_bienvenida' => ['porcentaje' => 12.5]],
        ]));

        $this->assertStringContainsString('12,5 % off', LeadOfertaDePruebaService::promo());
        $this->assertStringContainsString('12,5 % de descuento', PreciosVigentesSettings::bloque_para_el_agente());
    }

    /* ==================================================================================
     * 4. Listas mandadas como objeto suelto
     * ================================================================================== */

    /**
     * Una lista (planes, ítems, tarifas) mandada como un objeto suelto da 422 y no guarda; antes se
     * ignoraba y la respuesta era 200 sin cambiar nada.
     *
     * @return void
     */
    public function test_una_lista_mandada_como_objeto_suelto_da_422(): void
    {
        $this->admin_autenticado();

        $casos = [
            'planes' => function (array $c) {
                $c['gestion']['planes'] = ['clave' => 'inicial', 'nombre' => 'Inicial', 'abono' => 70000];

                return $c;
            },
            'items' => function (array $c) {
                $c['implementaciones']['items'] = ['clave' => 'gestion', 'nombre' => 'Sistema de gestión', 'precio' => 1];

                return $c;
            },
            'tarifas' => function (array $c) {
                $c['costos_meta']['tarifas'] = ['clave' => 'marketing', 'nombre' => 'x', 'usd' => 1, 'ars' => 1];

                return $c;
            },
        ];

        foreach ($casos as $caso => $deformar) {
            $respuesta = $this->putJson(PreciosVigentesSettingsTest::RUTA, $deformar(PreciosVigentesSettingsTest::contrato_por_defecto()));

            $respuesta->assertStatus(422);
            $this->assertNotEmpty($respuesta->json('errors'), "Sin errores por campo: {$caso}.");
            $this->assertNull(
                AdminSetting::query()->where('key', PreciosVigentesSettingsTest::CLAVE)->first(),
                "El PUT con {$caso} como objeto suelto guardó algo."
            );
        }
    }

    /* ==================================================================================
     * 5. Notación de las claves de `errors`
     * ================================================================================== */

    /**
     * Las claves de `errors` van por `clave` de plan, ítem o tarifa (no por posición): la pantalla del
     * admin-spa se adapta a esta notación.
     *
     * @return void
     */
    public function test_las_claves_de_errors_van_por_clave(): void
    {
        $this->admin_autenticado();

        $casos = [
            'gestion.planes.inicial.usuarios' => function (array $c) {
                $c['gestion']['planes'][0]['usuarios'] = 0;

                return $c;
            },
            'implementaciones.items.gestion.precio' => function (array $c) {
                $c['implementaciones']['items'][0]['precio'] = -5;

                return $c;
            },
            'costos_meta.tarifas.marketing.usd' => function (array $c) {
                $c['costos_meta']['tarifas'][0]['usd'] = 0.12345;

                return $c;
            },
        ];

        foreach ($casos as $clave_esperada => $deformar) {
            $respuesta = $this->putJson(PreciosVigentesSettingsTest::RUTA, $deformar(PreciosVigentesSettingsTest::contrato_por_defecto()));

            $respuesta->assertStatus(422);
            $this->assertSame([$clave_esperada], array_keys($respuesta->json('errors')));
        }
    }

    /* ==================================================================================
     * Helpers
     * ================================================================================== */

    /**
     * Guarda tal cual (sin pasar por la validación) un contrato en `admin_settings`, como si alguien lo
     * hubiera editado a mano en la base.
     *
     * @param array<string, mixed> $contrato Contenido a guardar.
     *
     * @return void
     */
    private function guardar_crudo(array $contrato): void
    {
        AdminSetting::set(PreciosVigentesSettingsTest::CLAVE, json_encode($contrato, JSON_UNESCAPED_UNICODE));
        AdminSetting::flush_memo();
    }

    /**
     * Le saca al contrato los sellos del servidor.
     *
     * @param array<string, mixed> $contrato Contrato leído.
     *
     * @return array<string, mixed>
     */
    private function sin_sellos(array $contrato): array
    {
        unset($contrato['actualizado_en'], $contrato['actualizado_por']);

        return $contrato;
    }

    /**
     * Admin autenticado por Sanctum.
     *
     * @return Admin
     */
    private function admin_autenticado(): Admin
    {
        $admin = Admin::create([
            'name'     => 'Editor de precios',
            'email'    => 'revision-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);

        $this->actingAs($admin, 'sanctum');

        return $admin;
    }

    /**
     * Un lead cualquiera.
     *
     * @return Lead
     */
    private function crear_lead(): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Lead de prueba';
        $lead->company_name = 'Empresa de prueba';
        $lead->phone        = '+54911' . random_int(10000000, 99999999);
        $lead->status       = 'nuevo';
        $lead->save();

        return $lead->refresh();
    }

    /**
     * El contexto que recibe Martín en un turno (`build_user_content` es protegido: se lo llama por una
     * subclase anónima).
     *
     * @param Lead $lead Lead en curso.
     *
     * @return string
     */
    private function contexto_del_turno(Lead $lead): string
    {
        $servicio = new class extends LeadAiService {
            public function contexto(Lead $lead): string
            {
                return $this->build_user_content($lead, false, '', null, false);
            }
        };

        return $servicio->contexto($lead);
    }
}
