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
                'name'                   => 'Información de la empresa',
                'description'            => 'El cliente completa el formulario de configuración.',
                'alert_threshold_hours'  => 24.00,
                'is_automated'           => false,
            ],
            [
                'stage_number'           => 2,
                'name'                   => 'Instalación del sistema',
                'description'            => 'El equipo instala empresa-api y empresa-spa en el hosting del cliente.',
                'alert_threshold_hours'  => 0.00,
                'is_automated'           => true,
            ],
            [
                'stage_number'           => 3,
                'name'                   => 'Recolección de archivos',
                'description'            => 'El responsable de migración envía los Excels y el logo.',
                'alert_threshold_hours'  => 24.00,
                'is_automated'           => false,
            ],
            [
                'stage_number'           => 4,
                'name'                   => 'Migración de datos',
                'description'            => 'El sistema analiza los archivos y los importa con IA.',
                'alert_threshold_hours'  => 24.00,
                'is_automated'           => false,
            ],
            [
                'stage_number'           => 5,
                'name'                   => 'Entrega del sistema',
                'description'            => 'Se entrega el acceso al cliente con los datos ya cargados.',
                'alert_threshold_hours'  => 24.00,
                'is_automated'           => false,
            ],
            [
                'stage_number'           => 6,
                'name'                   => 'Capacitación',
                'description'            => 'Se envían las credenciales a empleados y el link al centro de recursos.',
                'alert_threshold_hours'  => 24.00,
                'is_automated'           => false,
            ],
            [
                'stage_number'           => 7,
                'name'                   => 'Vinculación ARCA/AFIP',
                'description'            => 'Se conecta el sistema con AFIP para facturación electrónica.',
                'alert_threshold_hours'  => 24.00,
                'is_automated'           => false,
            ],
            [
                'stage_number'           => 8,
                'name'                   => 'Videollamada de capacitación',
                'description'            => 'Llamada final para resolver dudas.',
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
