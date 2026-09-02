<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ClaudeLeadsOutboundController;
use App\Http\Controllers\Api\ClaudeLeadsPipelineController;
use App\Http\Controllers\Controller;
use App\Models\FollowupTemplate;
use App\Models\LeadPipelineStatus;
use App\Services\ClaudeLeadMetricsService;
use App\Services\ClaudeLeadQueryService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Endpoints de LECTURA de leads, mensajes y métricas para Claude (misión
 * "endpoints de análisis y envío de leads", 24/8/2026).
 *
 * Protegidos por el middleware `claude.task.key` (clave fija en X-Claude-Task-Key), el
 * mismo bloque que ya usan ClaudeTaskIngestController y ClaudeVersionItemsIngestController.
 * No hay Sanctum: quien llama es un proceso externo sin sesión de admin.
 *
 * 🔴 EL PROBLEMA DE VOLUMEN NO ES LA API, ES LA VENTANA DE CONTEXTO. ~500 leads × ~50
 * mensajes son ~25.000 filas: aunque la API las sirva perfecto, no entran en un prompt.
 * De ahí las tres decisiones que gobiernan todo este controlador:
 *   1. Las métricas se calculan en SQL agregado y NUNCA devuelven filas crudas.
 *   2. Los mensajes se leen con query builder y `select` explícito, nunca con el modelo
 *      LeadMessage (sus `$appends` tocan `$this->lead` por mensaje: N+1 garantizado).
 *   3. La proyección por defecto es flaca; PII (`phone`, `email`) y datos de contrato
 *      solo viajan con un `include` explícito.
 *
 * 🔴 Páginas GRANDES a propósito (default 200, tope 500): `RouteServiceProvider` deja el
 * grupo `api` en 60 req/min y, sin usuario Sanctum, el limitador agrupa por IP. Un barrido
 * completo tiene que ser pocas llamadas grandes, no cientos de llamadas chicas.
 */
class ClaudeLeadsAnalyticsController extends Controller
{
    /**
     * Auto-descripción de la superficie de lectura: filtros válidos, estados del pipeline,
     * valores de `sender` y `delivery`, `include` disponibles y las trampas del dato.
     * Existe para que Claude no tenga que adivinar ningún valor.
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function schema_json(Request $request)
    {
        /* Catálogo de estados leído de la base (LeadPipelineStatus), no hardcodeado.
           🔴 UNA sola consulta: LeadPipelineStatus::label_for() hace un SELECT por llamada, y
           dentro de este foreach eran ~16 consultas para un catálogo de 15 filas. */
        $labels = [];
        foreach (LeadPipelineStatus::query()->get(['slug', 'label']) as $fila) {
            $labels[(string) $fila->slug] = (string) $fila->label;
        }

        $pipeline_statuses = [];
        foreach (LeadPipelineStatus::all_slugs() as $slug) {
            $pipeline_statuses[] = [
                'slug'  => $slug,
                'label' => isset($labels[$slug]) ? $labels[$slug] : LeadPipelineStatus::humanize_slug($slug),
            ];
        }

