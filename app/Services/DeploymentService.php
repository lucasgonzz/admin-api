<?php

namespace App\Services;

use App\Events\DeploymentLogCreated;
use App\Models\ClientApi;
use App\Models\ClientSshCredential;
use App\Models\ClientVersionUpgrade;
use App\Models\DeploymentLog;
use App\Models\Version;
use App\Models\VersionCommand;
use App\Models\VersionSeeder;
use App\Services\Afip\AfipCertificateProvisionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

/**
 * Ejecuta el deployment automatizado de un cliente en hosting compartido o VPS vía SSH.
 * Soporta dos tipos de hosting para el servidor destino:
 *   - shared_hosting: Hostinger, paths relativos bajo domains/comerciocity.com/public_html/
 *   - vps: servidor VPS propio, paths absolutos bajo /home/{vps_path}/
 */
class DeploymentService
{
    /**
     * Upgrade en curso.
     *
     * @var ClientVersionUpgrade
     */
    private $upgrade;

    /**
     * API destino del deployment.
     *
     * @var ClientApi
     */
    private $target_api;

    /**
     * Credenciales SSH de hosting compartido.
     *
     * @var ClientSshCredential
     */
    private $credential;

    /**
     * Sesión SSH activa (phpseclib).
     *
     * @var SSH2|null
     */
    private $ssh;

    /**
     * Sesión SSH al VPS de builds (empresa-spa).
     *
     * @var SSH2|null
     */
    private $build_ssh;

    /**
     * Orden de etapas del pipeline de deployment.
     * Pre-cierre: compile_spa → upload_spa → upload_api → run_migrations → pause_for_crons
     * Post-cierre (negocio cerrado): run_seeders → run_commands → update_default_version → complete
     *
     * @var array<int, string>
     */
    private $steps = [
        'compile_spa',
        'upload_spa',
        'upload_api',
        'run_migrations',
        'restart_queue_workers',
        'pause_for_crons',
        'run_seeders',
        'run_commands',
        'update_default_version',
        'complete',
    ];

    /**
     * Resolver de placeholders ({user_id?}, USER_ID=…) para seeders/comandos.
     *
     * @var DeploymentRunCommandResolver
     */
    private $run_command_resolver;

    /**
     * Carga upgrade, API destino y credencial SSH según hosting_type de la API destino.
     * La credencial se carga en connect() de forma dinámica según hosting_type.
     *
     * @param  ClientVersionUpgrade  $upgrade
     */
    public function __construct(ClientVersionUpgrade $upgrade)
    {
        $this->upgrade = $upgrade;
        $this->upgrade->loadMissing('client', 'client.active_client_api', 'target_client_api', 'from_version', 'to_version');
        $this->run_command_resolver = new DeploymentRunCommandResolver();

        $this->target_api = $this->upgrade->target_client_api;
        if ($this->target_api === null) {
            throw new \RuntimeException('El upgrade no tiene API destino configurada.');
        }

        /* La credencial se resuelve dinámicamente en connect() según hosting_type de target_api */
        $this->credential = ClientSshCredential::where('type', $this->get_hosting_credential_type())->firstOrFail();
    }

    /**
     * Conecta por SSH al servidor destino del cliente (shared_hosting o VPS según hosting_type).
     * Resuelve la credencial correcta antes de conectar.
     *
     * @return void
     */
    public function connect()
    {
        $this->disconnect_hosting_ssh();

        /* Seleccionar credencial según hosting_type de la API destino */
        $credential_type = $this->get_hosting_credential_type();
        $this->credential = ClientSshCredential::where('type', $credential_type)->firstOrFail();

        $this->ssh = new SSH2($this->credential->host, (int) $this->credential->port);

        $logged_in = $this->ssh->login($this->credential->username, $this->credential->password);
        if (! $logged_in) {
            throw new \RuntimeException('No se pudo conectar por SSH: credenciales rechazadas.');
        }
    }

    /**
     * Orquesta todas las etapas del deployment (opcionalmente reanudando desde una etapa).
     *
     * @param  string|null  $resume_from_step
     * @return void
     */
    public function run($resume_from_step = null)
    {
        try {
            $this->execute_steps($resume_from_step);
        } catch (\Throwable $e) {
            $this->log('deployment', $e->getMessage(), 'error');
            $this->upgrade->deployment_status = 'failed';
            $this->upgrade->save();
            throw $e;
        }
    }

    /**
     * Ejecuta las etapas en orden, respetando resume_from_step.
     * Tras cada etapa que corresponde a un paso del upgrade (timestamps), marca el campo en el modelo.
     *
     * @param  string|null  $resume_from_step
     * @return void
     */
    private function execute_steps($resume_from_step = null)
    {
        $started = ($resume_from_step === null || $resume_from_step === '');

        foreach ($this->steps as $step) {
            if (! $started) {
                if ($step === $resume_from_step) {
                    $started = true;
                } else {
                    continue;
                }
            }

            switch ($step) {
                case 'compile_spa':
                    $this->step_compile_spa();
                    break;
                case 'upload_spa':
                    $this->step_upload_spa();
                    break;
                case 'upload_api':
                    $this->step_upload_api();
                    // Marca el paso "Sistema actualizado" una vez que SPA y API están subidos.
                    $this->mark_upgrade_step_timestamp('sistema_actualizado_at');
                    break;
                case 'run_migrations':
                    $this->step_run_migrations();
                    // Marca el paso "Migraciones corridas" automáticamente.
                    $this->mark_upgrade_step_timestamp('migraciones_corridas_at');
                    break;
                case 'restart_queue_workers':
                    $this->step_restart_queue_workers();
                    break;
                case 'pause_for_crons':
                    $this->step_pause_for_crons();
                    return;
                case 'run_seeders':
                    $this->step_run_seeders();
                    // Marca el paso "Seeders ejecutados" automáticamente.
                    $this->mark_upgrade_step_timestamp('seeders_ejecutados_at');
                    break;
                case 'run_commands':
                    $this->step_run_commands();
                    // Marca el paso "Comandos ejecutados" automáticamente.
                    $this->mark_upgrade_step_timestamp('comandos_ejecutados_at');
                    // Pausa manual: espera botón para configurar URL/versión por defecto.
                    $this->step_pause_for_post_tasks();
                    return;
                case 'update_default_version':
                    $this->step_update_default_version();
                    // Solo marca "Sistema configurado" si el paso terminó en éxito real. Si degradó a
                    // manual_required, el timestamp queda sin marcar para que se vea pendiente en el
                    // panel hasta que el operador resuelva el cambio a mano en el servidor del cliente.
                    if ($this->upgrade->fresh()->default_version_sync_status === 'success') {
                        $this->mark_upgrade_step_timestamp('sistema_configurado_at');
                    }
                    break;
                case 'complete':
                    $this->step_complete();
                    break;
            }
        }
    }

    /**
     * Etapa: checkout del tag en VPS de builds y compilación del SPA (npm ci + npm run build).
     * Genera el directorio dist/ en el VPS listo para ser empaquetado y subido en step_upload_spa().
     *
     * @return void
     */
    private function step_compile_spa()
    {
        $this->connect_build_vps();
        $this->log('compile_spa', 'Conectado al VPS de builds');

        $spa_build_path = $this->builds_spa_path();
        $tag = 'v' . $this->upgrade->to_version->version;
        $this->exec_build_ssh(
            'compile_spa',
            'cd ' . escapeshellarg($spa_build_path) . ' && git fetch --tags 2>&1'
        );
        $checkout_output = $this->exec_build_ssh(
            'compile_spa',
            'cd ' . escapeshellarg($spa_build_path) . ' && git checkout ' . escapeshellarg($tag) . ' 2>&1'
        );
        $this->log('compile_spa', "Checkout {$tag}: " . $this->truncate_for_log($checkout_output));

        $api_url = $this->get_api_url_for_env();
        $spa_url = trim((string) $this->target_api->spa_url);
        if ($spa_url === '') {
            throw new \RuntimeException(
                'La API destino (target_client_api) no tiene spa_url. '
                . 'Configúrela en el cliente (ClientApi) antes de compilar empresa-spa.'
            );
        }
        $env_content = $this->build_spa_env_file_content($api_url, $spa_url);
        $env_escaped = str_replace("'", "'\\''", $env_content);
        $env_file = $spa_build_path . '/.env';
        $this->exec_build_ssh(
            'compile_spa',
            "printf '%s' '{$env_escaped}' > " . escapeshellarg($env_file)
        );
        $this->log(
            'compile_spa',
            "Archivo .env configurado — API: {$api_url} | SPA: {$spa_url} | Pusher cluster: "
            . trim((string) config('services.deploy.spa_pusher_cluster', 'sa1'))
        );

        $npm_bin = trim((string) config('services.deploy.npm_bin', 'npm'));
        $this->assert_vps_npm_available($spa_build_path, $npm_bin);

        $spa_output_dir = $this->spa_output_dir_name();

        $this->log('compile_spa', 'Instalando dependencias (npm ci)...');
        $this->exec_build_ssh(
            'compile_spa',
            $this->build_vps_command(
                $spa_build_path,
                escapeshellarg($npm_bin) . ' ci --no-audit --no-fund 2>&1'
            ),
            true,
            true
        );
        $this->log('compile_spa', 'Dependencias npm instaladas', 'success');

        $npm_build_cmd = $this->build_vps_npm_run_command($npm_bin, 'build');
        $this->log('compile_spa', 'Iniciando npm run build (NODE_OPTIONS para webpack en Linux)...');
        $build_output = $this->exec_build_ssh(
            'compile_spa',
            $this->build_vps_command($spa_build_path, $npm_build_cmd),
            true,
            true
        );
        if (! $this->spa_npm_build_output_indicates_success($build_output)) {
            throw new \RuntimeException(
                'npm run build no finalizó correctamente (no se detectó "Build complete" en la salida). '
                . $this->truncate_for_log($build_output, 800)
            );
        }
        $this->log('compile_spa', 'Build completado exitosamente', 'success');

        // Tras npm run build el canal SSH de phpseclib queda abierto; reconectar antes del zip.
        $this->reconnect_build_vps();
        $this->log('compile_spa', 'Reconectado al VPS tras el build');

        $this->assert_spa_dist_directory_on_vps($spa_build_path, $spa_output_dir);
    }

