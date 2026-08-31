<?php

namespace App\Jobs;

use App\Models\DemoInstallation;
use App\Services\DemoInstallationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job en cola que corre el pipeline completo de instalación desde cero del sistema de una demo.
 *
 * 🔴 El pipeline es DESTRUCTIVO en su etapa 8 (`run_demo_setup` le hace `migrate:fresh` a la base
 * de la demo). Eso condiciona las dos propiedades de abajo — leelas antes de tocarlas.
 */
class RunDemoInstallationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Tiempo máximo de ejecución, en segundos (35 minutos).
     *
     * 🔴 TIENE QUE QUEDAR POR DEBAJO del `retry_after` de la conexión `database` (2400). El motivo
     * está medido y escrito en RunDemoUpdateJob::$timeout: si el timeout del job supera el
     * retry_after, la cola vuelve a marcar el job disponible mientras el primer worker lo sigue
     * corriendo bien, el worker siguiente lo reserva, ve `attempts > tries` y lo manda a
     * `failed_jobs` con MaxAttemptsExceededException. La instalación aparece fallida sin haber
     * fallado — y acá eso es peor que en un update, porque el operador que ve "fallida" vuelve a
     * disparar la instalación y con ella un segundo `migrate:fresh`.
     *
     * 2100 s alcanza de sobra: el pipeline más largo es npm ci + npm run build (que es lo mismo
     * que corre un update de demo, con $timeout = 2100) más un demo-setup que contra una base
     * virgen se midió en ~109 s.
     *
     * El `--timeout` del worker (supervisor / queue:work) tiene que ser mayor o igual que esto o
     * mata el proceso antes.
     *
     * @var int
     */
    public $timeout = 2100;

    /**
     * Sin reintentos automáticos.
     *
     * 🔴 Y acá no es sólo "un fallo requiere revisión manual del log", como en un update. Un
     * reintento automático volvería a correr la etapa `run_demo_setup`, o sea un segundo
     * `migrate:fresh` sobre una instancia que puede estar todavía procesando el primero. Es
     * exactamente la secuencia que vació la base de una demo en producción y que
     * RunDemoSetupService documenta en detalle.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * UUID de la DemoInstallation a procesar.
     *
     * Se serializa el UUID y no el modelo para no arrastrar datos viejos: entre que se despacha y
     * que un worker lo toma pueden pasar minutos, y el estado de la fila cambia.
     *
     * @var string
     */
    private $demo_installation_uuid;

    /**
     * Acepta una DemoInstallation o su UUID directamente.
     *
     * @param  DemoInstallation|string  $demo_installation
     */
    public function __construct($demo_installation)
    {
        if ($demo_installation instanceof DemoInstallation) {
            $demo_installation = $demo_installation->uuid;
        }

        $this->demo_installation_uuid = (string) $demo_installation;
    }

    /**
     * Recarga la corrida fresca desde la base y ejecuta el pipeline.
     *
     * @return void
     */
    public function handle()
    {
        $installation = DemoInstallation::where('uuid', $this->demo_installation_uuid)
            ->with(['demo', 'version'])
            ->firstOrFail();

        $service = new DemoInstallationService($installation);
        $service->run();
    }

    /**
     * Se ejecuta cuando el job falla definitivamente (excepción, timeout del worker, OOM, worker
     * reiniciado). Marca la corrida como fallida para que no quede colgada en `instalando`: el
     * panel hace polling contra ese estado y el spinner no para nunca.
     *
     * Idempotente: si el service ya la cerró, no se pisa el resultado real.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        $installation = DemoInstallation::where('uuid', $this->demo_installation_uuid)->first();
        if ($installation === null) {
            return;
        }

        if ($installation->status === DemoInstallation::STATUS_COMPLETADA
            || $installation->status === DemoInstallation::STATUS_FALLIDA) {
            return;
        }

        $installation->status         = DemoInstallation::STATUS_FALLIDA;
        $installation->finished_at    = now();
        $installation->failure_reason = 'El job terminó sin completar el pipeline: ' . $exception->getMessage();
        $installation->save();
    }
}
