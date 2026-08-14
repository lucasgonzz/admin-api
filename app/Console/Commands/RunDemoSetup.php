<?php

namespace App\Console\Commands;

use App\Models\DemoEventoRecibido;
use App\Models\Lead;
use App\Models\LeadDemoHito;
use App\Helpers\AppTime;
use App\Services\RunDemoSetupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Corre automáticamente el demo setup para leads cuya demo arranca pronto.
 *
 * Se ejecuta cada minuto sobre los leads en `demo_agendada` con `demo_setup_status = pendiente` y
 * demo agendada para hoy. Quién dispara y quién no lo decide
 * {@see RunDemoSetupService::evaluar_disparo()}, que desde la misión 46 comparte esa regla con el
 * POST del formulario de la página inmersiva.
 *
 * Para la dinámica ACTUAL el criterio sigue siendo el de siempre: la demo tiene que empezar dentro
 * de los próximos `LeadDemoSettings::get_setup_minutos_antes()` minutos.
 */
class RunDemoSetup extends Command
{
    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'leads:run-demo-setup';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Corre automáticamente el demo setup para leads cuya demo arranca pronto';

    /**
     * Cuántas veces se reintenta un setup `fallido` de forma automática (misión 60, pieza 4).
     *
     * Uno. Agotado, el lead se queda en `fallido`: la página inmersiva ya tiene su bloque para ese
     * caso y Lucas tiene el botón manual del panel. Reintentar más veces no es más servicial —cada
     * intento es un `migrate:fresh` sobre la instancia— y sin tope sería un `migrate:fresh` por
     * minuto hasta que venza el turno.
     *
     * @var int
     */
    private const MAX_INTENTOS_AUTOMATICOS = 1;

    /**
     * Procesa todos los leads candidatos y dispara el demo setup.
     *
     * @param RunDemoSetupService $service Servicio que ejecuta el setup remoto.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(RunDemoSetupService $service): int
    {
        /* Momento actual en timezone Argentina. */
        $now = AppTime::now();

        /* Buscar leads con demo agendada, setup pendiente y demo del día de hoy. La decisión de
         * disparar o no ya no se toma acá: vive en RunDemoSetupService::evaluar_disparo(), porque
         * la comparte con el POST del formulario de la página inmersiva (misión 46, pieza 2). Ahí
         * está el comentario completo de la regla y de por qué desapareció el corte por hora de
         * inicio para la dinámica nueva. */
        /* `fallido` entra a la búsqueda desde la misión 60, para el reintento único. No se le
         * agregan condiciones acá sino en `puede_reintentarse()`, y a propósito: las guardas de ese
         * reintento son de seguridad —una de ellas evita un `migrate:fresh` con el lead adentro— y
         * tienen que estar juntas, legibles de un vistazo, y no repartidas entre un query builder y
         * un `if`. */
        $candidates = Lead::query()
            ->where('status', 'demo_agendada')
            ->whereIn('demo_setup_status', ['pendiente', 'fallido'])
            ->whereNotNull('demo_date')
            ->whereNotNull('demo_start_time')
            ->whereDate('demo_date', $now->format('Y-m-d'))
            ->get();

        /* Contador de setups ejecutados para el log final. */
        $executed = 0;

