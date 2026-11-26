<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\SupportCodexCase;
use App\Models\SupportCodexMission;
use App\Models\SupportCodexMissionFixEvidence;
use App\Models\SupportCodexOperation;
use App\Models\SupportCodexReleaseClaim;
use App\Models\SupportCodexScheduledUpgrade;
use App\Models\SupportCodexUpgradeAgreement;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\Local\MisionesDelLocal;
use App\Services\SupportCodexSettings;
use App\Services\SupportWhatsappOpenerService;
use App\Services\SupportMissionDeliveryService;
use App\Services\SupportCodexService;
use App\Services\ReleaseArtifactService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Contrato HTTP real; toda la evidencia y los tickets pertenecen a la base aislada del slot. */
class VariasMisionesPorTicketTest extends TestCase
{
    use DatabaseTransactions;
    private $headers = ['X-Claude-Task-Key' => 'varias-misiones', 'Accept' => 'application/json'];
    private $base = '/api/claude/support-codex/tickets/';

    protected function setUp(): void
    {
        parent::setUp();
        // La coordinación de horarios quedó apagada por defecto (martin-descripcion-y-actualizaciones, CA5); acá se prueba encendida.
        config(['services.support_codex.upgrade_coordination' => true]);
        Carbon::setTestNow(Carbon::parse('2026-11-16 12:00:00'));
        config(['services.claude_task_ingest.key' => 'varias-misiones']);
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, '2026-10-01 00:00:00');
        $this->app->instance(SupportWhatsappOpenerService::class, new class extends SupportWhatsappOpenerService {
            public function __construct() {}
            public function deliver_follow_up(SupportTicket $ticket, SupportMessage $message, string $name): array
            {
                $message->whatsapp_message_id = 'wamid.simulada.' . $message->id;
                $message->save();
                return ['delivery' => 'sent'];
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ticket(): array
    {
        $client = Client::create(['name' => 'Negocio con varias misiones', 'phone' => '+5493410000099', 'is_active' => true]);
        $ticket = SupportTicket::create(['client_id' => $client->id, 'client_user_id' => 0, 'source' => 'whatsapp',
            'status' => 'open', 'whatsapp_phone' => $client->phone, 'opened_at' => now(), 'last_client_message_at' => now()]);
        SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text',
            'body' => 'Falla al guardar una venta.', 'delivered_at' => now()]);
        $case = SupportCodexCase::create(['support_ticket_id' => $ticket->id, 'state' => 'mission_pending',
            'mission' => 'Corregir el guardado de ventas.', 'mission_thread_id' => 'mision-a-' . $ticket->id,
            'mission_started_at' => now(), 'thread_id' => 'soporte-' . $ticket->id]);
        return [$ticket, $case, SupportCodexMission::where('support_ticket_id', $ticket->id)->firstOrFail()];
    }

    private function evidence(SupportCodexMission $mission): array
    {
        return ['mission_id' => $mission->id, 'mission_thread_id' => $mission->mission_thread_id,
            'mission_hash' => $mission->mission_hash, 'informe' => 'informes/20261116-arreglo-de-ventas.md',
            'pruebas' => ['Recorrido de venta y pruebas de regresión correctos.'],
            'commits' => ['empresa-api' => str_repeat('a', 40)],
            'bases_verificadas' => ['empresa-api' => str_repeat('b', 40)], 'estado' => 'verificada'];
    }

    public function test_finalizar_una_mision_anterior_no_pisa_la_segunda_y_los_contadores_sobreviven_al_cierre(): void
    {
        [$ticket, $case, $first] = $this->ticket();
        $case->update(['mission' => 'Agregar búsqueda de pedidos.', 'mission_thread_id' => null, 'mission_started_at' => null,
            'close_on_mission_start' => true]);
        $second = SupportCodexMission::where('support_ticket_id', $ticket->id)->latest('id')->firstOrFail();
        $ticket->update(['status' => 'closed']);
        $case->update(['state' => 'closed']);
        $this->postJson($this->base . $ticket->id . '/mission-fix-evidence', $this->evidence($first), $this->headers)->assertOk();
        $this->assertNotNull($first->fresh()->completed_at);
        $this->assertNull($second->fresh()->completed_at);
        $this->assertSame($second->mission, $case->fresh()->mission);
        $summary = $ticket->fresh()->load('support_codex_case')->support_attention;
        $this->assertSame(['total' => 1, 'completed' => 1], $summary['mission_counts']['bug']);
        $this->assertSame(['total' => 1, 'completed' => 0], $summary['mission_counts']['funcionalidad']);
        $this->assertCount(2, $summary['missions']);
        $this->assertArrayNotHasKey('mission', $summary['missions'][0]);
        $this->getJson($this->base . $ticket->id . '?mission_id=' . $first->id, $this->headers)->assertOk()
            ->assertJsonPath('ticket.mission', $first->mission)->assertJsonPath('ticket.mission_counts.bug.completed', 1);
    }

    public function test_un_chat_terminado_no_cuenta_como_merge_y_evidencia_repetida_no_duplica(): void
    {
        [$ticket, $case, $mission] = $this->ticket();
        $this->assertNull($mission->completed_at);
        $payload = $this->evidence($mission);
        $this->postJson($this->base . $ticket->id . '/mission-fix-evidence', $payload, $this->headers)->assertOk();
        unset($payload['mission_id']);
        $this->postJson($this->base . $ticket->id . '/mission-fix-evidence', $payload, $this->headers)->assertOk();
        $this->assertSame(1, SupportCodexMissionFixEvidence::where('support_ticket_id', $ticket->id)->count());
        $this->assertSame(1, SupportCodexMission::where('support_ticket_id', $ticket->id)->count());
    }

    public function test_selector_de_otra_mision_o_ticket_no_se_ignora(): void
    {
        [$ticket, $case, $mission] = $this->ticket();
        [$other, $other_case, $foreign] = $this->ticket();
        $payload = $this->evidence($mission);
        $payload['mission_id'] = $foreign->id;
        $this->postJson($this->base . $ticket->id . '/mission-fix-evidence', $payload, $this->headers)->assertStatus(409);
        $this->assertNull($mission->fresh()->completed_at);
        $this->getJson($this->base . $ticket->id . '?mission_id=' . $foreign->id, $this->headers)->assertNotFound();
    }

    public function test_otro_hilo_no_reemplaza_el_inicio_real_y_las_misiones_pendientes_tienen_solicitudes_independientes(): void
    {
        [$ticket, $case, $first] = $this->ticket();
        $case->update(['mission' => 'Agregar una pantalla optativa.', 'mission_thread_id' => null, 'mission_started_at' => null,
            'close_on_mission_start' => true]);
        $feature_a = SupportCodexMission::where('support_ticket_id', $ticket->id)->latest('id')->firstOrFail();
        $case->update(['mission' => 'Agregar otra consulta.', 'mission_thread_id' => null, 'mission_started_at' => null]);
        $feature_b = SupportCodexMission::where('support_ticket_id', $ticket->id)->latest('id')->firstOrFail();
        $local = app(MisionesDelLocal::class);
        $local->solicitar($ticket->id, $feature_a->mission_hash, $feature_a->id);
        $local->solicitar($ticket->id, $feature_b->mission_hash, $feature_b->id);
        $this->assertNotSame($feature_a->fresh()->local_mission_request_id, $feature_b->fresh()->local_mission_request_id);
        $items = collect($local->solicitudes())->where('ticket_id', $ticket->id);
        $this->assertCount(2, $items);
        $request_a = $feature_a->fresh()->local_mission_request_id;
        $this->assertTrue($local->reclamar($ticket->id, $request_a, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $feature_a->id));
        $this->assertFalse($local->reclamar($ticket->id, $request_a, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $feature_b->id));
        $this->postJson($this->base . $ticket->id . '/mission-started', ['mission_id' => $feature_a->id,
            'mission_hash' => $feature_a->mission_hash, 'mission_thread_id' => 'feature-a', 'mission_type' => 'funcionalidad'], $this->headers)->assertOk();
        $this->assertSame('feature-a', $feature_a->fresh()->mission_thread_id);
        $this->assertNull($feature_b->fresh()->mission_thread_id);
        $this->assertSame($feature_b->mission, $case->fresh()->mission);
        $this->postJson($this->base . $ticket->id . '/mission-started', ['mission_id' => $feature_a->id,
            'mission_hash' => $feature_a->mission_hash, 'mission_thread_id' => 'otra-tarea'], $this->headers)->assertStatus(409);
    }

    public function test_reescalar_un_diagnostico_conocido_conserva_su_tarea_y_no_duplica_la_mision(): void
    {
        [$ticket, $case, $first] = $this->ticket();
        $case->update(['mission' => 'Corregir el buscador.', 'mission_thread_id' => null, 'mission_started_at' => null]);
        $in = SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text',
            'body' => 'El problema era el de ventas que te pasé antes.', 'delivered_at' => now()]);
        $claim = $this->postJson($this->base . $ticket->id . '/claim', ['worker_id' => 'prueba', 'expected_message_id' => $in->id], $this->headers)
            ->assertOk()->json();
        $this->postJson($this->base . $ticket->id . '/reply', ['lease_token' => $claim['lease_token'],
            'expected_message_id' => $in->id, 'operation_id' => 'reescala-' . $ticket->id, 'action' => 'escalar',
            'body' => 'Ya está en corrección.', 'mission' => $first->mission, 'mission_type' => 'bug'], $this->headers)
            ->assertOk()->assertJsonPath('operation.status', 'sent');
        $this->assertCount(2, SupportCodexMission::where('support_ticket_id', $ticket->id)->get());
        $this->assertSame($first->mission_thread_id, $first->fresh()->mission_thread_id);
        $this->assertSame($first->mission_thread_id, $case->fresh()->mission_thread_id);
        $this->assertNotNull($first->fresh()->mission_started_at);
    }

    public function test_funcionalidad_requiere_confirmacion_del_alcance_vigente_antes_de_enviar(): void
    {
        [$ticket, $case, $first] = $this->ticket();
        $old = SupportMessage::where('support_ticket_id', $ticket->id)->firstOrFail();
        $old->update(['body' => 'Sí, confirmo ese alcance.']);
        $in = SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text',
            'body' => 'Hola, tengo otra consulta.', 'delivered_at' => now()]);
        $claim = $this->postJson($this->base . $ticket->id . '/claim', ['worker_id' => 'prueba', 'expected_message_id' => $in->id], $this->headers)
            ->assertOk()->json();
        $body = ['lease_token' => $claim['lease_token'], 'expected_message_id' => $in->id,
            'operation_id' => 'feature-' . $ticket->id, 'action' => 'escalar', 'body' => 'Dejamos lista la mejora para Lucas.',
            'mission' => 'Agregar consulta nueva con comportamiento anterior conservado.', 'mission_type' => 'funcionalidad',
            'scope_confirmed_message_id' => $old->id, 'close_on_mission_start' => true];
        $this->postJson($this->base . $ticket->id . '/reply', $body, $this->headers)->assertStatus(422);
        $body['scope_confirmed_message_id'] = $in->id;
        $this->postJson($this->base . $ticket->id . '/reply', $body, $this->headers)->assertStatus(422);
        $this->assertFalse(SupportCodexOperation::where('operation_id', $body['operation_id'])->exists());
        $this->assertCount(1, SupportCodexMission::where('support_ticket_id', $ticket->id)->get());
        $in->update(['body' => 'Sí, confirmo ese alcance.']);
        $this->postJson($this->base . $ticket->id . '/reply', $body, $this->headers)->assertOk()->assertJsonPath('operation.status', 'sent');
        $feature = SupportCodexMission::where('support_ticket_id', $ticket->id)->latest('id')->firstOrFail();
        $this->assertSame('funcionalidad', $feature->mission_type);
        $this->assertSame((int) $in->id, (int) $feature->scope_confirmed_message_id);
        $this->assertNull($feature->mission_thread_id);
        $this->postJson($this->base . $ticket->id . '/mission-started', ['mission_id' => $feature->id,
            'mission_hash' => $feature->mission_hash, 'mission_thread_id' => 'feature-con-otro-bug', 'mission_type' => 'funcionalidad'], $this->headers)->assertOk();
        $this->assertSame('open', $ticket->fresh()->status, 'El inicio de la mejora no resuelve la correctiva anterior.');
    }

    public function test_un_release_entrega_dos_misiones_sin_inventar_la_prueba_funcional_de_la_segunda(): void
    {
        [$ticket, $case, $first] = $this->ticket();
        $first_payload = $this->evidence($first);
        $this->postJson($this->base . $ticket->id . '/mission-fix-evidence', $first_payload, $this->headers)->assertOk();
        $case->update(['mission' => 'Corregir el listado de ventas.', 'mission_thread_id' => 'mision-b-' . $ticket->id,
            'mission_started_at' => now()]);
        $second = SupportCodexMission::where('support_ticket_id', $ticket->id)->latest('id')->firstOrFail();
        $this->postJson($this->base . $ticket->id . '/mission-fix-evidence', $this->evidence($second), $this->headers)->assertOk();
        $op = SupportCodexOperation::create(['support_ticket_id' => $ticket->id, 'expected_message_id' => 1,
            'operation_id' => 'delivery-op-' . $ticket->id, 'request_hash' => str_repeat('a', 64), 'action' => 'responder', 'status' => 'sent']);
        $evidence = SupportCodexMissionFixEvidence::where('support_ticket_id', $ticket->id)->where('mission_hash', $first->mission_hash)->firstOrFail();
        $agreement = SupportCodexUpgradeAgreement::create(['support_ticket_id' => $ticket->id, 'mission_fix_evidence_id' => $evidence->id,
            'support_codex_operation_id' => $op->id, 'starts_at_utc' => now()->utc(), 'ends_at_utc' => now()->utc()->addHour(),
            'minimum_minutes' => 60, 'proposal_body_hash' => str_repeat('b', 64), 'confirmation_phrase' => 'Confirmo', 'accepted_at' => now()]);
        $schedule = SupportCodexScheduledUpgrade::create(['agreement_id' => $agreement->id, 'support_ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id, 'client_api_id' => 1, 'from_version_id' => 1, 'version_id' => 2,
            'version' => '99.0.1', 'artifacts' => [], 'status' => 'verified', 'starts_at_utc' => now()->utc(),
            'ends_at_utc' => now()->utc()->addHour(), 'verification_evidence' => 'Se reprodujo la venta completa sin el error de guardado.']);
        SupportCodexReleaseClaim::create(['agreement_id' => $agreement->id, 'mission_fix_evidence_id' => $evidence->id,
            'version_id' => 2, 'version' => '99.0.1', 'develop' => [], 'published_at' => now(),
            'proof' => ['tag_commits' => ['empresa-api' => str_repeat('c', 40)]]]);
        $github = \Mockery::mock(ReleaseArtifactService::class);
        $github->shouldReceive('support_fix_is_ancestor')->once()->with('empresa-api', str_repeat('a', 40), str_repeat('c', 40))->andReturn(true);
        $this->app->instance(ReleaseArtifactService::class, $github);
        app(SupportMissionDeliveryService::class)->record_verified_schedule($schedule);
        $this->assertNotNull($first->fresh()->delivery_verified_at);
        $this->assertNotNull($second->fresh()->installed_at);
        $this->assertNull($second->fresh()->delivery_verified_at);
        $this->getJson($this->base . $ticket->id . '?mission_id=' . $second->id, $this->headers)->assertOk()
            ->assertJsonPath('ticket.mission_delivery.status', 'installed_pending_verification')
            ->assertJsonPath('ticket.mission_delivery.version', '99.0.1');
        $this->postJson($this->base . $ticket->id . '/mission-delivery-verified', ['mission_id' => $second->id,
            'schedule_id' => $schedule->id, 'evidencia' => 'Se reprodujo el listado de ventas y los totales correctos en el negocio.'], $this->headers)
            ->assertOk()->assertJsonPath('mission_delivery.status', 'verified');
        $this->assertNotNull($second->fresh()->delivery_verified_at);
    }

    public function test_aviso_de_entrega_de_a_pide_prueba_de_a_sin_borrar_ni_cerrar_la_correctiva_b(): void
    {
        [$ticket, $case, $first] = $this->ticket();
        $first->update(['completed_at' => now(), 'installed_at' => now(), 'installed_schedule_id' => 1, 'delivery_verified_at' => now()]);
        $case->update(['mission' => 'Corregir el listado de ventas.', 'mission_thread_id' => 'otra-correctiva-' . $ticket->id,
            'mission_started_at' => now()]);
        $second = SupportCodexMission::where('support_ticket_id', $ticket->id)->latest('id')->firstOrFail();
        $this->postJson($this->base . $ticket->id . '/mission-fix-evidence', $this->evidence($second), $this->headers)->assertOk();
        $last = SupportMessage::where('support_ticket_id', $ticket->id)->latest('id')->firstOrFail();
        app(SupportCodexService::class)->resume($ticket->id, ['expected_message_id' => $last->id,
            'reason' => 'La venta fue corregida e instalada.', 'request_confirmation' => true, 'mission_id' => $first->id]);
        $this->assertSame($second->mission, $case->fresh()->mission);
        $this->assertSame($second->mission_thread_id, $case->fresh()->mission_thread_id);
        $this->getJson($this->base . $ticket->id, $this->headers)->assertOk()
            ->assertJsonPath('ticket.mission_id', $first->id)->assertJsonPath('ticket.mission', $first->mission)
            ->assertJsonPath('ticket.reason', 'mission_completed');
        $claim = $this->postJson($this->base . $ticket->id . '/claim', ['worker_id' => 'prueba',
            'expected_message_id' => $last->id], $this->headers)->assertOk()->json();
        $this->postJson($this->base . $ticket->id . '/reply', ['lease_token' => $claim['lease_token'],
            'expected_message_id' => $last->id, 'mission_id' => $first->id, 'operation_id' => 'prueba-a-' . $ticket->id,
            'action' => 'solicitar_confirmacion', 'body' => 'Podés probar si ahora guarda la venta?'], $this->headers)
            ->assertOk()->assertJsonPath('operation.status', 'sent');
        $in = SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text',
            'body' => 'Ya funciona, pude guardar la venta.', 'delivered_at' => now()]);
        $claim = $this->postJson($this->base . $ticket->id . '/claim', ['worker_id' => 'prueba',
            'expected_message_id' => $in->id], $this->headers)->assertOk()->json();
        $this->postJson($this->base . $ticket->id . '/reply', ['lease_token' => $claim['lease_token'],
            'expected_message_id' => $in->id, 'mission_id' => $second->id, 'operation_id' => 'prueba-b-prematura-' . $ticket->id,
            'action' => 'solicitar_confirmacion', 'body' => 'Podés probar el listado nuevo?'], $this->headers)->assertStatus(409);
        $this->postJson($this->base . $ticket->id . '/reply', ['lease_token' => $claim['lease_token'],
            'expected_message_id' => $in->id, 'operation_id' => 'acuse-a-' . $ticket->id,
            'action' => 'responder', 'body' => 'Buenísimo. La corrección del listado sigue en su misión.'], $this->headers)
            ->assertOk()->assertJsonPath('operation.status', 'sent');
        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertSame($second->mission, $case->fresh()->mission);
        $this->assertNull($case->fresh()->delivery_notice_mission_id);
        $this->getJson($this->base . $ticket->id, $this->headers)->assertOk()
            ->assertJsonPath('ticket.mission_id', $second->id)->assertJsonPath('ticket.reason', 'fix_ready_for_upgrade');
    }
}
