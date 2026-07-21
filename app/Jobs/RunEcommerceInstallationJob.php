<?php

namespace App\Jobs;

use App\Models\ClientEcommerceInstallation;
use App\Services\EcommerceDeploymentService;
use App\Services\EcommerceInstallationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job en cola que ejecuta el pipeline de instalación o actualización del ecommerce de un cliente.
 *
 * Espeja a `RunClientInstallationJob` (empresa): recibe la corrida (ClientEcommerceInstallation),
 * instancia el servicio correcto según su `mode` ('install' -> EcommerceInstallationService,
 * 'update' -> EcommerceDeploymentService) y corre el pipeline. A diferencia de
 * InstallationService/DeploymentService (empresa), ninguno de los dos servicios de ecommerce
 * necesita un `connect()` previo: cada etapa abre su propia sesión SSH/SFTP según haga falta.
 */
class RunEcommerceInstallationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Tiempo máximo de ejecución en segundos (30 min, igual que RunClientInstallationJob).
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
     * UUID de la ClientEcommerceInstallation a procesar.
     *
     * @var string
     */
    private $installation_uuid;

    /**
     * @param  string|ClientEcommerceInstallation  $installation_uuid  UUID o instancia de la corrida.
     */
    public function __construct($installation_uuid)
    {
        // Acepta tanto UUID string como instancia del modelo para facilitar el dispatch.
        if ($installation_uuid instanceof ClientEcommerceInstallation) {
            $installation_uuid = $installation_uuid->uuid;
        }

        $this->installation_uuid = (string) $installation_uuid;
    }

    /**
     * Carga la corrida, instancia el servicio correcto según su `mode` y ejecuta el pipeline.
     *
     * Si ocurre una excepción antes de que el servicio llegue a su propio try/catch interno (por
     * ejemplo, un error en el constructor al validar el `mode` o al resolver la tienda/cliente),
     * esta guardia marca la corrida como fallida para que nunca quede colgada en 'pendiente' o
     * 'instalando'. Si el servicio ya la marcó 'fallida' dentro de su `run()`, no la vuelve a tocar.
     *
     * @return void
     */
    public function handle()
    {
        // Carga la corrida con las relaciones que necesitan los servicios (tienda + cliente +
        // API activa del cliente, usada por write_env en el modo instalación).
        $installation = ClientEcommerceInstallation::where('uuid', $this->installation_uuid)
            ->with(['client_ecommerce.client.active_client_api'])
            ->firstOrFail();

        try {
            // Selecciona el servicio según el mode de la corrida: instalación desde cero (dentro
            // del try, para que un error del propio constructor también quede cubierto abajo).
            $service = $installation->mode === 'update'
                ? new EcommerceDeploymentService($installation)
                : new EcommerceInstallationService($installation);

            $service->run();
        } catch (\Throwable $e) {
            // Los servicios ya marcan status='fallida' (y restauran el status del ecommerce)
            // dentro de su propio run(). Esta guardia cubre el caso de que la excepción ocurra
            // antes de eso (constructor: mode inválido, tienda/cliente inexistente, etc.).
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
