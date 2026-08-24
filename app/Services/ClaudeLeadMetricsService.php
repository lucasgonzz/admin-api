<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Models\LeadPipelineStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Agregados del endpoint `GET claude/metrics`.
 *
 * 🔴 REGLA DE ORO DE ESTE SERVICIO: no devuelve NI UNA fila cruda. Todo se calcula en SQL
 * agregado (COUNT, SUM(CASE WHEN ...), GROUP BY). El problema real de volumen no es la API
 * sino la ventana de contexto de Claude: ~500 leads × ~50 mensajes son ~25.000 filas que
 * no entran en ningún prompt. Si alguna vez aparece acá un foreach que cuenta filas
 * traídas a PHP, el servicio está roto.
 *
 * 🔴 Se expone MÁS DE UNA definición de conversión y de tasa de respuesta, todas juntas y
 * cada una con su `nota`. Los datos admiten varias lecturas razonables y elegir una sola
 * por Lucas sería inventar.
 *
 * Aislado del controlador para poder testearlo sin HTTP.
 */
class ClaudeLeadMetricsService
{
    /**
     * Tope de días del rango [from, to].
     *
     * Medido el 24/8/2026: ni `leads.created_at` ni `lead_messages.created_at` /
     * `sent_at` tienen índice. Una métrica sin rango acotado hace full scan de las dos
     * tablas y puede trabar la base en horario laboral. Por eso `from` y `to` son
     * obligatorios y el rango tiene tope.
     */
    const MAX_DIAS = 366;

    /**
     * Estados que significan "el lead llegó al menos a tener la demo agendada".
     *
     * 🔴 No es "sort_order mayor o igual": `cerrado_perdido` y `en_pausa` tienen
     * sort_order posterior a `demo_agendada` pero NO implican haber llegado ahí. La lista
     * es explícita a propósito, y viaja en la `nota` de la respuesta para que quien lea el
     * número sepa exactamente qué se contó.
     *
     * @var array<int, string>
     */
    const STATUSES_A_DEMO_AGENDADA = [
        'demo_agendada',
        'ingresando_demo',
        'demo_en_curso',
        'demo_pendiente_de_ingreso',
        'demo_pendiente_de_terminar',
        'demo_realizada',
        'closer_activo',
        'mail2_enviado',
        'cerrado_ganado',
    ];

    /**
     * Estados que significan "el lead llegó al menos a tener la demo hecha".
     *
     * @var array<int, string>
     */
    const STATUSES_A_DEMO_REALIZADA = [
        'demo_realizada',
        'closer_activo',
        'mail2_enviado',
        'cerrado_ganado',
    ];

    /**
     * Arma la respuesta completa de `claude/metrics`.
     *
     * @param  string $from        Borde inferior ya normalizado (fecha-hora).
     * @param  string $to          Borde superior ya normalizado (fecha-hora).
     * @param  string $granularity day | week | month (lista blanca, validada en el controlador).
     * @return array<string, mixed>
     */
    public static function build(string $from, string $to, string $granularity): array
    {
        /* Timezone en el que la app escribe los timestamps. Los buckets agrupan el valor
           guardado TAL CUAL, sin convertir: sin declarar cuál es, un bucket "del día 12"
           es discutible. */
        $timezone = (string) config('app.timezone');
        $generated_at = AppTime::now();

        return [
            /* 🔴 Dos corridas del mismo rango pueden dar distinto: `leads` no tiene soft
               deletes, así que un lead borrado desaparece retroactivamente de todos los
               períodos pasados. Por eso la respuesta declara cuándo se calculó. */
            'generated_at' => $generated_at->toDateTimeString(),
            'timezone'     => $timezone,
            'rango'        => [
                'from'        => $from,
                'to'          => $to,
                'granularity' => $granularity,
                'nota'        => 'Los períodos agrupan el timestamp guardado tal cual, sin conversión de zona horaria. '
                    . 'La app escribe en ' . $timezone . '. Con granularity=week el período es el LUNES de esa semana; '
                    . 'con month, el día 1.',
            ],
            'leads_por_periodo' => static::leads_por_periodo($from, $to, $granularity),
            'embudo'            => static::embudo($from, $to, $generated_at),
            'respuesta'         => static::respuesta($from, $to),
            'seguimientos'      => static::seguimientos($from, $to, $granularity),
        ];
    }

