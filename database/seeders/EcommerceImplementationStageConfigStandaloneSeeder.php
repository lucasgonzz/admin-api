<?php

namespace Database\Seeders;

use App\Models\EcommerceImplementationStageConfig;
use Illuminate\Database\Seeder;

/**
 * Seeder standalone para actualizar bases de datos de producción existentes con
 * el catálogo de etapas del flujo de ecommerce.
 *
 * Reutiliza la definición de EcommerceImplementationStageConfigSeeder y usa
 * updateOrCreate por stage_number, por lo que es idempotente.
 */
class EcommerceImplementationStageConfigStandaloneSeeder extends Seeder
{
    /**
     * Inserta o actualiza las etapas del flujo de ecommerce en producción.
     *
     * @return void
     */
    public function run()
    {
        foreach (EcommerceImplementationStageConfigSeeder::stage_configs() as $config_data) {
            EcommerceImplementationStageConfig::updateOrCreate(
                ['stage_number' => $config_data['stage_number']],
                array_merge($config_data, ['active' => true])
            );
        }

        if (isset($this->command)) {
            $this->command->info('EcommerceImplementationStageConfigStandaloneSeeder: 5 etapas de ecommerce sembradas.');
        }
    }
}
