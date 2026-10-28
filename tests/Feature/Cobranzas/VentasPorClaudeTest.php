<?php

namespace Tests\Feature\Cobranzas;

use App\Models\LicenciaCuota;
use App\Models\Venta;

/**
 * Las ventas de un cliente desde Claude (misión ventas-y-comisiones, 28/10/2026):
 * `GET` y `POST claude/clients/{id}/ventas`.
 *
 * Lo que estas pruebas protegen, en orden de importancia:
 *
 *  1. 🔴 **El dry_run por defecto no escribe NADA**, y devuelve la venta que se crearía con sus
 *     cuotas y su comisión.
 *  2. 🔴 **Sin `confirm_client_name` correcto no se escribe** (422), y el error no revela el nombre.
 *  3. Con el nombre correcto (sin importar mayúsculas ni espacios) se crea con `origen = claude`.
 *  4. Sin la clave del bloque (o con una inválida), 401: el middleware es fail-closed.
 *  5. El GET devuelve lo mismo que la pestaña Ventas del admin.
 */
class VentasPorClaudeTest extends BaseDeVentas
{
    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-claude-ventas';

    /**
     * Setea la clave de ingesta: en el .env del slot está vacía y el middleware es fail-closed, así
     * que sin esto todo devolvería 401 y los tests medirían el middleware, no el endpoint.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
    }

    /**
     * Headers del bloque `claude/*`.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ];
    }

    /**
     * dry_run por defecto: la venta que se crearía, sin escribir ni la venta ni las cuotas.
     *
     * @return void
     */
    public function test_el_dry_run_por_defecto_no_escribe(): void
    {
        $vendedor = $this->crear_vendedor();
        $client = $this->crear_cliente(['name' => 'Ferretería Rioplatense']);

        $respuesta = $this->postJson('/api/claude/clients/' . $client->id . '/ventas', [
            'productos' => ['gestion', 'ecommerce', 'agentes'],
        ], $this->headers());

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('dry_run'));
        $this->assertFalse($respuesta->json('escribio'));
        $this->assertNull($respuesta->json('venta.id'));
        $this->assertSame('claude', $respuesta->json('venta.origen'));
        $this->assertEqualsWithDelta(1500.0, $respuesta->json('venta.monto_total'), 0.001);
        $this->assertCount(3, $respuesta->json('venta.cuotas'));
        $this->assertEqualsWithDelta(500.0, $respuesta->json('venta.cuotas.2.monto'), 0.001);
        $this->assertSame($vendedor->id, $respuesta->json('venta.comision.vendedor_admin_id'));
        $this->assertEqualsWithDelta(225.0, $respuesta->json('venta.comision.total'), 0.001);

        $this->assertSame(0, Venta::where('client_id', $client->id)->count(), 'El dry_run no escribe la venta.');
        $this->assertSame(0, LicenciaCuota::where('client_id', $client->id)->count(), 'El dry_run no escribe cuotas.');

        // dry_run=true explícito, igual.
        $this->postJson('/api/claude/clients/' . $client->id . '/ventas', [
            'productos' => ['gestion'],
            'dry_run'   => true,
        ], $this->headers())->assertStatus(200);
        $this->assertSame(0, Venta::where('client_id', $client->id)->count());

        // Las reglas de negocio valen también en el dry_run: desarrollo sin importe es 422.
        $sin_importe = $this->postJson('/api/claude/clients/' . $client->id . '/ventas', [
            'productos' => ['desarrollo'],
        ], $this->headers());
        $sin_importe->assertStatus(422);
        $this->assertNotEmpty($sin_importe->json('detalles'));
        $this->assertArrayHasKey('error', $sin_importe->json());

