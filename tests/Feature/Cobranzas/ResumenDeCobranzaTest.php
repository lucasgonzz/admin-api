<?php

namespace Tests\Feature\Cobranzas;

use App\Models\Client;
use App\Models\MensualidadPago;
use App\Models\MensualidadPeriodo;
use App\Services\CobranzasMensualidadService;
use Illuminate\Support\Facades\DB;

/**
 * Las dos tarjetas de plata de Mensualidades: «Cobrado» y «Falta cobrar» (misión
 * cobranzas-cobrado-y-falta-cobrar, 16/10/2026).
 *
 * `GET /api/admin/cobranzas/mensualidades` suma una clave `resumen` con la plata relativa al mes de
 * referencia R (el más reciente de los meses pedidos). Lo que estas pruebas protegen:
 *
 *  1. 🔴 **Que el «cobrado en total» sea la caja del mes R**, no un acumulado: lo imputado a R con
 *     cualquier fecha, más lo que entró DURANTE R por meses anteriores. Un pago sin monto, sin fecha
 *     (para lo de meses anteriores), de un mes posterior a R o de un cliente que ya no existe no suma.
 *  2. 🔴 **Que «falta cobrar» use la misma regla de siete estados de la tabla** y no una nueva:
 *     pendiente y facturado deben el esperado, parcial debe el saldo, y pagado, sin cargo, no aplica
 *     y futuro no deben nada. Los meses posteriores a R no entran, ni siquiera uno facturado por
 *     adelantado.
 *  3. **Que sea compatible hacia atrás** (las claves viejas de la respuesta siguen intactas) y
 *     **que no haga una consulta por cliente**.
 *
 * El reloj queda clavado el 15/9/2026 (ver `BaseDeCobranzas`), así que R es 2026-09 salvo que la
 * prueba pida otros meses.
 */
class ResumenDeCobranzaTest extends BaseDeCobranzas
{
    /**
     * Deja la base de testing sin nada que ensucie los totales. Las sumas del resumen son de TODOS
     * los clientes, y la base de testing puede traer clientes o pagos sueltos de antes (al armar el
     * slot había dos clientes sin inicio). Todo esto corre dentro de la transacción de la prueba
     * y se revierte solo al terminar.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Un cliente sin mes de inicio no debe nada: así los que hubiera de antes no cuentan.
        Client::query()->update(['mensualidad_inicio' => null]);

        // Sin pagos previos, para que el cobrado parta de cero.
        MensualidadPago::query()->delete();
    }

    /**
     * Pide la tabla con esos meses y devuelve la respuesta completa.
     *
     * @param array<int, string> $meses
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function pedir(array $meses)
    {
        $response = $this->getJson('/api/admin/cobranzas/mensualidades?meses=' . implode(',', $meses));
        $response->assertStatus(200);

        return $response;
    }

    /**
     * Pide la tabla y devuelve solo el `resumen`.
     *
     * @param array<int, string> $meses Por defecto, solo el mes corriente (R = 2026-09).
     *
     * @return array<string, mixed>
     */
    private function resumen(array $meses = ['2026-09']): array
    {
        return $this->pedir($meses)->json('resumen');
    }

    /**
     * Un pago con la fecha de cobro bajo control. `sembrar_pago()` fija la fecha en 2026-09-10 y acá
     * hace falta poder moverla (y dejarla vacía, como los pagos importados).
     *
     * @param Client      $client
     * @param string      $periodo    Mes al que se imputa, 'YYYY-MM'.
     * @param float|null  $monto      Null = pago importado sin monto.
     * @param string|null $fecha_pago 'YYYY-MM-DD' o null.
     *
     * @return MensualidadPago
     */
    private function pago_con_fecha(Client $client, string $periodo, ?float $monto, ?string $fecha_pago): MensualidadPago
    {
        return MensualidadPago::create([
            'client_id'  => $client->id,
            'periodo'    => $periodo,
            'monto'      => $monto,
            'fecha_pago' => $fecha_pago,
            'medio'      => 'Transferencia',
            'importado'  => $monto === null,
        ]);
    }

