<?php

namespace Tests\Feature\PreciosVigentes;

use App\Models\Admin;
use App\Models\AdminSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * El endpoint público de sólo lectura con los precios vigentes y los costos de Meta, que consume la
 * landing (misión embudo-whatsapp-precios, etapa E1, criterio CA3 del PLAN): `GET /api/landing/precios`.
 *
 * Es público como las demás rutas `landing/*` (sin credenciales), con cubeta propia por IP
 * (`throttle:landing-precios`, 60 por minuto) y sin la de todo /api, con `Cache-Control: public,
 * max-age=300` y CORS para `https://comerciocity.com`. Devuelve el mismo contrato que el endpoint
 * autenticado, SIN `actualizado_por` (el nombre de quien editó no sale a internet).
 *
 * El contrato de §2.6 y los valores del pedido viven en {@see PreciosVigentesSettingsTest}.
 */
class EndpointPublicoDePreciosTest extends TestCase
{
    use DatabaseTransactions;

    /** Ruta pública de la landing (PLAN §2.1). */
    private const RUTA = '/api/landing/precios';

    /** Origen de la landing que el CORS del admin ya acepta (`config/atribucion.php`). */
    private const ORIGEN_LANDING = 'https://comerciocity.com';

    /**
     * Deja la base sin la fuente guardada: la de testing puede traer un valor.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        AdminSetting::query()->where('key', PreciosVigentesSettingsTest::CLAVE)->delete();
    }

    /**
     * CA3. Sin autenticación responde 200 con el mismo contenido que CA1 menos `actualizado_por`; y
     * después de que un admin edita, el nombre del editor sigue sin salir.
     *
     * @return void
     */
    public function test_es_publico_y_no_expone_quien_lo_edito(): void
    {
        /* Sin ninguna credencial. */
        $respuesta = $this->getJson(self::RUTA);

        $respuesta->assertStatus(200);

        $contrato = $respuesta->json();

        $this->assertArrayNotHasKey('actualizado_por', $contrato, 'El endpoint público expone `actualizado_por`.');
        $this->assertArrayHasKey('actualizado_en', $contrato, 'El contrato público conserva `actualizado_en`.');

        unset($contrato['actualizado_en']);
        $this->assertEquals(PreciosVigentesSettingsTest::contrato_por_defecto(), $contrato);

        /* Un admin edita y deja su nombre sellado: el público no lo tiene que mostrar. */
        $admin = Admin::create([
            'name'     => 'Editor Secreto de Precios',
            'email'    => 'publico-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);

        $cuerpo = PreciosVigentesSettingsTest::contrato_por_defecto();
        $cuerpo['gestion']['planes'][1]['abono'] = 86000;

        $this->actingAs($admin, 'sanctum')
            ->putJson(PreciosVigentesSettingsTest::RUTA, $cuerpo)
            ->assertStatus(200);

        /* Se vuelve a pedir sin credenciales. */
        $this->app['auth']->forgetGuards();

        $publico = $this->getJson(self::RUTA);

        $publico->assertStatus(200);
        $this->assertEquals(86000, $publico->json('gestion.planes.1.abono'), 'El público no ve el precio nuevo.');
        $this->assertArrayNotHasKey('actualizado_por', $publico->json());
        $this->assertStringNotContainsString('Editor Secreto de Precios', (string) $publico->getContent());
    }

    /**
     * CA3. Cabecera `Cache-Control` con `max-age=300`, y la ruta usa la cubeta propia
     * `throttle:landing-precios` (60 por minuto por IP) en lugar de `throttle:api`.
     *
     * @return void
     */
    public function test_tiene_cache_y_cubeta_propia(): void
    {
        $respuesta = $this->getJson(self::RUTA);

        $respuesta->assertStatus(200);

        $cache_control = (string) $respuesta->headers->get('Cache-Control');
        $this->assertStringContainsString('max-age=300', $cache_control);
        $this->assertStringContainsString('public', $cache_control);

        $ruta = app('router')->getRoutes()->match(Request::create(self::RUTA, 'GET'));

        $this->assertContains('throttle:landing-precios', $ruta->gatherMiddleware(), 'La ruta no usa la cubeta propia.');
        $this->assertContains('throttle:api', $ruta->excludedMiddleware(), 'La ruta no se saca de la cubeta de todo /api.');

        /* La cubeta tiene que existir en RouteServiceProvider: un middleware que nombra una cubeta
           inexistente tira excepción en producción. */
        $cubeta = RateLimiter::limiter('landing-precios');
        $this->assertNotNull($cubeta, 'No está definida la cubeta `landing-precios`.');

        $limite = $cubeta(Request::create(self::RUTA, 'GET'));
        $this->assertSame(60, $limite->maxAttempts, 'La cubeta es de 60 por minuto.');
        $this->assertSame(60, $limite->decayMinutes * 60, 'La cubeta es por minuto.');
    }

    /**
     * CA3. Con `ADMIN_SPA_URL` configurado (así es el `.env.testing`) la landing puede leer el endpoint
     * desde su origen: el GET y el preflight devuelven `Access-Control-Allow-Origin`.
     *
     * @return void
     */
    public function test_responde_cors_a_la_landing(): void
    {
        $this->assertNotSame('', (string) env('ADMIN_SPA_URL'), 'El test necesita ADMIN_SPA_URL configurado.');
        $this->assertContains(self::ORIGEN_LANDING, (array) config('cors.allowed_origins'));

        $respuesta = $this->getJson(self::RUTA, ['Origin' => self::ORIGEN_LANDING]);

        $respuesta->assertStatus(200);
        $respuesta->assertHeader('Access-Control-Allow-Origin', self::ORIGEN_LANDING);

        $preflight = $this->call('OPTIONS', self::RUTA, [], [], [], [
            'HTTP_ORIGIN'                        => self::ORIGEN_LANDING,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $this->assertSame(self::ORIGEN_LANDING, $preflight->headers->get('Access-Control-Allow-Origin'));
    }
}
