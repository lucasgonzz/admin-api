<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Traduce el lenguaje de filtros de los endpoints de lectura `claude/*` a consultas de
 * query builder. Lo comparten el listado de leads y las dos consultas de mensajes, así el
 * lenguaje de filtros es UNO SOLO y no se desincroniza entre endpoints.
 *
 * 🔴 Todo lo que sale de acá es query builder sobre `DB::table()`, nunca Eloquent.
 * Motivo concreto: `LeadMessage::$appends` trae `suggested_lead_status_label`,
 * `pending_actions_summary` y `sent_by_admin_name`; `pending_actions_summary` toca
 * `$this->lead` por mensaje y `suggested_lead_status_label` pega contra
 * `LeadPipelineStatus`. Serializar un hilo largo con los appends puestos es N+1
 * garantizado. Acá las columnas se piden por nombre y punto.
 *
 * 🔴 Los filtros llegan ya normalizados desde el controlador (null = ausente). Este
 * servicio no conoce la Request: así se puede testear sin HTTP.
 */
class ClaudeLeadQueryService
{
    /**
     * Tamaño de página por defecto. Grande a propósito: `RouteServiceProvider` deja el
     * grupo `api` en 60 req/min y, como las rutas `claude/*` no tienen usuario Sanctum,
     * el limitador agrupa por IP. Un barrido completo tiene que ser unas pocas llamadas
     * grandes y no cientos de llamadas chicas.
     */
    const LIMIT_DEFAULT = 200;

    /** Tope duro de filas por página. Un `limit` mayor se recorta, no tira error. */
    const LIMIT_MAX = 500;

    /* El tope de destinatarios por lote NO vive acá: la única fuente es
       ClaudeLeadsOutboundController::MAX_BATCH, que es la constante que efectivamente se aplica.
       Había una copia en este archivo con otro valor (200 contra los 50 reales) que solo
       alimentaba claude/schema, así que el schema publicaba un tope que el endpoint rechazaba. */

    /** Cantidad de caracteres de `whatsapp_send_error` que definen un grupo en `group_by=error`. */
    const ERROR_GROUP_CHARS_DEFAULT = 120;
    const ERROR_GROUP_CHARS_MIN = 20;
    const ERROR_GROUP_CHARS_MAX = 500;

    /** Valores válidos de `lead_messages.sender`. */
    const SENDERS = ['lead', 'setter', 'sistema'];

    /** Valores válidos de `lead_messages.status`. */
    const MESSAGE_STATUSES = ['enviado', 'sugerido', 'rechazado'];

    /** Valores válidos del filtro `delivery` (ver apply_delivery_filter). */
    const DELIVERY = ['confirmado', 'no_confirmado', 'entregado', 'leido', 'fallido'];

    /** Columnas por las que se puede ordenar el listado de leads. */
    const LEADS_ORDER_BY = ['id', 'created_at', 'last_message_at'];

    /** Agrupaciones disponibles en `claude/messages`. */
    const MESSAGES_GROUP_BY = ['lead', 'template', 'error', 'day'];

    /** `include` disponibles en `claude/leads`. */
    const LEADS_INCLUDES = ['contacto', 'demo', 'contrato', 'conteos'];

    /** `include` disponibles en `claude/messages` (aplica al lead embebido). */
    const MESSAGES_INCLUDES = ['contacto'];

    /** Granularidades de agrupación temporal. */
    const GRANULARITIES = ['day', 'week', 'month'];

    /**
     * Proyección por defecto de un lead: flaca y SIN PII. Ni `phone` ni `email` ni
     * ningún `contract_*` salen de acá sin un `include` explícito.
     *
     * @var array<int, string>
     */
    const LEAD_COLUMNS_BASE = [
        'id',
        'contact_name',
        'company_name',
        'status',
        'created_at',
        'first_message_at',
        'last_message_at',
        'demo_date',
        'promoted_client_id',
        'requiere_seguimiento',
    ];

    /** `include=contacto`: datos personales del lead. Opt-in explícito. */
    const LEAD_COLUMNS_CONTACTO = ['phone', 'email'];

    /** `include=demo`: estado del ciclo de vida de la demo. */
    const LEAD_COLUMNS_DEMO = [
        'demo_date',
        'demo_start_time',
        'demo_end_time',
        'demo_setup_status',
        'demo_ingreso_confirmado',
        'demo_terminada_confirmada',
    ];