        foreach ($candidates as $lead) {
            $es_reintento = ((string) $lead->demo_setup_status === 'fallido');

            /* 🔴 La guarda de "nadie adentro" se aplica a TODO disparo automático, no sólo al
             * reintento, y esto lo corrigió la verificación de la misión 60. Colgada del reintento
             * tenía un agujero: `reclamar_reintento()` deja al lead en `pendiente`, así que si el
             * proceso muere entre ese UPDATE y el `run()` —un `QueryException`, un fatal de
             * memoria, el cron cortado— el lead se queda en `pendiente` y en el tick siguiente el
             * comando lo levanta como si fuera de primera vez, sin pasar por ninguna guarda. El
             * contador aguantaba igual (no había un segundo reintento), pero el que corría lo hacía
             * sin lo único que evita el daño irreversible.
             *
             * Y pensado de nuevo, colgarla del reintento nunca tuvo sentido: un lead en `pendiente`
             * con alguien adentro tampoco tiene que recibir un `migrate:fresh` automático.
             *
             * El botón manual del panel NO pasa por acá (`run($lead, false)`), a propósito: Lucas
             * tiene que poder forzar un armado sabiendo lo que hace. */
            if ($lead->usa_experiencia_demo_nueva() && $this->hay_alguien_adentro($lead)) {
                continue;
            }

            /* Un `fallido` tiene que ganarse el turno antes de que se lo evalúe como candidato. Si
             * no puede reintentarse, se lo saltea sin ruido: no es un error, es el estado final. */
            if ($es_reintento && ! $this->puede_reintentarse($lead)) {
                continue;
            }

            $evaluacion = $service->evaluar_disparo($lead, $now);

            /* Si el formato de hora es inválido, saltear sin romper el batch. */
            if ($evaluacion['motivo'] === 'sin_hora_de_inicio') {
                Log::warning('RunDemoSetup: no se pudo parsear demo_start_time', [
                    'lead_id'         => $lead->id,
                    'demo_start_time' => $lead->demo_start_time,
                ]);
                continue;
            }

            if (! $evaluacion['disparar']) {
                continue;
            }

            /* El reintento vuelve el lead a `pendiente` y consume su único intento, todo en un
             * UPDATE condicionado: si no reclama exactamente una fila, otro proceso llegó primero
             * y este se va sin hacer nada. Un chequeo previo y después un update es justamente la
             * ventana por la que se colarían dos `migrate:fresh` sobre la misma instancia — mismo
             * criterio que el claim atómico de `RunDemoSetupService::run()`. */
            if ($es_reintento && ! $this->reclamar_reintento($lead)) {
                continue;
            }

            Log::info('RunDemoSetup: ejecutando setup automático', [
                'lead_id'       => $lead->id,
                'contact_name'  => $lead->contact_name,
                'demo_datetime' => $evaluacion['inicio']->toDateTimeString(),
                'motivo'        => $evaluacion['motivo'],
                'reintento'     => $es_reintento,
            ]);

            /* Delegar al servicio existente que ya maneja HTTP, retries y estados. El `true` es el
             * claim atómico: desde la misión 46 el POST del formulario también dispara, así que el
             * comando tiene que poder llegar tarde y no hacer nada en vez de correr el setup dos
             * veces sobre la misma instancia. */
            $service->run($lead, true);

            $executed++;
        }

        $this->info("Demo setups ejecutados: {$executed}");

