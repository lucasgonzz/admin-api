<?php

namespace App\Services\Concerns;

use App\Models\ClientInstallation;
use App\Services\ClientApiPathResolver;
use App\Services\HostingProvisioningService;
use App\Services\HostingProvisioningStructure;

/**
 * Las etapas de aprovisionamiento del hosting —y los ajustes propios del VPS— para
 * InstallationService.
 *
 * 🔴 Por qué es un trait y no métodos sueltos adentro de InstallationService. La regla R1 del plan
 * (§9) le pone a esa clase un techo de 2350 líneas, y estaba en 2222 antes de esta misión: los seis
 * pasos con sus docblocks no entraban. La acción prescrita en la tabla de §9 para ese caso es
 * exactamente esta —"sacar los step_provision_* a un trait InstallationProvisioningSteps (mueve
 * líneas, no las agrega)"—, así que se aplicó antes de commitear y no después.
 *
 * 🔴 Y por eso mismo bajaron acá, en U9, el chown a los usuarios de CloudPanel y el composer install
 * del servidor del cliente: son las dos piezas de la instalación que existen SOLO porque el destino
 * puede ser un VPS, y con ellas adentro InstallationService cruzaba R1 otra vez (2386). Mismo freno,
 * misma acción prescrita, mismo trait. Todo lo demás de la instalación siguió donde estaba.
 *
 * Lo que hay acá adentro es delegación y nada más: cada paso instancia el proveedor y le pasa un
 * closure de log. La lógica del aprovisionamiento vive en HostingProvisioningService y sus
 * subclases, que no saben nada de DeploymentLog ni de SSH.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, promoción en
 * constructor, `readonly`, `enum`, atributos, `mixed` ni `never`. (Ojo: los traits de PHP 7.4
 * tampoco admiten constantes — eso llegó en 8.2 — así que las listas de pasos son literales.)
 */
trait InstallationProvisioningSteps
{
    /**
     * Proveedor de aprovisionamiento de esta corrida, instanciado perezosamente.
     *
     * @var HostingProvisioningService|null
     */
    private $provisioner = null;

    /**
     * Suma las etapas de aprovisionamiento a un pipeline, si la fila las pide.
     *
     * 🔴 Los cuatro primeros van al INICIO y eso no es orden estético: provision_check es el paso
     * que puede pasar las ClientApi a hosting_type='vps', y compile_spa compila el bundle con
     * build_api_url_for_env(), que le agrega '/public' a la URL cuando el hosting es compartido. Si
     * el flip llegara después de compile_spa, el SPA quedaría pidiendo
     * https://api-x.comerciocity.com/public contra un VPS cuyo docroot YA es public/ → 404 en todo
     * el sistema, y recién se descubre con el cliente adentro.
     *
     * Los dos últimos van al FINAL, y también por motivos medidos (§3.1): el cron necesita el
     * Kernel.php que recién sube upload_api, y un cron creado en el minuto 0 correría artisan contra
     * un directorio sin vendor/ una vez por minuto durante los ~15 minutos de la instalación, contra
     * un servidor que ya está a load 14. El certificado necesita que el A record haya propagado, y
     * poniéndolo último los uploads SON la espera de propagación, gratis.
     *
     * @param  array<int, string>  $steps  Pipeline base (real o esqueleto).
     * @return array<int, string>
     */
    private function build_steps_con_aprovisionamiento(array $steps): array
    {
        if (trim((string) $this->installation->provision_hosting_type) === '') {
            return $steps;
        }

        $steps = array_merge(
            ['provision_check', 'provision_sites', 'provision_dns', 'provision_db'],
            $steps
        );

        /*
         * El cron y el certificado son solo de la fila real. El esqueleto no tiene vendor/ ni
         * sistema: un schedule:run ahí escupe un fatal de PHP una vez por minuto, para siempre.
         */
        if ($this->installation->kind === ClientInstallation::KIND_COMPLETA) {
            $steps = array_merge($steps, ['provision_cron', 'provision_ssl']);
        }

        return $steps;
    }

