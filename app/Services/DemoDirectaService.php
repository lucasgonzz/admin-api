<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Models\Demo;
use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * La demo DIRECTA de la dinámica nueva (misión demo-agendado-directo, 10/9/2026).
 *
 * Hasta esta misión, agendar una demo era elegir un slot de una grilla: horarios del closer,
 * frecuencia, duración, ventanas extendidas, margen mínimo, revalidación al aprobar. Lucas lo
 * sacó de un plumazo: *"para ofrecer la demo ni siquiera se consulte los horarios, que simplemente
 * la ofrezca [...] cuando el lead diga que sí, en ese mismo momento tiene que chequear qué demo
 * está disponible y se la asigna, y listo"*. El motivo concreto: la oferta con hora caducaba
 * mientras el mensaje esperaba aprobación ("tardo tres minutos y ya me sale el error de que ese
 * horario ya no está disponible"), y el volumen de demos no justifica ese control.
 *
 * Acá vive lo único que queda de esa lógica: dada la hora actual, cuál es la ventana de la demo
 * (arranca en diez minutos —el tiempo que el agente le dice al lead, "te la puedo tener lista en
 * diez minutos"— y queda abierta hasta el tope de la ventana extendida o el fin del día) y qué
 * instancia física está libre para esa ventana. Nada más: ni closer, ni grilla, ni margen.
 *
 * 🔴 La instancia se elige en el momento de APLICAR la acción (al aprobar el mensaje, o en el
 * acto si el interruptor de Cuenta no retiene), no cuando el modelo escribió el texto. Lo que se
 * elige al generar (`elegir_prevista()`) es sólo una previsión para poder escribir la URL de la
 * tienda en el mensaje; si al aprobar la instancia real es otra, `corregir_links_de_tienda()`
 * reemplaza esa URL en el texto. Es un reemplazo determinista de una URL que este servicio mismo
 * inyectó, no una reescritura del mensaje.
 */
class DemoDirectaService
{
    /** Zona horaria de todo el cálculo, la misma que el resto del ciclo de demo. */
    const TZ = 'America/Argentina/Buenos_Aires';

    /**
     * Minutos entre que el lead dice "sí" y el inicio del turno. Es exactamente lo que el agente
     * le promete ("te la puedo tener lista en diez minutos"): el setup real tarda dos o tres, y
     * el resto es el margen que Lucas quiso a propósito, para que el lead se siente en la
     * computadora y para que se lea como algo que se prepara para él.
     */
    const MINUTOS_HASTA_EL_INICIO = 10;

    /**
     * Estados en los que un lead ocupa de verdad su instancia. Un lead en cualquier otro estado
     * (realizada, closer, cerrado, en pausa) puede seguir teniendo demo_date/demo_id cargados,
     * pero ya no va a entrar por ese turno: la instancia se considera libre.
     *
     * `demo_pendiente_de_ingreso` SÍ ocupa mientras conserve su turno: un lead llega ahí por
     * timeout y puede entrar más tarde dentro de su ventana (el evento demo.ingreso lo avanza desde
     * ese estado, `DemoEventosController::ESTADOS_DESDE_LOS_QUE_AVANZA_POR_INGRESO_REAL`).
     *
     * 🔴 Y desde el 13/10/2026 eso vale TAMBIÉN para la demo directa, que es lo contrario de lo que
     * decía este docblock. Hasta esa fecha el vencimiento por no-show limpiaba el turno
     * (`demo_id`/`demo_date`/`demo_start_time`/...), así que un lead de demo directa en
     * `demo_pendiente_de_ingreso` no bloqueaba nada y, cuando volvía, se le asignaba una instancia
     * nueva. La misión demo-dos-horas-y-experiencia dejó de limpiarlo: si al lead se le prometieron
     * dos horas, las dos horas son suyas aunque no haya entrado en la primera, y puede entrar en el
     * minuto 90 por el botón de su página. El que sí sigue soltando el turno es `marcar_no_ingreso`,
     * que es una decisión humana ("este no entra más"), no un reloj.
     *
     * @var array<int, string>
     */
    const ESTADOS_QUE_OCUPAN_LA_INSTANCIA = ['demo_agendada', 'demo_pendiente_de_ingreso', 'demo_en_curso', 'demo_pendiente_de_terminar'];

    /**
     * Ventana de la demo directa a partir de un instante.
     *
     * Inicio: ahora + MINUTOS_HASTA_EL_INICIO. Fin: el tope de la ventana extendida
     * (LeadDemoSettings::get_ventana_extendida_max_horas()), cortado a las 23:59 del día del
     * inicio —mismos dos clamps que la ventana extendida de la misión 47— sin recortar por otras
     * demos: la instancia se elige libre para toda la ventana, no al revés.
     *
     * `cabe` es false cuando entre el inicio y las 23:59 no entra ni una demo de la duración
     * configurada (un "sí" a las 23:30): no se asigna una demo de veinte minutos, se le ofrece
     * para mañana.
     *
     * @param Carbon|null $ahora Instante de referencia (AppTime::now() si no viene).
     *
     * @return array{inicio: Carbon, fin: Carbon, cabe: bool}
     */
    public function ventana_desde(?Carbon $ahora = null): array
    {
        $ahora = $ahora !== null ? $ahora->copy()->setTimezone(self::TZ) : AppTime::now(self::TZ);

        return $this->ventana_para($ahora->copy()->addMinutes(self::MINUTOS_HASTA_EL_INICIO));
    }

    /**
     * Ventana de la demo directa que ARRANCA en un instante dado (misión flujo-lead-landing-closer,
     * 17/10/2026): es `ventana_desde()` sin el +10, para la demo que el lead pide para un momento
     * concreto ("mañana a las 10", `agendar_demo: {dia, hora}`). Mismos dos clamps: el fin es el tope
     * de la ventana extendida, cortado a las 23:59 del día del inicio, y `cabe` dice si entre el
     * inicio y ese fin entra una demo de la duración configurada.
     *
     * No valida el inicio contra "ahora" ni contra el horario de la demo del día: eso es de
     * `LeadAiService::validar_inicio_de_demo_pedida()`, que lo mira antes de llamar acá.
     *
     * @param Carbon $inicio Inicio pedido (se copia; segundos en cero).
     *
     * @return array{inicio: Carbon, fin: Carbon, cabe: bool}
     */
    public function ventana_para(Carbon $inicio): array
    {
        $inicio = $inicio->copy()->setTimezone(self::TZ)->second(0);

        $fin  = $inicio->copy()->addHours(LeadDemoSettings::get_ventana_extendida_max_horas());
        $tope = $inicio->copy()->setTime(23, 59, 0);
        if ($fin->gt($tope)) {
            $fin = $tope;
        }

        $cabe = $fin->diffInMinutes($inicio, false) <= -LeadDemoSettings::get_duracion_minutos();

        return ['inicio' => $inicio, 'fin' => $fin, 'cabe' => $cabe];
    }

    /**
     * Primera instancia (por id) libre para TODA la ventana pedida, o null si ninguna sirve.
     *
     * "Libre" = ningún OTRO lead, en un estado que ocupe la instancia, tiene ese mismo demo_id el
     * día del inicio con una ventana [inicio - setup, fin + gracia] que se solape con la pedida
     * (también inflada con setup y gracia: dos setups no pueden pisarse, y la gracia es el rato en
     * que el lead anterior todavía puede estar adentro). Un solo intervalo solapado alcanza para
     * descartar la instancia, y se prueba la siguiente.
     *
     * 🔴 Por qué ya no hay recorte (13/10/2026, misión demo-dos-horas-y-experiencia). Hasta esta
     * misión, una instancia con un turno vecino más tarde servía igual: la ventana se RECORTABA
     * para terminar antes de ese turno y se aceptaba mientras quedara al menos
     * `get_duracion_minutos()`. Ese recorte existía porque la ventana extendida eran SEIS horas y
     * casi nunca entraba entera: sin él, tres instancias vacías con una demo a la tarde dejaban a
     * un "sí" de las 10:00 sin lugar.
     *
     * Lo que cambió es lo que se le promete al lead. Desde esta misión el agente le dice *"la tenés
     * reservada dos horas, hasta las HH:MM"* (ver `LeadAiService::build_demo_directa_context()`),
     * así que media reserva dejó de ser una reserva: el lead que vuelve a las dos horas se encuentra
     * con el turno vencido y con otro lead adentro de su instancia. Y con la ventana en 2 h
     * (`LeadDemoSettings::DEFAULT_VENTANA_EXTENDIDA_MAX_HORAS`) el recorte casi no hace falta: la
     * ventana entera entra en cualquier hueco razonable de la tarde. Si ninguna instancia sirve,
     * `proxima_liberacion()` le dice al agente desde qué hora sí, que es una respuesta honesta —
     * mejor que una reserva de veinte minutos vendida como dos horas.
     *
     * Y por eso la aritmética del recorte no quedó como caso borde: era vacía. Un intervalo que se
     * solapa con la pedida y NO pisa el arranque tiene su `desde` en algún punto de
     * `(inicio - setup, fin + gracia]`, así que su tope (`desde - gracia - 1`) cae siempre antes de
     * `fin`. O sea: todo vecino que no bloquea el inicio mueve el fin, sin excepción. Un `if` para
     * "recorte que no mueve el fin" no tendría ningún caso que atender.
     *
     * El clamp de las 23:59 y el `cabe` de `ventana_desde()` NO son esto y no cambiaron: la ventana
     * pedida ya llega recortada por el fin del día, y el fin del día no es un vecino.
     *
     * @param Lead   $lead   Lead al que se le va a asignar (se excluye a sí mismo: reagendar no
     *                       puede chocar contra la propia reserva, misma lección del lead #10).
     * @param Carbon $inicio Inicio de la ventana pedida.
     * @param Carbon $fin    Fin de la ventana pedida (tope de la ventana extendida).
     *
     * @return array{demo: Demo, fin: Carbon}|null La ventana devuelta es siempre la pedida entera.
     */
    public function instancia_libre(Lead $lead, Carbon $inicio, Carbon $fin): ?array
    {
        $ocupadas = $this->ocupacion_por_demo($lead, $inicio, $fin);

        foreach (Demo::query()->orderBy('id')->get() as $demo) {
            if (empty($ocupadas[$demo->id])) {
                return ['demo' => $demo, 'fin' => $fin->copy()];
            }
        }

        return null;
    }

    /**
     * ¿Esta instancia está libre para TODA la ventana pedida? Es la misma pregunta que
     * `instancia_libre()` le hace a cada demo, para UNA demo puntual (misión
     * demo-autogestionada-landing, 22/10/2026).
     *
     * La necesita la reserva de la landing (D2 del plan): ahí el turno tiene que estar en la grilla
     * del panel para una instancia concreta Y esa instancia tiene que estar libre según este servicio
     * (el criterio del agente). `instancia_libre()` no sirve porque devuelve la primera libre, que
     * puede no ser una de las que tienen el slot en la grilla. El cálculo es el mismo
     * (`ocupacion_por_demo()`), sin copia.
     *
     * @param Lead   $lead    Lead al que se le va a asignar (se excluye a sí mismo).
     * @param int    $demo_id Instancia a consultar.
     * @param Carbon $inicio  Inicio de la ventana pedida.
     * @param Carbon $fin     Fin de la ventana pedida.
     *
     * @return bool
     */
    public function demo_libre_para_ventana(Lead $lead, int $demo_id, Carbon $inicio, Carbon $fin): bool
    {
        $ocupadas = $this->ocupacion_por_demo($lead, $inicio, $fin);

        return empty($ocupadas[$demo_id]);
    }

    /**
     * Los campos que deja escritos una reserva de demo directa (misión demo-autogestionada-landing,
     * 22/10/2026, D3 del plan).
     *
     * 🔴 POR QUÉ vive acá y no en cada camino que agenda: hasta esta misión la lista estaba escrita a
     * mano adentro de `LeadAiService::apply_parsed_response()` (rama demo directa). Ahora también
     * reserva la landing, y un lead agendado desde la web tiene que quedar EXACTAMENTE igual que uno
     * agendado por el agente: los mismos relojes reseteados, el setup en pendiente, los dos checks
     * prendidos. Con dos listas, la primera vez que alguien agregara un campo en una sola, los leads
     * de la web dejarían de recibir el recordatorio o el check sin que nada lo denuncie. El agente la
     * usa a través de este método con el resultado idéntico al de antes.
     *
     * Lo que significa cada uno (texto movido tal cual de `LeadAiService`):
     *   - Ventana extendida siempre (`demo_flexible`): es lo que le deja al lead varias horas para
     *     entrar sin que los relojes del ciclo lo muevan de estado.
     *   - Se resetean los relojes del turno anterior, si lo hubo (re-asignación después de un
     *     cancelar_demo o de un vencimiento): el recordatorio y el check tienen que poder salir de
     *     nuevo, y el setup tiene que volver a correr —la instancia puede ser otra, o la misma
     *     re-sembrada para otro lead entre medio—; el formulario ya completado hace que
     *     `leads:run-demo-setup` lo dispare en el próximo tick.
     *   - Los dos checks de la demo directa se prenden solos al agendar (D9 del plan, 17/10/2026): el
     *     "¿pudiste entrar?" post-video (CheckDemoIngresoPostVideo) y el "¿cómo venís?" al cumplirse la
     *     duración (CheckDemoFin). Se prenden en ESTE UPDATE y no en el default global a propósito: los
     *     leads viejos y los agendados a mano quedan como están.
     *
     * @param Demo   $demo   Instancia asignada.
     * @param Carbon $inicio Inicio del turno (zona de la demo).
     * @param Carbon $fin    Fin del turno (tope de la ventana extendida).
     *
     * @return array<string, mixed>
     */
    public function campos_de_reserva(Demo $demo, Carbon $inicio, Carbon $fin): array
    {
        return [
            'demo_id'                       => (int) $demo->id,
            'demo_date'                     => $inicio->format('Y-m-d'),
            'demo_start_time'               => $inicio->format('H:i'),
            'demo_end_time'                 => $fin->format('H:i'),
            'demo_flexible'                 => true,
            'status'                        => 'demo_agendada',
            'recordatorio_demo_enviado'     => false,
            'demo_check_ingreso_enviado'    => false,
            'demo_check_ingreso_enviado_at' => null,
            'demo_setup_status'             => 'pendiente',
            'auto_check_ingreso_demo'       => true,
            'auto_check_fin_demo'           => true,
        ];
    }

    /**
     * Cuándo se libera la primera instancia si ahora mismo no hay ninguna libre: el menor "fin +
     * gracia" entre las ventanas que ocupan cada demo. Null si hay alguna libre (no hay nada que
     * esperar) o si no hay demos cargadas.
     *
     * Lo lee el contexto del agente para que, en vez de prometer "ahora", diga a partir de qué
     * hora puede ofrecerla.
     *
     * @param Lead        $lead  Lead para el que se consulta (se excluye a sí mismo).
     * @param Carbon|null $ahora Instante de referencia.
     *
     * @return Carbon|null
     */
    public function proxima_liberacion(Lead $lead, ?Carbon $ahora = null): ?Carbon
    {
        return $this->proxima_liberacion_para_ventana($lead, $this->ventana_desde($ahora));
    }

    /**
     * Igual que `proxima_liberacion()`, pero para una demo que arrancaría en `$inicio` (la que el lead
     * pide para un momento concreto, misión flujo-lead-landing-closer, 17/10/2026): si a esa hora no
     * hay instancia libre, desde qué hora de ese día sí la habría. Null si hay alguna libre o no hay
     * demos cargadas.
     *
     * @param Lead   $lead   Lead para el que se consulta (se excluye a sí mismo).
     * @param Carbon $inicio Inicio pedido.
     *
     * @return Carbon|null
     */
    public function proxima_liberacion_desde(Lead $lead, Carbon $inicio): ?Carbon
    {
        return $this->proxima_liberacion_para_ventana($lead, $this->ventana_para($inicio));
    }

    /**
     * El cálculo compartido por `proxima_liberacion()` y `proxima_liberacion_desde()`: cuándo se libera
     * la primera instancia para una ventana ya armada.
     *
     * @param Lead                                      $lead
     * @param array{inicio: Carbon, fin: Carbon, cabe: bool} $ventana
     *
     * @return Carbon|null
     */
    private function proxima_liberacion_para_ventana(Lead $lead, array $ventana): ?Carbon
    {
        $ocupadas = $this->ocupacion_por_demo($lead, $ventana['inicio'], $ventana['fin']);

        $demos = Demo::query()->orderBy('id')->get();
        if ($demos->isEmpty()) {
            return null;
        }

        if ($this->instancia_libre($lead, $ventana['inicio'], $ventana['fin']) !== null) {
            return null;
        }

        $mas_temprano = null;
        foreach ($demos as $demo) {
            if (empty($ocupadas[$demo->id])) {
                continue;
            }
            /* La instancia se libera cuando termina la ÚLTIMA de sus ventanas solapadas. */
            $libera_a = null;
            foreach ($ocupadas[$demo->id] as $intervalo) {
                if ($libera_a === null || $intervalo['hasta']->gt($libera_a)) {
                    $libera_a = $intervalo['hasta'];
                }
            }
            if ($mas_temprano === null || $libera_a->lt($mas_temprano)) {
                $mas_temprano = $libera_a;
            }
        }

        /* 🔴 Desde el 15/10/2026 (misión demo-dos-horas-y-experiencia) la hora de arriba puede ser
         * OPTIMISTA. instancia_libre() ya no recorta la ventana: un turno POSTERIOR en la misma
         * instancia la bloquea entera. Con X ocupando hasta las 11:10 e Y arrancando a las 13:00,
         * "se libera a las 11:10" era mentira: a las 11:10 la ventana nueva (11:20-13:20) choca con
         * Y. Se prueba cada liberación del resto del día, en orden, y se devuelve la primera en la
         * que la ventana entera entra de verdad. Si ninguna entra hoy, queda la de arriba, que es
         * lo que este método devolvía siempre. */
        $verificada = $this->primera_liberacion_que_entra($lead, $ventana['inicio']);

        return $verificada !== null ? $verificada : $mas_temprano;
    }

    /**
     * La primera hora del resto del día en la que, si el lead dijera "sí", alguna instancia
     * tendría libre la ventana ENTERA (ver proxima_liberacion()). Null si ninguna entra hoy.
     *
     * Las candidatas son los fines reales de todas las ocupaciones del día: entre dos fines
     * consecutivos nada se libera, así que no hace falta probar otros minutos. Se devuelve la
     * liberación (lo mismo que este método devolvió siempre), pero se la PRUEBA como si el lead
     * dijera "sí" en el primer minuto en que ya no toca esa ocupación: la ventana nueva arranca a
     * MINUTOS_HASTA_EL_INICIO del sí y ocupa desde `setup_minutos_antes` antes de su inicio, así que
     * un "sí" justo a la hora de la liberación todavía choca con ella (unos minutos; es así desde
     * antes de esta misión y no se cambió acá).
     *
     * @param Lead   $lead   Lead para el que se consulta (se excluye a sí mismo).
     * @param Carbon $inicio Inicio de la ventana pedida ahora.
     *
     * @return Carbon|null
     */
    private function primera_liberacion_que_entra(Lead $lead, Carbon $inicio): ?Carbon
    {
        $todo_el_dia = $this->ocupacion_por_demo($lead, $inicio, $inicio->copy()->setTime(23, 59, 0));
        $margen      = max(0, LeadDemoSettings::get_setup_minutos_antes() - self::MINUTOS_HASTA_EL_INICIO) + 1;

        $candidatas = [];
        foreach ($todo_el_dia as $intervalos) {
            foreach ($intervalos as $intervalo) {
                if ($intervalo['hasta']->gt($inicio)) {
                    $candidatas[$intervalo['hasta']->format('Y-m-d H:i:s')] = $intervalo['hasta'];
                }
            }
        }
        ksort($candidatas);

        foreach ($candidatas as $candidata) {
            $ventana = $this->ventana_desde($candidata->copy()->addMinutes($margen));
            if (! $ventana['cabe']) {
                return null;
            }
            if ($this->instancia_libre($lead, $ventana['inicio'], $ventana['fin']) !== null) {
                return $candidata->copy();
            }
        }

        return null;
    }

    /**
     * Previsión al GENERAR el mensaje: la instancia que hoy está libre para una demo que
     * arrancara en diez minutos, y —si no hay ninguna— cuándo se libera la primera. Sirve para
     * escribir la URL de la tienda en el texto y para que el agente no ofrezca "ahora" cuando no
     * puede cumplirlo. No reserva nada.
     *
     * @param Lead        $lead
     * @param Carbon|null $ahora
     *
     * @return array{demo: Demo|null, fin: Carbon|null, cabe_hoy: bool, proxima_liberacion: Carbon|null}
     */
    public function elegir_prevista(Lead $lead, ?Carbon $ahora = null): array
    {
        $ventana = $this->ventana_desde($ahora);
        if (! $ventana['cabe']) {
            return ['demo' => null, 'fin' => null, 'cabe_hoy' => false, 'proxima_liberacion' => null];
        }

        $libre = $this->instancia_libre($lead, $ventana['inicio'], $ventana['fin']);

        return [
            'demo'               => $libre !== null ? $libre['demo'] : null,
            'fin'                => $libre !== null ? $libre['fin'] : null,
            'cabe_hoy'           => true,
            'proxima_liberacion' => $libre === null ? $this->proxima_liberacion($lead, $ahora) : null,
        ];
    }

    /**
     * URL pública de la tienda demo de una instancia, con esquema, o '' si la instancia no tiene.
     *
     * Pasa por DemoUrlNormalizer por la misma razón que el link de ingreso (bug del 17/8/2026):
     * `ecommerce_spa_url` es texto libre y puede venir sin esquema.
     *
     * @param Demo|null $demo
     *
     * @return string
     */
    public static function url_tienda(?Demo $demo): string
    {
        if ($demo === null) {
            return '';
        }

        return (string) DemoUrlNormalizer::base((string) $demo->ecommerce_spa_url);
    }

    /**
     * Si el texto nombra la tienda de OTRA instancia (la prevista al generar), la reemplaza por la
     * de la instancia efectivamente asignada. Sólo toca URLs que este mismo sistema pudo haber
     * inyectado (las `ecommerce_spa_url` de las demos cargadas); cualquier otro texto queda igual.
     *
     * @param string $texto         Mensaje al lead (sugerido, aprobado o editado).
     * @param Demo   $demo_asignada Instancia que quedó asignada de verdad.
     *
     * @return string
     */
    public function corregir_links_de_tienda(string $texto, Demo $demo_asignada): string
    {
        $url_real = self::url_tienda($demo_asignada);
        if ($url_real === '' || $texto === '') {
            return $texto;
        }

        foreach (Demo::query()->where('id', '!=', $demo_asignada->id)->get() as $otra) {
            $url_otra = self::url_tienda($otra);
            if ($url_otra === '' || $url_otra === $url_real) {
                continue;
            }
            $texto = str_replace($url_otra, $url_real, $texto);
            /* Y la forma cruda guardada en la demo, por si el texto la trae sin normalizar. */
            $cruda = trim((string) $otra->ecommerce_spa_url);
            if ($cruda !== '' && $cruda !== $url_otra) {
                $texto = str_replace($cruda, $url_real, $texto);
            }
        }

        return $texto;
    }

    /**
     * Para cada demo, los intervalos [desde, hasta] (ya inflados con setup y gracia) de las
     * ventanas de otros leads que se solapan con la pedida. Una demo sin entradas está libre.
     *
     * @param Lead   $lead
     * @param Carbon $inicio
     * @param Carbon $fin
     *
     * @return array<int, array<int, array{desde: Carbon, hasta: Carbon}>> demo_id => intervalos solapados.
     */
    private function ocupacion_por_demo(Lead $lead, Carbon $inicio, Carbon $fin): array
    {
        $setup_antes  = LeadDemoSettings::get_setup_minutos_antes();
        $gracia       = LeadDemoSettings::get_gracia_minutos_post();
        $duracion     = LeadDemoSettings::get_duracion_minutos();
        $bloqueo_real = LeadDemoSettings::get_bloqueo_real_minutos();

        $pedida_desde = $inicio->copy()->subMinutes($setup_antes);
        $pedida_hasta = $fin->copy()->addMinutes($gracia);

        /* Un turno de ayer puede ocupar hoy por gracia/bloqueo real. También importa el setup
         * de un turno del día siguiente. La fecha de cada fila se conserva al armar su intervalo. */
        $ocupantes = Lead::query()
            ->whereBetween('demo_date', [
                $pedida_desde->copy()->subMinutes(max(1440, $bloqueo_real, $duracion + $gracia))->toDateString(),
                $pedida_hasta->copy()->addMinutes($setup_antes)->toDateString(),
            ])
            ->whereNotNull('demo_id')
            ->whereNotNull('demo_start_time')
            ->whereIn('status', self::ESTADOS_QUE_OCUPAN_LA_INSTANCIA)
            ->where('id', '!=', $lead->id)
            ->get(['id', 'demo_id', 'demo_date', 'demo_start_time', 'demo_end_time']);

        return $this->fines_solapados($ocupantes, $inicio->format('Y-m-d'), $pedida_desde, $pedida_hasta, $setup_antes, $gracia, $duracion, $bloqueo_real);
    }

    /**
     * Separado de ocupacion_por_demo() sólo para que la aritmética sea testeable sin base.
     *
     * @param Collection $ocupantes    Leads con demo ese día (id, demo_id, demo_start_time, demo_end_time).
     * @param string     $fecha        Y-m-d del inicio pedido.
     * @param Carbon     $pedida_desde Inicio pedido menos el setup.
     * @param Carbon     $pedida_hasta Fin pedido más la gracia.
     * @param int        $setup_antes
     * @param int        $gracia
     * @param int        $duracion
     * @param int        $bloqueo_real Minutos reales de bloqueo desde el inicio (11/9/2026); ver
     *                                 el docblock de LeadDemoSettings::KEY_BLOQUEO_REAL_MINUTOS.
     *
     * @return array<int, array<int, array{desde: Carbon, hasta: Carbon}>>
     */
    private function fines_solapados(Collection $ocupantes, string $fecha, Carbon $pedida_desde, Carbon $pedida_hasta, int $setup_antes, int $gracia, int $duracion, int $bloqueo_real = 0): array
    {
        $por_demo = [];

        foreach ($ocupantes as $ocupante) {
            $fecha_ocupante = $ocupante->demo_date ? $ocupante->demo_date->format('Y-m-d') : $fecha;
            $ocupa_inicio = $this->parse_hora($fecha_ocupante, (string) $ocupante->demo_start_time);
            if ($ocupa_inicio === null) {
                continue;
            }
            $ocupa_fin = $this->parse_hora($fecha_ocupante, (string) $ocupante->demo_end_time);
            if ($ocupa_fin === null) {
                $ocupa_fin = $ocupa_inicio->copy()->addMinutes($duracion);
            }

            $ocupa_desde = $ocupa_inicio->copy()->subMinutes($setup_antes);

            /* Fin real de la ocupación (11/9/2026): el mayor entre "fin nominal + gracia" y "inicio
             * + bloqueo real". Mismo criterio que LeadAiService::load_blocked_ranges_by_demo() --
             * sin este piso, la instancia de una demo directa quedaba libre para otro lead a los
             * ~70 minutos aunque el lead original siguiera adentro o quisiera reingresar. */
            $ocupa_hasta_por_gracia  = $ocupa_fin->copy()->addMinutes($gracia);
            $ocupa_hasta_por_bloqueo = $ocupa_inicio->copy()->addMinutes($bloqueo_real);
            $ocupa_hasta = $ocupa_hasta_por_bloqueo->gt($ocupa_hasta_por_gracia) ? $ocupa_hasta_por_bloqueo : $ocupa_hasta_por_gracia;

            /* Solapamiento de intervalos cerrados: se tocan si ninguno termina antes de que
             * empiece el otro. */
            if ($ocupa_hasta->lt($pedida_desde) || $ocupa_desde->gt($pedida_hasta)) {
                continue;
            }

            $por_demo[(int) $ocupante->demo_id][] = ['desde' => $ocupa_desde, 'hasta' => $ocupa_hasta];
        }

        return $por_demo;
    }

    /**
     * Combina fecha (Y-m-d) y hora (HH:MM en texto libre) en la zona horaria de referencia.
     *
     * @param string $fecha
     * @param string $hora
     *
     * @return Carbon|null Null si la hora está vacía o no parsea (ese lead se saltea, no rompe).
     */
    private function parse_hora(string $fecha, string $hora): ?Carbon
    {
        if (trim($hora) === '') {
            return null;
        }
        try {
            return Carbon::parse($fecha . ' ' . $hora, self::TZ);
        } catch (\Exception $e) {
            return null;
        }
    }
}
