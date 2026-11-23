<?php

namespace Tests\Feature\ImplementacionContratada;

use App\Models\AdminTask;
use App\Models\Client;
use Illuminate\Support\Facades\Http;

/**
 * CA20, CA21 y CA22 de la etapa E2 (misión embudo-juan-pipeline): la compuerta de importación de Soporte.
 *
 * Soporte importa SÓLO si el negocio contrató la implementación (`mitad`, `completa`) o es de los de antes (`incluida`):
 *  - CA20: con `no`, ni una prueba ni un negocio activo importan desde Soporte (`sin_implementacion`, HTTP 200, nada sale hacia la
 *    instancia) y `capabilities.prueba_import` pasa a false.
 *  - CA21: con `mitad` o `completa`, los ARTÍCULOS no se mandan a la instancia: queda una tarea «Importar artículos de …» para Lucas
 *    (idempotente por adjunto) y Soporte contesta `articulos_para_lucas`. Los proveedores y clientes siguen el camino normal.
 *  - CA22: un cliente `incluida` (el default de los que ya existían) importa como hoy.
 *
 * Hoy están EN ROJO: no existe la compuerta ni el dato.
 */
class CompuertaDeImportacionTest extends BaseDeLaImplementacion
{
    use ArmadoDeLaImportacion;

    /** @var Client */
    private $client;

    /** @var \App\Models\SupportTicket */
    private $ticket;

    /** @var \App\Models\SupportMessageAttachment */
    private $adjunto;

    /**
     * Una prueba con ticket abierto y un Excel adjunto.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        list($this->client) = $this->prueba_con_lead();
        $this->ticket  = $this->ticket_del_cliente($this->client);
        $this->adjunto = $this->adjuntar_excel($this->ticket);
    }

    /**
     * CA20. Una prueba `no`: `sin_implementacion` (200), sin pedidos a la instancia, y las capacidades apagadas.
     *
     * @return void
     */
    public function test_sin_implementacion_no_importa_en_la_prueba(): void
    {
        $this->fakear_http(['*/admin-sync/ai-excel-import/analyze' => Http::response(['analysis_uuid' => 'uuid', 'automatizar_prueba' => true], 202)]);

        $respuesta = $this->importar_del_ticket($this->ticket, ['attachment_id' => $this->adjunto->id]);

        $respuesta->assertStatus(200);
        $this->assertSame('sin_implementacion', $respuesta->json('estado'));
        $this->assertNotEmpty($respuesta->json('mensaje'));
        $this->assertSame(0, $this->pedidos_al_importador(), 'No sale nada hacia la instancia.');

        $contexto = $this->contexto_de_soporte($this->client);
        $contexto->assertStatus(200);
        $this->assertFalse((bool) $contexto->json('ticket.capabilities.prueba_import'));
        $this->assertFalse((bool) $contexto->json('ticket.implementacion_contratada.importa_soporte'));
        $this->assertTrue((bool) $contexto->json('ticket.capabilities.implementacion_gate'));
    }

    /**
     * CA20. Un negocio ACTIVO que contrató sin implementación tampoco importa por el camino de soporte.
     *
     * @return void
     */
    public function test_un_activo_sin_implementacion_tampoco_importa(): void
    {
        $this->client->estado = 'activo';
        $this->client->save();
        $this->client->update(['user_id' => 77001]);
        $this->pasar_a_activo($this->client->fresh(), $this->ticket, (int) $this->adjunto->support_message_id);
        $this->fakear_http(['*/admin-sync/ai-excel-import/*' => Http::response(['automatizar_soporte' => true], 200)]);
        $reserva = $this->reservar_turno_sin_afirmar($this->ticket, (int) $this->adjunto->support_message_id);

        $respuesta = $this->importar_del_ticket($this->ticket, ['attachment_id' => $this->adjunto->id, 'automatizar_soporte' => true] + $reserva);

        $respuesta->assertStatus(200);
        $this->assertSame('sin_implementacion', $respuesta->json('estado'));
        $this->assertSame(0, $this->pedidos_al_importador());
    }

