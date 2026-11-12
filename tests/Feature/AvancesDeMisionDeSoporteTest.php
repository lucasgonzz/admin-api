<?php

namespace Tests\Feature;

use App\Events\SupportMessageReceived;
use App\Events\SupportTicketUpdated;
use App\Models\AdminSetting;
use App\Models\Admin;
use App\Models\Client;
use App\Models\SupportCodexCase;
use App\Models\SupportCodexMissionFixEvidence;
use App\Models\SupportCodexMissionProgress;
use App\Models\SupportCodexOperation;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\SupportCodexService;
use App\Services\SupportCodexSettings;
use App\Services\SupportWhatsappOpenerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/** API real y estado durable del slot; el transporte nunca llega a WhatsApp. */
class AvancesDeMisionDeSoporteTest extends TestCase
{
    use DatabaseTransactions;
    private $headers = ['X-Claude-Task-Key' => 'avance-test', 'Accept' => 'application/json'];
    private $base = '/api/claude/support-codex';
    private $transport;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-12 12:00:00'));
        config(['services.claude_task_ingest.key' => 'avance-test']);
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, '2026-10-24 12:00:00');
        Event::fake([SupportMessageReceived::class, SupportTicketUpdated::class]);
        $this->transport = new class extends SupportWhatsappOpenerService {
            public $sent = 0;
            public $success = true;
            public function __construct() {}
            public function deliver_follow_up(SupportTicket $ticket, SupportMessage $message, string $name): array
            {
                $this->sent++;
                if ($this->success) {
                    $message->whatsapp_message_id = 'wamid.avance.' . $this->sent;
                    $message->save();
                }
                return ['delivery' => $this->success ? 'sent' : 'unknown'];
            }
        };
        $this->app->instance(SupportWhatsappOpenerService::class, $this->transport);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function caso(): array
    {
        $client = Client::create(['name' => 'Comercio de prueba', 'phone' => '+5493410000098', 'is_active' => true]);
        $ticket = SupportTicket::create(['client_id' => $client->id, 'client_user_id' => 0, 'source' => 'whatsapp',
            'status' => 'open', 'whatsapp_phone' => $client->phone, 'opened_at' => now(), 'last_client_message_at' => now()]);
        $in = SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text',
            'body' => 'Falla al crear la cuenta.', 'delivered_at' => now()]);
        $out = SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'admin', 'kind' => 'text',
            'body' => 'Registramos el problema para revisarlo.', 'delivered_at' => now()]);
        $case = SupportCodexCase::create(['support_ticket_id' => $ticket->id, 'state' => 'mission_pending',
            'mission' => 'Corregir alta de cuenta.', 'mission_thread_id' => 'mision-prueba', 'mission_started_at' => now(),
            'thread_id' => 'soporte-prueba', 'handled_message_id' => $in->id, 'last_reply_id' => $out->id,
            'expected_message_id' => $in->id]);
        return [$ticket, $case, $out];
    }

    private function avance($ticket, $case, array $extra = [])
    {
        return $this->postJson($this->base . '/tickets/' . $ticket->id . '/mission-progress', array_merge([
            'mission_thread_id' => $case->mission_thread_id, 'mission_hash' => hash('sha256', $case->mission),
            'estado' => 'en_correccion', 'causa' => 'El teléfono ya estaba usado en otra cuenta.',
            'evidencia' => 'Se comprobó en el flujo instalado y en una prueba aislada.', 'alternativa' => null,
        ], $extra), $this->headers);
    }

    private function toma($ticket, $out): array
    {
        return $this->postJson($this->base . '/tickets/' . $ticket->id . '/claim', [
            'worker_id' => 'pc-prueba', 'expected_message_id' => $out->id, 'supports_mission_progress' => true,
        ], $this->headers)->assertOk()->json();
    }

    private function respuesta($ticket, $claim, $hash, array $extra = [])
    {
        return $this->postJson($this->base . '/tickets/' . $ticket->id . '/reply', array_merge([
            'lease_token' => $claim['lease_token'], 'expected_message_id' => $claim['ticket']['expected_message_id'],
            'operation_id' => (string) Str::uuid(), 'action' => 'responder', 'body' => 'Identificamos la causa y la estamos corrigiendo.',
            'mission_progress_hash' => $hash, 'supports_human_approval' => true,
            'mission_progress_state' => $claim['ticket']['mission_progress']['estado_actual'],
        ], $extra), $this->headers);
    }

    public function test_registra_deduplica_y_envia_sin_entrante_nuevo_conservando_mision(): void
    {
        [$ticket, $case, $out] = $this->caso();
        $before = $case->fresh()->getAttributes();
        $hash = $this->avance($ticket, $case)->assertOk()->assertJsonPath('registered', true)->json('progress_hash');
        $this->avance($ticket, $case)->assertOk();
        $this->assertSame(1, SupportCodexMissionProgress::where('support_ticket_id', $ticket->id)->count());
        $this->assertSame($before, $case->fresh()->getAttributes());
        $this->assertSame(0, $this->transport->sent);
        $this->assertCount(0, $this->getJson($this->base . '/pending', $this->headers)->json('tickets'));
        $this->getJson($this->base . '/pending?supports_mission_progress=1', $this->headers)->assertOk()
            ->assertJsonPath('tickets.0.reason', 'mission_progress');
        $claim = $this->toma($ticket, $out);
        $this->respuesta($ticket, $claim, $hash)->assertOk()->assertJsonPath('operation.status', 'sent')
            ->assertJsonPath('ticket.status', 'open')->assertJsonPath('ticket.mission_lifecycle.status', 'started');
        $this->assertNotNull(SupportCodexMissionProgress::where('progress_hash', $hash)->first()->announced_at);
        $this->assertSame($case->mission, $case->fresh()->mission);
        $this->assertSame('mision-prueba', $case->fresh()->mission_thread_id);
        $this->assertCount(0, $this->getJson($this->base . '/pending?supports_mission_progress=1', $this->headers)->json('tickets'));
    }

    public function test_respeta_revision_pausa_ventana_y_reserva_sin_borrarlas(): void
    {
        [$ticket, $case] = $this->caso();
        $case->update(['state' => 'manual_review', 'review_reason' => 'Lucas está revisando.', 'review_requested_message_id' => null]);
        $this->avance($ticket, $case)->assertOk();
        $this->assertSame('Lucas está revisando.', $case->fresh()->review_reason);
        $this->assertCount(0, app(SupportCodexService::class)->pending(false, true)['tickets']);
        $case->update(['state' => 'mission_pending']);
        $ticket->update(['claude_auto_reply' => false]);
        $this->assertCount(0, app(SupportCodexService::class)->pending(false, true)['tickets']);
        $ticket->update(['claude_auto_reply' => true]);
        $case->update(['lease_hash' => hash('sha256', 'reserva-ajena'), 'lease_expires_at' => now()->addMinutes(5)]);
        $this->assertCount(0, app(SupportCodexService::class)->pending(false, true)['tickets']);
        $case->update(['lease_hash' => null, 'lease_expires_at' => null]);
        Carbon::setTestNow(now()->addHours(25));
        $this->assertCount(0, app(SupportCodexService::class)->pending(false, true)['tickets']);
    }

    public function test_nuevo_entrante_tiene_prioridad_y_no_consume_el_avance(): void
    {
        [$ticket, $case] = $this->caso();
        $this->avance($ticket, $case)->assertOk();
        SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text', 'body' => 'Otra consulta.', 'delivered_at' => now()]);
        $this->assertSame('inbound', app(SupportCodexService::class)->pending(false, true)['tickets'][0]['reason']);
        $this->assertNull(SupportCodexMissionProgress::where('support_ticket_id', $ticket->id)->first()->announced_at);
    }

    public function test_rechaza_identidad_ajena_y_acciones_de_resolucion(): void
    {
        [$ticket, $case, $out] = $this->caso();
        $this->avance($ticket, $case, ['mission_thread_id' => 'otra-mision'])->assertStatus(409);
        $this->avance($ticket, $case, ['mission_hash' => str_repeat('a', 64)])->assertStatus(409);
        $this->avance($ticket, $case, ['estado' => 'resuelto'])->assertStatus(422);
        $hash = $this->avance($ticket, $case)->assertOk()->json('progress_hash');
        $claim = $this->toma($ticket, $out);
        $this->respuesta($ticket, $claim, $hash, ['action' => 'solicitar_confirmacion'])->assertStatus(422);
        $this->respuesta($ticket, $claim, $hash, ['action' => 'cerrar'])->assertStatus(422);
        $this->respuesta($ticket, $claim, $hash, ['mission_progress_hash' => str_repeat('a', 64)])->assertStatus(409);
        $this->assertSame(0, $this->transport->sent);
    }

    public function test_envio_incierto_no_consume_ni_reintenta_y_conciliacion_solo_ackea_su_avance(): void
    {
        [$ticket, $case, $out] = $this->caso();
        $hash = $this->avance($ticket, $case)->assertOk()->json('progress_hash');
        $claim = $this->toma($ticket, $out);
        $this->transport->success = false;
        $op = $this->respuesta($ticket, $claim, $hash)->assertOk()->assertJsonPath('operation.status', 'unknown')->json('operation.id');
        $hash_b = $this->avance($ticket, $case, ['causa' => 'La causa ahora incluye otra validación comprobada.'])->assertOk()->json('progress_hash');
        $this->assertNull(SupportCodexMissionProgress::where('progress_hash', $hash)->first()->announced_at);
        $this->assertCount(0, app(SupportCodexService::class)->pending(false, true)['tickets']);
        $this->postJson($this->base . '/operations/' . $op . '/reconcile', ['outcome' => 'sent', 'confirmed_by' => 'Lucas González',
            'reason' => 'Confirmado manualmente.', 'evidence' => 'Se comprobó la entrega real en el canal.', 'whatsapp_message_id' => 'wamid.real'], $this->headers)->assertOk();
        $this->assertNotNull(SupportCodexMissionProgress::where('progress_hash', $hash)->first()->announced_at);
        $this->assertNull(SupportCodexMissionProgress::where('progress_hash', $hash_b)->first()->announced_at);
        $this->assertSame(1, $this->transport->sent);
    }

    public function test_nuevo_avance_cancela_borrador_anterior_y_descarte_no_repite(): void
    {
        [$ticket, $case, $out] = $this->caso();
        $ticket->requiere_verificacion_mensajes = true;
        $ticket->save();
        $hash = $this->avance($ticket, $case)->assertOk()->json('progress_hash');
        $claim = $this->toma($ticket, $out);
        $op_a = $this->respuesta($ticket, $claim, $hash)->assertOk()->assertJsonPath('operation.status', 'awaiting_approval')->json('operation.id');
        $hash_b = $this->avance($ticket, $case, ['estado' => 'identificado'])->assertOk()->json('progress_hash');
        $this->assertSame('cancelled', SupportCodexOperation::where('operation_id', $op_a)->first()->status);
        $claim_b = $this->toma($ticket, $out);
        $op_b = $this->respuesta($ticket, $claim_b, $hash_b)->assertOk()->assertJsonPath('operation.status', 'awaiting_approval')->json('operation.id');
        $operation = SupportCodexOperation::where('operation_id', $op_b)->first();
        app(SupportCodexService::class)->discard_draft($operation->message_id);
        $progress = SupportCodexMissionProgress::where('progress_hash', $hash_b)->first();
        $this->assertNull($progress->announced_at);
        $this->assertNotNull($progress->dismissed_at);
        $this->assertCount(0, app(SupportCodexService::class)->pending(false, true)['tickets']);
        $this->assertSame(0, $this->transport->sent);
    }

    public function test_integracion_actualiza_contexto_y_avisa_antes_de_coordinar_sin_repetir(): void
    {
        [$ticket, $case, $out] = $this->caso();
        $hash = $this->avance($ticket, $case)->assertOk()->json('progress_hash');
        SupportCodexMissionFixEvidence::create(['support_ticket_id' => $ticket->id, 'mission_thread_id' => $case->mission_thread_id,
            'mission_hash' => hash('sha256', $case->mission), 'payload_hash' => str_repeat('b', 64),
            'payload' => ['estado' => 'verificada', 'commits' => ['empresa-api' => str_repeat('a', 40)], 'pruebas' => ['Prueba correcta.']]]);
        $this->getJson($this->base . '/tickets/' . $ticket->id, $this->headers)->assertOk()
            ->assertJsonPath('ticket.mission_progress.estado_actual', 'integrada')
            ->assertJsonPath('ticket.mission_progress.causa', 'El teléfono ya estaba usado en otra cuenta.');
        $this->assertSame('mission_progress', app(SupportCodexService::class)->pending(true, true)['tickets'][0]['reason']);
        $claim = $this->toma($ticket, $out);
        $this->respuesta($ticket, $claim, $hash, ['body' => 'La causa fue identificada y la corrección ya está integrada, falta instalarla.'])
            ->assertOk()->assertJsonPath('operation.status', 'sent');
        $this->assertSame('fix_ready_for_upgrade', app(SupportCodexService::class)->pending(true, true)['tickets'][0]['reason']);
        $this->assertCount(0, app(SupportCodexService::class)->pending(false, true)['tickets']);
    }

    public function test_b_reformula_operacion_cancelada_sin_marcar_a_como_enviado(): void
    {
        [$ticket, $case, $out] = $this->caso();
        $ticket->requiere_verificacion_mensajes = true;
        $ticket->save();
        $hash_a = $this->avance($ticket, $case)->assertOk()->json('progress_hash');
        $claim = $this->toma($ticket, $out);
        $op = 'misma-operacion-avance';
        $this->respuesta($ticket, $claim, $hash_a, ['operation_id' => $op])->assertOk()->assertJsonPath('operation.status', 'awaiting_approval');
        $hash_b = $this->avance($ticket, $case, ['estado' => 'identificado'])->assertOk()->json('progress_hash');
        $claim_b = $this->toma($ticket, $out);
        $ticket->requiere_verificacion_mensajes = false;
        $ticket->save();
        $old = SupportCodexMissionProgress::create(['support_ticket_id' => $ticket->id, 'mission_thread_id' => 'mision-anterior',
            'mission_hash' => hash('sha256', $case->mission), 'progress_hash' => $hash_b,
            'payload' => ['estado' => 'identificado'], 'support_codex_operation_id' => SupportCodexOperation::where('operation_id', $op)->first()->id]);
        $this->respuesta($ticket, $claim_b, $hash_b, ['operation_id' => $op])->assertOk()->assertJsonPath('operation.status', 'sent');
        $this->assertNull(SupportCodexMissionProgress::where('progress_hash', $hash_a)->first()->announced_at);
        $this->assertNotNull(SupportCodexMissionProgress::where('progress_hash', $hash_b)->first()->announced_at);
        $this->assertNull($old->fresh()->announced_at);
        $this->assertSame(1, $this->transport->sent);
    }

    public function test_aprobacion_envia_y_consume_solo_el_avance_correcto(): void
    {
        [$ticket, $case, $out] = $this->caso();
        $ticket->requiere_verificacion_mensajes = true;
        $ticket->save();
        $hash = $this->avance($ticket, $case)->assertOk()->json('progress_hash');
        $claim = $this->toma($ticket, $out);
        $op = $this->respuesta($ticket, $claim, $hash)->assertOk()->assertJsonPath('operation.status', 'awaiting_approval')->json('operation.id');
        $progress = SupportCodexMissionProgress::where('progress_hash', $hash)->first();
        $this->assertNull($progress->announced_at);
        $admin = Admin::create(['name' => 'Lucas', 'email' => uniqid('avance') . '@test.local', 'password' => bcrypt('prueba')]);
        $operation = SupportCodexOperation::where('operation_id', $op)->first();
        $result = app(SupportCodexService::class)->approve_draft($operation->message_id, null, $admin->id);
        $this->assertSame('sent', $result['operation']['status']);
        $this->assertNotNull($progress->fresh()->announced_at);
        $this->assertSame('mision-prueba', $case->fresh()->mission_thread_id);
        $this->assertSame('open', $ticket->fresh()->status);
    }

    public function test_no_admite_respuesta_de_avance_sin_huella_ni_mision_sin_iniciar(): void
    {
        [$ticket, $case, $out] = $this->caso();
        $case->update(['mission_started_at' => null]);
        $this->avance($ticket, $case)->assertStatus(409);
        $case->update(['mission_started_at' => now()]);
        $this->avance($ticket, $case)->assertOk();
        $claim = $this->toma($ticket, $out);
        $this->postJson($this->base . '/tickets/' . $ticket->id . '/reply', [
            'lease_token' => $claim['lease_token'], 'expected_message_id' => $out->id, 'operation_id' => 'sin-huella',
            'action' => 'responder', 'body' => 'Identificamos la causa.', 'supports_human_approval' => true,
        ], $this->headers)->assertStatus(422)->assertJsonPath('message', 'mission_progress_required: Para avisar este avance, incluí su huella del contexto en mission_progress_hash.');
        $this->assertSame(0, $this->transport->sent);
    }

    public function test_pedir_revision_sin_ack_conserva_el_avance_y_la_mision(): void
    {
        [$ticket, $case, $out] = $this->caso();
        $hash = $this->avance($ticket, $case)->assertOk()->json('progress_hash');
        $claim = $this->toma($ticket, $out);
        $this->postJson($this->base . '/tickets/' . $ticket->id . '/reply', [
            'lease_token' => $claim['lease_token'], 'expected_message_id' => $out->id, 'operation_id' => 'revision-avance',
            'action' => 'pedir_revision', 'body' => 'Estamos revisando una alternativa segura.',
            'review_reason' => 'La alternativa todavía necesita verificación manual.', 'supports_human_approval' => true,
        ], $this->headers)->assertOk()->assertJsonPath('operation.status', 'sent')->assertJsonPath('ticket.state', 'manual_review');
        $this->assertNull(SupportCodexMissionProgress::where('progress_hash', $hash)->first()->announced_at);
        $this->assertSame('mision-prueba', $case->fresh()->mission_thread_id);
        $this->assertCount(0, app(SupportCodexService::class)->pending(true, true)['tickets']);
    }

    public function test_claim_supervisado_rechaza_avance_que_cambio_sin_tomar_reserva(): void
    {
        [$ticket, $case, $out] = $this->caso();
        $hash_a = $this->avance($ticket, $case)->assertOk()->json('progress_hash');
        $this->avance($ticket, $case, ['estado' => 'identificado'])->assertOk();
        $this->postJson($this->base . '/tickets/' . $ticket->id . '/claim', [
            'worker_id' => 'pc-prueba', 'expected_message_id' => $out->id, 'supports_mission_progress' => true,
            'expected_progress_hash' => $hash_a,
        ], $this->headers)->assertStatus(409);
        $this->assertNull($case->fresh()->lease_hash);
    }
}
