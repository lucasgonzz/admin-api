<?php

namespace Tests\Feature\Cobranzas;

use App\Models\Client;
use App\Models\LicenciaCuota;

/**
 * Clientes de dudoso cobro, o «stand-by» (misión cobro-dudoso-y-pagos-ventas, 30/10/2026).
 *
 * La marca es del CLIENTE y manda su deuda de ventas (cuotas pendientes) a un balde aparte: no
 * cuenta como plata «por cobrar» pero tampoco se da por perdida. Lo que estas pruebas protegen:
 *
 *  1. 🔴 **Que la marca mueva `pendiente` a `en_espera` y `por_cobrar`, y nada más.** `pendiente`
 *     sigue siendo la deuda total (un SPA viejo sigue leyendo eso), lo cobrado no cambia, y la
 *     comisión del vendedor NO se entera de la marca.
 *  2. **El panel**: `PUT client/{id}/cobro-dudoso` enciende (fecha de hoy; si ya estaba, la conserva)
 *     y apaga (las tres columnas vuelven a su estado de fábrica).
 *  3. **Las filas de `cobranzas/ventas`** y la lista del cliente traen la terna nueva
 *     (`cobro_dudoso`, `_desde`, `_motivo`), y los resúmenes por moneda suman `en_espera` y
 *     `por_cobrar` sin tocar los campos de siempre.
 *  4. **Que un cliente sin marca no cambie**: `por_cobrar = pendiente` y `en_espera = 0`.
 *
 * El reloj queda clavado el 15/9/2026 (ver `BaseDeCobranzas`).
 */
class CobroDudosoTest extends BaseDeVentas
{
    /**
     * Un cliente con una venta de USD 1.500 en tres cuotas de 500 y la primera ya cobrada: cobrado
     * 500, pendiente 1.000. El vendedor lleva el 15 % (comisión total 225, liberada 75).
     *
     * @param array<string, mixed> $atributos_del_cliente
     *
     * @return array{0: Client, 1: int} El cliente y el id de la venta.
     */
    private function cliente_con_una_venta(array $atributos_del_cliente = []): array
    {
        $this->crear_vendedor();
        $client = $this->crear_cliente($atributos_del_cliente);

        $alta = $this->alta_de_venta($client, ['productos' => ['gestion', 'ecommerce', 'agentes']]);
        $alta->assertStatus(201);
        $venta_id = (int) $alta->json('venta.id');

        $primera = $this->cuotas_de($venta_id)->first();
        $this->postJson('/api/admin/client/' . $client->id . '/licencias/' . $primera->id . '/pago', [
            'monto_pagado' => 500,
        ])->assertStatus(200);

        return [$client->fresh(), $venta_id];
    }

    /**
     * Un cliente nuevo no está marcado, y las tres columnas tienen su estado de fábrica.
     *
     * @return void
     */
    public function test_un_cliente_nuevo_no_esta_marcado(): void
    {
        $client = $this->crear_cliente();

        $this->assertFalse((bool) $client->cobro_dudoso);
        $this->assertNull($client->cobro_dudoso_desde);
        $this->assertNull($client->cobro_dudoso_motivo);
    }

