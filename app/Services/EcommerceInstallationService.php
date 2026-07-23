<?php

namespace App\Services;

use App\Models\ClientEcommerceInstallation;
use App\Models\ClientSshCredential;
use App\Models\EnvTemplate;
use Illuminate\Support\Facades\Http;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

/**
 * Ejecuta el pipeline de INSTALACIÓN inicial del ecommerce (tienda-spa + tienda-api) de un cliente.
 *
 * Espeja a InstallationService (empresa), con las diferencias propias del ecommerce:
 *   - Un solo dominio por cliente (sin swap v1/v2): el SPA va a la raíz del dominio, la API a "/api".
 *   - tienda-spa y tienda-api hay que clonarlos en el VPS la primera vez (no están clonados todavía,
 *     a diferencia de empresa-spa/empresa-api que ya vienen clonados de fábrica en el VPS de builds).
 *     Ambos clonados usan el mismo helper `ensure_repo_cloned()` (prompt 189/01).
 *   - El build necesita branding por cliente (color + nombre + íconos PWA), leído en vivo del
 *     online_configuration de la tienda vía HTTP.
 *   - tienda-api corre PHP 8.4 en el hosting del cliente; esta clase (admin-api) sigue en PHP 7.4.
 *
 * Esta clase cubre `mode = 'install'`. Las actualizaciones (`mode = 'update'`, recompilar y
 * resubir sin tocar .env) las maneja `EcommerceDeploymentService` (prompt 585), que EXTIENDE esta
 * clase para reutilizar sus pasos y helpers (ensure_spa_cloned, compile_spa, upload_spa, SSH/SFTP,
 * paths) sin duplicarlos — por eso las propiedades y métodos de abajo son `protected` en vez de
 * `private`. `expected_mode()` es el único punto que la subclase sobrescribe para validar su mode.
 *
 * Deuda técnica (prioridad media, a registrar): los helpers de SSH/SFTP/ZIP de esta clase están
 * duplicados desde InstallationService (empresa) porque esos métodos son privados ahí y no existe
 * hoy un trait/base compartida entre el pipeline de empresa y el de ecommerce. Sería valioso extraer
 * un `DeploySshHelpers` común en una refactorización aparte; no se hace acá para no tocar el pipeline
 * de empresa (que ya está en producción) dentro de un prompt de ecommerce.
 *
 * Pipeline de pasos en orden:
 *   1. ensure_spa_cloned — clona tienda-spa en el VPS si es la primera vez; si ya existe, fetch +
 *                          checkout master + reset --hard (siempre la última de master).
 *   2. compile_spa       — branding en vivo (color/nombre/logo), .env del SPA, patch de
 *                          vue.config.js, generación de íconos PWA, npm ci + npm run build.
 *   3. upload_spa        — zip de dist/, subida y despliegue con mv atómico en la raíz del dominio.
 *   4. upload_api        — clona/actualiza tienda-api en el VPS (ensure_repo_cloned), sube el
 *                          código a la subcarpeta /api y corre composer install --no-scripts.
 *   5. write_env         — genera el .env de tienda-api (plantilla scope=tienda + DB/APP_KEY de
 *                          la empresa del mismo cliente).
 *   6. finalize          — bootstrap/cache, package:discover, symlink storage, limpieza de caches.
 */
class EcommerceInstallationService
{
    /**
     * Corrida de instalación del ecommerce en curso.
     *
     * @var ClientEcommerceInstallation
     */
    protected $installation;

    /**
     * Tienda (ecommerce) que se está instalando.
     *
     * @var \App\Models\ClientEcommerce
     */
    protected $ecommerce;

    /**
     * Cliente dueño de la tienda (para leer company_name, user_id y la API de empresa).
     *
     * @var \App\Models\Client
     */
    protected $client;

    /**
     * Sesión SSH activa al hosting compartido del cliente (phpseclib).
     *
     * @var SSH2|null
     */
    protected $ssh;

    /**
     * Sesión SSH al VPS de builds (donde se clona/compila tienda-spa y se prepara tienda-api).
     *
     * @var SSH2|null
     */
    protected $build_ssh;

    /**
     * Prefijo estándar de rutas en el hosting compartido.
     *
     * A diferencia de `InstallationService` (empresa), donde cada cliente es un subdirectorio
     * dentro del dominio fijo `comerciocity.com`, la tienda NO funciona así: cada ecommerce vive
     * en su propio dominio de Hostinger (convención de Lucas, 22/7/2026). Por eso acá el prefijo
     * es solo `domains/` — el resto de la ruta (`{dominio}/public_html` para el SPA y
     * `{dominio}/public_html/api` para tienda-api) lo arman los helpers `resolve_spa_path()` /
     * `resolve_api_path()` de `ClientEcommerce` a partir del dominio real de cada tienda.
     *
     * @var string
     */
    protected const HOSTING_PREFIX = 'domains/';

    /**
     * Orden de etapas del pipeline de instalación del ecommerce.
     *
     * @var array<int, string>
     */
    protected $steps = [
        'ensure_spa_cloned',
        'compile_spa',
        'upload_spa',
        'upload_api',
        'write_env',
        'finalize',
    ];

    /**
     * Carga la corrida, la tienda y el cliente. Valida que sea una instalación (mode = install):
     * las actualizaciones se resuelven en EcommerceDeploymentService (prompt 585).
     *
     * @param  ClientEcommerceInstallation  $installation
     * @throws \RuntimeException  Si no hay tienda asociada o el mode no es 'install'.
     */
    public function __construct(ClientEcommerceInstallation $installation)
    {
        $this->installation = $installation;
        $this->installation->loadMissing('client_ecommerce.client.active_client_api');

        $this->ecommerce = $this->installation->client_ecommerce;
        if ($this->ecommerce === null) {
            throw new \RuntimeException('La corrida no tiene tienda (client_ecommerce) asociada.');
        }

        $this->client = $this->ecommerce->client;
        if ($this->client === null) {
            throw new \RuntimeException('La tienda no tiene cliente asociado.');
        }

        $this->assert_expected_mode();
    }

    /**
     * Mode que maneja esta clase ('install'). EcommerceDeploymentService (prompt 585) sobrescribe
     * este método para devolver 'update' y reutilizar el resto del pipeline/constructor sin
     * duplicar la carga de relaciones ni las validaciones de arriba.
     *
     * @return string
     */
    protected function expected_mode(): string
    {
        return 'install';
    }

    /**
     * Valida que el mode de la corrida coincida con el que maneja esta clase (o la subclase que
     * sobrescriba expected_mode()). Evita que una corrida 'install' se procese como 'update' o
     * viceversa.
     *
     * @return void
     * @throws \RuntimeException  Si el mode no coincide con expected_mode().
     */
    protected function assert_expected_mode(): void
    {
        $expected = $this->expected_mode();
        if ($this->installation->mode !== $expected) {
            $class_name = static::class;
            throw new \RuntimeException(
                "{$class_name} solo maneja mode='{$expected}'. "
                . "Esta corrida es mode='{$this->installation->mode}'."
            );
        }
    }

    /**
     * Orquesta todas las etapas del pipeline de instalación del ecommerce.
     *
     * @return void
     * @throws \Throwable  Si alguna etapa falla.
     */
    public function run()
    {
        // Estado previo de la tienda, para poder restaurarlo si la instalación falla (no debe
        // quedar colgada en 'installing').
        $previous_ecommerce_status = $this->ecommerce->status;

        try {
            $this->installation->update([
                'status'     => 'instalando',
                'started_at' => now(),
            ]);
            $this->ecommerce->update(['status' => 'installing']);

            $this->execute_steps();

            $this->installation->update([
                'status'      => 'completada',
                'finished_at' => now(),
            ]);
            $this->ecommerce->update(['status' => 'active']);
        } catch (\Throwable $e) {
            $this->log('installation', $e->getMessage(), 'error');
            $this->installation->update([
                'status'         => 'fallida',
                'finished_at'    => now(),
                'failure_reason' => $e->getMessage(),
            ]);
            // La tienda no debe quedar en 'installing' ante un fallo: vuelve al estado previo.
            $this->ecommerce->update(['status' => $previous_ecommerce_status]);
            throw $e;
        }
    }

