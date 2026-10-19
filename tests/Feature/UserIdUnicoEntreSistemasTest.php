<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Demo;
use App\Services\UserIdBlockAllocatorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Un cliente o una demo no pueden cargar un `user_id` que ya tiene OTRO cliente u OTRA demo
 * (misión pusher-canales-por-sistema, 19/10/2026).
 *
 * 🔴 El porqué: toda la flota habla por una sola app de Pusher y los canales llevan solo el id del
 * dueño. El 19/10 demo3 (USER_ID 2600) le mandó a Ferretotal (dueño 2600) el aviso en vivo del
 * escaneo de una factura. La guarda vive en el evento `saving` de los dos modelos
 * (`App\Services\UserIdUnicoService`), así que estos tests pegan por el panel —los mismos endpoints
 * que usa admin-spa— y comprueban el 422 con `errors.user_id` que el toast muestra tal cual.
 *
 * Los duplicados HEREDADOS (El Kiosco Verde y Feitoamao con 1, el cliente 45 «DEMO» y la demo 1 con
 * 400) se siembran por query, salteando el modelo, porque así llegaron a producción: tienen que poder
 * seguir guardándose mientras nadie toque su `user_id`.
 *
 * Los ids se calculan por encima del máximo que ya haya en la base de testing del slot, para que
 * una fila que haya dejado otra corrida no choque con la del test por casualidad.
 */
class UserIdUnicoEntreSistemasTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Cambiarle el `user_id` a un cliente por el de otro cliente: 422, con el nombre y el número
     * del que ya lo tiene, y la fila sin tocar.
     *
     * @return void
     */
    public function test_un_cliente_no_puede_tomar_el_user_id_de_otro_cliente(): void
    {
        $base       = $this->user_id_libre();
        $ferretotal = $this->crear_cliente('Ferretotal', $base);
        $otro       = $this->crear_cliente('Otro comercio', $base + 100);

        $respuesta = $this->actingAs($this->crear_admin(), 'sanctum')
            ->putJson('/api/admin/client/' . $otro->id, ['user_id' => $base]);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonValidationErrors(['user_id']);

        // El mensaje nombra al que ya lo tiene, para que el operador sepa con quién choca.
        $mensaje = $respuesta->json('errors.user_id.0');
        $this->assertStringContainsString('El ID de comercio ' . $base . ' ya lo tiene el cliente Ferretotal (#' . $ferretotal->id . ')', $mensaje);

        // Nada se escribió: el saving corta antes del UPDATE.
        $this->assertSame($base + 100, (int) DB::table('clients')->where('id', $otro->id)->value('user_id'));
    }

    /**
     * Cargarle a una demo el `user_id` de un cliente (el caso exacto de demo3 ↔ Ferretotal): 422.
     *
     * @return void
     */
    public function test_una_demo_no_puede_tomar_el_user_id_de_un_cliente(): void
    {
        $base       = $this->user_id_libre();
        $ferretotal = $this->crear_cliente('Ferretotal', $base);
        $demo       = $this->crear_demo('Demo tres', $base + 100);

        $respuesta = $this->actingAs($this->crear_admin(), 'sanctum')
            ->putJson('/api/admin/demo/' . $demo->id, ['user_id' => $base]);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonValidationErrors(['user_id']);
        $this->assertStringContainsString('ya lo tiene el cliente Ferretotal (#' . $ferretotal->id . ')', $respuesta->json('errors.user_id.0'));

        $this->assertSame($base + 100, (int) DB::table('demos')->where('id', $demo->id)->value('user_id'));
    }

    /**
     * Al revés: un cliente nuevo no puede nacer con el `user_id` de una demo, y el mensaje nombra a
     * la demo por su `display_name()`.
     *
     * @return void
     */
    public function test_un_cliente_nuevo_no_puede_nacer_con_el_user_id_de_una_demo(): void
    {
        $base = $this->user_id_libre();
        $demo = $this->crear_demo('Demo tres', $base);

        // Cantidad de clientes antes del intento, para confirmar que no se creó ninguno.
        $clientes_antes = Client::count();

        $respuesta = $this->actingAs($this->crear_admin(), 'sanctum')
            ->postJson('/api/admin/client', [
                'name'    => 'Comercio nuevo',
                'user_id' => $base,
            ]);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonValidationErrors(['user_id']);
        $this->assertStringContainsString('ya lo tiene la demo Demo tres (#' . $demo->id . ')', $respuesta->json('errors.user_id.0'));
        $this->assertSame($clientes_antes, Client::count());
    }

    /**
     * Una demo no puede tomar el `user_id` de otra demo.
     *
     * @return void
     */
    public function test_una_demo_no_puede_tomar_el_user_id_de_otra_demo(): void
    {
        $base  = $this->user_id_libre();
        $demo1 = $this->crear_demo('Demo uno', $base);
        $demo2 = $this->crear_demo('Demo dos', $base + 100);

        $respuesta = $this->actingAs($this->crear_admin(), 'sanctum')
            ->putJson('/api/admin/demo/' . $demo2->id, ['user_id' => $base]);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('ya lo tiene la demo Demo uno (#' . $demo1->id . ')', $respuesta->json('errors.user_id.0'));
    }

    /**
     * Crear un cliente con un `user_id` que no tiene nadie: 201 y la fila con ese id.
     *
     * @return void
     */
    public function test_un_cliente_nuevo_con_un_user_id_libre_se_crea(): void
    {
        $base = $this->user_id_libre();

        $respuesta = $this->actingAs($this->crear_admin(), 'sanctum')
            ->postJson('/api/admin/client', [
                'name'    => 'Comercio nuevo',
                'user_id' => $base,
            ]);

        $respuesta->assertStatus(201);
        $this->assertSame(1, Client::where('user_id', $base)->count());
    }

    /**
     * Duplicado heredado (como El Kiosco Verde y Feitoamao, los dos con 1): se siembra por query,
     * salteando el modelo, y cualquiera de los dos se sigue pudiendo editar mientras el request no
     * cambie el `user_id` — ni mandándolo igual, que es lo que hace el formulario del panel al
     * guardar la ficha entera.
     *
     * @return void
     */
    public function test_un_duplicado_heredado_se_sigue_guardando_si_no_cambia_el_user_id(): void
    {
        $base         = $this->user_id_libre();
        $kiosco_id    = $this->sembrar_cliente_por_query('El Kiosco Verde', $base);
        $feitoamao_id = $this->sembrar_cliente_por_query('Feitoamao', $base);
        $admin        = $this->crear_admin();

        // Sin mandar el user_id: solo el teléfono.
        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/client/' . $feitoamao_id, ['phone' => '+5493410000001'])
            ->assertStatus(200);

        $this->assertSame('+5493410000001', DB::table('clients')->where('id', $feitoamao_id)->value('phone'));

        // Mandando el mismo user_id que ya tenía (como string, igual que lo manda un input).
        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/client/' . $kiosco_id, ['phone' => '+5493410000002', 'user_id' => (string) $base])
            ->assertStatus(200);

        $this->assertSame('+5493410000002', DB::table('clients')->where('id', $kiosco_id)->value('phone'));
        $this->assertSame(2, (int) DB::table('clients')->where('user_id', $base)->count());
    }

    /**
     * Duplicado heredado entre un cliente y una demo (el cliente 45 «DEMO» y la demo 1, los dos con
     * 400): la demo se sigue pudiendo editar sin tocar su `user_id`.
     *
     * @return void
     */
    public function test_una_demo_con_duplicado_heredado_se_sigue_guardando(): void
    {
        $base = $this->user_id_libre();
        $this->sembrar_cliente_por_query('DEMO', $base);
        $demo = $this->crear_demo('Demo uno', null);
        DB::table('demos')->where('id', $demo->id)->update(['user_id' => $base]);

        $this->actingAs($this->crear_admin(), 'sanctum')
            ->putJson('/api/admin/demo/' . $demo->id, ['nombre' => 'Demo uno renombrada', 'user_id' => $base])
            ->assertStatus(200);

        $this->assertSame('Demo uno renombrada', DB::table('demos')->where('id', $demo->id)->value('nombre'));
    }

    /**
     * El valor que ya tenía el propio registro no se cuenta como choque consigo mismo.
     *
     * @return void
     */
    public function test_el_mismo_valor_del_propio_registro_no_es_un_choque(): void
    {
        $base   = $this->user_id_libre();
        $client = $this->crear_cliente('Comercio único', $base);
        $demo   = $this->crear_demo('Demo única', $base + 100);
        $admin  = $this->crear_admin();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/client/' . $client->id, ['user_id' => $base, 'phone' => '+5493410000003'])
            ->assertStatus(200);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/demo/' . $demo->id, ['user_id' => $base + 100, 'nombre' => 'Demo única editada'])
            ->assertStatus(200);

        // Y un guardado directo por el modelo, que es el camino de los servicios y de claude/*.
        $client->user_id = $base;
        $client->name    = 'Comercio único editado';
        $client->save();

        $this->assertSame('Comercio único editado', DB::table('clients')->where('id', $client->id)->value('name'));
    }

    /**
     * Sin `user_id` no hay choque: en producción hay 7 clientes activos sin id cargado.
     *
     * @return void
     */
    public function test_dos_clientes_sin_user_id_conviven(): void
    {
        $this->crear_cliente('Sin id uno', null);

        $respuesta = $this->actingAs($this->crear_admin(), 'sanctum')
            ->postJson('/api/admin/client', ['name' => 'Sin id dos', 'user_id' => null]);

        $respuesta->assertStatus(201);
    }

    /**
     * El asignador de bloques no le sugiere a un lead nuevo el bloque de una demo: ancla en el
     * máximo de `clients`, y sin saltear el bloque tomado la promoción del lead terminaría en el 422.
     *
     * @return void
     */
    public function test_el_asignador_saltea_el_bloque_que_tiene_una_demo(): void
    {
        $base = $this->user_id_libre();

        // El cliente con el id más alto de la base: el asignador va a proponer $base + 100.
        $this->crear_cliente('Último cliente', $base);
        // Y ese bloque ya lo tiene una demo.
        $this->crear_demo('Demo con id alto', $base + 100);

        $sugerido = app(UserIdBlockAllocatorService::class)->suggest_next_block_start();

        $this->assertSame($base + 200, $sugerido);
    }

    /* ------------------------------------------------------------------------------------------
     | Fixtures
     |----------------------------------------------------------------------------------------- */

    /**
     * Un `user_id` múltiplo de 100 que no tiene ningún cliente, ninguna demo ni ningún bloque
     * reservado de la base de testing, con margen para sumarle unos cientos.
     *
     * @return int
     */
    private function user_id_libre(): int
    {
        // Mayor id ya usado en cualquiera de los tres lugares que miran la guarda y el asignador.
        $maximo = max(
            (int) DB::table('clients')->max('user_id'),
            (int) DB::table('demos')->max('user_id'),
            (int) DB::table('user_id_blocks')->max('block_start')
        );

        return (int) (ceil(($maximo + 1000) / 100) * 100);
    }

    /**
     * Cliente guardado por el modelo (o sea, pasando por la guarda).
     *
     * @param string   $nombre
     * @param int|null $user_id
     *
     * @return Client
     */
    private function crear_cliente(string $nombre, ?int $user_id): Client
    {
        $client                  = new Client();
        $client->name            = $nombre;
        $client->slug            = 'user-id-unico-' . Str::random(10);
        $client->user_id         = $user_id;
        $client->api_key         = Str::random(40);
        $client->inbound_api_key = Str::random(40);
        $client->is_active       = true;
        $client->save();

        return $client;
    }

    /**
     * Cliente insertado por query, salteando el modelo y su guarda: así llegaron a producción los
     * duplicados heredados.
     *
     * @param string $nombre
     * @param int    $user_id
     *
     * @return int Id del cliente insertado.
     */
    private function sembrar_cliente_por_query(string $nombre, int $user_id): int
    {
        return (int) DB::table('clients')->insertGetId([
            'uuid'            => (string) Str::uuid(),
            'name'            => $nombre,
            'slug'            => 'user-id-heredado-' . Str::random(10),
            'user_id'         => $user_id,
            'api_key'         => Str::random(40),
            'inbound_api_key' => Str::random(40),
            'is_active'       => true,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    /**
     * Demo guardada por el modelo (pasando por la guarda).
     *
     * @param string   $nombre
     * @param int|null $user_id
     *
     * @return Demo
     */
    private function crear_demo(string $nombre, ?int $user_id): Demo
    {
        // Sufijo para que cada demo tenga sus propias URLs.
        $sufijo = strtolower(Str::random(8));

        $demo                    = new Demo();
        $demo->nombre            = $nombre;
        $demo->user_id           = $user_id;
        $demo->erp_spa_url       = 'https://demo-' . $sufijo . '.comerciocity.com';
        $demo->erp_api_url       = 'https://api-demo-' . $sufijo . '.comerciocity.com';
        $demo->ecommerce_spa_url = 'https://tienda-demo-' . $sufijo . '.comerciocity.com';
        $demo->ecommerce_api_url = 'https://api-tienda-demo-' . $sufijo . '.comerciocity.com';
        $demo->save();

        return $demo;
    }

    /**
     * Admin operador para autenticarse contra el panel.
     *
     * @return Admin
     */
    private function crear_admin(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Lucas';
        $admin->email    = 'user-id-unico-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }
}
