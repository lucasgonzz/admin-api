<?php

namespace Database\Seeders;

use App\Models\Version;
use App\Models\VersionCommand;
use App\Models\VersionManualTask;
use App\Models\VersionNotification;
use App\Models\VersionSeeder as VersionSeederModel;
use Illuminate\Database\Seeder;

class DemoVersionSeeder extends Seeder
{
    public function run()
    {

        $versions = [
            [
                'version' => '1.0.0',
                'title' => 'Versión inicial demo',
                'description' => 'Versión de ejemplo para probar la sincronización admin ↔ cliente.',
                'status' => 'published',
                'published_at' => now(),
                'notifications' => [
                    [
                        'title' => 'Bienvenido al nuevo sistema',
                        'body' => 'Esta es la primera notificación sincronizada desde el Admin API central. Apretá siguiente para continuar.',
                        'sort_order' => 1,
                    ],
                    [
                        'title' => 'Nuevas funcionalidades',
                        'body' => 'Ahora las notificaciones de versión se publican automáticamente desde el panel central.',
                        'sort_order' => 2,
                    ],
                ]
            ],
            [
                'version' => '1.0.2',
                'title' => 'Versión 2',
                'description' => 'Versión de ejemplo 2.',
                'status' => 'published',
                'published_at' => now(),
                'notifications' => [
                    [
                        'title' => 'Notificacion 1 de version 2',
                        'body' => 'Esta es la primera notificación sincronizada desde el Admin API central. Apretá siguiente para continuar.',
                        'sort_order' => 1,
                    ],
                    [
                        'title' => 'Notificacion 1 de version 2',
                        'body' => 'Ahora las notificaciones de versión se publican automáticamente desde el panel central.',
                        'sort_order' => 2,
                    ],
                ]
            ],
            [
                'version' => '1.0.3',
                'title' => 'Versión 3',
                'description' => 'Versión de ejemplo 3.',
                'status' => 'published',
                'published_at' => now(),
                'notifications' => [
                    [
                        'title' => 'Notificacion 1 de version 3',
                        'body' => 'Esta es la primera notificación sincronizada desde el Admin API central. Apretá siguiente para continuar.',
                        'sort_order' => 1,
                    ],
                    [
                        'title' => 'Notificacion 1 de version 3',
                        'body' => 'Ahora las notificaciones de versión se publican automáticamente desde el panel central.',
                        'sort_order' => 2,
                    ],
                ]
            ],
        ];


        foreach ($versions as $version) {
            $version_model = Version::create([
                'version'       => $version['version'],
                'title'         => $version['title'],
                'description'       => $version['description'],
                'status'        => $version['status'], 
                'published_at'      => $version['published_at'], 
            ]);

            foreach ($version['notifications'] as $notification) {
                VersionNotification::create([
                    'version_id'    => $version_model->id,
                    'title'         => $notification['title'],
                    'body'         => $notification['body'],
                    'is_active'     => true,
                ]);
            }


            VersionSeederModel::firstOrCreate(
                ['version_id' => $version_model->id, 'seeder_class' => 'Database\\Seeders\\DemoDataSeeder'],
                [
                    'description' => 'Seeder opcional (solo administrado, no se envía al cliente).',
                    'execution_order' => 1,
                    'is_required' => false,
                ]
            );

            VersionCommand::firstOrCreate(
                ['version_id' => $version_model->id, 'command' => 'php artisan cache:clear'],
                [
                    'description' => 'Limpiar cache al desplegar (solo administrado, no se envía al cliente).',
                    'execution_order' => 1,
                    'is_required' => true,
                ]
            );

            VersionManualTask::firstOrCreate(
                ['version_id' => $version_model->id, 'title' => 'Revisar backup previo'],
                [
                    'description' => 'Asegurarse que el backup de la base del cliente corrió antes del upgrade.',
                    'execution_order' => 1,
                    'is_required' => true,
                ]
            );

            $this->command->info("Versión '.$version_model->version.' creada");
        }

    }
}