    /**
     * Etapa: preflight del hosting. No escribe nada del otro lado.
     *
     * @return void
     */
    private function step_provision_check(): void
    {
        $this->provisioner()->provision_check();

        /*
         * refresh() defensivo: hasta el 31/8/2026 este paso era el que marcaba las ClientApi como
         * hosting_type='vps' y sin refrescar acá el flip existía en la base y no en memoria. El flip
         * se mudó al final de provision_sites (el porqué está en VpsSiteProvisioner::provision_sites)
         * y el refresh que importa se mudó con él, a step_provision_sites(). Este queda porque el
         * preflight igual puede correr después de que otra fila del grupo haya escrito, y recargar
         * una fila que no cambió no cuesta nada.
         */
        $this->target_api->refresh();
    }

    /**
     * Etapa: los 4 subdominios/sitios del cliente.
     *
     * @return void
     */
    private function step_provision_sites(): void
    {
        $this->provisioner()->provision_sites();

        /*
         * 🔴 refresh() obligatorio: en la rama VPS este paso es el que marca las ClientApi como
         * hosting_type='vps', y lo escribe por OTRAS instancias del modelo (las que devuelve
         * HostingProvisioningStructure). La que tiene esta clase la cargó el constructor, y es la
         * que después leen compile_spa —para decidir si la URL de la API lleva '/public'— y
         * get_api_path(). Sin este refresh el flip existe en la base y no en memoria, que es la peor
         * de las dos combinaciones: el pipeline sigue creyendo que instala en hosting compartido y
         * el bundle sale con una URL que en el VPS da 404 en todo.
         */
        $this->target_api->refresh();
    }

    /**
     * Etapa: el DNS de los 4 nombres.
     *
     * @return void
     */
    private function step_provision_dns(): void
    {
        $this->provisioner()->provision_dns();
    }

    /**
     * Etapa: la base de datos del cliente (una sola para las dos instancias).
     *
     * @return void
     */
    private function step_provision_db(): void
    {
        $this->provisioner()->provision_db();

        /* Los secretos los escribió el proveedor por otras instancias del modelo: sin refresh,
         * step_write_env leería provisioning_secrets en null y escribiría un .env sin base. */
        $this->target_api->refresh();

        $this->log('provision_db', $this->provisioner()->result()->resumen());
    }

    /**
     * Etapa: el cron de la instancia. Va al final del pipeline, después de finalize_api.
     *
     * 🔴 El comando se DECIDE VERIFICANDO, no asumiendo: se cuentan las apariciones de
     * 'stop-when-empty' en el Kernel.php que upload_api acaba de subir. Con Kernel nuevo va
     * `schedule:run` sin flock (el propio Kernel usa withoutOverlapping(75)); con Kernel viejo va
     * `queue:work --stop-when-empty` con flock obligatorio. Ese archivo NO existe en el servidor
     * hasta que upload_api corre, y por eso este paso no puede ir al inicio.
     *
     * 🔴 Y ese grep decide SOLO la rama del hosting compartido. Desde el commit del 26/8/2026 de
     * empresa-api, el `queue:work --stop-when-empty` del scheduler está envuelto en un
     * `if (! config('app.VPS'))`: en un VPS el grep sigue dando > 0 y sin embargo el scheduler no
     * programa la cola. El proveedor del VPS ignora este booleano a propósito y crea el supervisor
     * siempre — el porqué completo está en VpsDatabaseProvisioner::provision_cron().
     *
     * @return void
     */
    private function step_provision_cron(): void
    {
        if ($this->installation->kind !== ClientInstallation::KIND_COMPLETA) {
            $this->log('provision_cron', 'Esta fila no es la instalación real: no se crea ningún cron.');

            return;
        }

        $api_path = $this->get_api_path();
        $this->reconnect_hosting_ssh();

        /*
         * `|| true` para que un Kernel.php ausente devuelva 0 en vez de romper la etapa.
         *
         * 🔴 escape_remote_arg() y NO escapeshellarg(): el comando lo ejecuta el `sh` del servidor
         * del cliente, no el shell de esta máquina. escapeshellarg() escapa según el sistema donde
         * corre PHP, y como admin-api también corre local sobre WAMP, en Windows emite comillas
         * DOBLES — adentro de las cuales el `sh` remoto expande `$`, backticks y barras. El
         * $api_path lleva client_apis.path pegado, que es texto libre del CRUD y NO pasa por las
         * cinco guardas de HostingProvisioningStructure (esas validan el slug derivado del spa_url,
         * que es otro dato). Un path con `$(...)` se ejecutaría en el hosting del cliente. La
         * explicación larga está en EnvSshService::escape_remote_arg().
         */
        $salida = $this->exec_hosting_ssh(
            'provision_cron',
            'grep -c stop-when-empty ' . $this->escape_remote_arg($api_path . '/app/Console/Kernel.php') . ' || true',
            false
        );

        $this->provisioner()->provision_cron($api_path, ((int) trim($salida)) > 0);
    }

