<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\ClientScheduleSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Empuja los horarios comerciales de un cliente a su empresa-api, fuera del request que los guardó.
 *
 * 🔴 Se despacha SIEMPRE con `->onConnection('database')` explícito, nunca a secas.
 * `config/queue.php` es `env('QUEUE_CONNECTION', 'sync')` y en la máquina de Lucas vale `sync`: un
 * `dispatch()` pelado correría este job INLINE, adentro del request del PUT de horarios. Con
 * `config('services.client_api.timeout')` en 15 s y `->retry(2, 500)`, un cliente con la API caída
 * le sumaría hasta ~45 segundos de espera al modal del admin, por un efecto secundario que a quien
 * está guardando los horarios no le importa en ese momento. Es la misma lección que ya está escrita
 * en `RunDemoSetupJob` (líneas 18-42) y en `app/Console/Kernel.php`.
 *
 * Lo levanta el `queue:work database --stop-when-empty` que el scheduler corre cada minuto. La
 * latencia de hasta 60 segundos hasta el próximo tick es el costo asumido y es irrelevante acá:
 * nadie está esperando que el horario llegue al cliente en el mismo segundo.
 */
class SyncClientScheduleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 🔴 Sin reintentos de cola. El servicio ya reintenta el HTTP por su cuenta (`->retry(2, 500)`)
     * y todos los desenlaces posibles terminan escribiendo `schedule_sync_status`: no hay ningún
     * fallo que un reintento del worker pueda mejorar, y sí habría ruido de tres pushes seguidos
     * contra la instancia del cliente. Un `manual_required` o un `failed` se reintenta a mano desde
     * el botón de la pestaña o desde `POST claude/clients/{id}/schedule/sync`.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Id del cliente.
     *
     * 🔴 Se serializa el ID y NO el modelo: entre el despacho y la ejecución pueden pasar varios
     * minutos, y en el medio los horarios pueden haberse guardado de nuevo. Con el modelo
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
     * Arma el payload al momento de correr y lo empuja.
     *
     * @param ClientScheduleSyncService $service Servicio que arma el payload y hace el push.
     *
     * @return void
     */
    public function handle(ClientScheduleSyncService $service)
    {
        $client = Client::find($this->client_id);

        if ($client === null) {
            Log::warning('SyncClientScheduleJob: el cliente ya no existe.', ['client_id' => $this->client_id]);

            return;
        }

        // El servicio no lanza nunca: todos los desenlaces quedan escritos en el cliente.
        $service->sync($client);
    }
}
