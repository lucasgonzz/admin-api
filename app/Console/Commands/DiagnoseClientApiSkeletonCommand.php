<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientSshCredential;
use Illuminate\Console\Command;
use phpseclib3\Net\SSH2;

/**
 * Comando de diagnóstico de solo lectura: recorre la API activa de cada cliente
 * (o la de un cliente puntual) y reporta qué archivos/directorios de storage/ y
 * public/ le faltan en el hosting.
 *
 * Contexto (grupo 207, prompts 01-03): los ZIPs de upgrade excluyen a propósito
 * `public/*` y `storage/*` (contienen datos propios del cliente que no se pueden
 * pisar), así que si esos árboles quedaron incompletos por algún motivo previo
 * (limpieza manual, migración de hosting, instalación vieja), ningún upgrade
 * futuro los repone solo — hoy la única forma de enterarse es que el deploy
 * explote en el momento (caso `bellabianca2`, 23/7/2026). Este comando permite
 * detectarlo de antemano, para todos los clientes, en una sola corrida.
 *
 * No modifica absolutamente nada en ningún hosting: solo abre sesiones SSH de
 * lectura y corre chequeos de existencia (`[ -e ... ]` / `[ -d ... ]`).
 */
class DiagnoseClientApiSkeletonCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'clients:diagnose-api-skeleton
        {--client= : uuid o id de un cliente puntual (por default se revisan todos)}
        {--json : Imprime solo un JSON parseable en vez de la tabla legible}';

    /**
     * @var string
     */
    protected $description = 'Diagnostica (sin modificar nada) qué clientes tienen archivos o directorios de storage/public faltantes en su hosting';

    /**
     * Rutas relativas al api_path que tienen que existir para que la API bootee y
     * para que los upgrades (que excluyen public/ y storage/ de sus ZIPs) tengan
     * algo sobre lo que pisar. Misma lista que InstallationService::verify_api_installation()
     * (prompt 03 de este mismo grupo) — si diverge, esa es la fuente de verdad.
     *
     * @var array<int, string>
     */
    const REQUIRED_PATHS = [
        'public/index.php',
        'public/.htaccess',
        '.env',
        'vendor/autoload.php',
        'bootstrap/cache',
        'storage/framework/views',
        'storage/framework/cache/data',
        'storage/framework/sessions',
        'storage/logs',
        'storage/app/public',
    ];

    /**
     * Punto de entrada del comando.
     *
     * Arma la lista de ClientApi a revisar, las agrupa por hosting_type (todos los
     * clientes de un mismo hosting_type comparten credencial SSH, así que alcanza
     * con una sesión por grupo), corre el diagnóstico remoto de cada grupo y
     * finalmente imprime el resultado (tabla legible o JSON según --json).
     *
     * @return int  0 siempre que la corrida en sí no tenga un error fatal (clientes
     *              con faltantes no son un error del comando, son el resultado esperado).
     */
    public function handle(): int
    {
        $is_json = (bool) $this->option('json');

        // Junta las ClientApi a diagnosticar: por default, la active_client_api de
        // cada Client que tenga una configurada. Con --client, solo la de ese cliente
        // (aceptando uuid o id numérico).
        $client_filter = $this->option('client');

        $clients_query = Client::query()
            ->whereNotNull('active_client_api_id')
            ->with('active_client_api');

        if ($client_filter !== null && $client_filter !== '') {
            $clients_query->where(function ($query) use ($client_filter) {
                $query->where('uuid', $client_filter);
                if (is_numeric($client_filter)) {
                    $query->orWhere('id', (int) $client_filter);
                }
            });
        }

        $clients = $clients_query->get()->filter(function (Client $client) {
            // Filtra clientes cuya active_client_api quedó apuntando a un registro
            // borrado (FK sin constraint real, puede pasar).
            return $client->active_client_api !== null;
        });

        if ($clients->isEmpty()) {
            if ($is_json) {
                $this->line(json_encode(['clients_checked' => 0, 'results' => []]));
            } else {
                $this->warn('No se encontró ningún cliente con active_client_api configurada para el filtro dado.');
            }

            return 0;
        }

        // Agrupa por hosting_type de la API activa (shared_hosting o vps): una sola
        // sesión SSH por grupo revisa a todos sus clientes.
        $clients_by_hosting_type = $clients->groupBy(function (Client $client) {
            return $client->active_client_api->hosting_type ?: 'shared_hosting';
        });

        // Resultado consolidado por cliente, se va completando grupo por grupo.
        $results = [];

        foreach ($clients_by_hosting_type as $hosting_type => $group_clients) {
            $this->diagnose_hosting_group($hosting_type, $group_clients, $results, $is_json);
        }

        $this->print_results($results, $is_json);

        return 0;
    }

    /**
     * Diagnostica un grupo de clientes que comparten hosting_type: abre una sola
     * sesión SSH, arma un único comando remoto que recorre todas las ClientApi del
     * grupo y todas las rutas requeridas, y vuelca lo que devuelve en $results.
     *
     * @param  string  $hosting_type
     * @param  \Illuminate\Support\Collection<int, Client>  $group_clients
     * @param  array  &$results  Acumulador de resultados por cliente (se modifica por referencia)
     * @param  bool  $is_json  Si está activo, no se imprimen mensajes informativos intermedios
     * @return void
     */
    private function diagnose_hosting_group(string $hosting_type, $group_clients, array &$results, bool $is_json): void
    {
        // Arma, para cada cliente del grupo, el api_path remoto a revisar. Los que
        // no puedan resolver un path válido (ej. vps sin vps_path configurado)
        // quedan marcados como no verificables sin abrir sesión SSH por eso.
        $entries = [];
        foreach ($group_clients as $client) {
            /** @var ClientApi $api */
            $api = $client->active_client_api;
            $api_path = $this->resolve_api_path($api);

            if ($api_path === '') {
                $results[] = [
                    'client_name' => $client->resolve_display_name(),
                    'client_id'   => $client->id,
                    'hosting_type' => $hosting_type,
                    'api_path'    => null,
                    'status'      => 'no_verificado',
                    'missing'     => [],
                    'detail'      => 'La API activa tiene hosting_type=vps sin vps_path configurado, no se pudo calcular el path remoto.',
                ];
                continue;
            }

            $entries[] = [
                'client'   => $client,
                'api'      => $api,
                'api_path' => $api_path,
            ];
        }

        if (empty($entries)) {
            return;
        }

        // Credencial SSH única para todo el grupo (compartida por hosting_type).
        $credential = ClientSshCredential::where('type', $hosting_type)->first();
        if ($credential === null) {
            foreach ($entries as $entry) {
                $results[] = $this->build_result_row($entry, $hosting_type, 'no_verificado', [], 'No hay credencial SSH configurada para hosting_type=' . $hosting_type . '.');
            }

            return;
        }

        if (! $is_json) {
            $this->info('Conectando a ' . $hosting_type . ' (' . count($entries) . ' cliente(s))...');
        }

        try {
            $ssh = new SSH2($credential->host, (int) $credential->port);
            $logged_in = $ssh->login($credential->username, $credential->password);
        } catch (\Throwable $e) {
            $logged_in = false;
        }

        if (! $logged_in) {
            foreach ($entries as $entry) {
                $results[] = $this->build_result_row($entry, $hosting_type, 'no_verificado', [], 'No se pudo conectar por SSH a ' . $hosting_type . ' (credenciales rechazadas o servidor inaccesible).');
            }

            return;
        }

        // Un único comando remoto para todo el grupo: recorre cada api_path y,
        // si el directorio existe, cada ruta requerida.
        $command = $this->build_diagnose_script($entries);
        $output = (string) $ssh->exec($command);
        $ssh->disconnect();

        $diag_done = strpos($output, 'DIAG_DONE') !== false;

        // Indexa las líneas devueltas por api_path para poder resolver cada entry.
        $missing_by_path = [];
        $missing_dir_paths = [];
        foreach (preg_split('/\r\n|\r|\n/', $output) as $output_line) {
            if (strpos($output_line, 'API_INEXISTENTE ') === 0) {
                $missing_dir_paths[trim(substr($output_line, strlen('API_INEXISTENTE ')))] = true;
                continue;
            }

            if (strpos($output_line, 'FALTA ') === 0) {
                $rest = trim(substr($output_line, strlen('FALTA ')));
                // El formato es "FALTA <api_path> <ruta_relativa>": el api_path no
                // tiene espacios, así que el primer espacio separa ambos campos.
                $parts = explode(' ', $rest, 2);
                if (count($parts) === 2) {
                    $missing_by_path[$parts[0]][] = $parts[1];
                }
            }
        }

        foreach ($entries as $entry) {
            $api_path = $entry['api_path'];

            if (! $diag_done) {
                // La sesión se cortó antes de terminar: no se puede afirmar que el
                // cliente está sano, se reporta como no verificado en vez de "ok".
                $results[] = $this->build_result_row($entry, $hosting_type, 'no_verificado', [], 'La sesión SSH del grupo se interrumpió antes de completar la verificación (sin DIAG_DONE).');
                continue;
            }

            if (isset($missing_dir_paths[$api_path])) {
                $results[] = $this->build_result_row($entry, $hosting_type, 'no_existe', [], 'El directorio de la API no existe en el hosting.');
                continue;
            }

            if (isset($missing_by_path[$api_path])) {
                $results[] = $this->build_result_row($entry, $hosting_type, 'faltantes', $missing_by_path[$api_path], '');
                continue;
            }

            $results[] = $this->build_result_row($entry, $hosting_type, 'ok', [], '');
        }
    }

    /**
     * Arma una fila de resultado a partir de un entry (cliente + api_path) y el
     * estado que le corresponde según lo que devolvió la verificación remota.
     *
     * @param  array  $entry
     * @param  string  $hosting_type
     * @param  string  $status  'ok' | 'faltantes' | 'no_existe' | 'no_verificado'
     * @param  array<int, string>  $missing
     * @param  string  $detail
     * @return array
     */
    private function build_result_row(array $entry, string $hosting_type, string $status, array $missing, string $detail): array
    {
        /** @var Client $client */
        $client = $entry['client'];

        return [
            'client_name'  => $client->resolve_display_name(),
            'client_id'    => $client->id,
            'hosting_type' => $hosting_type,
            'api_path'     => $entry['api_path'],
            'status'       => $status,
            'missing'      => $missing,
            'detail'       => $detail,
        ];
    }

    /**
     * Calcula el api_path remoto de una ClientApi, replicando la misma lógica que
     * DeploymentService::get_api_path() (shared_hosting: prefijo Hostinger + path
     * relativo; vps: /home/api-{vps_path}/empresa-api).
     *
     * @param  ClientApi  $api
     * @return string  Vacío si no se pudo calcular (vps sin vps_path configurado).
     */
    private function resolve_api_path(ClientApi $api): string
    {
        $hosting_type = $api->hosting_type ?: 'shared_hosting';

        if ($hosting_type === 'vps') {
            $vps_path = trim((string) ($api->vps_path ?? ''));
            if ($vps_path === '') {
                return '';
            }

            return '/home/api-' . $vps_path . '/empresa-api';
        }

        return 'domains/comerciocity.com/public_html/' . $api->path;
    }

    /**
     * Arma el único comando remoto POSIX que recorre todos los api_path de un
     * grupo (for anidado, sin brace expansion ni sintaxis específica de bash):
     * si el directorio de la API no existe, reporta una sola línea
     * "API_INEXISTENTE <api_path>" y no revisa las rutas de ese cliente; si existe,
     * reporta "FALTA <api_path> <ruta>" por cada ruta requerida ausente. Termina
     * siempre con "echo DIAG_DONE" para poder distinguir una sesión cortada de una
     * verificación real sin faltantes.
     *
     * @param  array  $entries  Lista de ['client'=>Client, 'api'=>ClientApi, 'api_path'=>string]
     * @return string
     */
    private function build_diagnose_script(array $entries): string
    {
        $client_path_args = [];
        foreach ($entries as $entry) {
            $client_path_args[] = escapeshellarg($entry['api_path']);
        }

        $required_path_args = [];
        foreach (self::REQUIRED_PATHS as $required_path) {
            $required_path_args[] = escapeshellarg($required_path);
        }

        return 'for C in ' . implode(' ', $client_path_args) . '; do '
            . 'if [ ! -d "$C" ]; then echo "API_INEXISTENTE $C"; '
            . 'else for P in ' . implode(' ', $required_path_args) . '; do '
            . '[ -e "$C/$P" ] || echo "FALTA $C $P"; done; '
            . 'fi; '
            . 'done; echo DIAG_DONE';
    }

    /**
     * Imprime el resultado final: JSON crudo (--json, nada más en la salida) o
     * tabla legible + resumen de cuántos clientes se revisaron / están completos /
     * tienen faltantes.
     *
     * @param  array  $results
     * @param  bool  $is_json
     * @return void
     */
    private function print_results(array $results, bool $is_json): void
    {
        if ($is_json) {
            $this->line(json_encode([
                'clients_checked' => count($results),
                'results'         => $results,
            ], JSON_UNESCAPED_SLASHES));

            return;
        }

        $rows_with_issues = array_values(array_filter($results, function ($row) {
            return $row['status'] !== 'ok';
        }));

        if (empty($rows_with_issues)) {
            $this->info('Todos los clientes revisados están completos.');
        } else {
            $this->table(
                ['Cliente', 'api_path', 'Estado', 'Detalle'],
                array_map(function ($row) {
                    $detalle = $row['detail'];
                    if ($row['status'] === 'faltantes') {
                        $detalle = implode(', ', $row['missing']);
                    }

                    return [
                        $row['client_name'],
                        $row['api_path'] ?: '(sin resolver)',
                        $row['status'],
                        $detalle,
                    ];
                }, $rows_with_issues)
            );
        }

        $total = count($results);
        $ok_count = count(array_filter($results, function ($row) {
            return $row['status'] === 'ok';
        }));
        $issues_count = $total - $ok_count;

        $this->line('');
        $this->info("Clientes revisados: {$total} — completos: {$ok_count} — con faltantes/no verificados: {$issues_count}");
    }
}
