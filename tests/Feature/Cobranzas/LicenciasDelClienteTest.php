<?php

namespace Tests\Feature\Cobranzas;

use App\Models\LicenciaCuota;

/**
 * Las cuotas de la licencia de un cliente: la pestaña Licencias (misión modulo-cobranzas,
 * 18/9/2026).
 *
 * Lo que estas pruebas protegen, en orden de importancia:
 *
 *  1. 🔴 **El pago parcial.** Es el caso real de la planilla (Únicas pagó una parte de una cuota):
 *     `monto_pagado` acumula, el estado sale de la cuenta, y "completa" la cierra aunque falte.
 *  2. **El resumen por moneda.** Pesos y dólares conviven en un mismo cliente (HB) y sumarlos
 *     sería inventar un número.
 *  3. **Generar desde el contrato una sola vez**: 422 si ya hay cuotas.
 */
class LicenciasDelClienteTest extends BaseDeCobranzas
{
    /**
     * Alta y edición de una cuota.
     *
     * @return void
     */
    public function test_alta_y_edicion_de_una_cuota(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $response = $this->postJson('/api/admin/client/' . $client->id . '/licencias', [
            'monto'       => 1200,
            'moneda'      => 'USD',
            'vencimiento' => '2026-10-15',
            'observacion' => 'Primera cuota',
        ]);

        $response->assertStatus(201);
        $this->assertSame(1, $response->json('cuota.numero'));
        $this->assertSame('2026-10', $response->json('cuota.periodo'), 'Sin período, el mes sale del vencimiento.');
        $this->assertSame('2026-10-15', $response->json('cuota.vencimiento'));
        $this->assertEqualsWithDelta(1200.0, $response->json('cuota.monto'), 0.001);
        $this->assertSame('pendiente', $response->json('cuota.estado'));
        $this->assertNull($response->json('cuota.monto_pagado'));
        $this->assertEqualsWithDelta(1200.0, $response->json('cuota.monto_pendiente'), 0.001);
        $this->assertCount(1, $response->json('cuotas'));
        $this->assertSame(1, $response->json('resumen.cantidad'));

        $cuota_id = $response->json('cuota.id');

        // La segunda toma el siguiente número; sin vencimiento ni período, el mes corriente.
        $segunda = $this->postJson('/api/admin/client/' . $client->id . '/licencias', ['monto' => 300, 'moneda' => 'ARS']);
        $segunda->assertStatus(201);
        $this->assertSame(2, $segunda->json('cuota.numero'));
        $this->assertSame(self::MES_CORRIENTE, $segunda->json('cuota.periodo'));

        $editada = $this->putJson('/api/admin/client/' . $client->id . '/licencias/' . $cuota_id, [
            'monto'       => 1000,
            'moneda'      => 'ARS',
            'vencimiento' => '2026-11-20',
            'observacion' => 'Renegociada',
        ]);

        $editada->assertStatus(200);
        $this->assertEqualsWithDelta(1000.0, $editada->json('cuota.monto'), 0.001);
        $this->assertSame('ARS', $editada->json('cuota.moneda'));
        $this->assertSame('2026-11', $editada->json('cuota.periodo'), 'El mes sigue al vencimiento nuevo.');
        $this->assertSame('Renegociada', $editada->json('cuota.observacion'));

        // Validación.
        $this->postJson('/api/admin/client/' . $client->id . '/licencias', ['moneda' => 'USD'])->assertStatus(422);
        $this->postJson('/api/admin/client/' . $client->id . '/licencias', ['monto' => 10, 'moneda' => 'EUR'])->assertStatus(422);
        $this->putJson('/api/admin/client/' . $client->id . '/licencias/' . $cuota_id, ['estado' => 'cobrada'])->assertStatus(422);

        // Una cuota de otro cliente es 404.
        $otro = $this->crear_cliente();
        $this->putJson('/api/admin/client/' . $otro->id . '/licencias/' . $cuota_id, ['monto' => 1])->assertStatus(404);
    }

