<?php

namespace Database\Seeders;

use App\Models\EnvTemplate;
use Illuminate\Database\Seeder;

/**
 * Siembra las variables .env del template de tienda-api (scope='tienda').
 *
 * tienda-api comparte la misma base de datos que empresa-api del mismo cliente, así
 * que casi todas las variables comunes (mail, pusher, drivers) son idénticas a las
 * de la plantilla de empresa. Las variables derivadas del dominio de la tienda o
 * per-cliente (APP_KEY, DB_*, APP_URL, SANCTUM_*, etc.) se dejan con value vacío:
 * las completa el pipeline de instalación por código (grupo 162), no se cargan a mano
 * (por eso is_manual_on_create = false en todas: no aplica el flujo de "recordatorio
 * manual al crear sistema" que sí usa la plantilla de empresa).
 *
 * Idempotente: updateOrCreate por ['key', 'scope' => 'tienda'] (no duplica ni pisa
 * el value si ya fue editado desde el panel, salvo que se vuelva a correr con el
 * mismo valor por defecto).
 */
class EnvTemplateTiendaSeeder extends Seeder
{
    /**
     * Siembra o actualiza el template de variables .env de tienda-api.
     *
     * @return void
     */
    public function run()
    {
        foreach (self::rows() as $row) {
            EnvTemplate::updateOrCreate(
                ['key' => $row['key'], 'scope' => 'tienda'],
                $row
            );
        }
    }

    /**
     * Filas de la plantilla de tienda-api (scope='tienda').
     *
     * Expuesto como método estático para que EnvTemplateTiendaStandaloneSeeder pueda
     * reutilizarlo sin duplicar la definición (mismo patrón que EnvTemplateSeeder::rows()
     * y EcommerceImplementationStageConfigSeeder::stage_configs()).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rows(): array
    {
        /* Filas de la plantilla base de empresa, indexadas por key, para reutilizar
         * valores comunes (PUSHER_*) sin hardcodear secretos nuevos acá. */
        $empresa_rows_by_key = collect(EnvTemplateSeeder::rows())->keyBy('key');

        /* Helper local: valor de una key de la plantilla de empresa (o null si no existe). */
        $empresa_value = static function (string $key) use ($empresa_rows_by_key) {
            return $empresa_rows_by_key->has($key) ? $empresa_rows_by_key[$key]['value'] : null;
        };

