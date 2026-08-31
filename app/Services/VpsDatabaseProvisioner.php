<?php

namespace App\Services;

/**
 * La mitad del aprovisionamiento del VPS que habla de DATOS Y PROCESOS: la base de datos del
 * cliente y el cron (con su supervisor, cuando hace falta).
 *
 * Partido de VpsSiteProvisioner por la regla R2 de §9 (450 líneas por archivo nuevo de
 * app/Services/). La cadena completa es
 * HostingProvisioningService ← VpsSiteProvisioner ← VpsDatabaseProvisioner ← VpsHostingProvisioning.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, promoción en
 * constructor, `readonly`, `enum`, atributos, `mixed` ni `never`.
 */
abstract class VpsDatabaseProvisioner extends VpsSiteProvisioner
{
    /**
     * Mensaje para la base que ya existe y de la que no tenemos la contraseña.
     *
     * @var string
     */
    const MENSAJE_BASE_SIN_SECRETO = 'La base %s ya existe en el VPS y no tengo su contraseña: la '
        . 'creó otra corrida y no quedó guardada. No la reuso a ciegas porque el .env quedaría con '
        . 'una contraseña que no es y el sistema no bootearía. Resolvelo a mano en el VPS —reseteá '
        . 'la contraseña de ese usuario con clpctl y cargá DB_DATABASE/DB_USERNAME/DB_PASSWORD '
        . 'destildando el aprovisionamiento—, o borrá la base si es un resto de una prueba.';

    /**
     * Crea la base del cliente (una sola para las dos instancias) y persiste su contraseña.
     *
     * 🔴 SIN el prefijo u767360347_ (§F3 del informe de migración): ese prefijo se lo impone
     * Hostinger a las bases de su hosting compartido, y acá el MySQL es del VPS. prefijo_de_base()
     * devuelve '' en esta rama, así que el nombre es el slug pelado.
     *
     * La existencia NO se consulta antes: clpctl no tiene un `db:list` con contrato estable, así que
     * se intenta crear y se clasifica el error, que es el mismo criterio que usan los sitios. Un
     * "ya existe" no se adivina nunca: si el mensaje no se puede clasificar, la etapa falla.
     *
     * @return void
     * @throws \Throwable
     */
    public function provision_db(): void
    {
        $nombre   = $this->prefijo_de_base() . $this->slug();
        $password = $this->generar_password();

        $comando = 'clpctl db:add'
            . ' --domainName=' . escapeshellarg('api-' . $this->slug() . '.' . $this->dominio())
            . ' --databaseName=' . escapeshellarg($nombre)
            . ' --databaseUserName=' . escapeshellarg($nombre)
            . ' --databaseUserPassword=' . escapeshellarg($password);

        $this->log('provision_db', 'Creando la base ' . $nombre . ' en el VPS...');

        try {
            $this->vps('provision_db')->run($comando, [$password]);
        } catch (\Throwable $excepcion) {
            $this->reusar_base_existente($nombre, $excepcion);

            return;
        }

        /*
         * 🔴 Persistencia INMEDIATA, en su propia escritura y antes de loguear el éxito: si el
         * proceso muere acá abajo, la base queda creada con una contraseña que nadie conoce.
         */
        $this->persistir_secretos([
            'db_name'     => $nombre,
            'db_user'     => $nombre,
            'db_password' => $password,
        ]);

        $this->result->creado('base', $nombre);
        $this->log('provision_db', 'Base ' . $nombre . ' creada y credenciales guardadas cifradas.', 'success');
    }

