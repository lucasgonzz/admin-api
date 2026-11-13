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

    public function test_corte_de_sistema_usa_actividad_desde_inicio_y_audita_force_solo_del_horario(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-03T13:00:45-03:00'));
        config(['services.support_codex_scheduled_upgrades.enabled' => true]);
        try {
            [$agreement, $ticket, $api] = $this->acuerdo();
            $agreement->update(['maintenance_mode' => 'coordinated', 'target' => 'sistema', 'minimum_minutes' => 15, 'ends_at_utc' => '2026-11-03 16:15:00']);
            $evidence = SupportCodexMissionFixEvidence::findOrFail($agreement->mission_fix_evidence_id);
            $evidence->update(['payload' => ['commits' => ['empresa-api' => str_repeat('a', 40)]]]);
            $new = Version::forceCreate(['version' => '90.99.10', 'status' => 'published']);
            $row = SupportCodexScheduledUpgrade::create(['agreement_id' => $agreement->id, 'support_ticket_id' => $ticket->id,
                'client_id' => $ticket->client_id, 'client_api_id' => $api->id, 'target' => 'sistema',
                'from_version_id' => $ticket->client->current_version_id, 'version_id' => $new->id, 'version' => $new->version,
                'status' => 'starting', 'artifacts' => [], 'starts_at_utc' => $agreement->starts_at_utc, 'ends_at_utc' => $agreement->ends_at_utc]);
            $ssh = $this->createMock(EnvSshService::class);
            $ssh->expects($this->exactly(2))->method('read_coordinated_upgrade_activity_for')->with($this->anything(), 99009900, Carbon::parse('2026-11-03 16:00:00', 'UTC')->timestamp)
                ->willReturnOnConsecutiveCalls(['usuarios_activos' => 0, 'ventas_recientes' => 0, 'trabajos_pendientes' => 1], ['usuarios_activos' => 0, 'ventas_recientes' => 0, 'trabajos_pendientes' => 0]);
            $this->app->instance(EnvSshService::class, $ssh);
            $this->assertSame('actividad_reciente', app(SupportUpgradePreflight::class)->inspect($agreement->id, $row)['reason']);
            \Illuminate\Support\Facades\Log::shouldReceive('notice')->once()->with('Mantenimiento coordinado de sistema con force de horario.', \Mockery::on(function ($context) use ($row, $agreement) {
                return $context['force'] === true && $context['schedule_id'] === $row->id
                    && strpos($context['force_reason'], (string) $agreement->id) !== false;
            }));
            app(SupportScheduledUpgradeService::class)->before_live_write($row->id);
            $this->assertNotNull($row->fresh()->live_touched_at);
        } finally { Carbon::setTestNow(); }
    }

    /** @dataProvider resultados_de_restauracion */
    public function test_tienda_interrumpida_recupera_o_escala_sin_anunciar_resolucion(bool $restore_fails): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-03T13:16:00-03:00'));
        try {
                [$row, $installation, $ticket] = $this->corrida_tienda();
                $installation->update(['status' => 'completada']);
                Http::fake(['*' => Http::response('No disponible', 503)]);
                $deployment = \Mockery::mock(\App\Services\SupportEcommerceDeploymentService::class);
                $expect = $deployment->shouldReceive('recover')->once();
                if ($restore_fails) { $expect->andThrow(new \RuntimeException('Respaldo no verificable')); }
                else { $expect->andReturnUsing(function () use ($row) { $row->update(['status' => 'failed', 'reason' => 'tienda_restaurada_tras_fallo']); }); }
                $this->app->bind(\App\Services\SupportEcommerceDeploymentService::class, function () use ($deployment) { return $deployment; });
                app(\App\Services\SupportStoreUpgradeService::class)->advance($row);
                $this->assertSame('failed', $row->fresh()->status);
                $this->assertSame($restore_fails ? 'restauracion_tienda_sin_confirmar' : 'tienda_restaurada_tras_fallo', $row->fresh()->reason);
                $this->assertSame('manual_review', SupportCodexCase::where('support_ticket_id', $ticket->id)->firstOrFail()->state);
                $this->assertNotNull($ticket->fresh()->escalated_at);
                $this->assertNull($row->fresh()->notification_requested_at);
        } finally { Carbon::setTestNow(); }
    }

    public function resultados_de_restauracion(): array
    {
        return [[false], [true]];
    }

    public function test_tienda_con_trabajador_vivo_no_se_restaura_por_encima(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-03T13:16:00-03:00'));
        try {
            [$row, $installation, $ticket] = $this->corrida_tienda();
            $lock = \Illuminate\Support\Facades\Cache::lock('client-deployment-' . $row->client_id, 1860);
            $this->assertTrue($lock->get());
            try {
                app(\App\Services\SupportStoreUpgradeService::class)->advance($row);
                $this->assertSame('starting', $row->fresh()->status);
                $this->assertSame('reconciliar_tienda_en_ejecucion', $row->fresh()->reason);
                $this->assertSame('instalando', $installation->fresh()->status);
                $this->assertNotNull($ticket->fresh()->escalated_at);
            } finally { $lock->release(); }
        } finally { Carbon::setTestNow(); }
    }

    public function test_tienda_rechaza_zip_alterado_y_actividad_o_cola_sin_escrituras(): void
    {
        [$row, $installation] = $this->corrida_tienda();
        $service = new TiendaDeSoporteSinSsh($installation, $row);
        $file = tempnam(sys_get_temp_dir(), 'cc-store-');
        file_put_contents($file, 'ZIP de prueba');
        try {
            $row->update(['artifacts' => ['tienda-api' => ['id' => 123, 'size' => filesize($file), 'sha256' => hash_file('sha256', $file)]]]);
            $service = new TiendaDeSoporteSinSsh($installation, $row->fresh());
            $check = new \ReflectionMethod($service, 'assert_support_store_artifact'); $check->setAccessible(true);
            $check->invoke($service, 'tienda-api', ['url' => 'https://api.github.test/assets/123'], $file);
            file_put_contents($file, 'ZIP alterado');
            try { $check->invoke($service, 'tienda-api', ['url' => 'https://api.github.test/assets/123'], $file); $this->fail('Aceptó un ZIP alterado'); }
            catch (\RuntimeException $error) { $this->assertStringContainsString('artefacto', $error->getMessage()); }
            $activity = new \ReflectionMethod($service, 'assert_store_idle'); $activity->setAccessible(true);
            foreach ([['pedidos_recientes' => 1, 'carritos_recientes' => 0, 'trabajos_pendientes' => 0], ['pedidos_recientes' => 0, 'carritos_recientes' => 0, 'trabajos_pendientes' => 1]] as $counts) {
                $service->responses['support_activity'] = json_encode($counts);
                try { $activity->invoke($service); $this->fail('Aceptó actividad de tienda'); }
                catch (\RuntimeException $error) { $this->assertStringContainsString('actividad', $error->getMessage()); }
            }
            $row->update(['live_touched_at' => null, 'backup_path' => null, 'backup_sha256' => null, 'backup_size' => null]);
            $service->responses['support_backup'] = 'respaldo incompleto';
            $backup = new \ReflectionMethod($service, 'backup'); $backup->setAccessible(true);
            try { $backup->invoke($service); $this->fail('Aceptó respaldo incompleto'); }
            catch (\RuntimeException $error) { $this->assertStringContainsString('respaldo', $error->getMessage()); }
            $this->assertNull($row->fresh()->live_touched_at);
            $this->assertNull($row->fresh()->backup_path);
            $this->assertSame(['support_activity', 'support_activity', 'support_backup'], array_column($service->commands, 0));
        } finally { unlink($file); }
    }

    public function test_respaldo_privado_de_tienda_se_verifica_y_restaura_con_identidad_exacta(): void
    {
        [$row, $installation] = $this->corrida_tienda();
        $row->update(['live_touched_at' => null, 'backup_path' => null, 'backup_sha256' => null, 'backup_size' => null]);
        $service = new TiendaDeSoporteSinSsh($installation, $row);
        $path = '.cc-support-backups/tienda-' . $row->id . '-' . $installation->uuid . '.tar.gz';
        $service->responses['support_backup'] = str_repeat('a', 64) . '  ' . $path . "\n123\n";
        $backup = new \ReflectionMethod($service, 'backup'); $backup->setAccessible(true);
        $backup->invoke($service);
        $this->assertSame($path, $row->fresh()->backup_path);
        $this->assertSame(str_repeat('a', 64), $row->fresh()->backup_sha256);
        $this->assertSame(123, (int) $row->fresh()->backup_size);
        $this->assertStringContainsString('chmod 600', $service->commands[0][1]);
        $this->assertStringContainsString('tar -tzf', $service->commands[0][1]);
        $row->update(['live_touched_at' => now()]);
        $service->recover();
        $restore = $service->commands[1][1];
        foreach (['sha256sum', 'stat -c %s', ' && mv -- ', ' && tar -xzf '] as $check) { $this->assertStringContainsString($check, $restore); }
        $this->assertSame('tienda_restaurada_tras_fallo', $row->fresh()->reason);
        $this->assertSame('fallida', $installation->fresh()->status);
        $store = $installation->client_ecommerce->fresh();
        $this->assertSame('active', $store->status);
        $this->assertSame((int) $row->from_version_id, (int) $store->ecommerce_version_id);
    }

    private function corrida_tienda(): array
    {
        [$agreement, $ticket, $api] = $this->acuerdo();
        $old = \App\Models\EcommerceVersion::create(['version' => '96.0.' . $ticket->id, 'status' => 'published']);
        $new = \App\Models\EcommerceVersion::create(['version' => '96.1.' . $ticket->id, 'status' => 'published']);
        $store = \App\Models\ClientEcommerce::create(['client_id' => $ticket->client_id, 'domain' => 'tienda-' . $ticket->id . '.test',
            'spa_url' => 'https://tienda-' . $ticket->id . '.test', 'api_url' => 'https://tienda-' . $ticket->id . '.test/api',
            'status' => 'active', 'ecommerce_version_id' => $new->id]);
        $agreement->update(['target' => 'tienda', 'maintenance_mode' => 'coordinated', 'minimum_minutes' => 15,
            'client_ecommerce_id' => $store->id, 'store_identity_hash' => \App\Services\SupportMaintenancePolicy::store_hash($store),
            'ends_at_utc' => '2026-11-03 16:15:00']);
        $installation = $store->installations()->create(['mode' => 'update', 'status' => 'instalando', 'ecommerce_version_id' => $new->id]);
        $row = SupportCodexScheduledUpgrade::create(['agreement_id' => $agreement->id, 'support_ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id, 'client_api_id' => $api->id, 'target' => 'tienda', 'client_ecommerce_id' => $store->id,
            'ecommerce_installation_id' => $installation->id, 'from_version_id' => $old->id, 'version_id' => $new->id, 'version' => $new->version,
            'status' => 'starting', 'artifacts' => [], 'starts_at_utc' => $agreement->starts_at_utc, 'ends_at_utc' => $agreement->ends_at_utc,
            'live_touched_at' => now()->subMinute(), 'backup_path' => '.cc-support-backups/prueba.tar.gz', 'backup_sha256' => str_repeat('a', 64), 'backup_size' => 123]);
        return [$row, $installation, $ticket];
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

/** Los comandos se capturan: estas pruebas nunca abren una conexión SSH. */
class TiendaDeSoporteSinSsh extends \App\Services\SupportEcommerceDeploymentService
{
    public $responses = [];
    public $commands = [];
    protected function reconnect_hosting_ssh(): void {}
    protected function exec_hosting_ssh(string $step, string $command, bool $must_succeed = true, bool $long_running = false): string
    {
        $this->commands[] = [$step, $command];
        return ($this->responses[$step] ?? '') . "\nCC_SUPPORT_OK\n";
    }
}
