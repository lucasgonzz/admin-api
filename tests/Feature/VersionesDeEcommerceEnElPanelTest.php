<?php

namespace Tests\Feature;

use App\Jobs\RunEcommerceInstallationJob;
use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientEcommerce;
use App\Models\ClientEcommerceInstallation;
use App\Models\ClientSshCredential;
use App\Models\EcommerceVersion;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El panel (`api/admin`, sanctum) y las versiones de ecommerce (misión cruzada `versiones-tienda`,
 * 1/10/2026): el CRUD del módulo "Versiones de ecommerce" y la versión en los botones de instalar y
 * actualizar.
 *
 * Mismas reglas que por `claude/*` (salen del mismo servicio): publicar verifica los dos assets del
 * release y el código no se edita. Lo propio del panel: borrar sólo una versión que nadie usa, que
 * el modal genérico manda el borrador ENTERO (con `version` adentro), y que los botones de arranque
 * sin versión se comporten como antes.
 */
class VersionesDeEcommerceEnElPanelTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.github.token'                   => 'ghp_token_de_prueba',
            'services.github.releases_owner'          => 'lucasgonzz',
            'services.anthropic.verify_ssl'           => true,
            'services.anthropic.ca_bundle'            => null,
            'services.deploy_tienda.release_repo_spa' => 'tienda-spa',
            'services.deploy_tienda.release_repo_api' => 'tienda-api',
        ]);

        EcommerceVersion::query()->delete();

        $admin           = new Admin();
        $admin->name     = 'Admin de versiones de ecommerce';
        $admin->email    = 'versiones-ecommerce-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);
    }

    /**
     * Simula el release v{version} de los dos repos, con o sin el asset de la API.
     *
     * @param string $version
     * @param bool   $con_api
     *
     * @return void
     */
    private function github(string $version, bool $con_api = true): void
    {
        $release = function ($asset) {
            return ['id' => 1, 'assets' => $asset === null ? [] : [['id' => 1, 'name' => $asset, 'size' => 10, 'url' => 'https://api.github.com/x']]];
        };

        Http::fake([
            'api.github.com/repos/lucasgonzz/tienda-spa/releases/tags/v' . $version => Http::response($release('tienda-spa-v' . $version . '-dist.zip'), 200),
            'api.github.com/repos/lucasgonzz/tienda-api/releases/tags/v' . $version => Http::response($release($con_api ? 'tienda-api-v' . $version . '.zip' : null), 200),
            '*' => Http::response([], 404),
        ]);
    }

    /**
     * @param string $version
     * @param string $status
     *
     * @return EcommerceVersion
     */
    private function version(string $version, string $status = 'published'): EcommerceVersion
    {
        return EcommerceVersion::create([
            'version'      => $version,
            'status'       => $status,
            'published_at' => $status === 'published' ? now() : null,
        ]);
    }

    /**
     * Cliente con tienda configurada y credenciales SSH (las precondiciones del panel para update).
     *
     * @return array{cliente: Client, tienda: ClientEcommerce}
     */
    private function escenario(): array
    {
        ClientSshCredential::query()->delete();
        foreach (['vps', 'shared_hosting'] as $tipo) {
            $credencial           = new ClientSshCredential();
            $credencial->type     = $tipo;
            $credencial->host     = $tipo . '.ejemplo.test';
            $credencial->port     = 22;
            $credencial->username = 'deploy';
            $credencial->password = 'secreta';
            $credencial->save();
        }

        $client                  = new Client();
        $client->name            = 'Cliente Panel ' . Str::random(5);
        $client->company_name    = 'Comercio Panel';
        $client->slug            = 'cliente-panel-' . Str::random(8);
        $client->api_url         = 'https://ejemplo.test';
        $client->api_key         = 'clave-api';
        $client->inbound_api_key = 'clave-inbound';
        $client->is_active       = true;
        $client->save();

        $dominio           = 'panel-' . Str::random(6) . '.com.ar';
        $tienda            = new ClientEcommerce();
        $tienda->client_id = $client->id;
        $tienda->domain    = $dominio;
        $tienda->spa_url   = 'https://' . $dominio;
        $tienda->api_url   = 'https://api.' . $dominio;
        $tienda->status    = 'active';
        $tienda->save();

        return ['cliente' => $client, 'tienda' => $tienda];
    }

    /* ------------------------------------------------------------------------------------------ */

    /** El meta del modelo existe: es lo que arma la tabla y el modal genérico del SPA. */
    public function test_el_meta_de_ecommerce_version_existe(): void
    {
        $respuesta = $this->getJson('/api/admin/meta/ecommerce_version');

        $respuesta->assertStatus(200);
        $claves = array_column($respuesta->json('properties'), 'key');
        foreach (['version', 'title', 'status', 'description'] as $clave) {
            $this->assertContains($clave, $claves);
        }
    }

    /** El listado ordena por código semántico, filtra por estado y cuenta tiendas y corridas. */
    public function test_el_listado_ordena_semanticamente_y_cuenta_el_uso(): void
    {
        $this->version('1.9.0');
        $usada = $this->version('1.10.0');
        $this->version('2.0.0', 'draft');
        $e = $this->escenario();
        $e['tienda']->update(['ecommerce_version_id' => $usada->id]);

        $todas = $this->getJson('/api/admin/ecommerce-versions');
        $todas->assertStatus(200);
        $this->assertSame(['2.0.0', '1.10.0', '1.9.0'], array_column($todas->json('models'), 'version'));
        $todas->assertJsonPath('ultima_publicada.version', '1.10.0');
        $todas->assertJsonPath('models.1.client_ecommerces_count', 1);

        $publicadas = $this->getJson('/api/admin/ecommerce-versions?status=published');
        $this->assertSame(['1.10.0', '1.9.0'], array_column($publicadas->json('models'), 'version'));
    }

    /** Alta publicada con los dos assets: 201 con el modelo. */
    public function test_el_alta_publicada_verifica_los_assets(): void
    {
        $this->github('1.0.0');

        $this->postJson('/api/admin/ecommerce-versions', [
            'version' => '1.0.0',
            'title'   => 'Primera',
            'status'  => 'published',
        ])->assertStatus(201)->assertJsonPath('model.version', '1.0.0')->assertJsonPath('model.status', 'published');

        $this->assertNotNull(EcommerceVersion::where('version', '1.0.0')->value('published_at'));
    }

    /** 🔴 Si falta un asset, 422 con el asset en el mensaje y sin fila. */
    public function test_el_alta_sin_un_asset_no_crea_la_fila(): void
    {
        $this->github('1.0.0', false);

        $respuesta = $this->postJson('/api/admin/ecommerce-versions', ['version' => '1.0.0', 'status' => 'published']);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('tienda-api-v1.0.0.zip', (string) $respuesta->json('error'));
        $this->assertSame(0, EcommerceVersion::query()->count());
    }

    /** Duplicada e inválida: 422 en español. */
    public function test_el_alta_duplicada_o_invalida_es_422(): void
    {
        Http::fake();
        $this->version('1.0.0');

        $duplicada = $this->postJson('/api/admin/ecommerce-versions', ['version' => '1.0.0', 'status' => 'draft']);
        $duplicada->assertStatus(422);
        $this->assertSame('Ya existe una versión de ecommerce con ese código.', $duplicada->json('errors.version.0'));

        $invalida = $this->postJson('/api/admin/ecommerce-versions', ['version' => '1.0', 'status' => 'draft']);
        $invalida->assertStatus(422);
        $this->assertStringContainsString('al menos 3 componentes', $invalida->json('errors.version.0'));

        Http::assertNothingSent();
    }

    /**
     * El modal genérico manda el borrador entero: el mismo código se ignora, uno distinto es 422; y
     * publicar un borrador verifica los assets.
     */
    public function test_la_edicion_desde_el_modal_generico(): void
    {
        $this->github('1.1.0', false);
        $borrador = $this->version('1.1.0', 'draft');

        $this->putJson('/api/admin/ecommerce-versions/' . $borrador->id, [
            'id'           => $borrador->id,
            'version'      => '1.1.0',
            'title'        => 'Con título',
            'status'       => 'draft',
            'published_at' => null,
        ])->assertStatus(200)->assertJsonPath('model.title', 'Con título');

        $this->putJson('/api/admin/ecommerce-versions/' . $borrador->id, ['version' => '9.9.9'])->assertStatus(422);
        $this->assertSame('1.1.0', $borrador->fresh()->version);

        $this->putJson('/api/admin/ecommerce-versions/' . $borrador->id, ['version' => '1.1.0', 'status' => 'published'])
            ->assertStatus(422);
        $this->assertSame('draft', $borrador->fresh()->status);
    }

    /** Borrar: una versión en uso no se borra (422); una sin uso, sí. */
    public function test_borrar_solo_una_version_que_nadie_usa(): void
    {
        $usada = $this->version('1.0.0');
        $libre = $this->version('1.1.0');
        $e     = $this->escenario();
        ClientEcommerceInstallation::create([
            'client_ecommerce_id'  => $e['tienda']->id,
            'mode'                 => 'update',
            'status'               => 'completada',
            'ecommerce_version_id' => $usada->id,
        ]);

        $respuesta = $this->deleteJson('/api/admin/ecommerce-versions/' . $usada->id);
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('está en uso', (string) $respuesta->json('error'));
        $this->assertNotNull(EcommerceVersion::find($usada->id));

        $this->deleteJson('/api/admin/ecommerce-versions/' . $libre->id)->assertStatus(200)->assertJsonPath('deleted', true);
        $this->assertNull(EcommerceVersion::find($libre->id));
    }

    /* ------------------------------------------------------------------------------------------
     | Los botones de arranque
     |----------------------------------------------------------------------------------------- */

    /** start-update con una versión publicada la guarda en la corrida y la devuelve en el modelo. */
    public function test_start_update_con_version_la_guarda_en_la_corrida(): void
    {
        Queue::fake();
        $pedida = $this->version('1.0.0');
        $this->version('1.1.0');
        $e = $this->escenario();

        $respuesta = $this->postJson('/api/admin/ecommerce-installations/start-update', [
            'client_id'            => $e['cliente']->id,
            'ecommerce_version_id' => $pedida->id,
        ]);

        $respuesta->assertStatus(201);
        $respuesta->assertJsonPath('model.ecommerce_version.version', '1.0.0');
        $this->assertSame((int) $pedida->id, (int) ClientEcommerceInstallation::where('client_ecommerce_id', $e['tienda']->id)->value('ecommerce_version_id'));
        Queue::assertPushed(RunEcommerceInstallationJob::class);
    }

    /** Sin versión, como antes: 201, y la corrida guarda la última publicada. */
    public function test_start_update_sin_version_usa_la_ultima_publicada(): void
    {
        Queue::fake();
        $this->version('1.0.0');
        $ultima = $this->version('1.1.0');
        $e      = $this->escenario();

        $this->postJson('/api/admin/ecommerce-installations/start-update', ['client_id' => $e['cliente']->id])
            ->assertStatus(201);

        $this->assertSame((int) $ultima->id, (int) ClientEcommerceInstallation::where('client_ecommerce_id', $e['tienda']->id)->value('ecommerce_version_id'));
    }

    /** Una versión que no está publicada (o no existe): 422 y ninguna corrida. */
    public function test_start_update_con_una_version_no_publicada_es_422(): void
    {
        Queue::fake();
        $borrador = $this->version('2.0.0', 'draft');
        $e        = $this->escenario();

        foreach ([$borrador->id, 999999, 'abc'] as $pedida) {
            $this->postJson('/api/admin/ecommerce-installations/start-update', [
                'client_id'            => $e['cliente']->id,
                'ecommerce_version_id' => $pedida,
            ])->assertStatus(422);
        }

        $this->assertSame(0, ClientEcommerceInstallation::where('client_ecommerce_id', $e['tienda']->id)->count());
        Queue::assertNothingPushed();
    }

    /** El listado de corridas trae la versión de la corrida y la instalada en la tienda. */
    public function test_el_listado_de_corridas_trae_las_dos_versiones(): void
    {
        $version = $this->version('1.0.0');
        $e       = $this->escenario();
        $e['tienda']->update(['ecommerce_version_id' => $version->id]);
        $corrida = ClientEcommerceInstallation::create([
            'client_ecommerce_id'  => $e['tienda']->id,
            'mode'                 => 'update',
            'status'               => 'completada',
            'ecommerce_version_id' => $version->id,
        ]);

        $models = collect($this->getJson('/api/admin/ecommerce-installations?owner=cliente')->json('models'));
        $fila   = $models->firstWhere('id', $corrida->id);

        $this->assertNotNull($fila);
        $this->assertSame('1.0.0', $fila['ecommerce_version']['version']);
        $this->assertSame('1.0.0', $fila['client_ecommerce']['ecommerce_version']['version']);

        $this->getJson('/api/admin/client-ecommerce/' . $e['tienda']->id . '/installations')
            ->assertStatus(200)
            ->assertJsonPath('model.ecommerce_version.version', '1.0.0')
            ->assertJsonPath('model.installations.0.ecommerce_version.version', '1.0.0');
    }
}