    /**
     * 1. «Cobrado del mes»: lo imputado a R suma sin importar cuándo se cobró; los pagos sin monto
     * y los de un cliente que ya no existe, no.
     *
     * @return void
     */
    public function test_el_cobrado_del_mes_suma_lo_imputado_a_R_sea_cual_sea_su_fecha(): void
    {
        $this->admin_logueado();

        $a = $this->crear_cliente(['mensualidad_inicio' => '2026-09-01']);
        $b = $this->crear_cliente(['mensualidad_inicio' => '2026-09-01']);

        $this->pago_con_fecha($a, '2026-09', 5000, '2026-09-10');
        // Cobrado antes de que empiece el mes, pero imputado a septiembre: es de septiembre.
        $this->pago_con_fecha($a, '2026-09', 3000, '2026-08-20');
        // Sin fecha: igual cuenta, porque «del mes» mira el período al que se imputó y no la fecha.
        $this->pago_con_fecha($a, '2026-09', 2000, null);
        // Importado de la planilla sin monto: deja constancia pero no suma.
        $this->pago_con_fecha($a, '2026-09', null, '2026-09-10');
        // Cobrado en octubre, imputado a septiembre.
        $this->pago_con_fecha($b, '2026-09', 4000, '2026-10-02');
        // Un pago huérfano (no hay clave foránea): el cliente ya no existe y no puede inflar la tarjeta.
        MensualidadPago::create(['client_id' => 999999999, 'periodo' => '2026-09', 'monto' => 7777, 'fecha_pago' => '2026-09-10']);

        $cobrado = $this->resumen()['cobrado'];

        $this->assertEqualsWithDelta(14000.0, $cobrado['del_mes'], 0.001, '5.000 + 3.000 + 2.000 + 4.000; el pago sin monto y el huérfano no suman.');
        $this->assertEqualsWithDelta(0.0, $cobrado['de_anteriores'], 0.001);
        $this->assertEqualsWithDelta(14000.0, $cobrado['total'], 0.001);
        $this->assertSame(0, $cobrado['clientes_de_anteriores']);
    }

    /**
     * 2. «Cobrado de anteriores»: un pago de un mes anterior suma solo si se cobró DENTRO de R, con
     * los bordes del mes incluidos. Sin fecha, cobrado en otro mes, sin monto o imputado a un mes
     * posterior a R, no.
     *
     * @return void
     */
    public function test_el_cobrado_de_anteriores_es_lo_que_entro_durante_R_por_meses_viejos(): void
    {
        $this->admin_logueado();

        $a = $this->crear_cliente();
        $b = $this->crear_cliente();

        // Suman: agosto cobrado en septiembre, y los dos bordes del mes (1° y 30).
        $this->pago_con_fecha($a, '2026-08', 3000, '2026-09-03');
        $this->pago_con_fecha($a, '2026-08', 500, '2026-09-01');
        $this->pago_con_fecha($a, '2026-07', 1500, '2026-09-30');
        $this->pago_con_fecha($b, '2026-08', 2000, '2026-09-12');

        // No suman:
        // · agosto cobrado EN agosto (ya entró en la caja de agosto).
        $this->pago_con_fecha($a, '2026-08', 9999, '2026-08-30');
        // · agosto cobrado el 1° de octubre: un día después del mes.
        $this->pago_con_fecha($a, '2026-08', 6666, '2026-10-01');
        // · agosto sin fecha: no se puede ubicar en el mes.
        $this->pago_con_fecha($a, '2026-08', 8888, null);
        // · agosto sin monto, aunque tenga fecha de septiembre.
        $this->pago_con_fecha($a, '2026-08', null, '2026-09-12');
        // · octubre (posterior a R) cobrado en septiembre: el pedido es «mes actual y meses anteriores».
        $this->pago_con_fecha($a, '2026-10', 7777, '2026-09-05');

        $cobrado = $this->resumen()['cobrado'];

        $this->assertEqualsWithDelta(0.0, $cobrado['del_mes'], 0.001, 'Nada está imputado a septiembre.');
        $this->assertEqualsWithDelta(7000.0, $cobrado['de_anteriores'], 0.001, '3.000 + 500 + 1.500 + 2.000.');
        $this->assertEqualsWithDelta(7000.0, $cobrado['total'], 0.001);
        $this->assertSame(2, $cobrado['clientes_de_anteriores'], 'Pagaron deudas viejas el cliente A y el B.');
    }

    /**
     * El total es la suma de las dos mitades: la caja de R.
     *
     * @return void
     */
    public function test_el_cobrado_total_es_la_caja_del_mes_con_las_dos_mitades(): void
    {
        $this->admin_logueado();

        $a = $this->crear_cliente();
        $this->pago_con_fecha($a, '2026-09', 12000, '2026-09-10');
        $this->pago_con_fecha($a, '2026-08', 5000, '2026-09-11');
        // Cobrado en julio por julio: no entra en la caja de septiembre.
        $this->pago_con_fecha($a, '2026-07', 12000, '2026-07-10');

        $cobrado = $this->resumen()['cobrado'];

        $this->assertEqualsWithDelta(12000.0, $cobrado['del_mes'], 0.001);
        $this->assertEqualsWithDelta(5000.0, $cobrado['de_anteriores'], 0.001);
        $this->assertEqualsWithDelta(17000.0, $cobrado['total'], 0.001);
        $this->assertSame(1, $cobrado['clientes_de_anteriores']);
    }