    /**
     * Etapa: el certificado. Último de todo el pipeline.
     *
     * @return void
     */
    private function step_provision_ssl(): void
    {
        $this->provisioner()->provision_ssl();
    }

    /**
     * Le devuelve al usuario del sitio de CloudPanel los archivos que subió root. No-op en compartido.
     *
     * 🔴 Sin esto la instalación en VPS queda "perfecta" y no anda: php-fpm corre como el usuario del
     * sitio (§F6 del informe de migración del 26/8/2026) y todo lo que dejó el pipeline es de root,
     * así que el sistema del cliente no puede escribir en storage/ — ni logs, ni caché, ni sesiones,
     * ni un adjunto. Y no lo denuncia ninguna verificación: los archivos ESTÁN, solo que no son suyos.
     *
     * @param  string  $api_path
     * @param  string  $step
     * @return void
     */
    private function chown_api_dir_en_vps(string $api_path, string $step): void
    {
        $comando = $this->build_vps_chown_command($api_path);
        if ($comando === '') {
            return;
        }

        $this->log($step, 'Devolviéndole los archivos al usuario del sitio de CloudPanel...');
        $this->reconnect_hosting_ssh();
        $this->exec_hosting_ssh($step, $comando, true, true);
        $this->log($step, 'Archivos de la API con el dueño correcto en el VPS', 'success');
    }

    /**
     * Comando de chown para el VPS, o '' si la API destino no está en un VPS.
     *
     * Separado de quien lo ejecuta —igual que build_skeleton_verify_command()— porque es un string y
     * es lo único de esta parte que un test puede fijar sin un servidor del otro lado.
     *
     * @param  string  $api_path
     * @return string
     */
    private function build_vps_chown_command(string $api_path): string
    {
        $resolver = new ClientApiPathResolver();
        $usuario  = $resolver->vps_site_user($this->target_api);

        if ($usuario === '') {
            return '';
        }

        /*
         * 🔴 escape_remote_arg() y NO escapeshellarg(), por el mismo motivo que el grep del cron —
         * y acá pesa más: la credencial SSH del VPS es ROOT. El usuario y el path se arman con
         * client_apis.vps_path, que es texto libre del CRUD del admin, no el slug validado. Con
         * admin-api corriendo sobre WAMP, un vps_path con `$(...)` sería ejecución de comandos como
         * root en el VPS donde viven todos los clientes migrados.
         */
        return 'chown -R ' . $this->escape_remote_arg($usuario . ':' . $usuario) . ' '
            . $this->escape_remote_arg($api_path) . ' 2>&1';
    }

