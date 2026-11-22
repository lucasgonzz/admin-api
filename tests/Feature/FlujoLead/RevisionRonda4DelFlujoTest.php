<?php

namespace Tests\Feature\FlujoLead;

use App\Jobs\GenerateLeadAiSuggestionJob;
use App\Jobs\SendLeadDosFormasJob;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadAiService;
use App\Services\LeadAiSuggestionScheduler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Cuarta ronda de E2 (DECISIONES-E2 punto 14): el camino literal del paso 2 NO se toma si el lead tiene una pregunta anterior
 * todavía sin contestar, ni si hay una sugerencia sin enviar que pide intervención humana. En esos casos sigue el camino normal
 * (`schedule_after_lead_inbound`), que agenda la IA.
 */
class RevisionRonda4DelFlujoTest extends TestCase
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
     * Una pregunta anterior sin contestar («¿Funciona con factura electrónica?», IA agendada) y 20 s después un mensaje sin
     * pregunta: no sale el literal y la generación vigente es la que va a contestar la pregunta (token vigente).
     *
     * @return void
     */
    public function test_una_pregunta_anterior_sin_contestar_impide_el_literal(): void
    {
        $telefono = $this->telefono_al_azar();
        $lead     = $this->crear_lead_de_flujo('contactado', $telefono);
        $this->mensaje_del_lead($lead, 'Hola, vi el video');
        $this->dejar_entregado_el_paso_1($lead);
        $this->avanzar(60);

        $this->postear_kapso($telefono, '¿Funciona con factura electrónica?')->assertStatus(200);
        $this->avanzar(20);
        $this->postear_kapso($telefono, 'Somos ferretería, unos 4000')->assertStatus(200);

        $this->assertCount(0, $this->jobs_encolados(SendLeadDosFormasJob::class), 'No sale el literal: la pregunta sigue sin contestar.');

        $trabajos = $this->jobs_encolados(GenerateLeadAiSuggestionJob::class);
        $this->assertGreaterThanOrEqual(1, $trabajos->count(), 'Queda una generación agendada.');

        $token = new ReflectionProperty(GenerateLeadAiSuggestionJob::class, 'schedule_token');
        $token->setAccessible(true);
        $this->assertTrue(
            app(LeadAiSuggestionScheduler::class)->is_schedule_token_current((int) $lead->id, (int) $token->getValue($trabajos->last())),
            'La última generación agendada tiene el token vigente.'
        );
    }

    /**
     * Una sugerencia sin enviar que pide intervención humana (p. ej. el mensaje de espera del gate) y un «dale»: no sale el
     * literal; sigue el camino normal, que agenda la IA.
     *
     * @return void
     */
    public function test_una_sugerencia_que_pide_intervencion_humana_impide_el_literal(): void
    {
        $telefono = $this->telefono_al_azar();
        $lead     = $this->crear_lead_de_flujo('contactado', $telefono);
        $this->mensaje_del_lead($lead, 'Hola, vi el video');
        $this->dejar_entregado_el_paso_1($lead);
        $this->avanzar(60);
        $this->mensaje_del_lead($lead, 'Ferretería, unos 4000');
        $this->avanzar(30);

        LeadMessage::create([
            'lead_id' => $lead->id, 'sender' => 'sistema', 'kind' => 'text', 'content' => LeadAiService::MENSAJE_DE_ESPERA_SIN_RESPALDO,
            'status' => 'sugerido', 'is_followup' => false, 'is_status_event' => false, 'requiere_verificacion' => true,
            'pending_actions' => ['requiere_intervencion_humana' => true, 'motivo_intervencion' => 'Sin respaldo documental.'],
        ]);
        $this->avanzar(30);

        $this->postear_kapso($telefono, 'dale')->assertStatus(200);

        $this->assertCount(0, $this->jobs_encolados(SendLeadDosFormasJob::class), 'No sale el literal: hay un pase a una persona pendiente.');
        $this->assertGreaterThanOrEqual(1, $this->jobs_encolados(GenerateLeadAiSuggestionJob::class)->count(), 'Sigue el camino normal: se agenda la IA.');
    }
}
