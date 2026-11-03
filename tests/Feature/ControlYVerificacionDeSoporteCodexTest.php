<?php

namespace Tests\Feature;

use App\Events\SupportMessageReceived;
use App\Events\SupportMessageRead;
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
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
            public $sent_attachments = [];
            public function __construct() {}
            public function deliver_follow_up(SupportTicket $ticket, SupportMessage $message, string $name): array
            {
                $this->sent[] = $message->body;
                $this->sent_attachments[] = $message->attachments()->first();
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

    public function test_adjunta_documento_privado_y_el_replay_no_reenvia(): void
    {
        Storage::fake('local');
        $ticket = $this->ticket();
        $claim = $this->claim($ticket);
        $document = "%PDF-1.4\nDocumento de prueba\n%%EOF\n";
        $extra = ['attachment' => ['filename' => 'Factura.pdf', 'content_base64' => base64_encode($document)]];

        $this->reply($ticket, $claim, $extra)->assertOk()
            ->assertJsonPath('operation.status', 'sent')
            ->assertJsonPath('ticket.capabilities.outbound_attachments', true);
        $this->reply($ticket, $claim, $extra)->assertOk()->assertJsonPath('operation.status', 'sent');
        $extra['attachment']['filename'] = 'Otra.pdf';
        $conflict = $this->reply($ticket, $claim, $extra)->assertStatus(409);
        $this->assertStringStartsWith('operation_conflict:', $conflict->json('message'));

        $this->assertCount(1, $this->transport->sent_attachments);
        $attachment = $this->transport->sent_attachments[0];
        $this->assertSame('Factura.pdf', $attachment->display_filename);
        $this->assertSame('document', $attachment->message->kind);
        $this->assertSame('local', $attachment->disk);
        $this->assertSame($document, Storage::disk('local')->get($attachment->path));
        $this->get($attachment->download_url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get($attachment->download_url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        foreach ([SupportMessageReceived::class, SupportMessageRead::class] as $event_class) {
            $event = new $event_class($attachment->support_message_id);
            $public_message = $event->broadcastWith()['message']->toArray();
            $this->assertTrue($public_message['private_attachments_pending']);
            $this->assertArrayNotHasKey('attachments', $public_message);
            $this->assertArrayNotHasKey('body', $public_message);
        }
        $reply = SupportMessage::create([
            'support_ticket_id' => $ticket->id, 'sender_type' => 'admin', 'kind' => 'text',
            'body' => 'Respuesta al comprobante.', 'reply_to_message_id' => $attachment->support_message_id,
        ]);
        $public_reply = (new SupportMessageReceived($reply->id))->broadcastWith()['message']->toArray();
        $this->assertSame($attachment->support_message_id, $public_reply['reply_to_message']['id']);
        $this->assertArrayNotHasKey('body', $public_reply['reply_to_message']);

        $sender = \Mockery::mock(WhatsappSendService::class)->makePartial();
        $sender->shouldReceive('send_text')->never();
        $sender->shouldReceive('send_document_attachment')->once()->with(
            $ticket->whatsapp_phone,
            \Mockery::on(function ($sent_attachment) use ($attachment) { return $sent_attachment->is($attachment); }),
            'Factura.pdf', null, 'Probá recargar Ventas.'
        )->andReturn('wamid.document');
        $this->assertSame('wamid.document', $sender->send_support_message($ticket->whatsapp_phone, $attachment->message));
    }

    public function test_rechaza_un_adjunto_con_mime_distinto_del_nombre(): void
    {
        $ticket = $this->ticket();
        $claim = $this->claim($ticket);
        $this->reply($ticket, $claim, [
            'attachment' => ['filename' => 'Factura.pdf', 'content_base64' => base64_encode('Esto no es un PDF.')],
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_attachment');
        $this->assertCount(0, $this->transport->sent);
        $this->assertFalse(SupportCodexOperation::where('support_ticket_id', $ticket->id)->exists());
    }

    public function test_borrador_con_adjunto_muestra_archivo_y_frena_si_falta_al_aprobar(): void
    {
        Storage::fake('local');
        $ticket = $this->ticket();
        $this->verification($ticket);
        $reply = $this->reply($ticket, $this->claim($ticket), [
            'attachment' => ['filename' => 'Factura.pdf', 'content_base64' => base64_encode("%PDF-1.4\nDocumento\n%%EOF\n")],
        ])->assertOk()->assertJsonPath('operation.status', 'awaiting_approval')->json();

        $draft = SupportMessage::findOrFail($reply['operation']['message_id']);
        $attachment = $draft->attachments()->firstOrFail();
        $operation = SupportCodexOperation::where('message_id', $draft->id)->firstOrFail();
        $this->assertTrue($operation->approval_payload['requires_attachment']);
        $this->assertArrayNotHasKey('attachment', $operation->approval_payload);
        $this->assertSame('Factura.pdf', $attachment->display_filename);
        $bytes = Storage::disk('local')->get($attachment->path);
        Storage::disk('local')->delete($attachment->path);
        $approve_path = '/api/admin/support-message/' . $draft->id . '/approve-ai-draft';
        $this->actingAs($this->admin, 'sanctum')->postJson($approve_path)->assertStatus(422);
        $this->assertCount(0, $this->transport->sent);
        $this->assertSame('awaiting_approval', $operation->fresh()->status);

        Storage::disk('local')->put($attachment->path, $bytes);
        $this->postJson($approve_path, ['body' => 'Adjunto el comprobante.'])->assertOk()
            ->assertJsonPath('delivered', true);
        $this->assertSame(['Adjunto el comprobante.'], $this->transport->sent);
        $this->assertSame($attachment->id, $this->transport->sent_attachments[0]->id);
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


    private function request_manual(SupportTicket $ticket)
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/support-ticket/' . $ticket->id . '/suggest');
    }

    public function test_boton_manual_encola_pausa_humana_y_monitor_toma_una_sola_reserva(): void
    {
        $ticket = $this->ticket();
        $last = SupportMessage::where('support_ticket_id', $ticket->id)->max('id');
        SupportCodexCase::create(['support_ticket_id' => $ticket->id, 'state' => 'manual_review',
            'review_reason' => SupportCodexCase::HUMAN_REPLY_REVIEW_REASON, 'expected_message_id' => $last,
            'thread_id' => 'hilo-conservado', 'handled_message_id' => 0]);
        $this->request_manual($ticket)->assertOk()->assertJsonPath('codex_attention.status', 'queued');
        $this->request_manual($ticket)->assertOk()->assertJsonPath('codex_attention.status', 'queued');
        $this->getJson($this->base . '/pending', $this->headers)->assertOk()
            ->assertJsonPath('tickets.0.expected_message_id', $last);
        $claim = $this->claim($ticket);
        $this->assertSame('hilo-conservado', $claim['ticket']['thread_id']);
        $this->request_manual($ticket)->assertOk()->assertJsonPath('codex_attention.status', 'in_progress');
        $this->postJson($this->base . '/tickets/' . $ticket->id . '/claim', ['worker_id' => 'otra-pc', 'expected_message_id' => $last], $this->headers)->assertStatus(423);
        $this->assertSame(0, (int) SupportCodexCase::where('support_ticket_id', $ticket->id)->value('handled_message_id'));
        $this->assertCount(0, $this->transport->sent);
    }

    public function test_boton_manual_respeta_agente_apagado_y_encender_retoma_el_entrante(): void
    {
        $ticket = $this->ticket();
        $ticket->claude_auto_reply = false;
        $ticket->save();
        $this->request_manual($ticket)->assertOk()->assertJsonPath('codex_attention.code', 'agent_disabled');
        $this->assertFalse($ticket->fresh()->claude_auto_reply);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/support-ticket/' . $ticket->id . '/toggle-claude-auto-reply')->assertOk();
        $this->getJson($this->base . '/pending', $this->headers)->assertOk()->assertJsonPath('tickets.0.id', $ticket->id);
        $this->claim($ticket);
        $this->assertCount(0, $this->transport->sent);
    }

    public function test_boton_manual_muestra_aprobacion_sin_duplicar_borrador_o_envio(): void
    {
        $ticket = $this->ticket();
        $this->verification($ticket);
        $reply = $this->reply($ticket, $this->claim($ticket))->assertOk()->json();
        $this->request_manual($ticket)->assertOk()->assertJsonPath('codex_attention.status', 'awaiting_approval');
        $this->request_manual($ticket)->assertOk()->assertJsonPath('codex_attention.status', 'awaiting_approval');
        $this->assertSame(1, SupportCodexOperation::where('support_ticket_id', $ticket->id)->count());
        $this->assertSame('awaiting_approval', SupportCodexOperation::where('operation_id', $reply['operation']['id'])->firstOrFail()->status);
        $this->assertCount(0, $this->transport->sent);
    }

    public function test_boton_manual_no_revive_un_mensaje_atendido(): void
    {
        $ticket = $this->ticket();
        $this->reply($ticket, $this->claim($ticket))->assertOk();
        $this->request_manual($ticket)->assertOk()->assertJsonPath('codex_attention.code', 'no_pending_inbound');
        $this->assertCount(1, $this->transport->sent);
    }

    public function test_boton_manual_conserva_revision_tecnica_y_reserva_vencida(): void
    {
        $ticket = $this->ticket();
        $case = SupportCodexCase::create(['support_ticket_id' => $ticket->id, 'state' => 'manual_review',
            'review_reason' => 'Error técnico; revisión requerida',
            'lease_hash' => 'conservar', 'lease_expires_at' => now()->subMinute()]);
        $this->request_manual($ticket)->assertOk()->assertJsonPath('codex_attention.code', 'review_required');
        $this->assertSame('conservar', $case->fresh()->lease_hash);
        $this->assertSame('manual_review', $case->fresh()->state);
    }

    public function test_boton_manual_no_reintenta_envios_sending_o_unknown(): void
    {
        foreach (['sending', 'unknown'] as $status) {
            $ticket = $this->ticket();
            $reply = $this->reply($ticket, $this->claim($ticket))->assertOk()->json();
            $operation = SupportCodexOperation::where('operation_id', $reply['operation']['id'])->firstOrFail();
            $operation->status = $status;
            $operation->save();
            $this->request_manual($ticket)->assertOk()->assertJsonPath('codex_attention.code', 'unfinished_operation');
            $this->assertSame($status, $operation->fresh()->status);
        }
        $this->assertCount(2, $this->transport->sent);
    }

    public function test_boton_manual_bloquea_ventana_whatsapp_vencida(): void
    {
        $ticket = $this->ticket();
        Carbon::setTestNow(now()->addHours(25));
        $this->request_manual($ticket)->assertOk()->assertJsonPath('codex_attention.code', 'whatsapp_window_closed');
        $this->assertCount(0, $this->transport->sent);
        $this->assertSame(0, SupportCodexCase::where('support_ticket_id', $ticket->id)->count());
    }

    public function test_boton_manual_preserva_estados_ya_elegibles_y_mision(): void
    {
        foreach (['awaiting_confirmation', 'mission_pending', 'manual_review'] as $state) {
            $ticket = $this->ticket();
            $case = SupportCodexCase::create(['support_ticket_id' => $ticket->id, 'state' => $state,
                'mission' => $state === 'mission_pending' ? 'Revisar caso' : null,
                'review_reason' => $state === 'manual_review' ? 'Disparo de conversación sin confirmar; conciliar en Codex' : null,
                'expected_message_id' => 0, 'handled_message_id' => 0]);
            $this->request_manual($ticket)->assertOk()->assertJsonPath('codex_attention.status', 'queued');
            $this->assertSame($state, $case->fresh()->state);
            $this->assertSame($case->mission, $case->fresh()->mission);
            $this->claim($ticket);
        }
        $this->assertCount(0, $this->transport->sent);
    }

    public function test_boton_manual_invalida_solo_aprobacion_obsoleta_y_retoma_entrante_nuevo(): void
    {
        $ticket = $this->ticket();
        $this->verification($ticket);
        $reply = $this->reply($ticket, $this->claim($ticket))->assertOk()->json();
        $last = $this->inbound($ticket);
        $this->request_manual($ticket)->assertOk()->assertJsonPath('codex_attention.status', 'queued');
        $this->assertSame('cancelled', SupportCodexOperation::where('operation_id', $reply['operation']['id'])->firstOrFail()->status);
        $this->assertSame($last->id, $this->claim($ticket)['ticket']['expected_message_id']);
        $this->assertCount(0, $this->transport->sent);
    }

    public function test_solicitud_manual_no_elude_corte_global_de_codex(): void
    {
        $ticket = $this->ticket();
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AFTER_ID, (string) SupportMessage::max('id'));
        $result = app(SupportCodexService::class)->request_manual_attention($ticket->id);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame(0, SupportCodexCase::where('support_ticket_id', $ticket->id)->count());
        $this->assertCount(0, $this->transport->sent);
    }

    public function test_cierre_por_gracias_espera_aprobacion_y_cierra_solo_al_enviar(): void
    {
        $ticket = $this->ticket();
        $this->reply($ticket, $this->claim($ticket), ['body' => 'En Ventas abrí el detalle de la cuota.'])->assertOk();
        $message = $this->inbound($ticket);
        $message->update(['body' => 'Muchas gracias Martín']);
        $this->verification($ticket);
        $result = $this->reply($ticket, $this->claim($ticket), ['action' => 'cerrar', 'confirmation_message_id' => $message->id, 'body' => 'Un abrazo!'])
            ->assertOk()->assertJsonPath('operation.status', 'awaiting_approval')->json();
        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertCount(1, $this->transport->sent);
        $id = $result['operation']['message_id'];
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/support-message/' . $id . '/approve-ai-draft')->assertOk()->assertJsonPath('delivered', true);
        $this->assertSame('closed', $ticket->fresh()->status);
        $this->assertSame(['En Ventas abrí el detalle de la cuota.', 'De nada! Un abrazo!'], $this->transport->sent);
    }

    public function test_cierre_por_gracias_obsoleto_no_se_envia_al_llegar_nueva_consulta(): void
    {
        $ticket = $this->ticket();
        $this->reply($ticket, $this->claim($ticket), ['body' => 'En Ventas abrí el detalle de la cuota.'])->assertOk();
        $message = $this->inbound($ticket);
        $message->update(['body' => 'Gracias']);
        $this->verification($ticket);
        $result = $this->reply($ticket, $this->claim($ticket), ['action' => 'cerrar', 'confirmation_message_id' => $message->id, 'body' => 'De nada!'])->assertOk()->json();
        $this->inbound($ticket);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/support-message/' . $result['operation']['message_id'] . '/approve-ai-draft')->assertStatus(409);
        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertCount(1, $this->transport->sent);
    }
}
