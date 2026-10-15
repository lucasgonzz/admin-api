<?php

namespace Tests\Feature\Pipelines;

use App\Models\Pipeline;
use App\Models\PipelineActivity;
use App\Models\PipelineOpportunity;
use App\Models\PipelineStage;
use Database\Seeders\PipelineLeadSeeder;

/**
 * El seeder del pipeline "Leads" (misión embudo-lead-pipelines, 13/10/2026).
 *
 * Lo que se protege:
 *  1. Las cinco etapas con sus tipos, colores, `stage_key` y campos, y los motivos de pérdida.
 *  2. 🔴 La idempotencia SIN PISAR: correrlo dos veces no duplica, y no le deshace a Tomás una
 *     etapa renombrada (el seeder va en cada deploy por `pendientes.json` y por `DatabaseSeeder`).
 *  3. 🔴 Que NINGÚN campo sea obligatorio y que `fecha_llamada` NO sea campo de agenda. Las dos
 *     cosas parecen descuido y son decisiones: un obligatorio haría tirar 422 al movimiento
 *     automático, y un campo agenda le crearía a Tomás un segundo evento en el calendario por la
 *     misma videollamada, comiéndole huecos de demo.
 */
class SeederDeLeadsTest extends BaseDePipelines
{
    /**
     * Arranca sin ningún "Leads" previo (la base del slot lo tiene si alguien la sembró). Todo
     * corre adentro de la transacción de la prueba, así que se deshace al terminar.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $previos = Pipeline::query()->where('slug', PipelineLeadSeeder::SLUG)->pluck('id')->all();
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
    private function leads()
    {
        $pipeline = Pipeline::query()->where('slug', PipelineLeadSeeder::SLUG)->first();

        $this->assertNotNull($pipeline, 'El seeder no creó el pipeline "Leads".');

        return $pipeline->fresh('stages');
    }

    /**
     * 1. Cinco etapas en orden, con tipo, clave, color y campos; y los motivos de pérdida.
     *
     * @return void
     */
    public function test_siembra_las_cinco_etapas_del_embudo()
    {
        $this->seed(PipelineLeadSeeder::class);

        $pipeline = $this->leads();

        $this->assertSame('Leads', $pipeline->name);
        $this->assertSame(
            ['Precio', 'No lo necesita', 'Se fue con otro sistema', 'No es el momento', 'No contesta', 'Otro'],
            $pipeline->motivos_de_perdida()
        );

        $etapas = $pipeline->stages->sortBy('sort_order')->values();

        $esperadas = [
            ['Interesados',          PipelineLeadSeeder::ETAPA_INTERESADOS,       PipelineStage::TYPE_OPEN],
            ['Esperando la llamada', PipelineLeadSeeder::ETAPA_ESPERANDO_LLAMADA, PipelineStage::TYPE_OPEN],
            ['En seguimiento',       PipelineLeadSeeder::ETAPA_EN_SEGUIMIENTO,    PipelineStage::TYPE_OPEN],
            ['Cerrado',              PipelineLeadSeeder::ETAPA_CERRADO,           PipelineStage::TYPE_WON],
            ['No avanza',            PipelineLeadSeeder::ETAPA_NO_AVANZA,         PipelineStage::TYPE_LOST],
        ];

        $this->assertCount(count($esperadas), $etapas, 'El embudo tiene cinco etapas.');

        foreach ($esperadas as $orden => $esperada) {
            $etapa = $etapas[$orden];

            $this->assertSame($esperada[0], $etapa->name, 'Etapa ' . $orden);
            $this->assertSame($esperada[1], $etapa->stage_key, 'Clave de la etapa ' . $esperada[0]);
            $this->assertSame($esperada[2], $etapa->type, 'Tipo de la etapa ' . $esperada[0]);
            $this->assertSame($orden, (int) $etapa->sort_order);
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', (string) $etapa->color);
        }
    }

