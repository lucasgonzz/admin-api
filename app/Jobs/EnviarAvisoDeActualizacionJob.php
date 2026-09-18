<?php

namespace App\Jobs;

use App\Models\ClientUpgradeNotice;
use App\Models\ClientVersionUpgrade;
use App\Services\AvisoDeActualizacionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Saca del request todo lo que el aviso de actualización manda para afuera.
 *
 * 🔴 **Por qué existe.** El disparo vive en el hook `saved` de `ClientVersionUpgrade`, que corre
 * adentro del request del pipeline de deployment (y también del `update_json` de la grilla del
 * admin-spa). Ahí adentro este aviso serían, en el peor caso, una llamada HTTP al `empresa-api`
 * del cliente, un SMTP y una llamada a Kapso — y esas tres, cada una con su timeout, alargarían
 * el cierre del upgrade. Peor: un fallo de cualquiera de ellas subiría por el `save()` y tiraría
 * abajo el cierre de un upgrade que salió perfecto.
 */
class EnviarAvisoDeActualizacionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Conexión de cola por la que sale este job, explícita.
     *
     * 🔴 **Explícita y no la default, y es lo único que hace que el punto anterior sea cierto.**
     * `QUEUE_CONNECTION` cae a `sync` cuando no está seteada, y en `sync` el job corre INLINE
     * adentro del request — es decir, exactamente lo que este job vino a evitar: el mail y las dos
     * llamadas HTTP saldrían dentro del `save()` del pipeline. Es la misma razón por la que
     * `AsistenteWhatsappService::CONEXION_DE_COLA` está fijada a `database` en este mismo repo, y
     * la misma clase de error que ya dejó tres demos mudas con `RunDemoSetupJob`.
     *
     * ⚠️ Precondición de infraestructura: al job lo corre el worker
     * `queue:work database --stop-when-empty` que el scheduler dispara cada minuto. Si ese cron no
     * corre, el aviso no sale — y la señal para mirar es la fila de `client_upgrade_notices` que
     * se queda en `pendiente`.
     */
    const CONEXION_DE_COLA = 'database';

    /**
     * Un solo intento.
     *
     * El caso de lejos más común de "no se pudo" es el cliente sin casilla —porque todavía corre
     * una versión sin el endpoint `admin-sync/contacto-dueno`—, y reintentar eso es quemar cola
     * contra algo que no va a cambiar en los próximos minutos. Lo que quede debiendo queda escrito
     * en la fila, que es de donde se levanta a mano.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Techo del job. Adentro hay a lo sumo una llamada al `empresa-api`, un SMTP y una a Kapso,
     * todas con timeout propio.
     *
     * @var int
     */
    public $timeout = 120;

    /**
     * Id de la actualización que se cerró.
     *
     * Viaja el id y no el modelo a propósito: entre el despacho y el momento en que el worker lo
     * toma puede pasar un minuto, y lo que interesa es el estado de la fila cuando el job corre.
     *
     * @var int
     */
    private $client_version_upgrade_id;

    /**
     * @param int $client_version_upgrade_id Id de la actualización recién cerrada.
     */
    public function __construct(int $client_version_upgrade_id)
    {
        $this->client_version_upgrade_id = $client_version_upgrade_id;
    }

    /**
     * Manda el aviso.
     *
     * 🔴 **No deja escapar nada.** Un aviso que falla no es un job fallido que haya que mirar en
     * `failed_jobs`: el upgrade cerró bien y el resultado del aviso —incluido el fracaso— ya queda
     * escrito en `client_upgrade_notices`, que es donde se lo busca.
     *
     * @param AvisoDeActualizacionService $service Inyectado por el contenedor.
     *
     * @return void
     */
    public function handle(AvisoDeActualizacionService $service): void
    {
        try {
            $upgrade = ClientVersionUpgrade::find($this->client_version_upgrade_id);

            if (! $upgrade instanceof ClientVersionUpgrade) {
                Log::channel('daily')->warning('EnviarAvisoDeActualizacionJob: la actualización ya no existe.', [
                    'client_version_upgrade_id' => $this->client_version_upgrade_id,
                ]);

                return;
            }

            $service->avisar($upgrade);
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('EnviarAvisoDeActualizacionJob: se cayó el aviso.', [
                'client_version_upgrade_id' => $this->client_version_upgrade_id,
                'error'                     => $exception->getMessage(),
            ]);

            $this->dejar_el_motivo_escrito($exception->getMessage());
        }
    }

    /**
     * Último recurso: si el servicio se cayó antes de poder escribir el motivo, se escribe acá.
     *
     * @param string $motivo
     *
     * @return void
     */
    private function dejar_el_motivo_escrito(string $motivo): void
    {
        try {
            ClientUpgradeNotice::where('client_version_upgrade_id', $this->client_version_upgrade_id)
                ->where('estado', ClientUpgradeNotice::ESTADO_PENDIENTE)
                ->update([
                    'estado' => ClientUpgradeNotice::ESTADO_ERROR,
                    'error'  => $motivo,
                ]);
        } catch (\Throwable $ignorado) {
            // Si ni siquiera se puede escribir la fila, el log de arriba es todo lo que queda.
        }
    }
}
