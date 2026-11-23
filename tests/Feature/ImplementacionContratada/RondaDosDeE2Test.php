<?php

namespace Tests\Feature\ImplementacionContratada;

use App\Models\AdminTask;
use App\Models\Client;
use App\Models\Implementation;
use App\Models\Lead;
use App\Models\PipelineOpportunity;
use App\Services\AdminSoloLeadsPolicy;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Ronda 2 de la revisión de E2 (misión embudo-juan-pipeline): un test por cada enmienda del Coordinador que no es del freno del agente
 * (ese está en `FrenoAmpliadoDelAgenteTest`).
 *
 *  - Enmienda 2: para el perfil Sólo Leads, `errors` pasa sólo con textos por campo y una clave secreta sigue filtrada.
 *  - Enmienda 3: la implementación contratada de un cliente ACTIVO no mueve el lead ni las tarjetas; «pagó la mitad» sobre una tarjeta
 *    ganada deja la tarea para Lucas.
 *  - Enmienda 4: el desvío de artículos a Lucas es sólo para los artículos de la implementación y sólo antes de «Artículos importados
 *    OK»; los conteos de stock nunca se desvían.
 *  - Enmienda 6: las tres rutas de la ficha devuelven `{model}` con las columnas reales del cliente y sin pisar `estado`.
 *  - Enmienda 7: si falla el aviso al ERP (C3) al pausar o reanudar, se informa en la respuesta y queda una tarea para Lucas.
 *  - Enmienda 8: volver a `no` o `incluida` con la prueba pausada la reanuda, y cambiar los días de la prueba durante la pausa limpia
 *    la marca de pausa.
 */
class RondaDosDeE2Test extends BaseDeLaImplementacion
{
    use ArmadoDeLaImportacion;

    /**
     * Enmienda 2, la política pura: dentro de `errors` sólo pasan listas de textos; un objeto o una lista de objetos se descarta y una
     * clave secreta se filtra aunque su valor sea una lista de textos.
     *
     * @return void
     */
    public function test_errors_pasa_solo_con_textos_por_campo(): void
    {
        $cuerpo = json_decode(json_encode([
            'message' => 'Revisá los datos.',
            'errors'  => [
                'paquetes' => ['Elegí al menos un paquete.'],
                'token'    => ['no tiene que salir'],
                'anidado'  => ['api_key' => 'secreto', 'otro' => ['x']],
                'objetos'  => [['id' => 1, 'phone' => '549351']],
            ],
        ]));

        $filtrado = (new AdminSoloLeadsPolicy())->filtrar_salida($cuerpo);

        $this->assertSame(['Elegí al menos un paquete.'], $filtrado->errors->paquetes);
        $this->assertFalse(property_exists($filtrado->errors, 'token'), 'Una clave secreta sigue filtrada.');
        $this->assertFalse(property_exists($filtrado->errors, 'anidado'), 'Un objeto anidado se descarta.');
        $this->assertFalse(property_exists($filtrado->errors, 'objetos'), 'Una lista de objetos se descarta.');
        $this->assertSame('Revisá los datos.', $filtrado->message);
    }

    /**
     * Enmienda 2, de punta a punta: Juan ve el texto de cada campo en un 422.
     *
     * @return void
     */
    public function test_juan_ve_los_errores_por_campo(): void
    {
        $lead = $this->crear_lead_del_tablero('contactado');
        $this->dar_de_alta_en($lead, 'interesados');
        $this->loguear_a($this->juan);

        $respuesta = $this->postJson('/api/admin/lead/' . $lead->id . '/llamadas', ['atendio' => false]);

        $respuesta->assertStatus(422);
        $this->assertNotEmpty($respuesta->json('errors.proxima_llamada_at.0'));
    }

    /**
     * Enmienda 3: pagar la implementación de un cliente ACTIVO con lead ganado no toca el lead ni la tarjeta «Cliente» (y no pausa
     * nada), pero deja la implementación y las tareas.
     *
     * @return void
     */
    public function test_un_cliente_activo_no_mueve_el_lead_ni_las_tarjetas(): void
    {
        list($client, $lead) = $this->prueba_con_lead();
        Client::query()->whereKey($client->id)->update(['estado' => 'activo']);
        Lead::query()->whereKey($lead->id)->update(['status' => 'cerrado_ganado']);
        $tarjeta = $this->tarjeta_del_lead($lead->fresh());
        $this->mover_tarjeta_a($tarjeta, 'cerrado');

        $this->fijar_implementacion($client->fresh(), 'mitad', ['tienda'])->assertStatus(200);

        $this->assertSame('cerrado_ganado', (string) $lead->fresh()->status, 'El lead sigue como cliente.');
        $this->assertCount(1, $this->tarjetas_del_lead($lead->fresh()), 'No se abre otra tarjeta.');
        $this->assertSame('cerrado', $this->clave_de_la_columna($tarjeta), 'La tarjeta ganada no se mueve.');
        $this->assertNull($client->fresh()->prueba_pausada_at, 'Un cliente activo no tiene prueba que pausar.');
        $this->assertSame(1, Implementation::query()->where('client_id', $client->id)->count(), 'La implementación sí se inicia.');
        $this->assertSame(1, AdminTask::query()->where('created_via', 'impl_mitad')->count(), 'Y la tarea del cobro también.');
    }

