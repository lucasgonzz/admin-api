<?php

namespace App\Jobs;

use App\Models\ClientInstallation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Corre la instalación del sistema de un cliente que pidió `POST claude/implementations/{id}/install`:
 * el par de filas (la instalación real y el esqueleto del subdominio hermano), una atrás de la otra.
 *
 * 🔴 POR QUÉ ESTE JOB Y NO `RunClientInstallationGroupJob` DIRECTO. El de grupo existe y hace exactamente
 * el trabajo, pero declara `$timeout = 5700` (el doble del de una instalación más un margen) y la
 * conexión `database` tiene `retry_after = 2400`. Un job en esa conexión que dura más que `retry_after`
 * vuelve a quedar disponible mientras el primer worker lo sigue corriendo bien, y el worker del tick
 * siguiente lo reserva, ve `attempts > tries` y lo manda a `failed_jobs` con
 * `MaxAttemptsExceededException` sin haber fallado (el comentario de `config/queue.php` lo explica
 * entero). `RobustezDelDeploymentDesatendidoTest::test_ningun_job_de_la_conexion_supera_el_retry_after`
 * lo vigila: despachar el de grupo con `->onConnection('database')` lo pone en rojo. El panel lo corre en
 * la conexión por defecto (`sync`), donde nada de esto existe, y por eso nunca se notó.
 *
 * La salida es el patrón del ecommerce (`RunEcommerceInstallationJob`, `$timeout = 1800`): un job propio
 * cuyo `$timeout` queda POR DEBAJO de `retry_after`, y que delega todo el trabajo en el que ya existe. Acá
 * no se copia ni una línea del pipeline: `handle()` construye el `RunClientInstallationGroupJob` y lo
 * ejecuta. Cualquier arreglo que se le haga a aquél vale para los dos caminos.
 *
 * 🔴 EL NÚMERO. 2300 s (38 min 20 s), 100 por debajo de `retry_after`. Una instalación tarda ~15 minutos
 * de pipeline (la real; el esqueleto son unos pocos más), y la peor que se midió, ~28 en la real: cabe con
 * holgura. NO cubre el peor caso teórico del job de grupo (5700), que incluye esperas de DNS que solo
 * existen con el aprovisionamiento del VPS, y el endpoint de Claude instala SOLO en el hosting compartido
 * (con el aprovisionamiento del compartido la espera es de 30 s). Si una instalación pasara de los 38
 * minutos el worker la corta, y `failed()` deja las filas en `fallida` con ese motivo en vez de dejarlas
 * clavadas en `instalando` para siempre.
 *
 * ⚠️ Y el `$timeout` solo corta si el CLI del servidor tiene `pcntl` (`Worker::supportsAsyncSignals()`):
 * sin él es letra muerta y un pipeline colgado no tiene cota superior. Es el mismo agujero que
 * `config/queue.php` documenta para `RunDeploymentJob`.
 *
 * Si movés `$timeout`: tiene que seguir por debajo de `connections.database.retry_after`, y hay un test
 * que lo ata (`InstalacionDeImplementacionPorClaudeTest`).
 */
class EjecutarInstalacionDeImplementacionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Tiempo máximo de ejecución, en segundos. 🔴 Por debajo de `retry_after` (2400): ver el docblock.
     *
     * @var int
     */
    public $timeout = 2300;

    /**
     * Sin reintentos automáticos: un fallo de instalación se analiza a mano (los recursos del hosting
     * pueden estar a medio crear) y la fila queda `fallida` con su motivo.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * UUIDs de las instalaciones, YA ORDENADOS: la real primero.
     *
     * @var array<int, string>
     */
    private $installation_uuids;

    /**
     * @param array<int, string|ClientInstallation> $installation_uuids Los uuids (o las filas) del par, la real primero.
     */
    public function __construct(array $installation_uuids)
    {
        $uuids = [];

        foreach ($installation_uuids as $uuid) {
            $uuids[] = $uuid instanceof ClientInstallation ? (string) $uuid->uuid : (string) $uuid;
        }

        $this->installation_uuids = $uuids;
    }

    /**
     * Corre las instalaciones del par, una atrás de la otra.
     *
     * Es literalmente el `handle()` del job de grupo: sus filas se cargan adentro de un try, cada fallo se
     * deja en la fila de esa instalación y el de una no se lleva puesta a la otra.
     *
     * @return void
     */
    public function handle()
    {
        (new RunClientInstallationGroupJob($this->installation_uuids))->handle();
    }

    /**
     * Último recurso: el worker dio por fallado este job y `handle()` no llegó a escribir el estado.
     *
     * 🔴 Hace falta además de lo que ya hace el job de grupo (que marca `fallida` cada fila cuyo pipeline
     * revienta con una excepción capturable). Dos caminos NO pasan por ahí: el `$timeout` del worker, que
     * es un `SIGALRM` que mata el proceso sin dejar `catch` que valga, y `MaxAttemptsExceededException`,
     * que Laravel tira ANTES de entrar a `handle()`. En los dos las filas quedarían en `instalando`, y
     * como `install` frena con 409 mientras haya una instalando, ese cliente no podría reintentar nunca.
     *
     * Solo toca las filas que SIGUEN en `instalando` (con un UPDATE condicional): una que ya terminó
     * `completada` o quedó `fallida` con su propio motivo no se pisa.
     *
     * @param \Throwable $e Motivo del fallo.
     *
     * @return void
     */
    public function failed(\Throwable $e)
    {
        ClientInstallation::query()
            ->whereIn('uuid', $this->installation_uuids)
            ->where('status', 'instalando')
            ->update([
                'status'         => 'fallida',
                'finished_at'    => now(),
                'failure_reason' => 'El job de instalación se cortó antes de terminar: ' . $e->getMessage()
                    . ' Si el motivo es un timeout, la instalación pudo haber quedado a medio hacer en el hosting: '
                    . 'revisá los logs antes de volver a instalar.',
            ]);
    }
}
