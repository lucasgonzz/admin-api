<?php

namespace Tests\Feature;

use App\Jobs\RunEcommerceInstallationJob;
use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientEcommerce;
use App\Models\ClientEcommerceInstallation;
use App\Models\ClientSshCredential;
use App\Models\Demo;
use App\Models\EnvTemplate;
use App\Services\EcommerceInstallationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Tests\TestCase;

/**
 * El dueño de una tienda (`client_ecommerces`) pasó a ser polimórfico el 31/8/2026: un `Client`
 * o una `Demo`, exactamente uno de los dos. Antes, para instalar o actualizar el ecommerce de una
 * demo había que crear un cliente falso llamado "demo".
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 NO-REGRESIÓN: con dueño `Client`, los cuatro datos que el pipeline le pedía al cliente
 *     siguen saliendo del cliente y valiendo lo mismo. Son los ~40 clientes en producción y no
 *     hay suite de regresión que los cubra.
 *  2. Que una fila sin dueño, o con los dos, no se pueda guardar — porque una fila así no rompe
 *     nada visible: rompe adentro del pipeline, a mitad de un deploy.
 *  3. Que los endpoints de arranque acepten `demo_id` y dejen la tienda de la demo bien armada.
 *  4. Que una demo marcada como VPS se rechace con el mensaje acordado y no se despliegue a medias.
 */
class DuenoDelEcommerceEsClienteODemoTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Admin logueado por Sanctum: las rutas de ecommerce viven bajo auth:sanctum.
     *
     * @return Admin
     */
    private function admin_logueado(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de tiendas de demo';
        $admin->email    = 'tienda-demo-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Cliente con los cuatro datos que el pipeline de ecommerce le pide.
     *
     * @return Client
     */
    private function crear_cliente(): Client
    {
        $client                  = new Client();
        $client->name            = 'Comercio Ejemplo';
        $client->company_name    = 'Comercio Ejemplo SRL';
        $client->slug            = 'comercio-ejemplo-' . Str::random(8);
        $client->api_url         = 'https://api-comercio-ejemplo.test';
        $client->api_key         = 'clave-api-del-cliente';
        $client->inbound_api_key = 'clave-inbound';
        $client->user_id         = 4242;
        $client->is_active       = true;
        $client->save();

        return $client;
    }

    /**
     * Demo del catálogo, con las URLs del ERP y del ecommerce cargadas.
     *
     * @param  array<string, mixed>  $atributos  Pisa cualquiera de los valores por defecto.
     * @return Demo
     */
    private function crear_demo(array $atributos = []): Demo
    {
        $sufijo = strtolower(Str::random(6));

        $demo = new Demo();
        $demo->fill(array_merge([
            'nombre'            => 'Tienda de la Demo',
            'user_id'           => 7777,
            'api_key'           => 'clave-api-de-la-demo',
            'erp_spa_url'       => 'https://demo' . $sufijo . '.comerciocity.com',
            'erp_api_url'       => 'https://api-demo' . $sufijo . '.comerciocity.com',
            'ecommerce_spa_url' => 'https://tienda-demo' . $sufijo . '.comerciocity.com',
            'ecommerce_api_url' => 'https://tienda-demo' . $sufijo . '.comerciocity.com/api',
        ], $atributos));
        $demo->save();

        return $demo;
    }

    /**
     * Deja el entorno de deploy listo: credenciales SSH globales y plantilla de .env de tienda.
     *
     * Sin esto, `assert_deploy_prerequisites()` corta con un 422 antes de llegar a lo que se
     * quiere probar.
     *
     * @return void
     */
    private function cargar_entorno_de_deploy(): void
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

        if (! EnvTemplate::where('scope', 'tienda')->exists()) {
            EnvTemplate::create([
                'key'   => 'APP_ENV',
                'value' => 'production',
                'scope' => 'tienda',
            ]);
        }
    }

    /**
     * Llama un método protegido del servicio (los `owner_*` viven adentro del pipeline).
     *
     * @param  EcommerceInstallationService  $servicio
     * @param  string  $metodo
     * @return mixed
     */
    private function llamar_protegido(EcommerceInstallationService $servicio, string $metodo)
    {
        $reflexion = new ReflectionMethod(EcommerceInstallationService::class, $metodo);
        $reflexion->setAccessible(true);

        return $reflexion->invoke($servicio);
    }

    /**
     * Instancia el servicio de instalación sobre una corrida nueva de esa tienda.
     *
     * @param  ClientEcommerce  $tienda
     * @return EcommerceInstallationService
     */
    private function servicio_de(ClientEcommerce $tienda): EcommerceInstallationService
    {
        $corrida = ClientEcommerceInstallation::create([
            'client_ecommerce_id' => $tienda->id,
            'mode'                => 'install',
            'status'              => 'pendiente',
        ]);

        return new EcommerceInstallationService($corrida);
    }

    /**
     * Una tienda que pertenece a una demo se guarda con `client_id` en null.
     *
     * @return void
     */
    public function test_una_tienda_puede_pertenecer_a_una_demo(): void
    {
        $demo = $this->crear_demo();

        $tienda = ClientEcommerce::create([
            'client_id' => null,
            'demo_id'   => $demo->id,
            'spa_url'   => $demo->ecommerce_spa_url,
            'api_url'   => $demo->ecommerce_api_url,
            'status'    => 'pending',
        ]);

        $guardada = ClientEcommerce::find($tienda->id);

        $this->assertNull($guardada->client_id);
        $this->assertSame($demo->id, $guardada->demo_id);
        $this->assertTrue($guardada->is_demo());
        $this->assertInstanceOf(Demo::class, $guardada->owner());
        $this->assertSame($demo->id, $guardada->owner()->id);
    }

    /**
     * 🔴 Una tienda con los DOS dueños cargados no se guarda: el pipeline resuelve el nombre, el
     * id de comercio y la api key según el dueño, y con los dos cargados desplegaría los datos de
     * uno sobre la tienda del otro.
     *
     * @return void
     */
    public function test_una_tienda_con_los_dos_duenos_no_se_guarda(): void
    {
        $cliente = $this->crear_cliente();
        $demo    = $this->crear_demo();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no puede pertenecer a un cliente y a una demo a la vez');

        ClientEcommerce::create([
            'client_id' => $cliente->id,
            'demo_id'   => $demo->id,
            'spa_url'   => 'https://tienda.test',
            'api_url'   => 'https://tienda.test/api',
            'status'    => 'pending',
        ]);
    }

    /**
     * 🔴 Y una tienda sin ningún dueño tampoco: es la fila que no rompe nada hasta que el pipeline
     * la agarra a mitad de un deploy.
     *
     * @return void
     */
    public function test_una_tienda_sin_dueno_no_se_guarda(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('tiene que pertenecer a un cliente o a una demo');

        ClientEcommerce::create([
            'spa_url' => 'https://tienda-huerfana.test',
            'api_url' => 'https://tienda-huerfana.test/api',
            'status'  => 'pending',
        ]);
    }

    /**
     * 🔴 NO-REGRESIÓN. Con dueño cliente, los cuatro datos siguen saliendo del cliente y valen
     * exactamente lo que valían antes de la polimorfización.
     *
     * @return void
     */
    public function test_con_dueno_cliente_todo_sigue_saliendo_del_cliente(): void
    {
        $cliente = $this->crear_cliente();

        $tienda = ClientEcommerce::create([
            'client_id' => $cliente->id,
            'spa_url'   => 'https://comercio-ejemplo.com.ar',
            'api_url'   => 'https://comercio-ejemplo.com.ar/api',
            'status'    => 'pending',
        ]);

        $servicio = $this->servicio_de($tienda);

        $this->assertFalse($tienda->is_demo());
        $this->assertSame('Comercio Ejemplo SRL', $this->llamar_protegido($servicio, 'owner_display_name'));
        $this->assertSame(4242, $this->llamar_protegido($servicio, 'owner_commerce_id'));
        $this->assertSame('clave-api-del-cliente', $this->llamar_protegido($servicio, 'owner_api_key'));
        $this->assertSame(
            'https://api-comercio-ejemplo.test/api/admin-sync/branding',
            $this->llamar_protegido($servicio, 'owner_empresa_branding_url')
        );

        // Y los paths se siguen derivando del dominio, sin pasar por DemoPathResolver.
        $this->assertSame('comercio-ejemplo.com.ar/public_html', $tienda->resolve_spa_path());
        $this->assertSame('comercio-ejemplo.com.ar/public_html/api', $tienda->resolve_api_path());
    }

    /**
     * Con dueño demo, los mismos cuatro datos salen de la demo.
     *
     * @return void
     */
    public function test_con_dueno_demo_todo_sale_de_la_demo(): void
    {
        $demo = $this->crear_demo();

        $tienda = ClientEcommerce::create([
            'demo_id' => $demo->id,
            'spa_url' => $demo->ecommerce_spa_url,
            'api_url' => $demo->ecommerce_api_url,
            'status'  => 'pending',
        ]);

        $servicio = $this->servicio_de($tienda);

        $this->assertSame('Tienda de la Demo', $this->llamar_protegido($servicio, 'owner_display_name'));
        $this->assertSame(7777, $this->llamar_protegido($servicio, 'owner_commerce_id'));
        $this->assertSame('clave-api-de-la-demo', $this->llamar_protegido($servicio, 'owner_api_key'));

        // La URL de branding sale de erp_api_url normalizada con el hosting de la demo: en hosting
        // compartido la API se entra por /public.
        $this->assertSame(
            rtrim($demo->erp_api_url, '/') . '/public/api/admin-sync/branding',
            $this->llamar_protegido($servicio, 'owner_empresa_branding_url')
        );

        // Y los paths de instalación salen de DemoPathResolver, sobre el dominio del ecommerce.
        $dominio = 'tienda-' . parse_url($demo->erp_spa_url, PHP_URL_HOST);
        $this->assertSame($dominio . '/public_html', $tienda->resolve_spa_path());
        $this->assertSame($dominio . '/public_html/api', $tienda->resolve_api_path());
        $this->assertSame('api', $tienda->api_subpath_inside_spa_docroot());
    }

    /**
     * Una demo sin «Nombre del comercio» cae al slug de su ERP en vez de quedar vacía: ese valor
     * termina en el APP_NAME del .env, en el manifest de la PWA y en la etiqueta og:site_name.
     *
     * @return void
     */
    public function test_una_demo_sin_nombre_cae_al_slug_de_su_erp(): void
    {
        $demo = $this->crear_demo(['nombre' => null]);

        $tienda = ClientEcommerce::create([
            'demo_id' => $demo->id,
            'spa_url' => $demo->ecommerce_spa_url,
            'api_url' => $demo->ecommerce_api_url,
            'status'  => 'pending',
        ]);

        $slug_esperado = explode('.', parse_url($demo->erp_spa_url, PHP_URL_HOST))[0];

        $this->assertSame($slug_esperado, $this->llamar_protegido($this->servicio_de($tienda), 'owner_display_name'));
    }

    /**
     * Una demo sin «ID de comercio (USER_ID)» no compila: la tienda quedaría publicada pidiéndole
     * la configuración a un comercio que no existe, o sea en blanco y sin ningún error. El mensaje
     * tiene que decir dónde cargarlo.
     *
     * @return void
     */
    public function test_una_demo_sin_id_de_comercio_frena_con_un_mensaje_que_dice_donde_cargarlo(): void
    {
        $demo = $this->crear_demo(['user_id' => null]);

        $tienda = ClientEcommerce::create([
            'demo_id' => $demo->id,
            'spa_url' => $demo->ecommerce_spa_url,
            'api_url' => $demo->ecommerce_api_url,
            'status'  => 'pending',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('módulo de Demos');

        $this->llamar_protegido($this->servicio_de($tienda), 'assert_owner_commerce_id');
    }

    /**
     * POST /ecommerce-installations/start-install con `demo_id`: crea la tienda de la demo con
     * `client_id` en null, copia las URLs del catálogo y encola la corrida en modo instalación.
     *
     * @return void
     */
    public function test_start_install_con_demo_id_crea_la_tienda_de_la_demo_y_encola_la_corrida(): void
    {
        Queue::fake();
        $this->admin_logueado();
        $this->cargar_entorno_de_deploy();

        $demo = $this->crear_demo();

        $respuesta = $this->postJson('/api/admin/ecommerce-installations/start-install', [
            'demo_id' => $demo->id,
        ]);

        $respuesta->assertStatus(201);

        $tienda = ClientEcommerce::where('demo_id', $demo->id)->first();
        $this->assertNotNull($tienda, 'Tendría que haberse creado la tienda de la demo.');
        $this->assertNull($tienda->client_id);
        $this->assertSame($demo->ecommerce_spa_url, $tienda->spa_url);
        $this->assertSame($demo->ecommerce_api_url, $tienda->api_url);

        $corrida = ClientEcommerceInstallation::where('client_ecommerce_id', $tienda->id)->first();
        $this->assertNotNull($corrida);
        $this->assertSame('install', $corrida->mode);
        $this->assertSame('pendiente', $corrida->status);

        Queue::assertPushed(RunEcommerceInstallationJob::class);
    }

    /**
     * POST /ecommerce-installations/start-update con `demo_id`: reutiliza la tienda que ya existe
     * y refresca sus URLs desde el catálogo (una demo se recicla y apunta a otro subdominio).
     *
     * @return void
     */
    public function test_start_update_con_demo_id_reutiliza_la_tienda_y_refresca_las_urls(): void
    {
        Queue::fake();
        $this->admin_logueado();
        $this->cargar_entorno_de_deploy();

        $demo = $this->crear_demo();

        $tienda_previa = ClientEcommerce::create([
            'demo_id' => $demo->id,
            'spa_url' => 'https://url-vieja.comerciocity.com',
            'api_url' => 'https://url-vieja.comerciocity.com/api',
            'status'  => 'active',
        ]);

        $respuesta = $this->postJson('/api/admin/ecommerce-installations/start-update', [
            'demo_id' => $demo->id,
        ]);

        $respuesta->assertStatus(201);

        $this->assertSame(1, ClientEcommerce::where('demo_id', $demo->id)->count());

        $tienda_previa->refresh();
        $this->assertSame($demo->ecommerce_spa_url, $tienda_previa->spa_url);

        $corrida = ClientEcommerceInstallation::where('client_ecommerce_id', $tienda_previa->id)->first();
        $this->assertNotNull($corrida);
        $this->assertSame('update', $corrida->mode);

        Queue::assertPushed(RunEcommerceInstallationJob::class);
    }

    /**
     * Sin «Ecommerce SPA URL» no se arranca nada, y el mensaje manda al módulo de Demos (no al
     * perfil de un cliente, que para una demo no existe).
     *
     * @return void
     */
    public function test_una_demo_sin_url_de_ecommerce_no_arranca(): void
    {
        Queue::fake();
        $this->admin_logueado();
        $this->cargar_entorno_de_deploy();

        $demo = $this->crear_demo(['ecommerce_spa_url' => '']);

        $respuesta = $this->postJson('/api/admin/ecommerce-installations/start-install', [
            'demo_id' => $demo->id,
        ]);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('Ecommerce SPA URL', $respuesta->json('error'));
        $this->assertStringContainsString('módulo de Demos', $respuesta->json('error'));

        $this->assertSame(0, ClientEcommerce::where('demo_id', $demo->id)->count());
        Queue::assertNothingPushed();
    }

    /**
     * 🔴 Una demo con el ecommerce marcado como VPS se rechaza con el texto acordado, y no queda
     * ninguna corrida encolada: el pipeline solo sabe desplegar en hosting compartido y los cinco
     * puntos donde eso está fijo son compartidos con el camino de los clientes.
     *
     * @return void
     */
    public function test_una_demo_con_el_ecommerce_en_vps_se_rechaza_con_el_mensaje_acordado(): void
    {
        Queue::fake();
        $this->admin_logueado();
        $this->cargar_entorno_de_deploy();

        $demo = $this->crear_demo(['ecommerce_hosting_type' => 'vps']);

        $respuesta = $this->postJson('/api/admin/ecommerce-installations/start-install', [
            'demo_id' => $demo->id,
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame(
            'El pipeline de ecommerce todavía solo sabe desplegar en hosting compartido. '
            . 'Esta demo tiene su ecommerce marcado como VPS.',
            $respuesta->json('error')
        );

        Queue::assertNothingPushed();
    }

    /**
     * El listado se puede filtrar por tipo de dueño, y sin el parámetro devuelve todo (que es lo
     * que sigue pidiendo la pantalla actual del panel).
     *
     * @return void
     */
    public function test_el_listado_se_filtra_por_tipo_de_dueno(): void
    {
        $this->admin_logueado();

        $cliente = $this->crear_cliente();
        $demo    = $this->crear_demo();

        $tienda_cliente = ClientEcommerce::create([
            'client_id' => $cliente->id,
            'spa_url'   => 'https://comercio-ejemplo.com.ar',
            'api_url'   => 'https://comercio-ejemplo.com.ar/api',
            'status'    => 'pending',
        ]);
        $tienda_demo = ClientEcommerce::create([
            'demo_id' => $demo->id,
            'spa_url' => $demo->ecommerce_spa_url,
            'api_url' => $demo->ecommerce_api_url,
            'status'  => 'pending',
        ]);

        $corrida_cliente = ClientEcommerceInstallation::create([
            'client_ecommerce_id' => $tienda_cliente->id,
            'mode'                => 'install',
            'status'              => 'completada',
        ]);
        $corrida_demo = ClientEcommerceInstallation::create([
            'client_ecommerce_id' => $tienda_demo->id,
            'mode'                => 'install',
            'status'              => 'completada',
        ]);

        $ids_de = function ($url) {
            return array_column($this->getJson($url)->json('models'), 'id');
        };

        $todas = $ids_de('/api/admin/ecommerce-installations');
        $this->assertContains($corrida_cliente->id, $todas);
        $this->assertContains($corrida_demo->id, $todas);

        $solo_demo = $ids_de('/api/admin/ecommerce-installations?owner=demo');
        $this->assertContains($corrida_demo->id, $solo_demo);
        $this->assertNotContains($corrida_cliente->id, $solo_demo);

        $solo_cliente = $ids_de('/api/admin/ecommerce-installations?owner=cliente');
        $this->assertContains($corrida_cliente->id, $solo_cliente);
        $this->assertNotContains($corrida_demo->id, $solo_cliente);
    }
}
