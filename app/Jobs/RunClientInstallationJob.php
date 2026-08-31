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
     * Tiempo máximo de ejecución en segundos: 45 minutos.
     *
     * 🔴 NO son los 1800 (30 min) de RunDeploymentJob, y dejó de serlo el 31/8/2026. Desde que el
     * pipeline puede aprovisionar el hosting, este job incluye la espera de propagación del DNS de
     * provision_ssl: cada uno de los 4 dominios espera hasta `services.hostinger.dns_wait_seconds`
     * (180 s por default) en sondas de 15 s, o sea hasta 720 s de sleep ADEMÁS de los ~15 minutos
     * que ya tardaba compilar el SPA y subir la API. Con 1800, una instalación en VPS con el DNS
     * lento se quedaba sin tiempo justo en el último paso: el worker mataba el job con los 4 sitios,
     * el DNS, la base y el cron ya hechos, y la fila quedaba 'instalando' para siempre.
     *
     * 1800 (el techo que ya tenía el pipeline sin aprovisionamiento) + 720 (la espera del peor caso)
     * = 2520, redondeado a 2700 para que un cambio chico de `dns_wait_seconds` no lo cruce.
     * `AprovisionamientoDeHostingDelClienteTest` ata los dos números por test: si alguien sube
     * dns_wait_seconds sin subir esto, se pone rojo.
     *
     * ⚠️ Si alguna vez estos jobs se encolan en la conexión `database` (hoy corren en la default,
     * que es `sync`), este número tiene que quedar por debajo de su `retry_after` — hoy 2400. Lo
     * mira RobustezDelDeploymentDesatendidoTest.
     *
     * @var int
     */
    public $timeout = 2700;

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

        try {
            // 🔴 El `new InstallationService` va ADENTRO del try. El constructor puede tirar
            // excepción —rechaza un esqueleto sobre una API en VPS, y falla si la fila no tiene
            // API destino—, y desde afuera del try esa excepción se llevaba el job entero dejando
            // la fila clavada en 'instalando': el listado la muestra corriendo, no corre nada, y
            // start() no la reintenta porque ya no está en 'pendiente'.
            $service = new InstallationService($installation);

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
