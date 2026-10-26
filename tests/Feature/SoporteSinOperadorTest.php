<?php

namespace Tests\Feature;

use App\Events\SupportMessageReceived;
use App\Events\SupportTicketAlert;
use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\AsistenteWhatsappSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\AsistenteWhatsapp\BaseDelCanal;

/**
 * Los tickets de soporte dejan de pertenecer a un operador (26/10/2026).
 *
 * Contesta el agente o la persona que escribe a mano; `assigned_admin_id` queda en null en los
 * cuatro lugares que antes lo llenaban y la bandeja lista TODOS los tickets. Lo que se protege
 * acá es lo que un camino feliz no mira:
 *
 * 1. Que ningún camino de alta (webhook de WhatsApp, inbound del ERP, alta manual, apertura
 *    desde el panel) vuelva a asignar un operador, ni siquiera habiendo un "dueño por defecto".
 * 2. Que `GET support-ticket` ignore `assigned_filter` / `filter` (el SPA viejo los sigue
 *    mandando) y traiga `unread_total` además de los totales legacy.
 * 3. Que un ticket sin dueño no silencie nada: alertas de demora, eventos en tiempo real, el
 *    "está escribiendo" hacia el ERP.
 * 4. Que `PUT support-ticket/{id}` siga aceptando `assigned_admin_id` (compatibilidad).
 */
class SoporteSinOperadorTest extends BaseDelCanal
{
    /**
     * Configuración de WhatsApp activa y soporte conectado, sin que nada salga a la red.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->crear_config_whatsapp();
        AdminSetting::set(AsistenteWhatsappSettings::KEY_TICKETS_ENABLED, '1');
    }

    /**
     * Admin operador.
     *
     * @param string $nombre Nombre visible.
     * @param bool   $dueno_por_defecto true si es el dueño por defecto de soporte (criterio viejo).
     *
     * @return Admin
     */
    private function crear_admin(string $nombre, bool $dueno_por_defecto = false): Admin
    {
        $admin                           = new Admin();
        $admin->name                     = $nombre;
        $admin->email                    = 'sin-operador-' . uniqid() . '@test.local';
        $admin->password                 = bcrypt('secret');
        $admin->is_default_support_owner = $dueno_por_defecto;
        $admin->save();

        return $admin;
    }

    /**
     * Ticket de WhatsApp abierto, sin operador, con un mensaje del cliente sin leer.
     *
     * @param Client $client Cliente dueño.
     *
     * @return SupportTicket
     */
    private function crear_ticket_con_mensaje_sin_leer(Client $client): SupportTicket
    {
        $ticket = SupportTicket::create([
            'client_id'               => $client->id,
            'client_user_id'          => 0,
            'status'                  => 'open',
            'source'                  => 'whatsapp',
            'whatsapp_phone'          => '+5493415550' . random_int(100, 999),
            'opened_at'               => now(),
            'last_client_message_at'  => now()->subHours(3),
        ]);

        SupportMessage::create([
            'support_ticket_id'   => $ticket->id,
            'sender_type'         => 'user',
            'kind'                => 'text',
            'body'                => 'Necesito ayuda',
            'whatsapp_message_id' => 'wamid.SINOP' . random_int(100000, 999999),
            'delivered_at'        => now(),
        ]);

        return $ticket;
    }

    /**
     * El ticket que abre el webhook de WhatsApp nace sin operador, aunque exista un dueño por
     * defecto de soporte (el criterio viejo lo habría asignado a él).
     *
     * @return void
     */
    public function test_el_ticket_que_abre_el_webhook_nace_sin_operador()
    {
        Queue::fake();
        $this->crear_admin('Dueño de soporte', true);
        $client = $this->crear_cliente('+5493411234567', false);

        $this->postear_webhook(
            $this->payload_de_texto('+5493411234567', 'Se me traba el sistema', 'wamid.SINOP-WEBHOOK')
        )->assertStatus(200);

        $ticket = SupportTicket::where('client_id', $client->id)->first();
        $this->assertNotNull($ticket, 'El ticket tiene que nacer igual.');
        $this->assertNull($ticket->assigned_admin_id, 'Un ticket entrante no debe asignarse a nadie.');
        $this->assertSame(1, SupportMessage::where('support_ticket_id', $ticket->id)->count());
    }

