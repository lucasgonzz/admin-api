<?php

namespace Tests\Feature\Cobranzas;

use App\Models\LicenciaCuota;
use App\Models\Venta;

/**
 * Las ventas de un cliente: la pestaña Ventas (misión ventas-y-comisiones, 28/10/2026).
 *
 * Lo que estas pruebas protegen, en orden de importancia:
 *
 *  1. 🔴 **La comisión se libera a medida que paga el cliente.** Sin pagos no hay nada liberado;
 *     con una de tres cuotas cobrada, un tercio; una cuota `pagada` sin monto cargado (las de la
 *     planilla) cuenta completa, con el mismo criterio que el resumen de cuotas.
 *  2. **El alta precarga la política del 26/10**: los tres sistemas son 1.500 en 3 cuotas, de
 *     contado 1.350 en una; desarrollo y otro exigen importe; cuotas explícitas mandan sobre el
 *     total y si no cierran es 422 sin escribir nada.
 *  3. **El porcentaje**: 15 por defecto, 30 con gestión en un cliente (o un lead de origen) en
 *     Suscripción; editable, y la comisión se recalcula con el total y con el porcentaje.
 *  4. **El total de la venta es la suma de sus cuotas**, también cuando se tocan por las rutas
 *     viejas de licencias, que siguen andando.
 *  5. Pertenencia: una venta de otro cliente es 404.
 */
class VentasDelClienteTest extends BaseDeVentas
{
    /**
     * Los tres sistemas sin importe: 1.500 en 3 cuotas mensuales de 500 desde la fecha de la venta,
     * con el vendedor único y el 15 %. De contado: 1.350 en una cuota.
     *
     * @return void
     */
    public function test_los_tres_sistemas_salen_a_1500_en_tres_cuotas_y_de_contado_a_1350(): void
    {
        $admin = $this->admin_logueado();
        $vendedor = $this->crear_vendedor();
        $client = $this->crear_cliente();

        $respuesta = $this->alta_de_venta($client, [
            'productos' => ['agentes', 'gestion', 'ecommerce'],
        ]);

        $respuesta->assertStatus(201);
        $venta_id = (int) $respuesta->json('venta.id');

        $this->assertSame(['gestion', 'ecommerce', 'agentes'], $respuesta->json('venta.productos'), 'Los productos se ordenan como en Venta::PRODUCTOS.');
        $this->assertSame(['Sistema de gestión', 'E-commerce', 'Agentes de IA'], $respuesta->json('venta.productos_etiquetas'));
        $this->assertEqualsWithDelta(1500.0, $respuesta->json('venta.monto_total'), 0.001);
        $this->assertSame('USD', $respuesta->json('venta.moneda'));
        $this->assertSame('manual', $respuesta->json('venta.origen'));
        $this->assertSame('2026-09-15', $respuesta->json('venta.fecha'), 'Sin fecha, la venta es de hoy.');
        $this->assertSame($vendedor->id, $respuesta->json('venta.vendedor_admin_id'), 'Con un solo vendedor, la venta se le asigna sola.');
        $this->assertEqualsWithDelta(15.0, $respuesta->json('venta.comision_porcentaje'), 0.001);
        $this->assertEqualsWithDelta(225.0, $respuesta->json('venta.comision_monto'), 0.001);
        $this->assertSame($admin->id, $respuesta->json('venta.created_by_admin_id'));
        $this->assertCount(1, $respuesta->json('ventas'), 'La respuesta trae la lista entera del cliente.');

        $cuotas = $this->cuotas_de($venta_id);
        $this->assertCount(3, $cuotas);
        foreach ($cuotas as $indice => $cuota) {
            $this->assertEqualsWithDelta(500.0, (float) $cuota->monto, 0.001);
            $this->assertSame('USD', $cuota->moneda);
            $this->assertSame('pendiente', $cuota->estado);
            $this->assertSame($indice + 1, $cuota->numero);
        }
        $this->assertSame('2026-09-15', $cuotas[0]->vencimiento->toDateString());
        $this->assertSame('2026-10-15', $cuotas[1]->vencimiento->toDateString());
        $this->assertSame('2026-11-15', $cuotas[2]->vencimiento->toDateString());
        $this->assertSame('2026-11', $cuotas[2]->periodo);

        $this->assertDatabaseHas('ventas', [
            'id'                => $venta_id,
            'client_id'         => $client->id,
            'origen'            => 'manual',
            'vendedor_admin_id' => $vendedor->id,
        ]);

        // De contado: 10 % menos y una sola cuota. La numeración sigue la del cliente.
        $contado = $this->alta_de_venta($client, [
            'productos' => ['gestion', 'ecommerce', 'agentes'],
            'contado'   => true,
        ]);
        $contado->assertStatus(201);
        $this->assertEqualsWithDelta(1350.0, $contado->json('venta.monto_total'), 0.001);
        $this->assertCount(1, $contado->json('venta.cuotas'));
        $this->assertSame(4, $contado->json('venta.cuotas.0.numero'));
        $this->assertEqualsWithDelta(202.5, $contado->json('venta.comision_monto'), 0.001);

        // Un sistema suelto: su precio de lista.
        $solo = $this->alta_de_venta($client, ['productos' => ['gestion']]);
        $solo->assertStatus(201);
        $this->assertEqualsWithDelta(800.0, $solo->json('venta.monto_total'), 0.001);
        $this->assertCount(1, $solo->json('venta.cuotas'));
    }