        return 0;
    }

    /**
     * ¿Este lead en `fallido` puede recibir el reintento automático? (misión 60, pieza 4)
     *
     * Las cuatro condiciones son de seguridad, no de prolijidad, porque reintentar dispara un
     * `migrate:fresh` sobre la instancia de la demo:
     *
     *  1. **Dinámica nueva y nada más.** La dinámica actual queda exactamente como estaba: ninguna
     *     rama nueva la alcanza, igual que en `evaluar_disparo()`.
     *  2. **Un solo intento**, contado en la columna. Sin esto sería un `migrate:fresh` por minuto
     *     hasta que venciera el turno.
     *  3. 🔴 **Nadie adentro de la demo.** Si el lead ya entró, vaciarle la base es peor que dejarle
     *     el armado fallido: pierde lo que estaba haciendo, en vivo. Se miran las dos señales que
     *     existen y basta con que UNA diga que hay alguien.
     *  4. **El turno tiene que seguir vigente**, que lo decide `evaluar_disparo()` en el llamador y
     *     no acá.
     *
     * Sobre la señal de eventos: se pregunta por CUALQUIER evento de la instancia y no sólo por
     * `demo.ingreso`. Un evento cualquiera —un clip terminado, un artículo creado— prueba que hubo
     * alguien del otro lado igual de bien, y filtrar por nombre haría que la guarda dependa de un
     * vocabulario que todavía se está escribiendo (misión 50). Ante la duda, no reintentar.
     *
     * @param Lead $lead Lead en `fallido`.
     *
     * @return bool
     */
    private function puede_reintentarse(Lead $lead): bool
    {
        if (! $lead->usa_experiencia_demo_nueva()) {
            return false;
        }

        if ((int) $lead->demo_setup_intentos >= self::MAX_INTENTOS_AUTOMATICOS) {
            return false;
        }

        return true;
    }

    /**
     * 🔴 ¿Hay alguien adentro de la demo de este lead?
     *
     * Es la guarda que evita el único daño irreversible de todo este camino: disparar el setup
     * hace `migrate:fresh` sobre la instancia, y hacerlo con el lead adentro le vacía la base en
     * vivo. Alcanza con que UNA de las tres señales diga que sí.
     *
     * Las tres, y por qué son tres y no dos:
     *
     *  1. `demo_ingreso_confirmado` — lo infiere el agente de una respuesta del lead por WhatsApp.
     *     Depende de que el lead conteste.
     *  2. Cualquier evento en `demo_eventos_recibidos` — depende de que la instancia pueda alcanzar
     *     `/api/demo-eventos`. Se pregunta por cualquiera y no sólo por `demo.ingreso`: un clip
     *     terminado prueba lo mismo, y filtrar por nombre ataría la guarda a un vocabulario que
     *     todavía se está escribiendo (misión 50).
     *  3. El hito de ingreso pulsado (`tutorial_visto_at` del hito `ingreso`) — lo escribe el
     *     propio admin-api cuando el lead aprieta "Entrar a la demo" en la página inmersiva. La
     *     agregó la verificación de la misión 60, y es la más importante de las tres porque es la
     *     única **local**: las otras dos dependen de un tercero que puede no responder. El caso que
     *     la hace falta es real — un intento manual desde el panel rota `demo_eventos_token` y, si
     *     falla, deja a la instancia viva emitiendo con el token viejo, o sea 401 permanente: la
     *     señal 2 se apaga justo cuando más falta hace.
     *
     * @param Lead $lead
     *
     * @return bool
     */
    private function hay_alguien_adentro(Lead $lead): bool
    {
        if ((bool) $lead->demo_ingreso_confirmado) {
            Log::info('RunDemoSetup: no se dispara el setup, el lead ya confirmó el ingreso.', [
                'lead_id' => $lead->id,
            ]);

            return true;
        }

        if (DemoEventoRecibido::where('lead_id', $lead->id)->exists()) {
            Log::info('RunDemoSetup: no se dispara el setup, la instancia ya reportó eventos del lead.', [
                'lead_id' => $lead->id,
            ]);

            return true;
        }

        $ingreso_pulsado = LeadDemoHito::where('lead_id', $lead->id)
            ->where('tipo', LeadDemoHito::TIPO_INGRESO)
            ->whereNotNull('tutorial_visto_at')
            ->exists();

        if ($ingreso_pulsado) {
            Log::info('RunDemoSetup: no se dispara el setup, el lead ya pulsó el ingreso a la demo.', [
                'lead_id' => $lead->id,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Toma el reintento de forma atómica: vuelve el lead a `pendiente` y consume su intento.
     *
     * Vuelve a `pendiente` y no salta directo a `ejecutandose` porque el claim atómico de
     * `RunDemoSetupService::run($lead, true)` exige exactamente ese estado de partida: así el
     * reintento entra por el mismo camino que cualquier otro disparo y no hay una segunda forma de
     * arrancar un setup.
     *
     * @param Lead $lead Lead en `fallido` que ya pasó `puede_reintentarse()`.
     *
     * @return bool true si este proceso se quedó con el reintento.
     */
    private function reclamar_reintento(Lead $lead): bool
    {
        $reclamadas = Lead::where('id', $lead->id)
            ->where('demo_setup_status', 'fallido')
            ->where('demo_setup_intentos', '<', self::MAX_INTENTOS_AUTOMATICOS)
            ->update([
                'demo_setup_status'   => 'pendiente',
                'demo_setup_intentos' => DB::raw('demo_setup_intentos + 1'),
            ]);

        if ($reclamadas !== 1) {
            return false;
        }

        /* El UPDATE fue por query builder: la instancia en memoria sigue diciendo `fallido` y el
         * service lee de ella. */
        $lead->refresh();

        return true;
    }
}
