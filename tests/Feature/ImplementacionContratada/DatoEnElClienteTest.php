<?php

namespace Tests\Feature\ImplementacionContratada;

use App\Models\AdminTask;
use App\Models\Client;
use App\Models\Implementation;
use App\Models\ImplementationStage;
use App\Models\LeadMessage;
use Illuminate\Support\Facades\DB;

/**
 * CA15, CA16, CA17 y CA26 de la etapa E2 (misión embudo-juan-pipeline): el dato «implementación contratada» en el cliente y lo
 * que pasa cuando se paga la mitad, desde la ficha del cliente (`PUT client/{id}/implementacion-contratada`) o desde la llamada
 * de Juan (`POST lead/{id}/llamadas` con `pago_la_mitad` y `paquetes`): los dos caminos usan el mismo servicio.
 *
 *  - CA15: un cliente que ya existía queda `incluida` (default de la migración); la prueba nueva por la landing nace `no` (eso lo
 *    prueba `AltaDePruebaImplementacionTest`, que necesita el alta real).
 *  - CA16/CA17: se pausa la prueba (vence en un año + marca), se avisa al ERP (C3), el lead pasa a `en_implementacion`, la tarjeta a
 *    «En implementación», se crea la implementación manual (etapas 1 y 2 salteadas, la 3 en curso) y una tarea para Lucas con los
 *    datos para cargar la venta.
 *  - CA26: la venta de «Implementación» sale por el módulo de ventas de siempre, con el 15 % por defecto.
 *
 * Hoy están EN ROJO: no existen las columnas, la ruta ni el servicio.
 */
class DatoEnElClienteTest extends BaseDeLaImplementacion
{
    /**
     * CA15. Un cliente creado sin decir nada queda `incluida`.
     *
     * @return void
     */
    public function test_un_cliente_existente_queda_incluida(): void
    {
        $client = Client::create(['name' => 'Común', 'is_active' => true, 'api_url' => '', 'api_key' => 'x', 'inbound_api_key' => 'y', 'slug' => 'comun-' . uniqid()]);

        $this->assertSame('incluida', (string) $client->fresh()->implementacion_contratada);
    }

