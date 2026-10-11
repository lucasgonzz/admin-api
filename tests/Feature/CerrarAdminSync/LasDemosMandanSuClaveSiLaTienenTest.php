<?php

namespace Tests\Feature\CerrarAdminSync;

use App\Models\ClientSshCredential;
use App\Models\Demo;
use App\Models\DemoInstallation;
use App\Models\Lead;
use App\Models\Version;
use App\Services\DemoIngresoTokenService;
use App\Services\DemoInstallationService;
use App\Services\RunDemoSetupService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Fakes\HttpFactorySinSalida;
use Tests\TestCase;

/**
 * Las demos mandan `X-Admin-Api-Key` con `demos.api_key` si la tienen (misión `cerrar-admin-sync`, 10/10/2026, tarea A2).
 *
 * `demo-token` (`DemoIngresoTokenService::avisar_instancia`) salía SIEMPRE sin header, con el argumento de que la demo no tiene fila
 * de `clients`; pero la tabla `demos` tiene su propia `api_key` (nullable) desde el 31/8/2026. `demo-setup` (`RunDemoSetupService` y
 * `DemoInstallationService`) no necesita la clave —la ruta queda abierta a propósito—, y se manda por consistencia. Lo que se fija:
 *
 *  1. Con `demos.api_key` cargada, el pedido lleva el header con ese valor.
 *  2. Sin clave (null, vacía o solo espacios), el pedido sale como siempre: sin header.
 *  3. Nada más cambia: la URL (`/public` según el hosting) y el `Accept` siguen igual.
 *
 * Todo con `Http::fake()` sobre `HttpFactorySinSalida`: ningún pedido sale a internet.
 */
class LasDemosMandanSuClaveSiLaTienenTest extends TestCase
{
    use DatabaseTransactions;

    /** El nombre del header. */
    const HEADER = 'X-Admin-Api-Key';

    /** Una clave de demo de prueba. */
    const CLAVE = 'clave-de-la-demo-de-prueba-0123456789';

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------------------------------
     | Escenarios
     |----------------------------------------------------------------------------------------- */

    /**
     * Falsea la instancia de la demo anotando, de cada pedido, URL, headers y método.
     *
     * @return \stdClass Con `pedidos`: lista de `['url', 'tiene_header', 'clave', 'accept']`.
     */
    private function instancia_que_anota_los_headers(): \stdClass
    {
        $registro          = new \stdClass();
        $registro->pedidos = [];

        Http::swap(new HttpFactorySinSalida());

        Http::fake(function ($pedido) use ($registro) {
            $registro->pedidos[] = [
                'url'          => $pedido->url(),
                'tiene_header' => $pedido->hasHeader(self::HEADER),
                'clave'        => $pedido->header(self::HEADER),
                'accept'       => $pedido->header('Accept'),
            ];

            return Http::response(['ok' => true], 200);
        });

        return $registro;
    }

    /**
     * Una demo en hosting compartido, con o sin clave.
     *
     * @param string|null $api_key La clave (null = sin clave).
     *
     * @return Demo
     */
    private function crear_demo(?string $api_key): Demo
    {
        return Demo::create([
            'user_id'           => 4242,
            'api_key'           => $api_key,
            'erp_spa_url'       => 'https://demo-clave.comerciocity.com',
            'erp_api_url'       => 'https://api-demo-clave.comerciocity.com',
            'ecommerce_spa_url' => 'https://tienda-demo-clave.comerciocity.com',
            'ecommerce_api_url' => 'https://api-tienda-demo-clave.comerciocity.com',
        ]);
    }

