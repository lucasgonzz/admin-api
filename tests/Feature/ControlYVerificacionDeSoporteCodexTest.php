<?php

namespace Tests\Feature;

use App\Events\SupportMessageReceived;
use App\Events\SupportMessageRemoved;
use App\Events\SupportTicketUpdated;
use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\SupportAttentionNotification;
use App\Models\SupportCodexCase;
use App\Models\SupportCodexOperation;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\SupportAiSettings;
use App\Services\SupportAttentionNotificationService;
use App\Services\SupportCodexService;
use App\Services\SupportCodexSettings;
use App\Services\SupportWhatsappOpenerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Recorre endpoints reales y estado durable; ningún mensaje de prueba sale a WhatsApp. */
class ControlYVerificacionDeSoporteCodexTest extends TestCase
{
    use DatabaseTransactions;

    private $headers = ['X-Claude-Task-Key' => 'control-soporte-test', 'Accept' => 'application/json'];
    private $base = '/api/claude/support-codex';
    private $admin;
    private $transport;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-30 11:00:00'));
        config(['services.claude_task_ingest.key' => 'control-soporte-test']);
        Event::fake([SupportMessageReceived::class, SupportMessageRemoved::class, SupportTicketUpdated::class]);
        Queue::fake();
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, '2026-10-24 12:00:00');
        $this->admin = Admin::create(['name' => 'Lucas', 'email' => uniqid('control') . '@test.local', 'password' => bcrypt('prueba')]);
        $this->transport = new class extends SupportWhatsappOpenerService {
            public $sent = [];
            public function __construct() {}
            public function deliver_follow_up(SupportTicket $ticket, SupportMessage $message, string $name): array
            {
                $this->sent[] = $message->body;
                $message->whatsapp_message_id = 'wamid.control.' . count($this->sent);
                $message->save();
                return ['delivery' => 'sent'];
            }
        };
        $this->app->instance(SupportWhatsappOpenerService::class, $this->transport);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ticket(array $extra = []): SupportTicket
    {
        $client = Client::create(['name' => 'Ferretotal', 'phone' => '+5493410000098', 'is_active' => true]);
        $ticket = SupportTicket::create(array_merge([
            'client_id' => $client->id, 'client_user_id' => 0, 'source' => 'whatsapp', 'status' => 'open',
            'whatsapp_phone' => $client->phone, 'opened_at' => now(), 'last_client_message_at' => now(),
        ], $extra));
        $this->inbound($ticket);
        return $ticket;
    }

    private function inbound(SupportTicket $ticket): SupportMessage
    {
        $message = SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text', 'body' => 'No puedo abrir Ventas.', 'delivered_at' => now()]);
        $ticket->last_client_message_at = now();
        $ticket->save();
        return $message;
    }

    private function verification(SupportTicket $ticket): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/support-ticket/' . $ticket->id . '/toggle-requiere-verificacion')->assertOk();
    }

    private function claim(SupportTicket $ticket): array
    {
        $message = SupportMessage::where('support_ticket_id', $ticket->id)->where('sender_type', 'user')->orderByDesc('id')->first();
        return $this->postJson($this->base . '/tickets/' . $ticket->id . '/claim', [
            'worker_id' => 'pc-prueba', 'expected_message_id' => $message->id,
        ], $this->headers)->assertOk()->json();
    }

    private function reply(SupportTicket $ticket, array $claim, array $extra = [])
    {
        return $this->postJson($this->base . '/tickets/' . $ticket->id . '/reply', array_merge([
            'lease_token' => $claim['lease_token'], 'expected_message_id' => $claim['ticket']['expected_message_id'],
            'operation_id' => 'control-' . $ticket->id . '-' . $claim['ticket']['expected_message_id'],
            'thread_id' => 'soporte-' . $ticket->id, 'body' => 'Probá recargar Ventas.', 'action' => 'responder',
            'supports_human_approval' => true,
        ], $extra), $this->headers);
    }

    public function test_todos_nacen_autonomos_aun_con_config_global_o_payload_true(): void
    {
        AdminSetting::set(SupportAiSettings::KEY_REQUIRE_VERIFICATION, '1');
        $one = $this->ticket(['requiere_verificacion_mensajes' => true]);
        $this->assertFalse($one->requiere_verificacion_mensajes);
        $this->assertFalse($one->fresh()->requiere_verificacion_mensajes);
        $this->verification($one);
        $this->assertTrue($one->fresh()->requiere_verificacion_mensajes);
        $this->assertFalse($this->ticket()->fresh()->requiere_verificacion_mensajes);
        $this->assertFalse(SupportAiSettings::to_array()['require_verification']);
    }

    public function test_sin_verificacion_responde_de_inmediato(): void
    {
        $ticket = $this->ticket();
        $this->reply($ticket, $this->claim($ticket))->assertOk()->assertJsonPath('operation.status', 'sent');
        $this->assertCount(1, $this->transport->sent);
    }

    public function test_verificacion_guarda_borrador_avisa_y_aprueba_una_sola_vez(): void
    {
        $ticket = $this->ticket();
        $this->verification($ticket);
        $claim = $this->claim($ticket);
        $result = $this->reply($ticket, $claim)->assertOk()->assertJsonPath('operation.status', 'awaiting_approval')->json();
        $id = $result['operation']['message_id'];
        $this->assertTrue(SupportMessage::findOrFail($id)->is_ai_suggestion_draft);
        $this->assertCount(0, $this->transport->sent);
        $notification = SupportAttentionNotification::where('support_ticket_id', $ticket->id)->firstOrFail();
        $this->assertNotNull($notification->fingerprint);
        $this->assertNull($ticket->fresh()->ai_suggestion_send_at);
        $this->reply($ticket, $claim)->assertOk()->assertJsonPath('operation.message_id', $id);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/support-message/' . $id . '/approve-ai-draft', ['body' => 'Probá abrir Ventas de nuevo.'])
            ->assertOk()->assertJsonPath('delivered', true);
        $this->postJson('/api/admin/support-message/' . $id . '/approve-ai-draft')->assertOk();
        $this->assertSame(['Probá abrir Ventas de nuevo.'], $this->transport->sent);
        $this->assertSame('Probá recargar Ventas.', SupportMessage::findOrFail($id)->ai_original_body);
    }

    public function test_monitor_anterior_no_puede_saltar_la_verificacion(): void
    {
        $ticket = $this->ticket();
        $this->verification($ticket);
        $claim = $this->claim($ticket);
        $this->reply($ticket, $claim, ['supports_human_approval' => false])->assertStatus(422);
        $this->assertCount(0, $this->transport->sent);
        $this->assertSame(0, SupportCodexOperation::where('support_ticket_id', $ticket->id)->count());
    }

    public function test_entrante_nuevo_invalida_aprobacion_y_permite_otra_respuesta(): void
    {
        $ticket = $this->ticket();
        $this->verification($ticket);
        $result = $this->reply($ticket, $this->claim($ticket))->assertOk()->json();
        $this->inbound($ticket);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/support-message/' . $result['operation']['message_id'] . '/approve-ai-draft')->assertStatus(409);
        $this->getJson($this->base . '/pending', $this->headers)->assertOk()->assertJsonPath('tickets.0.id', $ticket->id);
        $this->reply($ticket, $this->claim($ticket))->assertOk()->assertJsonPath('operation.status', 'awaiting_approval');
        $this->assertCount(0, $this->transport->sent);
    }

    public function test_descartar_no_regenera_hasta_el_proximo_entrante(): void
    {
        $ticket = $this->ticket();
        $this->verification($ticket);
        $result = $this->reply($ticket, $this->claim($ticket))->assertOk()->json();
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/support-message/' . $result['operation']['message_id'] . '/discard-ai-draft')->assertOk();
        $this->getJson($this->base . '/pending', $this->headers)->assertOk()->assertJsonCount(0, 'tickets');
        $this->inbound($ticket);
        $this->reply($ticket, $this->claim($ticket))->assertOk()->assertJsonPath('operation.status', 'awaiting_approval');
        $this->assertCount(0, $this->transport->sent);
    }

    public function test_humano_cancela_borrador_y_el_siguiente_entrante_sigue_automatico(): void
    {
        $ticket = $this->ticket();
        $this->verification($ticket);
        $result = $this->reply($ticket, $this->claim($ticket))->assertOk()->json();
        app(SupportCodexService::class)->pause_for_human($ticket);
        $this->assertSame('cancelled', SupportCodexOperation::where('operation_id', $result['operation']['id'])->firstOrFail()->status);
        $this->inbound($ticket);
        $this->getJson($this->base . '/pending', $this->headers)->assertOk()->assertJsonPath('tickets.0.id', $ticket->id);
        $this->assertCount(0, $this->transport->sent);
    }

    public function test_aprobacion_no_envia_fuera_de_la_ventana(): void
    {
        $ticket = $this->ticket();
        $this->verification($ticket);
        $result = $this->reply($ticket, $this->claim($ticket))->assertOk()->json();
        Carbon::setTestNow(now()->addHours(25));
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/support-message/' . $result['operation']['message_id'] . '/approve-ai-draft')->assertStatus(422);
        $this->assertCount(0, $this->transport->sent);
        $this->assertSame('awaiting_approval', SupportCodexOperation::where('operation_id', $result['operation']['id'])->firstOrFail()->status);
    }

    public function test_apagar_alerta_conserva_mision_y_nueva_necesidad_vuelve_a_avisar(): void
    {
        $ticket = $this->ticket();
        $case = SupportCodexCase::create(['support_ticket_id' => $ticket->id, 'state' => 'manual_review',
            'review_reason' => 'Pidió hablar con Lucas.', 'review_requested_message_id' => 1,
            'mission' => 'Diagnóstico vigente', 'mission_thread_id' => 'mision-27', 'mission_started_at' => now()]);
        $this->actingAs($this->admin, 'sanctum')->patchJson('/api/admin/support-ticket/' . $ticket->id . '/attention', ['needs_human' => false])
            ->assertOk()->assertJsonPath('model.support_attention.needs_human', false)->assertJsonPath('model.support_attention.mission.status', 'started');
        $this->assertSame('Diagnóstico vigente', $case->fresh()->mission);
        $this->assertSame('manual_review', $case->fresh()->state);
        $this->assertSame('open', $ticket->fresh()->status);
        $generation = SupportAttentionNotification::where('support_ticket_id', $ticket->id)->firstOrFail()->generation;
        $case->update(['review_reason' => 'Nuevo problema que necesita a Lucas.']);
        $this->assertTrue($ticket->fresh()->load('support_codex_case')->support_attention['needs_human']);
        $this->assertGreaterThan($generation, SupportAttentionNotification::where('support_ticket_id', $ticket->id)->firstOrFail()->generation);
    }
    public function test_push_de_aprobacion_tiene_link_y_no_se_entrega_si_se_descarta(): void
    {
        $this->admin->update(['is_default_support_owner' => true]);
        $endpoint = 'https://web.push.apple.test/control-' . $this->admin->id;
        \App\Models\AdminPushSubscription::create(['admin_id' => $this->admin->id, 'endpoint' => $endpoint,
            'endpoint_hash' => \App\Models\AdminPushSubscription::hash_endpoint($endpoint), 'p256dh' => 'falsa', 'auth' => 'falsa']);
        $push = new class extends SupportAttentionNotificationService {
            public $sent = [];
            protected function send_push(int $admin_id, string $title, string $body, array $data, array $accepted_hashes = []): array
            {
                $this->sent[] = compact('admin_id', 'title', 'body', 'data');
                return ['accepted_endpoint_hashes' => ['falso'], 'complete' => true];
            }
        };
        $this->app->instance(SupportAttentionNotificationService::class, $push);
        $ticket = $this->ticket();
        $this->verification($ticket);
        $result = $this->reply($ticket, $this->claim($ticket))->assertOk()->json();
        $notice = SupportAttentionNotification::where('support_ticket_id', $ticket->id)->firstOrFail();
        $push->enviar($ticket->id, $notice->generation);
        $this->assertCount(1, $push->sent);
        $this->assertTrue($push->sent[0]['data']['approval_pending']);
        $this->assertSame('/soporte/ticket/' . $ticket->id, $push->sent[0]['data']['url']);
        $this->assertSame('Hay una respuesta lista que espera tu aprobación.', $push->sent[0]['body']);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/support-message/' . $result['operation']['message_id'] . '/discard-ai-draft')->assertOk();
        $push->enviar($ticket->id, $notice->generation);
        $this->assertCount(1, $push->sent);
    }

    public function test_apagar_verificacion_conserva_borrador_y_entrante_nuevo_sale_solo(): void
    {
        $ticket = $this->ticket();
        $this->verification($ticket);
        $result = $this->reply($ticket, $this->claim($ticket))->assertOk()->json();
        $this->verification($ticket);
        $this->assertTrue(SupportMessage::findOrFail($result['operation']['message_id'])->is_ai_suggestion_draft);
        $this->assertCount(0, $this->transport->sent);
        $this->inbound($ticket);
        $this->getJson($this->base . '/pending', $this->headers)->assertOk();
        $this->reply($ticket, $this->claim($ticket))->assertOk()->assertJsonPath('operation.status', 'sent');
        $this->assertCount(1, $this->transport->sent);
    }

    public function test_apagar_agente_cancela_aprobacion_sin_enviar(): void
    {
        $ticket = $this->ticket();
        $this->verification($ticket);
        $result = $this->reply($ticket, $this->claim($ticket))->assertOk()->json();
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/support-ticket/' . $ticket->id . '/toggle-claude-auto-reply')->assertOk();
        $this->assertSame('cancelled', SupportCodexOperation::where('operation_id', $result['operation']['id'])->firstOrFail()->status);
        $this->assertNull($ticket->fresh()->ai_pending_suggestion);
        $this->assertCount(0, $this->transport->sent);
    }

    public function test_mision_nueva_no_deja_sending_sin_intento_al_aprobar(): void
    {
        $ticket = $this->ticket();
        $this->verification($ticket);
        $result = $this->reply($ticket, $this->claim($ticket), ['action' => 'solicitar_confirmacion'])->assertOk()->json();
        SupportCodexCase::where('support_ticket_id', $ticket->id)->firstOrFail()->update(['mission' => 'Misión nueva', 'state' => 'mission_pending']);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/support-message/' . $result['operation']['message_id'] . '/approve-ai-draft')->assertStatus(409);
        $this->assertSame('awaiting_approval', SupportCodexOperation::where('operation_id', $result['operation']['id'])->firstOrFail()->status);
        $this->assertCount(0, $this->transport->sent);
    }

    public function test_recuperar_apertura_no_pisa_humano_ni_acepta_otros_motivos(): void
    {
        $ticket = $this->ticket();
        $claim = $this->claim($ticket);
        $this->postJson($this->base . '/tickets/' . $ticket->id . '/release', ['lease_token' => $claim['lease_token'],
            'manual_review' => true, 'reason' => 'Disparo de conversación sin confirmar; conciliar en Codex'], $this->headers)->assertOk();
        $payload = ['worker_id' => 'pc-prueba', 'expected_message_id' => $claim['ticket']['expected_message_id'],
            'thread_id' => 'hilo-recuperado', 'dispatch_recovery' => true, 'execution_id' => '3954f0b1-0a69-4201-b842-de310c6568bd'];
        $recovered = $this->postJson($this->base . '/tickets/' . $ticket->id . '/claim', $payload, $this->headers)->assertOk()->json();
        $this->postJson($this->base . '/tickets/' . $ticket->id . '/release', ['lease_token' => $recovered['lease_token'],
            'manual_review' => true, 'reason' => 'Disparo de conversación sin confirmar; conciliar en Codex'], $this->headers)->assertOk();
        SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'admin', 'kind' => 'text', 'body' => 'Ya lo estoy viendo yo.']);
        $this->postJson($this->base . '/tickets/' . $ticket->id . '/claim', $payload, $this->headers)->assertStatus(409);
        $this->assertCount(0, $this->transport->sent);
    }

    public function test_claim_guarda_ejecucion_y_rechaza_recuperacion_de_otro_turno(): void
    {
        $ticket = $this->ticket();
        $message = SupportMessage::where('support_ticket_id', $ticket->id)->firstOrFail();
        $execution = '3954f0b1-0a69-4201-b842-de310c6568bd';
        $claim = $this->postJson($this->base . '/tickets/' . $ticket->id . '/claim', [
            'worker_id' => 'pc-prueba', 'expected_message_id' => $message->id, 'execution_id' => $execution,
        ], $this->headers)->assertOk()->json();
        $this->assertSame($execution, SupportCodexCase::where('support_ticket_id', $ticket->id)->firstOrFail()->dispatch_execution_id);
        $this->postJson($this->base . '/tickets/' . $ticket->id . '/release', ['lease_token' => $claim['lease_token'],
            'manual_review' => true, 'reason' => 'Disparo de conversación sin confirmar; conciliar en Codex'], $this->headers)->assertOk();
        $this->postJson($this->base . '/tickets/' . $ticket->id . '/claim', [
            'worker_id' => 'pc-prueba', 'expected_message_id' => $message->id, 'dispatch_recovery' => true,
            'execution_id' => '01234567-89ab-4def-8123-456789abcdef', 'thread_id' => 'hilo-ajeno',
        ], $this->headers)->assertStatus(409);
        $this->assertSame($execution, SupportCodexCase::where('support_ticket_id', $ticket->id)->firstOrFail()->dispatch_execution_id);
    }

    public function test_entrante_entre_persistencia_y_envio_no_crea_falso_incierto(): void
    {
        $ticket = $this->ticket();
        $claim = $this->claim($ticket);
        SupportCodexOperation::created(function (SupportCodexOperation $operation) use ($ticket) {
            if ((int) $operation->support_ticket_id === (int) $ticket->id) {
                SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text',
                    'body' => 'Agrego otro dato antes de la respuesta.', 'delivered_at' => now()]);
            }
        });
        $this->reply($ticket, $claim)->assertOk()->assertJsonPath('operation.status', 'not_sent')->assertJsonPath('operation.raw_status', 'cancelled');
        $this->assertCount(0, $this->transport->sent);
    }

    public function test_respuesta_humana_despues_de_apertura_fallida_no_bloquea_proximos_entrantes(): void
    {
        $ticket = $this->ticket();
        $claim = $this->claim($ticket);
        $this->postJson($this->base . '/tickets/' . $ticket->id . '/release', ['lease_token' => $claim['lease_token'],
            'manual_review' => true, 'reason' => 'Disparo de conversación sin confirmar; conciliar en Codex'], $this->headers)->assertOk();
        app(SupportCodexService::class)->pause_for_human($ticket);
        SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'admin', 'kind' => 'text', 'body' => 'Ya quedó restablecido.']);
        $this->inbound($ticket);
        $this->getJson($this->base . '/pending', $this->headers)->assertOk()->assertJsonPath('tickets.0.id', $ticket->id);
        $this->reply($ticket, $this->claim($ticket))->assertOk()->assertJsonPath('operation.status', 'sent');
        $this->assertCount(1, $this->transport->sent);
    }

    public function test_realtime_caido_no_revierte_aprobacion_ni_duplica_entrega(): void
    {
        $ticket = $this->ticket();
        $this->verification($ticket);
        $result = $this->reply($ticket, $this->claim($ticket))->assertOk()->json();
        Event::swap(new \Illuminate\Events\Dispatcher($this->app));
        Event::listen(SupportMessageReceived::class, function () { throw new \RuntimeException('Realtime no disponible.'); });
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/support-message/' . $result['operation']['message_id'] . '/approve-ai-draft')->assertOk()->assertJsonPath('delivered', true);
        $this->assertSame('sent', SupportCodexOperation::where('operation_id', $result['operation']['id'])->firstOrFail()->status);
        $this->assertCount(1, $this->transport->sent);
    }

}
