<?php

namespace Tests\Feature;

use App\Events\SupportAiSuggestionGenerating;
use App\Events\SupportAiSuggestionPending;
use App\Events\SupportAiSuggestionScheduled;
use App\Events\SupportMessageReceived;
use App\Events\SupportMessageRemoved;
use App\Jobs\AutoSendPendingSupportSuggestion;
use App\Jobs\SendSupportAiSuggestion;
use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientEmployee;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\RecursosCatalogoService;
use App\Services\SupportAiSettings;
use App\Services\SupportAiSuggestionScheduler;
use App\Services\SupportAiSuggestionService;
use App\Services\SupportCodexSettings;
use App\Services\SupportTourService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ToursDeSoporteTest extends TestCase
{
    use DatabaseTransactions;

    private const CLAUDE = '/api/claude/support-codex/tickets/';
    private const PUENTE = 'https://empresa-tours.test/api/admin-sync/support/tours';
    private const INBOUND = '/api/inbound/support/messages';
    private const GUION = ['tema' => 'crear_ticket', 'titulo' => 'Crear un ticket'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-08 11:00:00'));
        config(['services.claude_task_ingest.key' => 'clave-monitor-tour', 'services.admin_inbound_integration.require_api_key' => false]);
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, '2026-11-08 10:00:00');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AFTER_ID, '0');
        AdminSetting::set(SupportAiSettings::KEY_SUGGESTIONS_ENABLED, '1');
        AdminSetting::set(SupportAiSettings::KEY_AUTO_SEND_DELAY_SECONDS, '0');
        Event::fake([
            SupportMessageReceived::class, SupportMessageRemoved::class, SupportAiSuggestionGenerating::class,
            SupportAiSuggestionPending::class, SupportAiSuggestionScheduled::class,
        ]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_whatsapp_reintenta_con_el_uuid_del_entrante_y_no_envia_un_destinatario_del_prompt(): void
    {
        [$ticket, $entrante] = $this->ticket_whatsapp($this->cliente(['asistente_phone' => '+5493519843001']));
        $headers = $this->reserva($ticket, $entrante);
        Http::fake([self::PUENTE => Http::response(['ok' => true, 'uuid' => $entrante->uuid])]);
        $body = self::GUION + ['expected_message_id' => $entrante->id, 'user_id' => 412];

        for ($intento = 0; $intento < 2; $intento++) {
            $this->postJson(self::CLAUDE . $ticket->id . '/tour', $body, $headers)
                ->assertOk()->assertExactJson(['ok' => true, 'uuid' => $entrante->uuid]);
        }
        Http::assertSentCount(2);
        Http::assertSent(function ($request) use ($ticket, $entrante) {
            return $request->hasHeader('X-Admin-Api-Key', 'clave-empresa') && $request->data() === self::GUION + [
                'uuid' => $entrante->uuid, 'ticket_uuid' => $ticket->uuid, 'source' => 'whatsapp',
            ];
        });
    }

    public function test_whatsapp_envia_empleado_sincronizado_con_su_numero_actual_y_rechaza_coincidencias_ambiguas(): void
    {
        $cliente = $this->cliente();
        $empleado = ClientEmployee::create(['client_id' => $cliente->id, 'name' => 'Empleado', 'phone' => '3519843002', 'empresa_employee_id' => 33]);
        [$ticket, $entrante] = $this->ticket_whatsapp($cliente, '+5493519843002', $empleado);
        $headers = $this->reserva($ticket, $entrante);
        Http::fake([self::PUENTE => Http::response(['ok' => true, 'uuid' => $entrante->uuid])]);
        $body = self::GUION + ['expected_message_id' => $entrante->id];

        $this->postJson(self::CLAUDE . $ticket->id . '/tour', $body, $headers)->assertOk();
        Http::assertSent(function ($request) {
            return $request['user_id'] === 33 && $request['recipient_phone'] === '5493519843002';
        });
        $this->cliente(['phone' => '+5493519843002']);
        $this->postJson(self::CLAUDE . $ticket->id . '/tour', $body, $headers)->assertStatus(409)->assertJsonPath('code', 'tour_recipient_unresolved');
        Http::assertSentCount(1);
    }

    public function test_lease_revision_y_frente_anterior_no_confirman_un_tour(): void
    {
        [$ticket, $entrante] = $this->ticket_whatsapp($this->cliente());
        $headers = $this->reserva($ticket, $entrante);
        $body = self::GUION + ['expected_message_id' => $entrante->id];
        Http::fake([self::PUENTE => Http::response([], 404)]);

        $this->postJson(self::CLAUDE . $ticket->id . '/tour', $body, ['X-Claude-Task-Key' => 'clave-monitor-tour'])->assertStatus(423);
        $ticket->update(['requiere_verificacion_mensajes' => true]);
        $this->postJson(self::CLAUDE . $ticket->id . '/tour', $body, $headers)->assertStatus(409)->assertJsonPath('code', 'verification_required');
        Http::assertNothingSent();

        $ticket->update(['requiere_verificacion_mensajes' => false]);
        $this->postJson(self::CLAUDE . $ticket->id . '/tour', $body, $headers)->assertStatus(409)->assertJsonPath('code', 'tour_unsupported');
        SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'body' => 'Otra consulta.', 'kind' => 'text']);
        $this->postJson(self::CLAUDE . $ticket->id . '/tour', $body, $headers)->assertStatus(409);
        Http::assertSentCount(1);
    }

    public function test_erp_anterior_o_sin_firma_no_encola_y_un_entrante_firmado_no_se_procesa_dos_veces(): void
    {
        $cliente = $this->cliente();
        $body = $this->mensaje_erp($cliente);
        $this->postJson(self::INBOUND, array_diff_key($body, ['robotito_support_version' => true]))->assertCreated();
        $body['message_uuid'] = (string) Str::uuid();
        $this->postJson(self::INBOUND, $body)->assertCreated();
        Queue::assertNotPushed(SendSupportAiSuggestion::class);

        $body['message_uuid'] = (string) Str::uuid();
        $this->postJson(self::INBOUND, $body, ['X-Admin-Api-Key' => 'clave-inbound'])->assertCreated();
        $this->postJson(self::INBOUND, $body, ['X-Admin-Api-Key' => 'clave-inbound'])->assertOk();
        Queue::assertPushed(SendSupportAiSuggestion::class, 1);
    }

    public function test_reintenta_la_programacion_de_una_consulta_firmada_guardada_sin_duplicar_el_mensaje(): void
    {
        $body = $this->mensaje_erp($this->cliente());
        $headers = ['X-Admin-Api-Key' => 'clave-inbound'];
        $this->mock(SupportAiSuggestionScheduler::class)->shouldReceive('schedule_after_client_inbound')->once()
            ->andThrow(new \RuntimeException('La cola está caída.'));
        $this->postJson(self::INBOUND, $body, $headers)->assertStatus(503);
        $entrante = SupportMessage::where('uuid', $body['message_uuid'])->firstOrFail();
        $this->assertSame(['inbound_version' => 1], $entrante->erp_auto_reply);
        Queue::assertNotPushed(SendSupportAiSuggestion::class);

        $this->app->forgetInstance(SupportAiSuggestionScheduler::class);
        $this->postJson(self::INBOUND, $body, $headers)->assertOk()->assertJsonPath('model.id', $entrante->id);
        $this->postJson(self::INBOUND, $body, $headers)->assertOk()->assertJsonPath('model.id', $entrante->id);
        Queue::assertPushed(SendSupportAiSuggestion::class, 1);
        $this->assertNotEmpty($entrante->fresh()->erp_auto_reply['scheduled_at']);
    }

    public function test_un_reintento_firmado_no_agenda_un_entrante_superado_ni_convierte_un_mensaje_sin_firma(): void
    {
        $body = $this->mensaje_erp($this->cliente());
        $headers = ['X-Admin-Api-Key' => 'clave-inbound'];
        $this->mock(SupportAiSuggestionScheduler::class)->shouldReceive('schedule_after_client_inbound')->once()
            ->andThrow(new \RuntimeException('La cola está caída.'));
        $this->postJson(self::INBOUND, $body, $headers)->assertStatus(503);
        $posterior = array_replace($body, ['message_uuid' => (string) Str::uuid()]);
        $this->postJson(self::INBOUND, $posterior)->assertCreated();

        $this->app->forgetInstance(SupportAiSuggestionScheduler::class);
        $this->postJson(self::INBOUND, $body, $headers)->assertOk();
        $this->postJson(self::INBOUND, $posterior, $headers)->assertOk();
        Queue::assertNotPushed(SendSupportAiSuggestion::class);
    }

    public function test_erp_ofrece_robotito_y_agrega_el_aviso_solo_con_confirmacion(): void
    {
        $cliente = $this->cliente();
        $body = $this->mensaje_erp($cliente);
        $this->postJson(self::INBOUND, $body, ['X-Admin-Api-Key' => 'clave-inbound'])->assertCreated();
        Http::fake([
            self::PUENTE => Http::response(['ok' => true, 'uuid' => $body['message_uuid']]),
            'empresa-tours.test/api/admin-sync/support/messages' => Http::response(['ok' => true]),
        ]);
        $this->simular_agente();
        app()->call([Queue::pushed(SendSupportAiSuggestion::class)->first(), 'handle']);

        Http::assertSentCount(2);
        Http::assertSent(function ($request) use ($body) {
            return $request->url() === self::PUENTE && $request['source'] === 'sistema' && $request['user_id'] === 17
                && $request['uuid'] === $body['message_uuid'];
        });
        $respuesta = $this->respuesta_erp($body);
        $this->assertNotNull($respuesta->synced_to_client_at);
        $this->assertStringContainsString(SupportTourService::AVISO, $respuesta->body);
        $this->assertStringContainsString('https://recursos.comerciocity.com/?v=T0.1', $respuesta->body);
        Http::assertSent(function ($request) use ($respuesta) {
            return $request->url() === 'https://empresa-tours.test/api/admin-sync/support/messages'
                && $request['body'] === $respuesta->body && $request['message_uuid'] === $respuesta->uuid;
        });
    }

    public function test_erp_demorado_guarda_el_plan_y_mantiene_la_respuesta_si_el_frente_es_anterior(): void
    {
        AdminSetting::set(SupportAiSettings::KEY_AUTO_SEND_DELAY_SECONDS, '30');
        $body = $this->mensaje_erp($this->cliente());
        $this->postJson(self::INBOUND, $body, ['X-Admin-Api-Key' => 'clave-inbound'])->assertCreated();
        Http::fake([self::PUENTE => Http::response([], 404), 'empresa-tours.test/api/admin-sync/support/messages' => Http::response(['ok' => true])]);
        $this->simular_agente();
        app()->call([Queue::pushed(SendSupportAiSuggestion::class)->first(), 'handle']);
        $borrador = $this->respuesta_erp($body);
        $this->assertTrue($borrador->is_ai_suggestion_draft);
        $this->assertSame(self::GUION, $borrador->erp_auto_reply['tour']);
        Http::assertNothingSent();

        Carbon::setTestNow(now()->addSeconds(31));
        app()->call([Queue::pushed(AutoSendPendingSupportSuggestion::class)->first(), 'handle']);
        $respuesta = $borrador->fresh();
        $this->assertFalse($respuesta->is_ai_suggestion_draft);
        $this->assertNull($respuesta->erp_auto_reply);
        $this->assertStringNotContainsString('Te mandé a Robotito', $respuesta->body);
        $this->assertStringContainsString('https://recursos.comerciocity.com/?v=T0.1', $respuesta->body);
    }

    public function test_erp_con_revision_no_ofrece_tour_ni_envia_respuesta_automatica(): void
    {
        $body = $this->mensaje_erp($this->cliente());
        $this->postJson(self::INBOUND, $body, ['X-Admin-Api-Key' => 'clave-inbound'])->assertCreated();
        SupportTicket::where('uuid', $body['ticket_uuid'])->update(['requiere_verificacion_mensajes' => true]);
        Http::fake();
        $this->simular_agente();
        app()->call([Queue::pushed(SendSupportAiSuggestion::class)->first(), 'handle']);
        $borrador = $this->respuesta_erp($body);
        $this->assertTrue($borrador->is_ai_suggestion_draft);
        $this->assertNull($borrador->erp_auto_reply['tour']);
        $this->assertStringNotContainsString('Te mandé a Robotito', $borrador->body);
        Http::assertNothingSent();
    }

    public function test_erp_aprueba_y_sincroniza_el_texto_editado_sin_ofrecer_tour(): void
    {
        $body = $this->mensaje_erp($this->cliente());
        $this->postJson(self::INBOUND, $body, ['X-Admin-Api-Key' => 'clave-inbound'])->assertCreated();
        SupportTicket::where('uuid', $body['ticket_uuid'])->update(['requiere_verificacion_mensajes' => true]);
        Http::fake(['empresa-tours.test/api/admin-sync/support/messages' => Http::response(['ok' => true])]);
        $this->simular_agente();
        app()->call([Queue::pushed(SendSupportAiSuggestion::class)->first(), 'handle']);
        $borrador = $this->respuesta_erp($body);
        $admin = Admin::create(['name' => 'Operador', 'email' => 'operador-aprueba-tour@test.local', 'password' => bcrypt('test')]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-message/' . $borrador->id . '/approve-ai-draft', ['body' => 'Texto revisado.'])
            ->assertOk()->assertJsonPath('delivered', true)->assertJsonPath('model.body', 'Texto revisado.');
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($borrador) {
            $actual = $borrador->fresh();
            return $request['body'] === 'Texto revisado.' && $request['message_uuid'] === $actual->uuid
                && $actual->body === 'Texto revisado.' && ! $actual->is_ai_suggestion_draft && $actual->erp_auto_reply === null;
        });
    }

    public function test_el_agente_web_agrega_solo_el_video_verificado_por_la_herramienta(): void
    {
        config(['services.anthropic.api_key' => 'clave-falsa']);
        $ticket = SupportTicket::create(['client_id' => $this->cliente()->id, 'client_user_id' => 17, 'source' => 'erp', 'status' => 'open']);
        $this->mock(RecursosCatalogoService::class)->shouldReceive('catalogo')->once()->andReturn(['videos' => [[
            'codigo' => 'T0.4', 'titulo' => 'Crear un ticket de soporte', 'para_que' => 'Pedir ayuda desde el sistema', 'url' => 'https://cdn.test/video.mp4',
        ]]]);
        $fin = [
            'suggested_message' => 'Abrí Soporte y tocá Nuevo ticket.', 'tipo_respuesta' => 'afirmacion_del_sistema',
            'fuentes_kb' => ['manual_sistema/general/soporte-tecnico.md'], 'tour' => self::GUION,
        ];
        Http::fakeSequence('api.anthropic.com/*')->push([
            'stop_reason' => 'tool_use', 'content' => [
                ['type' => 'tool_use', 'id' => 'manual', 'name' => 'get_manual_file', 'input' => ['path' => 'manual_sistema/general/soporte-tecnico.md']],
                ['type' => 'tool_use', 'id' => 'video', 'name' => 'buscar_video_de_ayuda', 'input' => ['consulta' => 'crear ticket de soporte']],
            ],
        ])->push([
            'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode($fin + ['video_codigo' => 'T0.4'])]],
        ])->push([
            'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode($fin + ['video_codigo' => 'T0.4'])]],
        ]);
        $agente = new class extends SupportAiSuggestionService {
            protected function fetch_manual_file_list(): string
            {
                return '- manual_sistema/general/soporte-tecnico.md';
            }

            protected function fetch_escalation_rules(): string
            {
                return 'Protocolo de prueba.';
            }

            protected function github_get_file(string $path): string
            {
                return 'Abrí Soporte y tocá Nuevo ticket.';
            }
        };
        $respuesta = $agente->generate($ticket);
        $this->assertSame(self::GUION, $respuesta['tour']);
        $this->assertStringContainsString('https://recursos.comerciocity.com/?v=T0.4', $respuesta['suggested_message']);
        $this->assertStringNotContainsString('https://recursos.comerciocity.com/', $agente->generate($ticket)['suggested_message']);
        Http::assertSent(function ($request) {
            return strpos($request['system'][0]['text'], 'buscar_video_de_ayuda') !== false
                && in_array('buscar_video_de_ayuda', array_column($request['tools'], 'name'), true);
        });
    }

    public function test_un_envio_erp_automatico_fallido_se_reintenta_desde_la_bandeja_con_el_mismo_uuid(): void
    {
        config(['services.client_api.retries' => 1]);
        $body = $this->mensaje_erp($this->cliente());
        $this->postJson(self::INBOUND, $body, ['X-Admin-Api-Key' => 'clave-inbound'])->assertCreated();
        Http::fake([self::PUENTE => Http::response([], 404)]);
        Http::fakeSequence('empresa-tours.test/api/admin-sync/support/messages')->push([], 500)->push(['ok' => true]);
        $this->simular_agente();
        app()->call([Queue::pushed(SendSupportAiSuggestion::class)->first(), 'handle']);
        $respuesta = $this->respuesta_erp($body);
        $this->assertSame('not_received', $respuesta->remote_delivery_status);

        $admin = Admin::create(['name' => 'Operador', 'email' => 'operador-reintento-tours@test.local', 'password' => bcrypt('test')]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-message/' . $respuesta->id . '/retry-remote-sync')
            ->assertOk()->assertJsonPath('model.uuid', $respuesta->uuid)->assertJsonPath('model.remote_delivery_status', null);
        $this->assertNotNull($respuesta->fresh()->synced_to_client_at);
        $this->assertCount(2, Http::recorded(function ($request) use ($respuesta) {
            return $request->url() === 'https://empresa-tours.test/api/admin-sync/support/messages' && $request['message_uuid'] === $respuesta->uuid;
        }));
    }

    public function test_seguimiento_usa_el_ticket_del_operador_y_marca_frente_anterior(): void
    {
        [$ticket] = $this->ticket_whatsapp($this->cliente());
        $url = '/api/admin/support-ticket/' . $ticket->id . '/tours';
        $this->getJson($url)->assertUnauthorized();
        $admin = Admin::create(['name' => 'Operador', 'email' => 'operador-tours@test.local', 'password' => bcrypt('test')]);
        $this->actingAs($admin, 'sanctum');
        Http::fakeSequence(self::PUENTE . '/' . $ticket->uuid)->push(['model' => [['uuid' => 'tour', 'state' => 'completed']]])->push([], 404);
        $this->getJson($url)->assertOk()->assertJsonPath('available', true)->assertJsonPath('model.0.state', 'completed');
        $this->getJson($url)->assertOk()->assertExactJson(['available' => false, 'model' => []]);
    }

    private function cliente(array $extra = []): Client
    {
        return Client::create($extra + [
            'name' => 'Comercio tours', 'phone' => '+5493419843001', 'is_active' => true, 'api_url' => 'https://empresa-tours.test',
            'api_key' => 'clave-empresa', 'inbound_api_key' => 'clave-inbound',
        ]);
    }

    private function ticket_whatsapp(Client $cliente, string $telefono = '+5493419843001', ?ClientEmployee $empleado = null): array
    {
        $ticket = SupportTicket::create([
            'client_id' => $cliente->id, 'client_employee_id' => optional($empleado)->id, 'client_user_id' => 0,
            'status' => 'open', 'source' => 'whatsapp', 'whatsapp_phone' => $telefono, 'opened_at' => now(), 'last_client_message_at' => now(),
        ]);
        $entrante = SupportMessage::create([
            'support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text', 'body' => '¿Cómo hago un ticket?',
            'delivered_at' => now(), 'whatsapp_remitente_crudo' => ltrim($telefono, '+'),
        ]);
        return [$ticket, $entrante];
    }

    private function reserva(SupportTicket $ticket, SupportMessage $entrante): array
    {
        $headers = ['X-Claude-Task-Key' => 'clave-monitor-tour'];
        $respuesta = $this->postJson(self::CLAUDE . $ticket->id . '/claim', ['worker_id' => 'tour-tests', 'expected_message_id' => $entrante->id], $headers)->assertOk();
        return $headers + ['X-Support-Lease-Token' => $respuesta->json('lease_token')];
    }

    private function mensaje_erp(Client $cliente): array
    {
        return [
            'client_uuid' => $cliente->uuid, 'ticket_uuid' => (string) Str::uuid(), 'message_uuid' => (string) Str::uuid(),
            'client_user_id' => 17, 'sender_user_id' => 17, 'sender_type' => 'user', 'kind' => 'text',
            'body' => '¿Cómo creo un ticket?', 'robotito_support_version' => 1,
        ];
    }

    private function simular_agente(): void
    {
        $this->mock(SupportAiSuggestionService::class)->shouldReceive('generate')->once()->andReturn([
            'suggested_message' => "Abrí Soporte y tocá Nuevo ticket.\nhttps://recursos.comerciocity.com/?v=T0.1",
            'tour' => self::GUION,
        ]);
    }

    private function respuesta_erp(array $body): SupportMessage
    {
        return SupportMessage::whereHas('ticket', function ($ticket) use ($body) {
            $ticket->where('uuid', $body['ticket_uuid']);
        })->where('sender_type', 'admin')->firstOrFail();
    }
}
