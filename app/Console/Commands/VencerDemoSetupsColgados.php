<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\LeadDemoSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Saca del limbo a los leads cuyo demo setup quedó en `ejecutandose` y nunca reportó nada
 * (misión 60).
 *
 * 🔴 Por qué existe: `ejecutandose` era un estado terminal de hecho. `RunDemoSetup` —el comando de
 * cada minuto— filtra `where('demo_setup_status', 'pendiente')`, así que un lead que entró en
 * `ejecutandose` y cuyo proceso murió antes de poder escribir el fallo se quedaba ahí para siempre:
 * invisible para el ciclo automático y sin ningún camino de vuelta. Medido sobre la base real el
 * 14/8/2026: tres leads colgados, y ni uno solo que hubiera llegado a `exitoso` en dos meses.
 *
 * Y el proceso puede morir sin dejar rastro por construcción: bajo mod_php un fatal por
 * `max_execution_time` no es capturable, así que el `catch (\Throwable)` de `RunDemoSetupService`
 * nunca corre. La misión 60 saca el trabajo del request para que eso no pase, pero este comando es
 * la red que hace falta igual: un worker también se puede morir, y un estado del que no se sale no
 * es un estado, es una fuga.
 *
 * La lección ya estaba escrita en este mismo repo, para `demo_updates`, el 13/7/2026
 * (`DemoUpdateService.php:145`): *"el DemoUpdate quedaba en `ejecutandose` para siempre. El log es
 * información; el estado es la máquina."* Se aprendió ahí y no se generalizó; esto es generalizarlo.
 */
class VencerDemoSetupsColgados extends Command
{
    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'leads:vencer-demo-setups-colgados';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Pasa a fallido los demo setups que quedaron colgados en ejecutandose';

    /**
     * Busca los setups colgados y los pasa a `fallido` con un motivo explícito.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        $timeout_minutos = LeadDemoSettings::get_setup_timeout_minutos();
        $limite = now()->subMinutes($timeout_minutos);

        /* `whereNotNull('demo_setup_last_run_at')` no es defensivo: es la condición que decide.
         * Sin fecha de arranque no se sabe hace cuánto está corriendo, y con NULL la comparación
         * de MySQL nunca da verdadero — pero dejarlo implícito haría que un cambio futuro en el
         * operador (un `orWhere`, un rango) empezara a vencer leads sin ninguna medición detrás. */
        $colgados = Lead::query()
            ->where('demo_setup_status', 'ejecutandose')
            ->whereNotNull('demo_setup_last_run_at')
            ->where('demo_setup_last_run_at', '<', $limite)
            ->get()
            /* 🔴 Sólo la dinámica nueva. Lo corrigió la verificación de la misión 60: la primera
             * versión no filtraba, así que a un lead 'actual' colgado también le pisaba el estado y
             * el error. No destruía nada —el reintento sí tiene su guarda de dinámica— pero el
             * criterio de aceptación de la misión es explícito: *ningún* lead con
             * `demo_experiencia` distinto de 'nueva' cambia de comportamiento en ningún camino.
             *
             * El filtro va en PHP y no en el WHERE a propósito: `usa_experiencia_demo_nueva()` cae
             * a la dinámica actual ante null, string vacío o cualquier valor desconocido, y esa
             * lógica no se puede duplicar en SQL sin que las dos versiones diverjan algún día. */
            ->filter(function (Lead $lead) {
                return $lead->usa_experiencia_demo_nueva();
            });

        /* Contador de leads vencidos para el log final. */
        $vencidos = 0;

        foreach ($colgados as $lead) {
            $minutos = (int) $lead->demo_setup_last_run_at->diffInMinutes(now());

            /* El texto dice que NO SE SABE cómo terminó, que es información distinta de "falló".
             * Lo lee Lucas en el panel y lo lee el lead indirectamente, porque de este estado
             * depende la pantalla de la página inmersiva: escribir "falló el armado" afirmaría
             * algo que nadie midió. */
            $motivo = 'El armado no reportó resultado en ' . $minutos . ' minutos. '
                . 'El proceso que lo lanzó probablemente murió.';

            /* UPDATE condicionado a que el estado siga siendo `ejecutandose`, y no un `save()` sobre
             * el modelo que se leyó recién: entre el `get()` de arriba y este momento el worker
             * puede haber terminado bien y haber escrito `exitoso`. Sin la condición, este comando
             * le pisaría un setup que anduvo. Mismo criterio que el claim atómico de
             * `RunDemoSetupService::run()`. */
            $afectadas = Lead::where('id', $lead->id)
                ->where('demo_setup_status', 'ejecutandose')
                ->update([
                    'demo_setup_status'     => 'fallido',
                    'demo_setup_last_error' => $motivo,
                ]);

            if ($afectadas !== 1) {
                continue;
            }

            Log::warning('VencerDemoSetupsColgados: demo setup vencido por timeout', [
                'lead_id'            => $lead->id,
                'minutos_corriendo'  => $minutos,
                'timeout_minutos'    => $timeout_minutos,
            ]);

            $vencidos++;
        }

        $this->info("Demo setups colgados vencidos: {$vencidos}");

        return 0;
    }
}
