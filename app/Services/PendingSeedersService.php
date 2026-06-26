<?php

namespace App\Services;

use App\Models\AdminSetting;
use App\Models\EcommerceImplementationStageConfig;
use App\Models\FollowupTemplate;
use App\Models\ImplementationStageConfig;
use App\Models\MessageVariant;
use App\Models\TaskTemplate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Chequeo de seeders pendientes en producción.
 *
 * Cada entrada del array de checks define:
 *   - class:       nombre de la clase seeder (sin namespace)
 *   - description: texto legible para mostrar en el alert del panel
 *   - is_pending:  closure que devuelve true si el seeder aún no corrió
 *
 * Para agregar un seeder nuevo al chequeo: agregar una entrada en checks().
 * El controller y las rutas no requieren ningún cambio.
 */
class PendingSeedersService
{
    /**
     * Definición de todos los seeders que deben haber corrido en producción.
     *
     * @return array<int, array{class: string, description: string, is_pending: \Closure}>
     */
    public static function checks(): array
    {
        return [
            [
                'class'       => 'StandaloneMessageVariantSeeder',
                'description' => 'Variantes A/B de mensaje de bienvenida (message_variants vacía)',
                'is_pending'  => function () {
                    return MessageVariant::count() === 0;
                },
            ],
            [
                'class'       => 'FollowupTemplatesDemoEnCursoSeeder',
                'description' => 'Plantillas de seguimiento para leads que iniciaron la demo pero no terminaron',
                'is_pending'  => function () {
                    return ! FollowupTemplate::where('template_name', 'cc_seg_demo_en_curso_d1')->exists();
                },
            ],
            [
                'class'       => 'TaskTemplateSeeder',
                'description' => 'Plantillas de tareas automáticas para el proceso lead → cliente (task_templates vacía)',
                'is_pending'  => function () {
                    return TaskTemplate::count() === 0;
                },
            ],
            [
                'class'       => 'EcommerceImplementationStageConfigStandaloneSeeder',
                'description' => 'Etapas del flujo de implementación de tienda online (ecommerce_implementation_stage_configs vacía)',
                'is_pending'  => function () {
                    return EcommerceImplementationStageConfig::count() === 0;
                },
            ],
            [
                'class'       => 'ImplementationFileWaitSeeder',
                'description' => 'Setting de tiempo de espera para procesar archivos en Etapa 4 de implementación',
                'is_pending'  => function () {
                    return AdminSetting::get('implementation_file_wait_seconds') === null;
                },
            ],
            [
                'class'       => 'UpdateImplementationStageConfigsSeeder',
                'description' => 'Actualización del esquema de etapas de implementación (nueva Etapa 5: Entrega del sistema)',
                'is_pending'  => function () {
                    return ! ImplementationStageConfig::where('name', 'Entrega del sistema')->exists();
                },
            ],
        ];
    }

    /**
     * Devuelve solo los seeders que aún no han sido ejecutados.
     *
     * @return array<int, array{class: string, description: string}>
     */
    public static function get_pending(): array
    {
        /* Acumula los seeders cuya condición is_pending devuelve true. */
        $pending = [];

        foreach (self::checks() as $check) {
            /* Closure de verificación del seeder actual. */
            $is_pending = $check['is_pending'];

            try {
                if ($is_pending()) {
                    $pending[] = [
                        'class'       => $check['class'],
                        'description' => $check['description'],
                    ];
                }
            } catch (\Exception $e) {
                /* Si el chequeo falla (tabla inexistente, etc.), lo saltea sin romper el resto. */
                Log::warning('PendingSeedersService: error al chequear ' . $check['class'] . ': ' . $e->getMessage());
            }
        }

        return $pending;
    }

    /**
     * Ejecuta todos los seeders pendientes y devuelve el resultado de cada uno.
     *
     * @return array<int, array{class: string, description: string, status: string, error?: string}>
     */
    public static function run_all_pending(): array
    {
        /* Acumula el resultado de cada seeder ejecutado (ok o error). */
        $results = [];

        foreach (self::checks() as $check) {
            /* Closure de verificación del seeder actual. */
            $is_pending = $check['is_pending'];

            try {
                if (! $is_pending()) {
                    /* Ya estaba ejecutado — omitir. */
                    continue;
                }
            } catch (\Exception $e) {
                Log::warning('PendingSeedersService: error al chequear antes de correr ' . $check['class'] . ': ' . $e->getMessage());
                continue;
            }

            try {
                /* Ejecutar el seeder con --force para que funcione en producción. */
                Artisan::call('db:seed', [
                    '--class' => $check['class'],
                    '--force' => true,
                ]);

                $results[] = [
                    'class'       => $check['class'],
                    'description' => $check['description'],
                    'status'      => 'ok',
                ];
            } catch (\Exception $e) {
                Log::error('PendingSeedersService: error al ejecutar ' . $check['class'] . ': ' . $e->getMessage());

                $results[] = [
                    'class'       => $check['class'],
                    'description' => $check['description'],
                    'status'      => 'error',
                    'error'       => $e->getMessage(),
                ];
            }
        }

        return $results;
    }
}