    /**
     * 3. «Falta cobrar de anteriores»: pendiente y facturado deben el esperado, parcial solo el
     * saldo; pagado, sin cargo (aunque tenga factura) y los meses de antes del inicio no deben nada.
     *
     * @return void
     */
    public function test_lo_que_falta_cobrar_de_anteriores_usa_los_estados_de_la_tabla(): void
    {
        $this->admin_logueado();

        // Cobra desde enero, 12.000 por mes. Septiembre (R) está pagado: todo lo que falta es de antes.
        $a = $this->crear_cliente();

        $this->sembrar_periodo($a, '2026-01', MensualidadPeriodo::ESTADO_PAGADO);
        $this->sembrar_periodo($a, '2026-02', MensualidadPeriodo::ESTADO_SIN_CARGO);
        // Marzo: sin fila ni factura ni pago → pendiente, debe 12.000.
        // Abril: factura autorizada y nadie registró el pago → facturado, debe 12.000.
        $this->sembrar_factura($a, '2026-04');
        // Mayo: entraron 5.000 de 12.000 → parcial, debe el saldo de 7.000.
        $this->sembrar_periodo($a, '2026-05', MensualidadPeriodo::ESTADO_PARCIAL, 12000);
        $this->sembrar_pago($a, '2026-05', 5000);
        // Junio: pagado con su pago completo.
        $this->sembrar_periodo($a, '2026-06', MensualidadPeriodo::ESTADO_PAGADO, 12000);
        $this->sembrar_pago($a, '2026-06', 12000);
        // Julio: lo importó la planilla como PAGADO, sin monto.
        $this->sembrar_periodo($a, '2026-07', MensualidadPeriodo::ESTADO_PAGADO, null, true);
        // Agosto: sin cargo, y con una factura encima: lo afirmado le gana a la factura.
        $this->sembrar_periodo($a, '2026-08', MensualidadPeriodo::ESTADO_SIN_CARGO);
        $this->sembrar_factura($a, '2026-08');
        // Septiembre (R): pagado.
        $this->sembrar_periodo($a, '2026-09', MensualidadPeriodo::ESTADO_PAGADO, 12000);

        $falta = $this->resumen()['falta_cobrar'];

        $this->assertEqualsWithDelta(31000.0, $falta['de_anteriores'], 0.001, 'Marzo 12.000 + abril 12.000 + saldo de mayo 7.000.');
        $this->assertSame(3, $falta['meses_de_anteriores']);
        $this->assertSame(1, $falta['clientes_de_anteriores']);
        $this->assertEqualsWithDelta(0.0, $falta['del_mes'], 0.001, 'Septiembre está pagado.');
        $this->assertSame(0, $falta['clientes_del_mes']);
        $this->assertEqualsWithDelta(31000.0, $falta['total'], 0.001);
        $this->assertSame(0, $falta['sin_monto']);
    }

    /**
     * Un cliente sin mes de inicio, o que arranca después de R, no debe nada; y los meses anteriores
     * a su inicio (no aplica) tampoco se le reclaman.
     *
     * @return void
     */
    public function test_un_cliente_no_debe_nada_antes_de_su_inicio_ni_si_nunca_arranco(): void
    {
        $this->admin_logueado();

        // Sin inicio: septiembre le sale «no aplica», no «pendiente».
        $this->crear_cliente(['mensualidad_inicio' => null]);
        // Arranca en octubre, después de R.
        $this->crear_cliente(['mensualidad_inicio' => '2026-10-01']);
        // Arranca en septiembre: solo debe el mes R, no los anteriores.
        $this->crear_cliente(['mensualidad_inicio' => '2026-09-01']);
        // Arranca en agosto: debe agosto (anterior) y septiembre (R).
        $this->crear_cliente(['mensualidad_inicio' => '2026-08-01']);

        $falta = $this->resumen()['falta_cobrar'];

        $this->assertEqualsWithDelta(24000.0, $falta['del_mes'], 0.001, 'Septiembre de los que arrancaron en agosto y septiembre.');
        $this->assertSame(2, $falta['clientes_del_mes']);
        $this->assertEqualsWithDelta(12000.0, $falta['de_anteriores'], 0.001, 'Solo agosto del que arrancó en agosto.');
        $this->assertSame(1, $falta['clientes_de_anteriores']);
        $this->assertSame(1, $falta['meses_de_anteriores']);
        $this->assertEqualsWithDelta(36000.0, $falta['total'], 0.001);
    }

