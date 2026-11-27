<?php

namespace Tests\Feature\Soporte;

use App\Models\Admin;
use App\Models\SupportTicket;
use Illuminate\Support\Str;
use Tests\Feature\AsistenteWhatsapp\BaseDelCanal;

/**
 * Fijar un ticket arriba de la bandeja y marcarlo como no leído (misión soporte-fijar-no-leido).
 *
 * Las marcas viven en el ticket (la bandeja es una sola para todos los operadores), viajan en la fila de la
 * bandeja y NO mueven `updated_at`: fijar o marcar no es actividad del cliente.
 */
class FijarYMarcarNoLeidoEnLaBandejaTest extends BaseDelCanal
{
    private function operador(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Lucas';
        $admin->email    = 'fijar-' . Str::random(10) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    private function ticket(): SupportTicket
    {
        $client = $this->crear_cliente('+5493417772' . random_int(100, 999), false, 'clave-' . Str::random(6));

        return SupportTicket::create([
            'client_id'          => $client->id,
            'client_employee_id' => null,
            'client_user_id'     => 0,
            'assigned_admin_id'  => null,
            'status'             => 'open',
            'source'             => 'whatsapp',
            'whatsapp_phone'     => (string) $client->phone,
            'opened_at'          => now(),
        ]);
    }

    public function test_fijar_y_desfijar_devuelve_la_marca_en_la_fila_y_no_toca_updated_at(): void
    {
        $admin  = $this->operador();
        $ticket = $this->ticket();
        $otro   = $this->ticket();
        SupportTicket::query()->whereKey($ticket->id)->update(['updated_at' => '2026-01-01 10:00:00']);

        $this->actingAs($admin, 'sanctum')->patchJson('/api/admin/support-ticket/' . $ticket->id . '/pin', ['pinned' => true])
            ->assertOk()
            ->assertJsonPath('model.id', $ticket->id);
        $this->assertNotNull($ticket->fresh()->pinned_at);
        $this->assertSame('2026-01-01 10:00:00', $ticket->fresh()->updated_at->format('Y-m-d H:i:s'));
        $this->assertNull($otro->fresh()->pinned_at, 'Fijar uno no fija los demás.');

        $fila = collect($this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket')->assertOk()->json('models'))
            ->firstWhere('id', $ticket->id);
        $this->assertNotNull($fila['pinned_at']);

        $this->actingAs($admin, 'sanctum')->patchJson('/api/admin/support-ticket/' . $ticket->id . '/pin', ['pinned' => false])
            ->assertOk()
            ->assertJsonPath('model.pinned_at', null);
        $this->assertNull($ticket->fresh()->pinned_at);
    }

    public function test_volver_a_fijar_conserva_el_momento_original(): void
    {
        $admin  = $this->operador();
        $ticket = $this->ticket();
        $this->actingAs($admin, 'sanctum')->patchJson('/api/admin/support-ticket/' . $ticket->id . '/pin', ['pinned' => true])->assertOk();
        $primero = $ticket->fresh()->pinned_at;
        $this->travel(5)->minutes();
        $this->actingAs($admin, 'sanctum')->patchJson('/api/admin/support-ticket/' . $ticket->id . '/pin', ['pinned' => true])->assertOk();
        $this->assertTrue($primero->equalTo($ticket->fresh()->pinned_at));
    }

    public function test_marcar_como_no_leido_y_sacar_la_marca(): void
    {
        $admin  = $this->operador();
        $ticket = $this->ticket();

        $this->actingAs($admin, 'sanctum')->patchJson('/api/admin/support-ticket/' . $ticket->id . '/mark-unread', ['unread' => true])
            ->assertOk();
        $this->assertNotNull($ticket->fresh()->marked_unread_at);

        $fila = collect($this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket')->assertOk()->json('models'))
            ->firstWhere('id', $ticket->id);
        $this->assertNotNull($fila['marked_unread_at']);

        $this->actingAs($admin, 'sanctum')->patchJson('/api/admin/support-ticket/' . $ticket->id . '/mark-unread', ['unread' => false])
            ->assertOk()
            ->assertJsonPath('model.marked_unread_at', null);
        $this->assertNull($ticket->fresh()->marked_unread_at);
    }

    public function test_exige_el_booleano_y_un_ticket_que_exista(): void
    {
        $admin  = $this->operador();
        $ticket = $this->ticket();
        $this->actingAs($admin, 'sanctum')->patchJson('/api/admin/support-ticket/' . $ticket->id . '/pin', [])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->patchJson('/api/admin/support-ticket/' . $ticket->id . '/mark-unread', [])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->patchJson('/api/admin/support-ticket/999999/pin', ['pinned' => true])->assertNotFound();
    }

    public function test_sin_sesion_no_se_puede_fijar(): void
    {
        $ticket = $this->ticket();
        $this->patchJson('/api/admin/support-ticket/' . $ticket->id . '/pin', ['pinned' => true])->assertUnauthorized();
        $this->assertNull($ticket->fresh()->pinned_at);
    }
}