    /**
     * Un lead con la demo asignada, en el medio de su turno y con el setup exitoso.
     *
     * @param Demo $demo La demo.
     *
     * @return Lead
     */
    private function crear_lead_con_demo(Demo $demo): Lead
    {
        $inicio = Carbon::parse('2026-08-26 10:00:00', RunDemoSetupService::TZ);
        Carbon::setTestNow($inicio->copy()->addMinutes(10));

        $lead                     = new Lead();
        $lead->uuid               = (string) Str::uuid();
        $lead->contact_name       = 'Lead de prueba';
        $lead->company_name       = 'Empresa de prueba';
        $lead->status             = 'demo_agendada';
        $lead->demo_id            = $demo->id;
        $lead->demo_date          = $inicio->copy()->format('Y-m-d');
        $lead->demo_start_time    = $inicio->copy()->format('H:i');
        $lead->demo_end_time      = $inicio->copy()->addMinutes(60)->format('H:i');
        $lead->demo_setup_status  = 'pendiente';
        $lead->demo_ingreso_token = 'tok-' . Str::random(20);
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Los valores de `api_key` que valen "sin clave".
     *
     * @return array<string, array{0: string|null}>
     */
    public function claves_vacias(): array
    {
        return [
            'null'     => [null],
            'vacía'    => [''],
            'espacios' => ['   '],
        ];
    }

    /* ------------------------------------------------------------------------------------------
     | demo-token
     |----------------------------------------------------------------------------------------- */

    /**
     * Con `demos.api_key`: el aviso de revocar el token lleva el header, a la URL de siempre.
     *
     * @return void
     */
    public function test_demo_token_con_clave_lleva_el_header(): void
    {
        $instancia = $this->instancia_que_anota_los_headers();
        $lead      = $this->crear_lead_con_demo($this->crear_demo(self::CLAVE));

        (new DemoIngresoTokenService())->revocar($lead);

        $this->assertCount(1, $instancia->pedidos);
        $this->assertSame('https://api-demo-clave.comerciocity.com/public/api/admin-sync/demo-token', $instancia->pedidos[0]['url']);
        $this->assertTrue($instancia->pedidos[0]['tiene_header']);
        $this->assertSame([self::CLAVE], $instancia->pedidos[0]['clave']);
        $this->assertSame(['application/json'], $instancia->pedidos[0]['accept']);
    }

    /**
     * Sin `demos.api_key` (null, vacía, espacios): sale como siempre, sin header, y revocar no se frena.
     *
     * @dataProvider claves_vacias
     *
     * @param string|null $vacia Valor sin clave.
     *
     * @return void
     */
    public function test_demo_token_sin_clave_sale_sin_header(?string $vacia): void
    {
        $instancia = $this->instancia_que_anota_los_headers();
        $lead      = $this->crear_lead_con_demo($this->crear_demo($vacia));

        (new DemoIngresoTokenService())->revocar($lead);

        $this->assertCount(1, $instancia->pedidos);
        $this->assertFalse($instancia->pedidos[0]['tiene_header']);
        $this->assertSame(['application/json'], $instancia->pedidos[0]['accept']);
    }

    /* ------------------------------------------------------------------------------------------
     | demo-setup (RunDemoSetupService)
     |----------------------------------------------------------------------------------------- */

    /**
     * Con `demos.api_key`: el demo-setup del lead lleva el header.
     *
     * @return void
     */
    public function test_demo_setup_del_lead_con_clave_lleva_el_header(): void
    {
        $instancia = $this->instancia_que_anota_los_headers();
        $lead      = $this->crear_lead_con_demo($this->crear_demo(self::CLAVE));

        (new RunDemoSetupService())->run($lead);

        $this->assertCount(1, $instancia->pedidos);
        $this->assertSame('https://api-demo-clave.comerciocity.com/public/api/admin-sync/demo-setup', $instancia->pedidos[0]['url']);
        $this->assertTrue($instancia->pedidos[0]['tiene_header']);
        $this->assertSame([self::CLAVE], $instancia->pedidos[0]['clave']);
    }

    /**
     * Sin `demos.api_key`: el demo-setup del lead sale como siempre, sin header.
     *
     * @dataProvider claves_vacias
     *
     * @param string|null $vacia Valor sin clave.
     *
     * @return void
     */
    public function test_demo_setup_del_lead_sin_clave_sale_sin_header(?string $vacia): void
    {
        $instancia = $this->instancia_que_anota_los_headers();
        $lead      = $this->crear_lead_con_demo($this->crear_demo($vacia));

        (new RunDemoSetupService())->run($lead);

        $this->assertCount(1, $instancia->pedidos);
        $this->assertFalse($instancia->pedidos[0]['tiene_header']);
    }

    /* ------------------------------------------------------------------------------------------
     | demo-setup (DemoInstallationService, etapa run_demo_setup)
     |----------------------------------------------------------------------------------------- */

    /**
     * Corre la etapa `run_demo_setup` de la instalación de una demo (es privada: se la invoca por reflexión, igual que hace el pipeline).
     *
     * @param Demo $demo La demo.
     *
     * @return void
     */
    private function correr_la_etapa_run_demo_setup(Demo $demo): void
    {
        if (! ClientSshCredential::where('type', 'shared_hosting')->exists()) {
            ClientSshCredential::create([
                'type'     => 'shared_hosting',
                'host'     => 'hosting.invalido.local',
                'port'     => 65002,
                'username' => 'usuario-de-prueba',
                'password' => 'no-se-usa-' . Str::random(8),
            ]);
        }

        $version = Version::create(['version' => '9.9.' . random_int(1000, 999999), 'title' => 'Versión de prueba', 'status' => 'published']);

        $instalacion = DemoInstallation::create([
            'demo_id'    => $demo->id,
            'version_id' => $version->id,
            'status'     => DemoInstallation::STATUS_PENDIENTE,
        ]);

        $servicio = new DemoInstallationService($instalacion);
        $metodo   = new \ReflectionMethod(DemoInstallationService::class, 'step_run_demo_setup');
        $metodo->setAccessible(true);
        $metodo->invoke($servicio);
    }

    /**
     * Con `demos.api_key`: la etapa de la instalación lleva el header.
     *
     * @return void
     */
    public function test_demo_setup_de_la_instalacion_con_clave_lleva_el_header(): void
    {
        $instancia = $this->instancia_que_anota_los_headers();

        $this->correr_la_etapa_run_demo_setup($this->crear_demo(self::CLAVE));

        $this->assertCount(1, $instancia->pedidos);
        $this->assertStringEndsWith('/api/admin-sync/demo-setup', $instancia->pedidos[0]['url']);
        $this->assertTrue($instancia->pedidos[0]['tiene_header']);
        $this->assertSame([self::CLAVE], $instancia->pedidos[0]['clave']);
    }

    /**
     * Sin `demos.api_key`: la etapa de la instalación sale como siempre, sin header.
     *
     * @dataProvider claves_vacias
     *
     * @param string|null $vacia Valor sin clave.
     *
     * @return void
     */
    public function test_demo_setup_de_la_instalacion_sin_clave_sale_sin_header(?string $vacia): void
    {
        $instancia = $this->instancia_que_anota_los_headers();

        $this->correr_la_etapa_run_demo_setup($this->crear_demo($vacia));

        $this->assertCount(1, $instancia->pedidos);
        $this->assertFalse($instancia->pedidos[0]['tiene_header']);
    }
}
