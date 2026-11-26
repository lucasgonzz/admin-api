<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\SupportCodexCase;
use App\Models\SupportCodexMissionFixEvidence;
use App\Models\SupportCodexOperation;
use App\Models\SupportCodexScheduledUpgrade as Scheduled;
use App\Models\SupportCodexUpgradeAgreement;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\Version;
use App\Services\SupportCodexSettings;
use App\Services\SupportScheduledUpgradeService;
use App\Services\SupportUpgradeReconfirmationService;
use App\Services\SupportWhatsappOpenerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** HTTP y estado reales; el transporte es un espía y nunca contacta un negocio. */
class ReconfirmacionDeActualizacionSoporteTest extends TestCase
{
    use DatabaseTransactions;
    private $headers = ['X-Claude-Task-Key' => 'reconfirmacion-test'];
    private $delivery;
    private $leases = [];

    protected function setUp(): void
    {
        parent::setUp();
        // La coordinación de horarios quedó apagada por defecto (martin-descripcion-y-actualizaciones, CA5); acá se prueba encendida.
        config(['services.support_codex.upgrade_coordination' => true]);
        Carbon::setTestNow(Carbon::parse('2026-11-16T15:00:00-03:00'));
        config(['services.claude_task_ingest.key' => 'reconfirmacion-test', 'services.support_codex_scheduled_upgrades.enabled' => true]);
        Event::fake([\App\Events\SupportMessageReceived::class, \App\Events\SupportTicketUpdated::class]);
        Queue::fake();
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, '2026-11-01 00:00:00');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AFTER_ID, '0');
        $this->delivery = new class extends SupportWhatsappOpenerService {
            public $bodies = [];
            public function __construct() {}
            public function deliver_follow_up(SupportTicket $ticket, SupportMessage $message, string $admin_name): array
            {
                $this->bodies[] = $message->body;
                $message->update(['whatsapp_message_id' => 'wamid.qa.' . $message->id]);
                return ['delivery' => 'sent', 'message_id' => $message->whatsapp_message_id, 'used_template' => false, 'error' => null];
            }
        };
        $this->app->instance(SupportWhatsappOpenerService::class, $this->delivery);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    public function test_tarea_diurna_de_sistema_y_tienda_no_encola_antes_de_reconfirmar(): void
    {
        foreach (['sistema', 'tienda'] as $target) {
            [$row] = $this->scenario($target);
            app(SupportScheduledUpgradeService::class)->tick();
            $this->assertSame('scheduled', $row->fresh()->status);
            $this->assertSame('esperando_reconfirmacion', $row->fresh()->reason);
        }
        Queue::assertNothingPushed();
        $this->assertCount(0, $this->delivery->bodies);
    }

    public function test_pide_una_vez_por_ticket_y_sin_respuesta_pasa_a_las_dos_de_argentina(): void
    {
        [$row, $ticket] = $this->scenario();
        $input = $this->claim($ticket) + ['operation_id' => 'reconfirmar-una-vez', 'schedule_id' => $row->id];
        $url = $this->url($ticket, 'upgrade-reconfirmation');
        $this->postJson($url, $input, $this->headers)->assertOk()->assertJsonPath('operation.status', 'sent');
        $this->postJson($url, $input, $this->headers)->assertOk()->assertJsonPath('operation.status', 'sent');
        $this->assertCount(1, $this->delivery->bodies);
        $this->assertStringContainsString('15 a 30 minutos', $this->delivery->bodies[0]);
        app(SupportScheduledUpgradeService::class)->tick();
        $this->assertNotNull($row->fresh()->reconfirmation_expires_at);
        Carbon::setTestNow(now()->addMinutes(16));
        app(SupportScheduledUpgradeService::class)->tick();
        $row->refresh();
        $this->assertTrue((bool) $row->overnight_fallback);
        $this->assertSame('2026-11-17 05:00:00', $row->starts_at_utc);
        $this->assertSame('aviso_madrugada_pendiente', $row->reason);
        Queue::assertNothingPushed();
        // La madrugada no requiere otro sí; el aviso, la aprobación y la actividad real siguen siendo obligatorios.
        $input = $this->claim($ticket) + ['operation_id' => 'aviso-madrugada', 'schedule_id' => $row->id];
        $this->postJson($this->url($ticket, 'upgrade-overnight'), $input, $this->headers)->assertOk()->assertJsonPath('operation.status', 'sent');
        app(SupportUpgradeReconfirmationService::class)->context($ticket->id);
        $this->assertSame(null, $row->fresh()->reason);
        $this->assertNotNull($row->fresh()->overnight_notice_operation_id);
    }

    public function test_si_vigente_habilita_ventana_nueva_de_treinta_minutos_y_otro_tema_no(): void
    {
        [$row, $ticket] = $this->scenario();
        $input = $this->claim($ticket) + ['operation_id' => 'reconfirmar-si', 'schedule_id' => $row->id];
        $this->postJson($this->url($ticket, 'upgrade-reconfirmation'), $input, $this->headers)->assertOk();
        app(SupportUpgradeReconfirmationService::class)->context($ticket->id);
        $operation = SupportCodexOperation::findOrFail($row->fresh()->reconfirmation_operation_id);
        Carbon::setTestNow(now()->addMinutes(10));
        $wrong = $this->inbound($ticket, 'Gracias, después pruebo');
        $claim = $this->claim($ticket);
        $this->postJson($this->url($ticket, 'upgrade-agreement'), $claim + ['proposal_operation_id' => $operation->operation_id, 'schedule_id' => $row->id], $this->headers)->assertStatus(422);
        $this->assertNull($row->fresh()->reconfirmed_at);
        $right = $this->inbound($ticket, 'Sí', $operation->message_id);
        $claim = $this->claim($ticket);
        $this->postJson($this->url($ticket, 'upgrade-agreement'), $claim + ['proposal_operation_id' => $operation->operation_id, 'schedule_id' => $row->id], $this->headers)
            ->assertOk()->assertJsonPath('upgrade_reconfirmation.status', 'confirmed');
        $row->refresh();
        $this->assertSame((int) $right->id, (int) $row->reconfirmation_message_id);
        $this->assertSame(30, Carbon::parse($row->ends_at_utc, 'UTC')->diffInMinutes(Carbon::parse($row->starts_at_utc, 'UTC')));
        $this->assertTrue(app(SupportUpgradeReconfirmationService::class)->may_start($row));
        $this->assertTrue(app(SupportUpgradeReconfirmationService::class)->valid_confirmation($row, $right));
        $this->assertFalse(app(SupportUpgradeReconfirmationService::class)->valid_confirmation($row, $wrong));
        $thanks = $this->inbound($ticket, 'Ok, gracias!');
        $this->assertTrue(app(SupportUpgradeReconfirmationService::class)->valid_confirmation($row, $thanks));
        $ssh = $this->createMock(\App\Services\EnvSshService::class);
        $ssh->method('read_coordinated_upgrade_activity_for')->willReturn(['usuarios_activos' => 0, 'ventas_recientes' => 0, 'trabajos_pendientes' => 0]);
        $this->app->instance(\App\Services\EnvSshService::class, $ssh);
        Carbon::setTestNow(now()->addSeconds(31));
        app(SupportScheduledUpgradeService::class)->tick();
        app(SupportScheduledUpgradeService::class)->tick();
        $this->assertSame('starting', $row->fresh()->status, (string) $row->fresh()->reason);
        Queue::assertPushed(\App\Jobs\RunDeploymentJob::class, 1);
    }

    public function test_reconfirmacion_copiada_con_comillas_conserva_la_pausa_y_rechaza_ambiguedades(): void
    {
        foreach (['sistema', 'tienda'] as $target) {
            [$row, $ticket] = $this->scenario($target);
            $input = $this->claim($ticket) + ['operation_id' => 'reconfirmacion-copia-' . $ticket->id, 'schedule_id' => $row->id];
            $this->postJson($this->url($ticket, 'upgrade-reconfirmation'), $input, $this->headers)->assertOk();
            app(SupportUpgradeReconfirmationService::class)->context($ticket->id);
            $operation = SupportCodexOperation::findOrFail($row->fresh()->reconfirmation_operation_id);
            $phrase = 'Confirmo que nadie está usando el sistema y pueden actualizar ahora';
            $message = $this->inbound($ticket, '«' . $phrase . '».');
            $accept = $this->claim($ticket) + ['proposal_operation_id' => $operation->operation_id, 'schedule_id' => $row->id];
            foreach (['Confirmo', 'No ' . $phrase, $phrase . ' pero esperen', '"' . $phrase,
                str_replace('ahora', 'mañana', $phrase)] as $wrong) {
                $message->update(['body' => $wrong]);
                $response = $this->postJson($this->url($ticket, 'upgrade-agreement'), $accept, $this->headers)->assertStatus(422);
                $this->assertStringContainsString('No se autorizó el corte', $response->json('message'));
                $this->assertNull($row->fresh()->reconfirmed_at);
                $this->assertNotNull($row->agreement->fresh()->accepted_at);
            }
            $message->update(['body' => '«' . $phrase . '».', 'reply_to_message_id' => $row->agreement->operation->message_id]);
            $this->postJson($this->url($ticket, 'upgrade-agreement'), $accept, $this->headers)->assertStatus(422);
            $message->update(['reply_to_message_id' => null]);
            $notice = SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'admin', 'kind' => 'text',
                'body' => 'Estamos registrando tu confirmación.', 'whatsapp_message_id' => 'wamid.avance.' . $ticket->id]);
            SupportCodexCase::where('support_ticket_id', $ticket->id)->update(['expected_message_id' => $notice->id]);
            $accept['expected_message_id'] = $notice->id;
            $this->postJson($this->url($ticket, 'upgrade-agreement'), $accept, $this->headers)->assertStatus(422);
            $accept['confirmation_message_id'] = $message->id;
            $this->postJson($this->url($ticket, 'upgrade-agreement'), $accept, $this->headers)->assertOk()
                ->assertJsonPath('upgrade_reconfirmation.confirmation_message_id', $message->id);
            $this->assertTrue(app(SupportUpgradeReconfirmationService::class)->valid_confirmation($row->fresh(), $message));
            $later = $this->inbound($ticket, 'No, todavía lo estamos usando');
            $accept = array_replace($accept, $this->claim($ticket));
            $this->postJson($this->url($ticket, 'upgrade-agreement'), $accept, $this->headers)->assertStatus(422);
            $later->update(['body' => 'Gracias']);
            $this->postJson($this->url($ticket, 'upgrade-agreement'), $accept, $this->headers)->assertStatus(422);
            Queue::assertNothingPushed();
        }
    }

    public function test_aprobacion_y_envio_incierto_no_se_interpretan_como_silencio_del_cliente(): void
    {
        [$row, $ticket] = $this->scenario();
        $ticket->update(['requiere_verificacion_mensajes' => true]);
        $input = $this->claim($ticket) + ['operation_id' => 'reconfirmar-aprobacion', 'schedule_id' => $row->id, 'supports_human_approval' => true];
        $this->postJson($this->url($ticket, 'upgrade-reconfirmation'), $input, $this->headers)->assertOk()->assertJsonPath('operation.status', 'awaiting_approval');
        Carbon::setTestNow(now()->addMinutes(20));
        app(SupportScheduledUpgradeService::class)->tick();
        $this->assertFalse((bool) $row->fresh()->overnight_fallback);
        $this->assertNull($row->fresh()->reconfirmation_requested_at);
        $this->assertCount(0, $this->delivery->bodies);
        $op = SupportCodexOperation::findOrFail($row->fresh()->reconfirmation_operation_id);
        $op->update(['status' => 'unknown']);
        app(SupportScheduledUpgradeService::class)->tick();
        $this->assertNull($row->fresh()->reconfirmed_at);
        Queue::assertNothingPushed();
    }

    public function test_negativa_explicita_puede_pasar_a_madrugada_antes_del_timeout(): void
    {
        [$row, $ticket] = $this->scenario();
        $this->inbound($ticket, 'No, seguimos usando el sistema. A la madrugada mejor.');
        $input = $this->claim($ticket) + ['operation_id' => 'negativa-madrugada', 'schedule_id' => $row->id];
        $this->postJson($this->url($ticket, 'upgrade-overnight'), $input, $this->headers)->assertOk()->assertJsonPath('operation.status', 'sent');
        app(SupportUpgradeReconfirmationService::class)->context($ticket->id);
        $this->assertTrue((bool) $row->fresh()->overnight_fallback);
        $this->assertSame('2026-11-17 05:00:00', $row->fresh()->starts_at_utc);
        Queue::assertNothingPushed();
    }

    public function test_un_aviso_demostrado_no_enviado_puede_reprepararse_con_otra_operacion(): void
    {
        [$row, $ticket] = $this->scenario();
        $ticket->update(['requiere_verificacion_mensajes' => true]);
        $input = $this->claim($ticket) + ['operation_id' => 'reconfirmacion-vieja', 'schedule_id' => $row->id, 'supports_human_approval' => true];
        $this->postJson($this->url($ticket, 'upgrade-reconfirmation'), $input, $this->headers)->assertOk()->assertJsonPath('operation.status', 'awaiting_approval');
        $prior = SupportCodexOperation::findOrFail($row->fresh()->reconfirmation_operation_id);
        $this->inbound($ticket, 'Ya llegué, seguimos?');
        app(\App\Services\SupportCodexService::class)->invalidate_stale_approval($ticket);
        $this->assertSame('cancelled', $prior->fresh()->status);
        $ticket->update(['requiere_verificacion_mensajes' => false]);
        $input = $this->claim($ticket) + ['operation_id' => 'reconfirmacion-vigente', 'schedule_id' => $row->id];
        $this->postJson($this->url($ticket, 'upgrade-reconfirmation'), $input, $this->headers)->assertOk()->assertJsonPath('operation.status', 'sent');
        $this->assertNotSame((int) $prior->id, (int) $row->fresh()->reconfirmation_operation_id);
        $this->assertCount(1, $this->delivery->bodies);
        Queue::assertNothingPushed();
    }

    public function test_editar_el_borrador_de_reconfirmacion_no_puede_quitar_el_aviso_ni_enviar(): void
    {
        [$row, $ticket] = $this->scenario();
        $ticket->update(['requiere_verificacion_mensajes' => true]);
        $input = $this->claim($ticket) + ['operation_id' => 'reconfirmacion-editada', 'schedule_id' => $row->id, 'supports_human_approval' => true];
        $draft = $this->postJson($this->url($ticket, 'upgrade-reconfirmation'), $input, $this->headers)->assertOk()->assertJsonPath('operation.status', 'awaiting_approval')->json();
        $admin = \App\Models\Admin::create(['name' => 'Operador QA', 'email' => uniqid('reconfirmacion') . '@test.local', 'password' => bcrypt('solo-fixture')]);
        $path = '/api/admin/support-message/' . $draft['operation']['message_id'] . '/approve-ai-draft';
        $this->actingAs($admin, 'sanctum')->postJson($path, ['body' => 'Vamos a actualizar ahora.'])->assertStatus(422);
        $this->assertCount(0, $this->delivery->bodies);
        $this->assertNull($row->fresh()->reconfirmed_at);
        $this->postJson($path)->assertOk()->assertJsonPath('delivered', true);
        $this->assertCount(1, $this->delivery->bodies);
        Queue::assertNothingPushed();
    }

    public function test_propuesta_nocturna_informada_habilita_preparacion_sin_nuevo_entrante_y_la_cortesia_no_la_revoca(): void
    {
        [$row, $ticket] = $this->scenario();
        $agreement = $row->agreement;
        $row->delete();
        $agreement->delete();
        $mission = \App\Models\SupportCodexMission::where('support_ticket_id', $ticket->id)->firstOrFail();
        $input = $this->claim($ticket) + ['operation_id' => 'elegir-madrugada', 'mission_id' => $mission->id,
            'mission_thread_id' => $mission->mission_thread_id, 'mission_hash' => $mission->mission_hash,
            'maintenance_mode' => 'overnight', 'target' => 'sistema', 'minimum_minutes' => 60,
            'starts_at' => '2026-11-17T02:00:00-03:00', 'ends_at' => '2026-11-17T03:00:00-03:00'];
        $this->postJson($this->url($ticket, 'upgrade-proposal'), $input, $this->headers)->assertOk()->assertJsonPath('operation.status', 'sent');
        app(SupportUpgradeReconfirmationService::class)->sync_overnight_agreements();
        $agreement = SupportCodexUpgradeAgreement::where('support_ticket_id', $ticket->id)->firstOrFail();
        $this->assertNotNull($agreement->accepted_at);
        $this->assertTrue(app(\App\Services\SupportUpgradePreflight::class)->inspect($agreement->id, null, true)['ready']);
        $this->assertCount(1, app(\App\Services\SupportCodexReleaseService::class)->work()['work']);
        $this->inbound($ticket, 'Perfecto, gracias');
        $this->assertTrue(app(\App\Services\SupportUpgradePreflight::class)->inspect($agreement->id, null, true)['ready']);
        $this->inbound($ticket, 'No actualicen, lo voy a usar a esa hora');
        $this->assertSame('confirmacion_obsoleta', app(\App\Services\SupportUpgradePreflight::class)->inspect($agreement->id, null, true)['reason']);
        Queue::assertNothingPushed();
    }

    private function url(SupportTicket $ticket, string $action): string { return '/api/claude/support-codex/tickets/' . $ticket->id . '/' . $action; }

    private function claim(SupportTicket $ticket): array
    {
        $last = $ticket->messages()->reorder('id', 'desc')->first();
        $data = ['worker_id' => 'qa-reconfirmacion', 'expected_message_id' => $last->id, 'supports_upgrade_agreement' => true];
        $case = SupportCodexCase::where('support_ticket_id', $ticket->id)->first();
        if (isset($this->leases[$ticket->id]) && $case->lease_expires_at !== null && $case->lease_expires_at->isFuture()) {
            $data['lease_token'] = $this->leases[$ticket->id];
        }
        $http = $this->postJson($this->url($ticket, 'claim'), $data, $this->headers);
        $this->assertSame(200, $http->status(), $http->json('message') ?: 'No se pudo tomar la atención.');
        $response = $http->json();
        $this->leases[$ticket->id] = $response['lease_token'];
        return ['lease_token' => $response['lease_token'], 'expected_message_id' => $response['ticket']['expected_message_id']];
    }

    private function inbound(SupportTicket $ticket, string $body, ?int $reply = null): SupportMessage
    {
        return SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text',
            'body' => $body, 'reply_to_message_id' => $reply, 'whatsapp_message_id' => 'wamid.inbound.' . uniqid(), 'delivered_at' => now()]);
    }

    private function scenario(string $target = 'sistema'): array
    {
        static $user_id = 90123;
        $old = Version::firstOrCreate(['version' => '90.99.9'], ['status' => 'published']);
        $version = Version::firstOrCreate(['version' => '90.99.10'], ['status' => 'published']);
        $client = Client::create(['name' => 'Cliente QA', 'phone' => '+5493417771234', 'is_active' => true, 'user_id' => ++$user_id, 'current_version_id' => $old->id]);
        $api = ClientApi::create(['client_id' => $client->id, 'url' => 'https://api-reconfirmacion.test', 'path' => 'qa/api']);
        $client->update(['active_client_api_id' => $api->id]);
        $ticket = SupportTicket::create(['client_id' => $client->id, 'client_user_id' => 0, 'status' => 'open', 'source' => 'whatsapp',
            'whatsapp_phone' => $client->phone, 'opened_at' => now(), 'last_client_message_at' => now()]);
        $inbound = $this->inbound($ticket, 'Confirmo el horario acordado');
        SupportCodexCase::create(['support_ticket_id' => $ticket->id, 'state' => 'mission_pending', 'mission' => 'Corregir ventas',
            'mission_thread_id' => 'chat-' . $ticket->id, 'mission_started_at' => now()->subDay(), 'handled_message_id' => $inbound->id]);
        $evidence = SupportCodexMissionFixEvidence::create(['support_ticket_id' => $ticket->id, 'mission_thread_id' => 'chat-' . $ticket->id,
            'mission_hash' => hash('sha256', 'Corregir ventas'), 'payload' => ['commits' => ['empresa-api' => str_repeat('a', 40)]], 'payload_hash' => str_repeat('b', 64)]);
        $outbound = SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'admin', 'kind' => 'text',
            'body' => 'Actualizamos hoy a las 15?', 'whatsapp_message_id' => 'wamid.oferta.' . $ticket->id, 'delivered_at' => now()]);
        $case = SupportCodexCase::where('support_ticket_id', $ticket->id)->first();
        $case->update(['last_reply_id' => $outbound->id, 'handled_message_id' => $outbound->id]);
        $operation = SupportCodexOperation::create(['support_ticket_id' => $ticket->id, 'expected_message_id' => $inbound->id,
            'operation_id' => 'oferta-' . $ticket->id, 'request_hash' => str_repeat('a', 64), 'action' => 'responder', 'status' => 'sent', 'message_id' => $outbound->id]);
        $agreement = SupportCodexUpgradeAgreement::create(['support_ticket_id' => $ticket->id, 'mission_fix_evidence_id' => $evidence->id,
            'support_codex_operation_id' => $operation->id, 'minimum_minutes' => 15, 'maintenance_mode' => 'coordinated', 'target' => $target,
            'starts_at_utc' => now()->utc()->toDateTimeString(), 'ends_at_utc' => now()->utc()->addMinutes(15)->toDateTimeString(),
            'proposal_body_hash' => hash('sha256', $outbound->body), 'confirmation_phrase' => 'Confirmo', 'confirmation_message_id' => $inbound->id, 'accepted_at' => now()]);
        $row = Scheduled::create(['agreement_id' => $agreement->id, 'support_ticket_id' => $ticket->id, 'client_id' => $client->id,
            'client_api_id' => $api->id, 'from_version_id' => $old->id, 'version_id' => $version->id, 'version' => $version->version,
            'target' => $target, 'artifacts' => [], 'status' => Scheduled::SCHEDULED,
            'starts_at_utc' => $agreement->starts_at_utc, 'ends_at_utc' => $agreement->ends_at_utc, 'reason' => 'esperando_reconfirmacion']);
        return [$row, $ticket];
    }
}
