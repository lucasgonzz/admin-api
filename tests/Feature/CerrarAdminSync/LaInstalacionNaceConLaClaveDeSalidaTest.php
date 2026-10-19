<?php

namespace Tests\Feature\CerrarAdminSync;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientInstallation;
use App\Models\ClientSshCredential;
use App\Models\Demo;
use App\Models\DemoInstallation;
use App\Models\Version;
use App\Services\DemoInstallationService;
use App\Services\EnvSshService;
use App\Services\InstallationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Fakes\EnvSshServiceFake;
use Tests\TestCase;

/**
 * La instalación de un cliente nuevo también nace con la clave de SALIDA (`ADMIN_API_OUTBOUND_KEY` = `clients.inbound_api_key`), misión
 * `cerrar-admin-sync`, 11/10/2026.
 *
 * Con la exigencia prendida desde el nacimiento (`LaInstalacionNaceCerradaAAdminSyncTest`), `AdminReportHelper` y `SupportSyncHelper` de
 * `empresa-api` no mandan nada al admin si ese `.env` no tiene la clave de salida. El admin hoy no valida esa clave, así que escribirla es inocua.
 *
 *  - Si el cliente tiene `inbound_api_key`, es esa. Si no (vacía o solo espacios), se genera de 40 caracteres y se GUARDA antes de escribir, con un
 *    UPDATE condicional (el par real + esqueleto usa la misma).
 *  - Las demos NO la reciben (siguen sin banderas ni clave de salida).
 */
class LaInstalacionNaceConLaClaveDeSalidaTest extends TestCase
{
    use DatabaseTransactions;

    /** @var EnvSshServiceFake */
    private $ssh;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->ssh = new EnvSshServiceFake();

        $this->app->instance(EnvSshService::class, $this->ssh);

