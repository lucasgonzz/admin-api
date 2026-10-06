<?php

namespace Tests\Unit;

use App\Services\EnvSshService;
use phpseclib3\Net\SSH2;
use PHPUnit\Framework\TestCase;

/**
 * Agregar una variable al final de un `.env` que NO termina en salto de línea (misión
 * `implementacion-dos-sistemas`, revisión independiente del 6/10/2026).
 *
 * 🔴 EL DEFECTO. `EnvSshService::write_env_vars()` agregaba la línea con `printf '%s\n' 'KEY=val' >> .env`.
 * Si el último renglón del archivo no tenía salto de línea, la variable nueva quedaba PEGADA a esa línea
 * (`LAST=valKEY=nuevo`): se corrompía una variable que nadie había pedido tocar. Y como la relectura
 * posterior no encontraba la variable nueva, el error decía "el .env quedó como estaba", que era falso.
 * Lo usan el cambio masivo de variables (`EnvBulkChangeService`) y la clave de `/categorizar`
 * (`ClientInboundKeySyncService`): el arreglo vale para los dos y no cambia su contrato (sigue lanzando
 * `RuntimeException` cuando la escritura no se verifica).
 *
 * El `EnvSshServiceFake` de los tests de Feature no arma comandos (no tiene shell), así que acá se usa
 * un SSH2 que GRABA los comandos que le llegan y contesta lo mínimo que necesita el servicio real. Lo
 * que se afirma es el COMANDO que se arma —que lleva la guarda del salto de línea, antes del `>>` y con
 * el mismo quoting de siempre—; que esa guarda se comporta bien en un shell de verdad (sin salto final,
 * con salto, archivo vacío, CRLF, path con espacios y comilla, sin permisos) se comprobó a mano en bash.
 */
class EnvSshServiceAgregaConSaltoDeLineaTest extends TestCase
{
    /** Path del `.env` de la API de un cliente de VPS. */
    const ENV = '/home/api-doblep/empresa-api/.env';

    /**
     * Un SSH2 que no conecta a nada: graba cada comando y contesta lo mínimo que pide `write_env_vars()`.
     *
     * - `test -f ...`  → el archivo existe.
     * - `grep -q ...`  → la variable existe o no, según `$la_variable_existe`.
     * - `cat ...`      → el contenido que tendría el archivo DESPUÉS de escribir (`$env_despues`).
     * - lo demás (la escritura) → vacío, con el exit status que se pida.
     *
     * @param string $env_despues       Contenido del `.env` que devuelve la relectura.
     * @param bool   $la_variable_existe Si el `grep` encuentra la variable (rama de `sed` y no de agregar).
     * @param int    $exit_de_escribir  Exit status de los comandos que escriben.
     *
     * @return SSH2 Con la propiedad pública `comandos`: todo lo que le llegó, en orden.
     */
    private function ssh_que_graba(string $env_despues, bool $la_variable_existe = false, int $exit_de_escribir = 0): SSH2
    {
        return new class($env_despues, $la_variable_existe, $exit_de_escribir) extends SSH2 {
            /** @var array<int, string> */
            public $comandos = [];

            private $env_despues;

            private $la_variable_existe;

            private $exit_de_escribir;

            private $ultimo = '';

            public function __construct($env_despues, $la_variable_existe, $exit_de_escribir)
            {
                // A propósito NO se llama al constructor del padre: no hay ningún servidor al que conectar.
                $this->env_despues        = $env_despues;
                $this->la_variable_existe = $la_variable_existe;
                $this->exit_de_escribir   = $exit_de_escribir;
            }

            public function __destruct()
            {
            }

            public function disconnect()
            {
            }

            public function exec($command, $callback = null)
            {
                $this->comandos[] = $command;
                $this->ultimo     = $command;

                if (strpos($command, 'test -f') === 0) {
                    return "EXISTS\n";
                }

                if (strpos($command, 'grep -q') === 0) {
                    return $this->la_variable_existe ? "EXISTS\n" : "NOT_EXISTS\n";
                }

                if (strpos($command, 'cat ') === 0) {
                    return $this->env_despues;
                }

                return '';
            }

            public function getExitStatus()
            {
                $escribe = strpos($this->ultimo, '>>') !== false || strpos($this->ultimo, 'sed -i') === 0;

                return $escribe ? $this->exit_de_escribir : 0;
            }
        };
    }

