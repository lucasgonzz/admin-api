<?php

namespace Tests\Feature;

use App\Jobs\EnviarAvisoDeSoporteJob;
use App\Models\Admin;
use App\Models\AdminPushSubscription;
use App\Models\Client;
use App\Models\SupportAttentionNotification;
use App\Models\SupportCodexCase;
use App\Models\SupportTicket;
use App\Services\SupportAttentionNotificationService;
use App\Services\AdminPushNotificationService;
use Carbon\Carbon;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use Tests\TestCase;

/** Estado real del ticket/caso, sin red: el worker y la entrega de push se sustituyen. */
class AvisosPushDeAtencionDeSoporteTest extends TestCase
{
    use DatabaseTransactions;

    private $service;
    private $owner;
    private $ticket;
    private $case;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-29 10:00:00'));
        Queue::fake();
        Admin::query()->update(['is_default_support_owner' => false, 'notify_support_escalation_whatsapp' => false]);
        $this->owner = $this->admin('responsable', ['is_default_support_owner' => true]);
        $this->device($this->owner);
        $this->service = new class extends SupportAttentionNotificationService {
            public $sent = [];
            public $fail_ids = [];
            protected function send_push(int $admin_id, string $title, string $body, array $data, array $accepted_hashes = []): array
            {
                if (in_array($admin_id, $this->fail_ids, true)) {
                    throw new \RuntimeException('Falla simulada del transporte.');
                }
                $this->sent[] = compact('admin_id', 'title', 'body', 'data');
                return ['accepted_endpoint_hashes' => [hash('sha256', 'device-' . $admin_id)], 'failed_endpoint_hashes' => [], 'complete' => true];
            }
        };
        $this->app->instance(SupportAttentionNotificationService::class, $this->service);
        $client = Client::create(['name' => 'Golden Brace', 'phone' => '+5493410000011', 'is_active' => true]);
        $this->ticket = SupportTicket::create([
            'client_id' => $client->id, 'client_user_id' => 0, 'client_user_name' => 'Inés',
            'status' => 'open', 'source' => 'whatsapp', 'opened_at' => now(),
        ]);
        $this->case = SupportCodexCase::create(['support_ticket_id' => $this->ticket->id, 'state' => 'ready']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(string $name, array $attributes = []): Admin
    {
        return Admin::create(array_merge([
            'name' => $name, 'email' => uniqid($name) . '@test.local', 'password' => bcrypt('prueba'),
        ], $attributes));
    }

    private function device(Admin $admin): void
    {
        $endpoint = 'https://web.push.apple.test/' . $admin->id;
        AdminPushSubscription::create([
            'admin_id' => $admin->id, 'endpoint' => $endpoint,
            'endpoint_hash' => AdminPushSubscription::hash_endpoint($endpoint),
            'p256dh' => 'clave-falsa', 'auth' => 'auth-falso',
        ]);
    }

    private function row(): SupportAttentionNotification
    {
        return SupportAttentionNotification::where('support_ticket_id', $this->ticket->id)->firstOrFail();
    }

    private function run_job(?int $generation = null): void
    {
        (new EnviarAvisoDeSoporteJob((int) $this->ticket->id, $generation === null ? $this->row()->generation : $generation))
            ->handle($this->service);
    }

    public function test_review_real_avisa_al_responsable_con_link_y_sin_diagnostico_privado(): void
    {
        $this->case->update(['state' => 'manual_review', 'review_reason' => 'No puede operar. SQL privado secreto.']);
        $this->assertCount(0, $this->service->sent);
        $job = new EnviarAvisoDeSoporteJob((int) $this->ticket->id, $this->row()->generation);
        $this->assertSame('database', $job->connection);
        $this->assertTrue($job->afterCommit);
        $this->run_job();
        $this->assertCount(1, $this->service->sent);
        $sent = $this->service->sent[0];
        $this->assertSame((int) $this->owner->id, $sent['admin_id']);
        $this->assertStringContainsString('Inés', $sent['title']);
        $this->assertSame('Este ticket necesita tu revisión.', $sent['body']);
        $this->assertSame('/soporte/ticket/' . $this->ticket->id, $sent['data']['url']);
        $this->assertTrue($sent['data']['needs_review']);
        $this->assertFalse($sent['data']['mission_pending']);
        $this->assertStringNotContainsString('SQL', json_encode($sent));
    }

    public function test_mision_pendiente_y_revision_pueden_coexistir(): void
    {
        $this->case->update(['state' => 'mission_pending', 'mission' => 'Diagnóstico privado del código.']);
        $this->run_job();
        $this->assertSame('Hay una misión pendiente de iniciar.', $this->service->sent[0]['body']);
        $this->assertFalse($this->service->sent[0]['data']['needs_review']);
        $this->case->update(['state' => 'manual_review', 'review_reason' => 'Fallo de atención.']);
        $this->run_job();
        $this->assertSame('Necesita tu revisión y tiene una misión pendiente de iniciar.', $this->service->sent[1]['body']);
        $this->assertTrue($this->service->sent[1]['data']['mission_pending']);
        $this->assertStringNotContainsString('Diagnóstico privado', json_encode($this->service->sent));
    }

    public function test_guardados_repetidos_y_jobs_duplicados_no_reenvian(): void
    {
        $this->case->update(['state' => 'manual_review', 'review_reason' => 'Revisar.']);
        $generation = $this->row()->generation;
        $this->run_job();
        $this->case->save();
        $this->ticket->update(['last_client_message_at' => now()]);
        $this->service->conciliar();
        $this->run_job($generation);
        $this->assertSame($generation, $this->row()->generation);
        $this->assertCount(1, $this->service->sent);
    }

    public function test_un_cambio_de_motivo_si_genera_otro_aviso(): void
    {
        $this->case->update(['state' => 'manual_review', 'review_reason' => 'Revisar envío.']);
        $this->run_job();
        $this->case->update(['review_reason' => 'Urgente: no puede trabajar.']);
        $this->run_job();
        $this->assertCount(2, $this->service->sent);
    }

    public function test_mision_iniciada_invalida_el_aviso_pendiente(): void
    {
        $this->case->update(['state' => 'mission_pending', 'mission' => 'Corregir.']);
        $generation = $this->row()->generation;
        $this->case->update(['mission_thread_id' => 'hilo-real', 'mission_started_at' => now()]);
        $this->run_job($generation);
        $this->assertCount(0, $this->service->sent);
        $this->assertNull($this->row()->fingerprint);
    }

    public function test_mision_iniciada_con_revision_pendiente_avisa_solo_revision(): void
    {
        $this->case->update([
            'state' => 'manual_review', 'review_reason' => 'Revisar.', 'mission' => 'Corregir.',
            'mission_thread_id' => 'hilo-real', 'mission_started_at' => now(),
        ]);
        $this->run_job();
        $this->assertSame('Este ticket necesita tu revisión.', $this->service->sent[0]['body']);
    }

    public function test_la_pausa_por_respuesta_humana_no_pide_revision(): void
    {
        $this->case->update(['state' => 'manual_review', 'review_reason' => SupportCodexCase::HUMAN_REPLY_REVIEW_REASON]);
        $this->run_job();
        $this->assertCount(0, $this->service->sent);
        $this->case->update(['mission' => 'Misión que sí sigue pendiente.']);
        $this->run_job();
        $this->assertSame('Hay una misión pendiente de iniciar.', $this->service->sent[0]['body']);
    }

    public function test_cerrar_el_ticket_invalida_el_aviso_antes_de_enviar(): void
    {
        $this->case->update(['state' => 'manual_review', 'review_reason' => 'Revisar.']);
        $generation = $this->row()->generation;
        $this->ticket->update(['status' => 'closed']);
        $this->run_job($generation);
        $this->assertCount(0, $this->service->sent);
    }

    public function test_un_nuevo_episodio_del_mismo_motivo_vuelve_a_avisar(): void
    {
        $this->case->update(['state' => 'manual_review', 'review_reason' => 'Revisar.']);
        $this->run_job();
        $this->case->update(['state' => 'ready', 'review_reason' => null]);
        $this->case->update(['state' => 'manual_review', 'review_reason' => 'Revisar.']);
        $this->run_job();
        $this->assertCount(2, $this->service->sent);
    }

    public function test_destinatarios_no_duplican_owner_asignado_y_opt_in_y_excluyen_ajenos(): void
    {
        $this->owner->update(['notify_support_escalation_whatsapp' => true]);
        $this->ticket->update(['assigned_admin_id' => $this->owner->id]);
        $opt = $this->admin('opt', ['notify_support_escalation_whatsapp' => true]);
        $this->device($opt);
        $this->device($this->admin('ajeno'));
        $this->case->update(['state' => 'manual_review', 'review_reason' => 'Revisar.']);
        $this->run_job();
        $this->assertEqualsCanonicalizing([(int) $this->owner->id, (int) $opt->id], array_column($this->service->sent, 'admin_id'));
    }

    public function test_asignado_sin_opt_in_tambien_recibe_el_aviso(): void
    {
        $assigned = $this->admin('asignado');
        $this->device($assigned);
        $this->ticket->update(['assigned_admin_id' => $assigned->id]);
        $this->case->update(['state' => 'manual_review', 'review_reason' => 'Revisar.']);
        $this->run_job();
        $this->assertContains((int) $assigned->id, array_column($this->service->sent, 'admin_id'));
    }

    public function test_sin_device_no_hay_envio_ni_error(): void
    {
        AdminPushSubscription::where('admin_id', $this->owner->id)->delete();
        $this->case->update(['state' => 'manual_review', 'review_reason' => 'Revisar.']);
        $this->run_job();
        $this->assertCount(0, $this->service->sent);
        $this->assertNull($this->row()->notified_at);
        $this->device($this->owner);
        $this->run_job();
        $this->assertCount(1, $this->service->sent);
        $this->assertNotNull($this->row()->notified_at);
    }

    public function test_fallo_por_destinatario_reintenta_solo_los_no_despachados(): void
    {
        $other = $this->admin('segundo', ['is_default_support_owner' => true]);
        $this->device($other);
        $this->service->fail_ids = [(int) $other->id];
        $this->case->update(['state' => 'manual_review', 'review_reason' => 'Revisar.']);
        try {
            $this->run_job();
            $this->fail('El fallo debe dejar el job reintentable.');
        } catch (\RuntimeException $exception) {
            $this->assertNull($this->row()->notified_at);
        }
        $this->service->fail_ids = [];
        $this->run_job();
        $this->assertCount(2, $this->service->sent);
        $this->assertEqualsCanonicalizing([(int) $this->owner->id, (int) $other->id], array_column($this->service->sent, 'admin_id'));
    }

    /** @dataProvider errores_transitorios */
    public function test_un_report_transitorio_de_apple_queda_pendiente_y_se_reintenta(int $status): void
    {
        $attempts = 0;
        $endpoint = AdminPushSubscription::where('admin_id', $this->owner->id)->firstOrFail()->endpoint;
        $web_push = \Mockery::mock(WebPush::class);
        $web_push->shouldReceive('queueNotification')->twice();
        $web_push->shouldReceive('flush')->twice()->andReturnUsing(function () use (&$attempts, $endpoint, $status) {
            $attempts++;
            $ok = $attempts > 1;
            yield new MessageSentReport(new Request('POST', $endpoint), new Response($ok ? 201 : $status), $ok, 'Prueba');
        });
        $push = new class extends AdminPushNotificationService {
            public static $client;
            protected static function make_web_push(): WebPush
            {
                return static::$client;
            }
        };
        $push::$client = $web_push;
        $this->app->instance(AdminPushNotificationService::class, $push);
        $service = new SupportAttentionNotificationService();
        $this->case->update(['state' => 'manual_review', 'review_reason' => 'Revisar.']);
        try {
            $service->enviar((int) $this->ticket->id, $this->row()->generation);
            $this->fail('El report fallido no puede marcar el aviso como despachado.');
        } catch (\RuntimeException $exception) {
            $this->assertNull($this->row()->notified_at);
            $this->assertSame([], $this->row()->dispatched_admin_ids);
            $this->assertTrue(AdminPushSubscription::where('admin_id', $this->owner->id)->exists());
        }
        $service->enviar((int) $this->ticket->id, $this->row()->generation);
        $this->assertNotNull($this->row()->notified_at);
        $this->assertSame([(int) $this->owner->id], $this->row()->dispatched_admin_ids);
    }

    public function errores_transitorios(): array
    {
        return [[429], [500]];
    }

    public function test_dos_devices_reintentan_solo_el_endpoint_que_no_fue_aceptado(): void
    {
        $first = AdminPushSubscription::where('admin_id', $this->owner->id)->firstOrFail()->endpoint;
        $second = $first . '/segundo';
        AdminPushSubscription::create([
            'admin_id' => $this->owner->id, 'endpoint' => $second,
            'endpoint_hash' => AdminPushSubscription::hash_endpoint($second),
            'p256dh' => 'otra-clave-falsa', 'auth' => 'otro-auth-falso',
        ]);
        $attempts = 0;
        $queued = [];
        $web_push = \Mockery::mock(WebPush::class);
        $web_push->shouldReceive('queueNotification')->times(3)->andReturnUsing(function ($subscription) use (&$queued) {
            $queued[] = $subscription->getEndpoint();
        });
        $web_push->shouldReceive('flush')->twice()->andReturnUsing(function () use (&$attempts, $first, $second) {
            $attempts++;
            if ($attempts === 1) {
                yield new MessageSentReport(new Request('POST', $first), new Response(201), true, 'Aceptado');
                yield new MessageSentReport(new Request('POST', $second), new Response(429), false, 'Rate limit');
                return;
            }
            yield new MessageSentReport(new Request('POST', $second), new Response(201), true, 'Aceptado');
        });
        $push = new class extends AdminPushNotificationService {
            public static $client;
            protected static function make_web_push(): WebPush
            {
                return static::$client;
            }
        };
        $push::$client = $web_push;
        $this->app->instance(AdminPushNotificationService::class, $push);
        $service = new SupportAttentionNotificationService();
        $this->case->update(['state' => 'manual_review', 'review_reason' => 'Revisar.']);
        try {
            $service->enviar((int) $this->ticket->id, $this->row()->generation);
            $this->fail('La entrega parcial debe quedar pendiente.');
        } catch (\RuntimeException $exception) {
            $this->assertNull($this->row()->notified_at);
            $this->assertSame([AdminPushSubscription::hash_endpoint($first)], $this->row()->accepted_endpoint_hashes);
        }
        $service->enviar((int) $this->ticket->id, $this->row()->generation);
        $this->assertSame([$first, $second, $second], $queued);
        $this->assertCount(2, $this->row()->accepted_endpoint_hashes);
        $this->assertSame([(int) $this->owner->id], $this->row()->dispatched_admin_ids);
        $this->assertNotNull($this->row()->notified_at);
    }

    public function test_rollback_no_deja_fila_ni_push_de_la_transicion(): void
    {
        DB::beginTransaction();
        $this->case->update(['state' => 'manual_review', 'review_reason' => 'Revisar.']);
        $this->assertNotNull($this->row()->fingerprint);
        DB::rollBack();
        $this->assertNull($this->row()->fingerprint);
        Queue::assertNothingPushed();
        $this->assertCount(0, $this->service->sent);
    }

    public function test_el_conciliador_recupera_transicion_no_observada_sin_duplicar_despachos(): void
    {
        SupportCodexCase::whereKey($this->case->id)->update(['state' => 'manual_review', 'review_reason' => 'No pasó por observer.']);
        $this->assertNull($this->row()->fingerprint);
        $this->artisan('support:notify-attention')->assertExitCode(0);
        $this->assertNotNull($this->row()->enqueued_at);
        $this->run_job();
        $this->service->conciliar();
        $this->run_job();
        $this->assertCount(1, $this->service->sent);
    }

    public function test_legacy_sin_caso_conserva_su_canal_existente_sin_nuevo_aviso(): void
    {
        $legacy = SupportTicket::create([
            'client_id' => $this->ticket->client_id, 'client_user_id' => 0, 'status' => 'open',
            'escalated_at' => now(), 'escalation_reason' => 'Escalación legacy.',
        ]);
        $this->assertFalse(SupportAttentionNotification::where('support_ticket_id', $legacy->id)->exists());
    }
}
