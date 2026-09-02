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
 * Los dos campos que le permiten a la página de experiencia CONTAR la espera del armado.
 *
 * El problema que cierran: cuando el setup tarda más que el video de introducción (medido: 565,7 s
 * contra ~7 min), el lead termina el video, no puede entrar todavía, y hasta ahora veía un cartel
 * fijo — "en un momento se habilita el ingreso"— que no cambia nunca. Con el poleo agotándose a los
 * 20 minutos, ese cartel se podía quedar ahí para siempre.
 *
 * 🔴 Lo que estos tests protegen NO es el copy: es que los campos sean ADITIVOS. La puerta de
 * ingreso tiene que seguir siendo exactamente la de antes (`puede_ingresar` + los motivos de
 * `evaluar_ingreso()`), porque un contador es información y una puerta es una decisión, y esta
 * misión sólo tenía permitido tocar la primera.
 */
class EsperaDelSetupEnLaPaginaTest extends TestCase
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
     * @return Demo
     */
    private function crear_demo(): Demo
    {
        $demo = new Demo();
        $demo->uuid              = (string) Str::uuid();
        $demo->erp_spa_url       = 'https://demo-erp.test';
        $demo->erp_api_url       = 'https://demo-erp-api.test';
        $demo->ecommerce_spa_url = 'https://demo-tienda.test';
        $demo->ecommerce_api_url = 'https://demo-tienda-api.test';
        $demo->save();

        return $demo;
    }

    /**
     * Lead de la dinámica nueva con el turno EN CURSO, para que el único motivo posible de no
     * poder entrar sea el estado del armado.
     *
     * @param string      $estado_setup Valor de `demo_setup_status`.
     * @param Carbon|null $arrancado_en Marca de `demo_setup_last_run_at`; null = nunca arrancó.
     *
     * @return Lead
     */
    private function crear_lead(string $estado_setup, ?Carbon $arrancado_en = null): Lead
    {
        $demo   = $this->crear_demo();
        $inicio = Carbon::now()->subMinutes(5);

        $lead = new Lead();
        $lead->uuid                    = (string) Str::uuid();
        $lead->contact_name            = 'Lead de prueba';
        $lead->company_name            = 'Empresa de prueba';
        $lead->status                  = 'demo_agendada';
        $lead->demo_id                 = $demo->id;
        $lead->demo_date               = $inicio->copy()->format('Y-m-d');
        $lead->demo_start_time         = $inicio->copy()->format('H:i');
        $lead->demo_end_time           = $inicio->copy()->addMinutes(60)->format('H:i');
        $lead->demo_setup_status       = $estado_setup;
        $lead->demo_setup_last_run_at  = $arrancado_en;
        $lead->save();

        // Después del save: el hook `creating` estampa la dinámica por defecto y la pisaría.
        $lead->demo_experiencia = Lead::EXPERIENCIA_NUEVA;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Test 20 del plan: el payload trae con qué contar la espera.
     *
     * Afirma que el transcurrido lo calcula el SERVIDOR. Si lo dedujera el navegador restando su
     * propio reloj, un lead con la hora mal puesta vería un contador cualquiera.
     *
     * @return void
     */
    public function test_el_payload_dice_hace_cuanto_arranco_el_setup(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02 15:00:00'));

        $lead = $this->crear_lead('ejecutandose', Carbon::now()->copy()->subMinutes(3));

        $payload = $this->getJson('/api/demo-experiencia/' . $lead->uuid)->json();

        $this->assertSame('ejecutandose', $payload['setup']['estado']);
        $this->assertSame(180, $payload['setup']['iniciado_hace_seg']);
        $this->assertSame(
            RunDemoSetupService::DURACION_ESTIMADA_SEGUNDOS,
            $payload['setup']['duracion_estimada_seg']
        );
    }

    /**
     * Test 21 del plan: sin arranque no hay contador.
     *
     * `null` no es un borde raro: es el estado normal de un lead con la demo agendada cuyo setup
     * todavía no disparó. El front lo usa para caer al texto de siempre — un contador arrancado en
     * cero le mentiría diciendo que ya se está armando.
     *
     * @return void
     */
    public function test_sin_arranque_el_contador_viaja_en_null(): void
    {
        $lead = $this->crear_lead('pendiente', null);

        $payload = $this->getJson('/api/demo-experiencia/' . $lead->uuid)->json();

        $this->assertNull($payload['setup']['iniciado_hace_seg']);
        $this->assertSame(
            RunDemoSetupService::DURACION_ESTIMADA_SEGUNDOS,
            $payload['setup']['duracion_estimada_seg']
        );
    }

    /**
     * Test 22 del plan: los campos nuevos no movieron la puerta.
     *
     * El lead tiene el turno en curso y el armado corriendo: antes de esta misión no podía entrar,
     * y después tampoco. El 409 con motivo `preparando` es el mismo de siempre.
     *
     * @return void
     */
    public function test_los_campos_nuevos_no_cambian_puede_ingresar_ni_los_motivos(): void
    {
        $lead = $this->crear_lead('ejecutandose', Carbon::now()->copy()->subMinutes(3));

        $payload = $this->getJson('/api/demo-experiencia/' . $lead->uuid)->json();

        $this->assertFalse($payload['puede_ingresar']);

        $respuesta = $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/ingresar');

        $respuesta->assertStatus(409);
        $this->assertSame('preparando', $respuesta->json('motivo'));
    }

    /**
     * El reloj corrido hacia atrás no produce un transcurrido negativo.
     *
     * No está en el plan, pero el clamp existe en el código y un número negativo del lado del front
     * dibuja una barra vacía que se lee como rota. Un invariante sin test es una intención.
     *
     * @return void
     */
    public function test_un_arranque_en_el_futuro_no_da_un_transcurrido_negativo(): void
    {
        $lead = $this->crear_lead('ejecutandose', Carbon::now()->copy()->addMinutes(2));

        $payload = $this->getJson('/api/demo-experiencia/' . $lead->uuid)->json();

        $this->assertSame(0, $payload['setup']['iniciado_hace_seg']);
    }
}
