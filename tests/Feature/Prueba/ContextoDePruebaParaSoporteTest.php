<?php

namespace Tests\Feature\Prueba;

use App\Models\Client;
use App\Models\Lead;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\TrialInstance;
use App\Services\Prueba\PruebaContextoSoporteService;
use App\Services\SupportCodexService;
use Illuminate\Support\Str;

/**
 * El bloque `prueba` del contexto de un ticket (para Martín, M4): solo datos.
 */
class ContextoDePruebaParaSoporteTest extends BaseDePruebaGratuita
{
    public function test_el_contexto_de_un_ticket_de_un_cliente_en_prueba_trae_el_bloque_con_los_hitos(): void
    {
        $instancia = $this->crear_instancia_libre('prueba-01', 900100);
        $uuid      = $this->postear_alta()->json('uuid');
        $lead      = Lead::where('uuid', $uuid)->first();
        $client    = Client::find($lead->promoted_client_id);
        $instancia->refresh();
        $instancia->update(['estado' => TrialInstance::ESTADO_ASIGNADA, 'etapa' => 'lista', 'progreso' => 100]);

        $this->postJson('/api/demo-eventos', ['tipo' => 'prueba.ingreso', 'ocurrido_at' => '2026-11-03T15:30:00-03:00'], ['X-Demo-Eventos-Key' => $lead->demo_eventos_token])->assertStatus(200);

        $ticket = SupportTicket::create([
            'client_id'        => $client->id,
            'client_user_id'   => 0,
            'client_user_name' => 'Lucas Prueba',
            'status'           => 'open',
            'source'           => 'whatsapp',
            'whatsapp_phone'   => self::TELEFONO_E164,
            'opened_at'        => now(),
        ]);
        SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text', 'body' => 'Hola, ¿cómo importo mi Excel?', 'delivered_at' => now()]);

        $contexto = app(SupportCodexService::class)->context($ticket);

        $this->assertArrayHasKey('prueba', $contexto);
        $this->assertSame('prueba', $contexto['prueba']['estado']);
        $this->assertSame(7, $contexto['prueba']['trial_days']);
        $this->assertSame('2026-11-10T15:00:00-03:00', $contexto['prueba']['trial_ends_at']);
        $this->assertSame(7, $contexto['prueba']['dias_restantes']);
        $this->assertFalse($contexto['prueba']['vencida']);
        $this->assertSame('https://prueba-test.comerciocity.com', $contexto['prueba']['url']);
        $this->assertSame('prueba-01', $contexto['prueba']['instancia']['nombre']);
        $this->assertSame((int) $lead->id, $contexto['prueba']['lead_id']);
        $this->assertSame(['prueba.ingreso'], array_keys($contexto['prueba']['hitos']));
        $this->assertSame('2026-11-03T15:30:00-03:00', $contexto['prueba']['hitos']['prueba.ingreso']['ocurrido_at']);
    }

    public function test_un_cliente_que_no_es_una_prueba_no_trae_bloque(): void
    {
        $client = Client::create(['name' => 'Común', 'is_active' => true, 'api_url' => '', 'api_key' => Str::random(40), 'inbound_api_key' => Str::random(40), 'slug' => 'comun-' . uniqid()]);

        $this->assertNull(app(PruebaContextoSoporteService::class)->bloque_para_el_cliente($client));
        $this->assertNull(app(PruebaContextoSoporteService::class)->bloque_para_el_cliente(null));
    }
}