    /**
     * 4. «Falta cobrar del mes»: pendiente, facturado y parcial deben; pagado y sin cargo no.
     *
     * @return void
     */
    public function test_lo_que_falta_cobrar_del_mes_usa_los_estados_de_la_tabla(): void
    {
        $this->admin_logueado();

        // Todos arrancan en septiembre, para que no haya deuda de meses anteriores.
        // Pendiente: sin fila, sin factura y sin pago.
        $this->crear_cliente(['mensualidad_inicio' => '2026-09-01']);

        $parcial = $this->crear_cliente(['mensualidad_inicio' => '2026-09-01']);
        $this->sembrar_periodo($parcial, '2026-09', MensualidadPeriodo::ESTADO_PARCIAL, 12000);
        $this->sembrar_pago($parcial, '2026-09', 5000);

        $facturado = $this->crear_cliente(['mensualidad_inicio' => '2026-09-01']);
        $this->sembrar_factura($facturado, '2026-09');

        $pagado = $this->crear_cliente(['mensualidad_inicio' => '2026-09-01']);
        $this->sembrar_periodo($pagado, '2026-09', MensualidadPeriodo::ESTADO_PAGADO, 12000);
        $this->sembrar_pago($pagado, '2026-09', 12000);

        $sin_cargo = $this->crear_cliente(['mensualidad_inicio' => '2026-09-01']);
        $this->sembrar_periodo($sin_cargo, '2026-09', MensualidadPeriodo::ESTADO_SIN_CARGO);

        $resumen = $this->resumen();
        $falta = $resumen['falta_cobrar'];

        $this->assertEqualsWithDelta(31000.0, $falta['del_mes'], 0.001, 'Pendiente 12.000 + saldo parcial 7.000 + facturado 12.000.');
        $this->assertSame(3, $falta['clientes_del_mes']);
        $this->assertEqualsWithDelta(0.0, $falta['de_anteriores'], 0.001);
        $this->assertSame(0, $falta['clientes_de_anteriores']);
        $this->assertSame(0, $falta['meses_de_anteriores']);
        $this->assertEqualsWithDelta(31000.0, $falta['total'], 0.001);

        // Lo cobrado de septiembre no se mezcla con lo que falta: entraron 5.000 + 12.000.
        $this->assertEqualsWithDelta(17000.0, $resumen['cobrado']['del_mes'], 0.001);
    }

    /**
     * Un mes que alguien reabrió (pendiente afirmado) pero ya tiene pagos solo debe la diferencia, y
     * si se pagó de más no debe nada ni resta de otro.
     *
     * @return void
     */
    public function test_un_mes_reabierto_con_pagos_solo_debe_la_diferencia(): void
    {
        $this->admin_logueado();

        $entro_parte = $this->crear_cliente(['mensualidad_inicio' => '2026-09-01']);
        $this->sembrar_periodo($entro_parte, '2026-09', MensualidadPeriodo::ESTADO_PENDIENTE, 12000);
        $this->sembrar_pago($entro_parte, '2026-09', 5000);

        $pago_de_mas = $this->crear_cliente(['mensualidad_inicio' => '2026-09-01']);
        $this->sembrar_periodo($pago_de_mas, '2026-09', MensualidadPeriodo::ESTADO_PENDIENTE, 12000);
        $this->sembrar_pago($pago_de_mas, '2026-09', 15000);

        $falta = $this->resumen()['falta_cobrar'];

        $this->assertEqualsWithDelta(7000.0, $falta['del_mes'], 0.001, '12.000 - 5.000; el que pagó de más debe 0, no -3.000.');
        $this->assertSame(1, $falta['clientes_del_mes']);
    }

