<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\EnvChangeBatch;
use App\Models\EnvChangeItem;
use App\Services\EnvSshService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Fakes\EnvSshServiceFake;
use Tests\TestCase;

/**
 * Cambio masivo de variables .env sobre los clientes, en dos tiempos.
 *
 * Lo que se prueba acá no es que el sed funcione —eso pasa en el servidor del cliente— sino los
 * frenos: que previsualizar no escriba, que un .env tocado en el medio no se pise, que un cliente
 * roto no se lleve puesto al resto, y que un secreto no quede en claro en la base del admin.
 *
 * Son los frenos que existen porque esto se va a manejar por voz, donde un error de transcripción
 * aplicado "a todos" se lleva puestos los 40 clientes de una.
 */
class EnvMasivoDeClientesTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Reemplazo en memoria del servicio SSH, bindeado en el container para toda la prueba.
     *
     * @var EnvSshServiceFake
     */
    private $ssh_fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ssh_fake = new EnvSshServiceFake();

        $this->app->instance(EnvSshService::class, $this->ssh_fake);
    }

    public function test_previsualizar_devuelve_el_diff_y_no_escribe_una_sola_linea(): void
    {
        $admin  = $this->crear_admin('env-preview@test.local');
        $client = $this->crear_cliente('Distribuidora Uno', "APP_ENV=production\nANTHROPIC_API_KEY=sk-viejo-123456\n");

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-bulk/preview', [
            'alcance' => 'todos',
            'vars'    => ['ANTHROPIC_API_KEY' => 'sk-nuevo-abcdef'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['token', 'expira_en', 'clientes']);

        $cliente = $response->json('clientes.0');
        $this->assertSame('ok', $cliente['status']);
        $this->assertSame('ANTHROPIC_API_KEY', $cliente['cambios'][0]['key']);
        $this->assertTrue($cliente['cambios'][0]['cambia']);

        /* Lo que importa: previsualizar no tocó el .env de nadie. */
        $this->assertSame([], $this->ssh_fake->escrituras);
        $this->assertSame([], $this->ssh_fake->backups);
        $this->assertStringContainsString('sk-viejo-123456', $this->ssh_fake->envs[$client->active_client_api->id]);
    }

    public function test_aplicar_escribe_previo_backup_y_solo_con_un_token_valido(): void
    {
        $admin  = $this->crear_admin('env-apply@test.local');
        $client = $this->crear_cliente('Distribuidora Dos', "APP_ENV=production\nANTHROPIC_API_KEY=sk-viejo-123456\n");
        $api_id = $client->active_client_api->id;

        $token = $this->previsualizar($admin, ['ANTHROPIC_API_KEY' => 'sk-nuevo-abcdef']);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-bulk/apply', [
            'token'     => $token,
            'confirmar' => true,
        ]);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('aplicados'));
        $this->assertSame(0, $response->json('fallidos'));

        /* Se escribió el valor real, y antes se hizo el backup. */
        $this->assertSame('sk-nuevo-abcdef', $this->ssh_fake->escrituras[$api_id]['ANTHROPIC_API_KEY']);
        $this->assertArrayHasKey($api_id, $this->ssh_fake->backups);
    }

    public function test_un_token_inexistente_o_vencido_no_escribe_nada(): void
    {
        $admin = $this->crear_admin('env-token@test.local');
        $this->crear_cliente('Distribuidora Tres', "ANTHROPIC_API_KEY=sk-viejo-123456\n");

        /* Token que nunca existió. */
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/env-bulk/apply', ['token' => Str::random(64), 'confirmar' => true])
            ->assertStatus(422);

        /* Token real, pero vencido. */
        $token = $this->previsualizar($admin, ['ANTHROPIC_API_KEY' => 'sk-nuevo-abcdef']);

        $batch             = EnvChangeBatch::where('token', $token)->first();
        $batch->expires_at = Carbon::now()->subMinute();
        $batch->save();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/env-bulk/apply', ['token' => $token, 'confirmar' => true])
            ->assertStatus(422);

        $this->assertSame([], $this->ssh_fake->escrituras);
    }

    public function test_el_mismo_token_no_se_aplica_dos_veces(): void
    {
        $admin = $this->crear_admin('env-doble@test.local');
        $this->crear_cliente('Distribuidora Cuatro', "ANTHROPIC_API_KEY=sk-viejo-123456\n");

        $token = $this->previsualizar($admin, ['ANTHROPIC_API_KEY' => 'sk-nuevo-abcdef']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/env-bulk/apply', ['token' => $token, 'confirmar' => true])
            ->assertStatus(200);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/env-bulk/apply', ['token' => $token, 'confirmar' => true])
            ->assertStatus(422);
    }

    public function test_un_env_que_cambio_despues_de_previsualizar_no_se_pisa(): void
    {
        $admin  = $this->crear_admin('env-stale@test.local');
        $client = $this->crear_cliente('Distribuidora Cinco', "ANTHROPIC_API_KEY=sk-viejo-123456\n");
        $api_id = $client->active_client_api->id;

        $token = $this->previsualizar($admin, ['ANTHROPIC_API_KEY' => 'sk-nuevo-abcdef']);

        /* Alguien tocó el .env entre la previsualización y la confirmación: otro deploy, o a mano. */
        $this->ssh_fake->envs[$api_id] = "ANTHROPIC_API_KEY=sk-viejo-123456\nOTRA_COSA=1\n";

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-bulk/apply', [
            'token'     => $token,
            'confirmar' => true,
        ]);

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('aplicados'));
        $this->assertSame('omitido', $response->json('clientes.0.status'));

        /* No se escribió nada: lo que se confirmó ya no era lo que había en el servidor. */
        $this->assertSame([], $this->ssh_fake->escrituras);
        $this->assertSame('stale', EnvChangeItem::where('client_api_id', $api_id)->first()->status);
    }

    public function test_un_cliente_que_falla_no_aborta_el_lote(): void
    {
        $admin = $this->crear_admin('env-parcial@test.local');

        $roto = $this->crear_cliente('Distribuidora Rota', "ANTHROPIC_API_KEY=sk-viejo-123456\n");
        $sano = $this->crear_cliente('Distribuidora Sana', "ANTHROPIC_API_KEY=sk-viejo-123456\n");

        /* El primero no responde por SSH; el segundo sí. */
        $this->ssh_fake->fallan_al_leer[$roto->active_client_api->id] = 'No se pudo conectar por SSH.';

        $preview = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-bulk/preview', [
            'alcance' => 'todos',
            'vars'    => ['ANTHROPIC_API_KEY' => 'sk-nuevo-abcdef'],
        ]);

        $preview->assertStatus(200);

        /* El cliente roto se reporta con su error y el sano se previsualiza igual. */
        $estados = collect($preview->json('clientes'))->pluck('status', 'nombre');
        $this->assertSame('error', $estados['Distribuidora Rota']);
        $this->assertSame('ok', $estados['Distribuidora Sana']);

        $apply = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-bulk/apply', [
            'token'     => $preview->json('token'),
            'confirmar' => true,
        ]);

        $apply->assertStatus(200);
        $this->assertSame(1, $apply->json('aplicados'));
        $this->assertSame(
            'sk-nuevo-abcdef',
            $this->ssh_fake->escrituras[$sano->active_client_api->id]['ANTHROPIC_API_KEY']
        );
    }

    public function test_un_secreto_nunca_queda_en_claro_en_la_base_ni_en_la_respuesta(): void
    {
        $admin = $this->crear_admin('env-secreto@test.local');
        $this->crear_cliente('Distribuidora Seis', "ANTHROPIC_API_KEY=sk-viejo-123456\nDB_HOST=127.0.0.1\n");

        $preview = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-bulk/preview', [
            'alcance' => 'todos',
            'vars'    => [
                'ANTHROPIC_API_KEY' => 'sk-nuevo-abcdef-secreto',
                'DB_HOST'           => '10.0.0.9',
            ],
        ]);

        $preview->assertStatus(200);

        /* La respuesta enmascara el secreto y deja legible lo que no lo es. */
        $cambios = collect($preview->json('clientes.0.cambios'))->keyBy('key');
        $this->assertStringNotContainsString('secreto', $cambios['ANTHROPIC_API_KEY']['valor_nuevo']);
        $this->assertTrue($cambios['ANTHROPIC_API_KEY']['sensible']);
        $this->assertSame('10.0.0.9', $cambios['DB_HOST']['valor_nuevo']);
        $this->assertFalse($cambios['DB_HOST']['sensible']);

        /* En la base, la columna legible tampoco tiene el valor completo. */
        $item = EnvChangeItem::where('env_key', 'ANTHROPIC_API_KEY')->first();
        $this->assertStringNotContainsString('secreto', (string) $item->new_value_masked);
        $this->assertSame(hash('sha256', 'sk-nuevo-abcdef-secreto'), $item->new_value_sha256);

        /* El valor real está cifrado: la fila cruda no lo contiene en texto plano. */
        $crudo = \DB::table('env_change_items')->where('id', $item->id)->first();
        $this->assertStringNotContainsString('sk-nuevo-abcdef-secreto', (string) $crudo->new_value_encrypted);

        /* Y después de aplicar, deja de estar guardado. */
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-bulk/apply', [
            'token'     => $preview->json('token'),
            'confirmar' => true,
        ])->assertStatus(200);

        $this->assertNull(EnvChangeItem::find($item->id)->new_value_encrypted);
    }

    public function test_el_alcance_es_obligatorio_y_una_lista_vacia_no_significa_todos(): void
    {
        $admin = $this->crear_admin('env-alcance@test.local');
        $this->crear_cliente('Distribuidora Siete', "ANTHROPIC_API_KEY=sk-viejo-123456\n");

        /* Sin alcance no se previsualiza: "a todos" tiene que decirse, no deducirse. */
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-bulk/preview', [
            'vars' => ['ANTHROPIC_API_KEY' => 'sk-nuevo-abcdef'],
        ])->assertStatus(422);

        /* Alcance 'seleccion' con la lista vacía tampoco cae en "todos": es un error. */
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-bulk/preview', [
            'alcance' => 'seleccion',
            'clients' => [],
            'vars'    => ['ANTHROPIC_API_KEY' => 'sk-nuevo-abcdef'],
        ])->assertStatus(422);

        $this->assertSame([], $this->ssh_fake->escrituras);
    }

    public function test_un_nombre_de_variable_invalido_se_rechaza_antes_de_llegar_al_servidor(): void
    {
        $admin = $this->crear_admin('env-keyrara@test.local');
        $this->crear_cliente('Distribuidora Ocho', "ANTHROPIC_API_KEY=sk-viejo-123456\n");

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-bulk/preview', [
            'alcance' => 'todos',
            'vars'    => ['FOO; rm -rf /' => 'lo que sea'],
        ])->assertStatus(422);

        $this->assertSame([], $this->ssh_fake->escrituras);
    }

    public function test_el_listado_de_clientes_no_abre_ninguna_conexion(): void
    {
        $admin = $this->crear_admin('env-listado@test.local');
        $this->crear_cliente('Distribuidora Nueve', "ANTHROPIC_API_KEY=sk-viejo-123456\n");

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/env-bulk/clients');

        $response->assertStatus(200);

        $cliente = collect($response->json('clientes'))->firstWhere('nombre', 'Distribuidora Nueve');
        $this->assertNotNull($cliente);
        $this->assertTrue($cliente['operable']);
        $this->assertSame('shared_hosting', $cliente['hosting_type']);
    }

    /**
     * Previsualiza un cambio sobre todos los clientes y devuelve el token.
     *
     * @param  Admin  $admin
     * @param  array<string, string>  $vars
     * @return string
     */
    private function previsualizar(Admin $admin, array $vars): string
    {
        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-bulk/preview', [
            'alcance' => 'todos',
            'vars'    => $vars,
        ]);

        $response->assertStatus(200);

        return $response->json('token');
    }

    /**
     * Crea un cliente con su API activa y le carga un .env en el servicio SSH falso.
     *
     * @param  string  $nombre
     * @param  string  $contenido_env
     * @return Client
     */
    private function crear_cliente(string $nombre, string $contenido_env): Client
    {
        $sufijo = Str::random(8);

        $client                  = new Client();
        $client->name            = $nombre;
        $client->slug            = Str::slug($nombre) . '-' . $sufijo;
        $client->api_url         = 'https://api-' . $sufijo . '.comerciocity.com';
        $client->api_key         = Str::random(20);
        $client->inbound_api_key = Str::random(20);
        $client->save();

        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = 'https://api-' . $sufijo . '.comerciocity.com';
        $api->path         = $sufijo . '/api';
        $api->hosting_type = 'shared_hosting';
        $api->save();

        $client->active_client_api_id = $api->id;
        $client->save();

        $this->ssh_fake->envs[$api->id] = $contenido_env;

        return $client->fresh('active_client_api');
    }

    /**
     * Crea un admin para autenticar las requests.
     *
     * @param  string  $email
     * @return Admin
     */
    private function crear_admin(string $email): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = $email;
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }
}
