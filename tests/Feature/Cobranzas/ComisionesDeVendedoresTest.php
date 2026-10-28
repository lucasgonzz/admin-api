<?php

namespace Tests\Feature\Cobranzas;

use App\Models\ComisionPago;
use App\Models\LicenciaCuota;
use Laravel\Sanctum\Sanctum;

/**
 * Las comisiones de los vendedores (misión ventas-y-comisiones, 28/10/2026): los pagos al
 * vendedor, la tabla de Cobranzas → Comisiones y "Mis comisiones".
 *
 * Lo que estas pruebas protegen:
 *
 *  1. 🔴 **Una venta con pagos de comisión no se borra** (422): esos pagos son plata que salió.
 *  2. **El pago baja lo que falta pagar**; borrarlo lo devuelve. Pagar de más se permite (es un
 *     adelanto) y la respuesta lo marca con `excede`.
 *  3. **"Mis comisiones" trae solo lo del autenticado**, y el filtro por vendedor de la tabla
 *     general, solo lo de ese vendedor.
 */
class ComisionesDeVendedoresTest extends BaseDeVentas
{
    /**
     * Registrar un pago al vendedor, que baje lo que falta pagar, el aviso de que excede, borrarlo,
     * y que la venta con pagos no se pueda borrar hasta sacar los pagos.
     *
     * @return void
     */
    public function test_pagos_al_vendedor_y_la_venta_con_pagos_no_se_borra(): void
    {
        $admin = $this->admin_logueado('Lucas');
        $vendedor = $this->crear_vendedor();
        $client = $this->crear_cliente();

        $venta_id = (int) $this->alta_de_venta($client, ['productos' => ['gestion', 'ecommerce', 'agentes']])->json('venta.id');
        $cuotas = $this->cuotas_de($venta_id);

        // El cliente paga la primera cuota: se liberan 75 de 225.
        $this->postJson('/api/admin/client/' . $client->id . '/licencias/' . $cuotas[0]->id . '/pago', ['monto_pagado' => 500])->assertStatus(200);

        // Pago de 50: no excede, quedan 25 a pagar.
        $pago = $this->postJson('/api/admin/ventas/' . $venta_id . '/comision/pagos', [
            'monto' => 50,
            'fecha' => '2026-09-20',
            'nota'  => 'Transferencia',
        ]);
        $pago->assertStatus(201);
        $this->assertFalse($pago->json('excede'));
        $this->assertEqualsWithDelta(75.0, $pago->json('venta.comision.liberada'), 0.001);
        $this->assertEqualsWithDelta(50.0, $pago->json('venta.comision.pagada'), 0.001);
        $this->assertEqualsWithDelta(25.0, $pago->json('venta.comision.a_pagar'), 0.001);
        $this->assertCount(1, $pago->json('venta.pagos'));
        $this->assertSame('2026-09-20', $pago->json('pago.fecha'));
        $this->assertSame($client->id, $pago->json('venta.client.id'));

        $pago_id = (int) $pago->json('pago.id');
        $this->assertDatabaseHas('comision_pagos', [
            'id'                      => $pago_id,
            'venta_id'                => $venta_id,
            'vendedor_admin_id'       => $vendedor->id,
            'moneda'                  => 'USD',
            'nota'                    => 'Transferencia',
            'registrado_por_admin_id' => $admin->id,
        ]);

        // Pago de 100 cuando faltaban 25: se registra igual y avisa que excede. A pagar no es negativo.
        $adelanto = $this->postJson('/api/admin/ventas/' . $venta_id . '/comision/pagos', ['monto' => 100]);
        $adelanto->assertStatus(201);
        $this->assertTrue($adelanto->json('excede'));
        $this->assertSame('2026-09-15', $adelanto->json('pago.fecha'), 'Sin fecha, el pago es de hoy.');
        $this->assertEqualsWithDelta(150.0, $adelanto->json('venta.comision.pagada'), 0.001);
        $this->assertEqualsWithDelta(0.0, $adelanto->json('venta.comision.a_pagar'), 0.001);

        // Validación: monto obligatorio y mayor a cero.
        $this->postJson('/api/admin/ventas/' . $venta_id . '/comision/pagos', [])->assertStatus(422);
        $this->postJson('/api/admin/ventas/' . $venta_id . '/comision/pagos', ['monto' => 0])->assertStatus(422);
        $this->postJson('/api/admin/ventas/999999999/comision/pagos', ['monto' => 10])->assertStatus(404);

        // Con pagos registrados la venta NO se borra.
        $baja = $this->deleteJson('/api/admin/client/' . $client->id . '/ventas/' . $venta_id);
        $baja->assertStatus(422);
        $this->assertDatabaseHas('ventas', ['id' => $venta_id]);
        $this->assertSame(3, LicenciaCuota::where('venta_id', $venta_id)->count());

        // Borrar un pago devuelve lo que falta pagar.
        $borrado = $this->deleteJson('/api/admin/comision-pagos/' . (int) $adelanto->json('pago.id'));
        $borrado->assertStatus(200);
        $this->assertEqualsWithDelta(50.0, $borrado->json('venta.comision.pagada'), 0.001);
        $this->assertEqualsWithDelta(25.0, $borrado->json('venta.comision.a_pagar'), 0.001);

        $this->deleteJson('/api/admin/comision-pagos/' . $pago_id)->assertStatus(200);
        $this->assertSame(0, ComisionPago::where('venta_id', $venta_id)->count());

        // Sin pagos, ahora sí se borra (con sus cuotas).
        $this->deleteJson('/api/admin/client/' . $client->id . '/ventas/' . $venta_id)->assertStatus(200);
        $this->assertDatabaseMissing('ventas', ['id' => $venta_id]);
        $this->assertSame(0, LicenciaCuota::where('venta_id', $venta_id)->count());
    }

