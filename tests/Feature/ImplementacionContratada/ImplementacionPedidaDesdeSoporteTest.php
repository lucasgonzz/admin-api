<?php

namespace Tests\Feature\ImplementacionContratada;

use App\Models\AdminTask;
use App\Models\Client;

/**
 * CA24 de la etapa E2 (misión embudo-juan-pipeline): `POST claude/support-codex/tickets/{id}/implementacion-pedida`.
 *
 * Cuando el dueño de una prueba SIN implementación la pide por Soporte, el lead pasa a `implementacion_pedida`, se crea (una sola
 * vez) la tarea del embudo para Juan y la tarjeta vuelve a «Interesados». Si ya la tiene contratada no hace nada
 * (`ya_contratada`), y si el cliente no salió de un lead es 422 `sin_lead`.
 *
 * Hoy está EN ROJO: la ruta no existe.
 */
class ImplementacionPedidaDesdeSoporteTest extends BaseDeLaImplementacion
{
    /**
     * @param \App\Models\SupportTicket $ticket
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function pedir($ticket)
    {
        return $this->postJson('/api/claude/support-codex/tickets/' . $ticket->id . '/implementacion-pedida', [], $this->headers_de_claude());
    }

    /**
     * @return void
     */
    public function test_la_pide_el_dueno_de_una_prueba_sin_implementacion(): void
    {
        list($client, $lead) = $this->prueba_con_lead();
        $ticket = $this->ticket_del_cliente($client);

        $respuesta = $this->pedir($ticket);

        $respuesta->assertStatus(200);
        $this->assertSame('ok', $respuesta->json('estado'));
        $this->assertSame('implementacion_pedida', $respuesta->json('lead_status'));
        $this->assertSame('implementacion_pedida', (string) $lead->fresh()->status);
        $this->assertSame('interesados', $this->clave_de_la_columna($this->tarjeta_del_lead($lead->fresh())), 'La tarjeta vuelve a Interesados.');
        $tareas = AdminTask::query()->where('lead_id', $lead->id)->where('created_via', 'embudo_impl_pedida')->get();
        $this->assertCount(1, $tareas);
        $this->assertSame([(int) $this->juan->id], $tareas[0]->assigned_admins()->pluck('admins.id')->map(function ($id) {
            return (int) $id;
        })->all(), 'La tarea es para Juan.');

        /* Idempotente: repetirlo no crea otra tarea. */
        $this->pedir($ticket)->assertStatus(200);
        $this->assertSame(1, AdminTask::query()->where('lead_id', $lead->id)->where('created_via', 'embudo_impl_pedida')->count());
    }

    /**
     * @return void
     */
    public function test_con_la_implementacion_ya_contratada_no_hace_nada(): void
    {
        list($client, $lead) = $this->prueba_con_lead();
        $this->fijar_implementacion($client, 'mitad', ['gestion'])->assertStatus(200);
        $estado_antes = (string) $lead->fresh()->status;
        $tareas_antes = AdminTask::query()->where('lead_id', $lead->id)->count();

        $respuesta = $this->pedir($this->ticket_del_cliente($client));

        $respuesta->assertStatus(200);
        $this->assertSame('ya_contratada', $respuesta->json('estado'));
        $this->assertSame($estado_antes, (string) $lead->fresh()->status);
        $this->assertSame($tareas_antes, AdminTask::query()->where('lead_id', $lead->id)->count());
    }

    /**
     * @return void
     */
    public function test_un_cliente_sin_lead_da_422(): void
    {
        $client = Client::query()->findOrFail($this->cliente_en_prueba(4, 7, '+5493417771111')->id);

        $respuesta = $this->pedir($this->ticket_del_cliente($client));

        $respuesta->assertStatus(422);
        $this->assertSame('sin_lead', $respuesta->json('estado'));
    }
}