        return [
            // ── Comunes: iguales para todos los clientes (is_common = true) ──────────
            ['key' => 'APP_ENV',            'value' => 'production',  'group' => 'app',    'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 1],
            ['key' => 'APP_DEBUG',          'value' => 'false',       'group' => 'app',    'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 2],
            ['key' => 'LOG_CHANNEL',        'value' => 'stack',       'group' => 'app',    'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 3],
            ['key' => 'DB_CONNECTION',      'value' => 'mysql',       'group' => 'db',     'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 1],
            ['key' => 'DB_HOST',            'value' => '127.0.0.1',   'group' => 'db',     'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 2],
            ['key' => 'DB_PORT',            'value' => '3306',        'group' => 'db',     'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 3],
            ['key' => 'BROADCAST_DRIVER',   'value' => 'pusher',      'group' => 'misc',   'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 1],
            ['key' => 'CACHE_DRIVER',       'value' => 'array',       'group' => 'misc',   'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 2],
            ['key' => 'QUEUE_CONNECTION',   'value' => 'sync',        'group' => 'misc',   'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 3],
            ['key' => 'SESSION_DRIVER',     'value' => 'file',        'group' => 'misc',   'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 4],
            ['key' => 'SESSION_LIFETIME',   'value' => '10000',       'group' => 'misc',   'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 5],
            ['key' => 'REDIS_HOST',         'value' => '127.0.0.1',   'group' => 'misc',   'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 6],
            ['key' => 'REDIS_PASSWORD',     'value' => 'null',        'group' => 'misc',   'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 7],
            ['key' => 'REDIS_PORT',         'value' => '6379',        'group' => 'misc',   'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 8],
            ['key' => 'MAIL_DRIVER',        'value' => 'smtp',        'group' => 'mail',   'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 1],
            ['key' => 'MAIL_HOST',          'value' => 'smtp.hostinger.com', 'group' => 'mail', 'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 2],
            ['key' => 'MAIL_PORT',          'value' => '465',         'group' => 'mail',   'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 3],
            ['key' => 'MAIL_ENCRYPTION',    'value' => 'ssl',         'group' => 'mail',   'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 4],
            ['key' => 'MAIL_USERNAME',      'value' => 'sistemas@comerciocity.com', 'group' => 'mail', 'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 5],
            ['key' => 'MAIL_FROM_ADDRESS',  'value' => 'sistemas@comerciocity.com', 'group' => 'mail', 'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 6],
            ['key' => 'MAIL_FROM_NAME',     'value' => 'sistemas@comerciocity.com', 'group' => 'mail', 'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 7],
            // PUSHER_* / MIX_PUSHER_*: mismos valores que la plantilla de empresa, tomados de
            // EnvTemplateSeeder::rows() para no divergir ni duplicar secretos.
            ['key' => 'PUSHER_APP_ID',        'value' => $empresa_value('PUSHER_APP_ID'),      'group' => 'pusher', 'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 1],
            ['key' => 'PUSHER_APP_KEY',       'value' => $empresa_value('PUSHER_APP_KEY'),     'group' => 'pusher', 'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 2],
            ['key' => 'PUSHER_APP_SECRET',    'value' => $empresa_value('PUSHER_APP_SECRET'),  'group' => 'pusher', 'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 3],
            ['key' => 'PUSHER_APP_CLUSTER',   'value' => $empresa_value('PUSHER_APP_CLUSTER'), 'group' => 'pusher', 'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 4],
            ['key' => 'MIX_PUSHER_APP_KEY',     'value' => $empresa_value('PUSHER_APP_KEY'),     'group' => 'pusher', 'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 5],
            ['key' => 'MIX_PUSHER_APP_CLUSTER', 'value' => $empresa_value('PUSHER_APP_CLUSTER'), 'group' => 'pusher', 'is_common' => true, 'is_manual_on_create' => false, 'notes' => null, 'sort_order' => 6],

            // ── Per-cliente / derivadas del dominio: las completa el pipeline (is_common = false, value vacío) ──
            ['key' => 'APP_NAME',                  'value' => null, 'group' => 'app',  'is_common' => false, 'is_manual_on_create' => false, 'notes' => 'Derivada: nombre de la tienda del cliente.',                    'sort_order' => 4],
            ['key' => 'APP_KEY',                   'value' => null, 'group' => 'app',  'is_common' => false, 'is_manual_on_create' => false, 'notes' => 'Misma que empresa-api del cliente.',                            'sort_order' => 5],
            ['key' => 'APP_URL',                   'value' => null, 'group' => 'app',  'is_common' => false, 'is_manual_on_create' => false, 'notes' => 'Derivada del dominio de la tienda.',                            'sort_order' => 6],
            ['key' => 'DB_DATABASE',               'value' => null, 'group' => 'db',   'is_common' => false, 'is_manual_on_create' => false, 'notes' => 'Del .env de empresa del mismo cliente.',                        'sort_order' => 4],
            ['key' => 'DB_USERNAME',               'value' => null, 'group' => 'db',   'is_common' => false, 'is_manual_on_create' => false, 'notes' => 'Del .env de empresa del mismo cliente.',                        'sort_order' => 5],
            ['key' => 'DB_PASSWORD',               'value' => null, 'group' => 'db',   'is_common' => false, 'is_manual_on_create' => false, 'notes' => 'Del .env de empresa del mismo cliente.',                        'sort_order' => 6],
            ['key' => 'MAIL_PASSWORD',             'value' => null, 'group' => 'mail', 'is_common' => false, 'is_manual_on_create' => false, 'notes' => 'Del .env de empresa del mismo cliente.',                        'sort_order' => 8],
            ['key' => 'SANCTUM_STATEFUL_DOMAINS',  'value' => null, 'group' => 'misc',  'is_common' => false, 'is_manual_on_create' => false, 'notes' => 'Derivada del dominio de la tienda.',                            'sort_order' => 9],
            ['key' => 'SANCTUM_STATEFUL_CORS',     'value' => null, 'group' => 'misc',  'is_common' => false, 'is_manual_on_create' => false, 'notes' => 'Derivada del dominio de la tienda.',                            'sort_order' => 10],
            ['key' => 'SANCTUM_STATEFUL_CORS_WWW', 'value' => null, 'group' => 'misc',  'is_common' => false, 'is_manual_on_create' => false, 'notes' => 'Derivada del dominio de la tienda (variante con www).',         'sort_order' => 11],
            ['key' => 'SESSION_DOMAIN',            'value' => null, 'group' => 'misc',  'is_common' => false, 'is_manual_on_create' => false, 'notes' => 'Derivada del dominio de la tienda.',                            'sort_order' => 12],
            ['key' => 'AWS_ACCESS_KEY_ID',         'value' => null, 'group' => 'misc',  'is_common' => false, 'is_manual_on_create' => false, 'notes' => 'Del .env de empresa del mismo cliente (si aplica).',            'sort_order' => 13],
            ['key' => 'AWS_SECRET_ACCESS_KEY',     'value' => null, 'group' => 'misc',  'is_common' => false, 'is_manual_on_create' => false, 'notes' => 'Del .env de empresa del mismo cliente (si aplica).',            'sort_order' => 14],
            ['key' => 'AWS_DEFAULT_REGION',        'value' => null, 'group' => 'misc',  'is_common' => false, 'is_manual_on_create' => false, 'notes' => 'Del .env de empresa del mismo cliente (si aplica).',            'sort_order' => 15],
            ['key' => 'AWS_BUCKET',                'value' => null, 'group' => 'misc',  'is_common' => false, 'is_manual_on_create' => false, 'notes' => 'Del .env de empresa del mismo cliente (si aplica).',            'sort_order' => 16],
        ];
    }
}