    /**
     * 🔴 Un mes POSTERIOR a R no entra, ni siquiera facturado por adelantado.
     *
     * `deducir_estado()` aplica la regla 4 (factura autorizada) antes que la 6 (el mes no llegó),
     * así que octubre con Factura C sale `facturado` y no `futuro` en la tabla. El resumen recorre
     * hasta R inclusive, así que con R = septiembre esa factura queda afuera; recién cuando octubre
     * pasa a ser R (se elige en la tira) cuenta como deuda del mes.
     *
     * @return void
     */
    public function test_un_mes_futuro_facturado_por_adelantado_no_entra_si_R_es_anterior(): void
    {
        $this->admin_logueado();

        // Septiembre pagado y octubre con factura autorizada.
        $adelantado = $this->crear_cliente(['mensualidad_inicio' => '2026-09-01']);
        $this->sembrar_periodo($adelantado, '2026-09', MensualidadPeriodo::ESTADO_PAGADO, 12000);
        $this->sembrar_factura($adelantado, '2026-10');

        // Septiembre pagado y octubre sin nada: la tabla lo da `futuro`.
        $sin_factura = $this->crear_cliente(['mensualidad_inicio' => '2026-09-01']);
        $this->sembrar_periodo($sin_factura, '2026-09', MensualidadPeriodo::ESTADO_PAGADO, 12000);

        // Con R = septiembre (solo ese mes pedido): octubre no se recorre.
        $con_r_septiembre = $this->resumen(['2026-09']);
        $this->assertSame('2026-09', $con_r_septiembre['mes_referencia']);
        $this->assertEqualsWithDelta(0.0, $con_r_septiembre['falta_cobrar']['del_mes'], 0.001);
        $this->assertEqualsWithDelta(0.0, $con_r_septiembre['falta_cobrar']['de_anteriores'], 0.001);
        $this->assertEqualsWithDelta(0.0, $con_r_septiembre['falta_cobrar']['total'], 0.001, 'La factura de octubre no se cuenta como deuda de septiembre.');
        $this->assertSame(0, $con_r_septiembre['falta_cobrar']['clientes_del_mes']);

        // Con octubre entre los meses pedidos: R = octubre. La tabla confirma los estados deducidos.
        $respuesta = $this->pedir(['2026-09', '2026-10']);
        $estados = [];
        foreach ($respuesta->json('clientes') as $fila) {
            $estados[$fila['id']] = $fila['meses']['2026-10']['estado'];
        }
        $this->assertSame('facturado', $estados[$adelantado->id], 'Un mes futuro con Factura C autorizada es facturado, no futuro.');
        $this->assertSame('futuro', $estados[$sin_factura->id]);

        $con_r_octubre = $respuesta->json('resumen');
        $this->assertSame('2026-10', $con_r_octubre['mes_referencia']);
        $this->assertEqualsWithDelta(12000.0, $con_r_octubre['falta_cobrar']['del_mes'], 0.001, 'Octubre facturado debe; octubre futuro sin factura, no.');
        $this->assertSame(1, $con_r_octubre['falta_cobrar']['clientes_del_mes']);
        $this->assertEqualsWithDelta(0.0, $con_r_octubre['falta_cobrar']['de_anteriores'], 0.001);
    }

    /**
     * 5. Sin monto esperado (cliente sin precios cargados): no se suma ningún importe y cada mes que
     * debe algo se cuenta en `sin_monto`, para que la pantalla avise que el total es un piso.
     *
     * @return void
     */
    public function test_un_mes_que_debe_sin_monto_esperado_se_cuenta_aparte_y_no_suma(): void
    {
        $this->admin_logueado();

        // Sin precios cargados: no hay total_mensualidad al que caer.
        $sin_precios = $this->crear_cliente(['total_mensualidad' => null, 'mensualidad_inicio' => '2026-07-01']);
        // Julio: parcial sin esperado (entraron 3.000, no se sabe de cuánto).
        $this->sembrar_periodo($sin_precios, '2026-07', MensualidadPeriodo::ESTADO_PARCIAL, null);
        $this->sembrar_pago($sin_precios, '2026-07', 3000);
        // Agosto: facturado sin esperado.
        $this->sembrar_factura($sin_precios, '2026-08');
        // Septiembre: pendiente sin esperado.

        // Un cliente normal, para ver que lo demás sigue sumando.
        $this->crear_cliente(['mensualidad_inicio' => '2026-09-01']);

        $resumen = $this->resumen();
        $falta = $resumen['falta_cobrar'];

        $this->assertSame(3, $falta['sin_monto'], 'Julio parcial, agosto facturado y septiembre pendiente, sin esperado.');
        $this->assertEqualsWithDelta(12000.0, $falta['del_mes'], 0.001, 'Solo el cliente con precios suma.');
        $this->assertSame(1, $falta['clientes_del_mes'], 'El que debe sin monto no cuenta como cliente con deuda.');
        $this->assertEqualsWithDelta(0.0, $falta['de_anteriores'], 0.001);
        $this->assertSame(0, $falta['clientes_de_anteriores']);
        $this->assertSame(0, $falta['meses_de_anteriores']);
        $this->assertEqualsWithDelta(12000.0, $falta['total'], 0.001);

        // El pago de 3.000 de julio sí se cobró (en septiembre) aunque no se sepa de cuánto era el mes.
        $this->assertEqualsWithDelta(3000.0, $resumen['cobrado']['de_anteriores'], 0.001);
    }

    /**
     * 6. El mes de referencia es el más reciente de los pedidos, y el resumen se calcula contra ese.
     *
     * @return void
     */
    public function test_el_mes_de_referencia_es_el_mayor_de_los_meses_pedidos(): void
    {
        $this->admin_logueado();

        // Arranca en julio: julio, agosto y septiembre pendientes, 12.000 cada uno.
        $this->crear_cliente(['mensualidad_inicio' => '2026-07-01']);

        // Varios meses, desordenados: R es el mayor.
        $varios = $this->resumen(['2026-07', '2026-09', '2026-08']);
        $this->assertSame('2026-09', $varios['mes_referencia']);
        $this->assertEqualsWithDelta(12000.0, $varios['falta_cobrar']['del_mes'], 0.001);
        $this->assertEqualsWithDelta(24000.0, $varios['falta_cobrar']['de_anteriores'], 0.001, 'Julio y agosto.');
        $this->assertSame(2, $varios['falta_cobrar']['meses_de_anteriores']);

        // Un solo mes: es ese. Con R = agosto, septiembre ya no cuenta.
        $uno = $this->resumen(['2026-08']);
        $this->assertSame('2026-08', $uno['mes_referencia']);
        $this->assertEqualsWithDelta(12000.0, $uno['falta_cobrar']['del_mes'], 0.001, 'Agosto es el mes R.');
        $this->assertEqualsWithDelta(12000.0, $uno['falta_cobrar']['de_anteriores'], 0.001, 'Solo julio.');
        $this->assertEqualsWithDelta(24000.0, $uno['falta_cobrar']['total'], 0.001);

        // Un mes anterior al inicio de todos: no hay deuda.
        $antes = $this->resumen(['2026-06']);
        $this->assertSame('2026-06', $antes['mes_referencia']);
        $this->assertEqualsWithDelta(0.0, $antes['falta_cobrar']['total'], 0.001);
    }

