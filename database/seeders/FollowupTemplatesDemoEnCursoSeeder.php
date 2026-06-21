<?php

namespace Database\Seeders;

use App\Models\FollowupTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeder standalone para agregar las plantillas de seguimiento
 * destinadas a leads que iniciaron la demo pero no confirmaron que terminaron.
 *
 * Idempotente: usa updateOrCreate con (estado, dia_numero, template_name)
 * como clave única para evitar duplicados al ejecutarlo múltiples veces.
 *
 * Estas plantillas aplican exclusivamente cuando demo_ingreso_confirmado = true
 * en el lead, por lo que se registran con solo_si_ingreso_confirmado = true.
 */
class FollowupTemplatesDemoEnCursoSeeder extends Seeder
{
    /**
     * Inserta o actualiza las tres plantillas de seguimiento para demo en curso.
     *
     * @return void
     */
    public function run()
    {
        /*
         * Plantillas aprobadas en Meta para el seguimiento de leads
         * que entraron a la demo pero no la terminaron.
         * Cada plantilla corresponde a un día de seguimiento distinto.
         */
        $templates = [
            [
                'dia_numero'    => 1,
                'template_name' => 'cc_seg_demo_en_curso_d1',
                'body_template' => 'Hola {{1}}! Habías entrado a la demo de ComercioCity... ¿pudiste terminar de recorrerla? Si te quedó algo pendiente, podés seguir cuando quieras.',
            ],
            [
                'dia_numero'    => 3,
                'template_name' => 'cc_seg_demo_en_curso_d3',
                'body_template' => 'Hola {{1}}, ¿cómo estás? Quedaste con la demo empezada de ComercioCity. ¿Pudiste terminarla? Cualquier duda que te haya quedado, avisame.',
            ],
            [
                'dia_numero'    => 6,
                'template_name' => 'cc_seg_demo_en_curso_d6',
                'body_template' => 'Hola {{1}}, último mensaje de mi parte. Si en algún momento querés terminar de ver la demo o charlar con alguien del equipo, escribime. Quedamos disponibles.',
            ],
        ];

        foreach ($templates as $row) {
            FollowupTemplate::updateOrCreate(
                [
                    /* Clave compuesta para identificar unívocamente cada plantilla. */
                    'estado'        => 'demo_agendada',
                    'dia_numero'    => $row['dia_numero'],
                    'template_name' => $row['template_name'],
                ],
                [
                    'language_code'              => 'es_AR',
                    'activa'                     => true,
                    /* Esta plantilla aplica solo cuando el lead confirmó ingreso a la demo. */
                    'solo_si_ingreso_confirmado' => true,
                    'body_template'              => $row['body_template'],
                ]
            );
        }

        $this->command->info('Plantillas de seguimiento demo_en_curso agregadas.');
    }
}
