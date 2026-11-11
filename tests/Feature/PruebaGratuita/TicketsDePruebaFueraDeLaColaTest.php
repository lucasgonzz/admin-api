<?php

namespace Tests\Feature\PruebaGratuita;

use App\Events\SupportTicketAlert;
use App\Models\SupportTicket;
use App\Services\Prueba\PruebaCicloService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

/**
 * Los tickets de prueba gratuita separados de la cola y de las métricas de soporte (A3 de la misión cruzada
 * prueba-ayuda-unificada-en-soporte, 10/11/2026).
 *
 * Lo que protege:
 *  - La bandeja marca cada fila con `es_prueba` (por `clients.estado` en `prueba` o `prueba_vencida`) y sigue
 *    trayendo todos los tickets, para que un SPA viejo no pierda ninguno.
 *  - Los contadores (no leídos, abiertos, tarjetas de inicio, alerta de demora) excluyen los de prueba por
 *    defecto; los no leídos de prueba van aparte en `unread_total_prueba`.
 */
class TicketsDePruebaFueraDeLaColaTest extends BaseDeLaPrueba
{
    /**
     * Un cliente común, uno en prueba y uno con la prueba vencida, cada uno con un ticket sin responder ni leer.
     *
     * @return array{0: SupportTicket, 1: SupportTicket, 2: SupportTicket}
     */
    private function escenario(): array
    {
        $comun   = $this->crear_cliente('+5493417770001', false, 'clave-comun');
        $prueba  = $this->cliente_en_prueba();
        $vencida = $this->crear_cliente('+5493417770002', false, 'clave-vencida');
        $this->fijar_en_prueba($vencida, -1);
        $vencida->estado = PruebaCicloService::CLIENTE_PRUEBA_VENCIDA;
        $vencida->save();

        $tickets = [];
        foreach ([$comun, $prueba, $vencida] as $client) {
            $ticket = $this->ticket_del_cliente($client, true);
            $ticket->last_client_message_at = now()->subHours(2);
            $ticket->save();
            $tickets[] = $ticket;
        }

        return $tickets;
    }

    /** @test */
    public function la_bandeja_marca_los_tickets_de_prueba_y_los_cuenta_aparte(): void
    {
        [$comun, $prueba, $vencida] = $this->escenario();
        $admin = $this->admin();

        $respuesta = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket')->assertOk();

        $marcas = [];
        foreach ($respuesta->json('models') as $fila) {
            $marcas[(int) $fila['id']] = $fila['es_prueba'];
        }
        $this->assertSame(false, $marcas[(int) $comun->id]);
        $this->assertSame(true, $marcas[(int) $prueba->id]);
        $this->assertSame(true, $marcas[(int) $vencida->id]);

        $this->assertSame(1, $respuesta->json('unread_total'), 'Solo el mensaje del cliente común.');
        $this->assertSame(2, $respuesta->json('unread_total_prueba'));
        $this->assertSame(1, $respuesta->json('open_totals.all'));

        $insignias = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket/unread-badges')->assertOk();
        $this->assertSame(1, $insignias->json('unread_total'));
        $this->assertSame(2, $insignias->json('unread_total_prueba'));
        $this->assertSame(1, $insignias->json('open_totals.all'));
        $this->assertSame(0, $insignias->json('unread_totals.mine'));
        $this->assertSame(1, $insignias->json('unread_totals.others'));
    }

    /** @test */
    public function las_tarjetas_de_inicio_no_cuentan_los_tickets_de_prueba(): void
    {
        [$comun] = $this->escenario();
        $admin = $this->admin();

        $tarjetas = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/inicio/tarjetas')->assertOk();
        $this->assertSame(1, $tarjetas->json('tickets_sin_responder'));
        $this->assertSame(1, $tarjetas->json('tickets_abiertos_hoy'));

        /* Y coincide con lo que muestra la bandeja por defecto (sin los de prueba). */
        $filas = array_filter($this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket')->json('models'), function ($fila) {
            return $fila['es_prueba'] === false && $fila['sin_responder'] === true;
        });
        $this->assertSame([(int) $comun->id], array_values(array_map('intval', array_column($filas, 'id'))));
    }

    /** @test */
    public function la_alerta_de_demora_no_salta_para_un_ticket_de_prueba(): void
    {
        Event::fake([SupportTicketAlert::class]);
        [$comun, $prueba, $vencida] = $this->escenario();

        Artisan::call('support:check-response-alerts');

        Event::assertDispatched(SupportTicketAlert::class, function ($evento) use ($comun) {
            return (int) $evento->ticket_id === (int) $comun->id;
        });
        Event::assertNotDispatched(SupportTicketAlert::class, function ($evento) use ($prueba, $vencida) {
            return in_array((int) $evento->ticket_id, [(int) $prueba->id, (int) $vencida->id], true);
        });
    }

    /** @test */
    public function una_prueba_borrada_vuelve_a_contar_como_cualquier_ticket(): void
    {
        $client = $this->cliente_en_prueba();
        $ticket = $this->ticket_del_cliente($client, true);
        $this->assertTrue(SupportTicket::query()->dePrueba()->whereKey($ticket->id)->exists());

        $client->estado = PruebaCicloService::CLIENTE_PRUEBA_BORRADA;
        $client->save();

        $this->assertTrue(SupportTicket::query()->sinPrueba()->whereKey($ticket->id)->exists());
        $this->assertFalse(SupportTicket::query()->dePrueba()->whereKey($ticket->id)->exists());
    }
}
