<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\SupportCodexCase;
use App\Models\SupportCodexMissionFixEvidence;
use App\Models\SupportCodexOperation;
use App\Models\SupportCodexUpgradeAgreement;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\Version;
use App\Services\ClientScheduleResolver;
use App\Services\EnvSshService;
use App\Services\SupportCodexSettings;
use App\Services\SupportUpgradePreflight;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PreflightUpgradeDeSoporteTest extends TestCase
{
    use DatabaseTransactions;

    public function test_relee_confirmacion_ventana_y_actividad_sin_encolar(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-03T13:00:00-03:00'));
        try {
            [$agreement, $ticket, $api] = $this->acuerdo();
            $schedule = $this->createMock(ClientScheduleResolver::class);
            $schedule->method('primera_ventana_cerrada')->willReturn(['window' => [
                'starts_at' => '2026-11-03T13:00:00-03:00', 'ends_at' => '2026-11-03T16:00:00-03:00',
            ]]);
            $this->app->instance(ClientScheduleResolver::class, $schedule);
            $ssh = $this->createMock(EnvSshService::class);
            $ssh->expects($this->exactly(3))->method('read_upgrade_activity_for')
                ->with($this->callback(function (ClientApi $front) use ($api) {
                    return $front->id === $api->id;
                }), 99009900)
                ->willReturnOnConsecutiveCalls(
                    ['usuarios_activos' => 0, 'ventas_recientes' => 1],
                    $this->throwException(new \RuntimeException('SSH no disponible')),
                    ['usuarios_activos' => 0, 'ventas_recientes' => 0]
                );
            $this->app->instance(EnvSshService::class, $ssh);
            $preflight = app(SupportUpgradePreflight::class);

            $this->assertSame('actividad_reciente', $preflight->inspect($agreement->id)['reason']);
            $this->assertSame('actividad_no_verificada', $preflight->inspect($agreement->id)['reason']);
            $this->assertSame(['ready' => false, 'reason' => 'version_no_vinculada'], $preflight->inspect($agreement->id));

            Carbon::setTestNow(Carbon::parse('2026-11-03T15:00:00-03:00'));
            $this->assertSame('ventana_inactiva_o_sin_margen', $preflight->inspect($agreement->id)['reason']);
            SupportMessage::create([
                'support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text',
                'body' => 'Cambiamos de horario, no lo hagan.', 'whatsapp_message_id' => 'wamid.preflight.cancelado',
                'delivered_at' => now(),
            ]);
            $this->assertSame('confirmacion_obsoleta', $preflight->inspect($agreement->id)['reason']);
            Queue::assertNothingPushed();
        } finally {
            Carbon::setTestNow();
        }
    }

    private function acuerdo(): array
    {
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, '2026-11-01 00:00:00');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AFTER_ID, '0');
        $version = Version::forceCreate(['version' => '90.99.9', 'status' => 'published']);
        $client = Client::create([
            'name' => 'Cliente preflight', 'company_name' => 'Comercio preflight',
            'phone' => '+5493417774321', 'is_active' => true, 'user_id' => 99009900,
            'current_version_id' => $version->id,
        ]);
        $api = ClientApi::create([
            'client_id' => $client->id, 'url' => 'https://api-cliente-preflight.test',
            'path' => 'preflight/api',
        ]);
        $client->update(['active_client_api_id' => $api->id]);
        $ticket = SupportTicket::create([
            'client_id' => $client->id, 'client_user_id' => 0, 'status' => 'open', 'source' => 'whatsapp',
            'whatsapp_phone' => $client->phone, 'opened_at' => now(), 'last_client_message_at' => now(),
            'claude_auto_reply' => true,
        ]);
        $mission = 'Corregir la venta';
        $thread = 'preflight-mision';
        SupportCodexCase::create([
            'support_ticket_id' => $ticket->id, 'state' => 'mission_pending', 'mission' => $mission,
            'mission_thread_id' => $thread, 'mission_started_at' => now()->subDay(),
        ]);
        $evidence = SupportCodexMissionFixEvidence::create([
            'support_ticket_id' => $ticket->id, 'mission_thread_id' => $thread,
            'mission_hash' => hash('sha256', $mission), 'payload' => [], 'payload_hash' => hash('sha256', '[]'),
        ]);
        $outbound = SupportMessage::create([
            'support_ticket_id' => $ticket->id, 'sender_type' => 'admin', 'kind' => 'text',
            'body' => 'Podemos actualizar de 13 a 16.', 'whatsapp_message_id' => 'wamid.preflight.propuesta',
            'delivered_at' => now()->subDay(),
        ]);
        $inbound = SupportMessage::create([
            'support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text',
            'body' => 'Sí', 'reply_to_message_id' => $outbound->id,
            'whatsapp_message_id' => 'wamid.preflight.confirmacion', 'delivered_at' => now()->subDay(),
        ]);
        $operation = SupportCodexOperation::create([
            'support_ticket_id' => $ticket->id, 'expected_message_id' => $inbound->id,
            'operation_id' => 'preflight-propuesta-' . $ticket->id, 'request_hash' => str_repeat('a', 64),
            'action' => 'responder', 'status' => 'sent', 'message_id' => $outbound->id,
        ]);
        return [SupportCodexUpgradeAgreement::create([
            'support_ticket_id' => $ticket->id, 'mission_fix_evidence_id' => $evidence->id,
            'support_codex_operation_id' => $operation->id, 'minimum_minutes' => 120,
            'starts_at_utc' => '2026-11-03 16:00:00', 'ends_at_utc' => '2026-11-03 19:00:00',
            'proposal_body_hash' => hash('sha256', $outbound->body), 'confirmation_phrase' => 'Confirmo la actualización',
            'confirmation_message_id' => $inbound->id, 'accepted_at' => now()->subDay(),
        ]), $ticket, $api];
    }
}
