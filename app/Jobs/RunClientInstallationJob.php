<?php

namespace App\Jobs;

use App\Models\ClientInstallation;
use App\Services\InstallationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job en cola que ejecuta el pipeline completo de instalación inicial de un sistema.
 *
 * Recibe el ID de la ClientInstallation, instancia InstallationService y llama a run().
 * En caso de excepción marca el status como 'fallida' y persiste el mensaje en failure_reason.
 */
class RunClientInstallationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Tiempo máximo de ejecución en segundos (30 min, igual que RunDeploymentJob).
     *
     * @var int
     */
    public $timeout = 1800;

    /**
     * Sin reintentos automáticos: los fallos deben analizarse manualmente.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * UUID de la ClientInstallation a procesar.
     *
     * @var string
     */
    private $installation_uuid;

    /**
     * @param  string|ClientInstallation  $installation_uuid  UUID o instancia de la instalación.
     */
    public function __construct($installation_uuid)
    {
        // Acepta tanto UUID string como instancia del modelo para facilitar el dispatch.
        if ($installation_uuid instanceof ClientInstallation) {
            $installation_uuid = $installation_uuid->uuid;
        }

        $this->installation_uuid = (string) $installation_uuid;
    }

    /**
     * Ejecuta el pipeline de instalación: SSH + etapas.
     *
     * Si ocurre una excepción que InstallationService no captura (por ejemplo, error de
     * conexión SSH antes de entrar al pipeline), marca la instalación como fallida aquí.
     *
     * @return void
     */
    public function handle()
    {
        // Carga la instalación con sus relaciones para el servicio.
        $installation = ClientInstallation::where('uuid', $this->installation_uuid)
            ->with(['client', 'client_api', 'version'])
            ->firstOrFail();

        $service = new InstallationService($installation);

        try {
            // connect() abre la sesión SSH al hosting; run() ejecuta el pipeline completo.
            $service->connect();
            $service->run();
        } catch (\Throwable $e) {
            // InstallationService ya marca status=fallida en run(). Esta guardia cubre el
            // caso de que la excepción ocurra antes de que run() llegue a hacerlo.
            $installation->refresh();
            if ($installation->status !== 'fallida') {
                $installation->update([
                    'status'         => 'fallida',
                    'finished_at'    => now(),
                    'failure_reason' => $e->getMessage(),
                ]);
            }
            throw $e;
        }
    }
}