    /**
     * 1. Pago parcial → parcial con lo pagado; el segundo pago completa; "completa" cierra aunque falte.
     *
     * @return void
     */
    public function test_pago_parcial_y_pago_completo(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $cuota = LicenciaCuota::create([
            'client_id' => $client->id, 'numero' => 1, 'periodo' => '2026-08',
            'monto' => 600, 'moneda' => 'USD', 'estado' => 'pendiente',
        ]);

        $parcial = $this->postJson('/api/admin/client/' . $client->id . '/licencias/' . $cuota->id . '/pago', [
            'monto_pagado' => 250,
            'fecha_pago'   => '2026-08-20',
            'observacion'  => 'Pagó 436.000 pesos',
        ]);

        $parcial->assertStatus(200);
        $this->assertSame('parcial', $parcial->json('cuota.estado'));
        $this->assertEqualsWithDelta(250.0, $parcial->json('cuota.monto_pagado'), 0.001);
        $this->assertEqualsWithDelta(350.0, $parcial->json('cuota.monto_pendiente'), 0.001);
        $this->assertSame('2026-08-20', $parcial->json('cuota.fecha_pago'));
        $this->assertSame(1, $parcial->json('resumen.parciales'));

        $completo = $this->postJson('/api/admin/client/' . $client->id . '/licencias/' . $cuota->id . '/pago', [
            'monto_pagado' => 350,
        ]);

        $completo->assertStatus(200);
        $this->assertSame('pagada', $completo->json('cuota.estado'));
        $this->assertEqualsWithDelta(600.0, $completo->json('cuota.monto_pagado'), 0.001, 'Acumula.');
        $this->assertEqualsWithDelta(0.0, $completo->json('cuota.monto_pendiente'), 0.001);
        $this->assertSame('2026-09-15', $completo->json('cuota.fecha_pago'), 'Sin fecha, hoy.');
        $this->assertSame(0, $completo->json('resumen.parciales'));
        $this->assertSame(0, $completo->json('resumen.pendientes'));

        // "Completa" con menos plata: pagada igual, y lo cobrado es lo que entró.
        $otra = LicenciaCuota::create([
            'client_id' => $client->id, 'numero' => 2, 'periodo' => '2026-09',
            'monto' => 400, 'moneda' => 'USD', 'estado' => 'pendiente',
        ]);

        $cerrada = $this->postJson('/api/admin/client/' . $client->id . '/licencias/' . $otra->id . '/pago', [
            'monto_pagado' => 380,
            'completa'     => true,
        ]);

        $cerrada->assertStatus(200);
        $this->assertSame('pagada', $cerrada->json('cuota.estado'));
        $this->assertEqualsWithDelta(380.0, $cerrada->json('cuota.monto_cobrado'), 0.001);
        $this->assertEqualsWithDelta(0.0, $cerrada->json('cuota.monto_pendiente'), 0.001);

        $this->postJson('/api/admin/client/' . $client->id . '/licencias/' . $otra->id . '/pago', [])->assertStatus(422);
    }

    /**
     * Baja.
     *
     * @return void
     */
    public function test_baja_de_una_cuota(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $cuota = LicenciaCuota::create([
            'client_id' => $client->id, 'numero' => 1, 'periodo' => '2026-08',
            'monto' => 600, 'moneda' => 'USD', 'estado' => 'pendiente',
        ]);
        $otro = $this->crear_cliente();
        $ajena = LicenciaCuota::create([
            'client_id' => $otro->id, 'numero' => 1, 'periodo' => '2026-08',
            'monto' => 100, 'moneda' => 'USD', 'estado' => 'pendiente',
        ]);

        $this->deleteJson('/api/admin/client/' . $client->id . '/licencias/' . $ajena->id)->assertStatus(404);

        $response = $this->deleteJson('/api/admin/client/' . $client->id . '/licencias/' . $cuota->id);
        $response->assertStatus(200);
        $this->assertCount(0, $response->json('cuotas'));
        $this->assertSame(0, $response->json('resumen.cantidad'));
        $this->assertNull(LicenciaCuota::find($cuota->id));
        $this->assertNotNull(LicenciaCuota::find($ajena->id));
    }

