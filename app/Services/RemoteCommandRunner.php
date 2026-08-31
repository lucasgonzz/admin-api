<?php

namespace App\Services;

use App\Models\ClientSshCredential;
use phpseclib3\Net\SSH2;

/**
 * Ejecuta comandos por SSH contra una credencial dada, redactando los secretos ANTES de loguearlos.
 *
 * 🔴 POR QUÉ EXISTE Y POR QUÉ NO SE REUSA exec_ssh_session() DE InstallationService.
 *
 * Dos motivos, y los dos son la razón de ser de esta clase:
 *
 * 1. La redacción. exec_ssh_session() hace `$this->log($step, '$ ' . $command)` con el comando
 *    ENTERO (InstallationService.php:~1580). El aprovisionamiento del VPS ejecuta
 *    `clpctl site:add:php ... --siteUserPassword=<generada>` y `clpctl db:add ...
 *    --databaseUserPassword=<generada>`: con ese helper, la contraseña del sitio de CloudPanel y
 *    la de la base del cliente se imprimen en el panel de operaciones que Lucas comparte en
 *    pantalla y quedan escritas en claro en deployment_logs, para siempre. Acá cada string de
 *    $secretos se reemplaza por *** antes de que la línea llegue al log — y también en la salida
 *    del comando y en el mensaje de la excepción, que son los otros dos caminos por los que el
 *    mismo texto termina en la base (failure_reason de la instalación).
 *
 * 2. La testeabilidad. exec_ssh_session() agarra la sesión de una propiedad privada
 *    ($this->ssh / $this->build_ssh) que arma connect(): no hay forma de falsearla sin abrir un
 *    SSH de verdad contra el hosting de un cliente. Acá la credencial entra por constructor y la
 *    ejecución vive en un solo método protected —ejecutar()—, que es exactamente lo que
 *    sobreescribe RemoteCommandRunnerFake.
 *
 * ⚠️ Residual que esta clase NO puede tapar (§10.7 del plan): clpctl recibe la contraseña como
 * argumento, así que durante ~1 segundo es legible en /proc/<pid>/cmdline para cualquier usuario
 * del VPS. clpctl no la acepta por stdin. La redacción cubre el log, que es lo que persiste.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, promoción en
 * constructor, `readonly`, `enum`, atributos, `mixed` ni `never`.
 */
class RemoteCommandRunner
{
    /**
     * Con qué se reemplaza cada secreto en todo texto que se loguea.
     *
     * @var string
     */
    const REDACCION = '***';

    /**
     * Largo mínimo que tiene que tener un string para que se lo redacte.
     *
     * 🔴 Sin este piso, un secreto de un carácter —o una cadena vacía que se coló en la lista—
     * reemplazaría medio comando por *** y el log dejaría de servir para diagnosticar. Las
     * contraseñas que genera ProvisioningPasswordGenerator son de 24 caracteres, así que el piso
     * nunca las deja pasar.
     *
     * @var int
     */
    const LARGO_MINIMO_DE_SECRETO = 6;

    /**
     * Credencial contra la que se ejecuta todo.
     *
     * @var ClientSshCredential
     */
    private $credential;

    /**
     * Closure de log: function (string $step, string $linea, string $level): void. Puede no estar:
     * el runner también se usa fuera de una instalación (por ejemplo desde un comando de consola).
     *
     * @var \Closure|null
     */
    private $logger = null;

    /**
     * Etapa a la que se le atribuyen las líneas del panel de operaciones.
     *
     * @var string
     */
    private $step = 'remote';

    /**
     * Sesión SSH abierta, perezosa: no se conecta hasta el primer comando.
     *
     * @var SSH2|null
     */
    private $ssh = null;

    /**
     * @param  ClientSshCredential  $credential
     */
    public function __construct(ClientSshCredential $credential)
    {
        $this->credential = $credential;
    }