    /**
     * El composer install que corre en el servidor del CLIENTE, con el envoltorio que le toque.
     *
     * 🔴 El flag salía hardcodeado en `false` = "no es VPS". Con la API destino en VPS eso mandaba
     * el comando pelado por una sesión SSH no interactiva de Ubuntu, donde `composer` puede no estar
     * en el PATH: el mismo motivo por el que el VPS de builds usa el envoltorio desde siempre.
     * DeploymentService hace la misma distinción con ese mismo parámetro.
     *
     * Existe como método propio —y no como una expresión adentro de step_upload_api()— porque es la
     * única forma de fijar por test qué comando sale para cada hosting sin un servidor del otro lado.
     *
     * @param  string  $api_path
     * @return string
     */
    private function build_hosting_composer_install_command(string $api_path): string
    {
        return $this->build_composer_install_command(
            $api_path,
            $this->get_hosting_credential_type() === 'vps'
        );
    }

    /**
     * Proveedor de aprovisionamiento de esta fila, con el log enchufado al panel de operaciones.
     *
     * @return HostingProvisioningService
     * @throws \RuntimeException Si la fila no pide aprovisionar.
     */
    private function provisioner(): HostingProvisioningService
    {
        if ($this->provisioner === null) {
            $this->provisioner = HostingProvisioningService::para(
                $this->installation,
                $this->target_api,
                function ($step, $linea, $level) {
                    $this->log($step, $linea, $level);
                }
            );
        }

        return $this->provisioner;
    }

    /**
     * Mergea sobre el .env las tres DB_* que completó el aprovisionamiento.
     *
     * Va después de las variables manuales del operador y antes de APP_URL, a propósito: estas tres
     * claves son las de ClientInstallation::CLAVES_ENV_APROVISIONADAS, que start() deja de exigirle
     * al operador justamente porque las completa el pipeline. Si se aplicaran antes de las manuales,
     * un valor viejo cargado a mano en env_manual_values pisaría la base recién creada.
     *
     * 🔴 Falta un secreto → FALLA la etapa. Escribir un .env con DB_DATABASE vacío deja un sistema
     * instalado que no bootea, y el error aparece recién cuando el cliente entra.
     *
     * @param  array<string, string>  $vars_to_write
     * @return array<string, string>
     * @throws \RuntimeException
     */
    private function merge_env_del_aprovisionamiento(array $vars_to_write): array
    {
        if (trim((string) $this->installation->provision_hosting_type) === '') {
            return $vars_to_write;
        }

        /* El paso provision_db escribió por otras instancias del modelo. */
        $this->target_api->refresh();
        $secretos = $this->target_api->provisioning_secrets;

        /* Mismo orden y mismas claves que ClientInstallation::CLAVES_ENV_APROVISIONADAS. */
        $mapa = ['DB_DATABASE' => 'db_name', 'DB_USERNAME' => 'db_user', 'DB_PASSWORD' => 'db_password'];

        foreach ($mapa as $clave_env => $clave_secreta) {
            if (! is_array($secretos) || ! isset($secretos[$clave_secreta])
                || (string) $secretos[$clave_secreta] === '') {
                throw new \RuntimeException(
                    'La instalación pide aprovisionar el hosting pero la ClientApi destino no tiene '
                    . 'guardado el secreto "' . $clave_secreta . '". Sin él el .env quedaría sin '
                    . $clave_env . ' y el sistema no bootea. Revisá el paso provision_db.'
                );
            }

            $vars_to_write[$clave_env] = (string) $secretos[$clave_secreta];
        }

        $this->log('write_env', 'Las DB_* salen del aprovisionamiento (' . $secretos['db_name'] . ').');

        return $vars_to_write;
    }

