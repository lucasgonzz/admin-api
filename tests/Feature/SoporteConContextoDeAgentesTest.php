<?php

namespace Tests\Feature;

use App\Models\AgentImplementation;
use App\Models\AgentImplementationEvent;
use App\Models\AgentImplementationStage;
use App\Models\Client;
use App\Models\Implementation;
use App\Models\ImplementationStage;
use App\Models\SupportCodexCase;
use App\Models\SupportTicket;
use App\Services\AgentImplementationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Martín ve y anota la implementación de AGENTES DE IA del cliente del ticket (misión
 * `implementacion-agentes-ia`, 28/10/2026).
 *
 *  1. **El contexto** (`GET support-codex/tickets/{id}` → `implementation.agentes`): la abierta viaja completa,
 *     sin teléfonos ni ids de Meta ni `whatsapp_ops`; la cerrada hace ≤ 30 días viaja con `abierta: false`;
 *     la vieja y la cancelada no viajan; sin agentes, `agentes` es null y el resto del bloque no cambia.
 *  2. **La nota** (`POST support-codex/tickets/{id}/implementation-note`): con `tipo: agentes` va a la bitácora
 *     de la implementación de agentes (reserva, idempotencia, etapa 1..8, 409 sin abierta); SIN `tipo` se
 *     comporta exactamente como antes (va a la del sistema y la etapa 8 sigue siendo 422).
 */
class SoporteConContextoDeAgentesTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<string, string> Headers con la clave de ingesta. */
    private $headers = ['X-Claude-Task-Key' => 'agentes-codex-test', 'Accept' => 'application/json'];

    /** @var string Base de las rutas del puente. */
    private $base = '/api/claude/support-codex';

    /** Teléfono del dueño (no tiene que aparecer en ningún bloque). */
    const TELEFONO = '+5493415550177';

    /**
     * La clave de ingesta, sin red y el reloj fijo.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.claude_task_ingest.key' => 'agentes-codex-test']);
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-28 13:00:00'));
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Un cliente activo con el teléfono del dueño.
     *
     * @param string $nombre El nombre.
     *
     * @return Client
     */
    private function cliente(string $nombre = 'Mayorista de Pesca'): Client
    {
        return Client::create([
            'name' => $nombre, 'company_name' => 'Negocio ' . $nombre, 'phone' => self::TELEFONO, 'is_active' => true,
            'api_url' => 'https://api-agentes.test', 'api_key' => 'secreto-' . Str::random(8),
        ]);
    }

    /**
     * Un ticket abierto del dueño.
     *
     * @param Client $client El cliente.
     *
     * @return SupportTicket
     */
    private function ticket(Client $client): SupportTicket
    {
        return SupportTicket::create([
            'client_id' => $client->id, 'client_user_id' => 0, 'status' => 'open', 'source' => 'whatsapp',
            'whatsapp_phone' => self::TELEFONO, 'opened_at' => now(), 'last_client_message_at' => now(),
        ]);
    }

    /**
     * Reserva el ticket para Martín y devuelve el token.
     *
     * @param SupportTicket $ticket El ticket.
     *
     * @return string
     */
    private function reservar(SupportTicket $ticket): string
    {
        $token = Str::random(64);
        SupportCodexCase::create([
            'support_ticket_id' => $ticket->id, 'state' => 'ready', 'worker_id' => 'pc-lucas',
            'lease_hash' => hash('sha256', $token), 'lease_expires_at' => now()->addMinutes(10),
        ]);

        return $token;
    }

    /**
     * Una implementación de agentes dada de alta por el servicio real (ocho etapas, la 1 en curso).
     *
     * @param Client $client El cliente.
     * @param string $camino nuevo | propio.
     *
     * @return AgentImplementation
     */
    private function agentes(Client $client, string $camino = 'propio'): AgentImplementation
    {
        return app(AgentImplementationService::class)->create($client, $camino)['implementation'];
    }

    /**
     * Una implementación del sistema en curso, con sus siete etapas.
     *
     * @param Client $client El cliente.
     * @param int    $actual La etapa actual.
     *
     * @return Implementation
     */
    private function sistema(Client $client, int $actual = 3): Implementation
    {
        $implementation = Implementation::create([
            'client_id' => $client->id, 'status' => 'in_progress', 'current_stage' => $actual,
            'started_at' => now(), 'automation_mode' => 'manual', 'form_token' => (string) Str::uuid(),
        ]);
        for ($numero = 1; $numero <= Implementation::ULTIMA_ETAPA; $numero++) {
            ImplementationStage::create([
                'implementation_id' => $implementation->id, 'stage_number' => $numero,
                'status' => $numero < $actual ? 'completed' : ($numero === $actual ? 'in_progress' : 'pending'),
            ]);
        }

        return $implementation;
    }

    /**
     * El contexto del ticket por el endpoint real.
     *
     * @param SupportTicket $ticket El ticket.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function contexto(SupportTicket $ticket)
    {
        return $this->getJson($this->base . '/tickets/' . $ticket->id, $this->headers)->assertOk();
    }

    /**
     * La abierta viaja completa: etapa actual con su nombre, las ocho etapas con data (sin `whatsapp_ops`), los
     * últimos 20 eventos más nuevos primero (con los números enmascarados) y `conectado` según haya
     * `phone_number_id`. Ni el teléfono ni los ids de Meta/Kapso aparecen en ningún lado.
     *
     * @return void
     */
    public function test_abierta_viaja_completa_y_sin_datos_personales(): void
    {
        $client         = $this->cliente();
        $implementation = $this->agentes($client);
        $servicio       = app(AgentImplementationService::class);

        $servicio->update_stage($implementation, 1, 'completed', ['web_https' => true, 'guia_enviada' => true]);
        $servicio->update_stage($implementation, 2, 'completed', ['chats_leidos' => 120]);
        $servicio->update_stage($implementation, 3, 'in_progress', ['link_enviado' => true]);
        $servicio->add_event($implementation, 'El dueño pasó su número ' . self::TELEFONO . ' y la clave es Pesca2026', 3, 'nota', 'claude');

        /* La memoria interna de los envíos, en la etapa 3: no tiene que viajar. */
        $etapa       = AgentImplementationStage::where('agent_implementation_id', $implementation->id)->where('stage_number', 3)->first();
        $etapa->data = array_merge($etapa->data, ['whatsapp_ops' => ['op-12345678' => ['huella' => 'abc', 'estado' => 'sent']]]);
        $etapa->save();

        $agentes = $this->contexto($this->ticket($client))->json('ticket.implementation.agentes');

        $this->assertSame((int) $implementation->id, $agentes['id']);
        $this->assertTrue($agentes['abierta']);
        $this->assertSame('propio', $agentes['camino']);
        $this->assertSame(3, $agentes['etapa_actual']);
        $this->assertSame('Conexión con Meta', $agentes['nombre_etapa_actual']);
        $this->assertFalse($agentes['conectado']);
        $this->assertCount(8, $agentes['etapas']);
        $this->assertSame(['numero', 'nombre', 'estado', 'completada_at', 'data'], array_keys($agentes['etapas'][0]));
        $this->assertSame('completed', $agentes['etapas'][0]['estado']);
        $this->assertNotNull($agentes['etapas'][0]['completada_at']);
        $this->assertTrue($agentes['etapas'][0]['data']['guia_enviada']);
        $this->assertSame(['link_enviado' => true], $agentes['etapas'][2]['data']);
        $this->assertSame([], $agentes['etapas'][7]['data']);

        $this->assertSame(['etapa', 'kind', 'source', 'texto', 'created_at'], array_keys($agentes['eventos'][0]));
        $this->assertSame('nota', $agentes['eventos'][0]['kind']);
        $this->assertStringContainsString('[número]', $agentes['eventos'][0]['texto']);
        $this->assertStringContainsString('[oculta]', $agentes['eventos'][0]['texto']);

        /* Con el número conectado: `conectado` true y los ids siguen sin viajar. */
        $servicio->update_fields($implementation, ['phone_number_id' => '109876543210987', 'waba_id' => '209876543210987', 'kapso_customer_id' => 'kapso-cust-xyz']);
        $respuesta = $this->contexto($this->ticket($client));
        $this->assertTrue($respuesta->json('ticket.implementation.agentes.conectado'));

        $crudo = json_encode($respuesta->json('ticket.implementation'));
        $this->assertStringNotContainsString('5493415550177', $crudo);
        $this->assertStringNotContainsString('109876543210987', $crudo);
        $this->assertStringNotContainsString('209876543210987', $crudo);
        $this->assertStringNotContainsString('kapso-cust-xyz', $crudo);
        $this->assertStringNotContainsString('whatsapp_ops', $crudo);
        $this->assertStringNotContainsString('Pesca2026', $crudo);
        $this->assertStringContainsString('"data":{}', (string) $respuesta->getContent(), 'Una etapa sin datos viaja como objeto JSON.');
    }

    /**
     * A un empleado no le viajan los eventos (null, como la conversación de sistema y tienda); las etapas sí. Al
     * dueño le viajan.
     *
     * @return void
     */
    public function test_a_un_empleado_no_le_viajan_los_eventos(): void
    {
        $client         = $this->cliente('Con Empleado');
        $implementation = $this->agentes($client);
        app(AgentImplementationService::class)->add_event($implementation, 'Quedó en mandar el DNI del titular', 1, 'nota', 'claude');

        $empleado = \App\Models\ClientEmployee::create(['client_id' => $client->id, 'name' => 'Empleado', 'phone' => '+5493415550188']);
        $ticket   = SupportTicket::create([
            'client_id' => $client->id, 'client_user_id' => 0, 'client_employee_id' => $empleado->id, 'status' => 'open',
            'source' => 'whatsapp', 'whatsapp_phone' => '+5493415550188', 'opened_at' => now(), 'last_client_message_at' => now(),
        ]);

        $agentes = $this->contexto($ticket)->json('ticket.implementation.agentes');
        $this->assertNull($agentes['eventos']);
        $this->assertCount(8, $agentes['etapas']);
        $this->assertSame(1, $agentes['etapa_actual']);
        $this->assertStringNotContainsString('DNI del titular', json_encode($agentes));

        $del_dueno = $this->contexto($this->ticket($client))->json('ticket.implementation.agentes.eventos');
        $this->assertIsArray($del_dueno);
        $this->assertSame('Quedó en mandar el DNI del titular', $del_dueno[0]['texto']);
    }

    /**
     * Viajan los últimos 20 eventos, más nuevos primero.
     *
     * @return void
     */
    public function test_viajan_los_ultimos_veinte_eventos(): void
    {
        $client         = $this->cliente();
        $implementation = $this->agentes($client);
        for ($i = 1; $i <= 25; $i++) {
            app(AgentImplementationService::class)->add_event($implementation, 'Nota ' . $i, 1, 'nota', 'claude');
        }

        $eventos = $this->contexto($this->ticket($client))->json('ticket.implementation.agentes.eventos');

        $this->assertCount(20, $eventos);
        $this->assertSame('Nota 25', $eventos[0]['texto']);
        $this->assertSame('Nota 6', $eventos[19]['texto']);
    }

    /**
     * Cerrada hace ≤ 30 días: viaja con `abierta: false`. Cerrada hace más: no viaja (y sin otra implementación,
     * `implementation` es null). Cancelada: no viaja.
     *
     * @return void
     */
    public function test_cerrada_reciente_viaja_y_vieja_o_cancelada_no(): void
    {
        $reciente = $this->cliente('Cerrada Reciente');
        $impl     = $this->agentes($reciente);
        app(AgentImplementationService::class)->update_fields($impl, ['status' => 'completed']);
        AgentImplementation::where('id', $impl->id)->update(['completed_at' => now()->subDays(10)]);

        $this->contexto($this->ticket($reciente))
            ->assertJsonPath('ticket.implementation.agentes.abierta', false)
            ->assertJsonPath('ticket.implementation.agentes.id', $impl->id)
            ->assertJsonPath('ticket.implementation.sistema', null);

        $vieja = $this->cliente('Cerrada Vieja');
        $impl  = $this->agentes($vieja);
        app(AgentImplementationService::class)->update_fields($impl, ['status' => 'completed']);
        AgentImplementation::where('id', $impl->id)->update(['completed_at' => now()->subDays(40)]);

        $this->contexto($this->ticket($vieja))->assertJsonPath('ticket.implementation', null);

        $cancelada = $this->cliente('Cancelada');
        $impl      = $this->agentes($cancelada);
        app(AgentImplementationService::class)->update_fields($impl, ['status' => 'cancelled']);

        $this->contexto($this->ticket($cancelada))->assertJsonPath('ticket.implementation', null);
    }

    /**
     * Sin implementación de agentes, `agentes` es null y la del sistema viaja como siempre.
     *
     * @return void
     */
    public function test_sin_agentes_la_clave_es_null_y_el_sistema_viaja_igual(): void
    {
        $client = $this->cliente('Solo Sistema');
        $this->sistema($client, 3);

        $respuesta = $this->contexto($this->ticket($client));
        $respuesta->assertJsonPath('ticket.implementation.agentes', null)
            ->assertJsonPath('ticket.implementation.sistema.etapa_actual.numero', 3);
    }

    /**
     * `implementation-note` con `tipo: agentes`: sin reserva 423; con reserva, un evento `nota`/`martin` en la
     * etapa actual sin cambiar estados (201); repetida no se duplica (200, `registrada: false`); con etapa 8 va a
     * esa; sin implementación de agentes abierta, 409 `sin_implementacion_abierta` (aunque haya una del sistema).
     *
     * @return void
     */
    public function test_nota_de_martin_con_tipo_agentes(): void
    {
        $client         = $this->cliente();
        $implementation = $this->agentes($client);
        $ticket         = $this->ticket($client);
        $ruta           = $this->base . '/tickets/' . $ticket->id . '/implementation-note';
        $antes          = AgentImplementationEvent::where('agent_implementation_id', $implementation->id)->count();

        $this->postJson($ruta, ['lease_token' => Str::random(64), 'texto' => 'Ya cargó la tarjeta', 'tipo' => 'agentes'], $this->headers)->assertStatus(423);
        $this->assertSame($antes, AgentImplementationEvent::where('agent_implementation_id', $implementation->id)->count());

        $token = $this->reservar($ticket);

        $primera = $this->postJson($ruta, ['lease_token' => $token, 'texto' => 'Ya cargó la tarjeta', 'tipo' => 'agentes'], $this->headers);
        $primera->assertStatus(201)
            ->assertJsonPath('registrada', true)
            ->assertJsonPath('tipo', 'agentes')
            ->assertJsonPath('etapa', 1)
            ->assertJsonPath('implementation_id', $implementation->id);

        $evento = AgentImplementationEvent::where('agent_implementation_id', $implementation->id)->orderByDesc('id')->first();
        $this->assertSame('nota', $evento->kind);
        $this->assertSame('martin', $evento->source);
        $this->assertSame('Ya cargó la tarjeta', $evento->texto);
        $this->assertSame(1, (int) $evento->stage_number);

        $this->postJson($ruta, ['lease_token' => $token, 'texto' => 'Ya cargó la tarjeta', 'tipo' => 'agentes'], $this->headers)
            ->assertStatus(200)
            ->assertJsonPath('registrada', false);
        $this->assertSame($antes + 1, AgentImplementationEvent::where('agent_implementation_id', $implementation->id)->count());

        $this->postJson($ruta, ['lease_token' => $token, 'texto' => 'Responde solo desde hoy', 'tipo' => 'agentes', 'etapa' => 8], $this->headers)
            ->assertStatus(201)
            ->assertJsonPath('etapa', 8);

        $this->postJson($ruta, ['lease_token' => $token, 'texto' => 'x', 'tipo' => 'agentes', 'etapa' => 9], $this->headers)->assertStatus(422);
        $this->postJson($ruta, ['lease_token' => $token, 'texto' => 'x', 'tipo' => 'tienda'], $this->headers)->assertStatus(422);

        /* No cambió ningún estado. */
        $this->assertSame('in_progress', $implementation->fresh()->status);
        $this->assertSame(1, (int) $implementation->fresh()->current_stage);
        $this->assertSame('pending', AgentImplementationStage::where('agent_implementation_id', $implementation->id)->where('stage_number', 8)->value('status'));

        /* Sin implementación de agentes abierta (aunque haya del sistema): 409. */
        $otro = $this->cliente('Sin Agentes');
        $this->sistema($otro, 3);
        $otro_ticket = $this->ticket($otro);
        $otro_token  = $this->reservar($otro_ticket);
        $this->postJson($this->base . '/tickets/' . $otro_ticket->id . '/implementation-note', ['lease_token' => $otro_token, 'texto' => 'Algo', 'tipo' => 'agentes'], $this->headers)
            ->assertStatus(409);
    }

    /**
     * SIN `tipo` (y con `tipo: sistema`) la nota va a la implementación del SISTEMA como siempre, aunque el
     * cliente tenga también una de agentes abierta: la bitácora de agentes no se toca y la etapa 8 sigue siendo
     * 422 (el sistema tiene siete).
     *
     * @return void
     */
    public function test_sin_tipo_la_nota_va_al_sistema_como_siempre(): void
    {
        $client  = $this->cliente('Con Las Dos');
        $sistema = $this->sistema($client, 3);
        $agentes = $this->agentes($client);
        $ticket  = $this->ticket($client);
        $token   = $this->reservar($ticket);
        $ruta    = $this->base . '/tickets/' . $ticket->id . '/implementation-note';
        $eventos = AgentImplementationEvent::where('agent_implementation_id', $agentes->id)->count();

        $sin_tipo = $this->postJson($ruta, ['lease_token' => $token, 'texto' => 'Mandó la lista de precios'], $this->headers);
        $sin_tipo->assertStatus(201)
            ->assertJsonPath('registrada', true)
            ->assertJsonPath('implementation_id', $sistema->id)
            ->assertJsonPath('etapa', 3);
        /* La respuesta del sistema es la de siempre: sin claves nuevas. */
        $this->assertSame(['registrada', 'implementation_id', 'etapa', 'at'], array_keys($sin_tipo->json()));

        $this->postJson($ruta, ['lease_token' => $token, 'texto' => 'Queda en mandar fotos', 'tipo' => 'sistema'], $this->headers)
            ->assertStatus(201)
            ->assertJsonPath('implementation_id', $sistema->id);

        $data = ImplementationStage::where('implementation_id', $sistema->id)->where('stage_number', 3)->first()->data;
        $this->assertCount(2, $data['actions']);
        $this->assertSame($eventos, AgentImplementationEvent::where('agent_implementation_id', $agentes->id)->count());

        $this->postJson($ruta, ['lease_token' => $token, 'texto' => 'x', 'etapa' => 8], $this->headers)->assertStatus(422);
    }
}