    /**
     * Un servicio real con la sesión SSH ya "abierta" sobre el doble (el servicio no conecta solo: la
     * sesión se inyecta donde `connect_to()` la dejaría).
     *
     * @param SSH2 $ssh El doble.
     *
     * @return EnvSshService
     */
    private function servicio_con(SSH2 $ssh): EnvSshService
    {
        $servicio  = new EnvSshService();
        $propiedad = new \ReflectionProperty(EnvSshService::class, 'ssh');

        $propiedad->setAccessible(true);
        $propiedad->setValue($servicio, $ssh);

        return $servicio;
    }

    /**
     * Los comandos que ESCRIBEN (los que llevan `>>`), sin los de consulta.
     *
     * @param SSH2 $ssh El doble.
     *
     * @return array<int, string>
     */
    private function comandos_de_agregar($ssh): array
    {
        return array_values(array_filter($ssh->comandos, function ($comando) {
            return strpos($comando, '>>') !== false;
        }));
    }

    /**
     * 🔴 El comando que agrega una variable lleva la guarda del salto de línea ANTES del `>>` que
     * escribe, unida con `&&` y con el mismo quoting POSIX de los demás comandos. Si alguien la saca, el
     * `.env` que no termina en salto vuelve a quedar con dos variables pegadas.
     *
     * @return void
     */
    public function test_el_comando_de_agregar_lleva_la_guarda_del_salto_de_linea(): void
    {
        $ssh      = $this->ssh_que_graba("APP=1\nLAST=val\nKEY=nuevo\n");
        $servicio = $this->servicio_con($ssh);

        $servicio->write_env_vars('/home/api-doblep/empresa-api', ['KEY' => 'nuevo']);

        $agregar = $this->comandos_de_agregar($ssh);

        $this->assertCount(1, $agregar);

        $archivo  = "'" . self::ENV . "'";
        $esperado = '{ [ -z "$(tail -c1 ' . $archivo . ')" ] || printf \'\n\' >> ' . $archivo . '; } && '
            . 'printf \'%s\n\' \'KEY=nuevo\' >> ' . $archivo;

        $this->assertSame($esperado, $agregar[0]);

        /* Y la guarda va antes de la escritura de la línea. */
        $this->assertLessThan(strpos($agregar[0], "'KEY=nuevo'"), strpos($agregar[0], 'tail -c1'));
    }

    /**
     * Con varias variables nuevas, CADA agregado lleva su guarda (la primera deja el archivo con salto
     * final, pero no se confía en eso: cada comando se sostiene solo).
     *
     * @return void
     */
    public function test_cada_variable_nueva_lleva_su_propia_guarda(): void
    {
        $ssh      = $this->ssh_que_graba("APP=1\nUNO=1\nDOS=2\n");
        $servicio = $this->servicio_con($ssh);

        $servicio->write_env_vars('/home/api-doblep/empresa-api', ['UNO' => '1', 'DOS' => '2']);

        $agregar = $this->comandos_de_agregar($ssh);

        $this->assertCount(2, $agregar);

        foreach ($agregar as $comando) {
            $this->assertStringContainsString('tail -c1', $comando);
            $this->assertLessThan(strpos($comando, 'printf \'%s'), strpos($comando, 'tail -c1'));
        }
    }

