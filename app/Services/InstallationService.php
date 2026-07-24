<?php

namespace App\Services;

use App\Events\DeploymentLogCreated;
use App\Models\ClientApi;
use App\Models\ClientInstallation;
use App\Models\ClientSshCredential;
use App\Models\DeploymentLog;
use App\Models\EnvTemplate;
use Illuminate\Support\Collection;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

/**
 * Ejecuta el pipeline de instalación inicial de un sistema para un cliente.
 *
 * A diferencia de DeploymentService (actualizaciones), este servicio instala
 * desde cero: no requiere versión previa, no corre migraciones de actualización,
 * sino que sube el código completo (public/ y storage/ incluidos) y escribe el
 * .env desde la plantilla base + valores manuales.
 *
 * Importante: esta instalación deja el SPA y la API instalados y booteando,
 * nada más. El user-setup (alta del User inicial en empresa-api) NO forma parte
 * de este pipeline: es un paso posterior y manual que se dispara desde el módulo
 * de Leads (RunUserSetupService), una vez confirmado que la instalación quedó
 * completa. Antes el user-setup era la etapa 6 y su fallo marcaba toda la
 * instalación como 'fallida' aunque el sistema hubiera quedado perfectamente
 * instalado — por eso se sacó del pipeline.
 *
 * Pipeline de pasos en orden:
 *   1. compile_spa   — igual a DeploymentService
 *   2. upload_spa    — igual a DeploymentService
 *   3. upload_api    — sin excluir public/ ni storage/ (instalación inicial)
 *   4. write_env     — genera el .env desde la plantilla base + valores manuales
 *   5. finalize_api  — corre los scripts de artisan que composer no ejecutó (ya con .env)
 */
class InstallationService
{
    /**
     * Instalación inicial en curso.
     *
     * @var ClientInstallation
     */
    private $installation;

    /**
     * API del cliente donde se instalará el sistema.
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
     * Sesión SSH activa al hosting compartido (phpseclib).
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
     * Orden de etapas del pipeline de instalación inicial.
     *
     * @var array<int, string>
     */
    private $steps = [
        'compile_spa',
        'upload_spa',
        'upload_api',
        'write_env',
        'finalize_api',
        // 'run_user_setup' eliminado del pipeline (prompt 396): el user-setup es un paso
        // posterior y manual disparado desde el módulo de Leads, no parte de la instalación.
    ];

    /**
     * Carga la instalación, la API destino y las credenciales SSH.
     *
     * @param  ClientInstallation  $installation
     * @throws \RuntimeException Si no hay API configurada o no existen credenciales.
     */
    public function __construct(ClientInstallation $installation)
    {
        // Carga relaciones necesarias para el pipeline.
        $this->installation = $installation;
        $this->installation->loadMissing('client', 'client_api', 'version');

        // La API destino es obligatoria para saber a qué hosting subir.
        $this->target_api = $this->installation->client_api;
        if ($this->target_api === null) {
            throw new \RuntimeException('La instalación no tiene API destino configurada.');
        }

        // Credenciales SSH de hosting compartido (una sola entrada en el sistema).
        $this->credential = ClientSshCredential::where('type', 'shared_hosting')->firstOrFail();
    }

