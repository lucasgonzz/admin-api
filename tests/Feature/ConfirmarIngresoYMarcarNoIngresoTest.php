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
     * Lead en demo_agendada, listo para las dos acciones. Con la dinámica nueva, que desde el
     * 14/10/2026 es la única (antes el estado de partida era la dinámica 'actual', hoy retirada:
     * lo que prueban estos tests no depende de la dinámica).
     *
     * @return Lead
     */
    private function crear_lead(): Lead
    {
        $lead                   = new Lead();
        $lead->uuid             = (string) Str::uuid();
        $lead->contact_name     = 'Lead de prueba';
        $lead->status            = 'demo_agendada';
        $lead->demo_experiencia = Lead::EXPERIENCIA_NUEVA;
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
     * confirmar_ingreso desde demo_agendada NO mueve el estado ni marca el ingreso (misión
     * flujo-lead-landing-closer, 17/10/2026, decisión de Lucas): lo que el lead dice se le cree, pero
     * `demo_en_curso` y `demo_ingreso_confirmado` los escribe únicamente el evento real `demo.ingreso`
     * de la instancia. Queda el rastro en el hilo como evento de estado.
     *
     * Hasta el 17/10 este caso fijaba lo contrario (la inferencia forzaba demo_en_curso): se
     * reescribió junto con la regla. El camino completo —el dicho no mueve, el evento sí— está en
     * `tests/Feature/FlujoLead/ConfirmarIngresoSinCambioDeEstadoTest`.
     */
    public function test_confirmar_ingreso_desde_demo_agendada_no_mueve_el_estado(): void
    {
        $lead = $this->crear_lead();

        $msg = $this->service()->aplicar($lead, [
            'mensaje_sugerido' => '¡Buenísimo que ya pudiste entrar!',
            'estado_sugerido'  => 'demo_agendada',
            'confirmar_ingreso' => true,
        ]);

        $lead->refresh();
        $this->assertFalse((bool) $lead->demo_ingreso_confirmado);
        $this->assertNull($lead->demo_ingreso_confirmado_at);
        $this->assertSame('demo_agendada', (string) $lead->status);
        $this->assertNull($msg->suggested_lead_status);

        $this->assertDatabaseHas('lead_messages', [
            'lead_id'         => $lead->id,
            'sender'          => 'sistema',
            'is_status_event' => true,
            'content'         => 'El lead dice que entró a la demo; el ingreso real lo confirma la instancia.',
        ]);
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
