<?php

namespace Tests\Feature\Cobranzas;

use App\Models\Client;
use App\Services\Afip\AfipFacturacionService;
use Illuminate\Support\Facades\Http;

/**
 * "Emitir factura" trae primero los empleados vivos del cliente (pedido 9, misión
 * cobranzas-mejoras, 18/9/2026): antes de facturar, el controller llama a
 * `CobranzasMensualidadService::sincronizar_empleados()`, que deja `total_mensualidad` actualizado
 * en el MISMO objeto `$client` que después se le pasa a `AfipFacturacionService::emitir()`.
 *
 * `AfipFacturacionService` se mockea en el container: emitir una Factura C de verdad necesita
 * WSAA/WSFE contra AFIP (homologación real), que no hay en este entorno de test — no hay ningún
 * test de "emitir factura" existente en el repo del que copiar un fake de AFIP (se buscó antes de
 * escribir este). Mockear el servicio no es solo la forma de esquivar esa dependencia: es también
 * la forma más directa de probar el ORDEN correcto (sincronizar antes de facturar) y el valor
 * exacto que le llega, capturando el mismo objeto `$client` que recibe el mock.
 */
class EmitirFacturaSincronizaEmpleadosTest extends BaseDeCobranzas
{
    /**
     * 1. El total facturado refleja los empleados NUEVOS (traídos del cliente), no los 2 viejos
     * del default de `crear_cliente()`.
     *
     * @return void
     */
    public function test_emitir_factura_sincroniza_empleados_antes_de_facturar(): void
    {
        $this->admin_logueado();

        $client = $this->crear_cliente([
            'api_url' => 'https://api-prueba-cobranzas.test',
            'api_key' => 'clave-de-prueba',
        ]);
        $this->assertSame(2, (int) $client->cantidad_empleados, 'Default de crear_cliente(): 2 empleados viejos.');

        $this->fakear_http([
            '*/api/admin-sync/mensualidad-info*' => Http::response([
                'conteos' => ['empleados' => 7, 'ecommerce' => 0, 'mercado_libre' => 0, 'tienda_nube' => 0],
            ], 200),
        ]);

        $total_recibido = null;
        $empleados_recibidos = null;

        $this->mock(AfipFacturacionService::class, function ($mock) use (&$total_recibido, &$empleados_recibidos) {
            $mock->shouldReceive('emitir')
                ->once()
                ->andReturnUsing(function (Client $client_recibido, $periodo) use (&$total_recibido, &$empleados_recibidos) {
                    $total_recibido = (float) $client_recibido->total_mensualidad;
                    $empleados_recibidos = (int) $client_recibido->cantidad_empleados;

                    return [
                        'ok' => true, 'ya_facturado' => false, 'resultado' => 'A', 'cae' => '71234567890123',
                        'cbte_numero' => 1, 'error_message' => null, 'invoice_id' => 999,
                    ];
                });
        });

        $response = $this->postJson('/api/admin/client/' . $client->id . '/emitir-factura', ['periodo' => '2026-09']);

        $response->assertStatus(200);
        $this->assertSame(7, $empleados_recibidos, 'AfipFacturacionService tiene que recibir la cantidad NUEVA de empleados.');
        // Plan 10.000 + 7 × 1.000 (precio_por_cuenta) = 17.000, sin ecommerce ni otros módulos.
        $this->assertEqualsWithDelta(17000.0, $total_recibido, 0.001, 'El total facturado tiene que reflejar los empleados nuevos, no los 2 viejos.');

        $client->refresh();
        $this->assertSame(7, (int) $client->cantidad_empleados, 'La sincronización también quedó guardada en admin.');
    }

    /**
     * 2. Un cliente sin sincronización (sin api_url/api_key: versión vieja o recién cargado) no
     * bloquea la emisión: se factura igual con el total que ya tenía, y no sale a la red.
     *
     * @return void
     */
    public function test_un_cliente_sin_sincronizacion_factura_igual_con_lo_que_ya_tenia(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente(['api_url' => null, 'api_key' => null]);

        $total_recibido = null;

        $this->mock(AfipFacturacionService::class, function ($mock) use (&$total_recibido) {
            $mock->shouldReceive('emitir')
                ->once()
                ->andReturnUsing(function (Client $client_recibido, $periodo) use (&$total_recibido) {
                    $total_recibido = (float) $client_recibido->total_mensualidad;

                    return [
                        'ok' => true, 'ya_facturado' => false, 'resultado' => 'A', 'cae' => '71234567890123',
                        'cbte_numero' => 1, 'error_message' => null, 'invoice_id' => 999,
                    ];
                });
        });

        $response = $this->postJson('/api/admin/client/' . $client->id . '/emitir-factura', ['periodo' => '2026-09']);

        $response->assertStatus(200);
        $this->assertEqualsWithDelta(12000.0, $total_recibido, 0.001, 'Sin sincronización soportada, factura con el total que ya tenía.');

        Http::assertNothingSent();
    }
}
