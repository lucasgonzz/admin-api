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
 * compilación del SPA, subida del SPA, subida del API y demo-setup remoto.
 */
class RunDemoUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Tiempo máximo de ejecución en segundos (30 minutos).
     * El pipeline SSH puede tardar bastante según tamaño del proyecto y velocidad del hosting.
     *
     * @var int
     */
    public $timeout = 1800;

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
}