    /**
     * Enchufa el log del panel de operaciones.
     *
     * Entra por un setter y no por el constructor para que el constructor tenga UN solo argumento:
     * así el container puede construirlo con makeWith(['credential' => ...]) sin tener que resolver
     * un Closure, que no sabría de dónde sacar.
     *
     * @param  \Closure  $logger  function (string $step, string $linea, string $level): void.
     * @return $this
     */
    public function usar_logger(\Closure $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Etapa a la que se atribuyen las próximas líneas.
     *
     * @param  string  $step
     * @return $this
     */
    public function para_etapa(string $step): self
    {
        $this->step = $step;

        return $this;
    }

    /**
     * Ejecuta un comando y devuelve su salida CRUDA (sin redactar).
     *
     * La salida vuelve cruda a propósito: el llamador la necesita entera para decidir (un
     * `command -v clpctl` que devuelve la ruta, un `dig +short` que devuelve la IP). Lo que se
     * redacta es todo lo que se LOGUEA, que es lo único que persiste.
     *
     * @param  string                $command       Comando completo, ya escapado por el llamador.
     * @param  array<int, string>    $secretos      Strings a reemplazar por *** en todo lo logueado.
     * @param  bool                  $must_succeed  false = un exit distinto de 0 no rompe la etapa.
     * @return string  Salida cruda del comando.
     * @throws \RuntimeException Si el comando falla y $must_succeed.
     */
    public function run(string $command, array $secretos = [], bool $must_succeed = true): string
    {
        $this->registrar('$ ' . $this->redactar($command, $secretos));

        $resultado = $this->ejecutar($command);
        $salida    = (string) $resultado['salida'];
        $exit      = $resultado['exit'];

        $salida_redactada = trim($this->redactar($salida, $secretos));
        if ($salida_redactada !== '') {
            $this->registrar($salida_redactada);
        }

        /*
         * exit === false es "el servidor no devolvió exit status", que es lo que pasa con algunos
         * comandos por el canal exec de phpseclib. No se trata como falla: exec_ssh_session() hace
         * lo mismo desde siempre y cambiarlo acá rompería comandos que hoy andan.
         */
        if ($must_succeed && $exit !== false && (int) $exit !== 0) {
            /*
             * 🔴 El mensaje va REDACTADO. Esta excepción termina en failure_reason de la
             * instalación y en el panel: si el comando que falló era el `clpctl site:add:php`, sin
             * esta redacción la contraseña quedaría escrita en la base igual que si no existiera
             * toda esta clase.
             */
            throw new \RuntimeException(
                'El comando remoto falló (exit ' . (int) $exit . '): '
                    . $this->redactar($command, $secretos) . ' → '
                    . substr($salida_redactada, 0, 800),
                (int) $exit
            );
        }

        return $salida;
    }

    /**
     * Reemplaza por *** cada secreto que aparezca en el texto.
     *
     * Es público porque el llamador también arma textos propios que llevan secretos (el bloque de
     * configuración de supervisor, por ejemplo) y tiene que poder redactarlos con la misma regla.
     *
     * @param  string              $texto
     * @param  array<int, string>  $secretos
     * @return string
     */
    public function redactar(string $texto, array $secretos): string
    {
        foreach ($secretos as $secreto) {
            $secreto = (string) $secreto;

            if (strlen($secreto) < self::LARGO_MINIMO_DE_SECRETO) {
                continue;
            }

            $texto = str_replace($secreto, self::REDACCION, $texto);
        }

        return $texto;
    }

    /**
     * 🔴 EL ÚNICO ESCAPADOR DE ARGUMENTOS REMOTOS DE TODO EL ADMIN. Es estático y vive acá —en la
     * clase que manda los comandos— justamente para que no haya una segunda copia de esta
     * convención dando vueltas.
     *
     * NO SE USA escapeshellarg() PARA UN COMANDO QUE EJECUTA OTRA MÁQUINA. Esa función escapa
     * según el sistema donde corre PHP y no según el del otro lado: en Linux emite comillas
     * simples, pero admin-api también corre local sobre WAMP y ahí emite comillas DOBLES —adentro
     * de las cuales el `sh` remoto expande `$`, backticks y `\`— y encima borra los `%`. O sea que
     * el mismo código, corrido desde la máquina de desarrollo, manda un comando distinto y
     * ejecutable.
     *
     * Y no es teórico: la línea del crontab del VPS lleva adentro el api_path, que se deriva de
     * client_apis.vps_path —texto libre del CRUD del admin, que NO pasa por las guardas de
     * HostingProvisioningStructure (esas validan el slug derivado del spa_url, que es otro dato)—,
     * y la credencial del VPS es ROOT.
     *
     * Comillas simples POSIX: adentro todo es literal, y la única comilla simple se cierra, se
     * escapa y se reabre. El resultado es idéntico en Windows, en Linux y en cualquier otro lado.
     *
     * @param  string  $valor
     * @return string  El valor entre comillas simples, listo para concatenar en un comando.
     */
    public static function escapar_argumento(string $valor): string
    {
        return "'" . str_replace("'", "'\\''", $valor) . "'";
    }

    /**
     * 🔴 LA COSTURA. Es lo único que sobreescribe RemoteCommandRunnerFake, y por eso es lo único
     * que no está cubierto por los tests: todo lo que se meta acá adentro deja de estar probado en
     * el mismo momento en que se escribe. La redacción, el armado del mensaje de error y la
     * decisión sobre el exit status viven arriba, en run(), a propósito.
     *
     * @param  string  $command
     * @return array<string, mixed>  ['salida' => string, 'exit' => int|false]
     */
    protected function ejecutar(string $command): array
    {
        $ssh    = $this->sesion();
        $salida = (string) $ssh->exec($command);

        return ['salida' => $salida, 'exit' => $ssh->getExitStatus()];
    }

    /**
     * Sesión SSH, abierta al primer uso.
     *
     * @return SSH2
     * @throws \RuntimeException Si la credencial es rechazada.
     */
    protected function sesion(): SSH2
    {
        if ($this->ssh !== null) {
            return $this->ssh;
        }

        $ssh = new SSH2($this->credential->host, (int) $this->credential->port);

        if (! $ssh->login($this->credential->username, $this->credential->password)) {
            throw new \RuntimeException(
                'No se pudo conectar por SSH a ' . $this->credential->host . ' con la credencial de '
                . 'tipo "' . $this->credential->type . '": credenciales rechazadas.'
            );
        }

        $this->ssh = $ssh;

        return $ssh;
    }

    /**
     * Manda una línea al panel de operaciones, si hay logger enchufado.
     *
     * @param  string  $linea  YA redactada por el llamador.
     * @param  string  $level
     * @return void
     */
    protected function registrar(string $linea, string $level = 'info'): void
    {
        if ($this->logger === null) {
            return;
        }

        $logger = $this->logger;
        $logger($this->step, $linea, $level);
    }
}