    /**
     * Las variables que un .env de VPS lleva y uno de hosting compartido NO: la bandera VPS y los
     * prefijos de Redis (§3.3).
     *
     * 🔴 `VPS=true` no es cosmética, y su ausencia es un fallo SILENCIOSO en el sistema del cliente.
     * Verificado el 31/8/2026 contra `origin/develop` de empresa-api, donde esa misma variable
     * decide dos cosas distintas:
     *
     *   1. `config/filesystems.php:44` arma la URL del disco `public` agregándole '/public' salvo
     *      que `env('VPS')` sea verdadera. En el VPS el docroot YA es empresa-api/public, así que
     *      sin la variable toda imagen y todo adjunto del cliente sale como
     *      `.../public/storage/...` → 404 en TODOS los archivos.
     *   2. `app/Console/Kernel.php` (commit del 26/8/2026) envuelve el `queue:work
     *      --stop-when-empty` del scheduler en un `if (! config('app.VPS'))`, porque en el VPS la
     *      cola la maneja supervisor. De ahí sale el hallazgo B y por eso el supervisor del VPS ya
     *      no depende del grep: ver VpsDatabaseProvisioner::configurar_supervisor().
     *
     * Los clientes ya migrados a mano (demo2, demo3, grupolimp) la tienen puesta a mano justamente
     * por (1) — §F5 del informe 20260826-plan-migracion-shared-a-vps.md.
     *
     * 🔴 Y en hosting compartido NO se escribe, ni siquiera como `false`. Hoy no existe en el .env
     * de ninguno de los ~40 clientes del compartido y el default de `env('VPS')` ya es false: el
     * comportamiento sería idéntico y el precio, tocarle el .env a 40 clientes sin motivo.
     *
     * Los prefijos de Redis van por el mismo camino porque tienen la misma condición: el VPS tiene
     * UN SOLO Redis para todos los clientes. Hoy la plantilla trae CACHE_DRIVER=file y
     * QUEUE_CONNECTION=database, así que la colisión no aplica — pero el día que alguien ponga redis
     * en un cliente, sin prefijo las claves de todos los clientes viven en el mismo keyspace: una
     * sesión de un cliente pisa la de otro y un `cache:clear` los vacía a todos.
     *
     * El prefijo lleva un guion bajo al final para que <slug> y <slug>2 no se puedan pisar: sin él,
     * 'lacava' + '2:foo' y 'lacava2' + ':foo' son la misma clave.
     *
     * @param  array<string, string>  $vars_to_write
     * @return array<string, string>
     * @throws \RuntimeException
     */
    private function aplicar_variables_del_vps(array $vars_to_write): array
    {
        if (trim((string) $this->target_api->hosting_type) !== 'vps') {
            return $vars_to_write;
        }

        /* La bandera que leen config/filesystems.php y config/app.php de empresa-api. */
        $vars_to_write['VPS'] = 'true';

        $slug = HostingProvisioningStructure::label_de_url((string) $this->target_api->spa_url);

        $vars_to_write['CACHE_PREFIX'] = $slug === '' ? '' : $slug . '_';
        $vars_to_write['REDIS_PREFIX'] = $slug === '' ? '' : $slug . '_';

        $usa_redis = (isset($vars_to_write['CACHE_DRIVER']) && $vars_to_write['CACHE_DRIVER'] === 'redis')
            || (isset($vars_to_write['QUEUE_CONNECTION']) && $vars_to_write['QUEUE_CONNECTION'] === 'redis');

        /* Mecánica, sin criterio: si va a usar Redis y no hay prefijo, la etapa falla. */
        if ($usa_redis && ($vars_to_write['CACHE_PREFIX'] === '' || $vars_to_write['REDIS_PREFIX'] === '')) {
            throw new \RuntimeException(
                'La API destino está en VPS y el .env usa Redis, pero no se pudo derivar el prefijo '
                . 'del spa_url ("' . $this->target_api->spa_url . '"). El VPS tiene un solo Redis '
                . 'para todos los clientes: sin prefijo, las claves de este cliente se mezclan con '
                . 'las de los demás. Corregí el spa_url de la ClientApi antes de instalar.'
            );
        }

        return $vars_to_write;
    }
}
