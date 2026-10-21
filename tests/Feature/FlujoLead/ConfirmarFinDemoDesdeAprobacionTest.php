<?php

namespace Tests\Feature\FlujoLead;

use App\Helpers\AppTime;
use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\LeadMessage;
use App\Services\LeadAiService;
use App\Services\LeadSuggestionSendService;
use App\Services\LeadWhatsappOnboardingSettings;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Database\Seeders\LeadPipelineStatusGroupOrderSeeder;
use Database\Seeders\LeadPipelineStatusSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConfirmarFinDemoDesdeAprobacionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        $this->seed(LeadPipelineStatusSeeder::class);
        $this->seed(LeadPipelineStatusGroupOrderSeeder::class);
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '1');
    }

    /** @dataProvider cierres_validos */
    public function test_el_operador_confirma_el_fin_con_o_sin_paquete_y_conserva_la_reserva(string $estado, bool $con_paquete): void
    {
        $lead = $this->lead($estado);
        $message = $this->mensaje($lead, $con_paquete ? $this->paquete($estado) : null);
        $this->inbound($lead);
        $admin = $this->admin();
        $this->sender(true);
        $reserva = $lead->only(['demo_id', 'demo_date', 'demo_start_time', 'demo_end_time']);
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00', 'America/Argentina/Buenos_Aires'));
        try {
            $this->actingAs($admin, 'sanctum')->putJson($this->url($message), [
                'edited_content' => 'Confirmo que terminó. ¿Qué te pareció?',
                'final_actions' => ['confirmar_fin_demo' => true],
            ])->assertStatus(200);

            $lead->refresh();
            $message->refresh();
            $this->assertSame('demo_realizada', $lead->status);
            $this->assertTrue((bool) $lead->demo_terminada_confirmada);
            $this->assertSame(AppTime::now()->format('Y-m-d H:i:s'), $lead->demo_terminada_confirmada_at->format('Y-m-d H:i:s'));
            $this->assertEquals($reserva, $lead->only(array_keys($reserva)));
            $this->assertSame('enviado', $message->status);
            $this->assertSame((int) $admin->id, (int) $message->sent_by_admin_id);
            $this->assertTrue((bool) $message->marca_demo_terminada_confirmada);
            $this->assertNull($message->pending_actions);
            $this->assertContains('Confirmar que terminó la demo', $message->applied_actions_summary);
            $override = collect($message->actions_override_log)->firstWhere('campo', 'confirmar_fin_demo');
            $this->assertNotNull($override);
            $this->assertTrue($override['elegido_por_admin']);
            $this->assertTrue((bool) $lead->claude_auto_reply);
            $this->assertNull($lead->closer_notified_at);
            $this->assertSame(0, LeadCall::where('lead_id', $lead->id)->count());
            $this->assertNull($lead->google_event_id);
            $this->assertNull($lead->meet_url);
        } finally {
            Carbon::setTestNow();
        }
    }

    public static function cierres_validos(): array
    {
        return [
            ['demo_en_curso', true], ['demo_en_curso', false],
            ['demo_pendiente_de_terminar', true], ['demo_pendiente_de_terminar', false],
        ];
    }

    /** @dataProvider estados_invalidos */
    public function test_rechaza_otro_estado_antes_de_aplicar_cualquier_accion(string $estado): void
    {
        $lead = $this->lead($estado);
        $message = $this->mensaje($lead, $this->paquete($estado));
        $antes = $lead->fresh()->getAttributes();
        $this->sender(false);
        $this->actingAs($this->admin(), 'sanctum')->putJson($this->url($message), [
            'final_actions' => ['confirmar_fin_demo' => true, 'guardar_nombre' => 'No debe guardarse'],
        ])->assertStatus(422);
        $this->assertSame($antes, $lead->fresh()->getAttributes());
        $this->assertSame('sugerido', $message->fresh()->status);
        $this->assertNull($message->fresh()->actions_override_log);
    }

    public static function estados_invalidos(): array
    {
        return [['nuevo'], ['contactado'], ['calificado'], ['solicita_disponibilidad'],
            ['demo_agendada'], ['demo_pendiente_de_ingreso'], ['demo_realizada'], ['closer_activo']];
    }

    public function test_el_endpoint_exige_admin_autenticado(): void
    {
        $lead = $this->lead('demo_en_curso');
        $message = $this->mensaje($lead);
        $this->sender(false);
        $this->putJson($this->url($message), ['final_actions' => ['confirmar_fin_demo' => true]])->assertStatus(401);
        $this->assertFalse((bool) $lead->fresh()->demo_terminada_confirmada);
        $this->assertSame('sugerido', $message->fresh()->status);
    }

    /** @dataProvider valores_invalidos */
    public function test_no_acepta_valores_truthy_como_decision_humana($valor): void
    {
        $lead = $this->lead('demo_en_curso');
        $message = $this->mensaje($lead, $this->paquete($lead->status));
        $this->sender(false);
        $this->actingAs($this->admin(), 'sanctum')->putJson($this->url($message), [
            'final_actions' => ['confirmar_fin_demo' => $valor, 'guardar_nombre' => 'No debe guardarse'],
        ])->assertStatus(422);
        $this->assertSame('Contacto local', $lead->fresh()->contact_name);
        $this->assertFalse((bool) $lead->fresh()->demo_terminada_confirmada);
    }

    public static function valores_invalidos(): array
    {
        return [['true'], [1], [null], [['valor' => true]]];
    }

    public function test_false_desactiva_la_inferencia_de_fin_del_paquete(): void
    {
        $lead = $this->lead('demo_en_curso');
        $message = $this->mensaje($lead, $this->paquete($lead->status) + ['confirmar_fin_demo' => true]);
        $this->inbound($lead);
        $this->sender(true);
        $this->actingAs($this->admin(), 'sanctum')->putJson($this->url($message), [
            'final_actions' => ['confirmar_fin_demo' => false],
        ])->assertStatus(200);
        $this->assertSame('demo_en_curso', $lead->fresh()->status);
        $this->assertFalse((bool) $lead->fresh()->demo_terminada_confirmada);
        $override = collect($message->fresh()->actions_override_log)->firstWhere('campo', 'confirmar_fin_demo');
        $this->assertFalse($override['elegido_por_admin']);
    }

    public function test_la_aprobacion_vieja_sin_campo_conserva_la_inferencia_revisada(): void
    {
        $lead = $this->lead('demo_en_curso');
        $message = $this->mensaje($lead, $this->paquete($lead->status) + ['confirmar_fin_demo' => true]);
        $this->inbound($lead);
        $this->sender(true);
        $this->actingAs($this->admin(), 'sanctum')->putJson($this->url($message))->assertStatus(200);
        $this->assertSame('demo_realizada', $lead->fresh()->status);
        $this->assertTrue((bool) $lead->fresh()->demo_terminada_confirmada);
    }

    public function test_false_con_paquete_vacio_no_inventa_acciones(): void
    {
        $lead = $this->lead('demo_en_curso');
        $message = $this->mensaje($lead);
        $this->inbound($lead);
        $this->sender(true);
        $this->actingAs($this->admin(), 'sanctum')->putJson($this->url($message), [
            'final_actions' => ['confirmar_fin_demo' => false, 'guardar_nombre' => 'No se aplica sin paquete'],
        ])->assertStatus(200);
        $this->assertSame('Contacto local', $lead->fresh()->contact_name);
        $this->assertFalse((bool) $lead->fresh()->demo_terminada_confirmada);
        $this->assertNull($message->fresh()->actions_override_log);
    }

    public function test_el_autoenvio_no_ejecuta_la_decision_manual_aunque_tenga_admin_en_sesion(): void
    {
        $lead = $this->lead('demo_en_curso');
        $message = $this->mensaje($lead, $this->paquete($lead->status));
        $this->actingAs($this->admin(), 'sanctum');
        $this->sender(false);
        $this->expectException(\InvalidArgumentException::class);
        try {
            app(LeadSuggestionSendService::class)->send_suggestion($message, null, ['confirmar_fin_demo' => true], true);
        } finally {
            $this->assertSame('demo_en_curso', $lead->fresh()->status);
            $this->assertFalse((bool) $lead->fresh()->demo_terminada_confirmada);
            $this->assertSame('sugerido', $message->fresh()->status);
        }
    }

    public function test_no_se_ignora_la_accion_al_aprobar_una_plantilla(): void
    {
        $lead = $this->lead('demo_en_curso');
        $message = $this->mensaje($lead);
        $message->update(['is_followup' => true, 'followup_template_id' => 999999]);
        $this->sender(false);
        $this->actingAs($this->admin(), 'sanctum')->putJson($this->url($message), [
            'final_actions' => ['confirmar_fin_demo' => true],
        ])->assertStatus(422);
        $this->assertFalse((bool) $lead->fresh()->demo_terminada_confirmada);
    }

    public function test_el_servicio_relee_el_estado_aunque_la_relacion_este_vieja(): void
    {
        $lead = $this->lead('demo_en_curso');
        $message = $this->mensaje($lead, $this->paquete($lead->status));
        $message->load('lead');
        Lead::where('id', $lead->id)->update(['status' => 'contactado']);
        $this->actingAs($this->admin(), 'sanctum');
        $this->expectException(\InvalidArgumentException::class);
        try {
            app(LeadAiService::class)->apply_pending_actions($message, ['confirmar_fin_demo' => true, 'guardar_nombre' => 'No guardar']);
        } finally {
            $this->assertSame('Contacto local', $lead->fresh()->contact_name);
            $this->assertFalse((bool) $lead->fresh()->demo_terminada_confirmada);
        }
    }

    /** @dataProvider acciones_incompatibles */
    public function test_no_ejecuta_acciones_ocultas_de_agenda_o_closer_al_confirmar_fin(string $accion, $valor): void
    {
        $lead = $this->lead('demo_en_curso');
        $message = $this->mensaje($lead, $this->paquete($lead->status) + [$accion => $valor]);
        $antes = $lead->fresh()->getAttributes();
        $this->sender(false);
        $this->actingAs($this->admin(), 'sanctum')->putJson($this->url($message), [
            'final_actions' => ['confirmar_fin_demo' => true, 'guardar_nombre' => 'No guardar'],
        ])->assertStatus(422);
        $this->assertSame($antes, $lead->fresh()->getAttributes());
        $this->assertSame(0, LeadCall::where('lead_id', $lead->id)->count());
    }

    public static function acciones_incompatibles(): array
    {
        return [['agendar_demo', ['ahora' => true]], ['cancelar_demo', true],
            ['agendar_llamada_closer', ['inicio' => '2026-10-21 15:00']], ['confirmar_interes_closer', true],
            ['descartar_llamada_closer', true], ['confirmar_ingreso', true],
            ['marcar_no_ingreso', true], ['posponer_check_fin_demo', 20]];
    }

    /** @dataProvider paquetes_postdemo_previos */
    public function test_el_panel_nuevo_conserva_paquetes_previos_de_fin_y_postdemo(string $accion, $valor, string $estado_final): void
    {
        $lead = $this->lead('demo_en_curso');
        $message = $this->mensaje($lead, $this->paquete($lead->status) + ['confirmar_fin_demo' => true, $accion => $valor]);
        $this->inbound($lead);
        $this->sender(true);
        $this->actingAs($this->admin(), 'sanctum')->putJson($this->url($message), [
            'final_actions' => ['confirmar_fin_demo' => true],
        ])->assertStatus(200);
        $this->assertTrue((bool) $lead->fresh()->demo_terminada_confirmada);
        $this->assertSame($estado_final, $lead->fresh()->status);
        $this->assertSame('enviado', $message->fresh()->status);
        $this->assertSame(0, LeadCall::where('lead_id', $lead->id)->count());
        $this->assertNull($lead->fresh()->closer_notified_at);
    }

    public static function paquetes_postdemo_previos(): array
    {
        return [['confirmar_interes_closer', true, 'closer_activo'],
            ['descartar_llamada_closer', ['motivo' => 'Lo evalúa más adelante'], 'demo_realizada']];
    }

    private function admin(): Admin
    {
        return Admin::create(['name' => 'Operador local', 'email' => Str::uuid().'@comerciocity.test', 'password' => bcrypt('prueba-local')]);
    }

    private function lead(string $estado): Lead
    {
        return Lead::create(['uuid' => (string) Str::uuid(), 'phone' => '549351'.random_int(1000000, 9999999),
            'contact_name' => 'Contacto local', 'status' => $estado, 'claude_auto_reply' => true,
            'demo_date' => '2026-10-21', 'demo_start_time' => '11:19', 'demo_end_time' => '13:19']);
    }

    private function mensaje(Lead $lead, ?array $pending = null): LeadMessage
    {
        return LeadMessage::create(['lead_id' => $lead->id, 'sender' => 'sistema', 'status' => 'sugerido',
            'content' => 'Respuesta local revisada', 'requiere_verificacion' => true, 'is_followup' => false, 'pending_actions' => $pending]);
    }

    private function paquete(string $estado): array
    {
        return ['mensaje_sugerido' => 'Respuesta local revisada', 'estado_sugerido' => $estado];
    }

    private function inbound(Lead $lead): void
    {
        LeadMessage::create(['lead_id' => $lead->id, 'sender' => 'lead', 'status' => 'enviado', 'content' => 'Consulta local', 'created_at' => now()]);
    }

    private function sender(bool $enviar): void
    {
        $sender = \Mockery::mock(WhatsappSendService::class);
        $sender->shouldNotReceive('send_template');
        if ($enviar) {
            $sender->shouldReceive('send_text')->once()->andReturn('confirmacion-local');
        } else {
            $sender->shouldNotReceive('send_text');
        }
        $this->app->instance(LeadSuggestionSendService::class, new LeadSuggestionSendService($sender));
    }

    private function url(LeadMessage $message): string
    {
        return '/api/admin/lead-message/'.$message->id.'/approve-with-actions';
    }
}
