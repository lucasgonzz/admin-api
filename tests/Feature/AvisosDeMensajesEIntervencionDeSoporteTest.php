<?php
namespace Tests\Feature;
use App\Models\Admin;
use App\Models\Client;
use App\Models\SupportTicket;
use App\Models\SupportCodexCase;
use App\Models\SupportMessage;
use App\Models\SupportMessageNotification;
use App\Models\SupportAttentionNotification;
use App\Services\SupportMessageNotificationService;
use App\Services\SupportAttentionNotificationService;
use App\Jobs\EnviarAvisoDeMensajeDeSoporteJob;
use App\Mail\ComercioCityMail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
class AvisosDeMensajesEIntervencionDeSoporteTest extends TestCase
{
    use DatabaseTransactions;
    private $admin;
    private $other;
    private $ticket;
    private $messages;
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); Mail::fake();
        Admin::query()->update(['is_default_support_owner' => false, 'notify_support_escalation_whatsapp' => false]);
        $this->admin = Admin::create(['name' => 'Responsable', 'email' => uniqid() . '@test.local', 'password' => bcrypt('prueba'), 'is_default_support_owner' => true]);
        $this->other = Admin::create(['name' => 'Otro', 'email' => uniqid() . '@test.local', 'password' => bcrypt('prueba')]);
        $client = Client::create(['name' => 'Cliente de avisos', 'is_active' => true]);
        $this->ticket = SupportTicket::create(['client_id' => $client->id, 'client_user_id' => 0, 'client_user_name' => 'Cliente', 'source' => 'whatsapp', 'status' => 'open']);
        $this->messages = new class extends SupportMessageNotificationService {
            public $sent = []; public $fail = false;
            protected function send_push(int $id, string $title, string $body, array $data, array $accepted): array
            {
                if ($this->fail) { throw new \RuntimeException('Falla simulada.'); }
                $this->sent[] = compact('id', 'title', 'body', 'data', 'accepted');
                return ['complete' => true, 'accepted_endpoint_hashes' => ['aceptado']];
            }
        };
        $this->app->instance(SupportMessageNotificationService::class, $this->messages);
    }
    private function url(): string { return '/api/admin/support-ticket/' . $this->ticket->id . '/message-notifications'; }
    private function subscribe(Admin $admin): void { $this->actingAs($admin, 'sanctum')->putJson($this->url(), ['subscribed' => true])->assertOk(); }
    private function message(array $attributes = []): SupportMessage
    {
        return SupportMessage::create($attributes + ['support_ticket_id' => $this->ticket->id, 'sender_type' => 'user', 'body' => 'Necesito ayuda']);
    }
    public function test_suscripcion_por_operador_idempotente_y_entrada_validada(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
        $this->subscribe($this->admin); $this->subscribe($this->admin);
        $this->assertSame(1, DB::table('support_ticket_admin_notifications')->where('support_ticket_id', $this->ticket->id)->count());
        $this->actingAs($this->other, 'sanctum')->getJson($this->url())->assertOk()->assertJsonPath('subscribed', false);
        $this->actingAs($this->admin, 'sanctum')->putJson($this->url(), ['subscribed' => 'mal'])->assertStatus(422);
        $this->putJson($this->url(), ['subscribed' => false])->assertOk()->assertJsonPath('subscribed', false);
        $this->getJson('/api/admin/support-ticket/99999999/message-notifications')->assertNotFound();
    }
    public function test_solo_entrantes_reales_y_no_replays_generan_avisos(): void
    {
        $this->subscribe($this->admin);
        $this->message(['sender_type' => 'admin']);
        $this->message(['is_ai_suggestion_draft' => true]);
        $this->message(['is_trial_import_notice' => true]);
        $message = $this->message();
        $message->update(['read_at' => now()]);
        $this->messages->registrar($message);
        $this->assertSame(1, SupportMessageNotification::where('support_message_id', $message->id)->count());
        $this->messages->enviar((int) $message->id); $this->messages->enviar((int) $message->id);
        $this->assertCount(1, $this->messages->sent);
        $this->assertSame((int) $this->admin->id, $this->messages->sent[0]['id']);
        $this->assertSame('/soporte/ticket/' . $this->ticket->id, $this->messages->sent[0]['data']['url']);
        $this->assertSame(1, SupportMessageNotification::whereIn('support_message_id', SupportMessage::where('support_ticket_id', $this->ticket->id)->pluck('id'))->count());
    }
    public function test_falla_transitoria_reintenta_y_baja_suscripcion_cancela_pendiente(): void
    {
        $this->subscribe($this->admin);
        $message = $this->message();
        $this->messages->fail = true;
        try { $this->messages->enviar((int) $message->id); $this->fail('Debió fallar el transporte.'); } catch (\RuntimeException $exception) {}
        $this->assertNull(SupportMessageNotification::where('support_message_id', $message->id)->firstOrFail()->notified_at);
        $this->messages->fail = false; $this->messages->enviar((int) $message->id);
        $pending = $this->message();
        $this->actingAs($this->admin, 'sanctum')->putJson($this->url(), ['subscribed' => false])->assertOk();
        $this->messages->enviar((int) $pending->id);
        $this->assertCount(1, $this->messages->sent);
    }
    public function test_el_mail_de_intervencion_sale_sin_device_con_motivo_y_casilla_especifica(): void
    {
        $this->admin->update(['email' => 'identificador-login', 'support_notification_email' => 'avisos@test.local']);
        $this->ticket->update(['support_attention_enabled' => true, 'escalation_reason' => 'El cliente pidió hablar con una persona por un problema de precios. SQL SELECT secreto.']);
        $service = new SupportAttentionNotificationService();
        $row = SupportAttentionNotification::where('support_ticket_id', $this->ticket->id)->firstOrFail();
        $service->enviar((int) $this->ticket->id, $row->generation);
        $service->enviar((int) $this->ticket->id, $row->generation);
        Mail::assertSent(ComercioCityMail::class, function ($mail) {
            return $mail->hasTo('avisos@test.local') && strpos(implode(' ', $mail->payload->paragraphs), 'problema de precios') !== false
                && strpos(implode(' ', $mail->payload->paragraphs), 'SQL') === false;
        });
        Mail::assertSent(ComercioCityMail::class, 1);
        $this->assertNotNull($row->fresh()->email_notified_at);
        $this->assertNull($row->fresh()->notified_at);
    }
    public function test_correo_pendiente_se_completa_aunque_push_ya_estaba_notificado(): void
    {
        $this->ticket->update(['support_attention_enabled' => true, 'escalation_reason' => 'Pide agregar una nueva funcionalidad.']);
        $row = SupportAttentionNotification::where('support_ticket_id', $this->ticket->id)->firstOrFail();
        $row->update(['notified_at' => now(), 'dispatched_admin_ids' => [(int) $this->admin->id]]);
        (new SupportAttentionNotificationService())->enviar((int) $this->ticket->id, $row->generation);
        Mail::assertSent(ComercioCityMail::class, 1);
        $this->assertNotNull($row->fresh()->email_notified_at);
    }
    public function test_motivos_operativos_concretos_y_saneamiento(): void
    {
        $service = new SupportAttentionNotificationService();
        foreach (['El cliente pidió hablar con una persona.', 'Problema de precios de sus artículos.', 'Pide agregar una nueva funcionalidad.', 'Error al guardar una venta que le ocurre ahora.'] as $reason) {
            $this->assertSame($reason, $service->motivo_para_aviso($reason));
        }
        $this->assertSame('Error al guardar.', $service->motivo_para_aviso('Error al guardar. SQL SELECT * FROM datos. token secreto. Authorization: Bearer abc. access_token=abc. clave de acceso 123.'));
    }
    public function test_conciliador_recupera_registro_fallido_y_lease_vencido_sin_historico(): void
    {
        $old = $this->message(); // Misma fecha/hora hasta el segundo que el alta: manda la frontera por id.
        $this->subscribe($this->admin);
        $message = $this->message();
        SupportMessageNotification::where('support_message_id', $message->id)->delete();
        $this->messages->conciliar();
        $this->assertTrue(SupportMessageNotification::where('support_message_id', $message->id)->exists());
        $this->assertFalse(SupportMessageNotification::where('support_message_id', $old->id)->exists());
        $notice = SupportMessageNotification::where('support_message_id', $message->id)->firstOrFail();
        $notice->update(['processing_until' => now()->addMinute()]);
        $this->messages->enviar((int) $message->id);
        $this->assertCount(0, $this->messages->sent);
        $notice->update(['processing_until' => now()->subMinute()]);
        $this->messages->conciliar();
        $this->messages->enviar((int) $message->id);
        $this->assertCount(1, $this->messages->sent);
    }
    public function test_entrega_parcial_conserva_endpoints_aceptados_al_reintentar(): void
    {
        $this->subscribe($this->admin);
        $message = $this->message();
        $service = new class extends SupportMessageNotificationService {
            public $calls = [];
            protected function send_push(int $id, string $title, string $body, array $data, array $accepted): array
            {
                $this->calls[] = $accepted;
                return ['complete' => count($this->calls) > 1, 'accepted_endpoint_hashes' => count($this->calls) === 1 ? ['device-uno'] : ['device-dos']];
            }
        };
        try { $service->enviar((int) $message->id); $this->fail('Debió quedar parcial.'); } catch (\RuntimeException $exception) {}
        $service->enviar((int) $message->id); $service->enviar((int) $message->id);
        $this->assertSame([[], ['device-uno']], $service->calls);
        $this->assertSame(['device-uno', 'device-dos'], SupportMessageNotification::where('support_message_id', $message->id)->firstOrFail()->accepted_endpoint_hashes);
    }
    public function test_correo_fallido_no_repite_push_y_push_fallido_no_repite_correo(): void
    {
        \App\Models\AdminPushSubscription::create(['admin_id' => $this->admin->id, 'endpoint' => 'https://push.test/device', 'endpoint_hash' => hash('sha256', 'https://push.test/device'), 'p256dh' => 'falso', 'auth' => 'falso']);
        $service = new class extends SupportAttentionNotificationService {
            public $pushes = 0; public $mails = 0; public $fail_mail = true; public $fail_push = false;
            protected function send_push(int $id, string $title, string $body, array $data, array $accepted = []): array
            {
                if ($this->fail_push) { throw new \RuntimeException('Push fallido'); }
                ++$this->pushes;
                return ['complete' => true, 'accepted_endpoint_hashes' => ['aceptado']];
            }
            protected function send_email(string $email, string $title, string $reason, int $ticket_id): void
            {
                if ($this->fail_mail) { throw new \RuntimeException('SMTP fallido'); }
                ++$this->mails;
            }
        };
        $this->ticket->update(['support_attention_enabled' => true, 'escalation_reason' => 'Problema de precios.']);
        $row = SupportAttentionNotification::where('support_ticket_id', $this->ticket->id)->firstOrFail();
        try { $service->enviar((int) $this->ticket->id, $row->generation); $this->fail('Debió fallar correo.'); } catch (\RuntimeException $exception) {}
        $service->fail_mail = false; $service->enviar((int) $this->ticket->id, $row->generation);
        $this->assertSame(1, $service->pushes); $this->assertSame(1, $service->mails);
        $this->ticket->update(['escalation_reason' => 'Solicita hablar con una persona.']);
        $row = $row->fresh(); $service->fail_push = true;
        try { $service->enviar((int) $this->ticket->id, $row->generation); $this->fail('Debió fallar push.'); } catch (\RuntimeException $exception) {}
        $service->fail_push = false; $service->enviar((int) $this->ticket->id, $row->generation);
        $this->assertSame(2, $service->pushes); $this->assertSame(2, $service->mails);
    }
    public function test_baja_y_nueva_alta_no_reviven_un_aviso_anterior(): void
    {
        $this->subscribe($this->admin);
        $old = $this->message();
        $this->actingAs($this->admin, 'sanctum')->putJson($this->url(), ['subscribed' => false])->assertOk();
        $this->subscribe($this->admin);
        $this->messages->enviar((int) $old->id);
        $this->assertCount(0, $this->messages->sent);
        $new = $this->message();
        $this->messages->enviar((int) $new->id);
        $this->assertCount(1, $this->messages->sent);
    }
    public function test_falta_casilla_no_falla_job_y_se_recupera_cuando_se_configura(): void
    {
        $this->admin->update(['email' => 'login-sin-casilla']);
        $this->ticket->update(['support_attention_enabled' => true, 'escalation_reason' => 'Error al vender.']);
        $row = SupportAttentionNotification::where('support_ticket_id', $this->ticket->id)->firstOrFail();
        $service = new SupportAttentionNotificationService();
        $service->enviar((int) $this->ticket->id, $row->generation);
        Mail::assertNothingSent();
        $this->assertNull($row->fresh()->email_notified_at);
        $this->admin->update(['support_notification_email' => 'aviso@test.local']);
        $service->enviar((int) $this->ticket->id, $row->generation);
        Mail::assertSent(ComercioCityMail::class, 1);
    }
    public function test_ticket_de_ensayo_no_permite_suscripcion_ni_avisos(): void
    {
        $this->ticket->client->update(['es_ensayo' => true]);
        $this->actingAs($this->admin, 'sanctum')->putJson($this->url(), ['subscribed' => true])->assertNotFound();
        $message = $this->message();
        $this->assertFalse(SupportMessageNotification::where('support_message_id', $message->id)->exists());
    }
}
