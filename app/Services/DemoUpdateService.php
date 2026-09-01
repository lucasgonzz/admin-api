<?php

namespace App\Services;

use App\Models\ClientSshCredential;
use App\Models\Demo;
use App\Models\DemoUpdate;
use App\Models\Version;
use App\Services\Afip\AfipCertificateProvisionService;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use App\Services\ClientEmpresaApiUrlResolver;
use Illuminate\Support\Facades\Http;

/**
 * Ejecuta el pipeline completo de actualización de una demo, en el servidor donde esa demo viva.
 *
 * Pipeline de etapas:
 *   1. step_compile_spa()     — checkout en VPS + npm ci + npm run build
 *   2. step_upload_spa()      — zip dist/ → sftp download → sftp upload al hosting
 *   3. step_upload_api()      — checkout en VPS + composer install + zip → sftp → hosting
 *   4. step_run_migrations()  — limpia caché de Laravel + `php artisan migrate --force` en el hosting
 *   5. step_restart_queue_workers() — solo en VPS: `queue:restart` para que el worker de supervisor,
 *                                     que es de larga vida, deje de correr el código anterior
 *   6. step_verify_demo()     — verifica que la API y el SPA respondan con la URL realmente compilada
 *
 * El demo-setup NO forma parte de este pipeline (decisión del 14/7/2026): corre `migrate:fresh`
 * y vaciaría la base de la demo. Se dispara solo desde el módulo de Leads.
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
     * Credencial SSH del servidor de la demo (shared_hosting o vps, según su erp_hosting_type).
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
     * URL de API que quedó efectivamente escrita en el .env del SPA compilado
     * (el mismo valor que se asignó a VUE_APP_API_URL en build_demo_spa_env_content()).
     *
     * CRÍTICO (24/7/2026): step_verify_demo() tiene que verificar contra ESTA cadena, no
     * contra una URL re-resuelta desde $this->demo->erp_api_url en el momento de verificar.
     * Si se re-arma la URL en vez de reusar la que se compiló, un bug de normalización daría
     * verde con la demo rota — que es justamente el incidente que motivó esta etapa.
     *
     * @var string
     */
    private $compiled_api_url = '';

    /**
     * Carga el DemoUpdate con sus relaciones y la credencial del servidor donde vive la demo.
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

        /* Credencial del servidor destino, requerida para SSH y SFTP. Sale del hosting de la demo:
         * hasta el 26/8/2026 estaba cableada a 'shared_hosting' porque todas las demos vivían ahí. */
        $this->credential = ClientSshCredential::where('type', $this->demo_credential_type())->firstOrFail();
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

        // Primera línea del log: a qué servidor va esta corrida. Toda la funcionalidad del hosting
        // consiste en elegir un camino, así que el camino elegido tiene que quedar registrado —
        // y arriba de todo, no deducible de las líneas `$ cd ...` que vengan 300 renglones después.
        $this->log_destino();

        try {
            $this->step_compile_spa();
            $this->step_upload_spa();
            $this->step_upload_api();
            $this->step_run_migrations();
            $this->step_restart_queue_workers();
            $this->step_verify_demo();

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
        $api_url_para_log = $this->demo_api_base_url();
        $env_content  = $this->build_demo_spa_env_content();
        $env_escaped  = str_replace("'", "'\\''", $env_content);
        $env_file     = $spa_build_path . '/.env';
        $this->exec_build_ssh(
            'compile_spa',
            "printf '%s' '{$env_escaped}' > " . escapeshellarg($env_file)
        );
        $this->append_log(
            '[compile_spa] .env configurado — API: ' . $api_url_para_log
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

        /* Path del SPA de la demo en su servidor: relativo al home SSH en hosting compartido,
         * absoluto en el VPS. Lo resuelve DemoPathResolver, que además tira si el path fuera a
         * quedar con un segmento vacío — abajo hay un `find . -mindepth 1 -delete`. */
        $hosting_spa_dir    = $this->demo_spa_path();
        $hosting_zip_remote = "{$hosting_spa_dir}/dist.zip";

        // Sube el ZIP al servidor de la demo.
        $sftp_hosting = $this->open_sftp_session($this->demo_credential_type());
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

        // Path del API de la demo en su servidor (relativo en shared, absoluto en VPS).
        $api_path    = $this->demo_api_path();
        $remote_zip  = "{$api_path}/{$zip_name}";

        // Sube el ZIP al servidor de la demo.
        $sftp_hosting = $this->open_sftp_session($this->demo_credential_type());
        $this->sftp_upload_file($sftp_hosting, $local_zip, $remote_zip, 'upload_api');
        $this->append_log('[upload_api] ZIP subido al hosting');

        // Descomprime el ZIP en el directorio del API.
        $this->connect_hosting_ssh();
        $this->exec_hosting_ssh(
            'upload_api',
            /* escapeshellarg en las tres, como el resto del archivo (472, 484, 1252). Era la única
             * interpolación cruda que quedaba, y desde que el path puede salir de `erp_vps_path`
             * —un campo de texto libre del modal de Demos— eso pasó de teórico a alcanzable. */
            'cd ' . escapeshellarg($api_path)
            . ' && unzip -o ' . escapeshellarg($zip_name)
            . ' && rm ' . escapeshellarg($zip_name),
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
     * Etapa 4: limpia el caché de Laravel y corre las migraciones pendientes en el API de la demo.
     *
     * IMPORTANTE (14/7/2026): esta etapa reemplazó a step_run_demo_setup(). El demo-setup NUNCA
     * corre como parte de una actualización de demo: DemoSetupHelper::run() arranca con
     * `migrate:fresh`, o sea que vaciaría la base de la demo entera en cada actualización. El
     * demo-setup se dispara exclusivamente desde el módulo de Leads, cuando corresponde para cada
     * lead.
     *
     * Pero subir código nuevo sin migrar deja el schema viejo, y cualquier versión con migraciones
     * pendientes rompe la demo (columna inexistente → 500 en cualquier request). Por eso el
     * pipeline sí corre migraciones: actualizar demo = código + schema. Los datos son problema del
     * demo-setup.
     *
     * `migrate --force` es incremental y no destructivo: solo aplica las migraciones que faltan.
     *
     * @return void
     */
    private function step_run_migrations(): void
    {
        // Path del API de la demo en su servidor, con el mismo criterio que step_upload_api().
        $api_path = $this->demo_api_path();

        /* Va acá y no en step_upload_api() por dos motivos. Primero, porque de este punto en
         * adelante NINGUNA etapa vuelve a escribir en el árbol de la API: las migraciones son
         * artisan, step_restart_queue_workers() es un `queue:restart` y step_verify_demo() es HTTP.
         * El único paso que borra archivos es el `find . -mindepth 1 -delete` del despliegue del
         * SPA, que es otro directorio y ocurre dos etapas antes. O sea que lo que se reponga acá es
         * lo que queda. (Lo que NO es motivo: que el `unzip -o` de step_upload_api() pisaría lo
         * repuesto. No lo pisaría —el ZIP excluye `storage/*`, así que nunca toca
         * storage/app/afip/—; el motivo es el estado final, no un riesgo de sobrescritura.)
         * Segundo, es el mismo punto del pipeline donde lo hace DeploymentService, así que los dos
         * se leen igual.
         *
         * 🔴 Y va ANTES del connect_hosting_ssh() de abajo a propósito: la reposición usa una
         * sesión SFTP aparte, y el connect que ya estaba hace de reconexión posterior sin que haya
         * que agregar un reconnect_hosting_ssh() propio. Si alguna vez se mueve la llamada debajo
         * del connect, hay que agregar ese reconnect —como hace DeploymentService—, porque los
         * artisan de esta etapa corren por SSH y una sesión tocada por la transferencia devuelve
         * salida vacía sin dar error. */
        $this->provision_afip_certificates('run_migrations');

        // step_upload_api() termina con reconnect_build_vps(), así que la sesión SSH activa
        // al cerrar esa etapa es la del VPS, no la del hosting: hay que reconectar acá.
        $this->connect_hosting_ssh();

        // El caché de config/rutas/vistas apunta al código viejo: limpiarlo antes de migrar.
        $this->append_log('[run_migrations] Limpiando caché de Laravel...');
        $clear_commands = [
            'config:clear',
            'cache:clear',
            'view:clear',
            'route:clear',
        ];
        foreach ($clear_commands as $clear_command) {
            // must_succeed = false: un caché ya inexistente hace que artisan devuelva error y no es
            // motivo para abortar una actualización que por lo demás salió bien.
            $this->exec_hosting_ssh(
                'run_migrations',
                'cd ' . escapeshellarg($api_path) . ' && php artisan ' . $clear_command . ' --no-ansi 2>&1',
                false
            );
        }
        $this->append_log('[run_migrations] Caché limpiado');

        // must_succeed = true: si una migración falla, la demo queda con schema roto y el
        // DemoUpdate tiene que marcarse como fallido. long_running = true: una migración pesada
        // puede superar el timeout estándar de 10s de phpseclib.
        $this->append_log('[run_migrations] Corriendo migraciones pendientes...');
        $this->exec_hosting_ssh(
            'run_migrations',
            'cd ' . escapeshellarg($api_path) . ' && php artisan migrate --force --no-ansi 2>&1',
            true,
            true
        );
        $this->append_log('[run_migrations] Migraciones completadas');
    }

    /**
     * Etapa 5: reinicia el worker de cola. Solo aplica a demos alojadas en VPS.
     *
     * En el VPS el worker vive bajo supervisor y es un proceso de LARGA VIDA: carga las clases en
     * memoria al arrancar y no las recarga nunca. Sin esta etapa, después de cada update sigue
     * procesando jobs con el código de la versión anterior, indefinidamente — y el síntoma no se
     * parece en nada a la causa: jobs que fallan por clases o constantes que "no existen" aunque
     * estén perfectas en disco, y notificaciones que nunca llegan.
     *
     * INCIDENTE (26/8/2026): la demo 1 se actualizó a v4.0.4 y el worker seguía siendo el de una
     * hora antes, procesando con el código de 4.0.3. Hubo que reiniciarlo a mano. El mismo modo de
     * falla en DeploymentService hacía que un job se cayera con "Undefined class constant
     * 'CONDICION_MT'" y que al negocio no le llegara la notificación de la cotización del dólar.
     *
     * Va DESPUÉS de las migraciones (el worker nuevo arranca contra un esquema al día) y ANTES de
     * step_verify_demo(), para que la verificación final corra con el worker ya renovado.
     *
     * En shared_hosting no hace falta: ahí el worker es el `queue:work --stop-when-empty` que
     * lanza el cron, arranca y muere cada minuto, y toma el código nuevo solo.
     *
     * ⚠️ El `schedule:work` NO se reinicia y no hace falta: ese comando lanza `schedule:run` como
     * subproceso nuevo cada minuto, así que las tareas programadas ya toman código fresco solas.
     *
     * @return void
     */
    private function step_restart_queue_workers(): void
    {
        if ($this->demo_hosting_type() !== 'vps') {
            $this->append_log(
                '[restart_queue_workers] Demo en hosting compartido: no hay worker de larga vida que '
                . 'reiniciar (el cron lanza queue:work --stop-when-empty, que muere cada minuto).'
            );

            return;
        }

        $api_path = $this->demo_api_path();

        $this->append_log('[restart_queue_workers] Reiniciando el worker de cola del VPS...');

        /* must_succeed = false a propósito: llegado este punto el código ya está subido y las
           migraciones corridas. Abortar acá dejaría el update a medias, que es peor que un worker
           con código viejo. Se degrada a warning en el log. */
        $output = $this->exec_hosting_ssh(
            'restart_queue_workers',
            'cd ' . escapeshellarg($api_path) . ' && php artisan queue:restart --no-ansi 2>&1',
            false
        );

        /* `queue:restart` no reinicia nada por sí mismo: deja una marca en caché que cada worker
           lee entre job y job. Si la caché no está disponible, artisan puede devolver 0 igual, así
           que se confirma contra el texto de éxito en vez de confiar en el exit code. */
        if (stripos((string) $output, 'Broadcasting queue restart signal') !== false) {
            $this->append_log(
                '[restart_queue_workers] Señal enviada: el worker termina el job en curso y arranca '
                . 'con el código nuevo.'
            );

            return;
        }

        $this->append_log(
            '[restart_queue_workers] AVISO: no se pudo confirmar el reinicio del worker. El update '
            . 'sigue, pero el worker puede estar corriendo el código anterior. Reinicialo a mano con '
            . '"php artisan queue:restart" en ' . $api_path
        );
    }

    /**
     * Etapa 6: verifica que la demo realmente responda tras la actualización.
     *
     * INCIDENTE (24/7/2026): el pipeline compiló, subió, migró y quedó en `completado` con la
     * demo devolviendo 404 en cada request porque el .env compilado tenía `/public/public`
     * (bug corregido por el prompt 01 de este grupo, en ClientEmpresaApiUrlResolver). Ninguna
     * de las cuatro etapas anteriores prueba el resultado: son todas validaciones de proceso
     * (build sin errores, ZIP válido, migrate con exit 0), no de que el SPA compilado pueda
     * efectivamente hablarle a la API. La URL queda compilada DENTRO del bundle, así que un
     * valor equivocado es invisible para esas validaciones.
     *
     * Por eso esta etapa pega HTTP directo:
     *   - a `{compiled_api_url}/sanctum/csrf-cookie`, con la cadena EXACTA que se escribió en
     *     el .env (no una URL re-resuelta desde erp_api_url — ver doc de $compiled_api_url);
     *   - al SPA (`erp_spa_url`), para detectar el caso de que el deploy haya vaciado el
     *     directorio sin reponer nada (find -delete de build_spa_hosting_deploy_shell()).
     *
     * Ambos chequeos reintentan hasta 3 veces con 5s de espera, para no marcar `fallido` por
     * un hipo de red transitorio o por el hosting todavía recalentando el opcache tras el
     * deploy. Si tras los 3 intentos no hay 2xx, lanza RuntimeException — run() lo captura y
     * marca el DemoUpdate como `fallido`.
     *
     * Demos con URL local (ej. empresa.local:8000, las que arma el seeder) no son hosting
     * real: se saltea la verificación y se deja constancia en el log, sin fallar.
     *
     * @return void
     */
    private function step_verify_demo(): void
    {
        // No debería pasar nunca: build_demo_spa_env_content() siempre lo setea antes de esta
        // etapa. Si llegara vacío, algo del pipeline cambió de orden — mejor fallar explícito
        // que verificar contra una URL vacía o adivinada.
        if ($this->compiled_api_url === '') {
            throw new \RuntimeException(
                '[verify_demo] No se pudo determinar la URL con la que se compiló el SPA '
                . '(compiled_api_url vacía).'
            );
        }

        // Demos locales (seeder) no son hosting real: no tiene sentido pegarles por HTTP.
        if (! preg_match('/^https?:\/\//i', $this->compiled_api_url)) {
            $this->append_log(
                '[verify_demo] Se saltea la verificación: "' . $this->compiled_api_url . '" '
                . 'no es una URL absoluta (demo local).'
            );

            return;
        }

        // Verifica la API con la cadena exacta compilada en el .env.
        $api_check_url = rtrim($this->compiled_api_url, '/') . '/sanctum/csrf-cookie';
        $this->verify_demo_url_responds('verify_demo', $api_check_url, 'API');

        // Verifica el SPA: detecta el caso de deploy que vació el directorio sin reponer nada.
        //
        // 🔴 Normalizada, no cruda (17/8/2026): acá ya se sabe que la demo es de hosting real
        // —lo decidió el guard de arriba mirando `compiled_api_url`—, pero eso no dice nada sobre
        // `erp_spa_url`, que es otra columna de texto libre. Una demo con `erp_api_url` absoluta y
        // `erp_spa_url` sin esquema hacía fallar este GET y marcaba fallida una actualización que
        // había salido bien.
        $spa_check_url = DemoUrlNormalizer::base($this->demo->erp_spa_url);
        $this->verify_demo_url_responds('verify_demo', $spa_check_url, 'SPA');
    }

    /**
     * Pega un GET a la URL indicada con reintentos, para uso de step_verify_demo().
     * Considera exitoso cualquier status 2xx. Loguea el resultado en ambos casos (OK y error).
     *
     * @param  string  $step        Prefijo de log
     * @param  string  $url         URL completa a verificar
     * @param  string  $label       Etiqueta legible ("API" | "SPA") para distinguir en el log
     * @return void
     * @throws \RuntimeException  Si ningún intento devuelve 2xx
     */
    private function verify_demo_url_responds(string $step, string $url, string $label): void
    {
        // Tope de intentos y espera entre ellos: un 404/timeout transitorio en el primer
        // intento no debe tirar abajo una actualización que por lo demás salió bien.
        $max_attempts = 3;
        $wait_seconds = 5;

        $last_status = null;
        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            try {
                $response    = Http::timeout(20)->get($url);
                $last_status = $response->status();

                if ($response->successful()) {
                    $this->append_log(
                        "[{$step}] {$label} OK (intento {$attempt}/{$max_attempts}, status {$last_status}) — {$url}"
                    );

                    return;
                }
            } catch (\Throwable $e) {
                // Timeout, DNS, conexión rechazada, etc.: se trata igual que un status no-2xx
                // y se reintenta.
                $last_status = null;
                $this->append_log(
                    "[{$step}] {$label} sin respuesta (intento {$attempt}/{$max_attempts}) — {$url} — "
                    . $e->getMessage()
                );
            }

            if ($attempt < $max_attempts) {
                sleep($wait_seconds);
            }
        }

        $status_text = $last_status === null ? 'sin respuesta' : (string) $last_status;
        $this->append_log(
            "[{$step}] {$label} FALLÓ tras {$max_attempts} intentos (status {$status_text}) — {$url}"
        );

        throw new \RuntimeException(
            "La demo no responde en {$label} ({$url}): status {$status_text} tras {$max_attempts} intentos. "
            . 'El código se subió bien, pero la demo no responde en esa URL — revisá el campo '
            . 'erp_api_url de la demo.'
        );
    }

    /**
     * Repone en la demo los certificados de AFIP que le falten, tomándolos del servidor del admin.
     *
     * 🔴 POR QUÉ HACE FALTA, Y POR QUÉ NO ALCANZABA CON LO QUE YA HABÍA
     *
     * Desde el commit ec6e164a de empresa-api (26/7/2026) los certificados no viajan más en el
     * código: viven en storage/app/afip/, gitignoreados. El ZIP de esta misma clase excluye
     * `storage/*` (ver step_upload_api), así que ninguna actualización de demo los repuso nunca.
     * El síntoma no se parece a la causa: buscar un cliente por CUIT o DNI en el módulo vender
     * devuelve HTTP 500 en 0,2 s —antes de salir a la red hacia ARCA— porque el constructor de
     * AfipWSAAHelper tira apenas no encuentra el archivo. Medido en demo, demo2 y demo3 el
     * 28/8/2026. El 20/8/2026 esto se había automatizado solo para clientes
     * (InstallationService y DeploymentService); el pipeline de demos quedó afuera.
     *
     * 🔴 NO HAY NADA QUE SINCRONIZAR DESDE UNA CARPETA ANTERIOR, y esa es la diferencia con
     * DeploymentService, que además del provision corre un sync_afip_certificates(). Un CLIENTE
     * alterna carpeta física en cada actualización (v1/v2, ver active_client_api_id) y storage/ no
     * se comparte entre las dos, así que hay que arrastrar lo que tenía. Una DEMO usa siempre el
     * mismo directorio: DemoPathResolver::api_path() se deriva del slug y del hosting, y
     * step_upload_api() descomprime con `unzip -o` ahí adentro sin borrar nada. storage/ sobrevive
     * al update. Portar el sync acá sería código muerto que además haría creer que la demo alterna
     * carpetas.
     *
     * 🔴 Nunca aborta el update, y en demos el argumento es todavía más fuerte que en clientes: a
     * esta altura el código ya está subido y quedarse a mitad deja la demo rota delante de un lead.
     * Además, si el admin no tuviera los certificados cargados, un corte acá haría fallar TODAS las
     * actualizaciones de demo por un archivo que ni siquiera es del camino crítico de la demo. La
     * política vive en AfipCertificateProvisionService::reponer_en_api().
     *
     * @param  string  $step
     * @return void
     */
    private function provision_afip_certificates(string $step): void
    {
        $service = new AfipCertificateProvisionService();

        /* append_log() es una sola tira de texto sin niveles, así que el nivel se antepone al
         * renglón: si no, un error de SFTP se lee igual que "ya los tenía todos". Se usa la misma
         * palabra AVISO que step_restart_queue_workers(), que es el otro paso de este pipeline que
         * se degrada en vez de abortar. */
        $log = function (string $linea, string $nivel) use ($step) {
            $prefijo = ($nivel === 'error' || $nivel === 'warning') ? 'AVISO: ' : '';
            $this->append_log('[' . $step . '] ' . $prefijo . $linea);
        };

        $service->reponer_en_api(
            function () {
                return $this->open_sftp_session($this->demo_credential_type());
            },
            $this->demo_api_path(),
            $log
        );
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
            /* Se conserva la cola: el final del log es lo que sirve para diagnosticar.
             *
             * CORRECCIÓN (13/7/2026): substr() corta por BYTES, no por caracteres. Un corte
             * a ciegas puede caer en medio de un carácter UTF-8 multibyte (tildes, "ñ" — el
             * log tiene texto en español: "compilación", "descomprimida", etc.) y dejar una
             * secuencia inválida colgando al principio de la cola. MySQL en utf8mb4 estricto
             * rechaza esa fila con "Incorrect string value" — el mismo tipo de fallo de
             * save() que causó el bug original (log colgado en `ejecutandose` para siempre).
             * trim_utf8_lead() descarta los bytes de continuación sueltos que puedan quedar
             * al principio tras el corte. */
            $log = "[...log recortado: superó " . self::MAX_LOG_CHARS . " caracteres...]\n"
                . $this->trim_utf8_lead(substr($log, -self::MAX_LOG_CHARS));
        }

        $this->demo_update->log = $log;
        $this->demo_update->save();
    }

    /**
     * Descarta bytes de continuación UTF-8 (0x80–0xBF) sueltos al principio de un string.
     * Necesario cuando un substr() por bytes corta en medio de un carácter multibyte y deja
     * el primer byte del string resultante siendo una continuación sin su byte líder.
     *
     * @param  string  $text
     * @return string
     */
    private function trim_utf8_lead(string $text): string
    {
        $offset = 0;
        $length = strlen($text);

        while ($offset < $length && (ord($text[$offset]) & 0xC0) === 0x80) {
            $offset++;
        }

        return $offset > 0 ? substr($text, $offset) : $text;
    }

    // =========================================================================
    // Helpers SSH al servidor de la demo
    // =========================================================================

    /**
     * Conecta por SSH al servidor de la demo con la credencial que resolvió el constructor.
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
     * Cierra la sesión SSH al servidor de la demo (evita "Please close the channel" en phpseclib).
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
     * Ejecuta un comando en el servidor de la demo y valida exit status.
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
     * 🔴 ESTE MÉTODO ARMA UN BORRADO RECURSIVO, y por eso la ruta pasa por la guarda ANTES de que se
     * escriba una sola letra del comando (31/8/2026). Que DemoPathResolver valide su insumo y que la
     * guarda valide el resultado no es redundante: son dos momentos distintos, y esta segunda es la
     * que sirve el día en que el resolver devuelva mal. Va acá y no adentro del shell remoto porque
     * un `if` del lado del servidor ya viajó, y cualquier error de escapado lo saltea.
     *
     * 🔴 Y el escapado es escape_remote_arg(), NO escapeshellarg(). Esa función escapa según el
     * sistema donde corre PHP y no según el del otro lado: en el WAMP de la máquina de desarrollo
     * emite comillas DOBLES, adentro de las cuales el `sh` remoto expande `$` y backticks. Sobre el
     * argumento que alimenta un `cd` seguido de un `find -delete`, eso no es un detalle de estilo.
     * Las demás llamadas a escapeshellarg() de este archivo son deuda previa y se migran aparte; la
     * de este método no podía esperar. El razonamiento largo está en
     * RemoteCommandRunner::escapar_argumento().
     *
     * @param  string  $spa_dir  Directorio del SPA (relativo en shared, absoluto en VPS)
     * @return string
     * @throws \RuntimeException Si el directorio no es identificable como el del SPA de esta demo.
     */
    private function build_spa_hosting_deploy_shell(string $spa_dir): string
    {
        $resolver = new DemoPathResolver();
        $resolver->assert_directorio_de_spa_borrable($this->assert_demo(), $spa_dir);

        $temp_zip_basename = 'dist_deploy_' . $this->demo_update->uuid . '.zip';
        $deploy_zip_name   = 'dist.zip';

        return 'set -e; '
            . 'SPA_DIR=' . RemoteCommandRunner::escapar_argumento($spa_dir) . '; '
            . 'TEMP_ZIP=' . RemoteCommandRunner::escapar_argumento('../' . $temp_zip_basename) . '; '
            . 'cd "$SPA_DIR" || exit 1; '
            . 'if [ -f ' . RemoteCommandRunner::escapar_argumento($deploy_zip_name) . ' ]; then mv '
            . RemoteCommandRunner::escapar_argumento($deploy_zip_name) . ' "$TEMP_ZIP"; fi; '
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
     * El cálculo vive en DemoPathResolver desde el 26/8/2026: es la misma regla que arma las rutas
     * remotas, y tenerla escrita dos veces es exactamente lo que después diverge sin que nadie lo
     * note.
     *
     * ⚠️ Las tres etapas del pipeline ya no lo llaman: piden la ruta entera a demo_api_path() /
     * demo_spa_path(). Queda a propósito como delegación de una línea porque
     * tests/Unit/DemoUpdateServiceSlugTest.php lo invoca por reflexión: ese test fija el bug del
     * 17/8/2026 (URL sin esquema → slug vacío → ZIP a un directorio equivocado) y, delegando,
     * sigue siendo una red efectiva sobre el cálculo real, ahora en el resolver.
     *
     * @param  string  $url
     * @return string
     */
    private function slug_from_url(string $url): string
    {
        $resolver = new DemoPathResolver();

        return $resolver->slug_from_url($url);
    }

    /**
     * Tipo de credencial SSH/SFTP del servidor donde vive esta demo ('shared_hosting' | 'vps').
     *
     * @return string
     */
    private function demo_credential_type(): string
    {
        if (! $this->demo instanceof Demo) {
            return 'shared_hosting';
        }

        $resolver = new DemoPathResolver();

        return $resolver->credential_type($this->demo);
    }

    /**
     * Directorio raíz de la API de la demo en su servidor.
     *
     * @return string
     * @throws \RuntimeException Si la demo no está cargada o no se puede armar una ruta completa.
     */
    private function demo_api_path(): string
    {
        $resolver = new DemoPathResolver();

        return $resolver->api_path($this->assert_demo());
    }

    /**
     * Directorio del SPA de la demo en su servidor.
     *
     * @return string
     * @throws \RuntimeException Si la demo no está cargada o no se puede armar una ruta completa.
     */
    private function demo_spa_path(): string
    {
        $resolver = new DemoPathResolver();

        return $resolver->spa_path($this->assert_demo());
    }

    /**
     * La demo del DemoUpdate, o excepción si la relación se perdió.
     *
     * Antes esto no hacía falta porque los paths se armaban interpolando un slug que, sin demo,
     * quedaba vacío — y el pipeline seguía igual contra un directorio equivocado. Ahora que la
     * ruta la arma un resolver tipado, la ausencia de demo se convierte en un error visible.
     *
     * @return Demo
     * @throws \RuntimeException
     */
    private function assert_demo(): Demo
    {
        if (! $this->demo instanceof Demo) {
            throw new \RuntimeException(
                'El registro de actualización no tiene demo asociada: no hay forma de saber a qué '
                . 'servidor subir el código.'
            );
        }

        return $this->demo;
    }

    /**
     * Devuelve la URL de API de la demo ya normalizada para uso en el SPA y el log.
     *
     * Valida que la URL no esté vacía (falla temprano si no está cargada).
     * Delega en ClientEmpresaApiUrlResolver::normalize_demo_api_base_url() para aplicar
     * la regla idempotente de /public, que solo corresponde en hosting compartido: en el VPS el
     * docroot ya es public/ y el sufijo daría 404 en todo.
     *
     * @return string  URL de API normalizada
     * @throws \RuntimeException Si erp_api_url está vacía
     */
    private function demo_api_base_url(): string
    {
        $url = rtrim(trim((string) $this->demo->erp_api_url), '/');
        if ($url === '') {
            throw new \RuntimeException(
                'La demo "' . $this->demo->slug . '" no tiene configurado el campo erp_api_url. '
                . 'Cargalo desde el módulo de Demos antes de actualizar.'
            );
        }

        $resolver = new ClientEmpresaApiUrlResolver();
        return $resolver->normalize_demo_api_base_url($url, $this->demo_hosting_type());
    }

    /**
     * Deja en el log a qué servidor y a qué rutas va esta corrida, antes de la primera etapa.
     *
     * No falla nunca: si las rutas no se pueden resolver, lo deja escrito y sigue — la etapa que
     * las necesite va a tirar con su propio mensaje. Un log de diagnóstico que aborte el pipeline
     * sería peor que no tenerlo.
     *
     * @return void
     */
    private function log_destino(): void
    {
        $hosting = $this->demo_hosting_type();
        $destino = $hosting === 'vps' ? 'VPS' : 'hosting compartido';

        try {
            $this->append_log(
                '[destino] Esta demo está en ' . $destino . ' (credencial SSH: '
                . $this->demo_credential_type() . ', host: ' . $this->credential->host . ')'
            );
            $this->append_log('[destino] API: ' . $this->demo_api_path());
            $this->append_log('[destino] SPA: ' . $this->demo_spa_path());
        } catch (\Throwable $e) {
            $this->append_log('[destino] No se pudieron resolver las rutas: ' . $e->getMessage());
        }
    }

    /**
     * Tipo de hosting del ERP de esta demo ('shared_hosting' | 'vps'), vía DemoPathResolver.
     *
     * Con la demo sin cargar (DemoUpdate huérfano) devuelve 'shared_hosting': es el
     * comportamiento que tenía el service cableado, y perder la relación no puede ser motivo para
     * elegir el camino nuevo.
     *
     * @return string
     */
    private function demo_hosting_type(): string
    {
        if (! $this->demo instanceof Demo) {
            return 'shared_hosting';
        }

        $resolver = new DemoPathResolver();

        return $resolver->hosting_type($this->demo);
    }

    /**
     * Genera el contenido del .env para el SPA apuntando a la demo.
     *
     * @return string
     */
    private function build_demo_spa_env_content(): string
    {
        // API URL ya normalizada (con /public agregado si corresponde, idempotente).
        $api_url = $this->demo_api_base_url();

        // 🔴 `VUE_APP_APP_URL` normalizada (17/8/2026): queda compilada DENTRO del bundle, así que
        // una URL sin esquema no la corrige nadie después — hay que rehacer el build.
        //
        // Y ojo con la asimetría de dos líneas más arriba: `$api_url` NO pasa por acá a propósito.
        // `demo_api_base_url()` deja crudo el valor no absoluto porque `step_verify_demo()` usa
        // justamente "esta URL no es absoluta" para reconocer una demo local y saltearse los
        // chequeos HTTP contra el hosting. Agregarle esquema a `erp_api_url` activaría tráfico de
        // red real en el entorno de desarrollo.
        $spa_url = DemoUrlNormalizer::base($this->demo->erp_spa_url);

        // Guarda la URL exacta que se va a escribir como VUE_APP_API_URL: es el único lugar
        // donde se asigna, y es la cadena que step_verify_demo() debe usar para verificar
        // (no una URL re-resuelta desde $this->demo->erp_api_url).
        $this->compiled_api_url = $api_url;

        // Defaults fijos del build de empresa-spa: los MISMOS que usan InstallationService y
        // DeploymentService para cualquier cliente real (config/services.php → spa_build_env).
        //
        // Hasta el 25/8/2026 acá se copiaba a mano una sola variable de ese array
        // (VUE_APP_GOOGLE_SEARCH_API_KEY) para no cambiarle el comportamiento a la demo. El costo
        // de esa asimetría fue que la demo se compilaba sin VUE_APP_HAS_EXTRA_CONFIG y sin otras
        // diez variables, y por eso "Configuración online" no aparecía en su barra de navegación.
        // Lucas pidió alinear el build de la demo con el de un cliente: una sola fuente de
        // verdad, y lo que se agregue mañana a spa_build_env llega a la demo sin tocar esta clase.
        //
        // 🔴 El orden importa: el array fijo va PRIMERO, para que aporte defaults, y lo que se
        // calcula en runtime para ESTA demo lo pisa abajo. (Hoy no hay colisión de claves —
        // spa_build_env no trae ninguna de las cuatro de abajo—, pero si alguna vez la hubiera,
        // la demo concreta tiene que ganar, no el default.)
        $env_vars      = [];
        $spa_build_env = config('services.deploy.spa_build_env', []);
        if (is_array($spa_build_env)) {
            foreach ($spa_build_env as $env_key => $env_value) {
                $env_vars[(string) $env_key] = trim((string) $env_value);
            }
        }

        // Específico de esta demo: pisa cualquier default homónimo del array de arriba.
        $env_vars['VUE_APP_API_URL']        = $api_url;
        $env_vars['VUE_APP_APP_URL']        = $spa_url;
        $env_vars['VUE_APP_PUSHER_KEY']     = trim((string) config('services.deploy.spa_pusher_key', ''));
        $env_vars['VUE_APP_PUSHER_CLUSTER'] = trim(
            (string) config('services.deploy.spa_pusher_cluster', 'sa1')
        );

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
