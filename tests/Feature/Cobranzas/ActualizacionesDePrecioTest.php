<?php

namespace Tests\Feature\Cobranzas;

use App\Models\MensualidadActualizacion;

/**
 * El historial de actualizaciones de precio de la mensualidad y el vencimiento de la próxima
 * oficial (misión modulo-cobranzas, 18/9/2026).
 *
 * Lo que estas pruebas protegen, en orden de importancia:
 *
 *  1. 🔴 **Que registrar precios nuevos no pise lo que no es precio.** `ClientMensualidadService::
 *     guardar()` setea los tres `tiene_*` con `! empty()` y exige `cantidad_empleados`: si el
 *     servicio de cobranzas no le pasara los valores actuales, cada actualización de precios
 *     apagaría el ecommerce del cliente y le pondría cero empleados. Es la trampa que el plan
 *     nombra explícitamente.
 *  2. **Que la cuenta de "hace cuánto no se actualiza" salga de la última OFICIAL y de los meses
 *     del contrato** (6 por default, 12 si el contrato lo dice), y que "vencida" sea vencida.
 */
class ActualizacionesDePrecioTest extends BaseDeCobranzas
{
    /**
     * 1. Registrar aplica los cinco precios y recalcula el total, sin tocar empleados ni toggles.
     *
     * Cuenta: plan 20.000 + 3 empleados × 2.000 + ecommerce (sin precio propio → cae a 2.000)
     * = **28.000**.
     *
     * @return void
     */
    public function test_registrar_aplica_los_precios_y_recalcula_sin_pisar_empleados_ni_toggles(): void
    {
        $admin = $this->admin_logueado();

        $client = $this->crear_cliente([
            'cantidad_empleados' => 3,
            'tiene_ecommerce'    => true,
            'payment_expired_at' => '2026-10-05',
            'total_mensualidad'  => 14000,
        ]);

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/actualizaciones', [
            'fecha'             => '2026-09-01',
            'precio_plan'       => 20000,
            'precio_por_cuenta' => 2000,
            'es_oficial'        => true,
            'observacion'       => 'IPC agosto',
        ]);

        $response->assertStatus(200);

        $client->refresh();

        $this->assertEqualsWithDelta(20000.0, (float) $client->precio_plan, 0.001);
        $this->assertEqualsWithDelta(2000.0, (float) $client->precio_por_cuenta, 0.001);
        $this->assertSame(3, (int) $client->cantidad_empleados, 'Los empleados no son un precio: quedan como estaban.');
        $this->assertTrue((bool) $client->tiene_ecommerce, 'El toggle no se apaga por registrar precios.');
        $this->assertSame('2026-10-05', $client->payment_expired_at->toDateString(), 'La fecha de pago tampoco se toca.');
        $this->assertEqualsWithDelta(28000.0, (float) $client->total_mensualidad, 0.001);

        // La respuesta trae el snapshot ya recalculado, el historial y el resumen.
        $this->assertEqualsWithDelta(28000.0, (float) $response->json('snapshot.total_mensualidad'), 0.001);
        $this->assertSame('2026-09-01', $response->json('snapshot.actualizacion.ultima_oficial_fecha'));
        $this->assertCount(1, $response->json('actualizaciones'));
        $this->assertSame($admin->name, $response->json('actualizaciones.0.admin_nombre'));
        $this->assertTrue($response->json('actualizaciones.0.es_oficial'));
        $this->assertSame('IPC agosto', $response->json('actualizaciones.0.observacion'));

        // La fila del historial es la foto de los precios.
        $fila = MensualidadActualizacion::where('client_id', $client->id)->first();
        $this->assertNotNull($fila);
        $this->assertEqualsWithDelta(20000.0, (float) $fila->precio_plan, 0.001);
        $this->assertNull($fila->precio_ecommerce);
        $this->assertSame($admin->id, $fila->admin_id);

        // Validación: los dos precios obligatorios son obligatorios.
        $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/actualizaciones', ['precio_plan' => 1000])->assertStatus(422);
    }

    /**
     * 2a. Oficial con el default del contrato: la próxima es a 6 meses y está al día.
     *
     * @return void
     */
    public function test_la_proxima_oficial_es_a_seis_meses_por_default(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/actualizaciones', [
            'fecha'             => '2026-08-20',
            'precio_plan'       => 11000,
            'precio_por_cuenta' => 1100,
            'es_oficial'        => true,
        ]);

        $response->assertStatus(200);

        $resumen = $response->json('resumen');
        $this->assertFalse($resumen['sin_oficial']);
        $this->assertSame(6, $resumen['meses']);
        $this->assertSame('2026-08-20', $resumen['ultima_oficial_fecha']);
        $this->assertSame('2027-02-20', $resumen['proxima_fecha']);
        $this->assertFalse($resumen['vencida']);
        // Del 15/9/2026 al 20/2/2027 hay 158 días.
        $this->assertSame(158, $resumen['dias_restantes']);

        // El snapshot del cliente lleva el mismo resumen (es lo que muestra la tarjeta Mensualidad).
        $snapshot = $this->getJson('/api/admin/client/' . $client->id . '/mensualidad');
        $snapshot->assertStatus(200);
        $this->assertSame('2027-02-20', $snapshot->json('actualizacion.proxima_fecha'));
        $this->assertSame('2026-01-01', $snapshot->json('mensualidad_inicio'));
    }

    /**
     * 2b. Con el contrato en 12 meses, la próxima es a 12.
     *
     * @return void
     */
    public function test_los_meses_del_contrato_mandan(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente(['contract_meses_actualizacion' => 12]);

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/actualizaciones', [
            'fecha'             => '2026-03-31',
            'precio_plan'       => 11000,
            'precio_por_cuenta' => 1100,
            'es_oficial'        => true,
        ]);

        $response->assertStatus(200);
        $this->assertSame(12, $response->json('resumen.meses'));
        $this->assertSame('2027-03-31', $response->json('resumen.proxima_fecha'));
        $this->assertFalse($response->json('resumen.vencida'));
    }

    /**
     * 2c. Una oficial vieja da vencida, con los días en negativo.
     *
     * @return void
     */
    public function test_una_oficial_vieja_da_vencida(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/actualizaciones', [
            'fecha'             => '2026-01-10',
            'precio_plan'       => 11000,
            'precio_por_cuenta' => 1100,
            'es_oficial'        => true,
        ]);

        $response->assertStatus(200);
        $this->assertSame('2026-07-10', $response->json('resumen.proxima_fecha'));
        $this->assertTrue($response->json('resumen.vencida'));
        // Del 15/9/2026 al 10/7/2026: 67 días atrás.
        $this->assertSame(-67, $response->json('resumen.dias_restantes'));

        // La tabla del módulo la marca igual.
        $tabla = $this->getJson('/api/admin/cobranzas/mensualidades?meses=2026-09');
        $tabla->assertStatus(200);
        $fila = collect($tabla->json('clientes'))->firstWhere('id', $client->id);
        $this->assertTrue($fila['actualizacion']['vencida']);
        $this->assertSame('2026-01-10', $fila['actualizacion']['ultima_oficial_fecha']);
    }

    /**
     * 2d. Sin ninguna oficial: `sin_oficial`. Marcar una como oficial lo destraba; borrarla no
     * revierte los precios.
     *
     * @return void
     */
    public function test_sin_oficial_se_dice_y_la_marca_se_puede_cambiar(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/actualizaciones', [
            'precio_plan'       => 15000,
            'precio_por_cuenta' => 1500,
            'es_oficial'        => false,
            'observacion'       => 'Retoque del ML',
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('resumen.sin_oficial'));
        $this->assertNull($response->json('resumen.proxima_fecha'));
        $this->assertNull($response->json('resumen.dias_restantes'));
        // Sin fecha en el body, la fecha es hoy.
        $this->assertSame('2026-09-15', $response->json('actualizaciones.0.fecha'));

        $id = $response->json('actualizaciones.0.id');

        $listado = $this->getJson('/api/admin/client/' . $client->id . '/mensualidad/actualizaciones');
        $listado->assertStatus(200);
        $this->assertCount(1, $listado->json('actualizaciones'));
        $this->assertFalse($listado->json('actualizaciones.0.es_oficial'));

        $patch = $this->patchJson('/api/admin/client/' . $client->id . '/mensualidad/actualizaciones/' . $id, [
            'es_oficial' => true,
        ]);
        $patch->assertStatus(200);
        $this->assertFalse($patch->json('resumen.sin_oficial'));
        $this->assertSame('2027-03-15', $patch->json('resumen.proxima_fecha'));
        $this->assertSame('Retoque del ML', $patch->json('actualizaciones.0.observacion'), 'El PATCH sin observación no la borra.');

        $delete = $this->deleteJson('/api/admin/client/' . $client->id . '/mensualidad/actualizaciones/' . $id);
        $delete->assertStatus(200);
        $this->assertCount(0, $delete->json('actualizaciones'));
        $this->assertTrue($delete->json('resumen.sin_oficial'));

        $client->refresh();
        $this->assertEqualsWithDelta(15000.0, (float) $client->precio_plan, 0.001, 'Borrar del historial no revierte los precios.');

        // Una actualización de otro cliente no se edita ni se borra desde acá.
        $otro = $this->crear_cliente();
        $ajena = MensualidadActualizacion::create([
            'client_id'         => $otro->id,
            'fecha'             => '2026-09-01',
            'precio_plan'       => 1,
            'precio_por_cuenta' => 1,
            'es_oficial'        => true,
        ]);
        $this->patchJson('/api/admin/client/' . $client->id . '/mensualidad/actualizaciones/' . $ajena->id, ['es_oficial' => false])->assertStatus(404);
        $this->deleteJson('/api/admin/client/' . $client->id . '/mensualidad/actualizaciones/' . $ajena->id)->assertStatus(404);
        $this->assertNotNull(MensualidadActualizacion::find($ajena->id));
    }

    /**
     * El PUT del snapshot acepta `mensualidad_inicio` y lo guarda como día 1; sin la clave no lo toca.
     *
     * @return void
     */
    public function test_el_put_de_mensualidad_guarda_el_inicio_como_dia_uno(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $base = [
            'precio_plan'        => 10000,
            'precio_por_cuenta'  => 1000,
            'cantidad_empleados' => 2,
        ];

        $response = $this->putJson('/api/admin/client/' . $client->id . '/mensualidad', array_merge($base, [
            'mensualidad_inicio' => '2026-03-17',
        ]));
        $response->assertStatus(200);
        $this->assertSame('2026-03-01', $response->json('mensualidad_inicio'));

        // Un SPA viejo que no manda la clave no la borra.
        $response = $this->putJson('/api/admin/client/' . $client->id . '/mensualidad', $base);
        $response->assertStatus(200);
        $this->assertSame('2026-03-01', $response->json('mensualidad_inicio'));

        // Con la clave en null, sí: "todavía no arrancó".
        $response = $this->putJson('/api/admin/client/' . $client->id . '/mensualidad', array_merge($base, [
            'mensualidad_inicio' => null,
        ]));
        $response->assertStatus(200);
        $this->assertNull($response->json('mensualidad_inicio'));
    }
}
