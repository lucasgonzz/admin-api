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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

/**
 * Ejecuta el deployment automatizado de un cliente en hosting compartido vía SSH.
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
     * Carga upgrade, API destino y credencial shared_hosting.
     *
     * @param  ClientVersionUpgrade  $upgrade
     */
    public function __construct(ClientVersionUpgrade $upgrade)
    {
        $this->upgrade = $upgrade;
        $this->upgrade->loadMissing('client', 'target_client_api', 'from_version', 'to_version');
        $this->run_command_resolver = new DeploymentRunCommandResolver();

        $this->target_api = $this->upgrade->target_client_api;
        if ($this->target_api === null) {
            throw new \RuntimeException('El upgrade no tiene API destino configurada.');
        }

        $this->credential = ClientSshCredential::where('type', 'shared_hosting')->firstOrFail();
    }

    /**
     * Conecta por SSH al servidor de hosting compartido.
     *
     * @return void
     */
    public function connect()
    {
        $this->disconnect_hosting_ssh();
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
                    // Marca el paso "Sistema configurado" automáticamente.
                    $this->mark_upgrade_step_timestamp('sistema_configurado_at');
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

        $spa_path = $this->get_spa_path();
        $hosting_zip_remote = "domains/comerciocity.com/public_html/{$spa_path}/dist.zip";
        $sftp_hosting = $this->open_sftp_session('shared_hosting');
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
            . "--exclude='.env' --exclude='vendor/*' --exclude='storage/*' --exclude='public/*' 2>&1";
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
        $sftp_hosting = $this->open_sftp_session('shared_hosting');
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
     * @return void
     */
    private function step_run_migrations()
    {
        $api_path = $this->get_api_path();

        $this->log('run_migrations', 'Limpiando caché de Laravel...');
        $clear_commands = [
            "cd {$api_path} && php artisan config:clear",
            "cd {$api_path} && php artisan cache:clear",
            "cd {$api_path} && php artisan view:clear",
            "cd {$api_path} && php artisan route:clear",
        ];
        foreach ($clear_commands as $cmd) {
            $this->run_command('run_migrations', $cmd);
        }
        $this->log('run_migrations', 'Caché limpiado', 'success');

        $this->log('run_migrations', 'Corriendo migraciones...');
        $this->run_command(
            'run_migrations',
            "cd {$api_path} && php artisan migrate --force",
            true
        );
        $this->log('run_migrations', 'Migraciones completadas', 'success');
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

        // Orden: versión ascendente y execution_order del VersionSeeder.
        $this->upgrade->loadMissing('update_seeders.version_seeder.version');
        $update_seeders = $this->upgrade->update_seeders->sortBy(function ($update_seeder) {
            $version_seeder = $update_seeder->version_seeder;
            $version_id = $version_seeder && $version_seeder->version
                ? (int) $version_seeder->version->id
                : 0;
            $execution_order = $version_seeder ? (int) $version_seeder->execution_order : 0;

            return [$version_id, $execution_order, (int) $update_seeder->id];
        });

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

        // Orden: versión ascendente y execution_order del VersionCommand.
        $this->upgrade->loadMissing('update_commands.version_command.version');
        $update_commands = $this->upgrade->update_commands->sortBy(function ($update_command) {
            $version_command = $update_command->version_command;
            $version_id = $version_command && $version_command->version
                ? (int) $version_command->version->id
                : 0;
            $execution_order = $version_command ? (int) $version_command->execution_order : 0;

            return [$version_id, $execution_order, (int) $update_command->id];
        });

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

        if ($url === '') {
            throw new \RuntimeException(
                'No hay URL válida del empresa-api para update-default-version. '
                . 'Configure la ClientApi destino del upgrade con URL https://...'
            );
        }

        if (empty($client->api_key)) {
            throw new \RuntimeException(
                'El cliente no tiene api_key (debe coincidir con ADMIN_API_INBOUND_KEY en empresa-api).'
            );
        }

        $spa_url = trim((string) $this->target_api->spa_url);
        $api_url = $this->get_api_url_for_env();

        $this->log(
            'update_default_version',
            "PUT {$url} — SPA: {$spa_url} | API: {$api_url}"
        );

        $response = Http::withHeaders([
            'X-Admin-Api-Key' => $client->api_key,
            'Accept'          => 'application/json',
        ])
            ->timeout((int) config('services.client_api.timeout', 15))
            ->retry((int) config('services.client_api.retries', 2), 500)
            ->put($url, [
                'spa_url'         => $spa_url,
                'default_version' => $spa_url,
                'api_url'         => $api_url,
            ]);

        $body = $response->body();
        $this->log('update_default_version', 'HTTP ' . $response->status() . ': ' . substr($body, 0, 2000));

        if (! $response->successful()) {
            throw new \RuntimeException(
                'Error al actualizar versión por defecto en empresa-api: HTTP ' . $response->status()
            );
        }
    }

    /**
     * Etapa: marcar deployment completado y activar API destino en el cliente.
     *
     * @return void
     */
    private function step_complete()
    {
        $this->upgrade->deployment_status = 'completed';
        $this->upgrade->save();

        $client = $this->upgrade->client;
        $client->active_client_api_id = $this->target_api->id;
        $client->save();

        $this->log('complete', 'Deployment completado exitosamente', 'success');
    }

    private function get_versions_in_range(): \Illuminate\Support\Collection
    {
        return \App\Models\Version::where('status', 'publicada')
            ->where('id', '>', $this->upgrade->from_version->id)
            ->where('id', '<=', $this->upgrade->to_version->id)
            ->orderBy('id')
            ->get();
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
     *
     * @return string
     */
    private function get_api_url_for_env()
    {
        $api_url = rtrim((string) $this->target_api->url, '/');
        $hosting_type = $this->target_api->hosting_type ?? 'shared_hosting';

        if ($hosting_type === 'shared_hosting') {
            if (substr($api_url, -7) !== '/public') {
                $api_url .= '/public';
            }
        }

        return $api_url;
    }

    /**
     * Ruta relativa del SPA en el hosting (reemplaza /api por /spa en el path de la API).
     *
     * @return string
     */
    private function get_spa_path()
    {
        return str_replace('/api', '/spa', $this->target_api->path);
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
     * Ruta absoluta SSH del directorio público del SPA en hosting compartido.
     *
     * @return string
     */
    private function get_spa_hosting_dir(): string
    {
        return 'domains/comerciocity.com/public_html/' . $this->get_spa_path();
    }

    /**
     * Script bash: vacía el public_html del SPA, descomprime dist.zip en la raíz (no en /dist).
     *
     * @return string
     */
    private function build_spa_hosting_deploy_shell(): string
    {
        $spa_dir = $this->get_spa_hosting_dir();
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
     * Ruta del API en el hosting compartido.
     *
     * @return string
     */
    private function get_api_path(): string
    {
        return 'domains/comerciocity.com/public_html/' . $this->target_api->path;
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
     * @param  VersionSeeder  $seeder
     * @return string
     */
    private function get_seeder_command(VersionSeeder $seeder): string
    {
        if (! empty($seeder->command)) {
            return $seeder->command;
        }

        return 'php artisan db:seed --class=' . $seeder->seeder_class . ' --force';
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

        $this->log($step, '$ ' . $command);
        $output = $ssh->exec($command);
        $this->log_remote_output($step, $output);

        if ($long_running) {
            $ssh->setTimeout(10);
        }

        $exit_status = $ssh->getExitStatus();
        if ($must_succeed && $exit_status !== 0 && $exit_status !== false) {
            throw new \Exception(
                'Comando remoto falló (exit ' . $exit_status . '). '
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