    /**
     * Conecta por SSH al servidor de hosting compartido.
     *
     * @return void
     * @throws \RuntimeException Si las credenciales son rechazadas.
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
     * Orquesta todas las etapas del pipeline de instalación.
     *
     * @return void
     * @throws \Throwable Si alguna etapa falla.
     */
    public function run()
    {
        try {
            // Marca la instalación como "instalando" y registra el inicio.
            $this->installation->update([
                'status'     => 'instalando',
                'started_at' => now(),
            ]);

            $this->execute_steps();

            // Marca como completada al finalizar todas las etapas.
            $this->installation->update([
                'status'      => 'completada',
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Ante cualquier fallo registra el motivo y marca como fallida.
            $this->log('installation', $e->getMessage(), 'error');
            $this->installation->update([
                'status'         => 'fallida',
                'finished_at'    => now(),
                'failure_reason' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Ejecuta cada etapa del pipeline en orden.
     *
     * @return void
     */
    private function execute_steps()
    {
        foreach ($this->steps as $step) {
            switch ($step) {
                case 'compile_spa':
                    $this->step_compile_spa();
                    break;
                case 'upload_spa':
                    $this->step_upload_spa();
                    break;
                case 'upload_api':
                    $this->step_upload_api();
                    break;
                case 'write_env':
                    $this->step_write_env();
                    break;
                case 'finalize_api':
                    $this->step_finalize_api();
                    break;
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ETAPAS DEL PIPELINE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Etapa 1: checkout del tag en VPS de builds y compilación del SPA (npm ci + npm run build).
     * Idéntico a DeploymentService::step_compile_spa() salvo que usa $installation->version.
     *
     * @return void
     */
    private function step_compile_spa()
    {
        $this->connect_build_vps();
        $this->log('compile_spa', 'Conectado al VPS de builds');

        $spa_build_path = $this->builds_spa_path();
        // Versión inicial a instalar (en formato de tag: v1.0.0).
        $tag = 'v' . $this->installation->version->version;

        $this->exec_build_ssh(
            'compile_spa',
            'cd ' . escapeshellarg($spa_build_path) . ' && git fetch --tags 2>&1'
        );
        $checkout_output = $this->exec_build_ssh(
            'compile_spa',
            'cd ' . escapeshellarg($spa_build_path) . ' && git checkout ' . escapeshellarg($tag) . ' 2>&1'
        );
        $this->log('compile_spa', "Checkout {$tag}: " . $this->truncate_for_log($checkout_output));

        // URL de la API para VUE_APP_API_URL en el .env del SPA.
        $api_url = $this->get_api_url_for_env();
        $spa_url = trim((string) $this->target_api->spa_url);
        if ($spa_url === '') {
            throw new \RuntimeException(
                'La API destino no tiene spa_url. Configúrela en el ClientApi antes de compilar.'
            );
        }

        // Escribe el .env del SPA en el VPS antes del build.
        $env_content = $this->build_spa_env_file_content($api_url, $spa_url);
        $env_escaped = str_replace("'", "'\\''", $env_content);
        $env_file    = $spa_build_path . '/.env';
        $this->exec_build_ssh(
            'compile_spa',
            "printf '%s' '{$env_escaped}' > " . escapeshellarg($env_file)
        );
        $this->log(
            'compile_spa',
            "Archivo .env configurado — API: {$api_url} | SPA: {$spa_url}"
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
        $this->log('compile_spa', 'Iniciando npm run build...');
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

        // Reconectar tras npm run build (el canal SSH puede quedar abierto en phpseclib).
        $this->reconnect_build_vps();
        $this->log('compile_spa', 'Reconectado al VPS tras el build');

        $this->assert_spa_dist_directory_on_vps($spa_build_path, $spa_output_dir);
    }

    /**
     * Etapa 2: empaquetado del dist/ compilado y despliegue en hosting compartido.
     * Idéntico a DeploymentService::step_upload_spa().
     *
     * @return void
     */
    private function step_upload_spa()
    {
        // Asegura conexión activa al VPS de builds.
        $this->connect_build_vps();

        $spa_build_path = $this->builds_spa_path();
        $spa_output_dir = $this->spa_output_dir_name();

        // ZIP con index.html en la raíz (contenido de dist/, no la carpeta dist/).
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
        $this->log('upload_spa', "{$spa_output_dir}/ comprimido ({$spa_zip_bytes} bytes en VPS)");

        // Descarga el ZIP al servidor admin.
        $deployments_dir = storage_path('app/deployments');
        if (! is_dir($deployments_dir)) {
            mkdir($deployments_dir, 0755, true);
        }
        $local_zip   = storage_path('app/deployments/dist_' . $this->installation->uuid . '.zip');
        $sftp_build  = $this->open_sftp_session('vps');
        $this->sftp_download_file($sftp_build, $spa_zip_remote, $local_zip, $spa_zip_bytes, 'upload_spa');
        $this->log('upload_spa', 'ZIP descargado al servidor de admin');

        // Sube al hosting compartido.
        $spa_path          = $this->get_spa_path();
        $hosting_zip_remote = "domains/comerciocity.com/public_html/{$spa_path}/dist.zip";
        $sftp_hosting      = $this->open_sftp_session('shared_hosting');
        $this->sftp_upload_file($sftp_hosting, $local_zip, $hosting_zip_remote, 'upload_spa');
        $this->log('upload_spa', 'ZIP subido al hosting');

        // Descomprime en el public_html del SPA.
        $this->reconnect_hosting_ssh();
        $this->exec_hosting_ssh(
            'upload_spa',
            $this->build_spa_hosting_deploy_shell()
        );
        $this->log('upload_spa', 'SPA desplegado en public_html (contenido anterior reemplazado)', 'success');

        // Limpia archivo local temporal.
        if (is_file($local_zip)) {
            unlink($local_zip);
        }

        // Limpia ZIP del VPS.
        $this->reconnect_build_vps();
        $this->exec_build_ssh(
            'upload_spa',
            'rm -f ' . escapeshellarg($spa_build_path . '/dist.zip')
        );
    }

    /**
     * Etapa 3: checkout en VPS, empaquetado y despliegue del API en hosting compartido.
     *
     * Diferencia clave respecto a DeploymentService: el ZIP NO excluye public/ ni storage/
     * porque es una instalación desde cero (no una actualización sobre código existente).
     * Solo se excluyen .env y vendor/ (igual que siempre).
     *
     * @return void
     */
    private function step_upload_api()
    {
        $this->connect_build_vps();

        $api_build_path = $this->builds_api_path();
        // Versión inicial a instalar.
        $tag = 'v' . $this->installation->version->version;
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

        $this->log('upload_api', 'Corriendo composer install en VPS...');
        $this->exec_build_ssh(
            'upload_api',
            $this->build_composer_install_command($api_build_path, true)
        );
        $this->log('upload_api', 'composer install en VPS completado', 'success');

        // ZIP: en instalación inicial NO se excluyen public/ ni storage/ (a diferencia de un upgrade).
        // Sí se excluyen:
        //   .env             — se genera en step_write_env
        //   vendor/          — se instala vía composer en el hosting
        //   bootstrap/cache/ — para no arrastrar config/rutas cacheadas del VPS de builds
        //   .git/            — el hosting no necesita el historial del repo
        //   *.zip            — CRÍTICO: si un ZIP huérfano de una corrida anterior quedó en el
        //                     directorio de builds, zip -r lo mete adentro del nuevo paquete y el
        //                     tamaño crece en bola de nieve hasta romper la descarga SFTP.
        //   tests/, database/super-budgets/, database/seeders/{articles,truvari,subcategories,sales}/
        //                   — datasets y tests que el cliente no necesita (igual que DeploymentService)
        $zip_name      = 'api_install_' . $this->installation->uuid . '.zip';
        $api_zip_remote = $api_build_path . '/' . $zip_name;
        $this->reconnect_build_vps();

        // Housekeeping: borra ZIPs huérfanos de corridas anteriores (propias y de DeploymentService).
        // El filtro por antigüedad evita pisar el paquete de un deploy que esté corriendo en paralelo.
        $this->exec_build_ssh(
            'upload_api',
            'cd ' . escapeshellarg($api_build_path)
            . " && find . -maxdepth 1 -name 'api_*.zip' -mmin +120 -delete 2>&1"
        );

        $zip_command = 'cd ' . escapeshellarg($api_build_path)
            . ' && rm -f ' . escapeshellarg($zip_name)
            . ' && zip -r ' . escapeshellarg($zip_name) . ' . '
            . "--exclude='.env' --exclude='vendor/*' --exclude='bootstrap/cache/*'"
            . " --exclude='.git/*' --exclude='*.zip' --exclude='tests/*'"
            . " --exclude='database/super-budgets/*' --exclude='database/seeders/articles/*'"
            . " --exclude='database/seeders/truvari/*' --exclude='database/seeders/subcategories/*'"
            . " --exclude='database/seeders/sales/*'"
            . " 2>&1";
        $this->exec_build_ssh('upload_api', $zip_command, true, true);

        $api_zip_bytes = $this->verify_zip_on_vps($api_zip_remote, 'upload_api');
        $this->log('upload_api', "API empaquetada ({$api_zip_bytes} bytes en VPS, public/ y storage/ incluidos)");

        // Descarga el ZIP al servidor admin.
        $deployments_dir = storage_path('app/deployments');
        if (! is_dir($deployments_dir)) {
            mkdir($deployments_dir, 0755, true);
        }
        $local_zip  = storage_path('app/deployments/api_' . $this->installation->uuid . '.zip');
        $sftp_build = $this->open_sftp_session('vps');
        $this->sftp_download_file($sftp_build, $api_zip_remote, $local_zip, $api_zip_bytes, 'upload_api');
        $this->log('upload_api', 'ZIP descargado al servidor de admin');

        // Sube al hosting y descomprime.
        $api_path      = $this->get_api_path();
        $remote_zip    = "{$api_path}/{$zip_name}";
        $sftp_hosting  = $this->open_sftp_session('shared_hosting');
        $this->sftp_upload_file($sftp_hosting, $local_zip, $remote_zip, 'upload_api');
        $this->log('upload_api', 'ZIP subido al hosting');

        $this->reconnect_hosting_ssh();
        $this->exec_hosting_ssh(
            'upload_api',
            "cd {$api_path} && unzip -o {$zip_name} && rm {$zip_name}",
            true,
            true
        );
        $this->log('upload_api', 'API descomprimida en el hosting');

        // Corre composer install en el hosting SIN scripts: el .env todavía no existe (se crea en
        // step_write_env) y los scripts de post-autoload-dump (artisan package:discover) bootean
        // Laravel, que revienta sin variables de entorno. Los scripts se corren en step_finalize_api.
        $this->log('upload_api', 'Corriendo composer install en hosting (sin scripts; el .env aún no existe)...');
        $this->reconnect_hosting_ssh();
        $this->exec_hosting_ssh(
            'upload_api',
            $this->build_composer_install_command($api_path, false),
            true,
            true
        );
        $this->log('upload_api', 'API lista en el hosting', 'success');

        // Limpia temporales.
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
     * Etapa 4: genera y escribe el .env de la API del cliente en el hosting.
     *
     * Combina (en orden de prioridad):
     *   a) TODAS las variables de la tabla env_templates con scope='empresa' (prompt 580: la
     *      tabla ahora también contiene la plantilla de tienda-api con scope='tienda', que
     *      NO debe mezclarse acá) y su valor de plantilla base.
     *   b) Variables is_manual_on_create = true cuyos valores vienen de installation->env_manual_values.
     *   c) APP_URL generada automáticamente desde la URL de la ClientApi (sin /public).
     *   d) SANCTUM_STATEFUL_DOMAINS / SANCTUM_STATEFUL_CORS derivadas del spa_url de la ClientApi.
     *   e) USER_ID = clients.user_id (bloque ComercioCity) del cliente instalado.
     *
     * Importante: NO se filtra por is_common. Ese flag significa "se contrasta con los clientes al
     * actualizar" (ver EnvTemplate), no "se escribe en el .env". Filtrar por is_common dejaba fuera
     * todo el grupo app (APP_NAME, APP_ENV, APP_KEY, APP_DEBUG), generando un .env inservible.
     *
     * Si el archivo .env no existe aún en el hosting, lo crea con touch antes de escribir.
     *
     * @return void
     */
    private function step_write_env()
    {
        $this->log('write_env', 'Generando .env para la instalación inicial...');

        // a) Plantilla base completa de empresa-api, ordenada por grupo y posición.
        //    Se filtra scope='empresa' (prompt 580) para no mezclar con las filas de
        //    la plantilla de tienda-api, que ahora conviven en la misma tabla.
        $base_templates = EnvTemplate::where('scope', 'empresa')->orderBy('group')->orderBy('sort_order')->get();

        // Array KEY => valor que se escribirá en el .env.
        $vars_to_write = [];
        foreach ($base_templates as $template) {
            $vars_to_write[$template->key] = (string) ($template->value ?? '');
        }

        // b) Variables manuales: los valores los cargó el operador en env_manual_values.
        $env_manual_values = $this->installation->env_manual_values ?? [];
        // Igual que arriba: scope='empresa' para no traer variables manuales de tienda-api.
        $manual_templates  = EnvTemplate::where('scope', 'empresa')
            ->where('is_manual_on_create', true)
            ->get()
            ->keyBy('key');

        foreach ($manual_templates as $key => $template) {
            // Solo aplica las claves que tienen valor en env_manual_values.
            if (isset($env_manual_values[$key]) && $env_manual_values[$key] !== '') {
                $vars_to_write[$key] = (string) $env_manual_values[$key];
            }
        }

        // c) APP_URL: URL cruda de la API del cliente, SIN /public (a diferencia de VUE_APP_API_URL).
        $vars_to_write['APP_URL'] = rtrim((string) $this->target_api->url, '/');

        // d) Variables de sesión/Sanctum derivadas del SPA (spa_url) de la ClientApi destino.
        //    SANCTUM_STATEFUL_DOMAINS = host del SPA (sin esquema); SANCTUM_STATEFUL_CORS = URL completa.
        $spa_url = rtrim(trim((string) $this->target_api->spa_url), '/');
        if ($spa_url !== '') {
            $spa_host = parse_url($spa_url, PHP_URL_HOST);
            if (is_string($spa_host) && $spa_host !== '') {
                $vars_to_write['SANCTUM_STATEFUL_DOMAINS'] = $spa_host;
            }
            $vars_to_write['SANCTUM_STATEFUL_CORS'] = $spa_url;
        }

        // e) USER_ID = bloque ComercioCity del cliente (clients.user_id).
        $installation_client = $this->installation->client;
        if ($installation_client !== null && $installation_client->user_id !== null && (int) $installation_client->user_id > 0) {
            $vars_to_write['USER_ID'] = (string) $installation_client->user_id;
        }

        $this->log(
            'write_env',
            'Variables a escribir: ' . count($vars_to_write) . ' (' . implode(', ', array_keys($vars_to_write)) . ')'
        );

        // Obtiene el path de la API en el hosting.
        $env_ssh_service = new EnvSshService();
        $api_path        = $env_ssh_service->get_api_path($this->target_api);

        // Si el archivo .env no existe, lo crea vacío (touch) antes de llamar a write_env_vars.
        $this->reconnect_hosting_ssh();
        $env_file    = $api_path . '/.env';
        $touch_cmd   = 'test -f ' . escapeshellarg($env_file) . ' || touch ' . escapeshellarg($env_file);
        $this->exec_hosting_ssh('write_env', $touch_cmd, false);

        // EnvSshService reutiliza la misma lógica de sed del sistema.
        $env_ssh_service->write_env_vars($api_path, $vars_to_write);

        $this->log('write_env', '.env generado y escrito en el hosting', 'success');
    }

    /**
     * Etapa 5: ejecuta en el hosting los comandos de artisan que composer no corrió.
     *
     * composer install se ejecuta con --no-scripts porque en ese momento todavía no hay .env
     * (ver build_composer_install_command). Recién acá, con el .env ya escrito, se puede bootear
     * Laravel: se limpia el cache de bootstrap, se regenera el manifest de paquetes descubiertos
     * (bootstrap/cache/packages.php y services.php) y se crea el symlink de storage.
     *
     * @return void
     * @throws \RuntimeException Si package:discover falla (la API no podría bootear).
     */
    private function step_finalize_api()
    {
        $api_path = $this->get_api_path();

        $this->log('finalize_api', 'Asegurando directorios de storage...');
        $this->reconnect_hosting_ssh();

        // Asegurar que el árbol de storage/ existe antes de correr clears.
        // El ZIP de instalación (step_upload_api) NO excluye storage/ (a diferencia de upgrades),
        // pero si por algún motivo el árbol llega incompleto (transferencia manual previa,
        // limpieza a mano en el hosting), view:clear y cache:clear fallan con "path not found",
        // y realpath() en config/view.php devuelve false.
        //
        // bootstrap/cache/ también se excluye del ZIP para no arrastrar caches del VPS de builds.
        // En una instalación desde cero ninguno de los directorios existe todavía en el hosting,
        // y tanto package:discover como el boot de Laravel en runtime exigen que ambos estén
        // presentes y con permiso de escritura.
        $this->exec_hosting_ssh(
            'finalize_api',
            'cd ' . escapeshellarg($api_path)
            . ' && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions'
            . ' storage/framework/testing storage/framework/views storage/logs bootstrap/cache'
            . ' && chmod -R 775 storage/framework bootstrap/cache'
            . ' && chmod 775 storage storage/app storage/app/public storage/logs 2>&1',
            false
        );

        // rm por shell (no artisan): un config.php cacheado inválido rompería cualquier comando.
        $this->exec_hosting_ssh(
            'finalize_api',
            'cd ' . escapeshellarg($api_path)
            . ' && rm -f bootstrap/cache/config.php bootstrap/cache/routes-*.php'
            . ' bootstrap/cache/packages.php bootstrap/cache/services.php 2>&1',
            false
        );

        // Regenera el manifest de paquetes: es lo que composer habría hecho en post-autoload-dump.
        $this->log('finalize_api', 'Ejecutando artisan package:discover...');
        $discover_output = $this->exec_hosting_ssh(
            'finalize_api',
            'cd ' . escapeshellarg($api_path) . ' && php artisan package:discover --no-ansi 2>&1',
            true,
            true
        );
        $this->log('finalize_api', $this->truncate_for_log($discover_output));
        $this->log('finalize_api', 'Paquetes descubiertos correctamente', 'success');

        // Symlink public/storage -> storage/app/public. Solo en instalación inicial.
        // No es crítico: si ya existe, artisan devuelve error y no se corta la instalación.
        $this->log('finalize_api', 'Creando symlink de storage...');
        $storage_link_output = $this->exec_hosting_ssh(
            'finalize_api',
            'cd ' . escapeshellarg($api_path) . ' && php artisan storage:link --no-ansi 2>&1',
            false
        );
        $this->log('finalize_api', $this->truncate_for_log($storage_link_output));

        // Limpieza final de caches de aplicación.
        $clear_commands = [
            'config:clear',
            'cache:clear',
            'view:clear',
            'route:clear',
        ];
        foreach ($clear_commands as $clear_command) {
            $this->exec_hosting_ssh(
                'finalize_api',
                'cd ' . escapeshellarg($api_path) . ' && php artisan ' . $clear_command . ' --no-ansi 2>&1',
                false
            );
        }

        // Última comprobación antes de dar la etapa (y la instalación) por completada: si algo
        // quedó incompleto en el hosting, verify_api_installation() lanza y la instalación se
        // marca como fallida en vez de exitosa.
        $this->verify_api_installation();

        $this->log('finalize_api', 'API finalizada y lista para bootear', 'success');
    }

    /**
     * Verifica que la instalación quedó completa en el hosting antes de darla por exitosa.
     *
     * Corre un único comando SSH que chequea, con `[ -e ... ]`, la existencia de cada ruta de
     * $required_paths relativa a api_path. Es la única oportunidad real de detectar una
     * instalación incompleta: los ZIPs de upgrade (DeploymentService::step_upload_api(), a
     * diferencia del ZIP de instalación) excluyen a propósito `public/*` y `storage/*` —
     * `public/afip/` guarda certificados del cliente y `storage/` sus archivos, no se pueden
     * pisar en cada actualización — así que lo que falte después de esta etapa no se repone
     * solo en ningún upgrade futuro.
     *
     * El comando remoto termina siempre con "echo VERIFY_DONE" para poder distinguir una salida
     * vacía por sesión SSH caída (verificación que no llegó a correr) de una verificación real
     * que no encontró faltantes.
     *
     * @return void
     * @throws \RuntimeException Si la verificación no llegó a completarse o falta alguna ruta requerida.
     */
    private function verify_api_installation(): void
    {
        $api_path = $this->get_api_path();

        // Rutas relativas a api_path imprescindibles para que la API bootee y para que los
        // upgrades (que excluyen public/ y storage/ de sus ZIPs) tengan algo sobre lo que pisar.
        // Agregar una ruta a chequear es agregar un elemento acá.
        $required_paths = [
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

        // Arma la lista del `for` a partir del array de arriba, escapando cada ruta.
        $escaped_paths = [];
        foreach ($required_paths as $required_path) {
            $escaped_paths[] = escapeshellarg($required_path);
        }

        // `for ... in ... ; do ... done` y `[ -e ... ]` son POSIX puro: sin brace expansion ni
        // nada específico de bash, para no depender del shell que tenga el hosting.
        $command = 'cd ' . escapeshellarg($api_path)
            . ' && for P in ' . implode(' ', $escaped_paths) . '; do [ -e "$P" ] || echo "FALTA $P"; done'
            . ' && echo VERIFY_DONE';

        $this->log('finalize_api', 'Verificando integridad de la instalación...');

        // must_succeed = false: acá lo que decide si la verificación falló es la salida
        // (líneas "FALTA ..." o ausencia de VERIFY_DONE), no el exit code del comando remoto.
        $output = $this->exec_hosting_ssh('finalize_api', $command, false);

        if (strpos($output, 'VERIFY_DONE') === false) {
            // Salida vacía o cortada antes de tiempo: la sesión SSH se interrumpió y no llegó a
            // terminar la verificación. Sin VERIFY_DONE no hay forma de confirmar que la
            // instalación está completa, así que se trata como fallo.
            $this->log(
                'finalize_api',
                'No se pudo completar la verificación de integridad (sesión SSH interrumpida).',
                'error'
            );

            throw new \RuntimeException(
                'La instalación no pudo verificarse: la sesión SSH se interrumpió antes de terminar '
                . 'la comprobación. Revisar manualmente antes de entregarla al cliente.'
            );
        }

        // Junta cada línea "FALTA <ruta>" que haya devuelto el comando remoto.
        $missing_paths = [];
        foreach (preg_split('/\r\n|\r|\n/', $output) as $output_line) {
            if (strpos($output_line, 'FALTA ') === 0) {
                $missing_paths[] = trim(substr($output_line, strlen('FALTA ')));
            }
        }

        if (!empty($missing_paths)) {
            $missing_list = implode(', ', $missing_paths);
            $this->log(
                'finalize_api',
                'La instalación quedó incompleta. Faltan: ' . $missing_list,
                'error'
            );

            throw new \RuntimeException(
                'La instalación quedó incompleta: faltan ' . $missing_list . '. Hay que revisarla '
                . 'antes de entregarla al cliente: los upgrades excluyen public/ y storage/ de sus '
                . 'ZIPs, así que estas rutas no se van a reponer solas en ninguna actualización futura.'
            );
        }

        $this->log(
            'finalize_api',
            'Instalación verificada: todos los archivos y directorios requeridos están presentes',
            'success'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS SSH / SFTP (extraídos de DeploymentService; idénticos)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Conecta por SSH al VPS de builds.
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
     * Reabre SSH al VPS de builds tras comandos largos.
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
     * Reabre SSH al hosting compartido.
     *
     * @return void
     */
    private function reconnect_hosting_ssh(): void
    {
        $this->connect();
    }

    /**
     * Ejecuta un comando en el VPS de builds.
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

    /**
     * Ejecuta un comando en el hosting compartido.
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

    /**
     * Ejecuta un comando en una sesión SSH y registra la salida.
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
     * Abre una sesión SFTP según tipo de credencial (vps | shared_hosting).
     *
     * @param  string  $credential_type
     * @return SFTP
     */
    private function open_sftp_session(string $credential_type): SFTP
    {
        $credential = ClientSshCredential::where('type', $credential_type)->firstOrFail();
        $sftp       = new SFTP($credential->host, (int) $credential->port);

        $logged_in = $sftp->login($credential->username, $credential->password);
        if (! $logged_in) {
            throw new \RuntimeException("No se pudo conectar por SFTP ({$credential_type}).");
        }

        return $sftp;
    }

    /**
     * Valida un ZIP en el VPS (integridad + tamaño).
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

        // Cota superior: un paquete sano ronda las decenas/centenas de MB. Si se dispara, algo se
        // coló en el empaquetado (típicamente un ZIP huérfano). Cortar acá evita una descarga SFTP
        // de varios GB que revienta con "Expected NET_SFTP_DATA or NET_SFTP_STATUS".
        $max_bytes = (int) config('services.deploy.max_zip_bytes', 1073741824);
        if ($size_bytes > $max_bytes) {
            throw new \RuntimeException(
                "ZIP sospechosamente grande en VPS ({$size_bytes} bytes, máximo {$max_bytes}): "
                . "{$remote_zip_path}. Revisá que no haya archivos huérfanos en el directorio de builds."
            );
        }

        $this->log($step, "ZIP verificado en VPS: {$size_bytes} bytes");

        return $size_bytes;
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
     * Sube un ZIP local al hosting vía SFTP y verifica el tamaño.
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

        $this->log($step, "SFTP subida OK ({$local_size} bytes)");
    }

    /**
     * Tamaño en bytes de un archivo remoto vía SFTP.
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
     * Comprueba que un archivo local sea un ZIP válido.
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

        $this->log($step, "ZIP local verificado ({$local_size} bytes)");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS DE RUTAS Y CONFIGURACIÓN
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * URL de la API para VUE_APP_API_URL y APP_URL, con /public en shared_hosting.
     * Misma lógica que DeploymentService::get_api_url_for_env().
     *
     * @return string
     */
    private function get_api_url_for_env(): string
    {
        $api_url      = rtrim((string) $this->target_api->url, '/');
        $hosting_type = $this->target_api->hosting_type ?? 'shared_hosting';

        if ($hosting_type === 'shared_hosting') {
            if (substr($api_url, -7) !== '/public') {
                $api_url .= '/public';
            }
        }

        return $api_url;
    }

    /**
     * Ruta relativa del SPA en el hosting (reemplaza /api por /spa).
     *
     * @return string
     */
    private function get_spa_path(): string
    {
        return str_replace('/api', '/spa', $this->target_api->path);
    }

    /**
     * Ruta del API en el hosting compartido (prefijo estándar del hosting).
     *
     * @return string
     */
    private function get_api_path(): string
    {
        return 'domains/comerciocity.com/public_html/' . $this->target_api->path;
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
     * Nombre de la carpeta de salida del build del SPA.
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
     * Contenido del .env del SPA en el VPS antes de npm run build.
     *
     * @param  string  $api_url
     * @param  string  $spa_url
     * @return string
     */
    private function build_spa_env_file_content(string $api_url, string $spa_url): string
    {
        $env_vars = [
            'VUE_APP_API_URL'       => $api_url,
            'VUE_APP_APP_URL'       => $spa_url,
            'VUE_APP_PUSHER_KEY'    => trim((string) config('services.deploy.spa_pusher_key', '')),
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
     * Script bash para desplegar el SPA en el public_html del hosting.
     *
     * @return string
     */
    private function build_spa_hosting_deploy_shell(): string
    {
        $spa_dir            = 'domains/comerciocity.com/public_html/' . $this->get_spa_path();
        $temp_zip_basename  = 'dist_deploy_' . $this->installation->uuid . '.zip';
        $deploy_zip_name    = 'dist.zip';

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
     * Verifica que el directorio dist/ exista en el VPS tras el build.
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
     * Verifica que npm esté disponible en el VPS antes del build.
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
                'npm no está disponible en el VPS de builds. '
                . 'Diagnóstico: ' . $this->truncate_for_log($output, 500)
            );
        }
    }

    /**
     * Comando npm run en VPS con NODE_OPTIONS.
     *
     * @param  string  $npm_bin
     * @param  string  $npm_script
     * @return string
     */
    private function build_vps_npm_run_command(string $npm_bin, string $npm_script): string
    {
        $parts       = [];
        $node_options = trim((string) config('services.deploy.node_options', '--openssl-legacy-provider'));
        if ($node_options !== '') {
            $parts[] = 'export NODE_OPTIONS=' . escapeshellarg($node_options);
        }
        $parts[] = escapeshellarg($npm_bin) . ' run ' . escapeshellarg($npm_script);

        return implode(' && ', $parts);
    }

    /**
     * Preamble que expone npm/node en SSH no interactivo.
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
     * Envuelve un script en bash login para cargar el entorno del VPS.
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
     * @param  string  $work_dir
     * @param  string  $command_after_cd
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
     * En instalación inicial SIEMPRE se usa --no-scripts, tanto en el VPS de builds como en el
     * hosting del cliente: en ninguno de los dos existe un .env al momento de correr composer, y
     * el script post-autoload-dump (artisan package:discover) bootea Laravel y falla sin entorno.
     * Los comandos de artisan se ejecutan después, en step_finalize_api(), ya con el .env escrito.
     *
     * @param  string  $work_dir
     * @param  bool    $is_vps  true en VPS de builds (envuelve el comando); false en hosting
     * @return string
     */
    private function build_composer_install_command(string $work_dir, bool $is_vps): string
    {
        $composer_bin = trim((string) config('services.deploy.composer_bin', 'composer'));
        $flags        = 'COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MEMORY_LIMIT=-1 '
            . escapeshellarg($composer_bin)
            . ' install --no-dev --optimize-autoloader --no-interaction --no-ansi --no-scripts';

        if ($is_vps) {
            return $this->build_vps_command($work_dir, $flags);
        }

        return 'cd ' . escapeshellarg($work_dir) . ' && ' . $flags . ' 2>&1';
    }

    /**
     * Heurística para detectar fallo cuando getExitStatus() no está disponible.
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
     * Heurística para verificar que el build de npm finalizó correctamente.
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
     * Registra salida remota en una o varias líneas de log (chunked para evitar truncar en BD).
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
        $total  = count($chunks);
        foreach ($chunks as $index => $chunk) {
            $this->log($step, '[salida ' . ($index + 1) . '/' . $total . '] ' . $chunk);
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

    /**
     * Persiste una línea de log asociada a la instalación y emite evento de broadcast.
     *
     * Usa client_installation_id (no client_version_upgrade_id) para que el frontend
     * pueda distinguir los logs de instalaciones de los de upgrades.
     *
     * @param  string  $step
     * @param  string  $line
     * @param  string  $level
     * @return DeploymentLog
     */
    private function log(string $step, string $line, string $level = 'info'): DeploymentLog
    {
        $deployment_log = DeploymentLog::create([
            'client_installation_id' => $this->installation->id,
            // El upgrade_id se deja null para que quede claro que es un log de instalación.
            'client_version_upgrade_id' => null,
            'step'                      => $step,
            'line'                      => $line,
            'level'                     => $level,
            'created_at'                => now(),
        ]);

        event(new DeploymentLogCreated($deployment_log));

        return $deployment_log;
    }
}