    /**
     * Etapa: empaquetado del dist/ compilado y despliegue en hosting compartido.
     * Depende de que step_compile_spa() haya generado el dist/ en el VPS de builds.
     *
     * @return void
     */
    private function step_upload_spa()
    {
        // Asegura conexión activa al VPS de builds (restablece si fue cerrada entre etapas).
        $this->connect_build_vps();

        $spa_build_path = $this->builds_spa_path();
        $spa_output_dir = $this->spa_output_dir_name();

        // ZIP con index.html en la raíz del archivo (contenido de dist/, no la carpeta dist/).
        $spa_zip_remote = $spa_build_path . '/dist.zip';
        $dist_dir = $spa_build_path . '/' . $spa_output_dir;
        $this->exec_build_ssh(
            'upload_spa',
            'cd ' . escapeshellarg($dist_dir)
            . ' && rm -f ../dist.zip && zip -r ../dist.zip . 2>&1',
            true,
            true
        );
        $spa_zip_bytes = $this->verify_zip_on_vps($spa_zip_remote, 'upload_spa');
        $this->log('upload_spa', "{$spa_output_dir}/ comprimido ({$spa_zip_bytes} bytes en VPS)");

        $deployments_dir = storage_path('app/deployments');
        if (! is_dir($deployments_dir)) {
            mkdir($deployments_dir, 0755, true);
        }

        $local_zip = storage_path('app/deployments/dist_' . $this->upgrade->uuid . '.zip');
        $sftp_build = $this->open_sftp_session('vps');
        $this->sftp_download_file($sftp_build, $spa_zip_remote, $local_zip, $spa_zip_bytes, 'upload_spa');
        $this->log('upload_spa', 'ZIP descargado al servidor de admin');

        /* Construir el path destino del ZIP según hosting_type */
        $hosting_type = $this->target_api->hosting_type ?? 'shared_hosting';
        if ($hosting_type === 'vps') {
            /* En VPS get_spa_hosting_dir() devuelve path absoluto */
            $hosting_zip_remote = $this->get_spa_hosting_dir() . '/dist.zip';
        } else {
            /* En shared_hosting el path del zip es relativo al home del usuario SSH */
            $spa_path = $this->get_spa_path();
            $hosting_zip_remote = "domains/comerciocity.com/public_html/{$spa_path}/dist.zip";
        }
        $sftp_hosting = $this->open_sftp_session($this->get_hosting_credential_type());
        $this->sftp_upload_file($sftp_hosting, $local_zip, $hosting_zip_remote, 'upload_spa');
        $this->log('upload_spa', 'ZIP subido al hosting');

        $this->reconnect_hosting_ssh();
        $this->exec_hosting_ssh(
            'upload_spa',
            $this->build_spa_hosting_deploy_shell()
        );
        $this->log('upload_spa', 'SPA desplegado en public_html (contenido anterior reemplazado)', 'success');

        if (is_file($local_zip)) {
            unlink($local_zip);
        }

        $this->reconnect_build_vps();
        $this->exec_build_ssh(
            'upload_spa',
            'rm -f ' . escapeshellarg($spa_build_path . '/dist.zip')
        );
    }

    /**
     * Etapa: checkout en VPS, empaquetado y despliegue del API en hosting compartido.
     *
     * @return void
     */
    private function step_upload_api()
    {
        $this->connect_build_vps();

        $api_build_path = $this->builds_api_path();
        $tag = 'v' . $this->upgrade->to_version->version;
        $this->log('upload_api', "Preparando versión {$tag} en VPS de builds");
        $this->exec_build_ssh(
            'upload_api',
            'cd ' . escapeshellarg($api_build_path) . ' && git fetch --tags 2>&1'
        );

        $checkout_output = $this->exec_build_ssh(
            'upload_api',
            'cd ' . escapeshellarg($api_build_path) . ' && git checkout ' . escapeshellarg($tag) . ' 2>&1'
        );
        $this->log('upload_api', $this->truncate_for_log($checkout_output));

        $this->log('upload_api', 'Corriendo composer install en VPS (sin scripts de artisan; sin .env en build)...');
        $this->exec_build_ssh(
            'upload_api',
            $this->build_composer_install_command($api_build_path, true)
        );
        $this->log('upload_api', 'composer install en VPS completado', 'success');

        $zip_name = 'api_' . $this->upgrade->uuid . '.zip';
        $api_zip_remote = $api_build_path . '/' . $zip_name;
        $this->reconnect_build_vps();
        $zip_command = 'cd ' . escapeshellarg($api_build_path)
            . ' && rm -f ' . escapeshellarg($zip_name)
            . ' && zip -r ' . escapeshellarg($zip_name) . ' . '
            . "--exclude='.env' --exclude='vendor/*' --exclude='storage/*' --exclude='public/*'"
            . " --exclude='*.zip' --exclude='tests/*' --exclude='database/super-budgets/*'"
            . " --exclude='database/seeders/articles/*' --exclude='database/seeders/truvari/*'"
            . " --exclude='database/seeders/subcategories/*' --exclude='database/seeders/sales/*'"
            . " 2>&1";
        $this->exec_build_ssh('upload_api', $zip_command, true, true);
        $api_zip_bytes = $this->verify_zip_on_vps($api_zip_remote, 'upload_api');
        $this->log('upload_api', "API empaquetada ({$api_zip_bytes} bytes en VPS)");

        $deployments_dir = storage_path('app/deployments');
        if (! is_dir($deployments_dir)) {
            mkdir($deployments_dir, 0755, true);
        }

        $local_zip = storage_path('app/deployments/api_' . $this->upgrade->uuid . '.zip');
        $sftp_build = $this->open_sftp_session('vps');
        $this->sftp_download_file($sftp_build, $api_zip_remote, $local_zip, $api_zip_bytes, 'upload_api');
        $this->log('upload_api', 'ZIP descargado al servidor de admin');

        $api_path = $this->get_api_path();
        $remote_zip = "{$api_path}/{$zip_name}";
        /* Usar la credencial correcta según hosting_type (shared_hosting o vps) */
        $sftp_hosting = $this->open_sftp_session($this->get_hosting_credential_type());
        $this->sftp_upload_file($sftp_hosting, $local_zip, $remote_zip, 'upload_api');
        $this->log('upload_api', 'ZIP subido al hosting');

        // Reconecta SSH al hosting (sesión inicial puede estar inactiva tras operaciones largas en VPS/SFTP).
        $this->reconnect_hosting_ssh();
        $this->exec_hosting_ssh(
            'upload_api',
            "cd {$api_path} && unzip -o {$zip_name} && rm {$zip_name}",
            true,
            true
        );
        $this->log('upload_api', 'API descomprimida en el hosting');

        $this->log('upload_api', 'Corriendo composer install en hosting...');
        // Cierra canal SSH previo (phpseclib: "Please close the channel before trying to open it again").
        $this->reconnect_hosting_ssh();
        $this->exec_hosting_ssh(
            'upload_api',
            $this->build_composer_install_command($api_path, false),
            true,
            true
        );
        $this->log('upload_api', 'API lista en el hosting', 'success');

        if (is_file($local_zip)) {
            unlink($local_zip);
        }

        $this->reconnect_build_vps();
        $this->exec_build_ssh(
            'upload_api',
            'rm -f ' . escapeshellarg($api_build_path . '/' . $zip_name)
        );
        $this->log('upload_api', 'Archivos temporales eliminados');
    }

    /**
     * Etapa: limpiar caché y migraciones en el servidor remoto.
     *
     * Comienza asegurando que el esqueleto de storage/ existe (el ZIP del upgrade
     * lo excluye, así que puede estar incompleto). Luego limpia caches de artisan
     * de forma defensiva (si fallan, la etapa sigue igual), y finalmente corre
     * las migraciones (bloqueante).
     *
     * @return void
     */
    private function step_run_migrations()
    {
        $api_path = $this->get_api_path();

        // Asegurar que el árbol de storage/ existe antes de correr clears.
        $this->ensure_storage_skeleton('run_migrations');
        $this->sync_afip_certificates('run_migrations');
        $this->provision_afip_certificates('run_migrations');

        $this->log('run_migrations', 'Limpiando caché de Laravel...');

        // Borrar caches de bootstrap por shell antes de los artisan clears.
        // Un config.php cacheado inválido podría romper artisan, así que lo sacamos
        // de circulación sin depender de que artisan bootee.
        $this->run_command(
            'run_migrations',
            'cd ' . escapeshellarg($api_path)
            . ' && rm -f bootstrap/cache/config.php bootstrap/cache/routes-*.php 2>&1',
            false
        );

        // Los cuatro artisan clears: si fallan (permisos, directorios que no existen aún,
        // ownership raro) no es motivo para abortar el deploy. Se loguean como warnings.
        $clear_commands = [
            'config:clear',
            'cache:clear',
            'view:clear',
            'route:clear',
        ];

        $had_clear_errors = false;
        foreach ($clear_commands as $clear_command) {
            try {
                $clear_output = $this->run_command(
                    'run_migrations',
                    "cd {$api_path} && php artisan {$clear_command} --no-ansi 2>&1",
                    false
                );

                // Revisar si la salida contiene señales de error de artisan
                // (aunque el exit status sea 0 en algunos casos raros).
                if ($this->remote_output_indicates_failure($clear_output)) {
                    $this->log(
                        'run_migrations',
                        "Advertencia: {$clear_command} no se pudo completar. El deploy sigue igual.",
                        'warning'
                    );
                    $had_clear_errors = true;
                }
            } catch (\Throwable $e) {
                // El comando falló bloqueantemente (exit != 0 con must_succeed=true original,
                // pero acá es false así que la excepción viene de verificación extra).
                // Loguear como warning y continuar.
                $this->log(
                    'run_migrations',
                    "Advertencia: {$clear_command} falló. El deploy sigue igual. Detalle: "
                    . $this->truncate_for_log($e->getMessage(), 300),
                    'warning'
                );
                $had_clear_errors = true;
            }
        }

        if ($had_clear_errors) {
            $this->log('run_migrations', 'Caché limpiado (con advertencias)', 'warning');
        } else {
            $this->log('run_migrations', 'Caché limpiado', 'success');
        }

        $this->log('run_migrations', 'Corriendo migraciones...');
        $this->run_command(
            'run_migrations',
            "cd {$api_path} && php artisan migrate --force",
            true
        );
        $this->log('run_migrations', 'Migraciones completadas', 'success');
    }

    /**
     * Compara dos ítems del upgrade (UpdateSeeder o UpdateCommand) para ordenarlos antes
     * de ejecutarlos.
     *
     * Criterio: ORDEN SEMÁNTICO del código de versión (`VersionNumberComparator`), no el
     * `id` de la fila en `versions` — un hotfix cargado después de una minor posterior
     * tiene `id` más alto y por `id` correría último, que es justo el defecto que esta
     * misión corrigió en el resto del sistema. Los desempates quedan como estaban:
     * `execution_order` del VersionSeeder/VersionCommand y, último, el `id` del ítem.
     *
     * @param  \App\Models\UpdateSeeder|\App\Models\UpdateCommand  $a
     * @param  \App\Models\UpdateSeeder|\App\Models\UpdateCommand  $b
     * @param  string  $relacion_padre  'version_seeder' o 'version_command'
     * @return int
     */
    private function compare_update_items($a, $b, $relacion_padre)
    {
        $padre_a = $a->{$relacion_padre};
        $padre_b = $b->{$relacion_padre};

        $codigo_a = ($padre_a && $padre_a->version) ? $padre_a->version->version : null;
        $codigo_b = ($padre_b && $padre_b->version) ? $padre_b->version->version : null;

        $por_version = VersionNumberComparator::compare($codigo_a, $codigo_b);
        if ($por_version !== 0) {
            return $por_version;
        }

        $orden_a = $padre_a ? (int) $padre_a->execution_order : 0;
        $orden_b = $padre_b ? (int) $padre_b->execution_order : 0;
        if ($orden_a !== $orden_b) {
            return $orden_a <=> $orden_b;
        }

        return ((int) $a->id) <=> ((int) $b->id);
    }