    /**
     * El ticket que abre el inbound del ERP (empresa-api) nace sin operador.
     *
     * @return void
     */
    public function test_el_ticket_que_abre_el_erp_nace_sin_operador()
    {
        Event::fake([SupportMessageReceived::class]);
        $this->crear_admin('Dueño de soporte', true);
        $client = $this->crear_cliente('+5493411234567', false);

        $this->postJson('/api/inbound/support/messages', [
            'client_uuid'      => $client->uuid,
            'ticket_uuid'      => 'ticket-' . uniqid(),
            'message_uuid'     => 'mensaje-' . uniqid(),
            'client_user_id'   => 5,
            'sender_user_name' => 'Usuario del ERP',
            'ticket_name'      => 'Consulta',
            'body'             => 'Hola desde el ERP',
        ])->assertStatus(201);

        $ticket = SupportTicket::where('client_id', $client->id)->first();
        $this->assertNotNull($ticket);
        $this->assertNull($ticket->assigned_admin_id);
    }

    /**
     * El alta manual del canal ERP desde el panel también deja el ticket sin operador, y un
     * `assigned_admin_id` que mande un SPA viejo se ignora.
     *
     * @return void
     */
    public function test_el_alta_manual_nace_sin_operador_aunque_el_spa_viejo_mande_uno()
    {
        $admin  = $this->crear_admin('Quien lo abre');
        $otro   = $this->crear_admin('Otro operador');
        $client = $this->crear_cliente('+5493411234567', false);

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket', [
            'client_id'         => $client->id,
            'client_user_id'    => 7,
            'client_user_name'  => 'Usuario del ERP',
            'name'              => 'Consulta de prueba',
            'assigned_admin_id' => $otro->id,
        ])->assertStatus(201);

        $ticket = SupportTicket::where('client_id', $client->id)->first();
        $this->assertNotNull($ticket);
        $this->assertNull($ticket->assigned_admin_id);
    }

    /**
     * `GET support-ticket` trae TODOS los tickets sin importar `assigned_filter` ni `filter`, con
     * `unread_total` además de los totales legacy y los campos que el SPA pinta en la lista.
     *
     * @return void
     */
    public function test_el_listado_ignora_el_filtro_y_trae_unread_total_y_los_campos_de_la_lista()
    {
        $admin  = $this->crear_admin('Operador');
        $otro   = $this->crear_admin('Otro operador');
        $client = $this->crear_cliente('+5493411234567', false);

        // Un ticket sin dueño, uno asignado al operador (dato legacy) y uno asignado a otro.
        $sin_dueno = $this->crear_ticket_con_mensaje_sin_leer($client);
        $mio       = $this->crear_ticket_con_mensaje_sin_leer($client);
        $mio->assigned_admin_id = $admin->id;
        $mio->save();
        $ajeno = $this->crear_ticket_con_mensaje_sin_leer($client);
        $ajeno->assigned_admin_id = $otro->id;
        $ajeno->claude_auto_reply = false;
        $ajeno->save();

        // Último mensaje del ticket "ajeno": respuesta de un operador por WhatsApp, con ticks.
        SupportMessage::create([
            'support_ticket_id'        => $ajeno->id,
            'sender_type'              => 'admin',
            'sender_admin_id'          => $otro->id,
            'kind'                     => 'text',
            'body'                     => 'Ya lo vemos',
            'whatsapp_message_id'      => 'wamid.SALIENTE.LISTA',
            'whatsapp_delivery_status' => 'leido',
            'whatsapp_delivered_at'    => now(),
            'whatsapp_seen_at'         => now(),
        ]);

        $ids_esperados = [(int) $sin_dueno->id, (int) $mio->id, (int) $ajeno->id];

        foreach (['', '?assigned_filter=mine', '?assigned_filter=unassigned', '?filter=others', '?assigned_filter=' . $otro->id] as $query) {
            $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket' . $query);
            $response->assertStatus(200);

            $ids = array_map(function ($fila) {
                return (int) $fila['id'];
            }, $response->json('models'));
            sort($ids);
            $esperados = $ids_esperados;
            sort($esperados);
            $this->assertSame($esperados, $ids, 'El filtro "' . $query . '" no debe recortar la lista.');

            // Contador único + los legacy intactos.
            $this->assertSame(3, $response->json('unread_total'));
            $this->assertArrayHasKey('mine', $response->json('unread_totals'));
            $this->assertArrayHasKey('others', $response->json('unread_totals'));
            $this->assertIsArray($response->json('inbox_nav'));
            $this->assertIsArray($response->json('open_totals'));
        }

        // Campos que el SPA pinta por fila: interruptores del agente y último mensaje con ticks.
        $filas = [];
        foreach ($this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket')->json('models') as $fila) {
            $filas[(int) $fila['id']] = $fila;
        }
        $this->assertTrue($filas[(int) $sin_dueno->id]['claude_auto_reply']);
        $this->assertFalse($filas[(int) $ajeno->id]['claude_auto_reply']);
        $this->assertArrayHasKey('requiere_verificacion_mensajes', $filas[(int) $sin_dueno->id]);
        $this->assertNull($filas[(int) $sin_dueno->id]['assigned_admin_id']);

        $ultimo = $filas[(int) $ajeno->id]['last_message'];
        foreach ([
            'whatsapp_message_id', 'whatsapp_delivery_status', 'whatsapp_delivered_at',
            'whatsapp_seen_at', 'sender_type', 'sender_admin', 'admin_reaction_emoji',
        ] as $clave) {
            $this->assertArrayHasKey($clave, $ultimo, 'last_message debe traer ' . $clave);
        }
        $this->assertSame('admin', $ultimo['sender_type']);
        $this->assertSame('leido', $ultimo['whatsapp_delivery_status']);
        $this->assertSame('Otro operador', $ultimo['sender_admin']['name']);
    }

    /**
     * `GET support-ticket/unread-badges` también suma `unread_total` y mantiene lo legacy.
     *
     * @return void
     */
    public function test_unread_badges_suma_unread_total()
    {
        $admin  = $this->crear_admin('Operador');
        $client = $this->crear_cliente('+5493411234567', false);
        $this->crear_ticket_con_mensaje_sin_leer($client);
        $this->crear_ticket_con_mensaje_sin_leer($client);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket/unread-badges');

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('unread_total'));
        $this->assertArrayHasKey('unread_totals', $response->json());
        $this->assertArrayHasKey('inbox_nav', $response->json());
        $this->assertArrayHasKey('open_totals', $response->json());
    }

    /**
     * `PUT support-ticket/{id}` sigue aceptando `assigned_admin_id` (compatibilidad con SPA viejo).
     *
     * @return void
     */
    public function test_el_put_sigue_aceptando_assigned_admin_id()
    {
        $admin  = $this->crear_admin('Operador');
        $client = $this->crear_cliente('+5493411234567', false);
        $ticket = $this->crear_ticket_con_mensaje_sin_leer($client);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/support-ticket/' . $ticket->id, ['assigned_admin_id' => $admin->id])
            ->assertStatus(200);

        $this->assertSame((int) $admin->id, (int) $ticket->fresh()->assigned_admin_id);
    }

    /**
     * Un ticket sin operador sigue generando la alerta de demora (que va a todos los operadores).
     *
     * @return void
     */
    public function test_la_alerta_de_demora_salta_para_un_ticket_sin_operador()
    {
        Event::fake([SupportTicketAlert::class]);
        $client = $this->crear_cliente('+5493411234567', false);
        $ticket = $this->crear_ticket_con_mensaje_sin_leer($client);
        $this->assertNull($ticket->assigned_admin_id);

        Artisan::call('support:check-response-alerts');

        Event::assertDispatched(SupportTicketAlert::class, function ($evento) use ($ticket) {
            return (int) $evento->ticket_id === (int) $ticket->id
                && in_array('support.admins', array_map(function ($canal) {
                    return $canal->name;
                }, $evento->broadcastOn()), true);
        });
    }

    /**
     * Los eventos en tiempo real de un ticket sin operador siguen llegando a `support.admins`
     * (el canal de todos los operadores) y no revientan por el operador nulo.
     *
     * @return void
     */
    public function test_los_eventos_de_un_ticket_sin_operador_llegan_a_support_admins()
    {
        $client  = $this->crear_cliente('+5493411234567', false);
        $ticket  = $this->crear_ticket_con_mensaje_sin_leer($client);
        $message = SupportMessage::where('support_ticket_id', $ticket->id)->first();

        $evento = new SupportMessageReceived((int) $message->id);
        $canales = array_map(function ($canal) {
            return $canal->name;
        }, $evento->broadcastOn());

        $this->assertContains('support.admins', $canales);
        $this->assertContains('support.admin.0', $canales, 'Sin operador, el canal personal cae en support.admin.0.');
    }

    /**
     * El aviso de "está escribiendo" hacia el ERP lleva el operador autenticado como actor, ya que
     * el ticket no tiene operador asignado (antes salía de `assigned_admin_id`).
     *
     * @return void
     */
    public function test_el_typing_hacia_el_erp_usa_al_operador_autenticado()
    {
        $admin  = $this->crear_admin('Operador que escribe');
        $client = $this->crear_cliente('+5493411234567', false);
        $ticket = SupportTicket::create([
            'client_id'      => $client->id,
            'client_user_id' => 3,
            'status'         => 'open',
            'source'         => 'erp',
            'opened_at'      => now(),
        ]);
        $this->assertNull($ticket->assigned_admin_id);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/support-ticket/' . $ticket->id . '/typing')
            ->assertStatus(200);

        Http::assertSent(function ($request) use ($admin) {
            return strpos($request->url(), '/api/admin-sync/support/typing') !== false
                && (int) $request['actor_id'] === (int) $admin->id;
        });
    }
}
