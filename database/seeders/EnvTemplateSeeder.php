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
 * Idempotente: crea por key con firstOrCreate (no duplica ni sobreescribe registros existentes).
 */
class EnvTemplateSeeder extends Seeder
{
    /**
     * Siembra o actualiza el template base de variables .env.
     *
     * @return void
     */
    public function run()
    {
        /* Registros actuales de env_templates, en orden de aparición. */
        $rows = [
            ['key' => 'APP_NAME',          'value' => 'ComercioCity',                    'group' => 'app',    'is_common' => false, 'is_manual_on_create' => false, 'notes' => null,                                    'sort_order' => 1],
            ['key' => 'APP_ENV',           'value' => 'production',                      'group' => 'app',    'is_common' => false, 'is_manual_on_create' => false, 'notes' => null,                                    'sort_order' => 2],
            ['key' => 'APP_KEY',           'value' => 'base64:x+eR5AISRu5bbFdIE3wiMlWv2LWBM5pv8Q5XBj5jeDg=', 'group' => 'app', 'is_common' => false, 'is_manual_on_create' => false, 'notes' => null,               'sort_order' => 3],
            ['key' => 'APP_DEBUG',         'value' => 'false',                           'group' => 'app',    'is_common' => false, 'is_manual_on_create' => false, 'notes' => null,                                    'sort_order' => 4],
            ['key' => 'APP_URL',           'value' => null,                              'group' => 'app',    'is_common' => false, 'is_manual_on_create' => false, 'notes' => 'Se autocompleta con la URL del API activo del cliente al generar el .env', 'sort_order' => 5],
            ['key' => 'DB_CONNECTION',     'value' => 'mysql',                           'group' => 'db',     'is_common' => false, 'is_manual_on_create' => true,  'notes' => 'Configurar manualmente para cada sistema', 'sort_order' => 1],
            ['key' => 'DB_HOST',           'value' => '127.0.0.1',                       'group' => 'db',     'is_common' => false, 'is_manual_on_create' => true,  'notes' => 'Configurar manualmente para cada sistema', 'sort_order' => 2],
            ['key' => 'DB_PORT',           'value' => '3306',                            'group' => 'db',     'is_common' => false, 'is_manual_on_create' => true,  'notes' => 'Configurar manualmente para cada sistema', 'sort_order' => 3],
            ['key' => 'DB_DATABASE',       'value' => null,                              'group' => 'db',     'is_common' => false, 'is_manual_on_create' => true,  'notes' => 'Configurar manualmente para cada sistema', 'sort_order' => 4],
            ['key' => 'DB_USERNAME',       'value' => null,                              'group' => 'db',     'is_common' => false, 'is_manual_on_create' => true,  'notes' => 'Configurar manualmente para cada sistema', 'sort_order' => 5],
            ['key' => 'DB_PASSWORD',       'value' => null,                              'group' => 'db',     'is_common' => false, 'is_manual_on_create' => true,  'notes' => 'Configurar manualmente para cada sistema', 'sort_order' => 6],
            ['key' => 'MAIL_MAILER',       'value' => 'smtp',                            'group' => 'mail',   'is_common' => true,  'is_manual_on_create' => false, 'notes' => null,                                    'sort_order' => 1],
            ['key' => 'MAIL_HOST',         'value' => 'smtp.hostinger.com',              'group' => 'mail',   'is_common' => true,  'is_manual_on_create' => false, 'notes' => null,                                    'sort_order' => 2],
            ['key' => 'MAIL_PORT',         'value' => '465',                             'group' => 'mail',   'is_common' => true,  'is_manual_on_create' => false, 'notes' => null,                                    'sort_order' => 3],
            ['key' => 'MAIL_USERNAME',     'value' => 'sistema@comerciocity.com',        'group' => 'mail',   'is_common' => true,  'is_manual_on_create' => false, 'notes' => null,                                    'sort_order' => 4],
            ['key' => 'MAIL_PASSWORD',     'value' => 'w3|mdz$B',                       'group' => 'mail',   'is_common' => true,  'is_manual_on_create' => false, 'notes' => null,                                    'sort_order' => 5],
            ['key' => 'MAIL_ENCRYPTION',   'value' => 'ssl',                             'group' => 'mail',   'is_common' => true,  'is_manual_on_create' => false, 'notes' => null,                                    'sort_order' => 6],
            ['key' => 'MAIL_FROM_ADDRESS', 'value' => 'sistema@comerciocity.com',        'group' => 'mail',   'is_common' => true,  'is_manual_on_create' => false, 'notes' => null,                                    'sort_order' => 7],
            ['key' => 'MAIL_FROM_NAME',    'value' => 'ComercioCity Sistemas',           'group' => 'mail',   'is_common' => true,  'is_manual_on_create' => false, 'notes' => null,                                    'sort_order' => 8],
            ['key' => 'PUSHER_APP_ID',     'value' => '1561202',                         'group' => 'pusher', 'is_common' => true,  'is_manual_on_create' => false, 'notes' => null,                                    'sort_order' => 1],
            ['key' => 'PUSHER_APP_KEY',    'value' => '7fc3a66cec31239fc44e',            'group' => 'pusher', 'is_common' => true,  'is_manual_on_create' => false, 'notes' => null,                                    'sort_order' => 2],
            ['key' => 'PUSHER_APP_SECRET', 'value' => 'c0a6bc62a3bf0df98517',            'group' => 'pusher', 'is_common' => true,  'is_manual_on_create' => false, 'notes' => null,                                    'sort_order' => 3],
            ['key' => 'PUSHER_APP_CLUSTER','value' => 'sa1',                             'group' => 'pusher', 'is_common' => true,  'is_manual_on_create' => false, 'notes' => null,                                    'sort_order' => 4],
            ['key' => 'QUEUE_CONNECTION',  'value' => 'database',                        'group' => 'misc',   'is_common' => false, 'is_manual_on_create' => false, 'notes' => null,                                    'sort_order' => 1],
            ['key' => 'CACHE_DRIVER',      'value' => 'file',                            'group' => 'misc',   'is_common' => false, 'is_manual_on_create' => false, 'notes' => null,                                    'sort_order' => 2],
            ['key' => 'SESSION_DRIVER',    'value' => 'file',                            'group' => 'misc',   'is_common' => false, 'is_manual_on_create' => false, 'notes' => null,                                    'sort_order' => 3],
            ['key' => 'SESSION_LIFETIME',  'value' => '5256000',                         'group' => 'misc',   'is_common' => false, 'is_manual_on_create' => false, 'notes' => 'Minutos (~10 años). Sesión web hasta logout.', 'sort_order' => 4],
            ['key' => 'ANTHROPIC_API_KEY', 'value' => null,                              'group' => 'misc',   'is_common' => true,  'is_manual_on_create' => false, 'notes' => 'Clave de API de Anthropic para funciones de IA. Configurar el valor en el panel de plantilla .env.', 'sort_order' => 5],
            ['key' => 'SANCTUM_EXPIRATION','value' => '',                              'group' => 'misc',   'is_common' => false, 'is_manual_on_create' => false, 'notes' => 'Ignorado: sanctum.php fuerza tokens sin vencimiento.', 'sort_order' => 6],
            // Dominio de la cookie de sesión: estático, común a todos los clientes (Sanctum SPA auth).
            ['key' => 'SESSION_DOMAIN',          'value' => '.comerciocity.com',    'group' => 'misc', 'is_common' => true,  'is_manual_on_create' => false, 'notes' => 'Dominio de la cookie de sesión — común a todos los clientes (Sanctum SPA auth).', 'sort_order' => 7],
            // Nombre de la cookie de sesión: estático, común a todos los clientes.
            ['key' => 'SESSION_COOKIE',          'value' => 'comerciocity_session', 'group' => 'misc', 'is_common' => true,  'is_manual_on_create' => false, 'notes' => 'Nombre de la cookie de sesión — común a todos los clientes.',                        'sort_order' => 8],
            // Host del SPA (spa_url) de la ClientApi: se autocompleta al generar el .env de cada instalación.
            ['key' => 'SANCTUM_STATEFUL_DOMAINS','value' => null,                   'group' => 'misc', 'is_common' => false, 'is_manual_on_create' => false, 'notes' => 'Se autocompleta con el host del SPA (spa_url) del ClientApi al generar el .env.',        'sort_order' => 9],
            // URL completa del SPA (spa_url) de la ClientApi: se autocompleta al generar el .env de cada instalación.
            ['key' => 'SANCTUM_STATEFUL_CORS',   'value' => null,                   'group' => 'misc', 'is_common' => false, 'is_manual_on_create' => false, 'notes' => 'Se autocompleta con la URL del SPA (spa_url) del ClientApi al generar el .env.',        'sort_order' => 10],
            // Bloque ComercioCity del cliente (clients.user_id): se autocompleta al generar el .env de cada instalación.
            ['key' => 'USER_ID',                 'value' => null,                   'group' => 'misc', 'is_common' => false, 'is_manual_on_create' => false, 'notes' => 'Se autocompleta con clients.user_id (bloque ComercioCity) al generar el .env.',       'sort_order' => 11],
        ];

        /* Crea por key solo si no existe: no duplica ni sobreescribe valores editados en el panel. */
        foreach ($rows as $row) {
            EnvTemplate::firstOrCreate(['key' => $row['key']], $row);
        }
    }
}
