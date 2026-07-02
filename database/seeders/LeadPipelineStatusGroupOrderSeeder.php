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
        'solicita_disponibilidad'    => 3,
        'demo_agendada'              => 4,
        'ingresando_demo'            => 5,
        'demo_en_curso'              => 6,
        'demo_pendiente_de_ingreso'  => 7,
        'demo_pendiente_de_terminar' => 8,
        'demo_realizada'             => 9,
        'closer_activo'              => 10,
        'cerrado_ganado'             => 11,
        'cerrado_perdido'            => 12,
        'en_pausa'                   => 13,
        'mail2_enviado'              => 14,
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

        /*
         * Asegurar cerrado_ganado: estaba definido en DEFAULT_STATUSES/DEFAULT_STATUS_GROUPS
         * desde antes, pero nunca se insertó como fila real en producción (options_for_meta()
         * arma las opciones desde BD cuando ya hay filas, no desde la constante) — por eso no
         * aparecía ni en el filtro ni al asignar estado a un lead. Detectado y corregido 2/7/2026.
         */
        LeadPipelineStatus::ensure_exists('cerrado_ganado', 'Cerrado ganado');

        /*
         * solicita_disponibilidad ya existe como fila real (el agente la crea en producción vía
         * ensure_exists cuando la usa por primera vez) — este ensure_exists es solo red de
         * seguridad idempotente, no un alta nueva. Lo que sí es nuevo acá es que ahora entra al
         * grupo Calificación (ver DEFAULT_STATUS_GROUPS) y al sort_order de abajo.
         */
        LeadPipelineStatus::ensure_exists('solicita_disponibilidad', 'Solicita disponibilidad');

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
