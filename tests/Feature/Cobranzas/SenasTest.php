<?php

namespace Tests\Feature\Cobranzas;

use App\Models\Client;
use App\Models\Lead;
use App\Models\LicenciaCuota;
use App\Models\Sena;
use App\Models\Venta;
use App\Services\ClientContratoService;
use App\Services\RunUserSetupService;
use App\Services\SenaService;
use Illuminate\Support\Facades\Storage;

/**
 * Las señas desde el panel del admin (misión senas-en-ventas, 30/10/2026): un pago a cuenta que se
 * aplica sola a las cuotas pendientes más próximas de la venta, y que también se puede registrar en
 * un lead antes de promoverlo.
 *
 * Lo que estas pruebas protegen, en orden de importancia:
 *
 *  1. 🔴 **La seña se reparte en las cuotas en orden** (vencimiento, con los nulos al final, y
 *     luego número) y baja lo pendiente exactamente en su monto: 100 sobre 4 × 325 deja la primera
 *     cuota parcial; 400 cubre la primera y 75 de la segunda.
 *  2. 🔴 **La cuenta de plata sigue siendo una sola**: la comisión liberada sube proporcional a lo
 *     cobrado, `resumen.total/cobrado/pendiente` no cambian de significado y la seña ya está dentro
 *     de `cobrado`.
 *  3. 🔴 **Una seña no pasa lo pendiente** (422) ni vale cero o menos, y un rechazo no escribe nada.
 *  4. **La seña de un lead sin promover queda pendiente y se aplica sola al promover**, con
 *     `aplicada_at`; un fallo de la aplicación NO rompe la promoción; un lead ya promovido no
 *     recibe señas.
 *  5. Los campos nuevos del JSON (`senas`, `resumen.sena_total`) y las cuotas con su nota.
 *
 * El reloj queda clavado el 15/9/2026 (ver `BaseDeCobranzas`).
 */
