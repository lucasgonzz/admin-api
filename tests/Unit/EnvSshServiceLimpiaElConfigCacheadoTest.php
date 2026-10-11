<?php

namespace Tests\Unit;

use App\Models\ClientApi;
use App\Services\EnvSshService;
use phpseclib3\Net\SSH2;
use PHPUnit\Framework\TestCase;

/**
 * `EnvSshService::limpiar_cache_de_config_for()` (misión `cerrar-admin-sync`, 10/10/2026, tarea A4).
 *
 * Es lo que hace el prendido de `ADMIN_SYNC_REQUIRE_API_KEY` después de escribir el `.env`: con el config cacheado la variable nueva no se
 * lee. Equivale a `php artisan config:clear` (borrar `bootstrap/cache/config.php`) sin depender de que artisan bootee. Lo que se afirma es
 * el COMANDO que se arma —sobre el archivo y no sobre otro, con el quoting de siempre— y que NO se da por borrado un archivo que sigue
 * existiendo, ni una respuesta vacía (sesión rota), ni un `rm` que falló.
 */
class EnvSshServiceLimpiaElConfigCacheadoTest extends TestCase
{
    /**
     * Un SSH2 que no conecta a nada: graba cada comando y contesta lo mínimo.
     *
     * @param string $respuesta_de_la_verificacion Lo que contesta la pregunta `[ -e ... ]` (`CACHE_NO`, `CACHE_SI`, o vacío).
     * @param int    $exit_del_rm                  Exit status del `rm`.
     *
     * @return SSH2 Con la propiedad pública `comandos`.
     */
    private function ssh_que_graba(string $respuesta_de_la_verificacion, int $exit_del_rm = 0): SSH2
    {
        return new class($respuesta_de_la_verificacion, $exit_del_rm) extends SSH2 {
            /** @var array<int, string> */
            public $comandos = [];

            private $respuesta;

            private $exit_del_rm;

            private $ultimo = '';

            public function __construct($respuesta, $exit_del_rm)
            {
                // A propósito NO se llama al constructor del padre: no hay ningún servidor al que conectar.
                $this->respuesta   = $respuesta;
                $this->exit_del_rm = $exit_del_rm;
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

                if (strpos($command, 'if [ -e') === 0) {
                    return $this->respuesta;
                }

                return '';
            }

            public function getExitStatus()
            {
                return strpos($this->ultimo, 'rm -f') === 0 ? $this->exit_del_rm : 0;
            }
        };
    }

    /**
     * Un servicio real con la sesión SSH ya "abierta" sobre el doble, del tipo de hosting indicado (así `connect_for()` la reutiliza y no
     * intenta conectar).
     *
     * @param SSH2   $ssh          El doble.
     * @param string $hosting_type `shared_hosting` | `vps`.
     *
     * @return EnvSshService
     */
    private function servicio_con(SSH2 $ssh, string $hosting_type): EnvSshService
    {
        $servicio = new EnvSshService();

        $sesion = new \ReflectionProperty(EnvSshService::class, 'ssh');
        $sesion->setAccessible(true);
        $sesion->setValue($servicio, $ssh);

        $tipo = new \ReflectionProperty(EnvSshService::class, 'connected_credential_type');
        $tipo->setAccessible(true);
        $tipo->setValue($servicio, $hosting_type);

        return $servicio;
    }

    /**
     * Un frente sin base de datos: solo los atributos que mira el resolver de rutas.
     *
     * @param string      $hosting_type `shared_hosting` | `vps`.
     * @param string      $path         `path` (shared).
     * @param string|null $vps_path     `vps_path` (vps).
     *
     * @return ClientApi
     */
    private function frente(string $hosting_type, string $path, ?string $vps_path = null): ClientApi
    {
        $frente               = new ClientApi();
        $frente->hosting_type = $hosting_type;
        $frente->path         = $path;
        $frente->vps_path     = $vps_path;

        return $frente;
    }

    /**
     * 🔴 El comando borra EXACTAMENTE `bootstrap/cache/config.php` de la API del frente, con `rm -f` y el path entre comillas, y después pregunta
     * si quedó: en shared (path relativo al home) y en VPS (`/home/api-<x>/empresa-api`).
     *
     * @return void
     */
    public function test_borra_el_config_cacheado_de_la_api_del_frente_y_verifica(): void
    {
        $ssh = $this->ssh_que_graba("CACHE_NO\n");
        $this->servicio_con($ssh, 'shared_hosting')->limpiar_cache_de_config_for($this->frente('shared_hosting', 'doblep/api'));

        $this->assertSame("rm -f 'domains/comerciocity.com/public_html/doblep/api/bootstrap/cache/config.php'", $ssh->comandos[0]);
        $this->assertStringStartsWith("if [ -e 'domains/comerciocity.com/public_html/doblep/api/bootstrap/cache/config.php' ];", $ssh->comandos[1]);
        $this->assertCount(2, $ssh->comandos);

        $vps = $this->ssh_que_graba("CACHE_NO\n");
        $this->servicio_con($vps, 'vps')->limpiar_cache_de_config_for($this->frente('vps', '', 'doblep'));

        $this->assertSame("rm -f '/home/api-doblep/empresa-api/bootstrap/cache/config.php'", $vps->comandos[0]);
    }

    /**
     * Si el archivo sigue existiendo después del `rm`, lanza: un cache que sigue ahí es un falso verde en la prueba del prendido.
     *
     * @return void
     */
    public function test_si_el_archivo_sigue_existiendo_lanza(): void
    {
        $ssh = $this->ssh_que_graba("CACHE_SI\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No se pudo confirmar que el config cacheado se haya borrado');

        $this->servicio_con($ssh, 'shared_hosting')->limpiar_cache_de_config_for($this->frente('shared_hosting', 'doblep/api'));
    }

    /**
     * Una respuesta vacía (la sesión rota devuelve vacío) NO es "no existe": lanza.
     *
     * @return void
     */
    public function test_una_respuesta_vacia_no_se_da_por_borrado(): void
    {
        $ssh = $this->ssh_que_graba('');

        $this->expectException(\RuntimeException::class);

        $this->servicio_con($ssh, 'shared_hosting')->limpiar_cache_de_config_for($this->frente('shared_hosting', 'doblep/api'));
    }

    /**
     * Un `rm` que falla (exit distinto de cero) lanza con el motivo, y no llega a preguntar.
     *
     * @return void
     */
    public function test_un_rm_que_falla_lanza_y_no_pregunta(): void
    {
        $ssh = $this->ssh_que_graba("CACHE_NO\n", 1);

        try {
            $this->servicio_con($ssh, 'shared_hosting')->limpiar_cache_de_config_for($this->frente('shared_hosting', 'doblep/api'));

            $this->fail('Tenía que lanzar.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('borrar el config cacheado', $e->getMessage());
            $this->assertCount(1, $ssh->comandos);
        }
    }
}
