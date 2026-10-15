<?php

namespace Database\Seeders;

use App\Models\ImplementationStageConfig;
use Illuminate\Database\Seeder;

/**
 * Catálogo de las 7 etapas del proceso de implementación de clientes (`Implementation::ULTIMA_ETAPA`).
 * Usa updateOrCreate por stage_number para ser idempotente en re-ejecuciones.
 *
 * 🔴 Eran 8 hasta el 15/10/2026. La 6 pasó de «Capacitación» a «Catálogo: categorías, imágenes y
 * descripciones» y la 8 («Videollamada de capacitación») dejó de sembrarse. Una base que ya tenía las
 * ocho NO se corrige con este seeder (solo crea/actualiza las siete y no toca la 8): para producción
 * está `ImplementationStageConfigSieteEtapasSeeder`, que renombra la 6 y deja la 8 inactiva.
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
                'name'                   => 'Catálogo: categorías, imágenes y descripciones',
                'description'            => 'Se arman las categorías y se completan las fotos y descripciones de los artículos que todavía no las tienen.',
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
