<?php

namespace Tests\Feature;

use App\Events\SupportMessageReceived;
use App\Models\Admin;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\WhatsappConfig;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Responder citando un mensaje en la conversación de WhatsApp de un ticket de soporte.
 *
 * Contrato: `POST support-ticket/{id}/message` acepta `reply_to_message_id` (opcional). El mensaje
 * queda con la cita guardada, vuelve con `reply_to_message` y, por WhatsApp, sale con el `context`
 * nativo de Meta apuntando al wamid del mensaje citado. Sin el campo, el envío es el de siempre.
 */
class RespuestaCitadaEnSoporteTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([SupportMessageReceived::class]);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.SALIENTE']]], 200)]);

        $config = new WhatsappConfig();
        $config->forceFill([
            'proposito'       => WhatsappConfig::PROPOSITO_PRINCIPAL,
            'kapso_api_key'   => 'clave-kapso-de-prueba',
            'phone_number_id' => '5550009998887',
            'webhook_secret'  => 'secreto',
            'display_phone'   => '+1 201 555 0100',
            'is_active'       => true,
            'test_mode'       => false,
        ]);
        $config->save();
    }

    private function admin(): Admin
    {
        return Admin::create(['name' => 'Lucas', 'email' => Str::uuid() . '@prueba.local', 'password' => bcrypt('secret')]);
    }

    private function ticket(): SupportTicket
    {
        $client = Client::create(['name' => 'Cliente de prueba', 'is_active' => true,
            'api_url' => 'https://cliente.test', 'api_key' => 'clave-de-prueba']);

        return SupportTicket::create(['client_id' => $client->id, 'client_user_id' => 0, 'status' => 'open',
            'source' => 'whatsapp', 'whatsapp_phone' => '+5493415550000', 'opened_at' => now()]);
    }

    /** Mensaje entrante del cliente, reciente: deja la ventana de 24hs abierta. */
    private function entrante(SupportTicket $ticket, string $wamid, string $body = 'Hola, tengo un problema'): SupportMessage
    {
        return SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text',
            'body' => $body, 'whatsapp_message_id' => $wamid, 'delivered_at' => now()]);
    }

    private function responder(SupportTicket $ticket, array $datos)
    {
        return $this->postJson('/api/admin/support-ticket/' . $ticket->id . '/message', $datos);
    }

    /** Payload que salió hacia Meta (el último POST a /messages). */
    private function payload_enviado(): array
    {
        $enviado = null;
        Http::assertSent(function ($request) use (&$enviado) {
            if (strpos($request->url(), '/messages') !== false) {
                $enviado = $request->data();
            }

            return true;
        });

        return (array) $enviado;
    }

    public function test_responder_citando_guarda_la_cita_y_la_manda_a_whatsapp_como_contexto(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $ticket = $this->ticket();
        $citado = $this->entrante($ticket, 'wamid.DEL_CLIENTE');

        $respuesta = $this->responder($ticket, [
            'kind' => 'text', 'body' => 'Ya lo vemos', 'reply_to_message_id' => $citado->id,
        ])->assertStatus(201);

        $respuesta->assertJsonPath('model.reply_to_message_id', $citado->id)
            ->assertJsonPath('model.reply_to_message.id', $citado->id)
            ->assertJsonPath('model.reply_to_message.body', 'Hola, tengo un problema');
        $this->assertArrayNotHasKey('whatsapp_message_id', $respuesta->json('model.reply_to_message'));

        $payload = $this->payload_enviado();
        $this->assertSame('Ya lo vemos', $payload['text']['body']);
        $this->assertSame('wamid.DEL_CLIENTE', $payload['context']['message_id']);
        $this->assertSame('wamid.SALIENTE', SupportMessage::findOrFail($respuesta->json('model.id'))->whatsapp_message_id);
    }

    public function test_sin_reply_to_message_id_el_envio_es_el_de_siempre_sin_contexto(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $ticket = $this->ticket();
        $this->entrante($ticket, 'wamid.DEL_CLIENTE');

        $respuesta = $this->responder($ticket, ['kind' => 'text', 'body' => 'Sin cita'])->assertStatus(201);

        $respuesta->assertJsonPath('model.reply_to_message_id', null)->assertJsonPath('model.reply_to_message', null);
        $this->assertArrayNotHasKey('context', $this->payload_enviado());
    }

    public function test_no_se_puede_citar_un_mensaje_de_otro_ticket(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $ticket = $this->ticket();
        $ajeno = $this->entrante($this->ticket(), 'wamid.AJENO');

        $this->responder($ticket, ['kind' => 'text', 'body' => 'Hola', 'reply_to_message_id' => $ajeno->id])
            ->assertStatus(422);

        $this->assertSame(0, SupportMessage::where('support_ticket_id', $ticket->id)->count());
        Http::assertNothingSent();
    }

    public function test_no_se_puede_citar_un_borrador_del_agente_que_el_cliente_nunca_vio(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $ticket = $this->ticket();
        $borrador = SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'admin', 'kind' => 'text',
            'body' => 'Borrador', 'is_ai_suggestion_draft' => true]);

        $this->responder($ticket, ['kind' => 'text', 'body' => 'Hola', 'reply_to_message_id' => $borrador->id])
            ->assertStatus(422);
    }

    public function test_citar_un_mensaje_sin_wamid_se_guarda_en_el_panel_pero_no_manda_contexto(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $ticket = $this->ticket();
        $this->entrante($ticket, 'wamid.ABRE_VENTANA');
        $sin_wamid = SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'admin', 'kind' => 'text',
            'body' => 'Nunca llegó', 'remote_delivery_status' => 'not_received']);

        $this->responder($ticket, ['kind' => 'text', 'body' => 'Perdón', 'reply_to_message_id' => $sin_wamid->id])
            ->assertStatus(201)
            ->assertJsonPath('model.reply_to_message_id', $sin_wamid->id);

        $this->assertArrayNotHasKey('context', $this->payload_enviado());
    }

    public function test_la_cita_no_se_filtra_al_siguiente_envio_de_la_misma_instancia(): void
    {
        $ticket = $this->ticket();
        $citado = $this->entrante($ticket, 'wamid.DEL_CLIENTE');
        $respuesta = SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'admin', 'kind' => 'text',
            'body' => 'Con cita', 'reply_to_message_id' => $citado->id]);

        $servicio = app(WhatsappSendService::class);
        $servicio->send_support_message('+5493415550000', $respuesta);
        $servicio->send_text('+5493415550000', 'Otro mensaje cualquiera');

        $bodies = [];
        Http::assertSent(function ($request) use (&$bodies) {
            if (strpos($request->url(), '/messages') !== false) {
                $bodies[] = $request->data();
            }

            return true;
        });
        $this->assertCount(2, $bodies);
        $this->assertSame('wamid.DEL_CLIENTE', $bodies[0]['context']['message_id']);
        $this->assertArrayNotHasKey('context', $bodies[1]);
    }

    public function test_el_hilo_que_se_abre_trae_la_cita_de_cada_mensaje(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $ticket = $this->ticket();
        $original = $this->entrante($ticket, 'wamid.ORIGINAL', 'Mensaje original');
        $cita = SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'admin', 'kind' => 'text',
            'body' => 'Respuesta', 'reply_to_message_id' => $original->id]);
        $huerfana = SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'admin', 'kind' => 'text',
            'body' => 'Cita a algo borrado', 'reply_to_message_id' => 999999]);

        $mensajes = collect($this->getJson('/api/admin/support-ticket/' . $ticket->id)->assertOk()->json('model.messages'))->keyBy('id');

        $this->assertSame('Mensaje original', $mensajes[$cita->id]['reply_to_message']['body']);
        $this->assertNull($mensajes[$original->id]['reply_to_message']);
        $this->assertSame(999999, $mensajes[$huerfana->id]['reply_to_message_id']);
        $this->assertNull($mensajes[$huerfana->id]['reply_to_message']);
    }

    public function test_el_cuerpo_de_la_cita_se_corta_para_no_pasar_el_tope_del_broadcast(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $ticket = $this->ticket();
        $largo = $this->entrante($ticket, 'wamid.LARGO', str_repeat('ñandú ', 1000));

        $respuesta = $this->responder($ticket, ['kind' => 'text', 'body' => 'ok', 'reply_to_message_id' => $largo->id])
            ->assertStatus(201);

        $this->assertLessThanOrEqual(300, mb_strlen($respuesta->json('model.reply_to_message.body')));
        $this->assertGreaterThan(100, mb_strlen($respuesta->json('model.reply_to_message.body')));
        // El mensaje original sigue entero en la base.
        $this->assertSame(6000, mb_strlen(SupportMessage::findOrFail($largo->id)->body));
    }

    public function test_un_entrante_que_cita_se_enlaza_con_el_mensaje_local_por_su_wamid(): void
    {
        $ticket = $this->ticket();
        $original = $this->entrante($ticket, 'wamid.ORIGINAL');

        $this->assertSame($original->id, SupportMessage::local_id_by_wamid($ticket->id, 'wamid.ORIGINAL'));
        $this->assertNull(SupportMessage::local_id_by_wamid($ticket->id, 'wamid.NO_ESTA'));
        $this->assertNull(SupportMessage::local_id_by_wamid($ticket->id, null));
        $this->assertNull(SupportMessage::local_id_by_wamid($this->ticket()->id, 'wamid.ORIGINAL'));
    }
}