    /**
     * Etapa: seeders del upgrade (mismos registros que muestra la interfaz).
     * Marca cada UpdateSeeder como exitoso o fallido al terminar.
     *
     * @return void
     */
    private function step_run_seeders()
    {
        $api_path = $this->get_api_path();

        // Cliente del upgrade: fuente del user_id ComercioCity para placeholders y USER_ID=.
        $deployment_client = $this->run_command_resolver->get_upgrade_client($this->upgrade);
        $this->log(
            'run_seeders',
            'Cliente: ' . $deployment_client->resolve_display_name()
            . ' — user_id ComercioCity: '
            . ($deployment_client->user_id !== null ? (string) $deployment_client->user_id : '(no configurado)'),
            'info'
        );

        // Orden: versión ascendente por ORDEN SEMÁNTICO del código (no por `id` de tabla)
        // y execution_order del VersionSeeder. Este es el único camino que ejecuta de
        // verdad contra el servidor del cliente: si acá el orden sale por `id`, la
        // actualización corre los seeders fuera de orden aunque el resto ya esté bien.
        $this->upgrade->loadMissing('update_seeders.version_seeder.version');
        $update_seeders = $this->upgrade->update_seeders->sort(function ($a, $b) {
            return $this->compare_update_items($a, $b, 'version_seeder');
        })->values();

        foreach ($update_seeders as $update_seeder) {
            $version_seeder = $update_seeder->version_seeder;
            if ($version_seeder === null) {
                $this->log('run_seeders', "UpdateSeeder #{$update_seeder->id} sin version_seeder asociado", 'error');
                continue;
            }

            // Seeder marcado para saltear por el operador: se omite sin error.
            if ((bool) $update_seeder->skipped) {
                $this->log(
                    'run_seeders',
                    "Seeder omitido (saltear): {$version_seeder->seeder_class}",
                    'info'
                );
                continue;
            }

            $seeder_command = $this->get_seeder_command($version_seeder);
            $resolved_seeder_command = $this->resolve_client_run_command(
                $seeder_command,
                $version_seeder->run_scope ?? null
            );
            $this->log('run_seeders', "Corriendo seeder: {$resolved_seeder_command}");

            try {
                $this->run_command(
                    'run_seeders',
                    "cd {$api_path} && {$resolved_seeder_command}"
                );
                $this->log('run_seeders', "Seeder completado: {$version_seeder->seeder_class}", 'success');

                // Marca el UpdateSeeder como exitoso en la base de datos.
                $update_seeder->update([
                    'status'      => 'exitoso',
                    'executed_at' => now(),
                    'failure_notes' => null,
                ]);
            } catch (\Throwable $e) {
                $error_message = $e->getMessage();
                $this->log(
                    'run_seeders',
                    "Seeder fallido ({$version_seeder->seeder_class}): {$error_message}",
                    'error'
                );

                // Marca el UpdateSeeder como fallido con el detalle del error.
                $update_seeder->update([
                    'status'        => 'fallido',
                    'failure_notes' => $error_message,
                    'executed_at'   => now(),
                ]);

                throw $e;
            }
        }

        $this->log('run_seeders', 'Seeders completados', 'success');
    }

    /**
     * Etapa: comandos del upgrade (mismos registros que muestra la interfaz).
     * Omite los ya exitosos y los marcados como ejecución manual (quedan pendientes).
     * Ante fallo marca ese comando y detiene el pipeline; los anteriores ya quedaron exitosos.
     *
     * @return void
     */
    private function step_run_commands()
    {
        $api_path = $this->get_api_path();

        // Cliente del upgrade: fuente del user_id ComercioCity para placeholders y USER_ID=.
        $deployment_client = $this->run_command_resolver->get_upgrade_client($this->upgrade);
        $this->log(
            'run_commands',
            'Cliente: ' . $deployment_client->resolve_display_name()
            . ' — user_id ComercioCity: '
            . ($deployment_client->user_id !== null ? (string) $deployment_client->user_id : '(no configurado)'),
            'info'
        );

        // Orden: versión ascendente por ORDEN SEMÁNTICO del código (no por `id` de tabla)
        // y execution_order del VersionCommand. Mismo motivo que en los seeders.
        $this->upgrade->loadMissing('update_commands.version_command.version');
        $update_commands = $this->upgrade->update_commands->sort(function ($a, $b) {
            return $this->compare_update_items($a, $b, 'version_command');
        })->values();

        $skipped_manual_count = 0;
        $skipped_done_count = 0;

        foreach ($update_commands as $update_command) {
            $version_command = $update_command->version_command;
            if ($version_command === null) {
                $this->log('run_commands', "UpdateCommand #{$update_command->id} sin version_command asociado", 'error');
                continue;
            }

            // Reintento o segunda pasada: no volver a ejecutar los ya exitosos.
            if ($update_command->status === 'exitoso') {
                $skipped_done_count++;
                $this->log(
                    'run_commands',
                    "Comando ya ejecutado (omitido): {$version_command->command}",
                    'info'
                );
                continue;
            }

            // Comando marcado para saltear por el operador: se omite sin error.
            if ((bool) $update_command->skipped) {
                $this->log(
                    'run_commands',
                    "Comando omitido (saltear): {$version_command->command}",
                    'info'
                );
                continue;
            }

            // Comandos configurados como manuales en la versión: se omiten en el deployment SSH.
            if ($this->is_version_command_manual($version_command)) {
                $skipped_manual_count++;
                $this->log(
                    'run_commands',
                    "Comando omitido (ejecución manual): {$version_command->command}",
                    'info'
                );
                continue;
            }

            $resolved_command = $this->resolve_client_run_command(
                $version_command->command,
                $version_command->run_scope ?? null
            );
            $this->log('run_commands', "Corriendo comando: {$resolved_command}");

            try {
                $this->run_command(
                    'run_commands',
                    "cd {$api_path} && {$resolved_command}",
                    true
                );
                $this->log('run_commands', "Comando completado: {$resolved_command}", 'success');

                // Marca el UpdateCommand como exitoso en la base de datos.
                $update_command->update([
                    'status'        => 'exitoso',
                    'executed_at'   => now(),
                    'failure_notes' => null,
                ]);
            } catch (\Throwable $e) {
                $error_message = $e->getMessage();
                $this->log(
                    'run_commands',
                    "Comando fallido ({$resolved_command}): {$error_message}",
                    'error'
                );

                // Marca el UpdateCommand como fallido con el detalle del error.
                $update_command->update([
                    'status'        => 'fallido',
                    'failure_notes' => $error_message,
                    'executed_at'   => now(),
                ]);

                throw $e;
            }
        }

        if ($skipped_manual_count > 0) {
            $this->log(
                'run_commands',
                "{$skipped_manual_count} comando(s) omitido(s) por ejecución manual (quedan pendientes).",
                'info'
            );
        }

        if ($skipped_done_count > 0) {
            $this->log(
                'run_commands',
                "{$skipped_done_count} comando(s) ya ejecutado(s) (omitidos en reintento).",
                'info'
            );
        }

        $this->log('run_commands', 'Comandos automatizados completados', 'success');
    }

    /**
     * Indica si un VersionCommand debe ejecutarse manualmente (no vía deployment SSH).
     *
     * @param  VersionCommand  $version_command
     * @return bool
     */
    private function is_version_command_manual(VersionCommand $version_command): bool
    {
        return (bool) $version_command->run_manually;
    }

    /**
     * Etapa: pausa tras seeders y comandos; espera confirmación para cambiar URL/versión por defecto.
     *
     * @return void
     */
    private function step_pause_for_post_tasks()
    {
        $this->log(
            'pause_for_post_tasks',
            'Seeders y comandos completados. Esperando configuración de URL/versión por defecto.',
            'info'
        );

        $this->upgrade->deployment_status = 'paused_post_tasks';
        $this->upgrade->save();
    }

    /**
     * Etapa: reinicio del worker de cola. Solo aplica a instancias en VPS.
     *
     * En el VPS el worker vive bajo supervisor y es un proceso de LARGA VIDA: carga las clases
     * en memoria al arrancar y no las recarga nunca. Sin este paso, después de cada deploy sigue
     * ejecutando el código viejo indefinidamente, y el negocio ve jobs que fallan por clases o
     * constantes que "no existen" aunque estén perfectas en disco.
     *
     * En shared_hosting NO hace falta: ahí el worker es el `queue:work --stop-when-empty` que
     * lanza el cron, arranca y muere cada minuto, y toma el código nuevo solo.
     *
     * Se usa `queue:restart` y no `supervisorctl restart`: el primero es graceful (el worker
     * termina el job que está procesando y recién ahí sale; supervisor lo relanza con el código
     * nuevo), el segundo lo corta en seco a mitad de un job. Además `supervisorctl` pide root y
     * la sesión del deploy no necesariamente lo es.
     *
     * @return void
     */
    private function step_restart_queue_workers()
    {
        $hosting_type = $this->target_api->hosting_type ?: 'shared_hosting';

        if ($hosting_type !== 'vps') {
            $this->log(
                'restart_queue_workers',
                'Instancia en shared_hosting: no hay worker de larga vida que reiniciar '
                . '(el cron lanza queue:work --stop-when-empty, que muere cada minuto y toma el código nuevo solo).'
            );

            return;
        }

        $api_path = $this->get_api_path();

        $this->log('restart_queue_workers', 'Reiniciando el worker de cola del VPS...');

        /* must_succeed = false a propósito: llegado este punto el código ya está subido y las
           migraciones corridas. Abortar acá dejaría el deploy a medias, que es peor que un worker
           con código viejo. Se degrada a warning para que quede visible en deployment_logs. */
        $output = $this->run_command(
            'restart_queue_workers',
            'cd ' . escapeshellarg($api_path) . ' && php artisan queue:restart --no-ansi 2>&1',
            false
        );

        $output_trimmed = trim((string) $output);

        /* `queue:restart` no reinicia nada por sí mismo: deja una marca en caché que cada worker
           lee entre job y job. Si la caché no está disponible, artisan puede devolver 0 igual, así
           que se confirma contra el texto de éxito en vez de confiar en el exit code. */
        if (stripos($output_trimmed, 'Broadcasting queue restart signal') !== false) {
            $this->log(
                'restart_queue_workers',
                'Señal de reinicio enviada: el worker va a terminar el job en curso y arrancar con el código nuevo.',
                'success'
            );

            return;
        }

        $this->log(
            'restart_queue_workers',
            'No se pudo confirmar el reinicio del worker de cola. El deploy sigue, pero el worker puede '
            . 'estar corriendo el código anterior: reinicialo a mano con "php artisan queue:restart" en '
            . $api_path . '. Salida: ' . $this->truncate_for_log($output_trimmed),
            'warning'
        );
    }