    /**
     * 7. Contrato del endpoint: la clave `resumen` está completa y con los tipos prometidos, y las
     * claves de siempre siguen donde estaban (compatible hacia atrás con el SPA viejo).
     *
     * @return void
     */
    public function test_el_endpoint_suma_resumen_y_no_toca_las_claves_de_siempre(): void
    {
        $this->admin_logueado();

        $cliente = $this->crear_cliente();
        $this->sembrar_periodo($cliente, '2026-09', MensualidadPeriodo::ESTADO_PARCIAL, 12000);
        $this->sembrar_pago($cliente, '2026-09', 5000);

        $respuesta = $this->pedir(['2026-08', '2026-09']);

        // Las claves de siempre, intactas.
        $respuesta->assertJsonStructure([
            'meses',
            'mes_corriente',
            'clientes' => [
                '*' => [
                    'id', 'nombre', 'name', 'company_name', 'is_active', 'mensualidad_inicio', 'total_mensualidad',
                    'cantidad_empleados', 'tiene_ecommerce', 'afip_cuit', 'facturar_consumidor_final', 'phone',
                    'phone_whatsapp', 'cobranzas_observaciones', 'actualizacion',
                    'meses' => ['*' => ['periodo', 'estado', 'monto_esperado', 'monto_pagado', 'saldo', 'factura', 'observacion', 'importado']],
                ],
            ],
            'rango' => ['desde', 'hasta'],
            // La clave nueva, completa.
            'resumen' => [
                'mes_referencia',
                'cobrado' => ['del_mes', 'de_anteriores', 'total', 'clientes_de_anteriores'],
                'falta_cobrar' => [
                    'del_mes', 'de_anteriores', 'total', 'clientes_del_mes', 'clientes_de_anteriores',
                    'meses_de_anteriores', 'sin_monto',
                ],
            ],
        ]);

        $this->assertSame(['2026-08', '2026-09'], $respuesta->json('meses'));
        $this->assertSame(self::MES_CORRIENTE, $respuesta->json('mes_corriente'));
        $this->assertSame('2026-12', $respuesta->json('rango.hasta'));

        // Los tipos prometidos, tal como llegan por el cable: los montos son números JSON (nunca texto)
        // y los conteos enteros. OJO: en JSON un monto sin decimales como 5000.0 viaja como `5000`
        // (Laravel no preserva el «.0») y se lee como int; para el front, que es JS, es lo mismo. Que
        // el servicio devuelve `float` de verdad se prueba aparte, sin pasar por el JSON.
        $resumen = $respuesta->json('resumen');
        $this->assertSame('2026-09', $resumen['mes_referencia']);

        foreach (['del_mes', 'de_anteriores', 'total'] as $clave) {
            $this->assertTrue(
                is_int($resumen['cobrado'][$clave]) || is_float($resumen['cobrado'][$clave]),
                'cobrado.' . $clave . ' es un monto numérico, no un texto.'
            );
            $this->assertTrue(
                is_int($resumen['falta_cobrar'][$clave]) || is_float($resumen['falta_cobrar'][$clave]),
                'falta_cobrar.' . $clave . ' es un monto numérico, no un texto.'
            );
        }

        $this->assertIsInt($resumen['cobrado']['clientes_de_anteriores']);
        foreach (['clientes_del_mes', 'clientes_de_anteriores', 'meses_de_anteriores', 'sin_monto'] as $clave) {
            $this->assertIsInt($resumen['falta_cobrar'][$clave], 'falta_cobrar.' . $clave . ' es un conteo.');
        }

        // Y los números del caso: entraron 5.000 de septiembre; falta el saldo de 7.000 y los meses
        // anteriores sin pagar (enero a agosto, 8 meses de 12.000).
        $this->assertEqualsWithDelta(5000.0, $resumen['cobrado']['del_mes'], 0.001);
        $this->assertEqualsWithDelta(7000.0, $resumen['falta_cobrar']['del_mes'], 0.001);
        $this->assertEqualsWithDelta(96000.0, $resumen['falta_cobrar']['de_anteriores'], 0.001);
        $this->assertSame(8, $resumen['falta_cobrar']['meses_de_anteriores']);
    }