    /**
     * Serie de leads creados por período, sobre `leads.created_at`.
     *
     * @param  string $from
     * @param  string $to
     * @param  string $granularity
     * @return array<string, mixed>
     */
    protected static function leads_por_periodo(string $from, string $to, string $granularity): array
    {
        $periodo = ClaudeLeadQueryService::period_expression('leads.created_at', $granularity);

        $rows = DB::table('leads')
            ->where('leads.created_at', '>=', $from)
            ->where('leads.created_at', '<=', $to)
            ->groupByRaw($periodo)
            ->orderByRaw($periodo)
            ->selectRaw($periodo . ' as periodo, COUNT(*) as cantidad')
            ->get();

        $serie = [];
        foreach ($rows as $row) {
            $serie[] = [
                'periodo'  => (string) $row->periodo,
                'cantidad' => (int) $row->cantidad,
            ];
        }

        return [
            'serie' => $serie,
            'nota'  => 'Leads cuyo created_at cae en el rango. Es la cohorte que usan también el embudo y las tasas de respuesta.',
        ];
    }

    /**
     * Embudo: conteo por estado ACTUAL, las cuatro definiciones de conversión, y el
     * bloque de desacuerdo entre las dos definiciones fuertes.
     *
     * @param  string $from
     * @param  string $to
     * @param  Carbon $generated_at
     * @return array<string, mixed>
     */
    protected static function embudo(string $from, string $to, Carbon $generated_at): array
    {
        /* Conteo por estado actual. */
        $por_estado_rows = DB::table('leads')
            ->where('leads.created_at', '>=', $from)
            ->where('leads.created_at', '<=', $to)
            ->groupBy('leads.status')
            ->orderByRaw('COUNT(*) DESC')
            ->selectRaw('leads.status as status, COUNT(*) as cantidad')
            ->get();

        $labels = static::labels_por_slug();

        $por_estado = [];
        foreach ($por_estado_rows as $row) {
            $slug = (string) $row->status;
            $por_estado[] = [
                'status'   => $slug,
                'label'    => isset($labels[$slug]) ? $labels[$slug] : LeadPipelineStatus::humanize_slug($slug),
                'cantidad' => (int) $row->cantidad,
            ];
        }

        /* Placeholders para los dos IN de estados. Los slugs son constantes de esta
           clase, pero igual viajan como bindings y no interpolados. */
        $ph_agendada  = implode(',', array_fill(0, count(static::STATUSES_A_DEMO_AGENDADA), '?'));
        $ph_realizada = implode(',', array_fill(0, count(static::STATUSES_A_DEMO_REALIZADA), '?'));

        $select_sql = 'COUNT(*) as total'
            . ', SUM(CASE WHEN leads.status IN (' . $ph_agendada . ') THEN 1 ELSE 0 END) as a_demo_agendada'
            . ', SUM(CASE WHEN leads.status IN (' . $ph_realizada . ') THEN 1 ELSE 0 END) as a_demo_realizada'
            . ", SUM(CASE WHEN leads.status = 'cerrado_ganado' THEN 1 ELSE 0 END) as a_cerrado_ganado"
            . ', SUM(CASE WHEN leads.promoted_client_id IS NOT NULL THEN 1 ELSE 0 END) as a_cliente'
            . ", SUM(CASE WHEN leads.status = 'cerrado_ganado' AND leads.promoted_client_id IS NULL THEN 1 ELSE 0 END) as ganados_sin_promover"
            . ", SUM(CASE WHEN leads.status <> 'cerrado_ganado' AND leads.promoted_client_id IS NOT NULL THEN 1 ELSE 0 END) as promovidos_sin_ganar";

        $bindings = array_merge(static::STATUSES_A_DEMO_AGENDADA, static::STATUSES_A_DEMO_REALIZADA);

        $row = DB::table('leads')
            ->where('leads.created_at', '>=', $from)
            ->where('leads.created_at', '<=', $to)
            ->selectRaw($select_sql, $bindings)
            ->first();

        $total = $row !== null ? (int) $row->total : 0;

        $nota_estado_actual = 'Mira el estado ACTUAL, no el histórico: un lead que pasó por demo_realizada y hoy está '
            . 'en cerrado_perdido NO cuenta acá. Reconstruir el histórico exigiría leer los mensajes is_status_event, '
            . 'que es otro trabajo.';

        $conversiones = [
            'a_demo_agendada' => static::definicion(
                $row !== null ? (int) $row->a_demo_agendada : 0,
                $total,
                'Estado actual dentro de: ' . implode(', ', static::STATUSES_A_DEMO_AGENDADA) . '. ' . $nota_estado_actual
                    . ' cerrado_perdido y en_pausa quedan EXCLUIDOS a propósito: tienen sort_order posterior pero no implican haber llegado a la demo.'
            ),
            'a_demo_realizada' => static::definicion(
                $row !== null ? (int) $row->a_demo_realizada : 0,
                $total,
                'Estado actual dentro de: ' . implode(', ', static::STATUSES_A_DEMO_REALIZADA) . '. ' . $nota_estado_actual
            ),
            'a_cerrado_ganado' => static::definicion(
                $row !== null ? (int) $row->a_cerrado_ganado : 0,
                $total,
                "Estado actual = 'cerrado_ganado'. " . $nota_estado_actual
            ),
            'a_cliente' => static::definicion(
                $row !== null ? (int) $row->a_cliente : 0,
                $total,
                'promoted_client_id IS NOT NULL. Es la definición más dura y la más real: el lead terminó siendo un Client.'
            ),
        ];

        /* Censura a derecha: los leads de los últimos días todavía no tuvieron tiempo de
           convertir, así que la última semana SIEMPRE parece peor. Se declara cuánto
           maduró la cohorte para poder aclararlo en vez de concluir que "cayó la conversión". */
        $to_carbon = static::parse_o_null($to);
        $dias_de_maduracion = 0;
        if ($to_carbon !== null && $to_carbon->lessThan($generated_at)) {
            $dias_de_maduracion = (int) $to_carbon->diffInDays($generated_at);
        }

        return [
            'cohort_from'        => $from,
            'cohort_to'          => $to,
            'total_leads'        => $total,
            'dias_de_maduracion' => $dias_de_maduracion,
            'por_estado'         => $por_estado,
            'conversiones'       => $conversiones,
            /* Las dos definiciones fuertes se setean por caminos distintos y se desfasan.
               Este bloque dice CUÁL de las dos está mintiendo, en vez de elegir por Lucas. */
            'desacuerdo' => [
                'ganados_sin_promover' => $row !== null ? (int) $row->ganados_sin_promover : 0,
                'promovidos_sin_ganar' => $row !== null ? (int) $row->promovidos_sin_ganar : 0,
                'nota' => "a_cerrado_ganado (status='cerrado_ganado') y a_cliente (promoted_client_id) se setean por "
                    . 'caminos distintos del sistema y se desfasan. ganados_sin_promover son ventas marcadas que nunca '
                    . 'se promovieron a Client; promovidos_sin_ganar son clientes reales cuyo lead quedó en otro estado. '
                    . 'Si los dos números son chicos, las dos definiciones coinciden y cualquiera sirve.',
            ],
            'nota' => 'Censura a derecha: los leads más nuevos de la cohorte todavía no tuvieron tiempo de convertir, '
                . 'así que el último tramo del rango siempre parece peor. dias_de_maduracion dice cuántos días pasaron '
                . 'entre cohort_to y generated_at. Con 0 o pocos días, NO se puede concluir que cayó la conversión.',
        ];
    }