    /**
     * Ejecuta cada etapa del pipeline en orden.
     *
     * @return void
     */
    protected function execute_steps()
    {
        foreach ($this->steps as $step) {
            switch ($step) {
                case 'ensure_spa_cloned':
                    $this->step_ensure_spa_cloned();
                    break;
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
                case 'finalize':
                    $this->step_finalize();
                    break;
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ETAPAS DEL PIPELINE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Etapa 1: asegura que tienda-spa esté clonado en el VPS de builds (rama master).
     *
     * Envoltorio finito: conecta al VPS de builds y delega toda la lógica de clonado/actualización
     * en `ensure_repo_cloned()` (compartida con `upload_api()` para tienda-api, prompt 189/01).
     *
     * @return void
     * @throws \RuntimeException  Si no está clonado y falta configurar el repo git de origen.
     */
    protected function step_ensure_spa_cloned()
    {
        $this->connect_build_vps();

        $this->ensure_repo_cloned(
            'ensure_spa_cloned',
            $this->builds_spa_path(),
            trim((string) config('services.deploy_tienda.spa_git_repo', '')),
            'tienda-spa',
            'DEPLOY_TIENDA_SPA_GIT_REPO'
        );
    }

    /**
     * Deja un repo del VPS de builds en la última de master, clonándolo si es la primera vez.
     *
     * Concentra la lógica que antes vivía duplicada dentro de `step_ensure_spa_cloned()` (tienda-spa)
     * y al principio de `step_upload_api()` (tienda-api), para que ambos repos del VPS de builds se
     * resuelvan igual (prompt 189/01: antes solo se clonaba tienda-spa, y encima solo si estaba
     * definida la variable de entorno; tienda-api se asumía ya clonada y la corrida moría más
     * adelante con un `cd` a un directorio inexistente).
     *
     * Requiere una sesión SSH al VPS de builds ya abierta (`connect_build_vps()` corrido por el
     * llamador antes de invocar este helper).
     *
     * @param  string  $step             Etapa del pipeline bajo la que se loguean los comandos
     *                                    (para que el progreso siga apareciendo en la etapa correcta
     *                                    del panel de seguimiento, sin agregar una etapa nueva).
     * @param  string  $repo_path        Ruta absoluta del clone en el VPS de builds.
     * @param  string  $git_repo         URL del repo git de origen (config `deploy_tienda.*_git_repo`).
     * @param  string  $repo_label       Nombre legible del repo para los logs/mensajes ("tienda-spa",
     *                                   "tienda-api").
     * @param  string  $env_var_name     Nombre de la variable de entorno que hay que definir en el
     *                                   `.env` de admin-api si el repo no está clonado y falta
     *                                   configurar el origen (se cita literal en el mensaje de error).
     * @return void
     * @throws \RuntimeException  Si no está clonado y falta el repo de origen, o si la ruta destino
     *                            existe pero no es un repo git válido.
     */
    protected function ensure_repo_cloned(
        string $step,
        string $repo_path,
        string $git_repo,
        string $repo_label,
        string $env_var_name
    ): void {
        // Verifica si el directorio ya es un repo git clonado.
        $check_cmd = 'test -d ' . escapeshellarg($repo_path . '/.git') . ' && echo REPO_CLONED || echo REPO_NOT_CLONED';
        $check_output = $this->exec_build_ssh($step, $check_cmd, false);

        if (stripos($check_output, 'REPO_CLONED') !== false) {
            // Ya clonado: siempre trae la última de master (sin selección de tag/versión).
            $this->log($step, "{$repo_label} ya está clonado; actualizando a la última de master");
            $this->exec_build_ssh(
                $step,
                'cd ' . escapeshellarg($repo_path) . ' && git fetch origin master 2>&1'
            );
            $this->exec_build_ssh(
                $step,
                'cd ' . escapeshellarg($repo_path) . ' && git checkout master 2>&1'
            );
            $this->exec_build_ssh(
                $step,
                'cd ' . escapeshellarg($repo_path) . ' && git reset --hard origin/master 2>&1'
            );
            $this->log($step, "{$repo_label} quedó en la última de master", 'success');

            return;
        }

        // No está clonado: primera instalación de ecommerce en este VPS (o el repo se movió/perdió).
        if ($git_repo === '') {
            throw new \RuntimeException(
                "{$repo_label} no está clonado en {$repo_path} del VPS de builds y falta configurar "
                . "{$env_var_name} en el .env de admin-api. Alternativa manual: clonarlo una única vez "
                . "en el VPS con git clone --branch master " . '<repo> ' . "{$repo_path}."
            );
        }

        // Caso borde: la ruta destino existe pero no tiene .git (clone cortado a la mitad, o carpeta
        // creada a mano). git clone fallaría con "destination path already exists and is not an
        // empty directory", un error críptico que no explica qué hay que hacer.
        $exists_cmd = 'test -e ' . escapeshellarg($repo_path) . ' && echo REPO_PATH_EXISTS || echo REPO_PATH_FREE';
        $exists_output = $this->exec_build_ssh($step, $exists_cmd, false);
        if (stripos($exists_output, 'REPO_PATH_EXISTS') !== false) {
            throw new \RuntimeException(
                "La ruta {$repo_path} del VPS de builds existe pero no es un repo git válido "
                . "(no tiene .git). Hay que borrarla o repararla a mano antes de reintentar la corrida."
            );
        }

        $this->log($step, "{$repo_label} no está clonado; clonando rama master...");
        $this->exec_build_ssh(
            $step,
            'mkdir -p ' . escapeshellarg(dirname($repo_path)) . ' 2>&1'
        );
        $this->exec_build_ssh(
            $step,
            'git clone --branch master --single-branch '
            . escapeshellarg($git_repo) . ' ' . escapeshellarg($repo_path) . ' 2>&1',
            true,
            true
        );
        $this->log($step, "{$repo_label} clonado correctamente", 'success');
    }

    /**
     * Etapa 2: branding en vivo + .env del SPA + patch de vue.config.js + íconos PWA + build.
     *
     * @return void
     * @throws \RuntimeException  Si el build de npm no finaliza correctamente.
     */
    protected function step_compile_spa()
    {
        $this->connect_build_vps();

        // a) Branding en vivo: color primario y logo del online_configuration de la tienda.
        [$primary_color, $logo_url] = $this->fetch_online_configuration_branding();

        $spa_build_path = $this->builds_spa_path();

        // b) .env del SPA: SOLO las 3 variables que necesita tienda-spa para bootear (sin Google,
        // sin VARIANT_COLOR y sin NO_PAUSAR — a diferencia de empresa, ver contexto del prompt 584).
        $api_url_for_env = $this->get_ecommerce_api_url_for_env();
        $spa_url         = trim((string) $this->ecommerce->spa_url);
        if ($spa_url === '') {
            throw new \RuntimeException('La tienda no tiene spa_url configurada.');
        }
        if ($this->client->user_id === null) {
            throw new \RuntimeException('El cliente no tiene user_id (bloque ComercioCity) configurado.');
        }

        $env_content = $this->build_spa_env_file_content($api_url_for_env, $spa_url);
        $env_escaped = str_replace("'", "'\\''", $env_content);
        $env_file    = $spa_build_path . '/.env';
        $this->exec_build_ssh(
            'compile_spa',
            "printf '%s' '{$env_escaped}' > " . escapeshellarg($env_file)
        );
        $this->log('compile_spa', "Archivo .env del SPA configurado — API: {$api_url_for_env} | SPA: {$spa_url}");

        // c) Patchea vue.config.js: themeColor + name dentro del bloque pwa. Idempotente: solo
        // reemplaza el valor de las líneas ya existentes (no agrega ni duplica líneas).
        $this->patch_spa_vue_config($spa_build_path, $primary_color, $this->pwa_display_name());

        // d) Íconos PWA a partir del logo (si hay logo_url; si no, se deja el set genérico versionado).
        if ($logo_url !== null) {
            $this->step_generate_pwa_icons($spa_build_path, $logo_url);
        } else {
            $this->log(
                'compile_spa',
                'Sin logo_url en online_configuration: se deja el set de íconos genérico versionado',
                'warning'
            );
        }

        // e) npm ci + npm run build (mismo mecanismo que empresa).
        $npm_bin = trim((string) config('services.deploy.npm_bin', 'npm'));
        $this->assert_vps_npm_available($spa_build_path, $npm_bin);

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

        // Reconecta tras npm run build (el canal SSH puede quedar abierto en phpseclib).
        $this->reconnect_build_vps();
        $this->log('compile_spa', 'Reconectado al VPS tras el build');

        $this->assert_spa_dist_directory_on_vps($spa_build_path, $this->spa_output_dir_name());
    }

    /**
     * Sub-etapa de compile_spa: descarga el logo y genera el set de íconos PWA con el script node
     * `deploy/tienda/generate_pwa_icons.js` (ver ese archivo para el detalle de cada tamaño).
     *
     * @param  string  $spa_build_path
     * @param  string  $logo_url
     * @return void
     */
    protected function step_generate_pwa_icons(string $spa_build_path, string $logo_url)
    {
        $this->log('compile_spa', 'Generando íconos PWA desde el logo del cliente...');

        // Descarga el logo a un archivo temporal en el VPS (curl, ya disponible junto con node/npm).
        $remote_logo_path = $spa_build_path . '/.deploy_logo_tmp';
        $download_output  = $this->exec_build_ssh(
            'compile_spa',
            'curl -sL -o ' . escapeshellarg($remote_logo_path) . ' ' . escapeshellarg($logo_url)
            . ' && test -s ' . escapeshellarg($remote_logo_path) . ' && echo LOGO_DOWNLOAD_OK || echo LOGO_DOWNLOAD_FAILED',
            false
        );
        if (stripos($download_output, 'LOGO_DOWNLOAD_OK') === false) {
            // No corta la instalación: se loguea warning y se deja el set genérico versionado.
            $this->log(
                'compile_spa',
                'No se pudo descargar el logo (' . $this->truncate_for_log($download_output, 300)
                . '); se deja el set de íconos genérico',
                'warning'
            );

            return;
        }

        // Instala "sharp" en el clone del VPS sin persistirlo en package.json (--no-save): es una
        // dependencia solo de generación de íconos, no del bundle final de la SPA.
        $this->log('compile_spa', 'Instalando sharp para generar íconos...');
        $this->exec_build_ssh(
            'compile_spa',
            $this->build_vps_command(
                $spa_build_path,
                'npm install sharp --no-save --no-audit --no-fund 2>&1'
            ),
            true,
            true
        );

        // Copia el script generador al VPS (contenido versionado en admin-api/deploy/tienda/).
        $script_local_path = base_path('deploy/tienda/generate_pwa_icons.js');
        if (! is_file($script_local_path)) {
            throw new \RuntimeException("No se encontró el script local: {$script_local_path}");
        }
        $script_content  = file_get_contents($script_local_path);
        $script_escaped  = str_replace("'", "'\\''", $script_content);
        $remote_script    = $spa_build_path . '/.deploy_generate_pwa_icons.js';
        $this->exec_build_ssh(
            'compile_spa',
            "printf '%s' '{$script_escaped}' > " . escapeshellarg($remote_script)
        );

        // Corre el script: logo descargado -> public/img/icons del repo.
        $icons_output_dir = $spa_build_path . '/public/img/icons';
        $run_output = $this->exec_build_ssh(
            'compile_spa',
            $this->build_vps_command(
                $spa_build_path,
                'node ' . escapeshellarg($remote_script) . ' '
                . escapeshellarg($remote_logo_path) . ' '
                . escapeshellarg($icons_output_dir) . ' 2>&1'
            ),
            true,
            true
        );
        $this->log('compile_spa', $this->truncate_for_log($run_output, 1200));

        if (stripos($run_output, 'GENERATE_ICONS_DONE') === false) {
            throw new \RuntimeException(
                'La generación de íconos PWA no terminó OK. ' . $this->truncate_for_log($run_output, 600)
            );
        }
        $this->log('compile_spa', 'Íconos PWA generados desde el logo del cliente', 'success');

        // Limpia el logo y el script temporales del VPS.
        $this->exec_build_ssh(
            'compile_spa',
            'rm -f ' . escapeshellarg($remote_logo_path) . ' ' . escapeshellarg($remote_script),
            false
        );
    }

    /**
     * Etapa 3: empaqueta dist/ y lo despliega en la RAÍZ del dominio del cliente, con mv atómico
     * para evitar downtime (un solo dominio, sin swap v1/v2 como en empresa).
     *
     * @return void
     */
    protected function step_upload_spa()
    {
        $this->connect_build_vps();

        $spa_build_path = $this->builds_spa_path();
        $spa_output_dir = $this->spa_output_dir_name();

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

        $deployments_dir = storage_path('app/deployments');
        if (! is_dir($deployments_dir)) {
            mkdir($deployments_dir, 0755, true);
        }
        $local_zip  = storage_path('app/deployments/tienda_dist_' . $this->installation->uuid . '.zip');
        $sftp_build = $this->open_sftp_session('vps');
        $this->sftp_download_file($sftp_build, $spa_zip_remote, $local_zip, $spa_zip_bytes, 'upload_spa');
        $this->log('upload_spa', 'ZIP descargado al servidor de admin');

        // Sube el ZIP a una ruta TEMPORAL en el hosting (nunca directo al docroot en vivo).
        // Se usa resolve_spa_path() (no la columna cruda spa_path, que puede estar vacía) para que
        // el dirname() no dé basura: dirname('') devolvería '.' y armaría una ruta inválida.
        // dirname('{dominio}/public_html') da '{dominio}', o sea que el ZIP queda en
        // domains/{dominio}/, fuera del docroot público.
        $spa_docroot   = $this->get_spa_docroot();
        $temp_zip_name = 'dist_' . $this->installation->uuid . '.zip';
        $temp_zip_path = self::HOSTING_PREFIX . dirname($this->ecommerce->resolve_spa_path()) . '/' . $temp_zip_name;

        $sftp_hosting = $this->open_sftp_session('shared_hosting');
        $this->sftp_upload_file($sftp_hosting, $local_zip, $temp_zip_path, 'upload_spa');
        $this->log('upload_spa', 'ZIP subido al hosting (ruta temporal)');

        // Descomprime en una carpeta hermana temporal y recién al final hace el mv atómico sobre
        // el docroot: mientras se descomprime, el dominio en vivo sigue sirviendo el contenido
        // anterior sin interrupción.
        $this->reconnect_hosting_ssh();
        $this->exec_hosting_ssh(
            'upload_spa',
            $this->build_spa_atomic_deploy_shell($spa_docroot, $temp_zip_path)
        );
        $this->log('upload_spa', 'SPA desplegado en la raíz del dominio (mv atómico, sin downtime)', 'success');

        if (is_file($local_zip)) {
            unlink($local_zip);
        }

        $this->reconnect_build_vps();
        $this->exec_build_ssh('upload_spa', 'rm -f ' . escapeshellarg($spa_zip_remote));
    }

    /**
     * Etapa 4: sube tienda-api a la subcarpeta /api del dominio del cliente.
     *
     * @return void
     */
    protected function step_upload_api()
    {
        $this->connect_build_vps();

        $api_build_path = $this->builds_api_path();
        $this->log('upload_api', 'Preparando tienda-api en VPS de builds (última de master)');

        // Clona tienda-api si es la primera vez en este VPS (misma lógica que tienda-spa, prompt
        // 189/01: antes se asumía ya clonada y la corrida moría acá con un cd a un directorio
        // inexistente cuando no lo estaba).
        $this->ensure_repo_cloned(
            'upload_api',
            $api_build_path,
            trim((string) config('services.deploy_tienda.api_git_repo', '')),
            'tienda-api',
            'DEPLOY_TIENDA_API_GIT_REPO'
        );

        $this->log('upload_api', 'Corriendo composer install en VPS...');
        $this->exec_build_ssh(
            'upload_api',
            $this->build_composer_install_command($api_build_path, true)
        );
        $this->log('upload_api', 'composer install en VPS completado', 'success');

        // Empaqueta la API excluyendo lo que no debe viajar: .env (se genera en write_env),
        // vendor/ (se instala vía composer en el hosting), bootstrap/cache/, .git/ y ZIPs huérfanos.
        $zip_name       = 'tienda_api_install_' . $this->installation->uuid . '.zip';
        $api_zip_remote = $api_build_path . '/' . $zip_name;
        $this->reconnect_build_vps();

        $this->exec_build_ssh(
            'upload_api',
            'cd ' . escapeshellarg($api_build_path)
            . " && find . -maxdepth 1 -name 'tienda_api_*.zip' -mmin +120 -delete 2>&1"
        );

        $zip_command = 'cd ' . escapeshellarg($api_build_path)
            . ' && rm -f ' . escapeshellarg($zip_name)
            . ' && zip -r ' . escapeshellarg($zip_name) . ' . '
            . "--exclude='.env' --exclude='vendor/*' --exclude='bootstrap/cache/*'"
            . " --exclude='.git/*' --exclude='*.zip'"
            . ' 2>&1';
        $this->exec_build_ssh('upload_api', $zip_command, true, true);

        $api_zip_bytes = $this->verify_zip_on_vps($api_zip_remote, 'upload_api');
        $this->log('upload_api', "tienda-api empaquetada ({$api_zip_bytes} bytes en VPS)");

        $deployments_dir = storage_path('app/deployments');
        if (! is_dir($deployments_dir)) {
            mkdir($deployments_dir, 0755, true);
        }
        $local_zip  = storage_path('app/deployments/tienda_api_' . $this->installation->uuid . '.zip');
        $sftp_build = $this->open_sftp_session('vps');
        $this->sftp_download_file($sftp_build, $api_zip_remote, $local_zip, $api_zip_bytes, 'upload_api');
        $this->log('upload_api', 'ZIP descargado al servidor de admin');

        $api_path = $this->get_api_path();

        // Prompt 191/01: asegura que el directorio destino exista en el hosting ANTES de subir el
        // ZIP por SFTP. En instalación normal ya lo crea unzip/mkdir de etapas previas, pero si el
        // deploy del SPA se ejecutó antes y se llevó puesta la carpeta /api (bug del mismo prompt),
        // esto la reconstruye en vez de morir con un SFTP put a un directorio inexistente.
        $this->ensure_hosting_api_directory('upload_api');

        $remote_zip   = "{$api_path}/{$zip_name}";
        $sftp_hosting = $this->open_sftp_session('shared_hosting');
        $this->sftp_upload_file($sftp_hosting, $local_zip, $remote_zip, 'upload_api');
        $this->log('upload_api', 'ZIP subido al hosting');

        $this->reconnect_hosting_ssh();
        $this->exec_hosting_ssh(
            'upload_api',
            "cd {$api_path} && unzip -o {$zip_name} && rm {$zip_name}",
            true,
            true
        );
        $this->log('upload_api', 'tienda-api descomprimida en el hosting (/api)');

        // composer install sin scripts: el .env todavía no existe (se genera en write_env) y
        // el script post-autoload-dump (package:discover) bootea el framework y fallaría sin él.
        $this->log('upload_api', 'Corriendo composer install en hosting (sin scripts; el .env aún no existe)...');
        $this->reconnect_hosting_ssh();
        $this->exec_hosting_ssh(
            'upload_api',
            $this->build_composer_install_command($api_path, false),
            true,
            true
        );
        $this->log('upload_api', 'tienda-api lista en el hosting', 'success');

        if (is_file($local_zip)) {
            unlink($local_zip);
        }
        $this->reconnect_build_vps();
        $this->exec_build_ssh('upload_api', 'rm -f ' . escapeshellarg($api_zip_remote));
        $this->log('upload_api', 'Archivos temporales eliminados');
    }

    /**
     * Etapa 5: genera y escribe el .env de tienda-api en el hosting.
     *
     * Combina:
     *   a) Plantilla base de env_templates con scope = 'tienda' (grupo 160).
     *   b) APP_NAME (razón social del cliente) y APP_URL (api_url de la tienda, sin /public).
     *   c) SANCTUM_STATEFUL_DOMAINS / SANCTUM_STATEFUL_CORS / SANCTUM_STATEFUL_CORS_WWW /
     *      SESSION_DOMAIN derivadas del domain de la tienda.
     *   d) DB_DATABASE / DB_USERNAME / DB_PASSWORD / APP_KEY: se TOMAN del .env de empresa del
     *      mismo cliente (misma base de datos; APP_KEY unificada por decisión de producto — ver
     *      prompt 584), leídos vía EnvSshService::read_env() sobre la ClientApi activa del cliente.
     *
     * NOTA (deuda registrada, prioridad media): EnvTemplate todavía no tiene la columna `scope`
     * que este método necesita (debía agregarla el grupo 160, declarado como dependencia de este
     * prompt, pero no está aplicada en este repo al momento de escribir este servicio). El filtro
     * `where('scope', 'tienda')` de abajo va a fallar en runtime con "Unknown column 'scope'"
     * hasta que esa migración/columna exista. Se deja tal cual porque así lo pide la especificación
     * del prompt 584 y es responsabilidad de otro prompt (160) resolver el dependency gap.
     *
     * @return void
     * @throws \RuntimeException  Si el cliente no tiene una ClientApi activa (empresa) para clonar DB/APP_KEY.
     */
    protected function step_write_env()
    {
        $this->log('write_env', 'Generando .env de tienda-api para la instalación inicial...');

        // a) Plantilla base con scope = 'tienda' (variables propias de tienda-api).
        $base_templates = EnvTemplate::where('scope', 'tienda')
            ->orderBy('group')
            ->orderBy('sort_order')
            ->get();

        $vars_to_write = [];
        foreach ($base_templates as $template) {
            $vars_to_write[$template->key] = (string) ($template->value ?? '');
        }

        // b) APP_NAME / APP_URL.
        $vars_to_write['APP_NAME'] = (string) ($this->client->company_name ?? $this->client->name ?? 'Tienda');
        $vars_to_write['APP_URL']  = rtrim((string) $this->ecommerce->api_url, '/');

        // c) Variables derivadas del dominio único de la tienda.
        $domain = trim((string) $this->ecommerce->domain);
        if ($domain !== '') {
            $vars_to_write['SANCTUM_STATEFUL_DOMAINS']  = $domain;
            $vars_to_write['SANCTUM_STATEFUL_CORS']     = 'https://' . $domain;
            $vars_to_write['SANCTUM_STATEFUL_CORS_WWW'] = 'https://www.' . $domain;
            $vars_to_write['SESSION_DOMAIN']            = $domain;
        }

        // d) DB y APP_KEY: se copian del .env de empresa-api del mismo cliente (misma base de
        // datos física; la APP_KEY se unifica entre empresa y tienda por decisión de producto).
        $empresa_client_api = $this->client->active_client_api;
        if ($empresa_client_api === null) {
            throw new \RuntimeException(
                'El cliente no tiene una ClientApi activa (empresa): no se puede clonar DB/APP_KEY para tienda-api.'
            );
        }

        $env_ssh_service  = new EnvSshService();
        $empresa_api_path = $env_ssh_service->get_api_path($empresa_client_api);
        $empresa_env_vars = $env_ssh_service->read_env($empresa_api_path);

        foreach (['DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'APP_KEY'] as $shared_key) {
            if (isset($empresa_env_vars[$shared_key])) {
                $vars_to_write[$shared_key] = $empresa_env_vars[$shared_key];
            }
        }
        $this->log('write_env', 'DB_DATABASE/DB_USERNAME/DB_PASSWORD/APP_KEY tomadas del .env de empresa del cliente');

        $this->log(
            'write_env',
            'Variables a escribir: ' . count($vars_to_write) . ' (' . implode(', ', array_keys($vars_to_write)) . ')'
        );

        $api_path = $this->get_api_path();

        // Si el .env todavía no existe en el hosting, lo crea vacío (touch) antes de escribir.
        $this->reconnect_hosting_ssh();
        $env_file  = $api_path . '/.env';
        $touch_cmd = 'test -f ' . escapeshellarg($env_file) . ' || touch ' . escapeshellarg($env_file);
        $this->exec_hosting_ssh('write_env', $touch_cmd, false);

        $env_ssh_service->write_env_vars($api_path, $vars_to_write);

        $this->log('write_env', '.env de tienda-api generado y escrito en el hosting', 'success');
    }

    /**
     * Etapa 6: finaliza la instalación de tienda-api en el hosting.
     *
     * Igual que InstallationService::step_finalize_api(): recrea bootstrap/cache, corre
     * package:discover, crea el symlink de storage y limpia caches. NO corre migraciones: la base
     * de datos la gestiona empresa (misma base física, compartida).
     *
     * @return void
     * @throws \RuntimeException  Si package:discover falla (la API no podría bootear).
     */
    protected function step_finalize()
    {
        $api_path = $this->get_api_path();

        // Resuelve el binario de PHP configurado para tienda-api. En hosting compartido con
        // CloudLinux, el php de PATH apunta a PHP 7.4 (necesario para admin-api), pero
        // tienda-api requiere PHP 8.4+. Sin usar el binario explícito, el Composer platform_check
        // aborta durante artisan package:discover.
        $php_bin = escapeshellarg(trim((string) config('services.deploy_tienda.php_bin', '/opt/alt/php84/usr/bin/php')));

        $this->log('finalize', 'Limpiando cache de bootstrap...');
        $this->reconnect_hosting_ssh();

        $this->exec_hosting_ssh(
            'finalize',
            'cd ' . escapeshellarg($api_path)
            . ' && mkdir -p bootstrap/cache && chmod 775 bootstrap/cache 2>&1',
            false
        );

        $this->exec_hosting_ssh(
            'finalize',
            'cd ' . escapeshellarg($api_path)
            . ' && rm -f bootstrap/cache/config.php bootstrap/cache/routes-*.php'
            . ' bootstrap/cache/packages.php bootstrap/cache/services.php 2>&1',
            false
        );

        $this->log('finalize', 'Ejecutando artisan package:discover...');
        $discover_output = $this->exec_hosting_ssh(
            'finalize',
            'cd ' . escapeshellarg($api_path) . ' && ' . $php_bin . ' artisan package:discover --no-ansi 2>&1',
            true,
            true
        );
        $this->log('finalize', $this->truncate_for_log($discover_output));
        $this->log('finalize', 'Paquetes descubiertos correctamente', 'success');

        $this->log('finalize', 'Creando symlink de storage...');
        $storage_link_output = $this->exec_hosting_ssh(
            'finalize',
            'cd ' . escapeshellarg($api_path) . ' && ' . $php_bin . ' artisan storage:link --no-ansi 2>&1',
            false
        );
        $this->log('finalize', $this->truncate_for_log($storage_link_output));

        // No se corren migraciones acá: la base es la misma que usa empresa-api del cliente.
        $clear_commands = ['config:clear', 'cache:clear', 'view:clear', 'route:clear'];
        foreach ($clear_commands as $clear_command) {
            $this->exec_hosting_ssh(
                'finalize',
                'cd ' . escapeshellarg($api_path) . ' && ' . $php_bin . ' artisan ' . $clear_command . ' --no-ansi 2>&1',
                false
            );
        }
        $this->log('finalize', 'tienda-api finalizada y lista para bootear', 'success');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BRANDING (online_configuration en vivo)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Consulta GET {api_url}/api/commerce/{commerce_id} para obtener el branding en vivo de la
     * tienda (mismo endpoint que usa tienda-spa al bootear, ver tienda-spa/src/store/commerce.js).
     *
     * Diseñado para degradar con gracia: en una instalación desde cero tienda-api todavía no está
     * desplegada, así que esta llamada típicamente FALLA (se usa el color por defecto). En una
     * futura re-ejecución (update, prompt 585) con tienda-api ya viva, sí trae el color/logo reales.
     *
     * @return array{0: string, 1: string|null}  [primary_color, logo_url|null]
     */
    protected function fetch_online_configuration_branding(): array
    {
        $default_color = trim((string) config('services.deploy_tienda.default_theme_color', '#c5111d'));
        $commerce_id   = $this->client->user_id;
        $api_url       = trim((string) $this->ecommerce->api_url);

        if ($api_url === '' || $commerce_id === null) {
            $this->log(
                'compile_spa',
                'Sin api_url o user_id todavía: se usa el color por defecto ' . $default_color,
                'warning'
            );

            return [$default_color, null];
        }

        $endpoint = rtrim($this->get_ecommerce_api_url_for_env(), '/') . "/api/commerce/{$commerce_id}";
        $timeout  = (int) config('services.deploy_tienda.commerce_config_timeout', 5);

        try {
            $response = Http::timeout($timeout)->get($endpoint);
        } catch (\Throwable $e) {
            $this->log(
                'compile_spa',
                "No se pudo consultar {$endpoint} ({$e->getMessage()}); se usa el color por defecto",
                'warning'
            );

            return [$default_color, null];
        }

        if (! $response->successful()) {
            $this->log(
                'compile_spa',
                "GET {$endpoint} respondió {$response->status()}; se usa el color por defecto",
                'warning'
            );

            return [$default_color, null];
        }

        $online_configuration = $response->json('commerce.online_configuration', []);
        if (! is_array($online_configuration)) {
            $online_configuration = [];
        }

        $primary_color = trim((string) ($online_configuration['primary_color'] ?? ''));
        if ($primary_color === '') {
            $this->log('compile_spa', 'Falta primary_color en online_configuration; se usa el color por defecto', 'warning');
            $primary_color = $default_color;
        }

        $logo_url = isset($online_configuration['logo_url']) ? trim((string) $online_configuration['logo_url']) : '';
        if ($logo_url === '') {
            $logo_url = null;
        }

        $this->log('compile_spa', "Branding leído del online_configuration — color: {$primary_color}");

        return [$primary_color, $logo_url];
    }

    /**
     * Nombre del negocio a usar como nombre del PWA (manifest.json acepta espacios).
     *
     * @return string
     */
    protected function pwa_display_name(): string
    {
        $name = trim((string) ($this->client->company_name ?? ''));
        if ($name === '') {
            $name = trim((string) ($this->client->name ?? 'Tienda'));
        }

        return $name;
    }

    /**
     * Reemplaza `themeColor` y `name` dentro del bloque `pwa` de vue.config.js en el VPS.
     *
     * Usa sed anclado a inicio de línea (permitiendo espacios previos) para tocar SOLO líneas no
     * comentadas: las líneas comentadas empiezan con "//" y por lo tanto no matchean el patrón
     * `^\s*themeColor:`. Es idempotente: siempre reemplaza el valor completo de la línea existente,
     * nunca agrega una línea nueva, así que correrlo de nuevo no acumula cambios.
     *
     * @param  string  $spa_build_path
     * @param  string  $theme_color
     * @param  string  $display_name
     * @return void
     */
    protected function patch_spa_vue_config(string $spa_build_path, string $theme_color, string $display_name)
    {
        $vue_config_file = $spa_build_path . '/vue.config.js';

        $theme_color_escaped = $this->escape_sed_replacement($theme_color);
        $display_name_escaped = $this->escape_sed_replacement($display_name);

        $this->exec_build_ssh(
            'compile_spa',
            'sed -i ' . escapeshellarg('s/^\\(\\s*\\)themeColor:.*/\\1themeColor: "' . $theme_color_escaped . '",/')
            . ' ' . escapeshellarg($vue_config_file)
        );
        $this->exec_build_ssh(
            'compile_spa',
            'sed -i ' . escapeshellarg('s/^\\(\\s*\\)name:.*/\\1name: "' . $display_name_escaped . '",/')
            . ' ' . escapeshellarg($vue_config_file)
        );

        $this->log(
            'compile_spa',
            "vue.config.js patcheado — themeColor: {$theme_color} | name: {$display_name}",
            'success'
        );
    }

    /**
     * Escapa un valor para usarlo de forma segura como reemplazo en un comando sed (mismo criterio
     * que EnvSshService::escape_sed_replacement, duplicado acá porque ese método es privado).
     *
     * @param  string  $value
     * @return string
     */
    protected function escape_sed_replacement(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace('/', '\\/', $value);
        $value = str_replace('&', '\\&', $value);
        $value = str_replace('"', '\\"', $value);

        return $value;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS SSH / SFTP (duplicados de InstallationService — ver nota de deuda técnica arriba)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Conecta por SSH al VPS de builds.
     *
     * @return void
     */
    protected function connect_build_vps()
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
    protected function disconnect_build_vps(): void
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
    protected function reconnect_build_vps(): void
    {
        $this->connect_build_vps();
    }

    /**
     * Conecta por SSH al hosting compartido.
     *
     * @return void
     */
    protected function connect_hosting_ssh(): void
    {
        $this->disconnect_hosting_ssh();

        $credential = ClientSshCredential::where('type', 'shared_hosting')->firstOrFail();
        $this->ssh  = new SSH2($credential->host, (int) $credential->port);

        $logged_in = $this->ssh->login($credential->username, $credential->password);
        if (! $logged_in) {
            throw new \RuntimeException('No se pudo conectar por SSH: credenciales rechazadas.');
        }
    }

    /**
     * Cierra la sesión SSH al hosting compartido.
     *
     * @return void
     */
    protected function disconnect_hosting_ssh(): void
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
    protected function reconnect_hosting_ssh(): void
    {
        $this->connect_hosting_ssh();
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
    protected function exec_build_ssh(
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
    protected function exec_hosting_ssh(
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
    protected function exec_ssh_session(
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
    protected function open_sftp_session(string $credential_type): SFTP
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
    protected function verify_zip_on_vps(string $remote_zip_path, string $step): int
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

        $max_bytes = (int) config('services.deploy.max_zip_bytes', 1073741824);
        if ($size_bytes > $max_bytes) {
            throw new \RuntimeException(
                "ZIP sospechosamente grande en VPS ({$size_bytes} bytes, máximo {$max_bytes}): {$remote_zip_path}."
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
    protected function sftp_download_file(
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
    protected function sftp_upload_file(
        SFTP $sftp,
        string $local_path,
        string $remote_path,
        string $step
    ): void {
        $this->assert_local_zip_file($local_path, 0, $step);
        $local_size = (int) filesize($local_path);

        $uploaded = $sftp->put($remote_path, $local_path, SFTP::SOURCE_LOCAL_FILE);
        if ($uploaded === false) {
            // Directorio padre del destino: la causa más común de este fallo es que no exista en
            // el hosting (prompt 191/01) — nombrarlo explícitamente ahorra tener que leer el
            // stacktrace completo para darse cuenta.
            $remote_parent_dir = dirname($remote_path);
            throw new \RuntimeException(
                "SFTP put falló al subir {$remote_path}. Causa más común: el directorio destino "
                . "\"{$remote_parent_dir}\" no existe en el hosting."
            );
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
    protected function sftp_remote_file_size(SFTP $sftp, string $remote_path)
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
    protected function assert_local_zip_file(string $local_path, int $expected_bytes, string $step): void
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
     * URL de la API de la tienda para el .env del SPA y las consultas de branding, con /public
     * agregado (hosting compartido). Misma convención que InstallationService::get_api_url_for_env().
     *
     * @return string
     */
    protected function get_ecommerce_api_url_for_env(): string
    {
        $api_url = rtrim((string) $this->ecommerce->api_url, '/');
        if (substr($api_url, -7) !== '/public') {
            $api_url .= '/public';
        }

        return $api_url;
    }

    /**
     * Ruta absoluta del docroot del SPA en el hosting (raíz del dominio del cliente).
     *
     * @return string
     */
    protected function get_spa_docroot(): string
    {
        // Se usa resolve_spa_path() en vez de la columna cruda: deriva la ruta del dominio de la
        // tienda cuando no hay un spa_path cargado a mano (ver App\Models\ClientEcommerce).
        $spa_path = trim((string) $this->ecommerce->resolve_spa_path(), '/');
        if ($spa_path === '') {
            throw new \RuntimeException(
                'La tienda no tiene cargada ni la URL del SPA ni el dominio en el perfil del cliente del admin.'
            );
        }

        return self::HOSTING_PREFIX . $spa_path;
    }

    /**
     * Ruta absoluta de tienda-api en el hosting (subcarpeta /api del dominio del cliente).
     *
     * @return string
     */
    protected function get_api_path(): string
    {
        // Idem get_spa_docroot(): resolve_api_path() deriva del dominio si no hay api_path cargado.
        $api_path = trim((string) $this->ecommerce->resolve_api_path(), '/');
        if ($api_path === '') {
            throw new \RuntimeException(
                'La tienda no tiene cargada ni la URL del SPA ni el dominio en el perfil del cliente del admin.'
            );
        }

        return self::HOSTING_PREFIX . $api_path;
    }

    /**
     * Se asegura de que el directorio de tienda-api exista en el hosting antes de subir el ZIP
     * por SFTP (prompt 191/01). El swap atómico del SPA (`build_spa_atomic_deploy_shell()`) ya
     * preserva esta carpeta cuando existe, pero si una tienda quedó rota por el bug de este mismo
     * prompt (o por cualquier otro motivo el directorio no está creado), el `mkdir -p` la
     * reconstruye en vez de dejar que el SFTP `put` falle contra un path inexistente.
     *
     * Reconecta/reabre la sesión SSH al hosting antes de correr el comando (mismo criterio que el
     * resto de los pasos de este servicio tras operaciones largas o entre etapas).
     *
     * @param  string  $step  Nombre de la etapa del pipeline, para el log en vivo de la corrida.
     * @return void
     */
    protected function ensure_hosting_api_directory(string $step): void
    {
        // Ruta absoluta destino en el hosting (con el prefijo domains/ ya resuelto).
        $api_path = $this->get_api_path();

        $this->log($step, "Asegurando que exista el directorio de tienda-api en el hosting ({$api_path})");
        $this->reconnect_hosting_ssh();
        $this->exec_hosting_ssh($step, 'mkdir -p ' . escapeshellarg($api_path));
    }

    /**
     * Ruta del clone tienda-spa en el VPS de builds.
     *
     * @return string
     */
    protected function builds_spa_path(): string
    {
        return (string) config('services.deploy_tienda.builds_spa_path', '/home/builds/tienda-spa');
    }

    /**
     * Ruta del clone tienda-api en el VPS de builds (se clona la primera vez desde upload_api(),
     * vía ensure_repo_cloned(); prompt 189/01 — ya no se asume clonado de antemano).
     *
     * @return string
     */
    protected function builds_api_path(): string
    {
        return (string) config('services.deploy_tienda.builds_api_path', '/home/builds/tienda-api');
    }

    /**
     * Nombre de la carpeta de salida del build del SPA.
     *
     * @return string
     */
    protected function spa_output_dir_name(): string
    {
        $dir = trim((string) config('services.deploy.spa_output_dir', 'dist'));
        $dir = trim($dir, '/');

        return $dir !== '' ? $dir : 'dist';
    }

    /**
     * Contenido del .env de tienda-spa en el VPS antes de npm run build.
     *
     * SOLO estas 3 variables (a diferencia de empresa-spa): VUE_APP_API_URL, VUE_APP_COMMERCE_ID
     * y VUE_APP_APP_URL. Explícitamente SIN VUE_APP_VARIANT_COLOR, VUE_APP_GOOGLE_* ni
     * VUE_APP_NO_PAUSAR_TIENDA_ONLINE (ver contexto del prompt 584 y limpiezas del grupo 161).
     *
     * @param  string  $api_url
     * @param  string  $spa_url
     * @return string
     */
    protected function build_spa_env_file_content(string $api_url, string $spa_url): string
    {
        $env_vars = [
            'VUE_APP_API_URL'      => $api_url,
            'VUE_APP_COMMERCE_ID'  => (string) $this->client->user_id,
            'VUE_APP_APP_URL'      => $spa_url,
        ];

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
     * Contenido canónico del .htaccess que necesita el docroot de la tienda para que Vue Router
     * en history mode funcione bajo Apache: reescribe a index.html cualquier request que no
     * corresponda a un archivo o directorio real, para que el SPA pueda tomar la ruta interna en
     * vez de que Apache devuelva 404 antes de que el JS llegue a cargar (deep-links y refresh
     * sobre rutas internas). El build de tienda-spa no emite este archivo, por eso lo escribe el
     * pipeline de deploy (ver build_spa_atomic_deploy_shell(), prompt 193/01).
     *
     * @return string
     */
    protected function spa_htaccess_content(): string
    {
        return "<IfModule mod_rewrite.c>\n"
            . "  RewriteEngine On\n"
            . "  RewriteBase /\n"
            . "  RewriteRule ^index\\.html$ - [L]\n"
            . "  RewriteCond %{REQUEST_FILENAME} !-f\n"
            . "  RewriteCond %{REQUEST_FILENAME} !-d\n"
            . "  RewriteRule . /index.html [L]\n"
            . "</IfModule>\n";
    }

    /**
     * Script bash para el despliegue atómico del SPA en la raíz del dominio: descomprime en una
     * carpeta hermana temporal y recién al final hace el mv sobre el docroot, minimizando la
     * ventana de downtime a un par de operaciones mv (en vez de descomprimir en vivo sobre el
     * docroot, que dejaría el dominio sirviendo contenido a medio escribir durante todo el unzip).
     *
     * @param  string  $spa_docroot     Ruta absoluta del docroot en el hosting.
     * @param  string  $temp_zip_path   Ruta absoluta del ZIP ya subido (fuera del docroot).
     * @return string
     */
    protected function build_spa_atomic_deploy_shell(string $spa_docroot, string $temp_zip_path): string
    {
        // Contenido del .htaccess de history mode, codificado en base64 para poder inyectarlo en
        // el shell (armado con escapeshellarg más abajo) sin pelear con comillas, saltos de línea
        // ni el `<IfModule>` dentro del string ya escapado que arma este método. El alfabeto
        // base64 (A-Za-z0-9+/=) es seguro dentro de escapeshellarg (prompt 193/01).
        $htaccess_b64 = base64_encode($this->spa_htaccess_content());

        $staging_dir = $spa_docroot . '__new_' . $this->installation->uuid;
        $old_dir     = $spa_docroot . '__old_' . $this->installation->uuid;

        // ADVERTENCIA para el próximo que toque este método (bug real, 22/7/2026): por la
        // convención de subdominios de Hostinger, tienda-api suele vivir ANIDADA dentro del
        // docroot del SPA (`{dominio}/public_html/api`, ver ClientEcommerce::resolve_api_path()).
        // El swap de abajo reemplaza `public_html` entero por el `dist/` nuevo del SPA y borraba
        // (antes de este fix) todo lo que hubiera en el docroot viejo con un `rm -rf "$OLD"` — eso
        // se llevaba puesta la API instalada del cliente. En una actualización esto es catastrófico:
        // borra `.env`, `storage/` y `vendor/` de una tienda ya funcionando sin poder reconstruirse
        // sola (ver `EcommerceDeploymentService::step_upload_api()`, que sube solo código). Por eso,
        // antes del `rm -rf`, se rescata del docroot viejo cualquier entrada que no venga en el
        // `dist/` nuevo: el subpath de la API (si está anidada) y `.well-known/` (validaciones SSL).
        $preserve_entries = [];

        // Subpath de la API dentro del docroot del SPA (p.ej. "api"), o '' si no está anidada
        // (cliente con API en dominio/carpeta aparte: no hay nada que preservar de más).
        $api_subpath = $this->ecommerce->api_subpath_inside_spa_docroot();
        if ($api_subpath !== '') {
            $preserve_entries[] = $api_subpath;
        }

        // .well-known/ (validaciones de certificado SSL) también cuelga directo del docroot y se
        // pierde con el mismo rm -rf si no se preserva explícitamente.
        $preserve_entries[] = '.well-known';

        // Arma, para cada entrada a preservar, el bloque de shell que la mueve de vuelta desde
        // "$OLD" a "$DOCROOT" solo si existe en el viejo y todavía no existe en el nuevo (si el
        // dist/ del SPA trajera su propia versión de esa ruta, gana la nueva). Cada bloque queda
        // detrás de un `if [ -e ... ]` para que la ausencia de la entrada no aborte el script con
        // `set -e` activo.
        $preserve_shell = '';
        foreach ($preserve_entries as $entry) {
            $entry_escaped = escapeshellarg($entry);
            // mkdir -p del padre por si la entrada tuviera más de un nivel (hoy "api" es un solo
            // nivel, pero no se asume): dirname de un valor de un nivel da ".", mkdir -p "." es no-op.
            $preserve_shell .= 'if [ -e "$OLD/' . $entry . '" ] && [ ! -e "$DOCROOT/' . $entry . '" ]; then '
                . 'mkdir -p "$(dirname "$DOCROOT/' . $entry . '")"; '
                . 'mv "$OLD/' . $entry . '" "$DOCROOT/' . $entry . '"; '
                . 'echo SPA_PRESERVED ' . $entry_escaped . '; '
                . 'fi; ';
        }

        return 'set -e; '
            . 'STAGING=' . escapeshellarg($staging_dir) . '; '
            . 'DOCROOT=' . escapeshellarg($spa_docroot) . '; '
            . 'OLD=' . escapeshellarg($old_dir) . '; '
            . 'ZIP=' . escapeshellarg($temp_zip_path) . '; '
            // Descomprime en la carpeta temporal (todavía no afecta al dominio en vivo).
            . 'rm -rf "$STAGING"; mkdir -p "$STAGING"; '
            . 'unzip -o "$ZIP" -d "$STAGING"; '
            . 'test -f "$STAGING/index.html" || (echo SPA_STAGING_MISSING_INDEX; exit 1); '
            // Escribe el .htaccess de history mode dentro del staging, ANTES del swap, para que
            // llegue atómicamente como parte del docroot nuevo (prompt 193/01). Vue Router en
            // history mode necesita que Apache reescriba a index.html cualquier request que no
            // sea un archivo/directorio real; sin este archivo, cualquier deep-link o refresh
            // sobre una ruta interna de la tienda devuelve 404 aunque el resto del deploy haya
            // salido bien. El build de tienda-spa no emite .htaccess, así que se lo agrega el
            // pipeline acá. Se regenera a propósito en CADA corrida (instalación y actualización):
            // es idempotente y auto-repara cualquier tienda vieja cuyo .htaccess se hubiera
            // perdido en un swap anterior (hoy no hay .htaccess custom por cliente; si alguna vez
            // hiciera falta uno distinto por tienda, es otra feature aparte, no tocar esto para eso).
            . 'printf %s ' . escapeshellarg($htaccess_b64) . ' | base64 -d > "$STAGING/.htaccess"; '
            // Guard: con set -e activo, si por lo que sea el archivo no quedó escrito, cortar la
            // corrida acá en vez de desplegar una tienda que va a dar 404 en cualquier deep-link.
            . 'test -s "$STAGING/.htaccess" || (echo SPA_HTACCESS_MISSING; exit 1); '
            . 'echo SPA_HTACCESS_OK; '
            // Swap atómico: mueve el docroot actual a "old" y el staging a docroot.
            . 'mkdir -p "$(dirname "$DOCROOT")"; '
            . 'if [ -d "$DOCROOT" ]; then mv "$DOCROOT" "$OLD"; fi; '
            . 'mv "$STAGING" "$DOCROOT"; '
            // Rescata del docroot viejo lo que el dist/ nuevo no trae (tienda-api anidada,
            // .well-known/), ANTES de borrar "$OLD" definitivamente.
            . $preserve_shell
            // Limpieza: contenido anterior (ya sin lo preservado) y ZIP temporal.
            . 'rm -rf "$OLD" "$ZIP"; '
            . 'echo SPA_DEPLOY_OK 2>&1';
    }

    /**
     * Verifica que el directorio dist/ exista en el VPS tras el build.
     *
     * @param  string  $spa_build_path
     * @param  string  $spa_output_dir
     * @return void
     */
    protected function assert_spa_dist_directory_on_vps(string $spa_build_path, string $spa_output_dir): void
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
    protected function assert_vps_npm_available(string $spa_build_path, string $npm_bin): void
    {
        $check_cmd = $this->build_vps_command(
            $spa_build_path,
            'echo PATH=$PATH; command -v ' . escapeshellarg($npm_bin) . ' node 2>&1; '
            . escapeshellarg($npm_bin) . ' -v 2>&1'
        );
        $output = $this->exec_build_ssh('compile_spa', $check_cmd, false);
        $this->log('compile_spa', 'Diagnóstico Node/npm: ' . $this->truncate_for_log($output));

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
    protected function build_vps_npm_run_command(string $npm_bin, string $npm_script): string
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
     * Preamble que expone npm/node en SSH no interactivo (mismas variables de config que empresa,
     * ver config/services.php > deploy > *).
     *
     * @return string
     */
    protected function build_vps_node_preamble(): string
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
    protected function wrap_vps_bash_script(string $script): string
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
    protected function build_vps_command(string $work_dir, string $command_after_cd): string
    {
        $script = $this->build_vps_node_preamble()
            . '; cd ' . escapeshellarg($work_dir)
            . ' && ' . $command_after_cd;

        return $this->wrap_vps_bash_script($script);
    }

    /**
     * Arma el comando composer install para un directorio remoto.
     *
     * Siempre --no-scripts: ni en el VPS de builds ni en el hosting del cliente existe todavía un
     * .env al momento de correr composer, y el script post-autoload-dump bootea el framework.
     * El resto de los comandos de artisan corren después, en step_finalize(), con el .env ya escrito.
     *
     * En el hosting compartido (is_vps=false), invoca explícitamente {php_bin} {composer_script}
     * en lugar del binario bare 'composer' de PATH, porque ese wrapper puede no usar la versión de
     * PHP correcta para tienda-api (ver config/services.php > deploy_tienda.php_bin/composer_script).
     *
     * En VPS de builds (is_vps=true) se mantiene el comportamiento original: composer bare de PATH,
     * ya que esa infraestructura no tiene la restricción de CloudLinux que sí existe en hosting compartido.
     *
     * @param  string  $work_dir
     * @param  bool    $is_vps  true en VPS de builds (envuelve el comando); false en hosting compartido
     * @return string
     */
    protected function build_composer_install_command(string $work_dir, bool $is_vps): string
    {
        if ($is_vps) {
            // VPS de builds: usar composer bare del PATH (comportamiento original, sin cambios).
            $composer_bin = trim((string) config('services.deploy.composer_bin', 'composer'));
            $flags        = 'COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MEMORY_LIMIT=-1 '
                . escapeshellarg($composer_bin)
                . ' install --no-dev --optimize-autoloader --no-interaction --no-ansi --no-scripts';

            return $this->build_vps_command($work_dir, $flags);
        }

        // Hosting compartido (is_vps=false): usar php_bin + composer_script configurados.
        // Criterio estándar en hosting con versión específica de PHP (confirmado con Lucas, 22/7/2026).
        $php_bin = escapeshellarg(trim((string) config('services.deploy_tienda.php_bin', '/opt/alt/php84/usr/bin/php')));
        $composer_script = escapeshellarg(trim((string) config('services.deploy_tienda.composer_script', '/usr/local/bin/composer')));
        $flags = 'COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MEMORY_LIMIT=-1 '
            . $php_bin . ' ' . $composer_script
            . ' install --no-dev --optimize-autoloader --no-interaction --no-ansi --no-scripts';

        return 'cd ' . escapeshellarg($work_dir) . ' && ' . $flags . ' 2>&1';
    }

    /**
     * Heurística para detectar fallo cuando getExitStatus() no está disponible.
     *
     * @param  string  $output
     * @return bool
     */
    protected function remote_output_indicates_failure(string $output): bool
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
    protected function spa_npm_build_output_indicates_success(string $output): bool
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
    protected function log_remote_output(string $step, string $output): void
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
    protected function truncate_for_log(string $text, int $max = 500): string
    {
        $text = trim($text);
        if (strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max) . '…';
    }

    /**
     * Persiste una línea de log asociada a la corrida de instalación del ecommerce, vía el helper
     * add_log() del modelo (prompt 583).
     *
     * @param  string  $step
     * @param  string  $line
     * @param  string  $level
     * @return \App\Models\EcommerceDeploymentLog
     */
    protected function log(string $step, string $line, string $level = 'info')
    {
        return $this->installation->add_log($step, $line, $level);
    }
}