    /**
     * Cuotas escritas una por una (su suma es el total), desarrollo a medida con importe libre, el
     * reparto con redondeo y el 422 cuando la suma de las cuotas no da el total.
     *
     * @return void
     */
    public function test_cuotas_explicitas_desarrollo_libre_y_suma_que_no_cierra(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        // Cuotas explícitas: el total es su suma.
        $explicitas = $this->alta_de_venta($client, [
            'productos' => ['ecommerce'],
            'moneda'    => 'ARS',
            'cuotas'    => [
                ['monto' => 300, 'vencimiento' => '2026-10-01'],
                ['monto' => 200.5],
            ],
        ]);
        $explicitas->assertStatus(201);
        $this->assertEqualsWithDelta(500.5, $explicitas->json('venta.monto_total'), 0.001);
        $this->assertSame('ARS', $explicitas->json('venta.moneda'));
        $this->assertSame('2026-10-01', $explicitas->json('venta.cuotas.0.vencimiento'));
        $this->assertNull($explicitas->json('venta.cuotas.1.vencimiento'));
        $this->assertSame('2026-09', $explicitas->json('venta.cuotas.1.periodo'), 'Sin vencimiento, el mes es el de la venta.');
        $this->assertSame('ARS', $explicitas->json('venta.cuotas.1.moneda'));
        $this->assertNull($explicitas->json('venta.vendedor_admin_id'), 'Sin ningún vendedor marcado, la venta nace sin vendedor.');

        // Desarrollo a medida sin importe: 422 y no se escribe nada.
        $ventas_antes = Venta::where('client_id', $client->id)->count();

        $sin_importe = $this->alta_de_venta($client, ['productos' => ['desarrollo'], 'descripcion' => 'Integración a medida']);
        $sin_importe->assertStatus(422);
        $sin_importe->assertJsonValidationErrors(['monto_total']);
        $this->assertSame($ventas_antes, Venta::where('client_id', $client->id)->count());

        // Desarrollo con importe libre, repartido en 3 con redondeo: la última absorbe la diferencia.
        $desarrollo = $this->alta_de_venta($client, [
            'productos'          => ['desarrollo'],
            'descripcion'        => 'Integración a medida',
            'monto_total'        => 1000,
            'cantidad_cuotas'    => 3,
            'primer_vencimiento' => '2026-10-31',
        ]);
        $desarrollo->assertStatus(201);
        $this->assertSame('Integración a medida', $desarrollo->json('venta.descripcion'));
        $this->assertEqualsWithDelta(333.33, $desarrollo->json('venta.cuotas.0.monto'), 0.001);
        $this->assertEqualsWithDelta(333.33, $desarrollo->json('venta.cuotas.1.monto'), 0.001);
        $this->assertEqualsWithDelta(333.34, $desarrollo->json('venta.cuotas.2.monto'), 0.001);
        $this->assertSame('2026-11-30', $desarrollo->json('venta.cuotas.1.vencimiento'), 'Mensual sin desbordar el mes.');

        // La suma de las cuotas no da el total: 422, sin escribir ni la venta ni las cuotas.
        $ventas_antes = Venta::where('client_id', $client->id)->count();
        $cuotas_antes = LicenciaCuota::where('client_id', $client->id)->count();

        $no_cierra = $this->alta_de_venta($client, [
            'productos'   => ['gestion'],
            'monto_total' => 600,
            'cuotas'      => [['monto' => 300], ['monto' => 200]],
        ]);
        $no_cierra->assertStatus(422);
        $no_cierra->assertJsonValidationErrors(['cuotas']);
        $this->assertSame($ventas_antes, Venta::where('client_id', $client->id)->count());
        $this->assertSame($cuotas_antes, LicenciaCuota::where('client_id', $client->id)->count());

        // Validación de forma: sin productos, un producto inventado, una moneda inventada.
        $this->alta_de_venta($client, [])->assertStatus(422);
        $this->alta_de_venta($client, ['productos' => ['hosting']])->assertStatus(422);
        $this->alta_de_venta($client, ['productos' => ['gestion'], 'moneda' => 'EUR'])->assertStatus(422);
    }