        if (ClientSshCredential::where('type', 'shared_hosting')->first() === null) {
            ClientSshCredential::create(['type' => 'shared_hosting', 'host' => '127.0.0.1', 'port' => 22, 'username' => 'test', 'password' => 'test']);
        }
    }

    /**
     * Un cliente con dos frentes y la instalación real + el esqueleto.
     *
     * @param string $inbound `clients.inbound_api_key`.
     *
     * @return array{client: Client, real: ClientInstallation, esqueleto: ClientInstallation, api1: ClientApi, api2: ClientApi}
     */
    private function cliente_con_su_instalacion(string $inbound): array
    {
        $sufijo = strtolower(Str::random(8));

        $client                  = new Client();
        $client->name            = 'Cliente ' . $sufijo;
        $client->slug            = 'cliente-' . $sufijo;
        $client->api_url         = 'https://api-' . $sufijo . '.comerciocity.com';
        $client->api_key         = 'clave-del-cliente-de-prueba-0123';
        $client->inbound_api_key = $inbound;
        $client->user_id         = random_int(500000, 900000) * 100;
        $client->save();

        $api1               = new ClientApi();
        $api1->client_id    = $client->id;
        $api1->url          = 'https://api-' . $sufijo . '.comerciocity.com';
        $api1->spa_url      = 'https://' . $sufijo . '.comerciocity.com';
        $api1->path         = $sufijo . '/api';
        $api1->hosting_type = 'shared_hosting';
        $api1->save();

        $api2               = new ClientApi();
        $api2->client_id    = $client->id;
        $api2->url          = 'https://api-' . $sufijo . '2.comerciocity.com';
        $api2->spa_url      = 'https://' . $sufijo . '2.comerciocity.com';
        $api2->path         = $sufijo . '2/api';
        $api2->hosting_type = 'shared_hosting';
        $api2->save();

        $client->active_client_api_id = $api1->id;
        $client->save();

        $version          = new Version();
        $version->version = '9.9.' . random_int(1000, 9999);
        $version->status  = 'published';
        $version->save();

        $grupo = (string) Str::uuid();

        $real = ClientInstallation::create([
            'client_id' => $client->id, 'client_api_id' => $api1->id, 'version_id' => $version->id,
            'kind' => ClientInstallation::KIND_COMPLETA, 'group_uuid' => $grupo, 'status' => 'pendiente',
        ]);

        $esqueleto = ClientInstallation::create([
            'client_id' => $client->id, 'client_api_id' => $api2->id, 'version_id' => $version->id,
            'kind' => ClientInstallation::KIND_ESQUELETO, 'group_uuid' => $grupo, 'status' => 'pendiente',
        ]);

        return ['client' => $client->fresh(), 'real' => $real, 'esqueleto' => $esqueleto, 'api1' => $api1, 'api2' => $api2];
    }

    /**
     * Corre `step_write_env()` (privada) por reflexión.
     *
     * @param ClientInstallation $installation La instalación.
     *
     * @return void
     */
    private function correr_write_env(ClientInstallation $installation): void
    {
        $service = new InstallationService($installation);
        $metodo  = new \ReflectionMethod($service, 'step_write_env');
        $metodo->setAccessible(true);
        $metodo->invoke($service);
    }

    /**
     * 🔴 El `.env` de la instalación real y el del esqueleto llevan `ADMIN_API_OUTBOUND_KEY = clients.inbound_api_key`, además de lo de antes.
     *
     * @return void
     */
    public function test_la_instalacion_y_el_esqueleto_escriben_la_clave_de_salida_del_cliente(): void
    {
        $e = $this->cliente_con_su_instalacion('inbound-del-cliente-0123456789');

        $this->correr_write_env($e['real']->fresh());
        $this->correr_write_env($e['esqueleto']->fresh());

        foreach (['api1', 'api2'] as $api) {
            $escrito = $this->ssh->escrituras[$e[$api]->id];

            $this->assertSame('inbound-del-cliente-0123456789', $escrito['ADMIN_API_OUTBOUND_KEY']);
            $this->assertSame('clave-del-cliente-de-prueba-0123', $escrito['ADMIN_API_INBOUND_KEY']);
            $this->assertSame('true', $escrito['ADMIN_SYNC_REQUIRE_API_KEY']);
            $this->assertSame('true', $escrito['ADMIN_SYNC_SETUP_REQUIRE_API_KEY']);
        }
    }

    /**
     * 🔴 Sin `inbound_api_key` (vacía o solo espacios): se genera de 40 caracteres, se GUARDA, lo escrito es lo guardado y el esqueleto usa la misma.
     *
     * @return void
     */
    public function test_sin_inbound_api_key_se_la_genera_y_se_la_guarda_antes_de_escribir(): void
    {
        foreach (['', '   '] as $vacia) {
            $this->ssh->escrituras = [];

            $e = $this->cliente_con_su_instalacion($vacia);

            $this->correr_write_env($e['real']->fresh());

            $guardada = (string) Client::query()->where('id', $e['client']->id)->value('inbound_api_key');

            $this->assertSame(40, strlen($guardada), 'inbound_api_key="' . $vacia . '"');
            $this->assertSame($guardada, $this->ssh->escrituras[$e['api1']->id]['ADMIN_API_OUTBOUND_KEY']);

            $this->correr_write_env($e['esqueleto']->fresh());

            $this->assertSame($guardada, $this->ssh->escrituras[$e['api2']->id]['ADMIN_API_OUTBOUND_KEY'], 'El esqueleto usa la misma.');
            $this->assertSame($guardada, (string) Client::query()->where('id', $e['client']->id)->value('inbound_api_key'), 'No se generó una segunda.');
            $this->assertSame('clave-del-cliente-de-prueba-0123', (string) Client::query()->where('id', $e['client']->id)->value('api_key'), 'La api_key no se toca.');
        }
    }

    /**
     * Las demos NO reciben la clave de salida: ni con `demos.api_key` ni sin ella.
     *
     * @return void
     */
    public function test_las_demos_no_reciben_la_clave_de_salida(): void
    {
        foreach (['clave-de-la-demo-de-prueba-0123', null] as $indice => $api_key) {
            $demo = Demo::create([
                /* Un `user_id` por demo: desde la misión pusher-canales-por-sistema (19/10/2026) el
                 * admin no acepta dos demos con el mismo id de dueño. */
                'user_id'           => 4242 + $indice,
                'api_key'           => $api_key,
                'erp_spa_url'       => 'https://demo-s6.comerciocity.com',
                'erp_api_url'       => 'https://api-demo-s6.comerciocity.com',
                'ecommerce_spa_url' => 'https://tienda-demo-s6.comerciocity.com',
                'ecommerce_api_url' => 'https://api-tienda-demo-s6.comerciocity.com',
            ]);

            $version = Version::create(['version' => '9.9.' . random_int(1000, 999999), 'title' => 'Versión de prueba', 'status' => 'published']);

            $instalacion = DemoInstallation::create(['demo_id' => $demo->id, 'version_id' => $version->id, 'status' => DemoInstallation::STATUS_PENDIENTE]);

            $vars = (new DemoInstallationService($instalacion))->build_env_vars_to_write();

            $this->assertArrayNotHasKey('ADMIN_API_OUTBOUND_KEY', $vars);
            $this->assertArrayNotHasKey('ADMIN_SYNC_REQUIRE_API_KEY', $vars);
        }
    }
}
