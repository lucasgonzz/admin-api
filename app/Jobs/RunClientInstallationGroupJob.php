<?php

namespace App\Jobs;

use App\Models\ClientInstallation;
use App\Services\InstallationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
     * Tiempo máximo de ejecución en segundos: 95 minutos.
     *
     * 🔴 NO es el mismo número que RunClientInstallationJob (2700 = 45 min) y no puede serlo. Ese
     * job corre UN pipeline; éste corre DOS, uno atrás del otro, adentro del mismo handle(). Con
     * 1800, una instalación real que tarda 28 minutos —que está dentro de lo normal: compila el
     * SPA, sube el ZIP de la API y corre composer install en un hosting compartido— deja al
     * esqueleto arrancando en el minuto 28 y el worker lo mata en el 30, dejando su fila clavada en
     * 'instalando' para siempre.
     *
     * El número sale de ahí: 2700 (el techo que RunClientInstallationJob declara para un pipeline)
     * × 2 = 5400, más 300 segundos de margen para lo que pasa ENTRE las dos corridas (la reconexión
     * SSH y la lectura de la segunda fila) = 5700. Si algún día RunClientInstallationJob sube su
     * timeout, éste tiene que subir con él: es el doble más el margen, no un número suelto.
     *
     * 🔴 Y el 31/8/2026 pasó exactamente eso: el otro subió de 1800 a 2700 —porque el pipeline
     * ahora incluye la espera de propagación del DNS del certificado— y éste subió con él, de 3900
     * a 5700. La fórmula no cambió; cambió su base. El doble es conservador a propósito: la espera
     * del certificado la paga solo la fila real (el esqueleto ni siquiera tiene los pasos de cron y
     * SSL en su pipeline), así que el peor caso verdadero es menor. Es más barato sobrar tiempo que
     * que el worker mate una instalación a mitad. Lo ata por test
     * InstalacionEsqueletoEnElSubdominioSecundarioTest::test_el_timeout_del_job_de_grupo_cubre_las_dos_corridas().
     *
     * @var int
     */
    public $timeout = 5700;

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
            $this->run_one($installation_uuid);
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
     * 🔴 La fila se CARGA adentro del try, no afuera. Un timeout de base o una conexión caída al
     * leerla tiraba el job entero desde el foreach de handle() y dejaba a las DOS filas clavadas en
     * 'instalando' —la que falló al leerse y la que ni siquiera llegó a intentarse—, que es el peor
     * estado posible: el listado las muestra corriendo y no corre nada, y start() no las reintenta
     * porque ya no están en 'pendiente'.
     *
     * @param  string  $installation_uuid
     * @return void
     */
    private function run_one(string $installation_uuid): void
    {
        $installation = null;

        try {
            $installation = ClientInstallation::where('uuid', $installation_uuid)
                ->with(['client', 'client_api', 'version'])
                ->first();

            // La fila puede haberse borrado entre el dispatch y la corrida (destroy() lo permite
            // mientras no esté 'instalando'). No es un error del grupo: se sigue con la que queda.
            if ($installation === null) {
                return;
            }

            $service = new InstallationService($installation);
            $service->connect();
            $service->run();
        } catch (\Throwable $e) {
            if ($installation === null) {
                // La caída fue leyendo la fila: no hay dónde dejar el motivo, así que queda en el
                // log de la aplicación y se sigue con la siguiente del grupo. Cortar acá no
                // arreglaría esta fila y además se llevaría puesta a la hermana.
                Log::error(
                    'RunClientInstallationGroupJob: no se pudo cargar la instalación '
                    . $installation_uuid . ' (' . $e->getMessage() . '). Se sigue con el resto del grupo.'
                );

                return;
            }

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
