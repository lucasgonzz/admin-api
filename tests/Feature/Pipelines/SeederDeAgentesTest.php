<?php

namespace Tests\Feature\Pipelines;

use App\Models\Pipeline;
use App\Models\PipelineActivity;
use App\Models\PipelineOpportunity;
use App\Models\PipelineStage;
use Database\Seeders\PipelineAgentesSeeder;

/**
 * El seeder del pipeline "Agentes" (misión pipelines-crm, 27/9/2026).
 *
 * Lo que se protege:
 *  1. Las ocho etapas con sus tipos, colores y campos, tal cual las pidió Lucas.
 *  2. 🔴 La idempotencia SIN PISAR: correrlo dos veces no duplica, y no le deshace a Lucas una etapa
 *     renombrada (el seeder va en cada deploy por `pendientes.json` y por `DatabaseSeeder`).
 *  3. Que lo sembrado se pueda USAR con la API: sus campos agenda completan la próxima acción.
 */
class SeederDeAgentesTest extends BaseDePipelines
{
    /**
     * Arranca sin ningún "Agentes" previo (la base del slot podría tenerlo si alguien la sembró).
     * Todo corre adentro de la transacción de la prueba, así que se deshace al terminar.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $previos = Pipeline::query()->where('slug', PipelineAgentesSeeder::SLUG)->pluck('id')->all();
        if ($previos !== []) {
            $oportunidades = PipelineOpportunity::query()->whereIn('pipeline_id', $previos)->pluck('id')->all();
            PipelineActivity::query()->whereIn('opportunity_id', $oportunidades)->delete();
            PipelineOpportunity::query()->whereIn('id', $oportunidades)->delete();
            PipelineStage::query()->whereIn('pipeline_id', $previos)->delete();
            Pipeline::query()->whereIn('id', $previos)->delete();
        }
    }

    /**
     * El pipeline sembrado.
     *
     * @return Pipeline
     */
    private function agentes()
    {
        $pipeline = Pipeline::query()->where('slug', PipelineAgentesSeeder::SLUG)->first();

        $this->assertNotNull($pipeline, 'El seeder no creó el pipeline "Agentes".');

        return $pipeline->fresh('stages');
    }

    /**
     * 1. Ocho etapas en orden, con tipos, colores y campos; y los motivos de pérdida.
     *
     * @return void
     */
    public function test_crea_ocho_etapas_con_tipos_colores_y_campos(): void
    {
        $this->seed(PipelineAgentesSeeder::class);

        $pipeline = $this->agentes();
        $this->assertSame('Agentes', $pipeline->name);
        $this->assertSame('Ofrecimiento del sistema de agentes (tu asistente) a los clientes activos.', $pipeline->description);
        $this->assertSame(['Precio', 'No lo necesita', 'No confía en la IA', 'No es el momento', 'Otro'], $pipeline->lost_reasons);
        $this->assertNull($pipeline->archived_at);

        $this->assertSame(
            ['Por contactar', 'Contactado', 'Interesado', 'Reunión agendada', 'Reunión hecha', 'Más adelante', 'Ganado', 'Perdido'],
            $pipeline->stages->pluck('name')->all()
        );
        $this->assertSame(
            ['open', 'open', 'open', 'open', 'open', 'open', 'won', 'lost'],
            $pipeline->stages->pluck('type')->all()
        );
        $this->assertSame(
            ['#adb5bd', '#0dcaf0', '#0d6efd', '#6f42c1', '#fd7e14', '#ffc107', '#198754', '#dc3545'],
            $pipeline->stages->pluck('color')->all()
        );

        /* Los campos, leídos por la API (la forma que ve la SPA). */
        $this->admin_logueado();
        $por_nombre = [];
        foreach ($this->getJson('/api/admin/pipelines')->assertStatus(200)->json('pipelines') as $json) {
            if ($json['id'] === $pipeline->id) {
                foreach ($json['stages'] as $etapa) {
                    $por_nombre[$etapa['name']] = $etapa['fields'];
                }
            }
        }

        $this->assertSame([], $por_nombre['Por contactar']);
        $this->assertSame([
            ['key' => 'canal', 'label' => 'Canal', 'type' => 'select', 'required' => true, 'agenda' => false, 'options' => ['WhatsApp', 'Llamada', 'Mail', 'Presencial']],
        ], $por_nombre['Contactado']);
        $this->assertSame([
            ['key' => 'que_le_intereso', 'label' => 'Qué le interesó', 'type' => 'textarea', 'required' => false, 'agenda' => false, 'options' => []],
        ], $por_nombre['Interesado']);
        $this->assertSame([
            ['key' => 'fecha_reunion', 'label' => 'Fecha y hora', 'type' => 'datetime', 'required' => true, 'agenda' => true, 'options' => []],
            ['key' => 'que_quiere_ver', 'label' => 'Qué quiere ver', 'type' => 'textarea', 'required' => false, 'agenda' => false, 'options' => []],
            ['key' => 'participantes', 'label' => 'Quién participa', 'type' => 'text', 'required' => false, 'agenda' => false, 'options' => []],
        ], $por_nombre['Reunión agendada']);
        $this->assertSame([
            ['key' => 'como_fue', 'label' => 'Cómo fue', 'type' => 'textarea', 'required' => false, 'agenda' => false, 'options' => []],
            ['key' => 'proximo_paso', 'label' => 'Próximo paso', 'type' => 'text', 'required' => false, 'agenda' => false, 'options' => []],
        ], $por_nombre['Reunión hecha']);
        $this->assertSame([
            ['key' => 'fecha_retomar', 'label' => 'Retomar el', 'type' => 'date', 'required' => true, 'agenda' => true, 'options' => []],
        ], $por_nombre['Más adelante']);
        $this->assertSame([
            ['key' => 'paquete', 'label' => 'Paquete', 'type' => 'text', 'required' => false, 'agenda' => false, 'options' => []],
            ['key' => 'precio_acordado', 'label' => 'Precio acordado', 'type' => 'text', 'required' => false, 'agenda' => false, 'options' => []],
        ], $por_nombre['Ganado']);
        $this->assertSame([], $por_nombre['Perdido']);
    }

