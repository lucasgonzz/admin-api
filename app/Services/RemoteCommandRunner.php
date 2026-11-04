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
     * Cada cuántos segundos se manda un latido (SSH_MSG_IGNORE) mientras corre un comando con tope propio.
     *
     * @var int
     */
    const KEEPALIVE_SEGUNDOS = 30;

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
     * Tope en segundos que pidió el `run()` en curso (null = el de la sesión). Lo lee la costura ejecutar().
     *
     * @var int|null
     */
    protected $timeout_del_comando = null;

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
     * @param  int|null              $timeout_segundos  null = el de la sesión, 10 s de phpseclib (como siempre).
     *                                                  Un número positivo = tope de ESTE comando, en segundos. Se
     *                                                  aplica solo durante el comando y después se restaura (ver
     *                                                  ejecutar()). Cero o negativo no se acepta: en phpseclib 0 no
     *                                                  es "sin tope" sino el timeout por defecto del socket.
     * @param  bool                  $exit_obligatorio  true = que el servidor no devuelva exit status (`false`) es una
     *                                                  falla. Es lo que pasa cuando phpseclib corta la lectura por
     *                                                  tiempo y devuelve la salida TRUNCADA: sin esta bandera esa
     *                                                  salida parcial pasaría por éxito. Default false para no
     *                                                  cambiar a los demás llamadores (ver el comentario de abajo).
     * @return string  Salida cruda del comando.
     * @throws \RuntimeException Si el comando falla y $must_succeed, o si no hubo exit status y $exit_obligatorio.
     */
    public function run(
        string $command,
        array $secretos = [],
        bool $must_succeed = true,
        ?int $timeout_segundos = null,
        bool $exit_obligatorio = false
    ): string {
        if ($timeout_segundos !== null && $timeout_segundos < 1) {
            throw new \InvalidArgumentException('El timeout de un comando remoto tiene que ser de al menos 1 segundo.');
        }

        $this->registrar('$ ' . $this->redactar($command, $secretos));

        $this->timeout_del_comando = $timeout_segundos;

        try {
            $resultado = $this->ejecutar_con_reintento_de_canal($command, $secretos);
        } finally {
            $this->timeout_del_comando = null;
        }
        $salida    = (string) $resultado['salida'];
        $exit      = $resultado['exit'];

        $salida_redactada = trim($this->redactar($salida, $secretos));
        if ($salida_redactada !== '') {
            $this->registrar($salida_redactada);
        }

        /*
         * exit === false es "el servidor no devolvió exit status", que es lo que pasa con algunos
         * comandos por el canal exec de phpseclib. Por defecto no se trata como falla:
         * exec_ssh_session() hace lo mismo desde siempre y cambiarlo acá rompería comandos que hoy
         * andan.
         *
         * 🔴 Salvo que el llamador lo pida con $exit_obligatorio (los scripts largos de la prueba
         * gratuita). Medido en producción el 4/11/2026: phpseclib cortó un script de 25 s a los 10 s
         * y devolvió su salida a medias con exit false; tomarlo por éxito dejaba pasar un
         * `pool-crear.sh` que nunca terminó. Ahí "no hay exit status" quiere decir "no sé si
         * terminó", y eso es una falla.
         */
        if ($must_succeed && $exit_obligatorio && $exit === false) {
            throw new \RuntimeException(
                'El comando remoto no devolvió exit status (se cortó la conexión o venció el tiempo máximo'
                    . ($timeout_segundos === null ? '' : ' de ' . $timeout_segundos . ' s') . '): '
                    . $this->redactar($command, $secretos) . ' → '
                    . substr($salida_redactada, -800)
            );
        }

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
     * Corre el comando y, si phpseclib no pudo ni abrir el canal, reconecta y lo intenta UNA vez.
     *
     * 🔴 El reintento está acotado a un solo error, y eso importa: *"Please close the channel (N)
     * before trying to open it again"* lo tira `SSH2::open_channel()`, o sea **antes** de mandar
     * nada. El comando no llegó a ejecutarse, así que repetirlo no puede duplicar un efecto. Un
     * reintento general acá sería otra cosa —un comando que se ejecutó a medias y se repite— y por
     * eso no se hace.
     *
     * Aparece cuando una etapa encadena varios comandos largos sobre la misma sesión: medido el
     * 10/9/2026 instalando `pescamayorista`, donde provision_ssl pide cuatro certificados seguidos
     * y el segundo se encontraba el canal anterior sin cerrar. La instalación quedaba `fallida` con
     * ese texto de phpseclib como único motivo —que no le dice nada a nadie— habiendo hecho TODO
     * bien salvo tres certificados.
     *
     * @param  string              $command
     * @param  array<int, string>  $secretos  Para redactar el mensaje si hay que relanzar.
     * @return array<string, mixed>  ['salida' => string, 'exit' => int|false]
     */
    private function ejecutar_con_reintento_de_canal(string $command, array $secretos): array
    {
        try {
            return $this->ejecutar($command);
        } catch (\Throwable $excepcion) {
            if (strpos($excepcion->getMessage(), 'before trying to open it again') === false) {
                throw $excepcion;
            }

            $this->registrar(
                'El canal SSH anterior quedó abierto; se reconecta y se reintenta el comando.'
            );

            $this->reconectar();

            return $this->ejecutar($command);
        }
    }

    /**
     * Tira la sesión SSH para que la próxima llamada abra una nueva.
     *
     * Vive del lado de la costura porque toca el estado de la conexión, pero es una línea: la
     * decisión de cuándo reconectar está arriba, en ejecutar_con_reintento_de_canal(), que sí queda
     * cubierta por los tests.
     *
     * @return void
     */
    protected function reconectar(): void
    {
        $this->ssh = null;
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
     * 🔴 EL ESCAPADOR DE ARGUMENTOS REMOTOS DEL APROVISIONAMIENTO Y DE LA INSTALACIÓN. Es estático y
     * vive acá —en la clase que manda los comandos— justamente para que no haya una segunda copia de
     * esta convención dando vueltas. `InstallationService` y `EnvSshService` tenían la suya y ahora
     * delegan acá: de tres implementaciones quedó una.
     *
     * ⚠️ Decía "el único de todo el admin" y dejó de ser cierto el 31/8/2026, cuando el merge de
     * `master` trajo el módulo de instalación de demos: `DemoInstallationService` tiene su propia
     * copia de `escape_remote_arg()` y `DemoUpdateService` usa `escapeshellarg()` en ~30 llamadas,
     * incluida la del `SPA_DIR` de su `find . -mindepth 1 -delete`. Es deuda de ese módulo, no de
     * este, y migrarla es mecánico ahora que este escapador existe — pero la frase absoluta se
     * corrige en vez de dejarla mintiendo, porque un docblock que promete una garantía que no da es
     * peor que no tenerlo.
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
     * 🔴 El timeout. phpseclib trae 10 s de TIEMPO TOTAL por `exec()` (no de inactividad: un script
     * que imprime cada segundo se corta igual) y devuelve lo que alcanzó a leer, sin avisar. Con
     * `$timeout_segundos` null no se toca nada —el 10 de siempre, para los comandos cortos que ya
     * andaban con él—; con un número se aplica SOLO a este comando y se restaura en un `finally`,
     * porque la sesión es compartida: un `setTimeout(3900)` que quedara puesto le cambiaría el
     * comportamiento al siguiente `run()` de otro llamador (un `dig`, un `clpctl`).
     *
     * El tope de ESTE comando llega por `$timeout_del_comando` (lo deja `run()`) y no como argumento, para no
     * cambiar la firma de la costura: los dobles que ya la sobreescriben siguen siendo válidos.
     *
     * @param  string  $command
     * @return array<string, mixed>  ['salida' => string, 'exit' => int|false]
     */
    protected function ejecutar(string $command): array
    {
        $ssh              = $this->sesion();
        $timeout_segundos = $this->timeout_del_comando;

        if ($timeout_segundos === null) {
            $salida = (string) $ssh->exec($command);

            return ['salida' => $salida, 'exit' => $ssh->getExitStatus()];
        }

        $anterior = $ssh->getTimeout();
        $ssh->setTimeout($timeout_segundos);

        /* Un comando de minutos u horas que no imprime nada deja el TCP callado: un NAT o un firewall lo puede
           cortar. phpseclib manda un SSH_MSG_IGNORE cada tanto si se lo pide. Se apaga al terminar (la sesión es
           compartida). */
        $ssh->setKeepAlive(self::KEEPALIVE_SEGUNDOS);

        try {
            $salida = (string) $ssh->exec($command);

            /*
             * 🔴 Si venció el tiempo, exec() devuelve la salida parcial y getExitStatus() devuelve el exit del
             * comando ANTERIOR de la sesión (phpseclib no lo resetea): un 0 viejo haría pasar la salida cortada
             * por un éxito. Por eso un corte por tiempo es SIEMPRE "sin exit status", y la sesión se descarta
             * porque el canal quedó abierto con el comando todavía corriendo.
             */
            if ($ssh->isTimeout()) {
                $this->reconectar();

                return ['salida' => $salida, 'exit' => false];
            }

            return ['salida' => $salida, 'exit' => $ssh->getExitStatus()];
        } finally {
            $ssh->setTimeout($anterior);
            $ssh->setKeepAlive(0);
        }
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