    /**
     * 🔴 Sin la marca: `por_cobrar` es todo el pendiente y `en_espera` es cero. Con la marca:
     * `en_espera` es todo el pendiente y `por_cobrar` cero. `pendiente`, `cobrado` y `total` no se
     * mueven, y la comisión del vendedor tampoco.
     *
     * @return void
     */
    public function test_la_marca_mueve_el_pendiente_a_en_espera_y_no_toca_la_comision(): void
    {
        $this->admin_logueado();
        [$client, $venta_id] = $this->cliente_con_una_venta();

        $antes = $this->venta_de_la_lista($client, $venta_id);

        $this->assertFalse($antes['cobro_dudoso']);
        $this->assertEqualsWithDelta(1500.0, $antes['resumen']['total'], 0.001);
        $this->assertEqualsWithDelta(500.0, $antes['resumen']['cobrado'], 0.001);
        $this->assertEqualsWithDelta(1000.0, $antes['resumen']['pendiente'], 0.001);
        $this->assertEqualsWithDelta(0.0, $antes['resumen']['en_espera'], 0.001);
        $this->assertEqualsWithDelta(1000.0, $antes['resumen']['por_cobrar'], 0.001);
        $this->assertEqualsWithDelta(75.0, $antes['comision']['liberada'], 0.001);

        $this->putJson('/api/admin/client/' . $client->id . '/cobro-dudoso', ['cobro_dudoso' => true])
            ->assertStatus(200);

        $despues = $this->venta_de_la_lista($client, $venta_id);

        $this->assertTrue($despues['cobro_dudoso']);
        $this->assertEqualsWithDelta(1500.0, $despues['resumen']['total'], 0.001);
        $this->assertEqualsWithDelta(500.0, $despues['resumen']['cobrado'], 0.001);
        $this->assertEqualsWithDelta(1000.0, $despues['resumen']['pendiente'], 0.001, '`pendiente` no cambia de significado: sigue siendo la deuda total.');
        $this->assertEqualsWithDelta(1000.0, $despues['resumen']['en_espera'], 0.001);
        $this->assertEqualsWithDelta(0.0, $despues['resumen']['por_cobrar'], 0.001);

        // La comisión del vendedor se libera por lo que paga el cliente: la marca no la mueve.
        $this->assertSame($antes['comision'], $despues['comision']);
        $this->assertEquals($antes['comision_monto'], $despues['comision_monto']);

        // Las cuotas no se tocaron.
        $this->assertSame($antes['cuotas'], $despues['cuotas']);
    }

    /**
     * El panel: encender fija la fecha de hoy y guarda el motivo; volver a encender conserva la
     * fecha; apagar deja las tres columnas como de fábrica. Devuelve la lista entera del cliente.
     *
     * @return void
     */
    public function test_el_panel_enciende_conserva_la_fecha_y_apaga(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();
        $ruta = '/api/admin/client/' . $client->id . '/cobro-dudoso';

        $encendido = $this->putJson($ruta, ['cobro_dudoso' => true, 'motivo' => 'Dejó de contestar']);
        $encendido->assertStatus(200);
        $this->assertTrue($encendido->json('cobro_dudoso'));
        $this->assertSame('2026-09-15', $encendido->json('cobro_dudoso_desde'));
        $this->assertSame('Dejó de contestar', $encendido->json('cobro_dudoso_motivo'));
        $this->assertArrayHasKey('ventas', $encendido->json(), 'Devuelve la lista entera del cliente.');

        $fila = Client::find($client->id);
        $this->assertTrue((bool) $fila->cobro_dudoso);
        $this->assertSame('2026-09-15', $fila->cobro_dudoso_desde->toDateString());

        // Pasan días y se vuelve a encender sin motivo: la fecha y el motivo se conservan.
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-20 10:00:00', 'America/Argentina/Buenos_Aires'));
        $de_nuevo = $this->putJson($ruta, ['cobro_dudoso' => true]);
        $de_nuevo->assertStatus(200);
        $this->assertSame('2026-09-15', $de_nuevo->json('cobro_dudoso_desde'), 'Encender dos veces no corre la fecha.');
        $this->assertSame('Dejó de contestar', $de_nuevo->json('cobro_dudoso_motivo'));

        // Un motivo nuevo sí reemplaza al anterior.
        $con_otro_motivo = $this->putJson($ruta, ['cobro_dudoso' => true, 'motivo' => '  Pidió esperar  ']);
        $this->assertSame('Pidió esperar', $con_otro_motivo->json('cobro_dudoso_motivo'));

        // Apagar: vuelve todo a de fábrica, aunque venga un motivo.
        $apagado = $this->putJson($ruta, ['cobro_dudoso' => false, 'motivo' => 'ignorado']);
        $apagado->assertStatus(200);
        $this->assertFalse($apagado->json('cobro_dudoso'));
        $this->assertNull($apagado->json('cobro_dudoso_desde'));
        $this->assertNull($apagado->json('cobro_dudoso_motivo'));

        $fila = Client::find($client->id);
        $this->assertFalse((bool) $fila->cobro_dudoso);
        $this->assertNull($fila->cobro_dudoso_desde);
        $this->assertNull($fila->cobro_dudoso_motivo);
    }

