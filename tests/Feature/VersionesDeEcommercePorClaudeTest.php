<?php

namespace Tests\Feature;

use App\Jobs\RunEcommerceInstallationJob;
use App\Models\Client;
use App\Models\ClientEcommerce;
use App\Models\ClientEcommerceInstallation;
use App\Models\ClientSshCredential;
use App\Models\EcommerceVersion;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Versiones de ecommerce por `claude/*` (misión cruzada `versiones-tienda`, 1/10/2026): el alta, la
 * edición, el listado, y lo que SUMAN los endpoints de tiendas y actualizaciones.
 *
 * Ni una llamada real a GitHub: `Http::fake()` simula los releases de `tienda-spa` y `tienda-api`.
 * Lo que se protege, en orden de importancia:
 *  1. 🔴 Que no se pueda publicar una versión cuyo release no tenga los dos assets: 422 nombrando
 *     repo, tag y asset, y SIN fila creada. Es el error que, si pasa, aparece recién adentro del job
 *     de la primera tienda que se actualiza.
 *  2. Que un error de GitHub que no es "no está" (token, 500) tampoco publique (502).
 *  3. Que `POST claude/ecommerce/updates` (y el lote) guarden en la corrida la versión pedida o la
 *     última publicada, rechacen una que no está publicada y no cambien nada de lo de antes.
 *  4. Que `GET claude/ecommerce/stores` e `installations` devuelvan la versión.
 */
class VersionesDeEcommercePorClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-claude-versiones-ecommerce';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.claude_task_ingest.key'         => self::CLAVE,
            'services.github.token'                   => 'ghp_token_de_prueba',
            'services.github.releases_owner'          => 'lucasgonzz',
            'services.anthropic.verify_ssl'           => true,
            'services.anthropic.ca_bundle'            => null,
            'services.deploy_tienda.release_repo_spa' => 'tienda-spa',
            'services.deploy_tienda.release_repo_api' => 'tienda-api',
        ]);

        // Cada test arma sus propias versiones: la base del slot no aporta ninguna.
        EcommerceVersion::query()->delete();
    }

    /* ------------------------------------------------------------------------------------------
     | Armado
     |----------------------------------------------------------------------------------------- */

    /**
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
     * Cuerpo de la respuesta con los acentos sin escapar (ver ActualizacionDelEcommercePorClaudeTest).
     *
     * @param \Illuminate\Testing\TestResponse $respuesta
     *
     * @return string
     */
    private function cuerpo($respuesta): string
    {
        return (string) json_encode($respuesta->json(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Simula los releases del tag v{version} de los dos repos.
     *
     * @param string $version
     * @param bool   $con_spa  Si el release de tienda-spa trae su asset.
     * @param bool   $con_api  Si el release de tienda-api trae su asset.
     *
     * @return void
     */
    private function github_con_release(string $version, bool $con_spa = true, bool $con_api = true): void
    {
        $release = function ($asset) {
            return [
                'id'     => 1,
                'assets' => $asset === null ? [] : [[
                    'id'   => 1,
                    'name' => $asset,
                    'size' => 4096,
                    'url'  => 'https://api.github.com/repos/lucasgonzz/x/releases/assets/1',
                ]],
            ];
        };

        Http::fake([
            'api.github.com/repos/lucasgonzz/tienda-spa/releases/tags/v' . $version => Http::response(
                $release($con_spa ? 'tienda-spa-v' . $version . '-dist.zip' : null),
                200
            ),
            'api.github.com/repos/lucasgonzz/tienda-api/releases/tags/v' . $version => Http::response(
                $release($con_api ? 'tienda-api-v' . $version . '.zip' : null),
                200
            ),
            '*' => Http::response(['message' => 'Not Found'], 404),
        ]);
    }

    /**
     * Versión ya cargada.
     *
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
     * Cliente + tienda configurada + credenciales SSH (mismo escenario que el test de actualización).
     *
     * @param string $nombre
     *
     * @return array{cliente: Client, tienda: ClientEcommerce}
     */
    private function escenario(string $nombre): array
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
        $client->name            = $nombre;
        $client->company_name    = 'Empresa ' . $nombre;
        $client->slug            = Str::slug($nombre) . '-' . Str::random(8);
        $client->api_url         = 'https://ejemplo.test';
        $client->api_key         = 'clave-api';
        $client->inbound_api_key = 'clave-inbound';
        $client->is_active       = true;
        $client->save();

        $dominio           = Str::slug($nombre) . '-' . Str::random(6) . '.com.ar';
        $tienda            = new ClientEcommerce();
        $tienda->client_id = $client->id;
        $tienda->domain    = $dominio;
        $tienda->spa_url   = 'https://' . $dominio;
        $tienda->api_url   = 'https://api.' . $dominio;
        $tienda->status    = 'active';
        $tienda->save();

        return ['cliente' => $client, 'tienda' => $tienda];
    }

    /* ------------------------------------------------------------------------------------------
     | La puerta
     |----------------------------------------------------------------------------------------- */

    /** Sin la clave, las tres rutas de versiones contestan 401 y no escriben nada. */
    public function test_sin_clave_las_rutas_de_versiones_devuelven_401(): void
    {
        Http::fake();
        $version = $this->version('1.0.0');

        $this->getJson('/api/claude/ecommerce/versions')->assertStatus(401);
        $this->postJson('/api/claude/ecommerce/versions', ['version' => '1.1.0'])->assertStatus(401);
        $this->patchJson('/api/claude/ecommerce/versions/' . $version->id, ['title' => 'x'])->assertStatus(401);

        $this->assertSame(1, EcommerceVersion::query()->count());
    }

    /* ------------------------------------------------------------------------------------------
     | POST claude/ecommerce/versions
     |----------------------------------------------------------------------------------------- */

    /** Con los dos assets en el release, publica: 201, published_at y artefactos_verificados. */
    public function test_el_alta_con_los_dos_assets_publica_la_version(): void
    {
        $this->github_con_release('1.0.0');

        $respuesta = $this->postJson('/api/claude/ecommerce/versions', [
            'version'     => '1.0.0',
            'title'       => 'Primera versión',
            'description' => 'Release inicial de tienda',
        ], $this->headers());

        $respuesta->assertStatus(201);
        $respuesta->assertJsonPath('model.version', '1.0.0');
        $respuesta->assertJsonPath('model.status', 'published');
        $respuesta->assertJsonPath('model.title', 'Primera versión');
        $respuesta->assertJsonPath('artefactos_verificados', true);
        $respuesta->assertJsonPath('artefactos.spa.asset', 'tienda-spa-v1.0.0-dist.zip');
        $respuesta->assertJsonPath('artefactos.api.asset', 'tienda-api-v1.0.0.zip');
        $this->assertNotNull($respuesta->json('model.published_at'));
        foreach (['id', 'version', 'title', 'description', 'status', 'published_at', 'created_at'] as $clave) {
            $this->assertArrayHasKey($clave, $respuesta->json('model'));
        }

        $fila = EcommerceVersion::where('version', '1.0.0')->first();
        $this->assertNotNull($fila);
        $this->assertNotNull($fila->published_at);

        Http::assertSent(function ($request) {
            return strpos($request->url(), 'tienda-spa/releases/tags/v1.0.0') !== false;
        });
        Http::assertSent(function ($request) {
            return strpos($request->url(), 'tienda-api/releases/tags/v1.0.0') !== false;
        });
    }

    /** 🔴 Si al release le falta un asset: 422 nombrando repo, tag y asset, y NO se crea la fila. */
    public function test_si_falta_un_asset_no_se_publica_ni_se_crea_la_fila(): void
    {
        $this->github_con_release('1.2.0', true, false);

        $respuesta = $this->postJson('/api/claude/ecommerce/versions', ['version' => '1.2.0'], $this->headers());

        $respuesta->assertStatus(422);
        $cuerpo = $this->cuerpo($respuesta);
        $this->assertStringContainsString('tienda-api-v1.2.0.zip', $cuerpo);
        $this->assertStringContainsString('v1.2.0', $cuerpo);
        $this->assertStringContainsString('tienda-api', $cuerpo);
        $respuesta->assertJsonPath('faltantes.0.asset', 'tienda-api-v1.2.0.zip');
        $respuesta->assertJsonPath('faltantes.0.repo', 'tienda-api');
        $respuesta->assertJsonPath('faltantes.0.tag', 'v1.2.0');

        $this->assertSame(0, EcommerceVersion::query()->count(), 'Se creó la versión aunque faltaba un asset.');
    }

    /** Sin release para el tag (404 de GitHub), faltan los dos: 422 y sin fila. */
    public function test_sin_release_faltan_los_dos_assets(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

        $respuesta = $this->postJson('/api/claude/ecommerce/versions', ['version' => '3.0.0'], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertCount(2, $respuesta->json('faltantes'));
        $this->assertSame(0, EcommerceVersion::query()->count());
    }

    /** Si GitHub falla de otra manera (500, token), 502 y tampoco se publica. */
    public function test_un_error_de_github_que_no_es_404_devuelve_502_y_no_publica(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Server Error'], 500)]);

        $respuesta = $this->postJson('/api/claude/ecommerce/versions', ['version' => '1.0.0'], $this->headers());

        $respuesta->assertStatus(502);
        $this->assertStringContainsString('No se pudo verificar en GitHub', $this->cuerpo($respuesta));
        $this->assertSame(0, EcommerceVersion::query()->count());
    }

    /** Un código duplicado es 422 (en español) y no crea una segunda fila. */
    public function test_una_version_duplicada_es_422(): void
    {
        Http::fake();
        $this->version('1.0.0');

        $respuesta = $this->postJson('/api/claude/ecommerce/versions', ['version' => '1.0.0'], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('Ya existe una versión de ecommerce', $this->cuerpo($respuesta));
        $this->assertSame(1, EcommerceVersion::where('version', '1.0.0')->count());
        Http::assertNothingSent();
    }

    /** Un código que no es una versión (dos componentes, letras) es 422 y no consulta GitHub. */
    public function test_una_version_invalida_es_422(): void
    {
        Http::fake();

        foreach (['1.0', 'v1.0.0', '1.0.0-beta', ''] as $mala) {
            $respuesta = $this->postJson('/api/claude/ecommerce/versions', ['version' => $mala], $this->headers());
            $respuesta->assertStatus(422);
        }

        $this->assertStringContainsString(
            'no tiene el formato de una versión',
            $this->cuerpo($this->postJson('/api/claude/ecommerce/versions', ['version' => '1.0'], $this->headers()))
        );
        $this->assertSame(0, EcommerceVersion::query()->count());
        Http::assertNothingSent();
    }

    /** Un borrador no se verifica (se verifica al publicarlo); verify_artifacts=false publica sin mirar. */
    public function test_un_borrador_y_verify_artifacts_false_no_consultan_github(): void
    {
        Http::fake();

        $this->postJson('/api/claude/ecommerce/versions', ['version' => '2.0.0', 'status' => 'draft'], $this->headers())
            ->assertStatus(201)
            ->assertJsonPath('model.status', 'draft')
            ->assertJsonPath('model.published_at', null)
            ->assertJsonPath('artefactos_verificados', false);

        $this->postJson('/api/claude/ecommerce/versions', ['version' => '2.0.1', 'verify_artifacts' => false], $this->headers())
            ->assertStatus(201)
            ->assertJsonPath('model.status', 'published')
            ->assertJsonPath('artefactos_verificados', false);

        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------------------------------
     | GET y PATCH
     |----------------------------------------------------------------------------------------- */

    /** El listado ordena por código SEMÁNTICO, filtra published por defecto y trae la última. */
    public function test_el_listado_ordena_semanticamente_y_filtra_publicadas(): void
    {
        $this->version('1.9.0');
        $this->version('1.10.0');
        $this->version('1.9.0.1');
        $this->version('2.0.0', 'draft');

        $respuesta = $this->getJson('/api/claude/ecommerce/versions', $this->headers());

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('count', 3);
        $this->assertSame(['1.10.0', '1.9.0.1', '1.9.0'], array_column($respuesta->json('data'), 'version'));
        $respuesta->assertJsonPath('ultima_publicada.version', '1.10.0');

        $todas = $this->getJson('/api/claude/ecommerce/versions?status=all', $this->headers());
        $todas->assertJsonPath('count', 4);
        $this->assertSame('2.0.0', $todas->json('data.0.version'));

        $this->getJson('/api/claude/ecommerce/versions?status=borradas', $this->headers())->assertStatus(422);
    }

    /** El PATCH edita título y descripción sin tocar el resto. */
    public function test_el_patch_edita_titulo_y_descripcion(): void
    {
        Http::fake();
        $version = $this->version('1.0.0');

        $this->patchJson('/api/claude/ecommerce/versions/' . $version->id, [
            'title'       => 'Título nuevo',
            'description' => 'Descripción nueva',
        ], $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('model.title', 'Título nuevo')
            ->assertJsonPath('model.description', 'Descripción nueva')
            ->assertJsonPath('model.status', 'published');

        Http::assertNothingSent();
    }

    /** 🔴 Pasar un borrador a published verifica los assets: si falta uno, 422 y no cambia nada. */
    public function test_publicar_un_borrador_sin_assets_es_422_y_no_cambia_nada(): void
    {
        $this->github_con_release('1.1.0', false, true);
        $borrador = $this->version('1.1.0', 'draft');

        $respuesta = $this->patchJson('/api/claude/ecommerce/versions/' . $borrador->id, ['status' => 'published'], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('tienda-spa-v1.1.0-dist.zip', $this->cuerpo($respuesta));
        $this->assertSame('draft', $borrador->fresh()->status);
        $this->assertNull($borrador->fresh()->published_at);
    }

    /** Con los assets, el borrador se publica y estampa published_at. */
    public function test_publicar_un_borrador_con_assets_estampa_published_at(): void
    {
        $this->github_con_release('1.1.0');
        $borrador = $this->version('1.1.0', 'draft');

        $this->patchJson('/api/claude/ecommerce/versions/' . $borrador->id, ['status' => 'published'], $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('model.status', 'published')
            ->assertJsonPath('artefactos_verificados', true);

        $this->assertNotNull($borrador->fresh()->published_at);
    }

    /** El código no se edita (422 explícito), un cuerpo vacío es 422 y un id que no existe, 404. */
    public function test_el_patch_no_edita_el_codigo_ni_acepta_cuerpo_vacio(): void
    {
        Http::fake();
        $version = $this->version('1.0.0');

        $this->patchJson('/api/claude/ecommerce/versions/' . $version->id, ['version' => '9.9.9'], $this->headers())
            ->assertStatus(422);
        $this->assertSame('1.0.0', $version->fresh()->version);

        $this->patchJson('/api/claude/ecommerce/versions/' . $version->id, [], $this->headers())->assertStatus(422);
        $this->patchJson('/api/claude/ecommerce/versions/999999', ['title' => 'x'], $this->headers())->assertStatus(404);
    }

    /* ------------------------------------------------------------------------------------------
     | POST claude/ecommerce/updates (de a uno)
     |----------------------------------------------------------------------------------------- */

    /** Sin versión pedida, la corrida guarda la última publicada (semántica) y la respuesta la trae. */
    public function test_la_actualizacion_sin_version_guarda_la_ultima_publicada(): void
    {
        Queue::fake();
        $this->version('1.9.0');
        $ultima = $this->version('1.10.0');
        $e      = $this->escenario('Librería Versión Default');

        $respuesta = $this->postJson('/api/claude/ecommerce/updates', [
            'client_id'           => $e['cliente']->id,
            'confirm_client_name' => 'Librería Versión Default',
        ], $this->headers());

        $respuesta->assertStatus(202);
        $respuesta->assertJsonPath('ecommerce_version.id', (int) $ultima->id);
        $respuesta->assertJsonPath('ecommerce_version.version', '1.10.0');

        $corrida = ClientEcommerceInstallation::find($respuesta->json('installation_id'));
        $this->assertSame((int) $ultima->id, (int) $corrida->ecommerce_version_id);
        $this->assertSame('update', $corrida->mode);

        Queue::assertPushed(RunEcommerceInstallationJob::class, function ($job) {
            return $job->connection === 'database';
        });
    }

    /** Con ecommerce_version_id o con version, se guarda la pedida (aunque no sea la última). */
    public function test_la_actualizacion_guarda_la_version_pedida_por_id_o_por_codigo(): void
    {
        Queue::fake();
        $pedida = $this->version('1.0.0');
        $this->version('1.1.0');

        $a = $this->escenario('Kiosco Por Id');
        $this->postJson('/api/claude/ecommerce/updates', [
            'client_id'            => $a['cliente']->id,
            'confirm_client_name'  => 'Kiosco Por Id',
            'ecommerce_version_id' => $pedida->id,
        ], $this->headers())->assertStatus(202)->assertJsonPath('ecommerce_version.version', '1.0.0');

        $b = $this->escenario('Kiosco Por Codigo');
        $this->postJson('/api/claude/ecommerce/updates', [
            'client_id'           => $b['cliente']->id,
            'confirm_client_name' => 'Kiosco Por Codigo',
            'version'             => '1.0.0',
            'ecommerce_version_id' => $pedida->id,
        ], $this->headers())->assertStatus(202)->assertJsonPath('ecommerce_version.id', (int) $pedida->id);

        foreach ([$a['tienda'], $b['tienda']] as $tienda) {
            $this->assertSame(
                (int) $pedida->id,
                (int) ClientEcommerceInstallation::where('client_ecommerce_id', $tienda->id)->value('ecommerce_version_id')
            );
        }
    }

    /** 🔴 Una versión no publicada, inexistente o que no coincide entre id y código: 422 y nada encolado. */
    public function test_la_actualizacion_rechaza_una_version_que_no_se_puede_desplegar(): void
    {
        Queue::fake();
        $borrador  = $this->version('2.0.0', 'draft');
        $publicada = $this->version('1.0.0');
        $e         = $this->escenario('Ferretería Versión Mala');

        $casos = [
            ['ecommerce_version_id' => $borrador->id],
            ['version' => '2.0.0'],
            ['version' => '7.7.7'],
            ['ecommerce_version_id' => 999999],
            ['ecommerce_version_id' => $publicada->id, 'version' => '2.0.0'],
        ];

        foreach ($casos as $caso) {
            $respuesta = $this->postJson('/api/claude/ecommerce/updates', array_merge([
                'client_id'           => $e['cliente']->id,
                'confirm_client_name' => 'Ferretería Versión Mala',
            ], $caso), $this->headers());

            $respuesta->assertStatus(422);
            $this->assertStringContainsString('No se encoló nada', $this->cuerpo($respuesta));
        }

        $this->assertSame(0, ClientEcommerceInstallation::where('client_ecommerce_id', $e['tienda']->id)->count());
        Queue::assertNothingPushed();
    }

    /** Sin ninguna versión publicada, el endpoint encola como antes, pero avisa que la corrida va a fallar. */
    public function test_sin_version_publicada_encola_igual_y_lo_avisa(): void
    {
        Queue::fake();
        $e = $this->escenario('Almacén Sin Versiones');

        $respuesta = $this->postJson('/api/claude/ecommerce/updates', [
            'client_id'           => $e['cliente']->id,
            'confirm_client_name' => 'Almacén Sin Versiones',
        ], $this->headers());

        $respuesta->assertStatus(202);
        $respuesta->assertJsonPath('ecommerce_version', null);
        $this->assertStringContainsString('No hay ninguna versión de ecommerce publicada', (string) $respuesta->json('nota_version'));
        $this->assertNull(ClientEcommerceInstallation::find($respuesta->json('installation_id'))->ecommerce_version_id);
    }

    /* ------------------------------------------------------------------------------------------
     | El lote
     |----------------------------------------------------------------------------------------- */

    /** La simulación muestra la versión del lote, y el lote real la guarda en todas las corridas. */
    public function test_el_lote_guarda_la_version_en_todas_las_corridas(): void
    {
        Queue::fake();
        $pedida = $this->version('1.0.0');
        $this->version('1.1.0');

        $a = $this->escenario('Lote Versión A');
        $b = $this->escenario('Lote Versión B');
        $ids = [$a['cliente']->id, $b['cliente']->id];

        $simulacion = $this->postJson('/api/claude/ecommerce/updates/batch', [
            'client_ids'           => $ids,
            'ecommerce_version_id' => $pedida->id,
        ], $this->headers());

        $simulacion->assertStatus(200);
        $simulacion->assertJsonPath('ecommerce_version.version', '1.0.0');

        $this->postJson('/api/claude/ecommerce/updates/batch', [
            'client_ids'           => $ids,
            'ecommerce_version_id' => $pedida->id,
            'dry_run'              => false,
            'confirm_client_count' => 2,
            'confirm_token'        => $simulacion->json('confirm_token'),
        ], $this->headers())
            ->assertStatus(202)
            ->assertJsonPath('creadas', 2)
            ->assertJsonPath('ecommerce_version.id', (int) $pedida->id);

        $versiones = ClientEcommerceInstallation::whereIn('client_ecommerce_id', [$a['tienda']->id, $b['tienda']->id])
            ->pluck('ecommerce_version_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();
        $this->assertSame([(int) $pedida->id, (int) $pedida->id], $versiones);
    }

    /**
     * 🔴 El token de una simulación con una versión no confirma un lote con otra; y sin versión
     * pedida el token es el de siempre (no depende de qué versión sea la última publicada).
     */
    public function test_el_token_del_lote_queda_atado_a_la_version_pedida(): void
    {
        Queue::fake();
        $v1 = $this->version('1.0.0');
        $v2 = $this->version('1.1.0');
        $a  = $this->escenario('Lote Token Versión');

        $sin_version = $this->postJson('/api/claude/ecommerce/updates/batch', ['client_ids' => [$a['cliente']->id]], $this->headers())
            ->json('confirm_token');
        $con_v1 = $this->postJson('/api/claude/ecommerce/updates/batch', [
            'client_ids' => [$a['cliente']->id], 'ecommerce_version_id' => $v1->id,
        ], $this->headers())->json('confirm_token');

        $this->assertNotSame($sin_version, $con_v1);

        $this->postJson('/api/claude/ecommerce/updates/batch', [
            'client_ids'           => [$a['cliente']->id],
            'ecommerce_version_id' => $v2->id,
            'dry_run'              => false,
            'confirm_client_count' => 1,
            'confirm_token'        => $con_v1,
        ], $this->headers())->assertStatus(422);

        $this->assertSame(0, ClientEcommerceInstallation::where('client_ecommerce_id', $a['tienda']->id)->count());
        Queue::assertNothingPushed();
    }

    /** Una versión no publicada en el lote: 422 antes de mirar tiendas, cero corridas. */
    public function test_el_lote_rechaza_una_version_no_publicada(): void
    {
        Queue::fake();
        $this->version('3.0.0', 'archived');
        $a = $this->escenario('Lote Archivada');

        $respuesta = $this->postJson('/api/claude/ecommerce/updates/batch', [
            'client_ids' => [$a['cliente']->id],
            'version'    => '3.0.0',
        ], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('archived', $this->cuerpo($respuesta));
        $this->assertSame(0, ClientEcommerceInstallation::where('client_ecommerce_id', $a['tienda']->id)->count());
    }

    /* ------------------------------------------------------------------------------------------
     | Lectura: stores e installations
     |----------------------------------------------------------------------------------------- */

    /** stores devuelve la versión instalada de cada tienda ({id, version}) o null. */
    public function test_stores_devuelve_la_version_instalada(): void
    {
        $version = $this->version('1.0.0');
        $con     = $this->escenario('Tienda Con Versión');
        $sin     = $this->escenario('Tienda Sin Versión');
        $con['tienda']->update(['ecommerce_version_id' => $version->id]);

        $con_fila = $this->getJson('/api/claude/ecommerce/stores?client_id=' . $con['cliente']->id, $this->headers());
        $con_fila->assertStatus(200);
        $con_fila->assertJsonPath('data.0.ecommerce_version.id', (int) $version->id);
        $con_fila->assertJsonPath('data.0.ecommerce_version.version', '1.0.0');

        $sin_fila = $this->getJson('/api/claude/ecommerce/stores?client_id=' . $sin['cliente']->id, $this->headers());
        $sin_fila->assertJsonPath('data.0.ecommerce_version', null);
        $this->assertArrayHasKey('ecommerce_version', $sin_fila->json('data.0'));
    }

    /** installations (listado y ficha) devuelven la versión que despliega cada corrida. */
    public function test_installations_devuelve_la_version_de_la_corrida(): void
    {
        $version = $this->version('1.0.0');
        $e       = $this->escenario('Tienda Corridas Versión');

        $corrida = ClientEcommerceInstallation::create([
            'client_ecommerce_id'  => $e['tienda']->id,
            'mode'                 => 'update',
            'status'               => 'completada',
            'ecommerce_version_id' => $version->id,
        ]);
        ClientEcommerceInstallation::create([
            'client_ecommerce_id' => $e['tienda']->id,
            'mode'                => 'update',
            'status'              => 'fallida',
        ]);

        $listado = $this->getJson('/api/claude/ecommerce/installations?client_id=' . $e['cliente']->id . '&order=asc', $this->headers());
        $listado->assertStatus(200);
        $listado->assertJsonPath('data.0.ecommerce_version.version', '1.0.0');
        $listado->assertJsonPath('data.1.ecommerce_version', null);

        $this->getJson('/api/claude/ecommerce/installations/' . $corrida->id, $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('corrida.ecommerce_version.id', (int) $version->id)
            ->assertJsonPath('corrida.ecommerce_version.version', '1.0.0');
    }
}
