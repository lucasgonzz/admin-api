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
 * frenos: que previsualizar no escriba, que un .env tocado en el medio no se pise, que una
 * escritura que falla no se dé por buena, que un cliente roto no se lleve puesto al resto, y que un
 * secreto no quede en claro ni guardado de más en la base del admin.
 *
 * Son los frenos que existen porque esto se va a manejar por voz, donde un error de transcripción
 * aplicado "a todos" se lleva puestos los 40 clientes de una.
 *
 * ⚠️ Todos los casos previsualizan con alcance 'seleccion' y los ids que crea la propia prueba: con
 * 'todos' bastaría un cliente preexistente en admin_testing_s4 para poner los tests en rojo sin que
 * haya ninguna regresión real.
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

        $response = $this->previsualizar($admin, [$client->id], ['ANTHROPIC_API_KEY' => 'sk-nuevo-abcdef']);

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

        $token = $this->previsualizar($admin, [$client->id], ['ANTHROPIC_API_KEY' => 'sk-nuevo-abcdef'])->json('token');

        $response = $this->aplicar($admin, $token);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('aplicados'));
        $this->assertSame(0, $response->json('fallidos'));
        $this->assertTrue($response->json('completo'));

        /* Se escribió el valor real, y antes se hizo el backup. */
        $this->assertSame('sk-nuevo-abcdef', $this->ssh_fake->escrituras[$api_id]['ANTHROPIC_API_KEY']);
        $this->assertArrayHasKey($api_id, $this->ssh_fake->backups);
    }

    public function test_una_escritura_que_falla_no_se_da_por_aplicada_ni_pierde_el_secreto(): void
    {
        $admin  = $this->crear_admin('env-escritura-falla@test.local');
        $client = $this->crear_cliente('Distribuidora Sin Permisos', "ANTHROPIC_API_KEY=sk-viejo-123456\n");
        $api_id = $client->active_client_api->id;

        /* El sed no puede escribir: permisos, cuota agotada, disco lleno. */
        $this->ssh_fake->fallan_al_escribir[$api_id] = 'La escritura no quedó aplicada: revisá permisos.';

        $token = $this->previsualizar($admin, [$client->id], ['ANTHROPIC_API_KEY' => 'sk-nuevo-abcdef'])->json('token');

        $response = $this->aplicar($admin, $token);

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('aplicados'));
        $this->assertSame(1, $response->json('fallidos'));
        $this->assertSame('error', $response->json('clientes.0.status'));

        /*
         * El valor cifrado NO se borró: sin él no habría con qué reintentar salvo volviendo a
         * dictar la API key. Antes se marcaba aplicado y se descartaba el secreto.
         */
        $item = $this->item_del_batch($token, 'ANTHROPIC_API_KEY');
        $this->assertSame('failed', $item->status);
        $this->assertSame('sk-nuevo-abcdef', $item->new_value_encrypted);
    }

    public function test_un_token_inexistente_o_vencido_no_escribe_nada(): void
    {
        $admin  = $this->crear_admin('env-token@test.local');
        $client = $this->crear_cliente('Distribuidora Tres', "ANTHROPIC_API_KEY=sk-viejo-123456\n");

        /* Token que nunca existió. */
        $this->aplicar($admin, Str::random(64))->assertStatus(422);

        /* Token real, pero vencido. */
        $token = $this->previsualizar($admin, [$client->id], ['ANTHROPIC_API_KEY' => 'sk-nuevo-abcdef'])->json('token');

        $batch             = EnvChangeBatch::where('token', $token)->first();
        $batch->expires_at = Carbon::now()->subMinute();
        $batch->save();

        $this->aplicar($admin, $token)->assertStatus(422);

        $this->assertSame([], $this->ssh_fake->escrituras);
    }

    public function test_el_mismo_token_no_se_aplica_dos_veces(): void
    {
        $admin  = $this->crear_admin('env-doble@test.local');
        $client = $this->crear_cliente('Distribuidora Cuatro', "ANTHROPIC_API_KEY=sk-viejo-123456\n");

        $token = $this->previsualizar($admin, [$client->id], ['ANTHROPIC_API_KEY' => 'sk-nuevo-abcdef'])->json('token');

        $this->aplicar($admin, $token)->assertStatus(200);
        $this->aplicar($admin, $token)->assertStatus(422);
    }

    public function test_un_lote_cortado_a_la_mitad_se_puede_reanudar(): void
    {
        $admin  = $this->crear_admin('env-reanudar@test.local');
        $client = $this->crear_cliente('Distribuidora Cortada', "ANTHROPIC_API_KEY=sk-viejo-123456\n");
        $api_id = $client->active_client_api->id;

        /* Primera corrida: la escritura falla, así que el lote queda incompleto. */
        $this->ssh_fake->fallan_al_escribir[$api_id] = 'Se cortó la conexión.';

        $token = $this->previsualizar($admin, [$client->id], ['ANTHROPIC_API_KEY' => 'sk-nuevo-abcdef'])->json('token');

        $primera = $this->aplicar($admin, $token);
        $primera->assertStatus(200);
        $this->assertFalse($primera->json('completo'));

        /*
         * El renglón quedó 'failed' pero CONSERVA su valor cifrado: es lo que permite reintentarlo
         * sin que Lucas tenga que volver a dictar la API key.
         */
        $item = $this->item_del_batch($token, 'ANTHROPIC_API_KEY');
        $this->assertSame('failed', $item->status);
        $this->assertSame('sk-nuevo-abcdef', $item->new_value_encrypted);

        /* Se arregla el problema en el servidor del cliente y se reintenta con el mismo token. */
        unset($this->ssh_fake->fallan_al_escribir[$api_id]);

        $segunda = $this->aplicar($admin, $token, true);
        $segunda->assertStatus(200);
        $this->assertSame(1, $segunda->json('aplicados'));
        $this->assertTrue($segunda->json('completo'));
        $this->assertSame('sk-nuevo-abcdef', $this->ssh_fake->escrituras[$api_id]['ANTHROPIC_API_KEY']);
    }

    public function test_un_env_que_cambio_despues_de_previsualizar_no_se_pisa(): void
    {
        $admin  = $this->crear_admin('env-stale@test.local');
        $client = $this->crear_cliente('Distribuidora Cinco', "ANTHROPIC_API_KEY=sk-viejo-123456\n");
        $api_id = $client->active_client_api->id;

        $token = $this->previsualizar($admin, [$client->id], ['ANTHROPIC_API_KEY' => 'sk-nuevo-abcdef'])->json('token');

        /* Alguien tocó el .env entre la previsualización y la confirmación: otro deploy, o a mano. */
        $this->ssh_fake->envs[$api_id] = "ANTHROPIC_API_KEY=sk-viejo-123456\nOTRA_COSA=1\n";

        $response = $this->aplicar($admin, $token);

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('aplicados'));
        $this->assertSame(1, $response->json('omitidos'));
        $this->assertSame('omitido', $response->json('clientes.0.status'));

        /* No se escribió nada: lo que se confirmó ya no era lo que había en el servidor. */
        $this->assertSame([], $this->ssh_fake->escrituras);
        $this->assertSame('stale', $this->item_del_batch($token, 'ANTHROPIC_API_KEY')->status);
    }

    public function test_un_cliente_que_falla_no_aborta_el_lote(): void
    {
        $admin = $this->crear_admin('env-parcial@test.local');

        $roto = $this->crear_cliente('Distribuidora Rota', "ANTHROPIC_API_KEY=sk-viejo-123456\n");
        $sano = $this->crear_cliente('Distribuidora Sana', "ANTHROPIC_API_KEY=sk-viejo-123456\n");

        /* El primero no responde por SSH; el segundo sí. */
        $this->ssh_fake->fallan_al_leer[$roto->active_client_api->id] = 'No se pudo conectar por SSH.';

        $preview = $this->previsualizar($admin, [$roto->id, $sano->id], ['ANTHROPIC_API_KEY' => 'sk-nuevo-abcdef']);
        $preview->assertStatus(200);

        /* El cliente roto se reporta con su error y el sano se previsualiza igual. */
        $estados = collect($preview->json('clientes'))->pluck('status', 'nombre');
        $this->assertSame('error', $estados['Distribuidora Rota']);
        $this->assertSame('ok', $estados['Distribuidora Sana']);

        $apply = $this->aplicar($admin, $preview->json('token'));

        $apply->assertStatus(200);
        $this->assertSame(1, $apply->json('aplicados'));
        $this->assertSame(
            'sk-nuevo-abcdef',
            $this->ssh_fake->escrituras[$sano->active_client_api->id]['ANTHROPIC_API_KEY']
        );
    }

    public function test_un_secreto_nunca_queda_en_claro_en_la_base_ni_en_la_respuesta(): void
    {
        $admin  = $this->crear_admin('env-secreto@test.local');
        $client = $this->crear_cliente('Distribuidora Seis', "ANTHROPIC_API_KEY=sk-viejo-123456\nDB_HOST=127.0.0.1\n");

        $preview = $this->previsualizar($admin, [$client->id], [
            'ANTHROPIC_API_KEY' => 'sk-nuevo-abcdef-secreto',
            'DB_HOST'           => '10.0.0.9',
        ]);

        $preview->assertStatus(200);

        /* La respuesta enmascara el secreto y deja legible lo que no lo es. */
        $cambios = collect($preview->json('clientes.0.cambios'))->keyBy('key');
        $this->assertStringNotContainsString('secreto', $cambios['ANTHROPIC_API_KEY']['valor_nuevo']);
        $this->assertTrue($cambios['ANTHROPIC_API_KEY']['sensible']);
        $this->assertSame('10.0.0.9', $cambios['DB_HOST']['valor_nuevo']);
        $this->assertFalse($cambios['DB_HOST']['sensible']);

        /* En la base, la columna legible tampoco tiene el valor completo. */
        $item = $this->item_del_batch($preview->json('token'), 'ANTHROPIC_API_KEY');
        $this->assertStringNotContainsString('secreto', (string) $item->new_value_masked);
        $this->assertSame(hash('sha256', 'sk-nuevo-abcdef-secreto'), $item->new_value_sha256);

        /* El valor real está cifrado: la fila cruda no lo contiene en texto plano. */
        $crudo = \DB::table('env_change_items')->where('id', $item->id)->first();
        $this->assertStringNotContainsString('sk-nuevo-abcdef-secreto', (string) $crudo->new_value_encrypted);

        /* Y después de aplicar, deja de estar guardado. */
        $this->aplicar($admin, $preview->json('token'))->assertStatus(200);

        $this->assertNull(EnvChangeItem::find($item->id)->new_value_encrypted);
    }

    public function test_un_valor_que_ya_coincide_no_se_reescribe_ni_guarda_el_secreto(): void
    {
        $admin  = $this->crear_admin('env-igual@test.local');
        $client = $this->crear_cliente('Distribuidora Siete', "ANTHROPIC_API_KEY=sk-ya-esta-puesto\n");

        $preview = $this->previsualizar($admin, [$client->id], ['ANTHROPIC_API_KEY' => 'sk-ya-esta-puesto']);
        $preview->assertStatus(200);

        $this->assertFalse($preview->json('clientes.0.cambios.0.cambia'));

        /*
         * 🔴 Un renglón 'unchanged' guarda el valor que el cliente YA TIENE en producción. Si se
         * guardara cifrado, aplicar() nunca lo mira y el secreto vigente de cada cliente quedaría
         * archivado en la base del admin para siempre. Es el caso más común de todos: la segunda
         * corrida de cualquier cambio masivo.
         */
        $item = $this->item_del_batch($preview->json('token'), 'ANTHROPIC_API_KEY');
        $this->assertSame('unchanged', $item->status);
        $this->assertNull($item->new_value_encrypted);

        $apply = $this->aplicar($admin, $preview->json('token'));
        $apply->assertStatus(200);

        /* Aparece en la cuenta como "sin cambios", no desaparece del informe. */
        $this->assertSame(0, $apply->json('aplicados'));
        $this->assertSame(1, $apply->json('sin_cambios'));
        $this->assertSame([], $this->ssh_fake->escrituras);
    }

    public function test_un_valor_con_comillas_se_relee_igual_a_como_se_escribio(): void
    {
        $admin  = $this->crear_admin('env-comillas@test.local');
        $client = $this->crear_cliente('Distribuidora Ocho', "MAIL_FROM_NAME=Comercio\n");

        /* Valor con comillas, barra y signo peso: los tres rompen el ida y vuelta si se maneja mal. */
        $valor = 'Comercio "City" \\ $HOME';

        $this->aplicar($admin, $this->previsualizar($admin, [$client->id], ['MAIL_FROM_NAME' => $valor])->json('token'))
            ->assertStatus(200);

        /*
         * Segunda corrida con EL MISMO valor: tiene que detectarse como "ya está puesto". Si el
         * parseo no fuera la inversa exacta del formateo, acá daría 'cambia' y cada corrida
         * reescribiría lo mismo, dejando un backup nuevo en el servidor del cliente cada vez.
         */
        $segunda = $this->previsualizar($admin, [$client->id], ['MAIL_FROM_NAME' => $valor]);
        $segunda->assertStatus(200);

        $this->assertFalse($segunda->json('clientes.0.cambios.0.cambia'));
        $this->assertSame($valor, $segunda->json('clientes.0.cambios.0.valor_actual'));
    }

    public function test_el_alcance_es_obligatorio_y_una_lista_vacia_no_significa_todos(): void
    {
        $admin = $this->crear_admin('env-alcance@test.local');
        $this->crear_cliente('Distribuidora Nueve', "ANTHROPIC_API_KEY=sk-viejo-123456\n");

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

    public function test_el_servicio_tambien_rechaza_la_lista_vacia_sin_pasar_por_el_endpoint(): void
    {
        /*
         * El invariante tiene que estar donde está descripto, no sólo en la validación del
         * controller: un comando de artisan o un endpoint futuro que reusara el servicio con una
         * lista vacía le escribiría a los 40 clientes creyendo que no le escribe a ninguno.
         */
        $this->expectException(\InvalidArgumentException::class);

        $this->app->make(\App\Services\EnvBulkChangeService::class)
            ->previsualizar(['ANTHROPIC_API_KEY' => 'sk-nuevo'], []);
    }

    public function test_un_valor_con_salto_de_linea_o_nulo_se_rechaza(): void
    {
        $admin  = $this->crear_admin('env-multilinea@test.local');
        $client = $this->crear_cliente('Distribuidora Diez', "ANTHROPIC_API_KEY=sk-viejo-123456\n");

        /* Un salto de línea partiría el comando de sed en dos y lo haría abortar. */
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-bulk/preview', [
            'alcance' => 'seleccion',
            'clients' => [$client->id],
            'vars'    => ['MAIL_FROM_NAME' => "Comercio\nCity"],
        ])->assertStatus(422);

        /* Un null blanquearía la variable en todos los clientes sin decir nada. */
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-bulk/preview', [
            'alcance' => 'seleccion',
            'clients' => [$client->id],
            'vars'    => ['ANTHROPIC_API_KEY' => null],
        ])->assertStatus(422);

        $this->assertSame([], $this->ssh_fake->escrituras);
    }

    public function test_un_nombre_de_variable_invalido_se_rechaza_antes_de_llegar_al_servidor(): void
    {
        $admin  = $this->crear_admin('env-keyrara@test.local');
        $client = $this->crear_cliente('Distribuidora Once', "ANTHROPIC_API_KEY=sk-viejo-123456\n");

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-bulk/preview', [
            'alcance' => 'seleccion',
            'clients' => [$client->id],
            'vars'    => ['FOO; rm -rf /' => 'lo que sea'],
        ])->assertStatus(422);

        $this->assertSame([], $this->ssh_fake->escrituras);
    }

    public function test_un_cliente_dado_de_baja_no_entra_en_a_todos(): void
    {
        $admin = $this->crear_admin('env-baja@test.local');

        $baja = $this->crear_cliente('Distribuidora De Baja', "ANTHROPIC_API_KEY=sk-viejo-123456\n");
        $baja->is_active = false;
        $baja->save();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-bulk/preview', [
            'alcance' => 'todos',
            'vars'    => ['ANTHROPIC_API_KEY' => 'sk-nuevo-abcdef'],
        ]);

        $response->assertStatus(200);

        $nombres = collect($response->json('clientes'))->pluck('nombre');
        $this->assertNotContains('Distribuidora De Baja', $nombres);

        /* Y tampoco aparece en el listado que el conector lee por voz. */
        $listado = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/env-bulk/clients');
        $this->assertNotContains('Distribuidora De Baja', collect($listado->json('clientes'))->pluck('nombre'));
    }

    public function test_el_historial_deja_ver_que_se_le_cambio_a_un_cliente(): void
    {
        $admin  = $this->crear_admin('env-historial@test.local');
        $client = $this->crear_cliente('Distribuidora Doce', "ANTHROPIC_API_KEY=sk-viejo-123456\n");

        $this->aplicar(
            $admin,
            $this->previsualizar($admin, [$client->id], ['ANTHROPIC_API_KEY' => 'sk-nuevo-abcdef'])->json('token')
        )->assertStatus(200);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/env-bulk/history?client_id=' . $client->id);

        $response->assertStatus(200);

        $cambio = $response->json('cambios.0');
        $this->assertSame('ANTHROPIC_API_KEY', $cambio['key']);
        $this->assertSame('applied', $cambio['status']);
        $this->assertNotNull($cambio['backup_path']);

        /* El historial también está enmascarado: es auditoría, no un depósito de secretos. */
        $this->assertStringNotContainsString('sk-nuevo-abcdef', (string) $cambio['valor_nuevo']);
    }

    public function test_el_listado_de_clientes_no_abre_ninguna_conexion(): void
    {
        $admin = $this->crear_admin('env-listado@test.local');
        $this->crear_cliente('Distribuidora Trece', "ANTHROPIC_API_KEY=sk-viejo-123456\n");

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/env-bulk/clients');

        $response->assertStatus(200);

        $cliente = collect($response->json('clientes'))->firstWhere('nombre', 'Distribuidora Trece');
        $this->assertNotNull($cliente);
        $this->assertTrue($cliente['operable']);
        $this->assertSame('shared_hosting', $cliente['hosting_type']);
    }

    /**
     * Previsualiza un cambio sobre los clientes indicados.
     *
     * @param  Admin  $admin
     * @param  array<int, int>  $client_ids
     * @param  array<string, string>  $vars
     * @return \Illuminate\Testing\TestResponse
     */
    private function previsualizar(Admin $admin, array $client_ids, array $vars)
    {
        return $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-bulk/preview', [
            'alcance' => 'seleccion',
            'clients' => $client_ids,
            'vars'    => $vars,
        ]);
    }

    /**
     * Aplica un lote por su token.
     *
     * @param  Admin   $admin
     * @param  string  $token
     * @param  bool    $reanudar
     * @return \Illuminate\Testing\TestResponse
     */
    private function aplicar(Admin $admin, string $token, bool $reanudar = false)
    {
        return $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-bulk/apply', [
            'token'     => $token,
            'confirmar' => true,
            'reanudar'  => $reanudar,
        ]);
    }

    /**
     * Devuelve el renglón de una variable dentro del lote de ese token.
     *
     * Se busca siempre acotado al batch: filtrar sólo por env_key rompería en cuanto otra prueba
     * o un seeder deje renglones con la misma variable.
     *
     * @param  string  $token
     * @param  string  $env_key
     * @return EnvChangeItem
     */
    private function item_del_batch(string $token, string $env_key): EnvChangeItem
    {
        $batch = EnvChangeBatch::where('token', $token)->firstOrFail();

        return EnvChangeItem::where('env_change_batch_id', $batch->id)
            ->where('env_key', $env_key)
            ->firstOrFail();
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
