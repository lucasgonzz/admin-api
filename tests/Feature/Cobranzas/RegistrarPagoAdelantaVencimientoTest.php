<?php

namespace Tests\Feature\Cobranzas;

use App\Models\Client;
use App\Models\MensualidadPago;
use App\Models\MensualidadPeriodo;
use Illuminate\Support\Facades\Http;

/**
 * "Un solo click hace todo" (pedido 7, misión cobranzas-mejoras, 18/9/2026): registrar un pago que
 * cierra el mes completo adelanta el vencimiento del cliente un mes y avisa a su empresa-api, sin
 * un paso más. Lo que estas pruebas protegen:
 *
 *  1. El camino feliz: el mes se cierra, el cliente tenía `payment_expired_at` cargado → se
 *     adelanta, se guarda en admin, se avisa al cliente, y la respuesta lo cuenta.
 *  2. Los dos casos donde NO tiene que pasar nada de esto: pago parcial, y cliente sin fecha
 *     cargada — ninguno de los dos intenta sincronizar (ni sale a la red), y la clave `motivo`
 *     explica por qué cuando corresponde.
 *  3. Que un cliente sin sincronización (versión vieja de empresa-api) no rompa el registro del
 *     pago: se registra igual, y la respuesta explica por qué no se pudo avisar.
 *  4. La aritmética de fin de mes: 31 de enero + 1 mes = 28/29 de febrero, no 3 de marzo (mismo
 *     `addMonthNoOverflow()` que ya usa el resto del servicio).
 *  5. 🔴 Idempotencia por (cliente, período): borrar un pago mal cargado y volver a registrar el
 *     MISMO mes no adelanta el vencimiento una segunda vez (hallazgo del chequeo independiente,
 *     18/9/2026).
 *  6. 🔴 Transaccionalidad: si algo revienta a mitad de camino, no queda nada escrito a medias
 *     (mismo hallazgo).
 */
class RegistrarPagoAdelantaVencimientoTest extends BaseDeCobranzas
{
    /**
     * Cliente con empresa-api configurada por la vía legacy (`clients.api_url` + `api_key`), mismo
     * patrón que `SincronizarEmpleadosTest`.
     *
     * @param array<string, mixed> $atributos
     *
     * @return Client
     */
    private function cliente_con_api(array $atributos = []): Client
    {
        return $this->crear_cliente(array_merge([
            'api_url' => 'https://api-prueba-cobranzas.test',
            'api_key' => 'clave-de-prueba',
        ], $atributos));
    }

