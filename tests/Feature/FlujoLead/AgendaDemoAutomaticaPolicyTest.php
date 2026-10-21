<?php

namespace Tests\Feature\FlujoLead;

use App\Helpers\AppTime;
use App\Jobs\AutoSendLeadAiSuggestionJob;
use App\Models\AdminSetting;
use App\Models\AiSystemPrompt;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\SyncedGithubFile;
use App\Events\LeadSuggestionCreated;
use App\Events\LeadConversationUpdated;
use App\Services\AgendaDemoAutomaticaPolicy;
use App\Services\AgendaDemoTemporalService;
use App\Services\DemoDirectaService;
use App\Services\LeadAiService;
use App\Services\LeadAiSuggestionAutoSendScheduler;
use App\Services\LeadConversationAiState;
use App\Services\LeadDemoSettings;
use App\Services\LeadSuggestionSendService;
use App\Services\LeadWhatsappOnboardingSettings;
use App\Services\WhatsappSendService;
use App\Services\WhatsappProtocolService;
use Carbon\Carbon;
use Database\Seeders\LeadPipelineStatusGroupOrderSeeder;
use Database\Seeders\LeadPipelineStatusSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
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
        $demo = $this->demo();
        $lead = $this->lead();
        $lead->update(['status' => 'solicita_disponibilidad']);
        $this->inbound($lead, 'Ahora sí puedo, agenda@fixture.test');
        Event::fake([LeadSuggestionCreated::class, LeadConversationUpdated::class]);
        $msg = $this->generar_desde_el_agente($lead, $demo);
        $this->assertFalse((bool) $msg->requiere_verificacion);
        $this->assertSame('sugerido', $msg->status);
        $this->assertTrue($msg->pending_actions['decision_agenda']['permitido']);
        $this->assertTrue($msg->pending_actions['agendar_demo']['ahora']);
        $this->assertSame('agenda@fixture.test', $msg->pending_actions['guardar_email']);
        $this->assertNull($lead->fresh()->demo_id, 'Generar no reserva ni envía correo.');
        $this->assertNull($lead->fresh()->email);
        $this->assertNull($msg->ai_auto_send_at, 'El job inmediato no necesita un timer futuro.');
        // Esta es la integración que faltaba: el generador tiene que haber encolado SOLO.
        Queue::assertPushed(AutoSendLeadAiSuggestionJob::class, 1);
        Event::assertDispatched(LeadSuggestionCreated::class, 1);
        Event::assertDispatched(LeadConversationUpdated::class);
        $scheduler = app(LeadAiSuggestionAutoSendScheduler::class);
        $job = Queue::pushed(AutoSendLeadAiSuggestionJob::class)->first();
        $sender = \Mockery::mock(WhatsappSendService::class);
        $sender->shouldReceive('send_text')->once()->andReturn('salida-ficticia');
        $job->handle(new LeadSuggestionSendService($sender), $scheduler);
        $this->assertSame('enviado', $msg->fresh()->status);
        $this->assertSame('12:10', substr((string) $lead->fresh()->demo_start_time, 0, 5));
        $this->assertFalse((bool) $lead->fresh()->requiere_verificacion_mensajes);
        $this->assertSame('agenda@fixture.test', $lead->fresh()->email);
        Queue::assertPushed(AutoSendLeadAiSuggestionJob::class, 1);
        $this->assertNull($msg->fresh()->sent_by_admin_id);
        $this->assertNull($msg->fresh()->actions_override_log);
    }

    public function test_una_consulta_de_ingreso_con_estado_redundante_no_pide_otra_reserva(): void
    {
        $this->activar(); Carbon::setTestNow(Carbon::parse('2026-10-21 16:29', DemoDirectaService::TZ));
        $demo = $this->demo(); $lead = $this->lead();
        $lead->update(['status' => 'demo_agendada', 'demo_id' => $demo->id, 'demo_date' => '2026-10-21', 'demo_start_time' => '16:36', 'demo_end_time' => '18:36']);
        $this->inbound($lead, 'Perfecto. Veo que empieza a las 16:36; ¿puedo entrar a las 16:37? Es sólo un minuto después.');
        $antes = $lead->fresh()->getAttributes();
        $msg = $this->generar_desde_el_agente($lead, $demo, ['mensaje_sugerido' => 'Sí, el botón se habilita a las 16:36 y podés entrar a las 16:37. La tenés reservada hasta las 18:36.',
            'estado_sugerido' => 'demo_agendada', 'requiere_verificacion' => false, 'tipo_respuesta' => 'conversacional']);
        $this->assertFalse((bool) $msg->requiere_verificacion);
        $this->assertTrue($msg->pending_actions['decision_agenda']['permitido']);
        $this->assertEmpty($msg->pending_actions['agendar_demo']);
        $antes['tiene_sugerencia_pendiente'] = 1;
        $this->assertSame($antes, $lead->fresh()->getAttributes());
        Queue::assertPushed(AutoSendLeadAiSuggestionJob::class, 1);
        Mail::assertNothingSent();
    }

    /** @dataProvider conservaciones_que_deben_seguir_retenidas */
    public function test_la_excepcion_de_estado_no_abre_conflictos_ni_guardas_manual(string $caso, string $consulta, array $cambios = [], array $acciones = [], ?string $previo = null): void
    {
        $this->activar(); Carbon::setTestNow(Carbon::parse('2026-10-21 16:29', DemoDirectaService::TZ));
        $demo = $this->demo(); $lead = $this->lead();
        $lead->update(array_merge(['status' => 'demo_agendada', 'demo_id' => $demo->id, 'demo_date' => '2026-10-21', 'demo_start_time' => '16:36', 'demo_end_time' => '18:36'], $cambios));
        if ($caso === 'instancia_ausente') { $demo->delete(); }
        if ($caso === 'escudo') { $lead->update(['requiere_verificacion_mensajes' => true]); }
        if ($caso === 'intervencion') { $lead->update(['requiere_intervencion_humana' => true]); }
        if (strpos($caso, 'ahora_') === 0) { Carbon::setTestNow(Carbon::parse('2026-10-21 17:00', DemoDirectaService::TZ)); }
        if ($previo !== null) {
            Carbon::setTestNow(Carbon::parse('2026-10-21 16:50', DemoDirectaService::TZ)); $this->inbound($lead, $previo);
            Carbon::setTestNow(Carbon::parse('2026-10-21 17:00', DemoDirectaService::TZ));
        }
        $this->inbound($lead, $consulta);
        if ($previo !== null) {
            $pedido = app(AgendaDemoTemporalService::class)->pedido($lead);
            $this->assertSame('ambiguo', $pedido['tipo']);
            $this->assertSame((int) LeadMessage::where('lead_id', $lead->id)->where('sender', 'lead')->max('id'), $pedido['mensaje_id']);
        }
        $campos_reserva = array_flip(['demo_id', 'demo_date', 'demo_start_time', 'demo_end_time']);
        $reserva = array_intersect_key($lead->fresh()->getAttributes(), $campos_reserva);
        $msg = $this->generar_desde_el_agente($lead, $demo, array_merge(['mensaje_sugerido' => 'La reserva sigue como estaba.',
            'estado_sugerido' => $lead->status, 'requiere_verificacion' => false, 'tipo_respuesta' => 'conversacional'], $acciones));
        $this->assertFalse(app(AgendaDemoAutomaticaPolicy::class)->evaluar($lead->fresh(), $msg->pending_actions ?? [], $msg)['permitido'], $caso);
        Queue::assertNotPushed(AutoSendLeadAiSuggestionJob::class);
        $this->assertSame($reserva, array_intersect_key($lead->fresh()->getAttributes(), $campos_reserva));
        if (! in_array($caso, ['escudo', 'intervencion', 'postdemo'], true)) { $this->assertTrue((bool) $msg->requiere_verificacion, $caso); }
        Mail::assertNothingSent();
    }

    public function conservaciones_que_deben_seguir_retenidas(): array
    {
        $pregunta = '¿Puedo entrar a las 16:37?';
        return [
            ['sin_reserva', $pregunta, ['demo_id' => null, 'demo_date' => null, 'demo_start_time' => null, 'demo_end_time' => null]],
            ['incompleta', $pregunta, ['demo_end_time' => null]],
            ['invertida', $pregunta, ['demo_end_time' => '16:35']],
            ['vencida', 'Ya voy, un minuto', ['demo_start_time' => '14:00', 'demo_end_time' => '16:28']],
            ['instancia_ausente', $pregunta],
            ['otro_dia', '¿Puedo entrar mañana a las 16:37?'],
            ['fecha_en_palabras', '¿Puedo entrar el veintidós de octubre a las 16:37?'],
            ['fecha_numerica', '¿Puedo entrar el 22/10 a las 16:37?'],
            ['dia_suelto', '¿Puedo entrar el 22 a las 16:37?'],
            ['dia_en_palabras', '¿Puedo entrar el veintidós a las 16:37?'],
            ['semana_ajena', '¿Puedo entrar la próxima semana a las 16:37?'],
            ['otro_momento', 'Entro en otro momento.'],
            ['no_puedo', 'No puedo entrar.'],
            ['ya_no_voy', 'Ya no voy a entrar.'],
            ['no_quiero', 'No quiero ingresar.'],
            ['mas_tarde', 'Entro más tarde.'],
            ['ahora_fuera_de_ventana', 'Ahora entro a las 19:00.'],
            ['ahora_otro_dia', 'Ahora entro otro día.'],
            ['hora_ajena', '¿Puedo entrar a las 19:00?'],
            ['mover', 'Mejor movemos la demo a las 16:37 para entrar después.'],
            ['cancelar', 'Cancelá la demo, no voy a entrar.', [], ['cancelar_demo' => true]],
            ['otra_accion', $pregunta, [], ['guardar_nombre' => 'Otro negocio ficticio']],
            ['modelo_manual', $pregunta, [], ['requiere_verificacion' => true]],
            ['escudo', $pregunta], ['intervencion', $pregunta],
            ['postdemo', $pregunta, ['status' => 'demo_realizada']],
            'secuencia_no_puedo' => ['secuencia_no_puedo', 'No puedo entrar.', [], [], 'Ya voy, un minuto'],
            'secuencia_otro_momento' => ['secuencia_otro_momento', 'Entro en otro momento.', [], [], 'Ya voy, un minuto'],
            'secuencia_mas_tarde' => ['secuencia_mas_tarde', 'Entro más tarde.', [], [], 'Ya voy, un minuto'],
            'secuencia_ahora_no_puedo' => ['secuencia_ahora_no_puedo', 'No puedo entrar.', [], [], 'Ahora entro'],
            'secuencia_ahora_otro_momento' => ['secuencia_ahora_otro_momento', 'Entro en otro momento.', [], [], 'Ahora entro'],
            'secuencia_ahora_mas_tarde' => ['secuencia_ahora_mas_tarde', 'Entro más tarde.', [], [], 'Ahora entro'],
        ];
    }

    public function test_conservar_sin_hora_sigue_siendo_conversacion_sin_reservar_de_nuevo(): void
    {
        $this->activar(); $demo = $this->demo(); $lead = $this->lead();
        $lead->update(['status' => 'demo_agendada', 'demo_id' => $demo->id, 'demo_date' => '2026-10-21', 'demo_start_time' => '12:00', 'demo_end_time' => '14:00']);
        $this->inbound($lead, 'Ya voy, dame un minuto para entrar.');
        $msg = $this->generar_desde_el_agente($lead, $demo, ['mensaje_sugerido' => 'Tranquilo, te espero.', 'estado_sugerido' => 'demo_agendada']);
        $this->assertFalse((bool) $msg->requiere_verificacion); $this->assertTrue($msg->pending_actions['decision_agenda']['permitido']);
        $this->assertEmpty($msg->pending_actions['agendar_demo']); Queue::assertPushed(AutoSendLeadAiSuggestionJob::class, 1); Mail::assertNothingSent();
    }

    public function test_el_correo_posterior_solo_completa_la_intencion_temporal_anterior(): void
    {
        $lead = $this->lead(); $this->inbound($lead, 'Mañana a las 10:00 puedo hacer la demo.');
        $fuente = (int) LeadMessage::where('lead_id', $lead->id)->max('id');
        Carbon::setTestNow(now()->addMinute()); $this->inbound($lead, 'agenda@fixture.test');
        $pedido = app(AgendaDemoTemporalService::class)->pedido($lead);
        $this->assertSame('momento', $pedido['tipo']); $this->assertSame('2026-10-22 10:00', $pedido['inicio']);
        $this->assertSame($fuente, $pedido['mensaje_id']);
    }

    public function test_un_conflicto_nuevo_entre_programar_y_enviar_retiene_sin_negativa(): void
    {
        $this->activar();
        $demo = $this->demo();
        $lead = $this->lead();
        $this->inbound($lead, 'Ahora puedo');
        $msg = $this->generar_pendiente($lead);
        $scheduler = app(LeadAiSuggestionAutoSendScheduler::class);
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

    public function test_no_promete_un_correo_nuevo_si_el_modo_automatico_conserva_el_ya_guardado(): void
    {
        $this->activar();
        $lead = $this->lead();
        $lead->update(['email' => 'verificado@fixture.test']);
        $this->inbound($lead, 'Ahora puedo, mi correo nuevo es nuevo@fixture.test');
        $decision = app(AgendaDemoAutomaticaPolicy::class)->evaluar($lead->fresh(), ['guardar_email' => 'nuevo@fixture.test']);
        $this->assertFalse($decision['permitido']);
        $this->assertSame('correccion_correo_requiere_operador', $decision['motivo']);
        $this->assertSame('verificado@fixture.test', $lead->fresh()->email);
    }

    public function test_un_cambio_de_momento_entre_el_lease_y_el_lock_retiene_y_conserva_reserva(): void
    {
        $this->activar();
        $demo = $this->demo();
        $lead = $this->lead();
        $lead->update(['status' => 'demo_agendada', 'demo_id' => $demo->id, 'demo_date' => '2026-10-22', 'demo_start_time' => '12:00', 'demo_end_time' => '14:00']);
        $this->inbound($lead, 'Mañana a las 15 me sirve.');
        $msg = $this->generar_pendiente($lead, ['dia' => 'manana', 'hora' => '15:00', 'inicio_resuelto' => '2026-10-22 15:00'], ['cancelar_demo' => true]);
        $this->assertFalse((bool) $msg->requiere_verificacion);
        $this->intercalar_al_tomar_lock(function () use ($lead) { $this->inbound($lead, 'Mejor hoy a las 16'); });
        $sender = \Mockery::mock(WhatsappSendService::class);
        $sender->shouldNotReceive('send_text');
        (new LeadSuggestionSendService($sender))->send_suggestion($msg, null, null, true);
        $this->assertSame('sugerido', $msg->fresh()->status);
        $this->assertTrue((bool) $msg->fresh()->requiere_verificacion);
        $this->assertSame($msg->content, $msg->fresh()->content);
        $this->assertSame('2026-10-22', $lead->fresh()->demo_date->format('Y-m-d'));
        $this->assertSame('12:00', substr((string) $lead->fresh()->demo_start_time, 0, 5));
        $this->assertSame('Mejor hoy a las 16', LeadMessage::query()->where('lead_id', $lead->id)->where('sender', 'lead')->orderByDesc('id')->value('content'));
        Mail::assertNothingSent();
    }

    public function test_un_correo_nuevo_entre_el_lease_y_el_lock_no_guarda_ni_envia_el_anterior(): void
    {
        $this->activar();
        $this->demo();
        $lead = $this->lead();
        $this->inbound($lead, 'Ahora puedo, viejo@fixture.test');
        $msg = $this->generar_pendiente($lead, ['ahora' => true], ['guardar_email' => 'viejo@fixture.test']);
        $this->assertFalse((bool) $msg->requiere_verificacion);
        $this->intercalar_al_tomar_lock(function () use ($lead) { $this->inbound($lead, 'Corrección: usá nuevo@fixture.test'); });
        $sender = \Mockery::mock(WhatsappSendService::class);
        $sender->shouldNotReceive('send_text');
        (new LeadSuggestionSendService($sender))->send_suggestion($msg, null, null, true);
        $this->assertTrue((bool) $msg->fresh()->requiere_verificacion);
        $this->assertNull($lead->fresh()->email);
        $this->assertNull($lead->fresh()->demo_id);
        $this->assertSame('Corrección: usá nuevo@fixture.test', LeadMessage::query()->where('lead_id', $lead->id)->where('sender', 'lead')->orderByDesc('id')->value('content'));
        Mail::assertNothingSent();
    }

    public function test_cambiar_el_email_guardado_durante_el_lease_no_cambia_silenciosamente_el_destinatario(): void
    {
        $this->activar();
        $this->demo();
        $lead = $this->lead();
        $lead->update(['email' => 'anterior@fixture.test']);
        $this->inbound($lead, 'Ahora puedo');
        $msg = $this->generar_pendiente($lead, ['ahora' => true], ['guardar_email' => '']);
        $this->assertSame('anterior@fixture.test', $msg->pending_actions['destinatario_correo']);
        $this->assertFalse((bool) $msg->requiere_verificacion);
        $this->intercalar_al_tomar_lock(function () use ($lead) { Lead::query()->whereKey($lead->id)->update(['email' => 'actualizado@fixture.test']); });
        $sender = \Mockery::mock(WhatsappSendService::class);
        $sender->shouldNotReceive('send_text');
        (new LeadSuggestionSendService($sender))->send_suggestion($msg, null, null, true);
        $this->assertTrue((bool) $msg->fresh()->requiere_verificacion);
        $this->assertSame('actualizado@fixture.test', $lead->fresh()->email);
        $this->assertNull($lead->fresh()->demo_id);
        Mail::assertNothingSent();
    }

    /** Se intercalan escrituras reales de BD en el instante exacto de tomar el lock real. */
    private function intercalar_al_tomar_lock(callable $intercalar): void
    {
        $real = Cache::lock('demo_asignacion_directa', 8);
        $lock = new class($real, $intercalar) {
            private $real;
            private $intercalar;
            public function __construct($real, callable $intercalar) { $this->real = $real; $this->intercalar = $intercalar; }
            public function block($segundos) { ($this->intercalar)(); return $this->real->block($segundos); }
            public function release() { return $this->real->release(); }
        };
        $manager = Cache::getFacadeRoot();
        Cache::swap(new class($manager, $lock) {
            private $manager;
            private $lock;
            public function __construct($manager, $lock) { $this->manager = $manager; $this->lock = $lock; }
            public function lock($nombre, $segundos) { return $nombre === 'demo_asignacion_directa' ? $this->lock : $this->manager->lock($nombre, $segundos); }
            public function __call($metodo, $argumentos) { return $this->manager->{$metodo}(...$argumentos); }
        });
    }

    private function activar(): void { AdminSetting::set(AgendaDemoAutomaticaPolicy::KEY_ACTIVA, '1'); }
    private function generar_desde_el_agente(Lead $lead, Demo $demo, ?array $respuesta = null): LeadMessage
    {
        config(['services.anthropic.api_key' => 'clave-local-ficticia']);
        AiSystemPrompt::create(['contenido' => 'System de prueba determinista.', 'activa' => true]);
        SyncedGithubFile::create(['key' => WhatsappProtocolService::SYSTEM_BASE_KEY, 'repo_path' => 'prueba', 'content' => 'System base de prueba.']);
        SyncedGithubFile::create(['key' => WhatsappProtocolService::RECURSO_KEY_PREFIX_V2 . 'demo_agenda', 'repo_path' => 'prueba', 'content' => LeadAiService::MARCADOR_DEMO_DIRECTA]);
        $respuesta = $respuesta ?? ['mensaje_sugerido' => 'Te dejo tu página ' . $lead->demo_experiencia_url . ' y la tienda ' . DemoDirectaService::url_tienda($demo) . '. Te mandé todo al correo agenda@fixture.test.', 'estado_sugerido' => 'demo_agendada', 'guardar_email' => 'agenda@fixture.test', 'agendar_demo' => ['ahora' => true]];
        Http::swap(new \Tests\Fakes\HttpFactorySinSalida());
        Http::fake(['api.anthropic.com/*' => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode($respuesta)]]]), '*' => Http::response(['ok' => true])]);
        return (new LeadAiService())->generate_suggestion($lead, false);
    }
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
    private function generar_pendiente(Lead $lead, array $agendar = ['ahora' => true], array $extras = []): LeadMessage
    {
        $metodo = new \ReflectionMethod(LeadAiService::class, 'create_message_and_update_lead');
        $metodo->setAccessible(true);
        return $metodo->invoke(new LeadAiService(), $lead, array_merge(['mensaje_sugerido' => 'Dale, te dejo tu página.', 'estado_sugerido' => 'demo_agendada', 'agendar_demo' => $agendar], $extras), false, null);
    }
}
