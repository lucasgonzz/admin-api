<?php

namespace Tests\Feature\PruebaGratuita;

use App\Events\SupportMessageReceived;
use App\Events\SupportTicketUpdated;
use App\Models\AdminSetting;
use App\Models\PruebaWhatsappEnvio;
use App\Models\SupportCodexCase;
use App\Models\SupportCodexOperation;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\PruebaPlantillasService;
use App\Services\PruebaWhatsappService;
use App\Services\SupportCodexSettings;
use App\Services\SupportPendingReplyService;
use Illuminate\Support\Facades\Event;

/** Productor real + endpoints reales de Codex; solamente el transporte externo se reemplaza. */
class AvisosAutomaticosNoConsumenEntrantesTest extends BaseDeLaPrueba
{
    private $client;
    private $lead;
    private $ticket;
    private $inbound;
    private $operator;
    private $sender;
    private $base = '/api/claude/support-codex';

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([SupportMessageReceived::class, SupportTicketUpdated::class]);
        $this->sender = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->operator = $this->admin();
        $this->plantillas_de_la_prueba();
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, now()->subHour()->toDateTimeString());
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AFTER_ID, 0);
        $this->client = $this->cliente_en_prueba();
        $this->lead = $this->lead_en_prueba($this->client);
        $this->ticket = $this->ticket_del_cliente($this->client);
        $this->inbound = $this->ticket->messages()->firstOrFail();
    }

    private function aviso(string $plantilla = PruebaPlantillasService::ASISTENTE_LISTO): SupportMessage
    {
        $resultado = app(PruebaWhatsappService::class)->enviar_plantilla_por_ticket(
            $this->client, $this->lead, $plantilla, null, true, '+1 201-586-6682'
        );
        $this->assertSame('enviado', $resultado['estado']);
        $envio = PruebaWhatsappEnvio::where('client_id', $this->client->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame((int) $this->ticket->id, (int) $envio->support_ticket_id);
        $this->assertSame($plantilla, $envio->plantilla);
        return SupportMessage::findOrFail($envio->support_message_id);
    }

    private function claim(array $extra = [])
    {
        return $this->postJson($this->base . '/tickets/' . $this->ticket->id . '/claim', $extra + [
            'worker_id' => 'worker-avisos', 'expected_message_id' => (int) $this->inbound->id,
        ], $this->headers_de_claude());
    }

    private function pendientes(): array
    {
        return collect($this->getJson($this->base . '/pending', $this->headers_de_claude())
            ->assertOk()->json('tickets'))->where('id', (int) $this->ticket->id)->values()->all();
    }

    private function verificar_sin_responder(bool $esperado): void
    {
        $ticket = $this->ticket->fresh()->load('messages');
        $this->assertSame($esperado, app(SupportPendingReplyService::class)->ticket_sin_responder($ticket));
        $this->assertSame($esperado, SupportTicket::whereKey($ticket->id)->sinResponder()->exists());
        $this->assertSame($esperado, SupportTicket::whereKey($ticket->id)->withIndicadoresDeInicio()->firstOrFail()->sin_responder);
        $filas = $this->actingAs($this->operator, 'sanctum')->getJson('/api/admin/support-ticket')->assertOk()->json('models');
        $fila = collect($filas)->firstWhere('id', $ticket->id);
        $this->assertNotNull($fila);
        $this->assertSame($esperado, $fila['sin_responder'], 'La tarjeta y la fila del listado comparten el criterio.');
    }

    public function test_las_tarjetas_y_el_listado_no_cuentan_avisos_como_respuestas_pero_si_al_operador(): void
    {
        // Un cliente activo entra al contador de inicio; las pruebas gratuitas van en otra bandeja.
        $this->client->update(['estado' => \App\Services\Prueba\PruebaCicloService::CLIENTE_ACTIVO]);
        $this->verificar_sin_responder(true);
        $antes = $this->getJson('/api/admin/inicio/tarjetas')->assertOk()->json('tickets_sin_responder');
        $aviso = $this->aviso();
        $this->assertTrue($aviso->is_trial_import_notice);
        $this->assertTrue(SupportMessage::es_mensaje_real($aviso), 'El aviso sigue siendo parte del historial.');
        $this->verificar_sin_responder(true);
        $this->assertSame($antes, $this->getJson('/api/admin/inicio/tarjetas')->assertOk()->json('tickets_sin_responder'));

        // Mismo texto, distinta procedencia: el POST humano sí responde; no se clasifica por contenido.
        $this->postJson('/api/admin/support-ticket/' . $this->ticket->id . '/message', [
            'body' => $aviso->body, 'kind' => 'text', 'client_message_id' => 'respuesta-humana-resumen',
        ])->assertStatus(201);
        $this->aviso(PruebaPlantillasService::BIENVENIDA);
        $this->verificar_sin_responder(false);
        $this->assertSame($antes - 1, $this->getJson('/api/admin/inicio/tarjetas')->assertOk()->json('tickets_sin_responder'));
        $this->assertSame(1, $this->ticket->fresh()->messages()->where('client_message_id', 'respuesta-humana-resumen')->count());
    }

    public function test_asistente_listo_no_tapa_el_catalogo_y_sigue_visible_en_el_contexto(): void
    {
        $aviso = $this->aviso();
        $this->assertTrue($aviso->is_trial_import_notice);
        $pendientes = $this->pendientes();
        $this->assertCount(1, $pendientes);
        $this->assertSame((int) $this->inbound->id, $pendientes[0]['expected_message_id']);
        $this->assertSame('inbound', $pendientes[0]['reason']);
        $contexto = $this->getJson($this->base . '/tickets/' . $this->ticket->id, $this->headers_de_claude())
            ->assertOk()->assertJsonPath('ticket.expected_message_id', (int) $this->inbound->id)->json('ticket');
        $this->assertContains((int) $aviso->id, array_column($contexto['messages'], 'id'));
        $this->claim()->assertOk()->assertJsonPath('ticket.expected_message_id', (int) $this->inbound->id);
    }

    public function test_un_aviso_intercalado_conserva_la_reserva_y_no_admite_otro_worker(): void
    {
        $claim = $this->claim()->assertOk()->json();
        $antes = SupportCodexCase::where('support_ticket_id', $this->ticket->id)->firstOrFail()->getAttributes();
        $this->aviso();
        $this->assertSame($antes, SupportCodexCase::where('support_ticket_id', $this->ticket->id)->firstOrFail()->getAttributes());
        $this->assertSame([], $this->pendientes());
        $this->claim(['worker_id' => 'otro-worker'])->assertStatus(423);
        $this->claim(['lease_token' => $claim['lease_token'], 'thread_id' => 'hilo-ya-existente'])
            ->assertOk()->assertJsonPath('lease_token', $claim['lease_token'])
            ->assertJsonPath('ticket.thread_id', 'hilo-ya-existente')
            ->assertJsonPath('ticket.expected_message_id', (int) $this->inbound->id);
        $this->assertSame(0, SupportCodexOperation::where('support_ticket_id', $this->ticket->id)->count());
    }

    public function test_una_respuesta_humana_con_el_mismo_texto_frena_la_reserva_aunque_luego_llegue_otro_aviso(): void
    {
        $claim = $this->claim()->assertOk()->json();
        $aviso = $this->aviso();
        $humano = $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/admin/support-ticket/' . $this->ticket->id . '/message', [
                'body' => $aviso->body, 'kind' => 'text',
            ])->assertStatus(201);
        $mensaje = SupportMessage::findOrFail($humano->json('model.id'));
        $this->assertFalse($mensaje->is_trial_import_notice);
        $this->aviso(PruebaPlantillasService::BIENVENIDA);
        $this->assertSame([], $this->pendientes());
        $this->claim(['lease_token' => $claim['lease_token']])->assertStatus(409);
        $this->getJson($this->base . '/tickets/' . $this->ticket->id, $this->headers_de_claude())
            ->assertOk()->assertJsonPath('ticket.expected_message_id', (int) $mensaje->id);
        $case = SupportCodexCase::where('support_ticket_id', $this->ticket->id)->firstOrFail();
        $this->assertSame('manual_review', $case->state);
        $this->assertNull($case->lease_hash);
    }

    /** @dataProvider bloqueos */
    public function test_el_aviso_no_reabre_pausas_ni_descarta_operaciones_inciertas(string $bloqueo): void
    {
        $case = SupportCodexCase::create([
            'support_ticket_id' => $this->ticket->id, 'state' => 'ready',
            'expected_message_id' => (int) $this->inbound->id,
        ]);
        if ($bloqueo === 'manual_review') {
            $case->update(['state' => 'manual_review', 'review_reason' => 'Revisar operación antes de continuar']);
        } elseif ($bloqueo === 'paused') {
            $this->ticket->update(['claude_auto_reply' => false]);
        } else {
            SupportCodexOperation::create([
                'operation_id' => 'incierta-' . $this->ticket->id,
                'support_ticket_id' => $this->ticket->id, 'expected_message_id' => (int) $this->inbound->id,
                'action' => 'responder', 'request_hash' => str_repeat('a', 64), 'status' => $bloqueo,
            ]);
        }
        $antes = $case->fresh()->getAttributes();
        $operaciones = SupportCodexOperation::where('support_ticket_id', $this->ticket->id)->get()->toArray();
        $this->aviso();
        $this->assertSame([], $this->pendientes());
        $this->claim()->assertStatus(409);
        $this->assertSame($antes, $case->fresh()->getAttributes());
        $this->assertSame($operaciones, SupportCodexOperation::where('support_ticket_id', $this->ticket->id)->get()->toArray());
    }

    public function bloqueos(): array
    {
        return [['manual_review'], ['paused'], ['unknown'], ['sending']];
    }

    public function test_un_aviso_historico_sin_marca_no_se_reclasifica_al_leer_ni_por_su_texto(): void
    {
        $aviso = $this->aviso();
        // Representa una fila anterior al arreglo. Su reparación exige conciliación explícita.
        $aviso->update(['is_trial_import_notice' => false]);
        $this->assertSame([], $this->pendientes());
        $this->getJson($this->base . '/tickets/' . $this->ticket->id, $this->headers_de_claude())
            ->assertOk()->assertJsonPath('ticket.expected_message_id', (int) $aviso->id);
        $this->claim()->assertStatus(409);
        $this->assertFalse($aviso->fresh()->is_trial_import_notice);
    }
}
