<?php

namespace App\Jobs;

use App\Models\DemoUpdate;
use App\Services\DemoUpdateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job en cola que ejecuta el pipeline completo de actualización de una demo:
 * compilación del SPA, subida del SPA, subida del API y migraciones en el hosting.
 *
 * El demo-setup NO forma parte de este pipeline: se dispara desde el módulo de Leads.
 */
class RunDemoUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Tiempo máximo de ejecución en segundos (60 minutos).
     * El pipeline SSH puede tardar bastante: npm ci + build + SFTP + composer install en
     * hosting compartido. Si se excede, failed() marca el registro como fallido.
     *
     * Importante (13/7/2026): el --timeout del worker de la cola (supervisor / queue:work)
     * tiene que ser mayor o igual que este valor, o el worker mata el proceso antes.
     *
     * @var int
     */
    public $timeout = 3600;

    /**
     * Sin reintentos automáticos: un fallo en el pipeline requiere revisión manual del log.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * UUID del DemoUpdate a procesar.
     * Se serializa el UUID (string) en lugar del modelo para evitar stale data al ejecutar.
     *
     * @var string
     */
    private $demo_update_uuid;

    /**
     * Acepta un DemoUpdate o su UUID directamente para mayor flexibilidad al despachar.
     *
     * @param  DemoUpdate|string  $demo_update
     */
    public function __construct($demo_update)
    {
        if ($demo_update instanceof DemoUpdate) {
            $demo_update = $demo_update->uuid;
        }

        $this->demo_update_uuid = (string) $demo_update;
    }

    /**
     * Instancia el DemoUpdateService fresco desde la base de datos y ejecuta el pipeline.
     *
     * @return void
     */
    public function handle()
    {
        // Recarga el modelo fresco con relaciones para evitar datos desactualizados.
        $demo_update = DemoUpdate::where('uuid', $this->demo_update_uuid)
            ->with(['demo', 'version', 'created_by_admin'])
            ->firstOrFail();

        $service = new DemoUpdateService($demo_update);
        $service->run();
    }

    /**
     * Se ejecuta cuando el job falla definitivamente (excepción, timeout del worker, OOM,
     * worker reiniciado). Marca el DemoUpdate como fallido para que no quede colgado en
     * `ejecutandose`: el frontend hace polling contra ese estado y el spinner no para nunca.
     *
     * Idempotente: si el service ya lo marcó como fallido o completado, no toca nada.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        // Se busca el registro fresco: el $this->demo_update_uuid es lo único serializado.
        $demo_update = DemoUpdate::where('uuid', $this->demo_update_uuid)->first();
        if ($demo_update === null) {
            return;
        }

        // Idempotencia: si el service ya cerró el registro, no se pisa el resultado real.
        if ($demo_update->status === 'completado' || $demo_update->status === 'fallido') {
            return;
        }

        $demo_update->status      = 'fallido';
        $demo_update->finished_at = now();
        $demo_update->log         = ($demo_update->log === null ? '' : $demo_update->log)
            . '[' . now()->format('H:i:s') . '] ERROR (job): ' . $exception->getMessage() . "\n";
        $demo_update->save();
    }
}
