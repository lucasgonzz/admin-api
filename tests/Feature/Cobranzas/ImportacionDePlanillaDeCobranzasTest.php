<?php

namespace Tests\Feature\Cobranzas;

use App\Models\Client;
use App\Models\LicenciaCuota;
use App\Models\MensualidadPago;
use App\Models\MensualidadPeriodo;

/**
 * El importador de la planilla de administración, `cobranzas:importar-planilla` (misión
 * modulo-cobranzas, 18/9/2026).
 *
 * Lo que estas pruebas protegen, en orden de importancia:
 *
 *  1. 🔴 **Idempotencia.** El comando corre en producción por el deploy y puede volver a correr en
 *     el siguiente: la segunda pasada no puede duplicar un mes, una cuota ni un cliente.
 *  2. **Que lo cargado a mano en el admin gane a la planilla** (precios, datos fiscales, inicio).
 *  3. **Que el parcial quede como parcial**: esperado + un pago importado por la diferencia, para
 *     que el saldo de la pantalla sea el "FALTAN $4.000" de la planilla.
 *  4. **Que `--simular` no escriba.**
 *
 * 🔴 Usa un JSON fixture propio y chico, escrito en el temp del sistema. NUNCA el JSON real de
 * producción (`database/seeders/data/cobranzas_historial.json`): tiene ids de clientes reales
 * y crearía a Kas, Jorge Bello y Scrap Free en la base de testing.
 */
