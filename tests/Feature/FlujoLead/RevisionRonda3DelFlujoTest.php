<?php

namespace Tests\Feature\FlujoLead;

use App\Jobs\GenerateLeadAiSuggestionJob;
use App\Jobs\SendLeadDosFormasJob;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\KnowledgeGroundingGate;
use App\Services\LeadAiService;
use App\Services\LeadSuggestionEnvioEnCurso;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Tercera ronda de E2 (DECISIONES-E2 punto 13): el camino literal del paso 2 tiene que replicar las defensas de
 * `schedule_after_lead_inbound`: renovar el token del debounce (una generación en curso se descarta al terminar), respetar la
 * guarda de envío en curso y no tocar los seguimientos. Literal o IA, nunca los dos con los mismos textos.
 */
class RevisionRonda3DelFlujoTest extends TestCase
{
    use DatabaseTransactions;
    use ArmadoDelFlujoDeEntrada;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_flujo_de_entrada(true);
        $this->sembrar_protocolo_de_leads();
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
     * Generación en curso + «dale»: el lead (con el escudo prendido y el paso 1 entregado) pregunta, arranca la generación de la
     * IA y, mientras genera, escribe «dale» (sin fila `sugerido` todavía). Sale el literal y la generación, al terminar, se
     * descarta: no queda ninguna sugerencia con las dos formas.
     *
     * @return void
     */
    public function test_una_generacion_en_curso_se_descarta_cuando_sale_el_literal(): void
    {
        [$telefono, $lead] = $this->lead_que_pregunto_el_precio();

        $trabajos = $this->jobs_encolados(GenerateLeadAiSuggestionJob::class);
        $this->assertCount(1, $trabajos, 'Precondición: la pregunta agendó la generación.');
        $generacion = $trabajos->first();

        $this->fakear_ia([$this->paquete_ia('El plan Inicial sale $55.900 por mes.', 'contactado', KnowledgeGroundingGate::TIPO_AFIRMACION, ['precios_vigentes'])]);

        /* La IA «generando»: en medio de la llamada el lead escribe «dale». */
        $prueba = $this;
        $servicio = new class extends LeadAiService {
            /** @var callable|null Lo que pasa mientras el modelo contesta. */
            public $durante;

            public function generate_suggestion(Lead $lead, bool $is_followup, ?string $intencion_forzada = null): LeadMessage
            {
                if ($this->durante !== null) {
                    call_user_func($this->durante);
                }

                return parent::generate_suggestion($lead, $is_followup, $intencion_forzada);
            }
        };
        $servicio->durante = function () use ($prueba, $telefono) {
            $prueba->avanzar(5);
            $prueba->postear_kapso($telefono, 'dale')->assertStatus(200);
        };
        $this->app->instance(LeadAiService::class, $servicio);

        $this->correr_job($generacion);

        $this->assertCount(1, $this->jobs_encolados(SendLeadDosFormasJob::class), 'Sale el literal.');
        $this->assertSame(
            0,
            LeadMessage::query()->where('lead_id', $lead->id)->where('status', 'sugerido')->count(),
            'La generación en curso se descarta al terminar: el lead no puede recibir las dos formas dos veces.'
        );
    }

    /**
     * Sugerencia en envío en curso + «dale»: no se borra y no sale el literal (sigue el camino normal).
     *
     * @return void
     */
    public function test_una_sugerencia_en_envio_no_se_borra_ni_sale_el_literal(): void
    {
        [$telefono, $lead] = $this->lead_con_paso_1_y_respuesta_pendiente();

        /* Cubre sólo una parte de lo debido (las dos formas, sin la página), para que se decida por la guarda de envío. */
        $enviandose = $this->mensaje_de_sistema($lead, 'Podés arrancar gratis, o con un pago único de $628.400.', null, 'sugerido');
        (new LeadSuggestionEnvioEnCurso())->marcar((int) $enviandose->id);

        $this->avanzar(60);
        $this->postear_kapso($telefono, 'dale')->assertStatus(200);

        $this->assertNotNull(LeadMessage::query()->find($enviandose->id), 'No se borra una sugerencia que está saliendo.');
        $this->assertCount(0, $this->jobs_encolados(SendLeadDosFormasJob::class), 'No sale el literal: sigue el camino normal.');
    }

    /**
     * Una sugerencia que cubre sólo una parte: se descarta y sale el literal (nunca los dos).
     *
     * @return void
     */
    public function test_una_sugerencia_que_cubre_una_parte_se_descarta_y_sale_el_literal(): void
    {
        [$telefono, $lead] = $this->lead_con_paso_1_y_respuesta_pendiente();

        $parcial = $this->mensaje_de_sistema($lead, 'Podés arrancar gratis, o con un pago único de $628.400.', null, 'sugerido');

        $this->avanzar(60);
        $this->postear_kapso($telefono, 'dale')->assertStatus(200);

        $this->assertNull(LeadMessage::query()->find($parcial->id), 'La sugerencia vieja se descarta.');
        $this->assertCount(1, $this->jobs_encolados(SendLeadDosFormasJob::class), 'Sale el literal.');
    }

    /**
     * Un seguimiento (`is_followup`) pendiente no se descarta, y no impide el literal.
     *
     * @return void
     */
    public function test_un_seguimiento_pendiente_no_se_descarta(): void
    {
        [$telefono, $lead] = $this->lead_con_paso_1_y_respuesta_pendiente();

        $seguimiento = $this->mensaje_de_sistema($lead, 'Hola, ¿pudiste verlo?', null, 'sugerido');
        LeadMessage::query()->whereKey($seguimiento->id)->update(['is_followup' => true]);

        $this->avanzar(60);
        $this->postear_kapso($telefono, 'dale')->assertStatus(200);

        $this->assertNotNull(LeadMessage::query()->find($seguimiento->id), 'Un seguimiento no se descarta.');
        $this->assertCount(1, $this->jobs_encolados(SendLeadDosFormasJob::class), 'El seguimiento no cuenta como cobertura: sale el literal.');
    }

    // ------------------------------------------------------------------------------------------
    // Helpers propios
    // ------------------------------------------------------------------------------------------

    /**
     * Lead `contactado` con el escudo prendido y el paso 1 entregado que pregunta «¿Cuánto sale?» por el webhook.
     *
     * @return array{0: string, 1: Lead}
     */
    private function lead_que_pregunto_el_precio(): array
    {
        $telefono = $this->telefono_al_azar();
        $lead     = $this->crear_lead_de_flujo('contactado', $telefono);
        $lead->requiere_verificacion_mensajes = true;
        $lead->save();
        $this->mensaje_del_lead($lead, 'Hola, vi el video');
        $this->dejar_entregado_el_paso_1($lead);
        $this->avanzar(120);

        $this->postear_kapso($telefono, 'Ferretería, 4000. ¿Cuánto sale?')->assertStatus(200);
        $this->avanzar(60);

        return [$telefono, $lead->fresh()];
    }

    /**
     * Lead `contactado` con el paso 1 entregado que ya contestó una vez (para que «dale» dispare el literal del paso 2).
     *
     * @return array{0: string, 1: Lead}
     */
    private function lead_con_paso_1_y_respuesta_pendiente(): array
    {
        $telefono = $this->telefono_al_azar();
        $lead     = $this->crear_lead_de_flujo('contactado', $telefono);
        $this->mensaje_del_lead($lead, 'Hola, vi el video');
        $this->dejar_entregado_el_paso_1($lead);
        $this->avanzar(60);
        $this->mensaje_del_lead($lead, 'Ferretería, unos 4000');
        $this->avanzar(30);

        return [$telefono, $lead->fresh()];
    }
}
