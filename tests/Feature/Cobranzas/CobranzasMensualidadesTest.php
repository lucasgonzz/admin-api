<?php

namespace Tests\Feature\Cobranzas;

use App\Models\MensualidadPago;
use App\Models\MensualidadPeriodo;

/**
 * La tabla de Mensualidades del módulo Cobranzas y los movimientos sobre un mes (misión
 * modulo-cobranzas, 18/9/2026).
 *
 * Lo que estas pruebas protegen, en orden de importancia:
 *
 *  1. 🔴 **Que los siete estados se deduzcan en el orden correcto.** Es el color de cada celda del
 *     Excel: un `sin_cargo` afirmado gana a una factura, una factura gana al calendario, y un mes
 *     anterior al alta del cliente no es "rojo" sino "no aplica". Si el orden se corre, la tabla
 *     reclama meses que no se deben o esconde meses que sí.
 *  2. **Que registrar y borrar pagos recalcule el mes sin sorpresas**, incluido el caso del pago
 *     parcial (el "FALTAN $4.000" de la planilla) y el del mes importado que no se reabre.
 *  3. **Que las preferencias sean por admin.** Dos personas mirando el módulo no se pisan la
 *     selección de meses.
 */
class CobranzasMensualidadesTest extends BaseDeCobranzas
{
    /**
     * Pide la tabla y devuelve la fila de un cliente indexada por mes.
     *
     * @param array<int, string> $meses
     * @param int                $client_id
     *
     * @return array<string, mixed>
     */
    private function fila_de(array $meses, int $client_id): array
    {
        $response = $this->getJson('/api/admin/cobranzas/mensualidades?meses=' . implode(',', $meses));
        $response->assertStatus(200);

        foreach ($response->json('clientes') as $fila) {
            if ((int) $fila['id'] === $client_id) {
                return $fila;
            }
        }

        $this->fail('El cliente #' . $client_id . ' no está en la tabla.');
    }

    /**
     * 1. Los siete estados, con dos meses de referencia y el orden de las reglas.
     *
     * @return void
     */
    public function test_la_tabla_deduce_los_siete_estados_en_el_orden_correcto(): void
    {
        $this->admin_logueado();

        $meses = ['2026-07', '2026-08', '2026-09', '2026-10'];

        // Cliente A: cobra desde enero; julio sin cargo, agosto facturado, septiembre nada, octubre no llegó.
        $a = $this->crear_cliente();
        $this->sembrar_periodo($a, '2026-07', MensualidadPeriodo::ESTADO_SIN_CARGO);
        $this->sembrar_factura($a, '2026-08');
        // Y una factura sobre el mes sin cargo: lo afirmado tiene que ganarle.
        $this->sembrar_factura($a, '2026-07');

        $fila_a = $this->fila_de($meses, $a->id);

        $this->assertSame('sin_cargo', $fila_a['meses']['2026-07']['estado'], 'Un mes marcado sin cargo es sin cargo aunque tenga factura.');
        $this->assertSame('facturado', $fila_a['meses']['2026-08']['estado']);
        $this->assertSame(123, $fila_a['meses']['2026-08']['factura']['cbte_numero']);
        $this->assertSame('pendiente', $fila_a['meses']['2026-09']['estado'], 'Le tocaba, sin factura y sin pago: rojo.');
        $this->assertSame('futuro', $fila_a['meses']['2026-10']['estado']);
        $this->assertArrayNotHasKey('pagos', $fila_a['meses']['2026-09'], 'La tabla no lleva el detalle de pagos.');
        $this->assertEqualsWithDelta(12000.0, $fila_a['meses']['2026-09']['monto_esperado'], 0.001, 'Sin fila, el esperado es el total actual del cliente.');
        $this->assertSame(12000.0, (float) $fila_a['total_mensualidad']);
        $this->assertTrue($fila_a['actualizacion']['sin_oficial']);

        // Cliente B: arranca en septiembre; agosto no aplica; septiembre parcial con saldo.
        $b = $this->crear_cliente(['mensualidad_inicio' => '2026-09-01']);
        $this->sembrar_periodo($b, '2026-09', MensualidadPeriodo::ESTADO_PARCIAL, 12000);
        $this->sembrar_pago($b, '2026-09', 5000);
        // Un pago importado sin monto no suma.
        MensualidadPago::create(['client_id' => $b->id, 'periodo' => '2026-09', 'monto' => null, 'importado' => true]);

        $fila_b = $this->fila_de($meses, $b->id);

        $this->assertSame('no_aplica', $fila_b['meses']['2026-08']['estado'], 'Antes del alta no se reclama.');
        $this->assertSame('parcial', $fila_b['meses']['2026-09']['estado']);
        $this->assertEqualsWithDelta(5000.0, $fila_b['meses']['2026-09']['monto_pagado'], 0.001);
        $this->assertEqualsWithDelta(7000.0, $fila_b['meses']['2026-09']['saldo'], 0.001);

        // Cliente C: julio pagado a mano; agosto con una factura RECHAZADA, que no cuenta; sin inicio → nada aplica.
        $c = $this->crear_cliente();
        $this->sembrar_periodo($c, '2026-07', MensualidadPeriodo::ESTADO_PAGADO);
        $this->sembrar_factura($c, '2026-08', false);

        $fila_c = $this->fila_de($meses, $c->id);

        $this->assertSame('pagado', $fila_c['meses']['2026-07']['estado']);
        $this->assertSame('pendiente', $fila_c['meses']['2026-08']['estado'], 'Una factura rechazada no es una factura.');
        $this->assertNull($fila_c['meses']['2026-08']['factura']);

        $d = $this->crear_cliente(['mensualidad_inicio' => null]);
        $fila_d = $this->fila_de($meses, $d->id);
        $this->assertSame('no_aplica', $fila_d['meses']['2026-09']['estado'], 'Sin mes de inicio no se le reclama nada.');

        // La cabecera de la respuesta.
        $response = $this->getJson('/api/admin/cobranzas/mensualidades?meses=2026-09,2026-08');
        $response->assertStatus(200);
        $this->assertSame(['2026-08', '2026-09'], $response->json('meses'), 'Los meses vuelven ordenados.');
        $this->assertSame(self::MES_CORRIENTE, $response->json('mes_corriente'));
        $this->assertSame('2026-12', $response->json('rango.hasta'), 'La tira llega hasta el corriente + 3.');
        $this->assertLessThanOrEqual('2026-01', $response->json('rango.desde'), 'La tira arranca en el inicio más viejo.');
    }

