<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\SupportCodexSettings;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CotejoDeSoporteErpTest extends BaseDelCanal
{
    private const RUTA = '/api/claude/support-codex/erp-verification';
    private const CLAVE = ['X-Claude-Task-Key' => 'cotejo-erp-test', 'X-Support-ERP-Verifier-Key' => 'verificador-erp-test'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.claude_task_ingest.key' => self::CLAVE['X-Claude-Task-Key']]);
        config(['services.support_erp_verifier.key' => self::CLAVE['X-Support-ERP-Verifier-Key']]);
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, now()->subMinute()->toDateTimeString());
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AFTER_ID, (int) SupportMessage::max('id'));
        Storage::fake('public');
    }

    public function test_coteja_el_ultimo_entrante_y_solo_acredita_la_evidencia_exacta(): void
    {
        $ticket = $this->ticket_del_sistema($this->crear_cliente());
        $this->mensaje($ticket, 'Entrante anterior');
        $mensaje = $this->mensaje($ticket, '¿Cómo reviso el depósito?');
        $this->adjunto($mensaje, 'archivo B');
        $this->adjunto($mensaje, 'archivo A');
        $this->adjunto($mensaje, 'archivo B');
        $ticket->messages()->create(['sender_type' => 'admin', 'kind' => 'text', 'body' => 'Borrador', 'is_ai_suggestion_draft' => true]);
        $ticket->messages()->create(['sender_type' => 'admin', 'kind' => 'text', 'body' => 'Aviso de importación', 'is_trial_import_notice' => true]);
        $hashes = [hash('sha256', 'archivo A'), hash('sha256', 'archivo B'), hash('sha256', 'archivo B')];
        sort($hashes, SORT_STRING);

        $this->getJson(self::RUTA)->assertUnauthorized();
        $this->postJson(self::RUTA)->assertUnauthorized();
        $solo_ingesta = ['X-Claude-Task-Key' => self::CLAVE['X-Claude-Task-Key']];
        $this->getJson(self::RUTA, $solo_ingesta)->assertForbidden();
        $this->postJson(self::RUTA, [], $solo_ingesta + ['X-Support-ERP-Verifier-Key' => 'otra-clave'])->assertForbidden();
        $respuesta = $this->getJson(self::RUTA, self::CLAVE)->assertOk()
            ->assertJsonCount(1, 'candidates')->assertJsonPath('next_after_message_id', null);
        $this->assertSame([
            'ticket_id' => $ticket->id, 'client_id' => $ticket->client_id,
            'client_user_id' => $ticket->client_user_id, 'ticket_uuid' => $ticket->uuid,
            'message_id' => $mensaje->id, 'message_uuid' => $mensaje->uuid,
            'kind' => 'text', 'body_sha256' => hash('sha256', $mensaje->body), 'attachments' => $hashes,
        ], $respuesta->json('candidates.0'));

        $evidencia = $this->evidencia($mensaje, $hashes);
        $this->postJson(self::RUTA, array_replace($evidencia, ['message_uuid' => (string) Str::uuid()]), self::CLAVE)->assertStatus(409);
        $this->postJson(self::RUTA, array_replace($evidencia, ['ticket_uuid' => (string) Str::uuid()]), self::CLAVE)->assertStatus(409);
        $this->postJson(self::RUTA, array_replace($evidencia, ['client_id' => $ticket->client_id + 1]), self::CLAVE)->assertStatus(409);
        $this->postJson(self::RUTA, array_replace($evidencia, ['client_user_id' => $ticket->client_user_id + 1]), self::CLAVE)->assertStatus(409);
        $this->postJson(self::RUTA, array_replace($evidencia, ['kind' => 'audio']), self::CLAVE)->assertStatus(409);
        $this->postJson(self::RUTA, array_replace($evidencia, ['body_sha256' => hash('sha256', 'otro')]), self::CLAVE)->assertStatus(409);
        $this->postJson(self::RUTA, array_replace($evidencia, ['attachments' => []]), self::CLAVE)->assertStatus(409);
        $this->assertNull($mensaje->fresh()->erp_auto_reply);
        $this->postJson(self::RUTA, $evidencia, self::CLAVE)->assertOk()->assertExactJson(['verified' => true]);
        $this->postJson(self::RUTA, $evidencia, self::CLAVE)->assertOk()->assertExactJson(['verified' => true]);
        $this->assertTrue($mensaje->fresh()->erp_auto_reply[SupportMessage::MARCA_ERP_VERIFICADO_PARA_CODEX]);
        $this->getJson(self::RUTA, self::CLAVE)->assertOk()->assertJsonCount(0, 'candidates');
        $this->getJson('/api/claude/support-codex/pending', self::CLAVE)->assertOk()
            ->assertJsonCount(1, 'tickets')->assertJsonPath('tickets.0.expected_message_id', $mensaje->id);
    }

    public function test_el_cotejo_no_aplica_a_otra_conversacion_ni_a_un_entrante_superado_o_pausado(): void
    {
        $ticket = $this->ticket_del_sistema($this->crear_cliente());
        $mensaje = $this->mensaje($ticket, 'Pregunta inicial');
        $evidencia = $this->evidencia($mensaje);
        $otro_ticket = $this->ticket_del_sistema($this->crear_cliente('+5493412345678'));
        $this->mensaje($otro_ticket, 'Otra conversación');
        $this->postJson(self::RUTA, array_replace($evidencia, ['ticket_id' => $otro_ticket->id]), self::CLAVE)->assertStatus(409);

        $ticket->update(['claude_auto_reply' => false]);
        $this->postJson(self::RUTA, $evidencia, self::CLAVE)->assertStatus(409);
        $ticket->update(['claude_auto_reply' => true]);
        $nuevo = $this->mensaje($ticket, 'Agrego otra cosa');
        $this->postJson(self::RUTA, $evidencia, self::CLAVE)->assertStatus(409);
        $this->assertNull($mensaje->fresh()->erp_auto_reply);
        $this->postJson(self::RUTA, $this->evidencia($nuevo), self::CLAVE)->assertOk();
        $ticket->messages()->create(['sender_type' => 'admin', 'kind' => 'text', 'body' => 'Ya atendido']);
        $this->postJson(self::RUTA, $this->evidencia($nuevo), self::CLAVE)->assertStatus(409);
    }

    public function test_el_cursor_avanza_aunque_no_haya_binarios_locales_verificables_en_la_pagina(): void
    {
        $cliente = $this->crear_cliente();
        for ($i = 0; $i < 20; $i++) {
            $mensaje = $this->mensaje($this->ticket_del_sistema($cliente), 'Adjunto en la instancia de Empresa');
            $mensaje->attachments()->create(['disk' => 'local', 'path' => 'solo-metadata.png']);
        }
        $siguiente = $this->mensaje($this->ticket_del_sistema($cliente), 'Sin archivos para cotejar');
        $this->getJson(self::RUTA, self::CLAVE)->assertOk()->assertJsonCount(0, 'candidates')
            ->assertJsonPath('next_after_message_id', $mensaje->id);
        $this->postJson(self::RUTA, $this->evidencia($mensaje), self::CLAVE)->assertStatus(409);
        $this->getJson(self::RUTA . '?after_message_id=' . $mensaje->id, self::CLAVE)->assertOk()
            ->assertJsonCount(1, 'candidates')->assertJsonPath('next_after_message_id', null)
            ->assertJsonPath('candidates.0.message_id', $siguiente->id)->assertJsonPath('candidates.0.attachments', []);
    }

    private function ticket_del_sistema(Client $cliente): SupportTicket
    {
        return SupportTicket::create([
            'client_id' => $cliente->id, 'client_user_id' => 17, 'source' => 'erp', 'status' => 'open',
            'opened_at' => now(), 'last_client_message_at' => null,
        ]);
    }

    private function mensaje(SupportTicket $ticket, string $texto): SupportMessage
    {
        return $ticket->messages()->create(['sender_type' => 'user', 'kind' => 'text', 'body' => $texto]);
    }

    private function adjunto(SupportMessage $mensaje, string $bytes): void
    {
        $path = 'support_messages/' . $mensaje->support_ticket_id . '/' . Str::random(40) . '.pdf';
        Storage::disk('public')->put($path, $bytes);
        $mensaje->attachments()->create(['disk' => 'public', 'path' => $path, 'size' => strlen($bytes), 'mime' => 'application/pdf']);
    }

    private function evidencia(SupportMessage $mensaje, array $adjuntos = []): array
    {
        $ticket = $mensaje->ticket;
        return [
            'ticket_id' => $mensaje->support_ticket_id, 'client_id' => $ticket->client_id,
            'client_user_id' => $ticket->client_user_id, 'ticket_uuid' => $ticket->uuid,
            'message_id' => $mensaje->id, 'message_uuid' => $mensaje->uuid, 'kind' => $mensaje->kind,
            'body_sha256' => hash('sha256', $mensaje->body), 'attachments' => $adjuntos,
        ];
    }
}
