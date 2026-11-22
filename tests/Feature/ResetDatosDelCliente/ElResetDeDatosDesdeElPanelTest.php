<?php

namespace Tests\Feature\ResetDatosDelCliente;

use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/**
 * Las tres rutas del panel del reset de datos (misión `reset-datos-cliente`, contrato C3 del PLAN, criterio CA11):
 * `POST admin/client/{id}/reset-datos/simular`, `POST …/ejecutar` y `GET …/registros`, con `auth:sanctum`.
 *
 * Reenvían lo mismo que la puerta de Claude (mismo servicio), pero con `origen = 'panel'`, el nombre del admin autenticado
 * como `solicitante` y su id en `client_data_resets.admin_id`. Sin sesión no contestan y no le pegan a nadie.
 */
class ElResetDeDatosDesdeElPanelTest extends BaseDelResetDeDatos
{
    /**
     * 🔴 Simular y ejecutar desde el panel: el mismo reenvío que por Claude, con `origen = panel`, el admin como solicitante y
     * su id en el registro. Ejecutar exige el nombre del cliente y la simulación nunca lo pide.
     *
     * @return void
     */
    public function test_simular_y_ejecutar_desde_el_panel(): void
    {
        $admin = $this->crear_admin('Operadora Panel');

        Sanctum::actingAs($admin);

        // 1. Simular, sin nombre: es solo lectura.
        $this->el_cliente_simula();

        $simulacion = $this->postJson($this->url_del_panel('simular'), ['incluir_comprobantes_con_cae' => true]);

        $simulacion->assertStatus(200);
        $this->assertTrue($simulacion->json('dry_run'));
        $this->assertSame(self::BASE, $simulacion->json('base'));
        $this->assertSame($this->token, $simulacion->json('token_de_simulacion'));
        $this->assertSame((int) $this->cliente->id, $simulacion->json('client_id'));
        $this->assertSinLaClave($simulacion, $this->clave);

        Http::assertSent(function ($request) {
            $cuerpo = $this->cuerpo_enviado($request);

            return $request->method() === 'POST'
                && $request->url() === self::URL_DEL_RESET
                && $this->header_enviado($request, 'X-Admin-Api-Key') === $this->clave
                && ($cuerpo['dry_run'] ?? null) === true
                && ($cuerpo['origen'] ?? null) === 'panel'
                && ($cuerpo['solicitante'] ?? null) === 'Operadora Panel'
                && ($cuerpo['incluir_comprobantes_con_cae'] ?? null) === true;
        });

        // 2. Ejecutar con el nombre del cliente, la base y el token de la simulación.
        $this->el_cliente_ejecuta();

        $ejecucion = $this->postJson($this->url_del_panel('ejecutar'), [
            'confirm_client_name'              => self::NOMBRE,
            'confirmar_base_de_datos'          => self::BASE,
            'token_de_simulacion'              => $this->token,
            'incluir_integraciones_conectadas' => true,
            'motivo'                           => 'El cliente terminó de probar',
        ]);

        $ejecucion->assertStatus(200);
        $this->assertFalse($ejecucion->json('dry_run'));
        $this->assertSame('ok', $ejecucion->json('estado'));
        $this->assertSame((int) $this->cliente->id, $ejecucion->json('client_id'));
        $this->assertSinLaClave($ejecucion, $this->clave);
        $this->assertSinElToken($ejecucion);

        Http::assertSent(function ($request) {
            $cuerpo = $this->cuerpo_enviado($request);

            return ($cuerpo['dry_run'] ?? null) === false
                && ($cuerpo['confirmar_base_de_datos'] ?? null) === self::BASE
                && ($cuerpo['token_de_simulacion'] ?? null) === $this->token
                && ($cuerpo['incluir_integraciones_conectadas'] ?? null) === true
                && ($cuerpo['origen'] ?? null) === 'panel'
                && ($cuerpo['solicitante'] ?? null) === 'Operadora Panel'
                && ($cuerpo['motivo'] ?? null) === 'El cliente terminó de probar'
                && ! array_key_exists('confirm_client_name', $cuerpo);
        });

        // 3. El registro: una fila por llamada, con el admin y el origen del panel.
        $filas = $this->registros_del_admin();

        $this->assertCount(2, $filas);

        foreach ($filas as $fila) {
            $this->assertSame((int) $admin->id, (int) $fila->admin_id);
            $this->assertSame('panel', $fila->origen);
            $this->assertSame('Operadora Panel', $fila->solicitante);
        }

        $this->assertTrue((bool) $filas[0]->dry_run);
        $this->assertFalse((bool) $filas[1]->dry_run);

        $this->assertStringNotContainsString($this->clave, $this->registro_completo_como_texto());
        $this->assertStringNotContainsString($this->token, $this->registro_completo_como_texto());
    }

