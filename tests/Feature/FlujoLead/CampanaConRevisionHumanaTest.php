<?php

namespace Tests\Feature\FlujoLead;

use App\Http\Controllers\WhatsappWebhookController;
use App\Jobs\AutoSendLeadAiSuggestionJob;
use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\CloserNotificationService;
use App\Services\LeadAiSuggestionAutoSendScheduler;
use App\Services\LeadAiService;
use App\Services\LeadSuggestionSendService;
use App\Services\LeadReminderComposer;
use App\Services\DemoDirectaService;
use App\Services\DemoCicloAdminNotificationService;
use App\Services\LeadEscalationWhatsappService;
use App\Services\LeadNotificationAudienceResolver;
use App\Console\Commands\SendDemoReminders;
use App\Services\LeadWhatsappOnboardingSettings;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use Carbon\Carbon;

class CampanaConRevisionHumanaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
    }

    public function test_el_envio_se_encola_antes_de_terminar_el_worker_y_no_sale_del_gate(): void
    {
        $lead = $this->lead();
        $mensaje = $this->mensaje($lead, false);

        app(LeadAiSuggestionAutoSendScheduler::class)->schedule_for_suggested_message($mensaje);

        // Sin terminar la aplicación ni drenar callbacks: el siguiente worker ya puede tomarlo.
        Queue::assertPushed(AutoSendLeadAiSuggestionJob::class, function ($job) {
            return $job->connection === 'database' && $job->delay === null;
        });
        $this->assertNull($mensaje->fresh()->ai_auto_send_at);

        $agenda = $this->mensaje($lead, true);
        $agenda->pending_actions = ['agendar_demo' => ['ahora' => true]];
        $agenda->save();
        app(LeadAiSuggestionAutoSendScheduler::class)->schedule_for_suggested_message($agenda);
        Queue::assertPushed(AutoSendLeadAiSuggestionJob::class, 1);
        $this->assertNull($agenda->fresh()->ai_auto_send_at, 'Una agenda sigue esperando aprobación humana sin respaldo.');
    }

    public function test_el_retenido_conserva_su_demora_y_usa_cola_real(): void
    {
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_VERIFICACION_AGENDAMIENTO_AUTO_SEND_DELAY_MINUTES, '16667');
        $mensaje = $this->mensaje($this->lead(), true);

        app(LeadAiSuggestionAutoSendScheduler::class)->schedule_for_suggested_message($mensaje);

        Queue::assertPushed(AutoSendLeadAiSuggestionJob::class, function ($job) {
            return $job->connection === 'database' && $job->delay !== null;
        });
        $this->assertTrue($mensaje->fresh()->ai_auto_send_at->gt(now()->addDays(11)));
    }

    public function test_el_closer_no_recibe_avisos_del_post_demo_nuevo(): void
    {
        $closer = new Admin();
        $closer->name = 'Asesor de prueba';
        $closer->email = Str::uuid() . '@comerciocity.test';
        $closer->password = bcrypt('prueba-local');
        $closer->is_closer = true;
        $closer->phone_number = '5493510000001';
        $closer->save();
        $sender = \Mockery::mock(WhatsappSendService::class);
        $sender->shouldNotReceive('send_text');
        $lead = $this->lead('closer_activo');

        (new CloserNotificationService($sender))->notify_for_lead($lead);

        $this->assertNull($lead->fresh()->closer_notified_at);
    }

    public function test_terminar_la_demo_nueva_no_dispara_el_detector_viejo_ni_avisa_al_closer(): void
    {
        $lead = $this->lead('demo_en_curso');
        $lead->demo_fin_check_enviado = true;
        $lead->demo_ingreso_confirmado = true;
        $lead->save();
        $closer = \Mockery::mock(CloserNotificationService::class);
        $closer->shouldNotReceive('notify_for_lead');
        $this->app->instance(CloserNotificationService::class, $closer);
        $metodo = new \ReflectionMethod(WhatsappWebhookController::class, 'handle_demo_confirmation_if_needed');
        $metodo->setAccessible(true);

        $metodo->invoke($this->app->make(WhatsappWebhookController::class), $lead, 'Sí, la vi completa.');

        $this->assertSame('demo_en_curso', $lead->fresh()->status, 'El agente interpreta la respuesta y el operador aprueba; el detector viejo no decide.');
        $this->assertNull($lead->fresh()->closer_notified_at);
    }

    public function test_media_hora_se_cuenta_desde_el_pedido_y_no_desde_el_mail(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', DemoDirectaService::TZ));
        try {
            $lead = $this->lead();
            $pedido = $this->mensaje($lead, false);
            $pedido->update(['sender' => 'lead', 'content' => 'Ahora estoy atendiendo, pero en media hora me libero.']);
            Carbon::setTestNow(Carbon::parse('2026-10-20 10:05:00', DemoDirectaService::TZ));
            $correo = $this->mensaje($lead, false);
            $correo->update(['sender' => 'lead', 'content' => 'prueba@comerciocity.test']);
            $metodo = new \ReflectionMethod(LeadAiService::class, 'normalizar_agendar_demo_directa');
            $metodo->setAccessible(true);

            $paquete = $metodo->invoke(new LeadAiService(), ['ahora' => true], $lead);

            $this->assertSame('2026-10-20 10:30', $paquete['inicio_resuelto']);
            $this->assertArrayNotHasKey('ahora', $paquete);

            $nuevo = $this->mensaje($lead, false);
            $nuevo->update(['sender' => 'lead', 'content' => 'Mejor la hago ahora']);
            $paquete = $metodo->invoke(new LeadAiService(), ['ahora' => true], $lead);
            $this->assertTrue($paquete['ahora'], 'Un pedido posterior reemplaza el momento anterior.');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_aprobar_tarde_corrige_solo_la_hora_de_fin_de_la_reserva(): void
    {
        $lead = $this->lead();
        $lead->demo_end_time = '12:20:00';
        $metodo = new \ReflectionMethod(LeadSuggestionSendService::class, 'corregir_fin_de_reserva');
        $metodo->setAccessible(true);

        $texto = $metodo->invoke(new LeadSuggestionSendService(), 'La tenés reservada dos horas, hasta las 12:10. Mañana abrís a las 09:00.', $lead);

        $this->assertSame('La tenés reservada dos horas, hasta las 12:20. Mañana abrís a las 09:00.', $texto);
    }

    public function test_el_recordatorio_no_promete_un_acceso_mientras_se_prepara(): void
    {
        $lead = $this->lead('demo_agendada');
        $lead->demo_setup_status = 'ejecutandose';
        $sender = \Mockery::mock(WhatsappSendService::class);
        $sender->shouldNotReceive('send_text');
        $sender->shouldNotReceive('send_template');
        $metodo = new \ReflectionMethod(SendDemoReminders::class, 'send_reminder_message_directa');
        $metodo->setAccessible(true);

        $this->assertSame('pendiente', $metodo->invoke(new SendDemoReminders($sender), $lead));
        $this->assertFalse((bool) $lead->fresh()->recordatorio_demo_enviado);
        $contexto = new \ReflectionMethod(LeadReminderComposer::class, 'describir_el_tipo');
        $contexto->setAccessible(true);
        $this->assertStringContainsString('NO afirmes que el acceso ya está disponible', $contexto->invoke(new LeadReminderComposer(), LeadReminderComposer::TIPO_PRE_DEMO, $lead));
    }

    public function test_los_avisos_post_demo_conservan_operador_y_campanita_sin_convocar_al_closer(): void
    {
        Admin::query()->update(['notify_demo_scheduled_whatsapp' => false, 'notify_lead_escalation_whatsapp' => false, 'es_setter' => false]);
        $setter = $this->admin('Operador', '5493510000011', false);
        $closer = $this->admin('Asesor', '5493510000012', true);
        $sender = \Mockery::mock(WhatsappSendService::class);
        $sender->shouldReceive('send_template')->withArgs(function ($phone) use ($setter) {
            return $phone === $setter->phone_number;
        })->twice()->andReturn('prueba-operador');
        $sender->shouldReceive('send_template')->withArgs(function ($phone) use ($closer) {
            return $phone === $closer->phone_number;
        })->once()->andReturn('prueba-suscripcion-explicita');
        $lead = $this->lead('demo_realizada');

        $this->assertSame(['Operador'], (new DemoCicloAdminNotificationService($sender))->notify_fin_confirmado($lead));
        $this->assertSame(['Operador'], (new LeadEscalationWhatsappService($sender))->notify($lead, 'Revisar el post-demo'));
        // Una suscripción explícita sigue habilitando su aviso, aunque también sea closer.
        (new LeadEscalationWhatsappService($sender))->notify($lead, 'Suscripción manual', [$closer->id]);
        $lead->status = 'closer_activo';
        $lead->save();
        $this->assertSame([$setter->id], LeadNotificationAudienceResolver::for_mensaje_entrante($lead));
        $lead->notification_admins()->attach($closer->id);
        $esperados = [$setter->id, $closer->id];
        sort($esperados);
        $this->assertSame($esperados, LeadNotificationAudienceResolver::for_mensaje_entrante($lead));
    }

    private function admin(string $nombre, string $telefono, bool $closer): Admin
    {
        $admin = new Admin();
        $admin->name = $nombre;
        $admin->email = Str::uuid() . '@comerciocity.test';
        $admin->password = bcrypt('prueba-local');
        $admin->phone_number = $telefono;
        $admin->is_closer = $closer;
        $admin->es_setter = ! $closer;
        $admin->notify_demo_scheduled_whatsapp = true;
        $admin->notify_lead_escalation_whatsapp = true;
        $admin->save();

        return $admin;
    }

    private function lead(string $estado = 'contactado'): Lead
    {
        $lead = new Lead();
        $lead->uuid = (string) Str::uuid();
        $lead->phone = '549351' . random_int(1000000, 9999999);
        $lead->status = $estado;
        $lead->demo_experiencia = Lead::EXPERIENCIA_NUEVA;
        $lead->claude_auto_reply = true;
        $lead->save();

        return $lead;
    }

    private function mensaje(Lead $lead, bool $verificacion): LeadMessage
    {
        $mensaje = new LeadMessage();
        $mensaje->lead_id = $lead->id;
        $mensaje->sender = 'sistema';
        $mensaje->status = 'sugerido';
        $mensaje->content = 'Respuesta de prueba';
        $mensaje->requiere_verificacion = $verificacion;
        $mensaje->is_followup = false;
        $mensaje->save();

        return $mensaje;
    }
}
