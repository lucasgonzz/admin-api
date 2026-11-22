<?php

namespace Tests\Feature\ResetDatosDelCliente;

use Illuminate\Support\Facades\Http;

/**
 * `POST claude/clients/{id}/reset-datos` (misión `reset-datos-cliente`, contrato C2 del PLAN, criterio CA10): el admin
 * reenvía el pedido al `empresa-api` del cliente y deja el registro. En orden de importancia:
 *
 *  1. 🔴 **Sin parámetros es una SIMULACIÓN** (`dry_run` por defecto): el pedido sale con `dry_run = true`, la clave del
 *     cliente en `X-Admin-Api-Key`, y la respuesta es la de empresa + `client_id` y `frente`.
 *  2. 🔴 **Ejecutar exige el nombre del cliente**: un nombre errado es 422 y NO le pega al cliente (`assertNothingSent`).
 *  3. 🔴 **La ejecución reenvía lo justo**: `dry_run = false`, `confirmar_base_de_datos`, `token_de_simulacion` y los flags; nada
 *     que no esté en la lista cerrada del contrato C1 (por ejemplo `confirm_client_name`, que es del admin).
 *  4. Cada llamada deja una fila en `client_data_resets` con `origen = 'claude'`.
 *
 * Los estados de error están en `LosErroresDelResetDeDatosTest`; el panel, en `ElResetDeDatosDesdeElPanelTest`.
 */
