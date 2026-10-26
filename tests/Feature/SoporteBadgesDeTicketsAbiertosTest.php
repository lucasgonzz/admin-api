<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\SupportTicket;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Badges de la bandeja de soporte: cuentan tickets ABIERTOS, no mensajes sin leer.
 *
 * Lucas pidió que los botones por operador y el ítem "Soporte" de la barra muestren la cantidad
 * de tickets abiertos. Antes contaban mensajes sin leer, y un ticket cerrado con mensajes viejos
 * sin leer seguía sumando en el badge. Lo que se protege acá:
 *
 * 1. Que cada fila de `inbox_nav` traiga `open_count` con los tickets `open` de ese operador
 *    (y la fila "Sin asignar" los que no tienen operador).
 * 2. Que `open_totals` traiga all / mine / unassigned con el mismo criterio.
 * 3. Que los tickets cerrados no cuenten.
 * 4. Que `unread_count` y `unread_totals` sigan viajando: la SPA vieja los usa.
 *
 * La base de testing del slot no se vacía entre corridas (DatabaseTransactions), así que los
 * totales globales (all, unassigned) se miden como diferencia contra una consulta previa; los de
 * cada operador arrancan en cero porque los operadores se crean en el test.
 */
class SoporteBadgesDeTicketsAbiertosTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Ningún test de este archivo sale a la red: el sync al empresa-api del cliente no participa.
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
     * @param string $name  Nombre visible del operador.
     * @param string $email Email único del admin.
     *
     * @return Admin
     */
    private function crear_admin(string $name, string $email): Admin
    {
        $admin           = new Admin();
        $admin->name     = $name;
        $admin->email    = $email;
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Cliente mínimo para colgarle tickets.
     *
     * @return Client
     */
    private function crear_cliente(): Client
    {
        $client               = new Client();
        $client->name         = 'Juan Pérez';
        $client->company_name = 'Distribuidora del Sur';
        $client->phone        = '+5493415551111';
        $client->is_active    = true;
        $client->save();

        return $client;
    }

    /**
     * Ticket con estado y operador asignado indicados.
     *
     * @param Client   $client      Cliente dueño del ticket.
     * @param string   $status      open|closed.
     * @param int|null $admin_id    Operador asignado (null = sin asignar).
     *
     * @return SupportTicket
     */
    private function crear_ticket(Client $client, string $status, ?int $admin_id): SupportTicket
    {
        return SupportTicket::create([
            'client_id'         => $client->id,
            'client_user_id'    => 0,
            'status'            => $status,
            'source'            => 'erp',
            'name'              => 'Consulta de prueba',
            'assigned_admin_id' => $admin_id,
            'opened_at'         => now()->subDays(2),
            'closed_at'         => $status === 'closed' ? now()->subHour() : null,
        ]);
    }

    /**
     * Busca en `inbox_nav` la fila de un operador (o la de "Sin asignar" con null).
     *
     * @param array    $inbox_nav Filas devueltas por la API.
     * @param int|null $admin_id  Operador buscado.
     *
     * @return array|null
     */
    private function fila_de(array $inbox_nav, ?int $admin_id): ?array
    {
        foreach ($inbox_nav as $row) {
            if ($row['assigned_admin_id'] === $admin_id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Arma el escenario: dos operadores con abiertos y cerrados, y tickets sin asignar.
     *
     * Operador A: 2 abiertos + 1 cerrado. Operador B: 1 abierto + 2 cerrados.
     * Sin asignar: 1 abierto + 1 cerrado.
     *
     * @param Admin $a Operador A (el que consulta).
     * @param Admin $b Operador B.
     *
     * @return void
     */
    private function armar_escenario(Admin $a, Admin $b): void
    {
        $client = $this->crear_cliente();

        $this->crear_ticket($client, 'open', $a->id);
        $this->crear_ticket($client, 'open', $a->id);
        $this->crear_ticket($client, 'closed', $a->id);

        $this->crear_ticket($client, 'open', $b->id);
        $this->crear_ticket($client, 'closed', $b->id);
        $this->crear_ticket($client, 'closed', $b->id);

        $this->crear_ticket($client, 'open', null);
        $this->crear_ticket($client, 'closed', null);
    }

    /**
     * Verifica una respuesta de la bandeja contra el escenario y la consulta previa.
     *
     * @param array $antes   Respuesta antes de crear los tickets (para los totales globales).
     * @param array $despues Respuesta después de crear los tickets.
     * @param Admin $a       Operador A (el autenticado).
     * @param Admin $b       Operador B.
     *
     * @return void
     */
    private function verificar_badges(array $antes, array $despues, Admin $a, Admin $b): void
    {
        // Contrato viejo intacto.
        $this->assertArrayHasKey('unread_totals', $despues);
        $this->assertArrayHasKey('mine', $despues['unread_totals']);
        $this->assertArrayHasKey('others', $despues['unread_totals']);

        // Totales de abiertos: los cerrados no cuentan.
        $this->assertSame(2, $despues['open_totals']['mine']);
        $this->assertSame(4, $despues['open_totals']['all'] - $antes['open_totals']['all']);
        $this->assertSame(1, $despues['open_totals']['unassigned'] - $antes['open_totals']['unassigned']);

        // Filas por operador.
        $fila_a = $this->fila_de($despues['inbox_nav'], (int) $a->id);
        $fila_b = $this->fila_de($despues['inbox_nav'], (int) $b->id);
        $this->assertNotNull($fila_a);
        $this->assertNotNull($fila_b);
        $this->assertSame(2, $fila_a['open_count']);
        $this->assertSame(1, $fila_b['open_count']);
        $this->assertArrayHasKey('unread_count', $fila_a);

        // Fila "Sin asignar": existe porque hay tickets sin operador.
        $fila_sin_asignar = $this->fila_de($despues['inbox_nav'], null);
        $this->assertNotNull($fila_sin_asignar);
        $this->assertSame('Sin asignar', $fila_sin_asignar['display_name']);
        $this->assertSame($despues['open_totals']['unassigned'], $fila_sin_asignar['open_count']);

        // La suma de open_count de todas las filas coincide con el total de abiertos.
        $suma = 0;
        foreach ($despues['inbox_nav'] as $row) {
            $suma += $row['open_count'];
        }
        $this->assertSame($despues['open_totals']['all'], $suma);
    }

    /**
     * GET /api/support-ticket/unread-badges cuenta tickets abiertos por operador y en total.
     *
     * @return void
     */
    public function test_unread_badges_cuenta_tickets_abiertos_por_operador()
    {
        $a = $this->crear_admin('Operador A', 'badges-abiertos-a@test.local');
        $b = $this->crear_admin('Operador B', 'badges-abiertos-b@test.local');

        $antes = $this->actingAs($a, 'sanctum')
            ->getJson('/api/admin/support-ticket/unread-badges')
            ->assertStatus(200)
            ->json();

        $this->armar_escenario($a, $b);

        $despues = $this->actingAs($a, 'sanctum')
            ->getJson('/api/admin/support-ticket/unread-badges')
            ->assertStatus(200)
            ->json();

        $this->verificar_badges($antes, $despues, $a, $b);
    }

    /**
     * GET /api/support-ticket (listado) trae los mismos badges de abiertos que unread-badges.
     *
     * @return void
     */
    public function test_listado_trae_los_badges_de_tickets_abiertos()
    {
        $a = $this->crear_admin('Operador A', 'badges-abiertos-lista-a@test.local');
        $b = $this->crear_admin('Operador B', 'badges-abiertos-lista-b@test.local');

        $antes = $this->actingAs($a, 'sanctum')
            ->getJson('/api/admin/support-ticket?assigned_filter=mine')
            ->assertStatus(200)
            ->json();

        $this->armar_escenario($a, $b);

        $despues = $this->actingAs($a, 'sanctum')
            ->getJson('/api/admin/support-ticket?assigned_filter=mine')
            ->assertStatus(200)
            ->json();

        $this->verificar_badges($antes, $despues, $a, $b);
        // Desde el 26/10/2026 el listado ignora `assigned_filter` (los tickets no pertenecen a un
        // operador): "mine" trae TODOS los tickets, abiertos y cerrados, de cualquier operador o
        // sin asignar. El escenario agrega 8 (3 de A, 3 de B y 2 sin asignar). Antes este assert
        // pedía los 3 de A, porque el filtro recortaba la lista por operador.
        $this->assertCount(count($antes['models']) + 8, $despues['models']);
    }
}
