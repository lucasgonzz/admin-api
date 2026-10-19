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
 * La instalación de un cliente nuevo NACE CERRADA a `admin-sync` (misión `cerrar-admin-sync`, 10/10/2026, tarea A5).
 *
 * Hasta ahora `InstallationService::step_write_env()` escribía el `.env` sin `ADMIN_API_INBOUND_KEY` ni las banderas de exigencia: un
 * cliente instalado nacía abierto y sin clave, y había que cerrarlo a mano después. Ahora el `.env` trae:
 *
 *   - `ADMIN_API_INBOUND_KEY` = `clients.api_key` (si el cliente no tiene, se genera de 40 caracteres y se GUARDA antes de escribir);
 *   - `ADMIN_SYNC_REQUIRE_API_KEY=true` y `ADMIN_SYNC_SETUP_REQUIRE_API_KEY=true`.
 *
 * Y la instalación de una DEMO escribe la clave SOLO si `demos.api_key` existe, y NUNCA las banderas (las demos quedan abiertas a propósito).
 *
 * Todo el SSH es un fake en memoria: ningún test abre una conexión.
 */
class LaInstalacionNaceCerradaAAdminSyncTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Demos creadas en este test, para darle a cada una su propio `user_id` (ver `variables_de_la_demo()`).
     *
     * @var int
     */
    private $demos_creadas = 0;

    /** @var EnvSshServiceFake Reemplazo en memoria del servicio SSH. */
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

    /* ------------------------------------------------------------------------------------------
     | Escenarios
     |----------------------------------------------------------------------------------------- */

    /**
     * Un cliente con sus dos frentes de shared y la instalación real + el esqueleto, como las arma el modal.
     *
     * @param string|null $api_key La clave del cliente (null = una de 20; '' / '   ' = sin clave).
     *
     * @return array{client: Client, real: ClientInstallation, esqueleto: ClientInstallation, api1: ClientApi, api2: ClientApi}
     */
    private function cliente_con_su_instalacion(?string $api_key = null): array
    {
        $sufijo = strtolower(Str::random(8));

        $client                  = new Client();
        $client->name            = 'Cliente ' . $sufijo;
        $client->slug            = 'cliente-' . $sufijo;
        $client->api_url         = 'https://api-' . $sufijo . '.comerciocity.com';
        $client->api_key         = $api_key === null ? Str::random(20) : $api_key;
        $client->inbound_api_key = Str::random(20);
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
            'client_id'     => $client->id,
            'client_api_id' => $api1->id,
            'version_id'    => $version->id,
            'kind'          => ClientInstallation::KIND_COMPLETA,
            'group_uuid'    => $grupo,
            'status'        => 'pendiente',
        ]);

        $esqueleto = ClientInstallation::create([
            'client_id'     => $client->id,
            'client_api_id' => $api2->id,
            'version_id'    => $version->id,
            'kind'          => ClientInstallation::KIND_ESQUELETO,
            'group_uuid'    => $grupo,
            'status'        => 'pendiente',
        ]);

        return ['client' => $client->fresh(), 'real' => $real, 'esqueleto' => $esqueleto, 'api1' => $api1, 'api2' => $api2];
    }

    /**
     * Corre `step_write_env()` de una instalación (es privada: se la invoca por reflexión, como hace el pipeline).
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

    /* ------------------------------------------------------------------------------------------
     | El cliente
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 La instalación real escribe la clave del cliente y las dos banderas en `true`.
     *
     * @return void
     */
    public function test_la_instalacion_real_escribe_la_clave_y_las_dos_banderas(): void
    {
        $e = $this->cliente_con_su_instalacion('clave-del-cliente-de-prueba-0123');

        $this->correr_write_env($e['real']->fresh());

        $escrito = $this->ssh->escrituras[$e['api1']->id];

        $this->assertSame('clave-del-cliente-de-prueba-0123', $escrito['ADMIN_API_INBOUND_KEY']);
        $this->assertSame('true', $escrito['ADMIN_SYNC_REQUIRE_API_KEY']);
        $this->assertSame('true', $escrito['ADMIN_SYNC_SETUP_REQUIRE_API_KEY']);

        /* Lo que ya escribía sigue escribiéndose. */
        $this->assertSame((string) $e['client']->user_id, $escrito['USER_ID']);
        $this->assertSame(rtrim($e['api1']->url, '/'), $escrito['APP_URL']);
    }

    /**
     * El esqueleto del subdominio secundario (destino virgen) también nace cerrado, con la MISMA clave: los dos frentes tienen que tenerla
     * para que una rotación no deje al cliente sin canal.
     *
     * @return void
     */
    public function test_el_esqueleto_tambien_nace_cerrado_con_la_misma_clave(): void
    {
        $e = $this->cliente_con_su_instalacion('clave-del-cliente-de-prueba-0123');

        $this->correr_write_env($e['real']->fresh());
        $this->correr_write_env($e['esqueleto']->fresh());

        $real      = $this->ssh->escrituras[$e['api1']->id];
        $esqueleto = $this->ssh->escrituras[$e['api2']->id];

        foreach (['ADMIN_API_INBOUND_KEY', 'ADMIN_SYNC_REQUIRE_API_KEY', 'ADMIN_SYNC_SETUP_REQUIRE_API_KEY'] as $variable) {
            $this->assertSame($real[$variable], $esqueleto[$variable], $variable);
        }
    }

    /**
     * El esqueleto contra un destino que YA tiene `.env` sigue sin escribir ni una clave (el cierre de admin-sync no le abre la puerta a
     * tocar un `.env` que puede estar sirviendo producción).
     *
     * @return void
     */
    public function test_el_esqueleto_sobre_un_env_existente_sigue_sin_escribir_nada(): void
    {
        $e = $this->cliente_con_su_instalacion('clave-del-cliente-de-prueba-0123');

        $original = "DB_DATABASE=la_base_de_produccion\n";

        $this->ssh->envs[$e['api2']->id] = $original;

        $this->correr_write_env($e['esqueleto']->fresh());

        $this->assertArrayNotHasKey($e['api2']->id, $this->ssh->escrituras);
        $this->assertSame($original, $this->ssh->envs[$e['api2']->id]);
    }

    /**
     * 🔴 Un cliente SIN clave (vacía o solo espacios): se genera una de 40 caracteres y se GUARDA en el admin; lo escrito en el `.env` es
     * exactamente lo guardado, y el esqueleto que viene después usa la misma (no genera otra).
     *
     * @return void
     */
    public function test_un_cliente_sin_clave_se_la_genera_y_se_la_guarda_antes_de_escribir(): void
    {
        foreach (['', '   '] as $vacia) {
            $this->ssh->escrituras = [];

            $e = $this->cliente_con_su_instalacion($vacia);

            $this->correr_write_env($e['real']->fresh());

            $guardada = (string) Client::query()->where('id', $e['client']->id)->value('api_key');
            $escrita  = $this->ssh->escrituras[$e['api1']->id]['ADMIN_API_INBOUND_KEY'];

            $this->assertSame(40, strlen($guardada), 'api_key="' . $vacia . '"');
            $this->assertSame($guardada, $escrita, 'Lo escrito en el .env es lo guardado en el admin.');
            $this->assertSame('true', $this->ssh->escrituras[$e['api1']->id]['ADMIN_SYNC_REQUIRE_API_KEY']);

            $this->correr_write_env($e['esqueleto']->fresh());

            $this->assertSame($guardada, $this->ssh->escrituras[$e['api2']->id]['ADMIN_API_INBOUND_KEY'], 'El esqueleto usa la misma clave.');
            $this->assertSame($guardada, (string) Client::query()->where('id', $e['client']->id)->value('api_key'), 'No se generó una segunda.');
        }
    }

    /**
     * El cierre no cambia el resto del `.env`: las variables de siempre siguen ahí.
     *
     * @return void
     */
    public function test_las_tres_variables_se_suman_a_las_de_siempre(): void
    {
        $e = $this->cliente_con_su_instalacion('clave-del-cliente-de-prueba-0123');

        $this->correr_write_env($e['real']->fresh());

        $claves = array_keys($this->ssh->escrituras[$e['api1']->id]);

        foreach (['APP_URL', 'SANCTUM_STATEFUL_DOMAINS', 'SANCTUM_STATEFUL_CORS', 'USER_ID', 'ADMIN_API_INBOUND_KEY', 'ADMIN_SYNC_REQUIRE_API_KEY', 'ADMIN_SYNC_SETUP_REQUIRE_API_KEY'] as $variable) {
            $this->assertContains($variable, $claves);
        }
    }

    /* ------------------------------------------------------------------------------------------
     | La demo
     |----------------------------------------------------------------------------------------- */

    /**
     * Las variables que escribiría la instalación de una demo.
     *
     * @param string|null $api_key `demos.api_key`.
     *
     * @return array<string, string>
     */
    private function variables_de_la_demo(?string $api_key): array
    {
        $demo = Demo::create([
            /* Un `user_id` por demo: `test_una_demo_sin_clave_no_recibe_nada_de_esto` arma tres en el
             * mismo test y, desde la misión pusher-canales-por-sistema (19/10/2026), el admin no
             * acepta dos demos con el mismo id de dueño. */
            'user_id'           => 4242 + $this->demos_creadas++,
            'api_key'           => $api_key,
            'erp_spa_url'       => 'https://demo-s6.comerciocity.com',
            'erp_api_url'       => 'https://api-demo-s6.comerciocity.com',
            'ecommerce_spa_url' => 'https://tienda-demo-s6.comerciocity.com',
            'ecommerce_api_url' => 'https://api-tienda-demo-s6.comerciocity.com',
        ]);

        $version = Version::create(['version' => '9.9.' . random_int(1000, 999999), 'title' => 'Versión de prueba', 'status' => 'published']);

        $instalacion = DemoInstallation::create(['demo_id' => $demo->id, 'version_id' => $version->id, 'status' => DemoInstallation::STATUS_PENDIENTE]);

        return (new DemoInstallationService($instalacion))->build_env_vars_to_write();
    }

    /**
     * Una demo CON `demos.api_key` recibe la clave en su `.env` — y NUNCA las banderas de exigencia: las demos quedan abiertas a propósito.
     *
     * @return void
     */
    public function test_una_demo_con_clave_recibe_la_clave_y_ninguna_bandera(): void
    {
        $vars = $this->variables_de_la_demo('clave-de-la-demo-de-prueba-0123');

        $this->assertSame('clave-de-la-demo-de-prueba-0123', $vars['ADMIN_API_INBOUND_KEY']);
        $this->assertArrayNotHasKey('ADMIN_SYNC_REQUIRE_API_KEY', $vars);
        $this->assertArrayNotHasKey('ADMIN_SYNC_SETUP_REQUIRE_API_KEY', $vars);
    }

    /**
     * Una demo SIN `demos.api_key` (null, vacía o solo espacios) no recibe ninguna de las tres variables: se instala exactamente como siempre.
     *
     * @return void
     */
    public function test_una_demo_sin_clave_no_recibe_nada_de_esto(): void
    {
        foreach ([null, '', '   '] as $vacia) {
            $vars = $this->variables_de_la_demo($vacia);

            $this->assertArrayNotHasKey('ADMIN_API_INBOUND_KEY', $vars);
            $this->assertArrayNotHasKey('ADMIN_SYNC_REQUIRE_API_KEY', $vars);
            $this->assertArrayNotHasKey('ADMIN_SYNC_SETUP_REQUIRE_API_KEY', $vars);
            $this->assertArrayHasKey('USER_ID', $vars);
        }
    }
}