    /**
     * Una venta sin vendedor no admite pagos de comisión: no hay a quién pagarle.
     *
     * @return void
     */
    public function test_una_venta_sin_vendedor_no_admite_pagos(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $venta_id = (int) $this->alta_de_venta($client, ['productos' => ['gestion'], 'vendedor_admin_id' => null])->json('venta.id');

        $this->postJson('/api/admin/ventas/' . $venta_id . '/comision/pagos', ['monto' => 10])->assertStatus(422);
        $this->assertSame(0, ComisionPago::where('venta_id', $venta_id)->count());
    }

    /**
     * "Mis comisiones" trae solo las ventas y los pagos del admin autenticado; la tabla general
     * filtrada por vendedor, solo las de ese vendedor; sin filtro, las de todos con su resumen.
     *
     * @return void
     */
    public function test_mis_comisiones_solo_trae_lo_del_autenticado(): void
    {
        $lucas = $this->admin_logueado('Lucas');
        $tomas = $this->crear_vendedor('Tomás');
        $otra = $this->crear_vendedor('Otra vendedora');
        $client = $this->crear_cliente();

        // Con dos vendedores no hay uno por defecto: se elige a mano.
        $de_tomas = $this->alta_de_venta($client, ['productos' => ['gestion'], 'vendedor_admin_id' => $tomas->id]);
        $de_otra = $this->alta_de_venta($client, ['productos' => ['ecommerce'], 'vendedor_admin_id' => $otra->id]);
        $sin_vendedor = $this->alta_de_venta($client, ['productos' => ['agentes']]);
        $this->assertNull($sin_vendedor->json('venta.vendedor_admin_id'), 'Con dos vendedores, la venta nace sin vendedor.');

        $venta_tomas = (int) $de_tomas->json('venta.id');
        $venta_otra = (int) $de_otra->json('venta.id');

        // El cliente paga entera la venta de Tomás: se le libera toda la comisión (120) y se le pagan 100.
        $cuota_tomas = $this->cuotas_de($venta_tomas)->first();
        $this->postJson('/api/admin/client/' . $client->id . '/licencias/' . $cuota_tomas->id . '/pago', ['monto_pagado' => 800])->assertStatus(200);
        $this->postJson('/api/admin/ventas/' . $venta_tomas . '/comision/pagos', ['monto' => 100])->assertStatus(201);

        // Tabla general sin filtro: las dos ventas con vendedor (no la que no tiene).
        $todas = $this->getJson('/api/admin/comisiones');
        $todas->assertStatus(200);
        $ids = collect($todas->json('comisiones'))->pluck('id')->all();
        $this->assertContains($venta_tomas, $ids);
        $this->assertContains($venta_otra, $ids);
        $this->assertNotContains((int) $sin_vendedor->json('venta.id'), $ids);
        $resumen_tomas = collect($todas->json('resumen_por_vendedor'))->firstWhere('vendedor_admin_id', $tomas->id);
        $this->assertEqualsWithDelta(20.0, $resumen_tomas['por_moneda']['USD']['a_pagar'], 0.001);

        // Filtrada por vendedor.
        $filtrada = $this->getJson('/api/admin/comisiones?vendedor_admin_id=' . $otra->id);
        $this->assertSame([$venta_otra], collect($filtrada->json('comisiones'))->pluck('id')->all());

        // "Mis comisiones" como Tomás: solo lo suyo. Y `me` le dice a la SPA que es vendedor.
        Sanctum::actingAs($tomas);
        $this->assertTrue($this->getJson('/api/admin/me')->json('admin.es_vendedor'));
        $mias = $this->getJson('/api/admin/comisiones/mias');
        $mias->assertStatus(200);
        $this->assertSame([$venta_tomas], collect($mias->json('comisiones'))->pluck('id')->all());
        $this->assertEqualsWithDelta(120.0, $mias->json('resumen.por_moneda.USD.total'), 0.001);
        $this->assertEqualsWithDelta(120.0, $mias->json('resumen.por_moneda.USD.liberada'), 0.001);
        $this->assertEqualsWithDelta(100.0, $mias->json('resumen.por_moneda.USD.pagada'), 0.001);
        $this->assertEqualsWithDelta(20.0, $mias->json('resumen.por_moneda.USD.a_pagar'), 0.001);
        $this->assertEqualsWithDelta(0.0, $mias->json('resumen.por_moneda.USD.por_liberar'), 0.001);
        $this->assertCount(1, $mias->json('resumen.pagos'));
        $this->assertSame($client->id, $mias->json('resumen.pagos.0.client.id'));

        // Como la otra vendedora: solo lo suyo, sin pagos.
        Sanctum::actingAs($otra);
        $mias_otra = $this->getJson('/api/admin/comisiones/mias');
        $this->assertSame([$venta_otra], collect($mias_otra->json('comisiones'))->pluck('id')->all());
        $this->assertCount(0, $mias_otra->json('resumen.pagos'));
        $this->assertEqualsWithDelta(75.0, $mias_otra->json('resumen.por_moneda.USD.por_liberar'), 0.001);

        // Lucas, que no vende, no tiene comisiones propias.
        Sanctum::actingAs($lucas);
        $this->assertCount(0, $this->getJson('/api/admin/comisiones/mias')->json('comisiones'));
        $this->assertFalse($this->getJson('/api/admin/me')->json('admin.es_vendedor'));
    }
}
