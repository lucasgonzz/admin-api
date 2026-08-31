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
     * Crea el cron de la instancia y, si el Kernel es viejo, el worker de supervisor.
     *
     * @param  string  $api_path
     * @param  bool    $kernel_optimizado
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

        $this->configurar_supervisor($api_path, $kernel_optimizado, $usuario);

        $this->log('provision_cron', 'El cron de ' . $usuario . ' está.', 'success');
    }

    /**
     * Worker de supervisor: SOLO si el Kernel.php del cliente es el viejo.
     *
     * 🔴 Es una desviación del enunciado y está fundada en §2.2 del informe de migración. El
     * Kernel.php nuevo programa `queue:work --stop-when-empty` ADENTRO del schedule: con él, un
     * worker de supervisor compite por los mismos jobs que el que dispara el scheduler, y dos
     * procesos tomando de la misma cola es exactamente el problema que el `withoutOverlapping(75)`
     * del Kernel viene a evitar. Con Kernel nuevo —el caso de toda instalación desde cero, que
     * siempre trae la última versión— el `schedule:run` alcanza y el supervisor sobra.
     *
     * Con Kernel viejo, en cambio, el schedule no dispara ningún worker: ahí el supervisor es el que
     * procesa la cola de verdad y el cron con flock es el respaldo que la vacía si el worker se cae.
     *
     * La regla es mecánica y es el MISMO grep que decide las dos ramas de los dos hostings.
     *
     * @param  string  $api_path
     * @param  bool    $kernel_optimizado
     * @param  string  $usuario
     * @return void
     * @throws \RuntimeException
     */
    private function configurar_supervisor(string $api_path, bool $kernel_optimizado, string $usuario): void
    {
        if ($kernel_optimizado) {
            $this->log(
                'provision_cron',
                'El Kernel.php ya programa queue:work --stop-when-empty adentro del schedule: NO se '
                    . 'crea worker de supervisor, competiría con el que dispara el propio scheduler.'
            );

            return;
        }

        $programa = $usuario . '-queue';
        $ruta     = '/etc/supervisor/conf.d/' . $programa . '.conf';
        $runner   = $this->vps('provision_cron');

        $this->log(
            'provision_cron',
            'El Kernel.php es el viejo (no programa queue:work): se crea el worker de supervisor '
                . $programa . '.',
            'warning'
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
