<?php

namespace Tests\Feature\Prueba;

use App\Models\ClientSshCredential;
use App\Services\RemoteCommandRunner;
use phpseclib3\Net\SSH2;
use Tests\TestCase;

/**
 * El timeout por comando y el exit obligatorio de `RemoteCommandRunner` (misión `prueba-runner-timeout-y-cola`,
 * 4/11/2026).
 *
 * phpseclib corta cada `exec()` a los 10 s de tiempo total y devuelve la salida a medias con exit `false`. Acá
 * se prueba lo que el runner hace con eso, con un SSH2 falso por la costura `sesion()`: ni una conexión real.
 */
class RemoteCommandRunnerTimeoutTest extends TestCase
{
    /** Un SSH2 que no conecta: registra setTimeout() y devuelve lo preparado. */
    private function ssh_falso(string $salida, $exit, int $timeout_inicial = 10): SSH2
    {
        return new class($salida, $exit, $timeout_inicial) extends SSH2 {
            public $timeout_actual;

            public $timeouts_puestos = [];

            public $timeout_durante_exec = 'no corrió';

            private $salida_preparada;

            private $exit_preparado;

            public function __construct($salida, $exit, $timeout_inicial)
            {
                $this->salida_preparada = $salida;
                $this->exit_preparado   = $exit;
                $this->timeout_actual   = $timeout_inicial;
            }

            /* El destructor real desconecta una sesión que nunca se abrió. */
            public function __destruct()
            {
            }

            public function getTimeout()
            {
                return $this->timeout_actual;
            }

            public function setTimeout($timeout)
            {
                $this->timeouts_puestos[] = $timeout;
                $this->timeout_actual     = $timeout;
            }

            public function exec($command, $callback = null)
            {
                $this->timeout_durante_exec = $this->timeout_actual;

                return $this->salida_preparada;
            }

            public function getExitStatus()
            {
                return $this->exit_preparado;
            }
        };
    }

    private function runner_con(SSH2 $ssh): RemoteCommandRunner
    {
        return new class(new ClientSshCredential(), $ssh) extends RemoteCommandRunner {
            private $ssh_falso;

            public function __construct(ClientSshCredential $credential, SSH2 $ssh)
            {
                parent::__construct($credential);
                $this->ssh_falso = $ssh;
            }

            protected function sesion(): SSH2
            {
                return $this->ssh_falso;
            }
        };
    }

    public function test_sin_timeout_no_toca_el_de_la_sesion(): void
    {
        $ssh    = $this->ssh_falso('ok', 0);
        $runner = $this->runner_con($ssh);

        $this->assertSame('ok', $runner->run('echo ok'));

        $this->assertSame([], $ssh->timeouts_puestos, 'Los demás llamadores siguen con los 10 s de phpseclib.');
        $this->assertSame(10, $ssh->timeout_durante_exec);
    }

    public function test_con_timeout_lo_aplica_durante_el_comando_y_lo_restaura(): void
    {
        $ssh    = $this->ssh_falso('listo', 0, 10);
        $runner = $this->runner_con($ssh);

        $runner->run('bash /root/prueba-gratuita/pool-crear.sh', [], true, 3900);

        $this->assertSame(3900, $ssh->timeout_durante_exec, 'El comando largo corrió con el tope alto.');
        $this->assertSame([3900, 10], $ssh->timeouts_puestos, 'Se puso el tope y después se devolvió el anterior.');
        $this->assertSame(10, $ssh->timeout_actual, 'La sesión compartida queda como estaba.');
    }

    public function test_restaura_el_timeout_aunque_el_comando_tire_una_excepcion(): void
    {
        $ssh = new class() extends SSH2 {
            public $timeout_actual = 10;

            public $timeouts_puestos = [];

            public function __construct()
            {
            }

            /* El destructor real desconecta una sesión que nunca se abrió. */
            public function __destruct()
            {
            }

            public function getTimeout()
            {
                return $this->timeout_actual;
            }

            public function setTimeout($timeout)
            {
                $this->timeouts_puestos[] = $timeout;
                $this->timeout_actual     = $timeout;
            }

            public function exec($command, $callback = null)
            {
                throw new \RuntimeException('se cayó la conexión');
            }
        };
        $runner = $this->runner_con($ssh);

        try {
            $runner->run('sleep 100', [], true, 600);
            $this->fail('Tenía que propagar la excepción.');
        } catch (\RuntimeException $e) {
            $this->assertSame('se cayó la conexión', $e->getMessage());
        }

        $this->assertSame(10, $ssh->timeout_actual);
        $this->assertSame([600, 10], $ssh->timeouts_puestos);
    }

    public function test_exit_ausente_con_exit_obligatorio_es_una_falla_y_no_filtra_secretos(): void
    {
        $ssh    = $this->ssh_falso('salida a medias con Clave-Muy-Secreta-123', false);
        $runner = $this->runner_con($ssh);

        try {
            $runner->run('bash x.sh Clave-Muy-Secreta-123', ['Clave-Muy-Secreta-123'], true, 3900, true);
            $this->fail('Un exit ausente con exit_obligatorio tiene que fallar.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no devolvió exit status', $e->getMessage());
            $this->assertStringContainsString('3900 s', $e->getMessage());
            $this->assertStringContainsString('salida a medias', $e->getMessage());
            $this->assertStringNotContainsString('Clave-Muy-Secreta-123', $e->getMessage());
        }
    }

    public function test_exit_ausente_sin_exit_obligatorio_sigue_pasando_como_siempre(): void
    {
        $ssh    = $this->ssh_falso('algo', false);
        $runner = $this->runner_con($ssh);

        $this->assertSame('algo', $runner->run('comando que anda hoy'));
        $this->assertSame('algo', $runner->run('comando que anda hoy', [], true, 3900), 'El timeout solo no cambia la lectura del exit.');
    }

    public function test_exit_ausente_con_exit_obligatorio_pero_sin_must_succeed_no_falla(): void
    {
        $ssh    = $this->ssh_falso('parcial', false);
        $runner = $this->runner_con($ssh);

        $this->assertSame('parcial', $runner->run('x', [], false, null, true));
    }

    public function test_un_exit_distinto_de_cero_sigue_fallando_como_antes(): void
    {
        $ssh    = $this->ssh_falso('boom', 3);
        $runner = $this->runner_con($ssh);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exit 3');

        $runner->run('falla', [], true, 3900, true);
    }
}
