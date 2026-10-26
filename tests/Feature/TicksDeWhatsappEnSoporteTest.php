<?php

namespace Tests\Feature;

use App\Events\SupportMessageStatusUpdated;
use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\WhatsappConfig;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ticks de WhatsApp (enviado / entregado / leído) en los mensajes salientes del hilo de soporte.
 *
 * Hasta esta misión el webhook de estados de Kapso solo sabía de `lead_messages`. Lo que se
 * verifica acá es lo que un camino feliz no mira:
 *
 * 1. Que un wamid de soporte actualice `support_messages.whatsapp_*` pegándole al webhook real
 *    (firma, ruteo y base de verdad).
 * 2. Que los estados NUNCA retrocedan, aunque Meta los entregue desordenados.
 * 3. Que `delivered_at` y `read_at` (espejo con el ERP / leído por el operador) no se toquen.
 * 4. Que el camino de leads siga exactamente igual.
 * 5. Que el evento en tiempo real salga solo cuando el estado cambió de verdad, y sin texto.
 */
class TicksDeWhatsappEnSoporteTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Secreto con el que se firman los payloads de las pruebas.
     */
    private const SECRETO = 'secreto-de-prueba-de-ticks';

    /**
     * Deja una configuración de WhatsApp activa con secreto conocido.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        WhatsappConfig::query()->update(['is_active' => false]);

        $config                  = new WhatsappConfig();
        $config->kapso_api_key   = 'clave-de-prueba';
        $config->phone_number_id = '1234567890';
        $config->webhook_secret  = self::SECRETO;
        $config->is_active       = true;
        $config->test_mode       = false;
        $config->save();
    }

    /**
     * Ticket de WhatsApp con un mensaje saliente del operador, ya enviado (con wamid).
     *
     * @param array<string, mixed> $campos_mensaje Campos a pisar sobre el mensaje saliente.
     *
     * @return SupportMessage
     */
    private function crear_mensaje_saliente(array $campos_mensaje = []): SupportMessage
    {
        $client            = new Client();
        $client->name      = 'Distribuidora de prueba';
        $client->phone     = '+5493411111111';
        $client->is_active = true;
        $client->save();

        $ticket = SupportTicket::create([
            'client_id'      => $client->id,
            'client_user_id' => 0,
            'status'         => 'open',
            'source'         => 'whatsapp',
            'whatsapp_phone' => '+5493415550000',
            'opened_at'      => now(),
        ]);

        return SupportMessage::create(array_merge([
            'support_ticket_id'   => $ticket->id,
            'sender_type'         => 'admin',
            'kind'                => 'text',
            'body'                => 'Hola, ya lo revisamos',
            'whatsapp_message_id' => 'wamid.SOPORTE' . random_int(100000, 999999),
        ], $campos_mensaje));
    }

    /**
     * Pega al webhook real de Kapso con el payload firmado.
     *
     * @param string $evento Nombre del evento (whatsapp.message.delivered, .read, .failed, .sent).
     * @param string $wamid  wamid del mensaje saliente.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function estado(string $evento, string $wamid)
    {
        $body = json_encode(['event' => $evento, 'message' => ['id' => $wamid]]);

        return $this->call('POST', '/api/webhook/whatsapp', [], [], [], [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X_KAPSO_SIGNATURE' => hash_hmac('sha256', $body, self::SECRETO),
        ], $body);
    }

    /**
     * Entregado y después leído: el mensaje pasa por los dos estados y guarda sus sellos.
     *
     * @return void
     */
    public function test_entregado_y_despues_leido_actualiza_el_mensaje_de_soporte()
    {
        $message = $this->crear_mensaje_saliente();

        $this->estado('whatsapp.message.delivered', $message->whatsapp_message_id)->assertStatus(200);

        $message->refresh();
        $this->assertSame('entregado', $message->whatsapp_delivery_status);
        $this->assertNotNull($message->whatsapp_delivered_at);
        $this->assertNull($message->whatsapp_seen_at);

        $this->estado('whatsapp.message.read', $message->whatsapp_message_id)->assertStatus(200);

        $message->refresh();
        $this->assertSame('leido', $message->whatsapp_delivery_status);
        $this->assertNotNull($message->whatsapp_delivered_at);
        $this->assertNotNull($message->whatsapp_seen_at);
    }

    /**
     * Un `leido` que llega antes que su `entregado` completa también el sello de entregado.
     *
     * @return void
     */
    public function test_leido_sin_entregado_previo_completa_el_sello_de_entrega()
    {
        $message = $this->crear_mensaje_saliente();

        $this->estado('whatsapp.message.read', $message->whatsapp_message_id)->assertStatus(200);

        $message->refresh();
        $this->assertSame('leido', $message->whatsapp_delivery_status);
        $this->assertNotNull($message->whatsapp_delivered_at, 'Un mensaje leído siempre fue entregado.');
        $this->assertNotNull($message->whatsapp_seen_at);
    }

    /**
     * Webhooks desordenados: un `entregado` tardío NO baja un `leido` ya guardado.
     *
     * @return void
     */
    public function test_un_entregado_tardio_no_retrocede_un_leido()
    {
        $message = $this->crear_mensaje_saliente();

        $this->estado('whatsapp.message.read', $message->whatsapp_message_id)->assertStatus(200);
        $message->refresh();
        $visto_a_las = $message->whatsapp_seen_at;
        $entregado_a_las = $message->whatsapp_delivered_at;

        // Llega el delivered que se había demorado.
        $this->estado('whatsapp.message.delivered', $message->whatsapp_message_id)->assertStatus(200);

        $message->refresh();
        $this->assertSame('leido', $message->whatsapp_delivery_status, 'El doble check azul no vuelve a gris.');
        $this->assertEquals($visto_a_las, $message->whatsapp_seen_at);
        $this->assertEquals($entregado_a_las, $message->whatsapp_delivered_at);
    }

    /**
     * Un `fallido` tardío tampoco le pisa el estado a un mensaje que ya fue entregado.
     *
     * @return void
     */
    public function test_un_fallido_tardio_no_pisa_un_entregado()
    {
        $message = $this->crear_mensaje_saliente();

        $this->estado('whatsapp.message.delivered', $message->whatsapp_message_id)->assertStatus(200);
        $this->estado('whatsapp.message.failed', $message->whatsapp_message_id)->assertStatus(200);

        $message->refresh();
        $this->assertSame('entregado', $message->whatsapp_delivery_status);
    }

    /**
     * Un fallo en un mensaje que todavía no tenía estado queda guardado como `fallido`.
     *
     * @return void
     */
    public function test_un_fallo_queda_guardado_como_fallido()
    {
        $message = $this->crear_mensaje_saliente();

        $this->estado('whatsapp.message.failed', $message->whatsapp_message_id)->assertStatus(200);

        $message->refresh();
        $this->assertSame('fallido', $message->whatsapp_delivery_status);
        $this->assertNull($message->whatsapp_delivered_at);
    }

    /**
     * El estado `enviado` no se persiste: se infiere por el wamid.
     *
     * @return void
     */
    public function test_el_evento_enviado_no_se_persiste()
    {
        $message = $this->crear_mensaje_saliente();

        $this->estado('whatsapp.message.sent', $message->whatsapp_message_id)->assertStatus(200);

        $message->refresh();
        $this->assertNull($message->whatsapp_delivery_status);
    }

    /**
     * Los ticks de WhatsApp usan SOLO columnas `whatsapp_*`: `delivered_at` y `read_at` quedan
     * como estaban, porque alimentan el espejo con el ERP y los no leídos de la bandeja.
     *
     * @return void
     */
    public function test_los_ticks_no_tocan_delivered_at_ni_read_at()
    {
        $message = $this->crear_mensaje_saliente();

        $this->estado('whatsapp.message.read', $message->whatsapp_message_id)->assertStatus(200);

        $message->refresh();
        $this->assertNull($message->delivered_at);
        $this->assertNull($message->read_at);
    }

    /**
     * Un wamid que no es ni de un lead ni de soporte se ignora con 200, sin error.
     *
     * @return void
     */
    public function test_un_wamid_desconocido_se_ignora_sin_error()
    {
        Event::fake([SupportMessageStatusUpdated::class]);

        $this->estado('whatsapp.message.delivered', 'wamid.NO.EXISTE.EN.NINGUN.LADO')->assertStatus(200);

        Event::assertNotDispatched(SupportMessageStatusUpdated::class);
    }

    /**
     * El camino de leads sigue igual: un wamid de LeadMessage actualiza el LeadMessage y no
     * dispara el evento de soporte.
     *
     * @return void
     */
    public function test_el_camino_de_leads_queda_intacto()
    {
        Event::fake([SupportMessageStatusUpdated::class]);

        $lead               = new Lead();
        $lead->contact_name = 'Karina';
        $lead->phone        = '549341' . random_int(1000000, 9999999);
        $lead->status       = 'contactado';
        $lead->save();

        $lead_message = LeadMessage::create([
            'lead_id'             => $lead->id,
            'sender'              => 'setter',
            'content'             => 'Hola Karina',
            'status'              => 'enviado',
            'whatsapp_message_id' => 'wamid.LEAD' . random_int(100000, 999999),
            'sent_at'             => now(),
        ]);
        // Mensaje de soporte aparte, para comprobar que no se mezcla con el del lead.
        $support_message = $this->crear_mensaje_saliente();

        $this->estado('whatsapp.message.read', $lead_message->whatsapp_message_id)->assertStatus(200);

        $lead_message->refresh();
        $this->assertSame('leido', $lead_message->whatsapp_delivery_status);
        $this->assertNotNull($lead_message->whatsapp_seen_at);

        $support_message->refresh();
        $this->assertNull($support_message->whatsapp_delivery_status, 'El mensaje de soporte no se toca.');
        Event::assertNotDispatched(SupportMessageStatusUpdated::class);
    }

    /**
     * El evento en tiempo real sale al cambiar el estado, con el payload del contrato y sin texto;
     * y NO sale cuando el evento llega repetido o desordenado (no cambió nada).
     *
     * @return void
     */
    public function test_el_evento_en_tiempo_real_sale_solo_cuando_el_estado_cambia()
    {
        Event::fake([SupportMessageStatusUpdated::class]);

        $message = $this->crear_mensaje_saliente();

        $this->estado('whatsapp.message.read', $message->whatsapp_message_id)->assertStatus(200);
        Event::assertDispatchedTimes(SupportMessageStatusUpdated::class, 1);

        // Repetido y atrasado: no cambia nada, no se avisa de nuevo.
        $this->estado('whatsapp.message.read', $message->whatsapp_message_id)->assertStatus(200);
        $this->estado('whatsapp.message.delivered', $message->whatsapp_message_id)->assertStatus(200);
        Event::assertDispatchedTimes(SupportMessageStatusUpdated::class, 1);

        // Payload y nombre del contrato, calculados con el evento real.
        $evento  = new SupportMessageStatusUpdated((int) $message->id);
        $payload = $evento->broadcastWith();

        $this->assertSame('support.message.status', $evento->broadcastAs());
        $this->assertSame([
            'id', 'support_ticket_id', 'whatsapp_delivery_status', 'whatsapp_delivered_at',
            'whatsapp_seen_at', 'whatsapp_message_id', 'admin_reaction_emoji', 'admin_reaction_at',
            'admin_reaction_admin_id',
        ], array_keys($payload));
        $this->assertSame((int) $message->id, $payload['id']);
        $this->assertSame('leido', $payload['whatsapp_delivery_status']);
        $this->assertSame($message->whatsapp_message_id, $payload['whatsapp_message_id']);
        $this->assertStringNotContainsString('Hola, ya lo revisamos', json_encode($payload), 'El texto no sale por un canal público.');

        // Mismos tres canales que SupportMessageRead: cliente, operador y support.admins.
        $canales = array_map(function ($canal) {
            return $canal->name;
        }, $evento->broadcastOn());
        $this->assertCount(3, $canales);
        $this->assertContains('support.admins', $canales);
    }

    /**
     * Si Meta rechaza después la reacción del panel (aviso `failed` con el wamid de la reacción),
     * se despinta sin tocar los ticks del mensaje original.
     *
     * @return void
     */
    public function test_un_fallo_con_el_wamid_de_la_reaccion_la_despinta_sin_tocar_el_mensaje()
    {
        $message = $this->crear_mensaje_saliente([
            'sender_type'                        => 'user',
            'admin_reaction_emoji'               => "\u{1F44D}",
            'admin_reaction_at'                  => now(),
            'admin_reaction_whatsapp_message_id' => 'wamid.REACCION.SOPORTE',
        ]);

        $this->estado('whatsapp.message.failed', 'wamid.REACCION.SOPORTE')->assertStatus(200);

        $message->refresh();
        $this->assertNull($message->admin_reaction_emoji);
        $this->assertNull($message->admin_reaction_whatsapp_message_id);
        $this->assertNull($message->whatsapp_delivery_status, 'El estado del mensaje original no se toca.');

        // Un `delivered` de nuestra reacción, en cambio, se ignora en silencio.
        $otro = $this->crear_mensaje_saliente([
            'sender_type'                        => 'user',
            'admin_reaction_emoji'               => "\u{1F44D}",
            'admin_reaction_whatsapp_message_id' => 'wamid.REACCION.SOPORTE.2',
        ]);
        $this->estado('whatsapp.message.delivered', 'wamid.REACCION.SOPORTE.2')->assertStatus(200);
        $otro->refresh();
        $this->assertSame("\u{1F44D}", $otro->admin_reaction_emoji);
        $this->assertNull($otro->whatsapp_delivery_status);
    }
}