class ImportacionDePlanillaDeCobranzasTest extends BaseDeCobranzas
{
    /**
     * Archivos temporales a barrer al terminar.
     *
     * @var array<int, string>
     */
    private $archivos_temporales = [];

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->archivos_temporales as $ruta) {
            if (is_file($ruta)) {
                @unlink($ruta);
            }
        }
        $this->archivos_temporales = [];

        parent::tearDown();
    }

    /**
     * Escribe un JSON con la forma de la planilla y devuelve su ruta absoluta.
     *
     * @param array<int, array<string, mixed>> $clientes
     * @param array<int, string>               $avisos
     *
     * @return string
     */
    private function escribir_fixture(array $clientes, array $avisos = []): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'cobranzas_fixture_') . '.json';
        $this->archivos_temporales[] = substr($ruta, 0, -5);
        $this->archivos_temporales[] = $ruta;

        file_put_contents($ruta, json_encode([
            '_origen'  => 'fixture de prueba',
            'clientes' => $clientes,
            'avisos'   => $avisos,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $ruta;
    }

    /**
     * La fila de un cliente existente de la planilla: sin precios en el admin, con un mes pagado,
     * uno parcial con saldo, uno sin cargo, una cuota de licencia y una nota suelta.
     *
     * @param int $client_id
     *
     * @return array<string, mixed>
     */
    private function fila_existente(int $client_id): array
    {
        return [
            'planilla'                    => 'Comercio Existente',
            'client_id'                   => $client_id,
            'crear'                       => null,
            'afip_cuit'                   => '20111111112',
            'afip_razon_social'           => 'EXISTENTE S.A.',
            'afip_condicion_iva'          => 'Monotributista',
            'monto_mensual_planilla'      => 36000.0,
            'cantidad_empleados_planilla' => 3,
            'tiene_ecommerce_planilla'    => true,
            'mensualidad_inicio'          => '2026-01-01',
            'observaciones'               => ['2026-01: "NUEVO" en la planilla'],
            'mensualidad_periodos'        => [
                ['periodo' => '2026-01', 'estado' => 'pagado', 'observacion' => null],
                ['periodo' => '2026-02', 'estado' => 'parcial', 'observacion' => 'FALTAN $4.000 (según planilla)', 'faltan' => 4000.0],
                ['periodo' => '2026-03', 'estado' => 'sin_cargo', 'observacion' => null],
            ],
            'licencia_cuotas'             => [
                ['numero' => 1, 'periodo' => '2026-02', 'monto' => 1400.0, 'moneda' => 'USD', 'estado' => 'pagada', 'observacion' => null],
            ],
        ];
    }

    /**
     * La fila de un cliente a crear.
     *
     * @return array<string, mixed>
     */
    private function fila_a_crear(): array
    {
        return [
            'planilla'                    => 'Importado XYZ',
            'client_id'                   => null,
            'crear'                       => [
                'clave'        => 'cliente-importado-prueba-xyz',
                'company_name' => 'Cliente Importado Prueba XYZ',
                'name'         => 'Fulano Importado',
                'nota'         => 'No usa el sistema, solo se le cobra.',
            ],
            'afip_cuit'                   => '30222222223',
            'afip_razon_social'           => null,
            'afip_condicion_iva'          => 'Responsable inscripto',
            'monto_mensual_planilla'      => 75000.0,
            'cantidad_empleados_planilla' => 19,
            'tiene_ecommerce_planilla'    => false,
            'mensualidad_inicio'          => '2025-08-01',
            'observaciones'               => [],
            'mensualidad_periodos'        => [
                ['periodo' => '2026-08', 'estado' => 'pagado', 'observacion' => null],
                ['periodo' => '2026-09', 'estado' => 'pendiente', 'observacion' => 'Sin marca en la planilla'],
            ],
            'licencia_cuotas'             => [],
        ];
    }

    /**
     * 1 a 3. La importación escribe lo que dice la planilla donde el admin no tiene nada, y la
     * segunda corrida no duplica.
     *
     * @return void
     */
    public function test_importa_y_la_segunda_corrida_no_duplica(): void
    {
        // Cliente existente: sin precios, sin CUIT, sin inicio, pero con empleados ya cargados a mano.
        $existente = $this->crear_cliente([
            'precio_plan'        => null,
            'precio_por_cuenta'  => null,
            'total_mensualidad'  => null,
            'cantidad_empleados' => 5,
            'mensualidad_inicio' => null,
        ]);

        $ruta = $this->escribir_fixture([
            $this->fila_existente($existente->id),
            $this->fila_a_crear(),
            ['planilla' => 'Fantasma', 'client_id' => 999999999, 'crear' => null, 'mensualidad_periodos' => [], 'licencia_cuotas' => []],
        ], ['MENSUALIDADES fila 99 "Alguien": omitido por decisión de Lucas']);

        $this->artisan('cobranzas:importar-planilla', ['--archivo' => $ruta])->assertExitCode(0);

        // --- El existente ---
        $existente->refresh();

        $this->assertSame('20111111112', $existente->afip_cuit);
        $this->assertSame('EXISTENTE S.A.', $existente->afip_razon_social);
        $this->assertEqualsWithDelta(36000.0, (float) $existente->precio_plan, 0.001, 'Sin precios en el admin, el monto de la planilla va entero al plan.');
        $this->assertEqualsWithDelta(0.0, (float) $existente->precio_por_cuenta, 0.001);
        $this->assertSame(5, (int) $existente->cantidad_empleados, 'Los empleados cargados a mano ganan a la planilla.');
        $this->assertTrue((bool) $existente->tiene_ecommerce);
        // Total: plan 36.000 + 5 empleados × 0 + ecommerce a precio_por_cuenta (0) = 36.000.
        $this->assertEqualsWithDelta(36000.0, (float) $existente->total_mensualidad, 0.001);
        $this->assertSame('2026-01-01', $existente->mensualidad_inicio->toDateString());
        $this->assertStringContainsString('"NUEVO" en la planilla', (string) $existente->cobranzas_observaciones);

        $periodos = MensualidadPeriodo::where('client_id', $existente->id)->orderBy('periodo')->get()->keyBy('periodo');
        $this->assertCount(3, $periodos);
        $this->assertSame('pagado', $periodos['2026-01']->estado);
        $this->assertNull($periodos['2026-01']->monto_esperado, 'Los PAGADO van sin monto.');
        $this->assertTrue($periodos['2026-01']->importado);
        $this->assertSame('parcial', $periodos['2026-02']->estado);
        $this->assertEqualsWithDelta(36000.0, (float) $periodos['2026-02']->monto_esperado, 0.001);
        $this->assertSame('sin_cargo', $periodos['2026-03']->estado);

        $pagos = MensualidadPago::where('client_id', $existente->id)->get();
        $this->assertCount(1, $pagos, 'Solo el parcial deja un pago importado.');
        $this->assertSame('2026-02', $pagos[0]->periodo);
        $this->assertEqualsWithDelta(32000.0, (float) $pagos[0]->monto, 0.001, 'Entró el esperado menos lo que falta.');
        $this->assertTrue($pagos[0]->importado);

        $cuotas = LicenciaCuota::where('client_id', $existente->id)->get();
        $this->assertCount(1, $cuotas);
        $this->assertSame('pagada', $cuotas[0]->estado);
        $this->assertSame('2026-02-01', $cuotas[0]->vencimiento->toDateString());
        $this->assertTrue($cuotas[0]->importado);

        // Y la pantalla lo lee como corresponde: febrero parcial con saldo 4.000.
        $this->admin_logueado();
        $fila = collect($this->getJson('/api/admin/cobranzas/mensualidades?meses=2026-02')->json('clientes'))->firstWhere('id', $existente->id);
        $this->assertSame('parcial', $fila['meses']['2026-02']['estado']);
        $this->assertEqualsWithDelta(4000.0, $fila['meses']['2026-02']['saldo'], 0.001);

        // --- El creado ---
        $creado = Client::where('company_name', 'Cliente Importado Prueba XYZ')->first();
        $this->assertNotNull($creado);
        $this->assertSame('Fulano Importado', $creado->name);
        $this->assertSame('cliente-importado-prueba-xyz', $creado->slug);
        $this->assertTrue((bool) $creado->is_active);
        $this->assertSame(40, strlen((string) $creado->api_key));
        $this->assertSame(40, strlen((string) $creado->inbound_api_key));
        $this->assertNull($creado->user_id, 'Sin sistema instalado: sin bloque de user_id.');
        $this->assertSame(0, $creado->client_apis()->count());
        $this->assertSame('No usa el sistema, solo se le cobra.', $creado->cobranzas_observaciones);
        $this->assertSame('30222222223', $creado->afip_cuit);
        $this->assertEqualsWithDelta(75000.0, (float) $creado->precio_plan, 0.001);
        $this->assertSame(19, (int) $creado->cantidad_empleados);
        $this->assertSame('2025-08-01', $creado->mensualidad_inicio->toDateString());
        $this->assertSame(2, MensualidadPeriodo::where('client_id', $creado->id)->count());

        // --- Segunda corrida: nada se duplica, y lo editado a mano entre corridas no se pisa ---
        $existente->precio_plan = 40000;
        $existente->afip_cuit = '20999999990';
        $existente->save();
        $creado->company_name = 'Cliente Importado Prueba XYZ renombrado';
        $creado->save();

        $this->artisan('cobranzas:importar-planilla', ['--archivo' => $ruta])->assertExitCode(0);

        $this->assertSame(3, MensualidadPeriodo::where('client_id', $existente->id)->count());
        $this->assertSame(1, MensualidadPago::where('client_id', $existente->id)->count());
        $this->assertSame(1, LicenciaCuota::where('client_id', $existente->id)->count());
        $this->assertSame(1, Client::where('slug', 'like', 'cliente-importado-prueba-xyz%')->count(), 'Renombrado y todo, lo encuentra por el slug: no crea otro.');
        $this->assertSame(2, MensualidadPeriodo::where('client_id', $creado->id)->count());

        $existente->refresh();
        $this->assertEqualsWithDelta(40000.0, (float) $existente->precio_plan, 0.001);
        $this->assertSame('20999999990', $existente->afip_cuit);
        $this->assertSame(1, substr_count((string) $existente->cobranzas_observaciones, 'NUEVO'), 'La observación no se repite.');
    }

    /**
     * 4. Con --simular, el resumen se imprime y nada queda escrito.
     *
     * @return void
     */
    public function test_simular_no_escribe(): void
    {
        $existente = $this->crear_cliente([
            'precio_plan'        => null,
            'precio_por_cuenta'  => null,
            'total_mensualidad'  => null,
            'mensualidad_inicio' => null,
        ]);

        $ruta = $this->escribir_fixture([
            $this->fila_existente($existente->id),
            $this->fila_a_crear(),
        ]);

        $this->artisan('cobranzas:importar-planilla', ['--archivo' => $ruta, '--simular' => true])
            ->expectsOutput('[SIMULACIÓN] Importando 2 clientes de la planilla...')
            ->assertExitCode(0);

        $existente->refresh();
        $this->assertNull($existente->precio_plan);
        $this->assertNull($existente->afip_cuit);
        $this->assertNull($existente->mensualidad_inicio);
        $this->assertSame(0, MensualidadPeriodo::where('client_id', $existente->id)->count());
        $this->assertSame(0, LicenciaCuota::where('client_id', $existente->id)->count());
        $this->assertNull(Client::where('company_name', 'Cliente Importado Prueba XYZ')->first());
    }

    /**
     * Un archivo que no existe o que no es la planilla corta con error, sin escribir.
     *
     * @return void
     */
    public function test_un_archivo_invalido_corta_con_error(): void
    {
        $this->artisan('cobranzas:importar-planilla', ['--archivo' => sys_get_temp_dir() . '/no-existe-' . uniqid() . '.json'])
            ->assertExitCode(1);

        $ruta = tempnam(sys_get_temp_dir(), 'cobranzas_basura_') . '.json';
        $this->archivos_temporales[] = substr($ruta, 0, -5);
        $this->archivos_temporales[] = $ruta;
        file_put_contents($ruta, '{"otra_cosa": true}');

        $this->artisan('cobranzas:importar-planilla', ['--archivo' => $ruta])->assertExitCode(1);
    }
}
