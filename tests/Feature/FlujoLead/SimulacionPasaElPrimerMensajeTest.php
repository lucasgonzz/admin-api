<?php

namespace Tests\Feature\FlujoLead;

use App\Http\Controllers\LeadController;
use App\Jobs\SendLeadPresentationMessageJob;
use App\Models\Lead;
use App\Services\LeadFlujoEntradaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Misión martin-link-directo, CA6: la simulación del panel (`LeadController::simulate_inbound_json`) tiene que pasarle
 * el texto simulado al onboarding, igual que el webhook real.
 *
 * Lo que pasaba (ensayo del lead 711): la simulación llamaba a `send_welcome_and_schedule_presentation($lead, $nombre)`
 * SIN el tercer argumento, así que el onboarding no veía el primer mensaje, no entraba al flujo de entrada nuevo y caía
 * en la bienvenida vieja (auto + variante A/B, que repite «¿qué rubro…?»). Por eso no se podía ensayar el flujo nuevo
 * desde el panel.
 */
class SimulacionPasaElPrimerMensajeTest extends TestCase
{
    use DatabaseTransactions;
    use ArmadoDelFlujoDeEntrada;

    /** Texto simulado del ensayo. */
    private const TEXTO_SIMULADO = 'Hola, quiero probar ComercioCity';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_flujo_de_entrada(true);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->desarmar_flujo_de_entrada();

        parent::tearDown();
    }

    /**
     * Llama al método de la simulación como lo hace la ruta `POST lead/{id}/simulate-inbound`.
     *
     * @param Lead   $lead
     * @param string $texto Texto simulado del lead.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function simular_inbound(Lead $lead, string $texto)
    {
        $request = Request::create('/api/admin/lead/' . $lead->id . '/simulate-inbound', 'POST', ['content' => $texto]);

        return app(LeadController::class)->simulate_inbound_json($request, $lead->id);
    }

    /**
     * CA6. Un lead nuevo en el flujo cuyo mensaje simulado es «Hola, quiero probar ComercioCity» recibe la presentación del
     * flujo (job en modo flujo de entrada con los dos mensajes de la llegada), sin el auto de la bienvenida vieja.
     *
     * @return void
     */
    public function test_la_simulacion_del_primer_mensaje_entra_al_flujo_de_entrada(): void
    {
        $lead = $this->crear_lead_de_flujo('nuevo');

        $respuesta = $this->simular_inbound($lead, self::TEXTO_SIMULADO);
        $this->assertSame(200, $respuesta->getStatusCode());

        $this->assertTrue(LeadFlujoEntradaService::quiere_probar_desde_el_primer_mensaje($lead->fresh()), 'Precondición: el primer mensaje dice «quiero probar».');
        $this->assertSame(
            0,
            $this->mensajes_de_sistema($lead)->count(),
            'En el flujo nuevo no sale el auto de la bienvenida vieja: el onboarding tiene que ver el primer mensaje.'
        );

        $jobs = $this->jobs_encolados(SendLeadPresentationMessageJob::class);
        $this->assertCount(1, $jobs, 'Tiene que quedar el job de la presentación.');
        $this->assertTrue($jobs->first()->es_flujo_de_entrada(), 'La presentación es la del flujo de entrada, no la variante A/B.');
        $this->assertNull($lead->fresh()->welcome_variant_id, 'El flujo nuevo no usa la variante A/B.');

        $espia = $this->espiar_whatsapp();
        $this->correr_job($jobs->first());
        $this->assertSame([$this->texto_llegada_1(), $this->texto_llegada_2()], $this->cuerpos_enviados($espia));
    }

    /**
     * CA6, segunda llamada (la «recuperación»): lead en `nuevo` con un inbound previo y sin auto que recibe un mensaje
     * simulado. También tiene que llevar el texto al onboarding.
     *
     * @return void
     */
    public function test_la_recuperacion_de_la_simulacion_tambien_pasa_el_mensaje(): void
    {
        $lead = $this->crear_lead_de_flujo('nuevo');
        $this->mensaje_del_lead($lead, 'Hola');
        $this->avanzar(30);

        $this->simular_inbound($lead, self::TEXTO_SIMULADO);

        $this->assertSame(0, $this->mensajes_de_sistema($lead)->count(), 'No sale el auto de la bienvenida vieja.');
        $jobs = $this->jobs_encolados(SendLeadPresentationMessageJob::class);
        $this->assertCount(1, $jobs);
        $this->assertTrue($jobs->first()->es_flujo_de_entrada());
    }
}
