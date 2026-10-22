<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientSshCredential;
use App\Models\ClientVersionUpgrade;
use App\Models\Version;
use App\Models\ClientInstallation;
use App\Models\Demo;
use App\Models\DemoInstallation;
use App\Models\VersionCommand;
use App\Models\UpdateCommand;
use App\Models\VersionSeeder;
use App\Models\UpdateSeeder;
use App\Services\DeploymentService;
use App\Services\InstallationService;
use App\Services\DemoInstallationService;
use App\Services\ReleaseArtifactService;
use App\Services\RuntimeProfileService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Fakes\SshDelDeploymentFake;
use Tests\TestCase;

/** Transporte simulado; las guardas y la construcción de comandos son las del pipeline real. */
class PerfilRuntimeEnElDeploymentTest extends TestCase
{
    use DatabaseTransactions;

    private $zip;

    protected function tearDown(): void
    {
        if ($this->zip && is_file($this->zip)) {
            unlink($this->zip);
        }
        parent::tearDown();
    }

    private function invoke($service, string $method, array $args = [])
    {
        $reflection = new \ReflectionMethod(DeploymentService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($service, $args);
    }

    private function set($service, string $property, $value): void
    {
        $reflection = new \ReflectionProperty(DeploymentService::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($service, $value);
    }

    private function scenario(bool $rotation = false, bool $oldRelease = false): array
    {
        $credential = ClientSshCredential::firstOrNew(['type' => 'vps']);
        $credential->forceFill(['host' => '127.0.0.1', 'port' => 1, 'username' => 'nadie', 'password' => 'falsa'])->save();
        $version = new Version();
        $version->forceFill(['version' => '97.' . random_int(1000, 9999) . '.1', 'title' => 'Perfil', 'status' => 'published', 'published_at' => now()])->save();
        $client = new Client();
        $client->forceFill(['name' => 'Perfil', 'company_name' => 'Perfil', 'slug' => 'perfil-' . Str::lower(Str::random(8)),
            'api_url' => 'https://ejemplo.test', 'api_key' => 'falsa', 'inbound_api_key' => 'falsa',
            'is_active' => true, 'current_version_id' => $version->id, 'user_id' => random_int(20000, 29000)])->save();
        $apis = [];
        foreach (['perfil', 'perfil2'] as $slug) {
            $api = new ClientApi();
            $api->forceFill(['client_id' => $client->id, 'url' => 'https://api-' . $slug . '.comerciocity.com',
                'path' => $slug . '/api', 'spa_url' => 'https://' . $slug . '.comerciocity.com',
                'hosting_type' => 'vps', 'vps_path' => $slug])->save();
            $apis[] = $api;
        }
        $client->active_client_api_id = $apis[$rotation ? 1 : 0]->id;
        $client->save();
        $upgrade = ClientVersionUpgrade::create(['client_id' => $client->id, 'from_version_id' => $version->id,
            'to_version_id' => $version->id, 'status' => 'pendiente', 'target_client_api_id' => $apis[0]->id]);

        $ssh = new SshDelDeploymentFake();
        $service = new class($upgrade) extends DeploymentService {
            public $fake;
            protected function abrir_sesion_ssh(ClientSshCredential $credential)
            {
                return $this->fake;
            }
        };
        $service->fake = $ssh;
        $service->connect();
        $ssh->responder('get_loaded_extensions', '{"php":"8.4.27","extensions":["json","fileinfo","mbstring","openssl","gd","ctype","tokenizer","dom","filter","libxml","xmlreader","zip","iconv","simplexml","xml","xmlwriter","zlib","curl","pcre"]}');
        $ssh->responder('--version --no-ansi', 'Composer version 2.8.12');
        $ssh->responder('check-platform-reqs', 'CC_RUNTIME_OK');

        $this->zip = tempnam(storage_path('app'), 'perfil-test-');
        $zip = new \ZipArchive();
        $zip->open($this->zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('artisan', '<?php');
        $real = new \ZipArchive();
        $real->open(__DIR__ . '/../Fixtures/runtime-vps-real.zip');
        foreach (['composer.json', 'composer.lock', 'composer.vps.json', 'composer.vps.lock'] as $name) {
            if (! $oldRelease || strpos($name, '.vps.') === false) {
                $zip->addFromString($name, $real->getFromName($name));
            }
        }
        $real->close();
        $zip->close();
        $release = new class($this->zip) extends ReleaseArtifactService {
            private $fixture;
            public function __construct($fixture) { $this->fixture = $fixture; }
            public function find_asset(string $repo, string $tag, string $name): ?array { return ['name' => $name]; }
            public function download_asset(array $asset, string $path): int
            {
                copy($this->fixture, $path);
                return filesize($path);
            }
        };
        $this->set($service, 'release_artifacts', $release);
        return [$service, $ssh, $upgrade];
    }

    public function test_rotacion_rechaza_release_viejo_antes_de_cualquier_upload(): void
    {
        list($service, $ssh) = $this->scenario(true, true);
        $ssh->responder('.comerciocity-runtime.json', '{"schema":1,"profile":"vps-php84-v1"}');
        try {
            $this->invoke($service, 'step_upload_spa');
            $this->fail('Un release sin perfil no puede llegar a subir SPA.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('composer.vps.json', $e->getMessage());
        }
        $this->assertCount(2, $ssh->comandos);
        $this->assertStringNotContainsString('find .', $ssh->todo());
        $this->assertStringNotContainsString('unzip', $ssh->todo());
        $this->assertStringNotContainsString('artisan down', $ssh->todo());
    }

    public function test_rotacion_rechaza_marcador_distinto_del_otro_frente(): void
    {
        list($service, $ssh) = $this->scenario(true);
        $ssh->responder('/home/api-perfil/.comerciocity-runtime.json', '{"schema":1,"profile":"vps-php84-v1"}');
        $this->expectExceptionMessage('perfiles runtime distintos');
        $this->invoke($service, 'step_upload_spa');
    }

    public function test_preflight_valido_composer_y_artisan_dentro_del_sudo(): void
    {
        list($service, $ssh) = $this->scenario();
        $ssh->responder('.comerciocity-runtime.json', '{"schema":1,"profile":"vps-php84-v1"}');
        $this->invoke($service, 'runtime_preflight', ['runtime']);
        $this->assertNotNull($ssh->posicion_de('check-platform-reqs'));
        $command = $this->invoke($service, 'como_usuario_del_sitio', ["USER_ID=2700 php artisan ejemplo --texto='con espacios' && php artisan otro"]);
        $this->assertStringStartsWith("sudo -H -u 'api-perfil' /bin/bash -c 'export COMPOSER=composer.vps.json;", $command);
        $this->assertStringContainsString('/usr/bin/php8.4', $command);
        $this->assertStringContainsString('composer() { /usr/bin/php8.4 /usr/local/bin/composer', $command);
        $this->assertStringContainsString('USER_ID=2700 php artisan ejemplo', $command);
        $this->assertStringContainsString('con espacios', $command);
        $composer = $this->invoke($service, 'build_composer_install_command', ['/home/api-perfil/empresa-api', false]);
        $this->assertStringContainsString('/usr/bin/php8.4 /usr/local/bin/composer install', $composer);
        $this->assertStringNotContainsString('--no-scripts', $composer);
    }

    public function test_reanudacion_vuelve_a_consultar_marcador_antes_de_seeders(): void
    {
        list($service, $ssh) = $this->scenario();
        $this->set($service, 'runtime_preflight_done', true);
        $ssh->responder('.comerciocity-runtime.json', '{"schema":1,"profile":"desconocido"}');
        try {
            $service->run('run_seeders');
            $this->fail('La reanudación debe volver a validar el marcador.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Marcador runtime inválido', $e->getMessage());
        }
        $this->assertNotNull($ssh->posicion_de('.comerciocity-runtime.json'));
        $this->assertStringNotContainsString('db:seed', $ssh->todo());
    }

    public function publishedItems(): array
    {
        return [[false], [true]];
    }

    /** @dataProvider publishedItems */
    public function test_comando_o_seeder_no_soportado_frena_antes_de_subir_spa(bool $seeder): void
    {
        list($service, $ssh, $upgrade) = $this->scenario(true);
        if ($seeder) {
            $command = VersionSeeder::create(['version_id' => $upgrade->from_version_id,
                'seeder_class' => 'Clase$fuera_del_contrato', 'execution_order' => 1]);
            UpdateSeeder::create(['client_version_upgrade_id' => $upgrade->id,
                'version_seeder_id' => $command->id, 'status' => 'pendiente']);
        } else {
            $command = VersionCommand::create(['version_id' => $upgrade->from_version_id,
                'command' => 'COMPOSER=composer.json composer install', 'execution_order' => 1]);
            UpdateCommand::create(['client_version_upgrade_id' => $upgrade->id,
                'version_command_id' => $command->id, 'status' => 'pendiente']);
        }
        $ssh->responder('.comerciocity-runtime.json', '{"schema":1,"profile":"vps-php84-v1"}');
        try {
            $this->invoke($service, 'step_upload_spa');
            $this->fail('Un comando que cambia el intérprete o Composer debe frenar en preflight.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Comando publicado no soportado', $e->getMessage());
        }
        $this->assertCount(2, $ssh->comandos, 'Sólo se leen los dos marcadores.');
        $this->assertStringNotContainsString('unzip', $ssh->todo());
        $this->assertStringNotContainsString('artisan down', $ssh->todo());
    }

    public function installationSteps(): array
    {
        return [[InstallationService::class, 'write_env'], [InstallationService::class, 'finalize_api'],
            [DemoInstallationService::class, 'finalize_api'], [DemoInstallationService::class, 'run_demo_setup']];
    }

    /** @dataProvider installationSteps */
    public function test_instalacion_recortada_no_salta_preflight(string $class, string $step): void
    {
        list($unused, $ssh, $upgrade) = $this->scenario();
        if ($class === InstallationService::class) {
            $installation = ClientInstallation::create(['client_id' => $upgrade->client_id,
                'client_api_id' => $upgrade->target_client_api_id, 'version_id' => $upgrade->to_version_id]);
        } else {
            $demo = Demo::create(['user_id' => 4242, 'erp_hosting_type' => 'vps', 'erp_vps_path' => 'perfil',
                'erp_spa_url' => 'https://perfil.comerciocity.com', 'erp_api_url' => 'https://api-perfil.comerciocity.com',
                'ecommerce_spa_url' => 'https://tienda-perfil.comerciocity.com',
                'ecommerce_api_url' => 'https://api-tienda-perfil.comerciocity.com']);
            $installation = DemoInstallation::create(['demo_id' => $demo->id, 'version_id' => $upgrade->to_version_id]);
        }
        $service = new $class($installation);
        foreach (['ssh' => $ssh, 'steps' => [$step]] as $name => $value) {
            $property = new \ReflectionProperty($class, $name);
            $property->setAccessible(true);
            $property->setValue($service, $value);
        }
        $ssh->responder('.comerciocity-runtime.json', '{"schema":1,"profile":"desconocido"}');
        $method = new \ReflectionMethod($class, 'execute_steps');
        $method->setAccessible(true);
        try {
            $method->invoke($service);
            $this->fail('Una etapa aislada no puede saltar la validación del perfil.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Marcador runtime inválido', $e->getMessage());
        }
        $this->assertCount(1, $ssh->comandos);
        $this->assertStringNotContainsString('artisan', $ssh->todo());
        $this->assertStringNotContainsString(' > ', $ssh->todo());
    }
}
