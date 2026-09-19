<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\ClientSessionLockSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Empuja el candado de sesión por pestaña de un cliente a su empresa-api, fuera del request que lo
 * guardó.
 *
 * 🔴 Calcado de `SyncClientScheduleJob`: se despacha SIEMPRE con `->onConnection('database')`
 * explícito, nunca a secas. `config/queue.php` es `env('QUEUE_CONNECTION', 'sync')` y en la máquina
 * de Lucas vale `sync`: un `dispatch()` pelado correría este job INLINE, adentro del request que
 * guardó el interruptor. Con `config('services.client_api.timeout')` en 15 s y `->retry(2, 500)`,
 * un cliente con la API caída le sumaría hasta ~45 segundos de espera al modal del admin, por un
 * efecto secundario que a quien está guardando el interruptor no le importa en ese momento.
 *
 * Lo levanta el `queue:work database --stop-when-empty` que el scheduler corre cada minuto.
 */
class SyncClientSessionLockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 🔴 Sin reintentos de cola, mismo motivo que `SyncClientScheduleJob`: el servicio ya reintenta
     * el HTTP por su cuenta (`->retry(2, 500)`) y todos los desenlaces posibles terminan escribiendo
     * `pestanas_sync_status`. Un `manual_required` o un `failed` se reintenta a mano desde el botón
     * de la pestaña.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Id del cliente.
     *
     * 🔴 Se serializa el ID y NO el modelo: entre el despacho y la ejecución pueden pasar varios
     * minutos, y en el medio el interruptor puede haberse guardado de nuevo. Con el modelo
     * serializado se estaría empujando una foto vieja; con el id, el job siempre lee lo último.
     *
     * @var int
     */
    private $client_id;

    /**
     * @param Client|int $client Cliente o su id.
     */
    public function __construct($client)
    {
        if ($client instanceof Client) {
            $client = $client->id;
        }

        $this->client_id = (int) $client;
    }

    /**
     * Lee el cliente al momento de correr y empuja su interruptor.
     *
     * @param ClientSessionLockSyncService $service Servicio que hace el push.
     *
     * @return void
     */
    public function handle(ClientSessionLockSyncService $service)
    {
        $client = Client::find($this->client_id);

        if ($client === null) {
            Log::warning('SyncClientSessionLockJob: el cliente ya no existe.', ['client_id' => $this->client_id]);

            return;
        }

        // El servicio no lanza nunca: todos los desenlaces quedan escritos en el cliente.
        $service->sync($client);
    }
}