    /**
     * Enmienda 3: «pagó la mitad» de Juan sobre una tarjeta ganada deja la tarea para Lucas («cargarla desde la ficha»).
     *
     * @return void
     */
    public function test_pago_de_la_mitad_sobre_tarjeta_ganada_deja_la_tarea(): void
    {
        $lead = $this->crear_lead_del_tablero('prueba_activa', ['company_name' => 'Kiosco Ganado']);
        $tarjeta = $this->dar_de_alta_en($lead, 'interesados');
        $this->mover_tarjeta_a($tarjeta, 'cerrado');
        $this->loguear_a($this->juan);

        $this->postJson('/api/admin/lead/' . $lead->id . '/llamadas', ['atendio' => true, 'resultado' => 'pago_la_mitad', 'paquetes' => ['gestion']])
            ->assertStatus(201);

        $this->assertSame('cerrado', $this->clave_de_la_columna($tarjeta), 'La tarjeta ganada no se mueve.');
        $tarea = AdminTask::query()->where('lead_id', $lead->id)->where('title', 'like', '%desde la ficha%')->first();
        $this->assertNotNull($tarea, 'Queda la tarea para cargarla desde la ficha.');
        $this->assertStringContainsString('Kiosco Ganado', (string) $tarea->title);
    }

    /**
     * Mueve una tarjeta a otra columna con el servicio del CRM.
     *
     * @param PipelineOpportunity $tarjeta
     * @param string              $clave
     *
     * @return void
     */
    private function mover_tarjeta_a(PipelineOpportunity $tarjeta, string $clave): void
    {
        app(\App\Services\Pipelines\PipelineOpportunityService::class)->mover($tarjeta, ['stage_id' => (int) $this->etapa_por_clave($clave)->id], null);
    }

    /**
     * Enmienda 4: el conteo de stock de un cliente con la implementación contratada nunca se desvía a Lucas.
     *
     * @return void
     */
    public function test_el_stock_existente_nunca_se_desvia(): void
    {
        list($client) = $this->prueba_con_lead();
        $this->fijar_implementacion($client, 'mitad', ['gestion'])->assertStatus(200);
        Client::query()->whereKey($client->id)->update(['estado' => 'activo', 'user_id' => 77002]);
        $ticket  = $this->ticket_del_cliente($client->fresh());
        $adjunto = $this->adjuntar_excel($ticket);
        $this->pasar_a_activo($client->fresh(), $ticket, (int) $adjunto->support_message_id);
        $this->fakear_http([
            '*/admin-sync/ai-excel-import/prueba-pendientes*' => Http::response(['automatizar_soporte' => true, 'alcances_soporte' => ['stock_existente']], 200),
            '*/admin-sync/ai-excel-import/analyze-stock-existente' => Http::response(['analysis_uuid' => 'uuid-stock', 'automatizar_soporte' => true, 'alcance' => 'stock_existente'], 202),
        ]);
        $reserva = $this->reservar_turno_sin_afirmar($ticket, (int) $adjunto->support_message_id);

        $respuesta = $this->postJson('/api/claude/support-codex/tickets/' . $ticket->id . '/import-excel-stock-existente', [
            'attachment_id' => $adjunto->id, 'automatizar_soporte' => true, 'alcance' => 'stock_existente',
        ] + $reserva, $this->headers_de_claude());

        $respuesta->assertStatus(200);
        $this->assertNotSame('articulos_para_lucas', $respuesta->json('estado'), 'El stock nunca se desvía.');
        $this->assertSame(0, AdminTask::query()->where('created_via', 'impl_articulos')->count());
    }

    /**
     * Enmienda 4: después de «Artículos importados OK», Soporte importa los artículos como a cualquier cliente con importación incluida.
     *
     * @return void
     */
    public function test_despues_del_ok_soporte_importa_normal(): void
    {
        list($client) = $this->prueba_con_lead();
        $this->fijar_implementacion($client, 'completa', ['gestion'])->assertStatus(200);
        $this->postJson('/api/admin/client/' . $client->id . '/implementacion/articulos-importados')->assertStatus(200);
        $ticket  = $this->ticket_del_cliente($client->fresh());
        $adjunto = $this->adjuntar_excel($ticket);
        $this->fakear_http(['*/admin-sync/ai-excel-import/analyze' => Http::response(['analysis_uuid' => 'uuid-ok', 'automatizar_prueba' => true], 202)]);

        $respuesta = $this->importar_del_ticket($ticket, ['attachment_id' => $adjunto->id]);

        $respuesta->assertStatus(200);
        $this->assertSame('aceptado', $respuesta->json('estado'));
        $this->assertSame(1, $this->pedidos_al_importador());
    }