    /**
     * 1. El camino feliz: cierra el mes, tenía vencimiento cargado → se adelanta, se guarda en
     * admin y se avisa al cliente.
     *
     * @return void
     */
    public function test_un_pago_que_cierra_el_mes_adelanta_el_vencimiento_y_avisa_al_cliente(): void
    {
        $this->admin_logueado();
        $client = $this->cliente_con_api(['payment_expired_at' => '2026-09-10']);

        $this->fakear_http([
            '*/api/admin-sync/mensualidad-update*' => Http::response([
                'payment_expired_at' => '2026-10-10',
                'total_mensualidad'  => 12000,
            ], 200),
        ]);

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/pagos', [
            'periodo'        => '2026-09',
            'monto'          => 12000,
            'cerrar_periodo' => true,
        ]);

        $response->assertStatus(200);
        $this->assertSame('pagado', $response->json('periodo.estado'));
        $this->assertTrue($response->json('periodo.vencimiento_avanzado.ocurrio'));
        $this->assertSame('2026-10-10', $response->json('periodo.vencimiento_avanzado.nueva_fecha'));
        $this->assertTrue($response->json('periodo.vencimiento_avanzado.sincronizado'));
        $this->assertNull($response->json('periodo.vencimiento_avanzado.motivo_no_sincronizado'));
        $this->assertNull($response->json('periodo.vencimiento_avanzado.motivo'), 'Adelantó bien: no hay nada raro que explicar.');

        $client->refresh();
        $this->assertSame('2026-10-10', $client->payment_expired_at->toDateString());

        Http::assertSent(function ($request) {
            return strpos($request->url(), '/api/admin-sync/mensualidad-update') !== false
                && $request->hasHeader('X-Admin-Api-Key', 'clave-de-prueba');
        });
    }

    /**
     * 2a. Un pago parcial (sin cerrar) no adelanta nada, aunque el cliente tenga vencimiento
     * cargado, y no sale a la red.
     *
     * @return void
     */
    public function test_un_pago_parcial_no_adelanta_nada(): void
    {
        $this->admin_logueado();
        $client = $this->cliente_con_api(['payment_expired_at' => '2026-09-10']);

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/pagos', [
            'periodo'        => '2026-09',
            'monto'          => 5000,
            'cerrar_periodo' => false,
        ]);

        $response->assertStatus(200);
        $this->assertSame('parcial', $response->json('periodo.estado'));
        $this->assertFalse($response->json('periodo.vencimiento_avanzado.ocurrio'));
        $this->assertNull($response->json('periodo.vencimiento_avanzado.nueva_fecha'));
        $this->assertFalse($response->json('periodo.vencimiento_avanzado.sincronizado'));
        $this->assertNull($response->json('periodo.vencimiento_avanzado.motivo_no_sincronizado'));
        $this->assertNull($response->json('periodo.vencimiento_avanzado.motivo'), 'Pago parcial: no correspondía intentarlo, no hay nada raro que explicar.');

        $client->refresh();
        $this->assertSame('2026-09-10', $client->payment_expired_at->toDateString(), 'Un pago parcial no toca el vencimiento.');

        Http::assertNothingSent();
    }

    /**
     * 2b. Un cliente sin `payment_expired_at` cargado: el pago se registra igual, no hay de dónde
     * adelantar, y no sale a la red.
     *
     * @return void
     */
    public function test_sin_vencimiento_cargado_no_hay_de_donde_adelantar(): void
    {
        $this->admin_logueado();
        $client = $this->cliente_con_api(['payment_expired_at' => null]);

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/pagos', [
            'periodo'        => '2026-09',
            'monto'          => 12000,
            'cerrar_periodo' => true,
        ]);

        $response->assertStatus(200);
        $this->assertSame('pagado', $response->json('periodo.estado'), 'El pago se registra igual.');
        $this->assertFalse($response->json('periodo.vencimiento_avanzado.ocurrio'));
        $this->assertFalse($response->json('periodo.vencimiento_avanzado.sincronizado'));
        $this->assertSame('Este cliente no tiene fecha de próximo pago cargada.', $response->json('periodo.vencimiento_avanzado.motivo'));

        Http::assertNothingSent();
    }

    /**
     * 3. Un cliente que no soporta sincronización (versión vieja, 404): el pago y la fecha se
     * guardan igual en admin; lo que no pasa es el aviso, y la respuesta explica el motivo.
     *
     * @return void
     */
    public function test_cliente_sin_sincronizacion_registra_el_pago_igual_y_avisa_el_motivo(): void
    {
        $this->admin_logueado();
        $client = $this->cliente_con_api(['payment_expired_at' => '2026-09-10']);

        $this->fakear_http([
            '*/api/admin-sync/mensualidad-update*' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/pagos', [
            'periodo'        => '2026-09',
            'monto'          => 12000,
            'cerrar_periodo' => true,
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('periodo.vencimiento_avanzado.ocurrio'), 'Se adelantó en admin igual.');
        $this->assertSame('2026-10-10', $response->json('periodo.vencimiento_avanzado.nueva_fecha'));
        $this->assertFalse($response->json('periodo.vencimiento_avanzado.sincronizado'));
        $this->assertNotEmpty($response->json('periodo.vencimiento_avanzado.motivo_no_sincronizado'));
        $this->assertNull($response->json('periodo.vencimiento_avanzado.motivo'), 'Adelantó bien en admin: lo que falló es OTRA cosa (el aviso), que va en motivo_no_sincronizado.');

        $client->refresh();
        $this->assertSame('2026-10-10', $client->payment_expired_at->toDateString(), 'La fecha se guarda en admin aunque no se pueda avisar al cliente.');
    }

    /**
     * 4. Fin de mes: 31 de enero + 1 mes = 28 de febrero (2026 no es bisiesto), no 3 de marzo.
     *
     * @return void
     */
    public function test_el_adelanto_respeta_fin_de_mes(): void
    {
        $this->admin_logueado();
        $client = $this->cliente_con_api(['payment_expired_at' => '2026-01-31']);

        $this->fakear_http([
            '*/api/admin-sync/mensualidad-update*' => Http::response(['payment_expired_at' => '2026-02-28'], 200),
        ]);

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/pagos', [
            'periodo'        => '2026-09',
            'monto'          => 12000,
            'cerrar_periodo' => true,
        ]);

        $response->assertStatus(200);
        $this->assertSame('2026-02-28', $response->json('periodo.vencimiento_avanzado.nueva_fecha'));

        $client->refresh();
        $this->assertSame('2026-02-28', $client->payment_expired_at->toDateString());
    }

    /**
     * 5. 🔴 Borrar un pago mal cargado y volver a registrar el MISMO mes no adelanta el
     * vencimiento una segunda vez: la idempotencia es por (cliente, período), no por cada llamada
     * a `registrar_pago()` (hallazgo del chequeo independiente, 18/9/2026). Escenario real: Lucas
     * carga mal el importe, borra el pago, lo vuelve a cargar bien — el vencimiento tiene que
     * quedar adelantado UNA sola vez por septiembre, no dos.
     *
     * @return void
     */
    public function test_borrar_y_reregistrar_el_mismo_mes_no_adelanta_dos_veces(): void
    {
        $this->admin_logueado();
        $client = $this->cliente_con_api(['payment_expired_at' => '2026-09-10']);

        $this->fakear_http([
            '*/api/admin-sync/mensualidad-update*' => Http::response(['payment_expired_at' => '2026-10-10'], 200),
        ]);

        $primero = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/pagos', [
            'periodo'        => '2026-09',
            'monto'          => 8000, // importe cargado mal
            'cerrar_periodo' => true,
        ]);
        $primero->assertStatus(200);
        $this->assertTrue($primero->json('periodo.vencimiento_avanzado.ocurrio'));
        $this->assertSame('2026-10-10', $primero->json('periodo.vencimiento_avanzado.nueva_fecha'));

        $client->refresh();
        $this->assertSame('2026-10-10', $client->payment_expired_at->toDateString());

        // Lucas nota el error: borra el pago y lo vuelve a cargar bien.
        $pago = MensualidadPago::where('client_id', $client->id)->where('periodo', '2026-09')->firstOrFail();
        $this->deleteJson('/api/admin/client/' . $client->id . '/mensualidad/pagos/' . $pago->id)->assertStatus(200);

        $segundo = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/pagos', [
            'periodo'        => '2026-09',
            'monto'          => 12000, // importe correcto
            'cerrar_periodo' => true,
        ]);
        $segundo->assertStatus(200);
        $this->assertSame('pagado', $segundo->json('periodo.estado'), 'El pago corregido se registra igual.');
        $this->assertFalse($segundo->json('periodo.vencimiento_avanzado.ocurrio'), 'Septiembre ya había adelantado: no se repite.');
        $this->assertNull($segundo->json('periodo.vencimiento_avanzado.nueva_fecha'));
        $this->assertSame('El vencimiento ya se había adelantado antes por este mismo mes; no se repite.', $segundo->json('periodo.vencimiento_avanzado.motivo'));

        // Un mes real, un solo adelanto: sigue en 10/10, no salta a 10/11.
        $client->refresh();
        $this->assertSame('2026-10-10', $client->payment_expired_at->toDateString());
    }

    /**
     * 6. 🔴 Si algo revienta a mitad de camino (acá: el `UPDATE` de `clients` que hace
     * `avanzar_vencimiento_si_corresponde()`), no queda nada escrito: ni el pago, ni la fila del
     * período, ni el vencimiento tocado (hallazgo del chequeo independiente, 18/9/2026). Mockea
     * `ClientMensualidadService::guardar()` para forzar la excepción exactamente en ese punto.
     *
     * @return void
     */
    public function test_un_fallo_a_mitad_de_camino_no_deja_nada_escrito(): void
    {
        $this->admin_logueado();
        $client = $this->cliente_con_api(['payment_expired_at' => '2026-09-10']);

        $this->mock(\App\Services\ClientMensualidadService::class, function ($mock) {
            $mock->shouldReceive('guardar')->once()->andThrow(new \RuntimeException('Fallo forzado de prueba.'));
        });

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/pagos', [
            'periodo'        => '2026-09',
            'monto'          => 12000,
            'cerrar_periodo' => true,
        ]);

        $response->assertStatus(500);

        $this->assertSame(0, MensualidadPago::where('client_id', $client->id)->where('periodo', '2026-09')->count(), 'El pago no puede quedar creado si el resto de la escritura falló.');
        $this->assertNull(MensualidadPeriodo::where('client_id', $client->id)->where('periodo', '2026-09')->first(), 'La fila del período tampoco debería haber quedado creada.');

        $client->refresh();
        $this->assertSame('2026-09-10', $client->payment_expired_at->toDateString(), 'El vencimiento no se movió.');
    }
}
