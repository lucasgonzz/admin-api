<?php

namespace Database\Seeders;

use App\Models\Implementation;
use App\Models\ImplementationStage;
use App\Models\ImplementationStageConfig;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeder de actualización para producción: introduce la nueva Etapa 4 "Recolección de archivos"
 * y renumera las etapas 4-7 existentes a 5-8.
 *
 * Ejecutar UNA SOLA VEZ en bases de datos existentes antes de correr ImplementationStageConfigSeeder.
 * Es idempotente: si la etapa 4 ya tiene el nombre correcto, no hace nada.
 */
class AddImplementationStage4FileCollectionSeeder extends Seeder
{
    /**
     * Aplica la migración de datos de etapas en producción.
     *
     * Pasos:
     * 1. Verificar si la nueva etapa 4 ya existe (idempotencia).
     * 2. Incrementar stage_number en implementation_stages para registros >= 4.
     * 3. Incrementar current_stage en implementations para implementaciones in_progress con stage >= 4.
     * 4. Incrementar stage_number en implementation_stage_configs para configs >= 4.
     * 5. Insertar la nueva etapa 4 "Recolección de archivos" en implementation_stage_configs.
     *
     * @return void
     */
    public function run(): void
    {
        // Idempotencia: si ya existe una config con stage_number=4 y el nombre correcto, salir.
        $existing_new_stage_4 = ImplementationStageConfig::where('stage_number', 4)
            ->where('name', 'Recolección de archivos')
            ->first();

        if ($existing_new_stage_4 !== null) {
            $this->command->info('AddImplementationStage4FileCollectionSeeder: ya ejecutado, omitiendo.');
            return;
        }

        DB::transaction(function () {
            // Paso 1: Incrementar stage_number en implementation_stages para >= 4.
            // Se usa UPDATE directo para evitar conflictos de unicidad al renumerar.
            // Se procesa en orden descendente para evitar colisiones (8->9, 7->8, ..., 4->5).
            DB::statement(
                'UPDATE implementation_stages SET stage_number = stage_number + 1 WHERE stage_number >= 4 ORDER BY stage_number DESC'
            );

            $this->command->info('implementation_stages: stage_number >= 4 incrementado en 1.');

            // Paso 2: Incrementar current_stage en implementations para implementaciones
            // in_progress cuyo current_stage sea >= 4, para que sigan apuntando a la etapa correcta.
            DB::statement(
                'UPDATE implementations SET current_stage = current_stage + 1 WHERE status = ? AND current_stage >= 4',
                ['in_progress']
            );

            $this->command->info('implementations: current_stage >= 4 incrementado en 1 (solo in_progress).');

            // Paso 3: Incrementar stage_number en implementation_stage_configs para >= 4.
            DB::statement(
                'UPDATE implementation_stage_configs SET stage_number = stage_number + 1 WHERE stage_number >= 4 ORDER BY stage_number DESC'
            );

            $this->command->info('implementation_stage_configs: stage_number >= 4 incrementado en 1.');

            // Paso 4: Insertar la nueva etapa 4 "Recolección de archivos".
            ImplementationStageConfig::create([
                'stage_number'          => 4,
                'name'                  => 'Recolección de archivos',
                'description'           => 'Recibir los archivos Excel del cliente para la migración',
                'alert_threshold_hours' => 24.00,
                'is_automated'          => false,
                'active'                => true,
            ]);

            $this->command->info('Nueva etapa 4 "Recolección de archivos" insertada.');

            // Paso 5: Insertar el registro de implementation_stages para la nueva etapa 4
            // en cada implementación existente (en estado pending por defecto).
            $implementations = Implementation::all();

            foreach ($implementations as $implementation) {
                // Verificar que no exista ya el stage 4 para esta implementación.
                $already_exists = ImplementationStage::where('implementation_id', $implementation->id)
                    ->where('stage_number', 4)
                    ->exists();

                if ($already_exists) {
                    continue;
                }

                ImplementationStage::create([
                    'implementation_id' => $implementation->id,
                    'stage_number'      => 4,
                    'status'            => 'pending',
                ]);
            }

            $this->command->info('Registros de implementation_stages para etapa 4 creados en implementaciones existentes.');
        });

        $this->command->info('AddImplementationStage4FileCollectionSeeder completado.');
    }
}