    /**
     * Enmienda 6: las tres rutas de la ficha devuelven `{model}` con las columnas reales del cliente y sin pisar `estado`.
     *
     * @return void
     */
    public function test_las_rutas_de_la_ficha_devuelven_las_columnas_del_cliente(): void
    {
        list($client) = $this->prueba_con_lead();
        $columnas = [
            'implementacion_contratada', 'implementacion_paquetes', 'implementacion_mitad_pagada_at', 'implementacion_articulos_recibidos_at',
            'implementacion_articulos_ok_at', 'prueba_pausada_at', 'prueba_reanudada_at',
        ];

        $fijada = $this->fijar_implementacion($client, 'mitad', ['gestion']);
        $fijada->assertStatus(200);
        foreach ($columnas as $columna) {
            $this->assertArrayHasKey($columna, (array) $fijada->json('model'), 'El model trae «' . $columna . '».');
        }
        $this->assertSame('mitad', $fijada->json('model.implementacion_contratada'));
        $this->assertSame(['gestion'], $fijada->json('model.implementacion_paquetes'));
        $this->assertNotEmpty($fijada->json('model.prueba_pausada_at'));
        $this->assertSame('prueba', $fijada->json('model.estado'), 'El estado del cliente no se pisa.');

        $ok = $this->postJson('/api/admin/client/' . $client->id . '/implementacion/articulos-importados');
        $ok->assertStatus(200);
        $this->assertNotEmpty($ok->json('model.implementacion_articulos_ok_at'));
        $this->assertSame('prueba', $ok->json('model.estado'));

        $reanudada = $this->postJson('/api/admin/client/' . $client->id . '/prueba/reanudar');
        $reanudada->assertStatus(200);
        $this->assertNotEmpty($reanudada->json('model.prueba_reanudada_at'));
        $this->assertSame('mitad', $reanudada->json('model.implementacion_contratada'));
        $this->assertSame('prueba', $reanudada->json('model.estado'));
    }

    /**
     * Enmienda 7: si C3 falla (HTTP 500) al pausar, se informa en la respuesta y queda una tarea para Lucas; lo mismo al reanudar.
     *
     * @return void
     */
    public function test_si_falla_c3_se_informa_y_queda_una_tarea(): void
    {
        list($client) = $this->prueba_con_lead();
        $this->fakear_http(['*/admin-sync/prueba/vencimiento' => Http::response(['message' => 'caído'], 500)]);

        $pausa = $this->fijar_implementacion($client, 'mitad', ['gestion']);

        $pausa->assertStatus(200);
        $this->assertNotSame('ok', $pausa->json('c3.estado'), 'La respuesta dice que el aviso al ERP falló.');
        $this->assertSame(1, AdminTask::query()->where('created_via', 'impl_c3')->count(), 'Queda una tarea para Lucas.');

        $reanuda = $this->postJson('/api/admin/client/' . $client->id . '/prueba/reanudar');

        $reanuda->assertStatus(200);
        $this->assertNotSame('ok', $reanuda->json('c3.estado'));
        $this->assertSame(2, AdminTask::query()->where('created_via', 'impl_c3')->count(), 'Y otra por la reanudación.');
    }

    /**
     * Enmienda 8: volver de `mitad` a `no` con la prueba pausada la reanuda (7 días desde ahora y C3).
     *
     * @return void
     */
    public function test_volver_a_no_con_la_prueba_pausada_la_reanuda(): void
    {
        list($client, $lead) = $this->prueba_con_lead();
        $this->fijar_implementacion($client, 'mitad', ['gestion'])->assertStatus(200);
        $this->clavar_el_reloj('2026-11-24 10:00:00');

        $this->fijar_implementacion($client, 'no', [])->assertStatus(200);

        $client = $client->fresh();
        $this->assertNotNull($client->prueba_reanudada_at, 'La prueba se reanudó.');
        $this->assertSame('2026-12-01 10:00:00', $this->fecha($client->trial_ends_at));
        $this->assertSame('prueba_activa', (string) $lead->fresh()->status);
    }

    /**
     * Enmienda 8: cambiar los días de la prueba durante la pausa limpia la marca de pausa.
     *
     * @return void
     */
    public function test_cambiar_los_dias_durante_la_pausa_limpia_la_marca(): void
    {
        list($client) = $this->prueba_con_lead();
        $this->fijar_implementacion($client, 'mitad', ['gestion'])->assertStatus(200);
        $this->assertNotNull($client->fresh()->prueba_pausada_at, 'Precondición: está pausada.');

        $this->putJson('/api/admin/client/' . $client->id . '/prueba', ['trial_days' => 14])->assertStatus(200);

        $client = $client->fresh();
        $this->assertNull($client->prueba_pausada_at, 'Ya no está pausada.');
        $this->assertFalse(app(\App\Services\ImplementacionContratadaService::class)->esta_pausada($client));
    }
}