    /**
     * Etapa: pausa manual para confirmación de crons.
     *
     * @return void
     */
    private function step_pause_for_crons()
    {
        $this->log(
            'pause_for_crons',
            'Esperando confirmación manual para cambiar crons',
            'info'
        );

        $this->upgrade->deployment_status = 'paused';
        $this->upgrade->save();
    }

    /**
     * Etapa: actualización de crons (pendiente Hostinger API).
     *
     * @return void
     */
    private function step_update_crons()
    {
        $this->log(
            'update_crons',
            'Pendiente de implementación: actualización de crons via Hostinger API',
            'info'
        );
    }

    /**
     * Etapa: notificar a empresa-api la nueva URL por defecto.
     *
     * @return void
     */
    private function step_update_default_version()
    {
        $client = $this->upgrade->client;
        $resolver = new ClientEmpresaApiUrlResolver();
        $url = $resolver->admin_sync_url(
            $client,
            ClientEmpresaApiUrlResolver::UPDATE_DEFAULT_VERSION_PATH,
            $this->upgrade
        );

        $spa_url = trim((string) $this->target_api->spa_url);
        $api_url = $this->get_api_url_for_env();
        // Cola común que se agrega al final de cualquier mensaje de "acción manual", con los valores
        // concretos que el operador tiene que dejar cargados a mano en el servidor del cliente.
        $manual_action_suffix = " Hay que cambiar a mano la version estable en el servidor del cliente: "
            . "SPA {$spa_url} | API {$api_url}.";

        // Sin URL válida de destino: no es un fallo del deployment (que ya subió SPA, API, migraciones,
        // seeders y comandos), es un problema de configuración de la ClientApi. Se degrada a manual.
        if ($url === '') {
            $manual_message = 'No hay URL válida del empresa-api para update-default-version '
                . '(configure la ClientApi destino del upgrade con URL https://...).' . $manual_action_suffix;
            $this->log('update_default_version', $manual_message, 'warning');
            $this->upgrade->update([
                'default_version_sync_status'  => 'manual_required',
                'default_version_sync_message' => $manual_message,
            ]);
            return;
        }

        /**
         * 🔴 LA FALTA DE `api_key` YA NO FRENA EL PUT, Y ES EL ARREGLO MÁS IMPORTANTE DE ESTE PASO.
         *
         * Hasta el 3/9/2026 acá había un `return` que degradaba a `manual_required` sin intentar
         * nada. El problema es que esa precondición no la exige el otro lado: el middleware
         * `AdminApiKey` de empresa-api arranca con
         *
         *     if (! config('services.admin_api.require_api_key', false)) { return $next($request); }
         *
         * y ese config sale de `env('ADMIN_SYNC_REQUIRE_API_KEY', false)` (config/services.php:65).
         * Medido el 3/9/2026: NINGUNO de los 97 .env del shared hosting define esa variable, y en
         * la instancia de masquito `config()` devuelve `bool(false)`. O sea que el endpoint acepta
         * el PUT sin ninguna clave, y el admin se estaba auto-bloqueando solo.
         *
         * Lo que costaba: 20 de los 44 clientes activos tienen `clients.api_key` vacío. A todos
         * ellos el deployment les terminaba en `completed` mientras `users.default_version` y
         * `users.api_url` seguían apuntando a la carpeta VIEJA — o sea, el negocio siguiendo con el
         * código anterior sobre la base ya migrada. Sin error, sin fallo, solo un warning en un log
         * que nadie mira. Le pasó a masquito ese mismo día (upgrade 76) y se descubrió de casualidad.
         *
         * Desde acá: el header viaja solo si hay clave, el PUT se intenta SIEMPRE, y la degradación
         * a manual queda para lo que de verdad falló (401/403, otro HTTP de error, o transporte),
         * con el motivo de ESE intento y no de una precondición.
         */
        $headers = [
            'Accept' => 'application/json',
        ];

        if (! empty($client->api_key)) {
            $headers['X-Admin-Api-Key'] = $client->api_key;
        } else {
            $this->log(
                'update_default_version',
                'El cliente no tiene api_key cargada: se intenta el PUT sin el header '
                . 'X-Admin-Api-Key. El empresa-api solo lo exige si tiene '
                . 'ADMIN_SYNC_REQUIRE_API_KEY=true en su .env; si lo exige, contesta 401 y este '
                . 'paso queda como pendiente manual.',
                'info'
            );
        }

        $this->log(
            'update_default_version',
            "PUT {$url} — SPA: {$spa_url} | API: {$api_url}"
        );

        // Respuesta HTTP real (si se pudo obtener) y mensaje de error de transporte (si no hubo respuesta).
        $response = null;
        $transport_error = '';

        try {
            $response = Http::withHeaders($headers)
                ->timeout((int) config('services.client_api.timeout', 15))
                ->retry((int) config('services.client_api.retries', 2), 500)
                ->put($url, [
                    'spa_url'         => $spa_url,
                    'default_version' => $spa_url,
                    'api_url'         => $api_url,
                ]);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            // En Laravel 8, ->retry() convierte cualquier respuesta no-2xx en excepción antes de que
            // put() devuelva algo (PendingRequest::send() llama $response->throw() dentro del retry()
            // cuando $tries > 1). Por eso la rama del 404 de más abajo quedaba como código muerto:
            // recuperamos acá la respuesta real desde la excepción para poder seguir evaluándola.
            $response = $e->response;
        } catch (\Throwable $e) {
            // ConnectionException, timeout, DNS, etc.: no hay respuesta HTTP asociada.
            $transport_error = $e->getMessage();
        }

        // Caso exitoso: empresa-api confirmó el cambio de versión/URL por defecto.
        if ($response !== null && $response->successful()) {
            $body = $response->body();
            $this->log('update_default_version', 'HTTP ' . $response->status() . ': ' . substr($body, 0, 2000));
            $this->upgrade->update([
                'default_version_sync_status'  => 'success',
                'default_version_sync_message' => null,
            ]);
            return;
        }

        /*
         * Cualquier otro desenlace (404, otro código HTTP, o sin respuesta por error de transporte) no
         * tumba el deployment: se deja seguir el pipeline (incluido step_complete(), que igual activa
         * la ClientApi destino en el admin) y se registra como pendiente de acción manual, con el
         * motivo real para que el operador sepa qué pasó.
         */
        if ($response !== null && $response->status() === 404) {
            // 404 = la ruta admin-sync/update-default-version no existe en esta instancia de empresa-api,
            // o la URL de la ClientApi está mal cargada (típico: falta el /public de hosting compartido).
            $manual_message = "empresa-api de este cliente respondió 404 en {$url}. Puede ser que la "
                . 'versión instalada todavía no tenga el endpoint admin-sync/update-default-version '
                . '(versión desactualizada), o que la URL de la ClientApi esté mal cargada (revisar si '
                . 'falta el /public de hosting compartido).' . $manual_action_suffix;
        } elseif ($response !== null) {
            $body = substr((string) $response->body(), 0, 300);
            $manual_message = 'El empresa-api del cliente respondió HTTP ' . $response->status() . ': '
                . $body;
            if ($response->status() === 401 || $response->status() === 403) {
                /*
                 * Con el PUT intentándose siempre (con o sin header), un 401 tiene dos causas
                 * distintas y conviene nombrar la que corresponde: si el cliente NO tenía api_key
                 * cargada, es que esa instancia sí exige la clave; si la tenía, es que no coincide.
                 */
                $manual_message .= empty($client->api_key)
                    ? ' Este cliente no tiene api_key cargada en el admin y su empresa-api SÍ la '
                        . 'exige (tiene ADMIN_SYNC_REQUIRE_API_KEY=true en el .env). Cargá en '
                        . 'clients.api_key el mismo valor que ADMIN_API_INBOUND_KEY de esa instancia.'
                    : ' La api_key del cliente no coincide con ADMIN_API_INBOUND_KEY del empresa-api.';
            }
            $manual_message .= $manual_action_suffix;
        } else {
            $manual_message = "No se pudo contactar al empresa-api del cliente en {$url}: "
                . $transport_error . $manual_action_suffix;
        }

        // Nivel 'warning' (no 'error'): no es un fallo del deployment, el detalle vive en
        // default_version_sync_message y el operador lo resuelve a mano desde el panel.
        $this->log('update_default_version', $manual_message, 'warning');

        $this->upgrade->update([
            'default_version_sync_status'  => 'manual_required',
            'default_version_sync_message' => $manual_message,
        ]);
    }

    /**
     * Etapa: marcar deployment completado y activar API destino en el cliente.
     *
     * @return void
     */
    private function step_complete()
    {
        /**
         * La promocion de la API activa va PRIMERO y el cierre del upgrade despues, y el orden
         * importa: el hook `saved` de ClientVersionUpgrade alinea `clients.current_version_id`
         * cuando el status pasa a `terminada`, y lo hace sobre una instancia fresca del cliente.
         * Guardando aca abajo una instancia que puede venir de mucho antes en el pipeline se
         * corria el riesgo de escribir el cliente despues del hook.
         */
        $client = $this->upgrade->client;
        $client->active_client_api_id = $this->target_api->id;
        $client->save();

        /**
         * 🔴 Un deployment que llega hasta aca dejaba el upgrade con el status que tuviera y la
         * version del cliente sin mover: `terminada` solo la escribia
         * PublishVersionService::syncExisting(), que es el boton aparte "sincronizar al cliente".
         * El caso que lo dejo a la vista es el cliente Servian (upgrade 56, 1/8/2026): deployment
         * `completed` con los seis pasos hechos, y el cliente siguio figurando en 3.3.1 con la
         * 3.3.3 arriba. La version la alinea el hook `saved` del modelo, que se dispara con este
         * mismo save().
         */
        $this->upgrade->deployment_status = 'completed';
        $this->upgrade->status            = 'terminada';
        if (is_null($this->upgrade->finished_at)) {
            $this->upgrade->finished_at = now();
        }
        $this->upgrade->save();

        $this->log('complete', 'Deployment completado exitosamente', 'success');
    }

    /**
     * Conecta por SSH al VPS de builds (empresa-spa).
     *
     * @return void
     */
    private function connect_build_vps()
    {
        $this->disconnect_build_vps();

        $vps_credential = ClientSshCredential::where('type', 'vps')->firstOrFail();

        $this->build_ssh = new SSH2($vps_credential->host, (int) $vps_credential->port);

        $logged_in = $this->build_ssh->login($vps_credential->username, $vps_credential->password);
        if (! $logged_in) {
            throw new \RuntimeException('No se pudo conectar al VPS de builds: credenciales rechazadas.');
        }
    }

    /**
     * Cierra la sesión SSH al VPS de builds (evita "Please close the channel" en phpseclib).
     *
     * @return void
     */
    private function disconnect_build_vps(): void
    {
        if ($this->build_ssh !== null) {
            $this->build_ssh->disconnect();
            $this->build_ssh = null;
        }
    }

