<?php

namespace App\Services;

use App\Models\ClientSshCredential;
use App\Models\Demo;
use App\Models\DemoInstallation;
use App\Models\DemoInstallationLog;
use App\Models\EnvTemplate;
use App\Models\Version;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

/**
 * Instala DESDE CERO el sistema (ERP) de una demo, en el servidor donde esa demo viva.
 *
 * Es el cruce de dos pipelines que ya existían y no se podían usar tal cual:
 *
 *   - InstallationService instala un sistema desde cero, pero resuelve todo contra una `ClientApi`
 *     (su `path`, su `hosting_type`, su blue/green de dos subdominios). Una demo no tiene nada de
 *     eso: sus rutas se derivan del slug de `erp_spa_url` vía DemoPathResolver.
 *   - DemoUpdateService sí sabe hablarle a una demo, pero da por sentado que ya está instalada:
 *     su ZIP de API excluye `public/*` y `storage/*` porque ahí viven los archivos que no se
 *     pisan en una actualización, no repone directorios y no escribe el .env.
 *
 * Pipeline de etapas, en orden:
 *
 *   1. prepare_dirs    — crea los directorios de la API y del SPA y el árbol de storage/
 *   2. upload_public   — sube public/ del tag (el ZIP de la API lo excluye)
 *   3. compile_spa     — checkout + npm ci + npm run build en el VPS de builds
 *   4. upload_spa      — zip de dist/ → SFTP → descompresión en el directorio del SPA
 *   5. upload_api      — zip del código de la API → SFTP → descompresión + composer install
 *   6. write_env       — .env desde EnvTemplate (scope empresa) + los valores manuales del modal
 *   7. finalize_api    — los artisan que composer no corrió (no había .env) + symlink de storage
 *   8. run_demo_setup  — POST /api/admin-sync/demo-setup: es lo que deja la demo CON DATOS
 *   9. verify          — la API y el SPA responden por HTTP
 *
 * 🔴 LA ETAPA 8 ES DESTRUCTIVA Y POR ESO EXISTE SOLO ACÁ. `run_demo_setup` dispara
 * `DemoSetupHelper::run()` en la instancia, que arranca con un `migrate:fresh`: vacía la base de
 * la demo entera y la vuelve a sembrar. En una INSTALACIÓN eso es exactamente lo que se quiere
 * (la base está vacía, la creó Lucas a mano en hPanel). En una ACTUALIZACIÓN sería una pérdida de
 * datos silenciosa, y por eso DemoUpdateService NO la tiene — su
 * `step_run_migrations()` lleva escrito el porqué desde el 14/7/2026. Si alguna vez alguien
 * "unifica" los dos pipelines, esta etapa es la que no se puede compartir.
 *
 * Los helpers de SSH/SFTP y de build están copiados de DemoUpdateService, igual que esa clase los
 * copió de DeploymentService: los cuatro pipelines son autónomos a propósito, sin herencia.
 */
class DemoInstallationService
{
    /**
     * Corrida de instalación en curso.
     *
     * @var DemoInstallation
     */
    private $installation;

    /**
     * Demo objetivo del pipeline.
     *
     * @var Demo
     */
    private $demo;

    /**
     * Versión que se instala.
     *
     * @var Version|null
     */
    private $version;

    /**
     * Resolver de rutas de la demo. Se usan SOLO los métodos del ERP (`api_path()`, `spa_path()`,
     * `credential_type()`, `hosting_type()`): los `ecommerce_*` son de otro pipeline y leen otras
     * columnas — el docblock de esa clase explica por qué no son intercambiables.
     *
     * @var DemoPathResolver
     */
    private $path_resolver;

    /**
     * Credencial SSH del servidor donde vive la demo (shared_hosting o vps, según su
     * `erp_hosting_type`).
     *
     * @var ClientSshCredential
     */
    private $credential;

    /**
     * Sesión SSH activa al servidor de la demo (phpseclib).
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
     * URL de API que quedó efectivamente escrita en el .env con el que se compiló el SPA.
     *
     * CRÍTICO, y es la misma razón que documenta DemoUpdateService: `step_verify()` tiene que
     * verificar contra ESTA cadena y no contra una URL re-resuelta desde `erp_api_url` en el
     * momento de verificar. Si se re-arma la URL, un bug de normalización da verde con la demo
     * rota — que es justamente el incidente del 24/7/2026 que motivó la etapa de verificación.
     *
     * @var string
     */
    private $compiled_api_url = '';

    /**
     * Orden de las etapas del pipeline.
     *
     * @var array<int, string>
     */
    private $steps = [
        'prepare_dirs',
        'upload_public',
        'compile_spa',
        'upload_spa',
        'upload_api',
        'write_env',
        'finalize_api',
        'run_demo_setup',
        'verify',
    ];

    /**
     * Carga la corrida con sus relaciones y resuelve la credencial del servidor de la demo.
     *
     * @param  DemoInstallation  $installation
     * @throws \RuntimeException Si la corrida no tiene demo o versión, o no hay credencial.
     */
    public function __construct(DemoInstallation $installation)
    {
        $this->installation = $installation;
        $this->installation->loadMissing('demo', 'version');

        $this->path_resolver = new DemoPathResolver();

        $this->demo    = $this->installation->demo;
        $this->version = $this->installation->version;

        if (! $this->demo instanceof Demo) {
            throw new \RuntimeException(
                'La instalación no tiene demo asociada: no hay forma de saber a qué servidor subir '
                . 'el código.'
            );
        }

        /* La versión se exige en el constructor y no en la etapa que la usa. Sin tag no hay nada
         * que compilar ni que empaquetar, y fallar recién en compile_spa significaría haber creado
         * ya los directorios y subido public/ a un subdominio para nada. */
        if (! $this->version instanceof Version) {
            throw new \RuntimeException(
                'La instalación no tiene versión asociada: no se sabe qué tag compilar ni empaquetar. '
                . 'Elegí una versión antes de iniciar la instalación.'
            );
        }

        /* Credencial del servidor destino. Sale del hosting de la demo, igual que en
         * DemoUpdateService: una demo en VPS y una en hosting compartido no se instalan en el
         * mismo lado. */
        $this->credential = ClientSshCredential::where('type', $this->demo_credential_type())->firstOrFail();
    }

