<?php

namespace Tests\Feature\PreciosVigentes;

use App\Models\Admin;
use App\Models\AdminSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * La fuente única de precios vigentes y su pantalla de edición (misión embudo-whatsapp-precios,
 * etapa E1, criterios CA1 y CA2 del PLAN).
 *
 * Hoy los precios viven en constantes de `ContractPreciosService` y a mano en el recurso `precios.md`.
 * La etapa E1 los pasa a UNA sola clave de `admin_settings` (`precios_vigentes`, un JSON con el contrato
 * de §2.6 del PLAN), editable por `GET` / `PUT /api/admin/settings/precios-vigentes`.
 *
 * Lo que se custodia acá:
 *  - CA1: con la base vacía el GET devuelve exactamente los valores del pedido de Lucas.
 *  - CA2: el PUT guarda y sella la fecha; los valores inválidos dan 422 legible y NO guardan; un JSON
 *    roto guardado a mano se lee igual, con los defaults.
 *
 * La IA no interviene en este archivo.
 */
class PreciosVigentesSettingsTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de `admin_settings` donde vive la fuente (PLAN §2.1). */
    public const CLAVE = 'precios_vigentes';

    /** Ruta autenticada de la fuente (PLAN §2.1). */
    public const RUTA = '/api/admin/settings/precios-vigentes';

    /**
     * Deja la base sin la clave: la base de testing puede traer un valor guardado.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        AdminSetting::query()->where('key', self::CLAVE)->delete();
    }

    /**
     * El contrato de §2.6 con los valores del pedido de Lucas, SIN `actualizado_en` ni
     * `actualizado_por` (los sella el servidor). Es lo que devuelve el GET con la base vacía y el
     * cuerpo que acepta el PUT. Lo reutilizan los otros dos archivos de la carpeta.
     *
     * @return array<string, mixed>
     */
    public static function contrato_por_defecto(): array
    {
        return [
            'version' => 2,
            'moneda'  => 'ARS',
            'gestion' => [
                'planes' => [
                    ['clave' => 'inicial', 'nombre' => 'Inicial', 'desde' => 0, 'hasta' => 200, 'usuarios' => 1, 'abono' => 55900, 'excedente' => 760],
                    ['clave' => 'intermedio', 'nombre' => 'Intermedio', 'desde' => 201, 'hasta' => 600, 'usuarios' => 2, 'abono' => 85400, 'excedente' => 570],
                    ['clave' => 'empresa', 'nombre' => 'Empresa', 'desde' => 601, 'hasta' => null, 'usuarios' => 3, 'abono' => 263000, 'excedente' => null],
                ],
                'usuario_extra'        => 30400,
                'descuento_bienvenida' => ['porcentaje' => 50, 'meses' => 3],
            ],
            'tienda'  => ['mensualidad' => 44900],
            'agentes' => [
                'incluye'                  => 'Agente de WhatsApp con la migración o configuración inicial del número',
                'uso_de_tokens'            => 'Hacerle preguntas y pedirle cosas a los agentes, y generar imágenes para el negocio',
                'cotizacion_usd'           => ['valor' => 1675, 'referencia' => 'Dólar blue, venta', 'fecha' => '2026-11-20'],
                'ganancia_por_paquete'     => 30000,
                'aviso_consumo_porcentaje' => 80,
                'paquetes'                 => [
                    ['clave' => 'inicial', 'nombre' => 'Inicial', 'tokens_usd' => 10, 'mensualidad' => 46750],
                    ['clave' => 'pro', 'nombre' => 'Pro', 'tokens_usd' => 30, 'mensualidad' => 80250],
                    ['clave' => 'max', 'nombre' => 'Max', 'tokens_usd' => 70, 'mensualidad' => 147250],
                ],
            ],
            'implementaciones' => [
                'anticipo_porcentaje' => 50,
                'reembolsable'        => false,
                'items'               => [
                    ['clave' => 'gestion', 'nombre' => 'Sistema de gestión', 'precio' => 628400],
                    ['clave' => 'tienda', 'nombre' => 'Tienda online', 'precio' => 628400],
                    ['clave' => 'agentes', 'nombre' => 'Agentes', 'precio' => 628400],
                ],
                'tres_juntos'         => ['descuento_porcentaje' => 30, 'precio_lista' => 1885200, 'precio' => 1319640],
            ],
            'costos_meta' => [
                'vigente_desde'                  => '2026-10-01',
                'mensajes_libres_gratis_por_mes' => 1000,
                'tarifas'                        => [
                    ['clave' => 'marketing', 'nombre' => 'Plantilla de marketing', 'usd' => 0.0618, 'ars' => 89.56],
                    ['clave' => 'utilidad', 'nombre' => 'Plantilla de utilidad', 'usd' => 0.026, 'ars' => 37.68],
                    ['clave' => 'autenticacion', 'nombre' => 'Plantilla de autenticación', 'usd' => 0.026, 'ars' => 37.68],
                    ['clave' => 'mensaje_libre', 'nombre' => 'Mensaje libre dentro de las 24 h', 'usd' => 0.026, 'ars' => 37.68],
                ],
            ],
        ];
    }

    /**
     * Admin autenticado por Sanctum, como en el resto de los tests del admin.
     *
     * @return Admin
     */
    private function admin_autenticado(): Admin
    {
        $admin = Admin::create([
            'name'     => 'Editor de precios',
            'email'    => 'precios-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);

        $this->actingAs($admin, 'sanctum');

        return $admin;
    }

    /**
     * Le saca al contrato los campos que sella el servidor, para compararlo con el de §2.6.
     *
     * @param array<string, mixed> $contrato Respuesta del GET.
     *
     * @return array<string, mixed>
     */
    private function sin_sellos(array $contrato): array
    {
        unset($contrato['actualizado_en'], $contrato['actualizado_por']);

        return $contrato;
    }

    /**
     * CA1. Con la base vacía el GET devuelve exactamente el JSON de §2.6 con los valores del pedido:
     * planes Inicial 0-200 / Intermedio 201-600 / Empresa ilimitado, usuario extra, descuento, tienda,
     * agentes (un solo paquete con tres paquetes de tokens), las tres implementaciones con los tres
     * juntos y las cuatro tarifas de Meta.
     *
     * @return void
     */
    public function test_sin_guardar_devuelve_los_precios_del_pedido(): void
    {
        $this->admin_autenticado();

        $respuesta = $this->getJson(self::RUTA);

        $respuesta->assertStatus(200);

        $contrato = $respuesta->json();

        $this->assertEquals(self::contrato_por_defecto(), $this->sin_sellos($contrato));

        /* Los valores que el pedido nombra uno por uno (CA1), con tipo estricto donde importa. */
        $planes = $contrato['gestion']['planes'];
        $this->assertSame('inicial', $planes[0]['clave']);
        $this->assertSame(0, $planes[0]['desde']);
        $this->assertSame(200, $planes[0]['hasta']);
        $this->assertSame(1, $planes[0]['usuarios']);
        $this->assertSame(55900, $planes[0]['abono']);
        $this->assertSame(760, $planes[0]['excedente']);
        $this->assertNull($planes[2]['hasta'], 'Empresa es ilimitado: su `hasta` es null.');
        $this->assertNull($planes[2]['excedente'], 'Empresa no tiene excedente.');
        $this->assertSame(30400, $contrato['gestion']['usuario_extra']);
        $this->assertSame(['porcentaje' => 50, 'meses' => 3], $contrato['gestion']['descuento_bienvenida']);
        $this->assertSame(44900, $contrato['tienda']['mensualidad']);
        $this->assertSame(2, $contrato['version']);
        $this->assertArrayNotHasKey('lite', $contrato['agentes']);
        $this->assertArrayNotHasKey('full', $contrato['agentes']);
        $this->assertSame(46750, $contrato['agentes']['paquetes'][0]['mensualidad']);
        $this->assertCount(3, $contrato['implementaciones']['items']);
        $this->assertCount(4, $contrato['costos_meta']['tarifas']);
        $this->assertSame(1000, $contrato['costos_meta']['mensajes_libres_gratis_por_mes']);
    }

    /**
     * CA2. El PUT con `intermedio.abono = 86000` guarda, lo devuelve en el GET y sella `actualizado_en`
     * (y quién lo editó). Lo guardado queda en la clave `precios_vigentes` de `admin_settings`.
     *
     * @return void
     */
    public function test_guardar_un_precio_lo_persiste_y_sella_la_fecha(): void
    {
        $this->admin_autenticado();

        $cuerpo = self::contrato_por_defecto();
        $cuerpo['gestion']['planes'][1]['abono'] = 86000;

        $guardado = $this->putJson(self::RUTA, $cuerpo);

        $guardado->assertStatus(200);
        $this->assertEquals(86000, $guardado->json('gestion.planes.1.abono'), 'El PUT no devolvió el valor guardado.');

        /* El GET lo devuelve y trae los sellos del servidor. */
        $leido = $this->getJson(self::RUTA);
        $leido->assertStatus(200);

        $this->assertEquals(86000, $leido->json('gestion.planes.1.abono'));
        $this->assertNotEmpty($leido->json('actualizado_en'), 'El PUT no selló `actualizado_en`.');
        $this->assertNotEmpty($leido->json('actualizado_por'), 'El PUT no selló `actualizado_por`.');
        $this->assertEqualsWithDelta(
            now()->timestamp,
            \Carbon\Carbon::parse($leido->json('actualizado_en'))->timestamp,
            120,
            '`actualizado_en` no es de ahora.'
        );

        /* Y quedó en la clave única de admin_settings, con el contrato de §2.6. */
        $fila = AdminSetting::query()->where('key', self::CLAVE)->first();
        $this->assertNotNull($fila, 'No quedó la clave `precios_vigentes` en admin_settings.');

        $almacenado = json_decode((string) $fila->value, true);
        $this->assertIsArray($almacenado, 'Lo guardado en `precios_vigentes` no es un JSON.');
        $this->assertEquals(86000, $almacenado['gestion']['planes'][1]['abono']);
    }

    /**
     * CA2. Cada valor inválido da 422 con un mensaje legible y errores por campo, y NO guarda nada:
     * después del intento la fuente sigue con los defaults y no hay clave en `admin_settings`.
     *
     * Los tres casos del CA: rango que no encadena (Intermedio desde 150 con Inicial hasta 200), monto
     * negativo y USD que no es un número.
     *
     * @return void
     */
    public function test_rangos_encadenados_invalidos_dan_422_y_no_guardan(): void
    {
        $this->admin_autenticado();

        $invalidos = [
            'intermedio.desde = 150 con inicial.hasta = 200' => function (array $c) {
                $c['gestion']['planes'][1]['desde'] = 150;

                return $c;
            },
            'inicial.abono = -1' => function (array $c) {
                $c['gestion']['planes'][0]['abono'] = -1;

                return $c;
            },
            'marketing.usd = "abc"' => function (array $c) {
                $c['costos_meta']['tarifas'][0]['usd'] = 'abc';

                return $c;
            },
        ];

        foreach ($invalidos as $caso => $modificar) {
            $respuesta = $this->putJson(self::RUTA, $modificar(self::contrato_por_defecto()));

            $respuesta->assertStatus(422);
            $respuesta->assertJsonStructure(['message', 'errors']);

            $this->assertIsString($respuesta->json('message'), $caso);
            $this->assertNotSame('', trim((string) $respuesta->json('message')), "Sin mensaje legible: {$caso}.");
            $this->assertNotEmpty($respuesta->json('errors'), "Sin errores por campo: {$caso}.");

            $this->assertNull(
                AdminSetting::query()->where('key', self::CLAVE)->first(),
                "El PUT inválido ({$caso}) guardó algo."
            );
        }

        /* Después de los tres intentos la fuente sigue con los valores del pedido. */
        $leido = $this->getJson(self::RUTA);
        $leido->assertStatus(200);
        $this->assertEquals(self::contrato_por_defecto(), $this->sin_sellos($leido->json()));
    }

    /**
     * CA2. Un JSON roto guardado a mano en la base (o una edición que lo corrompió) no tira el endpoint:
     * se lee con los defaults.
     *
     * @return void
     */
    public function test_un_json_roto_en_la_base_cae_a_los_defaults(): void
    {
        $this->admin_autenticado();

        AdminSetting::set(self::CLAVE, '{"version": 1, "gestion": {"planes": [');

        $respuesta = $this->getJson(self::RUTA);

        $respuesta->assertStatus(200);
        $this->assertEquals(self::contrato_por_defecto(), $this->sin_sellos($respuesta->json()));
    }
}
