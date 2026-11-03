<?php

namespace Tests\Feature\Cobranzas;

use App\Services\ContractPreciosService;
use Illuminate\Support\Facades\Http;

/**
 * La cuota de Gestión por operaciones (programa «Prueba gratuita de 7 días», misión
 * prueba-precios-landing, 3/11/2026): los cuatro planes por operaciones (ventas + compras + gastos),
 * la cuenta de los excedentes y los usuarios extra, y el botón «Traer datos del cliente» leyendo la
 * clave OPCIONAL `operaciones_mes` de `GET admin-sync/mensualidad-info` (C5).
 *
 * Lo que estas pruebas protegen:
 *
 *  1. La cuenta: abono + (operaciones por encima del límite × precio del excedente) + usuarios extra.
 *  2. 🔴 La tolerancia: un empresa-api viejo no manda `operaciones_mes` y el botón tiene que seguir
 *     trayendo los conteos como siempre, con `operaciones_mes` y `cuota_sugerida` en null.
 *  3. Que nada se persista: la cuota es una sugerencia para Lucas.
 */
class CuotaMensualPorOperacionesTest extends BaseDeCobranzas
{
    private function cliente_con_api(array $atributos = [])
    {
        return $this->crear_cliente(array_merge([
            'api_url' => 'https://api-prueba-operaciones.test',
            'api_key' => 'clave-de-prueba',
        ], $atributos));
    }

    public function test_la_cuota_suma_abono_excedentes_y_usuarios_extra(): void
    {
        // Dentro del límite: solo el abono.
        $cuota = ContractPreciosService::cuota_mensual('inicial', 100);
        $this->assertSame(0, $cuota['excedentes']);
        $this->assertEqualsWithDelta(55900.0, $cuota['total'], 0.001);

        // 30 operaciones de más en el Inicial, a $760 cada una.
        $cuota = ContractPreciosService::cuota_mensual('inicial', 130);
        $this->assertSame(30, $cuota['excedentes']);
        $this->assertEqualsWithDelta(22800.0, $cuota['importe_excedentes'], 0.001);
        $this->assertEqualsWithDelta(78700.0, $cuota['total'], 0.001);

        // Intermedio con 300 operaciones y un usuario extra: 85.400 + 50 × 570 + 30.400.
        $cuota = ContractPreciosService::cuota_mensual('intermedio', 300, 1);
        $this->assertSame(50, $cuota['excedentes']);
        $this->assertEqualsWithDelta(28500.0, $cuota['importe_excedentes'], 0.001);
        $this->assertEqualsWithDelta(30400.0, $cuota['importe_usuarios'], 0.001);
        $this->assertEqualsWithDelta(144300.0, $cuota['total'], 0.001);

        // Empresa pasado de 3.000: 500 × 76.
        $cuota = ContractPreciosService::cuota_mensual('empresa', 3500);
        $this->assertEqualsWithDelta(301000.0, $cuota['total'], 0.001);

        // A medida: importe pactado, sin excedentes aunque haya muchas operaciones.
        $cuota = ContractPreciosService::cuota_mensual('a_medida', 5000, 0, 400000.0);
        $this->assertSame(0, $cuota['excedentes']);
        $this->assertEqualsWithDelta(400000.0, $cuota['total'], 0.001);

        // Un plan desconocido cae en a medida; un abono pactado pisa al de lista.
        $this->assertSame('a_medida', ContractPreciosService::cuota_mensual('premium', 10)['plan']);
        $this->assertEqualsWithDelta(60000.0, ContractPreciosService::cuota_mensual('inicial', 100, 0, 60000.0)['total'], 0.001);
    }

    public function test_el_plan_por_operaciones_sigue_los_cortes_de_la_politica(): void
    {
        $esperado = [0 => 'inicial', 100 => 'inicial', 101 => 'intermedio', 250 => 'intermedio', 251 => 'plus',
            500 => 'plus', 501 => 'empresa', 3000 => 'empresa', 3001 => 'a_medida'];
        foreach ($esperado as $operaciones => $plan) {
            $this->assertSame($plan, ContractPreciosService::plan_por_operaciones($operaciones), "Con $operaciones operaciones");
        }
        $this->assertEqualsWithDelta(27950.0, ContractPreciosService::abono_con_descuento_inicial(55900), 0.001);
        $this->assertEqualsWithDelta(131500.0, ContractPreciosService::abono_con_descuento_inicial(263000), 0.001);
    }

