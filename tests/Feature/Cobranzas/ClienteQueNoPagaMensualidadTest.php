<?php

namespace Tests\Feature\Cobranzas;

use App\Models\Client;
use App\Models\MensualidadPago;

/**
 * Clientes que están cargados en el admin pero no usan el sistema y no pagan mensualidad (misión
 * no-paga-mensualidad, 29/10/2026).
 *
 * Lo que estas pruebas protegen:
 *
 *  1. 🔴 **Que un cliente marcado no cuente como deuda**: ni en el rojo de la tabla ni en «Falta
 *     cobrar» (que es el número que se infla si no se hace nada).
 *  2. **Que lo cobrado de verdad siga contando**: la marca saca la deuda, no borra plata que entró.
 *  3. **Que el resto de los clientes no cambie** y que sea compatible hacia atrás: un PUT sin la
 *     clave no toca la marca.
 *  4. **El comando** que marca por nombre, y que no adivina cuando el nombre es ambiguo.
 *
 * El reloj queda clavado el 15/9/2026 (ver `BaseDeCobranzas`): el mes corriente es 2026-09.
 */
class ClienteQueNoPagaMensualidadTest extends BaseDeCobranzas
{
    /**
     * Deja la base sin clientes con deuda ni pagos previos, para que los totales sean los de la
     * prueba. Todo corre en la transacción de la prueba y se revierte solo.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Client::query()->update(['mensualidad_inicio' => null]);
        MensualidadPago::query()->delete();
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    private function pedir()
    {
        $response = $this->getJson('/api/admin/cobranzas/mensualidades?meses=2026-09');
        $response->assertStatus(200);

        return $response;
    }

    /**
     * La fila de un cliente dentro de la respuesta de la tabla.
     *
     * @param \Illuminate\Testing\TestResponse $response
     * @param Client                           $client
     *
     * @return array<string, mixed>
     */
    private function fila($response, Client $client): array
    {
        foreach ($response->json('clientes') as $fila) {
            if ($fila['id'] === $client->id) {
                return $fila;
            }
        }

        $this->fail('El cliente #' . $client->id . ' no vino en la tabla.');
    }

    /**
     * 1. Un cliente que no paga y tiene el mes sin pagos no es «pendiente» (rojo): es «no aplica».
     *
     * @return void
     */
    public function test_un_cliente_que_no_paga_no_aparece_como_pendiente(): void
    {
        $this->admin_logueado();

        $paga = $this->crear_cliente(['mensualidad_inicio' => '2026-01-01']);
        $no_paga = $this->crear_cliente(['mensualidad_inicio' => '2026-01-01', 'no_paga_mensualidad' => true]);

        $respuesta = $this->pedir();

        $this->assertSame('pendiente', $this->fila($respuesta, $paga)['meses']['2026-09']['estado']);
        $this->assertSame('no_aplica', $this->fila($respuesta, $no_paga)['meses']['2026-09']['estado']);
        $this->assertTrue($this->fila($respuesta, $no_paga)['no_paga_mensualidad']);
        $this->assertFalse($this->fila($respuesta, $paga)['no_paga_mensualidad']);
    }

    /**
     * 2. 🔴 «Falta cobrar» no suma lo que debería un cliente marcado, ni del mes ni de anteriores,
     * y los demás clientes siguen sumando igual.
     *
     * @return void
     */
    public function test_falta_cobrar_no_cuenta_a_los_que_no_pagan(): void
    {
        $this->admin_logueado();

        // Debe agosto y septiembre: 2 × 12.000.
        $this->crear_cliente(['mensualidad_inicio' => '2026-08-01']);
        // Mismas condiciones, pero marcado: no debe nada.
        $this->crear_cliente(['mensualidad_inicio' => '2026-08-01', 'no_paga_mensualidad' => true]);

        $falta = $this->pedir()->json('resumen.falta_cobrar');

        $this->assertEqualsWithDelta(12000.0, $falta['del_mes'], 0.001);
        $this->assertEqualsWithDelta(12000.0, $falta['de_anteriores'], 0.001);
        $this->assertEqualsWithDelta(24000.0, $falta['total'], 0.001);
        $this->assertSame(1, $falta['clientes_del_mes']);
        $this->assertSame(1, $falta['clientes_de_anteriores']);
    }

    /**
     * Una marca puesta DESPUÉS de que el cliente acumulara meses sin pagar los saca de la deuda:
     * es el caso real (clientes que ya estaban cargados y en rojo).
     *
     * @return void
     */
    public function test_marcar_a_un_cliente_con_deuda_acumulada_la_saca_del_falta_cobrar(): void
    {
        $this->admin_logueado();

        $client = $this->crear_cliente(['mensualidad_inicio' => '2026-01-01']);

        $antes = $this->pedir()->json('resumen.falta_cobrar.total');
        $this->assertEqualsWithDelta(9 * 12000.0, $antes, 0.001, 'Enero a septiembre, nueve meses sin pagar.');

        $client->no_paga_mensualidad = true;
        $client->save();

        $despues = $this->pedir()->json('resumen.falta_cobrar.total');
        $this->assertEqualsWithDelta(0.0, $despues, 0.001);

        $client->no_paga_mensualidad = false;
        $client->save();

        $this->assertEqualsWithDelta($antes, $this->pedir()->json('resumen.falta_cobrar.total'), 0.001, 'Desmarcar devuelve la deuda.');
    }

