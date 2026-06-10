<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\Version;
use App\Models\VersionCommand;
use App\Models\VersionManualTask;
use App\Models\VersionNotification;
use App\Models\VersionSeeder as VersionSeederModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Resetea por completo versiones e ítems asociados y los recrea desde el Excel
 * Actualizaciones.xlsx (exportado en data/actualizaciones_excel.json).
 *
 * ATENCIÓN: elimina todas las versiones, seeders, comandos, notificaciones,
 * tareas manuales, upgrades y lecturas de notificación existentes.
 * Los clientes y ClientApi se mantienen / sincronizan (no se borran).
 *
 * Para regenerar el JSON desde Excel:
 *   powershell -ExecutionPolicy Bypass -File database/seeders/_export_excel.ps1
 *
 * Ejecutar: php artisan db:seed --class=RealVersionesFreshSeeder
 */
class RealVersionesFreshSeeder extends Seeder
{
    /**
     * Ruta al JSON generado desde el Excel.
     */
    const EXCEL_JSON_PATH = __DIR__ . '/data/actualizaciones_excel.json';

    /**
     * Punto de entrada: wipe + recrear todo desde el Excel.
     *
     * @return void
     */
    public function run()
    {
        /* Validamos que exista el export del Excel */
        if (!is_readable(self::EXCEL_JSON_PATH)) {
            $this->command->error('No se encontró ' . self::EXCEL_JSON_PATH);
            $this->command->error('Ejecutá: powershell -ExecutionPolicy Bypass -File database/seeders/_export_excel.ps1');
            return;
        }

        /* Decodificamos el JSON exportado desde Actualizaciones.xlsx (sin BOM) */
        $json_raw = file_get_contents(self::EXCEL_JSON_PATH);
        $json_raw = preg_replace('/^\xEF\xBB\xBF/', '', $json_raw);
        $excel_data = json_decode($json_raw, true);
        if (!is_array($excel_data) || empty($excel_data['versions'])) {
            $this->command->error('JSON inválido o sin versiones.');
            return;
        }

        $this->command->warn('Eliminando versiones e ítems existentes...');
        $this->wipe_version_data();

        /* Clientes y ClientApi (firstOrCreate, no destructivo) */
        $clients = $this->create_clients();

        /* Versiones ordenadas semver ascendente */
        $versions_data = $this->sort_versions_by_semver($excel_data['versions']);

        foreach ($versions_data as $version_string => $version_data) {
            $version_model = Version::create([
                'version'      => $version_string,
                'title'        => 'Versión ' . $version_string,
                'description'  => $version_data['description'] ?? null,
                'status'       => 'published',
                'published_at' => now(),
            ]);

            $this->create_seeders($version_model, $version_data['seeders'] ?? []);
            $this->create_commands($version_model, $version_data['commands'] ?? []);
            $this->create_manual_tasks($version_model, $version_data['manual_tasks'] ?? []);
            $this->create_notifications($version_model, $version_data['notifications'] ?? [], $clients);

            $this->command->info('Versión ' . $version_string . ' creada.');
        }

        $this->set_client_versions($clients, $excel_data['current_versions'] ?? []);

        $this->command->info('Recarga completa desde Excel finalizada.');
    }

    // =========================================================================
    // WIPE
    // =========================================================================

