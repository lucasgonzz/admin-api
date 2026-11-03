<?php

namespace Tests\Feature\PruebaGratuita;

use App\Models\DemoEventoRecibido;
use App\Models\SupportMessage;
use App\Services\SupportCodexService;
use App\Services\SupportCodexSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * El bloque `prueba` del contexto de Martín (`GET claude/support-codex/tickets/{id}`) y la marca `prueba`
 * de `pending` (misión cruzada prueba-martin-importacion, 3/11/2026).
 *
 * Lo que protege:
 *  1. Con el cliente en prueba, el contexto trae `prueba` con los días, los hitos de la instancia (C3) y el
 *     próximo paso, `client.estado = 'prueba'` y la capability `prueba_import`. Es lo que hace que Martín
 *     lea `prueba.md`.
 *  2. Si la instancia no tiene C3 (404), los hitos salen de los eventos de C2 y el bloque lo dice.
 *  3. Un cliente que no está en prueba sigue exactamente igual: `prueba` null, `client.estado` null.
 */
class ContextoDePruebaParaMartinTest extends BaseDeLaPrueba
{
    /** @test */
    public function el_contexto_trae_el_bloque_prueba_con_los_hitos_de_la_instancia(): void
    {
        $client = $this->cliente_en_prueba(5, 7);
        $lead   = $this->lead_en_prueba($client);
        $ticket = $this->ticket_del_cliente($client);

        $this->fakear_http([
            '*/admin-sync/prueba/estado' => Http::response($this->estado_de_la_instancia(['ingreso']), 200),
        ]);

        $respuesta = $this->getJson('/api/claude/support-codex/tickets/' . $ticket->id, $this->headers_de_claude());
        $respuesta->assertStatus(200);

        $prueba = $respuesta->json('ticket.prueba');
        $this->assertIsArray($prueba, 'El contexto tiene que traer el bloque prueba.');
        $this->assertSame('prueba', $prueba['estado']);
        $this->assertSame(7, $prueba['dias']);
        $this->assertSame(6, $prueba['dias_restantes'], 'Vence en 5 días y media hora: quedan 6 contando el de hoy.');
        $this->assertFalse($prueba['vencida']);
        $this->assertTrue($prueba['semilla_presente']);
        $this->assertSame('instancia', $prueba['hitos_fuente']);
        $this->assertSame('2026-11-03T10:15:00-03:00', $prueba['hitos']['ingreso']);
        $this->assertNull($prueba['hitos']['importo']);
        $this->assertSame('importo', $prueba['proximo_hito']);
        $this->assertSame('Importó su información', $prueba['proximo_hito_label']);
        $this->assertFalse($prueba['bienvenida_enviada']);
        $this->assertFalse($prueba['bienvenida_en_cola']);
        $this->assertSame((int) $lead->id, $prueba['lead_id']);

        $this->assertSame('prueba', $respuesta->json('ticket.client.estado'));
        $this->assertTrue($respuesta->json('ticket.capabilities.prueba_import'));

        /* La consulta a la instancia va con la clave del cliente, por el puente. */
        $consultas = collect(Http::recorded())->filter(function ($par) {
            return strpos($par[0]->url(), '/admin-sync/prueba/estado') !== false;
        })->values();
        $this->assertCount(1, $consultas);
        $this->assertSame('clave-del-cliente', $consultas[0][0]->header('X-Admin-Api-Key')[0]);
        $this->assertStringNotContainsString('clave-del-cliente', (string) $respuesta->getContent());
    }

    /** @test */
    public function sin_c3_los_hitos_salen_de_los_eventos_de_c2(): void
    {
        $client = $this->cliente_en_prueba();
        $lead   = $this->lead_en_prueba($client);
        $ticket = $this->ticket_del_cliente($client);

        foreach (['prueba.ingreso', 'prueba.importo'] as $nombre) {
            DemoEventoRecibido::create([
                'lead_id'     => $lead->id,
                'uuid'        => (string) Str::uuid(),
                'nombre'      => $nombre,
                'ocurrido_at' => '2026-11-03T11:00:00-03:00',
                'datos'       => [],
            ]);
        }

        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response(['message' => 'Not Found'], 404)]);

        $prueba = $this->getJson('/api/claude/support-codex/tickets/' . $ticket->id, $this->headers_de_claude())
            ->assertStatus(200)
            ->json('ticket.prueba');

        $this->assertSame('admin', $prueba['hitos_fuente']);
        $this->assertNotNull($prueba['hitos']['ingreso']);
        $this->assertNotNull($prueba['hitos']['importo']);
        $this->assertNull($prueba['hitos']['venta']);
        $this->assertSame('articulo_creado', $prueba['proximo_hito']);
        $this->assertNull($prueba['semilla_presente'], 'Sin la instancia no se sabe si la semilla sigue.');
    }

    /** @test */
    public function un_cliente_que_no_esta_en_prueba_no_trae_el_bloque(): void
    {
        $client = $this->crear_cliente('+5493417770000', false, 'otra-clave');
        $ticket = $this->ticket_del_cliente($client);

        $respuesta = $this->getJson('/api/claude/support-codex/tickets/' . $ticket->id, $this->headers_de_claude());
        $respuesta->assertStatus(200);

        $this->assertNull($respuesta->json('ticket.prueba'));
        $this->assertNull($respuesta->json('ticket.client.estado'));
        Http::assertNothingSent();
    }

    /** @test */
    public function pending_marca_los_tickets_de_los_negocios_en_prueba(): void
    {
        SupportCodexSettings::set_enabled(true);

        $en_prueba = $this->cliente_en_prueba();
        $comun     = $this->crear_cliente('+5493417770001', false, 'otra-clave');

        $ticket_prueba = $this->ticket_del_cliente($en_prueba);
        $ticket_comun  = $this->ticket_del_cliente($comun);

        $items = app(SupportCodexService::class)->pending()['tickets'];
        $por_id = [];
        foreach ($items as $item) {
            $por_id[(int) $item['id']] = $item;
        }

        $this->assertArrayHasKey((int) $ticket_prueba->id, $por_id);
        $this->assertArrayHasKey((int) $ticket_comun->id, $por_id);
        $this->assertTrue($por_id[(int) $ticket_prueba->id]['prueba']);
        $this->assertFalse($por_id[(int) $ticket_comun->id]['prueba']);
    }
}