    /**
     * 3. Lo que un cliente marcado igual pagó sigue en «Cobrado»: la marca no borra plata real.
     *
     * @return void
     */
    public function test_lo_cobrado_de_un_cliente_marcado_sigue_contando(): void
    {
        $this->admin_logueado();

        $client = $this->crear_cliente(['mensualidad_inicio' => '2026-09-01', 'no_paga_mensualidad' => true]);
        $this->sembrar_pago($client, '2026-09', 3000);

        $cobrado = $this->pedir()->json('resumen.cobrado');

        $this->assertEqualsWithDelta(3000.0, $cobrado['del_mes'], 0.001);
    }

    /**
     * Un mes que se afirmó pagado de un cliente marcado se ve pagado: lo afirmado gana.
     *
     * @return void
     */
    public function test_un_mes_afirmado_pagado_de_un_cliente_marcado_sigue_pagado(): void
    {
        $this->admin_logueado();

        $client = $this->crear_cliente(['mensualidad_inicio' => '2026-09-01', 'no_paga_mensualidad' => true]);
        $this->sembrar_periodo($client, '2026-09', 'pagado');

        $this->assertSame('pagado', $this->fila($this->pedir(), $client)['meses']['2026-09']['estado']);
    }

    /**
     * Una factura autorizada de un cliente marcado no lo vuelve a poner como deudor.
     *
     * @return void
     */
    public function test_una_factura_de_un_cliente_marcado_no_lo_pone_como_facturado_ni_deudor(): void
    {
        $this->admin_logueado();

        $client = $this->crear_cliente(['mensualidad_inicio' => '2026-09-01', 'no_paga_mensualidad' => true]);
        $this->sembrar_factura($client, '2026-09');

        $respuesta = $this->pedir();

        $this->assertSame('no_aplica', $this->fila($respuesta, $client)['meses']['2026-09']['estado']);
        $this->assertEqualsWithDelta(0.0, $respuesta->json('resumen.falta_cobrar.total'), 0.001);
    }

    /**
     * 4. El PUT de mensualidad guarda la marca y el estado la devuelve.
     *
     * @return void
     */
    public function test_el_put_guarda_la_marca_y_el_estado_la_devuelve(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();
        $this->assertFalse((bool) $client->no_paga_mensualidad, 'Por defecto todos pagan.');

        $payload = ['precio_plan' => 10000, 'precio_por_cuenta' => 1000, 'cantidad_empleados' => 2];

        $response = $this->putJson('/api/admin/client/' . $client->id . '/mensualidad', array_merge($payload, ['no_paga_mensualidad' => true]));
        $response->assertStatus(200);
        $this->assertTrue($response->json('no_paga_mensualidad'));
        $this->assertTrue((bool) Client::find($client->id)->no_paga_mensualidad);
        $this->assertTrue($this->getJson('/api/admin/client/' . $client->id . '/mensualidad')->json('no_paga_mensualidad'));

        $response = $this->putJson('/api/admin/client/' . $client->id . '/mensualidad', array_merge($payload, ['no_paga_mensualidad' => false]));
        $response->assertStatus(200);
        $this->assertFalse((bool) Client::find($client->id)->no_paga_mensualidad);
    }

    /**
     * Compatibilidad hacia atrás: un SPA anterior no manda la clave y no puede apagar la marca; un
     * `null` tampoco.
     *
     * @return void
     */
    public function test_el_put_sin_la_clave_o_con_null_no_toca_la_marca(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente(['no_paga_mensualidad' => true]);

        $payload = ['precio_plan' => 10000, 'precio_por_cuenta' => 1000, 'cantidad_empleados' => 2];

        $this->putJson('/api/admin/client/' . $client->id . '/mensualidad', $payload)->assertStatus(200);
        $this->assertTrue((bool) Client::find($client->id)->no_paga_mensualidad);

        $this->putJson('/api/admin/client/' . $client->id . '/mensualidad', array_merge($payload, ['no_paga_mensualidad' => null]))->assertStatus(200);
        $this->assertTrue((bool) Client::find($client->id)->no_paga_mensualidad);
    }