    /**
     * En el VPS el cron es SIEMPRE `schedule:run` y el worker de supervisor se crea SIEMPRE.
     *
     * 🔴 LEER ESTO ANTES DE "UNIFICAR" ESTA RAMA CON LA DEL HOSTING COMPARTIDO.
     *
     * Hasta el 31/8/2026 las dos ramas las decidía el mismo `grep -c stop-when-empty Kernel.php`, y
     * esa regla quedó VENCIDA para el VPS con el commit del 26/8/2026 de empresa-api (verificable
     * con `git show origin/develop:app/Console/Kernel.php`): desde ese commit el
     * `queue:work --stop-when-empty` del scheduler está envuelto en un `if (! config('app.VPS'))`.
     *
     * O sea que en un VPS —donde el .env lleva VPS=true, que es lo que escribe
     * InstallationProvisioningSteps::aplicar_variables_del_vps()— pasa esto:
     *
     *   • el grep SIGUE dando > 0, porque la cadena está en el archivo, solo que adentro del `if`;
     *   • el scheduler NO programa la cola;
     *   • el código concluía "Kernel optimizado" y NO creaba el worker de supervisor;
     *   • resultado: nadie procesaba la cola del cliente. Sin error, sin aviso, sin una línea en el
     *     log. El modo de falla más caro de los cuatro que encontró el chequeo del 31/8.
     *
     * La regla correcta ya no depende del grep sino del hosting:
     *
     *   | Hosting     | VPS en el .env | ¿El schedule programa la cola? | Qué hace falta                |
     *   |-------------|----------------|--------------------------------|-------------------------------|
     *   | compartido  | no está        | sí, si el Kernel es nuevo      | cron único (lo decide el grep)|
     *   | VPS         | true           | NO, nunca                      | schedule:run + supervisor     |
     *
     * En el compartido el grep sigue siendo la regla correcta y NO se toca: eso vive en la clase
     * base (HostingProvisioningService::comando_de_cron) y en SharedHostingProvisioning.
     *
     * @param  string  $api_path
     * @param  bool    $kernel_optimizado  Lo ignora esta rama, a propósito. Ver arriba.
     * @return void
     * @throws \RuntimeException
     */
    public function provision_cron(string $api_path, bool $kernel_optimizado): void
    {
        $usuario = $this->usuario_de_la_instancia();
        $linea   = '* * * * * ' . $this->comando_de_cron($api_path, $kernel_optimizado);

        $this->log('provision_cron', 'Dejando el cron de ' . $usuario . ': ' . $linea);

        /*
         * 🔴 Idempotente por construcción y no por consulta previa: se pregunta y se escribe en el
         * MISMO comando. Con dos comandos (leer, decidir, escribir) hay una ventana en la que otra
         * corrida escribe entremedio y el segundo `crontab -` pisa lo que la primera puso: el
         * crontab se reemplaza entero, no se agrega una línea. Acá el `||` hace que el pipe ni
         * siquiera se evalúe cuando la línea ya está.
         */
        $comando = 'crontab -u ' . escapeshellarg($usuario) . ' -l 2>/dev/null | grep -qF '
            . escapeshellarg($linea)
            . ' || ( crontab -u ' . escapeshellarg($usuario) . ' -l 2>/dev/null; echo '
            . escapeshellarg($linea) . ' ) | crontab -u ' . escapeshellarg($usuario) . ' -';

        $this->vps('provision_cron')->run($comando);

        $this->result->creado('cron', $usuario);

        $this->configurar_supervisor($api_path, $usuario);

        $this->log('provision_cron', 'El cron de ' . $usuario . ' está.', 'success');
    }

    /**
     * El comando del cron en el VPS: SIEMPRE `schedule:run`, sin mirar el grep.
     *
     * 🔴 Es el otro lado del mismo hallazgo que documenta provision_cron(). En el VPS la cola la
     * procesa el worker de supervisor, que ahora se crea siempre. Si acá saliera el
     * `flock ... queue:work --stop-when-empty` de la rama "Kernel viejo", quedarían DOS procesos
     * tomando de la misma cola —el cron y el worker—, que es exactamente el problema que el
     * `withoutOverlapping(75)` del Kernel viene a evitar (§2.2 del informe de migración).
     *
     * `schedule:run` hace falta igual, con Kernel nuevo o viejo: el scheduler programa el resto de
     * las tareas de negocio del cliente (sincronizaciones, embeddings, sugerencias), que no tienen
     * nada que ver con la cola.
     *
     * En el hosting compartido esto NO se toca: ahí manda el `comando_de_cron()` de la clase base,
     * con las dos ramas que decide el grep.
     *
     * @param  string  $api_path
     * @param  bool    $kernel_optimizado  Ignorado en el VPS, a propósito.
     * @return string
     */
    public function comando_de_cron(string $api_path, bool $kernel_optimizado): string
    {
        return parent::comando_de_cron($api_path, true);
    }

