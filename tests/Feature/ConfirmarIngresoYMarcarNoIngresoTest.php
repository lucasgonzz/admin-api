<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadAiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Las acciones `confirmar_ingreso` y `marcar_no_ingreso` de LeadAiService, después de que
 * `ingresando_demo` se sacó del catálogo (misión demo-v2-estados-automaticos, 4/9/2026): las dos
 * ahora solo son válidas desde `demo_agendada` -- antes `confirmar_ingreso` también valía desde
 * `ingresando_demo` (estado que ya no existe) y `marcar_no_ingreso` NO era válida desde
 * `demo_agendada`.
 */
class ConfirmarIngresoYMarcarNoIngresoTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Lead de dinámica actual en demo_agendada, listo para las dos acciones.
     *
     * @return Lead
     */
    private function crear_lead(): Lead
    {
        $lead                   = new Lead();
        $lead->uuid             = (string) Str::uuid();
        $lead->contact_name     = 'Lead de prueba';
        $lead->status            = 'demo_agendada';
        $lead->demo_experiencia = Lead::EXPERIENCIA_ACTUAL;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Instancia del service con `apply_parsed_response()` expuesto: es `protected` y es justo el
     * método donde viven las dos acciones bajo prueba. Mismo patrón que
     * DemoExtendidaHastaElFinDelDiaTest::service(). La subclase no cambia ninguna lógica.
     *
     * @return LeadAiService
     */
    private function service(): LeadAiService
    {
        return new class extends LeadAiService {
            public function aplicar(Lead $lead, array $parsed): LeadMessage
            {
                return $this->apply_parsed_response($lead, $parsed, false);
            }
        };
    }

    /**
     * confirmar_ingreso sigue siendo válida desde demo_agendada: marca demo_ingreso_confirmado y
     * sugiere el pase a demo_en_curso.
     */
    public function test_confirmar_ingreso_sigue_valida_desde_demo_agendada(): void
    {
        $lead = $this->crear_lead();

        $msg = $this->service()->aplicar($lead, [
            'mensaje_sugerido' => '¡Buenísimo que ya pudiste entrar!',
            'estado_sugerido'  => 'demo_agendada',
            'confirmar_ingreso' => true,
        ]);

        $lead->refresh();
        $this->assertTrue((bool) $lead->demo_ingreso_confirmado);
        $this->assertNotNull($lead->demo_ingreso_confirmado_at);
        $this->assertSame('demo_en_curso', $msg->suggested_lead_status);
    }

    /**
     * marcar_no_ingreso ahora ES válida desde demo_agendada (antes de esta misión no lo era: solo
     * valía desde ingresando_demo) y sigue llevando a demo_pendiente_de_ingreso. Pedido explícito
     * de Lucas en la Fase 2 del plan: que la acción siga siendo alcanzable.
     */
    public function test_marcar_no_ingreso_ahora_es_valida_desde_demo_agendada(): void
    {
        $lead = $this->crear_lead();

        $msg = $this->service()->aplicar($lead, [
            'mensaje_sugerido'  => 'No te preocupes, avisame cuando puedas retomarla.',
            'estado_sugerido'   => 'demo_agendada',
            'marcar_no_ingreso' => true,
        ]);

        $this->assertSame('demo_pendiente_de_ingreso', $msg->suggested_lead_status);
    }
}
