<?php

namespace Tests\Fakes;

use phpseclib3\Net\SSH2;

/**
 * Doble de la sesión SSH con la que `DeploymentService` le habla al hosting del cliente (misión
 * `un-solo-frente`, 14/10/2026).
 *
 * Existe porque el despliegue EN EL LUGAR agrega comandos que se ejecutan sobre el sistema vivo
 * del cliente —el respaldo (`tar`, `mysqldump`), `artisan down` y `artisan up`— y lo que hay que
 * probar no es que esos comandos anden en un servidor (eso no se puede probar desde acá), sino
 * QUÉ se manda y EN QUÉ ORDEN: que el respaldo pase antes de pisar nada, que `down` vaya antes de
 * descomprimir la API, que `up` vaya después de migrar, y que la contraseña de la base no viaje
 * nunca en la línea de comandos.
 *
 * 🔴 Lo único que se reemplaza es el transporte (`exec`, el código de salida, el timeout). El
 * envoltorio de stdin cerrado, el log de cada comando y la decisión sobre el exit status siguen
 * siendo el código real de `DeploymentService::exec_ssh_session()`.
 *
 * Se inyecta con el seam `DeploymentService::abrir_sesion_ssh()`: el servicio la pide en cada
 * `connect()` (y en cada reconexión), y el doble devuelve siempre esta misma instancia, así el
 * registro de comandos sobrevive a las reconexiones del pipeline.
 *
 * PHP 7.4.
 */
class SshDelDeploymentFake extends SSH2
{
    /**
     * Comandos tal cual llegaron a exec(), en orden (con el envoltorio `{ ... } < /dev/null`).
     *
     * @var array<int, string>
     */
    public $comandos = [];

    /**
     * Salida de cualquier comando que no matchee ninguna regla.
     *
     * @var string
     */
    public $salida_por_defecto = '';

    /**
     * Reglas de salida, en orden de declaración. Gana la primera que matchea.
     *
     * @var array<int, array<string, mixed>>
     */
    private $reglas = [];

    /**
     * Código de salida del último comando.
     *
     * @var int|false
     */
    private $ultimo_exit = 0;

    /**
     * No conecta: el constructor de SSH2 no abre el socket, recién lo abre login(), que acá no se
     * llama nunca.
     */
    public function __construct()
    {
        parent::__construct('127.0.0.1', 1);
    }

    /**
     * Prepara la salida (y el código de salida) de todo comando que contenga $aguja.
     *
     * @param  string     $aguja
     * @param  string     $salida
     * @param  int|false  $exit
     * @return void
     */
    public function responder(string $aguja, string $salida = '', $exit = 0): void
    {
        $this->reglas[] = ['aguja' => $aguja, 'salida' => $salida, 'exit' => $exit];
    }

    /**
     * Registra el comando y devuelve la salida preparada.
     *
     * @param  string         $command
     * @param  callable|null  $callback
     * @return string
     */
    public function exec($command, $callback = null)
    {
        $command            = (string) $command;
        $this->comandos[]   = $command;

        foreach ($this->reglas as $regla) {
            if (strpos($command, $regla['aguja']) !== false) {
                $this->ultimo_exit = $regla['exit'];

                return $regla['salida'];
            }
        }

        $this->ultimo_exit = 0;

        // Un VPS de los escenarios existentes no tiene un marcador opt-in salvo respuesta explícita.
        if (strpos($command, 'python3 -c') !== false && strpos($command, 'os.O_NOFOLLOW') !== false
            && strpos($command, '.comerciocity-runtime.json') !== false) {
            return 'CC_RUNTIME_AUSENTE';
        }

        return $this->salida_por_defecto;
    }

    /**
     * @return int|false
     */
    public function getExitStatus()
    {
        return $this->ultimo_exit;
    }

    /**
     * @param  mixed  $timeout
     * @return void
     */
    public function setTimeout($timeout)
    {
        // Sin socket no hay timeout que ajustar.
    }

    /**
     * @return void
     */
    public function disconnect()
    {
        // Sin socket no hay nada que cerrar.
    }

    /**
     * El destructor del padre llama a disconnect() sobre un socket que nunca existió.
     */
    public function __destruct()
    {
    }

    /**
     * Posición del primer comando que contiene $aguja, o null si ninguno.
     *
     * @param  string  $aguja
     * @return int|null
     */
    public function posicion_de(string $aguja): ?int
    {
        foreach ($this->comandos as $indice => $comando) {
            if (strpos($comando, $aguja) !== false) {
                return $indice;
            }
        }

        return null;
    }

    /**
     * Todos los comandos pegados, para buscar algo que no tiene que aparecer en ninguno.
     *
     * @return string
     */
    public function todo(): string
    {
        return implode("\n", $this->comandos);
    }
}
