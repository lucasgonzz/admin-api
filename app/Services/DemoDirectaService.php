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
     * (realizada, closer, cerrado, pendiente de ingreso, en pausa) puede seguir teniendo
     * demo_date/demo_id cargados, pero ya no va a entrar por ese turno: la instancia se considera
     * libre. Es más laxo que load_blocked_ranges_by_demo() de la grilla vieja (que bloqueaba por
     * cualquier lead con demo_date, sin mirar el estado) a propósito: con tres instancias y una
     * ventana de varias horas por lead, un no-show bloquearía media tarde.
     *
     * @var array<int, string>
     */
    const ESTADOS_QUE_OCUPAN_LA_INSTANCIA = ['demo_agendada', 'demo_en_curso', 'demo_pendiente_de_terminar'];

    /**
     * Ventana de la demo directa a partir de un instante.
     *
     * Inicio: ahora + MINUTOS_HASTA_EL_INICIO. Fin: el tope de la ventana extendida
     * (LeadDemoSettings::get_ventana_extendida_max_horas()), cortado a las 23:59 del día del
     * inicio —mismos dos clamps que la ventana extendida de la misión 47— sin recortar por otras
     * demos: la instancia se elige libre para toda la ventana, no al revés.
     *
     * @param Carbon|null $ahora Instante de referencia (AppTime::now() si no viene).
     *
     * @return array{inicio: Carbon, fin: Carbon}
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

        return ['inicio' => $inicio, 'fin' => $fin];
    }

    /**
     * Primera instancia (por id) libre para la ventana pedida, o null si las tres están ocupadas.
     *
     * "Libre" = ningún OTRO lead, en un estado que ocupe la instancia, tiene ese mismo demo_id el
     * día del inicio con una ventana [inicio - setup, fin + gracia] que se solape con la pedida
     * (también inflada con setup y gracia: dos setups no pueden pisarse, y la gracia es el rato en
     * que el lead anterior todavía puede estar adentro).
     *
     * @param Lead   $lead   Lead al que se le va a asignar (se excluye a sí mismo: reagendar no
     *                       puede chocar contra la propia reserva, misma lección del lead #10).
     * @param Carbon $inicio Inicio de la ventana pedida.
     * @param Carbon $fin    Fin de la ventana pedida.
     *
     * @return Demo|null
     */
    public function instancia_libre(Lead $lead, Carbon $inicio, Carbon $fin): ?Demo
    {
        $ocupadas = $this->ocupacion_por_demo($lead, $inicio, $fin);

        foreach (Demo::query()->orderBy('id')->get() as $demo) {
            if (empty($ocupadas[$demo->id])) {
                return $demo;
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

        $mas_temprano = null;
        foreach ($demos as $demo) {
            if (empty($ocupadas[$demo->id])) {
                return null;
            }
            /* La instancia se libera cuando termina la ÚLTIMA de sus ventanas solapadas. */
            $libera_a = null;
            foreach ($ocupadas[$demo->id] as $fin_ocupada) {
                if ($libera_a === null || $fin_ocupada->gt($libera_a)) {
                    $libera_a = $fin_ocupada;
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
     * @return array{demo: Demo|null, proxima_liberacion: Carbon|null}
     */
    public function elegir_prevista(Lead $lead, ?Carbon $ahora = null): array
    {
        $ventana = $this->ventana_desde($ahora);
        $demo    = $this->instancia_libre($lead, $ventana['inicio'], $ventana['fin']);

        return [
            'demo'               => $demo,
            'proxima_liberacion' => $demo === null ? $this->proxima_liberacion($lead, $ahora) : null,
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
     * Para cada demo, los "fin + gracia" de las ventanas de otros leads que se solapan con la
     * pedida. Una demo sin entradas está libre.
     *
     * @param Lead   $lead
     * @param Carbon $inicio
     * @param Carbon $fin
     *
     * @return array<int, array<int, Carbon>> demo_id => lista de fines (con gracia) solapados.
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
     * @return array<int, array<int, Carbon>>
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

            $por_demo[(int) $ocupante->demo_id][] = $ocupa_hasta;
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
