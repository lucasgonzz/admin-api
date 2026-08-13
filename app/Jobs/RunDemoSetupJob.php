<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Services\RunDemoSetupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Dispara el demo setup de un lead apenas completa el formulario de la página inmersiva
 * (misión 46, pieza 2).
 *
 * 🔴 Se despacha SIEMPRE con `->afterResponse()`, nunca a secas. `.env` declara
 * `QUEUE_CONNECTION=sync`, así que un `dispatch()` común correría INLINE y le bloquearía al lead la
 * respuesta del formulario hasta 300 segundos — el timeout de la llamada HTTP al empresa-api de la
 * demo. Con `afterResponse()` el trabajo corre después de mandada la respuesta y no depende del
 * driver de cola.
 *
 * Y no se encola como job normal por otro motivo: `queue:work --stop-when-empty` también corre
 * `everyMinute()`, así que un job en cola tendría exactamente la misma latencia que el comando
 * `leads:run-demo-setup` y no compraría nada. Lo que esta pieza viene a ganar son justamente esos
 * hasta 60 segundos, sobre un margen total de 5 minutos.
 */
class RunDemoSetupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Tiempo máximo de ejecución en segundos. El setup remoto hace migraciones y seeders sobre la
     * instancia de la demo: el propio service usa timeout de config('services.client_api.timeout')
     * por 20 (300s con el default), así que este techo va por encima.
     *
     * @var int
     */
    public $timeout = 600;

    /**
     * 🔴 Sin reintentos. Un reintento automático volvería a hacer `migrate:fresh` sobre una
     * instancia que puede tener a alguien adentro — el lead ya entró y le vaciarían la base abajo
     * de los pies. Un fallo se ve en `demo_setup_status = fallido` y lo re-dispara Lucas desde el
     * panel.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Id del lead. Se serializa el id y no el modelo para no arrastrar un estado viejo: entre el
     * despacho y la ejecución el comando `leads:run-demo-setup` puede haber tomado el mismo lead.
     *
     * @var int
     */
    private $lead_id;

    /**
     * @param Lead|int $lead Lead o su id.
     */
    public function __construct($lead)
    {
        if ($lead instanceof Lead) {
            $lead = $lead->id;
        }

        $this->lead_id = (int) $lead;
    }

    /**
     * Corre el setup con el claim atómico puesto.
     *
     * @param RunDemoSetupService $service
     *
     * @return void
     */
    public function handle(RunDemoSetupService $service)
    {
        $lead = Lead::find($this->lead_id);
        if ($lead === null) {
            Log::warning('RunDemoSetupJob: el lead ya no existe.', ['lead_id' => $this->lead_id]);

            return;
        }

        /* true = claim atómico. El comando puede haber levantado a este mismo lead en el minuto que
         * pasó entre el despacho y esta ejecución; en ese caso el claim no afecta ninguna fila y el
         * service se va sin hacer nada. */
        $service->run($lead, true);
    }
}
