<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\EnvTemplate;
use App\Services\EnvSshService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fakes\EnvSshServiceFake;
use Tests\TestCase;

/**
 * El controlador de la plantilla .env con variables sensibles cifradas.
 *
 * Lo que se prueba: el listado no devuelve el secreto, el guardado desde la pantalla no pisa lo
 * guardado cuando llega vacío (la pantalla nunca recibió el valor), los diffs contra los clientes se
 * comparan con el valor real pero salen tapados, y "aplicar" escribe en el .env el valor descifrado.
 * Los valores son falsos y obviamente de prueba.
 */
class PlantillaEnvControladorSensibleTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Clave falsa de 28 caracteres.
     *
     * @var string
     */
    private const CLAVE = 'sk-ant-test-0123456789ABCDEF';

    /**
     * @var EnvSshServiceFake
     */
    private $ssh_fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ssh_fake = new EnvSshServiceFake();

        /* El controlador resuelve el servicio SSH por el container: nada abre una conexión real. */
        $this->app->instance(EnvSshService::class, $this->ssh_fake);
    }

    public function test_el_listado_no_devuelve_el_valor_de_las_sensibles(): void
    {
        $admin = $this->crear_admin('plantilla-index@test.local');
        $this->crear_plantilla('ANTHROPIC_API_KEY', self::CLAVE);
        $this->crear_plantilla('MAIL_HOST', 'smtp.ejemplo.test');

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/env-template');

        $response->assertStatus(200);

        /* Ni la clave ni su versión cifrada están en ninguna parte de la respuesta. */
        $this->assertStringNotContainsString(self::CLAVE, $response->getContent());
        $this->assertStringNotContainsString('enc:v1:', $response->getContent());

        $modelos = collect($response->json('models'))->keyBy('key');

        $this->assertArrayNotHasKey('value', $modelos['ANTHROPIC_API_KEY']);
        $this->assertTrue($modelos['ANTHROPIC_API_KEY']['value_sensitive']);
        $this->assertTrue($modelos['ANTHROPIC_API_KEY']['value_set']);
        $this->assertSame('CDEF', $modelos['ANTHROPIC_API_KEY']['value_tail']);

        /* La no sensible sigue como siempre, más el indicador. */
        $this->assertSame('smtp.ejemplo.test', $modelos['MAIL_HOST']['value']);
        $this->assertFalse($modelos['MAIL_HOST']['value_sensitive']);
    }

    public function test_guardar_una_sensible_con_valor_vacio_no_pisa_lo_guardado(): void
    {
        $admin     = $this->crear_admin('plantilla-vacio@test.local');
        $plantilla = $this->crear_plantilla('ANTHROPIC_API_KEY', self::CLAVE);

        /* Vacío, null y ausente: los tres significan "no tocar". */
        foreach (['', null, '__ausente__'] as $valor) {
            $item = $this->item($plantilla, $valor === '__ausente__' ? [] : ['value' => $valor]);

            $this->actingAs($admin, 'sanctum')
                ->postJson('/api/admin/env-template/bulk-update', ['items' => [$item]])
                ->assertStatus(200);

            $this->assertSame(self::CLAVE, EnvTemplate::find($plantilla->id)->value);
        }
    }

    public function test_guardar_una_sensible_con_un_valor_nuevo_la_reemplaza_cifrada(): void
    {
        $admin     = $this->crear_admin('plantilla-nuevo@test.local');
        $plantilla = $this->crear_plantilla('ANTHROPIC_API_KEY', self::CLAVE);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-template/bulk-update', [
            'items' => [$this->item($plantilla, ['value' => 'sk-ant-test-NUEVA-clave-7777'])],
        ]);

        $response->assertStatus(200);

        $this->assertSame('sk-ant-test-NUEVA-clave-7777', EnvTemplate::find($plantilla->id)->value);
        $crudo = DB::table('env_templates')->where('id', $plantilla->id)->value('value');
        $this->assertStringStartsWith('enc:v1:', $crudo);
        $this->assertStringNotContainsString('NUEVA-clave', $crudo);

        /* La respuesta del guardado tampoco devuelve el valor. */
        $this->assertStringNotContainsString('NUEVA-clave', $response->getContent());
    }

    public function test_clear_value_vacia_una_sensible_a_proposito(): void
    {
        $admin     = $this->crear_admin('plantilla-clear@test.local');
        $plantilla = $this->crear_plantilla('ANTHROPIC_API_KEY', self::CLAVE);

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-template/bulk-update', [
            'items' => [$this->item($plantilla, ['value' => null, 'clear_value' => true])],
        ])->assertStatus(200);

        $this->assertNull(DB::table('env_templates')->where('id', $plantilla->id)->value('value'));
        $this->assertNull(EnvTemplate::find($plantilla->id)->value);
    }

    public function test_un_valor_con_salto_de_linea_se_rechaza_y_no_cambia_nada(): void
    {
        $admin     = $this->crear_admin('plantilla-lineas@test.local');
        $sensible  = $this->crear_plantilla('ANTHROPIC_API_KEY', self::CLAVE);
        $no_sensib = $this->crear_plantilla('MAIL_HOST', 'smtp.ejemplo.test');

        foreach (["sk-ant-test-con\nsalto", "sk-ant-test-con\rretorno"] as $valor) {
            $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-template/bulk-update', [
                'items' => [$this->item($sensible, ['value' => $valor])],
            ])->assertStatus(422);
        }

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-template/bulk-update', [
            'items' => [$this->item($no_sensib, ['value' => "smtp.otro\ntest"])],
        ])->assertStatus(422);

        $this->assertSame(self::CLAVE, EnvTemplate::find($sensible->id)->value);
        $this->assertSame('smtp.ejemplo.test', EnvTemplate::find($no_sensib->id)->value);
    }

    public function test_el_alta_rechaza_saltos_de_linea_y_cifra_una_sensible_nueva(): void
    {
        $admin = $this->crear_admin('plantilla-alta@test.local');

        $base = ['group' => 'ia', 'is_common' => false, 'is_manual_on_create' => false, 'sort_order' => 1, 'scope' => 'empresa'];

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-template', $base + [
            'key' => 'ALTA_PRUEBA_TOKEN', 'value' => "abc\ndef",
        ])->assertStatus(422);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-template', $base + [
            'key' => 'ALTA_PRUEBA_TOKEN', 'value' => 'token-de-prueba-largo-0001',
        ]);
        $response->assertStatus(201);
        $this->assertStringNotContainsString('token-de-prueba-largo-0001', $response->getContent());

        $crudo = DB::table('env_templates')->where('key', 'ALTA_PRUEBA_TOKEN')->value('value');
        $this->assertStringStartsWith('enc:v1:', $crudo);
    }

    public function test_guardar_las_no_sensibles_sigue_igual_que_siempre(): void
    {
        $admin     = $this->crear_admin('plantilla-nosensible@test.local');
        $plantilla = $this->crear_plantilla('MAIL_HOST', 'smtp.ejemplo.test');

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-template/bulk-update', [
            'items' => [$this->item($plantilla, ['value' => 'smtp.nuevo.test', 'notes' => 'cambio de proveedor'])],
        ])->assertStatus(200);

        $recargada = EnvTemplate::find($plantilla->id);
        $this->assertSame('smtp.nuevo.test', $recargada->value);
        $this->assertSame('cambio de proveedor', $recargada->notes);
        $this->assertSame('smtp.nuevo.test', DB::table('env_templates')->where('id', $plantilla->id)->value('value'));

        /* Vaciar una no sensible la vacía, como antes (el "no tocar" es sólo de las sensibles). */
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-template/bulk-update', [
            'items' => [$this->item($plantilla, ['value' => null])],
        ])->assertStatus(200);
        $this->assertNull(EnvTemplate::find($plantilla->id)->value);
    }

    public function test_check_diff_detecta_la_diferencia_real_pero_la_muestra_tapada(): void
    {
        $admin  = $this->crear_admin('plantilla-diff@test.local');
        $client = $this->crear_cliente("ANTHROPIC_API_KEY=sk-ant-test-VIEJA-clave-0000\nMAIL_HOST=smtp.viejo.test\nDEEPSEEK_API_KEY=sk-ds-test-IGUAL-12345678\n");
        $api_id = $client->active_client_api->id;

        $this->crear_plantilla('ANTHROPIC_API_KEY', self::CLAVE, 'empresa', true);
        $this->crear_plantilla('MAIL_HOST', 'smtp.ejemplo.test', 'empresa', true);
        /* Misma clave en la plantilla y en el cliente: la comparación es contra el valor real, no hay diff. */
        $this->crear_plantilla('DEEPSEEK_API_KEY', 'sk-ds-test-IGUAL-12345678', 'empresa', true);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/env-template/check-diff/' . $client->id, ['client_api_id' => $api_id]);

        $response->assertStatus(200);

        /* Ni la clave de la plantilla ni la del cliente aparecen enteras en la respuesta. */
        $this->assertStringNotContainsString('0123456789ABCDEF', $response->getContent());
        $this->assertStringNotContainsString('VIEJA-clave', $response->getContent());

        $diffs = collect($response->json('diffs'))->keyBy('key');
        $this->assertCount(2, $diffs, 'DEEPSEEK_API_KEY coincide y no debe figurar');

        $this->assertTrue($diffs['ANTHROPIC_API_KEY']['sensitive']);
        $this->assertSame('sk-a********', $diffs['ANTHROPIC_API_KEY']['template_value']);
        $this->assertSame('sk-a********', $diffs['ANTHROPIC_API_KEY']['client_value']);

        $this->assertFalse($diffs['MAIL_HOST']['sensitive']);
        $this->assertSame('smtp.ejemplo.test', $diffs['MAIL_HOST']['template_value']);
        $this->assertSame('smtp.viejo.test', $diffs['MAIL_HOST']['client_value']);
    }

    public function test_check_diff_all_tambien_tapa_las_sensibles(): void
    {
        $admin  = $this->crear_admin('plantilla-diffall@test.local');
        $client = $this->crear_cliente("ANTHROPIC_API_KEY=sk-ant-test-VIEJA-clave-0000\n");

        $this->crear_plantilla('ANTHROPIC_API_KEY', self::CLAVE, 'empresa', true);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-template/check-diff-all/' . $client->id);

        $response->assertStatus(200);
        $this->assertStringNotContainsString('0123456789ABCDEF', $response->getContent());
        $this->assertStringNotContainsString('VIEJA-clave', $response->getContent());

        $diff = $response->json('results.0.diffs.0');
        $this->assertSame('ANTHROPIC_API_KEY', $diff['key']);
        $this->assertTrue($diff['sensitive']);
        $this->assertSame('sk-a********', $diff['template_value']);
    }

    public function test_apply_diff_escribe_en_el_env_el_valor_descifrado(): void
    {
        $admin  = $this->crear_admin('plantilla-apply@test.local');
        $client = $this->crear_cliente("ANTHROPIC_API_KEY=sk-ant-test-VIEJA-clave-0000\n");
        $api_id = $client->active_client_api->id;

        $plantilla = $this->crear_plantilla('ANTHROPIC_API_KEY', self::CLAVE, 'empresa', true);

        /* En la base está cifrada... */
        $this->assertStringStartsWith('enc:v1:', DB::table('env_templates')->where('id', $plantilla->id)->value('value'));

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-template/apply-diff/' . $client->id, [
            'client_api_id' => $api_id,
            'keys'          => ['ANTHROPIC_API_KEY'],
        ]);

        $response->assertStatus(200);
        $this->assertSame(['ANTHROPIC_API_KEY'], $response->json('updated'));

        /* ...y al .env del cliente llega la clave real, no el texto cifrado. */
        $this->assertSame(self::CLAVE, $this->ssh_fake->escrituras[$api_id]['ANTHROPIC_API_KEY']);
        $this->assertStringContainsString(self::CLAVE, $this->ssh_fake->envs[$api_id]);
        $this->assertStringNotContainsString('enc:v1:', $this->ssh_fake->envs[$api_id]);
    }

    public function test_apply_diff_all_escribe_el_valor_descifrado(): void
    {
        $admin  = $this->crear_admin('plantilla-applyall@test.local');
        $client = $this->crear_cliente("ANTHROPIC_API_KEY=sk-ant-test-VIEJA-clave-0000\n");
        $api_id = $client->active_client_api->id;

        $this->crear_plantilla('ANTHROPIC_API_KEY', self::CLAVE, 'empresa', true);

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-template/apply-diff-all/' . $client->id, [
            'keys' => ['ANTHROPIC_API_KEY'],
        ])->assertStatus(200);

        $this->assertSame(self::CLAVE, $this->ssh_fake->escrituras[$api_id]['ANTHROPIC_API_KEY']);
    }

    /**
     * Arma un item de bulk-update a partir de una fila, con los campos obligatorios y los de $extra.
     *
     * @param  EnvTemplate  $plantilla
     * @param  array        $extra
     * @return array
     */
    private function item(EnvTemplate $plantilla, array $extra): array
    {
        return array_merge([
            'id'                  => $plantilla->id,
            'is_common'           => false,
            'is_manual_on_create' => false,
            'notes'               => null,
            'group'               => 'ia',
            'sort_order'          => 1,
        ], $extra);
    }

    /**
     * Crea una variable de la plantilla por el modelo (que cifra las sensibles).
     *
     * @param  string  $key
     * @param  string  $value
     * @param  string  $scope
     * @param  bool    $is_common
     * @return EnvTemplate
     */
    private function crear_plantilla(string $key, string $value, string $scope = 'empresa', bool $is_common = false): EnvTemplate
    {
        return EnvTemplate::create([
            'key'                 => $key,
            'value'               => $value,
            'group'               => 'ia',
            'scope'               => $scope,
            'is_common'           => $is_common,
            'is_manual_on_create' => false,
            'sort_order'          => 1,
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
        $client->name            = 'Cliente plantilla ' . $sufijo;
        $client->slug            = 'plantilla-' . $sufijo;
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