    /**
     * Sin ningún cliente que deba ni pago que sumar, todas las claves siguen presentes, en cero.
     *
     * @return void
     */
    public function test_sin_datos_todas_las_claves_estan_en_cero(): void
    {
        $this->admin_logueado();

        // Por el endpoint, que es lo que lee el SPA: las mismas claves y los mismos valores (el 0.0
        // viaja como `0` por el JSON, así que acá se compara sin exigir el tipo).
        $por_http = $this->resumen();
        $this->assertSame('2026-09', $por_http['mes_referencia']);
        $this->assertEquals(
            ['del_mes' => 0, 'de_anteriores' => 0, 'total' => 0, 'clientes_de_anteriores' => 0],
            $por_http['cobrado']
        );
        $this->assertEquals(
            [
                'del_mes' => 0, 'de_anteriores' => 0, 'total' => 0, 'clientes_del_mes' => 0,
                'clientes_de_anteriores' => 0, 'meses_de_anteriores' => 0, 'sin_monto' => 0,
            ],
            $por_http['falta_cobrar']
        );

        // Directo del servicio, sin JSON de por medio: los montos son float y los conteos int,
        // tal como promete el contrato.
        $resumen = app(CobranzasMensualidadService::class)->resumen_cobranza('2026-09');

        $this->assertSame('2026-09', $resumen['mes_referencia']);
        $this->assertSame(
            ['del_mes' => 0.0, 'de_anteriores' => 0.0, 'total' => 0.0, 'clientes_de_anteriores' => 0],
            $resumen['cobrado']
        );
        $this->assertSame(
            [
                'del_mes' => 0.0, 'de_anteriores' => 0.0, 'total' => 0.0, 'clientes_del_mes' => 0,
                'clientes_de_anteriores' => 0, 'meses_de_anteriores' => 0, 'sin_monto' => 0,
            ],
            $resumen['falta_cobrar']
        );
    }

