<?php

namespace Tests\Feature;

use App\Mail\SupportCodexHealthMail;
use App\Models\AdminSetting;
use App\Services\SupportCodexHealthService;
use App\Services\SupportCodexSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SoporteCodexSaludTest extends TestCase
{
    use DatabaseTransactions;
    private $headers = ['X-Claude-Task-Key' => 'salud-test'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00:00'));
        config(['services.claude_task_ingest.key' => 'salud-test']);
        AdminSetting::where('key', SupportCodexHealthService::KEY)->delete();
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pulse(array $data = [])
    {
        return $this->postJson('/api/claude/support-codex/health', $data, $this->headers)->assertOk();
    }

    private function advance(int $seconds): void
    {
        Carbon::setTestNow(now()->addSeconds($seconds));
    }

    public function test_health_exige_clave_y_rechaza_diagnosticos_arbitrarios(): void
    {
        $this->postJson('/api/claude/support-codex/health', [])->assertStatus(401);
        $this->postJson('/api/claude/support-codex/health', ['status' => 'error', 'error_code' => 'datos privados'], $this->headers)->assertStatus(422);
        $this->postJson('/api/claude/support-codex/health', ['monitor_version' => 'version con espacios'], $this->headers)->assertStatus(422);
        $this->assertNull(AdminSetting::get(SupportCodexHealthService::KEY));
    }

    public function test_no_arma_hasta_el_primer_pulso_y_admite_payload_vacio(): void
    {
        $this->artisan('support:check-codex-health')->assertExitCode(0);
        Mail::assertNothingSent();
        $this->pulse()->assertJson(['accepted' => true]);
        $this->advance(299);
        $this->artisan('support:check-codex-health');
        Mail::assertNothingSent();
        $this->advance(1);
        $this->artisan('support:check-codex-health');
        Mail::assertSent(SupportCodexHealthMail::class, function ($mail) {
            return $mail->kind === 'failure' && $mail->hasTo(SupportCodexHealthService::RECIPIENT) && $mail->health['reason'] === 'sin_pulso';
        });
    }

    public function test_error_con_pulsos_presentes_no_resetea_el_plazo_y_recuerda_cada_seis_horas(): void
    {
        $this->pulse(['status' => 'error', 'error_code' => 'app_error', 'monitor_version' => '2']);
        $initial = json_decode(AdminSetting::get(SupportCodexHealthService::KEY), true);
        for ($i = 0; $i < 5; $i++) {
            $this->advance(60);
            $this->pulse(['status' => 'error', 'error_code' => 'app_error']);
        }
        $this->artisan('support:check-codex-health');
        Mail::assertSent(SupportCodexHealthMail::class, function ($mail) { return $mail->kind === 'failure' && $mail->health['reason'] === 'app_error'; });
        $this->artisan('support:check-codex-health');
        Mail::assertSent(SupportCodexHealthMail::class, 1);
        $state = json_decode(AdminSetting::get(SupportCodexHealthService::KEY), true);
        $this->assertSame($initial['first_error_at'], $state['first_error_at']);
        $this->assertArrayNotHasKey('last_success_at', $state);
        $this->advance(21600);
        $this->artisan('support:check-codex-health');
        Mail::assertSent(SupportCodexHealthMail::class, 2);
    }

    public function test_recuperacion_se_envia_una_vez_y_rearma_un_incidente_nuevo(): void
    {
        $this->pulse();
        $this->advance(300);
        $this->artisan('support:check-codex-health');
        $this->pulse();
        $this->artisan('support:check-codex-health');
        $this->artisan('support:check-codex-health');
        Mail::assertSent(SupportCodexHealthMail::class, 2);
        Mail::assertSent(SupportCodexHealthMail::class, function ($mail) { return $mail->kind === 'recovery'; });
        $this->advance(300);
        $this->artisan('support:check-codex-health');
        Mail::assertSent(SupportCodexHealthMail::class, 3);
    }

    public function test_pausa_humana_no_dispara_alarma_y_un_microcorte_no_envia_mail(): void
    {
        $this->pulse(['status' => 'error']);
        $this->advance(200);
        $this->pulse();
        $this->artisan('support:check-codex-health');
        Mail::assertNothingSent();
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '0');
        $this->advance(600);
        $this->artisan('support:check-codex-health');
        Mail::assertNothingSent();
    }

    public function test_prueba_correo_no_arma_ni_modifica_el_monitor(): void
    {
        $this->artisan('support:check-codex-health', ['--prueba-correo' => true])->assertExitCode(0);
        $this->assertNull(AdminSetting::get(SupportCodexHealthService::KEY));
        Mail::assertSent(SupportCodexHealthMail::class, function ($mail) {
            return $mail->kind === 'test' && $mail->hasTo(SupportCodexHealthService::RECIPIENT);
        });
    }

    public function test_scheduler_vigila_desde_servidor_en_segundo_plano_cada_minuto(): void
    {
        $events = app(\Illuminate\Console\Scheduling\Schedule::class)->events();
        $health = array_values(array_filter($events, function ($event) {
            return strpos((string) $event->command, 'support:check-codex-health') !== false;
        }));
        $this->assertCount(1, $health);
        $this->assertSame('* * * * *', $health[0]->expression);
        $this->assertTrue($health[0]->runInBackground);
        $this->assertTrue($health[0]->withoutOverlapping);
    }

    public function test_fallo_smtp_no_consume_el_aviso_y_se_reintenta(): void
    {
        $this->pulse();
        $this->advance(300);
        Mail::swap(new class {
            public function mailer($name) { return $this; }
            public function to($recipient) { return $this; }
            public function send($mail) { throw new \RuntimeException('fallo simulado'); }
        });
        $this->artisan('support:check-codex-health');
        $state = json_decode(AdminSetting::get(SupportCodexHealthService::KEY), true);
        $this->assertArrayNotHasKey('alert_sent_at', $state);
        $this->assertNotNull($state['mail_error_at']);
        Mail::fake();
        $this->artisan('support:check-codex-health');
        Mail::assertSent(SupportCodexHealthMail::class, 1);
        $this->assertStringContainsString('la PC', view('emails.commerciocity.support-monitor-health', ['kind' => 'failure', 'health' => ['reason' => 'sin_pulso', 'last_seen_at' => null, 'last_success_at' => null]])->render());
    }
}
