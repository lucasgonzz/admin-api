<?php

namespace Tests\Feature;

use App\Events\SupportMessageReceived;
use App\Events\SupportTicketUpdated;
use App\Models\AdminSetting;
use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientEmployee;
use App\Models\SupportCodexCase;
use App\Models\SupportCodexOperation;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Services\SupportAiSuggestionService;
use App\Services\SupportCodexService;
use App\Services\SupportCodexSettings;
use App\Services\SupportWhatsappOpenerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** HTTP real, base del slot y envío sustituido: prueba reservas, duplicados y cierre seguro. */
class SoporteAutomaticoEnCodexTest extends TestCase
{
    use DatabaseTransactions;

    private $headers = ['X-Claude-Task-Key' => 'soporte-codex-test', 'Accept' => 'application/json'];
    private $base = '/api/claude/support-codex';
    private $deliveries;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.claude_task_ingest.key' => 'soporte-codex-test']);
        Carbon::setTestNow(Carbon::parse('2026-10-24 13:00:00'));
        Http::fake(['https://api-cliente.test/*' => Http::response([
            'query_type' => 'stock', 'data' => [['name' => 'Coca', 'stock' => 3]],
        ])]);
        Event::fake([SupportMessageReceived::class, SupportTicketUpdated::class]);
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, '2026-10-24 12:00:00');
        $this->deliveries = new class extends SupportWhatsappOpenerService {
            public $bodies = [];
            public $success = true;
            public function __construct()
            {
                // El espía no necesita transportes ni resolutores de URL.
            }
            public function deliver_follow_up(SupportTicket $ticket, SupportMessage $message, string $admin_name): array
            {
                $this->bodies[] = $message->body;
                if ($this->success) {
                    $message->whatsapp_message_id = 'wamid.codex.' . count($this->bodies);
                    $message->save();
                }
                return ['delivery' => $this->success ? 'sent' : 'failed', 'message_id' => $message->whatsapp_message_id,
                    'used_template' => false, 'error' => $this->success ? null : 'Fallo simulado'];
            }
        };
        $this->app->instance(SupportWhatsappOpenerService::class, $this->deliveries);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ticket(): SupportTicket
    {
        $client = Client::create([
            'name' => 'Cliente Codex', 'company_name' => 'Comercio Codex',
            'phone' => '+5493417770000', 'is_active' => true,
            'api_url' => 'https://api-cliente.test', 'api_key' => 'secreto-que-no-sale',
        ]);
        $ticket = SupportTicket::create([
            'client_id' => $client->id, 'client_user_id' => 0, 'status' => 'open',
            'source' => 'whatsapp', 'whatsapp_phone' => $client->phone,
            'opened_at' => now(), 'last_client_message_at' => now(),
        ]);
        $this->inbound($ticket, 'No puedo vender.');
        return $ticket;
    }

    private function inbound(SupportTicket $ticket, string $body): SupportMessage
    {
        $message = SupportMessage::create([
            'support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'body' => $body,
            'kind' => 'text', 'delivered_at' => now(),
        ]);
        $ticket->last_client_message_at = now();
        $ticket->save();
        return $message;
    }

    private function claim(SupportTicket $ticket): array
    {
        $last = SupportMessage::where('support_ticket_id', $ticket->id)->orderByDesc('id')->first();
        return $this->postJson($this->base . '/tickets/' . $ticket->id . '/claim', [
            'worker_id' => 'pc-lucas', 'expected_message_id' => $last->id,
        ], $this->headers)->assertOk()->json();
    }

    private function reply(SupportTicket $ticket, array $claim, array $extra = [])
    {
        return $this->postJson($this->base . '/tickets/' . $ticket->id . '/reply', array_merge([
            'lease_token' => $claim['lease_token'],
            'expected_message_id' => $claim['ticket']['expected_message_id'],
            'operation_id' => 'op-' . $ticket->id . '-' . $claim['ticket']['expected_message_id'],
            'action' => 'responder', 'body' => 'Probá abrir Ventas.',
            'thread_id' => 'thread-ticket-' . $ticket->id,
        ], $extra), $this->headers);
    }

    private function toggle_agent(SupportTicket $ticket): void
    {
        $admin = Admin::firstOrCreate(['email' => 'reactivar-' . $ticket->id . '@test.local'], ['name' => 'Lucas', 'password' => bcrypt('prueba')]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket/' . $ticket->id . '/toggle-claude-auto-reply')->assertOk();
        $ticket->refresh();
    }

    public function test_encender_recupera_consulta_pendiente_tras_atencion_manual_sin_consumirla(): void
    {
        $ticket = $this->ticket();
        app(SupportCodexService::class)->pause_for_human($ticket);
        SupportCodexCase::where('support_ticket_id', $ticket->id)->update(['thread_id' => 'hilo-conservado']);
        SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'admin', 'kind' => 'text', 'body' => 'Ya lo revisé.', 'delivered_at' => now()]);
        $this->toggle_agent($ticket);
        $last = $this->inbound($ticket, 'Ahora aparece una barra negra al ampliar la foto.');
        $this->getJson($this->base . '/pending', $this->headers)->assertJsonCount(0, 'tickets');
        $this->toggle_agent($ticket);
        $this->getJson($this->base . '/pending', $this->headers)->assertJsonCount(1, 'tickets')
            ->assertJsonPath('tickets.0.expected_message_id', $last->id)->assertJsonPath('tickets.0.thread_id', 'hilo-conservado');
        $claim = $this->claim($ticket);
        $this->assertSame($last->id, $claim['ticket']['expected_message_id']);
        $this->assertCount(0, $this->deliveries->bodies);
    }

    private function human_reply(SupportTicket $ticket): SupportMessage
    {
        $admin = Admin::firstOrCreate(['email' => 'respuesta-' . $ticket->id . '@test.local'], ['name' => 'Lucas', 'password' => bcrypt('prueba')]);
        $result = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket/' . $ticket->id . '/message', [
            'body' => 'Entren siempre desde la dirección del sistema.', 'kind' => 'text',
        ])->assertStatus(201);
        return SupportMessage::findOrFail($result->json('model.id'));
    }

    public function test_audio_nuevo_tras_respuesta_humana_retoma_sin_interruptor_y_descarta_turno_viejo(): void
    {
        $ticket = $this->ticket();
        $old = $this->claim($ticket);
        $human = $this->human_reply($ticket);
        $case = SupportCodexCase::where('support_ticket_id', $ticket->id)->first();
        $case->thread_id = 'thread-ticket-' . $ticket->id;
        $case->save();
        $this->getJson($this->base . '/pending', $this->headers)->assertJsonCount(0, 'tickets');
        $this->reply($ticket, $old)->assertStatus(423);
        $last = $this->inbound($ticket, 'Sí, ahí lo arreglamos. Era la antena de Internet.');
        $last->kind = 'audio';
        $last->save();
        $this->getJson($this->base . '/pending', $this->headers)->assertJsonCount(1, 'tickets')
            ->assertJsonPath('tickets.0.expected_message_id', $last->id)->assertJsonPath('tickets.0.reason', 'inbound');
        $new = $this->claim($ticket);
        $this->assertNotSame($old['lease_token'], $new['lease_token']);
        $this->assertSame('ready', $new['ticket']['state']);
        $this->assertSame('thread-ticket-' . $ticket->id, $new['ticket']['thread_id']);
        $this->reply($ticket, $old)->assertStatus(423);
        $this->reply($ticket, $new, ['body' => 'Gracias por avisar.'])->assertOk()->assertJsonPath('operation.status', 'sent');
        $this->assertSame(['Entren siempre desde la dirección del sistema.', 'Gracias por avisar.'], $this->deliveries->bodies);
        $this->getJson($this->base . '/pending', $this->headers)->assertJsonCount(0, 'tickets');
        $this->assertGreaterThan($human->id, $last->id);
    }

    public function test_pausa_humana_no_reprocesa_entrante_anterior_a_la_pausa_ni_borrador(): void
    {
        $ticket = $this->ticket();
        app(SupportCodexService::class)->pause_for_human($ticket);
        $this->getJson($this->base . '/pending', $this->headers)->assertJsonCount(0, 'tickets');
        SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'admin', 'kind' => 'text', 'body' => 'Borrador', 'is_ai_suggestion_draft' => true]);
        $this->getJson($this->base . '/pending', $this->headers)->assertJsonCount(0, 'tickets');
    }

    public function test_respuesta_humana_y_entrante_no_eluden_apagado_revision_ni_operacion_inconclusa(): void
    {
        foreach (['apagado', 'revision', 'conciliado', 'unknown', 'sending'] as $scenario) {
            $ticket = $this->ticket();
            if (in_array($scenario, ['revision', 'conciliado'], true)) {
                SupportCodexCase::create(['support_ticket_id' => $ticket->id, 'state' => 'manual_review', 'review_reason' => 'Revisar: ' . $scenario]);
            }
            $this->human_reply($ticket);
            $last = $this->inbound($ticket, 'Ya arreglamos Internet.');
            if ($scenario === 'apagado') {
                $this->toggle_agent($ticket);
            } elseif (in_array($scenario, ['unknown', 'sending'], true)) {
                SupportCodexOperation::create(['operation_id' => 'bloqueo-' . $ticket->id, 'support_ticket_id' => $ticket->id, 'expected_message_id' => $last->id, 'action' => 'responder', 'request_hash' => str_repeat('b', 64), 'status' => $scenario]);
            }
            $this->getJson($this->base . '/pending', $this->headers)->assertJsonCount(0, 'tickets');
            $this->postJson($this->base . '/tickets/' . $ticket->id . '/claim', ['worker_id' => 'pc-lucas', 'expected_message_id' => $last->id], $this->headers)->assertStatus(409);
            if (in_array($scenario, ['revision', 'conciliado'], true)) {
                $this->assertDatabaseHas('support_codex_cases', ['support_ticket_id' => $ticket->id, 'review_reason' => 'Revisar: ' . $scenario]);
            }
        }
        $this->assertSame(array_fill(0, 5, 'Entren siempre desde la dirección del sistema.'), $this->deliveries->bodies);
    }

    public function test_segunda_respuesta_humana_renueva_frontera_y_no_reprocesa_el_entrante_intermedio(): void
    {
        $ticket = $this->ticket();
        $this->human_reply($ticket);
        $this->inbound($ticket, 'Lo pruebo ahora.');
        $this->getJson($this->base . '/pending', $this->headers)->assertJsonCount(1, 'tickets');
        $this->human_reply($ticket);
        $this->getJson($this->base . '/pending', $this->headers)->assertJsonCount(0, 'tickets');
        $last = $this->inbound($ticket, 'Ahora ya funciona.');
        // claim vuelve a validar aunque el monitor no haya consultado pending antes.
        $claim = $this->claim($ticket);
        $this->assertSame($last->id, $claim['ticket']['expected_message_id']);
        $this->assertSame('ready', $claim['ticket']['state']);
        $this->assertCount(2, $this->deliveries->bodies);
    }

    public function test_pausa_humana_guardada_por_version_anterior_recupera_solo_el_entrante_nuevo(): void
    {
        $ticket = $this->ticket();
        $old = $this->claim($ticket);
        // La versión anterior dejaba este motivo y el expected del último claim, sin
        // actualizar la frontera al responder Lucas. Debe migrar por comportamiento.
        SupportCodexCase::where('support_ticket_id', $ticket->id)->update([
            'state' => 'manual_review', 'review_reason' => 'Lucas retomó la atención desde el admin.',
            'lease_hash' => null, 'lease_expires_at' => null,
        ]);
        SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'admin', 'kind' => 'text', 'body' => 'Revisen Internet.', 'delivered_at' => now()]);
        $this->getJson($this->base . '/pending', $this->headers)->assertJsonCount(0, 'tickets');
        $last = $this->inbound($ticket, 'Ya arreglamos la antena.');
        $new = $this->claim($ticket);
        $this->assertNotSame($old['lease_token'], $new['lease_token']);
        $this->assertSame('ready', $new['ticket']['state']);
        $this->assertSame($last->id, $new['ticket']['expected_message_id']);
        $this->reply($ticket, $old)->assertStatus(423);
    }

    public function test_entrante_tras_respuesta_humana_conserva_mision_y_no_anuncia_resolucion(): void
    {
        $ticket = $this->ticket();
        $first = $this->claim($ticket);
        $this->reply($ticket, $first, ['action' => 'escalar', 'mission' => '/cruzada Arreglar visor'])->assertOk();
        $this->human_reply($ticket);
        $last = $this->inbound($ticket, '¿Y cómo cambio las columnas?');
        $claim = $this->claim($ticket);
        $this->assertSame('mission_pending', $claim['ticket']['state']);
        $this->assertSame('/cruzada Arreglar visor', $claim['ticket']['mission']);
        $this->reply($ticket, $claim, ['action' => 'solicitar_confirmacion'])->assertStatus(409);
        $this->reply($ticket, $claim, ['body' => 'Activá el salto de línea. El visor sigue pendiente.'])->assertOk()
            ->assertJsonPath('ticket.state', 'mission_pending');
        $this->assertNotNull($ticket->fresh()->escalated_at);
        $this->assertSame($last->id, SupportCodexCase::where('support_ticket_id', $ticket->id)->first()->handled_message_id);
        $confirmation = $this->inbound($ticket, 'Ahora ya funciona.');
        $next = $this->claim($ticket);
        $before = count($this->deliveries->bodies);
        $this->reply($ticket, $next, ['action' => 'cerrar', 'confirmation_message_id' => $confirmation->id])
            ->assertStatus(409);
        $this->assertCount($before, $this->deliveries->bodies);
        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertNotNull($ticket->fresh()->escalated_at);
        $this->assertDatabaseHas('support_codex_cases', ['support_ticket_id' => $ticket->id, 'state' => 'mission_pending', 'mission' => '/cruzada Arreglar visor']);
    }

    public function test_encender_sin_caso_previo_recupera_entrante_en_ambos_ordenes(): void
    {
        foreach ([true, false] as $mensaje_antes_de_apagar) {
            $ticket = $this->ticket();
            if ($mensaje_antes_de_apagar) {
                $last = $this->inbound($ticket, 'Necesito una respuesta.');
            }
            $this->toggle_agent($ticket);
            if (! $mensaje_antes_de_apagar) {
                $last = $this->inbound($ticket, 'Necesito una respuesta.');
            }
            $this->toggle_agent($ticket);
            $claim = $this->claim($ticket);
            $this->assertSame($last->id, $claim['ticket']['expected_message_id']);
        }
    }

    public function test_apagar_y_prender_no_revive_la_reserva_anterior(): void
    {
        $ticket = $this->ticket();
        $old = $this->claim($ticket);
        $this->toggle_agent($ticket);
        $this->toggle_agent($ticket);
        $this->reply($ticket, $old)->assertStatus(423);
        $new = $this->claim($ticket);
        $this->assertNotSame($old['lease_token'], $new['lease_token']);
        $this->assertCount(0, $this->deliveries->bodies);
    }

    public function test_encender_no_repite_consulta_ya_respondida_por_lucas(): void
    {
        $ticket = $this->ticket();
        app(SupportCodexService::class)->pause_for_human($ticket);
        $this->toggle_agent($ticket);
        SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'admin', 'kind' => 'text', 'body' => 'Se hace desde Ventas.', 'delivered_at' => now()]);
        $this->toggle_agent($ticket);
        $this->getJson($this->base . '/pending', $this->headers)->assertJsonCount(0, 'tickets');
        $this->assertDatabaseHas('support_codex_cases', ['support_ticket_id' => $ticket->id, 'state' => 'manual_review']);
    }

    public function test_encender_no_repite_mensaje_ya_atendido_ni_reabre_ticket_cerrado(): void
    {
        foreach (['open', 'closed'] as $status) {
            $ticket = $this->ticket();
            $last = SupportMessage::where('support_ticket_id', $ticket->id)->orderByDesc('id')->first();
            $case = SupportCodexCase::create(['support_ticket_id' => $ticket->id, 'state' => 'manual_review', 'handled_message_id' => $last->id, 'review_reason' => 'Lucas retomó la atención desde el admin.']);
            $this->toggle_agent($ticket);
            $ticket->status = $status;
            $ticket->save();
            $this->toggle_agent($ticket);
            $this->assertSame('manual_review', $case->fresh()->state);
        }
        $this->getJson($this->base . '/pending', $this->headers)->assertJsonCount(0, 'tickets');
    }

    public function test_encender_no_elude_revision_por_error_envio_incierto_ni_mision(): void
    {
        foreach (['revision', 'unknown', 'sending', 'mission_pending', 'mision_pausada'] as $scenario) {
            $ticket = $this->ticket();
            $last = SupportMessage::where('support_ticket_id', $ticket->id)->orderByDesc('id')->first();
            $state = $scenario === 'mission_pending' ? 'mission_pending' : 'manual_review';
            $case = SupportCodexCase::create([
                'support_ticket_id' => $ticket->id, 'state' => $state,
                'review_reason' => $scenario === 'revision' ? 'Falló la atención; revisar.' : 'Lucas retomó la atención desde el admin.',
                'mission' => in_array($scenario, ['mission_pending', 'mision_pausada'], true) ? 'Arreglo todavía pendiente.' : null,
            ]);
            if (in_array($scenario, ['unknown', 'sending'], true)) {
                SupportCodexOperation::create(['operation_id' => 'incierta-' . $ticket->id, 'support_ticket_id' => $ticket->id, 'expected_message_id' => $last->id, 'action' => 'responder', 'request_hash' => str_repeat('a', 64), 'status' => $scenario]);
            }
            $this->toggle_agent($ticket);
            $this->toggle_agent($ticket);
            $this->assertSame($state, $case->fresh()->state);
        }
        $this->assertCount(0, $this->deliveries->bodies);
    }

    public function test_reactivar_pausa_humana_es_idempotente_y_no_pisa_reserva_vigente(): void
    {
        $ticket = $this->ticket();
        app(SupportCodexService::class)->pause_for_human($ticket);
        $case = SupportCodexCase::where('support_ticket_id', $ticket->id)->first();
        $case->lease_hash = hash('sha256', 'reserva-vigente');
        $case->lease_expires_at = now()->addMinutes(5);
        $case->save();
        app(SupportCodexService::class)->agent_toggled($ticket);
        $this->assertSame('manual_review', $case->fresh()->state);
        $case->lease_expires_at = now()->subMinute();
        $case->save();
        app(SupportCodexService::class)->agent_toggled($ticket);
        app(SupportCodexService::class)->agent_toggled($ticket);
        $this->assertSame('ready', $case->fresh()->state);
        $this->claim($ticket);
    }

    public function test_sin_clave_no_hay_lectura_ni_activacion(): void
    {
        $this->getJson($this->base . '/pending')->assertStatus(401);
        $this->putJson($this->base . '/settings', ['enabled' => true])->assertStatus(401);
    }

    public function test_activacion_no_toma_el_backlog_y_no_filtra_secretos(): void
    {
        $ticket = $this->ticket();
        $ticket->last_client_message_at = now()->subDays(1);
        $ticket->save();
        $this->getJson($this->base . '/pending', $this->headers)->assertOk()->assertJsonCount(0, 'tickets');
        $this->inbound($ticket, 'Volví a probar y no puedo.');
        $result = $this->getJson($this->base . '/pending', $this->headers)->assertOk()->assertJsonCount(1, 'tickets');
        $result = $this->getJson($this->base . '/tickets/' . $ticket->id, $this->headers)->assertOk();
        $this->assertStringNotContainsString('secreto-que-no-sale', $result->getContent());
        $this->assertArrayNotHasKey('api_key', $result->json('ticket.client'));
    }

    public function test_una_reserva_excluye_otro_worker_y_se_puede_renovar(): void
    {
        $ticket = $this->ticket();
        $claim = $this->claim($ticket);
        $path = $this->base . '/tickets/' . $ticket->id . '/claim';
        $this->postJson($path, ['worker_id' => 'otra-pc', 'expected_message_id' => $claim['ticket']['expected_message_id']], $this->headers)->assertStatus(423);
        $this->postJson($path, [
            'worker_id' => 'pc-lucas', 'expected_message_id' => $claim['ticket']['expected_message_id'],
            'lease_token' => $claim['lease_token'], 'thread_id' => 'thread-uno',
        ], $this->headers)->assertOk()->assertJsonPath('ticket.thread_id', 'thread-uno');
        $this->getJson($this->base . '/pending', $this->headers)->assertJsonCount(0, 'tickets');
    }

    public function test_primera_activacion_no_toma_mensajes_previos_del_mismo_segundo(): void
    {
        $ticket = $this->ticket();
        AdminSetting::where('key', SupportCodexSettings::KEY_ACTIVATED_AT)->delete();
        $this->putJson($this->base . '/settings', ['enabled' => true], $this->headers)->assertOk();
        $this->getJson($this->base . '/pending', $this->headers)->assertJsonCount(0, 'tickets');
        $this->inbound($ticket, 'Nuevo mensaje después de activar, en el mismo segundo.');
        $this->getJson($this->base . '/pending', $this->headers)->assertJsonCount(1, 'tickets');
    }

    public function test_primer_envio_conserva_saludo_contextual_y_enlace_sin_duplicar_replay(): void
    {
        $ticket = $this->ticket();
        $claim = $this->claim($ticket);
        $body = 'Buenas tardes. Podés consultar el paso a paso acá: https://comerciocity.com/recursos/ventas';
        $this->reply($ticket, $claim, ['body' => $body])->assertOk()->assertJsonPath('operation.status', 'sent');
        $this->reply($ticket, $claim, ['body' => $body])->assertOk()->assertJsonPath('operation.status', 'sent');
        $this->assertCount(1, $this->deliveries->bodies);
        $this->assertSame($body, $this->deliveries->bodies[0]);
        $this->assertDatabaseHas('support_messages', ['support_ticket_id' => $ticket->id, 'body' => $body]);
        $this->reply($ticket, $claim, ['body' => 'Otra respuesta'])->assertStatus(409);
        $this->assertCount(1, $this->deliveries->bodies);
    }

    public function test_mensaje_nuevo_descarta_la_respuesta_vieja(): void
    {
        $ticket = $this->ticket();
        $claim = $this->claim($ticket);
        $this->inbound($ticket, 'Pará, era otro comprobante.');
        $this->reply($ticket, $claim)->assertStatus(409);
        $this->assertCount(0, $this->deliveries->bodies);
    }

    public function test_atencion_humana_invalida_reserva_y_no_hay_competencia_de_ia_api(): void
    {
        $ticket = $this->ticket();
        $claim = $this->claim($ticket);
        app(SupportCodexService::class)->pause_for_human($ticket);
        $this->reply($ticket, $claim)->assertStatus(423);
        $this->getJson($this->base . '/pending', $this->headers)->assertJsonCount(0, 'tickets');
        $generated = app(SupportAiSuggestionService::class)->generate($ticket);
        $this->assertSame('', $generated['suggested_message']);
        Http::assertNothingSent();
    }

    public function test_gracias_ambiguos_no_cierran_pero_confirmacion_si_y_recibe_de_nada(): void
    {
        $ticket = $this->ticket();
        $claim = $this->claim($ticket);
        $this->reply($ticket, $claim, [
            'action' => 'solicitar_confirmacion', 'body' => '¿Me confirmás si ahora podés vender?',
        ])->assertOk();
        $message = $this->inbound($ticket, 'No, sigue igual, gracias.');
        $claim = $this->claim($ticket);
        $this->reply($ticket, $claim, ['action' => 'cerrar', 'confirmation_message_id' => $message->id])->assertStatus(422);
        $this->assertSame('open', $ticket->fresh()->status);
        // Una respuesta rechazada no consume la reserva: se libera antes de otro turno.
        $this->postJson($this->base . '/tickets/' . $ticket->id . '/release', [
            'lease_token' => $claim['lease_token'],
        ], $this->headers)->assertOk();
        $message = $this->inbound($ticket, 'Sí, ya quedó resuelto, muchas gracias.');
        $claim = $this->claim($ticket);
        $this->reply($ticket, $claim, [
            'action' => 'cerrar', 'confirmation_message_id' => $message->id, 'body' => 'Queda cerrado el caso.',
        ])->assertOk()->assertJsonPath('ticket.status', 'closed');
        $this->assertStringContainsString('De nada', end($this->deliveries->bodies));
    }

    public function test_envio_no_confirmado_no_se_reintenta_ni_cierra(): void
    {
        $ticket = $this->ticket();
        $this->deliveries->success = false;
        $claim = $this->claim($ticket);
        $this->reply($ticket, $claim)->assertOk()->assertJsonPath('operation.status', 'unknown');
        $this->reply($ticket, $claim)->assertOk()->assertJsonPath('operation.status', 'unknown');
        $this->assertCount(1, $this->deliveries->bodies);
        $this->getJson($this->base . '/pending', $this->headers)->assertJsonCount(0, 'tickets');
        $this->assertSame('open', $ticket->fresh()->status);
    }

    /** @dataProvider confirmaciones_contextuales */
    public function test_confirmacion_breve_reconoce_la_pregunta_y_cierra_despues_del_envio(string $question, string $answer): void
    {
        $ticket = $this->ticket();
        $first = $this->claim($ticket);
        $this->reply($ticket, $first, ['action' => 'solicitar_confirmacion', 'body' => $question])->assertOk();
        $message = $this->inbound($ticket, $answer);
        $claim = $this->claim($ticket);
        $payload = ['action' => 'cerrar', 'confirmation_message_id' => $message->id, 'body' => 'Gracias por confirmar.'];
        $this->reply($ticket, $claim, $payload)->assertOk()
            ->assertJsonPath('operation.status', 'sent')->assertJsonPath('ticket.status', 'closed');
        $this->assertSame('closed', SupportCodexCase::where('support_ticket_id', $ticket->id)->first()->state);
        $this->assertCount(2, $this->deliveries->bodies);
        // Repetir la misma operación definitiva no vuelve a mandar la despedida.
        $this->reply($ticket, $claim, $payload)->assertOk()->assertJsonPath('operation.status', 'sent');
        $this->assertCount(2, $this->deliveries->bodies);
    }

    public function confirmaciones_contextuales(): array
    {
        return [
            'Panchito todo normal' => ['¿Ya te abre el sistema en Panchito 2 y podés trabajar normalmente?', 'Todo normal'],
            'Panchito todo bien' => ['¿Me confirmás que ya podés abrir y usar el sistema con normalidad?', 'Todo bien'],
            'normal con agradecimiento' => ['¿Quedó resuelto?', 'Sí, todo normal. Muchas gracias.'],
            'sí a verificar venta' => ['¿Me confirmás si ahora podés vender?', 'Sí, gracias'],
            'confirmación breve' => ['¿Quedó resuelto?', 'Sí'],
        ];
    }

    /** @dataProvider respuestas_que_no_confirman */
    public function test_frase_breve_no_cierra_sin_contexto_de_solucion_o_con_dudas(string $action, string $question, string $answer): void
    {
        $ticket = $this->ticket();
        $first = $this->claim($ticket);
        $this->reply($ticket, $first, ['action' => $action, 'body' => $question])->assertOk();
        $message = $this->inbound($ticket, $answer);
        $claim = $this->claim($ticket);
        $this->reply($ticket, $claim, ['action' => 'cerrar', 'confirmation_message_id' => $message->id])
            ->assertStatus(422);
        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertCount(1, $this->deliveries->bodies);
        $this->assertSame(0, SupportCodexOperation::where('support_ticket_id', $ticket->id)->where('action', 'cerrar')->count());
    }

    public function respuestas_que_no_confirman(): array
    {
        return [
            'saludo social' => ['solicitar_confirmacion', 'Hola, ¿cómo estás?', 'Todo bien'],
            'sí a saludo social' => ['solicitar_confirmacion', '¿Todo bien?', 'Sí, gracias'],
            'estado listo sin pedido' => ['responder', '¿Quedó resuelto?', 'Todo normal'],
            'afirmación del agente sin pregunta' => ['solicitar_confirmacion', 'Ya podés abrir el sistema.', 'Todo normal'],
            'agradecimiento aislado' => ['solicitar_confirmacion', '¿Quedó resuelto?', 'Gracias'],
            'negación' => ['solicitar_confirmacion', '¿Quedó resuelto?', 'No, todavía no funciona'],
            'problema pendiente' => ['solicitar_confirmacion', '¿Quedó resuelto?', 'Todo normal, pero no puedo vender'],
            'condicional' => ['solicitar_confirmacion', '¿Quedó resuelto?', 'Si funciona después te aviso'],
            'arreglo condicional' => ['solicitar_confirmacion', '¿Quedó resuelto?', 'Si lo arreglamos te aviso'],
            'arreglo condicionado a poder' => ['solicitar_confirmacion', '¿Quedó resuelto?', 'Si podemos lo arreglamos'],
            'arreglo negado' => ['solicitar_confirmacion', '¿Quedó resuelto?', 'Nunca lo arreglamos'],
            'resultado dudoso' => ['solicitar_confirmacion', '¿Quedó resuelto?', 'Creo que ya quedó resuelto'],
            'prueba futura' => ['solicitar_confirmacion', '¿Quedó resuelto?', 'Lo pruebo mañana'],
            'sí a disponibilidad futura' => ['solicitar_confirmacion', '¿Me confirmás si podés probar mañana?', 'Sí'],
            'sí a oferta de prueba sin resultado' => ['solicitar_confirmacion', '¿Me confirmás si podés probar?', 'Sí'],
            'sí a disponibilidad de prueba de venta' => ['solicitar_confirmacion', '¿Me confirmás si podés probar vender?', 'Sí'],
            'sí a resultado futuro' => ['solicitar_confirmacion', '¿Me confirmás si mañana podés vender?', 'Sí'],
            'afirmación con futuro' => ['solicitar_confirmacion', '¿Quedó resuelto?', 'Todo normal, lo voy a probar mañana'],
            'pregunta del cliente' => ['solicitar_confirmacion', '¿Quedó resuelto?', '¿Todo normal?'],
            'pregunta sobre arreglo' => ['solicitar_confirmacion', '¿Quedó resuelto?', '¿Ya lo arreglamos?'],
        ];
    }

    public function test_pregunta_de_otro_ticket_no_habilita_confirmacion_breve(): void
    {
        $other = $this->ticket();
        $this->reply($other, $this->claim($other), ['action' => 'solicitar_confirmacion', 'body' => '¿Quedó resuelto?'])->assertOk();
        $other_case = SupportCodexCase::where('support_ticket_id', $other->id)->first();
        $ticket = $this->ticket();
        $this->reply($ticket, $this->claim($ticket), ['action' => 'solicitar_confirmacion', 'body' => '¿Cómo estás?'])->assertOk();
        SupportCodexCase::where('support_ticket_id', $ticket->id)->update(['last_reply_id' => $other_case->last_reply_id]);
        $message = $this->inbound($ticket, 'Todo normal');
        $claim = $this->claim($ticket);
        $this->reply($ticket, $claim, ['action' => 'cerrar', 'confirmation_message_id' => $message->id])->assertStatus(422);
        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertCount(2, $this->deliveries->bodies);
    }

    public function test_resultado_explicito_actual_o_pasado_no_depende_de_formula_masculina(): void
    {
        foreach (['Ya quedó resuelta, gracias.', 'Sí, lo arreglamos.', 'Ya lo solucioné.'] as $answer) {
            $ticket = $this->ticket();
            $message = $this->inbound($ticket, $answer);
            $claim = $this->claim($ticket);
            $this->reply($ticket, $claim, ['action' => 'cerrar', 'confirmation_message_id' => $message->id])
                ->assertOk()->assertJsonPath('ticket.status', 'closed');
        }
    }

    public function test_operacion_interrumpida_antes_del_commit_no_reenvia(): void
    {
        $ticket = $this->ticket();
        $claim = $this->claim($ticket);
        $this->reply($ticket, $claim)->assertOk();
        $operation = SupportCodexOperation::where('support_ticket_id', $ticket->id)->first();
        $operation->status = 'sending';
        $operation->save();
        $this->reply($ticket, $claim)->assertOk()->assertJsonPath('operation.status', 'unknown');
        $this->assertCount(1, $this->deliveries->bodies);
    }

    public function test_seguimiento_unico_pide_confirmacion_y_silencio_no_cierra(): void
    {
        $ticket = $this->ticket();
        $claim = $this->claim($ticket);
        $this->reply($ticket, $claim, ['action' => 'solicitar_confirmacion', 'body' => '¿Quedó resuelto?'])->assertOk();
        Carbon::setTestNow(now()->addHours(4)->addMinute());
        $this->getJson($this->base . '/pending', $this->headers)->assertOk()->assertJsonPath('tickets.0.reason', 'follow_up');
        $claim = $this->claim($ticket);
        $this->reply($ticket, $claim, ['action' => 'solicitar_confirmacion', 'body' => '¿Pudiste comprobar si quedó resuelto?'])->assertOk();
        Carbon::setTestNow(now()->addHours(5));
        $this->getJson($this->base . '/pending', $this->headers)->assertJsonCount(0, 'tickets');
        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertCount(2, $this->deliveries->bodies);
    }

    public function test_mision_se_guarda_y_no_anuncia_una_correccion_sin_ejecutar(): void
    {
        $ticket = $this->ticket();
        $claim = $this->claim($ticket);
        $this->reply($ticket, $claim, [
            'action' => 'escalar', 'body' => 'Dejé el caso preparado para Lucas.',
            'mission' => '/cruzada Corregir la venta que falla con comprobante X.',
        ])->assertOk()->assertJsonPath('ticket.state', 'mission_pending');
        $this->inbound($ticket, '¿Ya está?');
        $claim = $this->claim($ticket);
        $this->reply($ticket, $claim, ['action' => 'solicitar_confirmacion'])->assertStatus(409);
        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertCount(1, $this->deliveries->bodies);
    }

    public function test_no_envia_texto_libre_al_vencer_ventana(): void
    {
        $ticket = $this->ticket();
        $claim = $this->claim($ticket);
        Carbon::setTestNow(now()->addHours(25));
        // Renueva una reserva que venció mientras estaba apagada la PC.
        $claim = $this->claim($ticket);
        $this->reply($ticket, $claim)->assertStatus(422);
        $this->assertCount(0, $this->deliveries->bodies);
    }

    public function test_diagnostico_se_ata_al_cliente_del_ticket_y_no_llama_ia(): void
    {
        $ticket = $this->ticket();
        $claim = $this->claim($ticket);
        $this->postJson($this->base . '/tickets/' . $ticket->id . '/diagnostic', [
            'lease_token' => $claim['lease_token'], 'query' => 'stock Coca',
            'client_id' => 999, 'url' => 'https://otro.example',
        ], $this->headers)->assertOk()->assertJsonPath('data.data.0.stock', 3);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api-cliente.test/api/admin-sync/sistema-query'
                && $request->hasHeader('X-Admin-Api-Key', 'secreto-que-no-sale');
        });
        Http::assertSentCount(1);
    }

    public function test_adjunto_no_se_puede_leer_desde_otro_ticket(): void
    {
        Storage::fake('public');
        $ticket = $this->ticket();
        $other = $this->ticket();
        $message = SupportMessage::where('support_ticket_id', $ticket->id)->first();
        Storage::disk('public')->put('soporte/prueba.ogg', 'audio-de-prueba');
        $attachment = SupportMessageAttachment::create([
            'support_message_id' => $message->id, 'disk' => 'public',
            'path' => 'soporte/prueba.ogg', 'mime' => 'audio/ogg', 'size' => 15,
        ]);
        $this->get($this->base . '/tickets/' . $other->id . '/attachments/' . $attachment->id, $this->headers)->assertStatus(404);
        $this->get($this->base . '/tickets/' . $ticket->id . '/attachments/' . $attachment->id, $this->headers)->assertOk();
    }

    public function test_agradecer_o_prometer_probar_no_confirma_resolucion(): void
    {
        foreach (['Gracias', 'Perfecto, lo pruebo mañana, gracias', 'Si funciona después te aviso'] as $body) {
            $ticket = $this->ticket();
            $claim = $this->claim($ticket);
            $this->reply($ticket, $claim, ['action' => 'solicitar_confirmacion', 'body' => '¿Quedó resuelto?'])->assertOk();
            $message = $this->inbound($ticket, $body);
            $claim = $this->claim($ticket);
            $this->reply($ticket, $claim, ['action' => 'cerrar', 'confirmation_message_id' => $message->id])->assertStatus(422);
            $this->assertSame('open', $ticket->fresh()->status);
        }
    }

    public function test_resume_no_procesa_backlog_y_mision_verificada_pide_prueba(): void
    {
        $ticket = $this->ticket();
        app(SupportCodexService::class)->pause_for_human($ticket);
        $last = SupportMessage::where('support_ticket_id', $ticket->id)->latest('id')->first();
        $path = $this->base . '/tickets/' . $ticket->id . '/resume';
        $this->postJson($path, ['expected_message_id' => $last->id, 'reason' => 'Lucas retoma la atención automática.'], $this->headers)->assertOk();
        $this->getJson($this->base . '/pending', $this->headers)->assertJsonCount(0, 'tickets');
        $this->postJson($path, [
            'expected_message_id' => $last->id,
            'reason' => 'Misión implementada y verificada en producción.', 'request_confirmation' => true,
        ], $this->headers)->assertOk()->assertJsonPath('ticket.reason', 'mission_completed');
        $claim = $this->claim($ticket);
        $this->reply($ticket, $claim, ['action' => 'solicitar_confirmacion', 'body' => '¿Podés probar ahora y confirmar si quedó resuelto?'])->assertOk();
        $this->assertSame('open', $ticket->fresh()->status);
        $this->getJson($this->base . '/pending', $this->headers)->assertJsonCount(0, 'tickets');
    }

    public function test_continuar_consulta_pendiente_conserva_mision_y_no_consume_el_entrante(): void
    {
        $ticket = $this->ticket();
        $claim = $this->claim($ticket);
        $mission = '/cruzada Corregir el visor de imágenes.';
        $this->reply($ticket, $claim, ['action' => 'escalar', 'mission' => $mission])->assertOk();
        $message = $this->inbound($ticket, 'También necesito ver el nombre completo.');
        app(SupportCodexService::class)->pause_for_human($ticket);
        $before = SupportCodexCase::where('support_ticket_id', $ticket->id)->first();
        $escalation = $ticket->fresh()->escalation_reason;
        $messages = SupportMessage::where('support_ticket_id', $ticket->id)->count();

        $this->getJson($this->base . '/tickets/' . $ticket->id, $this->headers)
            ->assertJsonPath('ticket.capabilities.continue_pending', true);
        $this->postJson($this->base . '/tickets/' . $ticket->id . '/resume', [
            'expected_message_id' => $message->id, 'reason' => 'Continuar consulta de columnas sin resolver el visor.',
            'continue_pending' => true,
        ], $this->headers)->assertOk()->assertJsonPath('ticket.state', 'mission_pending')
            ->assertJsonPath('ticket.mission', $mission)->assertJsonPath('ticket.reason', 'inbound');

        $after = $before->fresh();
        $this->assertSame($before->handled_message_id, $after->handled_message_id);
        $this->assertSame($before->last_reply_id, $after->last_reply_id);
        $this->assertSame($before->thread_id, $after->thread_id);
        $this->assertSame($escalation, $ticket->fresh()->escalation_reason);
        $this->assertNotNull($ticket->fresh()->escalated_at);
        $this->assertSame($messages, SupportMessage::where('support_ticket_id', $ticket->id)->count());
        $this->assertCount(1, $this->deliveries->bodies, 'Retomar no envía una respuesta por sí mismo.');
        $this->getJson($this->base . '/pending', $this->headers)->assertJsonPath('tickets.0.expected_message_id', $message->id);
        $claim = $this->claim($ticket);
        $this->reply($ticket, $claim, ['body' => 'Activá Salto de línea en Nombre. El visor sigue pendiente.'])
            ->assertOk()->assertJsonPath('ticket.state', 'mission_pending')->assertJsonPath('ticket.mission', $mission);
        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertCount(2, $this->deliveries->bodies);
    }

    public function test_continuar_consulta_sin_mision_la_deja_disponible_sin_consumirla(): void
    {
        $ticket = $this->ticket();
        $last = SupportMessage::where('support_ticket_id', $ticket->id)->latest('id')->first();
        app(SupportCodexService::class)->pause_for_human($ticket);
        $this->postJson($this->base . '/tickets/' . $ticket->id . '/resume', [
            'expected_message_id' => $last->id, 'reason' => 'Continuidad autorizada por Lucas.', 'continue_pending' => true,
        ], $this->headers)->assertOk()->assertJsonPath('ticket.state', 'ready')->assertJsonPath('ticket.reason', 'inbound');
        $this->assertEmpty($this->deliveries->bodies);
        $this->getJson($this->base . '/pending', $this->headers)->assertJsonPath('tickets.0.expected_message_id', $last->id);
    }

    public function test_continuar_consulta_no_admite_confirmar_mision_ni_reprocesar_un_mensaje_atendido(): void
    {
        $ticket = $this->ticket();
        $last = SupportMessage::where('support_ticket_id', $ticket->id)->latest('id')->first();
        app(SupportCodexService::class)->pause_for_human($ticket);
        $path = $this->base . '/tickets/' . $ticket->id . '/resume';
        $payload = ['expected_message_id' => $last->id, 'reason' => 'Continuidad autorizada.', 'continue_pending' => true];
        $this->postJson($path, array_merge($payload, ['request_confirmation' => true]), $this->headers)->assertStatus(422);
        $case = SupportCodexCase::where('support_ticket_id', $ticket->id)->first();
        $case->handled_message_id = $last->id;
        $case->save();
        $this->postJson($path, $payload, $this->headers)->assertStatus(409);
        $this->assertSame('manual_review', $case->fresh()->state);
        $this->assertEmpty($this->deliveries->bodies);
    }

    public function test_continuar_consulta_no_repite_la_ultima_respuesta_del_equipo(): void
    {
        $ticket = $this->ticket();
        $claim = $this->claim($ticket);
        $this->reply($ticket, $claim)->assertOk();
        app(SupportCodexService::class)->pause_for_human($ticket);
        $last = SupportMessage::where('support_ticket_id', $ticket->id)->latest('id')->first();
        $this->postJson($this->base . '/tickets/' . $ticket->id . '/resume', [
            'expected_message_id' => $last->id, 'reason' => 'Continuidad autorizada.', 'continue_pending' => true,
        ], $this->headers)->assertStatus(409);
        $this->assertCount(1, $this->deliveries->bodies);
    }

    public function test_continuar_consulta_conserva_frenos_de_reserva_y_envio_ambiguo(): void
    {
        $ticket = $this->ticket();
        $claim = $this->claim($ticket);
        $path = $this->base . '/tickets/' . $ticket->id . '/resume';
        $payload = ['expected_message_id' => $claim['ticket']['expected_message_id'], 'reason' => 'Continuidad autorizada.', 'continue_pending' => true];
        $this->postJson($path, $payload, $this->headers)->assertStatus(423);
        $this->deliveries->success = false;
        $this->reply($ticket, $claim)->assertOk()->assertJsonPath('operation.status', 'unknown');
        $last = $this->inbound($ticket, '¿Llegó mi mensaje?');
        $payload['expected_message_id'] = $last->id;
        $this->postJson($path, $payload, $this->headers)->assertStatus(409);
        $this->assertSame('manual_review', SupportCodexCase::where('support_ticket_id', $ticket->id)->first()->state);
        $this->assertCount(1, $this->deliveries->bodies);
    }

    public function test_continuar_consulta_no_elude_mensaje_nuevo_ventana_vencida_ni_agente_apagado(): void
    {
        $ticket = $this->ticket();
        $old = SupportMessage::where('support_ticket_id', $ticket->id)->latest('id')->first();
        app(SupportCodexService::class)->pause_for_human($ticket);
        $last = $this->inbound($ticket, 'Otra captura.');
        $path = $this->base . '/tickets/' . $ticket->id . '/resume';
        $payload = ['expected_message_id' => $old->id, 'reason' => 'Continuidad autorizada.', 'continue_pending' => true];
        $this->postJson($path, $payload, $this->headers)->assertStatus(409);
        $payload['expected_message_id'] = $last->id;
        Carbon::setTestNow(now()->addHours(25));
        $this->postJson($path, $payload, $this->headers)->assertStatus(422);
        $ticket->claude_auto_reply = false;
        $ticket->save();
        $this->postJson($path, $payload, $this->headers)->assertStatus(409);
        $this->assertSame('manual_review', SupportCodexCase::where('support_ticket_id', $ticket->id)->first()->state);
        $this->assertEmpty($this->deliveries->bodies);
    }

    public function test_un_empleado_sin_permiso_no_consulta_datos_del_negocio(): void
    {
        $ticket = $this->ticket();
        $employee = ClientEmployee::create([
            'client_id' => $ticket->client_id, 'name' => 'Empleado de prueba',
            'phone' => '+5493417770001', 'can_query_system' => false,
        ]);
        $ticket->client_employee_id = $employee->id;
        $ticket->save();
        $claim = $this->claim($ticket);
        $this->assertFalse($claim['ticket']['can_query_system']);
        $this->assertSame('employee', $claim['ticket']['contact_role']);
        $this->postJson($this->base . '/tickets/' . $ticket->id . '/diagnostic', [
            'lease_token' => $claim['lease_token'], 'query' => 'ventas y caja del negocio',
        ], $this->headers)->assertStatus(403);
        Http::assertNothingSent();
    }

    public function test_conciliar_exige_evidencia_no_reenvia_y_no_cierra(): void
    {
        foreach (['sent', 'not_sent'] as $outcome) {
            $ticket = $this->ticket();
            $this->deliveries->success = false;
            $claim = $this->claim($ticket);
            $result = $this->reply($ticket, $claim)->assertOk();
            $operation_id = $result->json('operation.id');
            $path = $this->base . '/operations/' . $operation_id . '/reconcile';
            $data = [
                'outcome' => $outcome, 'reason' => 'Lucas revisó manualmente el historial en Kapso.',
                'confirmed_by' => 'Lucas González',
            ];
            if ($outcome === 'sent') {
                $this->postJson($path, $data, $this->headers)->assertStatus(422);
                $data['whatsapp_message_id'] = 'wamid.confirmado-manualmente';
                $data['evidence'] = 'Historial de Kapso verificado por Lucas a las 13:00.';
            }
            $before = count($this->deliveries->bodies);
            $this->postJson($path, $data, $this->headers)->assertOk()
                ->assertJsonPath('operation.status', $outcome)
                ->assertJsonPath('operation.reconciliation.by', 'Lucas González')
                ->assertJsonPath('ticket.state', 'manual_review');
            $this->assertCount($before, $this->deliveries->bodies);
            $this->assertSame('open', $ticket->fresh()->status);
            $this->postJson($path, $data, $this->headers)->assertStatus(409);
        }
    }

    public function test_cambiar_a_codex_durante_la_api_impide_cierre_y_envio_viejo(): void
    {
        $ticket = $this->ticket();
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '0');
        $scheduler = app(\App\Services\SupportAiSuggestionScheduler::class);
        \Illuminate\Support\Facades\Cache::put('support_ai_suggestion_schedule_token:' . $ticket->id, 1, 7200);
        $mock = \Mockery::mock(SupportAiSuggestionService::class);
        $mock->shouldReceive('generate')->once()->andReturnUsing(function () use ($ticket) {
            AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
            $this->inbound($ticket, 'Sigo necesitando ayuda.');
            // Incluso sin incrementar el token: la guarda de proveedor después de la API manda.
            return ['should_close' => true, 'should_escalate' => true, 'suggested_message' => 'Ya está resuelto.'];
        });
        $job = new \App\Jobs\SendSupportAiSuggestion($ticket->id, 1);
        $job->handle(
            $mock, app(\App\Services\SupportAiSuggestionDeliveryService::class), $scheduler,
            app(\App\Services\SupportAiSuggestionDraftService::class)
        );
        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->escalated_at);
        $this->assertCount(0, $this->deliveries->bodies);
    }

    public function test_un_saludo_conciliado_como_enviado_no_se_repite(): void
    {
        $ticket = $this->ticket();
        $claim = $this->claim($ticket);
        $this->reply($ticket, $claim)->assertOk();
        SupportCodexOperation::where('support_ticket_id', $ticket->id)->update(['status' => 'reconciled_sent']);
        $this->inbound($ticket, '¿Y cómo sigo?');
        $claim = $this->claim($ticket);
        $this->reply($ticket, $claim, ['body' => 'Ahora abrí el comprobante.'])->assertOk();
        $this->assertSame('Ahora abrí el comprobante.', end($this->deliveries->bodies));
    }
}
