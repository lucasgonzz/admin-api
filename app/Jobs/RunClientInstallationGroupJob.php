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
 * Job en cola que corre EN SECUENCIA todas las instalaciones de un mismo grupo.
 *
 * Un grupo es el par que se crea de una sola vez desde el modal: la instalación real sobre una
 * ClientApi del cliente y el esqueleto sobre la otra. Se corren de a una, con la real primero.
 *
 * 🔴 Un solo job para las dos, y no dos dispatch: dos jobs saldrían a la cola sin orden garantizado
 * y abrirían dos sesiones SSH en paralelo contra el mismo hosting compartido. El orden —real
 * primero— es un pedido explícito, no una casualidad del scheduler: la real es la larga y es la
 * que el operador mira en el log en vivo.
 *
 * RunClientInstallationJob sigue existiendo y sigue siendo el camino de una instalación suelta.
 */
class RunClientInstallationGroupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Tiempo máximo de ejecución en segundos (30 min, igual que RunClientInstallationJob).
     *
     * Es el mismo número aunque acá corran dos instalaciones: el esqueleto tarda menos de un
     * minuto (no compila el SPA ni sube el código de la API), así que el techo real lo sigue
     * poniendo la instalación completa.
     *
     * @var int
     */
    public $timeout = 1800;

    /**
     * Sin reintentos automáticos: los fallos se analizan a mano.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * UUIDs de las instalaciones del grupo, YA ORDENADOS: la instalación real primero.
     *
     * @var array<int, string>
     */
    private $installation_uuids;

    /**
     * @param  array<int, string>  $installation_uuids  Ya ordenados: la instalación real primero.
     */
    public function __construct(array $installation_uuids)
    {
        $uuids = [];
        foreach ($installation_uuids as $installation_uuid) {
            // Acepta instancias además de UUIDs, igual que RunClientInstallationJob, para que el
            // llamador no tenga que hacer el pluck a mano.
            if ($installation_uuid instanceof ClientInstallation) {
                $installation_uuid = $installation_uuid->uuid;
            }

            $uuids[] = (string) $installation_uuid;
        }

        $this->installation_uuids = $uuids;
    }

    /**
     * Corre cada instalación del grupo en orden, cada una aislada de la anterior.
     *
     * @return void
     */
    public function handle()
    {
        foreach ($this->installation_uuids as $installation_uuid) {
            $installation = ClientInstallation::where('uuid', $installation_uuid)
                ->with(['client', 'client_api', 'version'])
                ->first();

            // La fila puede haberse borrado entre el dispatch y la corrida (destroy() lo permite
            // mientras no esté 'instalando'). No es un error del grupo: se sigue con la que queda.
            if ($installation === null) {
                continue;
            }

            $this->run_one($installation);
        }
    }

    /**
     * Corre UNA instalación del grupo, sin dejar que su fallo se lleve puesta a la siguiente.
     *
     * 🔴 El catch por fila NO es una atrapada perezosa: es el requisito. Si el esqueleto falla, la
     * instalación real tiene que quedar 'completada' igual, y al revés. Un ->chain() de Laravel
     * haría exactamente lo contrario —cortaría la cadena en el primer fallo— y por eso no se usa.
     *
     * Tampoco se re-lanza al final del handle(): con tries=1 un re-lanzamiento no reintenta nada y
     * lo único que consigue es ensuciar failed_jobs con un grupo en el que una de las dos filas sí
     * funcionó. El estado real de cada instalación queda en su propia fila, que es donde el
     * operador lo mira.
     *
     * @param  ClientInstallation  $installation
     * @return void
     */
    private function run_one(ClientInstallation $installation): void
    {
        try {
            $service = new InstallationService($installation);
            $service->connect();
            $service->run();
        } catch (\Throwable $e) {
            // InstallationService::run() ya marca status=fallida con su motivo. Esta guarda cubre el
            // caso de que la excepción salga antes: el constructor rechazando un destino en VPS, o
            // connect() con las credenciales caídas.
            $installation->refresh();
            if ($installation->status !== 'fallida') {
                $installation->update([
                    'status'         => 'fallida',
                    'finished_at'    => now(),
                    'failure_reason' => $e->getMessage(),
                ]);
            }
        }
    }
}