    /**
     * 🔴 Ejecutar desde el panel con el nombre equivocado es 422 y NO le pega al cliente ni deja registro de ejecución.
     *
     * @return void
     */
    public function test_ejecutar_con_el_nombre_errado_no_le_pega_al_cliente(): void
    {
        Sanctum::actingAs($this->crear_admin());

        Http::fake();

        $respuesta = $this->postJson($this->url_del_panel('ejecutar'), [
            'confirm_client_name'     => 'Otro Cliente',
            'confirmar_base_de_datos' => self::BASE,
            'token_de_simulacion'     => $this->token,
        ]);

        $respuesta->assertStatus(422);
        $this->assertStringNotContainsString('Doblep', $this->cuerpo($respuesta), 'La respuesta revela el nombre del cliente.');

        Http::assertNothingSent();
    }

    /**
     * 🔴 Sin sesión no contesta ninguna de las tres rutas y no le pega al cliente.
     *
     * @return void
     */
    public function test_sin_sesion_401(): void
    {
        Http::fake();

        $this->postJson($this->url_del_panel('simular'), [])->assertStatus(401);
        $this->postJson($this->url_del_panel('ejecutar'), $this->pedido_de_ejecucion())->assertStatus(401);
        $this->getJson($this->url_del_panel('registros'))->assertStatus(401);

        Http::assertNothingSent();
    }

    /**
     * `GET …/registros` lista los últimos 10 del admin (los más nuevos primero) y lo que cuenta el cliente, sin filtrar la clave.
     *
     * @return void
     */
    public function test_registros_lista_los_ultimos(): void
    {
        $admin = $this->crear_admin();

        Sanctum::actingAs($admin);

        // Doce simulaciones: el listado corta en diez.
        $this->el_cliente_simula();

        for ($i = 0; $i < 12; $i++) {
            $this->postJson($this->url_del_panel('simular'), [])->assertStatus(200);
        }

        $this->assertCount(12, $this->registros_del_admin());

        $this->el_cliente_contesta(['ok' => true, 'registros' => [['id' => 41, 'modo' => 'simulacion', 'estado' => 'ok']]], 200);

        $respuesta = $this->getJson($this->url_del_panel('registros'));

        $respuesta->assertStatus(200);
        $this->assertSame((int) $this->cliente->id, $respuesta->json('client_id'));
        $this->assertCount(10, $respuesta->json('admin'), 'Los últimos diez del admin.');
        $this->assertStringContainsString('"modo":"simulacion"', $this->cuerpo($respuesta), 'Lo que cuenta el cliente viaja en la respuesta.');
        $this->assertSinLaClave($respuesta, $this->clave);

        // Los más nuevos primero: el id de la primera es el mayor.
        $ids = array_map(function ($fila) {
            return (int) $fila['id'];
        }, $respuesta->json('admin'));

        $ordenados = $ids;

        rsort($ordenados);

        $this->assertSame($ordenados, $ids, 'Del más nuevo al más viejo.');
    }
}