    /** `include=contrato`: dato comercial sensible. Opt-in explícito. */
    const LEAD_COLUMNS_CONTRATO = [
        'contract_client_name',
        'contract_client_razon_social',
        'contract_client_cuit',
        'contract_currency',
        'contract_precio_licencia',
        'contract_fecha_emision',
        'contract_fecha_primer_pago_unico',
        'contract_financiacion',
        'contract_mensualidad_moneda',
        'contract_mensualidad_base',
        'contract_usuarios_incluidos',
        'contract_usuarios_extra',
        'contract_precio_usuario_extra',
        'contract_perfiles_ecommerce',
        'contract_precio_perfil_ecommerce',
        'contract_fecha_primer_pago_mensual',
        'contract_clausulas_particulares',
    ];

    /**
     * Proyección de un mensaje. Se pide por nombre para no arrastrar los campos gordos
     * (`ai_reasoning`, `calendar_snapshot`, y los JSON de `pending_actions`,
     * `horarios_ofrecidos`, `applied_actions_summary`, `actions_override_log`) ni los
     * appends del modelo.
     *
     * @var array<int, string>
     */
    const MESSAGE_COLUMNS = [
        'id',
        'lead_id',
        'sender',
        'status',
        'content',
        'is_followup',
        'followup_template_id',
        'is_status_event',
        'is_error',
        'sent_at',
        'created_at',
        /* 🔴 `read_at` NO significa "el lead leyó el mensaje": significa que un ADMIN leyó
           el mensaje del lead (lo confirma LeadBroadcastService::count_unread_for_admin(),
           que cuenta mensajes sender='lead' sin fila en lead_message_reads). Para "el lead
           lo leyó" la columna es `whatsapp_seen_at`. */
        'read_at',
        'whatsapp_message_id',
        'whatsapp_delivery_status',
        'whatsapp_delivered_at',
        'whatsapp_seen_at',
        'whatsapp_send_error',
        'sent_by_admin_id',
        /* Origen del mensaje: 'claude' lo marca el endpoint de envío; null = origen no
           marcado (todo lo anterior a la migración de sent_via). */
        'sent_via',
        'kind',
    ];

    /**
     * Proyección flaca del lead embebido en `claude/messages`. Sin PII salvo `include=contacto`.
     *
     * @var array<int, string>
     */
    const LEAD_EMBEBIDO_COLUMNS = ['id', 'contact_name', 'company_name', 'status'];

    /**
     * Expresión SQL del instante efectivo de un mensaje: `sent_at` si está (viene del
     * webhook de WhatsApp), si no `created_at`. Es el mismo criterio que usa
     * `LeadMessage::booted()` para mover `last_message_at`, así que los filtros
     * temporales de mensajes coinciden con lo que muestra la bandeja.
     *
     * @param  string $alias Alias o nombre de tabla de lead_messages en la consulta.
     * @return string
     */
    public static function message_time_sql(string $alias = 'lead_messages'): string
    {
        return 'COALESCE(' . $alias . '.sent_at, ' . $alias . '.created_at)';
    }

    /**
     * Predicado SQL de "mensaje saliente que EFECTIVAMENTE salió al lead".
     *
     * Las tres trampas del dato que evita, y por qué importan:
     *  - `status` en 'sugerido' o 'rechazado' → el mensaje nunca salió; contarlo como
     *    contacto infla el denominador de cualquier tasa de respuesta.
     *  - `is_status_event = 1` → evento interno, no actividad real del hilo (el propio
     *    `LeadMessage::booted()` los excluye de `last_message_at`).
     *  - `whatsapp_message_id IS NULL` → Kapso nunca confirmó el envío. Sin este filtro la
     *    tasa de respuesta cae durante esa ventana como si los leads hubieran dejado de
     *    contestar, cuando lo que pasó es que no les llegó nada.
     *    🔴 Este null NO identifica una causa, solo dice que el envío no se confirmó. Hasta el
     *    27/8/2026 acá decía que era "la firma del problema de pago de Meta": era falso y
     *    desvió el diagnóstico durante seis semanas. La causa real de los 2.933 seguimientos
     *    caídos entre julio y agosto de 2026 fue una variable de plantilla vacía (error 131008
     *    de Meta), que a su vez venía de que el nombre del lead se descartaba al entrar por el
     *    webhook. Ante un pico de nulls, medir antes de atribuirlo a algo.
     *
     * @param  string $alias Alias o nombre de tabla de lead_messages.
     * @return string
     */
    public static function saliente_salido_sql(string $alias = 'lead_messages'): string
    {
        return '(' . $alias . ".sender IN ('setter','sistema')"
            . ' AND ' . $alias . ".status = 'enviado'"
            . ' AND ' . $alias . '.is_status_event = 0'
            . ' AND ' . $alias . '.whatsapp_message_id IS NOT NULL)';
    }

