<?php

namespace App\Services;

use App\Models\ClientSshCredential;
use App\Models\Demo;
use App\Models\DemoUpdate;
use App\Models\Version;
use Illuminate\Support\Facades\Http;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

/**
 * Ejecuta el pipeline completo de actualización de una demo en hosting compartido.
 *
 * Pipeline de etapas:
 *   1. step_compile_spa()    — checkout en VPS + npm ci + npm run build
 *   2. step_upload_spa()     — zip dist/ → sftp download → sftp upload al hosting
 *   3. step_upload_api()     — checkout en VPS + composer install + zip → sftp → hosting
 *   4. step_run_demo_setup() — HTTP POST al endpoint admin-sync/demo-setup de la demo
 *
 * Los helpers SSH/SFTP están copiados de DeploymentService para que este service
 * sea completamente autónomo (sin dependencia de herencia).
 */
class DemoUpdateService
{
    /**
     * Tope de caracteres del campo `log`. Al superarlo se conserva la cola (lo último
     * es siempre lo más relevante para diagnosticar) y se descarta el principio.
     *
     * La columna es LONGTEXT (4 GB), pero append_log() reescribe el string completo en
     * cada línea: un log ilimitado significa writes cada vez más pesados.
     */
    const MAX_LOG_CHARS = 2000000;

    /**
     * Registro DemoUpdate que se está procesando.
     *
     * @var DemoUpdate
     */
    private $demo_update;

    /**
     * Demo objetivo del pipeline.
     *
     * @var Demo
     */
    private $demo;

    /**
     * Versión destino a la que se actualiza la demo.
     *
     * @var Version
     */
    private $version;

    /**
     * Credencial SSH del hosting compartido.
     *
     * @var ClientSshCredential
     */
    private $credential;

    /**
     * Sesión SSH activa al hosting compartido (phpseclib).
     *
     * @var SSH2|null
     */
    private $ssh;

    /**
     * Sesión SSH activa al VPS de builds (phpseclib).
     *
     * @var SSH2|null
     */
    private $build_ssh;

    /**
     * Carga el DemoUpdate con sus relaciones y la credencial shared_hosting.
     *
     * @param  DemoUpdate  $demo_update
     */
    public function __construct(DemoUpdate $demo_update)
    {
        $this->demo_update = $demo_update;

        // Asegura que demo y version estén disponibles sin consultas adicionales.
        $this->demo_update->loadMissing('demo', 'version');

        $this->demo    = $this->demo_update->demo;
        $this->version = $this->demo_update->version;

        // Credencial del hosting compartido requerida para SSH y SFTP.
        $this->credential = ClientSshCredential::where('type', 'shared_hosting')->firstOrFail();
    }