    /**
     * 3. Generar desde el contrato: crea, y la segunda vez es 422. Sin contrato, 422 también.
     *
     * @return void
     */
    public function test_generar_desde_el_contrato_una_sola_vez(): void
    {
        $this->admin_logueado();

        $client = $this->crear_cliente([
            'contract_currency'                => 'ARS',
            'contract_precio_licencia'         => '900000',
            'contract_fecha_primer_pago_unico' => '2026-10-01',
            'contract_financiacion'            => [
                ['monto' => '500000', 'fecha' => '2026-10-01'],
                ['monto' => '400000', 'fecha' => '2026-11-01'],
            ],
        ]);

        // El GET adelanta lo que dice el contrato, para el botón de la pestaña.
        $index = $this->getJson('/api/admin/client/' . $client->id . '/licencias');
        $index->assertStatus(200);
        $this->assertCount(0, $index->json('cuotas'));
        $this->assertSame('900000', $index->json('contrato.precio_licencia'));
        $this->assertSame('ARS', $index->json('contrato.currency'));
        $this->assertCount(2, $index->json('contrato.financiacion'));
        $this->assertSame('2026-10-01', $index->json('contrato.fecha_primer_pago_unico'));

        $response = $this->postJson('/api/admin/client/' . $client->id . '/licencias/desde-contrato');
        $response->assertStatus(200);
        $this->assertSame(2, $response->json('creadas'));
        $this->assertCount(2, $response->json('cuotas'));
        $this->assertEqualsWithDelta(500000.0, $response->json('cuotas.0.monto'), 0.001);
        $this->assertSame('ARS', $response->json('cuotas.0.moneda'));
        $this->assertSame('2026-11', $response->json('cuotas.1.periodo'));
        $this->assertEqualsWithDelta(900000.0, $response->json('resumen.total_por_moneda.ARS'), 0.001);

        $this->postJson('/api/admin/client/' . $client->id . '/licencias/desde-contrato')->assertStatus(422);
        $this->assertSame(2, LicenciaCuota::where('client_id', $client->id)->count());

        // Sin contrato no hay nada que generar.
        $pelado = $this->crear_cliente();
        $this->postJson('/api/admin/client/' . $pelado->id . '/licencias/desde-contrato')->assertStatus(422);
    }

    /**
     * 2. El resumen separa por moneda y cuenta pendientes y parciales.
     *
     * USD: 1.000 pagada (sin monto_pagado → cobrada entera) + 500 parcial con 200 pagados
     *      → total 1.500, pagado 1.200, pendiente 300.
     * ARS: 300.000 pendiente → total 300.000, pagado 0, pendiente 300.000.
     *
     * @return void
     */
    public function test_el_resumen_separa_por_moneda(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        LicenciaCuota::create(['client_id' => $client->id, 'numero' => 1, 'periodo' => '2026-03', 'monto' => 1000, 'moneda' => 'USD', 'estado' => 'pagada', 'importado' => true]);
        LicenciaCuota::create(['client_id' => $client->id, 'numero' => 2, 'periodo' => '2026-06', 'monto' => 500, 'moneda' => 'USD', 'estado' => 'parcial', 'monto_pagado' => 200]);
        LicenciaCuota::create(['client_id' => $client->id, 'numero' => 3, 'periodo' => '2026-09', 'monto' => 300000, 'moneda' => 'ARS', 'estado' => 'pendiente']);

        $response = $this->getJson('/api/admin/client/' . $client->id . '/licencias');
        $response->assertStatus(200);

        $resumen = $response->json('resumen');
        $this->assertSame(3, $resumen['cantidad']);
        $this->assertEqualsWithDelta(1500.0, $resumen['total_por_moneda']['USD'], 0.001);
        $this->assertEqualsWithDelta(300000.0, $resumen['total_por_moneda']['ARS'], 0.001);
        $this->assertEqualsWithDelta(1200.0, $resumen['pagado_por_moneda']['USD'], 0.001, 'Una cuota pagada sin monto cargado se cobró entera.');
        $this->assertEqualsWithDelta(0.0, $resumen['pagado_por_moneda']['ARS'], 0.001);
        $this->assertEqualsWithDelta(300.0, $resumen['pendiente_por_moneda']['USD'], 0.001);
        $this->assertEqualsWithDelta(300000.0, $resumen['pendiente_por_moneda']['ARS'], 0.001);
        $this->assertSame(1, $resumen['pendientes']);
        $this->assertSame(1, $resumen['parciales']);

        // La tabla del módulo trae el mismo resumen por cliente y el rango de meses de las cuotas.
        $tabla = $this->getJson('/api/admin/cobranzas/licencias');
        $tabla->assertStatus(200);
        $fila = collect($tabla->json('clientes'))->firstWhere('id', $client->id);
        $this->assertNotNull($fila);
        $this->assertCount(3, $fila['cuotas']);
        $this->assertEqualsWithDelta(1200.0, $fila['resumen']['pagado_por_moneda']['USD'], 0.001);
        $this->assertLessThanOrEqual('2026-03', $tabla->json('rango.desde'));
        $this->assertGreaterThanOrEqual('2026-09', $tabla->json('rango.hasta'));
        $this->assertSame(self::MES_CORRIENTE, $tabla->json('mes_corriente'));
    }
}
