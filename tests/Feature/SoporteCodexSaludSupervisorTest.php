<?php

namespace Tests\Feature;

use App\Mail\SupportCodexHealthMail;
use App\Models\AdminSetting;
use App\Services\SupportCodexHealthService;
use App\Services\SupportCodexSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SoporteCodexSaludSupervisorTest extends TestCase
{
    use DatabaseTransactions;

    private $headers = ['X-Claude-Task-Key' => 'salud-supervisor-test'];
    private $id = '1847c3f7-8fd0-4ca2-9d74-c94083aa6767';
    private $other = '904ce029-a3b0-4b53-9914-550ec6b40cb3';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00', 'America/Argentina/Buenos_Aires'));
        config(['services.claude_task_ingest.key' => 'salud-supervisor-test']);
        AdminSetting::where('key', SupportCodexHealthService::KEY)->delete();
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function health_request(array $data)
    {
        return $this->postJson('/api/claude/support-codex/health', $data, $this->headers);
    }

    private function monitor(array $data = [])
    {
        return $this->health_request($data)->assertOk();
    }

    private function payload(string $status = 'attempting', array $extra = []): array
    {
        return array_merge(['source' => 'supervisor', 'incident_id' => $this->id,
            'recovery_state' => $status, 'reason_code' => 'monitor_stale', 'attempt' => 1], $extra);
    }

    private function supervisor(string $status = 'attempting', array $extra = [])
    {
        return $this->health_request($this->payload($status, $extra))->assertOk();
    }

    private function state(): array
    {
        return json_decode(AdminSetting::get(SupportCodexHealthService::KEY), true);
    }

    private function advance(int $seconds): void
    {
        Carbon::setTestNow(now()->addSeconds($seconds));
    }

    private function check(): void
    {
        $this->artisan('support:check-codex-health')->assertExitCode(0);
    }

    public function test_capacidad_explicita_no_activa_protocolo_ni_cambia_legacy(): void
    {
        $this->monitor()->assertJson(['accepted' => true, 'capabilities' => ['supervisor_recovery_v1' => true]]);
        $this->assertArrayNotHasKey('supervisor_incident', $this->state());
        $this->advance(300);
        $this->check();
        Mail::assertSent(SupportCodexHealthMail::class, function ($mail) { return $mail->health['reason'] === 'sin_pulso'; });
    }

    public function test_supervisor_exige_clave_y_payload_cerrado_sin_escribir_estado(): void
    {
        $this->postJson('/api/claude/support-codex/health', $this->payload())->assertStatus(401);
        foreach (['incident_id', 'recovery_state', 'reason_code', 'attempt'] as $field) {
            $data = $this->payload();
            unset($data[$field]);
            $this->health_request($data)->assertStatus(422);
        }
        foreach (['incident_id' => 'no-uuid', 'recovery_state' => 'idle', 'reason_code' => 'texto privado',
            'attempt' => 101, 'source' => 'otro'] as $field => $value) {
            $this->health_request($this->payload('attempting', [$field => $value]))->assertStatus(422);
        }
        $this->assertNull(AdminSetting::get(SupportCodexHealthService::KEY));
    }

    public function test_supervisor_no_altera_salud_ni_version_del_monitor(): void
    {
        $this->monitor(['status' => 'error', 'error_code' => 'app_error', 'monitor_version' => '2']);
        $before = $this->state();
        $this->advance(30);
        $this->supervisor('attempting', ['status' => 'ok', 'monitor_version' => '999']);
        $after = $this->state();
        foreach (['last_seen_at', 'status', 'error_code', 'monitor_version', 'first_error_at', 'monitor_sequence'] as $key) {
            $this->assertSame($before[$key], $after[$key]);
        }
        $this->assertArrayNotHasKey('last_success_at', $after);
        $this->assertSame(now()->getTimestamp(), $after['last_supervisor_seen_at']);
    }

    public function test_intentos_repetidos_no_renuevan_plazo_y_vencimiento_es_no_verificable(): void
    {
        $this->monitor(['status' => 'error']);
        $this->supervisor();
        $started = $this->state()['supervisor_incident']['started_at'];
        $this->advance(899);
        $this->supervisor('attempting', ['attempt' => 2]);
        $this->check();
        Mail::assertNothingSent();
        $this->assertSame($started, $this->state()['supervisor_incident']['started_at']);
        $this->advance(1);
        $this->check();
        Mail::assertSent(SupportCodexHealthMail::class, function ($mail) {
            return $mail->health['reason'] === 'recovery_unverified';
        });
    }

    public function test_failed_antes_de_cinco_minutos_avisa_una_vez_con_ok_viejo(): void
    {
        $this->monitor();
        $this->supervisor();
        $this->advance(180);
        $this->supervisor('failed', ['reason_code' => 'drain_timeout']);
        $this->check();
        $this->check();
        Mail::assertSent(SupportCodexHealthMail::class, 1);
        Mail::assertSent(SupportCodexHealthMail::class, function ($mail) {
            return $mail->health['reason'] === 'recovery_failed' && $mail->health['recovery_reason'] === 'drain_timeout';
        });
        $this->advance(21600);
        $this->check();
        Mail::assertSent(SupportCodexHealthMail::class, 2);
    }

    public function test_recovered_sin_pulso_posterior_no_falsea_salud(): void
    {
        $this->monitor();
        $this->supervisor();
        $this->advance(60);
        $this->supervisor('recovered', ['reason_code' => 'monitor_recovered']);
        $this->assertNotNull($this->state()['supervisor_incident']);
        $this->assertSame(1, $this->state()['monitor_sequence']);
        $this->advance(840);
        $this->check();
        Mail::assertSent(SupportCodexHealthMail::class, function ($mail) { return $mail->health['reason'] === 'recovery_unverified'; });
    }

    public function test_pulso_sano_posterior_cierra_en_el_mismo_segundo_y_no_manda_correo(): void
    {
        $this->monitor(['status' => 'error']);
        $this->supervisor();
        $this->monitor();
        $this->assertNull($this->state()['supervisor_incident']);
        $this->assertDatabaseHas('admin_settings', ['key' => SupportCodexHealthService::CLOSED_KEY_PREFIX . $this->id, 'value' => '1']);
        $this->supervisor('failed');
        $this->check();
        Mail::assertNothingSent();
    }

    public function test_no_reabre_uuid_cerrado_ni_reemplaza_incidente_activo(): void
    {
        $this->monitor(['status' => 'error']);
        $this->supervisor();
        $before = $this->state();
        $this->advance(100);
        $this->health_request($this->payload('attempting', ['incident_id' => $this->other]))->assertStatus(409);
        $this->assertSame($before, $this->state());
        $this->monitor();
        $this->supervisor('attempting', ['incident_id' => $this->other]);
        $before = $this->state();
        foreach (['attempting', 'failed', 'recovered'] as $status) {
            $this->supervisor($status);
            $this->assertSame($before, $this->state());
        }
    }

    public function test_resultado_desconocido_no_crea_incidente(): void
    {
        foreach (['failed', 'recovered'] as $status) {
            $this->health_request($this->payload($status))->assertStatus(409);
            $this->assertNull(AdminSetting::get(SupportCodexHealthService::KEY));
        }
    }

    public function test_intento_viejo_y_attempting_tardio_no_revierten_fallo(): void
    {
        $this->monitor(['status' => 'error']);
        $this->supervisor('attempting', ['attempt' => 2]);
        $this->supervisor('failed', ['attempt' => 1]);
        $this->assertSame('attempting', $this->state()['supervisor_incident']['state']);
        $this->supervisor('failed', ['attempt' => 2]);
        $this->supervisor('attempting', ['attempt' => 3]);
        $this->assertSame('failed', $this->state()['supervisor_incident']['state']);
        $this->check();
        Mail::assertSent(SupportCodexHealthMail::class, 1);
    }

    public function test_supervisor_sin_monitor_no_inventa_pulso_pero_alarma_al_vencer(): void
    {
        $this->supervisor();
        $this->assertArrayNotHasKey('last_seen_at', $this->state());
        $this->assertArrayNotHasKey('armed_at', $this->state());
        $this->advance(900);
        $this->check();
        Mail::assertSent(SupportCodexHealthMail::class, function ($mail) { return $mail->health['reason'] === 'recovery_unverified'; });
    }

    public function test_recuperacion_envia_una_vez_y_nuevo_fallo_no_hereda_deduplicacion(): void
    {
        $this->monitor(['status' => 'error']);
        $this->supervisor();
        $this->supervisor('failed');
        $this->check();
        $this->monitor();
        $this->check();
        $this->check();
        Mail::assertSent(SupportCodexHealthMail::class, 2);
        $this->supervisor('attempting', ['incident_id' => $this->other]);
        $this->supervisor('failed', ['incident_id' => $this->other]);
        $this->check();
        Mail::assertSent(SupportCodexHealthMail::class, 3);
    }

    public function test_futura_caida_sin_supervisor_conserva_respaldo_legacy(): void
    {
        $this->monitor(['status' => 'error']);
        $this->supervisor();
        $this->monitor();
        $this->advance(300);
        $this->check();
        Mail::assertSent(SupportCodexHealthMail::class, function ($mail) { return $mail->health['reason'] === 'sin_pulso'; });
    }

    public function test_pausa_diaria_y_gracia_matinal_tambien_cubren_supervisor(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-07 23:59:00', 'America/Argentina/Buenos_Aires'));
        $this->monitor(['status' => 'error']);
        $this->supervisor();
        $this->advance(60);
        $this->supervisor('failed');
        $this->check();
        Mail::assertNothingSent();
        Carbon::setTestNow(Carbon::parse('2026-11-08 07:04:59', 'America/Argentina/Buenos_Aires'));
        $this->check();
        Mail::assertNothingSent();
        $this->advance(1);
        $this->check();
        Mail::assertSent(SupportCodexHealthMail::class, 1);
    }

    public function test_attempting_nocturno_tiene_presupuesto_hasta_0715_sin_renovarlo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-07 23:59:00', 'America/Argentina/Buenos_Aires'));
        $this->monitor(['status' => 'error']);
        $this->supervisor();
        $started = $this->state()['supervisor_incident']['started_at'];
        foreach (['2026-11-08 00:00:00', '2026-11-08 07:05:00', '2026-11-08 07:14:59'] as $time) {
            Carbon::setTestNow(Carbon::parse($time, 'America/Argentina/Buenos_Aires'));
            $this->supervisor('attempting', ['attempt' => 2]);
            $this->check();
            Mail::assertNothingSent();
        }
        $this->assertSame($started, $this->state()['supervisor_incident']['started_at']);
        $this->advance(1);
        $this->check();
        Mail::assertSent(SupportCodexHealthMail::class, function ($mail) { return $mail->health['reason'] === 'recovery_unverified'; });
    }

    public function test_muchos_uuid_cerrados_no_agrandan_salud_y_replay_antiguo_sigue_bloqueado(): void
    {
        $rows = [];
        for ($i = 0; $i < 1800; $i++) {
            $id = sprintf('%08x-8fd0-4ca2-9d74-c94083aa6767', $i);
            $rows[] = ['key' => SupportCodexHealthService::CLOSED_KEY_PREFIX . $id,
                'value' => '1', 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('admin_settings')->insert($chunk);
        }
        $this->monitor(['status' => 'error']);
        $this->supervisor();
        $this->monitor();
        $before = $this->state();
        $this->assertArrayNotHasKey('closed_supervisor_incidents', $before);
        $this->assertLessThan(2048, strlen(AdminSetting::get(SupportCodexHealthService::KEY)));
        $this->assertDatabaseHas('admin_settings', ['key' => SupportCodexHealthService::CLOSED_KEY_PREFIX . $this->id]);
        foreach (['attempting', 'failed', 'recovered'] as $status) {
            $this->supervisor($status, ['incident_id' => '00000000-8fd0-4ca2-9d74-c94083aa6767']);
            $this->assertSame($before, $this->state());
        }
        $this->check();
        Mail::assertNothingSent();
    }

    public function test_correo_reintenta_smtp_sin_consumir_fallo_supervisado(): void
    {
        $this->supervisor();
        $this->supervisor('failed');
        Mail::swap(new class {
            public function mailer($name) { return $this; }
            public function to($recipient) { return $this; }
            public function send($mail) { throw new \RuntimeException('fallo simulado'); }
        });
        $this->check();
        $this->assertArrayNotHasKey('alert_sent_at', $this->state());
        $this->assertNotNull($this->state()['mail_error_at']);
        Mail::fake();
        $this->check();
        Mail::assertSent(SupportCodexHealthMail::class, 1);
    }

    public function test_plantillas_distinguen_resultado_y_no_inventan_duracion(): void
    {
        $failed = view('emails.commerciocity.support-monitor-health', ['kind' => 'failure',
            'health' => ['reason' => 'recovery_failed', 'recovery_reason' => 'drain_timeout']])->render();
        $this->assertStringContainsString('intentó recuperar', $failed);
        $this->assertStringNotContainsString('cinco minutos', $failed);
        $unknown = view('emails.commerciocity.support-monitor-health', ['kind' => 'failure',
            'health' => ['reason' => 'recovery_unverified']])->render();
        $this->assertStringContainsString('no afirma que la reparación haya fallado', $unknown);
    }
}