    /**
     * El porcentaje: 15 por defecto; 30 con gestión en un cliente en Suscripción (por su contrato o
     * por el del lead del que salió); editable, y la comisión se recalcula con el % y con el total.
     *
     * @return void
     */
    public function test_porcentaje_por_defecto_suscripcion_y_recalculo(): void
    {
        $this->admin_logueado();
        $vendedor = $this->crear_vendedor();

        // Cliente en Suscripción por su propio contrato: con gestión, 30 %; sin gestión, 15 %.
        $suscripto = $this->crear_cliente(['contract_modalidad' => 'suscripcion']);

        $con_gestion = $this->alta_de_venta($suscripto, ['productos' => ['gestion']]);
        $con_gestion->assertStatus(201);
        $this->assertEqualsWithDelta(30.0, $con_gestion->json('venta.comision_porcentaje'), 0.001);
        $this->assertEqualsWithDelta(240.0, $con_gestion->json('venta.comision_monto'), 0.001);

        $sin_gestion = $this->alta_de_venta($suscripto, ['productos' => ['ecommerce']]);
        $this->assertEqualsWithDelta(15.0, $sin_gestion->json('venta.comision_porcentaje'), 0.001);
        $this->assertEqualsWithDelta(75.0, $sin_gestion->json('venta.comision_monto'), 0.001);

        $lista = $this->getJson('/api/admin/client/' . $suscripto->id . '/ventas');
        $lista->assertStatus(200);
        $this->assertEqualsWithDelta(15.0, $lista->json('porcentaje_por_defecto'), 0.001);
        $this->assertEqualsWithDelta(30.0, $lista->json('porcentaje_con_gestion'), 0.001);
        $this->assertTrue($lista->json('es_suscripcion'));
        $this->assertSame($vendedor->id, $lista->json('vendedor_por_defecto'));
        $this->assertSame(1500, $lista->json('precios.los_tres'));
        $this->assertSame(800, $lista->json('precios.gestion'));
        $this->assertSame(10, $lista->json('precios.contado_descuento'));
        $this->assertSame('Desarrollo a medida', $lista->json('productos.desarrollo'));
        $this->assertSame([['id' => $vendedor->id, 'name' => $vendedor->name]], $lista->json('vendedores'));

        // Cliente sin modalidad propia pero salido de un lead en Suscripción: también 30 %.
        $del_lead = $this->crear_cliente();
        $this->crear_lead_promovido($del_lead, 'suscripcion');
        $venta_del_lead = $this->alta_de_venta($del_lead, ['productos' => ['gestion', 'agentes']]);
        $this->assertEqualsWithDelta(30.0, $venta_del_lead->json('venta.comision_porcentaje'), 0.001);

        // Cliente en Licencia: 15 %.
        $licencia = $this->crear_cliente(['contract_modalidad' => 'licencia']);
        $venta = $this->alta_de_venta($licencia, ['productos' => ['gestion']]);
        $this->assertEqualsWithDelta(15.0, $venta->json('venta.comision_porcentaje'), 0.001);
        $venta_id = (int) $venta->json('venta.id');

        // Porcentaje explícito al alta y null explícito de vendedor: sin vendedor.
        $explicito = $this->alta_de_venta($licencia, ['productos' => ['gestion'], 'comision_porcentaje' => 12.5, 'vendedor_admin_id' => null]);
        $this->assertEqualsWithDelta(12.5, $explicito->json('venta.comision_porcentaje'), 0.001);
        $this->assertEqualsWithDelta(100.0, $explicito->json('venta.comision_monto'), 0.001);
        $this->assertNull($explicito->json('venta.vendedor_admin_id'), 'null explícito = sin vendedor, aunque haya uno solo.');

        // Editar el % recalcula la comisión.
        $editada = $this->putJson('/api/admin/client/' . $licencia->id . '/ventas/' . $venta_id, [
            'comision_porcentaje' => 20,
            'observacion'         => 'Comisión pactada aparte',
        ]);
        $editada->assertStatus(200);
        $this->assertEqualsWithDelta(160.0, $editada->json('venta.comision_monto'), 0.001);
        $this->assertSame('Comisión pactada aparte', $editada->json('venta.observacion'));
        $this->assertDatabaseHas('ventas', ['id' => $venta_id, 'comision_monto' => 160.00]);

        // Agregar una cuota sube el total y la comisión.
        $con_cuota = $this->postJson('/api/admin/client/' . $licencia->id . '/ventas/' . $venta_id . '/cuotas', [
            'monto'       => 200,
            'vencimiento' => '2026-12-10',
        ]);
        $con_cuota->assertStatus(201);
        $this->assertEqualsWithDelta(1000.0, $con_cuota->json('venta.monto_total'), 0.001);
        $this->assertEqualsWithDelta(200.0, $con_cuota->json('venta.comision_monto'), 0.001);
        $this->assertCount(2, $con_cuota->json('venta.cuotas'));
        $this->assertSame($venta_id, $con_cuota->json('venta.cuotas.1.venta_id'));
        $this->assertSame('USD', $con_cuota->json('venta.cuotas.1.moneda'), 'La cuota agregada toma la moneda de la venta.');

        // Cambiar la moneda de la venta la cambia en sus cuotas.
        $this->putJson('/api/admin/client/' . $licencia->id . '/ventas/' . $venta_id, ['moneda' => 'ARS'])->assertStatus(200);
        $this->assertSame(0, LicenciaCuota::where('venta_id', $venta_id)->where('moneda', '!=', 'ARS')->count());
    }