    /**
     * Reabre SSH al VPS de builds tras comandos largos (p. ej. npm run build).
     *
     * @return void
     */
    private function reconnect_build_vps(): void
    {
        $this->connect_build_vps();
    }

    /**
     * Cierra la sesión SSH al hosting compartido.
     *
     * @return void
     */
    private function disconnect_hosting_ssh(): void
    {
        if ($this->ssh !== null) {
            $this->ssh->disconnect();
            $this->ssh = null;
        }
    }

    /**
     * Reabre SSH al hosting (la conexión inicial puede quedar inactiva durante builds largos).
     *
     * @return void
     */
    private function reconnect_hosting_ssh(): void
    {
        $this->connect();
    }

    /**
     * URL de API para VUE_APP_API_URL (.env del SPA), con /public en shared_hosting si aplica.
     * Delega en ClientEmpresaApiUrlResolver para mantener la regla de normalización centralizada.
     *
     * @return string
     */
    private function get_api_url_for_env()
    {
        // Delegado a ClientEmpresaApiUrlResolver::build_api_url_for_env(), único punto que
        // decide el sufijo "/public" según hosting_type (unificado con InstallationService,
        // grupo 237).
        $resolver = new ClientEmpresaApiUrlResolver();
        return $resolver->build_api_url_for_env($this->target_api);
    }

    /**
     * Ruta del SPA en el servidor destino según hosting_type.
     *
     * shared_hosting: ruta relativa derivada del path de la API (reemplaza /api por /spa).
     * vps: path absoluto construido como /home/{vps_path}/htdocs/{dominio_spa}.
     *
     * 🔴 DELEGA EN ClientApiPathResolver Y NO CALCULA NADA, desde el 31/8/2026. Acá había una
     * copia entera de esa convención, y esa copia era peligrosa por una razón puntual: el
     * `build_spa_hosting_deploy_shell()` de más arriba le pasa este valor a
     * assert_directorio_de_spa_borrable() antes de correr un `find . -mindepth 1 -delete`, y esa
     * guarda VALIDA EL STRING QUE LE PASAN, no lo recalcula. O sea que si las dos copias divergen,
     * la guarda sigue pasando y el borrado corre sobre el directorio que calculó la copia mala.
     * Agregar la guarda y dejar la duplicación que produce el valor que esa guarda valida no era
     * medio arreglo: era el arreglo con el agujero adentro.
     *
     * @return string
     * @throws \RuntimeException Si es un VPS sin vps_path o sin spa_url.
     */
    private function get_spa_path()
    {
        $resolver = new ClientApiPathResolver();

        return $resolver->resolve_spa($this->target_api);
    }