    /**
     * 5. El comando marca por nombre, es idempotente y se puede revertir.
     *
     * @return void
     */
    public function test_el_comando_marca_y_desmarca_por_nombre(): void
    {
        $unico = 'Zzmarca ' . uniqid();
        $client = $this->crear_cliente(['company_name' => $unico]);

        $this->artisan('cobranzas:marcar-no-paga', ['nombres' => [$unico], '--simular' => true])->assertExitCode(0);
        $this->assertFalse((bool) Client::find($client->id)->no_paga_mensualidad, '--simular no escribe.');

        $this->artisan('cobranzas:marcar-no-paga', ['nombres' => [strtoupper($unico)]])->assertExitCode(0);
        $this->assertTrue((bool) Client::find($client->id)->no_paga_mensualidad, 'Sin distinguir mayúsculas.');

        $this->artisan('cobranzas:marcar-no-paga', ['nombres' => [$unico]])->assertExitCode(0);
        $this->assertTrue((bool) Client::find($client->id)->no_paga_mensualidad, 'Idempotente.');

        $this->artisan('cobranzas:marcar-no-paga', ['nombres' => [$unico], '--quitar' => true])->assertExitCode(0);
        $this->assertFalse((bool) Client::find($client->id)->no_paga_mensualidad);
    }

    /**
     * El comando no adivina: con un nombre ambiguo o inexistente no escribe nada, ni siquiera para
     * los nombres que sí estaban bien.
     *
     * @return void
     */
    public function test_el_comando_no_escribe_nada_si_un_nombre_es_ambiguo_o_no_existe(): void
    {
        $base = 'Zzdoble ' . uniqid();
        $bueno = $this->crear_cliente(['company_name' => 'Zzbueno ' . uniqid()]);
        $this->crear_cliente(['company_name' => $base . ' uno']);
        $this->crear_cliente(['company_name' => $base . ' dos']);

        $this->artisan('cobranzas:marcar-no-paga', ['nombres' => [$bueno->company_name, $base]])->assertExitCode(1);
        $this->assertFalse((bool) Client::find($bueno->id)->no_paga_mensualidad, 'Un nombre ambiguo frena todo.');

        $this->artisan('cobranzas:marcar-no-paga', ['nombres' => [$bueno->company_name, 'Zznoexiste' . uniqid()]])->assertExitCode(1);
        $this->assertFalse((bool) Client::find($bueno->id)->no_paga_mensualidad, 'Un nombre inexistente frena todo.');
    }

    /**
     * La coincidencia exacta gana a la parcial: «Zzmb» no puede confundirse con «Zzmb malicia».
     *
     * @return void
     */
    public function test_el_comando_prefiere_la_coincidencia_exacta(): void
    {
        $base = 'Zzexacto' . uniqid();
        $exacto = $this->crear_cliente(['company_name' => $base]);
        $parecido = $this->crear_cliente(['company_name' => $base . ' sur']);

        $this->artisan('cobranzas:marcar-no-paga', ['nombres' => [$base]])->assertExitCode(0);

        $this->assertTrue((bool) Client::find($exacto->id)->no_paga_mensualidad);
        $this->assertFalse((bool) Client::find($parecido->id)->no_paga_mensualidad);
    }

    /**
     * «#id» apunta a un cliente exacto, aunque su nombre sea idéntico al de otro.
     *
     * @return void
     */
    public function test_el_comando_acepta_el_id_con_numeral(): void
    {
        $mismo_nombre = 'Zzigual ' . uniqid();
        $uno = $this->crear_cliente(['company_name' => $mismo_nombre]);
        $dos = $this->crear_cliente(['company_name' => $mismo_nombre]);

        $this->artisan('cobranzas:marcar-no-paga', ['nombres' => [$mismo_nombre]])->assertExitCode(1);

        $this->artisan('cobranzas:marcar-no-paga', ['nombres' => ['#' . $dos->id]])->assertExitCode(0);
        $this->assertTrue((bool) Client::find($dos->id)->no_paga_mensualidad);
        $this->assertFalse((bool) Client::find($uno->id)->no_paga_mensualidad);

        $this->artisan('cobranzas:marcar-no-paga', ['nombres' => ['#999999999']])->assertExitCode(1);
    }

    /**
     * Un mes `parcial` afirmado de un cliente marcado tampoco lo hace deudor: la tabla y la tarjeta
     * «Falta cobrar» tienen que decir lo mismo.
     *
     * @return void
     */
    public function test_un_mes_parcial_de_un_cliente_marcado_no_es_deuda_ni_en_la_tabla_ni_en_la_tarjeta(): void
    {
        $this->admin_logueado();

        $client = $this->crear_cliente(['mensualidad_inicio' => '2026-09-01', 'no_paga_mensualidad' => true]);
        $this->sembrar_periodo($client, '2026-09', 'parcial', 12000);
        $this->sembrar_pago($client, '2026-09', 4000);

        $respuesta = $this->pedir();

        $this->assertSame('no_aplica', $this->fila($respuesta, $client)['meses']['2026-09']['estado']);
        $this->assertEqualsWithDelta(0.0, $respuesta->json('resumen.falta_cobrar.total'), 0.001);
        $this->assertEqualsWithDelta(4000.0, $respuesta->json('resumen.cobrado.del_mes'), 0.001, 'Lo que entró sigue contando.');
    }
}
