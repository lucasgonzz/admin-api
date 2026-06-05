<?php

namespace Database\Seeders;

use App\Models\ImplementationStageConfig;
use Illuminate\Database\Seeder;

/**
 * Catálogo de las 8 etapas del proceso de implementación de clientes.
 * Usa updateOrCreate por stage_number para ser idempotente en re-ejecuciones.
 */
class ImplementationStageConfigSeeder extends Seeder
{
    /**
     * Ejecuta el seeder de configuración de etapas.
     *
     * @return void
     */
    public function run()
    {
        /**
         * Definición de etapas: número, nombre, descripción, umbral de alerta (horas), automatizada.
         */
        $stage_configs = [
            [
                'stage_number'           => 1,
                'name'                   => 'Info de la empresa',
                'description'            => 'Recolección de datos de configuración inicial por WhatsApp',
                'alert_threshold_hours'  => 24.00,
                'is_automated'           => false,
            ],
            [
                'stage_number'           => 2,
                'name'                   => 'Responsable de migración',
                'description'            => 'Definir quién envía los archivos Excel',
                'alert_threshold_hours'  => 24.00,
                'is_automated'           => false,
            ],
            [
                'stage_number'           => 3,
                'name'                   => 'Instalación del sistema',
                'description'            => 'Crear empleados y configurar el sistema del cliente',
                'alert_threshold_hours'  => 0.00,
                'is_automated'           => true,
            ],
            [
                'stage_number'           => 4,
                'name'                   => 'Recolección de archivos',
                'description'            => 'Recibir los archivos Excel del cliente para la migración',
                'alert_threshold_hours'  => 24.00,
                'is_automated'           => false,
            ],
            [
                'stage_number'           => 5,
                'name'                   => 'Migración de datos',
                'description'            => 'Análisis IA de columnas, confirmación e importación al sistema',
                'alert_threshold_hours'  => 24.00,
                'is_automated'           => false,
            ],
            [
                'stage_number'           => 6,
                'name'                   => 'Capacitación',
                'description'            => 'Enviar credenciales y centro de recursos a cada empleado',
                'alert_threshold_hours'  => 24.00,
                'is_automated'           => false,
            ],
            [
                'stage_number'           => 7,
                'name'                   => 'Vinculación AFIP/ARCA',
                'description'            => 'Coordinar vinculación con ARCA',
                'alert_threshold_hours'  => 24.00,
                'is_automated'           => false,
            ],
            [
                'stage_number'           => 8,
                'name'                   => 'Videollamada de capacitación',
                'description'            => 'Coordinar horario de videollamada de dudas',
                'alert_threshold_hours'  => 24.00,
                'is_automated'           => false,
            ],
        ];

        foreach ($stage_configs as $config_data) {
            // Clave de unicidad: stage_number para idempotencia.
            ImplementationStageConfig::updateOrCreate(
                ['stage_number' => $config_data['stage_number']],
                array_merge($config_data, ['active' => true])
            );
        }
    }
}