class ElResetDeDatosPorClaudeTest extends BaseDelResetDeDatos
{
    /**
     * 🔴 Sin parámetros es una simulación: reenvía a la URL de admin-sync del frente (bajo `/public`) con la clave del
     * cliente en `X-Admin-Api-Key`, `dry_run = true` y `origen = claude`; devuelve el cuerpo de empresa + `client_id` y
     * `frente`.
     *
     * @return void
     */
    public function test_simulacion_por_defecto_reenvia_con_la_clave(): void
    {
        $this->el_cliente_simula();

        $respuesta = $this->pedir([]);

        $respuesta->assertStatus(200);

        // El cuerpo de empresa pasa tal cual, y se le suma dónde se operó.
        $this->assertTrue($respuesta->json('ok'));
        $this->assertTrue($respuesta->json('dry_run'));
        $this->assertSame(self::BASE, $respuesta->json('base'));
        $this->assertSame(3, $respuesta->json('a_vaciar.articles'));
        $this->assertSame(9, $respuesta->json('total_filas'));
        $this->assertSame($this->token, $respuesta->json('token_de_simulacion'), 'La simulación entrega el token: es lo que hace falta para ejecutar.');
        $this->assertSame((int) $this->cliente->id, $respuesta->json('client_id'));

        $frente = $this->cliente->active_client_api;

        $this->assertSame((int) $frente->id, $respuesta->json('frente.client_api_id'));
        $this->assertSame('shared_hosting', $respuesta->json('frente.hosting_type'));
        $this->assertSame('api-doblep.ejemplo.test', $respuesta->json('frente.api_host'));

        $this->assertSinLaClave($respuesta, $this->clave);

        // Lo que salió hacia el cliente: un único POST, exacto.
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $cuerpo = $this->cuerpo_enviado($request);

            return $request->method() === 'POST'
                && $request->url() === self::URL_DEL_RESET
                && $this->header_enviado($request, 'X-Admin-Api-Key') === $this->clave
                && $this->header_enviado($request, 'Accept') === 'application/json'
                && ($cuerpo['dry_run'] ?? null) === true
                && ($cuerpo['origen'] ?? null) === 'claude'
                && ($cuerpo['solicitante'] ?? null) === 'claude'
                && ! array_key_exists('token_de_simulacion', $cuerpo)
                && ! array_key_exists('confirmar_base_de_datos', $cuerpo);
        });
    }

    /**
     * 🔴 Ejecutar con un nombre equivocado es 422, NO le pega al cliente, no deja ninguna fila y la respuesta NO revela el
     * nombre correcto (es un freno, no un formulario a completar). Lo mismo si falta el nombre.
     *
     * @return void
     */
    public function test_nombre_errado_no_le_pega_al_cliente(): void
    {
        Http::fake();

        $errado = $this->pedir_de_verdad(['confirm_client_name' => 'Otro Cliente']);

        $errado->assertStatus(422);
        $this->assertStringContainsString('confirm_client_name', $this->cuerpo($errado));
        $this->assertStringNotContainsString('Doblep', $this->cuerpo($errado), 'La respuesta revela el nombre del cliente.');
        $this->assertStringNotContainsString('Distribuciones', $this->cuerpo($errado), 'La respuesta revela el nombre del cliente.');
        $this->assertSinLaClave($errado, $this->clave);

        // Sin el campo `confirm_client_name` es lo mismo.
        $pedido = $this->pedido_de_ejecucion();

        unset($pedido['confirm_client_name']);

        $this->pedir($pedido)->assertStatus(422);

        Http::assertNothingSent();
    }

    /**
     * 🔴 Con el nombre bien confirmado (sin importar mayúsculas ni espacios en los bordes) reenvía `dry_run = false`, la base
     * a confirmar, el token de la simulación y los flags; el nombre del cliente NO viaja a empresa (no está en la lista cerrada
     * de C1: empresa lo rechazaría como parámetro desconocido).
     *
     * @return void
     */
    public function test_ejecucion_reenvia_base_token_y_flags(): void
    {
        $this->el_cliente_ejecuta();

        $respuesta = $this->pedir_de_verdad([
            'confirm_client_name'              => '  doblep DISTRIBUCIONES ',
            'incluir_comprobantes_con_cae'    => true,
            'incluir_integraciones_conectadas' => true,
            'solicitante'                     => 'Agente de implementación',
            'motivo'                          => 'Segunda importación pedida por el cliente',
        ]);

        $respuesta->assertStatus(200);
        $this->assertFalse($respuesta->json('dry_run'));
        $this->assertSame('ok', $respuesta->json('estado'));
        $this->assertSame(3, $respuesta->json('vaciadas.articles'));
        $this->assertSame(20480, $respuesta->json('respaldo.bytes'));
        $this->assertSame((int) $this->cliente->id, $respuesta->json('client_id'));
        $this->assertSinLaClave($respuesta, $this->clave);
        $this->assertSinElToken($respuesta, 'La ejecución no repite el token.');

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $cuerpo = $this->cuerpo_enviado($request);

            // Las claves permitidas son las de la lista cerrada del contrato C1 y nada más.
            $permitidas = [
                'dry_run', 'confirmar_base_de_datos', 'token_de_simulacion', 'incluir_comprobantes_con_cae',
                'incluir_integraciones_conectadas', 'solicitante', 'origen', 'motivo',
            ];

            return $request->method() === 'POST'
                && $request->url() === self::URL_DEL_RESET
                && $this->header_enviado($request, 'X-Admin-Api-Key') === $this->clave
                && ($cuerpo['dry_run'] ?? null) === false
                && ($cuerpo['confirmar_base_de_datos'] ?? null) === self::BASE
                && ($cuerpo['token_de_simulacion'] ?? null) === $this->token
                && ($cuerpo['incluir_comprobantes_con_cae'] ?? null) === true
                && ($cuerpo['incluir_integraciones_conectadas'] ?? null) === true
                && ($cuerpo['solicitante'] ?? null) === 'Agente de implementación'
                && ($cuerpo['origen'] ?? null) === 'claude'
                && ($cuerpo['motivo'] ?? null) === 'Segunda importación pedida por el cliente'
                && array_diff(array_keys($cuerpo), $permitidas) === [];
        });
    }

    /**
     * Los flags que no se mandan salen apagados o ausentes, nunca prendidos: levantar un freno es siempre un acto explícito.
     *
     * @return void
     */
    public function test_los_flags_que_no_se_piden_no_se_levantan(): void
    {
        $this->el_cliente_ejecuta();

        $this->pedir_de_verdad()->assertStatus(200);

        Http::assertSent(function ($request) {
            $cuerpo = $this->cuerpo_enviado($request);

            return empty($cuerpo['incluir_comprobantes_con_cae']) && empty($cuerpo['incluir_integraciones_conectadas']);
        });
    }

    /**
     * Cada llamada deja una fila en `client_data_resets` con `origen = claude`, el solicitante, el motivo y un resumen del
     * resultado; la fila NO lleva la clave del cliente ni el token de simulación.
     *
     * @return void
     */
    public function test_queda_registrado_con_origen_claude(): void
    {
        // 1. Una simulación.
        $this->el_cliente_simula();
        $this->pedir(['solicitante' => 'Agente de implementación', 'motivo' => 'Ver cuánto se borraría'])->assertStatus(200);

        // 2. Una ejecución.
        $this->el_cliente_ejecuta();
        $this->pedir_de_verdad(['solicitante' => 'Agente de implementación', 'motivo' => 'Borrar la prueba'])->assertStatus(200);

        $filas = $this->registros_del_admin();

        $this->assertCount(2, $filas, 'Una fila por llamada.');

        $simulacion = $filas[0];
        $ejecucion  = $filas[1];

        $this->assertSame((int) $this->cliente->id, (int) $simulacion->client_id);
        $this->assertSame((int) $this->cliente->active_client_api->id, (int) $simulacion->client_api_id);
        $this->assertNull($simulacion->admin_id, 'Por claude/ no hay admin autenticado.');
        $this->assertSame('claude', $simulacion->origen);
        $this->assertSame('Agente de implementación', $simulacion->solicitante);
        $this->assertSame('Ver cuánto se borraría', $simulacion->motivo);
        $this->assertTrue((bool) $simulacion->dry_run);
        $this->assertSame('ok', $simulacion->estado);
        $this->assertSame(200, (int) $simulacion->http_status);

        $this->assertSame('claude', $ejecucion->origen);
        $this->assertFalse((bool) $ejecucion->dry_run);
        $this->assertSame('ok', $ejecucion->estado);
        $this->assertSame(200, (int) $ejecucion->http_status);

        // El resumen guarda lo útil para auditar y nada secreto.
        $resumen = json_decode((string) $ejecucion->resumen, true);

        $this->assertIsArray($resumen, 'El resumen es un JSON.');

        $todo = $this->registro_completo_como_texto();

        $this->assertStringNotContainsString($this->clave, $todo, 'El registro lleva la clave del cliente.');
        $this->assertStringNotContainsString($this->token, $todo, 'El registro lleva el token de simulación.');
    }

    /**
     * `GET claude/clients/{id}/reset-datos/registros` junta lo que registró el admin y lo que cuenta el cliente (C1
     * `registros?limit=10`), con la misma clave.
     *
     * @return void
     */
    public function test_registros_junta_lo_del_admin_y_lo_del_cliente(): void
    {
        $this->el_cliente_simula();
        $this->pedir([])->assertStatus(200);

        $this->el_cliente_contesta(['ok' => true, 'registros' => [['id' => 41, 'modo' => 'simulacion', 'estado' => 'ok']]], 200);

        $respuesta = $this->getJson($this->url_de_claude(null, '/registros'), $this->headers());

        $respuesta->assertStatus(200);
        $this->assertSame((int) $this->cliente->id, $respuesta->json('client_id'));
        $this->assertCount(1, $respuesta->json('admin'), 'Lo que registró el admin.');
        $this->assertNotNull($respuesta->json('cliente'), 'Lo que cuenta el cliente (el contrato C1 no fija si va el cuerpo entero o la lista).');
        $this->assertStringContainsString('"modo":"simulacion"', $this->cuerpo($respuesta), 'Lo que cuenta el cliente viaja en la respuesta.');
        $this->assertSinLaClave($respuesta, $this->clave);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && strpos($request->url(), self::URL_DEL_RESET . '/registros') === 0
                && $this->header_enviado($request, 'X-Admin-Api-Key') === $this->clave;
        });
    }
}