    /**
     * CA16. Pagar la mitad desde la ficha, sobre una prueba que vence el 27/11 (hoy es el 23/11 a las 12:00).
     *
     * @return void
     */
    public function test_pagar_la_mitad_desde_la_ficha(): void
    {
        list($client, $lead) = $this->prueba_con_lead();

        $respuesta = $this->fijar_implementacion($client, 'mitad', ['gestion', 'tienda']);

        $respuesta->assertStatus(200);
        $client = $client->fresh();
        $this->assertSame('mitad', (string) $client->implementacion_contratada);
        $this->assertSame(['gestion', 'tienda'], array_values((array) $client->implementacion_paquetes));
        $this->assertSame('2026-11-23 12:00:00', $client->implementacion_mitad_pagada_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-23 12:00:00', $client->prueba_pausada_at->format('Y-m-d H:i:s'));
        $this->assertNull($client->prueba_reanudada_at);
        $this->assertSame('2027-11-23 12:00:00', $client->trial_ends_at->format('Y-m-d H:i:s'), 'La pausa lleva el vencimiento al horizonte (un año).');

        $c3 = $this->pedidos_c3();
        $this->assertNotEmpty($c3, 'Se le avisa al ERP.');
        $this->assertStringStartsWith('2027-11-23T12:00:00', (string) $c3[count($c3) - 1]['vence_at']);

        $this->assertSame('en_implementacion', (string) $lead->fresh()->status);
        $this->assertSame('en_implementacion', $this->clave_de_la_columna($this->tarjeta_del_lead($lead)));

        $implementacion = Implementation::query()->where('client_id', $client->id)->first();
        $this->assertNotNull($implementacion, 'Se inicia la implementación.');
        $this->assertSame('manual', (string) $implementacion->automation_mode);
        $this->assertSame(3, (int) $implementacion->current_stage);
        $etapas = ImplementationStage::query()->where('implementation_id', $implementacion->id)->orderBy('stage_number')->pluck('status', 'stage_number')->all();
        $this->assertSame('skipped', $etapas[1]);
        $this->assertSame('skipped', $etapas[2]);
        $this->assertSame('in_progress', $etapas[3]);

        $tarea = AdminTask::query()->where('created_via', 'impl_mitad')->orderByDesc('id')->first();
        $this->assertNotNull($tarea, 'Hay una tarea impl_mitad para Lucas.');
        $this->assertSame([(int) $this->lucas->id], $tarea->assigned_admins()->pluck('admins.id')->map(function ($id) {
            return (int) $id;
        })->all(), 'Asignada a quien resuelve for_implementacion_task.');
        foreach (['Implementación', '$628.400', '50 %'] as $dato) {
            $this->assertStringContainsString($dato, (string) $tarea->content, 'La tarea trae «' . $dato . '» para cargar la venta.');
        }
    }

    /**
     * CA16. Paquetes que no existen (Agentes Lite no tiene implementación) o un estado inválido: 422 y nada cambia.
     *
     * @return void
     */
    public function test_paquetes_o_estado_invalidos_dan_422_sin_cambios(): void
    {
        list($client) = $this->prueba_con_lead();

        $this->fijar_implementacion($client, 'mitad', ['lite'])->assertStatus(422);
        $this->fijar_implementacion($client, 'mitad', [])->assertStatus(422);
        $this->fijar_implementacion($client, 'quizas', ['gestion'])->assertStatus(422);

        $client = $client->fresh();
        $this->assertSame('no', (string) $client->implementacion_contratada);
        $this->assertNull($client->prueba_pausada_at);
        $this->assertSame('2026-11-27 12:00:00', $client->trial_ends_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, Implementation::query()->where('client_id', $client->id)->count());
    }

    /**
     * La tarea va a los setters cuando no hay un admin asignado por defecto a las implementaciones.
     *
     * @return void
     */
    public function test_sin_admin_de_implementaciones_la_tarea_va_a_los_setters(): void
    {
        \App\Models\AdminSetting::set('implementation_assigned_admin_id', '0');
        list($client) = $this->prueba_con_lead();

        $this->fijar_implementacion($client, 'mitad', ['gestion'])->assertStatus(200);

        $tarea = AdminTask::query()->where('created_via', 'impl_mitad')->first();
        $this->assertSame([(int) $this->lucas->id], $tarea->assigned_admins()->pluck('admins.id')->map(function ($id) {
            return (int) $id;
        })->all());
    }

    /**
     * CA17. Pagar la mitad desde la llamada de Juan produce lo mismo que desde la ficha.
     *
     * @return void
     */
    public function test_pagar_la_mitad_desde_la_llamada(): void
    {
        list($client, $lead) = $this->prueba_con_lead();
        $this->loguear_a($this->juan);

        $respuesta = $this->postJson('/api/admin/lead/' . $lead->id . '/llamadas', ['atendio' => true, 'resultado' => 'pago_la_mitad', 'paquetes' => ['gestion']]);

        $respuesta->assertStatus(201);
        $client = $client->fresh();
        $this->assertSame('mitad', (string) $client->implementacion_contratada);
        $this->assertNotNull($client->prueba_pausada_at);
        $this->assertSame('2027-11-23 12:00:00', $client->trial_ends_at->format('Y-m-d H:i:s'));
        $this->assertSame('en_implementacion', (string) $lead->fresh()->status);
        $this->assertSame('en_implementacion', $this->clave_de_la_columna($this->tarjeta_del_lead($lead)));
        $this->assertSame(1, Implementation::query()->where('client_id', $client->id)->count());
        $this->assertSame(1, AdminTask::query()->where('created_via', 'impl_mitad')->count());
    }

    /**
     * CA17. Con cliente, «pagó la mitad» sin paquetes es 422 y no deja nada.
     *
     * @return void
     */
    public function test_pagar_la_mitad_desde_la_llamada_sin_paquetes_es_422(): void
    {
        list($client, $lead) = $this->prueba_con_lead();
        $this->loguear_a($this->juan);

        $respuesta = $this->postJson('/api/admin/lead/' . $lead->id . '/llamadas', ['atendio' => true, 'resultado' => 'pago_la_mitad']);

        $respuesta->assertStatus(422);
        $this->assertArrayHasKey('paquetes', (array) $respuesta->json('errors'));
        $this->assertSame(0, (int) DB::table('lead_llamadas')->where('lead_id', (int) $lead->id)->count());
        $this->assertNull($client->fresh()->prueba_pausada_at);
        $this->assertSame('no', (string) $client->fresh()->implementacion_contratada);
    }

    /**
     * CA17. Sin cliente (todavía no creó la prueba): la tarjeta va a «En implementación» y queda una tarea «Dar de alta e
     * implementar…» para Lucas, sin error.
     *
     * @return void
     */
    public function test_pagar_la_mitad_de_un_lead_sin_cliente(): void
    {
        $lead = $this->crear_lead_del_tablero('link_enviado', ['company_name' => 'Kiosco Sin Prueba']);
        $this->dar_de_alta_en($lead, 'interesados');
        $this->loguear_a($this->juan);

        $this->postJson('/api/admin/lead/' . $lead->id . '/llamadas', ['atendio' => true, 'resultado' => 'pago_la_mitad', 'paquetes' => ['gestion']])
            ->assertStatus(201);

        $this->assertSame('en_implementacion', $this->clave_de_la_columna($this->tarjeta_del_lead($lead)));
        $tarea = AdminTask::query()->where('lead_id', $lead->id)->where('title', 'like', 'Dar de alta e implementar%')->first();
        $this->assertNotNull($tarea, 'Queda la tarea para dar de alta e implementar.');
        $this->assertStringContainsString('Kiosco Sin Prueba', (string) $tarea->title);
    }

    /**
     * CA26. La venta de «Implementación» sale por el módulo de ventas, con el 15 % de comisión por defecto.
     *
     * @return void
     */
    public function test_venta_de_implementacion(): void
    {
        list($client) = $this->prueba_con_lead();
        $this->juan->es_vendedor = true;
        $this->juan->save();
        $this->loguear_a($this->lucas);

        $respuesta = $this->postJson('/api/admin/client/' . $client->id . '/ventas', [
            'productos' => ['implementacion'],
            'moneda'    => 'ARS',
            'cuotas'    => [['monto' => 314200, 'vencimiento' => '2026-11-30'], ['monto' => 314200, 'vencimiento' => '2026-12-30']],
        ]);

        $respuesta->assertStatus(201);
        $this->assertSame(['implementacion'], $respuesta->json('venta.productos'));
        $this->assertSame(['Implementación'], $respuesta->json('venta.productos_etiquetas'));
        $this->assertEqualsWithDelta(15.0, $respuesta->json('venta.comision_porcentaje'), 0.001);
    }
}