        // Y las de forma, con el 422 del bloque.
        $this->postJson('/api/claude/clients/' . $client->id . '/ventas', ['productos' => ['hosting']], $this->headers())
            ->assertStatus(422)
            ->assertJsonStructure(['error', 'errores']);
    }

    /**
     * Con dry_run=false: sin confirm_client_name, o con uno que no coincide, 422 sin escribir; con
     * el nombre correcto (mayúsculas y espacios aparte), se crea con origen = claude.
     *
     * @return void
     */
    public function test_escribir_exige_el_nombre_del_cliente_y_crea_con_origen_claude(): void
    {
        $this->crear_vendedor();
        $client = $this->crear_cliente(['name' => 'Ferretería Rioplatense']);

        $payload = [
            'productos'   => ['desarrollo'],
            'descripcion' => 'Integración con la balanza',
            'monto_total' => 900,
            'dry_run'     => false,
        ];

        // Sin confirm_client_name.
        $sin = $this->postJson('/api/claude/clients/' . $client->id . '/ventas', $payload, $this->headers());
        $sin->assertStatus(422);
        $this->assertStringNotContainsString('Ferretería Rioplatense', $sin->getContent(), 'El error no revela el nombre correcto.');

        // Con otro nombre.
        $this->postJson('/api/claude/clients/' . $client->id . '/ventas', $payload + ['confirm_client_name' => 'Otra ferretería'], $this->headers())
            ->assertStatus(422);

        $this->assertSame(0, Venta::where('client_id', $client->id)->count(), 'Sin confirmar, no se escribe nada.');

        // Con el nombre correcto, en otras mayúsculas y con espacios.
        $real = $this->postJson('/api/claude/clients/' . $client->id . '/ventas', $payload + ['confirm_client_name' => '  ferretería RIOPLATENSE '], $this->headers());
        $real->assertStatus(201);
        $this->assertFalse($real->json('dry_run'));
        $this->assertTrue($real->json('escribio'));
        $this->assertSame('claude', $real->json('venta.origen'));
        $this->assertNull($real->json('venta.created_by_admin_id'));
        $this->assertCount(1, $real->json('ventas'), 'La respuesta trae la lista entera del cliente.');

        $venta_id = (int) $real->json('venta.id');
        $this->assertDatabaseHas('ventas', [
            'id'          => $venta_id,
            'client_id'   => $client->id,
            'origen'      => 'claude',
            'descripcion' => 'Integración con la balanza',
            'monto_total' => 900.00,
        ]);
        $this->assertSame(1, LicenciaCuota::where('venta_id', $venta_id)->count());
    }

    /**
     * El GET devuelve lo mismo que la pestaña Ventas del admin. Cliente inexistente: 404.
     *
     * @return void
     */
    public function test_el_get_devuelve_lo_mismo_que_la_pestana_del_admin(): void
    {
        $this->admin_logueado();
        $this->crear_vendedor();
        $client = $this->crear_cliente();

        $this->alta_de_venta($client, ['productos' => ['gestion', 'agentes']])->assertStatus(201);

        $de_claude = $this->getJson('/api/claude/clients/' . $client->id . '/ventas', $this->headers());
        $de_claude->assertStatus(200);

        $del_admin = $this->getJson('/api/admin/client/' . $client->id . '/ventas');

        $this->assertSame($del_admin->json(), $de_claude->json());
        $this->assertCount(1, $de_claude->json('ventas'));

        $this->getJson('/api/claude/clients/999999999/ventas', $this->headers())->assertStatus(404);
        $this->postJson('/api/claude/clients/999999999/ventas', ['productos' => ['gestion']], $this->headers())->assertStatus(404);
    }

    /**
     * Sin la clave, o con una inválida, 401 y nada escrito.
     *
     * @return void
     */
    public function test_sin_clave_o_con_clave_invalida_es_401(): void
    {
        $client = $this->crear_cliente(['name' => 'Ferretería Rioplatense']);

        $this->getJson('/api/claude/clients/' . $client->id . '/ventas', ['Accept' => 'application/json'])->assertStatus(401);

        $this->postJson('/api/claude/clients/' . $client->id . '/ventas', [
            'productos'           => ['gestion'],
            'dry_run'             => false,
            'confirm_client_name' => 'Ferretería Rioplatense',
        ], ['X-Claude-Task-Key' => 'clave-equivocada', 'Accept' => 'application/json'])->assertStatus(401);

        $this->assertSame(0, Venta::where('client_id', $client->id)->count());
    }
}
