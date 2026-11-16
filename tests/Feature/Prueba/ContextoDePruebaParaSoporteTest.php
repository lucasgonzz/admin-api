<?php

namespace Tests\Feature\Prueba;

use App\Models\Client;
use App\Models\Lead;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\TrialInstance;
use App\Services\Prueba\PruebaContextoSoporteService;
use App\Services\SupportCodexService;
use Illuminate\Support\Facades\Http;
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
        $lead      = $this->lead_del_alta($uuid);
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
        // Los hitos van con la clave corta, sin `prueba.` (así los leen M4 y Martín); el evento entero queda adentro.
        $this->assertSame(['ingreso'], array_keys($contexto['prueba']['hitos']));
        $this->assertSame('prueba.ingreso', $contexto['prueba']['hitos']['ingreso']['evento']);
        $this->assertSame('2026-11-03T15:30:00-03:00', $contexto['prueba']['hitos']['ingreso']['ocurrido_at']);
        $this->assertSame('https://prueba-test.comerciocity.com/demo/ingreso?t=' . $lead->demo_ingreso_token, $contexto['prueba']['url_ingreso']);
    }

    public function test_otro_servicio_le_suma_claves_al_bloque_sin_tocar_la_base(): void
    {
        $this->crear_instancia_libre('prueba-01', 900100);
        $uuid   = $this->postear_alta()->json('uuid');
        $lead   = $this->lead_del_alta($uuid);
        $client = Client::find($lead->promoted_client_id);

        PruebaContextoSoporteService::extender(function (array $base, Client $c, ?Lead $l, ?TrialInstance $i) {
            // Lo que M4 suma de verdad se calcula de otro lado; acá solo importa que llegue la base entera y se fusione.
            return ['dia_actual' => 1, 'proximo_hito' => 'importo', 'hitos_fuente' => array_keys($base['hitos']), 'instancia_vista' => $i ? $i->nombre : null];
        });

        try {
            $bloque = app(PruebaContextoSoporteService::class)->bloque_para_el_cliente($client, ['semilla_presente' => true]);
        } finally {
            PruebaContextoSoporteService::sin_extensiones();
        }

        $this->assertSame('prueba', $bloque['estado']);
        $this->assertSame(1, $bloque['dia_actual']);
        $this->assertSame('importo', $bloque['proximo_hito']);
        $this->assertSame([], $bloque['hitos_fuente']);
        $this->assertSame('prueba-01', $bloque['instancia_vista']);
        $this->assertTrue($bloque['semilla_presente']);
        $this->assertSame([], $bloque['hitos']);
    }

    /**
     * Ronda 8 (§0.3): `prueba_import_clientes` y `prueba_import_margen` salen de lo que el sistema del negocio anunció
     * en `capacidades_prueba` de `prueba-pendientes`; un sistema 4.3.50/4.3.56 no manda la clave y quedan en false.
     *
     * @dataProvider capacidades_anunciadas
     */
    public function test_las_capacidades_nuevas_de_la_prueba_salen_de_lo_que_anuncia_el_sistema(?array $anunciadas, bool $clientes, bool $margen): void
    {
        $ticket = $this->ticket_de_una_prueba_lista();
        $abierta = ['corrida_id' => '6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b', 'modelo' => 'client', 'estado' => 'importar',
            'revision' => 1, 'codigo_requerido' => false, 'modo_prueba' => true];
        $this->erp['ai-excel-import/prueba-pendientes'] = Http::response(['pendientes' => [], 'en_proceso' => true,
            'importaciones_de_contactos' => [$abierta]] + ($anunciadas === null ? [] : ['capacidades_prueba' => $anunciadas]), 200);

        $contexto = app(SupportCodexService::class)->context($ticket);

        $this->assertSame($clientes, $contexto['capabilities']['prueba_import_clientes']);
        $this->assertSame($margen, $contexto['capabilities']['prueba_import_margen']);
        $this->assertSame($clientes ? [$abierta] : [], $contexto['importaciones_de_contactos']);
        // Lo de activos no se enciende en la prueba.
        $this->assertFalse($contexto['capabilities']['soporte_import_clientes']);
        $this->assertTrue($contexto['capabilities']['prueba_import_resolver']);
    }

    public function capacidades_anunciadas(): array
    {
        return [
            'las dos' => [['margen_previo', 'contactos'], true, true],
            'solo la ganancia' => [['margen_previo'], false, true],
            'un sistema anterior sin la clave' => [null, false, false],
            'una lista vacía' => [[], false, false],
        ];
    }

    public function test_un_cliente_activo_tiene_las_capacidades_nuevas_de_la_prueba_en_false(): void
    {
        $ticket = $this->ticket_de_una_prueba_lista();
        $ticket->client->update(['estado' => \App\Services\Prueba\PruebaCicloService::CLIENTE_ACTIVO]);
        $this->erp['ai-excel-import/prueba-pendientes'] = Http::response(['pendientes' => [], 'en_proceso' => false,
            'automatizar_soporte' => true, 'capacidades_prueba' => ['margen_previo', 'contactos']], 200);

        $contexto = app(SupportCodexService::class)->context($ticket->fresh());

        $this->assertFalse($contexto['capabilities']['prueba_import_clientes']);
        $this->assertFalse($contexto['capabilities']['prueba_import_margen']);
    }

    public function test_una_prueba_vencida_tiene_las_capacidades_nuevas_en_false(): void
    {
        $ticket = $this->ticket_de_una_prueba_lista();
        $ticket->client->update(['trial_ends_at' => now()->subDay()]);
        $this->erp['ai-excel-import/prueba-pendientes'] = Http::response(['pendientes' => [], 'en_proceso' => false,
            'capacidades_prueba' => ['margen_previo', 'contactos']], 200);

        $contexto = app(SupportCodexService::class)->context($ticket->fresh());

        $this->assertFalse($contexto['capabilities']['prueba_import_resolver']);
        $this->assertFalse($contexto['capabilities']['prueba_import_clientes']);
        $this->assertFalse($contexto['capabilities']['prueba_import_margen']);
    }

    /** Un cliente en prueba con su instancia lista y el ticket de WhatsApp de su dueño. */
    private function ticket_de_una_prueba_lista(): SupportTicket
    {
        $instancia = $this->crear_instancia_libre('prueba-01', 900100);
        $uuid      = $this->postear_alta()->json('uuid');
        $client    = Client::find($this->lead_del_alta($uuid)->promoted_client_id);
        $instancia->refresh();
        $instancia->update(['estado' => TrialInstance::ESTADO_ASIGNADA, 'etapa' => 'lista', 'progreso' => 100]);

        $ticket = SupportTicket::create([
            'client_id' => $client->id, 'client_user_id' => 0, 'client_user_name' => 'Lucas Prueba', 'status' => 'open',
            'source' => 'whatsapp', 'whatsapp_phone' => self::TELEFONO_E164, 'opened_at' => now(),
        ]);
        SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text', 'body' => 'Te mando mis clientes', 'delivered_at' => now()]);

        return $ticket;
    }

    public function test_un_cliente_que_no_es_una_prueba_no_trae_bloque(): void
    {
        $client = Client::create(['name' => 'Común', 'is_active' => true, 'api_url' => '', 'api_key' => Str::random(40), 'inbound_api_key' => Str::random(40), 'slug' => 'comun-' . uniqid()]);

        $this->assertNull(app(PruebaContextoSoporteService::class)->bloque_para_el_cliente($client));
        $this->assertNull(app(PruebaContextoSoporteService::class)->bloque_para_el_cliente(null));
    }
}
