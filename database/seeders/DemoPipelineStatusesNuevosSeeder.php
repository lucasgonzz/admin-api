<?php

namespace Database\Seeders;

use App\Models\LeadPipelineStatus;
use Illuminate\Database\Seeder;

/**
 * Seeder standalone idempotente: agrega los 4 estados nuevos del ciclo de demo
 * (ingresando_demo, demo_en_curso, demo_pendiente_de_ingreso, demo_pendiente_de_terminar)
 * si aún no existen en la tabla `lead_pipeline_statuses`.
 *
 * Seguro de correr en producción sobre una base ya poblada:
 * solo inserta lo que falta y actualiza los colores de todos los estados conocidos.
 */
class DemoPipelineStatusesNuevosSeeder extends Seeder
{
    /**
     * Slugs y etiquetas de los 4 estados nuevos del ciclo de demo.
     *
     * @var array<string, string>
     */
    private const NUEVOS_ESTADOS = [
        'ingresando_demo'            => 'Ingresando a demo',
        'demo_en_curso'              => 'Demo en curso',
        'demo_pendiente_de_ingreso'  => 'Demo pendiente de ingreso',
        'demo_pendiente_de_terminar' => 'Demo pendiente de terminar',
    ];

    /**
     * Inserta los estados nuevos si no existen y sincroniza colores.
     *
     * @return void
     */
    public function run()
    {
        /* Insertar cada estado nuevo solo si no existe aún en la tabla. */
        foreach (self::NUEVOS_ESTADOS as $slug => $label) {
            LeadPipelineStatus::ensure_exists($slug, $label);
        }

        /* Actualizar colores de todos los estados conocidos (idempotente). */
        LeadPipelineStatus::sync_default_colors();
    }
}
