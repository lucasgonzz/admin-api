<?php

namespace Tests\Feature\Cobranzas;

use Illuminate\Support\Facades\Http;

/**
 * El botón "Traer empleados" del módulo Cobranzas (misión modulo-cobranzas, 18/9/2026):
 * `POST client/{id}/mensualidad/sincronizar-empleados`, que consulta el conteo vivo en el
 * empresa-api del cliente (`GET api/admin-sync/mensualidad-info`, que ya existe en `develop`) y
 * guarda SOLO `cantidad_empleados`.
 *
 * Lo que estas pruebas protegen:
 *
 *  1. 🔴 **Que se guarde solo eso.** El snapshot que vuelve del cliente trae también los toggles
 *     y los precios que él tiene; acá no se escriben. Y `guardar()` pisa los `tiene_*` con
 *     `! empty()`: si el servicio no le pasara los actuales, sincronizar empleados apagaría el
 *     ecommerce.
 *  2. **Que un cliente sin sincronización responda 200 con `soportado` false y no toque nada.**
 *     Kas, Jorge Bello y Scrap Free no tienen sistema: el botón tiene que explicarlo, no romper.
 */
class SincronizarEmpleadosTest extends BaseDeCobranzas
{
    /**
     * Cliente con empresa-api configurada por la vía legacy (`clients.api_url` + `api_key`).
     *
     * @param array<string, mixed> $atributos
     *
     * @return \App\Models\Client
     */
    private function cliente_con_api(array $atributos = [])
    {
        return $this->crear_cliente(array_merge([
            'api_url' => 'https://api-prueba-cobranzas.test',
            'api_key' => 'clave-de-prueba',
        ], $atributos));
    }

    /**
     * 1. El camino feliz: 7 empleados vivos → cantidad y total actualizados, lo demás intacto.
     *
     * Cuenta: plan 10.000 + 7 × 1.000 + ecommerce (sin precio propio → 1.000) = **18.000**.
     *
     * @return void
     */
    public function test_actualiza_la_cantidad_de_empleados_y_el_total_sin_tocar_lo_demas(): void
    {
        $this->admin_logueado();
        $client = $this->cliente_con_api(['tiene_ecommerce' => true, 'total_mensualidad' => 13000]);

        $this->fakear_http([
            '*/api/admin-sync/mensualidad-info*' => Http::response([
                'conteos'           => ['empleados' => 7, 'ecommerce' => 0, 'mercado_libre' => 1, 'tienda_nube' => 0],
                'precio_plan'       => 99999,
                'precio_por_cuenta' => 99999,
            ], 200),
        ]);

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/sincronizar-empleados');

        $response->assertStatus(200);
        $this->assertTrue($response->json('soportado'));
        $this->assertSame(7, $response->json('cantidad_empleados'));
        $this->assertEqualsWithDelta(18000.0, $response->json('total_mensualidad'), 0.001);
        $this->assertSame(7, $response->json('snapshot.cantidad_empleados'));

        $client->refresh();
        $this->assertSame(7, (int) $client->cantidad_empleados);
        $this->assertEqualsWithDelta(18000.0, (float) $client->total_mensualidad, 0.001);
        $this->assertEqualsWithDelta(10000.0, (float) $client->precio_plan, 0.001, 'Los precios del cliente no se escriben.');
        $this->assertTrue((bool) $client->tiene_ecommerce, 'El toggle no se apaga.');
        $this->assertFalse((bool) $client->tiene_mercado_libre, 'Ni se prende: el conteo de ML del cliente no se escribe.');

        Http::assertSent(function ($request) {
            return strpos($request->url(), '/api/admin-sync/mensualidad-info') !== false
                && $request->hasHeader('X-Admin-Api-Key', 'clave-de-prueba');
        });
    }

    /**
     * 2a. Un cliente con empresa-api vieja (404) → 200, soportado false, nada cambia.
     *
     * @return void
     */
    public function test_un_404_del_cliente_no_toca_nada(): void
    {
        $this->admin_logueado();
        $client = $this->cliente_con_api();

        $this->fakear_http([
            '*/api/admin-sync/mensualidad-info*' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/sincronizar-empleados');

        $response->assertStatus(200);
        $this->assertFalse($response->json('soportado'));
        $this->assertNotEmpty($response->json('error'));
        $this->assertArrayNotHasKey('cantidad_empleados', $response->json());

        $client->refresh();
        $this->assertSame(2, (int) $client->cantidad_empleados);
        $this->assertEqualsWithDelta(12000.0, (float) $client->total_mensualidad, 0.001);
    }

    /**
     * 2b. Un cliente sin api_key ni URL (los tres nuevos de la planilla) → 200, soportado false,
     * sin salir a la red.
     *
     * @return void
     */
    public function test_un_cliente_sin_sistema_no_sale_a_la_red(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente(['api_url' => null, 'api_key' => null]);

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/sincronizar-empleados');

        $response->assertStatus(200);
        $this->assertFalse($response->json('soportado'));
        $this->assertStringContainsString('URL', (string) $response->json('error'));

        Http::assertNothingSent();

        $client->refresh();
        $this->assertSame(2, (int) $client->cantidad_empleados);
    }
}
