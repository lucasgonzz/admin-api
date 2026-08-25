<?php

namespace App\Jobs;

use App\Console\Commands\VencerDeploymentsColgados;
use App\Events\DeploymentLogCreated;
use App\Models\ClientVersionUpgrade;
use App\Models\DeploymentLog;
use App\Services\DeploymentService;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job en cola que ejecuta el deployment de un upgrade (SSH + etapas).
 */
class RunDeploymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Tiempo máximo de ejecución, en segundos.
     *
     * 🔴 Es constante y no solo propiedad porque hay dos cosas afuera que dependen de este número y
     * tienen que moverse con él (misión 61):
     *
     *  - `config/queue.php` → `connections.database.retry_after`, que tiene que ser MAYOR. Si no,
     *    el job vuelve a quedar disponible mientras el primer worker lo sigue corriendo y termina
     *    en `failed_jobs` sin haber fallado.
     *  - `VencerDeploymentsColgados::min_timeout_minutos()`, el piso del umbral de vencimiento, que
     *    tiene que ser MAYOR para no marcar `failed` un deployment vivo.
     *
     * @var int
     */
    const TIMEOUT_SEGUNDOS = 1800;

    /**
     * @var int Tiempo máximo de ejecución (segundos).
     */
    public $timeout = self::TIMEOUT_SEGUNDOS;

    /**
     * @var int Sin reintentos automáticos.
     */
    public $tries = 1;

    /**
     * UUID del ClientVersionUpgrade a procesar.
     *
     * @var string
     */
    private $upgrade_uuid;

    /**
     * Etapa desde la cual reanudar (null = desde el inicio).
     *
     * @var string|null
     */
    private $resume_from_step;

    /**
     * @param  string|ClientVersionUpgrade  $upgrade_uuid
     * @param  string|null                  $resume_from_step
     */
    public function __construct($upgrade_uuid, $resume_from_step = null)
    {
        if ($upgrade_uuid instanceof ClientVersionUpgrade) {
            $upgrade_uuid = $upgrade_uuid->uuid;
        }

        $this->upgrade_uuid = (string) $upgrade_uuid;
        $this->resume_from_step = $resume_from_step;
    }

    /**
     * Conecta SSH y ejecuta el pipeline de deployment.
     *
     * @return void
     */
    public function handle()
    {
        $upgrade = ClientVersionUpgrade::where('uuid', $this->upgrade_uuid)
            ->with(['client', 'target_client_api', 'from_version', 'to_version'])
            ->firstOrFail();

        $service = new DeploymentService($upgrade);

        try {
            $service->connect();
            $service->run($this->resume_from_step);
        } catch (\Throwable $e) {
            $upgrade->refresh();
            if (
                $upgrade->deployment_status !== 'failed'
                && $upgrade->deployment_status !== 'paused'
                && $upgrade->deployment_status !== 'paused_post_tasks'
            ) {
                $upgrade->update(['deployment_status' => 'failed']);
            }
            throw $e;
        }
    }

    /**
     * Último recurso: el worker dio por fallado este job y `handle()` no llegó a escribir el estado.
     *
     * 🔴 Por qué hace falta además del `catch` de arriba (misión 61): ese `catch` solo corre para
     * excepciones capturables. Los dos caminos que más importan NO pasan por ahí:
     *
     *  - **El `$timeout` del worker**, que es un `SIGALRM` que mata el proceso: no hay `catch` que
     *    valga. Es la misma clase que el `max_execution_time` del 13/8/2026 —*"un `Throwable` se
     *    captura; un `max_execution_time` no es capturable"*—, un piso más abajo.
     *  - **`MaxAttemptsExceededException`**, que Laravel tira ANTES de entrar a `handle()`.
     *
     * En los dos casos el upgrade quedaba en `running` sin que nadie escribiera nada, y había que
     * esperar a que `deployments:vencer-colgados` lo levantara —que llega, pero recién a los 45
     * minutos SIN ACTIVIDAD, o sea más de una hora después del arranque—. Esto lo destraba en
     * segundos y deja al comando de vencimiento como lo que tiene que ser: la red de abajo, no el
     * único piso.
     *
     * @param \Throwable $e Motivo del fallo.
     *
     * @return void
     */
    public function failed(\Throwable $e)
    {
        $upgrade = ClientVersionUpgrade::where('uuid', $this->upgrade_uuid)->first();

        if ($upgrade === null) {
            return;
        }

        /* Solo desde `running`: `paused` y `paused_post_tasks` son pausas legítimas que el pipeline
         * escribió a propósito, y `failed` ya está donde queremos dejarlo. */
        $afectadas = ClientVersionUpgrade::where('id', $upgrade->id)
            ->where('deployment_status', 'running')
            ->update(['deployment_status' => 'failed']);

        /* 🔴 La línea de log se escribe SIEMPRE, desacoplada del UPDATE de arriba, y esto no es un
         * detalle: la primera versión de este método cortaba con un `return` cuando el UPDATE no
         * afectaba ninguna fila, y eso lo volvía código muerto en el camino MÁS COMÚN. El `catch`
         * de `handle()` ya escribió `failed` y re-tiró, así que para cuando el worker llama acá el
         * estado ya no es `running`, el CAS afecta 0 filas y el operador se quedaba sin ningún
         * motivo nuevo.
         *
         * El caso que lo vuelve grave: si lo que falló fue `connect()` —credencial SSH rechazada,
         * `target_client_api` en null—, `DeploymentService` nunca llegó a llamar a su `log()`, así
         * que el panel mostraba el deployment en rojo **con cero líneas** y sin una sola pista.
         *
         * Va a `deployment_logs` porque `client_version_upgrades` no tiene columna de error: es de
         * donde el panel lo muestra en rojo y de donde sale `logs.ultimo_error` de
         * `GET claude/upgrades/{id}`. */
        $deployment_log = DeploymentLog::create([
            'client_version_upgrade_id' => $upgrade->id,
            'step'                      => VencerDeploymentsColgados::STEP_VENCIMIENTO,
            'line'                      => $this->motivo_del_fallo($e, $afectadas === 1),
            'level'                     => 'error',
            'created_at'                => now(),
        ]);

        event(new DeploymentLogCreated($deployment_log));

        Log::warning('RunDeploymentJob: el job falló', [
            'upgrade_id'       => (int) $upgrade->id,
            'estado_reescrito' => $afectadas === 1,
            'excepcion'        => get_class($e),
            'motivo'           => $e->getMessage(),
        ]);
    }

    /**
     * Texto del motivo, que NO puede ser el mismo para los dos tipos de fallo.
     *
     * 🔴 `MaxAttemptsExceededException` no significa "el proceso murió": significa que la cola
     * volvió a considerar disponible este job y lo dio por agotado. El worker original **puede
     * seguir vivo, con el SSH abierto contra el hosting del cliente**. Decirle a alguien que
     * reintente en ese estado es pedirle que lance un segundo `DeploymentService` encima del
     * primero. La configuración está puesta para que esto no llegue a pasar —`retry_after` es el
     * más alto de los tres umbrales—, pero si llega, el texto tiene que decir la verdad.
     *
     * Y tampoco puede afirmar que el job "no pudo reportar" cuando sí reportó: si el estado ya
     * estaba en `failed`, el `catch` de `handle()` llegó a escribirlo y `DeploymentService`
     * probablemente ya dejó su propia línea de error. Ahí esta línea es un cierre, no la noticia.
     *
     * @param \Throwable $e                Motivo del fallo.
     * @param bool       $escribio_el_estado Si este método fue el que dejó el upgrade en `failed`.
     *
     * @return string
     */
    private function motivo_del_fallo(\Throwable $e, $escribio_el_estado)
    {
        if ($e instanceof MaxAttemptsExceededException) {
            return 'La cola dio por agotado este job, pero eso NO prueba que el proceso haya muerto: '
                . 'el worker original puede seguir corriendo el pipeline por SSH en este momento. '
                . '🔴 NO reintentes hasta confirmar en el servidor del cliente que no quedó nada '
                . 'corriendo. Detalle: ' . $e->getMessage();
        }

        if (! $escribio_el_estado) {
            return 'El job terminó fallado: ' . $e->getMessage()
                . '. El estado ya estaba escrito, así que el motivo real es la línea de error de '
                . 'más arriba.';
        }

        return 'El job del deployment murió sin poder reportar: ' . $e->getMessage()
            . '. Verificá en qué estado quedó el servidor del cliente antes de volver a arrancar.';
    }
}