    /**
     * La comisión se libera a medida que paga el cliente: sin pagos 0; una de tres cuotas cobrada,
     * un tercio; una cuota marcada pagada sin monto cargado cuenta completa.
     *
     * @return void
     */
    public function test_la_comision_se_libera_a_medida_que_paga_el_cliente(): void
    {
        $this->admin_logueado();
        $this->crear_vendedor();
        $client = $this->crear_cliente();

        $alta = $this->alta_de_venta($client, ['productos' => ['gestion', 'ecommerce', 'agentes']]);
        $venta_id = (int) $alta->json('venta.id');

        // Sin pagos del cliente: nada liberado, todo por liberar.
        $this->assertEqualsWithDelta(225.0, $alta->json('venta.comision.total'), 0.001);
        $this->assertEqualsWithDelta(0.0, $alta->json('venta.comision.liberada'), 0.001);
        $this->assertEqualsWithDelta(0.0, $alta->json('venta.comision.a_pagar'), 0.001);
        $this->assertEqualsWithDelta(225.0, $alta->json('venta.comision.por_liberar'), 0.001);
        $this->assertSame('USD', $alta->json('venta.comision.moneda'));
        $this->assertSame('Tomás vendedor', $alta->json('venta.comision.vendedor_nombre'));

        $cuotas = $this->cuotas_de($venta_id);

        // El cliente paga la primera cuota (por la ruta de licencias de siempre): un tercio.
        $this->postJson('/api/admin/client/' . $client->id . '/licencias/' . $cuotas[0]->id . '/pago', [
            'monto_pagado' => 500,
        ])->assertStatus(200);

        $venta = $this->venta_de_la_lista($client, $venta_id);
        $this->assertEqualsWithDelta(500.0, $venta['resumen']['cobrado'], 0.001);
        $this->assertEqualsWithDelta(1000.0, $venta['resumen']['pendiente'], 0.001);
        $this->assertEqualsWithDelta(75.0, $venta['comision']['liberada'], 0.001);
        $this->assertEqualsWithDelta(75.0, $venta['comision']['a_pagar'], 0.001);
        $this->assertEqualsWithDelta(150.0, $venta['comision']['por_liberar'], 0.001);

        // Un pago parcial libera en proporción.
        $this->postJson('/api/admin/client/' . $client->id . '/licencias/' . $cuotas[1]->id . '/pago', [
            'monto_pagado' => 250,
        ])->assertStatus(200);
        $venta = $this->venta_de_la_lista($client, $venta_id);
        $this->assertEqualsWithDelta(112.5, $venta['comision']['liberada'], 0.001);

        // La tercera, dada por pagada a mano sin cargar el monto: cuenta completa.
        $this->putJson('/api/admin/client/' . $client->id . '/licencias/' . $cuotas[2]->id, [
            'estado' => 'pagada',
        ])->assertStatus(200);
        $venta = $this->venta_de_la_lista($client, $venta_id);
        $this->assertEqualsWithDelta(1250.0, $venta['resumen']['cobrado'], 0.001);
        $this->assertEqualsWithDelta(187.5, $venta['comision']['liberada'], 0.001);
    }

