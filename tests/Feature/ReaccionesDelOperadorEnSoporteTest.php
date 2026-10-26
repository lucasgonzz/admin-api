<?php

namespace Tests\Feature;

use App\Events\SupportMessageStatusUpdated;
use App\Models\Admin;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\WhatsappConfig;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La reacción con emoji que el operador deja desde el panel sobre un mensaje del cliente en un
 * ticket de soporte por WhatsApp tiene que llegar al teléfono del cliente.
 *
 * Endpoint real: `POST /api/admin/support-message/{id}/reaction` con `{ emoji }`. Lo que se
 * protege no es el camino feliz sino las decisiones que se pierden en el primer refactor:
 *
 * 1. Sale a Meta como `type: reaction` sobre el wamid del mensaje del CLIENTE, no como texto.
 * 2. Un emoji vacío/null quita la reacción y viaja como `''`.
 * 3. Si el envío falla (ventana de 24 h cerrada, etc.) es 422 y NO se guarda nada.
 * 4. Solo se reacciona a mensajes del cliente de tickets de WhatsApp; el resto es 422.
 * 5. La reacción no crea mensajes, no toca `last_message` ni el `updated_at` del ticket.
 *
 * Se le pega al endpoint con base y ruteo de verdad; lo único falso es la red.
 */
class ReaccionesDelOperadorEnSoporteTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Pulgar arriba, con escape explícito para que ningún editor se lo coma.
     */
    private const PULGAR = "\u{1F44D}";

    /**
     * Corazón con selector de variación: la forma canónica de la paleta del backend.
     */
    private const CORAZON = "\u{2764}\u{FE0F}";

    /**
     * Deja una configuración de WhatsApp activa y REAL (no test_mode).
     *
     * 🔴 El `Http::fake()` NO va acá: los stubs se resuelven por el primero que matchea y un
     * catch-all del setUp le ganaría a la respuesta que registre cada test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        WhatsappConfig::query()->update(['is_active' => false]);

        $config                  = new WhatsappConfig();
        $config->kapso_api_key   = 'clave-de-prueba';
        $config->phone_number_id = '1234567890';
        $config->webhook_secret  = 'secreto-de-prueba';
        $config->is_active       = true;
        $config->test_mode       = false;
        $config->save();
    }

    /**
     * Operador que reacciona.
     *
     * @return Admin
     */
    private function crear_admin(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Martín';
        $admin->email    = 'reaccion-soporte-' . uniqid() . '@test.com';
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Ticket con un mensaje del cliente al que se le va a reaccionar.
     *
     * @param string               $source        Canal del ticket (whatsapp | erp).
     * @param array<string, mixed> $campos_mensaje Campos a pisar sobre el mensaje.
     *
     * @return SupportMessage
     */
    private function crear_mensaje_del_cliente(string $source = 'whatsapp', array $campos_mensaje = []): SupportMessage
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
            'source'         => $source,
            'whatsapp_phone' => $source === 'whatsapp' ? '+5493415550000' : null,
            'opened_at'      => now(),
        ]);

        return SupportMessage::create(array_merge([
            'support_ticket_id'   => $ticket->id,
            'sender_type'         => 'user',
            'kind'                => 'text',
            'body'                => 'Hola, necesito ayuda con una factura',
            'whatsapp_message_id' => $source === 'whatsapp' ? 'wamid.CLIENTE' . random_int(100000, 999999) : null,
            'delivered_at'        => now(),
        ], $campos_mensaje));
    }

    /**
     * Pega al endpoint real, autenticado como el operador dado.
     *
     * @param Admin                $admin   Quien reacciona.
     * @param SupportMessage       $message Mensaje objetivo.
     * @param array<string, mixed> $body    Cuerpo del request.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function reaccionar(Admin $admin, SupportMessage $message, array $body)
    {
        return $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/support-message/' . $message->id . '/reaction', $body);
    }

    /**
     * Cuerpos de las peticiones `reaction` que efectivamente viajaron a la red.
     *
     * @return array<int, array<string, mixed>>
     */
    private function reacciones_enviadas(): array
    {
        $cuerpos = [];

        foreach (Http::recorded() as $par) {
            $data = $par[0]->data();
            if (is_array($data) && isset($data['type']) && $data['type'] === 'reaction') {
                $cuerpos[] = $data;
            }
        }

        return $cuerpos;
    }

    /**
     * Camino feliz: sale a Meta como reacción sobre el wamid del cliente, queda guardada con el
     * operador, y la respuesta trae el mensaje con attachments y sender_admin.
     *
     * @return void
     */
    public function test_reaccionar_manda_la_reaccion_a_meta_y_la_guarda()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.REACCION1']]], 200)]);
        Event::fake([SupportMessageStatusUpdated::class]);

        $admin   = $this->crear_admin();
        $message = $this->crear_mensaje_del_cliente();

        $response = $this->reaccionar($admin, $message, ['emoji' => self::PULGAR]);

        $response->assertStatus(200);
        $response->assertJsonPath('model.id', $message->id);
        $response->assertJsonPath('model.admin_reaction_emoji', self::PULGAR);
        $this->assertArrayHasKey('attachments', $response->json('model'));
        $this->assertArrayHasKey('sender_admin', $response->json('model'));

        $enviadas = $this->reacciones_enviadas();
        $this->assertCount(1, $enviadas);
        $this->assertSame($message->whatsapp_message_id, $enviadas[0]['reaction']['message_id']);
        $this->assertSame(self::PULGAR, $enviadas[0]['reaction']['emoji']);

        $message->refresh();
        $this->assertSame(self::PULGAR, $message->admin_reaction_emoji);
        $this->assertNotNull($message->admin_reaction_at);
        $this->assertSame((int) $admin->id, (int) $message->admin_reaction_admin_id);
        $this->assertSame('wamid.REACCION1', $message->admin_reaction_whatsapp_message_id);

        Event::assertDispatched(SupportMessageStatusUpdated::class, function ($evento) use ($message) {
            return (int) $evento->support_message_id === (int) $message->id;
        });
    }

    /**
     * Reaccionar de nuevo reemplaza la anterior; el corazón sin selector de variación se acepta
     * y sale a Meta en su forma canónica.
     *
     * @return void
     */
    public function test_cambiar_la_reaccion_reemplaza_la_anterior_y_el_corazon_sale_canonico()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.REACCION2']]], 200)]);

        $admin   = $this->crear_admin();
        $message = $this->crear_mensaje_del_cliente();

        $this->reaccionar($admin, $message, ['emoji' => self::PULGAR])->assertStatus(200);
        // Corazón SIN el U+FE0F, como puede llegar del navegador.
        $this->reaccionar($admin, $message, ['emoji' => "\u{2764}"])->assertStatus(200);

        $message->refresh();
        $this->assertSame(self::CORAZON, $message->admin_reaction_emoji);

        $enviadas = $this->reacciones_enviadas();
        $this->assertCount(2, $enviadas);
        $this->assertSame(self::CORAZON, $enviadas[1]['reaction']['emoji']);
    }

    /**
     * Quitar: el emoji vacío (o null) viaja a Meta como '' y deja todas las columnas en null.
     *
     * @return void
     */
    public function test_quitar_la_reaccion_manda_el_emoji_vacio_y_limpia_las_columnas()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.REACCION3']]], 200)]);
        Event::fake([SupportMessageStatusUpdated::class]);

        $admin   = $this->crear_admin();
        $message = $this->crear_mensaje_del_cliente();

        $this->reaccionar($admin, $message, ['emoji' => self::PULGAR])->assertStatus(200);
        $this->reaccionar($admin, $message, ['emoji' => null])->assertStatus(200)
            ->assertJsonPath('model.admin_reaction_emoji', null);

        $enviadas = $this->reacciones_enviadas();
        $this->assertCount(2, $enviadas);
        $this->assertSame('', $enviadas[1]['reaction']['emoji'], 'El quitado viaja como cadena vacía.');
        $this->assertSame($message->whatsapp_message_id, $enviadas[1]['reaction']['message_id']);

        $message->refresh();
        $this->assertNull($message->admin_reaction_emoji);
        $this->assertNull($message->admin_reaction_at);
        $this->assertNull($message->admin_reaction_admin_id);
        $this->assertNull($message->admin_reaction_whatsapp_message_id);

        Event::assertDispatchedTimes(SupportMessageStatusUpdated::class, 2);
    }

    /**
     * Quitar con la cadena vacía (no null) es lo mismo; y quitar sin reacción puesta es un 200
     * que no sale a la red (idempotente).
     *
     * @return void
     */
    public function test_quitar_sin_reaccion_puesta_es_idempotente_y_no_sale_a_la_red()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);

        $admin   = $this->crear_admin();
        $message = $this->crear_mensaje_del_cliente();

        $this->reaccionar($admin, $message, ['emoji' => ''])->assertStatus(200);

        $this->assertCount(0, $this->reacciones_enviadas());
    }

    /**
     * No se puede reaccionar a un mensaje del operador (o del agente): 422 con el motivo y sin red.
     *
     * @return void
     */
    public function test_no_se_puede_reaccionar_a_un_mensaje_del_operador()
    {
        Http::fake();

        $admin   = $this->crear_admin();
        $message = $this->crear_mensaje_del_cliente('whatsapp', ['sender_type' => 'admin']);

        $response = $this->reaccionar($admin, $message, ['emoji' => self::PULGAR]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('error'));
        $this->assertCount(0, $this->reacciones_enviadas());
        $this->assertNull($message->fresh()->admin_reaction_emoji);
    }

    /**
     * Un ticket del ERP no tiene WhatsApp: 422 y nada sale.
     *
     * @return void
     */
    public function test_no_se_puede_reaccionar_en_un_ticket_del_erp()
    {
        Http::fake();

        $admin   = $this->crear_admin();
        $message = $this->crear_mensaje_del_cliente('erp');

        $response = $this->reaccionar($admin, $message, ['emoji' => self::PULGAR]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('error'));
        $this->assertCount(0, $this->reacciones_enviadas());
    }

    /**
     * Un mensaje del cliente sin wamid (no se puede enganchar) es 422.
     *
     * @return void
     */
    public function test_un_mensaje_sin_wamid_no_se_puede_reaccionar()
    {
        Http::fake();

        $admin   = $this->crear_admin();
        $message = $this->crear_mensaje_del_cliente('whatsapp', ['whatsapp_message_id' => null]);

        $this->reaccionar($admin, $message, ['emoji' => self::PULGAR])->assertStatus(422);
        $this->assertCount(0, $this->reacciones_enviadas());
    }

    /**
     * Un emoji fuera de la paleta, o un body sin el campo, se rechazan sin salir a la red.
     *
     * @return void
     */
    public function test_un_emoji_fuera_de_la_paleta_o_sin_campo_se_rechaza()
    {
        Http::fake();

        $admin   = $this->crear_admin();
        $message = $this->crear_mensaje_del_cliente();

        // Fuera de la paleta: 422 con { error }.
        $fuera = $this->reaccionar($admin, $message, ['emoji' => "\u{1F525}"]);
        $fuera->assertStatus(422);
        $this->assertNotEmpty($fuera->json('error'));

        // Sin el campo: 422 (no es un quitado silencioso).
        $this->reaccionar($admin, $message, [])->assertStatus(422);

        $this->assertCount(0, $this->reacciones_enviadas());
        $this->assertNull($message->fresh()->admin_reaction_emoji);
    }

    /**
     * Ventana de 24 h cerrada: Meta rechaza (400), la respuesta es 422 con el motivo legible y
     * NO queda nada guardado.
     *
     * @return void
     */
    public function test_si_meta_rechaza_la_reaccion_es_422_y_no_se_guarda_nada()
    {
        Http::fake(['*' => Http::response([
            'error' => ['message' => 'Message failed to send because more than 24 hours have passed (131047)'],
        ], 400)]);
        Event::fake([SupportMessageStatusUpdated::class]);

        $admin   = $this->crear_admin();
        $message = $this->crear_mensaje_del_cliente();

        $response = $this->reaccionar($admin, $message, ['emoji' => self::PULGAR]);

        $response->assertStatus(422);
        $this->assertStringContainsString('24 horas', (string) $response->json('error'));

        $message->refresh();
        $this->assertNull($message->admin_reaction_emoji);
        $this->assertNull($message->admin_reaction_whatsapp_message_id);
        Event::assertNotDispatched(SupportMessageStatusUpdated::class);
    }

    /**
     * Si Meta rechaza el QUITADO, la reacción sigue puesta (sigue en el teléfono del cliente).
     *
     * @return void
     */
    public function test_si_meta_rechaza_el_quitado_la_reaccion_sigue_puesta()
    {
        $admin   = $this->crear_admin();
        $message = $this->crear_mensaje_del_cliente('whatsapp', [
            'admin_reaction_emoji'               => self::PULGAR,
            'admin_reaction_at'                  => now(),
            'admin_reaction_admin_id'            => $admin->id,
            'admin_reaction_whatsapp_message_id' => 'wamid.REACCION.REAL',
        ]);

        Http::fake(['*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $this->reaccionar($admin, $message, ['emoji' => ''])->assertStatus(422);

        $message->refresh();
        $this->assertSame(self::PULGAR, $message->admin_reaction_emoji, 'El panel sigue mostrando lo que el cliente ve.');
        $this->assertSame('wamid.REACCION.REAL', $message->admin_reaction_whatsapp_message_id);
    }

    /**
     * La reacción es solo un atributo del mensaje: no crea mensajes, no cambia el último mensaje
     * ni el `updated_at` del ticket.
     *
     * @return void
     */
    public function test_la_reaccion_no_crea_mensajes_ni_toca_el_ticket()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.REACCION5']]], 200)]);

        $admin   = $this->crear_admin();
        $message = $this->crear_mensaje_del_cliente();
        $ticket  = $message->ticket;

        // Congela el updated_at del ticket en el pasado para detectar cualquier toque.
        SupportTicket::query()->where('id', $ticket->id)->update(['updated_at' => '2026-01-01 10:00:00']);
        $mensajes_antes = SupportMessage::where('support_ticket_id', $ticket->id)->count();

        $this->reaccionar($admin, $message, ['emoji' => self::PULGAR])->assertStatus(200);

        $this->assertSame($mensajes_antes, SupportMessage::where('support_ticket_id', $ticket->id)->count());
        $this->assertSame('2026-01-01 10:00:00', (string) $ticket->fresh()->updated_at->format('Y-m-d H:i:s'));
        $this->assertSame((int) $message->id, (int) $ticket->fresh()->lastMessage->id);
    }

    /**
     * Sin sesión no se puede reaccionar: la ruta cuelga del mismo grupo autenticado que mark-read.
     *
     * @return void
     */
    public function test_sin_sesion_no_se_puede_reaccionar()
    {
        Http::fake();

        $message = $this->crear_mensaje_del_cliente();

        $this->postJson('/api/admin/support-message/' . $message->id . '/reaction', ['emoji' => self::PULGAR])
            ->assertStatus(401);
    }
}
