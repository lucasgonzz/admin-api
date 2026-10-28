<?php

namespace Tests\Feature\Cobranzas;

use App\Models\Admin;
use App\Models\Client;
use App\Models\LicenciaCuota;
use App\Models\Venta;

/**
 * El comando de despliegue `ventas:migrar-licencias` (misión ventas-y-comisiones, 28/10/2026).
 *
 * Lo que estas pruebas protegen, en orden de importancia:
 *
 *  1. 🔴 **`--simular` no escribe NADA**: ni ventas, ni enlaces de cuotas, ni el flag de vendedor.
 *  2. 🔴 **La comisión solo para los promovidos desde la fecha** (13/10/2026 por defecto): un
 *     cliente promovido antes, o que no salió de un lead, queda sin vendedor ni comisión.
 *  3. **Agrupa bien**: una venta por cliente y moneda, `planilla` si todas sus cuotas son
 *     importadas y `contrato` si no, con todas las cuotas enlazadas.
 *  4. **Es idempotente**: la segunda corrida no crea nada ni cambia ninguna comisión.
 *  5. Marca como vendedor al closer si no había ninguno.
 */
class MigrarLicenciasAVentasTest extends BaseDeVentas
{
    /**
     * Una cuota suelta (sin venta) del cliente.
     *
     * @param Client      $client
     * @param int         $numero
     * @param float       $monto
     * @param string      $moneda
     * @param bool        $importado
     * @param string|null $vencimiento
     * @param string      $periodo
     *
     * @return LicenciaCuota
     */
    private function cuota_suelta(Client $client, int $numero, float $monto, string $moneda, bool $importado, ?string $vencimiento, string $periodo): LicenciaCuota
    {
        return LicenciaCuota::create([
            'client_id'   => $client->id,
            'numero'      => $numero,
            'periodo'     => $periodo,
            'vencimiento' => $vencimiento,
            'monto'       => $monto,
            'moneda'      => $moneda,
            'estado'      => 'pendiente',
            'importado'   => $importado,
        ]);
    }

