<?php

namespace Tests\Feature\Cobranzas;

use App\Models\ComisionPago;
use App\Models\LicenciaCuota;
use App\Models\Venta;

/**
 * Los arreglos que pidió la revisión independiente de la misión ventas-y-comisiones (28/10/2026).
 * Una prueba por punto, en el orden en que se arreglaron:
 *
 *  1. La liberación cuenta una cuota `pagada` por su monto completo y topa lo cobrado de cada cuota
 *     en su monto (pagos en pesos sobre una cuota en dólares no liberan de más).
 *  2. La moneda de una cuota con venta es siempre la de la venta (la distinta se ignora, sin 422), y
 *     una venta con pagos de comisión no cambia de moneda (422 en `moneda`).
 */
class RevisionDeVentasYComisionesTest extends BaseDeVentas
{
    /**
     * Registra un pago del cliente sobre una cuota por la ruta de licencias de siempre.
     *
     * @param int   $client_id
     * @param int   $cuota_id
     * @param float $monto
     * @param bool  $completa
     *
     * @return void
     */
    private function pagar_cuota(int $client_id, int $cuota_id, float $monto, bool $completa = false): void
    {
        $this->postJson('/api/admin/client/' . $client_id . '/licencias/' . $cuota_id . '/pago', [
            'monto_pagado' => $monto,
            'completa'     => $completa,
        ])->assertStatus(200);
    }

    /**
     * 1. Tres cuotas de 500 pagadas con 480 y "completa": la comisión se libera entera. Una cuota de
     * USD 500 con 1.200.000 cargados (pesos sobre dólares): libera solo su tercio.
     *
     * @return void
     */
    public function test_la_liberacion_cuenta_la_cuota_pagada_completa_y_topa_lo_cobrado_en_su_monto(): void
    {
        $this->admin_logueado();
        $this->crear_vendedor();
        $client = $this->crear_cliente();

        // Tres de 500 dadas por completas con 480 cada una: para el vendedor, la venta está cobrada.
        $venta_id = (int) $this->alta_de_venta($client, ['productos' => ['gestion', 'ecommerce', 'agentes']])->json('venta.id');
        foreach ($this->cuotas_de($venta_id) as $cuota) {
            $this->pagar_cuota($client->id, $cuota->id, 480, true);
        }

        $venta = $this->venta_de_la_lista($client, $venta_id);
        $this->assertEqualsWithDelta(225.0, $venta['comision']['liberada'], 0.001, 'Las tres cuotas están pagadas: se libera el 100 %.');
        $this->assertEqualsWithDelta(0.0, $venta['comision']['por_liberar'], 0.001);
        $this->assertEqualsWithDelta(1440.0, $venta['resumen']['cobrado'], 0.001, 'Lo que se muestra como cobrado no cambia: es lo que entró.');

        // Una cuota de USD 500 con 1.200.000 cargados: cuenta 500, no 1.200.000.
        $otra_id = (int) $this->alta_de_venta($client, ['productos' => ['gestion', 'ecommerce', 'agentes']])->json('venta.id');
        $primera = $this->cuotas_de($otra_id)->first();
        $this->pagar_cuota($client->id, $primera->id, 1200000);

        $otra = $this->venta_de_la_lista($client, $otra_id);
        $this->assertEqualsWithDelta(75.0, $otra['comision']['liberada'], 0.001, 'Una de tres cuotas: un tercio, aunque el monto cargado sea enorme.');
        $this->assertEqualsWithDelta(150.0, $otra['comision']['por_liberar'], 0.001);
    }

    /**
     * 2. Una cuota que es de una venta va siempre en la moneda de la venta, por la ruta nueva y por
     * las viejas; una cuota suelta se sigue editando como siempre. Y una venta con pagos de
     * comisión no puede cambiar de moneda.
     *
     * @return void
     */
    public function test_las_cuotas_de_una_venta_van_en_su_moneda_y_con_pagos_no_cambia(): void
    {
        $this->admin_logueado();
        $this->crear_vendedor();
        $client = $this->crear_cliente();

        $venta_id = (int) $this->alta_de_venta($client, ['productos' => ['gestion'], 'moneda' => 'USD'])->json('venta.id');

        // Agregar una cuota mandando otra moneda: se ignora, va en dólares (sin 422).
        $agregada = $this->postJson('/api/admin/client/' . $client->id . '/ventas/' . $venta_id . '/cuotas', [
            'monto'  => 100,
            'moneda' => 'ARS',
        ]);
        $agregada->assertStatus(201);
        $this->assertSame('USD', $agregada->json('venta.cuotas.1.moneda'));

        // Editarla por la ruta vieja mandando otra moneda: el monto cambia, la moneda no.
        $cuota_id = (int) $agregada->json('venta.cuotas.1.id');
        $this->putJson('/api/admin/client/' . $client->id . '/licencias/' . $cuota_id, ['monto' => 150, 'moneda' => 'ARS'])->assertStatus(200);
        $cuota = LicenciaCuota::find($cuota_id);
        $this->assertSame('USD', $cuota->moneda);
        $this->assertEqualsWithDelta(150.0, (float) $cuota->monto, 0.001);
        $this->assertSame(0, LicenciaCuota::where('venta_id', $venta_id)->where('moneda', '!=', 'USD')->count());

        // Una cuota suelta sí cambia de moneda, como siempre.
        $suelta_id = (int) $this->postJson('/api/admin/client/' . $client->id . '/licencias', ['monto' => 10, 'moneda' => 'USD'])->json('cuota.id');
        $this->putJson('/api/admin/client/' . $client->id . '/licencias/' . $suelta_id, ['moneda' => 'ARS'])->assertStatus(200);
        $this->assertSame('ARS', LicenciaCuota::find($suelta_id)->moneda);

        // Con un pago de comisión registrado, la venta no cambia de moneda.
        $this->pagar_cuota($client->id, $this->cuotas_de($venta_id)->first()->id, 800);
        $this->postJson('/api/admin/ventas/' . $venta_id . '/comision/pagos', ['monto' => 50])->assertStatus(201);

        $cambio = $this->putJson('/api/admin/client/' . $client->id . '/ventas/' . $venta_id, ['moneda' => 'ARS']);
        $cambio->assertStatus(422);
        $cambio->assertJsonValidationErrors(['moneda']);
        $this->assertSame('USD', Venta::find($venta_id)->moneda);
        $this->assertSame(0, LicenciaCuota::where('venta_id', $venta_id)->where('moneda', '!=', 'USD')->count());

        // Mandar la MISMA moneda no es un cambio: pasa.
        $this->putJson('/api/admin/client/' . $client->id . '/ventas/' . $venta_id, ['moneda' => 'USD', 'observacion' => 'ok'])->assertStatus(200);
    }
}
