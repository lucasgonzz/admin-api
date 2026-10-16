<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\EnvChangeBatch;
use App\Models\EnvChangeItem;
use App\Models\EnvTemplate;
use App\Services\EnvSshService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Fakes\EnvSshServiceFake;
use Tests\TestCase;

/**
 * env-bulk/preview con `plantilla`: el valor sale de la plantilla .env del admin, no del body.
 *
 * Es el camino que permite aplicar a la flota una clave guardada cifrada SIN que quien opera el
 * endpoint (Claude) la vea ni la dicte. Lo que se prueba: que el valor se resuelve en el servidor,
 * que la clave no aparece en la respuesta, en el historial ni en las columnas legibles, que apply
 * escribe el valor real, y los frenos (fila inexistente o vacía, repeticiones, y la prueba contra
 * Anthropic antes de crear el lote). Anthropic va siempre simulado; las claves son falsas.
 */
class EnvBulkDesdePlantillaTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Clave falsa de prueba.
     *
     * @var string
     */
    private const CLAVE = 'sk-ant-test-0123456789ABCDEF';

    /**
     * @var EnvSshServiceFake
     */
    private $ssh_fake;

    /**
     * Qué contesta el Anthropic simulado (Http::fake se registra una vez; si se llama dos veces gana la primera).
     *
     * @var callable
     */
    private $anthropic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ssh_fake = new EnvSshServiceFake();
        $this->app->instance(EnvSshService::class, $this->ssh_fake);

        $this->anthropic = function () {
            return Http::response(['data' => []], 200);
        };

        Http::fake(function () {
            return call_user_func($this->anthropic);
        });
    }

    public function test_resuelve_el_valor_en_el_servidor_y_la_clave_no_aparece_en_ninguna_respuesta(): void
    {
        $admin  = $this->crear_admin('bulk-plantilla-resuelve@test.local');
        $client = $this->crear_cliente('ANTHROPIC_API_KEY=sk-ant-test-VIEJA-clave-0000' . "\n");
        $this->crear_plantilla('ANTHROPIC_API_KEY', self::CLAVE);

        $preview = $this->previsualizar($admin, [$client->id], ['plantilla' => ['keys' => ['ANTHROPIC_API_KEY']]]);

        $preview->assertStatus(200);

        /* La respuesta del preview no trae la clave (sólo su versión enmascarada). */
        $this->assertStringNotContainsString('0123456789ABCDEF', $preview->getContent());
        $cambio = $preview->json('clientes.0.cambios.0');
        $this->assertSame('ANTHROPIC_API_KEY', $cambio['key']);
        $this->assertTrue($cambio['cambia']);
        $this->assertTrue($cambio['sensible']);
        $this->assertSame('sk-a********', $cambio['valor_nuevo']);

        /* Tampoco las columnas legibles del lote. */
        $item = $this->item_del_batch($preview->json('token'), 'ANTHROPIC_API_KEY');
        $this->assertSame('sk-a********', $item->new_value_masked);
        $this->assertSame(hash('sha256', self::CLAVE), $item->new_value_sha256);
        $crudo = DB::table('env_change_items')->where('id', $item->id)->first();
        $this->assertStringNotContainsString(self::CLAVE, (string) $crudo->new_value_encrypted);

        /* Aplicar escribe el valor REAL en el .env del cliente (no el texto cifrado de la plantilla). */
        $apply = $this->aplicar($admin, $preview->json('token'));
        $apply->assertStatus(200);
        $this->assertSame(1, $apply->json('aplicados'));
        $this->assertStringNotContainsString('0123456789ABCDEF', $apply->getContent());

        $api_id = $client->active_client_api->id;
        $this->assertSame(self::CLAVE, $this->ssh_fake->escrituras[$api_id]['ANTHROPIC_API_KEY']);
        $this->assertStringNotContainsString('enc:v1:', $this->ssh_fake->envs[$api_id]);

        /* El historial queda enmascarado. */
        $historial = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/env-bulk/history?client_id=' . $client->id);
        $historial->assertStatus(200);
        $this->assertStringNotContainsString('0123456789ABCDEF', $historial->getContent());
    }

    public function test_una_clave_que_no_existe_en_la_plantilla_da_422_con_su_nombre(): void
    {
        $admin  = $this->crear_admin('bulk-plantilla-noexiste@test.local');
        $client = $this->crear_cliente("APP_ENV=production\n");

        $lotes_antes = EnvChangeBatch::count();

        $response = $this->previsualizar($admin, [$client->id], ['plantilla' => ['keys' => ['CLAVE_QUE_NO_EXISTE']]]);

        $response->assertStatus(422);
        $this->assertStringContainsString('CLAVE_QUE_NO_EXISTE', $response->json('message'));
        $this->assertSame($lotes_antes, EnvChangeBatch::count());
    }

    public function test_una_clave_sin_valor_cargado_da_422_con_su_nombre(): void
    {
        $admin  = $this->crear_admin('bulk-plantilla-sinvalor@test.local');
        $client = $this->crear_cliente("APP_ENV=production\n");

        EnvTemplate::create([
            'key' => 'ANTHROPIC_API_KEY', 'value' => null, 'group' => 'ia', 'scope' => 'empresa',
            'is_common' => false, 'is_manual_on_create' => false, 'sort_order' => 1,
        ]);

        $response = $this->previsualizar($admin, [$client->id], ['plantilla' => ['keys' => ['ANTHROPIC_API_KEY']]]);

        $response->assertStatus(422);
        $this->assertStringContainsString('ANTHROPIC_API_KEY', $response->json('message'));
        Http::assertNothingSent();
    }

    public function test_la_plantilla_de_tienda_no_cuenta_porque_env_bulk_solo_toca_empresa(): void
    {
        $admin  = $this->crear_admin('bulk-plantilla-tienda@test.local');
        $client = $this->crear_cliente("APP_ENV=production\n");
        $this->crear_plantilla('ANTHROPIC_API_KEY', self::CLAVE, 'tienda');

        $this->previsualizar($admin, [$client->id], ['plantilla' => ['keys' => ['ANTHROPIC_API_KEY']]])->assertStatus(422);
    }

    public function test_vars_solo_se_comporta_como_antes_y_no_prueba_nada(): void
    {
        $admin  = $this->crear_admin('bulk-plantilla-varssolo@test.local');
        $client = $this->crear_cliente("ANTHROPIC_API_KEY=sk-viejo-123456\n");

        $preview = $this->previsualizar($admin, [$client->id], ['vars' => ['ANTHROPIC_API_KEY' => 'sk-nuevo-abcdef']]);

        $preview->assertStatus(200);
        $this->assertSame('ok', $preview->json('clientes.0.status'));
        /* La respuesta suma `verificaciones`, vacío. */
        $this->assertSame([], $preview->json('verificaciones'));
        Http::assertNothingSent();

        $this->aplicar($admin, $preview->json('token'))->assertStatus(200);
        $this->assertSame('sk-nuevo-abcdef', $this->ssh_fake->escrituras[$client->active_client_api->id]['ANTHROPIC_API_KEY']);
    }

    public function test_sin_vars_ni_plantilla_da_422(): void
    {
        $admin  = $this->crear_admin('bulk-plantilla-nada@test.local');
        $client = $this->crear_cliente("APP_ENV=production\n");

        $this->previsualizar($admin, [$client->id], [])->assertStatus(422);
    }

    public function test_se_pueden_combinar_vars_y_plantilla_si_no_repiten_clave(): void
    {
        $admin  = $this->crear_admin('bulk-plantilla-combina@test.local');
        $client = $this->crear_cliente("ANTHROPIC_API_KEY=sk-ant-test-VIEJA-clave-0000\nDB_HOST=127.0.0.1\n");
        $api_id = $client->active_client_api->id;
        $this->crear_plantilla('ANTHROPIC_API_KEY', self::CLAVE);

        $preview = $this->previsualizar($admin, [$client->id], [
            'vars'      => ['DB_HOST' => '10.0.0.9'],
            'plantilla' => ['keys' => ['ANTHROPIC_API_KEY']],
        ]);

        $preview->assertStatus(200);
        $this->assertCount(2, $preview->json('clientes.0.cambios'));

        $this->aplicar($admin, $preview->json('token'))->assertStatus(200);
        $this->assertSame('10.0.0.9', $this->ssh_fake->escrituras[$api_id]['DB_HOST']);
        $this->assertSame(self::CLAVE, $this->ssh_fake->escrituras[$api_id]['ANTHROPIC_API_KEY']);
    }

    public function test_una_clave_repetida_entre_vars_y_plantilla_da_422(): void
    {
        $admin  = $this->crear_admin('bulk-plantilla-repetida@test.local');
        $client = $this->crear_cliente("ANTHROPIC_API_KEY=sk-ant-test-VIEJA-clave-0000\n");
        $this->crear_plantilla('ANTHROPIC_API_KEY', self::CLAVE);

        $this->previsualizar($admin, [$client->id], [
            'vars'      => ['ANTHROPIC_API_KEY' => 'sk-otra-cosa-123456'],
            'plantilla' => ['keys' => ['ANTHROPIC_API_KEY']],
        ])->assertStatus(422);

        $this->previsualizar($admin, [$client->id], [
            'plantilla' => ['keys' => ['ANTHROPIC_API_KEY', 'ANTHROPIC_API_KEY']],
        ])->assertStatus(422);

        $this->assertSame([], $this->ssh_fake->escrituras);
    }

    public function test_un_nombre_invalido_en_plantilla_se_rechaza(): void
    {
        $admin  = $this->crear_admin('bulk-plantilla-nombre@test.local');
        $client = $this->crear_cliente("APP_ENV=production\n");

        $this->previsualizar($admin, [$client->id], ['plantilla' => ['keys' => ['FOO; rm -rf /']]])->assertStatus(422);
    }

    public function test_una_clave_valida_para_anthropic_sigue_y_informa_la_verificacion(): void
    {
        $admin  = $this->crear_admin('bulk-plantilla-valida@test.local');
        $client = $this->crear_cliente("ANTHROPIC_API_KEY=sk-ant-test-VIEJA-clave-0000\n");
        $this->crear_plantilla('ANTHROPIC_API_KEY', self::CLAVE);

        $preview = $this->previsualizar($admin, [$client->id], ['plantilla' => ['keys' => ['ANTHROPIC_API_KEY']]]);

        $preview->assertStatus(200);
        $this->assertSame('valida', $preview->json('verificaciones.ANTHROPIC_API_KEY.estado'));
        Http::assertSentCount(1);
    }

    public function test_una_clave_rechazada_por_anthropic_da_422_y_no_crea_ningun_lote(): void
    {
        $admin  = $this->crear_admin('bulk-plantilla-rechazada@test.local');
        $client = $this->crear_cliente("ANTHROPIC_API_KEY=sk-ant-test-VIEJA-clave-0000\n");
        $this->crear_plantilla('ANTHROPIC_API_KEY', self::CLAVE);

        $this->anthropic = function () {
            return Http::response(['error' => ['message' => 'invalid x-api-key']], 401);
        };

        $lotes_antes = EnvChangeBatch::count();
        $items_antes = EnvChangeItem::count();

        $response = $this->previsualizar($admin, [$client->id], ['plantilla' => ['keys' => ['ANTHROPIC_API_KEY']]]);

        $response->assertStatus(422);
        $this->assertStringContainsString('ANTHROPIC_API_KEY', $response->json('message'));
        $this->assertSame('rechazada', $response->json('verificaciones.ANTHROPIC_API_KEY.estado'));
        $this->assertStringNotContainsString('0123456789ABCDEF', $response->getContent());

        /* No se creó nada y no se tocó ningún .env. */
        $this->assertSame($lotes_antes, EnvChangeBatch::count());
        $this->assertSame($items_antes, EnvChangeItem::count());
        $this->assertSame([], $this->ssh_fake->escrituras);
    }

    public function test_si_la_prueba_no_es_concluyente_el_lote_se_crea_y_se_informa(): void
    {
        $admin  = $this->crear_admin('bulk-plantilla-noconcluyente@test.local');
        $client = $this->crear_cliente("ANTHROPIC_API_KEY=sk-ant-test-VIEJA-clave-0000\n");
        $this->crear_plantilla('ANTHROPIC_API_KEY', self::CLAVE);

        /* Red caída. */
        $this->anthropic = function () {
            throw new ConnectionException('no hay red');
        };
        $preview = $this->previsualizar($admin, [$client->id], ['plantilla' => ['keys' => ['ANTHROPIC_API_KEY']]]);
        $preview->assertStatus(200);
        $this->assertSame('no_concluyente', $preview->json('verificaciones.ANTHROPIC_API_KEY.estado'));
        $this->assertNotEmpty($preview->json('token'));

        /* Anthropic caído (503): igual. */
        $this->anthropic = function () {
            return Http::response('', 503);
        };
        $preview = $this->previsualizar($admin, [$client->id], ['plantilla' => ['keys' => ['ANTHROPIC_API_KEY']]]);
        $preview->assertStatus(200);
        $this->assertSame('no_concluyente', $preview->json('verificaciones.ANTHROPIC_API_KEY.estado'));
    }

    public function test_omitir_verificacion_saltea_la_prueba(): void
    {
        $admin  = $this->crear_admin('bulk-plantilla-omitir@test.local');
        $client = $this->crear_cliente("ANTHROPIC_API_KEY=sk-ant-test-VIEJA-clave-0000\n");
        $this->crear_plantilla('ANTHROPIC_API_KEY', self::CLAVE);

        /* Aunque Anthropic la rechazara, sin verificación el lote se crea. */
        $this->anthropic = function () {
            return Http::response([], 401);
        };

        $preview = $this->previsualizar($admin, [$client->id], [
            'plantilla'           => ['keys' => ['ANTHROPIC_API_KEY']],
            'omitir_verificacion' => true,
        ]);

        $preview->assertStatus(200);
        $this->assertSame([], $preview->json('verificaciones'));
        Http::assertNothingSent();
    }

    public function test_una_variable_sin_prueba_disponible_no_sale_a_la_red(): void
    {
        $admin  = $this->crear_admin('bulk-plantilla-sinprueba@test.local');
        $client = $this->crear_cliente("DEEPSEEK_API_KEY=sk-ds-test-VIEJA-0000000000\n");
        $this->crear_plantilla('DEEPSEEK_API_KEY', 'sk-ds-test-0123456789ABCDEF');

        $preview = $this->previsualizar($admin, [$client->id], ['plantilla' => ['keys' => ['DEEPSEEK_API_KEY']]]);

        $preview->assertStatus(200);
        Http::assertNothingSent();
        $this->assertStringNotContainsString('0123456789ABCDEF', $preview->getContent());

        $this->aplicar($admin, $preview->json('token'))->assertStatus(200);
        $this->assertSame('sk-ds-test-0123456789ABCDEF', $this->ssh_fake->escrituras[$client->active_client_api->id]['DEEPSEEK_API_KEY']);
    }

    /**
     * Previsualiza con alcance 'seleccion' y los clientes indicados, más el resto del payload.
     *
     * @param  Admin  $admin
     * @param  array  $client_ids
     * @param  array  $resto  vars / plantilla / omitir_verificacion.
     * @return \Illuminate\Testing\TestResponse
     */
    private function previsualizar(Admin $admin, array $client_ids, array $resto)
    {
        return $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-bulk/preview', array_merge([
            'alcance' => 'seleccion',
            'clients' => $client_ids,
        ], $resto));
    }

    /**
     * Aplica un lote por su token.
     *
     * @param  Admin   $admin
     * @param  string  $token
     * @return \Illuminate\Testing\TestResponse
     */
    private function aplicar(Admin $admin, string $token)
    {
        return $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-bulk/apply', [
            'token'     => $token,
            'confirmar' => true,
        ]);
    }

    /**
     * Renglón de una variable dentro del lote de ese token.
     *
     * @param  string  $token
     * @param  string  $env_key
     * @return EnvChangeItem
     */
    private function item_del_batch(string $token, string $env_key): EnvChangeItem
    {
        $batch = EnvChangeBatch::where('token', $token)->firstOrFail();

        return EnvChangeItem::where('env_change_batch_id', $batch->id)->where('env_key', $env_key)->firstOrFail();
    }

    /**
     * Crea una variable de la plantilla por el modelo.
     *
     * @param  string  $key
     * @param  string  $value
     * @param  string  $scope
     * @return EnvTemplate
     */
    private function crear_plantilla(string $key, string $value, string $scope = 'empresa'): EnvTemplate
    {
        return EnvTemplate::create([
            'key' => $key, 'value' => $value, 'group' => 'ia', 'scope' => $scope,
            'is_common' => false, 'is_manual_on_create' => false, 'sort_order' => 1,
        ]);
    }

    /**
     * Crea un cliente con su API activa y le carga un .env en el servicio SSH falso.
     *
     * @param  string  $contenido_env
     * @return Client
     */
    private function crear_cliente(string $contenido_env): Client
    {
        $sufijo = Str::random(8);

        $client                  = new Client();
        $client->name            = 'Cliente bulk plantilla ' . $sufijo;
        $client->slug            = 'bulk-plantilla-' . $sufijo;
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
