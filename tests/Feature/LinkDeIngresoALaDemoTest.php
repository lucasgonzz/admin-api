<?php

namespace Tests\Feature;

use App\Models\Demo;
use App\Models\Lead;
use App\Services\RunDemoSetupService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El link con el que el lead entra a su demo desde la página de experiencia tiene que ser
 * navegable, cualquiera sea la forma en que esté cargada la URL de la instancia.
 *
 * Bug reportado por Lucas el 17/8/2026: el POST devolvía `empresa.local:8080/demo/ingreso?t=...`,
 * el navegador lo rechazaba por protocolo desconocido y el botón quedaba clavado en "Entrando…".
 * Como el 200 llegaba bien, no había error de HTTP: el `.catch` del SPA nunca corría.
 *
 * `demos.erp_spa_url` es texto libre —`DemoController` solo le hace trim— así que el caso sin
 * esquema no es hipotético ni exclusivo del seeder local.
 */
class LinkDeIngresoALaDemoTest extends TestCase
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
     * Demo con las cuatro URLs, parametrizando la del SPA del ERP, que es la que arma el link.
     *
     * @param string $erp_spa_url Valor crudo tal como quedaría guardado en la columna.
     *
     * @return Demo
     */
    private function crear_demo(string $erp_spa_url): Demo
    {
        $demo = new Demo();
        $demo->uuid              = (string) Str::uuid();
        $demo->erp_spa_url       = $erp_spa_url;
        $demo->erp_api_url       = 'https://demo-erp-api.comerciocity.com';
        $demo->ecommerce_spa_url = 'https://demo-tienda.comerciocity.com';
        $demo->ecommerce_api_url = 'https://demo-tienda-api.comerciocity.com';
        $demo->save();

        return $demo;
    }

    /**
     * Lead con la demo armada, el token emitido y el turno en curso: el estado exacto en el que el
     * botón "Entrar a mi demo" está habilitado.
     *
     * @param string $erp_spa_url URL de la instancia.
     *
     * @return Lead
     */
    private function crear_lead_listo_para_entrar(string $erp_spa_url): Lead
    {
        $inicio = Carbon::parse('2026-08-20 10:00:00', RunDemoSetupService::TZ);

        // El reloj queda dentro del turno: ni antes (cuenta regresiva) ni después (vencido).
        Carbon::setTestNow($inicio->copy()->addMinutes(10));

        $demo = $this->crear_demo($erp_spa_url);

        $lead = new Lead();
        $lead->uuid              = (string) Str::uuid();
        $lead->contact_name      = 'Lead de prueba';
        $lead->company_name      = 'Empresa de prueba';
        $lead->status            = 'demo_agendada';
        $lead->demo_id           = $demo->id;
        $lead->demo_date         = $inicio->copy()->format('Y-m-d');
        $lead->demo_start_time   = $inicio->copy()->format('H:i');
        $lead->demo_end_time     = $inicio->copy()->addMinutes(60)->format('H:i');
        $lead->demo_setup_status = 'exitoso';
        $lead->demo_ingreso_token = 'tok-' . Str::random(20);
        $lead->save();

        return $lead->refresh();
    }

    /**
     * El caso reportado: instancia cargada sin esquema. El link tiene que salir navegable.
     *
     * @return void
     */
    public function test_el_endpoint_de_ingreso_devuelve_una_url_navegable_sin_esquema_cargado(): void
    {
        $lead = $this->crear_lead_listo_para_entrar('empresa.local:8080');

        $response = $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/ingresar');

        $response->assertStatus(200);

        $url = (string) $response->json('url');

        // La aserción que define el bug: sin esto el navegador no navega.
        $this->assertMatchesRegularExpression(
            '/^https?:\/\//',
            $url,
            'La URL de ingreso tiene que traer esquema.'
        );

        // Y sigue siendo el mismo contrato con empresa-spa: ruta y parámetro intactos.
        $this->assertSame(
            'http://empresa.local:8080/demo/ingreso?t=' . $lead->demo_ingreso_token,
            $url
        );
    }

    /**
     * Una demo de producción cargada sin esquema —el mismo error de carga, en el módulo de Demos—
     * sale por HTTPS, no por HTTP.
     *
     * @return void
     */
    public function test_una_instancia_real_sin_esquema_sale_por_https(): void
    {
        $lead = $this->crear_lead_listo_para_entrar('demo3.comerciocity.com');

        $this->assertSame(
            'https://demo3.comerciocity.com/demo/ingreso?t=' . $lead->demo_ingreso_token,
            $lead->demo_ingreso_url
        );
    }

    /**
     * El camino que ya andaba no cambia: una URL bien cargada se usa tal cual.
     *
     * @return void
     */
    public function test_una_instancia_ya_absoluta_no_cambia(): void
    {
        $lead = $this->crear_lead_listo_para_entrar('https://demo3.comerciocity.com/');

        $this->assertSame(
            'https://demo3.comerciocity.com/demo/ingreso?t=' . $lead->demo_ingreso_token,
            $lead->demo_ingreso_url
        );
    }

    /**
     * Sin demo asignada no hay link, y el endpoint lo dice con `sin_instancia` en vez de devolver
     * una URL rota. Es el guard que ya existía y que la normalización no puede romper.
     *
     * @return void
     */
    public function test_sin_url_de_instancia_no_se_arma_ningun_link(): void
    {
        $lead = $this->crear_lead_listo_para_entrar('');

        $this->assertNull($lead->demo_ingreso_url);

        $response = $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/ingresar');

        $response->assertStatus(409);
        $response->assertJson(['motivo' => 'sin_instancia']);
    }
}
