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
}