    /**
     * El total de la venta sigue a sus cuotas también por las rutas viejas de licencias, que siguen
     * andando; las cuotas sueltas van aparte; y la tabla del módulo trae al cliente con su resumen.
     *
     * @return void
     */
    public function test_rutas_viejas_cuotas_sueltas_y_tabla_del_modulo(): void
    {
        $this->admin_logueado();
        $this->crear_vendedor();
        $client = $this->crear_cliente();

        // Una cuota suelta, por la ruta vieja (como antes de las ventas).
        $suelta = $this->postJson('/api/admin/client/' . $client->id . '/licencias', ['monto' => 100, 'moneda' => 'ARS']);
        $suelta->assertStatus(201);
        $this->assertNull($suelta->json('cuota.venta_id'));

        $alta = $this->alta_de_venta($client, ['productos' => ['gestion', 'ecommerce', 'agentes']]);
        $venta_id = (int) $alta->json('venta.id');
        $cuotas = $this->cuotas_de($venta_id);

        // La lista vieja trae todas, con su venta_id.
        $licencias = $this->getJson('/api/admin/client/' . $client->id . '/licencias');
        $licencias->assertStatus(200);
        $this->assertCount(4, $licencias->json('cuotas'));
        $this->assertSame($venta_id, $licencias->json('cuotas.1.venta_id'));

        // La pestaña Ventas separa las sueltas.
        $lista = $this->getJson('/api/admin/client/' . $client->id . '/ventas');
        $this->assertCount(1, $lista->json('ventas'));
        $this->assertCount(1, $lista->json('cuotas_sin_venta'));
        $this->assertEqualsWithDelta(100.0, $lista->json('cuotas_sin_venta.0.monto'), 0.001);

        // Editar el monto de una cuota de la venta por la ruta vieja: el total y la comisión siguen.
        $this->putJson('/api/admin/client/' . $client->id . '/licencias/' . $cuotas[2]->id, ['monto' => 300])->assertStatus(200);
        $venta = Venta::find($venta_id);
        $this->assertEqualsWithDelta(1300.0, (float) $venta->monto_total, 0.001);
        $this->assertEqualsWithDelta(195.0, (float) $venta->comision_monto, 0.001);

        // Borrarla por la ruta vieja: baja el total.
        $this->deleteJson('/api/admin/client/' . $client->id . '/licencias/' . $cuotas[2]->id)->assertStatus(200);
        $venta->refresh();
        $this->assertEqualsWithDelta(1000.0, (float) $venta->monto_total, 0.001);
        $this->assertEqualsWithDelta(150.0, (float) $venta->comision_monto, 0.001);

        // La tabla del módulo: un renglón por cliente con ventas, con el resumen por moneda.
        $tabla = $this->getJson('/api/admin/cobranzas/ventas');
        $tabla->assertStatus(200);
        $fila = collect($tabla->json('clientes'))->firstWhere('client_id', $client->id);
        $this->assertNotNull($fila, 'El cliente con ventas aparece en la tabla.');
        $this->assertCount(1, $fila['ventas']);
        $this->assertEqualsWithDelta(1000.0, $fila['resumen_por_moneda']['USD']['total'], 0.001);
        $this->assertEqualsWithDelta(1000.0, $fila['resumen_por_moneda']['USD']['pendiente'], 0.001);
        $this->assertEqualsWithDelta(150.0, $fila['resumen_por_moneda']['USD']['comision_total'], 0.001);
        $this->assertSame('E-commerce', $tabla->json('productos.ecommerce'));
        $this->assertNotEmpty($tabla->json('vendedores'));

        $sin_ventas = $this->crear_cliente();
        $this->assertNull(collect($tabla->json('clientes'))->firstWhere('client_id', $sin_ventas->id), 'Un cliente sin ventas no va en la tabla.');
    }