    /**
     * 2. 🔴 "Interesados" es la PRIMERA abierta, que es de donde el alta automática saca su etapa
     *    (pide la primera abierta, no la nombra). Si alguien insertara una etapa abierta antes, las
     *    oportunidades nuevas nacerían ahí y nadie se enteraría.
     *
     * @return void
     */
    public function test_interesados_es_la_primera_etapa_abierta()
    {
        $this->seed(PipelineLeadSeeder::class);

        $primera = PipelineStage::query()
            ->where('pipeline_id', $this->leads()->id)
            ->where('type', PipelineStage::TYPE_OPEN)
            ->orderBy('sort_order')->orderBy('id')
            ->first();

        $this->assertNotNull($primera);
        $this->assertSame(PipelineLeadSeeder::ETAPA_INTERESADOS, $primera->stage_key);
    }

    /**
     * 3. 🔴 Ningún campo obligatorio, y `fecha_llamada` sin agenda. Ver el docblock de la clase: las
     *    dos cosas son decisiones y este test existe para que nadie las "arregle" sin leerlas.
     *
     * @return void
     */
    public function test_ningun_campo_es_obligatorio_y_la_fecha_de_la_llamada_no_es_de_agenda()
    {
        $this->seed(PipelineLeadSeeder::class);

        $con_agenda = [];

        foreach ($this->leads()->stages as $etapa) {
            foreach ($etapa->definicion_de_campos() as $campo) {
                $this->assertTrue(
                    empty($campo['required']),
                    'El campo "' . $campo['key'] . '" de "' . $etapa->name . '" quedó obligatorio: un obligatorio'
                    . ' hace tirar 422 al movimiento automático del embudo y lo deja mudo.'
                );

                if (! empty($campo['agenda'])) {
                    $con_agenda[] = $etapa->name . '.' . $campo['key'];
                }
            }
        }

        $this->assertSame(
            [],
            $con_agenda,
            'Ninguna etapa del embudo lleva campo de agenda: fijaría la próxima acción y eso le crea a Tomás'
            . ' un segundo evento en el calendario por la misma videollamada. Quedaron: ' . implode(', ', $con_agenda)
        );
    }

    /**
     * 4. 🔴 Idempotente y sin pisar: dos corridas no duplican el pipeline ni le deshacen una etapa
     *    renombrada.
     *
     * @return void
     */
    public function test_correrlo_dos_veces_no_duplica_ni_pisa_lo_editado()
    {
        $this->seed(PipelineLeadSeeder::class);

        $pipeline = $this->leads();

        $etapa            = $this->etapa($pipeline, 'En seguimiento');
        $etapa->name      = 'Negociando';
        $etapa->save();

        $this->seed(PipelineLeadSeeder::class);

        $this->assertSame(
            1,
            Pipeline::query()->where('slug', PipelineLeadSeeder::SLUG)->count(),
            'El seeder duplicó el pipeline.'
        );
        $this->assertCount(5, $this->leads()->stages, 'El seeder agregó etapas de más.');
        $this->assertSame('Negociando', $etapa->fresh()->name, 'El seeder le deshizo el renombre a Tomás.');
    }

    /**
     * 5. Lo sembrado se puede USAR con la API: un lead entra en "Interesados" y se mueve a
     *    "Esperando la llamada" cargando la fecha, sin que el campo fije ninguna próxima acción.
     *
     * @return void
     */
    public function test_lo_sembrado_se_puede_usar_desde_la_api()
    {
        $this->admin_logueado();
        $this->seed(PipelineLeadSeeder::class);

        $pipeline = $this->leads();
        $lead     = $this->crear_lead();

        $creada = $this->alta_de_uno($pipeline, $lead);

        $this->assertSame('Interesados', $creada['stage']['name']);
        $this->assertSame(
            PipelineLeadSeeder::ETAPA_INTERESADOS,
            PipelineStage::query()->find($creada['stage']['id'])->stage_key
        );

        $destino = $this->etapa($pipeline, 'Esperando la llamada');

        $this->mover($creada['id'], [
            'stage_id' => $destino->id,
            'fields'   => [PipelineLeadSeeder::CAMPO_FECHA_LLAMADA => '2026-09-28 15:00'],
        ])->assertStatus(200);

        $oportunidad = $this->oportunidad($creada['id']);

        $this->assertSame((int) $destino->id, (int) $oportunidad->stage_id);
        $this->assertNull(
            $oportunidad->next_action_at,
            'La fecha de la videollamada no tiene que fijar próxima acción: duplicaría el evento del calendario.'
        );
    }
}
