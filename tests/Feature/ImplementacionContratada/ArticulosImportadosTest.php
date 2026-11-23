<?php

namespace Tests\Feature\ImplementacionContratada;

use App\Models\AdminTask;
use App\Models\Implementation;
use App\Models\ImplementationStage;
use Illuminate\Support\Facades\Http;

/**
 * CA23 de la etapa E2 (misión embudo-juan-pipeline): «Artículos importados OK».
 *
 * Cuando Lucas termina de cargar los artículos de un negocio con implementación, aprieta el botón (`POST
 * client/{id}/implementacion/articulos-importados`): se cierra la tarea «Importar artículos» (`impl_articulos`), nace una tarea
 * «Seguir la implementación de … con /implementar» (`impl_seguir`), queda una nota en la etapa actual y Soporte lo ve en
 * `implementacion_contratada.articulos_importados_at`. Una segunda vez no repite nada.
 *
 * Hoy está EN ROJO: ni la ruta ni el servicio existen.
 */
class ArticulosImportadosTest extends BaseDeLaImplementacion
{
    use ArmadoDeLaImportacion;

    /**
     * @return void
     */
    public function test_articulos_importados_ok(): void
    {
        list($client) = $this->prueba_con_lead();
        $this->fijar_implementacion($client, 'mitad', ['gestion'])->assertStatus(200);
        $ticket  = $this->ticket_del_cliente($client);
        $adjunto = $this->adjuntar_excel($ticket);
        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response($this->estado_de_la_instancia(['ingreso']), 200)]);
        $this->importar_del_ticket($ticket, ['attachment_id' => $adjunto->id])->assertStatus(200);
        $this->assertSame(1, AdminTask::query()->where('created_via', 'impl_articulos')->where('is_done', false)->count(), 'Precondición: hay una tarea de artículos.');

        $this->loguear_a($this->lucas);
        $respuesta = $this->postJson('/api/admin/client/' . $client->id . '/implementacion/articulos-importados');

        $respuesta->assertStatus(200);
        $this->assertNotNull($client->fresh()->implementacion_articulos_ok_at);
        $this->assertSame(0, AdminTask::query()->where('created_via', 'impl_articulos')->where('is_done', false)->count(), 'Se cierra la tarea de artículos.');

        $seguir = AdminTask::query()->where('created_via', 'impl_seguir')->get();
        $this->assertCount(1, $seguir);
        $this->assertStringStartsWith('Seguir la implementación de', (string) $seguir[0]->title);
        $this->assertStringContainsString('/implementar', (string) $seguir[0]->title);

        $implementacion = Implementation::query()->where('client_id', $client->id)->firstOrFail();
        $etapa = ImplementationStage::query()->where('implementation_id', $implementacion->id)->where('stage_number', $implementacion->current_stage)->firstOrFail();
        $this->assertNotEmpty($etapa->data['notas'], 'Queda una nota en la etapa actual.');

        /* Una segunda vez: 200 y sigue habiendo una sola tarea de seguimiento. */
        $this->postJson('/api/admin/client/' . $client->id . '/implementacion/articulos-importados')->assertStatus(200);
        $this->assertSame(1, AdminTask::query()->where('created_via', 'impl_seguir')->count());

        $contexto = $this->contexto_de_soporte($client->fresh());
        $this->assertNotEmpty($contexto->json('ticket.implementacion_contratada.articulos_importados_at'));
    }
}
