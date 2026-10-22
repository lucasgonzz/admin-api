<?php

namespace Tests\Feature\FlujoLead;

use App\Jobs\AutoSendLeadAiSuggestionJob;
use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadAiSuggestionAutoSendScheduler;
use App\Services\LeadConversationAiState;
use App\Services\LeadSuggestionSendService;
use App\Services\LeadWhatsappOnboardingSettings;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class GateHumanoSinVencimientoTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '1');
    }

    /**
     * 🔴 22/10/2026 (decisión de Lucas, lead Christian): con el escudo apagado a mano, estar en el
     * tramo ya no retiene el mensaje: el global solo decide cuándo se prende solo el escudo, no
     * conserva ningún gate por estado. El mensaje sale en el acto.
     *
     * @dataProvider estados_supervisados
     */
    public function test_con_el_escudo_apagado_a_mano_el_mensaje_del_tramo_sale_solo(string $estado): void
    {
        $lead = $this->lead($estado);
        $lead->requiere_verificacion_mensajes = false;
        $lead->save();
        $mensaje = $this->mensaje($lead);

        app(LeadAiSuggestionAutoSendScheduler::class)->schedule_for_suggested_message($mensaje);

        Queue::assertPushed(AutoSendLeadAiSuggestionJob::class, 1);
        $this->assertFalse((bool) $mensaje->fresh()->requiere_verificacion);
        $this->assertNull($mensaje->fresh()->ai_auto_send_at);
    }

    /** @dataProvider estados_supervisados */
    public function test_el_texto_retenido_no_tiene_contador_ni_job_aunque_no_haya_acciones(string $estado): void
    {
        $lead = $this->lead($estado);
        // El escudo prendido (por el latch al entrar al tramo, o a mano) retiene sin contador ni job.
        $lead->requiere_verificacion_mensajes = true;
        $lead->save();
        $mensaje = $this->mensaje($lead);
        $mensaje->ai_auto_send_at = now()->addDays(12);
        $mensaje->save();
        Cache::put('lead_ai_suggestion_auto_send_token:'.$mensaje->id, 123, 7200);

        $scheduler = app(LeadAiSuggestionAutoSendScheduler::class);
        $scheduler->schedule_for_suggested_message($mensaje);

        Queue::assertNothingPushed();
        $this->assertNull($mensaje->fresh()->ai_auto_send_at);
        $this->assertTrue((bool) $mensaje->fresh()->requiere_verificacion);
        $this->assertSame('sugerido', $mensaje->fresh()->status);
        $this->assertFalse($scheduler->is_auto_send_token_current($mensaje->id, 123));
    }

    public static function estados_supervisados(): array
    {
        return array_map(function ($estado) { return [$estado]; }, Lead::ESTADOS_VENTANA_VERIFICACION_MENSAJES);
    }

    public function test_el_job_anterior_no_envia_si_el_lead_entra_al_gate_despues_de_encolar(): void
    {
        $lead = $this->lead('contactado');
        $mensaje = $this->mensaje($lead);
        $scheduler = app(LeadAiSuggestionAutoSendScheduler::class);
        $scheduler->schedule_for_suggested_message($mensaje);
        $job = Queue::pushed(AutoSendLeadAiSuggestionJob::class)->first();
        $lead->status = 'solicita_disponibilidad';
        $lead->save();
        $sender = $this->sin_envios();

        $job->handle(new LeadSuggestionSendService($sender), $scheduler);

        $this->assertSame('sugerido', $mensaje->fresh()->status);
        $this->assertTrue((bool) $mensaje->fresh()->requiere_verificacion);
        $this->assertNull($mensaje->fresh()->ai_auto_send_at);
        $this->assertNull($mensaje->fresh()->sent_at);
        Queue::assertPushed(AutoSendLeadAiSuggestionJob::class, 1);
    }

    public function test_el_servicio_relee_el_escudo_y_no_aplica_acciones_ni_plantilla(): void
    {
        $lead = $this->lead('contactado');
        $mensaje = $this->mensaje($lead);
        $mensaje->pending_actions = ['guardar_nombre' => 'No aplicar', 'agendar_demo' => ['ahora' => true]];
        $mensaje->followup_template_id = 999999;
        $mensaje->is_followup = true;
        $mensaje->ai_auto_send_at = now()->addMinutes(30);
        $mensaje->save();
        $mensaje->load('lead'); // La relación queda vieja a propósito.
        Lead::where('id', $lead->id)->update(['requiere_verificacion_mensajes' => true]);

        (new LeadSuggestionSendService($this->sin_envios()))->send_suggestion($mensaje, null, null, true);

        $this->assertSame('Contacto local', $lead->fresh()->contact_name);
        $this->assertNull($lead->fresh()->demo_id);
        $this->assertSame('sugerido', $mensaje->fresh()->status);
        $this->assertNotNull($mensaje->fresh()->pending_actions['agendar_demo']);
        $this->assertNull($mensaje->fresh()->ai_auto_send_at);
    }

    public function test_el_pre_gate_envia_y_recien_despues_activa_el_escudo_del_estado_sugerido(): void
    {
        $lead = $this->lead('contactado');
        $mensaje = $this->mensaje($lead);
        $mensaje->suggested_lead_status = 'solicita_disponibilidad';
        $mensaje->save();
        $this->inbound($lead);
        $scheduler = app(LeadAiSuggestionAutoSendScheduler::class);
        $scheduler->schedule_for_suggested_message($mensaje);
        $job = Queue::pushed(AutoSendLeadAiSuggestionJob::class)->first();
        $sender = \Mockery::mock(WhatsappSendService::class);
        $sender->shouldReceive('send_text')->once()->andReturn('salida-local');

        $job->handle(new LeadSuggestionSendService($sender), $scheduler);

        $this->assertSame('enviado', $mensaje->fresh()->status);
        $this->assertSame('solicita_disponibilidad', $lead->fresh()->status);
        $this->assertTrue((bool) $lead->fresh()->requiere_verificacion_mensajes);
    }

    public function test_global_apagado_respeta_el_escudo_explicito_y_no_inventa_gate_si_no_lo_hay(): void
    {
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '0');
        $lead = $this->lead('solicita_disponibilidad');
        $this->assertFalse(LeadConversationAiState::requiere_aprobacion_humana($lead));
        app(LeadAiSuggestionAutoSendScheduler::class)->schedule_for_suggested_message($this->mensaje($lead));
        Queue::assertPushed(AutoSendLeadAiSuggestionJob::class, 1);
        $lead->requiere_verificacion_mensajes = true;
        $lead->save();
        app(LeadAiSuggestionAutoSendScheduler::class)->schedule_for_suggested_message($this->mensaje($lead));
        Queue::assertPushed(AutoSendLeadAiSuggestionJob::class, 1);
    }

    public function test_un_humano_puede_aprobar_por_el_endpoint_y_enviar_el_texto_retenido(): void
    {
        $lead = $this->lead('solicita_disponibilidad');
        $mensaje = $this->mensaje($lead);
        $mensaje->requiere_verificacion = true;
        $mensaje->pending_actions = ['mensaje_sugerido' => $mensaje->content, 'estado_sugerido' => 'solicita_disponibilidad', 'guardar_nombre' => 'Nombre aprobado'];
        $mensaje->save();
        $this->inbound($lead);
        $admin = Admin::create(['name' => 'Revisor local', 'email' => Str::uuid().'@comerciocity.test', 'password' => bcrypt('prueba-local')]);
        $sender = \Mockery::mock(WhatsappSendService::class);
        $sender->shouldReceive('send_text')->once()->andReturn('aprobacion-local');
        $this->app->instance(LeadSuggestionSendService::class, new LeadSuggestionSendService($sender));

        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/lead-message/'.$mensaje->id.'/approve')->assertStatus(200);

        $this->assertSame('enviado', $mensaje->fresh()->status);
        $this->assertSame((int) $admin->id, (int) $mensaje->fresh()->sent_by_admin_id);
        $this->assertTrue((bool) $lead->fresh()->requiere_verificacion_mensajes);
        $this->assertSame('Nombre aprobado', $lead->fresh()->contact_name);
    }

    public function test_el_caso_b_no_aplica_acciones_internas_sin_aprobacion(): void
    {
        $lead = $this->lead('solicita_disponibilidad');
        $mensaje = $this->mensaje($lead);
        $mensaje->pending_actions = ['mensaje_sugerido' => $mensaje->content, 'estado_sugerido' => 'en_pausa', 'guardar_nombre' => 'No aplicar'];
        $mensaje->save();

        (new LeadSuggestionSendService($this->sin_envios()))->send_suggestion($mensaje, null, null, true);

        $this->assertSame('Contacto local', $lead->fresh()->contact_name);
        $this->assertSame('solicita_disponibilidad', $lead->fresh()->status);
        $this->assertSame('sugerido', $mensaje->fresh()->status);
        $this->assertNotNull($mensaje->fresh()->pending_actions);
    }

    public function test_retener_una_copia_vieja_no_modifica_la_aprobacion_ya_enviada(): void
    {
        $lead = $this->lead('solicita_disponibilidad');
        $mensaje = $this->mensaje($lead);
        $copia = $mensaje->fresh();
        LeadMessage::where('id', $mensaje->id)->update(['status' => 'enviado', 'requiere_verificacion' => false, 'whatsapp_message_id' => 'aprobado-antes', 'sent_at' => now()]);
        $antes = $mensaje->fresh()->getAttributes();

        app(LeadAiSuggestionAutoSendScheduler::class)->retener_para_aprobacion_humana($copia);

        $this->assertSame($antes, $mensaje->fresh()->getAttributes());
        $this->assertSame('enviado', $copia->status);
        $this->assertFalse((bool) $copia->requiere_verificacion);
    }

    public function test_un_seguimiento_viejo_en_ventana_no_se_reprograma_ni_envia(): void
    {
        $lead = $this->lead('contactado');
        $mensaje = $this->mensaje($lead);
        $scheduler = app(LeadAiSuggestionAutoSendScheduler::class);
        $scheduler->schedule_for_suggested_message($mensaje);
        $job = Queue::pushed(AutoSendLeadAiSuggestionJob::class)->first();
        $mensaje->update(['is_followup' => true, 'requiere_verificacion' => true]);
        $lead->status = 'solicita_disponibilidad';
        $lead->save();
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-21 23:00:00', 'America/Argentina/Buenos_Aires'));
        try {
            $job->handle(new LeadSuggestionSendService($this->sin_envios()), $scheduler);
        } finally {
            \Carbon\Carbon::setTestNow();
        }
        Queue::assertPushed(AutoSendLeadAiSuggestionJob::class, 1);
        $this->assertSame('sugerido', $mensaje->fresh()->status);
        $this->assertNull($mensaje->fresh()->ai_auto_send_at);
    }

    private function sin_envios(): WhatsappSendService
    {
        $sender = \Mockery::mock(WhatsappSendService::class);
        $sender->shouldNotReceive('send_text');
        $sender->shouldNotReceive('send_template');
        return $sender;
    }

    private function lead(string $estado): Lead
    {
        return Lead::create(['uuid' => (string) Str::uuid(), 'phone' => '549351'.random_int(1000000,9999999), 'contact_name' => 'Contacto local', 'status' => $estado, 'claude_auto_reply' => true]);
    }

    private function mensaje(Lead $lead): LeadMessage
    {
        return LeadMessage::create(['lead_id' => $lead->id, 'sender' => 'sistema', 'status' => 'sugerido', 'content' => 'Respuesta local sin acciones', 'requiere_verificacion' => false, 'is_followup' => false]);
    }

    private function inbound(Lead $lead): void
    {
        LeadMessage::create(['lead_id' => $lead->id, 'sender' => 'lead', 'status' => 'enviado', 'content' => 'Consulta local', 'created_at' => now()]);
    }
}
