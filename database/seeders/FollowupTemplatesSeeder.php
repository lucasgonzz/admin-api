<?php

namespace Database\Seeders;

use App\Models\FollowupTemplate;
use Illuminate\Database\Seeder;

/**
 * Siembra las plantillas Meta aprobadas usadas para enviar seguimientos
 * automáticos directos según estado del lead y número de día.
 */
class FollowupTemplatesSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run()
    {
        // Plantillas por estado y día. El orden por dia_numero dentro de cada
        // estado define qué plantilla recibe el primer, segundo, etc. seguimiento.
        $templates = [
            ['estado' => 'nuevo',          'dia_numero' => 2, 'template_name' => 'cc_seg_nuevo_d2'],
            ['estado' => 'nuevo',          'dia_numero' => 5, 'template_name' => 'cc_seg_nuevo_d5'],
            ['estado' => 'contactado',     'dia_numero' => 1, 'template_name' => 'cc_seg_contactado_d1'],
            ['estado' => 'contactado',     'dia_numero' => 4, 'template_name' => 'cc_seg_contactado_d4'],
            ['estado' => 'calificado',     'dia_numero' => 1, 'template_name' => 'cc_seg_calificado_d1'],
            ['estado' => 'calificado',     'dia_numero' => 3, 'template_name' => 'cc_seg_calificado_d3'],
            ['estado' => 'calificado',     'dia_numero' => 6, 'template_name' => 'cc_seg_calificado_d6'],
            ['estado' => 'demo_agendada',  'dia_numero' => 1, 'template_name' => 'cc_seg_demo_agendada_d1'],
            ['estado' => 'demo_agendada',  'dia_numero' => 3, 'template_name' => 'cc_seg_demo_agendada_d3'],
            ['estado' => 'demo_agendada',  'dia_numero' => 7, 'template_name' => 'cc_seg_demo_agendada_d7'],
            ['estado' => 'demo_realizada', 'dia_numero' => 1, 'template_name' => 'cc_seg_demo_realizada_d1'],
            ['estado' => 'demo_realizada', 'dia_numero' => 3, 'template_name' => 'cc_seg_demo_realizada_d3'],
            ['estado' => 'demo_realizada', 'dia_numero' => 6, 'template_name' => 'cc_seg_demo_realizada_d6'],
            ['estado' => 'mail2_enviado',  'dia_numero' => 1, 'template_name' => 'cc_seg_cierre_d1'],
            ['estado' => 'mail2_enviado',  'dia_numero' => 2, 'template_name' => 'cc_seg_cierre_d2'],
            ['estado' => 'mail2_enviado',  'dia_numero' => 4, 'template_name' => 'cc_seg_cierre_d4'],
        ];

        foreach ($templates as $row) {
            FollowupTemplate::updateOrCreate(
                ['estado' => $row['estado'], 'dia_numero' => $row['dia_numero']],
                array_merge($row, ['language_code' => 'es_AR', 'activa' => true])
            );
        }
    }
}