        return response()->json([
            'pipeline_statuses' => $pipeline_statuses,
            'senders'           => ClaudeLeadQueryService::SENDERS,
            'message_statuses'  => ClaudeLeadQueryService::MESSAGE_STATUSES,
            'delivery'          => [
                'confirmado'    => 'whatsapp_message_id IS NOT NULL — Kapso aceptó el envío.',
                'no_confirmado' => 'whatsapp_message_id IS NULL — Kapso nunca confirmó el envío: el mensaje NO salió. '
                    . 'Es un envío no confirmado y nada más; NO asumas una causa a partir de este campo. '
                    . 'La causa medida para el pico de julio-agosto de 2026 (2.933 seguimientos sobre 159 leads) fue una '
                    . 'variable de plantilla vacía: {{1}} viajaba como string vacío y Meta lo rechazó con el error 131008 '
                    . '(Required parameter is missing). NO fue un impago de Meta — ese diagnóstico estuvo escrito acá '
                    . 'hasta el 27/8/2026 y desvió el análisis durante semanas. Para el motivo real de cada fila mirá '
                    . 'lead_messages.whatsapp_send_error.',
                'entregado'     => "whatsapp_delivery_status = 'entregado'.",
                'leido'         => "whatsapp_delivery_status = 'leido'.",
                'fallido'       => "whatsapp_delivery_status = 'fallido'.",
            ],
            'leads' => [
                'filtros'                => ClaudeLeadQueryService::descriptor_filtros_leads(),
                'includes'               => ClaudeLeadQueryService::LEADS_INCLUDES,
                'order_by'               => ClaudeLeadQueryService::LEADS_ORDER_BY,
                'proyeccion_por_defecto' => ClaudeLeadQueryService::LEAD_COLUMNS_BASE,
                'includes_detalle'       => [
                    'contacto' => 'Agrega ' . implode(', ', ClaudeLeadQueryService::LEAD_COLUMNS_CONTACTO) . '. PII: opt-in explícito.',
                    'demo'     => 'Agrega ' . implode(', ', ClaudeLeadQueryService::LEAD_COLUMNS_DEMO) . '.',
                    'contrato' => 'Agrega los contract_*. Dato comercial sensible: opt-in explícito.',
                    'conteos'  => 'Agrega por lead los conteos de mensajes entrantes/salientes/seguimientos y los extremos '
                        . 'temporales de cada lado. Se resuelve con UNA sola consulta agregada para toda la página.',
                ],
            ],
            'messages' => [
                'filtros'  => ClaudeLeadQueryService::descriptor_filtros_mensajes(),
                'group_by' => ClaudeLeadQueryService::MESSAGES_GROUP_BY,
                'includes' => ClaudeLeadQueryService::MESSAGES_INCLUDES,
                'campos'   => ClaudeLeadQueryService::MESSAGE_COLUMNS,
                'caso_seguimientos_caidos' => 'GET claude/messages?is_followup=1&delivery=no_confirmado'
                    . '&has_followup_template=1&from=...&to=... devuelve los seguimientos por plantilla que no se '
                    . 'pudieron entregar. '
                    . '🔴 USAR has_followup_template=1, NO has_send_error=1: la columna whatsapp_send_error se agregó el '
                    . '13/7/2026, así que todo seguimiento que falló ANTES de esa fecha la tiene en null y un filtro por '
                    . 'has_send_error lo deja afuera en silencio — devuelve menos filas y parece un dato. '
                    . 'has_followup_template=1 no depende del texto del error, así que también alcanza a los caídos '
                    . 'viejos, y de paso excluye las notificaciones al closer (is_followup=1 sin followup_template_id). '
                    . 'Agregá has_send_error=1 solo si querés restringirte a los que SÍ tienen motivo registrado. '
                    . '🔴 El filtro captura TODO seguimiento que no salió: también números inválidos, plantilla despausada '
                    . 'y caídas de Kapso, no solo el impago de Meta. Agrupá con group_by=error ANTES de mandarle un mensaje '
                    . 'a alguien cuyo número simplemente está mal.',
            ],
            'metrics' => [
                'parametros' => [
                    'from'        => 'OBLIGATORIO. Fecha o fecha-hora.',
                    'to'          => 'OBLIGATORIO. Fecha o fecha-hora.',
                    'granularity' => implode(' | ', ClaudeLeadQueryService::GRANULARITIES) . '. Default day.',
                ],
                'definiciones_conversion' => [
                    'a_demo_agendada'  => 'Estado actual dentro de la lista de estados "llegó al menos a demo agendada".',
                    'a_demo_realizada' => 'Estado actual dentro de la lista de estados "llegó al menos a demo hecha".',
                    'a_cerrado_ganado' => "Estado actual = 'cerrado_ganado'.",
                    'a_cliente'        => 'promoted_client_id IS NOT NULL. La más dura y la más real.',
                ],
                'definiciones_respuesta' => [
                    'respondio_alguna_vez'           => 'Sobre leads con al menos un saliente que salió (Kapso confirmó).',
                    'respondio_alguna_vez_entregado' => 'Igual pero exigiendo entrega confirmada de Meta. 🔴 La diferencia '
                        . 'con la anterior es el daño del problema de Meta, aislado y cuantificado.',
                    'respondio_al_primer_contacto'   => 'De los leads cuyo primer mensaje del hilo es saliente.',
                    'respondio_a_seguimiento'        => 'De los leads con un seguimiento CONFIRMADO.',
                ],
                'nota' => 'from y to son obligatorios y el rango no puede pasar de ' . ClaudeLeadMetricsService::MAX_DIAS
                    . ' días: ni leads.created_at ni las columnas temporales de lead_messages tienen índice, así que una '
                    . 'métrica sin rango acotado hace full scan.',
            ],
            'templates' => [
                'filtros' => ['activa' => 'booleano. Opcional.'],
                'nota'    => 'Sin paginación (son pocas filas). El campo `variables` dice qué significa cada {{n}} de cada '
                    . 'plantilla, así no hay que adivinar al enviar.',
            ],
            /*
             * Los dos endpoints de ESCRITURA se describen acá aunque vivan en el otro controlador.
             * Sin esto, el protocolo de envío —que dry_run viene en true, que hace falta repetir la
             * llamada con confirm_count exacto, qué campos acepta variables_desde_lead— había que
             * leerlo del código fuente, y el sentido de este endpoint es no tener que adivinar nada.
             */
            'envio' => [
                'un_lead' => [
                    'ruta'       => 'POST claude/leads/{id}/send-template',
                    'body'       => [
                        'template_name'        => 'OBLIGATORIO. Nombre de la plantilla aprobada en Meta (ver claude/templates).',
                        'content'              => 'OBLIGATORIO. El texto YA renderizado, que es lo que se guarda como cuerpo '
                            . 'del mensaje en la conversación. Lo que viaja a Meta son las `variables`, no esto: los dos '
                            . 'tienen que quedar consistentes.',
                        'variables'            => 'Array POSICIONAL: el primero es {{1}}, el segundo {{2}}, etc.',
                        'language_code'        => 'Default ' . ClaudeLeadsOutboundController::IDIOMA_POR_DEFECTO . '.',
                        'followup_template_id' => 'Opcional, solo para trazabilidad.',
                        'context'              => 'Opcional. Texto del aviso a admins si el envío falla.',
                    ],
                    'nota'       => 'Envía de una: NO tiene dry_run. Sí respeta el cooldown de '
                        . ClaudeLeadsOutboundController::COOLDOWN_HORAS . ' hs (se saltea con ignorar_cooldown=true). '
                        . 'Para mandarle a varios leads usá el lote, que tiene simulación.',
                ],
                'lote' => [
                    'ruta' => 'POST claude/send-template-batch',
                    'body' => [
                        'lead_ids'             => 'OBLIGATORIO. Ids explícitos, máximo ' . ClaudeLeadsOutboundController::MAX_BATCH
                            . '. 🔴 No hay ningún filtro de selección de este lado a propósito: hay que nombrar a cada '
                            . 'destinatario. Los ids se sacan de claude/messages con group_by=lead.',
                        'template_name'        => 'OBLIGATORIO.',
                        'content_template'     => 'OBLIGATORIO. Texto con {{1}}, {{2}}... que se renderiza por lead.',
                        'variables_desde_lead' => 'Lista de campos del lead a usar como variables, en orden. Permitidos: '
                            . implode(', ', ClaudeLeadsOutboundController::CAMPOS_DE_LEAD_PERMITIDOS)
                            . '. 🔴 phone y email NO están y no se pueden usar.',
                        'variables_por_lead'   => 'Alternativa: mapa lead_id => array de variables. Gana sobre variables_desde_lead.',
                        'dry_run'              => 'Default TRUE. Simula sin enviar nada ni crear ningún mensaje.',
                        'confirm_count'        => 'OBLIGATORIO cuando dry_run=false. Tiene que coincidir EXACTO con el '
                            . '`enviarian` que devolvió la simulación; si no, 422 y cero envíos.',
                        'include_closed'       => 'Default false. Los leads en estado cerrado se omiten salvo que lo pongas.',
                    ],
                    'protocolo' => 'Son SIEMPRE dos llamadas. Primero sin dry_run (simula y devuelve `enviarian` + los '
                        . 'destinatarios con el texto ya renderizado y el teléfono enmascarado). Revisás esa lista. Después '
                        . 'la MISMA llamada con dry_run=false y confirm_count=<el enviarian que te devolvió>.',
                    'nota' => 'Cooldown de ' . ClaudeLeadsOutboundController::COOLDOWN_HORAS . ' hs por lead (un lead que ya '
                        . 'recibió un mensaje de Claude se omite): es lo que hace seguro reintentar un lote cortado. '
                        . 'El request se corta limpio a los ' . ClaudeLeadsOutboundController::PRESUPUESTO_SEGUNDOS
                        . ' s y devuelve `no_procesados` con los que no se intentaron; esos se reintentan sin riesgo.',
                ],
            ],
            'estado' => [
                'un_lead' => [
                    'ruta' => 'POST claude/leads/{id}/status',
                    'body' => [
                        'status'           => 'OBLIGATORIO. Slug destino, de pipeline_statuses.',
                        'motivo'           => 'Opcional. Texto que queda en el evento de la conversación.',
                        'registrar_evento' => 'Default TRUE. Deja un mensaje is_status_event con el cambio.',
                    ],
                    'nota' => 'Cambia de una: NO tiene dry_run. Si el lead ya estaba en ese estado devuelve '
                        . 'cambio=false y no escribe nada.',
                ],
                'lote' => [
                    'ruta' => 'POST claude/leads/status-batch',
                    'body' => [
                        'cambios'          => 'OBLIGATORIO. LISTA de {lead_id, status, motivo}, máximo '
                            . ClaudeLeadsPipelineController::MAX_BATCH . '. 🔴 Lista, no mapa: un mapa con claves '
                            . 'numéricas correlativas se decodifica como lista y las claves se corren.',
                        'dry_run'          => 'Default TRUE. Simula sin escribir ningún lead.',
                        'confirm_count'    => 'OBLIGATORIO cuando dry_run=false. Tiene que coincidir EXACTO con el '
                            . '`cambiarian` de la simulación.',
                        'confirm_token'    => 'OBLIGATORIO cuando dry_run=false. El que devolvió la simulación: ata la '
                            . 'confirmación a los leads y a los destinos exactos que se revisaron.',
                        'registrar_evento' => 'Default TRUE.',
                    ],
                    'protocolo' => 'Son SIEMPRE dos llamadas, igual que el lote de envío: primero la simulación, se '
                        . 'revisa la lista, después la MISMA llamada con dry_run=false, confirm_count y confirm_token.',
                ],
                'frenos' => 'No se puede asignar "' . ClaudeLeadsPipelineController::SLUG_PROHIBIDO . '" ni mover un lead '
                    . 'que ya esté en ese estado o promovido a cliente: ese tramo cuelga de la promoción a Client. Un slug '
                    . 'que no existe en el catálogo aborta el lote entero (es error de armado, no un lead salteable).',
                'efectos' => 'Al pasar a ' . implode(' o ', ClaudeLeadsPipelineController::ESTADOS_TERMINALES)
                    . ' se apagan requiere_seguimiento, tiene_sugerencia_pendiente y tiene_seguimiento_sin_ver, y se limpia '
                    . 'pendiente_revision_at — lo mismo que hace el pase automático a En Pausa.',
            ],
            'limites' => [
                'limit_default' => ClaudeLeadQueryService::LIMIT_DEFAULT,
                'limit_max'     => ClaudeLeadQueryService::LIMIT_MAX,
                /* Se lee del controlador de envío, que es donde se aplica: una copia local
                   publicaba 200 mientras el endpoint rechazaba a partir de 51. */
                'batch_max'     => ClaudeLeadsOutboundController::MAX_BATCH,
                'metrics_max_dias' => ClaudeLeadMetricsService::MAX_DIAS,
            ],
            'notas' => [
                'paginacion'     => 'Cursor por id (after_id), no offset: es estable ante inserciones concurrentes y no '
                    . 'degrada con el desplazamiento. after_id solo es válido con order_by=id (el default).',
                'read_at'        => '🔴 read_at NO significa que el lead leyó el mensaje: significa que un ADMIN leyó el '
                    . 'mensaje del lead. Para "el lead lo leyó", la columna es whatsapp_seen_at.',
                'status_events'  => 'include_status_events viene en false por defecto en las dos consultas de mensajes: los '
                    . 'is_status_event=1 son ruido interno (cambios de estado y bloques rojos de error), no actividad del hilo.',
                'nunca_salieron' => "Un mensaje con status 'sugerido' o 'rechazado' NUNCA salió al lead. Un saliente con "
                    . 'whatsapp_message_id NULL tampoco llegó. Las dos cosas ensucian cualquier conteo de "contactados".',
                'sent_via'       => "lead_messages.sent_via = 'claude' marca los mensajes que envió Claude por estos "
                    . 'endpoints. null = origen no marcado (todo lo anterior a esa columna).',
                'pii'            => 'phone y email de un lead solo viajan con include=contacto; los contract_* solo con '
                    . 'include=contrato. Por defecto no viaja ninguno de los dos.',
            ],
        ], 200);
    }

    /**
     * Listado de leads filtrable y paginado por cursor, con proyección flaca y `include`
     * opcional.
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function leads_json(Request $request)
    {
        $invalido = $this->validar_o_422($request, [
            'created_from'         => 'nullable|date',
            'created_to'           => 'nullable|date',
            'first_message_from'   => 'nullable|date',
            'first_message_to'     => 'nullable|date',
            'last_message_from'    => 'nullable|date',
            'last_message_to'      => 'nullable|date',
            'demo_date_from'       => 'nullable|date',
            'demo_date_to'         => 'nullable|date',
            'has_phone'            => 'nullable|boolean',
            'promoted'             => 'nullable|boolean',
            'requiere_seguimiento' => 'nullable|boolean',
            'q'                    => 'nullable|string|max:200',
            'after_id'             => 'nullable|integer|min:1',
            'limit'                => 'nullable|integer',
            'order_by'             => 'nullable|string|in:' . implode(',', ClaudeLeadQueryService::LEADS_ORDER_BY),
            'order'                => 'nullable|string|in:asc,desc',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        /* `include` puede llegar repetido (include[]=a&include[]=b) o separado por comas. */
        $includes = $this->resolver_includes($request, ClaudeLeadQueryService::LEADS_INCLUDES);
        if ($includes instanceof \Illuminate\Http\JsonResponse) {
            return $includes;
        }

        /* Los slugs de estado se validan contra el catálogo real: un typo devuelve la
           lista de válidos en vez de un resultado vacío que parece un dato. */
        $status = ClaudeLeadQueryService::normalize_list($request->input('status'));
        $slugs_validos = LeadPipelineStatus::all_slugs();
        foreach ($status as $slug) {
            if (! in_array($slug, $slugs_validos, true)) {
                return $this->error_422('El estado "' . $slug . '" no existe en el pipeline.', [
                    'estados_validos' => $slugs_validos,
                ]);
            }
        }

        $order_by  = $this->texto_con_default($request, 'order_by', 'id');
        $direction = $this->texto_con_default($request, 'order', 'asc');
        $limit     = ClaudeLeadQueryService::resolve_limit($request->input('limit'));
        $after_id  = $this->entero_o_null($request->input('after_id'));

        /* 🔴 El cursor es por `id`. Con order_by=created_at o last_message_at, un after_id
           daría páginas mal cortadas sin que nada lo denuncie: se rechaza explícitamente
           en vez de devolver un barrido silenciosamente roto. */
        if ($after_id !== null && $order_by !== 'id') {
            return $this->error_422(
                'El cursor after_id solo funciona con order_by=id (el default). Con order_by=' . $order_by
                . ' la paginación por cursor no es estable: usá order_by=id para barrer, y este order_by solo para '
                . 'una única página de top-N.'
            );
        }

        $filtros = [
            'status'               => $status,
            'lead_ids'             => ClaudeLeadQueryService::normalize_int_list($request->input('lead_ids')),
            'created_from'         => ClaudeLeadQueryService::normalize_date_boundary($request->input('created_from'), false),
            'created_to'           => ClaudeLeadQueryService::normalize_date_boundary($request->input('created_to'), true),
            'first_message_from'   => ClaudeLeadQueryService::normalize_date_boundary($request->input('first_message_from'), false),
            'first_message_to'     => ClaudeLeadQueryService::normalize_date_boundary($request->input('first_message_to'), true),
            'last_message_from'    => ClaudeLeadQueryService::normalize_date_boundary($request->input('last_message_from'), false),
            'last_message_to'      => ClaudeLeadQueryService::normalize_date_boundary($request->input('last_message_to'), true),
            'demo_date_from'       => $this->texto_o_null($request->input('demo_date_from')),
            'demo_date_to'         => $this->texto_o_null($request->input('demo_date_to')),
            'has_phone'            => $this->booleano_o_null($request, 'has_phone'),
            'promoted'             => $this->booleano_o_null($request, 'promoted'),
            'requiere_seguimiento' => $this->booleano_o_null($request, 'requiere_seguimiento'),
            'q'                    => $this->texto_o_null($request->input('q')),
        ];

        $query = ClaudeLeadQueryService::leads_query($filtros);
        ClaudeLeadQueryService::apply_cursor($query, 'leads', $after_id, $direction);

        $query->orderBy('leads.' . $order_by, $direction);
        if ($order_by !== 'id') {
            /* Desempate estable dentro del mismo valor de la columna de orden. */
            $query->orderBy('leads.id', $direction);
        }

        $query->select(ClaudeLeadQueryService::lead_columns($includes));

        $pagina = $this->traer_pagina($query, $limit);
        $rows = $pagina['rows'];
        $has_more = $pagina['has_more'];

        $data = [];
        foreach ($rows as $row) {
            $data[] = $this->proyectar_lead($row);
        }

        /* `include=conteos`: UNA sola consulta agregada con GROUP BY lead_id sobre los ids
           de esta página, y después se pega en memoria. Nunca una consulta por lead. */
        if (in_array('conteos', $includes, true)) {
            $lead_ids = [];
            foreach ($data as $lead) {
                $lead_ids[] = (int) $lead['id'];
            }

            $conteos = ClaudeLeadQueryService::conteos_por_lead($lead_ids);
            $vacio = ClaudeLeadQueryService::conteos_vacios();

            foreach ($data as $indice => $lead) {
                $lead_id = (int) $lead['id'];
                $data[$indice]['conteos'] = isset($conteos[$lead_id]) ? $conteos[$lead_id] : $vacio;
            }
        }

        $count = count($data);
        $next_after_id = null;
        if ($has_more && $order_by === 'id' && $count > 0) {
            $next_after_id = (int) $data[$count - 1]['id'];
        }

        $respuesta = [
            'data'          => $data,
            'count'         => $count,
            'has_more'      => $has_more,
            'next_after_id' => $next_after_id,
        ];

        if ($has_more && $order_by !== 'id') {
            $respuesta['nota'] = 'Hay más filas pero no se devuelve cursor: after_id solo es estable con order_by=id. '
                . 'Para barrer todo, repetí la consulta con order_by=id.';
        }

        return response()->json($respuesta, 200);
    }

    /**
     * Conversación de un lead, paginada por cursor.
     *
     * 🔴 Query builder con select explícito, NO el modelo LeadMessage: sus `$appends`
     * (`suggested_lead_status_label`, `pending_actions_summary`, `sent_by_admin_name`)
     * pegan a la base por mensaje. Serializar un hilo largo con los appends puestos es
     * N+1 garantizado.
     *
     * @param  Request $request
     * @param  int|string $id Id del lead.
     * @return \Illuminate\Http\JsonResponse
     */
    public function lead_messages_json(Request $request, $id)
    {
        $lead_id = (int) $id;
        if ($lead_id <= 0) {
            return $this->error_422('El id del lead tiene que ser un entero positivo.');
        }

        $invalido = $this->validar_o_422($request, $this->reglas_de_mensajes());
        if ($invalido !== null) {
            return $invalido;
        }

        $includes = $this->resolver_includes($request, ClaudeLeadQueryService::MESSAGES_INCLUDES);
        if ($includes instanceof \Illuminate\Http\JsonResponse) {
            return $includes;
        }

        $lead_columns = ClaudeLeadQueryService::LEAD_EMBEBIDO_COLUMNS;
        if (in_array('contacto', $includes, true)) {
            $lead_columns[] = 'phone';
        }

        $lead = DB::table('leads')->where('id', $lead_id)->select($lead_columns)->first();
        if ($lead === null) {
            return response()->json(['error' => 'no existe el lead ' . $lead_id], 404);
        }

        $filtros = $this->armar_filtros_de_mensajes($request);
        $filtros['lead_ids'] = [$lead_id];

        $direction = $this->texto_con_default($request, 'order', 'asc');
        $limit     = ClaudeLeadQueryService::resolve_limit($request->input('limit'));
        $after_id  = $this->entero_o_null($request->input('after_id'));

        $query = ClaudeLeadQueryService::messages_query($filtros);
        ClaudeLeadQueryService::apply_cursor($query, 'lead_messages', $after_id, $direction);
        $query->orderBy('lead_messages.id', $direction);
        $query->select(ClaudeLeadQueryService::MESSAGE_COLUMNS);

        $pagina = $this->traer_pagina($query, $limit);
        $max_content_chars = $this->entero_o_null($request->input('max_content_chars'));

        $data = [];
        foreach ($pagina['rows'] as $row) {
            $data[] = $this->proyectar_mensaje($row, $max_content_chars);
        }

        $count = count($data);

        return response()->json([
            'lead'          => $this->proyectar_lead($lead),
            'data'          => $data,
            'count'         => $count,
            'has_more'      => $pagina['has_more'],
            'next_after_id' => ($pagina['has_more'] && $count > 0) ? (int) $data[$count - 1]['id'] : null,
        ], 200);
    }

    /**
     * Consulta de mensajes CRUZADA entre leads. Es la que resuelve el caso de los
     * seguimientos que no se pudieron entregar.
     *
     * 🔴 Hasta el 27/8/2026 este comentario decía «por el problema de pago de Meta». Era falso y
     * costó semanas de análisis en la dirección equivocada: la causa medida fue una variable de
     * plantilla vacía ({{1}} sin nombre del lead → Meta 131008), no un impago. Un
     * `whatsapp_message_id` null significa envío no confirmado y nada más.
     *
     * Tres modos, de menos a más pesado:
     *   - `count_only=true` → solo {count, leads_distintos}. Ninguna fila.
     *   - `group_by=lead|template|error|day` → el desglose agregado. Ninguna fila cruda.
     *   - sin ninguno de los dos → las filas, paginadas por cursor, con el lead embebido flaco.
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function messages_json(Request $request)
    {
        $reglas = array_merge($this->reglas_de_mensajes(), [
            'count_only'        => 'nullable|boolean',
            'group_by'          => 'nullable|string|in:' . implode(',', ClaudeLeadQueryService::MESSAGES_GROUP_BY),
            'error_group_chars' => 'nullable|integer',
        ]);

        $invalido = $this->validar_o_422($request, $reglas);
        if ($invalido !== null) {
            return $invalido;
        }

        $includes = $this->resolver_includes($request, ClaudeLeadQueryService::MESSAGES_INCLUDES);
        if ($includes instanceof \Illuminate\Http\JsonResponse) {
            return $includes;
        }

        $filtros = $this->armar_filtros_de_mensajes($request);
        $filtros['lead_ids'] = ClaudeLeadQueryService::normalize_int_list($request->input('lead_ids'));

        /* Modo 1: solo el conteo. Ni una fila viaja. */
        if ($this->booleano_o_null($request, 'count_only') === true) {
            $row = ClaudeLeadQueryService::messages_query($filtros)
                ->selectRaw('COUNT(*) as cantidad, COUNT(DISTINCT lead_messages.lead_id) as leads_distintos')
                ->first();

            $respuesta_conteo = [
                'count'           => $row !== null ? (int) $row->cantidad : 0,
                'leads_distintos' => $row !== null ? (int) $row->leads_distintos : 0,
            ];

            /* count_only le gana a group_by, pero decirlo en vez de ignorarlo en silencio: pedir los
               dos y recibir solo un total, sin ninguna señal, se lee como si el agrupado no tuviera
               resultados. */
            if ($this->texto_o_null($request->input('group_by')) !== null) {
                $respuesta_conteo['nota'] = 'Mandaste count_only y group_by juntos: count_only tiene prioridad y el '
                    . 'group_by se ignoró. Sacá count_only si lo que querés es el desglose.';
            }

            return response()->json($respuesta_conteo, 200);
        }

        /* Modo 2: desglose agregado. Tampoco viaja ninguna fila cruda. */
        $group_by = $this->texto_o_null($request->input('group_by'));
        if ($group_by !== null) {
            return $this->agrupar_mensajes($request, $filtros, $group_by);
        }

        /* Modo 3: las filas, paginadas por cursor. */
        $direction = $this->texto_con_default($request, 'order', 'asc');
        $limit     = ClaudeLeadQueryService::resolve_limit($request->input('limit'));
        $after_id  = $this->entero_o_null($request->input('after_id'));

        $query = ClaudeLeadQueryService::messages_query($filtros);
        ClaudeLeadQueryService::apply_cursor($query, 'lead_messages', $after_id, $direction);
        $query->orderBy('lead_messages.id', $direction);
        $query->select(ClaudeLeadQueryService::MESSAGE_COLUMNS);

        $pagina = $this->traer_pagina($query, $limit);
        $max_content_chars = $this->entero_o_null($request->input('max_content_chars'));

        $data = [];
        $lead_ids = [];
        foreach ($pagina['rows'] as $row) {
            $data[] = $this->proyectar_mensaje($row, $max_content_chars);
            $lead_ids[] = (int) $row->lead_id;
        }

        /* Lead embebido flaco: UNA sola consulta para toda la página, no una por mensaje. */
        $leads = $this->leads_embebidos(array_values(array_unique($lead_ids)), $includes);
        foreach ($data as $indice => $mensaje) {
            $lead_id = (int) $mensaje['lead_id'];
            $data[$indice]['lead'] = isset($leads[$lead_id]) ? $leads[$lead_id] : null;
        }

        $count = count($data);

        return response()->json([
            'data'          => $data,
            'count'         => $count,
            'has_more'      => $pagina['has_more'],
            'next_after_id' => ($pagina['has_more'] && $count > 0) ? (int) $data[$count - 1]['id'] : null,
        ], 200);
    }

    /**
     * Agregados de leads, embudo, tasas de respuesta y salud de seguimientos.
     * Todo se calcula en SQL: no devuelve ninguna fila cruda.
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function metrics_json(Request $request)
    {
        $invalido = $this->validar_o_422($request, [
            'from'        => 'required|date',
            'to'          => 'required|date',
            'granularity' => 'nullable|string|in:' . implode(',', ClaudeLeadQueryService::GRANULARITIES),
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $from = ClaudeLeadQueryService::normalize_date_boundary($request->input('from'), false);
        $to   = ClaudeLeadQueryService::normalize_date_boundary($request->input('to'), true);

        $from_carbon = $this->parsear_o_null($from);
        $to_carbon   = $this->parsear_o_null($to);
        if ($from_carbon === null || $to_carbon === null) {
            return $this->error_422('No pude interpretar from o to como fecha.');
        }

        if ($to_carbon->lessThan($from_carbon)) {
            return $this->error_422('El parámetro "to" es anterior a "from".');
        }

        /* 🔴 Tope de rango obligatorio: ni leads.created_at ni las columnas temporales de
           lead_messages tienen índice (medido el 24/8/2026). Una métrica sin rango acotado
           hace full scan de las dos tablas y puede trabar la base en horario laboral. */
        $dias = $from_carbon->diffInDays($to_carbon) + 1;
        if ($dias > ClaudeLeadMetricsService::MAX_DIAS) {
            return $this->error_422(
                'El rango pedido es de ' . $dias . ' días y el máximo es ' . ClaudeLeadMetricsService::MAX_DIAS . '. '
                . 'Ninguna de las columnas temporales que usan las métricas tiene índice: un rango más largo hace full '
                . 'scan. Partí la consulta en tramos.'
            );
        }

        $granularity = $this->texto_con_default($request, 'granularity', 'day');

        return response()->json(ClaudeLeadMetricsService::build($from, $to, $granularity), 200);
    }

    /**
     * Catálogo de plantillas Meta aprobadas. Sin paginación: son pocas filas.
     *
     * Acá SÍ se usa el modelo Eloquent tal cual: `FollowupTemplate::$appends` (`categoria`,
     * `categoria_label`, `categoria_orden`, `variables`) se calcula en memoria sin pegarle a
     * la base, así que no hay riesgo de N+1. `variables` es lo que dice qué significa cada
     * {{n}} de cada plantilla, así no hay que adivinar al enviar.
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function templates_json(Request $request)
    {
        $invalido = $this->validar_o_422($request, [
            'activa' => 'nullable|boolean',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $query = FollowupTemplate::query();

        $activa = $this->booleano_o_null($request, 'activa');
        if ($activa !== null) {
            $query->where('activa', $activa ? 1 : 0);
        }

        $templates = $query->orderBy('estado')->orderBy('dia_numero')->orderBy('id')->get();

        /* `categoria_orden` es un accessor: el orden final se resuelve en memoria (son
           pocas filas y ordenar en SQL exigiría duplicar la lógica del accessor). */
        $templates = $templates->sortBy(function ($template) {
            return sprintf('%03d', $template->categoria_orden) . '-' . sprintf('%04d', (int) $template->dia_numero) . '-' . $template->id;
        })->values();

        return response()->json([
            'data'  => $templates,
            'count' => $templates->count(),
        ], 200);
    }

    /**
     * Resuelve `group_by` para claude/messages. Devuelve el desglose agregado, nunca filas
     * crudas.
     *
     * @param  Request              $request
     * @param  array<string, mixed> $filtros
     * @param  string               $group_by lead | template | error | day
     * @return \Illuminate\Http\JsonResponse
     */
    protected function agrupar_mensajes(Request $request, array $filtros, string $group_by)
    {
        $limit = ClaudeLeadQueryService::resolve_limit($request->input('limit'));
        $query = ClaudeLeadQueryService::messages_query($filtros);

        /* Columnas de salud del envío, útiles en los cuatro agrupados. */
        $salud_sql = ', COUNT(*) as cantidad'
            . ', COUNT(DISTINCT lead_messages.lead_id) as leads_distintos'
            . ', SUM(CASE WHEN lead_messages.whatsapp_message_id IS NOT NULL THEN 1 ELSE 0 END) as confirmados'
            . ', SUM(CASE WHEN lead_messages.whatsapp_message_id IS NULL THEN 1 ELSE 0 END) as no_confirmados';

        $nota = null;
        $error_group_chars = null;

        if ($group_by === 'lead') {
            $query->groupBy('lead_messages.lead_id')
                ->orderByRaw('COUNT(*) DESC')
                ->selectRaw('lead_messages.lead_id as lead_id' . $salud_sql);
        } elseif ($group_by === 'template') {
            $query->groupBy('lead_messages.followup_template_id')
                ->orderByRaw('COUNT(*) DESC')
                ->selectRaw('lead_messages.followup_template_id as followup_template_id' . $salud_sql);
        } elseif ($group_by === 'error') {
            $error_group_chars = ClaudeLeadQueryService::resolve_error_group_chars($request->input('error_group_chars'));
            /* El prefijo del error ES el grupo: se devuelve el texto, no solo el conteo.
               Es lo único que separa el impago de Meta de un número inválido o una
               plantilla despausada ANTES de mandarle un mensaje a alguien. */
            $expresion = 'LEFT(lead_messages.whatsapp_send_error, ' . (int) $error_group_chars . ')';
            $query->groupByRaw($expresion)
                ->orderByRaw('COUNT(*) DESC')
                ->selectRaw($expresion . ' as error' . $salud_sql);
            $nota = 'Cada grupo es el prefijo de ' . $error_group_chars . ' caracteres de whatsapp_send_error. '
                . 'error=null son mensajes sin error registrado. 🔴 El filtro de seguimientos caídos captura TODO '
                . 'seguimiento que no salió: número inválido, plantilla despausada y caídas de Kapso además del impago '
                . 'de Meta. Leé el texto de cada grupo antes de reenviarle nada a nadie.';
        } else {
            $expresion = ClaudeLeadQueryService::period_expression(ClaudeLeadQueryService::message_time_sql(), 'day');
            $query->groupByRaw($expresion)
                ->orderByRaw($expresion)
                ->selectRaw($expresion . ' as periodo' . $salud_sql);
            $nota = 'El período agrupa COALESCE(sent_at, created_at) tal cual está guardado, sin convertir zona horaria. '
                . 'La app escribe en ' . config('app.timezone') . '.';
        }

        $rows = $query->limit($limit + 1)->get();
        $has_more = $rows->count() > $limit;
        if ($has_more) {
            $rows = $rows->slice(0, $limit)->values();
        }

        $data = [];
        foreach ($rows as $row) {
            $grupo = (array) $row;
            foreach (['cantidad', 'leads_distintos', 'confirmados', 'no_confirmados'] as $campo) {
                if (array_key_exists($campo, $grupo)) {
                    $grupo[$campo] = (int) $grupo[$campo];
                }
            }
            foreach (['lead_id', 'followup_template_id'] as $campo) {
                if (array_key_exists($campo, $grupo) && $grupo[$campo] !== null) {
                    $grupo[$campo] = (int) $grupo[$campo];
                }
            }
            $data[] = $grupo;
        }

        /* Nombre del lead o de la plantilla: UNA consulta para todos los grupos, no una
           por grupo. */
        if ($group_by === 'lead') {
            $ids = [];
            foreach ($data as $grupo) {
                if ($grupo['lead_id'] !== null) {
                    $ids[] = (int) $grupo['lead_id'];
                }
            }
            $leads = $this->leads_embebidos(array_values(array_unique($ids)), []);
            foreach ($data as $indice => $grupo) {
                $lead_id = $grupo['lead_id'];
                $data[$indice]['lead'] = ($lead_id !== null && isset($leads[$lead_id])) ? $leads[$lead_id] : null;
            }
        } elseif ($group_by === 'template') {
            $ids = [];
            foreach ($data as $grupo) {
                if ($grupo['followup_template_id'] !== null) {
                    $ids[] = (int) $grupo['followup_template_id'];
                }
            }
            $nombres = [];
            if (! empty($ids)) {
                $filas = DB::table('followup_templates')
                    ->whereIn('id', array_values(array_unique($ids)))
                    ->select(['id', 'template_name', 'estado', 'dia_numero'])
                    ->get();
                foreach ($filas as $fila) {
                    $nombres[(int) $fila->id] = [
                        'id'            => (int) $fila->id,
                        'template_name' => $fila->template_name,
                        'estado'        => $fila->estado,
                        'dia_numero'    => (int) $fila->dia_numero,
                    ];
                }
            }
            foreach ($data as $indice => $grupo) {
                $template_id = $grupo['followup_template_id'];
                $data[$indice]['template'] = ($template_id !== null && isset($nombres[$template_id])) ? $nombres[$template_id] : null;
            }
        }

        $respuesta = [
            'group_by' => $group_by,
            'data'     => $data,
            'count'    => count($data),
            'has_more' => $has_more,
        ];

        if ($error_group_chars !== null) {
            $respuesta['error_group_chars'] = $error_group_chars;
        }
        if ($nota !== null) {
            $respuesta['nota'] = $nota;
        }

        return response()->json($respuesta, 200);
    }

    /**
     * Reglas de validación comunes a las dos consultas de mensajes.
     *
     * @return array<string, string>
     */
    protected function reglas_de_mensajes(): array
    {
        return [
            'sender'                => 'nullable|string|in:' . implode(',', ClaudeLeadQueryService::SENDERS),
            'status'                => 'nullable|string|in:' . implode(',', ClaudeLeadQueryService::MESSAGE_STATUSES),
            'is_followup'           => 'nullable|boolean',
            'followup_template_id'  => 'nullable|integer|min:1',
            'from'                  => 'nullable|date',
            'to'                    => 'nullable|date',
            'include_status_events' => 'nullable|boolean',
            'has_send_error'        => 'nullable|boolean',
            'has_followup_template' => 'nullable|boolean',
            'delivery'              => 'nullable|string|in:' . implode(',', ClaudeLeadQueryService::DELIVERY),
            'max_content_chars'     => 'nullable|integer|min:1',
            'after_id'              => 'nullable|integer|min:1',
            'limit'                 => 'nullable|integer',
            'order'                 => 'nullable|string|in:asc,desc',
        ];
    }

    /**
     * Arma el array de filtros de mensajes a partir de la Request, ya normalizado
     * (null = ausente). `lead_ids` lo pone cada endpoint según corresponda.
     *
     * @param  Request $request
     * @return array<string, mixed>
     */
    protected function armar_filtros_de_mensajes(Request $request): array
    {
        return [
            'lead_ids'              => [],
            'sender'                => $this->texto_o_null($request->input('sender')),
            'status'                => $this->texto_o_null($request->input('status')),
            'is_followup'           => $this->booleano_o_null($request, 'is_followup'),
            'followup_template_id'  => $this->entero_o_null($request->input('followup_template_id')),
            /* Default FALSE: los is_status_event=1 son ruido interno. */
            'include_status_events' => $this->booleano_o_null($request, 'include_status_events') === true,
            'has_send_error'        => $this->booleano_o_null($request, 'has_send_error'),
            'has_followup_template' => $this->booleano_o_null($request, 'has_followup_template'),
            'delivery'              => $this->texto_o_null($request->input('delivery')),
            'from'                  => ClaudeLeadQueryService::normalize_date_boundary($request->input('from'), false),
            'to'                    => ClaudeLeadQueryService::normalize_date_boundary($request->input('to'), true),
        ];
    }

    /**
     * Trae los leads embebidos de una página de mensajes con UNA sola consulta.
     *
     * @param  array<int, int>    $lead_ids
     * @param  array<int, string> $includes
     * @return array<int, array<string, mixed>> Mapa lead_id => proyección flaca.
     */
    protected function leads_embebidos(array $lead_ids, array $includes): array
    {
        if (empty($lead_ids)) {
            return [];
        }

        $columns = ClaudeLeadQueryService::LEAD_EMBEBIDO_COLUMNS;
        if (in_array('contacto', $includes, true)) {
            $columns[] = 'phone';
        }

        $filas = DB::table('leads')->whereIn('id', $lead_ids)->select($columns)->get();

        $map = [];
        foreach ($filas as $fila) {
            $map[(int) $fila->id] = $this->proyectar_lead($fila);
        }

        return $map;
    }

    /**
     * Ejecuta la consulta pidiendo una fila de más para saber si hay página siguiente sin
     * necesidad de un COUNT aparte.
     *
     * @param  \Illuminate\Database\Query\Builder $query
     * @param  int                                $limit
     * @return array<string, mixed> {rows: Collection, has_more: bool}
     */
    protected function traer_pagina($query, int $limit): array
    {
        $rows = $query->limit($limit + 1)->get();
        $has_more = $rows->count() > $limit;

        if ($has_more) {
            $rows = $rows->slice(0, $limit)->values();
        }

        return ['rows' => $rows, 'has_more' => $has_more];
    }

    /**
     * Normaliza una fila cruda de `leads` a la proyección de la respuesta: castea los
     * tinyint a booleano, los ids a entero y decodifica las columnas JSON de contrato.
     *
     * @param  object $row
     * @return array<string, mixed>
     */
    protected function proyectar_lead($row): array
    {
        $lead = (array) $row;

        foreach (['requiere_seguimiento', 'demo_ingreso_confirmado', 'demo_terminada_confirmada'] as $campo) {
            if (array_key_exists($campo, $lead)) {
                $lead[$campo] = (bool) $lead[$campo];
            }
        }

        $enteros = [
            'id',
            'promoted_client_id',
            'contract_usuarios_incluidos',
            'contract_usuarios_extra',
            'contract_perfiles_ecommerce',
        ];
        foreach ($enteros as $campo) {
            if (array_key_exists($campo, $lead) && $lead[$campo] !== null) {
                $lead[$campo] = (int) $lead[$campo];
            }
        }

        /* El query builder devuelve las columnas JSON como string crudo (a diferencia de
           Eloquent, que las castea). Se decodifican acá para que la respuesta sea JSON de
           verdad y no un string con JSON adentro. */
        foreach (['contract_financiacion', 'contract_clausulas_particulares'] as $campo) {
            if (array_key_exists($campo, $lead) && is_string($lead[$campo]) && $lead[$campo] !== '') {
                $decoded = json_decode($lead[$campo], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $lead[$campo] = $decoded;
                }
            }
        }

        return $lead;
    }

    /**
     * Normaliza una fila cruda de `lead_messages` a la proyección de la respuesta y aplica
     * el truncado opcional del cuerpo.
     *
     * @param  object   $row
     * @param  int|null $max_content_chars
     * @return array<string, mixed>
     */
    protected function proyectar_mensaje($row, ?int $max_content_chars): array
    {
        $mensaje = (array) $row;

        foreach (['is_followup', 'is_status_event', 'is_error'] as $campo) {
            if (array_key_exists($campo, $mensaje)) {
                $mensaje[$campo] = (bool) $mensaje[$campo];
            }
        }

        foreach (['id', 'lead_id', 'followup_template_id', 'sent_by_admin_id'] as $campo) {
            if (array_key_exists($campo, $mensaje) && $mensaje[$campo] !== null) {
                $mensaje[$campo] = (int) $mensaje[$campo];
            }
        }

        if ($max_content_chars !== null && array_key_exists('content', $mensaje)) {
            $content = (string) $mensaje['content'];
            $largo = mb_strlen($content);

            if ($largo > $max_content_chars) {
                $mensaje['content'] = mb_substr($content, 0, $max_content_chars);
                $mensaje['content_truncado'] = true;
                $mensaje['content_chars_originales'] = $largo;
            } else {
                $mensaje['content_truncado'] = false;
            }
        }

        return $mensaje;
    }

    /**
     * Normaliza y valida el parámetro `include` contra la lista blanca del endpoint.
     * Acepta `include[]=a&include[]=b` o `include=a,b`.
     *
     * @param  Request            $request
     * @param  array<int, string> $permitidos
     * @return array<int, string>|\Illuminate\Http\JsonResponse
     */
    protected function resolver_includes(Request $request, array $permitidos)
    {
        $includes = ClaudeLeadQueryService::normalize_list($request->input('include'));

        foreach ($includes as $include) {
            if (! in_array($include, $permitidos, true)) {
                return $this->error_422('El include "' . $include . '" no existe en este endpoint.', [
                    'includes_validos' => $permitidos,
                ]);
            }
        }

        return $includes;
    }

    /**
     * Corre `$request->validate()` pero garantizando una respuesta JSON 422 aunque la
     * request no traiga `Accept: application/json`. Sin esto, una GET pelada de un script
     * recibiría un redirect 302 en vez del error, que es imposible de diagnosticar del
     * otro lado.
     *
     * @param  Request              $request
     * @param  array<string, string> $reglas
     * @return \Illuminate\Http\JsonResponse|null Null si validó bien.
     */
    protected function validar_o_422(Request $request, array $reglas)
    {
        try {
            $request->validate($reglas, $this->mensajes_de_validacion());
        } catch (ValidationException $e) {
            return response()->json([
                'error'   => 'parámetros inválidos',
                'errores' => $e->errors(),
                'ayuda'   => 'Consultá GET claude/schema para ver los filtros y valores válidos.',
            ], 422);
        }

        return null;
    }

    /**
     * Mensajes de validación en español. El proyecto solo tiene traducciones en inglés
     * (resources/lang/en), así que se pasan inline. Una clave con el nombre pelado de la
     * regla aplica a todos los campos que la usen.
     *
     * @return array<string, string>
     */
    protected function mensajes_de_validacion(): array
    {
        return [
            'required' => 'El parámetro :attribute es obligatorio.',
            'date'     => 'El parámetro :attribute tiene que ser una fecha (AAAA-MM-DD) o una fecha-hora válida.',
            'integer'  => 'El parámetro :attribute tiene que ser un número entero.',
            'boolean'  => 'El parámetro :attribute tiene que ser booleano (1, 0, true o false).',
            'string'   => 'El parámetro :attribute tiene que ser texto.',
            'in'       => 'El parámetro :attribute tiene un valor que no está permitido. Mirá GET claude/schema para ver los válidos.',
            'min'      => 'El parámetro :attribute está por debajo del mínimo permitido.',
            'max'      => 'El parámetro :attribute supera el máximo permitido.',
        ];
    }

    /**
     * Respuesta 422 con mensaje legible en español.
     *
     * @param  string              $mensaje
     * @param  array<string, mixed> $extra
     * @return \Illuminate\Http\JsonResponse
     */
    protected function error_422(string $mensaje, array $extra = [])
    {
        return response()->json(array_merge(['error' => $mensaje], $extra), 422);
    }

    /**
     * Lee un parámetro booleano distinguiendo "ausente" (null) de "false" explícito. Sin
     * esto, `$request->boolean()` devolvería false para un filtro que nunca se pidió y
     * el filtro se aplicaría igual.
     *
     * @param  Request $request
     * @param  string  $key
     * @return bool|null
     */
    protected function booleano_o_null(Request $request, string $key): ?bool
    {
        if (! $request->has($key)) {
            return null;
        }

        $valor = $request->input($key);
        if ($valor === null || $valor === '') {
            return null;
        }

        return filter_var($valor, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Entero o null si el parámetro vino vacío/ausente.
     *
     * @param  mixed $valor
     * @return int|null
     */
    protected function entero_o_null($valor): ?int
    {
        if ($valor === null || $valor === '' || ! is_numeric($valor)) {
            return null;
        }

        return (int) $valor;
    }

    /**
     * Texto de un parámetro, cayendo al default cuando llega vacío o ausente.
     *
     * 🔴 Existe porque `$request->input('order', 'asc')` NO alcanza. El middleware global
     * `ConvertEmptyStringsToNull` (app/Http/Kernel.php) convierte `?order=` en null, y como la
     * clave EXISTE con valor null, `input()` devuelve null en vez del default. Ese null se
     * casteaba a `''` y llegaba a `orderBy('')`, que en Laravel 8 tira InvalidArgumentException:
     * un `?order=` de más en la URL devolvía **500** en vez del 422 legible que este controlador
     * se propone devolver siempre. Con `order_by=` era peor: `Unknown column 'leads.'`.
     *
     * @param  Request $request
     * @param  string  $clave
     * @param  string  $default
     * @return string
     */
    protected function texto_con_default(Request $request, string $clave, string $default): string
    {
        $valor = $this->texto_o_null($request->input($clave));

        return $valor !== null ? $valor : $default;
    }

    /**
     * Texto recortado, o null si quedó vacío.
     *
     * @param  mixed $valor
     * @return string|null
     */
    protected function texto_o_null($valor): ?string
    {
        if ($valor === null || is_array($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    /**
     * Parsea una fecha-hora sin lanzar.
     *
     * @param  string|null $valor
     * @return Carbon|null
     */
    protected function parsear_o_null(?string $valor): ?Carbon
    {
        if ($valor === null) {
            return null;
        }

        try {
            return Carbon::parse($valor, config('app.timezone'));
        } catch (\Exception $e) {
            return null;
        }
    }
}
