<?php

namespace Tests\Feature\FlujoLead;

use App\Helpers\AppTime;
use App\Jobs\AutoSendLeadAiSuggestionJob;
use App\Models\AdminSetting;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\AgendaDemoAutomaticaPolicy;
use App\Services\DemoDirectaService;
use App\Services\LeadAiService;
use App\Services\LeadAiSuggestionAutoSendScheduler;
use App\Services\LeadConversationAiState;
use App\Services\LeadDemoSettings;
use App\Services\LeadSuggestionSendService;
use App\Services\LeadWhatsappOnboardingSettings;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Database\Seeders\LeadPipelineStatusGroupOrderSeeder;
use Database\Seeders\LeadPipelineStatusSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgendaDemoAutomaticaPolicyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00', DemoDirectaService::TZ));
        Queue::fake();
        Mail::fake();
        Http::fake(['*' => Http::response(['ok' => true])]);
        $this->seed(LeadPipelineStatusSeeder::class);
        $this->seed(LeadPipelineStatusGroupOrderSeeder::class);
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '1');
        foreach ([LeadDemoSettings::KEY_DURACION_MINUTOS => '60', LeadDemoSettings::KEY_SETUP_MINUTOS_ANTES => '10', LeadDemoSettings::KEY_GRACIA_MINUTOS_POST => '10', LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS => '2'] as $key => $valor) { AdminSetting::set($key, $valor); }
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    public function test_sin_optin_permanece_el_gate_historico(): void
    {
        $this->assertFalse(AgendaDemoAutomaticaPolicy::activa());
        $lead = $this->lead();
        $lead->update(['status' => 'solicita_disponibilidad']);
        $this->assertTrue((bool) $lead->fresh()->requiere_verificacion_mensajes);
        $this->assertTrue(LeadConversationAiState::requiere_aprobacion_humana($lead->fresh()));
    }

    public function test_optin_no_apaga_un_escudo_historico_y_postdemo_sigue_manual(): void
    {
        $viejo = $this->lead();
        $viejo->update(['status' => 'solicita_disponibilidad']);
        $this->activar();
        $this->assertSame('escudo_manual_o_historico', app(AgendaDemoAutomaticaPolicy::class)->evaluar($viejo->fresh())['motivo']);
        $nuevo = $this->lead();
        $nuevo->update(['status' => 'solicita_disponibilidad']);
        $this->assertFalse((bool) $nuevo->fresh()->requiere_verificacion_mensajes);
        $nuevo->update(['status' => 'demo_realizada']);
        $this->assertSame('post_demo_manual', app(AgendaDemoAutomaticaPolicy::class)->evaluar($nuevo->fresh())['motivo']);
        $this->assertTrue(LeadConversationAiState::requiere_aprobacion_humana($nuevo->fresh()));
    }

    public function test_generar_programar_y_job_aplican_una_reserva_valida_sin_aprobacion_ficticia(): void
    {
        $this->activar();
        $this->demo();
        $lead = $this->lead();
        $this->inbound($lead, 'Ahora sí puedo');
        $msg = $this->generar_pendiente($lead);
        $this->assertFalse((bool) $msg->requiere_verificacion);
        $this->assertNull($lead->fresh()->demo_id, 'Generar no reserva ni envía correo.');
        $scheduler = app(LeadAiSuggestionAutoSendScheduler::class);
        $scheduler->schedule_for_suggested_message($msg);
        Queue::assertPushed(AutoSendLeadAiSuggestionJob::class, 1);
        $job = Queue::pushed(AutoSendLeadAiSuggestionJob::class)->first();
        $sender = \Mockery::mock(WhatsappSendService::class);
        $sender->shouldReceive('send_text')->once()->andReturn('salida-ficticia');
        $job->handle(new LeadSuggestionSendService($sender), $scheduler);
        $this->assertSame('enviado', $msg->fresh()->status);
        $this->assertSame('12:10', substr((string) $lead->fresh()->demo_start_time, 0, 5));
        $this->assertFalse((bool) $lead->fresh()->requiere_verificacion_mensajes);
        $this->assertNull($msg->fresh()->sent_by_admin_id);
        $this->assertNull($msg->fresh()->actions_override_log);
    }

    public function test_un_conflicto_nuevo_entre_programar_y_enviar_retiene_sin_negativa(): void
    {
        $this->activar();
        $demo = $this->demo();
        $lead = $this->lead();
        $this->inbound($lead, 'Ahora puedo');
        $msg = $this->generar_pendiente($lead);
        $scheduler = app(LeadAiSuggestionAutoSendScheduler::class);
        $scheduler->schedule_for_suggested_message($msg);
        $ocupante = $this->lead();
        $ocupante->update(['demo_id' => $demo->id, 'status' => 'demo_agendada', 'demo_date' => '2026-10-21', 'demo_start_time' => '12:00', 'demo_end_time' => '14:00']);
        $sender = \Mockery::mock(WhatsappSendService::class);
        $sender->shouldNotReceive('send_text');
        Queue::pushed(AutoSendLeadAiSuggestionJob::class)->first()->handle(new LeadSuggestionSendService($sender), $scheduler);
        $this->assertSame('sugerido', $msg->fresh()->status);
        $this->assertTrue((bool) $msg->fresh()->requiere_verificacion);
        $this->assertSame($msg->content, $msg->fresh()->content, 'No se reemplaza por una negativa fija.');
        $this->assertNull($lead->fresh()->demo_id);
        Mail::assertNothingSent();
    }

    public function test_autonomo_no_puede_forzar_slot_ni_simular_override_humano(): void
    {
        $this->activar();
        $this->demo();
        $lead = $this->lead();
        $this->inbound($lead, 'Ahora puedo');
        $msg = $this->generar_pendiente($lead);
        $this->expectException(\InvalidArgumentException::class);
        (new LeadAiService())->apply_pending_actions($msg, ['forzar_slot' => true, 'guardar_email' => 'inventado@fixture.test'], true);
    }

    public function test_el_envio_relee_un_escudo_activado_despues_del_job(): void
    {
        $this->activar();
        $this->demo();
        $lead = $this->lead();
        $this->inbound($lead, 'Ahora puedo');
        $msg = $this->generar_pendiente($lead);
        $msg->load('lead');
        Lead::query()->whereKey($lead->id)->update(['requiere_verificacion_mensajes' => true]);
        $sender = \Mockery::mock(WhatsappSendService::class);
        $sender->shouldNotReceive('send_text');
        (new LeadSuggestionSendService($sender))->send_suggestion($msg, null, null, true);
        $this->assertTrue((bool) $msg->fresh()->requiere_verificacion);
        $this->assertNull($lead->fresh()->demo_id);
    }

    private function activar(): void { AdminSetting::set(AgendaDemoAutomaticaPolicy::KEY_ACTIVA, '1'); }
    private function lead(): Lead
    {
        return Lead::create(['uuid' => (string) Str::uuid(), 'contact_name' => 'Comerciante ficticio', 'status' => 'calificado', 'phone' => '5493510000000', 'claude_auto_reply' => true]);
    }
    private function demo(): Demo
    {
        return Demo::create(['uuid' => (string) Str::uuid(), 'erp_spa_url' => 'https://erp.test', 'erp_api_url' => 'https://api-erp.test', 'ecommerce_spa_url' => 'https://tienda.test', 'ecommerce_api_url' => 'https://api-tienda.test']);
    }
    private function inbound(Lead $lead, string $texto): void
    {
        LeadMessage::create(['lead_id' => $lead->id, 'sender' => 'lead', 'status' => 'enviado', 'content' => $texto, 'is_followup' => false, 'sent_at' => AppTime::now()]);
    }
    private function generar_pendiente(Lead $lead): LeadMessage
    {
        $metodo = new \ReflectionMethod(LeadAiService::class, 'create_message_and_update_lead');
        $metodo->setAccessible(true);
        return $metodo->invoke(new LeadAiService(), $lead, ['mensaje_sugerido' => 'Dale, te dejo tu página.', 'estado_sugerido' => 'demo_agendada', 'agendar_demo' => ['ahora' => true]], false, null);
    }
}