    /**
     * Pertenencia: editar, borrar o agregarle una cuota a la venta de OTRO cliente es 404 y no
     * toca nada. Y una venta sin pagos de comisión se borra con sus cuotas.
     *
     * @return void
     */
    public function test_la_venta_de_otro_cliente_es_404_y_la_baja_borra_sus_cuotas(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();
        $otro = $this->crear_cliente();

        $venta_id = (int) $this->alta_de_venta($client, ['productos' => ['gestion']])->json('venta.id');

        $this->putJson('/api/admin/client/' . $otro->id . '/ventas/' . $venta_id, ['comision_porcentaje' => 50])->assertStatus(404);
        $this->deleteJson('/api/admin/client/' . $otro->id . '/ventas/' . $venta_id)->assertStatus(404);
        $this->postJson('/api/admin/client/' . $otro->id . '/ventas/' . $venta_id . '/cuotas', ['monto' => 1])->assertStatus(404);

        $this->assertDatabaseHas('ventas', ['id' => $venta_id, 'comision_porcentaje' => 15.00]);

        // La baja de verdad: la venta y sus cuotas.
        $this->deleteJson('/api/admin/client/' . $client->id . '/ventas/' . $venta_id)->assertStatus(200);
        $this->assertDatabaseMissing('ventas', ['id' => $venta_id]);
        $this->assertSame(0, LicenciaCuota::where('venta_id', $venta_id)->count());
    }
}