    /**
     * CA21. Con `mitad`, el Excel de artículos queda como tarea para Lucas y no se manda a la instancia; el mismo adjunto otra vez
     * devuelve la misma tarea.
     *
     * @return void
     */
    public function test_con_implementacion_los_articulos_son_para_lucas(): void
    {
        $this->fijar_implementacion($this->client, 'mitad', ['gestion'])->assertStatus(200);
        $this->fakear_http(['*/admin-sync/ai-excel-import/analyze' => Http::response(['analysis_uuid' => 'uuid', 'automatizar_prueba' => true], 202)]);

        $primera = $this->importar_del_ticket($this->ticket, ['attachment_id' => $this->adjunto->id]);

        $primera->assertStatus(200);
        $this->assertSame('articulos_para_lucas', $primera->json('estado'));
        $this->assertNotEmpty($primera->json('mensaje'));
        $tarea = AdminTask::query()->findOrFail((int) $primera->json('task_id'));
        $this->assertSame('impl_articulos', (string) $tarea->created_via);
        $this->assertStringContainsString('Importar artículos de', (string) $tarea->title);
        $this->assertStringContainsString('#' . $this->ticket->id, (string) $tarea->content, 'Nombra el ticket.');
        $this->assertStringContainsString((string) $this->adjunto->id, (string) $tarea->content, 'Nombra el adjunto.');
        $this->assertNotNull($this->client->fresh()->implementacion_articulos_recibidos_at);
        $this->assertSame(0, $this->pedidos_al_importador(), 'Nada a la instancia.');

        $segunda = $this->importar_del_ticket($this->ticket, ['attachment_id' => $this->adjunto->id]);
        $this->assertSame((int) $primera->json('task_id'), (int) $segunda->json('task_id'), 'Idempotente por adjunto.');
        $this->assertSame(1, AdminTask::query()->where('created_via', 'impl_articulos')->count());
    }

    /**
     * CA21. Con `mitad`, el Excel de un PROVEEDOR sigue el camino normal (se acepta y sale a la instancia).
     *
     * @return void
     */
    public function test_con_implementacion_los_proveedores_siguen_el_camino_normal(): void
    {
        $this->fijar_implementacion($this->client, 'mitad', ['gestion'])->assertStatus(200);
        $this->fakear_http(['*/admin-sync/ai-excel-import/analyze' => Http::response(['analysis_uuid' => 'uuid-proveedor', 'automatizar_prueba' => true], 202)]);

        $respuesta = $this->importar_del_ticket($this->ticket, ['attachment_id' => $this->adjunto->id, 'model' => 'provider']);

        $respuesta->assertStatus(200);
        $this->assertSame('aceptado', $respuesta->json('estado'));
        $this->assertSame(1, $this->pedidos_al_importador());
    }

    /**
     * CA22. Un cliente `incluida` (los de antes) importa artículos como hoy.
     *
     * @return void
     */
    public function test_los_clientes_viejos_siguen_importando(): void
    {
        Client::query()->whereKey($this->client->id)->update(['implementacion_contratada' => 'incluida']);
        $this->fakear_http(['*/admin-sync/ai-excel-import/analyze' => Http::response(['analysis_uuid' => 'uuid-viejo', 'automatizar_prueba' => true], 202)]);

        $respuesta = $this->importar_del_ticket($this->ticket, ['attachment_id' => $this->adjunto->id]);

        $respuesta->assertStatus(200);
        $this->assertSame('aceptado', $respuesta->json('estado'));
        $this->assertSame(1, $this->pedidos_al_importador());
        $this->assertTrue((bool) $this->contexto_de_soporte($this->client)->json('ticket.implementacion_contratada.importa_soporte'));
    }
}