    /**
     * Igual que saliente_salido_sql() pero exigiendo además confirmación de ENTREGA de
     * Meta (`whatsapp_delivery_status` en 'entregado' o 'leido').
     *
     * 🔴 La diferencia entre la tasa calculada con este predicado y la calculada con
     * saliente_salido_sql() ES el daño del problema de Meta, aislado y cuantificado:
     * mensajes que Kapso aceptó pero que Meta nunca entregó.
     *
     * @param  string $alias
     * @return string
     */
    public static function saliente_entregado_sql(string $alias = 'lead_messages'): string
    {
        return '(' . static::saliente_salido_sql($alias)
            . ' AND ' . $alias . ".whatsapp_delivery_status IN ('entregado','leido'))";
    }

    /**
     * Recorta el `limit` pedido al rango permitido. Nunca tira error: un valor fuera de
     * rango se clampea, porque una página más chica de lo pedido no rompe nada.
     *
     * @param  mixed $raw
     * @return int
     */
    public static function resolve_limit($raw): int
    {
        if ($raw === null || $raw === '') {
            return static::LIMIT_DEFAULT;
        }

        $limit = (int) $raw;
        if ($limit < 1) {
            return 1;
        }
        if ($limit > static::LIMIT_MAX) {
            return static::LIMIT_MAX;
        }

        return $limit;
    }

