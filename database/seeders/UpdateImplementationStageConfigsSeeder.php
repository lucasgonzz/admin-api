<?php

namespace Database\Seeders;

use App\Models\ImplementationStageConfig;
use Illuminate\Database\Seeder;

/**
 * Seeder standalone para actualizar la tabla implementation_stage_configs en bases
 * de datos existentes con el esquema de etapas vigente (siete desde el 15/10/2026).
 *
 * Trunca todos los registros existentes y los reinserta con los nombres y
 * descripciones del rediseño final. Usar con:
 *   php artisan db:seed --class=UpdateImplementationStageConfigsSeeder
 *
 * Cambios respecto al esquema anterior:
 * - Etapa 1: formulario web (antes preguntas WhatsApp)
 * - Etapa 2: instalación del sistema (antes etapa 3)
 * - Etapa 3: recolección de archivos (antes etapa 4)
 * - Etapa 4: migración de datos (antes etapa 5)
 * - Etapa 5: entrega del sistema (NUEVA)
 * - Etapas 6-7: sin cambio de número (hasta el 14/10/2026 capacitación y ARCA; desde el 15/10 catálogo y ARCA)
 *
 * 🔴 Desde el 15/10/2026 son SIETE etapas (`Implementation::ULTIMA_ETAPA`): la 6 pasó de «Capacitación» a
 * «Catálogo: categorías, imágenes y descripciones» (la capacitación es el centro de recursos que se entrega
 * con el sistema) y la 8, «Videollamada de capacitación», dejó de sembrarse. OJO: este seeder TRUNCA la tabla,
 * así que correrlo en una base con implementaciones anteriores borra la config de la 8 (esas implementaciones
 * siguen funcionando: la API le pone nombre de respaldo y el panel la muestra como «Seguimiento»). Para
 * corregir una base que ya tenía las ocho SIN truncar, está `ImplementationStageConfigSieteEtapasSeeder`.
 */
class UpdateImplementationStageConfigsSeeder extends Seeder
{
    /**
     * Trunca y reinicia los 7 registros de configuración de etapas.
     *
     * @return void
     */
    public function run(): void
    {
        // Eliminar todos los registros existentes para partir de cero limpio.
        ImplementationStageConfig::truncate();

        /**
         * Definición definitiva de las 7 etapas del proceso de implementación.
         * El campo active se fuerza a true para todos los registros.
         */
        $stage_configs = [
            [
                'stage_number'          => 1,
                'name'                  => 'Información de la empresa',
                'description'           => 'El cliente completa el formulario de configuración.',
                'alert_threshold_hours' => 24.00,
                'is_automated'          => false,
                'active'                => true,
            ],
            [
                'stage_number'          => 2,
                'name'                  => 'Instalación del sistema',
                'description'           => 'El equipo instala empresa-api y empresa-spa en el hosting del cliente.',
                'alert_threshold_hours' => 0.00,
                'is_automated'          => true,
                'active'                => true,
            ],
            [
                'stage_number'          => 3,
                'name'                  => 'Recolección de archivos',
                'description'           => 'El responsable de migración envía los Excels y el logo.',
                'alert_threshold_hours' => 24.00,
                'is_automated'          => false,
                'active'                => true,
            ],
            [
                'stage_number'          => 4,
                'name'                  => 'Migración de datos',
                'description'           => 'El sistema analiza los archivos y los importa con IA.',
                'alert_threshold_hours' => 24.00,
                'is_automated'          => false,
                'active'                => true,
            ],
            [
                'stage_number'          => 5,
                'name'                  => 'Entrega del sistema',
                'description'           => 'Se entrega el acceso al cliente con los datos ya cargados.',
                'alert_threshold_hours' => 24.00,
                'is_automated'          => false,
                'active'                => true,
            ],
            [
                'stage_number'          => 6,
                'name'                  => 'Catálogo: categorías, imágenes y descripciones',
                'description'           => 'Se arman las categorías y se completan las fotos y descripciones de los artículos que todavía no las tienen.',
                'alert_threshold_hours' => 24.00,
                'is_automated'          => false,
                'active'                => true,
            ],
            [
                'stage_number'          => 7,
                'name'                  => 'Vinculación ARCA/AFIP',
                'description'           => 'Se conecta el sistema con AFIP para facturación electrónica.',
                'alert_threshold_hours' => 24.00,
                'is_automated'          => false,
                'active'                => true,
            ],
        ];

        // Insertar todos los registros de una vez.
        foreach ($stage_configs as $config_data) {
            ImplementationStageConfig::create($config_data);
        }

        $this->command->info('UpdateImplementationStageConfigsSeeder: 7 etapas actualizadas correctamente.');
    }
}