    /**
     * El servicio rechaza un mes de referencia que no es 'YYYY-MM' en vez de calcular cualquier cosa.
     *
     * @return void
     */
    public function test_el_servicio_rechaza_un_mes_de_referencia_invalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(CobranzasMensualidadService::class)->resumen_cobranza('septiembre');
    }

    /**
     * Siembra `$cantidad` clientes con de todo —pagado, facturado, pendiente y un pago de un mes
     * anterior cobrado en septiembre— para que las cuatro cargas del resumen (filas, facturas,
     * pagos y clientes) tengan datos de cada cliente.
     *
     * @param int $cantidad
     *
     * @return void
     */
    private function sembrar_clientes_variados(int $cantidad): void
    {
        for ($i = 0; $i < $cantidad; $i++) {
            $cliente = $this->crear_cliente(['mensualidad_inicio' => '2026-06-01']);

            // Junio pagado con su pago; julio facturado; agosto parcial (con un pago cobrado en septiembre);
            // septiembre (R) pendiente.
            $this->sembrar_periodo($cliente, '2026-06', MensualidadPeriodo::ESTADO_PAGADO, 12000);
            // Cobrado en su propio mes (junio): no entra en la caja de septiembre.
            $this->pago_con_fecha($cliente, '2026-06', 12000, '2026-06-10');
            $this->sembrar_factura($cliente, '2026-07');
            $this->sembrar_periodo($cliente, '2026-08', MensualidadPeriodo::ESTADO_PARCIAL, 12000);
            $this->pago_con_fecha($cliente, '2026-08', 4000, '2026-09-08');
        }
    }

    /**
     * Cuenta las consultas que dispara el resumen del servicio para el mes corriente.
     *
     * @return int
     */
    private function consultas_del_resumen(): int
    {
        $service = app(CobranzasMensualidadService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->resumen_cobranza('2026-09');
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $consultas;
    }

    /**
     * 8. Sin N+1: la cantidad de consultas del resumen es la misma con 2 clientes que con 10.
     * Si alguien le agrega una consulta por cliente o por mes, esta prueba lo agarra.
     *
     * @return void
     */
    public function test_la_cantidad_de_consultas_no_crece_con_la_cantidad_de_clientes(): void
    {
        $this->admin_logueado();

        $this->sembrar_clientes_variados(2);
        $con_dos = $this->consultas_del_resumen();

        $this->sembrar_clientes_variados(8);
        $con_diez = $this->consultas_del_resumen();

        $this->assertGreaterThan(0, $con_dos, 'El resumen tiene que haber consultado la base.');
        $this->assertSame($con_dos, $con_diez, 'Con 10 clientes hizo ' . $con_diez . ' consultas y con 2, ' . $con_dos . ': hay una consulta por cliente.');

        // Y con los 10 clientes sembrados los números cierran: se sabe qué se está midiendo.
        $resumen = app(CobranzasMensualidadService::class)->resumen_cobranza('2026-09');
        $this->assertEqualsWithDelta(120000.0, $resumen['falta_cobrar']['del_mes'], 0.001, 'Septiembre pendiente: 10 × 12.000.');
        $this->assertEqualsWithDelta(10 * (12000 + 8000), $resumen['falta_cobrar']['de_anteriores'], 0.001, 'Julio facturado 12.000 + saldo de agosto 8.000, por 10.');
        $this->assertEqualsWithDelta(40000.0, $resumen['cobrado']['de_anteriores'], 0.001, '10 pagos de 4.000 de agosto cobrados en septiembre.');
    }

    /**
     * 9. Un mes de referencia más allá de lo que ofrece la tira (el corriente + 3) no calcula el
     * resumen: el recorrido de «falta cobrar» crece con los meses hasta ese mes, y un `9999-12` por
     * la API a mano tardaba minutos y terminaba en un 500. El resto de la respuesta sigue intacto.
     * El borde es exacto: con el reloj en septiembre, la tira llega hasta diciembre.
     *
     * @return void
     */
    public function test_un_mes_de_referencia_mas_alla_de_la_tira_no_calcula_el_resumen(): void
    {
        $this->admin_logueado();

        $this->crear_cliente();

        // Diciembre es el último mes de la tira (septiembre + 3): todavía hay resumen.
        $this->assertIsArray($this->resumen(['2026-12']), 'Diciembre está dentro de la tira.');

        // Enero ya no: resumen null, y la tabla de siempre sale completa.
        $respuesta = $this->pedir(['2027-01']);
        $this->assertNull($respuesta->json('resumen'), 'Enero de 2027 pasa del corriente + 3.');
        $this->assertSame('2026-09', $respuesta->json('mes_corriente'));
        $this->assertNotEmpty($respuesta->json('clientes'), 'La tabla sigue viniendo.');

        // El caso que lo motivó: un mes absurdo responde enseguida y sin error.
        $this->assertNull($this->pedir(['9999-12'])->json('resumen'));
    }

    /**
     * 10. Los meses `pagado` y `sin_cargo` no deben nada y NO se cuentan como «sin monto», aunque
     * el cliente no tenga precios cargados: «sin monto» es solo para un mes que SÍ debe y no se sabe
     * cuánto. Fija que el atajo de rendimiento de `resumen_falta_cobrar()` (saltear esos dos
     * estados sin armar la celda) da lo mismo que armarla.
     *
     * @return void
     */
    public function test_los_meses_pagados_o_sin_cargo_de_un_cliente_sin_precio_no_cuentan_como_sin_monto(): void
    {
        $this->admin_logueado();

        // Sin precios cargados: ningún mes tiene monto esperado salvo que la fila lo diga.
        $cliente = $this->crear_cliente(['total_mensualidad' => null, 'mensualidad_inicio' => '2026-06-01']);

        $this->sembrar_periodo($cliente, '2026-06', MensualidadPeriodo::ESTADO_PAGADO, null, true);
        $this->sembrar_periodo($cliente, '2026-07', MensualidadPeriodo::ESTADO_SIN_CARGO);
        // Agosto pagado y con factura encima: lo afirmado gana, no debe nada.
        $this->sembrar_periodo($cliente, '2026-08', MensualidadPeriodo::ESTADO_PAGADO);
        $this->sembrar_factura($cliente, '2026-08');
        // Septiembre (R): pagado.
        $this->sembrar_periodo($cliente, '2026-09', MensualidadPeriodo::ESTADO_PAGADO);

        $falta = $this->resumen()['falta_cobrar'];

        $this->assertSame(0, $falta['sin_monto'], 'Ningún mes de este cliente debe algo: no hay nada que no se sepa cuánto es.');
        $this->assertEqualsWithDelta(0.0, $falta['total'], 0.001);
        $this->assertSame(0, $falta['meses_de_anteriores']);
        $this->assertSame(0, $falta['clientes_del_mes']);

        // En cambio, un mes que SÍ debe y no tiene monto, sí cuenta como sin monto: se reabre
        // septiembre (la fila es única por cliente y mes, así que se actualiza, no se agrega otra).
        MensualidadPeriodo::where('client_id', $cliente->id)->where('periodo', '2026-09')
            ->update(['estado' => MensualidadPeriodo::ESTADO_PENDIENTE]);

        $this->assertSame(1, $this->resumen()['falta_cobrar']['sin_monto'], 'Septiembre pendiente sin precio: debe y no se sabe cuánto.');
    }
}
