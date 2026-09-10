<?php

namespace Tests\Unit;

use App\Models\ClientSshCredential;
use App\Services\RemoteCommandRunner;
use Tests\TestCase;

/**
 * Cuando phpseclib no puede ni abrir el canal, se reconecta y se reintenta UNA vez.
 *
 * El error es *"Please close the channel (N) before trying to open it again"* y lo tira
 * `SSH2::open_channel()`, o sea ANTES de mandar nada: el comando no llegó a ejecutarse, así que
 * repetirlo no puede duplicar ningún efecto. Aparece cuando una etapa encadena varios comandos
 * largos sobre la misma sesión.
 *
 * Medido el 10/9/2026 instalando `pescamayorista`: `provision_ssl` pide cuatro certificados
 * seguidos y el segundo se encontraba el canal anterior sin cerrar. La instalación quedaba
 * `fallida` con ese texto de phpseclib como único motivo —que no le dice nada a nadie— habiendo
 * hecho todo bien salvo tres certificados.
 *
 * 🔴 Lo que estos tests protegen es que el reintento siga siendo SÓLO para ese error. Un reintento
 * general acá repetiría un comando que se ejecutó a medias, que es un problema peor que el que
 * viene a resolver.
 */
class ReintentoDeCanalSshTest extends TestCase
{
    /**
     * Runner de prueba: cuenta las ejecuciones y falla las primeras N con el error que se le pida.
     *
     * @param string $mensaje        Mensaje de la excepción a simular.
     * @param int    $cuantas_fallan Cuántas ejecuciones fallan antes de andar.
     *
     * @return RemoteCommandRunner
     */
    private function runner(string $mensaje, int $cuantas_fallan): RemoteCommandRunner
    {
        $credencial = new ClientSshCredential([
            'type'     => 'vps',
            'host'     => '127.0.0.1',
            'port'     => 22,
            'username' => 'root',
        ]);

        return new class($credencial, $mensaje, $cuantas_fallan) extends RemoteCommandRunner {
            /** @var string */
            private $mensaje;

            /** @var int */
            private $cuantas_fallan;

            /** @var int Ejecuciones intentadas. */
            public $ejecuciones = 0;

            /** @var int Reconexiones pedidas. */
            public $reconexiones = 0;

            /**
             * @param ClientSshCredential $credencial
             * @param string              $mensaje
             * @param int                 $cuantas_fallan
             */
            public function __construct(ClientSshCredential $credencial, string $mensaje, int $cuantas_fallan)
            {
                parent::__construct($credencial);
                $this->mensaje        = $mensaje;
                $this->cuantas_fallan = $cuantas_fallan;
            }

            /**
             * @param string $command
             *
             * @return array<string, mixed>
             */
            protected function ejecutar(string $command): array
            {
                $this->ejecuciones++;

                if ($this->ejecuciones <= $this->cuantas_fallan) {
                    throw new \RuntimeException($this->mensaje);
                }

                return ['salida' => 'ok', 'exit' => 0];
            }

            /**
             * @return void
             */
            protected function reconectar(): void
            {
                $this->reconexiones++;
            }
        };
    }

    /**
     * 🔴 El caso que se vino a arreglar: el canal viejo sin cerrar, y el comando sale a la segunda.
     *
     * @return void
     */
    public function test_el_canal_sin_cerrar_reconecta_y_reintenta_una_vez()
    {
        $runner = $this->runner('Please close the channel (1) before trying to open it again', 1);

        $salida = $runner->run('clpctl lets-encrypt:install:certificate');

        $this->assertSame('ok', $salida);
        $this->assertSame(2, $runner->ejecuciones, 'Tenía que reintentar exactamente una vez.');
        $this->assertSame(1, $runner->reconexiones, 'Tenía que reconectar antes de reintentar.');
    }

    /**
     * 🔴 Cualquier OTRO error sube tal cual, sin reintentar: ese comando pudo haberse ejecutado.
     *
     * @return void
     */
    public function test_otro_error_no_se_reintenta()
    {
        $runner = $this->runner('Connection closed by server', 1);

        try {
            $runner->run('clpctl site:add:php');
            $this->fail('Tenía que subir la excepción sin reintentar.');
        } catch (\Throwable $excepcion) {
            $this->assertStringContainsString('Connection closed by server', $excepcion->getMessage());
        }

        $this->assertSame(1, $runner->ejecuciones, 'No tenía que reintentar un error que no es del canal.');
        $this->assertSame(0, $runner->reconexiones);
    }

    /**
     * Si el canal sigue roto después de reconectar, se sube el error: no se reintenta en bucle.
     *
     * @return void
     */
    public function test_no_reintenta_dos_veces()
    {
        $runner = $this->runner('Please close the channel (1) before trying to open it again', 2);

        try {
            $runner->run('clpctl lets-encrypt:install:certificate');
            $this->fail('Tenía que subir la excepción tras el único reintento.');
        } catch (\Throwable $excepcion) {
            $this->assertStringContainsString('before trying to open it again', $excepcion->getMessage());
        }

        $this->assertSame(2, $runner->ejecuciones, 'Un solo reintento, no un bucle.');
    }
}