    /**
     * Las CUATRO lecturas de la tasa de respuesta, todas sobre la misma cohorte de leads
     * (los creados dentro del rango), sin restringir los mensajes a la ventana: un lead
     * creado el último día del rango puede contestar después.
     *
     * Todo se resuelve con una subconsulta agregada por lead y una agregación encima. La
     * base devuelve UNA sola fila.
     *
     * @param  string $from
     * @param  string $to
     * @return array<string, mixed>
     */
    protected static function respuesta(string $from, string $to): array
    {
        $salido    = ClaudeLeadQueryService::saliente_salido_sql('lm');
        $entregado = ClaudeLeadQueryService::saliente_entregado_sql('lm');

        /* Un renglón por lead de la cohorte, con todo lo que hace falta para las cuatro
           lecturas. El orden cronológico se resuelve por `id` (orden de inserción), no por
           sent_at: sent_at viene de los webhooks y puede llegar desordenado. */
        $sub = DB::table('lead_messages as lm')
            ->join('leads as l', 'l.id', '=', 'lm.lead_id')
            ->where('l.created_at', '>=', $from)
            ->where('l.created_at', '<=', $to)
            ->where('lm.is_status_event', 0)
            ->groupBy('lm.lead_id')
            ->selectRaw(
                'lm.lead_id as lead_id'
                . ", SUM(CASE WHEN lm.sender = 'lead' THEN 1 ELSE 0 END) as msgs_lead"
                . ', SUM(CASE WHEN ' . $salido . ' THEN 1 ELSE 0 END) as salientes_salidos'
                . ', SUM(CASE WHEN ' . $entregado . ' THEN 1 ELSE 0 END) as salientes_entregados'
                . ", MIN(CASE WHEN lm.sender = 'lead' THEN lm.id END) as primer_id_lead"
                . ", MAX(CASE WHEN lm.sender = 'lead' THEN lm.id END) as ultimo_id_lead"
                . ', MIN(CASE WHEN ' . $salido . ' THEN lm.id END) as primer_id_saliente'
                . ', MIN(CASE WHEN ' . $salido . ' AND lm.is_followup = 1 THEN lm.id END) as primer_id_seguimiento'
            );

        $row = DB::query()->fromSub($sub, 'x')->selectRaw(
            'SUM(CASE WHEN salientes_salidos > 0 THEN 1 ELSE 0 END) as den_alguna'
            . ', SUM(CASE WHEN salientes_salidos > 0 AND msgs_lead > 0 THEN 1 ELSE 0 END) as num_alguna'
            . ', SUM(CASE WHEN salientes_entregados > 0 THEN 1 ELSE 0 END) as den_entregado'
            . ', SUM(CASE WHEN salientes_entregados > 0 AND msgs_lead > 0 THEN 1 ELSE 0 END) as num_entregado'
            . ', SUM(CASE WHEN primer_id_saliente IS NOT NULL AND (primer_id_lead IS NULL OR primer_id_saliente < primer_id_lead) THEN 1 ELSE 0 END) as den_primer'
            . ', SUM(CASE WHEN primer_id_saliente IS NOT NULL AND primer_id_lead IS NOT NULL AND primer_id_saliente < primer_id_lead THEN 1 ELSE 0 END) as num_primer'
            . ', SUM(CASE WHEN primer_id_seguimiento IS NOT NULL THEN 1 ELSE 0 END) as den_seguimiento'
            . ', SUM(CASE WHEN primer_id_seguimiento IS NOT NULL AND ultimo_id_lead IS NOT NULL AND ultimo_id_lead > primer_id_seguimiento THEN 1 ELSE 0 END) as num_seguimiento'
        )->first();

        $den_alguna       = $row !== null ? (int) $row->den_alguna : 0;
        $num_alguna       = $row !== null ? (int) $row->num_alguna : 0;
        $den_entregado    = $row !== null ? (int) $row->den_entregado : 0;
        $num_entregado    = $row !== null ? (int) $row->num_entregado : 0;
        $den_primer       = $row !== null ? (int) $row->den_primer : 0;
        $num_primer       = $row !== null ? (int) $row->num_primer : 0;
        $den_seguimiento  = $row !== null ? (int) $row->den_seguimiento : 0;
        $num_seguimiento  = $row !== null ? (int) $row->num_seguimiento : 0;

        $nota_denominador = "Denominador limpio de las tres trampas del dato: se excluyen los mensajes en status "
            . "'sugerido' o 'rechazado' (nunca salieron), los is_status_event=1 (evento interno, no actividad del hilo) "
            . 'y los que tienen whatsapp_message_id NULL (Kapso nunca confirmó el envío).';

        $definiciones = [
            'respondio_alguna_vez' => static::definicion(
                $num_alguna,
                $den_alguna,
                'Leads con al menos un mensaje del lead, sobre leads con al menos un saliente que SALIÓ. '
                    . '🔴 OJO CON EL NOMBRE: el numerador NO exige que el mensaje del lead sea POSTERIOR al saliente, '
                    . 'así que mide "el lead escribió alguna vez", no "el lead nos contestó". En un pipeline donde la '
                    . 'mayoría de los leads escriben primero, esta tasa tiende a 100% y no informa nada por sí sola. '
                    . 'Para la tasa con orden real usá respondio_al_primer_contacto o respondio_a_seguimiento. '
                    . $nota_denominador
            ),
            'respondio_alguna_vez_entregado' => static::definicion(
                $num_entregado,
                $den_entregado,
                'Igual que respondio_alguna_vez pero exigiendo además confirmación de ENTREGA de Meta '
                    . "(whatsapp_delivery_status en 'entregado' o 'leido'). "
                    . '🔴 La diferencia entre esta tasa y respondio_alguna_vez es el daño del problema de pago de Meta, '
                    . 'aislado: mensajes que Kapso aceptó pero que Meta nunca entregó. Si el denominador de esta es '
                    . 'mucho más chico que el de la otra, ahí está la ventana rota.'
            ),
            'respondio_al_primer_contacto' => static::definicion(
                $num_primer,
                $den_primer,
                'De los leads cuyo PRIMER mensaje del hilo es saliente (y salió), cuántos tienen después un mensaje '
                    . 'del lead. El orden se resuelve por id de mensaje (orden de inserción), no por sent_at, que viene '
                    . 'de los webhooks y puede llegar desordenado. Los mensajes de sender=sistema que no salieron no '
                    . 'cuentan como primer contacto.'
            ),
            'respondio_a_seguimiento' => static::definicion(
                $num_seguimiento,
                $den_seguimiento,
                'De los leads con al menos un seguimiento CONFIRMADO (is_followup=1 y whatsapp_message_id no nulo), '
                    . 'cuántos tienen un mensaje del lead posterior al PRIMER seguimiento confirmado. Los seguimientos '
                    . 'que nunca salieron quedan fuera del denominador, así que esta tasa no se ensucia con envíos '
                    . 'que jamás llegaron.'
            ),
        ];

        return array_merge($definiciones, [
            'nota' => 'Cohorte: leads creados dentro del rango. Los mensajes NO se restringen al rango, porque un lead '
                . 'creado el último día puede contestar después. Todas las tasas son numerador/denominador; tasa=null '
                . 'significa denominador cero, no cero por ciento.',
        ]);
    }