    /**
     * El quoting de un path con espacios y una comilla simple no se rompe: la guarda usa el mismo
     * `escape_remote_arg()` que el resto, con la comilla cerrada, escapada y reabierta.
     *
     * @return void
     */
    public function test_la_guarda_respeta_el_quoting_de_un_path_con_espacios_y_comilla(): void
    {
        $ssh      = $this->ssh_que_graba("KEY=nuevo\n");
        $servicio = $this->servicio_con($ssh);

        $servicio->write_env_vars("/home/api con espacios y 'comilla'/empresa-api", ['KEY' => 'nuevo']);

        $agregar = $this->comandos_de_agregar($ssh);

        $archivo = "'/home/api con espacios y '\\''comilla'\\''/empresa-api/.env'";

        $this->assertCount(1, $agregar);
        $this->assertStringContainsString('"$(tail -c1 ' . $archivo . ')"', $agregar[0]);
        $this->assertStringEndsWith('>> ' . $archivo, $agregar[0]);
    }

    /**
     * Cuando la variable YA existe se reemplaza con `sed` y NO se agrega nada al final: ahí no hay salto
     * que asegurar, y la guarda no tiene nada que hacer.
     *
     * @return void
     */
    public function test_reemplazar_una_variable_existente_no_agrega_nada_al_final(): void
    {
        $ssh      = $this->ssh_que_graba("APP=1\nKEY=nuevo\n", true);
        $servicio = $this->servicio_con($ssh);

        $servicio->write_env_vars('/home/api-doblep/empresa-api', ['KEY' => 'nuevo']);

        $this->assertSame([], $this->comandos_de_agregar($ssh));

        $con_sed = array_values(array_filter($ssh->comandos, function ($comando) {
            return strpos($comando, 'sed -i') === 0;
        }));

        $this->assertCount(1, $con_sed);
        $this->assertStringNotContainsString('tail -c1', $con_sed[0]);
    }

    /**
     * Si la guarda o la escritura fallan (permisos, disco lleno) el servicio lanza con el motivo y NO
     * sigue: la guarda va unida a la escritura con `&&`, así que una línea nunca se agrega si el
     * salto de línea no pudo asegurarse.
     *
     * @return void
     */
    public function test_si_el_comando_de_agregar_falla_el_servicio_lanza(): void
    {
        $ssh      = $this->ssh_que_graba("APP=1\n", false, 1);
        $servicio = $this->servicio_con($ssh);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Falló al agregar KEY en ' . self::ENV);

        $servicio->write_env_vars('/home/api-doblep/empresa-api', ['KEY' => 'nuevo']);
    }

    /**
     * 🔴 El mensaje de la relectura NO dice que el `.env` "quedó como estaba": cuando la variable no
     * se encuentra después de escribir, el archivo pudo haber cambiado (acá, la variable nueva pegada a
     * la anterior), y decir lo contrario manda a reintentar sobre un archivo que ya no es el original.
     *
     * @return void
     */
    public function test_el_mensaje_de_la_relectura_no_miente_sobre_el_estado_del_archivo(): void
    {
        /* Lo que quedaría SIN la guarda: la variable nueva pegada a la última línea. */
        $ssh      = $this->ssh_que_graba("APP=1\nLAST=valKEY=nuevo\n");
        $servicio = $this->servicio_con($ssh);

        try {
            $servicio->write_env_vars('/home/api-doblep/empresa-api', ['KEY' => 'nuevo']);

            $this->fail('Tendría que haber lanzado: la relectura no encuentra la variable.');
        } catch (\RuntimeException $e) {
            $mensaje = $e->getMessage();

            $this->assertStringContainsString('KEY', $mensaje);
            $this->assertStringContainsString(self::ENV, $mensaje);
            $this->assertStringNotContainsString('quedó como estaba', $mensaje);
            $this->assertStringContainsString('PUDO HABER CAMBIADO', $mensaje);
            $this->assertStringContainsString('.env.bak-', $mensaje);
        }
    }
}