    /**
     * Orquesta el pipeline completo de actualización.
     * Marca status = ejecutandose al inicio y completado/fallido al terminar.
     * En caso de excepción agrega la línea de error al log y relanza.
     *
     * @return void
     */
    public function run(): void
    {
        // Marca inicio del pipeline.
        $this->demo_update->status     = 'ejecutandose';
        $this->demo_update->started_at = now();
        $this->demo_update->save();

        try {
            $this->step_compile_spa();
            $this->step_upload_spa();
            $this->step_upload_api();
            $this->step_run_demo_setup();

            // Pipeline exitoso: actualizar timestamps y estado.
            $this->demo_update->status      = 'completado';
            $this->demo_update->finished_at = now();
            $this->demo_update->save();
        } catch (\Throwable $e) {
            /* CRÍTICO (13/7/2026): el estado se persiste PRIMERO. La versión anterior llamaba a
             * append_log() como primera instrucción del catch — y cuando la excepción original
             * ERA un fallo de escritura del log (columna TEXT desbordada), el append volvía a
             * tirar, las líneas siguientes nunca corrían, y el DemoUpdate quedaba en
             * `ejecutandose` para siempre. El log es información; el estado es la máquina.
             *
             * Se recarga desde la BD para descartar cualquier valor de `log` en memoria que pueda
             * ser el causante mismo de la excepción. Así el UPDATE del estado no arrastra la celda rota. */
            $fresh = DemoUpdate::find($this->demo_update->id);
            if ($fresh !== null) {
                $fresh->status      = 'fallido';
                $fresh->finished_at = now();
                $fresh->save();
                $this->demo_update = $fresh;
            }

            /* Recién ahora se intenta dejar constancia del error en el log. Si esto falla,
             * da igual: el registro ya quedó marcado como fallido. */
            try {
                $this->append_log('ERROR: ' . $e->getMessage());
            } catch (\Throwable $log_error) {
                \Log::error('DemoUpdateService: no se pudo escribir el error en el log del DemoUpdate.', [
                    'demo_update_id' => $this->demo_update->id,
                    'error_original' => $e->getMessage(),
                    'error_al_logue' => $log_error->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    // =========================================================================
    // Etapas del pipeline
    // =========================================================================

    /**
     * Etapa 1: Conecta al VPS de builds, hace checkout del tag y compila el SPA
     * con npm ci + npm run build. Verifica que dist/index.html exista al final.
     *
     * @return void
     */
    private function step_compile_spa(): void
    {
        $this->connect_build_vps();
        $this->append_log('[compile_spa] Conectado al VPS de builds');

        $spa_build_path = $this->builds_spa_path();
        // Tag de git que coincide con la versión destino (ej: v1.2.3).
        $tag = 'v' . $this->version->version;

        // Actualiza las referencias de tags remotos.
        $this->exec_build_ssh(
            'compile_spa',
            'cd ' . escapeshellarg($spa_build_path) . ' && git fetch --tags 2>&1'
        );

        // Checkout del tag de la versión destino.
        $checkout_output = $this->exec_build_ssh(
            'compile_spa',
            'cd ' . escapeshellarg($spa_build_path) . ' && git checkout ' . escapeshellarg($tag) . ' 2>&1'
        );
        $this->append_log('[compile_spa] Checkout ' . $tag . ': ' . $this->truncate_for_log($checkout_output));

        // Genera el .env para que el SPA apunte a esta demo específica.
        $env_content  = $this->build_demo_spa_env_content();
        $env_escaped  = str_replace("'", "'\\''", $env_content);
        $env_file     = $spa_build_path . '/.env';
        $this->exec_build_ssh(
            'compile_spa',
            "printf '%s' '{$env_escaped}' > " . escapeshellarg($env_file)
        );
        $this->append_log(
            '[compile_spa] .env configurado — API: ' . rtrim((string) $this->demo->erp_api_url, '/') . '/public'
            . ' | SPA: ' . $this->demo->erp_spa_url
        );

        // Verifica disponibilidad de npm en el VPS (diagnóstico previo al build).
        $npm_bin = trim((string) config('services.deploy.npm_bin', 'npm'));
        $this->assert_vps_npm_available($spa_build_path, $npm_bin);

        // Instalación de dependencias npm.
        $this->append_log('[compile_spa] Instalando dependencias (npm ci)...');
        $this->exec_build_ssh(
            'compile_spa',
            $this->build_vps_command(
                $spa_build_path,
                escapeshellarg($npm_bin) . ' ci --no-audit --no-fund 2>&1'
            ),
            true,
            true
        );
        $this->append_log('[compile_spa] Dependencias npm instaladas');

        // Compilación del SPA.
        $npm_build_cmd = $this->build_vps_npm_run_command($npm_bin, 'build');
        $this->append_log('[compile_spa] Iniciando npm run build...');
        $build_output = $this->exec_build_ssh(
            'compile_spa',
            $this->build_vps_command($spa_build_path, $npm_build_cmd),
            true,
            true
        );

        if (! $this->spa_npm_build_output_indicates_success($build_output)) {
            throw new \RuntimeException(
                'npm run build no finalizó correctamente. '
                . $this->truncate_for_log($build_output, 800)
            );
        }
        $this->append_log('[compile_spa] Build completado exitosamente');

        // Reconecta tras npm run build (el canal SSH puede quedar cerrado).
        $this->reconnect_build_vps();
        $this->append_log('[compile_spa] Reconectado al VPS tras el build');

        // Verifica que dist/index.html exista antes de proceder al zip.
        $this->assert_spa_dist_on_vps($spa_build_path);
    }

    /**
     * Etapa 2: Empaqueta dist/ en un ZIP en el VPS, lo descarga localmente
     * y lo sube al hosting. Luego descomprime en el directorio del SPA de la demo.
     *
     * @return void
     */
    private function step_upload_spa(): void
    {
        $this->connect_build_vps();

        $spa_build_path = $this->builds_spa_path();
        $spa_output_dir = $this->spa_output_dir_name();

        // Crea el ZIP con el contenido de dist/ (index.html en raíz).
        $spa_zip_remote = $spa_build_path . '/dist.zip';
        $dist_dir       = $spa_build_path . '/' . $spa_output_dir;
        $this->exec_build_ssh(
            'upload_spa',
            'cd ' . escapeshellarg($dist_dir)
            . ' && rm -f ../dist.zip && zip -r ../dist.zip . 2>&1',
            true,
            true
        );
        $spa_zip_bytes = $this->verify_zip_on_vps($spa_zip_remote, 'upload_spa');
        $this->append_log("[upload_spa] dist/ comprimido ({$spa_zip_bytes} bytes en VPS)");

        // Directorio local temporal para los ZIPs del pipeline.
        $deployments_dir = storage_path('app/deployments');
        if (! is_dir($deployments_dir)) {
            mkdir($deployments_dir, 0755, true);
        }

        // Descarga el ZIP del VPS al servidor de admin.
        $local_zip   = storage_path('app/deployments/dist_' . $this->demo_update->uuid . '.zip');
        $sftp_build  = $this->open_sftp_session('vps');
        $this->sftp_download_file($sftp_build, $spa_zip_remote, $local_zip, $spa_zip_bytes, 'upload_spa');
        $this->append_log('[upload_spa] ZIP descargado al servidor de admin');

        // Calcula el path del SPA de la demo en hosting compartido.
        $slug               = $this->slug_from_url((string) $this->demo->erp_spa_url);
        $hosting_spa_dir    = "domains/comerciocity.com/public_html/{$slug}/spa";
        $hosting_zip_remote = "{$hosting_spa_dir}/dist.zip";

        // Sube el ZIP al hosting.
        $sftp_hosting = $this->open_sftp_session('shared_hosting');
        $this->sftp_upload_file($sftp_hosting, $local_zip, $hosting_zip_remote, 'upload_spa');
        $this->append_log('[upload_spa] ZIP subido al hosting');

        // Descomprime en el directorio del SPA (mismo script que DeploymentService).
        $this->connect_hosting_ssh();
        $this->exec_hosting_ssh(
            'upload_spa',
            $this->build_spa_hosting_deploy_shell($hosting_spa_dir)
        );
        $this->append_log('[upload_spa] SPA desplegado en hosting (contenido anterior reemplazado)');

        // Limpieza local.
        if (is_file($local_zip)) {
            unlink($local_zip);
        }

        // Limpieza del ZIP temporal en el VPS.
        $this->reconnect_build_vps();
        $this->exec_build_ssh(
            'upload_spa',
            'rm -f ' . escapeshellarg($spa_build_path . '/dist.zip')
        );
    }

    /**
     * Etapa 3: Checkout del tag de empresa-api en el VPS, composer install sin scripts,
     * empaquetado en ZIP, descarga y subida al hosting. Luego composer install en hosting.
     *
     * @return void
     */
    private function step_upload_api(): void
    {
        $this->connect_build_vps();

        $api_build_path = $this->builds_api_path();
        $tag            = 'v' . $this->version->version;
        $this->append_log("[upload_api] Preparando versión {$tag} en VPS de builds");

        // Trae tags remotos y hace checkout de la versión destino.
        $this->exec_build_ssh(
            'upload_api',
            'cd ' . escapeshellarg($api_build_path) . ' && git fetch --tags 2>&1'
        );
        $checkout_output = $this->exec_build_ssh(
            'upload_api',
            'cd ' . escapeshellarg($api_build_path) . ' && git checkout ' . escapeshellarg($tag) . ' 2>&1'
        );
        $this->append_log('[upload_api] ' . $this->truncate_for_log($checkout_output));

        // composer install en VPS: sin scripts (no hay .env en el build).
        $this->append_log('[upload_api] Corriendo composer install en VPS (--no-scripts)...');
        $this->exec_build_ssh(
            'upload_api',
            $this->build_composer_install_command($api_build_path, true)
        );
        $this->append_log('[upload_api] composer install en VPS completado');

        // Empaqueta empresa-api en ZIP (excluye .env, vendor, storage, public).
        $zip_name       = 'api_' . $this->demo_update->uuid . '.zip';
        $api_zip_remote = $api_build_path . '/' . $zip_name;
        $this->reconnect_build_vps();
        $zip_command = 'cd ' . escapeshellarg($api_build_path)
            . ' && rm -f ' . escapeshellarg($zip_name)
            . ' && zip -r ' . escapeshellarg($zip_name) . ' . '
            . "--exclude='.env' --exclude='vendor/*' --exclude='storage/*' --exclude='public/*' 2>&1";
        $this->exec_build_ssh('upload_api', $zip_command, true, true);
        $api_zip_bytes = $this->verify_zip_on_vps($api_zip_remote, 'upload_api');
        $this->append_log("[upload_api] API empaquetada ({$api_zip_bytes} bytes en VPS)");

        // Directorio local temporal.
        $deployments_dir = storage_path('app/deployments');
        if (! is_dir($deployments_dir)) {
            mkdir($deployments_dir, 0755, true);
        }

        // Descarga ZIP del VPS al admin.
        $local_zip   = storage_path('app/deployments/api_' . $this->demo_update->uuid . '.zip');
        $sftp_build  = $this->open_sftp_session('vps');
        $this->sftp_download_file($sftp_build, $api_zip_remote, $local_zip, $api_zip_bytes, 'upload_api');
        $this->append_log('[upload_api] ZIP descargado al servidor de admin');

        // Path del API de la demo en hosting compartido.
        $slug        = $this->slug_from_url((string) $this->demo->erp_spa_url);
        $api_path    = "domains/comerciocity.com/public_html/{$slug}/api";
        $remote_zip  = "{$api_path}/{$zip_name}";

        // Sube el ZIP al hosting.
        $sftp_hosting = $this->open_sftp_session('shared_hosting');
        $this->sftp_upload_file($sftp_hosting, $local_zip, $remote_zip, 'upload_api');
        $this->append_log('[upload_api] ZIP subido al hosting');

        // Descomprime el ZIP en el directorio del API.
        $this->connect_hosting_ssh();
        $this->exec_hosting_ssh(
            'upload_api',
            "cd {$api_path} && unzip -o {$zip_name} && rm {$zip_name}",
            true,
            true
        );
        $this->append_log('[upload_api] API descomprimida en el hosting');

        // composer install en hosting: con scripts (el .env ya existe en el hosting).
        $this->append_log('[upload_api] Corriendo composer install en hosting...');
        $this->reconnect_hosting_ssh();
        $this->exec_hosting_ssh(
            'upload_api',
            $this->build_composer_install_command($api_path, false),
            true,
            true
        );
        $this->append_log('[upload_api] API lista en el hosting');

        // Limpieza local y remota.
        if (is_file($local_zip)) {
            unlink($local_zip);
        }
        $this->reconnect_build_vps();
        $this->exec_build_ssh(
            'upload_api',
            'rm -f ' . escapeshellarg($api_build_path . '/' . $zip_name)
        );
        $this->append_log('[upload_api] Archivos temporales eliminados');
    }

    /**
     * Etapa 4: Dispara el endpoint admin-sync/demo-setup en la API de la demo
     * para que se ejecute el pipeline de reset/setup de datos.
     * Lanza excepción si la respuesta HTTP no es exitosa.
     *
     * @return void
     */
    private function step_run_demo_setup(): void
    {
        $erp_api_url = rtrim((string) $this->demo->erp_api_url, '/');
        $endpoint    = $erp_api_url . '/api/admin-sync/demo-setup';
        $this->append_log("[run_demo_setup] POST {$endpoint}");

        // Payload vacío: en este contexto no hay Lead, el endpoint trabaja solo.
        $response = Http::withHeaders(['Accept' => 'application/json'])
            ->timeout((int) config('services.client_api.timeout', 15) * 20)
            ->post($endpoint, []);

        $body = $response->body();
        $this->append_log('[run_demo_setup] HTTP ' . $response->status() . ': ' . substr($body, 0, 500));

        if (! $response->successful()) {
            throw new \RuntimeException(
                'Error en demo-setup: HTTP ' . $response->status() . ' — ' . substr($body, 0, 300)
            );
        }

        $this->append_log('[run_demo_setup] Demo setup completado exitosamente');
    }

    // =========================================================================
    // Helpers de log
    // =========================================================================

    /**
     * Agrega una línea al campo log del DemoUpdate con timestamp [H:i:s] y persiste.
     * Cada llamada es un save() inmediato para que el log sea visible en tiempo real.
     *
     * Si el log supera MAX_LOG_CHARS se conserva solo la cola, con un marcador que deja
     * constancia del recorte. Antes de este guard (13/7/2026) el log desbordaba la columna
     * TEXT y el SQLSTATE[22001] resultante mataba el job dejándolo en `ejecutandose`.
     *
     * @param  string  $line  Texto de la línea a agregar
     * @return void
     */
    private function append_log(string $line): void
    {
        $log = ($this->demo_update->log === null ? '' : $this->demo_update->log)
            . '[' . now()->format('H:i:s') . '] ' . $line . "\n";

        if (strlen($log) > self::MAX_LOG_CHARS) {
            // Se conserva la cola: el final del log es lo que sirve para diagnosticar.
            $log = "[...log recortado: superó " . self::MAX_LOG_CHARS . " caracteres...]\n"
                . substr($log, -self::MAX_LOG_CHARS);
        }

        $this->demo_update->log = $log;
        $this->demo_update->save();
    }

    // =========================================================================
    // Helpers SSH al hosting compartido
    // =========================================================================

    /**
     * Conecta por SSH al hosting compartido usando la credencial shared_hosting.
     *
     * @return void
     */
    private function connect_hosting_ssh(): void
    {
        $this->disconnect_hosting_ssh();
        $this->ssh = new SSH2($this->credential->host, (int) $this->credential->port);

        $logged_in = $this->ssh->login($this->credential->username, $this->credential->password);
        if (! $logged_in) {
            throw new \RuntimeException('No se pudo conectar por SSH al hosting: credenciales rechazadas.');
        }
    }

    /**
     * Cierra la sesión SSH al hosting (evita "Please close the channel" en phpseclib).
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
        $this->connect_hosting_ssh();
    }

    /**
     * Ejecuta un comando en el hosting compartido y valida exit status.
     *
     * @param  string  $step
     * @param  string  $command
     * @param  bool    $must_succeed
     * @param  bool    $long_running
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

    // =========================================================================
    // Helpers SSH al VPS de builds
    // =========================================================================

    /**
     * Conecta por SSH al VPS de builds (empresa-spa / empresa-api).
     *
     * @return void
     */
    private function connect_build_vps(): void
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
     * Cierra la sesión SSH al VPS de builds.
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
     * Reabre SSH al VPS de builds (necesario tras npm run build que puede cerrar el canal).
     *
     * @return void
     */
    private function reconnect_build_vps(): void
    {
        $this->connect_build_vps();
    }

    /**
     * Ejecuta un comando en el VPS de builds y valida exit status.
     *
     * @param  string  $step
     * @param  string  $command
     * @param  bool    $must_succeed
     * @param  bool    $long_running
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

    // =========================================================================
    // Helper SSH genérico (mismo código que DeploymentService::exec_ssh_session)
    // =========================================================================

    /**
     * Ejecuta comando remoto vía SSH (phpseclib) y registra salida; opcionalmente lanza si exit != 0.
     *
     * @param  SSH2    $ssh
     * @param  string  $step
     * @param  string  $command
     * @param  bool    $must_succeed
     * @param  bool    $long_running
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
            // Sin timeout para comandos largos (npm run build, composer install).
            $ssh->setTimeout(0);
        }

        $this->append_log("[{$step}] $ {$command}");
        $output = $ssh->exec($command);
        $this->log_remote_output($step, $output);

        if ($long_running) {
            // Restaura timeout estándar tras el comando largo.
            $ssh->setTimeout(10);
        }

        $exit_status = $ssh->getExitStatus();
        if ($must_succeed && $exit_status !== 0 && $exit_status !== false) {
            throw new \Exception(
                "Comando remoto falló (exit {$exit_status}). "
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

    // =========================================================================
    // Helpers SFTP (mismos que DeploymentService)
    // =========================================================================

    /**
     * Abre sesión SFTP según tipo de credencial (vps | shared_hosting).
     *
     * @param  string  $credential_type
     * @return SFTP
     */
    private function open_sftp_session(string $credential_type): SFTP
    {
        $credential = ClientSshCredential::where('type', $credential_type)->firstOrFail();
        $sftp       = new SFTP($credential->host, (int) $credential->port);
        $logged_in  = $sftp->login($credential->username, $credential->password);
        if (! $logged_in) {
            throw new \RuntimeException("No se pudo conectar por SFTP ({$credential_type}).");
        }

        return $sftp;
    }

    /**
     * Descarga un archivo del VPS vía SFTP a disco local.
     *
     * @param  SFTP    $sftp
     * @param  string  $remote_path
     * @param  string  $local_path
     * @param  int     $expected_bytes
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
     * Sube un ZIP local al hosting y verifica que el tamaño remoto coincida.
     *
     * @param  SFTP    $sftp
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

        $this->append_log("[{$step}] SFTP subida OK ({$local_size} bytes)");
    }

    /**
     * Retorna el tamaño en bytes de un archivo remoto vía SFTP (phpseclib3).
     *
     * @param  SFTP    $sftp
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
     * Comprueba que un ZIP local sea válido (firma PK + ZipArchive).
     *
     * @param  string  $local_path
     * @param  int     $expected_bytes  0 = no comparar tamaño
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
        $magic  = $handle !== false ? fread($handle, 2) : '';
        if ($handle !== false) {
            fclose($handle);
        }
        if ($magic !== 'PK') {
            throw new \RuntimeException('El archivo local no es un ZIP válido (firma PK ausente).');
        }

        if (class_exists(\ZipArchive::class)) {
            $zip_archive = new \ZipArchive();
            $opened      = $zip_archive->open($local_path);
            if ($opened !== true) {
                throw new \RuntimeException('ZipArchive no pudo abrir el archivo local.');
            }
            $zip_archive->close();
        }

        $this->append_log("[{$step}] ZIP local verificado ({$local_size} bytes)");
    }

    // =========================================================================
    // Helpers de VPS (compilación y empaquetado)
    // =========================================================================

    /**
     * Valida un ZIP en el VPS (integridad + tamaño) tras crearlo con zip -r.
     *
     * @param  string  $remote_zip_path
     * @param  string  $step
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

        $this->append_log("[{$step}] ZIP verificado en VPS: {$size_bytes} bytes");

        return $size_bytes;
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
     * Ruta del clone empresa-api en el VPS de builds.
     *
     * @return string
     */
    private function builds_api_path(): string
    {
        return (string) config('services.deploy.builds_api_path', '/home/builds/empresa-api');
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
     * Arma el comando npm run build adaptado para el VPS (NODE_OPTIONS para webpack).
     *
     * @param  string  $npm_bin     Ruta o nombre del binario npm
     * @param  string  $npm_script  Script de package.json
     * @return string
     */
    private function build_vps_npm_run_command(string $npm_bin, string $npm_script): string
    {
        $parts        = [];
        $node_options = trim((string) config('services.deploy.node_options', '--openssl-legacy-provider'));
        if ($node_options !== '') {
            $parts[] = 'export NODE_OPTIONS=' . escapeshellarg($node_options);
        }
        $parts[] = escapeshellarg($npm_bin) . ' run ' . escapeshellarg($npm_script);

        return implode(' && ', $parts);
    }

    /**
     * Script que expone npm/node en SSH no interactivo (nvm, fnm, bashrc, PATH).
     *
     * @return string
     */
    private function build_vps_node_preamble(): string
    {
        $custom = trim((string) config('services.deploy.build_shell_preamble', ''));
        if ($custom !== '') {
            return $custom;
        }

        $parts   = [];
        $npm_bin = trim((string) config('services.deploy.npm_bin', 'npm'));
        if (strpos($npm_bin, '/') === 0) {
            $node_bin_dir = dirname($npm_bin);
            $parts[]      = 'export PATH=' . escapeshellarg($node_bin_dir) . ':$PATH';
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
     * Envuelve un script en bash login/interactivo para el VPS de builds.
     *
     * @param  string  $script
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
     * Arma un comando remoto en el VPS (preamble Node + cd + comando).
     *
     * @param  string  $work_dir          Directorio de trabajo remoto
     * @param  string  $command_after_cd  Comando sin cd
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
     * Arma el comando composer install para un directorio remoto.
     *
     * @param  string  $work_dir     Ruta absoluta en el servidor remoto
     * @param  bool    $skip_scripts true en VPS de build; false en hosting
     * @return string
     */
    private function build_composer_install_command(string $work_dir, bool $skip_scripts): string
    {
        $composer_bin = trim((string) config('services.deploy.composer_bin', 'composer'));
        $flags        = 'COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MEMORY_LIMIT=-1 '
            . escapeshellarg($composer_bin)
            . ' install --no-dev --optimize-autoloader --no-interaction --no-ansi';

        if ($skip_scripts) {
            $flags .= ' --no-scripts';

            return $this->build_vps_command($work_dir, $flags);
        }

        return 'cd ' . escapeshellarg($work_dir) . ' && ' . $flags . ' 2>&1';
    }

    /**
     * Verifica que npm esté disponible en el VPS antes del build.
     * Registra diagnóstico en el log y lanza excepción si no está.
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
        $output = $this->exec_build_ssh('compile_spa', $check_cmd, false);
        $this->append_log('[compile_spa] Diagnóstico Node/npm: ' . $this->truncate_for_log($output));

        if ($this->remote_output_indicates_failure($output) || ! preg_match('/\d+\.\d+/', $output)) {
            throw new \RuntimeException(
                'npm no está disponible en el VPS de builds. '
                . 'Configurá DEPLOY_NPM_BIN=/ruta/completa/npm en admin-api .env. '
                . 'Diagnóstico: ' . $this->truncate_for_log($output, 500)
            );
        }
    }

    /**
     * Verifica que dist/index.html exista en el VPS tras npm run build.
     *
     * @param  string  $spa_build_path
     * @return void
     */
    private function assert_spa_dist_on_vps(string $spa_build_path): void
    {
        $spa_output_dir = $this->spa_output_dir_name();
        $check_cmd      = $this->build_vps_command(
            $spa_build_path,
            'test -d ' . escapeshellarg($spa_output_dir)
            . ' && test -f ' . escapeshellarg($spa_output_dir . '/index.html')
            . ' && echo SPA_DIST_OK || (echo SPA_DIST_MISSING; ls -la; exit 1)'
        );
        $output = $this->exec_build_ssh('compile_spa', $check_cmd);
        if (stripos($output, 'SPA_DIST_OK') === false) {
            throw new \RuntimeException(
                "El build no generó {$spa_output_dir}/index.html en el VPS. "
                . $this->truncate_for_log($output, 600)
            );
        }
        $this->append_log("[compile_spa] Verificado {$spa_output_dir}/index.html en el VPS");
    }

    /**
     * Script bash que vacía el directorio del SPA y descomprime dist.zip en su raíz.
     * Misma lógica que DeploymentService::build_spa_hosting_deploy_shell().
     *
     * @param  string  $spa_dir  Ruta relativa al directorio del SPA en hosting
     * @return string
     */
    private function build_spa_hosting_deploy_shell(string $spa_dir): string
    {
        $temp_zip_basename = 'dist_deploy_' . $this->demo_update->uuid . '.zip';
        $deploy_zip_name   = 'dist.zip';

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

    // =========================================================================
    // Helpers de paths y URLs
    // =========================================================================

    /**
     * Infiere el slug de la demo a partir de su URL de SPA.
     * Ejemplo: demo.comerciocity.com → "demo"; demo2.comerciocity.com → "demo2".
     *
     * @param  string  $url
     * @return string
     */
    private function slug_from_url(string $url): string
    {
        $host = parse_url(rtrim($url, '/'), PHP_URL_HOST) ?? '';

        // El slug es el primer segmento del hostname (antes del primer punto).
        return explode('.', $host)[0];
    }

    /**
     * Genera el contenido del .env para el SPA apuntando a la demo.
     *
     * @return string
     */
    private function build_demo_spa_env_content(): string
    {
        // API URL con /public al final (shared_hosting siempre requiere el subfolder public).
        $api_url = rtrim((string) $this->demo->erp_api_url, '/') . '/public';
        $spa_url = rtrim((string) $this->demo->erp_spa_url, '/');

        $env_vars = [
            'VUE_APP_API_URL'        => $api_url,
            'VUE_APP_APP_URL'        => $spa_url,
            'VUE_APP_PUSHER_KEY'     => trim((string) config('services.deploy.spa_pusher_key', '')),
            'VUE_APP_PUSHER_CLUSTER' => trim((string) config('services.deploy.spa_pusher_cluster', 'sa1')),
        ];

        $lines = [];
        foreach ($env_vars as $env_key => $env_value) {
            // Valores con espacios requieren comillas para que dotenv/vue-cli los interprete bien.
            if (preg_match('/\s/', $env_value) !== 0) {
                $escaped_value = str_replace('"', '\\"', $env_value);
                $lines[]       = $env_key . '="' . $escaped_value . '"';
            } else {
                $lines[] = $env_key . '=' . $env_value;
            }
        }

        return implode("\n", $lines);
    }

    // =========================================================================
    // Helpers de análisis de salida remota
    // =========================================================================

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
     * Registra salida remota en el log (en chunks si es muy larga).
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

        // Con la columna log ya en LONGTEXT no hay riesgo de desborde; se sube el chunk
        // a 8000 caracteres para reducir la cantidad de save() por salida remota larga (13/7/2026).
        $max_chunk = 8000;
        if (strlen($output) <= $max_chunk) {
            $this->append_log("[{$step}] {$output}");

            return;
        }

        $chunks = str_split($output, $max_chunk);
        $total  = count($chunks);
        foreach ($chunks as $index => $chunk) {
            $this->append_log("[{$step}] [salida " . ($index + 1) . "/{$total}] {$chunk}");
        }
    }

    /**
     * Recorta texto para mensajes de excepción o logs resumidos.
     *
     * @param  string  $text
     * @param  int     $max
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
}