    /**
     * Salud de los seguimientos automáticos por período. Es la serie que muestra la
     * ventana del problema de Meta como un pozo: `intentados` se mantiene y
     * `confirmados` se cae.
     *
     * A diferencia del embudo y de las tasas, este bloque se recorta por el instante del
     * MENSAJE (no por la cohorte del lead): lo que interesa es cuándo se intentó enviar.
     *
     * @param  string $from
     * @param  string $to
     * @param  string $granularity
     * @return array<string, mixed>
     */
    protected static function seguimientos(string $from, string $to, string $granularity): array
    {
        $time_sql = ClaudeLeadQueryService::message_time_sql();
        $periodo  = ClaudeLeadQueryService::period_expression($time_sql, $granularity);

        /*
         * 🔴 Las dos condiciones de abajo no son cosméticas: sin ellas esta serie —que es
         * justamente la que se mira para dimensionar el daño del impago de Meta— queda inflada
         * con filas que NO son envíos caídos. Hay tres caminos de producción que escriben
         * is_followup=true con whatsapp_message_id nulo sin que ningún envío haya fallado:
         *
         *   1. LeadFollowupService::create_pending_followup_for_verification() → status='sugerido'.
         *      Es un seguimiento esperando aprobación: nunca se intentó enviar.
         *   2. El rechazo de esa misma sugerencia → status='rechazado'. Ídem.
         *   3. LeadFollowupService, notificación al closer (~línea 302) → status='enviado' pero
         *      SIN followup_template_id, y el WhatsApp fue al closer, no al lead. Es una fila de
         *      contabilidad interna para que suba el contador de cupo.
         *
         * status='enviado' saca las dos primeras; followup_template_id NOT NULL saca la tercera.
         * Hacen falta las dos: ninguna sola alcanza.
         */
        $rows = DB::table('lead_messages')
            ->where('lead_messages.is_followup', 1)
            ->where('lead_messages.is_status_event', 0)
            ->where('lead_messages.status', 'enviado')
            ->whereNotNull('lead_messages.followup_template_id')
            ->whereRaw($time_sql . ' >= ?', [$from])
            ->whereRaw($time_sql . ' <= ?', [$to])
            ->groupByRaw($periodo)
            ->orderByRaw($periodo)
            ->selectRaw(
                $periodo . ' as periodo'
                . ', COUNT(*) as intentados'
                . ', SUM(CASE WHEN lead_messages.whatsapp_message_id IS NOT NULL THEN 1 ELSE 0 END) as confirmados'
                . ', SUM(CASE WHEN lead_messages.whatsapp_message_id IS NULL THEN 1 ELSE 0 END) as no_confirmados'
                . ", SUM(CASE WHEN lead_messages.whatsapp_delivery_status IN ('entregado','leido') THEN 1 ELSE 0 END) as entregados"
                . ", SUM(CASE WHEN lead_messages.whatsapp_delivery_status = 'fallido' THEN 1 ELSE 0 END) as fallidos"
            )
            ->get();

        $serie = [];
        foreach ($rows as $row) {
            $serie[] = [
                'periodo'        => (string) $row->periodo,
                'intentados'     => (int) $row->intentados,
                'confirmados'    => (int) $row->confirmados,
                'no_confirmados' => (int) $row->no_confirmados,
                'entregados'     => (int) $row->entregados,
                'fallidos'       => (int) $row->fallidos,
            ];
        }

        return [
            'serie' => $serie,
            'nota'  => 'Recortado por el instante del mensaje (COALESCE(sent_at, created_at)), no por la cohorte del lead. '
                . 'Cuenta SOLO seguimientos reales por plantilla al lead: status=enviado y followup_template_id no nulo. '
                . 'Quedan excluidos a propósito los seguimientos sugeridos sin aprobar (status=sugerido), los rechazados, '
                . 'y las notificaciones al closer (is_followup=1 sin followup_template_id, donde el WhatsApp fue al closer '
                . 'y no al lead): ninguno de los tres es un envío que haya fallado. '
                . 'no_confirmados = Kapso nunca devolvió id: el envío no salió. entregados/fallidos = lo que reportó el '
                . 'webhook de Meta. 🔴 no_confirmados alto NO es solo el impago de Meta: también hay números inválidos y '
                . 'plantillas despausadas. Para separarlos, usar claude/messages con group_by=error.',
        ];
    }