    /**
     * El panel valida: `cobro_dudoso` es obligatorio y booleano, el motivo tiene tope de 255, y un
     * cliente inexistente es 404. Nada de eso escribe.
     *
     * @return void
     */
    public function test_el_panel_valida_y_responde_404(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();
        $ruta = '/api/admin/client/' . $client->id . '/cobro-dudoso';

        $this->putJson($ruta, [])->assertStatus(422);
        $this->putJson($ruta, ['cobro_dudoso' => 'tal vez'])->assertStatus(422);
        $this->putJson($ruta, ['cobro_dudoso' => true, 'motivo' => str_repeat('a', 256)])->assertStatus(422);
        $this->putJson('/api/admin/client/999999999/cobro-dudoso', ['cobro_dudoso' => true])->assertStatus(404);

        $this->assertFalse((bool) Client::find($client->id)->cobro_dudoso, 'Una request inválida no escribe.');
    }

    /**
     * La tabla de Cobranzas → Ventas: la fila de cada cliente trae la terna nueva y los resúmenes
     * suman `en_espera` / `por_cobrar`; el resumen global separa el balde del cliente dudoso del
     * cliente normal sin tocar `pendiente`.
     *
     * @return void
     */
    public function test_cobranzas_ventas_trae_la_terna_y_los_baldes(): void
    {
        $this->admin_logueado();

        [$dudoso, ] = $this->cliente_con_una_venta();
        [$normal, ] = $this->cliente_con_una_venta();

        $this->putJson('/api/admin/client/' . $dudoso->id . '/cobro-dudoso', [
            'cobro_dudoso' => true,
            'motivo'       => 'Cobro dudoso a futuro',
        ])->assertStatus(200);

        $respuesta = $this->getJson('/api/admin/cobranzas/ventas');
        $respuesta->assertStatus(200);

        $filas = [];
        foreach ($respuesta->json('clientes') as $fila) {
            $filas[(int) $fila['client_id']] = $fila;
        }

        $fila_dudoso = $filas[$dudoso->id];
        $this->assertTrue($fila_dudoso['cobro_dudoso']);
        $this->assertSame('2026-09-15', $fila_dudoso['cobro_dudoso_desde']);
        $this->assertSame('Cobro dudoso a futuro', $fila_dudoso['cobro_dudoso_motivo']);
        $this->assertEqualsWithDelta(1000.0, $fila_dudoso['resumen_por_moneda']['USD']['pendiente'], 0.001);
        $this->assertEqualsWithDelta(1000.0, $fila_dudoso['resumen_por_moneda']['USD']['en_espera'], 0.001);
        $this->assertEqualsWithDelta(0.0, $fila_dudoso['resumen_por_moneda']['USD']['por_cobrar'], 0.001);
        $this->assertTrue($fila_dudoso['ventas'][0]['cobro_dudoso']);
        $this->assertEqualsWithDelta(1000.0, $fila_dudoso['ventas'][0]['resumen']['en_espera'], 0.001);

        $fila_normal = $filas[$normal->id];
        $this->assertFalse($fila_normal['cobro_dudoso']);
        $this->assertNull($fila_normal['cobro_dudoso_desde']);
        $this->assertNull($fila_normal['cobro_dudoso_motivo']);
        $this->assertEqualsWithDelta(1000.0, $fila_normal['resumen_por_moneda']['USD']['pendiente'], 0.001);
        $this->assertEqualsWithDelta(0.0, $fila_normal['resumen_por_moneda']['USD']['en_espera'], 0.001);
        $this->assertEqualsWithDelta(1000.0, $fila_normal['resumen_por_moneda']['USD']['por_cobrar'], 0.001);

        /* El global suma TODAS las ventas de la base: se compara contra la suma de las filas, que es
           lo que pinta la cabecera, y no contra un número fijo que dependa de lo que traiga el slot. */
        $global = $respuesta->json('resumen_por_moneda.USD');
        $suma_pendiente = 0.0;
        $suma_en_espera = 0.0;
        $suma_por_cobrar = 0.0;
        foreach ($respuesta->json('clientes') as $fila) {
            $suma_pendiente += $fila['resumen_por_moneda']['USD']['pendiente'] ?? 0;
            $suma_en_espera += $fila['resumen_por_moneda']['USD']['en_espera'] ?? 0;
            $suma_por_cobrar += $fila['resumen_por_moneda']['USD']['por_cobrar'] ?? 0;
        }
        $this->assertEqualsWithDelta($suma_pendiente, $global['pendiente'], 0.01);
        $this->assertEqualsWithDelta($suma_en_espera, $global['en_espera'], 0.01);
        $this->assertEqualsWithDelta($suma_por_cobrar, $global['por_cobrar'], 0.01);
        $this->assertEqualsWithDelta($global['pendiente'], $global['en_espera'] + $global['por_cobrar'], 0.01, 'pendiente = en_espera + por_cobrar.');
    }

