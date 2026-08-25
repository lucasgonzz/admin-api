<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Un ticket de WhatsApp no tiene contraparte en el empresa-api del cliente.
 *
 * Marcar leído, avisar que se está escribiendo y guardar la cabecera salían igual hacia la
 * API del cliente sin mirar el canal: un POST con dos reintentos y 15s de timeout contra un
 * ticket que allá no existe, por cada mensaje abierto y por cada tecla. No rompía nada
 * visible —de ahí que sobreviviera— pero es latencia real en la bandeja, y con la apertura
 * de conversaciones desde el admin pasa a haber tickets de WhatsApp donde el operador tipea
 * activamente.
 *
 * Lo que verifica este test es una ausencia: que NO salga ese HTTP. Es exactamente lo que
 * un test de camino feliz nunca mira.
 */
class GuardasDeCanalEnElSyncDeSoporteTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Intercepta cualquier salida HTTP para poder contarla.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    /**
     * Admin operador de soporte.
     *
     * @param string $email Email único del admin.
     *
     * @return Admin
     */
    private function crear_admin(string $email): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Operador de prueba';
        $admin->email    = $email;
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Cliente con api_url cargada, para que el sync tenga a dónde ir si se dispara.
     *
     * @return Client
     */
    private function crear_cliente(): Client
    {
        $client            = new Client();
        $client->name      = 'Distribuidora de prueba';
        $client->phone     = '+5493412220000';
        $client->is_active = true;
        $client->api_url   = 'https://api-cliente-de-prueba.test';
        $client->api_key   = 'clave-de-prueba';
        $client->save();

        return $client;
    }

    /**
     * Ticket abierto del canal indicado, con un mensaje del cliente adentro.
     *
     * @param Client $client Cliente dueño.
     * @param string $source erp o whatsapp.
     *
     * @return array{ticket: SupportTicket, message: SupportMessage}
     */
    private function crear_ticket_con_mensaje(Client $client, string $source): array
    {
        $ticket = SupportTicket::create([
            'client_id'      => $client->id,
            'client_user_id' => 0,
            'status'         => 'open',
            'source'         => $source,
            'whatsapp_phone' => $source === 'whatsapp' ? '+5493412220000' : null,
            'opened_at'      => now(),
        ]);

        $message = SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'user',
            'kind'              => 'text',
            'body'              => 'Una consulta',
            'delivered_at'      => now(),
        ]);

        return ['ticket' => $ticket, 'message' => $message];
    }

    /**
     * En un ticket de WhatsApp, marcar leído no golpea la API del cliente.
     *
     * @return void
     */
    public function test_marcar_leido_en_whatsapp_no_sincroniza_al_erp()
    {
        $admin  = $this->crear_admin('leido-whatsapp@test.local');
        $client = $this->crear_cliente();
        $datos  = $this->crear_ticket_con_mensaje($client, 'whatsapp');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/support-message/' . $datos['message']->id . '/mark-read')
            ->assertStatus(200);

        Http::assertNothingSent();

        $this->assertNotNull(
            SupportMessage::find($datos['message']->id)->read_at,
            'La lectura no quedó guardada localmente.'
        );
    }

    /**
     * En un ticket del ERP, marcar leído sigue sincronizando como siempre.
     *
     * @return void
     */
    public function test_marcar_leido_en_el_erp_sigue_sincronizando()
    {
        $admin  = $this->crear_admin('leido-erp@test.local');
        $client = $this->crear_cliente();
        $datos  = $this->crear_ticket_con_mensaje($client, 'erp');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/support-message/' . $datos['message']->id . '/mark-read')
            ->assertStatus(200);

        Http::assertSentCount(1);
    }

    /**
     * En un ticket de WhatsApp, el aviso de "escribiendo" no golpea la API del cliente.
     *
     * @return void
     */
    public function test_typing_en_whatsapp_no_sincroniza_al_erp()
    {
        $admin  = $this->crear_admin('typing-whatsapp@test.local');
        $client = $this->crear_cliente();
        $datos  = $this->crear_ticket_con_mensaje($client, 'whatsapp');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/support-ticket/' . $datos['ticket']->id . '/typing')
            ->assertStatus(200);

        Http::assertNothingSent();
    }

    /**
     * En un ticket del ERP, el aviso de "escribiendo" sigue sincronizando.
     *
     * @return void
     */
    public function test_typing_en_el_erp_sigue_sincronizando()
    {
        $admin  = $this->crear_admin('typing-erp@test.local');
        $client = $this->crear_cliente();
        $datos  = $this->crear_ticket_con_mensaje($client, 'erp');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/support-ticket/' . $datos['ticket']->id . '/typing')
            ->assertStatus(200);

        Http::assertSentCount(1);
    }

    /**
     * Guardar la cabecera de un ticket de WhatsApp no golpea la API del cliente.
     *
     * @return void
     */
    public function test_guardar_cabecera_en_whatsapp_no_sincroniza_al_erp()
    {
        $admin  = $this->crear_admin('cabecera-whatsapp@test.local');
        $client = $this->crear_cliente();
        $datos  = $this->crear_ticket_con_mensaje($client, 'whatsapp');

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/support-ticket/' . $datos['ticket']->id, [
                'name'   => 'Problema con el stock',
                'status' => 'open',
            ])
            ->assertStatus(200);

        Http::assertNothingSent();

        $this->assertSame(
            'Problema con el stock',
            SupportTicket::find($datos['ticket']->id)->name,
            'El nombre del ticket no se guardó localmente.'
        );
    }

    /**
     * Guardar la cabecera de un ticket del ERP sigue sincronizando.
     *
     * @return void
     */
    public function test_guardar_cabecera_en_el_erp_sigue_sincronizando()
    {
        $admin  = $this->crear_admin('cabecera-erp@test.local');
        $client = $this->crear_cliente();
        $datos  = $this->crear_ticket_con_mensaje($client, 'erp');

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/support-ticket/' . $datos['ticket']->id, [
                'name'   => 'Problema con el stock',
                'status' => 'open',
            ])
            ->assertStatus(200);

        Http::assertSentCount(1);
    }

    /**
     * El cron de reintentos no vuelve a intentar los mensajes de tickets de WhatsApp.
     *
     * Esos mensajes nunca setean `synced_to_client_at`, así que sin el filtro quedaban en la
     * cola para siempre: cada cinco minutos, un POST con dos reintentos y 15s de timeout por
     * cada mensaje de cada conversación de WhatsApp abierta.
     *
     * @return void
     */
    public function test_el_cron_de_reintentos_ignora_los_tickets_de_whatsapp()
    {
        $client = $this->crear_cliente();
        $datos  = $this->crear_ticket_con_mensaje($client, 'whatsapp');

        SupportMessage::create([
            'support_ticket_id' => $datos['ticket']->id,
            'sender_type'       => 'admin',
            'kind'              => 'text',
            'body'              => 'Respuesta del operador',
            'delivered_at'      => now(),
        ]);

        $this->artisan('support:retry-pending-syncs')->assertExitCode(0);

        Http::assertNothingSent();
    }

    /**
     * El cron de reintentos sigue reintentando los mensajes del canal ERP.
     *
     * @return void
     */
    public function test_el_cron_de_reintentos_sigue_atendiendo_al_erp()
    {
        $client = $this->crear_cliente();
        $datos  = $this->crear_ticket_con_mensaje($client, 'erp');

        SupportMessage::create([
            'support_ticket_id' => $datos['ticket']->id,
            'sender_type'       => 'admin',
            'kind'              => 'text',
            'body'              => 'Respuesta del operador',
            'delivered_at'      => now(),
        ]);

        $this->artisan('support:retry-pending-syncs')->assertExitCode(0);

        Http::assertSentCount(1);
    }
}
