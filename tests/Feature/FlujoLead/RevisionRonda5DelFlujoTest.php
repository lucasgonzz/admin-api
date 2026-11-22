<?php

namespace Tests\Feature\FlujoLead;

use App\Jobs\GenerateLeadAiSuggestionJob;
use App\Jobs\SendLeadDosFormasJob;
use App\Jobs\SendLeadPresentationMessageJob;
use App\Models\Lead;
use App\Services\LeadAiSuggestionScheduler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Quinta ronda de E2 (DECISIONES-E2 punto 16): «el literal tapa a la IA». El camino literal del paso 2 no se toma mientras haya
 * una generación de la IA agendada o en curso con token vigente, y los salientes automáticos del flujo no cuentan como respuesta a
 * una pregunta del lead.
 */
class RevisionRonda5DelFlujoTest extends TestCase
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
     * El caso del revisor: «Hola» (paso 1 agendado), a los 5 s «¿Sirve para ferreterías?» (IA agendada), sale el paso 1 (queda
     * como último saliente, posterior a la pregunta) y a los 30 s «Ferretería, unos 4000»: no hay literal, y la IA agendada sigue
     * con el token vigente (es la que contesta la pregunta).
     *
     * @return void
     */
    public function test_los_literales_del_paso_1_no_cuentan_como_respuesta_a_la_pregunta(): void
    {
        $telefono = $this->telefono_al_azar();
        $this->postear_kapso($telefono, 'Hola')->assertStatus(200);
        $lead = Lead::query()->where('phone', $telefono)->firstOrFail();

        $this->avanzar(5);
        $this->postear_kapso($telefono, '¿Sirve para ferreterías?')->assertStatus(200);

        $paso_1 = $this->jobs_encolados(SendLeadPresentationMessageJob::class)->first();
        $this->assertNotNull($paso_1, 'Precondición: el paso 1 quedó agendado.');
        $this->avanzar(5);
        $this->espiar_whatsapp();
        $this->correr_job($paso_1);

        $this->avanzar(20);
        $this->postear_kapso($telefono, 'Ferretería, unos 4000')->assertStatus(200);

        $this->assertCount(0, $this->jobs_encolados(SendLeadDosFormasJob::class), 'No sale el literal: la pregunta sigue sin contestar.');
        $this->assertTrue($this->la_ultima_generacion_agendada_esta_vigente($lead), 'La IA agendada tiene el token vigente.');
    }

    /**
     * Con una generación agendada (aunque ningún mensaje sea una pregunta) y un mensaje sin pregunta: no hay literal; sigue la IA.
     *
     * @return void
     */
    public function test_con_una_generacion_agendada_no_sale_el_literal(): void
    {
        $telefono = $this->telefono_al_azar();
        $lead     = $this->crear_lead_de_flujo('contactado', $telefono);
        $this->mensaje_del_lead($lead, 'Hola, vi el video');
        $this->dejar_entregado_el_paso_1($lead);
        $this->avanzar(30);
        $this->mensaje_del_lead($lead, 'Me interesa');
        (new LeadAiSuggestionScheduler())->schedule_after_lead_inbound((int) $lead->id);
        $this->assertCount(1, $this->jobs_encolados(GenerateLeadAiSuggestionJob::class), 'Precondición: hay una generación agendada.');

        $this->avanzar(20);
        $this->postear_kapso($telefono, 'Somos ferretería, unos 4000')->assertStatus(200);

        $this->assertCount(0, $this->jobs_encolados(SendLeadDosFormasJob::class), 'No sale el literal con una generación agendada.');
        $this->assertTrue($this->la_ultima_generacion_agendada_esta_vigente($lead), 'Queda una generación con el token vigente.');
    }

    /**
     * ¿La última generación agendada del lead tiene el token vigente?
     *
     * @param Lead $lead
     *
     * @return bool
     */
    private function la_ultima_generacion_agendada_esta_vigente(Lead $lead): bool
    {
        $trabajos = $this->jobs_encolados(GenerateLeadAiSuggestionJob::class);
        if ($trabajos->isEmpty()) {
            return false;
        }

        $token = new ReflectionProperty(GenerateLeadAiSuggestionJob::class, 'schedule_token');
        $token->setAccessible(true);

        return app(LeadAiSuggestionScheduler::class)->is_schedule_token_current((int) $lead->id, (int) $token->getValue($trabajos->last()));
    }
}
