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
     * `demo_pendiente_de_ingreso` SÍ ocupa mientras conserve su turno: un lead de la dinámica
     * actual llega ahí por timeout y puede entrar más tarde dentro de su hora (el evento
     * demo.ingreso lo avanza desde ese estado). Los de la demo directa, en cambio, pierden el turno
     * al pasar a ese estado (marcar_no_ingreso y el vencimiento por no-show limpian demo_date), así
     * que no bloquean nada: cuando vuelven, se les asigna de nuevo.
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
        $ahora  = $ahora !== null ? $ahora->copy()->setTimezone(self::TZ) : AppTime::now(self::TZ);
        $inicio = $ahora->copy()->addMinutes(self::MINUTOS_HASTA_EL_INICIO)->second(0);

        $fin  = $inicio->copy()->addHours(LeadDemoSettings::get_ventana_extendida_max_horas());
        $tope = $inicio->copy()->setTime(23, 59, 0);
        if ($fin->gt($tope)) {
            $fin = $tope;
        }

        $cabe = $fin->diffInMinutes($inicio, false) <= -LeadDemoSettings::get_duracion_minutos();

        return ['inicio' => $inicio, 'fin' => $fin, 'cabe' => $cabe];
    }

    /**
     * Primera instancia (por id) libre para la ventana pedida, con la ventana que de verdad se le
     * puede dar, o null si ninguna sirve.
     *
     * "Libre" = ningún OTRO lead, en un estado que ocupe la instancia, tiene ese mismo demo_id el
     * día del inicio con una ventana [inicio - setup, fin + gracia] que cubra el INICIO pedido
     * (también inflado con setup y gracia: dos setups no pueden pisarse, y la gracia es el rato en
     * que el lead anterior todavía puede estar adentro).
     *
     * Si la instancia está libre ahora pero tiene un turno más tarde (una demo de la dinámica
     * actual a las 15:00, o un lead que dijo "sí" hace un rato y arranca en cinco minutos), la
     * ventana se RECORTA para terminar antes de ese turno, siempre que quede al menos la duración
     * configurada. Sin ese recorte, tres instancias vacías con una demo de una hora cada una a la
     * tarde dejaban a un "sí" de las 10:00 sin lugar, porque la ventana extendida de seis horas no
     * entraba entera.
     *
     * @param Lead   $lead   Lead al que se le va a asignar (se excluye a sí mismo: reagendar no
     *                       puede chocar contra la propia reserva, misma lección del lead #10).
     * @param Carbon $inicio Inicio de la ventana pedida.
     * @param Carbon $fin    Fin de la ventana pedida (tope de la ventana extendida).
     *
     * @return array{demo: Demo, fin: Carbon}|null
     */
    public function instancia_libre(Lead $lead, Carbon $inicio, Carbon $fin): ?array
    {
        $ocupadas    = $this->ocupacion_por_demo($lead, $inicio, $fin);
        $setup_antes = LeadDemoSettings::get_setup_minutos_antes();
        $gracia      = LeadDemoSettings::get_gracia_minutos_post();
        $duracion    = LeadDemoSettings::get_duracion_minutos();

        foreach (Demo::query()->orderBy('id')->get() as $demo) {
            if (empty($ocupadas[$demo->id])) {
                return ['demo' => $demo, 'fin' => $fin->copy()];
            }

            /* Hay turnos que se solapan con la ventana pedida: sirve sólo si todos arrancan
             * después del inicio, y entonces la ventana termina antes del más temprano. */
            $fin_recortado = $fin->copy();
            $bloqueada     = false;
            foreach ($ocupadas[$demo->id] as $intervalo) {
                /* $intervalo['desde'] ya viene con el setup descontado. Si ese margen cae antes
                 * de que termine nuestro propio setup (inicio - setup), el turno pisa el arranque. */
                if ($intervalo['desde']->lte($inicio->copy()->subMinutes($setup_antes))) {
                    $bloqueada = true;
                    break;
                }
                $tope_por_este = $intervalo['desde']->copy()->subMinutes($gracia + 1);
                if ($tope_por_este->lt($fin_recortado)) {
                    $fin_recortado = $tope_por_este;
                }
            }

            if ($bloqueada) {
                continue;
            }
            if ($fin_recortado->diffInMinutes($inicio, false) <= -$duracion) {
                return ['demo' => $demo, 'fin' => $fin_recortado->second(0)];
            }
        }

        return null;
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
        $ventana  = $this->ventana_desde($ahora);
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

        return $mas_temprano;
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
        $setup_antes = LeadDemoSettings::get_setup_minutos_antes();
        $gracia      = LeadDemoSettings::get_gracia_minutos_post();
        $duracion    = LeadDemoSettings::get_duracion_minutos();

        $pedida_desde = $inicio->copy()->subMinutes($setup_antes);
        $pedida_hasta = $fin->copy()->addMinutes($gracia);

        /* demo_date es DATE puro: se compara con la fecha calendario del inicio pedido. */
        $ocupantes = Lead::query()
            ->whereDate('demo_date', $inicio->format('Y-m-d'))
            ->whereNotNull('demo_id')
            ->whereNotNull('demo_start_time')
            ->whereIn('status', self::ESTADOS_QUE_OCUPAN_LA_INSTANCIA)
            ->where('id', '!=', $lead->id)
            ->get(['id', 'demo_id', 'demo_date', 'demo_start_time', 'demo_end_time']);

        return $this->fines_solapados($ocupantes, $inicio->format('Y-m-d'), $pedida_desde, $pedida_hasta, $setup_antes, $gracia, $duracion);
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
     *
     * @return array<int, array<int, array{desde: Carbon, hasta: Carbon}>>
     */
    private function fines_solapados(Collection $ocupantes, string $fecha, Carbon $pedida_desde, Carbon $pedida_hasta, int $setup_antes, int $gracia, int $duracion): array
    {
        $por_demo = [];

        foreach ($ocupantes as $ocupante) {
            $ocupa_inicio = $this->parse_hora($fecha, (string) $ocupante->demo_start_time);
            if ($ocupa_inicio === null) {
                continue;
            }
            $ocupa_fin = $this->parse_hora($fecha, (string) $ocupante->demo_end_time);
            if ($ocupa_fin === null) {
                $ocupa_fin = $ocupa_inicio->copy()->addMinutes($duracion);
            }

            $ocupa_desde = $ocupa_inicio->copy()->subMinutes($setup_antes);
            $ocupa_hasta = $ocupa_fin->copy()->addMinutes($gracia);

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
