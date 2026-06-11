<?php

namespace Database\Seeders;

use App\Models\EcommerceImplementationStageConfig;
use Illuminate\Database\Seeder;

/**
 * Catálogo de las 5 etapas del proceso de implementación de la tienda online.
 * Usa updateOrCreate por stage_number para ser idempotente en re-ejecuciones.
 */
class EcommerceImplementationStageConfigSeeder extends Seeder
{
    /**
     * Definición de las etapas del flujo de ecommerce.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function stage_configs(): array
    {
        return [
            [
                'stage_number'          => 1,
                'name'                  => 'Configuración de la tienda',
                'description'           => 'Recolección de preferencias y dominio por WhatsApp',
                'alert_threshold_hours' => 24.00,
                'is_automated'          => false,
            ],
            [
                'stage_number'          => 2,
                'name'                  => 'Compra y delegación del dominio',
                'description'           => 'Comprar en NIC.ar y delegar a Hostinger',
                'alert_threshold_hours' => 24.00,
                'is_automated'          => false,
            ],
            [
                'stage_number'          => 3,
                'name'                  => 'Instalación del API',
                'description'           => 'Subir tienda-api al servidor',
                'alert_threshold_hours' => 24.00,
                'is_automated'          => false,
            ],
            [
                'stage_number'          => 4,
                'name'                  => 'Instalación del SPA',
                'description'           => 'Compilar y subir tienda-spa',
                'alert_threshold_hours' => 24.00,
                'is_automated'          => false,
            ],
            [
                'stage_number'          => 5,
                'name'                  => 'Activación',
                'description'           => 'Notificar al cliente con link de la tienda',
                'alert_threshold_hours' => 24.00,
                'is_automated'          => false,
            ],
        ];
    }

    /**
     * Ejecuta el seeder de configuración de etapas de ecommerce.
     *
     * @return void
     */
    public function run()
    {
        foreach (self::stage_configs() as $config_data) {
            // Clave de unicidad: stage_number para idempotencia.
            EcommerceImplementationStageConfig::updateOrCreate(
                ['stage_number' => $config_data['stage_number']],
                array_merge($config_data, ['active' => true])
            );
        }
    }
}