    /**
     * El recorrido entero: simular, correr de verdad, volver a correr, y ampliar la fecha.
     *
     * @return void
     */
    public function test_agrupa_enlaza_y_asigna_comision_solo_desde_la_fecha(): void
    {
        $closer = $this->crear_admin('Tomás closer');
        $closer->is_closer = true;
        $closer->save();

        // A: promovido el 20/10 desde un lead en Suscripción, con gestión y e-commerce en el contrato.
        $a = $this->crear_cliente([
            'created_at'        => '2026-10-20 12:00:00',
            'contract_sistemas' => ['gestion', 'ecommerce'],
        ]);
        $lead_a = $this->crear_lead_promovido($a, 'suscripcion');
        $this->cuota_suelta($a, 1, 600, 'USD', false, '2026-10-25', '2026-10');
        $this->cuota_suelta($a, 2, 400, 'USD', false, null, '2026-11');
        $this->cuota_suelta($a, 3, 50000, 'ARS', true, null, '2026-10');

        // B: promovido el 1/9 (antes del esquema): su venta queda sin comisión.
        $b = $this->crear_cliente(['created_at' => '2026-09-01 12:00:00']);
        $this->crear_lead_promovido($b);
        $this->cuota_suelta($b, 1, 700, 'USD', true, null, '2026-09');

        // C: alta el 20/10 pero sin lead de origen: sin comisión.
        $c = $this->crear_cliente(['created_at' => '2026-10-20 12:00:00']);
        $this->cuota_suelta($c, 1, 300, 'USD', false, '2026-10-30', '2026-10');

        $clientes = [$a->id, $b->id, $c->id];

        /* 1. --simular: el resumen sale, pero no queda nada escrito. */
        $this->artisan('ventas:migrar-licencias', ['--simular' => true])
            ->expectsOutput('Resumen de la SIMULACIÓN (no se escribió nada):')
            ->assertExitCode(0);

        $this->assertSame(0, Venta::whereIn('client_id', $clientes)->count(), '--simular no crea ventas.');
        $this->assertSame(5, LicenciaCuota::whereIn('client_id', $clientes)->whereNull('venta_id')->count(), '--simular no enlaza cuotas.');
        $this->assertFalse((bool) $closer->fresh()->es_vendedor, '--simular no marca vendedores.');

        /* 2. De verdad. */
        $this->artisan('ventas:migrar-licencias')->assertExitCode(0);

        $this->assertTrue((bool) $closer->fresh()->es_vendedor, 'Sin vendedores, el closer pasa a ser vendedor.');
        $this->assertSame(0, LicenciaCuota::whereIn('client_id', $clientes)->whereNull('venta_id')->count(), 'Todas las cuotas quedan enlazadas.');

        // A: dos ventas, una por moneda, las dos con comisión (promovido después del 13/10).
        $a_usd = Venta::where('client_id', $a->id)->where('moneda', 'USD')->firstOrFail();
        $this->assertSame('contrato', $a_usd->origen);
        $this->assertSame($lead_a->id, $a_usd->lead_id);
        $this->assertSame(['gestion', 'ecommerce'], $a_usd->productos);
        $this->assertEqualsWithDelta(1000.0, (float) $a_usd->monto_total, 0.001);
        $this->assertSame('2026-10-25', $a_usd->fecha->toDateString(), 'La fecha es la del vencimiento más viejo (25/10 contra noviembre).');
        $this->assertSame($closer->id, $a_usd->vendedor_admin_id);
        $this->assertEqualsWithDelta(30.0, (float) $a_usd->comision_porcentaje, 0.001, 'Gestión en Suscripción (por el lead): 30 %.');
        $this->assertEqualsWithDelta(300.0, (float) $a_usd->comision_monto, 0.001);
        $this->assertSame(2, LicenciaCuota::where('venta_id', $a_usd->id)->count());

        $a_ars = Venta::where('client_id', $a->id)->where('moneda', 'ARS')->firstOrFail();
        $this->assertSame('planilla', $a_ars->origen, 'Todas sus cuotas son importadas: planilla.');
        $this->assertSame('2026-10-01', $a_ars->fecha->toDateString(), 'Sin vencimiento, el primer día del mes de la cuota.');
        $this->assertEqualsWithDelta(50000.0, (float) $a_ars->monto_total, 0.001);
        $this->assertSame($closer->id, $a_ars->vendedor_admin_id);
        $this->assertEqualsWithDelta(15000.0, (float) $a_ars->comision_monto, 0.001, 'La comisión va en la moneda de la venta.');

        // B: promovido antes de la fecha → sin vendedor ni comisión.
        $venta_b = Venta::where('client_id', $b->id)->firstOrFail();
        $this->assertSame('planilla', $venta_b->origen);
        $this->assertSame(['gestion'], $venta_b->productos, 'Sin sistemas en el contrato, gestión.');
        $this->assertNull($venta_b->vendedor_admin_id);
        $this->assertNull($venta_b->comision_porcentaje);
        $this->assertNull($venta_b->comision_monto);

        // C: sin lead de origen → sin comisión, aunque sea nuevo.
        $venta_c = Venta::where('client_id', $c->id)->firstOrFail();
        $this->assertSame('contrato', $venta_c->origen);
        $this->assertNull($venta_c->vendedor_admin_id);
        $this->assertNull($venta_c->comision_monto);

        /* 3. Idempotente: la segunda corrida no crea ni cambia nada. */
        $antes = Venta::whereIn('client_id', $clientes)->orderBy('id')->get(['id', 'monto_total', 'vendedor_admin_id', 'comision_monto'])->toArray();

        $this->artisan('ventas:migrar-licencias')->assertExitCode(0);

        $despues = Venta::whereIn('client_id', $clientes)->orderBy('id')->get(['id', 'monto_total', 'vendedor_admin_id', 'comision_monto'])->toArray();
        $this->assertSame($antes, $despues, 'La segunda corrida no cambia nada.');
        $this->assertSame(4, Venta::whereIn('client_id', $clientes)->count());

        /* 4. --desde más viejo: ahora B (promovido el 1/9) también entra. */
        $this->artisan('ventas:migrar-licencias', ['--desde' => '2026-08-01'])->assertExitCode(0);
        $venta_b->refresh();
        $this->assertSame($closer->id, $venta_b->vendedor_admin_id);
        $this->assertEqualsWithDelta(105.0, (float) $venta_b->comision_monto, 0.001);
        $this->assertNull($venta_c->fresh()->vendedor_admin_id, 'C sigue sin comisión: no salió de un lead.');
    }

    /**
     * Si ya hay un vendedor marcado, no se toca a los closers; y con dos vendedores no se asigna
     * comisión a nadie (no hay uno por defecto), pero las ventas se agrupan igual.
     *
     * @return void
     */
    public function test_sin_vendedor_unico_agrupa_pero_no_asigna(): void
    {
        $this->crear_vendedor('Uno');
        $this->crear_vendedor('Otro');

        $closer = $this->crear_admin('Closer que no vende');
        $closer->is_closer = true;
        $closer->save();

        $client = $this->crear_cliente(['created_at' => '2026-10-20 12:00:00']);
        $this->crear_lead_promovido($client);
        $this->cuota_suelta($client, 1, 800, 'USD', false, '2026-10-21', '2026-10');

        $this->artisan('ventas:migrar-licencias')
            ->expectsOutput('No hay un vendedor único (admins.es_vendedor): no se asignó comisión a ninguna venta. Marcá un vendedor y volvé a correr el comando, o asignalo a mano en la pestaña Ventas.')
            ->assertExitCode(0);

        $this->assertFalse((bool) $closer->fresh()->es_vendedor, 'Si ya hay vendedores marcados, los closers no se tocan.');
        $this->assertSame(2, Admin::where('es_vendedor', true)->count());

        $venta = Venta::where('client_id', $client->id)->firstOrFail();
        $this->assertNull($venta->vendedor_admin_id);
        $this->assertNull($venta->comision_monto);
        $this->assertSame(1, LicenciaCuota::where('venta_id', $venta->id)->count());

        // Una fecha ilegible no corre nada.
        $this->artisan('ventas:migrar-licencias', ['--desde' => 'cualquier-cosa'])->assertExitCode(1);
    }
}