    /**
     * Normaliza un parámetro que puede llegar como array (`status[]=a&status[]=b`) o
     * como string separado por comas (`status=a,b`). Devuelve siempre un array de
     * strings no vacíos y sin repetidos.
     *
     * @param  mixed $value
     * @return array<int, string>
     */
    public static function normalize_list($value): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            $value = explode(',', (string) $value);
        }

        $result = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                continue;
            }
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }
            $result[] = $item;
        }

        return array_values(array_unique($result));
    }

    /**
     * Igual que normalize_list() pero devolviendo enteros positivos.
     *
     * @param  mixed $value
     * @return array<int, int>
     */
    public static function normalize_int_list($value): array
    {
        $result = [];
        foreach (static::normalize_list($value) as $item) {
            if (! is_numeric($item)) {
                continue;
            }
            $int = (int) $item;
            if ($int > 0) {
                $result[] = $int;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * Normaliza un borde de rango temporal. Una fecha sin hora ("2026-08-01") se expande
     * al principio o al final del día según corresponda, para que `to=2026-08-01` incluya
     * todo ese día en vez de cortar a la medianoche.
     *
     * @param  mixed $value
     * @param  bool  $is_end True si es el borde superior del rango.
     * @return string|null
     */
    public static function normalize_date_boundary($value, bool $is_end): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $is_end ? ($value . ' 23:59:59') : ($value . ' 00:00:00');
        }

        return $value;
    }

    /**
     * Escapa los comodines de LIKE (`%`, `_`) de un término de búsqueda libre, para que
     * un `q` con guión bajo (típico de un slug) no matchee cualquier caracter.
     *
     * @param  string $term
     * @return string
     */
    public static function escape_like(string $term): string
    {
        return addcslashes($term, '%_\\');
    }

    /**
     * Lista final de columnas de `leads` a proyectar según los `include` pedidos.
     * `conteos` no agrega columnas: se resuelve aparte con conteos_por_lead().
     *
     * @param  array<int, string> $includes
     * @return array<int, string>
     */
    public static function lead_columns(array $includes): array
    {
        $columns = static::LEAD_COLUMNS_BASE;

        if (in_array('contacto', $includes, true)) {
            $columns = array_merge($columns, static::LEAD_COLUMNS_CONTACTO);
        }
        if (in_array('demo', $includes, true)) {
            $columns = array_merge($columns, static::LEAD_COLUMNS_DEMO);
        }
        if (in_array('contrato', $includes, true)) {
            $columns = array_merge($columns, static::LEAD_COLUMNS_CONTRATO);
        }

        return array_values(array_unique($columns));
    }

    /**
     * Consulta base de leads con todos los filtros del lenguaje aplicados. Sin select,
     * sin orden y sin límite: eso lo pone el controlador.
     *
     * @param  array<string, mixed> $filtros Ver descriptor_filtros_leads() para las claves.
     * @return \Illuminate\Database\Query\Builder
     */
    public static function leads_query(array $filtros)
    {
        $query = DB::table('leads');

        $status = isset($filtros['status']) ? $filtros['status'] : [];
        if (! empty($status)) {
            $query->whereIn('leads.status', $status);
        }

        $lead_ids = isset($filtros['lead_ids']) ? $filtros['lead_ids'] : [];
        if (! empty($lead_ids)) {
            $query->whereIn('leads.id', $lead_ids);
        }

        /* Rangos temporales sobre timestamps: los bordes ya vienen expandidos al día
           entero desde el controlador (ver normalize_date_boundary). */
        static::apply_range($query, 'leads.created_at', $filtros, 'created_from', 'created_to');
        static::apply_range($query, 'leads.first_message_at', $filtros, 'first_message_from', 'first_message_to');
        static::apply_range($query, 'leads.last_message_at', $filtros, 'last_message_from', 'last_message_to');

        /* `demo_date` es una columna DATE: se compara tal cual, sin expandir a hora. */
        if (! empty($filtros['demo_date_from'])) {
            $query->where('leads.demo_date', '>=', $filtros['demo_date_from']);
        }
        if (! empty($filtros['demo_date_to'])) {
            $query->where('leads.demo_date', '<=', $filtros['demo_date_to']);
        }

        $has_phone = isset($filtros['has_phone']) ? $filtros['has_phone'] : null;
        if ($has_phone === true) {
            $query->whereNotNull('leads.phone')->where('leads.phone', '!=', '');
        } elseif ($has_phone === false) {
            $query->where(function ($q) {
                $q->whereNull('leads.phone')->orWhere('leads.phone', '=', '');
            });
        }

        $promoted = isset($filtros['promoted']) ? $filtros['promoted'] : null;
        if ($promoted === true) {
            $query->whereNotNull('leads.promoted_client_id');
        } elseif ($promoted === false) {
            $query->whereNull('leads.promoted_client_id');
        }

        $requiere_seguimiento = isset($filtros['requiere_seguimiento']) ? $filtros['requiere_seguimiento'] : null;
        if ($requiere_seguimiento !== null) {
            $query->where('leads.requiere_seguimiento', $requiere_seguimiento ? 1 : 0);
        }

        $q_term = isset($filtros['q']) ? trim((string) $filtros['q']) : '';
        if ($q_term !== '') {
            $like = '%' . static::escape_like($q_term) . '%';
            $query->where(function ($sub) use ($like) {
                $sub->where('leads.contact_name', 'LIKE', $like)
                    ->orWhere('leads.company_name', 'LIKE', $like)
                    ->orWhere('leads.email', 'LIKE', $like)
                    ->orWhere('leads.phone', 'LIKE', $like);
            });
        }

        return $query;
    }

    /**
     * Consulta base de mensajes con los filtros del lenguaje aplicados. Sin select, sin
     * orden y sin límite.
     *
     * 🔴 `include_status_events` viene en false por defecto: los `is_status_event=1` son
     * ruido interno (cambios de estado, bloques rojos de error) y no actividad del hilo.
     *
     * @param  array<string, mixed> $filtros
     * @return \Illuminate\Database\Query\Builder
     */
    public static function messages_query(array $filtros)
    {
        $query = DB::table('lead_messages');

        $lead_ids = isset($filtros['lead_ids']) ? $filtros['lead_ids'] : [];
        if (! empty($lead_ids)) {
            $query->whereIn('lead_messages.lead_id', $lead_ids);
        }

        if (! empty($filtros['sender'])) {
            $query->where('lead_messages.sender', $filtros['sender']);
        }

        if (! empty($filtros['status'])) {
            $query->where('lead_messages.status', $filtros['status']);
        }

        $is_followup = isset($filtros['is_followup']) ? $filtros['is_followup'] : null;
        if ($is_followup !== null) {
            $query->where('lead_messages.is_followup', $is_followup ? 1 : 0);
        }

        if (! empty($filtros['followup_template_id'])) {
            $query->where('lead_messages.followup_template_id', (int) $filtros['followup_template_id']);
        }

        /*
         * 🔴 `has_followup_template` existe por un motivo puntual y no por simetría: la columna
         * `whatsapp_send_error` se agregó el 13/7/2026 (migración 2026_07_13_170000), así que
         * TODO seguimiento que falló antes de esa fecha la tiene en null y queda afuera de un
         * filtro que dependa de `has_send_error=1`. Ese filtro devolvería menos filas sin avisar,
         * y parecería un dato.
         *
         * Con `is_followup=1 + delivery=no_confirmado + has_followup_template=1` la consulta
         * identifica un envío por plantilla que no salió SIN depender del texto del error, así
         * que también alcanza a los caídos anteriores al 13/7/2026. De paso deja afuera las
         * notificaciones al closer, que son is_followup=1 sin followup_template_id.
         */
        $has_followup_template = isset($filtros['has_followup_template']) ? $filtros['has_followup_template'] : null;
        if ($has_followup_template === true) {
            $query->whereNotNull('lead_messages.followup_template_id');
        } elseif ($has_followup_template === false) {
            $query->whereNull('lead_messages.followup_template_id');
        }

        $include_status_events = ! empty($filtros['include_status_events']);
        if (! $include_status_events) {
            $query->where('lead_messages.is_status_event', 0);
        }

        $has_send_error = isset($filtros['has_send_error']) ? $filtros['has_send_error'] : null;
        if ($has_send_error === true) {
            $query->whereNotNull('lead_messages.whatsapp_send_error')
                ->where('lead_messages.whatsapp_send_error', '!=', '');
        } elseif ($has_send_error === false) {
            $query->where(function ($q) {
                $q->whereNull('lead_messages.whatsapp_send_error')
                    ->orWhere('lead_messages.whatsapp_send_error', '=', '');
            });
        }

        if (! empty($filtros['delivery'])) {
            static::apply_delivery_filter($query, (string) $filtros['delivery']);
        }

        /* Rango temporal sobre el instante efectivo del mensaje (sent_at o, si falta,
           created_at). Ninguna de las dos columnas tiene índice: con decenas de miles de
           filas el escaneo es de milisegundos y quedó anotado como hallazgo. */
        $time_sql = static::message_time_sql();
        if (! empty($filtros['from'])) {
            $query->whereRaw($time_sql . ' >= ?', [$filtros['from']]);
        }
        if (! empty($filtros['to'])) {
            $query->whereRaw($time_sql . ' <= ?', [$filtros['to']]);
        }

        return $query;
    }

    /**
     * Traduce el filtro `delivery` al estado real del dato. Es la traducción exacta de
     * cómo quedan las filas en la base, no una interpretación:
     *
     *  - `confirmado`    → Kapso devolvió id de mensaje (whatsapp_message_id IS NOT NULL).
     *  - `no_confirmado` → nunca hubo id: el envío no salió (firma del problema de Meta).
     *  - `entregado` / `leido` / `fallido` → lo que reportó el webhook de Meta en
     *    `whatsapp_delivery_status`.
     *
     * @param  \Illuminate\Database\Query\Builder $query
     * @param  string                             $delivery
     * @return void
     */
    public static function apply_delivery_filter($query, string $delivery): void
    {
        if ($delivery === 'confirmado') {
            $query->whereNotNull('lead_messages.whatsapp_message_id');

            return;
        }

        if ($delivery === 'no_confirmado') {
            $query->whereNull('lead_messages.whatsapp_message_id');

            return;
        }

        if (in_array($delivery, ['entregado', 'leido', 'fallido'], true)) {
            $query->where('lead_messages.whatsapp_delivery_status', $delivery);
        }
    }

    /**
     * Aplica el cursor por `id` a una consulta ya filtrada.
     *
     * Cursor y no OFFSET a propósito: es estable ante inserciones concurrentes (llegan
     * leads y mensajes mientras se barre) y no degrada con el desplazamiento.
     *
     * @param  \Illuminate\Database\Query\Builder $query
     * @param  string                             $table     Tabla/alias dueño de la columna id.
     * @param  int|null                           $after_id
     * @param  string                             $direction 'asc' | 'desc'
     * @return void
     */
    public static function apply_cursor($query, string $table, ?int $after_id, string $direction): void
    {
        if ($after_id === null) {
            return;
        }

        $query->where($table . '.id', $direction === 'desc' ? '<' : '>', $after_id);
    }

    /**
     * Conteos y extremos temporales por lead, resueltos con UNA SOLA consulta agregada
     * con GROUP BY lead_id. Nunca una consulta por lead.
     *
     * Excluye los `is_status_event=1`: son eventos internos, no mensajes del hilo. Los
     * seguimientos que no salieron SÍ quedan adentro (se guardan como mensaje normal con
     * `is_followup=1` y `whatsapp_message_id` null; el bloque rojo de error es otra fila
     * aparte, ésa sí `is_status_event`).
     *
     * @param  array<int, int> $lead_ids
     * @return array<int, array<string, mixed>> Mapa lead_id => conteos.
     */
    public static function conteos_por_lead(array $lead_ids): array
    {
        if (empty($lead_ids)) {
            return [];
        }

        $time_sql = static::message_time_sql();

        $rows = DB::table('lead_messages')
            ->whereIn('lead_messages.lead_id', $lead_ids)
            ->where('lead_messages.is_status_event', 0)
            ->groupBy('lead_messages.lead_id')
            ->selectRaw(
                'lead_messages.lead_id as lead_id, '
                . 'COUNT(*) as mensajes_total, '
                . "SUM(CASE WHEN lead_messages.sender = 'lead' THEN 1 ELSE 0 END) as entrantes, "
                /* 🔴 Saliente = setter O sistema, igual que saliente_salido_sql(), que es la base de
                   las tasas de respuesta. Antes esto contaba solo 'setter', así que un lead trabajado
                   enteramente por la IA aparecía con salientes=0 y primer_saliente_at=null —o sea
                   "nunca lo contactamos"— mientras claude/metrics lo contaba en el denominador de
                   "leads con al menos un saliente". Dos respuestas contradictorias sobre el mismo lead. */
                . "SUM(CASE WHEN lead_messages.sender IN ('setter','sistema') THEN 1 ELSE 0 END) as salientes, "
                . "SUM(CASE WHEN lead_messages.sender = 'setter' THEN 1 ELSE 0 END) as salientes_de_admin, "
                . "SUM(CASE WHEN lead_messages.sender = 'sistema' THEN 1 ELSE 0 END) as sistema, "
                /* 🔴 Seguimiento = envío REAL por plantilla al lead, con el mismo criterio que
                   ClaudeLeadMetricsService::seguimientos(). Sin status='enviado' y sin
                   followup_template_id se cuelan tres cosas que no son envíos caídos: sugerencias sin
                   aprobar (status='sugerido'), sugerencias rechazadas, y las notificaciones al closer
                   (is_followup=1 sin followup_template_id, donde el WhatsApp fue al closer y no al
                   lead). Si estos conteos y la serie de métricas usan criterios distintos, dan números
                   distintos para lo mismo — que es justo el número que hay que medir. */
                . "SUM(CASE WHEN lead_messages.is_followup = 1 AND lead_messages.status = 'enviado' AND lead_messages.followup_template_id IS NOT NULL THEN 1 ELSE 0 END) as seguimientos, "
                . "SUM(CASE WHEN lead_messages.is_followup = 1 AND lead_messages.status = 'enviado' AND lead_messages.followup_template_id IS NOT NULL AND lead_messages.whatsapp_message_id IS NOT NULL THEN 1 ELSE 0 END) as seguimientos_confirmados, "
                . "SUM(CASE WHEN lead_messages.is_followup = 1 AND lead_messages.status = 'enviado' AND lead_messages.followup_template_id IS NOT NULL AND lead_messages.whatsapp_message_id IS NULL THEN 1 ELSE 0 END) as seguimientos_no_confirmados, "
                . "SUM(CASE WHEN lead_messages.whatsapp_delivery_status = 'fallido' THEN 1 ELSE 0 END) as entregas_fallidas, "
                . "MIN(CASE WHEN lead_messages.sender = 'lead' THEN " . $time_sql . ' END) as primer_entrante_at, '
                . "MAX(CASE WHEN lead_messages.sender = 'lead' THEN " . $time_sql . ' END) as ultimo_entrante_at, '
                . "MIN(CASE WHEN lead_messages.sender IN ('setter','sistema') THEN " . $time_sql . ' END) as primer_saliente_at, '
                . "MAX(CASE WHEN lead_messages.sender IN ('setter','sistema') THEN " . $time_sql . ' END) as ultimo_saliente_at'
            )
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->lead_id] = [
                'mensajes_total'              => (int) $row->mensajes_total,
                'entrantes'                   => (int) $row->entrantes,
                'salientes'                   => (int) $row->salientes,
                'salientes_de_admin'          => (int) $row->salientes_de_admin,
                'sistema'                     => (int) $row->sistema,
                'seguimientos'                => (int) $row->seguimientos,
                'seguimientos_confirmados'    => (int) $row->seguimientos_confirmados,
                'seguimientos_no_confirmados' => (int) $row->seguimientos_no_confirmados,
                'entregas_fallidas'           => (int) $row->entregas_fallidas,
                'primer_entrante_at'          => $row->primer_entrante_at,
                'ultimo_entrante_at'          => $row->ultimo_entrante_at,
                'primer_saliente_at'          => $row->primer_saliente_at,
                'ultimo_saliente_at'          => $row->ultimo_saliente_at,
            ];
        }

        return $map;
    }

    /**
     * Conteos en cero, para los leads de la página que no tienen ningún mensaje. Así el
     * contrato de la respuesta es homogéneo y Claude no tiene que distinguir "sin datos"
     * de "cero mensajes".
     *
     * @return array<string, mixed>
     */
    public static function conteos_vacios(): array
    {
        return [
            'mensajes_total'              => 0,
            'entrantes'                   => 0,
            'salientes'                   => 0,
            'salientes_de_admin'          => 0,
            'sistema'                     => 0,
            'seguimientos'                => 0,
            'seguimientos_confirmados'    => 0,
            'seguimientos_no_confirmados' => 0,
            'entregas_fallidas'           => 0,
            'primer_entrante_at'          => null,
            'ultimo_entrante_at'          => null,
            'primer_saliente_at'          => null,
            'ultimo_saliente_at'          => null,
        ];
    }

    /**
     * Expresión SQL que agrupa una columna temporal en períodos. El resultado es siempre
     * una fecha en formato `YYYY-MM-DD` (para `week`, el lunes de esa semana; para
     * `month`, el día 1), así que ordena y se lee sin ambigüedad.
     *
     * 🔴 La granularidad viene de una lista blanca cerrada, nunca del input crudo: la
     * expresión se interpola en el SQL y no puede depender de lo que mande el cliente.
     *
     * @param  string $column      Columna o expresión SQL ya segura.
     * @param  string $granularity day | week | month
     * @return string
     */
    public static function period_expression(string $column, string $granularity): string
    {
        if ($granularity === 'month') {
            return "DATE_FORMAT(" . $column . ", '%Y-%m-01')";
        }

        if ($granularity === 'week') {
            /* Lunes de la semana del valor (WEEKDAY() devuelve 0 para lunes). */
            return "DATE_FORMAT(DATE_SUB(" . $column . ", INTERVAL WEEKDAY(" . $column . ") DAY), '%Y-%m-%d')";
        }

        return "DATE_FORMAT(" . $column . ", '%Y-%m-%d')";
    }

    /**
     * Recorta un valor al rango permitido de caracteres de agrupación de errores.
     *
     * @param  mixed $raw
     * @return int
     */
    public static function resolve_error_group_chars($raw): int
    {
        if ($raw === null || $raw === '') {
            return static::ERROR_GROUP_CHARS_DEFAULT;
        }

        $chars = (int) $raw;
        if ($chars < static::ERROR_GROUP_CHARS_MIN) {
            return static::ERROR_GROUP_CHARS_MIN;
        }
        if ($chars > static::ERROR_GROUP_CHARS_MAX) {
            return static::ERROR_GROUP_CHARS_MAX;
        }

        return $chars;
    }

    /**
     * Descriptor legible de los filtros de `claude/leads`, para `claude/schema`. Vive acá
     * y no en el controlador para que el lenguaje de filtros y su documentación no se
     * desincronicen: se cambia una cosa sola.
     *
     * @return array<string, string>
     */
    public static function descriptor_filtros_leads(): array
    {
        return [
            'status'               => 'array o lista separada por comas de slugs del pipeline. Ver pipeline_statuses.',
            'lead_ids'             => 'array o lista separada por comas de ids de lead.',
            'created_from'         => 'fecha o fecha-hora. Una fecha sola arranca a las 00:00:00 de ese día.',
            'created_to'           => 'fecha o fecha-hora. Una fecha sola incluye hasta las 23:59:59 de ese día.',
            'first_message_from'   => 'ídem sobre leads.first_message_at.',
            'first_message_to'     => 'ídem sobre leads.first_message_at.',
            'last_message_from'    => 'ídem sobre leads.last_message_at.',
            'last_message_to'      => 'ídem sobre leads.last_message_at.',
            'demo_date_from'       => 'fecha (columna DATE, se compara tal cual).',
            'demo_date_to'         => 'fecha (columna DATE, se compara tal cual).',
            'has_phone'            => 'booleano. true = phone no nulo y no vacío.',
            'promoted'             => 'booleano. true = promoted_client_id no nulo (el lead ya es cliente).',
            'requiere_seguimiento' => 'booleano.',
            'q'                    => 'texto libre: busca en contact_name, company_name, email y phone.',
            'after_id'             => 'cursor: devuelve los leads con id posterior (anterior si order=desc). Solo válido con order_by=id.',
            'limit'                => 'filas por página. Default ' . self::LIMIT_DEFAULT . ', máximo ' . self::LIMIT_MAX . ' (se recorta, no da error).',
            'order_by'             => implode(' | ', self::LEADS_ORDER_BY) . '. Default id.',
            'order'                => 'asc | desc. Default asc.',
            'include'              => 'array o lista separada por comas: ' . implode(', ', self::LEADS_INCLUDES) . '.',
        ];
    }

    /**
     * Descriptor legible de los filtros de mensajes (`claude/messages` y
     * `claude/leads/{id}/messages`), para `claude/schema`.
     *
     * @return array<string, string>
     */
    public static function descriptor_filtros_mensajes(): array
    {
        return [
            'lead_ids'              => 'array o lista separada por comas de ids de lead. Solo en claude/messages.',
            'sender'                => implode(' | ', self::SENDERS) . '.',
            'status'                => implode(' | ', self::MESSAGE_STATUSES) . '. Ojo: sugerido y rechazado NUNCA salieron al lead.',
            'is_followup'           => 'booleano. true = seguimiento automático por plantilla.',
            'followup_template_id'  => 'id de followup_templates.',
            'from'                  => 'fecha o fecha-hora sobre COALESCE(sent_at, created_at).',
            'to'                    => 'fecha o fecha-hora sobre COALESCE(sent_at, created_at).',
            'include_status_events' => 'booleano. Default FALSE: los is_status_event=1 son ruido interno (cambios de estado y bloques rojos de error).',
            'has_send_error'        => 'booleano. true = whatsapp_send_error cargado.',
            'delivery'              => implode(' | ', self::DELIVERY) . '. Ver el bloque delivery de este mismo schema.',
            'max_content_chars'     => 'entero. Trunca el cuerpo del mensaje y marca content_truncado. Default: sin truncar.',
            'count_only'            => 'booleano. Devuelve solo {count, leads_distintos}, ninguna fila. Solo en claude/messages.',
            'group_by'              => implode(' | ', self::MESSAGES_GROUP_BY) . '. Devuelve el desglose agregado en vez de las filas. Solo en claude/messages.',
            'error_group_chars'     => 'entero ' . self::ERROR_GROUP_CHARS_MIN . '..' . self::ERROR_GROUP_CHARS_MAX . '. Caracteres de whatsapp_send_error que definen un grupo con group_by=error. Default ' . self::ERROR_GROUP_CHARS_DEFAULT . '.',
            'after_id'              => 'cursor por id del mensaje.',
            'limit'                 => 'filas por página. Default ' . self::LIMIT_DEFAULT . ', máximo ' . self::LIMIT_MAX . '.',
            'order'                 => 'asc | desc. Default asc (cronológico).',
            'include'               => 'contacto: agrega phone al lead embebido. Solo en claude/messages.',
        ];
    }

    /**
     * Aplica un rango [desde, hasta] sobre una columna, tomando los bordes del array de
     * filtros (que ya vienen normalizados por el controlador).
     *
     * @param  \Illuminate\Database\Query\Builder $query
     * @param  string                             $column
     * @param  array<string, mixed>               $filtros
     * @param  string                             $from_key
     * @param  string                             $to_key
     * @return void
     */
    protected static function apply_range($query, string $column, array $filtros, string $from_key, string $to_key): void
    {
        if (! empty($filtros[$from_key])) {
            $query->where($column, '>=', $filtros[$from_key]);
        }
        if (! empty($filtros[$to_key])) {
            $query->where($column, '<=', $filtros[$to_key]);
        }
    }
}