    /**
     * Orquesta el pipeline completo.
     *
     * Maneja los estados igual que InstallationService::run(): marca `instalando` + `started_at` al
     * empezar, `completada` + `finished_at` al terminar bien, y `fallida` + `finished_at` +
     * `failure_reason` ante cualquier excepción, que después relanza para que el job la registre.
     *
     * @return void
     * @throws \Throwable
     */
    public function run(): void
    {
        try {
            $this->installation->update([
                'status'     => DemoInstallation::STATUS_INSTALANDO,
                'started_at' => now(),
            ]);

            // Primera línea del log: a qué servidor y a qué rutas va esta corrida. Va arriba de
            // todo y no deducible de los `$ cd ...` que aparecen trescientos renglones después.
            $this->log_destino();

            $this->execute_steps();

            $this->installation->update([
                'status'      => DemoInstallation::STATUS_COMPLETADA,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            /* El estado se persiste ANTES de intentar dejar constancia en el log, por la misma
             * razón que lo hace DemoUpdateService desde el 13/7/2026: si la excepción original
             * fuera un fallo de escritura del log, escribir primero el log volvería a tirar y la
             * corrida quedaría en `instalando` para siempre, con el panel haciendo polling contra
             * ese estado y el spinner sin parar nunca. El log es información; el estado es la
             * máquina.
             *
             * Con el log en filas (DemoInstallationLog) ese modo de falla es mucho menos probable
             * que con la columna de texto de demo_updates, pero el orden correcto no cuesta nada. */
            $this->installation->update([
                'status'         => DemoInstallation::STATUS_FALLIDA,
                'finished_at'    => now(),
                'failure_reason' => $e->getMessage(),
            ]);

            try {
                $this->log('installation', $e->getMessage(), 'error');
            } catch (\Throwable $log_error) {
                Log::error('DemoInstallationService: no se pudo escribir el error en el log.', [
                    'demo_installation_id' => $this->installation->id,
                    'error_original'       => $e->getMessage(),
                    'error_al_loguear'     => $log_error->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Ejecuta cada etapa en orden.
     *
     * Es un switch y no un `$this->{'step_' . $step}()` a propósito, igual que en
     * InstallationService: con la llamada dinámica, un nombre de etapa mal escrito en $steps es un
     * BadMethodCallException en runtime; con el switch, la etapa desconocida simplemente no existe
     * y el editor encuentra todos los llamadores.
     *
     * @return void
     */
    private function execute_steps(): void
    {
        foreach ($this->steps as $step) {
            switch ($step) {
                case 'prepare_dirs':
                    $this->step_prepare_dirs();
                    break;
                case 'upload_public':
                    $this->step_upload_public();
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
                case 'finalize_api':
                    $this->step_finalize_api();
                    break;
                case 'run_demo_setup':
                    $this->step_run_demo_setup();
                    break;
                case 'verify':
                    $this->step_verify();
                    break;
            }
        }
    }

    // =========================================================================
    // Etapas del pipeline
    // =========================================================================

    /**
     * Etapa 1: crea los directorios que el resto del pipeline da por existentes.
     *
     * Calcado de InstallationService::step_prepare_dirs(), con las rutas resueltas por
     * DemoPathResolver en vez de la ClientApi.
     *
     * El directorio de la API va PRIMERO: sin él, el `$sftp->put()` del ZIP de public/ de la etapa
     * siguiente devuelve `false` y aborta con "SFTP put falló al subir".
     *
     * El del SPA también tiene que existir aunque quede vacío hasta la etapa 4: el despliegue del
     * SPA hace `cd "$SPA_DIR" || exit 1` ANTES de vaciarlo, así que sobre un directorio inexistente
     * se corta ahí. `mkdir -p` no borra nada de lo que ya haya adentro.
     *
     * @return void
     */
    private function step_prepare_dirs(): void
    {
        $api_path = $this->demo_api_path();
        $spa_path = $this->demo_spa_path();

        $this->log('prepare_dirs', 'Preparando los directorios de la demo...');
        $this->reconnect_hosting_ssh();

        $this->exec_hosting_ssh(
            'prepare_dirs',
            'mkdir -p ' . $this->escape_remote_arg($api_path) . ' 2>&1'
        );

        $this->exec_hosting_ssh(
            'prepare_dirs',
            'mkdir -p ' . $this->escape_remote_arg($spa_path) . ' 2>&1'
        );

        /* Árbol de storage/ + bootstrap/cache.
         *
         * 🔴 Las rutas van ENUMERADAS una por una y NO con brace expansion: la expansión de llaves
         * es de bash y no está garantizada en el `sh` del hosting compartido, donde
         * `mkdir -p storage/{logs,app}` crea un directorio llamado literalmente "{logs,app}".
         *
         * 🔴 El `chmod -R` se limita a storage/framework y bootstrap/cache, que son árboles chicos.
         * Sobre un storage/ con adjuntos acumulados un chmod -R entero timeoutea la sesión SSH. En
         * una instalación de cero el árbol está vacío igual, pero el criterio se mantiene para que
         * un reintento sobre una demo ya usada no se cuelgue.
         *
         * must_succeed = false, igual que en InstallationService: el chmod puede fallar por
         * ownership en el hosting compartido sin que eso invalide la instalación. Quien decide si
         * quedó completa es la verificación de finalize_api. */
        $this->exec_hosting_ssh(
            'prepare_dirs',
            'cd ' . $this->escape_remote_arg($api_path)
            . ' && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions'
            . ' storage/framework/testing storage/framework/views storage/logs bootstrap/cache'
            . ' && chmod -R 775 storage/framework bootstrap/cache'
            . ' && chmod 775 storage storage/app storage/app/public storage/logs 2>&1',
            false
        );

        $this->log('prepare_dirs', 'Directorios de la demo listos', 'success');
    }

    /**
     * Etapa 2: empaqueta `public/` del tag en el VPS de builds y lo descomprime en la demo.
     *
     * Calcado de InstallationService::step_upload_public(). Existe como etapa propia porque el ZIP
     * de la API de la etapa 5 excluye `public/*` — hereda ese exclude de DemoUpdateService, donde
     * es correcto (una actualización no pisa los archivos del cliente). En una instalación de cero
     * nadie más pondría public/, y sin `public/index.php` el subdominio no bootea.
     *
     * Los archivos salen del clone de git del VPS, del mismo tag que se instala: son archivos
     * versionados y el tag es la única fuente de verdad.
     *
     * @return void
     */
    private function step_upload_public(): void
    {
        $this->connect_build_vps();

        $api_build_path = $this->builds_api_path();
        $tag            = $this->version_tag();
        $this->log('upload_public', "Preparando public/ de la versión {$tag} en el VPS de builds");

        $this->exec_build_ssh(
            'upload_public',
            'cd ' . $this->escape_remote_arg($api_build_path) . ' && git fetch --tags 2>&1'
        );
        $checkout_output = $this->exec_build_ssh(
            'upload_public',
            'cd ' . $this->escape_remote_arg($api_build_path)
            . ' && git checkout ' . $this->escape_remote_arg($tag) . ' 2>&1'
        );
        $this->log('upload_public', $this->truncate_for_log($checkout_output));

        $zip_name          = 'public_demo_' . $this->installation->uuid . '.zip';
        $public_zip_remote = $api_build_path . '/' . $zip_name;

        /* Housekeeping de ZIPs huérfanos: si uno viejo quedó en el directorio de builds, `zip -r`
         * lo mete adentro del nuevo y el tamaño crece en bola de nieve hasta romper la descarga
         * SFTP. El filtro por antigüedad evita pisar el paquete de una corrida en paralelo. */
        $this->exec_build_ssh(
            'upload_public',
            'cd ' . $this->escape_remote_arg($api_build_path)
            . " && find . -maxdepth 1 -name 'public_demo_*.zip' -mmin +120 -delete 2>&1"
        );

        /* El ZIP lleva SOLO public/.
         *
         * --exclude='public/storage/*': si alguien alguna vez corrió storage:link en el clone de
         * builds, ese symlink apunta a una ruta del VPS y empaquetarlo dejaría el link roto en la
         * demo. El symlink bueno lo crea step_finalize_api() con `artisan storage:link`. */
        $zip_command = 'cd ' . $this->escape_remote_arg($api_build_path)
            . ' && rm -f ' . $this->escape_remote_arg($zip_name)
            . ' && zip -r ' . $this->escape_remote_arg($zip_name) . ' public'
            . " --exclude='public/storage/*' 2>&1";
        $this->exec_build_ssh('upload_public', $zip_command, true, true);

        $public_zip_bytes = $this->verify_zip_on_vps($public_zip_remote, 'upload_public');
        $this->log('upload_public', "public/ empaquetado ({$public_zip_bytes} bytes en VPS)");

        $local_zip  = $this->local_zip_path('public_' . $this->installation->uuid . '.zip');
        $sftp_build = $this->open_sftp_session('vps');
        $this->sftp_download_file($sftp_build, $public_zip_remote, $local_zip, $public_zip_bytes, 'upload_public');
        $this->log('upload_public', 'ZIP de public/ descargado al servidor de admin');

        $api_path     = $this->demo_api_path();
        $remote_zip   = $api_path . '/' . $zip_name;
        $sftp_hosting = $this->open_sftp_session($this->demo_credential_type());
        $this->sftp_upload_file($sftp_hosting, $local_zip, $remote_zip, 'upload_public');
        $this->log('upload_public', 'ZIP de public/ subido al servidor de la demo');

        $this->reconnect_hosting_ssh();

        /* `unzip -o`, a diferencia del `-n` del esqueleto de un cliente. Acá no hay nada que
         * respetar: es una instalación desde cero sobre un subdominio virgen, y si es un reintento
         * de una corrida que falló, lo correcto es que el tag elegido gane. El esqueleto de un
         * cliente usa -n justamente porque puede correr contra un subdominio que está sirviendo
         * producción; una demo no sirve producción de nadie. */
        $this->exec_hosting_ssh(
            'upload_public',
            'cd ' . $this->escape_remote_arg($api_path)
            . ' && unzip -o ' . $this->escape_remote_arg($zip_name)
            . ' && rm -f ' . $this->escape_remote_arg($zip_name) . ' 2>&1',
            true,
            true
        );
        $this->log('upload_public', 'public/ descomprimido en el servidor de la demo', 'success');

        $this->cleanup_local_zip($local_zip);
        $this->reconnect_build_vps();
        $this->exec_build_ssh(
            'upload_public',
            'rm -f ' . $this->escape_remote_arg($public_zip_remote)
        );
        $this->log('upload_public', 'Archivos temporales eliminados');
    }

    /**
     * Etapa 3: checkout del tag en el VPS de builds y compilación del SPA (npm ci + npm run build).
     *
     * Adaptado de DemoUpdateService::step_compile_spa(): el .env del build sale de
     * build_demo_spa_env_content(), que arma las variables para ESTA demo. Es el mismo contenido
     * que compila una actualización, así que una demo recién instalada y una actualizada quedan con
     * el mismo bundle.
     *
     * @return void
     */
    private function step_compile_spa(): void
    {
        $this->connect_build_vps();
        $this->log('compile_spa', 'Conectado al VPS de builds');

        $spa_build_path = $this->builds_spa_path();
        $tag            = $this->version_tag();

        $this->exec_build_ssh(
            'compile_spa',
            'cd ' . $this->escape_remote_arg($spa_build_path) . ' && git fetch --tags 2>&1'
        );
        $checkout_output = $this->exec_build_ssh(
            'compile_spa',
            'cd ' . $this->escape_remote_arg($spa_build_path)
            . ' && git checkout ' . $this->escape_remote_arg($tag) . ' 2>&1'
        );
        $this->log('compile_spa', "Checkout {$tag}: " . $this->truncate_for_log($checkout_output));

        // .env del build, apuntando a esta demo.
        $api_url_para_log = $this->demo_api_base_url();
        $env_content      = $this->build_demo_spa_env_content();
        $env_escaped      = str_replace("'", "'\\''", $env_content);
        $env_file         = $spa_build_path . '/.env';
        $this->exec_build_ssh(
            'compile_spa',
            "printf '%s' '{$env_escaped}' > " . $this->escape_remote_arg($env_file)
        );
        $this->log(
            'compile_spa',
            '.env configurado — API: ' . $api_url_para_log . ' | SPA: ' . $this->demo->erp_spa_url
        );

        $npm_bin = trim((string) config('services.deploy.npm_bin', 'npm'));
        $this->assert_vps_npm_available($spa_build_path, $npm_bin);

        $this->log('compile_spa', 'Instalando dependencias (npm ci)...');
        $this->exec_build_ssh(
            'compile_spa',
            $this->build_vps_command(
                $spa_build_path,
                $this->escape_remote_arg($npm_bin) . ' ci --no-audit --no-fund 2>&1'
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
                'npm run build no finalizó correctamente. ' . $this->truncate_for_log($build_output, 800)
            );
        }
        $this->log('compile_spa', 'Build completado exitosamente', 'success');

        // Reconecta tras el build: phpseclib puede dejar el canal cerrado.
        $this->reconnect_build_vps();
        $this->log('compile_spa', 'Reconectado al VPS tras el build');

        $this->assert_spa_dist_on_vps($spa_build_path);
    }

    /**
     * Etapa 4: empaqueta dist/ en el VPS, lo baja al admin y lo descomprime en el SPA de la demo.
     *
     * Copiado de DemoUpdateService::step_upload_spa().
     *
     * @return void
     */
    private function step_upload_spa(): void
    {
        $this->connect_build_vps();

        $spa_build_path = $this->builds_spa_path();
        $spa_output_dir = $this->spa_output_dir_name();

        $spa_zip_remote = $spa_build_path . '/dist.zip';
        $dist_dir       = $spa_build_path . '/' . $spa_output_dir;
        $this->exec_build_ssh(
            'upload_spa',
            'cd ' . $this->escape_remote_arg($dist_dir)
            . ' && rm -f ../dist.zip && zip -r ../dist.zip . 2>&1',
            true,
            true
        );
        $spa_zip_bytes = $this->verify_zip_on_vps($spa_zip_remote, 'upload_spa');
        $this->log('upload_spa', "dist/ comprimido ({$spa_zip_bytes} bytes en VPS)");

        $local_zip  = $this->local_zip_path('dist_' . $this->installation->uuid . '.zip');
        $sftp_build = $this->open_sftp_session('vps');
        $this->sftp_download_file($sftp_build, $spa_zip_remote, $local_zip, $spa_zip_bytes, 'upload_spa');
        $this->log('upload_spa', 'ZIP descargado al servidor de admin');

        /* Path del SPA de la demo: relativo al home SSH en hosting compartido, absoluto en el VPS.
         * Lo resuelve DemoPathResolver, que además tira si la ruta fuera a quedar con un segmento
         * vacío — abajo hay un `find . -mindepth 1 -delete`. */
        $hosting_spa_dir    = $this->demo_spa_path();
        $hosting_zip_remote = $hosting_spa_dir . '/dist.zip';

        $sftp_hosting = $this->open_sftp_session($this->demo_credential_type());
        $this->sftp_upload_file($sftp_hosting, $local_zip, $hosting_zip_remote, 'upload_spa');
        $this->log('upload_spa', 'ZIP subido al servidor de la demo');

        $this->connect_hosting_ssh();
        $this->exec_hosting_ssh(
            'upload_spa',
            $this->build_spa_hosting_deploy_shell($hosting_spa_dir)
        );
        $this->log('upload_spa', 'SPA desplegado (contenido anterior reemplazado)', 'success');

        $this->cleanup_local_zip($local_zip);

        $this->reconnect_build_vps();
        $this->exec_build_ssh(
            'upload_spa',
            'rm -f ' . $this->escape_remote_arg($spa_build_path . '/dist.zip')
        );
    }

    /**
     * Etapa 5: empaqueta el código de la API en el VPS, lo sube a la demo y corre composer install.
     *
     * Adaptado de DemoUpdateService::step_upload_api(), con la lista de exclusiones de
     * InstallationService::step_upload_api(), que es más completa:
     *
     *   .env             — lo escribe la etapa 6
     *   vendor/          — se instala con composer en el servidor de la demo
     *   public/*         — lo puso la etapa 2 (el ZIP del tag, sin el symlink de storage)
     *   storage/*        — el árbol lo creó la etapa 1
     *   bootstrap/cache/ — para no arrastrar config/rutas cacheadas del VPS de builds
     *   .git/            — la demo no necesita el historial del repo
     *   *.zip            — 🔴 si un ZIP huérfano quedó en el directorio de builds, `zip -r` lo mete
     *                      adentro del nuevo y el tamaño crece en bola de nieve hasta romper la
     *                      descarga SFTP
     *   tests/ y los datasets de seeders — no los necesita ni un cliente ni una demo
     *
     * 🔴 `composer install` corre SIEMPRE con `--no-scripts`, en el VPS y en la demo: en este punto
     * del pipeline todavía no hay .env (lo escribe la etapa 6), y el `post-autoload-dump` de Laravel
     * ejecuta `artisan package:discover`, que bootea el framework y revienta sin entorno. Los
     * artisan se corren en finalize_api, ya con el .env puesto.
     *
     * @return void
     */
    private function step_upload_api(): void
    {
        $this->connect_build_vps();

        $api_build_path = $this->builds_api_path();
        $tag            = $this->version_tag();
        $this->log('upload_api', "Preparando versión {$tag} en el VPS de builds");

        $this->exec_build_ssh(
            'upload_api',
            'cd ' . $this->escape_remote_arg($api_build_path) . ' && git fetch --tags 2>&1'
        );
        $checkout_output = $this->exec_build_ssh(
            'upload_api',
            'cd ' . $this->escape_remote_arg($api_build_path)
            . ' && git checkout ' . $this->escape_remote_arg($tag) . ' 2>&1'
        );
        $this->log('upload_api', $this->truncate_for_log($checkout_output));

        $this->log('upload_api', 'Corriendo composer install en el VPS (--no-scripts)...');
        $this->exec_build_ssh(
            'upload_api',
            $this->build_composer_install_command($api_build_path, true)
        );
        $this->log('upload_api', 'composer install en el VPS completado', 'success');

        $zip_name       = 'api_demo_' . $this->installation->uuid . '.zip';
        $api_zip_remote = $api_build_path . '/' . $zip_name;
        $this->reconnect_build_vps();

        $this->exec_build_ssh(
            'upload_api',
            'cd ' . $this->escape_remote_arg($api_build_path)
            . " && find . -maxdepth 1 -name 'api_demo_*.zip' -mmin +120 -delete 2>&1"
        );

        $zip_command = 'cd ' . $this->escape_remote_arg($api_build_path)
            . ' && rm -f ' . $this->escape_remote_arg($zip_name)
            . ' && zip -r ' . $this->escape_remote_arg($zip_name) . ' . '
            . "--exclude='.env' --exclude='vendor/*' --exclude='public/*' --exclude='storage/*'"
            . " --exclude='bootstrap/cache/*' --exclude='.git/*' --exclude='*.zip'"
            . " --exclude='tests/*' --exclude='database/super-budgets/*'"
            . " --exclude='database/seeders/articles/*' --exclude='database/seeders/truvari/*'"
            . " --exclude='database/seeders/subcategories/*' --exclude='database/seeders/sales/*'"
            . ' 2>&1';
        $this->exec_build_ssh('upload_api', $zip_command, true, true);

        $api_zip_bytes = $this->verify_zip_on_vps($api_zip_remote, 'upload_api');
        $this->log('upload_api', "API empaquetada ({$api_zip_bytes} bytes en VPS)");

        $local_zip  = $this->local_zip_path('api_' . $this->installation->uuid . '.zip');
        $sftp_build = $this->open_sftp_session('vps');
        $this->sftp_download_file($sftp_build, $api_zip_remote, $local_zip, $api_zip_bytes, 'upload_api');
        $this->log('upload_api', 'ZIP descargado al servidor de admin');

        $api_path   = $this->demo_api_path();
        $remote_zip = $api_path . '/' . $zip_name;

        $sftp_hosting = $this->open_sftp_session($this->demo_credential_type());
        $this->sftp_upload_file($sftp_hosting, $local_zip, $remote_zip, 'upload_api');
        $this->log('upload_api', 'ZIP subido al servidor de la demo');

        $this->connect_hosting_ssh();
        $this->exec_hosting_ssh(
            'upload_api',
            'cd ' . $this->escape_remote_arg($api_path)
            . ' && unzip -o ' . $this->escape_remote_arg($zip_name)
            . ' && rm ' . $this->escape_remote_arg($zip_name),
            true,
            true
        );
        $this->log('upload_api', 'API descomprimida en el servidor de la demo');

        $this->log('upload_api', 'Corriendo composer install en la demo (--no-scripts; el .env todavía no existe)...');
        $this->reconnect_hosting_ssh();
        $this->exec_hosting_ssh(
            'upload_api',
            $this->build_composer_install_command($api_path, false),
            true,
            true
        );
        $this->log('upload_api', 'API lista en el servidor de la demo', 'success');

        $this->cleanup_local_zip($local_zip);
        $this->reconnect_build_vps();
        $this->exec_build_ssh(
            'upload_api',
            'rm -f ' . $this->escape_remote_arg($api_zip_remote)
        );
        $this->log('upload_api', 'Archivos temporales eliminados');
    }

    /**
     * Etapa 6: escribe el .env de la API de la demo.
     *
     * Calcado de InstallationService::step_write_env(). Las claves las arma
     * build_env_vars_to_write(), que es público y no tiene nada de SSH adentro justamente para que
     * se pueda probar sin un servidor del otro lado.
     *
     * @return void
     */
    private function step_write_env(): void
    {
        $this->log('write_env', 'Generando el .env de la demo...');

        $vars_to_write = $this->build_env_vars_to_write();

        $this->log(
            'write_env',
            'Variables a escribir: ' . count($vars_to_write)
            . ' (' . implode(', ', array_keys($vars_to_write)) . ')'
        );

        $api_path        = $this->demo_api_path();
        $credential_type = $this->demo_credential_type();

        /* 🔴 El `touch` del .env va por la sesión SSH de ESTA clase y no por EnvSshService, que no
         * tiene una variante de ensure_env_file por path (las suyas piden una ClientApi, que una
         * demo no tiene). Que caigan en el mismo servidor no es casualidad ni hay que confiar en
         * ella: las dos sesiones resuelven su credencial desde el MISMO
         * DemoPathResolver::credential_type($demo) —esta clase en el constructor, EnvSshService en
         * el connect_to() de abajo—, así que por construcción es la misma fila de
         * client_ssh_credentials y el mismo host. El bug del 22/8/2026 fue exactamente lo
         * contrario: el touch en un servidor y la escritura en otro, y el archivo no aparecía nunca
         * donde se lo iba a buscar. */
        $this->reconnect_hosting_ssh();
        $this->exec_hosting_ssh(
            'write_env',
            'cd ' . $this->escape_remote_arg($api_path) . ' && touch .env 2>&1'
        );

        /* app() y no `new`: sin binding registrado el resultado es idéntico (el container instancia
         * la misma clase), pero habilita que un test lo reemplace con $this->app->instance(), como
         * ya hace EnvMasivoDeClientesTest. */
        $env_ssh_service = app(EnvSshService::class);
        $env_ssh_service->connect_to($credential_type);
        $env_ssh_service->write_env_vars($api_path, $vars_to_write);

        $this->log('write_env', '.env escrito en el servidor de la demo', 'success');
    }

    /**
     * Arma el mapa KEY => valor que se va a escribir en el .env de la demo.
     *
     * Combina, en este orden de prioridad:
     *
     *   a) TODAS las variables de `env_templates` con scope='empresa' y su valor de plantilla.
     *      Se filtra por scope porque esa tabla también tiene la plantilla de tienda-api
     *      (scope='tienda'), que no se puede mezclar acá.
     *   b) Las variables `is_manual_on_create` cuyo valor cargó el operador en el modal
     *      (`env_manual_values`): son las credenciales de la base que Lucas creó a mano en hPanel.
     *   c) APP_URL = `demos.erp_api_url` CRUDA, sin `/public`. La regla del `/public` es de
     *      VUE_APP_API_URL (lo que el navegador le pide a la API), no de APP_URL (lo que Laravel
     *      cree que es su propia base). Es el mismo criterio que InstallationService, que escribe
     *      `$this->target_api->url` sin normalizar.
     *   d) SANCTUM_STATEFUL_DOMAINS = el HOST de `erp_spa_url` (sin esquema) y SANCTUM_STATEFUL_CORS
     *      = la URL completa del SPA. Sin esto, la demo devuelve 419 en cada request con sesión.
     *   e) USER_ID = `demos.user_id`. 🔴 Es el mismo número que el "ID de comercio" del catálogo:
     *      es el id con el que DemoSetupHelper crea el User (`'id' => config('app.USER_ID')`), así
     *      que si no coincide, el demo-setup de la etapa 8 siembra los datos colgando de un usuario
     *      y la tienda le pide su configuración a otro.
     *
     * ⚠️ NO se filtra por `is_common`. Ese flag significa "se contrasta con los clientes al
     * actualizar" (ver EnvTemplate), no "se escribe en el .env": filtrar por él deja afuera el
     * grupo `app` entero (APP_NAME, APP_ENV, APP_KEY, APP_DEBUG) y genera un .env inservible. Es
     * el mismo error que ya está documentado en InstallationService::step_write_env().
     *
     * 🔴 Es público a propósito, y sin una sola llamada a SSH adentro: es el ÚNICO punto donde se
     * puede verificar qué claves se escriben sin un servidor del otro lado. Si se vuelve privado,
     * el test que fija APP_URL / USER_ID / SANCTUM_* desaparece con él.
     *
     * @return array<string, string>
     */
    public function build_env_vars_to_write(): array
    {
        // a) Plantilla base completa de empresa-api.
        $base_templates = EnvTemplate::where('scope', 'empresa')
            ->orderBy('group')
            ->orderBy('sort_order')
            ->get();

        $vars_to_write = [];
        foreach ($base_templates as $template) {
            $vars_to_write[$template->key] = (string) ($template->value === null ? '' : $template->value);
        }

        // b) Valores manuales cargados por el operador.
        $env_manual_values = $this->installation->env_manual_values;
        if (! is_array($env_manual_values)) {
            $env_manual_values = [];
        }

        $manual_templates = EnvTemplate::where('scope', 'empresa')
            ->where('is_manual_on_create', true)
            ->get()
            ->keyBy('key');

        foreach ($manual_templates as $key => $template) {
            if (isset($env_manual_values[$key]) && $env_manual_values[$key] !== '') {
                $vars_to_write[$key] = (string) $env_manual_values[$key];
            }
        }

        // c) APP_URL: la URL de la API de la demo, cruda.
        $app_url = rtrim(trim((string) $this->demo->erp_api_url), '/');
        if ($app_url !== '') {
            $vars_to_write['APP_URL'] = $app_url;
        }

        // d) Sanctum, derivado del SPA.
        //
        // Se normaliza la URL antes de parsearla: `erp_spa_url` es texto libre del modal de Demos y
        // una URL sin esquema hace que parse_url() devuelva null como host (medido con PHP 7.4.33,
        // ver DemoPathResolver::slug_from_url()), o sea SANCTUM_STATEFUL_DOMAINS vacío y 419 en
        // todo sin que nada avise.
        $spa_url = DemoUrlNormalizer::base($this->demo->erp_spa_url);
        if ($spa_url !== '') {
            $spa_host = parse_url($spa_url, PHP_URL_HOST);
            if (is_string($spa_host) && $spa_host !== '') {
                $vars_to_write['SANCTUM_STATEFUL_DOMAINS'] = $spa_host;
            }
            $vars_to_write['SANCTUM_STATEFUL_CORS'] = $spa_url;
        }

        // e) USER_ID: el id del User dueño de los datos de esta demo.
        $user_id = $this->demo->user_id;
        if ($user_id !== null && (int) $user_id > 0) {
            $vars_to_write['USER_ID'] = (string) (int) $user_id;
        }

        return $vars_to_write;
    }

    /**
     * Etapa 7: corre en la demo los artisan que composer no ejecutó por falta de .env.
     *
     * Calcado de InstallationService::step_finalize_api(), sin el bloque de certificados de AFIP:
     * ese lo repone el pipeline de actualización de demos
     * (DemoUpdateService::provision_afip_certificates()) y meterlo también acá duplicaría la
     * política en dos lugares que después divergen. Queda anotado en el informe de la misión.
     *
     * @return void
     */
    private function step_finalize_api(): void
    {
        $api_path = $this->demo_api_path();

        $this->log('finalize_api', 'Asegurando directorios de storage...');
        $this->reconnect_hosting_ssh();

        /* El árbol ya lo creó step_prepare_dirs(), pero se reasegura acá porque el `unzip -o` de la
         * etapa 5 puede haber traído entradas de directorio del ZIP con otros permisos, y
         * `view:clear` / `cache:clear` fallan con "path not found" si falta cualquiera de estos.
         * Mismas rutas enumeradas y mismo chmod acotado que en prepare_dirs — el porqué está ahí. */
        $this->exec_hosting_ssh(
            'finalize_api',
            'cd ' . $this->escape_remote_arg($api_path)
            . ' && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions'
            . ' storage/framework/testing storage/framework/views storage/logs bootstrap/cache'
            . ' && chmod -R 775 storage/framework bootstrap/cache'
            . ' && chmod 775 storage storage/app storage/app/public storage/logs 2>&1',
            false
        );

        // rm por shell y no por artisan: un config.php cacheado inválido rompería cualquier comando.
        $this->exec_hosting_ssh(
            'finalize_api',
            'cd ' . $this->escape_remote_arg($api_path)
            . ' && rm -f bootstrap/cache/config.php bootstrap/cache/routes-*.php'
            . ' bootstrap/cache/packages.php bootstrap/cache/services.php 2>&1',
            false
        );

        // Regenera el manifest de paquetes: es lo que composer habría hecho en post-autoload-dump.
        $this->log('finalize_api', 'Ejecutando artisan package:discover...');
        $discover_output = $this->exec_hosting_ssh(
            'finalize_api',
            'cd ' . $this->escape_remote_arg($api_path) . ' && php artisan package:discover --no-ansi 2>&1',
            true,
            true
        );
        $this->log('finalize_api', $this->truncate_for_log($discover_output));
        $this->log('finalize_api', 'Paquetes descubiertos correctamente', 'success');

        /* Symlink public/storage -> storage/app/public.
         *
         * must_succeed = false: si ya existe, artisan devuelve error y eso no invalida la
         * instalación. Quien decide es verify_installation(). */
        $this->log('finalize_api', 'Creando el symlink de storage...');
        $storage_link_output = $this->exec_hosting_ssh(
            'finalize_api',
            'cd ' . $this->escape_remote_arg($api_path) . ' && php artisan storage:link --no-ansi 2>&1',
            false
        );
        $this->log('finalize_api', $this->truncate_for_log($storage_link_output));

        // Limpieza final de cachés.
        $clear_commands = ['config:clear', 'cache:clear', 'view:clear', 'route:clear'];
        foreach ($clear_commands as $clear_command) {
            $this->exec_hosting_ssh(
                'finalize_api',
                'cd ' . $this->escape_remote_arg($api_path) . ' && php artisan ' . $clear_command . ' --no-ansi 2>&1',
                false
            );
        }

        $this->verify_installation();

        $this->log('finalize_api', 'API finalizada y lista para bootear', 'success');
    }

    /**
     * Rutas, relativas a api_path, que tienen que existir para dar la instalación por completa.
     *
     * Cada una es algo que los ZIP de ACTUALIZACIÓN excluyen a propósito (`.env`, `vendor/*`,
     * `storage/*`, `public/*`) y que ninguna actualización posterior repone por su cuenta: lo que
     * falte después de esta etapa no lo pone nadie.
     *
     * ⚠️ Los certificados de AFIP NO están en esta lista, a diferencia de
     * InstallationService::required_installation_paths(). No es un olvido: en el camino de las demos
     * los repone el pipeline de actualización, en cada corrida
     * (DemoUpdateService::provision_afip_certificates(), 28/8/2026), y esa política dice
     * explícitamente que un certificado faltante NUNCA aborta el pipeline de una demo — porque un
     * corte deja la demo rota delante de un lead. Exigirlos acá contradiría esa decisión.
     *
     * @return array<int, string>
     */
    private function required_installation_paths(): array
    {
        return [
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
    }

    /**
     * Verifica que la instalación quedó completa en el servidor antes de darla por buena.
     *
     * Un único comando SSH que chequea con `[ -e ... ]` cada ruta requerida y termina SIEMPRE con
     * `echo VERIFY_DONE`. El marcador no es adorno: es lo único que distingue "la verificación
     * corrió y no encontró faltantes" de "la sesión SSH se cortó y no llegó a correr", que sin él
     * son el mismo string vacío.
     *
     * `for ... in ...; do ... done` y `[ -e ... ]` son POSIX puro: sin brace expansion ni nada
     * específico de bash, para no depender del shell que tenga el hosting compartido.
     *
     * @return void
     * @throws \RuntimeException
     */
    private function verify_installation(): void
    {
        $api_path = $this->demo_api_path();

        $escaped_paths = [];
        foreach ($this->required_installation_paths() as $required_path) {
            $escaped_paths[] = $this->escape_remote_arg($required_path);
        }

        $command = 'cd ' . $this->escape_remote_arg($api_path)
            . ' && for P in ' . implode(' ', $escaped_paths) . '; do [ -e "$P" ] || echo "FALTA $P"; done'
            . ' && echo VERIFY_DONE';

        $this->log('finalize_api', 'Verificando integridad de la instalación...');

        // must_succeed = false: quien decide acá es la salida, no el exit code del comando remoto.
        $output = $this->exec_hosting_ssh('finalize_api', $command, false);

        if (strpos($output, 'VERIFY_DONE') === false) {
            $this->log(
                'finalize_api',
                'No se pudo completar la verificación de integridad (sesión SSH interrumpida).',
                'error'
            );

            throw new \RuntimeException(
                'La instalación no pudo verificarse: la sesión SSH se interrumpió antes de terminar '
                . 'la comprobación. Revisala a mano antes de usar la demo.'
            );
        }

        $missing_paths = [];
        foreach (preg_split('/\r\n|\r|\n/', $output) as $output_line) {
            if (strpos($output_line, 'FALTA ') === 0) {
                $missing_paths[] = trim(substr($output_line, strlen('FALTA ')));
            }
        }

        if (! empty($missing_paths)) {
            $missing_list = implode(', ', $missing_paths);
            $this->log('finalize_api', 'La instalación quedó incompleta. Faltan: ' . $missing_list, 'error');

            throw new \RuntimeException(
                'La instalación de la demo quedó incompleta: faltan ' . $missing_list . '. '
                . 'Revisá el log de las etapas anteriores.'
            );
        }

        $this->log('finalize_api', 'Integridad verificada: están todas las rutas requeridas', 'success');
    }

    /**
     * Etapa 8: dispara el demo-setup en la instancia recién instalada, con los defaults del catálogo.
     *
     * ╔══════════════════════════════════════════════════════════════════════════════════════════╗
     * ║ 🔴 ESTA ETAPA ES DESTRUCTIVA. VACÍA LA BASE DE LA DEMO.                                   ║
     * ║                                                                                          ║
     * ║ Del otro lado, `DemoSetupHelper::run()` arranca con un `migrate:fresh`: DROPEA todas las  ║
     * ║ tablas y las vuelve a crear, y recién después corre los 52 seeders. En una INSTALACIÓN    ║
     * ║ eso es exactamente lo que se quiere —la base está vacía, la creó Lucas a mano en hPanel—  ║
     * ║ y es lo único que deja la demo con datos para mostrarle a un lead.                        ║
     * ║                                                                                          ║
     * ║ En una ACTUALIZACIÓN sería una pérdida de datos silenciosa, y por eso NO existe allá:     ║
     * ║ DemoUpdateService tiene `step_run_migrations()` en su lugar, con `migrate --force`, que   ║
     * ║ es incremental. Esa decisión es del 14/7/2026 y está escrita en el docblock de ese        ║
     * ║ método. Si algún día se "unifican" los dos pipelines, ESTA es la etapa que no se puede    ║
     * ║ compartir.                                                                               ║
     * ╚══════════════════════════════════════════════════════════════════════════════════════════╝
     *
     * 🔴 UN SOLO INTENTO, SIN `->retry()`. No es una omisión y no se repone:
     *
     *   - En Laravel 8, con `tries > 1` una respuesta NO exitosa se relanza
     *     (`PendingRequest::send`), o sea que el reintento no cubre sólo errores de red: un 500 del
     *     armado le re-dispara a la instancia el `migrate:fresh` ENTERO medio segundo después,
     *     encima de la corrida que puede seguir viva.
     *   - Reintentar un timeout del servidor da el mismo timeout: duplica la espera con cero
     *     probabilidad de éxito.
     *   - Con `tries = 1` el cliente HTTP deja de llamar solo a `throw()`, así que la respuesta no
     *     exitosa vuelve por el camino normal y se puede distinguir el 409 del 500.
     *
     * Es el mismo razonamiento —y el mismo incidente— que ya está escrito en
     * RunDemoSetupService::run(). Ahí está la versión larga.
     *
     * El timeout es propio y alto (900 s por default): una corrida sola del setup se midió en 565,7
     * s el 25/8/2026 contra una base con datos, y en ~109 s contra una base virgen como la de una
     * instalación. Con los 300 s genéricos, el camino normal terminaba SIEMPRE en timeout mientras
     * la instancia seguía sembrando tan campante.
     *
     * @return void
     * @throws \RuntimeException Si la instancia no acepta el setup.
     */
    private function step_run_demo_setup(): void
    {
        $api_base_url = $this->demo_api_base_url();

        // Demos locales (las que arma el seeder, ej. empresa.local:8000) no son hosting real: se
        // saltea el POST y se deja constancia, igual que hace la verificación por HTTP. Mismo
        // criterio y misma señal: "la URL no es absoluta".
        if (! $this->es_url_absoluta($api_base_url)) {
            $this->log(
                'run_demo_setup',
                'Se saltea el demo-setup: "' . $api_base_url . '" no es una URL absoluta (demo local).'
            );

            return;
        }

        $url     = rtrim($api_base_url, '/') . '/api/admin-sync/demo-setup';
        $payload = (new RunDemoSetupService())->payload_de_defaults($this->demo);

        $this->log(
            'run_demo_setup',
            'Disparando el demo-setup (migrate:fresh + seeders) contra ' . $url
            . '. Es destructivo y puede tardar varios minutos.'
        );

        try {
            $response = Http::withHeaders(['Accept' => 'application/json'])
                ->timeout((int) config('services.client_api.demo_setup_timeout', 900))
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            /* 🔴 Un timeout NO es "el setup falló": del otro lado el endpoint corre con
             * `ignore_user_abort(true)` y `set_time_limit(0)`, así que dejar de escuchar no detiene
             * nada — la corrida sigue viva sembrando la base y puede terminar bien. Pero tampoco se
             * puede seguir a la etapa de verificación como si nada, porque la demo todavía no tiene
             * datos y el resultado sería un verde mentiroso.
             *
             * Se marca la instalación como fallida con un mensaje que dice exactamente qué pasó y
             * qué hacer: NO volver a disparar el setup mientras la corrida pueda seguir viva. */
            throw new \RuntimeException(
                'Sin respuesta del demo-setup (timeout o conexión caída) contra ' . $url . '. '
                . 'OJO: la corrida puede seguir viva en la instancia sembrando la base — NO vuelvas '
                . 'a dispararla hasta confirmar que terminó, porque un segundo demo-setup le hace '
                . 'otro migrate:fresh encima. Detalle: ' . $e->getMessage()
            );
        }

        if ($response->successful()) {
            $this->log('run_demo_setup', 'Demo-setup completado: la demo ya tiene datos.', 'success');

            return;
        }

        /* 409 = la instancia ya tiene un demo-setup corriendo y NO tocó la base para decírnoslo
         * (empresa-api toma un candado con flock antes de llamar al helper). Es distinto de un
         * fallo del armado y merece su propio mensaje. */
        if ($response->status() === 409) {
            throw new \RuntimeException(
                'La instancia ya tenía un demo-setup corriendo (HTTP 409), así que este no se '
                . 'disparó y la base quedó intacta. Esperá a que termine el que está en curso y '
                . 'volvé a instalar.'
            );
        }

        throw new \RuntimeException(
            'El demo-setup respondió HTTP ' . $response->status() . ': '
            . substr((string) $response->body(), 0, 2000)
        );
    }

    /**
     * Etapa 9: verifica por HTTP que la demo realmente responda.
     *
     * Es el mismo criterio de DemoUpdateService::step_verify_demo(), y existe por el mismo
     * incidente (24/7/2026): el pipeline compiló, subió y migró, quedó en verde, y la demo devolvía
     * 404 en cada request porque la URL compilada en el bundle tenía `/public/public`. Ninguna de
     * las etapas anteriores prueba eso: son todas validaciones de proceso (build sin errores, ZIP
     * válido, artisan con exit 0), no de que el SPA compilado pueda hablarle a la API.
     *
     * Se verifica contra `compiled_api_url` —la cadena EXACTA que se escribió en el .env del
     * build— y no contra una URL re-resuelta: si se re-armara, un bug de normalización daría verde
     * con la demo rota, que es justamente lo que pasó.
     *
     * @return void
     * @throws \RuntimeException
     */
    private function step_verify(): void
    {
        // No debería pasar nunca: build_demo_spa_env_content() la setea en compile_spa. Si llegara
        // vacía, algo cambió de orden — mejor fallar explícito que verificar una URL adivinada.
        if ($this->compiled_api_url === '') {
            throw new \RuntimeException(
                '[verify] No se pudo determinar la URL con la que se compiló el SPA '
                . '(compiled_api_url vacía).'
            );
        }

        // Demos locales (seeder): no es hosting real, no tiene sentido pegarle por HTTP.
        if (! $this->es_url_absoluta($this->compiled_api_url)) {
            $this->log(
                'verify',
                'Se saltea la verificación: "' . $this->compiled_api_url . '" no es una URL '
                . 'absoluta (demo local).'
            );

            return;
        }

        $api_check_url = rtrim($this->compiled_api_url, '/') . '/sanctum/csrf-cookie';
        $this->verify_url_responds('verify', $api_check_url, 'API');

        /* SPA normalizado y no crudo: acá ya se sabe que la demo es de hosting real —lo decidió el
         * guard de arriba mirando compiled_api_url— pero eso no dice nada de `erp_spa_url`, que es
         * otra columna de texto libre. Una demo con la API absoluta y el SPA sin esquema hacía
         * fallar este GET y marcaba fallida una corrida que había salido bien (17/8/2026). */
        $spa_check_url = DemoUrlNormalizer::base($this->demo->erp_spa_url);
        $this->verify_url_responds('verify', $spa_check_url, 'SPA');
    }

    /**
     * Pega un GET con reintentos y considera exitoso cualquier 2xx.
     *
     * Los reintentos son de la VERIFICACIÓN, no del pipeline: un 404 o un timeout transitorio en el
     * primer intento (el hosting recalentando el opcache justo después del deploy) no puede tirar
     * abajo una instalación que por lo demás salió bien. Nada de lo que se hace acá es destructivo,
     * así que reintentar es gratis — a diferencia de la etapa 8.
     *
     * @param  string  $step
     * @param  string  $url
     * @param  string  $label  "API" | "SPA"
     * @return void
     * @throws \RuntimeException Si ningún intento devuelve 2xx.
     */
    private function verify_url_responds(string $step, string $url, string $label): void
    {
        $max_attempts = 3;
        $wait_seconds = 5;

        $last_status = null;
        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            try {
                $response    = Http::timeout(20)->get($url);
                $last_status = $response->status();

                if ($response->successful()) {
                    $this->log(
                        $step,
                        "{$label} OK (intento {$attempt}/{$max_attempts}, status {$last_status}) — {$url}",
                        'success'
                    );

                    return;
                }
            } catch (\Throwable $e) {
                // Timeout, DNS, conexión rechazada: se trata igual que un status no-2xx.
                $last_status = null;
                $this->log(
                    $step,
                    "{$label} sin respuesta (intento {$attempt}/{$max_attempts}) — {$url} — " . $e->getMessage()
                );
            }

            if ($attempt < $max_attempts) {
                sleep($wait_seconds);
            }
        }

        $status_text = $last_status === null ? 'sin respuesta' : (string) $last_status;
        $this->log($step, "{$label} FALLÓ tras {$max_attempts} intentos (status {$status_text}) — {$url}", 'error');

        throw new \RuntimeException(
            "La demo no responde en {$label} ({$url}): status {$status_text} tras {$max_attempts} "
            . 'intentos. El código se subió y el .env se escribió, pero la demo no contesta en esa '
            . 'URL — revisá erp_api_url y erp_spa_url en el catálogo de Demos.'
        );
    }

    // =========================================================================
    // Log
    // =========================================================================

    /**
     * Persiste una línea de log de esta corrida.
     *
     * Una fila por línea (ver la migración de demo_installation_logs): el `INSERT` es del tamaño de
     * la línea, y una línea gigante no puede romper el registro de la corrida como pasaba con el
     * log en una columna de texto de demo_updates.
     *
     * @param  string  $step
     * @param  string  $line
     * @param  string  $level  info | success | error | warning
     * @return DemoInstallationLog
     */
    private function log(string $step, string $line, string $level = 'info'): DemoInstallationLog
    {
        return DemoInstallationLog::create([
            'demo_installation_id' => $this->installation->id,
            'step'                 => $step,
            'line'                 => $line,
            'level'                => $level,
            'created_at'           => now(),
        ]);
    }

    /**
     * Registra la salida de un comando remoto, partida en trozos si es larga.
     *
     * El corte no es cosmético: una salida de webpack puede tener cientos de miles de caracteres y
     * la columna `line` es TEXT (64 KB). Con 8000 por trozo entra siempre y no se hace un INSERT
     * por renglón.
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

        $max_chunk = 8000;
        if (strlen($output) <= $max_chunk) {
            $this->log($step, $output);

            return;
        }

        $chunks = str_split($output, $max_chunk);
        $total  = count($chunks);
        foreach ($chunks as $index => $chunk) {
            $this->log($step, '[salida ' . ($index + 1) . "/{$total}] " . $chunk);
        }
    }

    /**
     * Deja escrito, antes de la primera etapa, a qué servidor y a qué rutas va esta corrida.
     *
     * No falla nunca: si las rutas no se pueden resolver lo deja anotado y sigue — la etapa que las
     * necesite va a tirar con su propio mensaje. Un log de diagnóstico que aborte el pipeline sería
     * peor que no tenerlo.
     *
     * @return void
     */
    private function log_destino(): void
    {
        $hosting = $this->demo_hosting_type();
        $destino = $hosting === 'vps' ? 'VPS' : 'hosting compartido';

        try {
            $this->log(
                'destino',
                'Esta demo se instala en ' . $destino . ' (credencial SSH: '
                . $this->demo_credential_type() . ', host: ' . $this->credential->host . ')'
            );
            $this->log('destino', 'Versión: ' . $this->version_tag());
            $this->log('destino', 'API: ' . $this->demo_api_path());
            $this->log('destino', 'SPA: ' . $this->demo_spa_path());
        } catch (\Throwable $e) {
            $this->log('destino', 'No se pudieron resolver las rutas: ' . $e->getMessage(), 'warning');
        }
    }

    // =========================================================================
    // Helpers de rutas y URLs de la demo
    // =========================================================================

    /**
     * Tag de git de la versión que se instala (v4.0.2).
     *
     * @return string
     */
    private function version_tag(): string
    {
        return 'v' . $this->version->version;
    }

    /**
     * Tipo de credencial SSH/SFTP del servidor de esta demo ('shared_hosting' | 'vps').
     *
     * @return string
     */
    private function demo_credential_type(): string
    {
        return $this->path_resolver->credential_type($this->demo);
    }

    /**
     * Tipo de hosting del ERP de esta demo ('shared_hosting' | 'vps').
     *
     * @return string
     */
    private function demo_hosting_type(): string
    {
        return $this->path_resolver->hosting_type($this->demo);
    }

    /**
     * Directorio raíz de la API de la demo en su servidor.
     *
     * @return string
     * @throws \RuntimeException Si no se puede armar una ruta completa.
     */
    private function demo_api_path(): string
    {
        return $this->path_resolver->api_path($this->demo);
    }

    /**
     * Directorio del SPA de la demo en su servidor.
     *
     * @return string
     * @throws \RuntimeException Si no se puede armar una ruta completa.
     */
    private function demo_spa_path(): string
    {
        return $this->path_resolver->spa_path($this->demo);
    }

    /**
     * URL de la API de la demo normalizada para el SPA y para el demo-setup.
     *
     * Delega en ClientEmpresaApiUrlResolver::normalize_demo_api_base_url(), que aplica la regla
     * idempotente del `/public` sólo en hosting compartido: en el VPS el docroot ya ES public/ y el
     * sufijo daría 404 en todo.
     *
     * ⚠️ Es DISTINTA de la que se escribe en APP_URL (ver build_env_vars_to_write): esta es la que
     * el navegador y el admin le piden a la API; APP_URL es lo que Laravel cree que es su base.
     *
     * @return string
     * @throws \RuntimeException Si erp_api_url está vacía.
     */
    private function demo_api_base_url(): string
    {
        $url = rtrim(trim((string) $this->demo->erp_api_url), '/');
        if ($url === '') {
            throw new \RuntimeException(
                'La demo no tiene cargado el campo «ERP API URL». Sin ese dato no se puede compilar '
                . 'el SPA ni disparar el demo-setup. Cargalo desde el catálogo de Demos.'
            );
        }

        $resolver = new ClientEmpresaApiUrlResolver();

        return $resolver->normalize_demo_api_base_url($url, $this->demo_hosting_type());
    }

    /**
     * ¿La URL tiene esquema http/https?
     *
     * Es la señal con la que este pipeline —igual que DemoUpdateService— reconoce una demo LOCAL
     * (las que arma el seeder, ej. `empresa.local:8000`) para saltearse lo que sale a la red. Sin
     * esto, correr el pipeline en desarrollo dispara tráfico real.
     *
     * @param  string  $url
     * @return bool
     */
    private function es_url_absoluta(string $url): bool
    {
        return preg_match('/^https?:\/\//i', $url) === 1;
    }

    /**
     * Contenido del .env con el que se compila el SPA de esta demo.
     *
     * Copiado de DemoUpdateService::build_demo_spa_env_content() para que una demo recién instalada
     * y una actualizada queden con el MISMO bundle. El orden importa: los defaults fijos de
     * `services.deploy.spa_build_env` van primero y lo específico de esta demo los pisa — si alguna
     * vez hay colisión de claves, la demo concreta tiene que ganar, no el default.
     *
     * @return string
     */
    private function build_demo_spa_env_content(): string
    {
        $api_url = $this->demo_api_base_url();

        /* 🔴 `VUE_APP_APP_URL` normalizada: queda compilada DENTRO del bundle, así que una URL sin
         * esquema no la corrige nadie después — hay que rehacer el build.
         *
         * Y ojo con la asimetría: `$api_url` NO pasa por el normalizador de esquema a propósito.
         * `demo_api_base_url()` deja crudo el valor no absoluto porque es justamente la señal con
         * la que step_verify() y step_run_demo_setup() reconocen una demo local y se saltean lo que
         * sale a la red. */
        $spa_url = DemoUrlNormalizer::base($this->demo->erp_spa_url);

        // Única asignación de la URL con la que se compila: es la cadena que step_verify() tiene
        // que verificar (no una re-resuelta desde erp_api_url).
        $this->compiled_api_url = $api_url;

        $env_vars      = [];
        $spa_build_env = config('services.deploy.spa_build_env', []);
        if (is_array($spa_build_env)) {
            foreach ($spa_build_env as $env_key => $env_value) {
                $env_vars[(string) $env_key] = trim((string) $env_value);
            }
        }

        $env_vars['VUE_APP_API_URL']        = $api_url;
        $env_vars['VUE_APP_APP_URL']        = $spa_url;
        $env_vars['VUE_APP_PUSHER_KEY']     = trim((string) config('services.deploy.spa_pusher_key', ''));
        $env_vars['VUE_APP_PUSHER_CLUSTER'] = trim((string) config('services.deploy.spa_pusher_cluster', 'sa1'));

        $lines = [];
        foreach ($env_vars as $env_key => $env_value) {
            // Los valores con espacios necesitan comillas o dotenv/vue-cli los parsea mal.
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
    // Helpers SSH al servidor de la demo
    // =========================================================================

    /**
     * Conecta por SSH al servidor de la demo.
     *
     * @return void
     */
    private function connect_hosting_ssh(): void
    {
        $this->disconnect_hosting_ssh();
        $this->ssh = new SSH2($this->credential->host, (int) $this->credential->port);

        $logged_in = $this->ssh->login($this->credential->username, $this->credential->password);
        if (! $logged_in) {
            throw new \RuntimeException(
                'No se pudo conectar por SSH al servidor de la demo: credenciales rechazadas.'
            );
        }
    }

    /**
     * Cierra la sesión SSH al servidor de la demo.
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
     * Reabre la sesión SSH al servidor de la demo.
     *
     * 🔴 Hace falta después de cada transferencia SFTP y de cada comando largo: una sesión tocada
     * por la transferencia devuelve salida VACÍA sin dar error, y una verificación que lee esa
     * salida vacía marca fallida una instalación que quedó perfecta.
     *
     * @return void
     */
    private function reconnect_hosting_ssh(): void
    {
        $this->connect_hosting_ssh();
    }

    /**
     * Ejecuta un comando en el servidor de la demo.
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

        $vps_credential  = ClientSshCredential::where('type', 'vps')->firstOrFail();
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
     * Reabre la sesión al VPS de builds (npm run build puede dejar el canal cerrado).
     *
     * @return void
     */
    private function reconnect_build_vps(): void
    {
        $this->connect_build_vps();
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
     * Ejecuta un comando remoto y valida el exit status.
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
            // Sin timeout para npm run build y composer install.
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
            throw new \RuntimeException(
                "Comando remoto falló (exit {$exit_status}). " . $this->truncate_for_log($output, 1200)
            );
        }

        /* getExitStatus() puede devolver false en servidores que no lo reportan. Ahí lo único que
         * queda es leer la salida, que es una heurística — pero peor es dar por bueno un comando
         * que dijo "command not found". */
        if ($must_succeed && $exit_status === false && $this->remote_output_indicates_failure($output)) {
            throw new \RuntimeException(
                'Comando remoto falló (sin exit status). ' . $this->truncate_for_log($output, 1200)
            );
        }

        return $output;
    }

    /**
     * Escapa un valor para interpolarlo en un comando remoto.
     *
     * 🔴 No es cosmética: `erp_vps_path` es un campo de texto libre del catálogo de Demos y el path
     * resuelto termina adentro de un `cd` y de un `rm`. Copiado de
     * EnvSshService::escape_remote_arg(), donde está la explicación larga. Se usa
     * `escape_remote_arg()` y no `escapeshellarg()` porque este código puede correr en Windows,
     * donde `escapeshellarg()` usa comillas dobles y el shell remoto es POSIX.
     *
     * @param  string  $value
     * @return string
     */
    private function escape_remote_arg(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }

    // =========================================================================
    // Helpers SFTP
    // =========================================================================

    /**
     * Abre una sesión SFTP contra el servidor del tipo de credencial pedido.
     *
     * @param  string  $credential_type  'vps' | 'shared_hosting'
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
     * Path local del ZIP temporal, asegurando que el directorio exista.
     *
     * @param  string  $basename
     * @return string
     */
    private function local_zip_path(string $basename): string
    {
        $deployments_dir = storage_path('app/deployments');
        if (! is_dir($deployments_dir)) {
            mkdir($deployments_dir, 0755, true);
        }

        return $deployments_dir . DIRECTORY_SEPARATOR . $basename;
    }

    /**
     * Borra el ZIP temporal local si quedó.
     *
     * @param  string  $local_zip
     * @return void
     */
    private function cleanup_local_zip(string $local_zip): void
    {
        if (is_file($local_zip)) {
            unlink($local_zip);
        }
    }

    /**
     * Descarga un archivo remoto vía SFTP a disco local.
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
                "SFTP: tamaño remoto ({$remote_size}) no coincide con el stat del VPS ({$expected_bytes})"
            );
        }

        $downloaded = $sftp->get($remote_path, $local_path);
        if ($downloaded === false) {
            throw new \RuntimeException("SFTP get falló al descargar {$remote_path}");
        }

        $this->assert_local_zip_file($local_path, $remote_size, $step);
    }

    /**
     * Sube un ZIP local y verifica que el tamaño remoto coincida.
     *
     * @param  SFTP    $sftp
     * @param  string  $local_path
     * @param  string  $remote_path
     * @param  string  $step
     * @return void
     */
    private function sftp_upload_file(SFTP $sftp, string $local_path, string $remote_path, string $step): void
    {
        $this->assert_local_zip_file($local_path, 0, $step);
        $local_size = (int) filesize($local_path);

        $uploaded = $sftp->put($remote_path, $local_path, SFTP::SOURCE_LOCAL_FILE);
        if ($uploaded === false) {
            throw new \RuntimeException("SFTP put falló al subir {$remote_path}");
        }

        $remote_size = $this->sftp_remote_file_size($sftp, $remote_path);
        if ($remote_size === false || $remote_size !== $local_size) {
            throw new \RuntimeException(
                "SFTP: tamaño en el servidor ({$remote_size}) no coincide con el local ({$local_size})"
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

        $this->log($step, "ZIP local verificado ({$local_size} bytes)");
    }

    // =========================================================================
    // Helpers del VPS de builds
    // =========================================================================

    /**
     * Valida un ZIP recién creado en el VPS (integridad + tamaño) y devuelve su tamaño.
     *
     * @param  string  $remote_zip_path
     * @param  string  $step
     * @return int
     */
    private function verify_zip_on_vps(string $remote_zip_path, string $step): int
    {
        $this->exec_build_ssh(
            $step,
            'test -f ' . $this->escape_remote_arg($remote_zip_path)
            . ' && unzip -tq ' . $this->escape_remote_arg($remote_zip_path) . ' 2>&1',
            true,
            true
        );

        $size_output = $this->exec_build_ssh(
            $step,
            'stat -c%s ' . $this->escape_remote_arg($remote_zip_path) . ' 2>&1'
        );
        $size_bytes = (int) trim($size_output);
        if ($size_bytes < 500) {
            throw new \RuntimeException("ZIP inválido o vacío en el VPS ({$size_bytes} bytes): {$remote_zip_path}");
        }

        $this->log($step, "ZIP verificado en el VPS: {$size_bytes} bytes");

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
     * Nombre de la carpeta de salida del build del SPA (vue-cli: dist).
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
     * Comando de `npm run <script>` para el VPS (con NODE_OPTIONS para webpack).
     *
     * @param  string  $npm_bin
     * @param  string  $npm_script
     * @return string
     */
    private function build_vps_npm_run_command(string $npm_bin, string $npm_script): string
    {
        $parts        = [];
        $node_options = trim((string) config('services.deploy.node_options', '--openssl-legacy-provider'));
        if ($node_options !== '') {
            $parts[] = 'export NODE_OPTIONS=' . $this->escape_remote_arg($node_options);
        }
        $parts[] = $this->escape_remote_arg($npm_bin) . ' run ' . $this->escape_remote_arg($npm_script);

        return implode(' && ', $parts);
    }

    /**
     * Preámbulo que expone npm/node en una sesión SSH no interactiva (nvm, fnm, bashrc, PATH).
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
            $parts[] = 'export PATH=' . $this->escape_remote_arg(dirname($npm_bin)) . ':$PATH';
        }

        $nvm_dir = trim((string) config('services.deploy.nvm_dir', ''));
        if ($nvm_dir !== '') {
            $parts[] = 'export NVM_DIR=' . $this->escape_remote_arg($nvm_dir);
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
     * Envuelve un script en un bash login/interactivo para el VPS de builds.
     *
     * @param  string  $script
     * @return string
     */
    private function wrap_vps_bash_script(string $script): string
    {
        if (filter_var(config('services.deploy.vps_use_login_shell_only', false), FILTER_VALIDATE_BOOLEAN)) {
            return 'bash -lc ' . $this->escape_remote_arg($script) . ' 2>&1';
        }

        $bash_flags = '-lc';
        if (filter_var(config('services.deploy.vps_use_interactive_login_shell', true), FILTER_VALIDATE_BOOLEAN)) {
            $bash_flags = '-lic';
        }

        return 'bash ' . $bash_flags . ' ' . $this->escape_remote_arg($script) . ' 2>&1';
    }

    /**
     * Arma un comando para el VPS de builds (preámbulo de Node + cd + comando).
     *
     * @param  string  $work_dir
     * @param  string  $command_after_cd
     * @return string
     */
    private function build_vps_command(string $work_dir, string $command_after_cd): string
    {
        $script = $this->build_vps_node_preamble()
            . '; cd ' . $this->escape_remote_arg($work_dir)
            . ' && ' . $command_after_cd;

        return $this->wrap_vps_bash_script($script);
    }

    /**
     * Arma el `composer install` para un directorio remoto.
     *
     * 🔴 SIEMPRE con `--no-scripts`, en el VPS y en el servidor de la demo. En una instalación no
     * hay .env en ninguno de los dos cuando corre composer, y el script `post-autoload-dump`
     * ejecuta `artisan package:discover`, que bootea Laravel y falla sin entorno. Los artisan van
     * después, en finalize_api. Mismo criterio que
     * InstallationService::build_composer_install_command().
     *
     * @param  string  $work_dir
     * @param  bool    $is_vps  true en el VPS de builds (envuelve el comando), false en la demo
     * @return string
     */
    private function build_composer_install_command(string $work_dir, bool $is_vps): string
    {
        $composer_bin = trim((string) config('services.deploy.composer_bin', 'composer'));
        $flags        = 'COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MEMORY_LIMIT=-1 '
            . $this->escape_remote_arg($composer_bin)
            . ' install --no-dev --optimize-autoloader --no-interaction --no-ansi --no-scripts';

        if ($is_vps) {
            return $this->build_vps_command($work_dir, $flags);
        }

        return 'cd ' . $this->escape_remote_arg($work_dir) . ' && ' . $flags . ' 2>&1';
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
            'echo PATH=$PATH; command -v ' . $this->escape_remote_arg($npm_bin) . ' node 2>&1; '
            . $this->escape_remote_arg($npm_bin) . ' -v 2>&1'
        );
        $output = $this->exec_build_ssh('compile_spa', $check_cmd, false);
        $this->log('compile_spa', 'Diagnóstico Node/npm: ' . $this->truncate_for_log($output));

        if ($this->remote_output_indicates_failure($output) || ! preg_match('/\d+\.\d+/', $output)) {
            throw new \RuntimeException(
                'npm no está disponible en el VPS de builds. Configurá DEPLOY_NPM_BIN=/ruta/completa/npm '
                . 'en el .env de admin-api. Diagnóstico: ' . $this->truncate_for_log($output, 500)
            );
        }
    }

    /**
     * Verifica que dist/index.html exista en el VPS tras el build.
     *
     * @param  string  $spa_build_path
     * @return void
     */
    private function assert_spa_dist_on_vps(string $spa_build_path): void
    {
        $spa_output_dir = $this->spa_output_dir_name();
        $check_cmd      = $this->build_vps_command(
            $spa_build_path,
            'test -d ' . $this->escape_remote_arg($spa_output_dir)
            . ' && test -f ' . $this->escape_remote_arg($spa_output_dir . '/index.html')
            . ' && echo SPA_DIST_OK || (echo SPA_DIST_MISSING; ls -la; exit 1)'
        );
        $output = $this->exec_build_ssh('compile_spa', $check_cmd);
        if (stripos($output, 'SPA_DIST_OK') === false) {
            throw new \RuntimeException(
                "El build no generó {$spa_output_dir}/index.html en el VPS. "
                . $this->truncate_for_log($output, 600)
            );
        }
        $this->log('compile_spa', "Verificado {$spa_output_dir}/index.html en el VPS", 'success');
    }

    /**
     * Script que vacía el directorio del SPA y descomprime dist.zip en su raíz.
     *
     * Mismo script que DemoUpdateService::build_spa_hosting_deploy_shell(). El `find . -mindepth 1
     * -delete` es la razón por la que DemoPathResolver tira si el path fuera a quedar con un
     * segmento vacío: una ruta incompleta no es un error visible, es un directorio equivocado
     * vaciado.
     *
     * @param  string  $spa_dir
     * @return string
     */
    private function build_spa_hosting_deploy_shell(string $spa_dir): string
    {
        $temp_zip_basename = 'dist_deploy_' . $this->installation->uuid . '.zip';
        $deploy_zip_name   = 'dist.zip';

        return 'set -e; '
            . 'SPA_DIR=' . $this->escape_remote_arg($spa_dir) . '; '
            . 'TEMP_ZIP=' . $this->escape_remote_arg('../' . $temp_zip_basename) . '; '
            . 'cd "$SPA_DIR" || exit 1; '
            . 'if [ -f ' . $this->escape_remote_arg($deploy_zip_name) . ' ]; then mv '
            . $this->escape_remote_arg($deploy_zip_name) . ' "$TEMP_ZIP"; fi; '
            . 'find . -mindepth 1 -delete 2>/dev/null || true; '
            . 'if [ -f "$TEMP_ZIP" ]; then unzip -o "$TEMP_ZIP" -d .; rm -f "$TEMP_ZIP"; fi; '
            . 'echo SPA_DEPLOY_OK 2>&1';
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

        return stripos($output, 'Build complete') !== false;
    }

    /**
     * Heurística para cuando getExitStatus() no está disponible en el servidor SSH.
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
     * Recorta texto para mensajes de excepción o líneas de log resumidas.
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
