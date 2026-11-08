<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\SupportCodexSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AgentesEnEmpresaTest extends BaseDelCanal
{
    private const INBOUND = '/api/inbound/agentes';
    private const CODEX = '/api/claude/support-codex';

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

    public function test_el_ticket_web_firmado_se_separa_de_soporte_legado(): void
    {
        config(['services.claude_task_ingest.key' => 'codex-agentes-test']);
        $cliente = $this->comercio();
        SupportCodexSettings::set_enabled(true);
        $agentes = $this->datos_entrante($cliente, (string) Str::uuid());
        $legado = $this->datos_entrante($cliente, (string) Str::uuid());
        unset($legado['agentes_version']);
        $this->postJson('/api/inbound/support/messages', $legado)->assertCreated();
        $this->postJson('/api/inbound/support/messages', $agentes, $this->clave($cliente))->assertCreated();

        $ticket = SupportTicket::where('uuid', $agentes['ticket_uuid'])->firstOrFail();
        $this->getJson(self::CODEX . '/pending', ['X-Claude-Task-Key' => 'codex-agentes-test'])->assertOk()
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