    /**
     * 2. 🔴 Correrlo dos veces no duplica ni pisa: una etapa renombrada (y recoloreada) desde la
     *    pantalla sigue como la dejó Lucas.
     *
     * @return void
     */
    public function test_correrlo_dos_veces_no_duplica_ni_pisa_una_etapa_renombrada(): void
    {
        $this->seed(PipelineAgentesSeeder::class);

        $this->admin_logueado();
        $interesado = $this->etapa($this->agentes(), 'Interesado');

        $this->putJson('/api/admin/pipeline-stages/' . $interesado->id, [
            'name'  => 'Le interesa',
            'color' => '#112233',
        ])->assertStatus(200);

        $this->seed(PipelineAgentesSeeder::class);

        $this->assertSame(1, Pipeline::query()->where('slug', PipelineAgentesSeeder::SLUG)->count());

        $pipeline = $this->agentes();
        $this->assertCount(8, $pipeline->stages);
        $this->assertSame('Le interesa', $pipeline->stages[2]->name);
        $this->assertSame('#112233', $pipeline->stages[2]->color);
        $this->assertSame(0, $pipeline->stages->where('name', 'Interesado')->count());
    }

    /**
     * 3. Lo sembrado se usa con la API: "Reunión agendada" exige su fecha y hora y la vuelve
     *    próxima acción; "Más adelante", su fecha (a las 00:00); "Perdido" pide el motivo de la
     *    lista.
     *
     * @return void
     */
    public function test_lo_sembrado_funciona_con_la_api(): void
    {
        $this->seed(PipelineAgentesSeeder::class);
        $this->admin_logueado();

        $pipeline = $this->agentes();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente(), ['note' => 'Arranca la campaña']);
        $this->assertSame('Por contactar', $op['stage']['name']);

        $this->mover($op['id'], ['stage_id' => $this->etapa($pipeline, 'Reunión agendada')->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Completá «Fecha y hora».');

        $this->mover($op['id'], [
            'stage_id' => $this->etapa($pipeline, 'Reunión agendada')->id,
            'fields'   => ['fecha_reunion' => '2026-09-30 15:00', 'que_quiere_ver' => 'El asistente respondiendo stock'],
        ])->assertStatus(200)
            ->assertJsonPath('opportunity.next_action_at', '2026-09-30 15:00:00')
            ->assertJsonPath('opportunity.next_action_note', 'Reunión agendada');

        $this->mover($op['id'], [
            'stage_id' => $this->etapa($pipeline, 'Más adelante')->id,
            'fields'   => ['fecha_retomar' => '2026-11-01'],
        ])->assertStatus(200)
            ->assertJsonPath('opportunity.next_action_at', '2026-11-01 00:00:00');

        $this->mover($op['id'], [
            'stage_id'    => $this->etapa($pipeline, 'Perdido')->id,
            'lost_reason' => 'No confía en la IA',
        ])->assertStatus(200)
            ->assertJsonPath('opportunity.lost_reason', 'No confía en la IA');
    }

    /**
     * 4. `DatabaseSeeder` lo llama (así un entorno nuevo nace con el pipeline).
     *
     * @return void
     */
    public function test_database_seeder_lo_incluye(): void
    {
        $fuente = (string) file_get_contents(database_path('seeders/DatabaseSeeder.php'));

        $this->assertStringContainsString('PipelineAgentesSeeder::class', $fuente);
    }
}