    /**
     * Worker de supervisor: en el VPS, SIEMPRE.
     *
     * 🔴 ACÁ ES DONDE ALGUIEN VA A QUERER VOLVER A METER EL GREP. No lo hagas sin leer el bloque de
     * provision_cron(): desde el commit del 26/8/2026 de empresa-api el `queue:work
     * --stop-when-empty` del scheduler vive adentro de un `if (! config('app.VPS'))`, así que en un
     * VPS —donde el .env lleva VPS=true— el scheduler NO programa la cola por más nuevo que sea el
     * Kernel. El grep, en cambio, sigue contando esa cadena porque el texto está en el archivo.
     * Condicionar el supervisor a ese grep deja la cola del cliente sin procesar, en silencio.
     *
     * Las dos combinaciones posibles terminan en el mismo lugar:
     *   • Kernel nuevo + VPS=true → el `if` deja el queue:work afuera → hace falta el supervisor.
     *   • Kernel viejo           → el schedule nunca programó la cola → hace falta el supervisor.
     *
     * No hay competencia con el scheduler en ninguna de las dos, y el cron del VPS es siempre
     * `schedule:run` (ver comando_de_cron()).
     *
     * ⚠️ Y es idempotente: `cat >` reescribe el .conf con el mismo contenido y el `reread`/`update`
     * de supervisor no reinicia un programa cuya configuración no cambió. Un reintento de la
     * instalación no deja dos workers.
     *
     * @param  string  $api_path
     * @param  string  $usuario
     * @return void
     * @throws \RuntimeException
     */
    private function configurar_supervisor(string $api_path, string $usuario): void
    {
        $programa = $usuario . '-queue';
        $ruta     = '/etc/supervisor/conf.d/' . $programa . '.conf';
        $runner   = $this->vps('provision_cron');

        $this->log(
            'provision_cron',
            'En el VPS la cola la procesa supervisor y no el scheduler (el Kernel.php de empresa-api '
                . 'saltea el queue:work cuando VPS=true): se deja el worker ' . $programa . '.'
        );

        /*
         * Heredoc con el delimitador entre comillas simples: adentro el shell no expande nada, así
         * que el bloque viaja tal cual sin una sola capa de escapado que pueda salir mal.
         */
        $runner->run(
            'cat > ' . escapeshellarg($ruta) . " <<'SUPERVISOR_EOF'\n"
            . $this->bloque_de_supervisor($programa, $api_path, $usuario) . "\nSUPERVISOR_EOF"
        );

        $runner->run('supervisorctl reread');
        $runner->run('supervisorctl update');

        /* `update` ya arranca los programas nuevos: un start redundante devuelve error y no importa. */
        $runner->run('supervisorctl start ' . escapeshellarg($programa . ':*'), [], false);

        $this->result->creado('supervisor', $programa);
    }

    /**
     * El bloque de configuración del worker (§F8 del informe de migración).
     *
     * @param  string  $programa
     * @param  string  $api_path
     * @param  string  $usuario
     * @return string
     */
    private function bloque_de_supervisor(string $programa, string $api_path, string $usuario): string
    {
        $raiz = rtrim($api_path, '/');

        return "[program:" . $programa . "]\n"
            . "process_name=%(program_name)s_%(process_num)02d\n"
            . "command=/usr/bin/php " . $raiz . "/artisan queue:work --sleep=3 --tries=3 --max-time=3600\n"
            . "directory=" . $raiz . "\n"
            . "user=" . $usuario . "\n"
            . "numprocs=1\n"
            . "autostart=true\n"
            . "autorestart=true\n"
            . "stopasgroup=true\n"
            . "killasgroup=true\n"
            . "redirect_stderr=true\n"
            . "stdout_logfile=" . $raiz . "/storage/logs/queue-worker.log\n"
            . "stopwaitsecs=3600";
    }

    /**
     * La base ya estaba: se reusa si tenemos su contraseña guardada, y si no, se falla.
     *
     * @param  string      $nombre
     * @param  \Throwable  $excepcion
     * @return void
     * @throws \Throwable
     */
    private function reusar_base_existente(string $nombre, \Throwable $excepcion): void
    {
        if ($this->hostinger()->clasificar_error($excepcion) !== HostingerApiClient::CLASIFICACION_YA_EXISTE) {
            throw $excepcion;
        }

        $secretos = $this->secretos_guardados();

        if (! isset($secretos['db_password']) || (string) $secretos['db_password'] === '') {
            throw new \RuntimeException(sprintf(self::MENSAJE_BASE_SIN_SECRETO, $nombre));
        }

        $this->result->ya_existia('base', $nombre);
        $this->result->agregar_credenciales($secretos);
        $this->log(
            'provision_db',
            'La base ' . $nombre . ' ya existía y tengo su contraseña guardada: se reusa.',
            'warning'
        );
    }
}