    /**
     * Elimina en orden seguro todas las tablas relacionadas con versiones.
     * No borra clientes ni client_apis.
     *
     * @return void
     */
    private function wipe_version_data(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        /* Ejecuciones de updates ligadas a seeders/comandos de versión */
        DB::table('update_commands')->truncate();
        DB::table('update_seeders')->truncate();

        /* Lecturas de notificaciones por cliente */
        DB::table('client_notification_reads')->truncate();

        /* Restricciones polimórficas notif/seeders/comandos/tareas → clientes */
        DB::table('version_item_clients')->truncate();

        /* Historial de upgrades entre versiones */
        DB::table('client_version_upgrades')->truncate();

        /* Desvinculamos versión actual de clientes antes de borrar versiones */
        DB::table('clients')->update(['current_version_id' => null]);

        /* Cascada lógica: hijos de versions */
        DB::table('version_notifications')->truncate();
        DB::table('version_seeders')->truncate();
        DB::table('version_commands')->truncate();
        DB::table('version_manual_tasks')->truncate();

        DB::table('versions')->truncate();

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    // =========================================================================
    // CLIENTES
    // =========================================================================

    /**
     * Crea o recupera clientes de producción y sus dos ClientApi por subdominio.
     *
     * @return array<string, Client>
     */
    private function create_clients(): array
    {
        $clients_data = [
            'servian'       => 'Servian',
            'masquito'      => 'Masquito',
            'sanblas'       => 'SanBlas',
            '2r'            => '2R',
            'ferretotal'    => 'Ferretotal',
            'trama'         => 'Trama',
            'golden-breeze' => 'Golden Breeze',
            'leudinox'      => 'Leudinox',
            'panchito'      => 'Panchito',
            'distri-creo'   => 'Distri-Creo',
            'golonorte'     => 'GoloNorte',
            'innovate'      => 'Innovate',
            'rober'         => 'Rober',
            'san-cayetano'  => 'San Cayetano',
            'truvari'       => 'Truvari',
            'lamartina'     => 'La Martina',
            'arfren'        => 'Arfren',
            'empresa'       => 'Empresa',
            'hipermax'      => 'Empresa - HiperMax',
            'fenix'         => 'Empresa - Fenix',
            'galvan'        => 'Empresa - Galvan',
            'cf'            => 'CF2',
            'chevrocar'     => 'ChevroCar',
            '3dtisk'        => '3DTisk',
            'oliva'         => 'Oliva',
            'ffperformance' => 'FFPerformance',
            'ht5'           => 'HT5',
            'mbmalizia'     => 'MBMalizia',
            'ananda'        => 'Ananda',
            'ferremas'      => 'FerreMas',
            'lacarra'       => 'Lacarra',
            'demo'          => 'DEMO',
            'demo2'         => 'DEMO2',
            'hb'            => 'HBDistribuciones',
        ];

        $clients = [];

        foreach ($clients_data as $subdomain_slug => $name) {
            $clients[$subdomain_slug] = Client::firstOrCreate(
                ['name' => $name],
                ['is_active' => true]
            );

            $this->sync_client_apis($clients[$subdomain_slug], $subdomain_slug);
        }

        return $clients;
    }

    /**
     * Crea las dos ClientApi por subdominio (prod + réplica "2").
     *
     * @param  Client  $client
     * @param  string  $subdomain_slug
     * @return void
     */
    private function sync_client_apis(Client $client, string $subdomain_slug): void
    {
        $endpoints = [
            [
                'url'     => 'https://api-' . $subdomain_slug . '.comerciocity.com/public',
                'path'    => $subdomain_slug . '/api',
                'spa_url' => 'https://' . $subdomain_slug . '.comerciocity.com',
            ],
            [
                'url'     => 'https://api-' . $subdomain_slug . '2.comerciocity.com/public',
                'path'    => $subdomain_slug . '2/api',
                'spa_url' => 'https://' . $subdomain_slug . '2.comerciocity.com',
            ],
        ];

        foreach ($endpoints as $endpoint) {
            ClientApi::firstOrCreate(
                ['client_id' => $client->id, 'url' => $endpoint['url']],
                [
                    'path'         => $endpoint['path'],
                    'spa_url'      => $endpoint['spa_url'],
                    'hosting_type' => 'shared_hosting',
                ]
            );
        }
    }

    /**
     * Asigna current_version_id según celdas verdes del Excel (current_versions).
     *
     * @param  array<string, Client> $clients
     * @param  array<string, string> $current_versions  slug → version
     * @return void
     */
    private function set_client_versions(array $clients, array $current_versions): void
    {
        $version_map = Version::pluck('id', 'version')->all();

        foreach ($current_versions as $slug => $version_str) {
            if (!isset($clients[$slug])) {
                $this->command->warn("Slug de cliente desconocido en Excel: $slug");
                continue;
            }
            if (!isset($version_map[$version_str])) {
                $this->command->warn("Versión $version_str no encontrada para $slug");
                continue;
            }

            $clients[$slug]->update(['current_version_id' => $version_map[$version_str]]);
        }
    }

    // =========================================================================
    // CREACIÓN DE ÍTEMS (desde JSON)
    // =========================================================================

    /**
     * Crea seeders de una versión.
     *
     * @param  Version           $version_model
     * @param  array<int, array> $seeders
     * @return void
     */
    private function create_seeders(Version $version_model, array $seeders): void
    {
        $order = 1;

        foreach ($seeders as $seeder) {
            VersionSeederModel::create([
                'version_id'      => $version_model->id,
                'seeder_class'    => $seeder['seeder_class'],
                'execution_order' => $order,
                'is_required'     => true,
                'run_scope'       => $seeder['run_scope'] ?? 'per_database',
            ]);
            $order++;
        }
    }

    /**
     * Crea comandos artisan de una versión.
     *
     * @param  Version           $version_model
     * @param  array<int, array> $commands
     * @return void
     */
    private function create_commands(Version $version_model, array $commands): void
    {
        $order = 1;

        foreach ($commands as $command) {
            VersionCommand::create([
                'version_id'      => $version_model->id,
                'command'         => $command['command'],
                'execution_order' => $order,
                'is_required'     => true,
                'run_scope'       => $command['run_scope'] ?? 'per_user',
            ]);
            $order++;
        }
    }

    /**
     * Crea tareas manuales de una versión.
     *
     * @param  Version           $version_model
     * @param  array<int, array> $manual_tasks
     * @return void
     */
    private function create_manual_tasks(Version $version_model, array $manual_tasks): void
    {
        $order = 1;

        foreach ($manual_tasks as $manual_task) {
            VersionManualTask::create([
                'version_id'      => $version_model->id,
                'title'           => $this->clean_title($manual_task['title'] ?? ''),
                'description'     => $manual_task['description'] ?? null,
                'execution_order' => $order,
                'is_required'     => true,
            ]);
            $order++;
        }
    }

    /**
     * Crea notificaciones de una versión y vincula restricciones por cliente.
     *
     * @param  Version               $version_model
     * @param  array<int, array>     $notifications
     * @param  array<string, Client> $clients
     * @return void
     */
    private function create_notifications(Version $version_model, array $notifications, array $clients): void
    {
        $order = 1;

        foreach ($notifications as $notification) {
            $title = $this->clean_title($notification['title'] ?? '');
            if ($title === '') {
                continue;
            }

            $notif = VersionNotification::create([
                'version_id' => $version_model->id,
                'title'      => $title,
                'body'       => $notification['body'] ?? '',
                'sort_order' => $order,
                'is_active'  => true,
            ]);

            $slug = $notification['restricted_to_client_slug'] ?? null;
            if ($slug && isset($clients[$slug])) {
                $notif->restrictedClients()->sync([$clients[$slug]->id]);
            }

            $order++;
        }
    }

    // =========================================================================
    // UTILIDADES
    // =========================================================================

    /**
     * Ordena versiones semver de menor a mayor.
     *
     * @param  array<string, array> $versions
     * @return array<string, array>
     */
    private function sort_versions_by_semver(array $versions): array
    {
        $keys = array_keys($versions);
        usort($keys, function ($a, $b) {
            return version_compare($a, $b);
        });

        $sorted = [];
        foreach ($keys as $key) {
            $sorted[$key] = $versions[$key];
        }

        return $sorted;
    }

    /**
     * Limpia emoji y caracteres basura del título exportado desde Excel.
     *
     * @param  string $title
     * @return string
     */
    private function clean_title(string $title): string
    {
        $title = preg_replace('/^[\x{2705}\x{27A1}\s"\*]+/u', '', $title);
        $title = trim($title, " \t\n\r\0\x0B\"");

        /* Solo la primera línea como título (columna varchar 200) */
        $lines = preg_split('/\r\n|\r|\n/', $title);
        $title = trim($lines[0] ?? $title);

        if (mb_strlen($title) > 200) {
            $title = mb_substr($title, 0, 197) . '...';
        }

        return $title;
    }
}
