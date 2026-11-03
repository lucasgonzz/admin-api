<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientScheduleDay;
use App\Models\ClientScheduleRange;
use App\Models\ClientVersionUpgrade;
use App\Models\SupportCodexCase;
use App\Models\SupportCodexMissionFixEvidence;
use App\Models\SupportCodexOperation;
use App\Models\SupportCodexReleaseClaim;
use App\Models\SupportCodexScheduledUpgrade;
use App\Models\SupportCodexUpgradeAgreement;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\Version;
use App\Services\ClientScheduleResolver;
use App\Services\ClientEmpresaApiUrlResolver;
use App\Services\EnvSshService;
use App\Services\SupportCodexSettings;
use App\Services\SupportUpgradePreflight;
use App\Services\SupportScheduledUpgradeService;
use App\Jobs\RunDeploymentJob;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PreflightUpgradeDeSoporteTest extends TestCase
{
    use DatabaseTransactions;

    public function test_separa_pipeline_salud_y_reproduccion_antes_de_pedir_confirmacion(): void
    {
        Queue::fake();
        config(['services.support_codex_scheduled_upgrades.enabled' => true]);
        config(['services.claude_task_ingest.key' => 'prueba-upgrade-programado']);
        Carbon::setTestNow(Carbon::parse('2026-11-03T13:00:00-03:00'));
        try {
            [$agreement, $ticket, $api] = $this->acuerdo();
            $api->update(['spa_url' => 'https://spa-cliente-preflight.test']);
            $old = $ticket->client->current_version_id;
            $version = Version::forceCreate(['version' => '90.99.10', 'status' => 'published']);
            $upgrade = ClientVersionUpgrade::forceCreate([
                'client_id' => $ticket->client_id, 'from_version_id' => $old, 'to_version_id' => $version->id,
                'target_client_api_id' => $api->id, 'status' => 'pendiente', 'scheduled_date' => now()->toDateString(),
                'deployment_status' => 'paused', 'crons_supervisor_at' => now(),
            ]);
            $row = SupportCodexScheduledUpgrade::create([
                'agreement_id' => $agreement->id, 'support_ticket_id' => $ticket->id, 'client_id' => $ticket->client_id,
                'client_api_id' => $api->id, 'from_version_id' => $old, 'version_id' => $version->id, 'version' => $version->version,
                'upgrade_id' => $upgrade->id, 'status' => 'starting', 'artifacts' => [],
                'starts_at_utc' => $agreement->starts_at_utc, 'ends_at_utc' => $agreement->ends_at_utc, 'live_touched_at' => now(),
            ]);
            $service = app(SupportScheduledUpgradeService::class);
            $service->tick();
            $this->assertSame('post_closure', $row->fresh()->status);
            $upgrade->update(['deployment_status' => 'paused_post_tasks']);
            $service->tick();
            $this->assertSame('configuring', $row->fresh()->status);
            Queue::assertPushed(RunDeploymentJob::class, 2);

            $api_url = rtrim((new ClientEmpresaApiUrlResolver())->build_api_url_for_env($api), '/');
            Http::fake([
                $api_url . '/api/user' => Http::response(['message' => 'Unauthenticated.'], 401),
                'https://spa-cliente-preflight.test/config.js' => Http::response('window.__CC_CONFIG__ = ' . json_encode([
                    'VUE_APP_API_URL' => $api_url, 'VUE_APP_APP_URL' => $api->spa_url,
                ]) . ';'),
                'https://spa-cliente-preflight.test/' => Http::response('<div id="app"></div>'),
            ]);
            $upgrade->update([
                'deployment_status' => 'completed', 'status' => 'terminada',
                'default_version_sync_status' => 'success', 'sistema_configurado_at' => now(),
            ]);
            $ticket->client->update(['current_version_id' => $version->id]);
            $service->tick();
            $this->assertSame('verification_pending', $row->fresh()->status);
            $this->assertSame('mission_pending', SupportCodexCase::where('support_ticket_id', $ticket->id)->first()->state);

            $headers = ['X-Claude-Task-Key' => 'prueba-upgrade-programado'];
            $route = '/api/claude/support-codex/tickets/' . $ticket->id . '/verify-scheduled-upgrade';
            $input = [
                'agreement_id' => $agreement->id, 'upgrade_id' => $upgrade->id,
                'mission_thread_id' => 'preflight-mision', 'mission_hash' => hash('sha256', 'Corregir la venta'),
                'resultado' => 'inconcluso', 'evidencia' => 'El escenario todavía no fue comprobado.',
            ];
            $this->postJson($route, array_merge($input, ['mission_thread_id' => 'otro-hilo']), $headers)->assertStatus(409);
            $this->postJson($route, $input, $headers)->assertOk()->assertJsonPath('scheduled_upgrade.status', 'verification_pending');
            $this->postJson($route, $input + ['motivo' => 'verificacion_sin_resultado'], $headers)
                ->assertOk()->assertJsonPath('scheduled_upgrade.reason', 'reproduccion_inconclusa');
            $input['resultado'] = 'verificado';
            $input['evidencia'] = 'El escenario de solo lectura reproduce el resultado esperado.';
            $this->postJson($route, $input, $headers)->assertOk()->assertJsonPath('scheduled_upgrade.status', 'verified');
            $service->tick();
            $this->assertNotNull($row->fresh()->notification_requested_at);
            $this->assertSame('ready_to_confirm', SupportCodexCase::where('support_ticket_id', $ticket->id)->first()->state);
            $this->assertSame('open', $ticket->fresh()->status);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_revocacion_despues_de_tocar_el_sistema_frena_configuracion_para_conciliar(): void
    {
        config(['services.support_codex_scheduled_upgrades.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-11-03T13:20:00-03:00'));
        try {
            [$agreement, $ticket, $api] = $this->acuerdo();
            $client = $ticket->client;
            $row = SupportCodexScheduledUpgrade::create([
                'agreement_id' => $agreement->id, 'support_ticket_id' => $ticket->id, 'client_id' => $client->id,
                'client_api_id' => $api->id, 'from_version_id' => $client->current_version_id,
                'version_id' => $client->current_version_id, 'version' => $client->current_version->version,
                'status' => 'configuring', 'artifacts' => [], 'live_touched_at' => now()->subMinutes(10),
                'starts_at_utc' => $agreement->starts_at_utc, 'ends_at_utc' => $agreement->ends_at_utc,
            ]);
            SupportMessage::create([
                'support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text',
                'body' => 'Volvimos a abrir.', 'whatsapp_message_id' => 'wamid.preflight.revocado',
                'delivered_at' => now(),
            ]);
            $ssh = $this->createMock(EnvSshService::class);
            $ssh->expects($this->never())->method('read_upgrade_activity_for');
            $this->app->instance(EnvSshService::class, $ssh);
            try {
                app(SupportScheduledUpgradeService::class)->before_continuation((int) $row->id, 'update_default_version');
                $this->fail('Configuró el sistema sin autorización vigente.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('conciliación', $error->getMessage());
            }
            $this->assertSame('failed', $row->fresh()->status);
            $this->assertSame('reconciliar_confirmacion_obsoleta', $row->fresh()->reason);
            $this->assertNotNull($row->fresh()->live_touched_at);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_programa_una_vez_y_revalida_antes_de_tocar_el_frente(): void
    {
        Queue::fake();
        config(['services.support_codex_scheduled_upgrades.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-11-03T12:00:00-03:00'));
        try {
            [$agreement, $ticket] = $this->acuerdo();
            $version = Version::forceCreate(['version' => '90.99.10', 'status' => 'published']);
            $schedule = $this->createMock(ClientScheduleResolver::class);
            $schedule->method('primera_ventana_cerrada')->willReturnCallback(function ($client, $from) {
                return ['window' => ['starts_at' => $from->toIso8601String(), 'ends_at' => '2026-11-03T16:00:00-03:00']];
            });
            $this->app->instance(ClientScheduleResolver::class, $schedule);
            $ssh = $this->createMock(EnvSshService::class);
            $ssh->expects($this->once())->method('read_upgrade_activity_for')
                ->willReturn(['usuarios_activos' => 0, 'ventas_recientes' => 0]);
            $this->app->instance(EnvSshService::class, $ssh);
            config(['services.claude_task_ingest.key' => 'prueba-upgrade-programado']);
            $headers = ['X-Claude-Task-Key' => 'prueba-upgrade-programado'];
            $route = '/api/claude/support-codex/tickets/' . $ticket->id . '/';
            $input = ['agreement_id' => $agreement->id, 'version_id' => $version->id];
            $this->postJson($route . 'schedule-upgrade', $input, $headers)->assertStatus(409);
            $release = [
                'mission_fix_evidence_id' => $agreement->mission_fix_evidence_id,
                'version_id' => $version->id, 'version' => $version->version, 'published_at' => now(),
                'develop' => [], 'proof' => ['artifacts' => [
                    'empresa-api' => ['id' => 100, 'size' => 1000, 'sha256' => str_repeat('a', 64), 'name' => 'empresa-api-v90.99.10.zip'],
                    'empresa-spa' => ['id' => 101, 'size' => 1100, 'sha256' => str_repeat('b', 64), 'name' => 'empresa-spa-v90.99.10-dist.zip'],
                ]],
            ];
            SupportCodexReleaseClaim::create(['agreement_id' => $agreement->id + 1000000] + $release);
            $this->postJson($route . 'schedule-upgrade', $input, $headers)->assertStatus(409);
            SupportCodexReleaseClaim::create(['agreement_id' => $agreement->id] + $release);
            $created = $this->postJson($route . 'schedule-upgrade', $input, $headers)->assertCreated()
                ->assertJsonPath('scheduled_upgrade.status', 'scheduled')->json('scheduled_upgrade');
            $this->postJson($route . 'schedule-upgrade', $input, $headers)->assertOk()
                ->assertJsonPath('scheduled_upgrade.id', $created['id']);
            $service = app(SupportScheduledUpgradeService::class);
            $service->tick();
            Queue::assertNothingPushed();

            Carbon::setTestNow(Carbon::parse('2026-11-03T13:00:00-03:00'));
            $service->tick();
            $service->tick();
            Queue::assertPushed(RunDeploymentJob::class, 1);
            $row = SupportCodexScheduledUpgrade::findOrFail($created['id']);
            $this->assertSame('starting', $row->status);
            $this->assertSame($version->id, (int) $row->upgrade->to_version_id);
            $this->assertNull($row->live_touched_at);
            $this->getJson($route . 'scheduled-upgrade', $headers)->assertOk()
                ->assertJsonPath('scheduled_upgrade.status', 'starting');
            $this->postJson('/api/claude/upgrades/' . $row->upgrade_id . '/deploy/start', [
                'confirm_client_name' => $ticket->client->name,
            ], $headers)->assertStatus(409);
            $this->assertSame('running', $row->upgrade->fresh()->deployment_status);
            Queue::assertPushed(RunDeploymentJob::class, 1);

            Carbon::setTestNow(Carbon::parse('2026-11-03T15:30:00-03:00'));
            try {
                $service->before_live_write((int) $row->id);
                $this->fail('El worker avanzó con la ventana vencida.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('antes de tocar producción', $error->getMessage());
            }
            $this->assertSame('blocked', $row->fresh()->status);
            $this->assertNull($row->fresh()->live_touched_at);
        } finally {
            Carbon::setTestNow();
        }
    }

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

            Carbon::setTestNow(Carbon::parse('2026-11-03T13:06:00-03:00'));
            $this->assertSame('hora_acordada_vencida', $preflight->inspect($agreement->id)['reason']);
            Carbon::setTestNow(Carbon::parse('2026-11-03T15:30:00-03:00'));
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
        $day = ClientScheduleDay::forceCreate(['client_id' => $client->id, 'day_key' => 'todos']);
        foreach ([['08:00', '13:00'], ['16:00', '21:00']] as $order => [$start, $end]) {
            ClientScheduleRange::forceCreate([
                'client_schedule_day_id' => $day->id, 'start_time' => $start, 'end_time' => $end, 'sort_order' => $order,
            ]);
        }
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
