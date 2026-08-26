<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ClientSshCredential;
use App\Models\Demo;
use App\Models\DemoUpdate;
use App\Models\Lead;
use App\Models\Version;
use App\Services\DemoIngresoTokenService;
use App\Services\DemoPathResolver;
use App\Services\DemoUpdateService;
use App\Services\RunDemoSetupService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ReflectionProperty;
use Tests\TestCase;

/**
 * El catálogo de demos guarda en qué hosting vive cada demo, y ese dato decide el camino.
 *
 * Hasta el 26/8/2026 TODA demo se asumía en hosting compartido: la URL de su API se armaba siempre
 * con `/public` porque en Hostinger el subdominio apunta a la carpeta `api/`. En el VPS el docroot
 * YA es `public/`, así que el mismo sufijo da `public/public` y 404 en cada request — la trampa
 * que §2.1 del informe 20260826-plan-migracion-shared-a-vps.md documenta para los clientes.
 *
 * Lo que se prueba acá es el efecto observable de punta a punta: que el CRUD guarde el dato, y que
 * los dos servicios que le hablan a la instancia de la demo por HTTP cambien la URL según ese dato.
 * El tercer camino (el pipeline de actualización) necesita SSH real y se cubre por reflexión en
 * tests/Unit/DemoPathResolverTest.php.
 */
class DemoEnVpsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Ningún test de este archivo sale a la red.
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Admin para autenticar el CRUD con Sanctum.
     *
     * @param string $email
     *
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

    /**
     * Demo con las cuatro URLs y el hosting del ERP parametrizado.
     *
     * @param string $erp_hosting_type 'shared_hosting' | 'vps'
     *
     * @return Demo
     */
    private function crear_demo(string $erp_hosting_type): Demo
    {
        $demo = new Demo();
        $demo->uuid              = (string) Str::uuid();
        $demo->erp_spa_url       = 'https://demo3.comerciocity.com';
        $demo->erp_api_url       = 'https://api-demo3.comerciocity.com';
        $demo->ecommerce_spa_url = 'https://demo3-tienda.comerciocity.com';
        $demo->ecommerce_api_url = 'https://api-demo3-tienda.comerciocity.com';
        $demo->erp_hosting_type  = $erp_hosting_type;
        $demo->save();

        return $demo;
    }

    /**
     * Lead con la demo asignada y el turno en curso.
     *
     * @param Demo $demo
     *
     * @return Lead
     */
    private function crear_lead_con_demo(Demo $demo): Lead
    {
        $inicio = Carbon::parse('2026-08-26 10:00:00', RunDemoSetupService::TZ);
        Carbon::setTestNow($inicio->copy()->addMinutes(10));

        $lead = new Lead();
        $lead->uuid               = (string) Str::uuid();
        $lead->contact_name       = 'Lead de prueba';
        $lead->company_name       = 'Empresa de prueba';
        $lead->status             = 'demo_agendada';
        $lead->demo_id            = $demo->id;
        $lead->demo_date          = $inicio->copy()->format('Y-m-d');
        $lead->demo_start_time    = $inicio->copy()->format('H:i');
        $lead->demo_end_time      = $inicio->copy()->addMinutes(60)->format('H:i');
        $lead->demo_setup_status  = 'exitoso';
        $lead->demo_ingreso_token = 'tok-' . Str::random(20);
        $lead->save();

        return $lead->refresh();
    }

    /**
     * URL del request que pegó al endpoint indicado.
     *
     * 🔴 Filtra por endpoint y exige exactamente uno. La versión ingenua —un callback que devuelve
     * `true` siempre— se queda con el ÚLTIMO request registrado, no con el único: `assertSent()`
     * corre el callback sobre todos. Hoy da igual porque cada service hace una sola llamada, pero
     * el día que alguno sume un request más, el test asertaría sobre la URL equivocada en vez de
     * fallar por el motivo real.
     *
     * @param string $endpoint Tramo final que identifica el endpoint (ej: admin-sync/demo-token)
     *
     * @return string
     */
    private function url_del_post(string $endpoint): string
    {
        $urls = [];
        Http::assertSent(function ($request) use (&$urls, $endpoint) {
            $url = (string) $request->url();
            if (strpos($url, $endpoint) !== false) {
                $urls[] = $url;
            }

            return true;
        });

        $this->assertCount(1, $urls, 'Se esperaba exactamente un POST a ' . $endpoint);

        return $urls[0];
    }

    /**
     * 🔴 Compatibilidad: el POST de siempre, sin los campos nuevos, sigue creando la demo y la
     * deja en hosting compartido. Es el contrato que ve el módulo de Demos de admin-spa.
     *
     * @return void
     */
    public function test_crear_una_demo_sin_los_campos_nuevos_la_deja_en_hosting_compartido(): void
    {
        $admin = $this->crear_admin('demo-vps-1@test.local');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/demo', [
            'erp_spa_url'       => 'https://demo9.comerciocity.com',
            'erp_api_url'       => 'https://api-demo9.comerciocity.com',
            'ecommerce_spa_url' => 'https://demo9-tienda.comerciocity.com',
            'ecommerce_api_url' => 'https://api-demo9-tienda.comerciocity.com',
        ]);

        $response->assertStatus(201);

        $demo = Demo::find($response->json('model.id'));
        $this->assertSame('shared_hosting', $demo->erp_hosting_type);
        $this->assertSame('shared_hosting', $demo->ecommerce_hosting_type);

        /* Los vps_path quedan SIN CARGAR. Se asserta "vacío" y no "null" a propósito: el CRUD es
         * declarativo (ModelPropertiesHelper recorre properties() y usa el `value` por defecto de
         * cada campo), así que un campo de texto ausente en el request se guarda como cadena vacía.
         * Es el mismo comportamiento que tienen todas las columnas de texto opcionales del módulo;
         * lo que importa acá es que no quede un identificador inventado. */
        $this->assertSame('', trim((string) $demo->erp_vps_path));
        $this->assertSame('', trim((string) $demo->ecommerce_vps_path));

        // Y sin vps_path cargado, la demo se ubica por el slug de su SPA (fallback del resolver).
        $resolver = new DemoPathResolver();
        $this->assertSame('domains/comerciocity.com/public_html/demo9/api', $resolver->api_path($demo));
    }

    /**
     * Los cuatro campos se guardan desde el catálogo, incluidos los del ecommerce (que hoy nadie
     * consume, pero Lucas quiso el dato por sistema).
     *
     * @return void
     */
    public function test_crear_una_demo_guarda_los_cuatro_campos_de_hosting(): void
    {
        $admin = $this->crear_admin('demo-vps-2@test.local');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/demo', [
            'erp_spa_url'            => 'https://demo9.comerciocity.com',
            'erp_api_url'            => 'https://api-demo9.comerciocity.com',
            'ecommerce_spa_url'      => 'https://demo9-tienda.comerciocity.com',
            'ecommerce_api_url'      => 'https://api-demo9-tienda.comerciocity.com',
            'erp_hosting_type'       => 'vps',
            'erp_vps_path'           => 'demo9',
            'ecommerce_hosting_type' => 'vps',
            'ecommerce_vps_path'     => 'demo9-tienda',
        ]);

        $response->assertStatus(201);

        $demo = Demo::find($response->json('model.id'));
        $this->assertSame('vps', $demo->erp_hosting_type);
        $this->assertSame('demo9', $demo->erp_vps_path);
        $this->assertSame('vps', $demo->ecommerce_hosting_type);
        $this->assertSame('demo9-tienda', $demo->ecommerce_vps_path);
    }

    /**
     * 🔴 COMPATIBILIDAD HACIA ATRÁS, EL CASO QUE IMPORTA. El admin-spa de producción no conoce los
     * campos nuevos y va a seguir mandando el payload viejo por un tiempo. Una demo YA marcada
     * como VPS que recibe uno de esos PUT **no puede volver a hosting compartido**: si volviera,
     * el siguiente pipeline le subiría el código al servidor equivocado, en silencio.
     *
     * @return void
     */
    public function test_un_put_sin_los_campos_nuevos_no_devuelve_una_demo_vps_al_hosting_compartido(): void
    {
        $admin = $this->crear_admin('demo-vps-4@test.local');
        $demo  = $this->crear_demo('vps');
        $demo->erp_vps_path = 'demo3';
        $demo->save();

        // El payload de siempre: solo las cuatro URLs, como lo manda el SPA que todavía no se actualizó.
        $response = $this->actingAs($admin, 'sanctum')->putJson('/api/admin/demo/' . $demo->id, [
            'erp_spa_url'       => 'https://demo3.comerciocity.com',
            'erp_api_url'       => 'https://api-demo3.comerciocity.com',
            'ecommerce_spa_url' => 'https://demo3-tienda.comerciocity.com',
            'ecommerce_api_url' => 'https://api-demo3-tienda.comerciocity.com',
        ]);

        $response->assertStatus(200);

        $demo->refresh();
        $this->assertSame('vps', $demo->erp_hosting_type);
        $this->assertSame('demo3', $demo->erp_vps_path);
    }

    /**
     * Marcar una demo existente como VPS desde el modal de edición.
     *
     * @return void
     */
    public function test_editar_una_demo_la_puede_mover_al_vps(): void
    {
        $admin = $this->crear_admin('demo-vps-3@test.local');
        $demo  = $this->crear_demo('shared_hosting');

        $response = $this->actingAs($admin, 'sanctum')->putJson('/api/admin/demo/' . $demo->id, [
            'erp_hosting_type' => 'vps',
            'erp_vps_path'     => 'demo3',
        ]);

        $response->assertStatus(200);

        $demo->refresh();
        $this->assertSame('vps', $demo->erp_hosting_type);
        $this->assertSame('demo3', $demo->erp_vps_path);
    }

    /**
     * 🔴 EL PIPELINE, NO EL HELPER. Los tests por reflexión de DemoPathResolverTest verifican que
     * los helpers DEVUELVAN la credencial correcta; ninguno verifica que el pipeline la USE. Sin
     * este test se podían revertir las tres líneas que son todo el cambio del pipeline
     * (la credencial del constructor y los dos `open_sftp_session()`) con los 15 tests en verde.
     *
     * Acá se construye un DemoUpdateService de verdad —constructor incluido, que es el que
     * consulta `client_ssh_credentials`— y se mira con qué credencial quedó.
     *
     * @return void
     */
    public function test_el_pipeline_se_construye_con_la_credencial_del_servidor_de_la_demo(): void
    {
        $this->sembrar_credencial('shared_hosting', 'shared.test.local');
        $this->sembrar_credencial('vps', 'vps.test.local');

        /* Versión propia y no `Version::first()`: la base del slot puede estar vacía, y un test que
         * se saltea solo es peor que no tenerlo — este es justamente el que cubre el cambio del
         * pipeline. El pipeline no llega a usarla (solo se construye el service), pero la columna
         * es NOT NULL. */
        $version              = new Version();
        $version->uuid        = (string) Str::uuid();
        $version->version     = '99.99.' . random_int(100, 999);
        $version->status      = 'draft';
        $version->save();

        foreach (['shared_hosting' => 'shared.test.local', 'vps' => 'vps.test.local'] as $hosting => $host_esperado) {
            $demo = $this->crear_demo($hosting);

            $demo_update = new DemoUpdate();
            $demo_update->uuid       = (string) Str::uuid();
            $demo_update->demo_id    = $demo->id;
            $demo_update->version_id = $version->id;
            $demo_update->status     = 'pendiente';
            $demo_update->save();

            $service = new DemoUpdateService($demo_update->fresh());

            $propiedad = new ReflectionProperty(DemoUpdateService::class, 'credential');
            $propiedad->setAccessible(true);
            $credencial = $propiedad->getValue($service);

            $this->assertSame($hosting, $credencial->type);
            $this->assertSame($host_esperado, $credencial->host);
        }
    }

    /**
     * Crea (o reusa) la fila de credencial SSH de un tipo, para que el constructor la encuentre.
     *
     * @param string $type
     * @param string $host
     *
     * @return void
     */
    private function sembrar_credencial(string $type, string $host): void
    {
        $credencial = ClientSshCredential::where('type', $type)->first();
        if ($credencial === null) {
            $credencial       = new ClientSshCredential();
            $credencial->type = $type;
        }

        $credencial->host     = $host;
        $credencial->port     = 22;
        $credencial->username = 'usuario-de-prueba';
        $credencial->password = 'secreto';
        $credencial->save();
    }

    /**
     * El camino de siempre: con la demo en hosting compartido, el aviso a la instancia entra por
     * /public. Este test y el que sigue son el par que prueba que el campo cambia el camino.
     *
     * @return void
     */
    public function test_el_aviso_de_token_a_una_demo_compartida_entra_por_public(): void
    {
        $lead    = $this->crear_lead_con_demo($this->crear_demo('shared_hosting'));
        $service = new DemoIngresoTokenService();

        $service->revocar($lead);

        $this->assertSame(
            'https://api-demo3.comerciocity.com/public/api/admin-sync/demo-token',
            $this->url_del_post('admin-sync/demo-token')
        );
    }

    /**
     * 🔴 Con la demo en el VPS, el mismo aviso va sin /public. Con el sufijo daría 404 y el token
     * quedaría sin revocar en la instancia, con el admin creyendo que revocó.
     *
     * @return void
     */
    public function test_el_aviso_de_token_a_una_demo_en_vps_va_sin_public(): void
    {
        $lead    = $this->crear_lead_con_demo($this->crear_demo('vps'));
        $service = new DemoIngresoTokenService();

        $service->revocar($lead);

        $this->assertSame(
            'https://api-demo3.comerciocity.com/api/admin-sync/demo-token',
            $this->url_del_post('admin-sync/demo-token')
        );
    }

    /**
     * El demo-setup de una demo compartida sigue entrando por /public.
     *
     * @return void
     */
    public function test_el_demo_setup_de_una_demo_compartida_entra_por_public(): void
    {
        $lead    = $this->crear_lead_con_demo($this->crear_demo('shared_hosting'));
        $service = new RunDemoSetupService();

        $service->run($lead);

        $this->assertSame(
            'https://api-demo3.comerciocity.com/public/api/admin-sync/demo-setup',
            $this->url_del_post('admin-sync/demo-setup')
        );
    }

    /**
     * 🔴 Y el de una demo en el VPS va sin /public. Sin esto, una demo migrada no se podría
     * sembrar: el setup fallaría con 404 y el lead se quedaría sin demo armada.
     *
     * @return void
     */
    public function test_el_demo_setup_de_una_demo_en_vps_va_sin_public(): void
    {
        $lead    = $this->crear_lead_con_demo($this->crear_demo('vps'));
        $service = new RunDemoSetupService();

        $service->run($lead);

        $this->assertSame(
            'https://api-demo3.comerciocity.com/api/admin-sync/demo-setup',
            $this->url_del_post('admin-sync/demo-setup')
        );
    }
}