    public function test_traer_del_cliente_devuelve_las_operaciones_y_la_cuota_del_plan_del_contrato_sin_guardar_nada(): void
    {
        $this->admin_logueado();
        $client = $this->cliente_con_api([
            'contract_plan_gestion'     => 'intermedio',
            'contract_precios_sistemas' => ['gestion' => ['licencia' => 0, 'mensualidad' => 85400], 'ecommerce' => ['licencia' => 800, 'mensualidad' => 20000], 'agentes' => ['licencia' => 800, 'mensualidad' => 30000]],
            'contract_usuarios_extra'   => 1,
        ]);

        $this->fakear_http([
            '*/api/admin-sync/mensualidad-info*' => Http::response([
                'conteos'         => ['empleados' => 3, 'ecommerce' => 1, 'mercado_libre' => 0, 'tienda_nube' => 0],
                'operaciones_mes' => ['mes' => '2026-09', 'ventas' => 200, 'compras' => 60, 'gastos' => 40, 'total' => 300],
            ], 200),
        ]);

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/traer-del-cliente');

        $response->assertStatus(200);
        $this->assertTrue($response->json('soportado'));
        $this->assertSame(3, $response->json('cantidad_empleados'));
        $this->assertSame(300, $response->json('operaciones_mes.total'));
        $this->assertSame('2026-09', $response->json('operaciones_mes.mes'));
        $this->assertSame('intermedio', $response->json('cuota_sugerida.plan'));
        $this->assertTrue($response->json('cuota_sugerida.plan_del_contrato'));
        $this->assertSame(50, $response->json('cuota_sugerida.excedentes'));
        $this->assertEqualsWithDelta(144300.0, $response->json('cuota_sugerida.total'), 0.001);

        $client->refresh();
        $this->assertEqualsWithDelta(12000.0, (float) $client->total_mensualidad, 0.001, 'La cuota sugerida no se guarda.');
        $this->assertSame(2, (int) $client->cantidad_empleados, 'Traer no persiste: lo confirma Lucas con Guardar.');
    }

    public function test_sin_operaciones_mes_el_boton_sigue_andando_y_la_cuota_es_null(): void
    {
        $this->admin_logueado();
        $client = $this->cliente_con_api();

        $this->fakear_http([
            '*/api/admin-sync/mensualidad-info*' => Http::response([
                'conteos' => ['empleados' => 4, 'ecommerce' => 0, 'mercado_libre' => 0, 'tienda_nube' => 0],
            ], 200),
        ]);

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/traer-del-cliente');

        $response->assertStatus(200);
        $this->assertTrue($response->json('soportado'));
        $this->assertSame(4, $response->json('cantidad_empleados'));
        $this->assertNull($response->json('operaciones_mes'));
        $this->assertNull($response->json('cuota_sugerida'));
    }

    public function test_sin_plan_vigente_en_el_contrato_la_cuota_sugiere_el_plan_por_operaciones_a_precio_de_lista(): void
    {
        $this->admin_logueado();
        // Contrato con el plan histórico por ventas: no es uno de los vigentes.
        $client = $this->cliente_con_api(['contract_plan_gestion' => 'estandar', 'contract_usuarios_extra' => 0]);

        $this->fakear_http([
            '*/api/admin-sync/mensualidad-info*' => Http::response([
                'conteos'         => ['empleados' => 1],
                // Sin `total`: se suma.
                'operaciones_mes' => ['mes' => '2026-09', 'ventas' => 100, 'compras' => 15, 'gastos' => 5],
            ], 200),
        ]);

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/traer-del-cliente');

        $response->assertStatus(200);
        $this->assertSame(120, $response->json('operaciones_mes.total'));
        $this->assertSame('intermedio', $response->json('cuota_sugerida.plan'));
        $this->assertFalse($response->json('cuota_sugerida.plan_del_contrato'));
        $this->assertEqualsWithDelta(85400.0, $response->json('cuota_sugerida.total'), 0.001);
    }

    public function test_una_operaciones_mes_rota_se_lee_como_ausente(): void
    {
        $this->admin_logueado();
        $client = $this->cliente_con_api();

        $this->fakear_http([
            '*/api/admin-sync/mensualidad-info*' => Http::response([
                'conteos'         => ['empleados' => 1],
                'operaciones_mes' => 'muchas',
            ], 200),
        ]);

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/traer-del-cliente');

        $response->assertStatus(200);
        $this->assertTrue($response->json('soportado'));
        $this->assertNull($response->json('operaciones_mes'));
        $this->assertNull($response->json('cuota_sugerida'));
    }
}
