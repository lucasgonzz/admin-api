<?php

namespace Database\Seeders;

use App\Models\EnvTemplate;
use Illuminate\Database\Seeder;

/**
 * Siembra las variables .env del template base del sistema.
 *
 * Variables organizadas por grupo funcional:
 * - app:    configuración base de Laravel.
 * - db:     conexión a base de datos (manual por sistema).
 * - mail:   configuración de correo SMTP (común entre todos los sistemas).
 * - pusher: configuración de WebSockets Pusher (común entre todos los sistemas).
 * - misc:   drivers de queue, cache y sesión.
 *
 * Solo se ejecuta si la tabla está vacía (idempotente).
 */
class EnvTemplateSeeder extends Seeder
{
    /**
     * Siembra el template base de variables .env.
     *
     * @return void
     */
    public function run()
    {
        /* Variables del grupo "app": configuración base de Laravel. No son comunes ni manuales. */
        $app_vars = [
            ['key' => 'APP_NAME',  'value' => null,         'sort_order' => 1],
            ['key' => 'APP_ENV',   'value' => 'production', 'sort_order' => 2],
            ['key' => 'APP_KEY',   'value' => null,         'sort_order' => 3],
            ['key' => 'APP_DEBUG', 'value' => 'false',      'sort_order' => 4],
            ['key' => 'APP_URL',   'value' => null,         'sort_order' => 5],
        ];

        foreach ($app_vars as $var) {
            EnvTemplate::create([
                'key'                => $var['key'],
                'value'              => $var['value'],
                'group'              => 'app',
                'is_common'          => false,
                'is_manual_on_create'=> false,
                'notes'              => null,
                'sort_order'         => $var['sort_order'],
            ]);
        }

        /* Variables del grupo "db": credenciales de base de datos.
           Son manuales al crear porque cada sistema tiene su propia base. */
        $db_vars = [
            ['key' => 'DB_CONNECTION', 'value' => 'mysql', 'sort_order' => 1],
            ['key' => 'DB_HOST',       'value' => null,    'sort_order' => 2],
            ['key' => 'DB_PORT',       'value' => '3306',  'sort_order' => 3],
            ['key' => 'DB_DATABASE',   'value' => null,    'sort_order' => 4],
            ['key' => 'DB_USERNAME',   'value' => null,    'sort_order' => 5],
            ['key' => 'DB_PASSWORD',   'value' => null,    'sort_order' => 6],
        ];

        foreach ($db_vars as $var) {
            EnvTemplate::create([
                'key'                => $var['key'],
                'value'              => $var['value'],
                'group'              => 'db',
                'is_common'          => false,
                'is_manual_on_create'=> true,
                'notes'              => 'Configurar manualmente para cada sistema',
                'sort_order'         => $var['sort_order'],
            ]);
        }

        /* Variables del grupo "mail": configuración SMTP.
           Son comunes entre todos los sistemas (mismo servidor de correo). */
        $mail_vars = [
            ['key' => 'MAIL_MAILER',       'value' => 'smtp', 'sort_order' => 1],
            ['key' => 'MAIL_HOST',         'value' => null,   'sort_order' => 2],
            ['key' => 'MAIL_PORT',         'value' => '465',  'sort_order' => 3],
            ['key' => 'MAIL_USERNAME',     'value' => null,   'sort_order' => 4],
            ['key' => 'MAIL_PASSWORD',     'value' => null,   'sort_order' => 5],
            ['key' => 'MAIL_ENCRYPTION',   'value' => 'ssl',  'sort_order' => 6],
            ['key' => 'MAIL_FROM_ADDRESS', 'value' => null,   'sort_order' => 7],
            ['key' => 'MAIL_FROM_NAME',    'value' => null,   'sort_order' => 8],
        ];

        foreach ($mail_vars as $var) {
            EnvTemplate::create([
                'key'                => $var['key'],
                'value'              => $var['value'],
                'group'              => 'mail',
                'is_common'          => true,
                'is_manual_on_create'=> false,
                'notes'              => null,
                'sort_order'         => $var['sort_order'],
            ]);
        }

        /* Variables del grupo "pusher": credenciales de WebSockets.
           Son comunes entre todos los sistemas (misma app Pusher). */
        $pusher_vars = [
            ['key' => 'PUSHER_APP_ID',      'value' => null,  'sort_order' => 1],
            ['key' => 'PUSHER_APP_KEY',     'value' => null,  'sort_order' => 2],
            ['key' => 'PUSHER_APP_SECRET',  'value' => null,  'sort_order' => 3],
            ['key' => 'PUSHER_APP_CLUSTER', 'value' => 'sa1', 'sort_order' => 4],
        ];

        foreach ($pusher_vars as $var) {
            EnvTemplate::create([
                'key'                => $var['key'],
                'value'              => $var['value'],
                'group'              => 'pusher',
                'is_common'          => true,
                'is_manual_on_create'=> false,
                'notes'              => null,
                'sort_order'         => $var['sort_order'],
            ]);
        }

        /* Variables del grupo "misc": drivers de queue, caché y sesión. No son comunes. */
        $misc_vars = [
            ['key' => 'QUEUE_CONNECTION',  'value' => 'database', 'sort_order' => 1],
            ['key' => 'CACHE_DRIVER',      'value' => 'file',     'sort_order' => 2],
            ['key' => 'SESSION_DRIVER',    'value' => 'file',     'sort_order' => 3],
            ['key' => 'SESSION_LIFETIME',  'value' => '120',      'sort_order' => 4],
        ];

        foreach ($misc_vars as $var) {
            EnvTemplate::create([
                'key'                => $var['key'],
                'value'              => $var['value'],
                'group'              => 'misc',
                'is_common'          => false,
                'is_manual_on_create'=> false,
                'notes'              => null,
                'sort_order'         => $var['sort_order'],
            ]);
        }
    }
}