    /**
     * Empaqueta una definición (conversión o tasa) con su numerador, su denominador, la
     * tasa y la nota que explica qué mide exactamente.
     *
     * @param  int    $numerador
     * @param  int    $denominador
     * @param  string $nota
     * @return array<string, mixed>
     */
    protected static function definicion(int $numerador, int $denominador, string $nota): array
    {
        return [
            'numerador'   => $numerador,
            'denominador' => $denominador,
            'tasa'        => $denominador > 0 ? round($numerador / $denominador, 4) : null,
            'nota'        => $nota,
        ];
    }

    /**
     * Mapa slug => label del catálogo del pipeline, armado una sola vez por request.
     *
     * @return array<string, string>
     */
    protected static function labels_por_slug(): array
    {
        /* 🔴 UNA sola consulta al catálogo, no una por slug. LeadPipelineStatus::label_for() hace
           un SELECT por llamada, así que usarlo dentro de un foreach sobre all_slugs() son ~16
           consultas para resolver etiquetas de un catálogo de 15 filas — justo lo contrario de la
           doctrina que este archivo declara. Está acotado por el catálogo, pero no hay motivo. */
        $map = [];
        foreach (LeadPipelineStatus::query()->get(['slug', 'label']) as $fila) {
            $map[(string) $fila->slug] = (string) $fila->label;
        }

        /* Los slugs del catálogo por defecto que todavía no tienen fila propia se humanizan. */
        foreach (LeadPipelineStatus::all_slugs() as $slug) {
            if (! isset($map[$slug])) {
                $map[$slug] = LeadPipelineStatus::humanize_slug($slug);
            }
        }

        return $map;
    }

    /**
     * Parsea una fecha-hora sin lanzar. Devuelve null si el string no es parseable.
     *
     * @param  string $value
     * @return Carbon|null
     */
    protected static function parse_o_null(string $value): ?Carbon
    {
        try {
            return Carbon::parse($value, config('app.timezone'));
        } catch (\Exception $e) {
            return null;
        }
    }
}