class SenasTest extends BaseDeVentas
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Mismo motivo que VentaDesdeElContratoTest: nada del contrato puede leer el disco real.
        Storage::fake('local');

        $this->admin_logueado();
    }

    /**
     * Venta de USD 1.300 en cuatro cuotas de 325 (el caso de Sebastián), con el 15 % para el vendedor
     * único (comisión total 195).
     *
     * @param Client $client
     *
     * @return int
     */
    private function venta_de_cuatro_cuotas(Client $client): int
    {
        $alta = $this->alta_de_venta($client, [
            'productos'       => ['gestion'],
            'monto_total'     => 1300,
            'cantidad_cuotas' => 4,
        ]);
        $alta->assertStatus(201);

        return (int) $alta->json('venta.id');
    }

    /**
     * Registra una seña en una venta por la ruta del panel.
     *
     * @param int                  $venta_id
     * @param array<string, mixed> $cuerpo
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function sena_en_venta(int $venta_id, array $cuerpo)
    {
        return $this->postJson('/api/admin/ventas/' . $venta_id . '/senas', $cuerpo);
    }

    /**
     * Registra una seña en un lead por la ruta del panel.
     *
     * @param int                  $lead_id
     * @param array<string, mixed> $cuerpo
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function sena_en_lead(int $lead_id, array $cuerpo)
    {
        return $this->postJson('/api/admin/leads/' . $lead_id . '/senas', $cuerpo);
    }

    /**
     * Un lead ganado con contrato: licencia de 1.500 financiada en dos cuotas (1.000 y 500).
     *
     * @param array<string, mixed> $atributos
     *
     * @return Lead
     */
    private function crear_lead_con_contrato(array $atributos = []): Lead
    {
        $lead = new Lead();
        $lead->contact_name                       = 'Prospecto con seña';
        $lead->company_name                       = 'Comercio con seña ' . uniqid();
        $lead->email                              = 'sena@test.local';
        $lead->status                             = 'cerrado_ganado';
        $lead->contract_client_name               = 'Juana Pérez';
        $lead->contract_currency                  = 'USD';
        $lead->contract_precio_licencia           = '1500';
        $lead->contract_fecha_emision             = '2026-09-10';
        $lead->contract_fecha_primer_pago_unico   = '2026-10-05';
        $lead->contract_financiacion              = [
            ['monto' => '1000', 'fecha' => '2026-10-05'],
            ['monto' => '500', 'fecha' => '2026-11-05'],
        ];
        $lead->contract_fecha_primer_pago_mensual = '2026-10-10';

        foreach ($atributos as $campo => $valor) {
            $lead->{$campo} = $valor;
        }

        $lead->save();

        return $lead->refresh();
    }

    /* ------------------------------------------------------------------------------------------
     | Seña en una venta
     |------------------------------------------------------------------------------------------ */

    /**
     * 🔴 El caso de Sebastián: una seña de 100 sobre 4 × 325 deja la primera cuota parcial con 100
     * pagados, baja lo pendiente en 100 y no toca las otras tres cuotas. La seña viaja en `senas`.
     *
     * @return void
     */
    public function test_una_sena_de_100_deja_la_primera_cuota_parcial_y_baja_lo_pendiente_en_100(): void
    {
        $this->crear_vendedor();
        $client = $this->crear_cliente(['name' => 'Sebastián']);
        $venta_id = $this->venta_de_cuatro_cuotas($client);

        $respuesta = $this->sena_en_venta($venta_id, ['monto' => 100, 'fecha' => '2026-09-12', 'nota' => 'Seña por transferencia']);

        $respuesta->assertStatus(201);
        $this->assertEqualsWithDelta(100.0, $respuesta->json('sena.monto'), 0.001);
        $this->assertSame('USD', $respuesta->json('sena.moneda'));
        $this->assertSame('2026-09-12', $respuesta->json('sena.fecha'));
        $this->assertSame('manual', $respuesta->json('sena.origen'));
        $this->assertTrue($respuesta->json('sena.aplicada'));

        $cuotas = $this->cuotas_de($venta_id)->values();
        $this->assertSame('parcial', $cuotas[0]->estado);
        $this->assertEqualsWithDelta(100.0, (float) $cuotas[0]->monto_pagado, 0.001);
        $this->assertSame('2026-09-12', $cuotas[0]->fecha_pago->toDateString());
        $this->assertSame('Seña', $cuotas[0]->observacion);
        foreach ([1, 2, 3] as $i) {
            $this->assertSame('pendiente', $cuotas[$i]->estado, 'Las demás cuotas no se tocan.');
            $this->assertNull($cuotas[$i]->monto_pagado);
        }

        // La venta de la respuesta y la de la lista del cliente cuentan lo mismo.
        $this->assertEqualsWithDelta(1300.0, $respuesta->json('venta.resumen.total'), 0.001);
        $this->assertEqualsWithDelta(100.0, $respuesta->json('venta.resumen.cobrado'), 0.001);
        $this->assertEqualsWithDelta(1200.0, $respuesta->json('venta.resumen.pendiente'), 0.001);
        $this->assertEqualsWithDelta(100.0, $respuesta->json('venta.resumen.sena_total'), 0.001);
        $this->assertCount(1, $respuesta->json('venta.senas'));
        $this->assertSame($respuesta->json('sena.id'), $respuesta->json('venta.senas.0.id'));
        $this->assertSame('Seña por transferencia', $respuesta->json('venta.senas.0.nota'));
        $this->assertCount(1, $respuesta->json('ventas'), 'La respuesta trae la lista entera del cliente.');

        $en_la_lista = $this->venta_de_la_lista($client, $venta_id);
        $this->assertEqualsWithDelta(1200.0, $en_la_lista['resumen']['pendiente'], 0.001);
        $this->assertEqualsWithDelta(100.0, $en_la_lista['resumen']['sena_total'], 0.001);

        // La fila guarda a qué cuota fue.
        $fila = Sena::firstOrFail();
        $this->assertSame($venta_id, $fila->venta_id);
        $this->assertSame($client->id, $fila->client_id);
        $this->assertNotNull($fila->aplicada_at);
        // La columna es JSON: vuelve con las claves en otro orden y 100 como entero, por eso se compara sin ser estricto.
        $this->assertEquals([['cuota_id' => $cuotas[0]->id, 'monto' => 100.0]], $fila->aplicaciones);
    }

    /**
     * 🔴 Una seña de 400 sobre 4 × 325 reparte en orden: cubre la primera cuota entera y 75 de la
     * segunda. Una segunda seña sigue por donde quedó la primera.
     *
     * @return void
     */
    public function test_una_sena_de_400_cubre_la_primera_cuota_y_75_de_la_segunda(): void
    {
        $this->crear_vendedor();
        $client = $this->crear_cliente(['name' => 'Cliente de señas']);
        $venta_id = $this->venta_de_cuatro_cuotas($client);

        $respuesta = $this->sena_en_venta($venta_id, ['monto' => 400]);
        $respuesta->assertStatus(201);

        $cuotas = $this->cuotas_de($venta_id)->values();
        $this->assertSame('pagada', $cuotas[0]->estado);
        $this->assertEqualsWithDelta(325.0, (float) $cuotas[0]->monto_pagado, 0.001);
        $this->assertSame('parcial', $cuotas[1]->estado);
        $this->assertEqualsWithDelta(75.0, (float) $cuotas[1]->monto_pagado, 0.001);
        $this->assertSame('pendiente', $cuotas[2]->estado);
        $this->assertEqualsWithDelta(900.0, $respuesta->json('venta.resumen.pendiente'), 0.001);

        $this->assertEqualsWithDelta(325.0, $respuesta->json('sena.aplicaciones.0.monto'), 0.001);
        $this->assertSame($cuotas[0]->id, $respuesta->json('sena.aplicaciones.0.cuota_id'));
        $this->assertEqualsWithDelta(75.0, $respuesta->json('sena.aplicaciones.1.monto'), 0.001);
        $this->assertSame($cuotas[1]->id, $respuesta->json('sena.aplicaciones.1.cuota_id'));

        // Otra seña de 300: termina la segunda (250) y sigue con 50 de la tercera.
        $segunda = $this->sena_en_venta($venta_id, ['monto' => 300]);
        $segunda->assertStatus(201);
        $cuotas = $this->cuotas_de($venta_id)->values();
        $this->assertSame('pagada', $cuotas[1]->estado);
        $this->assertSame('parcial', $cuotas[2]->estado);
        $this->assertEqualsWithDelta(50.0, (float) $cuotas[2]->monto_pagado, 0.001);
        $this->assertEqualsWithDelta(700.0, $segunda->json('venta.resumen.cobrado'), 0.001);
        $this->assertEqualsWithDelta(700.0, $segunda->json('venta.resumen.sena_total'), 0.001);
        $this->assertCount(2, $segunda->json('venta.senas'));
    }

    /**
     * 🔴 La comisión liberada sube proporcional a lo cobrado: la seña no tiene lógica de comisión
     * propia. Comisión total 195 (15 % de 1.300): con 100 cobrados se liberan 15; con 500, 75.
     *
     * @return void
     */
    public function test_la_comision_liberada_sube_proporcional_a_la_sena(): void
    {
        $vendedor = $this->crear_vendedor();
        $client = $this->crear_cliente(['name' => 'Cliente de comisión']);
        $venta_id = $this->venta_de_cuatro_cuotas($client);

        $antes = $this->venta_de_la_lista($client, $venta_id);
        $this->assertSame($vendedor->id, $antes['comision']['vendedor_admin_id']);
        $this->assertEqualsWithDelta(195.0, $antes['comision']['total'], 0.001);
        $this->assertEqualsWithDelta(0.0, $antes['comision']['liberada'], 0.001);

        $primera = $this->sena_en_venta($venta_id, ['monto' => 100]);
        $this->assertEqualsWithDelta(15.0, $primera->json('venta.comision.liberada'), 0.001);
        $this->assertEqualsWithDelta(15.0, $primera->json('venta.comision.a_pagar'), 0.001);

        $segunda = $this->sena_en_venta($venta_id, ['monto' => 400]);
        $this->assertEqualsWithDelta(75.0, $segunda->json('venta.comision.liberada'), 0.001);
        $this->assertEqualsWithDelta(120.0, $segunda->json('venta.comision.por_liberar'), 0.001);
    }

    /**
     * 🔴 Una seña mayor a lo pendiente es 422 y no escribe NADA: ni la fila, ni un pago en las
     * cuotas. Sirve igual con lo pendiente ya bajado por otros cobros, y una seña por EXACTAMENTE lo
     * pendiente salda la venta.
     *
     * @return void
     */
    public function test_una_sena_mayor_a_lo_pendiente_es_422_y_no_escribe(): void
    {
        $this->crear_vendedor();
        $client = $this->crear_cliente(['name' => 'Cliente con deuda']);
        $venta_id = $this->venta_de_cuatro_cuotas($client);
        $antes = $this->cuotas_de($venta_id)->map->only(['id', 'estado', 'monto_pagado', 'fecha_pago', 'observacion'])->all();

        $exceso = $this->sena_en_venta($venta_id, ['monto' => 1300.01]);
        $exceso->assertStatus(422);
        $this->assertArrayHasKey('monto', $exceso->json('errors'));
        $this->assertStringContainsString('supera lo pendiente', $exceso->json('errors.monto.0'));

        $this->assertSame(0, Sena::count(), 'Un rechazo no deja ninguna seña.');
        $this->assertSame($antes, $this->cuotas_de($venta_id)->map->only(['id', 'estado', 'monto_pagado', 'fecha_pago', 'observacion'])->all(), 'Ni toca las cuotas.');

        // Con la primera cuota ya cobrada por la vía de siempre, lo pendiente es 975.
        $cuota1 = $this->cuotas_de($venta_id)->first();
        $this->postJson('/api/admin/client/' . $client->id . '/licencias/' . $cuota1->id . '/pago', ['monto_pagado' => 325])
            ->assertStatus(200);

        $this->sena_en_venta($venta_id, ['monto' => 1000])->assertStatus(422);
        $this->assertSame(0, Sena::count());

        $exacta = $this->sena_en_venta($venta_id, ['monto' => 975]);
        $exacta->assertStatus(201);
        $this->assertEqualsWithDelta(0.0, $exacta->json('venta.resumen.pendiente'), 0.001);
        $this->assertEqualsWithDelta(1300.0, $exacta->json('venta.resumen.cobrado'), 0.001);
        $this->assertEqualsWithDelta(975.0, $exacta->json('venta.resumen.sena_total'), 0.001, 'Solo la seña cuenta como seña, no el pago de la primera cuota.');

        // Una venta saldada ya no admite señas.
        $saldada = $this->sena_en_venta($venta_id, ['monto' => 1]);
        $saldada->assertStatus(422);
        $this->assertStringContainsString('nada pendiente', $saldada->json('errors.monto.0'));
        $this->assertSame(1, Sena::count());
    }

    /**
     * Una seña de monto cero, negativo, no numérico o ausente es 422, y no escribe.
     *
     * @return void
     */
    public function test_una_sena_de_monto_cero_o_negativo_es_422(): void
    {
        $this->crear_vendedor();
        $client = $this->crear_cliente(['name' => 'Cliente de montos']);
        $venta_id = $this->venta_de_cuatro_cuotas($client);

        foreach ([0, -50, 'cien', null] as $monto) {
            $this->sena_en_venta($venta_id, ['monto' => $monto])->assertStatus(422);
        }
        $this->sena_en_venta($venta_id, [])->assertStatus(422);

        // Una nota de más de 255 o una fecha imposible tampoco pasan.
        $this->sena_en_venta($venta_id, ['monto' => 10, 'nota' => str_repeat('x', 256)])->assertStatus(422);
        $this->sena_en_venta($venta_id, ['monto' => 10, 'fecha' => 'no-es-fecha'])->assertStatus(422);

        $this->assertSame(0, Sena::count());
        $this->assertSame(0, LicenciaCuota::where('venta_id', $venta_id)->whereNotNull('monto_pagado')->count());
    }

    /**
     * La venta o el lead que no existen son 404 (en la seña y en el listado), y no escriben nada.
     *
     * @return void
     */
    public function test_una_venta_o_un_lead_inexistente_es_404(): void
    {
        $this->sena_en_venta(99999999, ['monto' => 10])->assertStatus(404);
        $this->sena_en_lead(99999999, ['monto' => 10])->assertStatus(404);
        $this->getJson('/api/admin/leads/99999999/senas')->assertStatus(404);

        $this->assertSame(0, Sena::count());
    }

    /**
     * 🔴 El orden de aplicación: la cuota SIN vencimiento va al final, aunque tenga el número más
     * bajo; con el mismo vencimiento, manda el número. Las cuotas ya pagadas se saltean.
     *
     * @return void
     */
    public function test_el_orden_es_por_vencimiento_con_los_nulos_al_final_y_luego_por_numero(): void
    {
        $this->crear_vendedor();
        $client = $this->crear_cliente(['name' => 'Cliente del orden']);
        $venta_id = $this->venta_de_cuatro_cuotas($client);
        $cuotas = $this->cuotas_de($venta_id)->values();

        // Cuota 1 sin vencimiento; 2 y 3 vencen el mismo día; 4 vence antes que todas.
        $cuotas[0]->update(['vencimiento' => null]);
        $cuotas[1]->update(['vencimiento' => '2026-12-01']);
        $cuotas[2]->update(['vencimiento' => '2026-12-01']);
        $cuotas[3]->update(['vencimiento' => '2026-10-01']);

        // 325 + 100: cubre la 4 (la más próxima) y entra 100 en la 2 (mismo vencimiento que la 3, número menor).
        $respuesta = $this->sena_en_venta($venta_id, ['monto' => 425]);
        $respuesta->assertStatus(201);

        $fresco = $this->cuotas_de($venta_id)->values();
        $this->assertSame('pagada', $fresco[3]->estado, 'La de vencimiento más próximo va primero.');
        $this->assertSame('parcial', $fresco[1]->estado, 'Con el mismo vencimiento, el número menor.');
        $this->assertEqualsWithDelta(100.0, (float) $fresco[1]->monto_pagado, 0.001);
        $this->assertSame('pendiente', $fresco[2]->estado);
        $this->assertSame('pendiente', $fresco[0]->estado, 'La que no tiene vencimiento queda para el final.');

        // Una seña grande llega hasta la cuota sin vencimiento, y salta las pagadas.
        $this->sena_en_venta($venta_id, ['monto' => 875])->assertStatus(201);
        $fresco = $this->cuotas_de($venta_id)->values();
        foreach ($fresco as $cuota) {
            $this->assertSame('pagada', $cuota->estado);
        }
    }

    /**
     * Los redondeos a 2 decimales: una venta de 2.800,02 en seis cuotas (466,67 las primeras) cubierta
     * con señas que no caen justo en el límite de una cuota deja todo cuadrado al centavo.
     *
     * @return void
     */
    public function test_los_redondeos_cuadran_al_centavo(): void
    {
        $this->crear_vendedor();
        $client = $this->crear_cliente(['name' => 'Cliente de centavos']);
        $alta = $this->alta_de_venta($client, ['productos' => ['desarrollo'], 'descripcion' => 'A medida', 'monto_total' => 2800.02, 'cantidad_cuotas' => 6]);
        $alta->assertStatus(201);
        $venta_id = (int) $alta->json('venta.id');

        $this->sena_en_venta($venta_id, ['monto' => 466.67])->assertStatus(201);
        $this->assertSame('pagada', $this->cuotas_de($venta_id)->first()->estado, 'Una seña igual al monto de la cuota la deja pagada.');

        $this->sena_en_venta($venta_id, ['monto' => 0.01])->assertStatus(201);
        $this->sena_en_venta($venta_id, ['monto' => 933.33])->assertStatus(201);

        $venta = $this->venta_de_la_lista($client, $venta_id);
        $this->assertEqualsWithDelta(1400.01, $venta['resumen']['cobrado'], 0.0001);
        $this->assertEqualsWithDelta(1400.01, $venta['resumen']['sena_total'], 0.0001);
        $this->assertEqualsWithDelta(1400.01, $venta['resumen']['pendiente'], 0.0001);
        $this->assertEqualsWithDelta(2800.02, $venta['resumen']['cobrado'] + $venta['resumen']['pendiente'], 0.0001);

        // Lo que falta justo (1400,01) la salda; un centavo más, no.
        $this->sena_en_venta($venta_id, ['monto' => 1400.02])->assertStatus(422);
        $this->sena_en_venta($venta_id, ['monto' => 1400.01])->assertStatus(201);
        $this->assertEqualsWithDelta(0.0, $this->venta_de_la_lista($client, $venta_id)['resumen']['pendiente'], 0.0001);
    }

    /**
     * La moneda de la seña es la de la venta, y la observación que la cuota ya tenía no se pierde.
     *
     * @return void
     */
    public function test_la_moneda_es_la_de_la_venta_y_se_conserva_la_observacion_de_la_cuota(): void
    {
        $this->crear_vendedor();
        $client = $this->crear_cliente(['name' => 'Cliente en pesos']);
        $alta = $this->alta_de_venta($client, ['productos' => ['gestion'], 'monto_total' => 900000, 'moneda' => 'ARS', 'cantidad_cuotas' => 3]);
        $alta->assertStatus(201);
        $venta_id = (int) $alta->json('venta.id');

        $primera = $this->cuotas_de($venta_id)->first();
        $primera->update(['observacion' => 'Acordado por WhatsApp']);

        $respuesta = $this->sena_en_venta($venta_id, ['monto' => 100000]);
        $respuesta->assertStatus(201);
        $this->assertSame('ARS', $respuesta->json('sena.moneda'));
        $this->assertSame('Acordado por WhatsApp · Seña', $primera->fresh()->observacion);
    }

    /* ------------------------------------------------------------------------------------------
     | Compatibilidad del JSON
     |------------------------------------------------------------------------------------------ */

    /**
     * Los campos nuevos del JSON de la venta (`senas`, `resumen.sena_total`) existen siempre (vacíos
     * en una venta sin señas) y `resumen.total/cobrado/pendiente` no cambiaron de significado.
     *
     * @return void
     */
    public function test_la_venta_trae_los_campos_nuevos_y_los_viejos_no_cambian(): void
    {
        $this->crear_vendedor();
        $client = $this->crear_cliente(['name' => 'Cliente de compatibilidad']);
        $venta_id = $this->venta_de_cuatro_cuotas($client);

        $sin = $this->venta_de_la_lista($client, $venta_id);
        $this->assertSame([], $sin['senas']);
        $this->assertEqualsWithDelta(0.0, $sin['resumen']['sena_total'], 0.001);
        $this->assertEqualsWithDelta(1300.0, $sin['resumen']['total'], 0.001);
        $this->assertEqualsWithDelta(0.0, $sin['resumen']['cobrado'], 0.001);
        $this->assertEqualsWithDelta(1300.0, $sin['resumen']['pendiente'], 0.001);
        foreach (['en_espera', 'por_cobrar'] as $clave) {
            $this->assertArrayHasKey($clave, $sin['resumen']);
        }

        // Un pago "de siempre" a una cuota suma a cobrado pero NO es seña.
        $cuota = $this->cuotas_de($venta_id)->first();
        $this->postJson('/api/admin/client/' . $client->id . '/licencias/' . $cuota->id . '/pago', ['monto_pagado' => 325])->assertStatus(200);
        $con_pago = $this->venta_de_la_lista($client, $venta_id);
        $this->assertEqualsWithDelta(325.0, $con_pago['resumen']['cobrado'], 0.001);
        $this->assertEqualsWithDelta(0.0, $con_pago['resumen']['sena_total'], 0.001);

        $this->sena_en_venta($venta_id, ['monto' => 100])->assertStatus(201);
        $con_sena = $this->venta_de_la_lista($client, $venta_id);
        $this->assertEqualsWithDelta(1300.0, $con_sena['resumen']['total'], 0.001, 'El total no cambia: la seña no es una cuota.');
        $this->assertEqualsWithDelta(425.0, $con_sena['resumen']['cobrado'], 0.001, 'La seña está dentro de lo cobrado.');
        $this->assertEqualsWithDelta(875.0, $con_sena['resumen']['pendiente'], 0.001);
        $this->assertEqualsWithDelta(100.0, $con_sena['resumen']['sena_total'], 0.001);
        $this->assertCount(4, $con_sena['cuotas'], 'La seña no agrega cuotas.');

        // La tabla de Cobranzas → Ventas también trae los campos nuevos, sin romperse.
        $tabla = $this->getJson('/api/admin/cobranzas/ventas');
        $tabla->assertStatus(200);
    }

    /* ------------------------------------------------------------------------------------------
     | Seña en un lead, antes de promoverlo
     |------------------------------------------------------------------------------------------ */

    /**
     * 🔴 Una seña en un lead sin promover queda PENDIENTE (sin venta, sin cliente, sin aplicar) en la
     * moneda del contrato, y el GET la lista con su total.
     *
     * @return void
     */
    public function test_una_sena_en_un_lead_sin_promover_queda_pendiente(): void
    {
        $lead = $this->crear_lead_con_contrato(['contract_currency' => 'ARS']);

        $respuesta = $this->sena_en_lead($lead->id, ['monto' => 100000, 'fecha' => '2026-09-14', 'nota' => 'Dejó seña al cerrar']);
        $respuesta->assertStatus(201);
        $this->assertFalse($respuesta->json('sena.aplicada'));
        $this->assertNull($respuesta->json('sena.venta_id'));
        $this->assertSame($lead->id, $respuesta->json('sena.lead_id'));
        $this->assertSame('ARS', $respuesta->json('sena.moneda'), 'La moneda es la del contrato del lead.');
        $this->assertEqualsWithDelta(100000.0, $respuesta->json('total'), 0.001);

        $fila = Sena::firstOrFail();
        $this->assertNull($fila->venta_id);
        $this->assertNull($fila->client_id);
        $this->assertNull($fila->aplicada_at);

        $this->sena_en_lead($lead->id, ['monto' => 5000])->assertStatus(201);

        $lista = $this->getJson('/api/admin/leads/' . $lead->id . '/senas');
        $lista->assertStatus(200);
        $this->assertCount(2, $lista->json('senas'));
        $this->assertEqualsWithDelta(105000.0, $lista->json('total'), 0.001);
        $this->assertEqualsWithDelta(0.0, $lista->json('total_aplicado'), 0.001);

        // Un lead sin moneda en el contrato usa USD.
        $otro = $this->crear_lead_con_contrato(['contract_currency' => null]);
        $this->assertSame('USD', $this->sena_en_lead($otro->id, ['monto' => 10])->json('sena.moneda'));

        // Monto no válido: 422 y no escribe.
        $this->sena_en_lead($lead->id, ['monto' => 0])->assertStatus(422);
        $this->sena_en_lead($lead->id, ['monto' => -1])->assertStatus(422);
        $this->assertSame(3, Sena::count());
    }

    /**
     * 🔴 Al promover el lead se crea la venta del contrato y la seña pendiente se aplica SOLA: la
     * primera cuota (1.000) queda parcial con 100, la fila pasa a tener venta, cliente y
     * `aplicada_at`, y la venta la muestra.
     *
     * @return void
     */
    public function test_la_sena_del_lead_se_aplica_sola_al_promoverlo(): void
    {
        $this->crear_vendedor();
        $lead = $this->crear_lead_con_contrato();
        $this->sena_en_lead($lead->id, ['monto' => 100, 'fecha' => '2026-09-14'])->assertStatus(201);

        $client = app(RunUserSetupService::class)->ensure_production_client($lead, '');
        $this->assertInstanceOf(Client::class, $client);

        $venta = Venta::where('client_id', $client->id)->firstOrFail();
        $cuotas = $this->cuotas_de($venta->id)->values();
        $this->assertSame('parcial', $cuotas[0]->estado);
        $this->assertEqualsWithDelta(100.0, (float) $cuotas[0]->monto_pagado, 0.001);
        $this->assertSame('2026-09-14', $cuotas[0]->fecha_pago->toDateString());
        $this->assertSame('pendiente', $cuotas[1]->estado);

        $fila = Sena::firstOrFail();
        $this->assertSame($venta->id, $fila->venta_id);
        $this->assertSame($client->id, $fila->client_id);
        $this->assertSame($lead->id, $fila->lead_id);
        $this->assertNotNull($fila->aplicada_at, 'Queda registrado cuándo se aplicó.');
        // La columna es JSON: vuelve con las claves en otro orden y 100 como entero, por eso se compara sin ser estricto.
        $this->assertEquals([['cuota_id' => $cuotas[0]->id, 'monto' => 100.0]], $fila->aplicaciones);

        $en_la_lista = $this->venta_de_la_lista($client, $venta->id);
        $this->assertEqualsWithDelta(1400.0, $en_la_lista['resumen']['pendiente'], 0.001);
        $this->assertEqualsWithDelta(100.0, $en_la_lista['resumen']['cobrado'], 0.001);
        $this->assertEqualsWithDelta(100.0, $en_la_lista['resumen']['sena_total'], 0.001);
        $this->assertCount(1, $en_la_lista['senas']);

        // El lead muestra la seña como aplicada, y ya no hay nada pendiente.
        $lista = $this->getJson('/api/admin/leads/' . $lead->id . '/senas');
        $this->assertTrue($lista->json('senas.0.aplicada'));
        $this->assertEqualsWithDelta(0.0, $lista->json('total'), 0.001);
        $this->assertEqualsWithDelta(100.0, $lista->json('total_aplicado'), 0.001);
    }

    /**
     * Varias señas del lead se aplican en orden de fecha, una detrás de otra, y su suma es lo que
     * baja lo pendiente.
     *
     * @return void
     */
    public function test_varias_senas_del_lead_se_aplican_en_orden_de_fecha(): void
    {
        $this->crear_vendedor();
        $lead = $this->crear_lead_con_contrato();
        $this->sena_en_lead($lead->id, ['monto' => 400, 'fecha' => '2026-09-14'])->assertStatus(201);
        $this->sena_en_lead($lead->id, ['monto' => 700, 'fecha' => '2026-09-12'])->assertStatus(201);

        $client = app(RunUserSetupService::class)->ensure_production_client($lead, '');

        $venta = Venta::where('client_id', $client->id)->firstOrFail();
        $cuotas = $this->cuotas_de($venta->id)->values();
        // 700 + 400 = 1100: la primera cuota (1000) entera y 100 de la segunda.
        $this->assertSame('pagada', $cuotas[0]->estado);
        $this->assertSame('parcial', $cuotas[1]->estado);
        $this->assertEqualsWithDelta(100.0, (float) $cuotas[1]->monto_pagado, 0.001);
        $this->assertSame(2, Sena::whereNotNull('aplicada_at')->count());

        // La de fecha más vieja (700) se aplicó primero: entró en la cuota 1 y quedó el resto para la de 400.
        $primera = Sena::where('monto', 700)->firstOrFail();
        $this->assertEquals([['cuota_id' => $cuotas[0]->id, 'monto' => 700.0]], $primera->aplicaciones);
        $segunda = Sena::where('monto', 400)->firstOrFail();
        $this->assertEquals([
            ['cuota_id' => $cuotas[0]->id, 'monto' => 300.0],
            ['cuota_id' => $cuotas[1]->id, 'monto' => 100.0],
        ], $segunda->aplicaciones);
    }

    /**
     * Una seña de lead que supera lo pendiente de la venta se aplica hasta cubrirlo y lo que sobra
     * queda como una seña PENDIENTE nueva (la fila aplicada queda por lo que de verdad entró). Una
     * seña en otra moneda que la venta no se aplica.
     *
     * @return void
     */
    public function test_la_sena_del_lead_que_pasa_lo_pendiente_deja_el_resto_y_la_de_otra_moneda_no_se_aplica(): void
    {
        $this->crear_vendedor();
        $lead = $this->crear_lead_con_contrato();
        $this->sena_en_lead($lead->id, ['monto' => 2000, 'fecha' => '2026-09-14'])->assertStatus(201);

        // Una seña en pesos sobre un contrato en dólares (se cambió la moneda del contrato después de cargarla).
        Sena::create([
            'lead_id' => $lead->id, 'monto' => 50000, 'moneda' => 'ARS', 'fecha' => '2026-09-15', 'origen' => 'manual',
        ]);

        $client = app(RunUserSetupService::class)->ensure_production_client($lead, '');

        $venta = Venta::where('client_id', $client->id)->firstOrFail();
        $venta_json = $this->venta_de_la_lista($client, $venta->id);
        $this->assertEqualsWithDelta(0.0, $venta_json['resumen']['pendiente'], 0.001, 'Se aplicó hasta cubrir la venta.');
        $this->assertEqualsWithDelta(1500.0, $venta_json['resumen']['cobrado'], 0.001);
        $this->assertEqualsWithDelta(1500.0, $venta_json['resumen']['sena_total'], 0.001);

        $aplicada = Sena::whereNotNull('aplicada_at')->firstOrFail();
        $this->assertEqualsWithDelta(1500.0, (float) $aplicada->monto, 0.001, 'La fila aplicada queda por lo que entró en las cuotas.');

        $pendientes = Sena::whereNull('aplicada_at')->get()->keyBy('moneda');
        $this->assertCount(2, $pendientes);
        $this->assertEqualsWithDelta(500.0, (float) $pendientes['USD']->monto, 0.001, 'Lo que sobró queda pendiente.');
        $this->assertEqualsWithDelta(50000.0, (float) $pendientes['ARS']->monto, 0.001, 'La de otra moneda no se aplicó.');
        $this->assertNull($pendientes['ARS']->venta_id);
    }

    /**
     * 🔴 Un fallo al aplicar las señas NO rompe la promoción del lead: el cliente y su venta se
     * crean igual, con las cuotas intactas, y la seña queda pendiente en el lead.
     *
     * @return void
     */
    public function test_un_fallo_al_aplicar_las_senas_no_rompe_la_promocion(): void
    {
        $this->crear_vendedor();
        $lead = $this->crear_lead_con_contrato();
        $this->sena_en_lead($lead->id, ['monto' => 100])->assertStatus(201);

        // Un SenaService que explota al aplicar, en lugar del de verdad.
        $this->app->bind(SenaService::class, function ($app) {
            return new class($app->make(\App\Services\LicenciaCuotaService::class)) extends SenaService {
                public function aplicar_pendientes_del_lead($lead, Venta $venta): int
                {
                    throw new \RuntimeException('falla simulada al aplicar la seña');
                }
            };
        });

        $client = app(RunUserSetupService::class)->ensure_production_client($lead, '');

        $this->assertInstanceOf(Client::class, $client, 'La promoción terminó.');
        $this->assertSame($client->id, $lead->fresh()->promoted_client_id);
        $venta = Venta::where('client_id', $client->id)->firstOrFail();
        $this->assertEqualsWithDelta(1500.0, (float) $venta->monto_total, 0.001);
        $this->assertSame(2, LicenciaCuota::where('venta_id', $venta->id)->count());
        $this->assertSame(0, LicenciaCuota::where('venta_id', $venta->id)->whereNotNull('monto_pagado')->count(), 'Las cuotas quedaron sin tocar.');

        $fila = Sena::firstOrFail();
        $this->assertNull($fila->aplicada_at, 'La seña queda pendiente.');
        $this->assertNull($fila->venta_id);
    }

    /**
     * También el servicio, llamado directo, atrapa lo que no puede aplicar y no tira: sobre una venta
     * sin nada pendiente la seña queda pendiente, y con un lead inválido no hace nada.
     *
     * @return void
     */
    public function test_el_servicio_nunca_tira_al_aplicar_las_pendientes(): void
    {
        $this->crear_vendedor();
        $client = $this->crear_cliente(['name' => 'Cliente del servicio']);
        $venta = Venta::findOrFail($this->venta_de_cuatro_cuotas($client));
        $lead = $this->crear_lead_con_contrato();
        $servicio = app(SenaService::class);

        Sena::create(['lead_id' => $lead->id, 'monto' => 20, 'moneda' => 'USD', 'fecha' => '2026-09-14', 'origen' => 'manual']);

        // Un id de lead que no es nada, y una venta sin cuotas: ninguna tira.
        $this->assertSame(0, $servicio->aplicar_pendientes_del_lead(0, $venta));
        LicenciaCuota::where('venta_id', $venta->id)->delete();
        $this->assertSame(0, $servicio->aplicar_pendientes_del_lead($lead, $venta->fresh()));
        $this->assertNull(Sena::firstOrFail()->aplicada_at, 'La seña queda pendiente.');
    }

    /**
     * 🔴 Un lead ya promovido no recibe señas (422): se registran en la venta de su cliente. Y sin el
     * lead promovido de por medio, el hook del contrato no encuentra señas y no hace nada.
     *
     * @return void
     */
    public function test_un_lead_ya_promovido_no_recibe_senas(): void
    {
        $this->crear_vendedor();
        $client = $this->crear_cliente(['name' => 'Cliente promovido']);
        $lead = $this->crear_lead_promovido($client);

        $respuesta = $this->sena_en_lead($lead->id, ['monto' => 100]);

        $respuesta->assertStatus(422);
        $this->assertArrayHasKey('lead_id', $respuesta->json('errors'));
        $this->assertSame(0, Sena::count());

        // El GET de un lead promovido anda y está vacío.
        $lista = $this->getJson('/api/admin/leads/' . $lead->id . '/senas');
        $lista->assertStatus(200);
        $this->assertSame([], $lista->json('senas'));
        $this->assertEqualsWithDelta(0.0, $lista->json('total'), 0.001);
    }

    /**
     * Generar las cuotas desde el contrato de un cliente sin lead de origen (el botón del panel) no
     * busca señas y anda como siempre.
     *
     * @return void
     */
    public function test_la_venta_del_contrato_sin_lead_de_origen_anda_como_siempre(): void
    {
        $this->crear_vendedor();
        $client = $this->crear_cliente([
            'name'                     => 'Cliente sin lead',
            'contract_currency'        => 'USD',
            'contract_precio_licencia' => '1.200',
        ]);

        $creadas = app(ClientContratoService::class)->generar_cuotas_desde_contrato($client);

        $this->assertSame(1, $creadas);
        $venta = Venta::where('client_id', $client->id)->firstOrFail();
        $this->assertNull($venta->lead_id);
        $this->assertSame(0, Sena::count());
    }
}
