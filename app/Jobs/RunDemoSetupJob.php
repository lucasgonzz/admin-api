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
 * 🔴 Se despacha SIEMPRE con `->onConnection('database')`, nunca a secas y nunca con
 * `afterResponse()` (misión 60, 14/8/2026). Este docblock decía lo contrario y **las dos premisas
 * que daba eran falsas**; quedan escritas acá para que nadie las vuelva a dar por buenas:
 *
 *  1. *"Con `afterResponse()` el trabajo corre después de mandada la respuesta"*. No.
 *     `Dispatcher::dispatchAfterResponse()` de `laravel/framework` v8.83.29 registra un
 *     `container->terminating(...)` que llama a **`dispatchNow()`**: el job corre inline, en este
 *     mismo proceso, y nunca lo toma un worker. Corolario incómodo: el `$timeout` y el `$tries` de
 *     acá abajo **no regían nada**, porque son ajustes de worker de cola.
 *  2. *"...y no depende del driver de cola"*. Tampoco, pero peor: `Response::send()` de
 *     `symfony/http-foundation` 5.4 sólo suelta la conexión antes de los `terminating` si existe
 *     `fastcgi_finish_request()`. El entorno de Lucas corre `apache2handler` (mod_php), donde esa
 *     función no existe — verificado sirviendo una sonda por HTTP, no leyendo el disco. Así que el
 *     lead esperaba TODO el trabajo con el POST del formulario abierto: 124 segundos medidos, y
 *     Apache matando el proceso a los 120 por `max_execution_time`. Un fatal por tiempo no es
 *     capturable, así que el `catch (\Throwable)` del service nunca corría y el lead quedaba en
 *     `demo_setup_status = 'ejecutandose'` con el error en NULL.
 *
 * A la cola de base de datos, entonces, y con la conexión explícita: `config/queue.php` es
 * `env('QUEUE_CONNECTION', 'sync')` y con `sync` un `dispatch()` común volvería a correr inline.
 * Lo levanta el `queue:work database --stop-when-empty` que `Kernel.php` corre cada minuto, en un proceso
 * de CLI donde `max_execution_time` vale 0 y estos `$timeout`/`$tries` sí se aplican.
 *
 * La latencia de hasta 60 segundos hasta el próximo tick es el costo asumido, y es el mismo que
 * tiene `leads:run-demo-setup`. La versión anterior de este docblock decía que por eso encolar "no
 * compraba nada": compra que el trabajo no muera a los 120 segundos y que el lead no espere.
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