    /**
     * La lista del cliente (panel y Claude) trae la terna en la raíz, y es la misma por los dos
     * caminos.
     *
     * @return void
     */
    public function test_la_lista_del_cliente_trae_la_terna_en_la_raiz(): void
    {
        config(['services.claude_task_ingest.key' => 'clave-cobro-dudoso']);

        $this->admin_logueado();
        $client = $this->crear_cliente();

        $sin_marca = $this->getJson('/api/admin/client/' . $client->id . '/ventas');
        $this->assertFalse($sin_marca->json('cobro_dudoso'));
        $this->assertNull($sin_marca->json('cobro_dudoso_desde'));
        $this->assertNull($sin_marca->json('cobro_dudoso_motivo'));

        $this->putJson('/api/admin/client/' . $client->id . '/cobro-dudoso', [
            'cobro_dudoso' => true,
            'motivo'       => 'Stand-by',
        ])->assertStatus(200);

        $del_panel = $this->getJson('/api/admin/client/' . $client->id . '/ventas');
        $de_claude = $this->getJson('/api/claude/clients/' . $client->id . '/ventas', [
            'X-Claude-Task-Key' => 'clave-cobro-dudoso',
            'Accept'            => 'application/json',
        ]);

        $this->assertTrue($del_panel->json('cobro_dudoso'));
        $this->assertSame('2026-09-15', $del_panel->json('cobro_dudoso_desde'));
        $this->assertSame('Stand-by', $del_panel->json('cobro_dudoso_motivo'));
        $this->assertSame($del_panel->json(), $de_claude->json());
    }

    /**
     * Compatibilidad: sin marca, `resumen.pendiente` es el de siempre y los campos nuevos no lo
     * alteran; y una cuota suelta (sin venta) no se ve afectada por la marca.
     *
     * @return void
     */
    public function test_sin_marca_el_resumen_viejo_queda_intacto(): void
    {
        $this->admin_logueado();
        [$client, $venta_id] = $this->cliente_con_una_venta();

        $venta = $this->venta_de_la_lista($client, $venta_id);

        foreach (['total', 'cobrado', 'pendiente'] as $clave) {
            $this->assertArrayHasKey($clave, $venta['resumen']);
        }
        $this->assertEqualsWithDelta(1000.0, $venta['resumen']['pendiente'], 0.001);
        $this->assertEqualsWithDelta(
            $venta['resumen']['pendiente'],
            $venta['resumen']['por_cobrar'] + $venta['resumen']['en_espera'],
            0.001
        );

        // Una cuota suelta de otro cliente no cambia nada de este.
        $otro = $this->crear_cliente();
        LicenciaCuota::create([
            'client_id' => $otro->id, 'numero' => 1, 'periodo' => '2026-09', 'monto' => 100,
            'moneda' => 'USD', 'estado' => 'pendiente', 'importado' => false,
        ]);
        $this->assertEqualsWithDelta(1000.0, $this->venta_de_la_lista($client, $venta_id)['resumen']['pendiente'], 0.001);
    }
}
