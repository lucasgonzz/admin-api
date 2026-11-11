<?php

namespace Tests\Feature\FichaDelCliente;

use App\Models\ClientEmployee;
use App\Models\ClientSupportContext;
use App\Models\ClientSupportMemoryEntry;
use App\Models\ImplementationFollowup;
use App\Models\SupportTicket;
use Illuminate\Support\Str;
use Tests\Feature\ImplementationFollowups\BaseDeSeguimientos;

/**
 * La ficha del cliente desde el seguimiento proactivo (misión cruzada `ficha-del-cliente`, 11/11/2026): el claim trae
 * `ficha_cliente` e `indice_tickets` (como el dueño), y Martín escribe en la ficha y busca en el historial con la
 * reserva del SEGUIMIENTO (423 `invalid_lease` sin ella).
 */
class FichaDelClienteDesdeElSeguimientoTest extends BaseDeSeguimientos
{
    const BASE = '/api/claude/support-codex/implementation-followups/';

    /**
     * Un seguimiento vencido y reclamado: [client, followup, token, contexto].
     *
     * @return array
     */
    private function reclamado(): array
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation);
        $claim    = $this->postJson(self::BASE . $followup->id . '/claim', [], $this->headers())->assertOk();

        return [$client, $followup, (string) $claim->json('lease_token'), $claim->json('context')];
    }

    /**
     * El claim trae la ficha (orden del contrato, ficha operativa) y el índice de TODOS los tickets del cliente, los
     * de sus empleados incluidos (el seguimiento le escribe al dueño), sin ninguno marcado como actual.
     *
     * @return void
     */
    public function test_el_claim_trae_la_ficha_y_el_indice(): void
    {
        [$client, $implementation] = $this->escenario();
        $decision   = ClientSupportMemoryEntry::create(['client_id' => $client->id, 'categoria' => 'decision', 'texto' => 'La carga arranca el lunes 17.', 'status' => 'vigente', 'source' => 'martin']);
        $compromiso = ClientSupportMemoryEntry::create(['client_id' => $client->id, 'categoria' => 'compromiso', 'texto' => 'Manda la lista el viernes.', 'status' => 'vigente', 'source' => 'lucas', 'vigente_hasta' => '2026-11-13']);
        ClientSupportContext::create(['client_id' => $client->id, 'ficha_operativa' => 'Habla con Gastón.']);
        $empleado = ClientEmployee::create(['client_id' => $client->id, 'name' => 'Brisa', 'phone' => '+5493419998888']);
        $del_dueno = $this->el_cliente_escribe($client, now()->subDays(10), 'closed', 'Hola');
        $del_dueno->name = 'Lista de precios';
        $del_dueno->save();
        $del_empleado = SupportTicket::create([
            'client_id' => $client->id, 'client_employee_id' => $empleado->id, 'client_user_id' => 0, 'status' => 'closed',
            'source' => 'whatsapp', 'whatsapp_phone' => $empleado->phone, 'opened_at' => now()->subDays(9), 'closed_at' => now()->subDays(9),
        ]);

        $followup = $this->seguimiento($client, $implementation, ['last_client_contact_at' => now()->subDays(10)]);
        $contexto = $this->postJson(self::BASE . $followup->id . '/claim', [], $this->headers())->assertOk()->json('context');

        $this->assertSame([(int) $compromiso->id, (int) $decision->id], array_column($contexto['ficha_cliente']['entradas'], 'id'));
        $this->assertSame(2, $contexto['ficha_cliente']['total_vigentes']);
        $this->assertSame('Habla con Gastón.', $contexto['ficha_cliente']['ficha_operativa']);
        $this->assertSame([(int) $del_empleado->id, (int) $del_dueno->id], array_column($contexto['indice_tickets']['tickets'], 'id'));
        $this->assertSame('Lista de precios', $contexto['indice_tickets']['tickets'][1]['titulo']);
        $this->assertSame([false, false], array_column($contexto['indice_tickets']['tickets'], 'actual'));
        $this->assertSame(2, $contexto['indice_tickets']['total']);
    }

    /**
     * POST .../client-memory con la reserva del seguimiento: escribe (source martin, sin ticket) y devuelve el bloque.
     * Con otro token o sin reserva: 423 invalid_lease sin escribir nada; seguimiento inexistente: 404.
     *
     * @return void
     */
    public function test_escribir_la_ficha_con_la_reserva_del_seguimiento(): void
    {
        [$client, $followup, $token] = $this->reclamado();
        $ruta = self::BASE . $followup->id . '/client-memory';
        $alta = [['accion' => 'agregar', 'categoria' => 'implementacion', 'texto' => 'Quiere arrancar con la carga de Bronzen.']];

        $respuesta = $this->postJson($ruta, ['lease_token' => $token, 'operaciones' => $alta], $this->headers())->assertOk()->assertJsonPath('ok', true);
        $entrada = ClientSupportMemoryEntry::find($respuesta->json('resultados.0.id'));
        $this->assertSame((int) $client->id, $entrada->client_id);
        $this->assertSame('martin', $entrada->source);
        $this->assertNull($entrada->support_ticket_id);
        $this->assertSame(1, $respuesta->json('ficha_cliente.total_vigentes'));

        $otro = [['accion' => 'agregar', 'categoria' => 'otro', 'texto' => 'No entra']];
        $this->postJson($ruta, ['lease_token' => Str::random(64), 'operaciones' => $otro], $this->headers())->assertStatus(423)->assertJsonPath('error', 'invalid_lease');
        ImplementationFollowup::where('id', $followup->id)->update(['lease_expires_at' => now()->subMinute()]);
        $this->postJson($ruta, ['lease_token' => $token, 'operaciones' => $otro], $this->headers())->assertStatus(423);
        $this->postJson(self::BASE . '999999999/client-memory', ['lease_token' => $token, 'operaciones' => $otro], $this->headers())->assertStatus(404)->assertJsonPath('error', 'sin_seguimiento');
        $this->postJson($ruta, ['lease_token' => $token, 'operaciones' => [['accion' => 'agregar', 'texto' => 'Sin categoría']]], $this->headers())->assertStatus(422);
        $this->assertSame(1, ClientSupportMemoryEntry::where('client_id', $client->id)->count());
    }

    /**
     * GET .../history-search con la reserva del seguimiento en la query: busca en todos los tickets del cliente.
     * Sin lease_token: 422; con uno que no es el vigente: 423.
     *
     * @return void
     */
    public function test_buscar_en_el_historial_desde_el_seguimiento(): void
    {
        [$client, $followup, $token] = $this->reclamado();
        $viejo = $this->el_cliente_escribe($client, now()->subDays(40), 'closed', 'No me sale la factura A');

        $respuesta = $this->getJson(self::BASE . $followup->id . '/history-search?q=factura&lease_token=' . $token, $this->headers())->assertOk();
        $this->assertSame([(int) $viejo->id], array_column($respuesta->json('coincidencias'), 'ticket_id'));
        $this->assertStringContainsString('factura A', $respuesta->json('coincidencias.0.fragmento'));
        $this->assertGreaterThanOrEqual(1, $respuesta->json('total_tickets'));

        $this->getJson(self::BASE . $followup->id . '/history-search?q=factura', $this->headers())->assertStatus(422);
        $this->getJson(self::BASE . $followup->id . '/history-search?q=factura&lease_token=' . Str::random(64), $this->headers())->assertStatus(423)->assertJsonPath('error', 'invalid_lease');
    }
}
