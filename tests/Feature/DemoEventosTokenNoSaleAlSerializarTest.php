<?php

namespace Tests\Feature;

use App\Models\Lead;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El token del canal de eventos de la demo no sale al serializar el lead.
 *
 * Historia: este archivo se llamaba `DemoDinamicaActualIntactaTest` y probaba que ninguna rama de la
 * misión 48 alcanzara a un lead con `demo_experiencia = 'actual'` (plan, hitos, botón de ingreso y
 * canal de eventos). El 14/10/2026, misión demo-solo-experiencia-nueva, Lucas retiró la dinámica
 * 'actual': ya no hay ningún lead en ella ni forma de crear uno (ver DemoSoloExperienciaNuevaTest),
 * así que esos cuatro tests —los que probaban que la dinámica actual quedaba intacta— se
 * eliminaron. Lo único que no dependía de la dinámica era el `$hidden` del token, y es lo que
 * queda acá.
 */
class DemoEventosTokenNoSaleAlSerializarTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Lead de la dinámica nueva (la única vigente).
     *
     * @return Lead
     */
    private function crear_lead(): Lead
    {
        $lead                   = new Lead();
        $lead->uuid             = (string) Str::uuid();
        $lead->contact_name     = 'Juana Pérez';
        $lead->demo_experiencia = Lead::EXPERIENCIA_NUEVA;
        $lead->save();

        return $lead;
    }

    /**
     * El token no sale al serializar el modelo. `Lead` tiene `$guarded = []`, así que sin
     * `$hidden` cualquier columna nueva viaja a todo lo que serialice el lead entero: las
     * respuestas de la API, los payloads de los endpoints públicos de la demo, y cualquier
     * lugar que mañana devuelva un lead sin elegir columnas.
     *
     * ⚠️ Hasta el 2/9/2026 esta lista incluía el `broadcastWith()` de LeadSuggestionCreated, que
     * emitía el lead entero sobre un canal PÚBLICO. Ese evento ahora manda solo el `lead_id`
     * (ver LosEventosDeBroadcastMandanSoloIdsTest), así que ese sumidero puntual ya no existe —
     * pero el motivo del `$hidden` no dependía de él y sigue en pie.
     */
    public function test_el_token_de_eventos_no_sale_al_serializar_el_lead(): void
    {
        $lead                     = $this->crear_lead();
        $lead->demo_eventos_token = Str::random(64);
        $lead->save();

        $this->assertArrayNotHasKey('demo_eventos_token', $lead->toArray());
        $this->assertStringNotContainsString($lead->demo_eventos_token, $lead->toJson());

        // Pero el valor sigue disponible como atributo: es de donde lo toma el payload del setup.
        $this->assertNotEmpty($lead->demo_eventos_token);
    }
}
