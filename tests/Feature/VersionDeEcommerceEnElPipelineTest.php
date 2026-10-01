<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientEcommerce;
use App\Models\ClientEcommerceInstallation;
use App\Models\EcommerceVersion;
use App\Services\EcommerceDeploymentService;
use App\Services\EcommerceInstallationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Espía del pipeline de ecommerce: cualquier intento de tocar el VPS de builds, el hosting, el lock
 * global del build o el clone de un repo queda anotado en `$toques` y corta la corrida con un
 * mensaje que empieza con "ESPIA:". Con `$pasos_en_blanco` las etapas no hacen nada (para probar
 * lo que pasa al TERMINAR bien una corrida sin SSH de por medio).
 *
 * Va en un trait para poder colgarlo de los dos servicios (instalación y actualización).
 */
trait EspiaDelPipelineDeTienda
{
    /** @var array<int, string> Lo que la corrida intentó tocar, en orden. */
    public $toques = [];

    /** @var bool Si las etapas se saltean (el lock se toma sin error). */
    public $pasos_en_blanco = false;

    protected function connect_build_vps()
    {
        $this->toques[] = 'vps';

        throw new \RuntimeException('ESPIA: se intentó conectar al VPS de builds');
    }

    protected function connect_hosting_ssh(): void
    {
        $this->toques[] = 'hosting';

        throw new \RuntimeException('ESPIA: se intentó conectar al hosting');
    }

    protected function acquire_build_lock(): void
    {
        $this->toques[] = 'lock';

        if (! $this->pasos_en_blanco) {
            throw new \RuntimeException('ESPIA: se intentó tomar el lock global del build');
        }
    }

    protected function release_build_lock(): void
    {
        $this->toques[] = 'release';
    }

    protected function ensure_repo_cloned(
        string $step,
        string $repo_path,
        string $git_repo,
        string $repo_label,
        string $env_var_name
    ): void {
        $this->toques[] = 'clone';

        throw new \RuntimeException('ESPIA: se intentó clonar ' . $repo_label);
    }

    protected function execute_steps()
    {
        if ($this->pasos_en_blanco) {
            $this->toques[] = 'pasos';

            return;
        }

        parent::execute_steps();
    }
}

/**
 * La versión de ecommerce en el pipeline (misión cruzada `versiones-tienda`, 1/10/2026): qué se
 * despliega, por qué vía, y qué pasa cuando no hay nada que desplegar.
 *
 * Lo que se protege, en orden de importancia:
 *  1. 🔴 Sin versión publicada y con `DEPLOY_PERMITIR_BUILD_EN_VPS` apagada (el default), la corrida
 *     FALLA con un mensaje que dice qué falta y cómo publicarla, y NO TOCA el VPS de builds. Es la
 *     regresión más probable: alguien "arregla" la falla cayendo a la vía vieja y el carril entero
 *     vuelve a compilar en el VPS sin que nada lo denuncie, porque el síntoma es una corrida verde.
 *  2. Una versión pedida que no está publicada, o un release sin uno de los dos assets, también
 *     falla antes de tocar nada, y el mensaje nombra repo, tag y asset.
 *  3. La vía de artefacto no clona ni toma el lock global del build.
 *  4. Al terminar bien, la tienda queda con la versión de la corrida (y la corrida con la que
 *     desplegó: la última publicada por orden SEMÁNTICO, no por id).
 */
class VersionDeEcommerceEnElPipelineTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.deploy.permitir_build_en_vps'    => false,
            'services.github.token'                    => 'ghp_token_de_prueba',
            'services.github.releases_owner'           => 'lucasgonzz',
            'services.anthropic.verify_ssl'            => true,
            'services.anthropic.ca_bundle'             => null,
            'services.deploy_tienda.release_repo_spa'  => 'tienda-spa',
            'services.deploy_tienda.release_repo_api'  => 'tienda-api',
        ]);

        // La base del slot no tiene que aportar versiones propias: cada test arma las suyas.
        EcommerceVersion::query()->delete();
    }

    /* ------------------------------------------------------------------------------------------
     | Armado
     |----------------------------------------------------------------------------------------- */

    /**
     * Cliente + tienda configurada + corrida pendiente.
     *
     * @param string   $mode        install | update.
     * @param int|null $version_id  Versión pedida para la corrida.
     *
     * @return ClientEcommerceInstallation
     */
    private function corrida(string $mode = 'update', $version_id = null): ClientEcommerceInstallation
    {
        $client                  = new Client();
        $client->name            = 'Tienda Pipeline ' . Str::random(6);
        $client->company_name    = 'Comercio de prueba';
        $client->slug            = 'tienda-pipeline-' . Str::random(8);
        $client->api_url         = 'https://ejemplo.test';
        // Sin api_key: el branding no le pega a empresa-api (que acá no existe) y cae al default.
        $client->api_key         = '';
        $client->inbound_api_key = 'clave-inbound';
        $client->is_active       = true;
        $client->user_id         = 4200;
        $client->save();

        $dominio           = 'pipeline-' . Str::random(6) . '.com.ar';
        $tienda            = new ClientEcommerce();
        $tienda->client_id = $client->id;
        $tienda->domain    = $dominio;
        $tienda->spa_url   = 'https://' . $dominio;
        $tienda->api_url   = 'https://api.' . $dominio;
        $tienda->status    = 'active';
        $tienda->save();

        return ClientEcommerceInstallation::create([
            'client_ecommerce_id'  => $tienda->id,
            'mode'                 => $mode,
            'status'               => 'pendiente',
            'ecommerce_version_id' => $version_id,
        ]);
    }

    /**
     * Servicio espía de actualización o de instalación, según el mode de la corrida.
     *
     * @param ClientEcommerceInstallation $corrida
     * @param bool                        $pasos_en_blanco
     *
     * @return EcommerceInstallationService
     */
    private function espia(ClientEcommerceInstallation $corrida, bool $pasos_en_blanco = false): EcommerceInstallationService
    {
        if ($corrida->mode === 'install') {
            $servicio = new class($corrida) extends EcommerceInstallationService {
                use EspiaDelPipelineDeTienda;
            };
        } else {
            $servicio = new class($corrida) extends EcommerceDeploymentService {
                use EspiaDelPipelineDeTienda;
            };
        }

        $servicio->pasos_en_blanco = $pasos_en_blanco;

        return $servicio;
    }

    /**
     * Corre el pipeline y devuelve el mensaje con que falló (o null si terminó bien).
     *
     * @param EcommerceInstallationService $servicio
     *
     * @return string|null
     */
    private function correr(EcommerceInstallationService $servicio): ?string
    {
        try {
            $servicio->run();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Versión de ecommerce.
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
            'title'        => 'Versión ' . $version,
            'status'       => $status,
            'published_at' => $status === 'published' ? now() : null,
        ]);
    }

    /**
     * Respuesta de GitHub para un release que trae (o no) su asset.
     *
     * @param string|null $asset Nombre del asset, o null para un release vacío.
     * @param int         $bytes Tamaño declarado.
     * @param string      $url   URL del asset en la API.
     *
     * @return array<string, mixed>
     */
    private function release(?string $asset, int $bytes = 2048, string $url = 'https://api.github.com/repos/lucasgonzz/x/releases/assets/1'): array
    {
        return [
            'id'     => 1,
            'assets' => $asset === null ? [] : [[
                'id'                   => 1,
                'name'                 => $asset,
                'size'                 => $bytes,
                'url'                  => $url,
                'browser_download_url' => 'https://github.com/lucasgonzz/x/releases/download/' . $asset,
            ]],
        ];
    }

    /* ------------------------------------------------------------------------------------------
     | 1. Sin nada publicado
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 El test que importa: sin versión publicada y con la bandera apagada, la corrida falla
     * diciendo qué falta y cómo se publica, y NO toca el VPS de builds (ni el hosting, ni el lock,
     * ni GitHub).
     */
    public function test_sin_version_publicada_y_sin_bandera_la_actualizacion_falla_y_no_toca_el_vps(): void
    {
        Http::fake();

        $corrida  = $this->corrida('update');
        $servicio = $this->espia($corrida);

        $error = $this->correr($servicio);

        $this->assertNotNull($error, 'La corrida terminó bien sin ninguna versión publicada.');
        $this->assertStringContainsString('No hay ninguna versión de ecommerce publicada', $error);
        $this->assertStringContainsString('POST claude/ecommerce/versions', $error);
        $this->assertStringContainsString('DEPLOY_PERMITIR_BUILD_EN_VPS', $error);
        $this->assertSame([], $servicio->toques, 'La corrida tocó infraestructura antes de fallar.');
        Http::assertNothingSent();

        $corrida->refresh();
        $this->assertSame('fallida', $corrida->status);
        $this->assertStringContainsString('No hay ninguna versión de ecommerce publicada', (string) $corrida->failure_reason);
        $this->assertNull($corrida->ecommerce_version_id);
        $this->assertSame('active', $corrida->client_ecommerce->status, 'La tienda quedó colgada en installing.');
    }

    /** Lo mismo para una instalación desde cero. */
    public function test_sin_version_publicada_y_sin_bandera_la_instalacion_tambien_falla_sin_tocar_nada(): void
    {
        Http::fake();

        $corrida  = $this->corrida('install');
        $servicio = $this->espia($corrida);

        $error = $this->correr($servicio);

        $this->assertNotNull($error);
        $this->assertStringContainsString('No hay ninguna versión de ecommerce publicada', $error);
        $this->assertSame([], $servicio->toques);
    }

    /** Una versión en borrador no cuenta como publicada: sin otra, falla igual. */
    public function test_un_borrador_no_es_una_version_publicada(): void
    {
        Http::fake();
        $this->version('3.0.0', 'draft');

        $servicio = $this->espia($this->corrida('update'));

        $this->assertStringContainsString('No hay ninguna versión de ecommerce publicada', (string) $this->correr($servicio));
        $this->assertSame([], $servicio->toques);
    }

    /** Con la bandera prendida y sin versión publicada, va por la vía vieja: toma el lock del build. */
    public function test_con_la_bandera_y_sin_version_publicada_va_por_la_via_vieja(): void
    {
        Http::fake();
        config(['services.deploy.permitir_build_en_vps' => true]);

        $servicio = $this->espia($this->corrida('update'));

        $this->assertStringContainsString('ESPIA: se intentó tomar el lock global del build', (string) $this->correr($servicio));
        $this->assertSame(['lock'], $servicio->toques);
    }

    /* ------------------------------------------------------------------------------------------
     | 2. Versión pedida y artefactos
     |----------------------------------------------------------------------------------------- */

    /** Una versión pedida que no está publicada falla, aunque haya otra publicada. */
    public function test_una_version_pedida_que_no_esta_publicada_falla(): void
    {
        Http::fake();
        $this->version('1.0.0');
        $borrador = $this->version('1.1.0', 'draft');

        $servicio = $this->espia($this->corrida('update', $borrador->id));
        $error    = (string) $this->correr($servicio);

        $this->assertStringContainsString('1.1.0', $error);
        $this->assertStringContainsString('draft', $error);
        $this->assertSame([], $servicio->toques);
        Http::assertNothingSent();
    }

    /**
     * 🔴 Si al release le falta un asset, la corrida falla ANTES de tocar nada, nombrando el asset,
     * el tag y el repo (mismo mensaje que el trait de empresa), y no cae al VPS.
     */
    public function test_sin_el_asset_en_el_release_falla_nombrando_repo_tag_y_asset(): void
    {
        $this->version('9.8.7');

        Http::fake([
            'api.github.com/repos/lucasgonzz/tienda-spa/releases/tags/*' => Http::response($this->release(null), 200),
            'api.github.com/repos/lucasgonzz/tienda-api/releases/tags/*' => Http::response($this->release('tienda-api-v9.8.7.zip'), 200),
            '*'                                                          => Http::response([], 404),
        ]);

        $corrida  = $this->corrida('update');
        $servicio = $this->espia($corrida);
        $error    = (string) $this->correr($servicio);

        $this->assertStringContainsString('tienda-spa-v9.8.7-dist.zip', $error);
        $this->assertStringContainsString('v9.8.7', $error);
        $this->assertStringContainsString('tienda-spa', $error);
        $this->assertStringContainsString('DEPLOY_PERMITIR_BUILD_EN_VPS', $error);
        $this->assertSame([], $servicio->toques, 'Sin artefacto se tocó el VPS de builds.');
    }

    /* ------------------------------------------------------------------------------------------
     | 3. La vía de artefacto
     |----------------------------------------------------------------------------------------- */

    /**
     * Con la versión y sus dos assets, la corrida va por artefacto: baja el dist del release y el
     * primer contacto con el VPS de builds es para generar el branding — sin clonar nada y sin
     * tomar el lock global del build.
     */
    public function test_la_via_de_artefacto_no_clona_ni_toma_el_lock_del_build(): void
    {
        $this->version('2.0.0');

        // Un dist de release mínimo pero real: index.html en la raíz y más de 500 bytes.
        $zip_local = tempnam(sys_get_temp_dir(), 'dist');
        $zip       = new \ZipArchive();
        $zip->open($zip_local, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('index.html', '<title>__CC_SITE_NAME__</title>');
        // Contenido incompresible: con texto repetido el zip queda por debajo de los 500 bytes que
        // assert_local_zip_file() exige a cualquier artefacto.
        $zip->addFromString('img/relleno.bin', random_bytes(2048));
        $zip->close();
        $binario = (string) file_get_contents($zip_local);
        @unlink($zip_local);

        $url_asset = 'https://api.github.com/repos/lucasgonzz/tienda-spa/releases/assets/101';

        Http::fake([
            'api.github.com/repos/lucasgonzz/tienda-spa/releases/tags/*'   => Http::response($this->release('tienda-spa-v2.0.0-dist.zip', strlen($binario), $url_asset), 200),
            'api.github.com/repos/lucasgonzz/tienda-api/releases/tags/*'   => Http::response($this->release('tienda-api-v2.0.0.zip'), 200),
            'api.github.com/repos/lucasgonzz/tienda-spa/releases/assets/*' => Http::response($binario, 200),
            '*'                                                            => Http::response([], 404),
        ]);

        $corrida  = $this->corrida('update');
        $servicio = $this->espia($corrida);
        $error    = (string) $this->correr($servicio);

        $this->assertStringContainsString('ESPIA: se intentó conectar al VPS de builds', $error);
        $this->assertNotContains('lock', $servicio->toques, 'La vía de artefacto tomó el lock global del build.');
        $this->assertNotContains('clone', $servicio->toques, 'La vía de artefacto clonó un repo en el VPS.');
        $this->assertSame('vps', $servicio->toques[0], 'El primer contacto no fue el VPS de builds para el branding.');

        $corrida->refresh();
        $logs = $corrida->logs()->pluck('line')->implode("\n");
        $this->assertStringContainsString('no se clona tienda-spa', $logs);
        $this->assertStringContainsString('tienda-spa-v2.0.0-dist.zip bajado de GitHub', $logs);
        $this->assertSame(EcommerceVersion::where('version', '2.0.0')->value('id'), (int) $corrida->ecommerce_version_id);

        // Nada quedó tirado en storage/app/deployments de esta corrida.
        $this->assertFileDoesNotExist(storage_path('app/deployments/tienda_release_dist_' . $corrida->uuid . '.zip'));
    }

    /**
     * Un asset que baja pero no es un zip (o viene corrupto) corta la corrida antes de tocar el VPS,
     * y no deja el archivo tirado en storage/app/deployments.
     */
    public function test_un_dist_que_no_es_un_zip_corta_y_no_queda_en_storage(): void
    {
        $this->version('2.1.0');

        $basura    = str_repeat('esto no es un zip ', 60);
        $url_asset = 'https://api.github.com/repos/lucasgonzz/tienda-spa/releases/assets/202';

        Http::fake([
            'api.github.com/repos/lucasgonzz/tienda-spa/releases/tags/*'   => Http::response($this->release('tienda-spa-v2.1.0-dist.zip', strlen($basura), $url_asset), 200),
            'api.github.com/repos/lucasgonzz/tienda-api/releases/tags/*'   => Http::response($this->release('tienda-api-v2.1.0.zip'), 200),
            'api.github.com/repos/lucasgonzz/tienda-spa/releases/assets/*' => Http::response($basura, 200),
            '*'                                                            => Http::response([], 404),
        ]);

        $corrida  = $this->corrida('update');
        $servicio = $this->espia($corrida);
        $error    = (string) $this->correr($servicio);

        $this->assertStringContainsString('ZIP', $error);
        $this->assertSame([], $servicio->toques, 'Con el dist roto se tocó el VPS de builds.');
        $this->assertFileDoesNotExist(storage_path('app/deployments/tienda_release_dist_' . $corrida->uuid . '.zip'));
    }

    /* ------------------------------------------------------------------------------------------
     | 4. Al terminar
     |----------------------------------------------------------------------------------------- */

    /**
     * Al terminar bien por artefacto, la corrida guarda la última publicada por orden SEMÁNTICO
     * (1.10.0, no 1.9.0 ni el hotfix cargado último) y la tienda queda registrada en esa versión.
     */
    public function test_al_terminar_la_tienda_queda_en_la_ultima_publicada_por_orden_semantico(): void
    {
        $this->version('1.9.0');
        $esperada = $this->version('1.10.0');
        $this->version('1.9.0.1');
        $this->version('2.0.0', 'archived');

        Http::fake([
            'api.github.com/repos/lucasgonzz/tienda-spa/releases/tags/v1.10.0' => Http::response($this->release('tienda-spa-v1.10.0-dist.zip'), 200),
            'api.github.com/repos/lucasgonzz/tienda-api/releases/tags/v1.10.0' => Http::response($this->release('tienda-api-v1.10.0.zip'), 200),
            '*'                                                                => Http::response([], 404),
        ]);

        $corrida  = $this->corrida('update');
        $servicio = $this->espia($corrida, true);

        $this->assertNull($this->correr($servicio));
        $this->assertSame(['pasos'], $servicio->toques, 'La vía de artefacto tomó el lock del build.');

        $corrida->refresh();
        $this->assertSame('completada', $corrida->status);
        $this->assertSame((int) $esperada->id, (int) $corrida->ecommerce_version_id);
        $this->assertSame((int) $esperada->id, (int) $corrida->client_ecommerce->ecommerce_version_id);
        $this->assertSame('active', $corrida->client_ecommerce->status);
    }

    /** Una versión pedida explícitamente gana sobre la última publicada. */
    public function test_la_version_pedida_gana_sobre_la_ultima_publicada(): void
    {
        $pedida = $this->version('1.0.0');
        $this->version('1.1.0');

        Http::fake([
            'api.github.com/repos/lucasgonzz/tienda-spa/releases/tags/v1.0.0' => Http::response($this->release('tienda-spa-v1.0.0-dist.zip'), 200),
            'api.github.com/repos/lucasgonzz/tienda-api/releases/tags/v1.0.0' => Http::response($this->release('tienda-api-v1.0.0.zip'), 200),
            '*'                                                               => Http::response([], 404),
        ]);

        $corrida = $this->corrida('update', $pedida->id);

        $this->assertNull($this->correr($this->espia($corrida, true)));
        $this->assertSame((int) $pedida->id, (int) $corrida->fresh()->client_ecommerce->ecommerce_version_id);
    }

    /**
     * Por la vía vieja (bandera prendida, sin versión publicada) la tienda queda SIN versión
     * registrada: compiló la última de master, que no es ninguna versión, aunque antes tuviera una.
     */
    public function test_por_la_via_vieja_la_tienda_queda_sin_version_registrada(): void
    {
        Http::fake();
        config(['services.deploy.permitir_build_en_vps' => true]);

        $vieja   = $this->version('0.9.0', 'archived');
        $corrida = $this->corrida('update');
        $corrida->client_ecommerce->update(['ecommerce_version_id' => $vieja->id]);

        $servicio = $this->espia($corrida, true);

        $this->assertNull($this->correr($servicio));
        $this->assertSame(['lock', 'pasos', 'release'], $servicio->toques);
        $this->assertNull($corrida->fresh()->client_ecommerce->ecommerce_version_id);
    }
}