    /**
     * `meses` es obligatorio y tiene que ser YYYY-MM.
     *
     * @return void
     */
    public function test_la_tabla_exige_meses_validos(): void
    {
        $this->admin_logueado();

        $this->getJson('/api/admin/cobranzas/mensualidades')->assertStatus(422);
        $this->getJson('/api/admin/cobranzas/mensualidades?meses=2026-13')->assertStatus(422);
        $this->getJson('/api/admin/cobranzas/mensualidades?meses=septiembre')->assertStatus(422);
    }

    /**
     * 2a. Un pago por el total cierra el mes; un pago menor con "cerrar" también lo cierra.
     *
     * @return void
     */
    public function test_registrar_un_pago_completo_deja_el_mes_pagado(): void
    {
        $admin = $this->admin_logueado();
        $client = $this->crear_cliente();

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/pagos', [
            'periodo'    => '2026-09',
            'monto'      => 12000,
            'fecha_pago' => '2026-09-12',
            'medio'      => 'Transferencia',
        ]);

        $response->assertStatus(200);
        $this->assertSame('pagado', $response->json('periodo.estado'));
        $this->assertEqualsWithDelta(12000.0, $response->json('periodo.monto_pagado'), 0.001);
        $this->assertEqualsWithDelta(0.0, $response->json('periodo.saldo'), 0.001);
        $this->assertCount(1, $response->json('periodo.pagos'));
        $this->assertSame($admin->id, $response->json('periodo.pagos.0.admin_id'));

        $fila = MensualidadPeriodo::where('client_id', $client->id)->where('periodo', '2026-09')->first();
        $this->assertNotNull($fila);
        $this->assertSame('pagado', $fila->estado);
        $this->assertEqualsWithDelta(12000.0, (float) $fila->monto_esperado, 0.001, 'La fila nace con el esperado = total del cliente.');

        // Con cerrar_periodo (el default), un pago menor también deja el mes pagado.
        $otro = $this->crear_cliente();
        $response = $this->postJson('/api/admin/client/' . $otro->id . '/mensualidad/pagos', [
            'periodo' => '2026-08',
            'monto'   => 3000,
        ]);
        $response->assertStatus(200);
        $this->assertSame('pagado', $response->json('periodo.estado'), 'Cerrar el mes es decisión de quien registra.');
    }

    /**
     * 2b. Un pago menor sin cerrar deja el mes parcial con saldo; el segundo pago lo completa.
     *
     * @return void
     */
    public function test_registrar_un_pago_parcial_deja_saldo_y_el_segundo_lo_completa(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/pagos', [
            'periodo'        => '2026-09',
            'monto'          => 5000,
            'cerrar_periodo' => false,
        ]);

        $response->assertStatus(200);
        $this->assertSame('parcial', $response->json('periodo.estado'));
        $this->assertEqualsWithDelta(7000.0, $response->json('periodo.saldo'), 0.001);

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/pagos', [
            'periodo'        => '2026-09',
            'monto'          => 7000,
            'cerrar_periodo' => false,
        ]);

        $response->assertStatus(200);
        $this->assertSame('pagado', $response->json('periodo.estado'));
        $this->assertEqualsWithDelta(12000.0, $response->json('periodo.monto_pagado'), 0.001);
        $this->assertCount(2, $response->json('periodo.pagos'));

        // La validación: sin período no hay pago; un monto negativo tampoco.
        $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/pagos', ['monto' => 100])->assertStatus(422);
        $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/pagos', ['periodo' => '2026-09', 'monto' => -1])->assertStatus(422);
    }

    /**
     * 2c. Borrar un pago recalcula: de pagado a parcial, de parcial a pendiente.
     *
     * @return void
     */
    public function test_eliminar_un_pago_recalcula_el_mes(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/pagos', ['periodo' => '2026-09', 'monto' => 5000, 'cerrar_periodo' => false])->assertStatus(200);
        $segundo = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/pagos', ['periodo' => '2026-09', 'monto' => 7000, 'cerrar_periodo' => false]);
        $this->assertSame('pagado', $segundo->json('periodo.estado'));

        $pagos = MensualidadPago::where('client_id', $client->id)->orderBy('id')->get();
        $this->assertCount(2, $pagos);

        $response = $this->deleteJson('/api/admin/client/' . $client->id . '/mensualidad/pagos/' . $pagos[1]->id);
        $response->assertStatus(200);
        $this->assertSame('parcial', $response->json('periodo.estado'));
        $this->assertEqualsWithDelta(7000.0, $response->json('periodo.saldo'), 0.001);

        $response = $this->deleteJson('/api/admin/client/' . $client->id . '/mensualidad/pagos/' . $pagos[0]->id);
        $response->assertStatus(200);
        $this->assertSame('pendiente', $response->json('periodo.estado'));
        $this->assertSame(0, MensualidadPago::where('client_id', $client->id)->count());

        // Un pago de OTRO cliente no se borra desde acá.
        $otro = $this->crear_cliente();
        $ajeno = $this->sembrar_pago($otro, '2026-09', 100);
        $this->deleteJson('/api/admin/client/' . $client->id . '/mensualidad/pagos/' . $ajeno->id)->assertStatus(404);
        $this->assertNotNull(MensualidadPago::find($ajeno->id));
    }

    /**
     * 2d. Un mes que la planilla dio por pagado no se reabre porque se borre un pago cargado después.
     *
     * @return void
     */
    public function test_eliminar_el_ultimo_pago_de_un_mes_importado_no_lo_reabre(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $this->sembrar_periodo($client, '2026-05', MensualidadPeriodo::ESTADO_PAGADO, null, true);
        $pago = $this->sembrar_pago($client, '2026-05', 12000);

        $response = $this->deleteJson('/api/admin/client/' . $client->id . '/mensualidad/pagos/' . $pago->id);

        $response->assertStatus(200);
        $this->assertSame('pagado', $response->json('periodo.estado'), 'Lo cerró la planilla, no el pago.');
        $this->assertTrue($response->json('periodo.importado'));
    }

    /**
     * 2e. Marcar sin cargo y reabrir; el listado del cliente refleja el cambio.
     *
     * @return void
     */
    public function test_marcar_un_mes_sin_cargo_y_reabrirlo(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $response = $this->putJson('/api/admin/client/' . $client->id . '/mensualidad/periodos/2026-09', [
            'estado'      => 'sin_cargo',
            'observacion' => 'Mes bonificado por la migración',
        ]);

        $response->assertStatus(200);
        $this->assertSame('sin_cargo', $response->json('periodo.estado'));
        $this->assertSame('Mes bonificado por la migración', $response->json('periodo.observacion'));

        // El listado del cliente lo muestra igual, y los meses sin fila siguen deducidos.
        $listado = $this->getJson('/api/admin/client/' . $client->id . '/mensualidad/periodos?desde=2026-08&hasta=2026-10');
        $listado->assertStatus(200);
        $periodos = collect($listado->json('periodos'))->keyBy('periodo');
        $this->assertSame('pendiente', $periodos['2026-08']['estado']);
        $this->assertSame('sin_cargo', $periodos['2026-09']['estado']);
        $this->assertSame('futuro', $periodos['2026-10']['estado']);
        $this->assertSame(self::MES_CORRIENTE, $listado->json('mes_corriente'));
        $this->assertSame('2026-01-01', $listado->json('mensualidad_inicio'));

        // Sin rango: desde el inicio del cliente hasta el corriente + 3 (enero a diciembre = 12 meses).
        $default = $this->getJson('/api/admin/client/' . $client->id . '/mensualidad/periodos');
        $default->assertStatus(200);
        $this->assertCount(12, $default->json('periodos'));

        $response = $this->putJson('/api/admin/client/' . $client->id . '/mensualidad/periodos/2026-09', ['estado' => 'pendiente']);
        $response->assertStatus(200);
        $this->assertSame('pendiente', $response->json('periodo.estado'));

        // Parcial no se marca a mano, y el período tiene que ser YYYY-MM.
        $this->putJson('/api/admin/client/' . $client->id . '/mensualidad/periodos/2026-09', ['estado' => 'parcial'])->assertStatus(422);
        $this->putJson('/api/admin/client/' . $client->id . '/mensualidad/periodos/2026-99', ['estado' => 'pagado'])->assertStatus(422);
    }

    /**
     * 3. Las preferencias son por admin: default sin nada guardado, y dos admins no se pisan.
     *
     * @return void
     */
    public function test_las_preferencias_son_por_admin_y_tienen_default(): void
    {
        $primero = $this->admin_logueado('Primero');

        $response = $this->getJson('/api/admin/cobranzas/preferencias');
        $response->assertStatus(200);
        $this->assertSame([self::MES_CORRIENTE], $response->json('meses'), 'Sin nada guardado: el mes corriente.');
        $this->assertSame('carga', $response->json('orden'));

        $response = $this->putJson('/api/admin/cobranzas/preferencias', [
            'meses' => ['2026-09', '2026-07', '2026-09'],
            'orden' => 'sin_pago',
        ]);
        $response->assertStatus(200);
        $this->assertSame(['2026-07', '2026-09'], $response->json('meses'), 'Sin duplicados y ordenados.');
        $this->assertSame('sin_pago', $response->json('orden'));

        $primero->refresh();
        $this->assertSame(['2026-07', '2026-09'], $primero->cobranzas_preferencias['meses']);

        // Validación: sin meses, mes inválido, orden desconocido.
        $this->putJson('/api/admin/cobranzas/preferencias', ['meses' => [], 'orden' => 'carga'])->assertStatus(422);
        $this->putJson('/api/admin/cobranzas/preferencias', ['meses' => ['2026-9'], 'orden' => 'carga'])->assertStatus(422);
        $this->putJson('/api/admin/cobranzas/preferencias', ['meses' => ['2026-09'], 'orden' => 'alfabetico'])->assertStatus(422);

        // Otro admin arranca con el default: lo del primero no lo toca.
        $this->admin_logueado('Segundo');

        $response = $this->getJson('/api/admin/cobranzas/preferencias');
        $response->assertStatus(200);
        $this->assertSame([self::MES_CORRIENTE], $response->json('meses'));
        $this->assertSame('carga', $response->json('orden'));
    }

    /**
     * Las rutas del módulo están detrás de auth:sanctum.
     *
     * @return void
     */
    public function test_sin_sesion_no_se_ve_nada(): void
    {
        $this->getJson('/api/admin/cobranzas/mensualidades?meses=2026-09')->assertStatus(401);
        $this->getJson('/api/admin/cobranzas/licencias')->assertStatus(401);
        $this->getJson('/api/admin/cobranzas/preferencias')->assertStatus(401);
    }
}
