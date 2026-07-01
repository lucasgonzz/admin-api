<?php

namespace Database\Seeders;

use App\Models\LeadPipelineStatus;
use Illuminate\Database\Seeder;

/**
 * Actualiza el sort_order de los estados del pipeline para que queden
 * ordenados por grupo visual (Calificación → Demo → Cierre → Fin).
 * Asegura que closer_activo existe. Idempotente.
 */
class LeadPipelineStatusGroupOrderSeeder extends Seeder
{
    /** Orden de sort_order por slug (incluye demo_realizada para consistencia en BD). */
    private const ORDER = [
        'nuevo'                      => 0,
        'contactado'                 => 1,
        'calificado'                 => 2,
        'demo_agendada'              => 3,
        'ingresando_demo'            => 4,
        'demo_en_curso'              => 5,
        'demo_pendiente_de_ingreso'  => 6,
        'demo_pendiente_de_terminar' => 7,
        'demo_realizada'             => 8,
        'closer_activo'              => 9,
        'cerrado_ganado'             => 10,
        'cerrado_perdido'            => 11,
        'en_pausa'                   => 12,
        'mail2_enviado'              => 13,
    ];

    /**
     * Asegura closer_activo y reordena sort_order según grupos visuales.
     *
     * @return void
     */
    public function run()
    {
        /* Asegurar que closer_activo existe. */
        LeadPipelineStatus::ensure_exists('closer_activo', 'Closer activo');

        /* Actualizar sort_order. */
        foreach (self::ORDER as $slug => $order) {
            LeadPipelineStatus::query()
                ->where('slug', $slug)
                ->update(['sort_order' => $order]);
        }

        /* Sincronizar colores de los conocidos (incluye closer_activo). */
        LeadPipelineStatus::sync_default_colors();

        $this->command->info('LeadPipelineStatusGroupOrderSeeder: sort_order actualizado.');
    }
}
