<?php

namespace App\Console\Commands;

use App\Helpers\AppTime;
use App\Models\Lead;
use App\Services\LeadDemoSettings;
use App\Services\RunDemoSetupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Destraba los demo setups que se quedaron en un estado intermedio y nunca reportaron desenlace.
 *
 * 🔴 Por qué existe, y por qué es parte del arreglo y no un extra. El 25/8/2026 se agregó el estado
 * `sin_confirmar` a `demo_setup_status`: la corrida cuyo final el admin NO conoce, porque se venció
 * la espera de la llamada o porque la instancia contestó 409 avisando que ya tenía un setup vivo.
 * Un estado nuevo del que no se sale sería exactamente el bug del 13/8/2026 con otra cara — tres
 * leads en `ejecutandose` con `demo_setup_last_error` en NULL para siempre, porque
 * `leads:run-demo-setup` filtra `where('demo_setup_status', 'pendiente')` y nadie los sacaba de
 * ahí. La regla que quedó escrita en APRENDER_NO_PARCHEAR después de eso:
 *
 *     "Todo estado intermedio necesita un proceso que lo destrabe que no sea el mismo que lo
 *      puso ahí."
 *
 * 🔴 Barre DOS estados a propósito, `sin_confirmar` y `ejecutandose`. El segundo no es redundante
 * con el camino feliz: el botón "Correr demo setup ahora" del panel sigue siendo síncrono, y si el
 * PHP del admin se muere por `max_execution_time` en el medio, el proceso no llega a escribir nada
 * — ni `sin_confirmar` ni `fallido`. Un fatal por tiempo bajo mod_php no es capturable, así que el
 * `catch` de `RunDemoSetupService` nunca corre y el lead se queda en `ejecutandose` con el error en
 * NULL. Esa es, literalmente, la fuga del 13/8.
 *
 * ⚠️ Se solapa a propósito con `leads:vencer-demo-setups-colgados` (misión 60) sobre el estado
 * `ejecutandose`. Las diferencias: aquel filtra sólo la dinámica nueva y usa
 * `demo_setup_timeout_minutos` (10 min); éste barre las dos dinámicas y usa el umbral más holgado
 * de `demo_setup_sin_confirmar_timeout_minutos` (25 min), porque acá lo que se está venciendo puede
 * ser una corrida viva. Los dos escriben con un UPDATE condicionado al estado, así que si los dos
 * llegan al mismo lead gana el primero y el segundo no toca nada. Queda anotado para que la
 * consolidación de los dos comandos se decida a propósito y no por accidente.
 */
class CheckDemoSetupTimeout extends Command
{
    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'leads:check-demo-setup-timeout';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Pasa a fallido los demo setups que quedaron en sin_confirmar o ejecutandose más allá del timeout';

    /**
     * Estados intermedios de los que hay que poder salir. Ver el docblock de la clase.
     *
     * @var array<int, string>
     */
    private const ESTADOS_INTERMEDIOS = [
        'ejecutandose',
        RunDemoSetupService::ESTADO_SIN_CONFIRMAR,
    ];

    /**
     * Busca los setups vencidos y los pasa a `fallido` con un motivo explícito.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        /* Minutos que un setup puede pasar en un estado intermedio antes de darse por perdido. */
        $timeout_minutos = LeadDemoSettings::get_setup_sin_confirmar_timeout_minutos();

        /* `AppTime::now()` y no `now()`: en local el reloj puede estar virtualizado, y todo el
         * ciclo de demo ya se mide contra ese reloj. Mezclar los dos haría que un vencimiento
         * dependa de si el comando lo escribió alguien que se acordó del reloj virtual. */
        $now = AppTime::now();
        $limite = $now->copy()->subMinutes($timeout_minutos);

        /* `whereNotNull('demo_setup_last_run_at')` no es defensivo: es la condición que decide.
         * Sin fecha de arranque no se sabe hace cuánto está en ese estado, y con NULL la
         * comparación de MySQL nunca da verdadero — pero dejarlo implícito haría que un cambio
         * futuro en el operador empezara a vencer leads sin ninguna medición detrás. */
        $vencidos_candidatos = Lead::query()
            ->whereIn('demo_setup_status', self::ESTADOS_INTERMEDIOS)
            ->whereNotNull('demo_setup_last_run_at')
            ->where('demo_setup_last_run_at', '<', $limite)
            ->get();

        /* Contador de leads vencidos para el log final. */
        $vencidos = 0;

        foreach ($vencidos_candidatos as $lead) {
            /* El estado que se leyó, para condicionar el UPDATE a que siga siendo ése. */
            $estado_previo = (string) $lead->demo_setup_status;

            $minutos = (int) $lead->demo_setup_last_run_at->diffInMinutes($now);

            /* El texto dice que NO SE SABE cómo terminó, y no que el armado falló: nadie midió
             * eso. Lo lee Lucas en el panel, y de este estado depende además la pantalla de la
             * página inmersiva del lead. */
            $motivo = 'El armado no reportó resultado en ' . $minutos . ' minutos (estado previo: '
                . $estado_previo . '). Se lo da por perdido para que el panel pueda volver a '
                . 'dispararlo; si la instancia terminó igual, el aviso del canal de eventos la '
                . 'deja en exitoso.';

            /* UPDATE condicionado al estado que se leyó, y no un `save()` sobre el modelo: entre el
             * `get()` de arriba y este momento la instancia puede haber terminado bien y haber
             * escrito `exitoso` por el canal de eventos. Sin la condición, este comando le pisaría
             * un setup que anduvo. Mismo criterio que el claim atómico de
             * `RunDemoSetupService::run()`. */
            $afectadas = Lead::where('id', $lead->id)
                ->where('demo_setup_status', $estado_previo)
                ->update([
                    'demo_setup_status'     => 'fallido',
                    'demo_setup_last_error' => $motivo,
                    // A mano porque el query builder no estampa los timestamps, y el panel ordena
                    // por esta columna.
                    'updated_at'            => $now,
                ]);

            if ($afectadas !== 1) {
                continue;
            }

            Log::warning('CheckDemoSetupTimeout: demo setup vencido por timeout', [
                'lead_id'           => $lead->id,
                'estado_previo'     => $estado_previo,
                'minutos_corriendo' => $minutos,
                'timeout_minutos'   => $timeout_minutos,
            ]);

            $vencidos++;
        }

        $this->info("Demo setups vencidos por timeout: {$vencidos}");

        return 0;
    }
}
