<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POST /support-message/mark-read-bulk: marcar leídos varios mensajes de soporte de una vez.
 *
 * Hoy admin-spa hace un POST por mensaje sin leer: un ticket con 40 mensajes son 40 requests al
 * entrar al módulo. Este endpoint se AGREGA; el de a uno sigue existiendo y funcionando igual,
 * porque el SPA lo mantiene como camino de respaldo.
 *
 * 🔴 Lo que se prueba acá, en orden de importancia:
 *  1. Que el de a uno siga intacto (si se rompió, el respaldo del SPA no sirve de nada).
 *  2. Que el lote marque lo mismo que marcarían N llamadas al de a uno.
 *  3. Que no quede más abierto que el existente: sin sesión, 401.
 *  4. Que la sincronización al ERP se siga haciendo o salteando con el mismo criterio
 *     (los tickets de WhatsApp no sincronizan; los del ERP sí).
 */
class MarcarEnLoteMensajesDeSoporteTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Ningún test de este archivo sale a la red de verdad.
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
     * @return Admin
     */
    private function crear_admin(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Lucas';
        $admin->email    = 'lucas+' . Str::random(10) . '@comerciocity.com';
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Ticket de un cliente, por el canal indicado.
     *
     * @param string $source erp|whatsapp.
     *
     * @return SupportTicket
     */
    private function crear_ticket(string $source): SupportTicket
    {
        $client               = new Client();
        $client->name         = 'Juan Pérez';
        $client->company_name = 'Distribuidora del Sur';
        $client->is_active    = true;
        $client->api_url      = 'https://api-distribuidora.comerciocity.com';
        $client->api_key      = Str::random(40);
        $client->save();

        return SupportTicket::create([
            'client_id'      => $client->id,
            'client_user_id' => 0,
            'status'         => 'open',
            'source'         => $source,
            'whatsapp_phone' => $source === 'whatsapp' ? '+5493415551111' : null,
            'opened_at'      => now()->subHours(3),
        ]);
    }

    /**
     * Mensajes entrantes sin leer de un ticket.
     *
     * @param SupportTicket $ticket   Ticket dueño.
     * @param int           $cantidad Cuántos mensajes crear.
     *
     * @return \Illuminate\Support\Collection
     */
    private function crear_mensajes_sin_leer(SupportTicket $ticket, int $cantidad)
    {
        $mensajes = collect();
        for ($i = 0; $i < $cantidad; $i++) {
            $mensajes->push(SupportMessage::create([
                'support_ticket_id' => $ticket->id,
                'sender_type'       => 'user',
                'kind'              => 'text',
                'body'              => 'Consulta ' . $i,
                'delivered_at'      => now()->subMinutes(30 - $i),
            ]));
        }

        return $mensajes;
    }

    /**
     * 🔴 El endpoint de a uno no se tocó: sigue marcando y sigue devolviendo { ok: true }.
     *
     * Es la primera prueba del archivo a propósito: el SPA lo usa como respaldo del lote, así que
     * si este se rompió, el respaldo no sirve.
     *
     * @return void
     */
    public function test_el_endpoint_de_a_uno_sigue_funcionando_igual(): void
    {
        $admin    = $this->crear_admin();
        $ticket   = $this->crear_ticket('whatsapp');
        $mensajes = $this->crear_mensajes_sin_leer($ticket, 1);
        $mensaje  = $mensajes->first();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/support-message/' . $mensaje->id . '/mark-read');

        $response->assertStatus(200);
        $response->assertExactJson(['ok' => true]);
        $this->assertNotNull($mensaje->fresh()->read_at, 'El de a uno tiene que seguir marcando el mensaje.');
    }

    /**
     * El lote marca los mensajes que se le pasan y contesta cuántos marcó.
     *
     * @return void
     */
    public function test_el_lote_marca_todos_los_mensajes_que_se_le_pasan(): void
    {
        $admin    = $this->crear_admin();
        $ticket   = $this->crear_ticket('whatsapp');
        $mensajes = $this->crear_mensajes_sin_leer($ticket, 12);

        $response = $this->actingAs($admin, 'sanctum')->postJson(
            '/api/admin/support-message/mark-read-bulk',
            ['ids' => $mensajes->pluck('id')->all()]
        );

        $response->assertStatus(200);
        $this->assertTrue($response->json('ok'), 'La respuesta tiene que traer ok = true, igual que el de a uno.');
        $this->assertSame(12, $response->json('marked'), 'Tiene que informar los 12 que marcó.');

        $this->assertSame(
            0,
            SupportMessage::where('support_ticket_id', $ticket->id)->whereNull('read_at')->count(),
            'No puede quedar ni un mensaje sin leer del ticket.'
        );
    }

    /**
     * El lote deja el mismo estado que marcar los mismos mensajes de a uno.
     *
     * Dos tickets iguales, uno por cada camino: lo que queda en la base tiene que ser lo mismo.
     *
     * @return void
     */
    public function test_el_lote_deja_el_mismo_estado_que_marcarlos_de_a_uno(): void
    {
        $admin = $this->crear_admin();

        $ticket_de_a_uno    = $this->crear_ticket('whatsapp');
        $mensajes_de_a_uno  = $this->crear_mensajes_sin_leer($ticket_de_a_uno, 4);
        $ticket_en_lote     = $this->crear_ticket('whatsapp');
        $mensajes_en_lote   = $this->crear_mensajes_sin_leer($ticket_en_lote, 4);

        foreach ($mensajes_de_a_uno as $mensaje) {
            $this->actingAs($admin, 'sanctum')
                ->postJson('/api/admin/support-message/' . $mensaje->id . '/mark-read')
                ->assertStatus(200);
        }

        $this->actingAs($admin, 'sanctum')->postJson(
            '/api/admin/support-message/mark-read-bulk',
            ['ids' => $mensajes_en_lote->pluck('id')->all()]
        )->assertStatus(200);

        foreach ([$mensajes_de_a_uno, $mensajes_en_lote] as $grupo) {
            foreach ($grupo as $mensaje) {
                $fresco = $mensaje->fresh();
                $this->assertNotNull($fresco->read_at, 'Los dos caminos tienen que dejar read_at cargado.');
                // save() toca updated_at; el UPDATE en lote también, porque lo agrega Eloquent.
                $this->assertNotNull($fresco->updated_at, 'Los dos caminos tienen que tocar updated_at.');
            }
        }
    }

    /**
     * Volver a marcar lo que ya estaba leído no rompe nada ni lo desmarca.
     *
     * @return void
     */
    public function test_marcar_dos_veces_el_mismo_lote_no_rompe_nada(): void
    {
        $admin    = $this->crear_admin();
        $ticket   = $this->crear_ticket('whatsapp');
        $mensajes = $this->crear_mensajes_sin_leer($ticket, 3);
        $ids      = $mensajes->pluck('id')->all();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/support-message/mark-read-bulk', ['ids' => $ids])
            ->assertStatus(200);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/support-message/mark-read-bulk', ['ids' => $ids])
            ->assertStatus(200)
            ->assertJsonPath('marked', 3);

        $this->assertSame(
            0,
            SupportMessage::whereIn('id', $ids)->whereNull('read_at')->count(),
            'Los tres tienen que seguir leídos.'
        );
    }

    /**
     * Un id que no existe no tumba el lote: a diferencia del de a uno, acá un 404 dejaría sin
     * marcar a los otros 39 por culpa de uno solo.
     *
     * @return void
     */
    public function test_un_id_inexistente_no_tumba_el_resto_del_lote(): void
    {
        $admin    = $this->crear_admin();
        $ticket   = $this->crear_ticket('whatsapp');
        $mensajes = $this->crear_mensajes_sin_leer($ticket, 3);

        $ids   = $mensajes->pluck('id')->all();
        $ids[] = 99999999;

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/support-message/mark-read-bulk', ['ids' => $ids]);

        $response->assertStatus(200);
        $this->assertSame(3, $response->json('marked'), 'Marca los tres que existen e ignora el que no.');
        $this->assertSame(0, SupportMessage::whereIn('id', $mensajes->pluck('id'))->whereNull('read_at')->count());
    }

    /**
     * 🔴 El lote no queda más abierto que el de a uno: sin sesión, 401.
     *
     * @return void
     */
    public function test_sin_sesion_no_se_puede_marcar_en_lote(): void
    {
        $ticket   = $this->crear_ticket('whatsapp');
        $mensajes = $this->crear_mensajes_sin_leer($ticket, 2);

        // El de a uno, sin sesión.
        $this->postJson('/api/admin/support-message/' . $mensajes->first()->id . '/mark-read')
            ->assertStatus(401);

        // El nuevo tiene que comportarse igual.
        $this->postJson('/api/admin/support-message/mark-read-bulk', ['ids' => $mensajes->pluck('id')->all()])
            ->assertStatus(401);

        $this->assertSame(
            2,
            SupportMessage::whereIn('id', $mensajes->pluck('id'))->whereNull('read_at')->count(),
            'Sin sesión no se puede haber marcado nada.'
        );
    }

    /**
     * La entrada se valida: sin ids, con ids vacíos o con basura, 422 — nunca un marcado a medias.
     *
     * @return void
     */
    public function test_valida_la_entrada_en_vez_de_marcar_cualquier_cosa(): void
    {
        $admin = $this->crear_admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/support-message/mark-read-bulk', [])
            ->assertStatus(422);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/support-message/mark-read-bulk', ['ids' => []])
            ->assertStatus(422);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/support-message/mark-read-bulk', ['ids' => ['no-es-un-id']])
            ->assertStatus(422);
    }

    /**
     * 🔴 Un lote más grande que el tope falla con 422 y NO marca nada.
     *
     * Es a propósito que sea un error y no un recorte silencioso: recortar dejaría los últimos sin
     * leer sin que nadie se entere. Con el 422, el SPA cae al camino de a uno.
     *
     * @return void
     */
    public function test_un_lote_mas_grande_que_el_tope_falla_en_vez_de_recortar(): void
    {
        $admin    = $this->crear_admin();
        $ticket   = $this->crear_ticket('whatsapp');
        $mensajes = $this->crear_mensajes_sin_leer($ticket, 2);

        // 501 ids: los dos reales y relleno hasta pasarse del tope de 500.
        $ids = $mensajes->pluck('id')->all();
        for ($i = 0; $i < 499; $i++) {
            $ids[] = 90000000 + $i;
        }

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/support-message/mark-read-bulk', ['ids' => $ids])
            ->assertStatus(422);

        $this->assertSame(
            2,
            SupportMessage::whereIn('id', $mensajes->pluck('id'))->whereNull('read_at')->count(),
            'Un lote rechazado no puede haber marcado nada.'
        );
    }

    /**
     * Un ticket de WhatsApp no sincroniza la lectura al ERP, igual que en el de a uno.
     *
     * El cliente de WhatsApp no tiene chat del ERP donde ver la lectura: sincronizar sería un POST
     * con dos reintentos y 15s de timeout contra una API que ni conoce ese ticket.
     *
     * @return void
     */
    public function test_un_ticket_de_whatsapp_no_sincroniza_la_lectura_al_erp(): void
    {
        $admin    = $this->crear_admin();
        $ticket   = $this->crear_ticket('whatsapp');
        $mensajes = $this->crear_mensajes_sin_leer($ticket, 5);

        $this->actingAs($admin, 'sanctum')->postJson(
            '/api/admin/support-message/mark-read-bulk',
            ['ids' => $mensajes->pluck('id')->all()]
        )->assertStatus(200);

        Http::assertNothingSent();
    }

    /**
     * Un ticket del ERP sí sincroniza, y una vez por mensaje: el endpoint del otro lado es por
     * mensaje (manda message_uuid) y no tiene variante en lote. Lo que este endpoint ahorra son
     * los requests del SPA y las consultas, no los POST al cliente.
     *
     * @return void
     */
    public function test_un_ticket_del_erp_sincroniza_la_lectura_de_cada_mensaje(): void
    {
        $admin    = $this->crear_admin();
        $ticket   = $this->crear_ticket('erp');
        $mensajes = $this->crear_mensajes_sin_leer($ticket, 4);

        $this->actingAs($admin, 'sanctum')->postJson(
            '/api/admin/support-message/mark-read-bulk',
            ['ids' => $mensajes->pluck('id')->all()]
        )->assertStatus(200);

        Http::assertSentCount(4);

        // Y con el read_at cargado: el modelo en memoria tiene que llevar el valor nuevo.
        Http::assertSent(function ($request) {
            $cuerpo = $request->data();

            return strpos($request->url(), '/api/admin-sync/support/messages/read') !== false
                && ! empty($cuerpo['message_uuid'])
                && ! empty($cuerpo['read_at']);
        });
    }
}
