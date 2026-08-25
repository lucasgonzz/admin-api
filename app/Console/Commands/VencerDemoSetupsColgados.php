<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\LeadDemoSettings;
use App\Services\RunDemoSetupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Saca del limbo a los leads cuyo demo setup quedó en un estado intermedio y nunca reportó
 * desenlace (misión 60; ampliado a `sin_confirmar` en la misión cruzada del 25/8/2026).
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
 *
 * ---
 *
 * 🔴 **Barre DOS estados, con un criterio de dinámica DISTINTO para cada uno.** No es una
 * inconsistencia: es que los dos estados tienen historias distintas.
 *
 *  - **`ejecutandose` → sólo la dinámica NUEVA.** Se mantiene tal cual desde la misión 60. Ese
 *    estado existe desde siempre y hay leads viejos parados ahí, así que barrer las dos dinámicas
 *    le cambiaría el comportamiento a leads de producción que hoy andan — y el criterio de
 *    aceptación de la misión 60 es explícito: *ningún* lead con `demo_experiencia` distinto de
 *    'nueva' cambia de comportamiento en ningún camino.
 *  - **`sin_confirmar` → las DOS dinámicas.** Ese estado no existía antes del 25/8/2026, así que
 *    ningún lead viejo puede estar parado ahí y no hay comportamiento previo que preservar. Y lo
 *    escribe `RunDemoSetupService` para las dos dinámicas por igual: ni el timeout de la llamada ni
 *    el HTTP 409 de la instancia miran la dinámica del lead. Barrerlo sólo para la nueva dejaría a
 *    un lead 'actual' colgado ahí para siempre — la fuga del 13/8/2026 otra vez, con otra cara.
 *
 * El filtro de dinámica va en PHP y no en el WHERE a propósito: `usa_experiencia_demo_nueva()` cae
 * a la dinámica actual ante null, string vacío o cualquier valor desconocido, y esa lógica no se
 * puede duplicar en SQL sin que las dos versiones diverjan algún día.
 *
 * 🔴 Y el motivo que se escribe en `demo_setup_last_error` distingue los dos casos, porque lo que
 * se sabe es distinto. Ver el docblock de `motivo_para()`.
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
    protected $description = 'Pasa a fallido los demo setups que quedaron colgados en ejecutandose o sin_confirmar';

    /**
     * Estados intermedios de los que hay que poder salir. Ver el docblock de la clase para el
     * criterio de dinámica de cada uno.
     *
     * @var array<int, string>
     */
    private const ESTADOS_INTERMEDIOS = [
        'ejecutandose',
        RunDemoSetupService::ESTADO_SIN_CONFIRMAR,
    ];

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
            ->whereIn('demo_setup_status', self::ESTADOS_INTERMEDIOS)
            ->whereNotNull('demo_setup_last_run_at')
            ->where('demo_setup_last_run_at', '<', $limite)
            ->get()
            ->filter(function (Lead $lead) {
                /* `sin_confirmar` no lleva guarda de dinámica: nació el 25/8/2026 y se escribe
                 * para las dos. Ver el docblock de la clase. */
                if ((string) $lead->demo_setup_status === RunDemoSetupService::ESTADO_SIN_CONFIRMAR) {
                    return true;
                }

                /* `ejecutandose`, en cambio, sigue siendo sólo de la dinámica nueva, igual que
                 * desde la misión 60. */
                return $lead->usa_experiencia_demo_nueva();
            });

        /* Contador de leads vencidos para el log final. */
        $vencidos = 0;

        foreach ($colgados as $lead) {
            /* El estado que se leyó: decide el motivo, y condiciona el UPDATE de más abajo. */
            $estado_previo = (string) $lead->demo_setup_status;

            $minutos = (int) $lead->demo_setup_last_run_at->diffInMinutes(now());

            $motivo = $this->motivo_para($estado_previo, $minutos);

            /* UPDATE condicionado a que el estado siga siendo el que se leyó, y no un `save()` sobre
             * el modelo que se leyó recién: entre el `get()` de arriba y este momento el worker
             * puede haber terminado bien y haber escrito `exitoso`. Sin la condición, este comando
             * le pisaría un setup que anduvo. Mismo criterio que el claim atómico de
             * `RunDemoSetupService::run()`. */
            $afectadas = Lead::where('id', $lead->id)
                ->where('demo_setup_status', $estado_previo)
                ->update([
                    'demo_setup_status'     => 'fallido',
                    'demo_setup_last_error' => $motivo,
                ]);

            if ($afectadas !== 1) {
                continue;
            }

            Log::warning('VencerDemoSetupsColgados: demo setup vencido por timeout', [
                'lead_id'            => $lead->id,
                'estado_previo'      => $estado_previo,
                'minutos_corriendo'  => $minutos,
                'timeout_minutos'    => $timeout_minutos,
            ]);

            $vencidos++;
        }

        $this->info("Demo setups colgados vencidos: {$vencidos}");

        return 0;
    }

    /**
     * Texto que se guarda en `demo_setup_last_error`, según de qué estado se venía.
     *
     * 🔴 Los dos textos dicen que NO SE SABE cómo terminó el armado, que es información distinta
     * de "falló". Los lee Lucas en el panel, y de este estado depende además la pantalla de la
     * página inmersiva del lead: escribir "falló el armado" afirmaría algo que nadie midió.
     *
     * Pero no dicen lo mismo, porque no se sabe lo mismo:
     *
     *  - Desde `ejecutandose` no hubo ningún reporte, y lo más probable es que el proceso que lo
     *    lanzó se haya muerto (un fatal por `max_execution_time` no es capturable).
     *  - Desde `sin_confirmar` sí hubo una señal, y dice lo contrario: el admin dejó de esperar la
     *    respuesta (timeout) o la instancia contestó 409 avisando que ya tenía una corrida en
     *    curso. O sea que la corrida estaba VIVA. Lo único que se declara acá es que ya pasó
     *    demasiado tiempo como para seguir esperándola.
     *
     * @param string $estado_previo Estado del que se viene (`ejecutandose` o `sin_confirmar`).
     * @param int    $minutos       Minutos transcurridos desde `demo_setup_last_run_at`.
     *
     * @return string
     */
    protected function motivo_para(string $estado_previo, int $minutos): string
    {
        if ($estado_previo === RunDemoSetupService::ESTADO_SIN_CONFIRMAR) {
            return 'La corrida seguía viva en la instancia y nunca confirmó cómo terminó. '
                . 'Pasaron ' . $minutos . ' minutos, que es demasiado para seguir esperándola. '
                . 'Si al final terminó bien, el aviso de la instancia lo vuelve a dejar en exitoso.';
        }

        return 'El armado no reportó resultado en ' . $minutos . ' minutos. '
            . 'El proceso que lo lanzó probablemente murió.';
    }
}
