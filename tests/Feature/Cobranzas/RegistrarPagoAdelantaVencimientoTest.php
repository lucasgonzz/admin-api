<?php

namespace Tests\Feature\Cobranzas;

use App\Models\Client;
use Illuminate\Support\Facades\Http;

/**
 * "Un solo click hace todo" (pedido 7, misión cobranzas-mejoras, 18/9/2026): registrar un pago que
 * cierra el mes completo adelanta el vencimiento del cliente un mes y avisa a su empresa-api, sin
 * un paso más. Lo que estas pruebas protegen:
 *
 *  1. El camino feliz: el mes se cierra, el cliente tenía `payment_expired_at` cargado → se
 *     adelanta, se guarda en admin, se avisa al cliente, y la respuesta lo cuenta.
 *  2. Los dos casos donde NO tiene que pasar nada de esto: pago parcial, y cliente sin fecha
 *     cargada — ninguno de los dos intenta sincronizar (ni sale a la red).
 *  3. Que un cliente sin sincronización (versión vieja de empresa-api) no rompa el registro del
 *     pago: se registra igual, y la respuesta explica por qué no se pudo avisar.
 *  4. La aritmética de fin de mes: 31 de enero + 1 mes = 28/29 de febrero, no 3 de marzo (mismo
 *     `addMonthNoOverflow()` que ya usa el resto del servicio).
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
}