    /**
     * Contenido del .env de empresa-spa en el VPS antes de npm run build.
     *
     * @param  string  $api_url  VUE_APP_API_URL
     * @param  string  $spa_url  VUE_APP_APP_URL
     * @return string
     */
    private function build_spa_env_file_content(string $api_url, string $spa_url): string
    {
        $env_vars = [
            'VUE_APP_API_URL' => $api_url,
            'VUE_APP_APP_URL' => $spa_url,
            'VUE_APP_PUSHER_KEY' => trim((string) config('services.deploy.spa_pusher_key', '')),
            'VUE_APP_PUSHER_CLUSTER' => trim((string) config('services.deploy.spa_pusher_cluster', 'sa1')),
        ];

        $spa_build_env = config('services.deploy.spa_build_env', []);
        if (is_array($spa_build_env)) {
            foreach ($spa_build_env as $env_key => $env_value) {
                $env_vars[(string) $env_key] = trim((string) $env_value);
            }
        }

        $lines = [];
        foreach ($env_vars as $env_key => $env_value) {
            // Valores con espacios requieren comillas para que dotenv/vue-cli los interprete bien.
            if (preg_match('/\s/', $env_value) !== 0) {
                $escaped_value = str_replace('"', '\\"', $env_value);
                $lines[] = $env_key . '="' . $escaped_value . '"';
            } else {
                $lines[] = $env_key . '=' . $env_value;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Ruta SSH del directorio público del SPA en el servidor destino.
     *
     * VPS: el path ya es absoluto (ej: /home/arfren2/htdocs/arfren.comerciocity.com).
     * shared_hosting: lleva adelante el prefijo de la cuenta de Hostinger.
     *
     * 🔴 También delega, y por el mismo motivo que get_spa_path(): el prefijo
     * 'domains/comerciocity.com/public_html/' estaba escrito acá a mano y ES la raíz de la cuenta
     * compartida. Concatenarlo con un path vacío da exactamente el directorio que el
     * `find . -mindepth 1 -delete` de build_spa_hosting_deploy_shell() no puede tocar nunca. En el
     * resolver ese prefijo es una constante con su motivo escrito al lado.
     *
     * @return string
     * @throws \RuntimeException Si es un VPS sin vps_path o sin spa_url.
     */
    private function get_spa_hosting_dir(): string
    {
        $resolver = new ClientApiPathResolver();

        return $resolver->spa_hosting_dir($this->target_api);
    }

    /**
     * Script bash: vacía el public_html del SPA, descomprime dist.zip en la raíz (no en /dist).
     *
     * 🔴 ESTE MÉTODO ARMA UN BORRADO RECURSIVO, igual que su gemelo de InstallationService, y por
     * eso lleva la misma guarda dura. No es defensa teórica: si el directorio calculado sale vacío
     * —una ClientApi con hosting_type='vps' y vps_path NULL, que es como están hoy en producción
     * los clientes 13 y 43 según el relevamiento del 26/8/2026— el `find . -mindepth 1 -delete` de
     * abajo corre sobre 'domains/comerciocity.com/public_html/' y vacía la cuenta compartida
     * entera: las carpetas de los ~40 clientes activos, de una.
     *
     * La guarda nació el 31/8/2026 en InstallationService, donde el agujero se descubrió. Se adopta
     * acá en el mismo movimiento porque este es el servicio que corre en CADA actualización de
     * cliente, o sea el camino por el que el problema llegaría antes. Dejar la guarda en uno solo de
     * los dos lugares que tienen la misma línea es exactamente la clase de arreglo que vuelve con
     * otra cara — ver APRENDER_NO_PARCHEAR.md.
     *
     * Y va ANTES de armar el string, no adentro del shell remoto: un `if` del lado del servidor ya
     * viajó, y cualquier error de escapado lo saltea.
     *
     * @return string
     * @throws \RuntimeException Si el directorio calculado no es identificable como el de este cliente.
     */
    private function build_spa_hosting_deploy_shell(): string
    {
        $spa_dir = $this->get_spa_hosting_dir();

        $path_resolver = new ClientApiPathResolver();
        $path_resolver->assert_directorio_de_spa_borrable($this->target_api, $spa_dir);

        $temp_zip_basename = 'dist_deploy_' . $this->upgrade->uuid . '.zip';
        $deploy_zip_name = 'dist.zip';

        // TEMP_ZIP es relativo al SPA_DIR (../) porque el shell hace cd "$SPA_DIR" primero.
        return 'set -e; '
            . 'SPA_DIR=' . escapeshellarg($spa_dir) . '; '
            . 'TEMP_ZIP=' . escapeshellarg('../' . $temp_zip_basename) . '; '
            . 'cd "$SPA_DIR" || exit 1; '
            . 'if [ -f ' . escapeshellarg($deploy_zip_name) . ' ]; then mv '
            . escapeshellarg($deploy_zip_name) . ' "$TEMP_ZIP"; fi; '
            . 'find . -mindepth 1 -delete 2>/dev/null || true; '
            . 'if [ -f "$TEMP_ZIP" ]; then unzip -o "$TEMP_ZIP" -d .; rm -f "$TEMP_ZIP"; fi; '
            . 'echo SPA_DEPLOY_OK 2>&1';
    }

    /**
     * Asegura que el esqueleto de directorios de storage/ existe en el servidor remoto.
     *
     * Contexto: el ZIP del upgrade excluye storage/* (regla defensiva para no pisar
     * archivos del cliente), así que el árbol de storage/ solo viene de la instalación
     * inicial y puede estar incompleto si el cliente lo limpió, migró de hosting, o
     * quedó corrupto por algún motivo. Sin estos directorios, realpath() en
     * config/view.php devuelve false, ViewClearCommand falla con "View path not found",
     * y el deploy se aborta. Este método crea preventivamente el árbol completo.
     *
     * El comando enumera rutas una por una (prohibida brace expansion, que no está
     * garantizada en sh del hosting compartido), hace chmod -R solo sobre los árboles
     * chicos (storage/framework y bootstrap/cache; ver nota sobre rendimiento),
     * y no falla si los directorios ya existen (mkdir -p es no-op).
     *
     * @param  string  $step  Identificador de etapa para logs
     * @return void
     */
    private function ensure_storage_skeleton(string $step): void
    {
        $api_path = $this->get_api_path();

        $this->log($step, 'Asegurando directorios de storage...');

        // Comando: mkdir -p enumerando rutas una por una, chmod -R limitado a
        // storage/framework y bootstrap/cache (en un cliente con años de adjuntos,
        // chmod -R sobre storage/ entero puede tardar muchísimo y timeoutear SSH).
        $this->run_command(
            $step,
            'cd ' . escapeshellarg($api_path)
            . ' && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions'
            . ' storage/framework/testing storage/framework/views storage/logs bootstrap/cache'
            . ' && chmod -R 775 storage/framework bootstrap/cache'
            . ' && chmod 775 storage storage/app storage/app/public storage/logs 2>&1',
            false
        );

        $this->log($step, 'Directorios de storage asegurados', 'success');
    }

    /**
     * Ruta del API en el servidor según hosting_type, para cualquier ClientApi (no solo el destino).
     *
     * shared_hosting: prefijo Hostinger + path relativo de la API (ej: domains/comerciocity.com/public_html/colman/api).
     * vps: path absoluto construido como /home/api-{vps_path}/empresa-api.
     *
     * @param  ClientApi  $client_api
     * @return string
     */
    private function resolve_client_api_path(ClientApi $client_api): string
    {
        $resolver = new ClientApiPathResolver();

        return $resolver->resolve($client_api);
    }

    /**
     * Ruta del API en el servidor destino según hosting_type.
     *
     * @return string
     */
    private function get_api_path(): string
    {
        return $this->resolve_client_api_path($this->target_api);
    }

    /**
     * Copia storage/app/afip/ desde la ClientApi hoy activa del cliente hacia la ClientApi destino
     * de este deploy, ANTES de que se active el destino (step_update_default_version corre después,
     * al final del pipeline, así que $client->active_client_api sigue siendo la vieja acá).
     *
     * Estrictamente no destructivo: solo copia archivos que no existen ya en destino, y solo dentro
     * de storage/app/afip/ — nunca toca storage/app/public/ ni ningún otro subdirectorio, ahí viven
     * los adjuntos e imágenes reales del cliente.
     *
     * Contexto: storage/* queda excluido del ZIP de cada deploy (ver step_upload_api) y cada
     * actualización alterna de carpeta física (v1/v2, ver active_client_api_id) — storage/ no se
     * comparte entre esas dos carpetas. Sin este paso, el certificado AFIP que ya está copiado a mano
     * en la carpeta hoy activa desaparece en la próxima actualización del cliente.
     *
     * @param  string  $step
     * @return void
     */
    private function sync_afip_certificates(string $step): void
    {
        $client = $this->upgrade->client;
        $source_api = $client ? $client->active_client_api : null;

        if ($source_api === null) {
            $this->log($step, 'Sin ClientApi activa previa: nada de donde sincronizar certificados AFIP.', 'info');
            return;
        }

        if ((int) $source_api->id === (int) $this->target_api->id) {
            $this->log($step, 'La API activa ya es el destino de este deploy: nada que sincronizar.', 'info');
            return;
        }

        $source_hosting_type = $source_api->hosting_type ?? 'shared_hosting';
        $target_hosting_type = $this->target_api->hosting_type ?? 'shared_hosting';

        // Ambas tienen que ser shared_hosting en el MISMO servidor para poder resolverlo con un cp
        // dentro de la sesión SSH ya abierta. Una migración shared_hosting -> vps (u origen/destino de
        // tipos distintos) queda fuera de este sync automático: se avisa y sigue siendo manual.
        if ($source_hosting_type !== 'shared_hosting' || $target_hosting_type !== 'shared_hosting') {
            $this->log(
                $step,
                'Origen y/o destino no son shared_hosting: el sync automático de certificados AFIP '
                . 'no aplica acá (por ejemplo, migración a VPS). Copiar el certificado a mano si corresponde.',
                'warning'
            );
            return;
        }

        $source_path = $this->resolve_client_api_path($source_api);
        $target_path = $this->get_api_path();

        $this->log($step, 'Sincronizando storage/app/afip/ desde la versión activa (no destructivo)...');

        $sync_command = $this->build_afip_sync_command($source_path, $target_path);
        $output = $this->run_command($step, $sync_command, false);

        if (strpos($output, 'AFIP_SYNC_OK') !== false) {
            $this->log($step, 'Certificados AFIP sincronizados desde la versión activa.', 'success');
        } elseif (strpos($output, 'AFIP_SYNC_SKIP_NO_SOURCE') !== false) {
            $this->log($step, 'La versión activa no tiene storage/app/afip/ — nada para sincronizar.', 'info');
        } else {
            $this->log(
                $step,
                'No se pudo confirmar el sync de certificados AFIP. Si el cliente ya tenía certificado, '
                . 'revisar a mano que siga facturando después de este deploy.',
                'warning'
            );
        }
    }

    /**
     * Repone desde el servidor del admin los certificados de AFIP que el cliente siga sin tener,
     * después de que sync_afip_certificates() ya arrastró lo que había en la carpeta de la versión
     * anterior.
     *
     * Los dos pasos son complementarios y este orden importa: el sync respeta lo que el cliente ya
     * tenía (incluido un certificado propio o rotado a mano) y esto solo completa los huecos. Un
     * cliente instalado después del 26/7/2026 nunca tuvo los archivos —el ZIP de instalación sale
     * del clon de git, donde están gitignoreados— y por eso el sync no encuentra nada que copiar:
     * ese es justamente el caso que dejaba al cliente sin poder facturar.
     *
     * 🔴 Nunca aborta el deploy. Si el admin todavía no tiene los certificados cargados, o si el
     * SFTP falla, se loguea y la actualización sigue: una actualización cortada a la mitad es peor
     * que un certificado que ya venía faltando de antes. La instalación inicial sí es estricta
     * (ver InstallationService::verify_api_installation()), porque ahí todavía no hay nada en
     * producción que romper.
     *
     * @param  string  $step
     * @return void
     */
    private function provision_afip_certificates(string $step): void
    {
        $service = new AfipCertificateProvisionService();

        $log = function (string $linea, string $nivel) use ($step) {
            $this->log($step, $linea, $nivel);
        };

        $service->reponer_en_api(
            function () {
                return $this->open_sftp_session($this->get_hosting_credential_type());
            },
            $this->get_api_path(),
            $log
        );

        // El resto de step_run_migrations sigue con run_command() sobre la sesión SSH: se reconecta
        // por las dudas, igual que después de cada operación SFTP larga del resto del pipeline.
        $this->reconnect_hosting_ssh();
    }

    /**
     * Arma el comando remoto que copia storage/app/afip/ de $source_path a $target_path SIN pisar
     * ningún archivo que ya exista en destino, y sin depender de `cp --no-clobber` (no garantizado en
     * todo hosting compartido): recorre archivo por archivo con find + cp condicional.
     *
     * @param  string  $source_path  Path (relativo, shared_hosting) de la carpeta hoy activa.
     * @param  string  $target_path  Path (relativo, shared_hosting) de la carpeta destino del deploy.
     * @return string
     */
    private function build_afip_sync_command(string $source_path, string $target_path): string
    {
        $source_afip = escapeshellarg($source_path . '/storage/app/afip');
        $target_afip = escapeshellarg($target_path . '/storage/app/afip');

        return 'SRC=' . $source_afip . '; DST=' . $target_afip . '; '
            . 'if [ -d "$SRC" ]; then '
            . 'mkdir -p "$DST" && '
            . 'find "$SRC" -type f | while IFS= read -r f; do '
            . 'rel="${f#$SRC/}"; '
            . 'destfile="$DST/$rel"; '
            . 'if [ ! -e "$destfile" ]; then mkdir -p "$(dirname "$destfile")" && cp "$f" "$destfile"; fi; '
            . 'done; '
            . 'echo AFIP_SYNC_OK; '
            . 'else echo AFIP_SYNC_SKIP_NO_SOURCE; fi';
    }

    /**
     * Seeders de una versión aplicables al cliente del upgrade (restricción por pivote).
     *
     * @param  Version  $version
     * @return Collection
     */
    private function get_applicable_seeders(Version $version): Collection
    {
        $client_id = (int) $this->upgrade->client_id;

        return $version->seeders()
            ->where(function ($q) use ($client_id) {
                $q->whereDoesntHave('restrictedClients')
                    ->orWhereHas('restrictedClients', function ($sub) use ($client_id) {
                        $sub->where('clients.id', $client_id);
                    });
            })
            ->orderBy('execution_order')
            ->get();
    }

    /**
     * Comandos de una versión aplicables al cliente del upgrade (restricción por pivote).
     *
     * @param  Version  $version
     * @return Collection
     */
    private function get_applicable_commands(Version $version): Collection
    {
        $client_id = (int) $this->upgrade->client_id;

        return $version->commands()
            ->where(function ($q) use ($client_id) {
                $q->whereDoesntHave('restrictedClients')
                    ->orWhereHas('restrictedClients', function ($sub) use ($client_id) {
                        $sub->where('clients.id', $client_id);
                    });
            })
            ->orderBy('execution_order')
            ->get();
    }

    /**
     * Comando shell del seeder (atributo command o derivado de seeder_class).
     *
     * 🔴 El `--class` va SIEMPRE escapado, y el motivo es una barra invertida.
     *
     * Un `seeder_class` con namespace —`Database\Seeders\ExtencionTrackingBuyersSeeder`— se
     * concatenaba crudo y viajaba por SSH hasta el shell del hosting, que se come la barra
     * invertida porque para él es un carácter de escape. Del otro lado llegaba
     * `--class=DatabaseSeedersExtencionTrackingBuyersSeeder` y el seeder moría con
     * "Target class does not exist".
     *
     * Medido el 3/9/2026 actualizando masquito a 4.0.11: el upgrade 75 se cayó ahí, en el
     * tercero de los trece seeders, con las migraciones ya aplicadas sobre la base del cliente.
     * Seis de esos trece seeders traían el namespace adelante y los otros siete no, así que el
     * mismo deployment ejecutaba unos bien y otros no.
     *
     * `escapeshellarg()` es la función correcta acá y no la trampa que documenta
     * APRENDER_NO_PARCHEAR: esto corre en el admin (Linux) y se ejecuta en el hosting (Linux),
     * las dos puntas POSIX. Donde `escapeshellarg()` no sirve es armando desde Windows un
     * comando que corre en Linux, porque ahí usa comillas dobles.
     *
     * El `command` propio del seeder (la rama de arriba) se deja tal cual: es un comando
     * completo escrito a mano, no un argumento que estemos componiendo nosotros.
     *
     * @param  VersionSeeder  $seeder
     * @return string
     */
    private function get_seeder_command(VersionSeeder $seeder): string
    {
        if (! empty($seeder->command)) {
            return $seeder->command;
        }

        return 'php artisan db:seed --class=' . escapeshellarg($seeder->seeder_class) . ' --force';
    }

    /**
     * Resuelve placeholders y USER_ID del cliente en un comando de seeder/comando.
     *
     * @param  string  $command
     * @param  string|null  $run_scope
     * @return string
     */
    private function resolve_client_run_command(string $command, ?string $run_scope): string
    {
        return $this->run_command_resolver->resolve_for_upgrade(
            $this->upgrade,
            $command,
            $run_scope
        );
    }

    /**
     * Ejecuta un comando en el VPS de builds y valida exit status.
     *
     * @param  string  $step
     * @param  string  $command
     * @param  bool  $must_succeed
     * @return string
     */
    private function exec_build_ssh(
        string $step,
        string $command,
        bool $must_succeed = true,
        bool $long_running = false
    ): string {
        return $this->exec_ssh_session($this->build_ssh, $step, $command, $must_succeed, $long_running);
    }

    /**
     * Ejecuta un comando en el hosting compartido y valida exit status.
     *
     * @param  string  $step
     * @param  string  $command
     * @param  bool  $must_succeed
     * @return string
     */
    /**
     * Ejecuta comando remoto en hosting compartido vía SSH.
     *
     * @param  string  $step
     * @param  string  $command
     * @param  bool  $must_succeed
     * @param  bool  $long_running
     * @return string
     */
    private function exec_hosting_ssh(
        string $step,
        string $command,
        bool $must_succeed = true,
        bool $long_running = false
    ): string {
        return $this->exec_ssh_session($this->ssh, $step, $command, $must_succeed, $long_running);
    }

    /**
     * Ejecuta comando remoto vía SSH (phpseclib) y registra salida; opcionalmente lanza si exit != 0.
     *
     * @param  SSH2  $ssh
     * @param  string  $step
     * @param  string  $command
     * @param  bool  $must_succeed
     * @return string
     */
    /**
     * Envuelve un comando remoto para que su stdin sea /dev/null.
     *
     * 🔴 ESTO EXISTE PARA QUE NINGÚN COMANDO PUEDA COLGAR EL PIPELINE ESPERANDO UNA RESPUESTA.
     *
     * Una sesión SSH no interactiva deja stdin abierto pero nunca escribe nada. Un comando que
     * pregunte algo se queda esperando para siempre: no falla, no devuelve, no loguea. El
     * deployment sigue en `running` hasta que la cola da por agotado el job a los 30 minutos
     * (`RunDeploymentJob::TIMEOUT_SEGUNDOS`), y recién ahí aparece como `failed` sin decir por qué.
     *
     * Medido el 3/9/2026 actualizando masquito a 4.0.11: el upgrade 76 se colgó 31 minutos en
     * `USER_ID=2700 php artisan migrate` (sin `--force`, con `APP_ENV=production`), que imprimió
     * el cartel "Application In Production!" y se quedó esperando un `yes`.
     *
     * Con stdin en /dev/null ese mismo comando lee EOF, `ConfirmableTrait::confirmToProceed()`
     * toma el default (`no`) y artisan sale con código 1 en el acto: el paso falla en segundos,
     * con salida para leer, en vez de consumir la ventana entera del job.
     *
     * 🔴 Se ataca acá —el punto único por donde pasa TODO comando remoto— y no agregando
     * `--force` comando por comando, porque `migrate` fue la instancia, no la familia: cualquier
     * artisan que pregunte algo tiene el mismo final. Es la clase que APRENDER_NO_PARCHEAR llama
     * "arreglar las instancias que el stack trace nombró, y no la familia".
     *
     * ⚠️ Redirige stdin, NO stdout: los pasos que leen la salida del comando siguen leyéndola
     * igual. El envoltorio `{ ...; } < /dev/null` cubre la cadena entera —un
     * `cd X && cmd1 && cmd2` redirige los tres— y no solo el último eslabón, que es lo que
     * pasaría pegando `< /dev/null` al final.
     *
     * @param  string  $command
     * @return string
     */
    /**
     * Prefijo de diagnóstico cuando un artisan murió por pedir confirmación.
     *
     * Con stdin en /dev/null (ver `con_stdin_cerrado()`) un comando que pregunta ya no cuelga:
     * se cancela solo y sale con 1. Pero "exit 1" a secas manda a buscar el problema en la base
     * o en el código del cliente, cuando lo que falta es un `--force` en el `version_command`.
     * Esta línea le ahorra ese rodeo al que lea el log.
     *
     * @param  string  $output  Salida cruda del comando remoto.
     * @return string  Texto a anteponer, o cadena vacía si no fue este caso.
     */
    private function diagnostico_de_confirmacion(string $output): string
    {
        $pistas = [
            'Application In Production',
            'Do you really wish to run this command',
            'Command Cancelled',
        ];

        foreach ($pistas as $pista) {
            if (stripos($output, $pista) !== false) {
                return 'El comando pidió confirmación y se canceló solo porque el deployment '
                    . 'corre sin terminal. Al comando de esta versión le falta --force. ';
            }
        }

        return '';
    }

    private function con_stdin_cerrado(string $command): string
    {
        $limpio = trim($command);

        if ($limpio === '') {
            return $command;
        }

        // El ';' final es necesario adentro de { } cuando el cierre va en la misma línea.
        return '{ ' . rtrim($limpio, ';') . '; } < /dev/null';
    }

    private function exec_ssh_session(
        SSH2 $ssh,
        string $step,
        string $command,
        bool $must_succeed = true,
        bool $long_running = false
    ): string {
        if ($long_running) {
            $ssh->setTimeout(0);
        }

        $comando_a_ejecutar = $this->con_stdin_cerrado($command);

        // Se loguea el comando PEDIDO, sin el envoltorio: el log es para leerlo un humano.
        $this->log($step, '$ ' . $command);
        $output = $ssh->exec($comando_a_ejecutar);
        $this->log_remote_output($step, $output);

        if ($long_running) {
            $ssh->setTimeout(10);
        }

        $exit_status = $ssh->getExitStatus();
        if ($must_succeed && $exit_status !== 0 && $exit_status !== false) {
            throw new \Exception(
                'Comando remoto falló (exit ' . $exit_status . '). '
                . $this->diagnostico_de_confirmacion($output)
                . $this->truncate_for_log($output, 1200)
            );
        }

        if ($must_succeed && $exit_status === false && $this->remote_output_indicates_failure($output)) {
            throw new \Exception(
                'Comando remoto falló (sin exit status). '
                . $this->truncate_for_log($output, 1200)
            );
        }

        return $output;
    }

    /**
     * Ruta del clone empresa-spa en el VPS de builds.
     *
     * @return string
     */
    private function builds_spa_path(): string
    {
        return (string) config('services.deploy.builds_spa_path', '/home/builds/empresa-spa');
    }

    /**
     * Nombre de la carpeta de salida del build del SPA (vue-cli por defecto: dist).
     *
     * @return string
     */
    private function spa_output_dir_name(): string
    {
        $dir = trim((string) config('services.deploy.spa_output_dir', 'dist'));
        $dir = trim($dir, '/');

        return $dir !== '' ? $dir : 'dist';
    }

    /**
     * Heurística: vue-cli-service build exitoso incluye "Build complete" en stdout.
     *
     * @param  string  $output
     * @return bool
     */
    private function spa_npm_build_output_indicates_success(string $output): bool
    {
        if (stripos($output, 'Failed to compile') !== false) {
            return false;
        }
        if (stripos($output, 'Build failed') !== false) {
            return false;
        }
        if (stripos($output, 'ERR_OSSL_EVP_UNSUPPORTED') !== false) {
            return false;
        }

        return stripos($output, 'Build complete') !== false
            || stripos($output, 'DONE  Build complete') !== false;
    }

    /**
     * Comprueba en el VPS que exista dist/index.html antes de empaquetar.
     *
     * @param  string  $spa_build_path
     * @param  string  $spa_output_dir
     * @return void
     */
    private function assert_spa_dist_directory_on_vps(string $spa_build_path, string $spa_output_dir): void
    {
        $check_cmd = $this->build_vps_command(
            $spa_build_path,
            'test -d ' . escapeshellarg($spa_output_dir)
            . ' && test -f ' . escapeshellarg($spa_output_dir . '/index.html')
            . ' && echo SPA_DIST_OK || (echo SPA_DIST_MISSING; ls -la; ls -la '
            . escapeshellarg($spa_output_dir) . ' 2>/dev/null; exit 1)'
        );
        $output = $this->exec_build_ssh('upload_spa', $check_cmd);
        if (stripos($output, 'SPA_DIST_OK') === false) {
            throw new \RuntimeException(
                'El build no generó ' . $spa_output_dir . '/index.html en el VPS. '
                . $this->truncate_for_log($output, 600)
            );
        }
        $this->log('upload_spa', "Verificado {$spa_output_dir}/index.html en el VPS", 'success');
    }

    /**
     * Ruta del clone empresa-api en el VPS de builds.
     *
     * @return string
     */
    private function builds_api_path(): string
    {
        return (string) config('services.deploy.builds_api_path', '/home/builds/empresa-api');
    }

    /**
     * Comando npm run en VPS con NODE_OPTIONS (el script build del repo usa sintaxis Windows "set").
     *
     * @param  string  $npm_bin  Ruta o nombre del binario npm
     * @param  string  $npm_script  Script de package.json (p. ej. build)
     * @return string
     */
    private function build_vps_npm_run_command(string $npm_bin, string $npm_script): string
    {
        $parts = [];
        $node_options = trim((string) config('services.deploy.node_options', '--openssl-legacy-provider'));
        if ($node_options !== '') {
            $parts[] = 'export NODE_OPTIONS=' . escapeshellarg($node_options);
        }
        $parts[] = escapeshellarg($npm_bin) . ' run ' . escapeshellarg($npm_script);

        return implode(' && ', $parts);
    }

    /**
     * Script shell que expone npm/node en SSH no interactivo (nvm, fnm, bashrc, PATH).
     *
     * @return string
     */
    private function build_vps_node_preamble(): string
    {
        $custom = trim((string) config('services.deploy.build_shell_preamble', ''));
        if ($custom !== '') {
            return $custom;
        }

        $parts = [];

        $npm_bin = trim((string) config('services.deploy.npm_bin', 'npm'));
        if (strpos($npm_bin, '/') === 0) {
            $node_bin_dir = dirname($npm_bin);
            $parts[] = 'export PATH=' . escapeshellarg($node_bin_dir) . ':$PATH';
        }

        $nvm_dir = trim((string) config('services.deploy.nvm_dir', ''));
        if ($nvm_dir !== '') {
            $parts[] = 'export NVM_DIR=' . escapeshellarg($nvm_dir);
        } else {
            $parts[] = 'export NVM_DIR="$HOME/.nvm"';
        }
        $parts[] = '[ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh"';
        $parts[] = '[ -s "$HOME/.fnm/fnm" ] && eval "$("$HOME/.fnm/fnm" env)"';
        $parts[] = '[ -f "$HOME/.bashrc" ] && . "$HOME/.bashrc"';
        $parts[] = 'export PATH="$HOME/.local/bin:/usr/local/bin:/opt/nodejs/bin:$PATH"';

        return implode('; ', $parts);
    }

    /**
     * Ejecuta un script en el VPS con bash login (+ interactivo por defecto para cargar nvm).
     *
     * @param  string  $script  Comandos bash (sin envolver en comillas externas)
     * @return string
     */
    private function wrap_vps_bash_script(string $script): string
    {
        if (filter_var(config('services.deploy.vps_use_login_shell_only', false), FILTER_VALIDATE_BOOLEAN)) {
            return 'bash -lc ' . escapeshellarg($script) . ' 2>&1';
        }

        $bash_flags = '-lc';
        if (filter_var(config('services.deploy.vps_use_interactive_login_shell', true), FILTER_VALIDATE_BOOLEAN)) {
            $bash_flags = '-lic';
        }

        return 'bash ' . $bash_flags . ' ' . escapeshellarg($script) . ' 2>&1';
    }

    /**
     * Arma un comando remoto en el VPS de builds (preamble Node + cd + comando).
     *
     * @param  string  $work_dir  Directorio de trabajo remoto
     * @param  string  $command_after_cd  Comando sin cd (p. ej. npm run build)
     * @return string
     */
    private function build_vps_command(string $work_dir, string $command_after_cd): string
    {
        $script = $this->build_vps_node_preamble()
            . '; cd ' . escapeshellarg($work_dir)
            . ' && ' . $command_after_cd;

        return $this->wrap_vps_bash_script($script);
    }

    /**
     * Verifica que npm exista en el VPS antes del build; registra diagnóstico en el log.
     *
     * @param  string  $spa_build_path
     * @param  string  $npm_bin
     * @return void
     */
    private function assert_vps_npm_available(string $spa_build_path, string $npm_bin): void
    {
        $check_cmd = $this->build_vps_command(
            $spa_build_path,
            'echo PATH=$PATH; command -v ' . escapeshellarg($npm_bin) . ' node 2>&1; '
            . escapeshellarg($npm_bin) . ' -v 2>&1'
        );
        $output = $this->exec_build_ssh('upload_spa', $check_cmd, false);
        $this->log('upload_spa', 'Diagnóstico Node/npm: ' . $this->truncate_for_log($output));

        if ($this->remote_output_indicates_failure($output) || ! preg_match('/\d+\.\d+/', $output)) {
            throw new \RuntimeException(
                'npm no está disponible en el VPS de builds para el usuario SSH. '
                . 'Instalá Node/npm en el servidor o definí en admin-api .env: '
                . 'DEPLOY_NPM_BIN=/ruta/completa/npm (salida de `bash -lic "which npm"` con el mismo usuario SSH). '
                . 'Opcional: DEPLOY_BUILD_SHELL_PREAMBLE=source ~/.nvm/nvm.sh. '
                . 'Diagnóstico: ' . $this->truncate_for_log($output, 500)
            );
        }
    }

    /**
     * Arma el comando composer install para un directorio de trabajo remoto.
     *
     * @param  string  $work_dir  Ruta absoluta en el servidor remoto
     * @param  bool  $skip_scripts  true en VPS de build (sin .env); false en hosting del cliente
     * @return string
     */
    private function build_composer_install_command(string $work_dir, bool $skip_scripts): string
    {
        $composer_bin = trim((string) config('services.deploy.composer_bin', 'composer'));
        $flags = 'COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MEMORY_LIMIT=-1 '
            . escapeshellarg($composer_bin)
            . ' install --no-dev --optimize-autoloader --no-interaction --no-ansi';
        if ($skip_scripts) {
            $flags .= ' --no-scripts';

            return $this->build_vps_command($work_dir, $flags);
        }

        return 'cd ' . escapeshellarg($work_dir) . ' && ' . $flags . ' 2>&1';
    }

    /**
     * Heurística cuando getExitStatus() no está disponible en el servidor SSH.
     *
     * @param  string  $output
     * @return bool
     */
    private function remote_output_indicates_failure(string $output): bool
    {
        $needles = [
            'Your requirements could not be resolved',
            'composer: command not found',
            'npm: command not found',
            'command not found',
            'Could not find package',
            'fatal error:',
            'PHP Fatal error:',
            'npm ERR!',
            'ELIFECYCLE',
            'ERR_OSSL_EVP_UNSUPPORTED',
            'digital envelope routines::unsupported',
        ];
        foreach ($needles as $needle) {
            if (stripos($output, $needle) !== false) {
                return true;
            }
        }

        return (bool) preg_match('/returned with error code [1-9]/i', $output);
    }

    /**
     * Registra salida remota en una o varias líneas de log (evita truncar en BD).
     *
     * @param  string  $step
     * @param  string  $output
     * @return void
     */
    private function log_remote_output(string $step, string $output): void
    {
        $output = trim($output);
        if ($output === '') {
            return;
        }

        $max_chunk = 3500;
        if (strlen($output) <= $max_chunk) {
            $this->log($step, $output);

            return;
        }

        $chunks = str_split($output, $max_chunk);
        $total = count($chunks);
        foreach ($chunks as $index => $chunk) {
            $this->log($step, '[salida ' . ($index + 1) . '/' . $total . '] ' . $chunk);
        }
    }

    /**
     * Tamaño en bytes de un archivo remoto vía SFTP (phpseclib3 usa filesize, no size).
     *
     * @param  SFTP  $sftp
     * @param  string  $remote_path
     * @return int|false
     */
    private function sftp_remote_file_size(SFTP $sftp, string $remote_path)
    {
        $file_size = $sftp->filesize($remote_path);
        if ($file_size !== false) {
            return (int) $file_size;
        }

        $stat = $sftp->stat($remote_path);
        if (is_array($stat) && isset($stat['size'])) {
            return (int) $stat['size'];
        }

        return false;
    }

    /**
     * Tipo de credencial SSH/SFTP para el servidor destino del cliente.
     * Depende del hosting_type configurado en la API destino del upgrade.
     *
     * @return string  'shared_hosting' | 'vps'
     */
    private function get_hosting_credential_type(): string
    {
        return ($this->target_api->hosting_type ?? 'shared_hosting') === 'vps' ? 'vps' : 'shared_hosting';
    }

    /**
     * Abre sesión SFTP según tipo de credencial (vps | shared_hosting).
     *
     * @param  string  $credential_type
     * @return SFTP
     */
    private function open_sftp_session(string $credential_type): SFTP
    {
        $credential = ClientSshCredential::where('type', $credential_type)->firstOrFail();
        $sftp = new SFTP($credential->host, (int) $credential->port);
        $logged_in = $sftp->login($credential->username, $credential->password);
        if (! $logged_in) {
            throw new \RuntimeException("No se pudo conectar por SFTP ({$credential_type}).");
        }

        return $sftp;
    }

    /**
     * Valida un ZIP en el VPS (integridad + tamaño) tras crearlo con zip -r.
     *
     * @param  string  $remote_zip_path  Ruta absoluta al .zip en el VPS
     * @param  string  $step  Etapa de log
     * @return int  Tamaño en bytes
     */
    private function verify_zip_on_vps(string $remote_zip_path, string $step): int
    {
        $this->exec_build_ssh(
            $step,
            'test -f ' . escapeshellarg($remote_zip_path)
            . ' && unzip -tq ' . escapeshellarg($remote_zip_path) . ' 2>&1',
            true,
            true
        );

        $size_output = $this->exec_build_ssh(
            $step,
            'stat -c%s ' . escapeshellarg($remote_zip_path) . ' 2>&1'
        );
        $size_bytes = (int) trim($size_output);
        if ($size_bytes < 500) {
            throw new \RuntimeException(
                "ZIP inválido o vacío en VPS ({$size_bytes} bytes): {$remote_zip_path}"
            );
        }

        $this->log($step, "ZIP verificado en VPS: {$size_bytes} bytes");

        return $size_bytes;
    }

    /**
     * Descarga un archivo del VPS vía SFTP a disco local (sin cargar todo en RAM).
     *
     * @param  SFTP  $sftp
     * @param  string  $remote_path
     * @param  string  $local_path
     * @param  int  $expected_bytes  Tamaño esperado según stat en VPS
     * @param  string  $step
     * @return void
     */
    private function sftp_download_file(
        SFTP $sftp,
        string $remote_path,
        string $local_path,
        int $expected_bytes,
        string $step
    ): void {
        $remote_size = $this->sftp_remote_file_size($sftp, $remote_path);
        if ($remote_size === false) {
            throw new \RuntimeException("SFTP: no se encontró el archivo remoto {$remote_path}");
        }
        if ($expected_bytes > 0 && $remote_size !== $expected_bytes) {
            throw new \RuntimeException(
                "SFTP: tamaño remoto ({$remote_size}) no coincide con VPS stat ({$expected_bytes})"
            );
        }

        $downloaded = $sftp->get($remote_path, $local_path);
        if ($downloaded === false) {
            throw new \RuntimeException("SFTP get falló al descargar {$remote_path}");
        }

        $this->assert_local_zip_file($local_path, $remote_size, $step);
    }

    /**
     * Sube un ZIP local al hosting y comprueba que el tamaño remoto coincida.
     *
     * @param  SFTP  $sftp
     * @param  string  $local_path
     * @param  string  $remote_path
     * @param  string  $step
     * @return void
     */
    private function sftp_upload_file(
        SFTP $sftp,
        string $local_path,
        string $remote_path,
        string $step
    ): void {
        $this->assert_local_zip_file($local_path, 0, $step);
        $local_size = (int) filesize($local_path);

        $uploaded = $sftp->put($remote_path, $local_path, SFTP::SOURCE_LOCAL_FILE);
        if ($uploaded === false) {
            throw new \RuntimeException("SFTP put falló al subir {$remote_path}");
        }

        $remote_size = $this->sftp_remote_file_size($sftp, $remote_path);
        if ($remote_size === false || $remote_size !== $local_size) {
            throw new \RuntimeException(
                "SFTP: tamaño en hosting ({$remote_size}) no coincide con local ({$local_size})"
            );
        }

        $this->log($step, "SFTP subida OK ({$local_size} bytes)");
    }

    /**
     * Comprueba que un archivo local sea un ZIP válido (firma PK y ZipArchive).
     *
     * @param  string  $local_path
     * @param  int  $expected_bytes  0 = no comparar tamaño
     * @param  string  $step
     * @return void
     */
    private function assert_local_zip_file(string $local_path, int $expected_bytes, string $step): void
    {
        if (! is_file($local_path)) {
            throw new \RuntimeException("No existe el archivo local: {$local_path}");
        }

        $local_size = (int) filesize($local_path);
        if ($local_size < 500) {
            throw new \RuntimeException("ZIP local demasiado pequeño ({$local_size} bytes)");
        }
        if ($expected_bytes > 0 && $local_size !== $expected_bytes) {
            throw new \RuntimeException(
                "ZIP local ({$local_size} bytes) no coincide con el esperado ({$expected_bytes})"
            );
        }

        $handle = fopen($local_path, 'rb');
        $magic = $handle !== false ? fread($handle, 2) : '';
        if ($handle !== false) {
            fclose($handle);
        }
        if ($magic !== 'PK') {
            throw new \RuntimeException('El archivo local no es un ZIP válido (firma PK ausente).');
        }

        if (class_exists(\ZipArchive::class)) {
            $zip_archive = new \ZipArchive();
            $opened = $zip_archive->open($local_path);
            if ($opened !== true) {
                throw new \RuntimeException('ZipArchive no pudo abrir el archivo local.');
            }
            $zip_archive->close();
        }

        $this->log($step, "ZIP local verificado ({$local_size} bytes)");
    }

    /**
     * Recorta texto para mensajes de excepción o logs resumidos.
     *
     * @param  string  $text
     * @param  int  $max
     * @return string
     */
    private function truncate_for_log(string $text, int $max = 500): string
    {
        $text = trim($text);
        if (strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max) . '…';
    }

    /**
     * Ejecuta un comando remoto por SSH en hosting y registra salida / errores.
     *
     * @param  string  $step
     * @param  string  $command
     * @param  bool  $must_succeed
     * @return string
     */
    private function run_command(string $step, string $command, bool $must_succeed = true): string
    {
        return $this->exec_hosting_ssh($step, $command, $must_succeed, true);
    }

    /**
     * Marca un campo timestamp en el upgrade y persiste el cambio.
     * Se llama automáticamente desde execute_steps() al completar cada etapa relevante.
     *
     * @param  string  $field  Nombre del campo timestamp en ClientVersionUpgrade (ej: 'sistema_actualizado_at')
     * @return void
     */
    private function mark_upgrade_step_timestamp(string $field): void
    {
        $this->upgrade->$field = now();
        $this->upgrade->save();
    }

    /**
     * Persiste una línea de log y emite evento de broadcast.
     *
     * @param  string  $step
     * @param  string  $line
     * @param  string  $level
     * @return DeploymentLog
     */
    private function log($step, $line, $level = 'info')
    {
        $deployment_log = DeploymentLog::create([
            'client_version_upgrade_id' => $this->upgrade->id,
            'step'                      => $step,
            'line'                      => $line,
            'level'                     => $level,
            'created_at'                => now(),
        ]);

        event(new DeploymentLogCreated($deployment_log));

        return $deployment_log;
    }
}

