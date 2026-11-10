<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Jobs\SendSupportAiSuggestion;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\SupportAiSettings;
use App\Services\SupportAiSuggestionDeliveryService;
use App\Services\SupportClientSyncService;
use App\Services\SupportCodexSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class AgentesEnEmpresaTest extends BaseDelCanal
{
    private const INBOUND = '/api/inbound/agentes';
    private const CODEX = '/api/claude/support-codex';

    public function test_martin_toma_un_ticket_del_sistema_anterior_y_entrega_una_vez_en_empresa(): void
    {
        $cliente = $this->comercio();
        $clave = $this->activar_martin_para_erp();
        $anterior = $this->ticket_whatsapp($cliente, [
            'source' => 'erp', 'whatsapp_phone' => null, 'last_client_message_at' => null,
        ]);
        $this->mensaje($anterior, 'Consulta histórica', ['created_at' => now()->subHours(2)]);
        $ticket = $this->ticket_whatsapp($cliente, [
            'source' => 'erp', 'whatsapp_phone' => null, 'last_client_message_at' => null,
            'client_user_id' => $cliente->user_id,
        ]);
        $entrada = $this->mensaje($ticket, 'Necesito ayuda con una venta.', [
            'erp_auto_reply' => [SupportMessage::MARCA_ERP_VERIFICADO_PARA_CODEX => true],
        ]);

        $this->getJson(self::CODEX . '/pending', $clave)->assertOk()->assertJsonCount(1, 'tickets')
            ->assertJsonPath('tickets.0.id', $ticket->id)->assertJsonPath('tickets.0.canal', 'sistema');
        $ruta = self::CODEX . '/tickets/' . $ticket->id;
        $reserva = $this->postJson($ruta . '/claim', [
            'worker_id' => 'codex-erp-anterior-test', 'expected_message_id' => $entrada->id,
        ], $clave)->assertOk()->assertJsonPath('ticket.canal', 'sistema')
            ->assertJsonPath('ticket.window.open', null)->assertJsonPath('ticket.contact_role', 'employee')
            ->assertJsonPath('ticket.can_query_system', false)->assertJsonCount(0, 'ticket.previous_tickets');

        $respuesta = [
            'lease_token' => $reserva->json('lease_token'), 'expected_message_id' => $entrada->id,
            'operation_id' => 'respuesta-erp-anterior-' . $ticket->id, 'action' => 'responder',
            'body' => 'Te contesto desde el chat de soporte del sistema.',
        ];
        $this->postJson($ruta . '/reply', $respuesta, $clave)->assertOk()->assertJsonPath('operation.status', 'sent');
        $this->postJson($ruta . '/reply', $respuesta, $clave)->assertOk()->assertJsonPath('operation.status', 'sent');
        $this->getJson(self::CODEX . '/pending', $clave)->assertOk()->assertJsonCount(0, 'tickets');
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($ticket, $respuesta) {
            return strpos($request->url(), '/api/admin-sync/support/messages') !== false
                && $request['ticket_uuid'] === $ticket->uuid && $request['body'] === $respuesta['body'];
        });
    }

    public function test_un_entrante_erp_anterior_va_a_martin_sin_programar_ni_entregar_robotito(): void
    {
        $cliente = $this->comercio();
        $clave = $this->activar_martin_para_erp();
        AdminSetting::set(SupportAiSettings::KEY_SUGGESTIONS_ENABLED, '1');
        Queue::fake();
        $datos = $this->datos_entrante($cliente, (string) Str::uuid());
        unset($datos['agentes_version']);
        $datos['robotito_support_version'] = 1;
        $entrada = $this->postJson('/api/inbound/support/messages', $datos, $this->clave($cliente))->assertCreated();
        $mensaje = SupportMessage::findOrFail($entrada->json('model.id'));
        $ticket = $mensaje->ticket;
        $this->assertFalse($ticket->agentes_erp);
        $this->assertNotNull($ticket->last_client_message_at);
        $this->assertTrue($mensaje->erp_auto_reply[SupportMessage::MARCA_ERP_VERIFICADO_PARA_CODEX]);
        Queue::assertNotPushed(SendSupportAiSuggestion::class);
        $this->getJson(self::CODEX . '/pending', $clave)->assertOk()->assertJsonCount(1, 'tickets')
            ->assertJsonPath('tickets.0.id', $ticket->id);

        $borrador = $this->mensaje($ticket, 'Respuesta vieja de Robotito', [
            'sender_type' => 'admin', 'is_ai_suggestion_draft' => true, 'ai_auto_send_at' => now()->subMinute(),
            'erp_auto_reply' => ['message_id' => $mensaje->id],
        ]);
        $servicio = app(SupportAiSuggestionDeliveryService::class);
        $this->assertNull($servicio->deliver_erp_auto_draft($borrador, $ticket));
        $this->assertNull($servicio->deliver_erp_auto_reply($ticket, 'Respuesta que ya no corresponde.', $mensaje->id, null));
        $this->assertTrue($borrador->fresh()->is_ai_suggestion_draft);
        Http::assertNothingSent();
    }

    public function test_un_entrante_erp_sin_firma_queda_para_atencion_humana_y_no_se_acredita_con_un_replay(): void
    {
        $cliente = $this->comercio();
        $clave = $this->activar_martin_para_erp();
        $datos = $this->datos_entrante($cliente, (string) Str::uuid());
        unset($datos['agentes_version']);
        $entrada = $this->postJson('/api/inbound/support/messages', $datos)->assertCreated();
        $mensaje = SupportMessage::findOrFail($entrada->json('model.id'));
        $ticket = $mensaje->ticket;
        $ruta = self::CODEX . '/tickets/' . $ticket->id;
        $this->postJson('/api/inbound/support/messages', array_replace($datos, ['body' => 'Otro texto.']), $this->clave($cliente))
            ->assertOk()->assertJsonPath('model.id', $mensaje->id);
        $this->assertSame($datos['body'], $mensaje->fresh()->body);
        $this->assertNull($mensaje->fresh()->erp_auto_reply);

        $this->getJson(self::CODEX . '/pending', $clave)->assertOk()->assertJsonCount(0, 'tickets');
        $this->getJson($ruta, $clave)->assertOk()->assertJsonCount(0, 'ticket.messages');
        $bloqueada = $this->postJson($ruta . '/claim', ['worker_id' => 'codex-erp-test', 'expected_message_id' => $mensaje->id], $clave)
            ->assertStatus(409);
        $this->assertStringStartsWith('not_pending:', $bloqueada->json('message'));

        $datos['message_uuid'] = (string) Str::uuid();
        $datos['body'] = 'Consulta nueva con firma.';
        $firmado = $this->postJson('/api/inbound/support/messages', $datos, $this->clave($cliente))->assertCreated();
        $reserva = $this->postJson($ruta . '/claim', [
            'worker_id' => 'codex-erp-test', 'expected_message_id' => $firmado->json('model.id'),
        ], $clave)->assertOk()->assertJsonCount(1, 'ticket.messages')
            ->assertJsonPath('ticket.messages.0.body', 'Consulta nueva con firma.');

        $datos['message_uuid'] = (string) Str::uuid();
        $this->postJson('/api/inbound/support/messages', $datos)->assertCreated();
        $respuesta = $this->postJson($ruta . '/reply', [
            'lease_token' => $reserva->json('lease_token'), 'expected_message_id' => $firmado->json('model.id'),
            'operation_id' => 'respuesta-superada-' . $ticket->id, 'action' => 'responder', 'body' => 'Quedó superada.',
        ], $clave)->assertStatus(409);
        $this->assertStringStartsWith('stale_message:', $respuesta->json('message'));
        Http::assertNothingSent();
    }

    public function test_el_cierre_de_martin_en_erp_anterior_sincroniza_al_frente_activo(): void
    {
        $cliente = $this->comercio();
        $frente = $this->crear_client_api($cliente, 'https://frente-actual.test', 'vps');
        $ticket = $this->ticket_whatsapp($cliente, ['source' => 'erp', 'status' => 'closed', 'whatsapp_phone' => null]);

        $this->assertTrue(app(SupportClientSyncService::class)->sync_ticket_to_client($ticket, true));
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($frente, $ticket) {
            return $request->url() === $frente->url . '/api/admin-sync/support/tickets/' . $ticket->uuid
                && $request->method() === 'PUT' && $request['status'] === 'closed';
        });
    }

    public function test_el_primer_entrante_de_agentes_no_acredita_un_mensaje_previo_del_erp(): void
    {
        $cliente = $this->comercio();
        $clave = $this->activar_martin_para_erp();
        $datos = $this->datos_entrante($cliente, (string) Str::uuid());
        $sin_firma = array_diff_key($datos, ['agentes_version' => true]);
        $anterior = $this->postJson('/api/inbound/support/messages', $sin_firma)->assertCreated();

        $datos['message_uuid'] = (string) Str::uuid();
        $datos['body'] = 'Consulta desde la versión nueva.';
        $entrada = $this->postJson('/api/inbound/support/messages', $datos, $this->clave($cliente))->assertCreated();
        $mensaje = SupportMessage::findOrFail($entrada->json('model.id'));
        $this->assertTrue($mensaje->ticket->agentes_erp);
        $this->assertTrue($mensaje->erp_auto_reply[SupportMessage::MARCA_TRANSICION_ERP_PARA_CODEX]);
        $this->getJson(self::CODEX . '/tickets/' . $mensaje->support_ticket_id, $clave)->assertOk()
            ->assertJsonCount(1, 'ticket.messages')->assertJsonPath('ticket.messages.0.id', $mensaje->id);
        $this->assertNull(SupportMessage::findOrFail($anterior->json('model.id'))->erp_auto_reply);
    }

    public function test_el_historial_y_los_numeros_exigen_la_identidad_del_comercio_y_del_usuario(): void
    {
        $cliente = $this->comercio();
        $otro = $this->crear_cliente('+5493425202156');
        $ticket = $this->ticket_whatsapp($cliente);
        $ajeno = $this->ticket_whatsapp($otro);
        $empleado = $this->crear_empleado($cliente, '+5493415202156');
        $empleado->update(['empresa_employee_id' => $cliente->user_id + 1]);
        $de_empleado = $this->ticket_whatsapp($cliente, ['client_employee_id' => $empleado->id, 'whatsapp_phone' => $empleado->phone]);
        $principal = $this->crear_config_whatsapp();
        $principal->update(['display_phone' => '+54 9 3444 54-4199']);
        $this->crear_config_asistente(['display_phone' => '+1 (201) 586-6682']);
        SupportCodexSettings::set_enabled(true);

        $this->getJson($this->ruta($cliente, 'canales'))->assertUnauthorized();
        $this->getJson($this->ruta($otro, 'canales'), $this->clave($cliente))->assertUnauthorized();
        $this->getJson($this->ruta($cliente, 'canales'), $this->clave($cliente))
            ->assertExactJson(['asistente' => '12015866682', 'soporte' => '5493444544199']);
        $this->getJson($this->ruta($cliente, 'soporte'), $this->clave($cliente))->assertOk()
            ->assertJsonCount(1, 'models')->assertJsonPath('models.0.uuid', $ticket->uuid);
        $this->getJson($this->ruta($cliente, 'soporte/' . $ajeno->uuid), $this->clave($cliente))->assertNotFound();
        $this->getJson($this->ruta($cliente, 'soporte/' . $de_empleado->uuid), $this->clave($cliente))->assertNotFound();
        $this->getJson($this->ruta($cliente, 'soporte', ['client_user_id' => $empleado->empresa_employee_id, 'es_dueno' => 0]), $this->clave($cliente))
            ->assertOk()->assertJsonCount(1, 'models')->assertJsonPath('models.0.uuid', $de_empleado->uuid);

        $visible = $this->mensaje($ticket, 'Hola desde WhatsApp');
        $visible->attachments()->create(['disk' => 'local', 'path' => 'interno/cliente.pdf', 'mime' => 'application/pdf', 'size' => 250]);
        $this->mensaje($ticket, 'borrador interno', ['sender_type' => 'admin', 'is_ai_suggestion_draft' => true]);
        $this->mensaje($ticket, 'envío fallido', ['sender_type' => 'admin', 'remote_delivery_status' => 'not_received']);
        $datos = $this->getJson($this->ruta($cliente, 'soporte/' . $ticket->uuid), $this->clave($cliente))
            ->assertOk()->assertJsonCount(1, 'model.messages')
            ->assertJsonPath('model.messages.0.body', 'Hola desde WhatsApp')->json('model');
        $adjunto = $datos['messages'][0]['attachments'][0];
        $this->assertStringContainsString('signature=', $adjunto['public_url']);
        $this->assertArrayNotHasKey('path', $adjunto);
        $this->assertArrayNotHasKey('whatsapp_phone', $datos);
    }

    public function test_las_referencias_erp_solo_exponen_tickets_de_la_persona_y_el_comercio(): void
    {
        $cliente = $this->comercio();
        $otro = $this->crear_cliente('+5493425202156');
        $whatsapp = $this->ticket_whatsapp($cliente);
        $erp = $this->ticket_whatsapp($cliente, ['source' => 'erp', 'client_user_id' => $cliente->user_id]);
        $this->ticket_whatsapp($cliente, ['source' => 'erp', 'client_user_id' => $cliente->user_id + 1]);
        $this->ticket_whatsapp($otro, ['source' => 'erp', 'client_user_id' => $cliente->user_id]);
        $traspasado = $this->ticket_whatsapp($cliente, [
            'source' => 'erp', 'client_user_id' => $cliente->user_id, 'sistema_exclusivo_at' => now(),
        ]);

        $ruta = $this->ruta($cliente, 'soporte', ['solo_referencias_erp' => 1]);
        $this->getJson($ruta)->assertUnauthorized();
        $this->getJson($ruta, $this->clave($cliente))->assertOk()
            ->assertExactJson(['models' => [], 'ticket_numbers' => [$erp->uuid => $erp->id]]);

        $this->getJson($this->ruta($cliente, 'soporte'), $this->clave($cliente))->assertOk()
            ->assertJsonPath('ticket_numbers', [$erp->uuid => $erp->id])
            ->assertJsonPath('models.0.admin_ticket_id', $traspasado->id)
            ->assertJsonPath('models.1.admin_ticket_id', $whatsapp->id);
    }

    public function test_la_fecha_de_la_conversacion_solo_cambia_con_un_mensaje_visible(): void
    {
        $cliente = $this->comercio();
        $primero = $this->ticket_whatsapp($cliente, ['last_client_message_at' => now()]);
        $segundo = $this->ticket_whatsapp($cliente, ['last_client_message_at' => now()->subHour()]);
        $antiguo = now()->subMinutes(20)->startOfSecond();
        $reciente = now()->subMinutes(10)->startOfSecond();
        $mensaje = $this->mensaje($primero, 'Consulta anterior', ['created_at' => $antiguo]);
        $this->mensaje($segundo, 'Respuesta entregada', [
            'sender_type' => 'admin', 'created_at' => $reciente, 'whatsapp_message_id' => 'wamid.respuesta',
        ]);
        $this->mensaje($primero, 'Respuesta sin entregar', ['sender_type' => 'admin']);
        $this->mensaje($primero, 'Borrador privado', [
            'sender_type' => 'admin', 'is_ai_suggestion_draft' => true, 'synced_to_client_at' => now(),
        ]);

        $ruta = $this->ruta($cliente, 'soporte');
        $this->getJson($ruta, $this->clave($cliente))->assertOk()
            ->assertJsonPath('models.0.uuid', $segundo->uuid)
            ->assertJsonPath('models.0.last_message_at', $reciente->toIso8601String())
            ->assertJsonPath('models.1.last_message_at', $antiguo->toIso8601String());

        $mensaje->update(['read_at' => now()]);
        $primero->update(['status' => 'closed']);
        $this->getJson($ruta, $this->clave($cliente))->assertOk()
            ->assertJsonPath('models.0.uuid', $segundo->uuid)
            ->assertJsonPath('models.1.last_message_at', $antiguo->toIso8601String());
        $this->getJson($this->ruta($cliente, 'soporte/' . $primero->uuid), $this->clave($cliente))->assertOk()
            ->assertJsonCount(1, 'model.messages')
            ->assertJsonPath('model.last_message_at', $antiguo->toIso8601String());

        $this->mensaje($primero, 'Mensaje nuevo', ['sender_type' => 'user']);
        $this->getJson($ruta, $this->clave($cliente))->assertOk()
            ->assertJsonPath('models.0.uuid', $primero->uuid);
    }

    public function test_el_traspaso_conserva_el_ticket_y_martin_entrega_solo_en_empresa(): void
    {
        config(['services.claude_task_ingest.key' => 'codex-agentes-test']);
        $this->crear_config_whatsapp();
        $cliente = $this->comercio();
        $ticket = $this->ticket_whatsapp($cliente);
        SupportCodexSettings::set_enabled(true);
        $this->mensaje($ticket, 'Primer mensaje', ['whatsapp_message_id' => 'wamid.agentes.primero']);

        $datos = $this->datos_entrante($cliente, $ticket->uuid) + ['traspasar_a_sistema' => 1];
        $this->postJson('/api/inbound/support/messages', $datos)->assertUnauthorized();
        $this->postJson('/api/inbound/support/messages', array_replace($datos, [
            'client_user_id' => $cliente->user_id + 1, 'sender_user_id' => $cliente->user_id + 1,
        ]), $this->clave($cliente))->assertNotFound();
        $envio = $this->postJson('/api/inbound/support/messages', $datos, $this->clave($cliente))->assertCreated();
        $ticket->refresh();
        $this->assertSame('erp', $ticket->source);
        $this->assertNotNull($ticket->sistema_exclusivo_at);
        $this->postJson('/api/inbound/support/messages', $datos, $this->clave($cliente))->assertOk()
            ->assertJsonPath('sistema_exclusivo_at', $envio->json('sistema_exclusivo_at'));
        $this->assertSame(2, $ticket->messages()->count());
        $this->getJson($this->ruta($cliente, 'soporte/' . $ticket->uuid), $this->clave($cliente))->assertOk()
            ->assertJsonPath('model.origen', 'whatsapp')->assertJsonCount(2, 'model.messages');

        $this->postear_webhook($this->payload_de_texto((string) $cliente->phone, 'Respuesta citada', 'wamid.agentes.cita', 'wamid.agentes.primero'))->assertOk();
        $this->assertDatabaseMissing('support_messages', ['whatsapp_message_id' => 'wamid.agentes.cita']);

        $clave_codex = ['X-Claude-Task-Key' => 'codex-agentes-test'];
        $this->getJson(self::CODEX . '/pending', $clave_codex)->assertOk()
            ->assertJsonPath('tickets.0.id', $ticket->id);
        $reserva = $this->postJson(self::CODEX . '/tickets/' . $ticket->id . '/claim', [
            'worker_id' => 'agentes-erp-test', 'expected_message_id' => $envio->json('model.id'),
        ], $clave_codex)->assertOk()->assertJsonPath('ticket.canal', 'sistema')->assertJsonPath('ticket.window.open', null);
        $respuesta = [
            'lease_token' => $reserva->json('lease_token'), 'expected_message_id' => $envio->json('model.id'),
            'operation_id' => 'agentes-respuesta-' . $ticket->id, 'action' => 'responder', 'body' => 'Te contesto en el sistema.',
        ];
        $url = self::CODEX . '/tickets/' . $ticket->id . '/reply';
        $this->postJson($url, $respuesta, $clave_codex)->assertOk()->assertJsonPath('operation.status', 'sent');
        $this->postJson($url, $respuesta, $clave_codex)->assertOk()->assertJsonPath('operation.status', 'sent');
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($ticket) {
            return strpos($request->url(), '/api/admin-sync/support/messages') !== false
                && $request['ticket_uuid'] === $ticket->uuid && $request['body'] === 'Te contesto en el sistema.';
        });

        $this->postear_webhook($this->payload_de_texto((string) $cliente->phone, 'Consulta nueva', 'wamid.agentes.nueva'))->assertOk();
        $nuevo = SupportMessage::where('whatsapp_message_id', 'wamid.agentes.nueva')->firstOrFail()->ticket;
        $this->assertNotSame($ticket->id, $nuevo->id);
        $this->assertSame('whatsapp', $nuevo->source);

        $cliente->update(['phone' => '+5493485202156']);
        $this->getJson($this->ruta($cliente, 'soporte/' . $ticket->uuid), $this->clave($cliente))->assertOk()
            ->assertJsonCount(3, 'model.messages');
    }

    public function test_el_empleado_conserva_el_hilo_traspasado_sin_su_contacto_de_whatsapp(): void
    {
        $cliente = $this->comercio();
        $empleado = $this->crear_empleado($cliente, '+5493415202156');
        $usuario = $cliente->user_id + 1;
        $empleado->update(['empresa_employee_id' => $usuario]);
        $ticket = $this->ticket_whatsapp($cliente, ['client_employee_id' => $empleado->id, 'whatsapp_phone' => $empleado->phone]);
        $datos = array_replace($this->datos_entrante($cliente, $ticket->uuid), [
            'client_user_id' => $usuario, 'sender_user_id' => $usuario, 'es_dueno' => 0, 'traspasar_a_sistema' => 1,
        ]);

        $this->postJson('/api/inbound/support/messages', $datos, $this->clave($cliente))->assertCreated();
        $empleado->delete();

        $this->getJson($this->ruta($cliente, 'soporte/' . $ticket->uuid), $this->clave($cliente))->assertNotFound();
        $this->getJson($this->ruta($cliente, 'soporte/' . $ticket->uuid, ['client_user_id' => $usuario, 'es_dueno' => 0]), $this->clave($cliente))
            ->assertOk()->assertJsonPath('model.uuid', $ticket->uuid);
        $datos['message_uuid'] = (string) Str::uuid();
        $this->postJson('/api/inbound/support/messages', $datos, $this->clave($cliente))->assertCreated();
        $this->assertSame(2, $ticket->messages()->count());
    }

    public function test_el_ticket_web_firmado_se_encola_sin_reabrir_el_soporte_legado_previo_al_corte(): void
    {
        $cliente = $this->comercio();
        $legado = $this->datos_entrante($cliente, (string) Str::uuid());
        unset($legado['agentes_version']);
        $this->postJson('/api/inbound/support/messages', $legado)->assertCreated();

        $clave_codex = $this->activar_martin_para_erp();
        $agentes = $this->datos_entrante($cliente, (string) Str::uuid());
        $this->postJson('/api/inbound/support/messages', $agentes, $this->clave($cliente))->assertCreated();

        $ticket = SupportTicket::where('uuid', $agentes['ticket_uuid'])->firstOrFail();
        $this->getJson(self::CODEX . '/pending', $clave_codex)->assertOk()
            ->assertJsonCount(1, 'tickets')->assertJsonPath('tickets.0.id', $ticket->id);
    }

    public function test_un_empleado_web_sin_mapeo_no_toma_historial_ni_consultas_del_dueno(): void
    {
        config(['services.claude_task_ingest.key' => 'codex-agentes-test']);
        $cliente = $this->comercio();
        $this->ticket_whatsapp($cliente);
        SupportCodexSettings::set_enabled(true);
        $datos = array_replace($this->datos_entrante($cliente, (string) Str::uuid()), [
            'es_dueno' => 0, 'client_user_id' => $cliente->user_id + 1, 'sender_user_id' => $cliente->user_id + 1,
        ]);
        $mensaje = $this->postJson('/api/inbound/support/messages', $datos, $this->clave($cliente))->assertCreated();

        $ticket = SupportTicket::where('uuid', $datos['ticket_uuid'])->firstOrFail();
        $clave = ['X-Claude-Task-Key' => 'codex-agentes-test'];
        $url = self::CODEX . '/tickets/' . $ticket->id;
        $reserva = $this->postJson($url . '/claim', [
            'worker_id' => 'agentes-erp-test', 'expected_message_id' => $mensaje->json('model.id'),
        ], $clave)->assertOk()->assertJsonPath('ticket.contact_role', 'employee')
            ->assertJsonPath('ticket.can_query_system', false)->assertJsonCount(0, 'ticket.previous_tickets');

        $this->postJson($url . '/diagnostic', [
            'lease_token' => $reserva->json('lease_token'), 'query' => 'stock',
        ], $clave)->assertForbidden();
        Http::assertNothingSent();
    }

    private function comercio(): Client
    {
        $cliente = $this->crear_cliente();
        $cliente->update(['user_id' => 893400, 'inbound_api_key' => 'firma-agentes-test']);
        return $cliente;
    }

    private function activar_martin_para_erp(): array
    {
        config(['services.claude_task_ingest.key' => 'codex-agentes-test']);
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, now()->subHour()->toDateTimeString());
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AFTER_ID, (int) SupportMessage::max('id'));

        return ['X-Claude-Task-Key' => 'codex-agentes-test'];
    }

    private function clave(Client $cliente): array
    {
        return ['X-Admin-Api-Key' => (string) $cliente->inbound_api_key];
    }

    private function ruta(Client $cliente, string $ruta, array $datos = []): string
    {
        return self::INBOUND . '/' . $ruta . '?' . http_build_query($datos + [
            'client_uuid' => $cliente->uuid, 'client_user_id' => $cliente->user_id, 'es_dueno' => 1,
        ]);
    }

    private function ticket_whatsapp(Client $cliente, array $datos = []): SupportTicket
    {
        return SupportTicket::create($datos + [
            'client_id' => $cliente->id, 'client_user_id' => 0, 'status' => 'open', 'source' => 'whatsapp',
            'whatsapp_phone' => (string) $cliente->phone, 'opened_at' => now(), 'last_client_message_at' => now(),
        ]);
    }

    private function mensaje(SupportTicket $ticket, string $texto, array $datos = []): SupportMessage
    {
        return $ticket->messages()->create($datos + ['sender_type' => 'user', 'kind' => 'text', 'body' => $texto, 'delivered_at' => now()]);
    }

    private function datos_entrante(Client $cliente, string $ticket): array
    {
        return [
            'agentes_version' => 1, 'es_dueno' => 1, 'client_uuid' => $cliente->uuid, 'ticket_uuid' => $ticket,
            'client_user_id' => $cliente->user_id, 'sender_user_id' => $cliente->user_id, 'sender_type' => 'user',
            'message_uuid' => (string) Str::uuid(), 'kind' => 'text', 'body' => 'Sigo desde el sistema.',
        ];
    }
}
