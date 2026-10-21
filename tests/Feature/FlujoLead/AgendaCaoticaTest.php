<?php

namespace Tests\Feature\FlujoLead;

use App\Helpers\AppTime;
use App\Mail\LeadDemoAccesoMail;
use App\Models\AdminSetting;
use App\Models\AiSystemPrompt;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\SyncedGithubFile;
use App\Services\AgendaDemoTemporalService;
use App\Services\DemoDirectaService;
use App\Services\LeadAiService;
use App\Services\LeadDemoSettings;
use App\Services\LeadWhatsappOnboardingSettings;
use App\Services\WhatsappProtocolService;
use Carbon\Carbon;
use Database\Seeders\LeadPipelineStatusGroupOrderSeeder;
use Database\Seeders\LeadPipelineStatusSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Autoridad del servidor y BD; respuestas IA sustituidas, ninguna prueba de calidad del modelo. */
class AgendaCaoticaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reloj('2026-10-21 12:00:00');
        Mail::fake();
        Queue::fake();
        Http::fake(['*' => Http::response(['ok' => true])]);
        $this->seed(LeadPipelineStatusSeeder::class);
        $this->seed(LeadPipelineStatusGroupOrderSeeder::class);
        config(['services.admin_spa.url' => 'https://admin.test']);
        foreach ([LeadDemoSettings::KEY_DURACION_MINUTOS => '60', LeadDemoSettings::KEY_GRACIA_MINUTOS_POST => '10', LeadDemoSettings::KEY_SETUP_MINUTOS_ANTES => '10', LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS => '2', LeadDemoSettings::KEY_BLOQUEO_REAL_MINUTOS => '120', LeadDemoSettings::KEY_DEMO_HORARIO_LUNES_VIERNES => '00:00-23:59', LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD => '1'] as $key => $valor) {
            AdminSetting::set($key, $valor);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @dataProvider demoras */
    public function test_aceptar_ahora_despues_no_caduca_por_el_minuto(int $minutos): void
    {
        $demo = $this->demo();
        $lead = $this->lead();
        $this->mensaje($lead, '¿La probamos ahora? Te la preparo en diez minutos.', 'sistema');
        $this->reloj('2026-10-21 12:' . sprintf('%02d', $minutos) . ':00');
        $this->mensaje($lead, 'Sí, dale');
        $accion = $this->normalizar($lead, ['dia' => 'hoy', 'hora' => '12:00']);
        $this->assertTrue($accion['ahora']);
        $msg = $this->pendiente($lead, $accion);
        (new LeadAiService())->apply_pending_actions($msg);
        $this->assertSame($demo->id, $lead->fresh()->demo_id);
        $this->assertSame('12:' . sprintf('%02d', $minutos + 10), substr((string) $lead->fresh()->demo_start_time, 0, 5));
        $this->assertSame('demo_agendada', $lead->fresh()->status);
    }

    public function demoras(): array { return [[1], [5], [15], [30]]; }

    public function test_manana_del_mensaje_no_se_vuelve_pasado_manana_cuando_llega_el_mail(): void
    {
        $lead = $this->lead(null);
        $this->reloj('2026-10-21 23:55:00');
        $pedido = $this->mensaje($lead, 'Mañana a las 10 puedo');
        $this->reloj('2026-10-22 00:02:00');
        $this->mensaje($lead, 'agenda@fixture.test');
        $accion = $this->normalizar($lead, ['dia' => 'manana', 'hora' => '10:00']);
        $this->assertSame('2026-10-22 10:00', $accion['inicio_resuelto']);
        $this->assertSame('hoy', $accion['dia']);
        $this->assertSame($pedido->id, $accion['evidencia_temporal']['mensaje_id']);
    }

    /** @dataProvider cambios */
    public function test_la_ultima_intencion_reemplaza_la_anterior(string $primero, string $ultimo, string $esperado): void
    {
        $this->demo();
        $lead = $this->lead();
        $this->mensaje($lead, $primero);
        $this->reloj('2026-10-21 12:05:00');
        $this->mensaje($lead, $ultimo);
        $accion = $this->normalizar($lead, ['dia' => 'manana', 'hora' => '10:00']);
        $this->assertSame($esperado, $accion['inicio_resuelto']);
        (new LeadAiService())->apply_pending_actions($this->pendiente($lead, $accion));
        $this->assertSame(substr($esperado, 0, 10), $lead->fresh()->demo_date->format('Y-m-d'));
        $this->assertSame(substr($esperado, -5), substr((string) $lead->fresh()->demo_start_time, 0, 5));
    }

    public function cambios(): array
    {
        return [['Mañana a las 10', 'Mejor hoy a las 15', '2026-10-21 15:00'], ['Hoy a las 15', 'Mejor mañana a las 10', '2026-10-22 10:00']];
    }

    /** @dataProvider esperas_dentro_del_turno */
    public function test_una_espera_dentro_del_turno_no_lo_reagenda(string $texto): void
    {
        $demo = $this->demo();
        $lead = $this->lead();
        $lead->update(['status' => 'demo_agendada', 'demo_id' => $demo->id, 'demo_date' => '2026-10-21', 'demo_start_time' => '12:10', 'demo_end_time' => '14:10']);
        $this->reloj('2026-10-21 12:11:00');
        $this->mensaje($lead, $texto);
        $msg = $this->generar($lead, ['mensaje_sugerido' => 'Dale, te espero en tu página.', 'agendar_demo' => ['ahora' => true]]);
        $this->assertEmpty($msg->pending_actions['agendar_demo']);
        (new LeadAiService())->apply_pending_actions($msg);
        $this->assertSame('12:10', substr((string) $lead->fresh()->demo_start_time, 0, 5));
        $this->assertSame('14:10', substr((string) $lead->fresh()->demo_end_time, 0, 5));
    }

    public function esperas_dentro_del_turno(): array { return [['Dame un minuto y entro'], ['Ahora sí puedo entrar']]; }

    public function test_la_ocupacion_de_ayer_sigue_bloqueando_hoy(): void
    {
        $demo = $this->demo();
        $ocupante = $this->lead();
        $ocupante->update(['status' => 'demo_agendada', 'demo_id' => $demo->id, 'demo_date' => '2026-10-21', 'demo_start_time' => '23:00', 'demo_end_time' => '23:59']);
        $lead = $this->lead();
        $this->reloj('2026-10-22 00:05:00');
        $servicio = new DemoDirectaService();
        $ventana = $servicio->ventana_desde();
        $this->assertNull($servicio->instancia_libre($lead, $ventana['inicio'], $ventana['fin']));
        $this->reloj('2026-10-22 01:01:00');
        $ventana = $servicio->ventana_desde();
        $this->assertSame($demo->id, $servicio->instancia_libre($lead, $ventana['inicio'], $ventana['fin'])['demo']->id);
    }

    public function test_dos_aceptaciones_con_la_misma_prevision_no_reservan_la_ultima_instancia_dos_veces(): void
    {
        $this->demo();
        $uno = $this->lead();
        $dos = $this->lead();
        $accion = ['ahora' => true];
        $msg1 = $this->pendiente($uno, $this->normalizar($uno, $accion));
        $msg2 = $this->pendiente($dos, $this->normalizar($dos, $accion));
        (new LeadAiService())->apply_pending_actions($msg1);
        try {
            (new LeadAiService())->apply_pending_actions($msg2);
            $this->fail('La última instancia ya estaba reservada.');
        } catch (\App\Exceptions\SinDemoLibreException $e) {
            $this->assertStringContainsString('ocupadas', $e->getMessage());
        }
        $this->assertNull($dos->fresh()->demo_id);
        $this->assertNotNull($uno->fresh()->demo_id);
    }

    public function test_un_pedido_incompleto_no_hereda_la_hora_anterior(): void
    {
        $lead = $this->lead();
        $this->mensaje($lead, 'Mañana a las 10');
        $this->mensaje($lead, 'Mejor hoy a la tarde');
        $this->assertTrue($this->normalizar($lead, ['dia' => 'manana', 'hora' => '10:00'])['inicio_ilegible']);
    }

    public function test_el_correo_citado_sin_accion_se_recupera_del_inbound_y_se_envia_una_sola_carta(): void
    {
        $demo = $this->demo();
        $lead = $this->lead(null);
        $fuente = $this->mensaje($lead, 'Dale ahora, agenda@fixture.test');
        $msg = $this->generar($lead, ['mensaje_sugerido' => 'Te mandé todo al mail agenda@fixture.test. ' . $lead->demo_experiencia_url . ' ' . DemoDirectaService::url_tienda($demo), 'agendar_demo' => ['ahora' => true], 'guardar_email' => null]);
        $this->assertSame('agenda@fixture.test', $msg->pending_actions['guardar_email']);
        $this->assertSame($fuente->id, $msg->pending_actions['evidencia_correo']['mensaje_id']);
        $this->assertNull($lead->fresh()->email);
        (new LeadAiService())->apply_pending_actions($msg);
        $this->assertSame('agenda@fixture.test', $lead->fresh()->email);
        Mail::assertSent(LeadDemoAccesoMail::class, 1);
    }

    public function test_la_prosa_sola_no_habilita_guardar_un_email(): void
    {
        $lead = $this->lead(null);
        $this->mensaje($lead, 'Dale, ahora puedo');
        $msg = $this->generar($lead, ['mensaje_sugerido' => 'Te mandé todo al mail inventado@fixture.test.', 'guardar_email' => null]);
        $this->assertEmpty($msg->pending_actions['guardar_email']);
        $this->assertNotEmpty($msg->pending_actions['conflicto_correo']);
        $this->assertTrue((bool) $msg->requiere_verificacion);
        $this->expectException(\InvalidArgumentException::class);
        (new LeadAiService())->apply_pending_actions($msg);
    }

    public function test_una_accion_de_email_inventado_se_retiene_antes_de_guardar_o_enviar(): void
    {
        $lead = $this->lead(null);
        $this->mensaje($lead, 'Dale, ahora puedo');
        $msg = $this->generar($lead, ['mensaje_sugerido' => 'Te mandé todo al mail inventado@fixture.test.', 'guardar_email' => 'inventado@fixture.test']);
        $this->assertNotEmpty($msg->pending_actions['conflicto_correo']);
        try {
            (new LeadAiService())->apply_pending_actions($msg, ['guardar_email' => 'inventado@fixture.test']);
            $this->fail('Aprobar la casilla precargada no acredita una corrección humana.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('no está respaldada', $e->getMessage());
        }
        $this->assertNull($lead->fresh()->email);
        Mail::assertNothingSent();
        (new LeadAiService())->apply_pending_actions($msg, ['guardar_email' => 'corregido@fixture.test']);
        $this->assertSame('corregido@fixture.test', $lead->fresh()->email);
    }

    public function test_dos_casillas_se_retienen_sin_efectos(): void
    {
        $lead = $this->lead(null);
        $this->mensaje($lead, 'Usá uno@fixture.test o dos@fixture.test');
        $msg = $this->generar($lead, ['mensaje_sugerido' => 'Te mando el mail.', 'guardar_email' => 'uno@fixture.test']);
        $this->assertNotEmpty($msg->pending_actions['conflicto_correo']);
        $this->assertNull($lead->fresh()->email);
        Mail::assertNothingSent();
    }

    public function test_cancelar_libera_y_reagendar_sin_capacidad_conserva_la_reserva_anterior(): void
    {
        $demo = $this->demo();
        $lead = $this->lead();
        $lead->update(['status' => 'demo_agendada', 'demo_id' => $demo->id, 'demo_date' => '2026-10-22', 'demo_start_time' => '10:00', 'demo_end_time' => '12:00']);
        $otro = $this->lead();
        $otro->update(['status' => 'demo_agendada', 'demo_id' => $demo->id, 'demo_date' => '2026-10-21', 'demo_start_time' => '12:00', 'demo_end_time' => '14:00']);
        $msg = $this->pendiente($lead, ['ahora' => true]);
        $acciones = $msg->pending_actions;
        $acciones['cancelar_demo'] = true;
        $msg->update(['pending_actions' => $acciones]);
        try {
            (new LeadAiService())->apply_pending_actions($msg);
            $this->fail('La instancia está ocupada.');
        } catch (\App\Exceptions\SinDemoLibreException $e) {
            $this->assertSame('2026-10-22', $lead->fresh()->demo_date->format('Y-m-d'));
            $this->assertSame('10:00', substr((string) $lead->fresh()->demo_start_time, 0, 5));
        }
        $acciones['agendar_demo'] = null;
        $acciones['estado_sugerido'] = 'calificado';
        $msg->update(['pending_actions' => $acciones]);
        (new LeadAiService())->apply_pending_actions($msg);
        $this->assertNull($lead->fresh()->demo_id);
        $this->assertNull($lead->fresh()->demo_date);
    }

    public function test_el_panel_no_pierde_la_guarda_de_un_horario_contradictorio(): void
    {
        $this->demo();
        $lead = $this->lead();
        $msg = $this->pendiente($lead, ['ahora' => true, 'conflicto_temporal' => true]);
        $this->expectException(\InvalidArgumentException::class);
        (new LeadAiService())->apply_pending_actions($msg, ['agendar_demo' => ['ahora' => true]]);
    }

    private function reloj(string $hora): void { Carbon::setTestNow(Carbon::parse($hora, DemoDirectaService::TZ)); }
    private function lead(?string $correo = 'agenda@fixture.test'): Lead
    {
        return Lead::create(['uuid' => (string) Str::uuid(), 'contact_name' => 'Comerciante ficticio', 'company_name' => 'Ferretería ficticia', 'status' => 'calificado', 'phone' => '5493510000000', 'email' => $correo]);
    }
    private function demo(): Demo
    {
        return Demo::create(['uuid' => (string) Str::uuid(), 'erp_spa_url' => 'https://erp.test', 'erp_api_url' => 'https://api-erp.test', 'ecommerce_spa_url' => 'https://tienda.test', 'ecommerce_api_url' => 'https://api-tienda.test']);
    }
    private function mensaje(Lead $lead, string $texto, string $sender = 'lead'): LeadMessage
    {
        return LeadMessage::create(['lead_id' => $lead->id, 'sender' => $sender, 'status' => 'enviado', 'content' => $texto, 'is_followup' => false, 'sent_at' => AppTime::now()]);
    }
    private function normalizar(Lead $lead, array $crudo): array
    {
        $metodo = new \ReflectionMethod(LeadAiService::class, 'normalizar_agendar_demo_directa');
        $metodo->setAccessible(true);
        return $metodo->invoke(new LeadAiService(), $crudo, $lead);
    }
    private function pendiente(Lead $lead, array $accion): LeadMessage
    {
        $texto = 'Dale, entrá desde tu página.';
        return LeadMessage::create(['lead_id' => $lead->id, 'sender' => 'sistema', 'status' => 'sugerido', 'is_followup' => false, 'requiere_verificacion' => true, 'content' => $texto, 'pending_actions' => ['mensaje_sugerido' => $texto, 'estado_sugerido' => 'demo_agendada', 'agendar_demo' => $accion]]);
    }
    private function generar(Lead $lead, array $campos): LeadMessage
    {
        config(['services.anthropic.api_key' => 'clave-local-ficticia']);
        AiSystemPrompt::create(['contenido' => 'System de prueba determinista.', 'activa' => true]);
        SyncedGithubFile::create(['key' => WhatsappProtocolService::SYSTEM_BASE_KEY, 'repo_path' => 'prueba', 'content' => 'System base de prueba.']);
        SyncedGithubFile::create(['key' => WhatsappProtocolService::RECURSO_KEY_PREFIX_V2 . 'demo_agenda', 'repo_path' => 'prueba', 'content' => LeadAiService::MARCADOR_DEMO_DIRECTA]);
        $respuesta = array_merge(['mensaje_sugerido' => 'Dale.', 'estado_sugerido' => $lead->demo_id ? $lead->status : 'demo_agendada', 'razonamiento' => 'Prueba determinista.'], $campos);
        Http::swap(new \Tests\Fakes\HttpFactorySinSalida());
        Http::fake(['api.anthropic.com/*' => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode($respuesta)]]]), '*' => Http::response(['ok' => true])]);
        return (new LeadAiService())->generate_suggestion($lead, false);
    }
}

