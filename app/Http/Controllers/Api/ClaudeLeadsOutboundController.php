<?php

namespace App\Http\Controllers\Api;

use App\Helpers\AppTime;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadBroadcastService;
use App\Services\LeadConversationErrorLogger;
use App\Services\WhatsappSendService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Lado de ESCRITURA de los endpoints `claude/*` de leads: enviar una plantilla Meta a un lead
 * (endpoint 7 del plan) o a un lote de leads nombrados uno por uno (endpoint 8).
 *
 * Protegido por el middleware `claude.task.key` (header X-Claude-Task-Key), igual que
 * ClaudeTaskIngestController. 🔴 Acá NO hay sesión de admin: `$request->user()` es null y llamarlo
 * revienta. Por eso todo LeadMessage que crea este controlador va con `sent_by_admin_id = null` y
 * `sent_via = 'claude'`, que es lo que la burbuja del admin-spa lee para rotular "Enviado por Claude".
 *
 * Estos endpoints tocan leads REALES: no hay entorno intermedio entre esto y el WhatsApp de la
 * gente. De ahí los cuatro frenos del lote, que no son adorno:
 *   1. `dry_run` en true por defecto — sin llamadas al sender ni LeadMessage creados.
 *   2. `confirm_count` obligatorio para enviar de verdad, y tiene que coincidir exacto.
 *   3. Tope duro de MAX_BATCH leads por llamada.
 *   4. Solo ids explícitos: no hay lenguaje de filtros del lado de escritura, así un filtro mal
 *      escrito no se puede convertir en un envío masivo.
 *
 * Reusa `WhatsappSendService` tal cual está: este controlador no modifica ni un renglón del camino
 * de envío que ya usan los seguimientos automáticos y el panel.
 */
class ClaudeLeadsOutboundController extends Controller
{
    /**
     * Tope duro de leads por llamada al lote.
     *
     * 🔴 50 y no más, y el motivo es medido, no de gusto: `KapsoHttpClient` arranca con
     * `services.client_api.timeout` (15 s por defecto) y `send_template()` hace `->retry(2, 500)`
     * —o sea 2 intentos, ~30 s—, y si el envío falla, `notify_admins_of_failure()` dispara además un
     * `send_text()` a los admins DENTRO de la misma iteración, con su propio timeout y reintentos.
     * Con Meta caído —que es exactamente el escenario que este endpoint existe para recuperar— 200
     * leads serían horas dentro de un solo request HTTP: lo mata `max_execution_time` o nginx a
     * mitad de camino y nadie sabe qué salió y qué no.
     */
    const MAX_BATCH = 50;

    /**
     * Presupuesto de tiempo total del loop de envío, en segundos.
     *
     * Cuando se agota, el lote corta LIMPIO y devuelve 200 con lo que alcanzó a enviar más los
     * `no_procesados`. Preferimos una respuesta honesta e incompleta antes que un request colgado
     * que muere sin contarle a nadie dónde quedó.
     */
    const PRESUPUESTO_SEGUNDOS = 50;

    /**
     * Reserva de tiempo, en segundos, que se le guarda al PRÓXIMO envío antes de arrancarlo.
     *
     * 🔴 Sin esto el presupuesto no acota nada, y ese era el bug: la comprobación miraba solo el
     * tiempo YA transcurrido, así que un envío que arrancaba en el segundo 49 podía correr otros
     * ~60 s (30 s de la plantilla + 30 s del aviso a admins si falla) y el request terminaba
     * muriendo a los ~110 s — exactamente lo que el presupuesto dice evitar, y encima sin respuesta,
     * que es el peor de los casos porque nadie sabe qué salió.
     *
     * Con la reserva, el loop no arranca un envío nuevo si no le entra el peor caso completo dentro
     * del presupuesto. Cuando los envíos salen rápido (~1 s) esto no se nota y pasan los 50; cuando
     * están lentos, corta temprano y devuelve `no_procesados`, que es justo cuando hace falta.
     */
    const RESERVA_POR_ENVIO_SEGUNDOS = 35;

    /** Horas de enfriamiento: un lead que ya recibió un mensaje de Claude no vuelve a recibir otro. */
    const COOLDOWN_HORAS = 24;

    /** Idioma por defecto de la plantilla, mismo default que WhatsappSendService::send_template(). */
    const IDIOMA_POR_DEFECTO = 'es_AR';

    /**
     * Valor de `lead_messages.sent_via` para los mensajes que salen por acá.
     *
     * Referencia a la constante del modelo, que es donde vive la definición: es el mismo string
     * que `ClaudeTaskIngestController` ya escribe en `admin_tasks.created_via`. Se apunta al
     * modelo y no se repite el literal para que no haya dos fuentes del mismo valor.
     */
    const ORIGEN_CLAUDE = LeadMessage::SENT_VIA_CLAUDE;

    /**
     * Estados del pipeline que se consideran cerrados: por defecto no se les envía nada.
     *
     * Se puede forzar con `include_closed=true`, pero tiene que ser una decisión escrita en el body,
     * no un descuido.
     */
    const ESTADOS_CERRADOS = ['cerrado_ganado', 'cerrado_perdido', 'en_pausa'];

    /**
     * Campos del lead que `variables_desde_lead` puede usar como variables de la plantilla.
     *
     * Lista blanca a propósito: sin ella, cualquier nombre de columna terminaría dentro de un
     * WhatsApp real (incluidos tokens y notas internas). `phone` y `email` quedan afuera aunque sean
     * del propio lead, porque la simulación enmascara el teléfono justamente para no volverse una
     * forma de exportar la agenda — meterlo como variable sería la puerta de atrás de esa regla.
     */
    const CAMPOS_DE_LEAD_PERMITIDOS = [
        'contact_name',
        'company_name',
        'user_name',
        'business_type',
        'demo_date',
        'demo_start_time',
        'demo_end_time',
        'total_a_pagar',
    ];

    /** Texto que se guarda cuando el envío no se confirmó y el sender no dejó ningún motivo. */
    const ERROR_GENERICO = 'El envío por plantilla no se confirmó (revisar conexión con WhatsApp/Kapso).';

    /**
     * Endpoint 7: envía una plantilla Meta a UN lead y registra el mensaje en su conversación.
     *
     * Espeja la estructura de `LeadController::send_template_json()` —el camino de envío es el mismo
     * y no se reinventa—, con tres diferencias: `sent_by_admin_id` queda null (acá no hay admin),
     * `sent_via` va en 'claude', y el mensaje se crea IGUAL si el envío falla, con el motivo real en
     * `whatsapp_send_error`, para que un envío caído quede visible en el hilo y no desaparezca.
     *
     * `is_followup` va en false a propósito: así el envío de Claude no consume el cupo de
     * `max_followups` del lead (el conteo de cupo de LeadFollowupService filtra por `is_followup=true`
     * antes que nada). `followup_template_id` se puede mandar igual, solo para trazabilidad.
     *
     * @param Request             $request Body: template_name (req), language_code, variables[],
     *                                     content (req, texto YA renderizado), followup_template_id, context.
     * @param int|string          $lead_id Lead destinatario.
     * @param WhatsappSendService $sender  Se retiene la instancia para poder leer su last_send_error.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function send_template_json(Request $request, $lead_id, WhatsappSendService $sender)
    {
        /* En try/catch y no `validate()` pelado: sin header Accept: application/json, Laravel
           responde un 302 de redirect en vez del 422, y del otro lado eso es indiagnosticable. */
        try {
            $request->validate([
            'template_name'        => 'required|string|max:255',
            'language_code'        => 'nullable|string|max:20',
            'variables'            => 'nullable|array',
            'content'              => 'required|string',
            'followup_template_id' => 'nullable|integer',
            'context'              => 'nullable|string|max:500',
            'ignorar_cooldown'     => 'nullable|boolean',
        ], [
            'template_name.required' => 'El nombre de la plantilla es obligatorio.',
            'template_name.string'   => 'El nombre de la plantilla tiene que ser texto.',
            'content.required'       => 'El contenido renderizado del mensaje es obligatorio.',
            'content.string'         => 'El contenido renderizado tiene que ser texto.',
            'variables.array'        => 'variables tiene que ser un array posicional ({{1}}, {{2}}…).',
            'language_code.string'   => 'language_code tiene que ser texto (ej. es_AR).',
            'followup_template_id.integer' => 'followup_template_id tiene que ser un id numérico.',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Parámetros inválidos. No se envió nada.',
                'errors'  => $e->errors(),
            ], 422);
        }

        $content = trim((string) $request->input('content', ''));
        if ($content === '') {
            return response()->json(['message' => 'El contenido renderizado no puede estar vacío.'], 422);
        }

        /* Lead destinatario. No se usa findOrFail para devolver un mensaje en español y no una página de error. */
        $lead = Lead::query()->find($lead_id);
        if ($lead === null) {
            return response()->json(['message' => 'No existe ningún lead con id ' . (int) $lead_id . '.'], 404);
        }

        /* Sin teléfono no hay a dónde mandar: 422 y no se crea absolutamente nada. */
        $phone = trim((string) ($lead->phone ?? ''));
        if ($phone === '') {
            return response()->json([
                'message' => 'El lead #' . (int) $lead->id . ' no tiene teléfono cargado: no se envió nada.',
            ], 422);
        }

        /*
         * 🔴 Cooldown también acá, y no solo en el lote. El lote lo tenía desde el principio; este
         * endpoint no, y esa asimetría era un agujero real: iterar POST claude/leads/{id}/send-template
         * sobre una lista de leads saltea el tope de lote, la simulación y la confirmación de conteo,
         * y con el rate limit en 60 req/min eso son 60 mensajes por minuto sin ninguna fricción.
         * También es lo único que hace idempotente un reintento después de un corte de red.
         *
         * Se puede saltear con ignorar_cooldown=true, que es el caso legítimo de "mandale otro
         * mensaje a este lead puntual": tiene que ser una decisión explícita, no el default.
         */
        if ($request->boolean('ignorar_cooldown') !== true) {
            $en_cooldown = $this->leads_en_cooldown([(int) $lead->id]);
            if (in_array((int) $lead->id, $en_cooldown, true)) {
                return response()->json([
                    'message' => 'El lead #' . (int) $lead->id . ' ya recibió un mensaje de Claude en las últimas '
                        . self::COOLDOWN_HORAS . ' hs: no se envió nada. Si de verdad querés mandarle otro, repetí la '
                        . 'llamada con ignorar_cooldown=true.',
                    'en_cooldown' => true,
                ], 422);
            }
        }

        $resultado = $this->enviar_plantilla_al_lead($sender, $lead, [
            'template_name'        => trim((string) $request->input('template_name', '')),
            'language_code'        => $this->resolver_idioma($request->input('language_code')),
            'variables'            => $this->normalizar_variables($request->input('variables', [])),
            'content'              => $content,
            'followup_template_id' => $request->filled('followup_template_id')
                ? (int) $request->input('followup_template_id')
                : null,
            'context'              => trim((string) $request->input('context', '')),
        ], true);

        return response()->json([
            'enviado'             => $resultado['ok'],
            'whatsapp_message_id' => $resultado['whatsapp_message_id'],
            'error'               => $resultado['error'],
            'lead_message'        => $resultado['lead_message'],
        ], 200);
    }

    /**
     * Endpoint 8: envía una plantilla Meta a un LOTE de leads nombrados uno por uno.
     *
     * 🔴 Solo acepta `lead_ids[]` explícitos: no hay ningún filtro ni consulta para elegir
     * destinatarios de este lado. Es deliberado — la selección se hace con los endpoints de lectura,
     * se revisa, y recién ahí se nombra a cada uno.
     *
     * Orden de los frenos, y el orden importa porque todos van ANTES del primer envío:
     *   1. Más de MAX_BATCH ids → 422, cero envíos.
     *   2. Se resuelven los leads: los que no existen, no tienen teléfono, están cerrados o ya
     *      recibieron un mensaje de Claude en las últimas COOLDOWN_HORAS salen como `omitidos`.
     *   3. `dry_run` (default true) → devuelve a quién le llegaría y con qué texto. Nada más.
     *   4. `dry_run=false` sin `confirm_count`, o con un `confirm_count` que no coincida exacto con
     *      la cantidad real de destinatarios → 422 con el número real, cero envíos.
     *   5. Recién ahí se envía, uno por uno, con presupuesto de tiempo y corte por error no transitorio.
     *
     * @param Request             $request Body: lead_ids[] (req), template_name (req), content_template (req),
     *                                     language_code, followup_template_id, variables_por_lead,
     *                                     variables_desde_lead[], dry_run, confirm_count, include_closed, context.
     * @param WhatsappSendService $sender  Una sola instancia para todo el lote: send_template() resetea
     *                                     last_send_error en cada llamada, así que reusarla es correcto
     *                                     y además es la única forma de leer el motivo real de cada fallo.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function send_template_batch_json(Request $request, WhatsappSendService $sender)
    {
        /* Ver el comentario del endpoint individual: garantiza 422 JSON y no un 302. */
        try {
            $request->validate([
            'lead_ids'               => 'required|array|min:1',
            'lead_ids.*'             => 'required|integer|min:1',
            'template_name'          => 'required|string|max:255',
            'content_template'       => 'required|string',
            'language_code'          => 'nullable|string|max:20',
            'followup_template_id'   => 'nullable|integer',
            'variables_por_lead'     => 'nullable|array',
            'variables_desde_lead'   => 'nullable|array',
            'variables_desde_lead.*' => 'required|string|max:60',
            'dry_run'                => 'nullable|boolean',
            'confirm_count'          => 'nullable|integer|min:0',
            'confirm_token'          => 'nullable|string|max:64',
            'include_closed'         => 'nullable|boolean',
            'context'                => 'nullable|string|max:500',
        ], [
            'lead_ids.required'         => 'lead_ids es obligatorio: hay que nombrar a cada destinatario.',
            'lead_ids.array'            => 'lead_ids tiene que ser un array de ids de lead.',
            'lead_ids.min'              => 'lead_ids no puede venir vacío.',
            'lead_ids.*.integer'        => 'Cada id de lead_ids tiene que ser un número entero.',
            'template_name.required'    => 'El nombre de la plantilla es obligatorio.',
            'content_template.required' => 'content_template es obligatorio: es el texto con {{1}}, {{2}}… que se guarda como cuerpo del mensaje.',
            'dry_run.boolean'           => 'dry_run tiene que ser true o false.',
            'confirm_count.integer'     => 'confirm_count tiene que ser un número entero.',
            'include_closed.boolean'    => 'include_closed tiene que ser true o false.',
            'variables_por_lead.array'  => 'variables_por_lead tiene que ser un mapa lead_id → array de variables.',
            'variables_desde_lead.array'=> 'variables_desde_lead tiene que ser un array de nombres de campo del lead.',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Parámetros inválidos. No se envió nada.',
                'errors'  => $e->errors(),
            ], 422);
        }

        /* --- Freno 1: tope duro por llamada, antes de tocar la base siquiera. --- */
        $lead_ids_crudos = $request->input('lead_ids', []);
        if (count($lead_ids_crudos) > self::MAX_BATCH) {
            return response()->json([
                'message'   => 'El lote no puede superar los ' . self::MAX_BATCH . ' leads por llamada y llegaron '
                    . count($lead_ids_crudos) . '. No se envió nada: partilo en tandas.',
                'max_batch' => self::MAX_BATCH,
                'recibidos' => count($lead_ids_crudos),
            ], 422);
        }

        /* Campos del lead pedidos como variables: se validan contra la lista blanca antes de nada. */
        $campos_desde_lead = $this->normalizar_campos_desde_lead($request->input('variables_desde_lead', []));
        foreach ($campos_desde_lead as $campo) {
            if (! in_array($campo, self::CAMPOS_DE_LEAD_PERMITIDOS, true)) {
                return response()->json([
                    'message'           => 'El campo "' . $campo . '" no está permitido en variables_desde_lead. No se envió nada.',
                    'campos_permitidos' => self::CAMPOS_DE_LEAD_PERMITIDOS,
                ], 422);
            }
        }

        $content_template = trim((string) $request->input('content_template', ''));
        if ($content_template === '') {
            return response()->json(['message' => 'content_template no puede estar vacío.'], 422);
        }

        $template_name = trim((string) $request->input('template_name', ''));
        if ($template_name === '') {
            return response()->json(['message' => 'El nombre de la plantilla no puede estar vacío.'], 422);
        }

        /* Ids únicos conservando el orden en que los nombró el llamador. Un id repetido se omite en
           vez de mandar dos veces el mismo mensaje al mismo teléfono. */
        $omitidos = [];
        $lead_ids = [];
        foreach ($lead_ids_crudos as $valor) {
            $id = (int) $valor;
            if (in_array($id, $lead_ids, true)) {
                $omitidos[] = ['lead_id' => $id, 'motivo' => 'id repetido en lead_ids (se envía una sola vez)'];
                continue;
            }
            $lead_ids[] = $id;
        }

        /* --- Freno 2: resolución de destinatarios. Todo lo que no califica sale como omitido. --- */
        $leads_por_id = [];
        $leads = Lead::query()->whereIn('id', $lead_ids)->get();
        foreach ($leads as $lead) {
            $leads_por_id[(int) $lead->id] = $lead;
        }

        $incluir_cerrados = $request->boolean('include_closed');
        /* Leads que ya recibieron un mensaje de Claude dentro de la ventana de enfriamiento. Una sola
           consulta agregada para todo el lote: nada de N+1 acá adentro. */
        $en_cooldown = $this->leads_en_cooldown($lead_ids);

        $variables_por_lead = $request->input('variables_por_lead', []);
        if (! is_array($variables_por_lead)) {
            $variables_por_lead = [];
        }

        /*
         * 🔴 Tiene que ser un MAPA lead_id => variables, nunca una lista posicional.
         *
         * Si llega `[["Juan"], ["Ana"]]`, las claves son 0 y 1, y el lookup por id haría que el lead
         * con id 1 se lleve las variables del índice 1 — o sea, el mensaje de OTRA persona, con el
         * nombre de otra persona, enviado a un teléfono real. Es el peor error posible de este
         * endpoint y es silencioso: el lote sale completo y nadie se entera hasta que alguien
         * contesta "¿quién es Ana?".
         *
         * Se rechaza de entrada en vez de intentar adivinar la intención.
         */
        if (! empty($variables_por_lead) && array_keys($variables_por_lead) === range(0, count($variables_por_lead) - 1)) {
            return response()->json([
                'message' => 'variables_por_lead tiene que ser un mapa lead_id => array de variables, no una lista '
                    . 'posicional. Con una lista, las claves son 0,1,2... y un lead cuyo id coincida con un índice se '
                    . 'llevaría las variables de otro destinatario. No se envió nada. '
                    . 'Ejemplo válido: {"' . (int) reset($lead_ids_crudos) . '": ["Juan"]}.',
            ], 422);
        }

        $destinatarios = [];
        foreach ($lead_ids as $id) {
            if (! isset($leads_por_id[$id])) {
                $omitidos[] = ['lead_id' => $id, 'motivo' => 'el lead no existe'];
                continue;
            }

            $lead = $leads_por_id[$id];

            $phone = trim((string) ($lead->phone ?? ''));
            if ($phone === '') {
                $omitidos[] = ['lead_id' => $id, 'motivo' => 'el lead no tiene teléfono cargado'];
                continue;
            }

            $status = (string) ($lead->status ?? '');
            /* strtolower: la comparación estricta dejaba pasar un 'CERRADO_GANADO' cargado con
               otra capitalización, y ese lead recibía el mensaje igual. */
            if (! $incluir_cerrados && in_array(strtolower($status), self::ESTADOS_CERRADOS, true)) {
                $omitidos[] = [
                    'lead_id' => $id,
                    'motivo'  => 'el lead está en estado "' . $status . '" (cerrado); mandá include_closed=true si igual querés escribirle',
                ];
                continue;
            }

            if (in_array($id, $en_cooldown, true)) {
                $omitidos[] = [
                    'lead_id' => $id,
                    'motivo'  => 'ya recibió un mensaje de Claude en las últimas ' . self::COOLDOWN_HORAS . ' hs',
                ];
                continue;
            }

            $variables = $this->resolver_variables_del_lote($lead, $variables_por_lead, $campos_desde_lead);

            $destinatarios[] = [
                'lead_id'              => $id,
                'contact_name'         => $lead->contact_name,
                'company_name'         => $lead->company_name,
                'status'               => $status,
                'telefono_enmascarado' => $this->enmascarar_telefono($phone),
                'variables'            => $variables,
                'content'              => $this->render_contenido($content_template, $variables),
            ];
        }

        $enviarian = count($destinatarios);

        /* Huella del conjunto exacto de destinatarios, para que la confirmación no pueda referirse
           a un lote distinto del que se simuló. Ver el docblock de calcular_confirm_token(). */
        $confirm_token = $this->calcular_confirm_token($destinatarios, $template_name);

        /* --- Freno 3: simulación. Es el default, y no toca el sender ni crea ningún LeadMessage. --- */
        $dry_run = $request->filled('dry_run') ? $request->boolean('dry_run') : true;
        if ($dry_run) {
            return response()->json([
                'dry_run'       => true,
                'enviarian'     => $enviarian,
                'omitidos'      => $omitidos,
                'destinatarios' => $destinatarios,
                'confirm_token' => $confirm_token,
                'nota'          => 'Simulación: no se envió ningún mensaje ni se creó ningún LeadMessage. '
                    . 'REVISÁ la lista de destinatarios y el texto renderizado antes de seguir. '
                    . 'Para enviar de verdad, repetí la misma llamada con dry_run=false, confirm_count=' . $enviarian
                    . ' y confirm_token=' . $confirm_token . '.',
            ], 200);
        }

        /* --- Freno 4: confirmación explícita del número exacto de destinatarios. --- */
        if (! $request->filled('confirm_count')) {
            return response()->json([
                'message'   => 'confirm_count es obligatorio cuando dry_run es false. No se envió nada.',
                'enviarian' => $enviarian,
                'omitidos'  => $omitidos,
            ], 422);
        }

        $confirm_count = (int) $request->input('confirm_count');
        if ($confirm_count !== $enviarian) {
            return response()->json([
                'message'                => 'confirm_count no coincide con la cantidad real de destinatarios ('
                    . $enviarian . '). No se envió nada: revisá los omitidos y volvé a llamar con el número real.',
                'confirm_count_recibido' => $confirm_count,
                'enviarian'              => $enviarian,
                'omitidos'               => $omitidos,
            ], 422);
        }

        /*
         * --- Freno 5: el token ata la confirmación al CONJUNTO, no solo a la cantidad. ---
         *
         * 🔴 Sin esto, `confirm_count` se satisface con cualquier lote del mismo tamaño: simular con
         * los leads A y B y después enviar a C y D pasaba el chequeo sin una sola advertencia. El
         * conteo protege de "la lista cambió de tamaño entre la simulación y el envío"; no protege de
         * "es otra lista". Y el error de buena fe más probable acá —armar la segunda llamada con una
         * lista distinta de la que se revisó— cae justo en ese hueco.
         */
        $token_recibido = trim((string) $request->input('confirm_token', ''));
        if ($token_recibido === '') {
            return response()->json([
                'message'       => 'confirm_token es obligatorio cuando dry_run es false. Corré primero la simulación '
                    . '(sin dry_run), revisá los destinatarios y volvé con el token que te devolvió. No se envió nada.',
                'enviarian'     => $enviarian,
                'confirm_token' => $confirm_token,
            ], 422);
        }

        if (! hash_equals($confirm_token, $token_recibido)) {
            return response()->json([
                'message' => 'confirm_token no corresponde a este conjunto de destinatarios: la lista de leads, la '
                    . 'plantilla o el texto cambiaron respecto de la simulación que generó ese token. No se envió nada. '
                    . 'Volvé a simular y usá el token nuevo.',
                'enviarian'              => $enviarian,
                'confirm_token_esperado' => $confirm_token,
            ], 422);
        }

        /* --- Envío real, uno por uno. --- */
        $language_code        = $this->resolver_idioma($request->input('language_code'));
        $followup_template_id = $request->filled('followup_template_id')
            ? (int) $request->input('followup_template_id')
            : null;
        $context_del_llamador = trim((string) $request->input('context', ''));

        $resultados     = [];
        $no_procesados  = [];
        $enviados       = 0;
        $fallidos       = 0;
        $abortado       = false;
        $motivo_corte   = null;
        $arranque       = microtime(true);
        $indice         = 0;

        foreach ($destinatarios as $destinatario) {
            /* Presupuesto de tiempo: cortamos limpio y devolvemos lo que se hizo, en vez de dejar el
               request colgado hasta que lo mate PHP (ahí no hay respuesta y nadie sabe dónde quedó).
               🔴 Se le RESERVA al próximo envío su peor caso: mirar solo el tiempo transcurrido no
               acota nada, porque un envío que arranca justo antes del límite corre igual sus ~35 s.
               Ver el docblock de RESERVA_POR_ENVIO_SEGUNDOS. */
            $transcurrido = microtime(true) - $arranque;
            if ($indice > 0 && ($transcurrido + self::RESERVA_POR_ENVIO_SEGUNDOS) >= self::PRESUPUESTO_SEGUNDOS) {
                $abortado     = true;
                $motivo_corte = 'se agotó el presupuesto de ' . self::PRESUPUESTO_SEGUNDOS . ' s del request ('
                    . round($transcurrido, 1) . ' s usados, y no entra otro envío sin pasarse); los leads no '
                    . 'procesados NO recibieron nada y se pueden reintentar sin riesgo de duplicar';
            }

            if ($abortado) {
                $no_procesados[] = $destinatario['lead_id'];
                $indice++;
                continue;
            }

            $lead = $leads_por_id[$destinatario['lead_id']];

            try {
                $resultado = $this->enviar_plantilla_al_lead($sender, $lead, [
                    'template_name'        => $template_name,
                    'language_code'        => $language_code,
                    'variables'            => $destinatario['variables'],
                    'content'              => $destinatario['content'],
                    'followup_template_id' => $followup_template_id,
                    'context'              => $context_del_llamador,
                    /* 🔴 En el lote NO se deja bloque rojo en el hilo: un lote de 50 que falla entero
                       ensuciaría 50 conversaciones con burbujas de error. Los fallos van en la
                       respuesta y en el log diario. En el envío individual sí se deja. */
                ], false);
            } catch (\Throwable $e) {
                /* Un fallo inesperado en un lead no puede llevarse puesto el lote entero. */
                Log::channel('daily')->error('ClaudeLeadsOutboundController: excepción al procesar un lead del lote.', [
                    'lead_id' => $destinatario['lead_id'],
                    'error'   => $e->getMessage(),
                ]);
                $resultado = [
                    'ok'                  => false,
                    'whatsapp_message_id' => null,
                    'error'               => $e->getMessage(),
                    'lead_message'        => null,
                ];
            }

            if ($resultado['ok']) {
                $enviados++;
            } else {
                $fallidos++;
            }

            $resultados[] = [
                'lead_id'             => $destinatario['lead_id'],
                'ok'                  => $resultado['ok'],
                'whatsapp_message_id' => $resultado['whatsapp_message_id'],
                'error'               => $resultado['error'],
                'lead_message_id'     => $resultado['lead_message'] !== null
                    ? $resultado['lead_message']['id']
                    : null,
            ];

            /*
             * Si el PRIMER envío falla, el problema casi nunca es ese lead: es Meta/Kapso. Repetir 49
             * veces el mismo timeout no ayuda a nadie y garantiza que el request se muera por tiempo.
             * Cortamos y devolvemos los no procesados, que se pueden reintentar sin riesgo.
             *
             * 🔴 A propósito NO se consulta `WhatsappSendService::last_send_was_transient()`, aunque
             * el nombre invite. Ese método lee `last_send_status_code`, que hoy solo se asigna dentro
             * de `send_text()`: `send_template()` lo resetea a null al arrancar y no lo vuelve a
             * setear nunca, así que después de un fallo de plantilla devuelve SIEMPRE false. Apoyarse
             * en él sería depender de un bug de otro archivo para ser seguro: el día que alguien
             * corrija esa asimetría —que es la corrección obvia y correcta— este lote empezaría a
             * seguir mandando los 49 restantes contra un Meta caído, en silencio y sin que ningún
             * test lo note. Cortamos ante cualquier primer fallo, y que sea una decisión explícita.
             */
            if ($indice === 0 && ! $resultado['ok']) {
                $abortado     = true;
                $motivo_corte = 'el primer envío del lote falló ('
                    . ($resultado['error'] !== null ? $resultado['error'] : self::ERROR_GENERICO)
                    . '); se cortó el lote para no repetir el mismo fallo en los demás. Los no procesados NO '
                    . 'recibieron nada: revisá el motivo y reintentá cuando esté resuelto';
            }

            $indice++;
        }

        return response()->json([
            'dry_run'       => false,
            'enviados'      => $enviados,
            'fallidos'      => $fallidos,
            'omitidos'      => $omitidos,
            'no_procesados' => $no_procesados,
            'abortado'      => $abortado,
            'motivo_corte'  => $motivo_corte,
            'resultados'    => $resultados,
        ], 200);
    }

    /**
     * Envía una plantilla a un lead y registra el LeadMessage, haya salido o no.
     *
     * El mensaje se crea SIEMPRE, igual que en `LeadFollowupService::send_followup_via_template()`:
     * un envío que no se confirmó tiene que quedar visible en el hilo, con el motivo en
     * `whatsapp_send_error`, y no desaparecer como si nunca se hubiera intentado.
     *
     * @param WhatsappSendService $sender             Instancia retenida: su `last_send_error` es el motivo real del fallo.
     * @param Lead                $lead               Destinatario (ya validado: existe y tiene teléfono).
     * @param array               $envio              template_name, language_code, variables, content,
     *                                                followup_template_id, context.
     * @param bool                $registrar_en_hilo  Si true y el envío falla, deja además el bloque rojo con
     *                                                LeadConversationErrorLogger. Va en false en el lote.
     *
     * @return array{ok: bool, whatsapp_message_id: string|null, error: string|null, lead_message: array|null}
     */
    protected function enviar_plantilla_al_lead(WhatsappSendService $sender, Lead $lead, array $envio, bool $registrar_en_hilo): array
    {
        $template_name        = (string) $envio['template_name'];
        $language_code        = (string) $envio['language_code'];
        $variables            = isset($envio['variables']) && is_array($envio['variables']) ? $envio['variables'] : [];
        $content              = (string) $envio['content'];
        $followup_template_id = isset($envio['followup_template_id']) ? $envio['followup_template_id'] : null;

        /* Contexto explícito para el aviso a admins de notify_admins_of_failure(): sin esto, el aviso
           que le llega a Lucas cuando el envío falla no dice de dónde salió el mensaje. */
        $context = isset($envio['context']) ? trim((string) $envio['context']) : '';
        if ($context === '') {
            $context = 'Recuperación por Claude - Lead #' . (int) $lead->id
                . ' (' . trim((string) ($lead->contact_name ?? '')) . ')';
        }

        $whatsapp_message_id = null;
        $error               = null;

        try {
            $whatsapp_message_id = $sender->send_template(
                (string) $lead->phone,
                $template_name,
                $variables,
                $language_code,
                $context
            );

            if ($whatsapp_message_id === null) {
                /* Motivo real capturado por el sender; si no capturó ninguno, texto genérico, para que
                   el mensaje quede igual identificable como fallido desde claude/messages. */
                $error = $sender->last_send_error ? $sender->last_send_error : self::ERROR_GENERICO;
            }
        } catch (\Throwable $e) {
            /* send_template() ya atrapa sus propias excepciones y devuelve null: esto es defensa en
               profundidad para que nada raro se lleve puesto el registro del intento.
               🔴 El texto de la excepción va al log, NUNCA a la respuesta: una excepción de PDO trae
               el INSERT completo con los valores atados, incluido el contenido del mensaje. */
            $error = self::ERROR_GENERICO;
            Log::channel('daily')->error('ClaudeLeadsOutboundController: excepción al enviar la plantilla.', [
                'lead_id'       => $lead->id,
                'template_name' => $template_name,
                'error'         => $e->getMessage(),
            ]);
        }

        /*
         * 🔴 EL ORDEN IMPORTA Y LA FALLA DE ACÁ ES LA MÁS CARA DE TODO EL CONTROLADOR.
         *
         * El WhatsApp ya salió (o no) ANTES de esta línea. Si el INSERT explota —índice único de
         * whatsapp_message_id, deadlock, corte de conexión— y dejamos que la excepción suba, pasa lo
         * peor posible: el mensaje LLEGÓ al lead, no queda ninguna fila, el lead NO entra en cooldown,
         * y la respuesta dice `ok:false`. El reintento vuelve a mandar y el lead recibe el mensaje dos
         * veces. Es exactamente el escenario que el cooldown existe para impedir.
         *
         * Por eso: si el envío se confirmó, la fila se escribe SÍ O SÍ. Si el insert normal falla, se
         * intenta uno mínimo que al menos deje el whatsapp_message_id y el sent_via, que es lo único
         * que el cooldown necesita para frenar el reintento. Y pase lo que pase, un mensaje que salió
         * se reporta como `ok:true`: mentir en la otra dirección es lo que duplica envíos.
         */
        $message = null;
        $fallo_de_persistencia = false;

        try {
            $message = $this->persistir_mensaje($lead, [
                'content'              => $content,
                'whatsapp_message_id'  => $whatsapp_message_id,
                'error'                => $error,
                'followup_template_id' => $followup_template_id,
            ]);
        } catch (\Throwable $e) {
            $fallo_de_persistencia = true;
            Log::channel('daily')->error(
                'ClaudeLeadsOutboundController: 🔴 no se pudo registrar el mensaje DESPUÉS de intentar el envío.',
                [
                    'lead_id'             => $lead->id,
                    'whatsapp_message_id' => $whatsapp_message_id,
                    'salio_el_mensaje'    => $whatsapp_message_id !== null,
                    'error'               => $e->getMessage(),
                ]
            );

            /* Si el mensaje salió, hace falta una fila igual para que el cooldown frene el reintento. */
            if ($whatsapp_message_id !== null) {
                try {
                    $message = $this->persistir_mensaje($lead, [
                        'content'              => $content,
                        'whatsapp_message_id'  => $whatsapp_message_id,
                        'error'                => 'El mensaje salió pero no se pudo registrar completo (ver log del día).',
                        'followup_template_id' => null,
                    ]);
                } catch (\Throwable $e2) {
                    Log::channel('daily')->error(
                        'ClaudeLeadsOutboundController: 🔴🔴 el mensaje SALIÓ y NO quedó registrado. '
                            . 'El lead no está en cooldown: un reintento se lo manda de nuevo.',
                        [
                            'lead_id'             => $lead->id,
                            'whatsapp_message_id' => $whatsapp_message_id,
                            'error'               => $e2->getMessage(),
                        ]
                    );
                }
            }
        }

        if ($message === null) {
            /* Sin fila. Se reporta según lo único que importa: si el mensaje salió o no. */
            return [
                'ok'                  => $whatsapp_message_id !== null,
                'whatsapp_message_id' => $whatsapp_message_id,
                'error'               => $whatsapp_message_id !== null
                    ? '🔴 El mensaje SE ENVIÓ pero no se pudo registrar en la conversación. NO reintentar este lead: '
                        . 'no está en cooldown y un reintento se lo manda de nuevo. Ver el log del día.'
                    : ($error !== null ? $error : self::ERROR_GENERICO),
                'lead_message'        => null,
                'persistencia_fallida' => true,
            ];
        }

        if ($fallo_de_persistencia) {
            Log::channel('daily')->warning('ClaudeLeadsOutboundController: el mensaje se registró en modo mínimo.', [
                'lead_id'         => $lead->id,
                'lead_message_id' => $message->id,
            ]);
        }

        return $this->cerrar_envio($sender, $lead, $message, $whatsapp_message_id, $error, $template_name, $registrar_en_hilo);
    }

    /**
     * Huella determinista del lote simulado: ata la confirmación al conjunto exacto de
     * destinatarios y al texto que se les va a mandar.
     *
     * Entra el id de cada destinatario en orden, el contenido ya renderizado de cada uno y el
     * nombre de la plantilla. Cambiar un solo lead, o el texto de uno solo, da otro token.
     *
     * Determinista y sin estado: no hace falta ninguna tabla ni ninguna sesión para validarlo,
     * se recalcula del mismo input. No es un secreto ni pretende serlo — no defiende contra
     * alguien que quiere burlarlo (ya tiene la clave de la API), defiende contra el error de
     * armar la segunda llamada con una lista distinta de la que se revisó en la simulación.
     *
     * @param array  $destinatarios Lista ya resuelta, en el orden en que se va a enviar.
     * @param string $template_name
     *
     * @return string
     */
    protected function calcular_confirm_token(array $destinatarios, string $template_name): string
    {
        $partes = [];
        foreach ($destinatarios as $destinatario) {
            $partes[] = (int) $destinatario['lead_id'] . ':' . md5((string) $destinatario['content']);
        }
        sort($partes);

        return substr(hash('sha256', $template_name . '|' . implode('|', $partes)), 0, 32);
    }

    /**
     * Escribe la fila del mensaje en la conversación del lead.
     *
     * Aislado del resto para poder reintentarlo en modo mínimo si el insert completo falla:
     * ver el bloque de persistencia de enviar_plantilla_al_lead().
     *
     * @param Lead  $lead
     * @param array $datos content, whatsapp_message_id, error, followup_template_id.
     *
     * @return LeadMessage
     */
    protected function persistir_mensaje(Lead $lead, array $datos): LeadMessage
    {
        $whatsapp_message_id = isset($datos['whatsapp_message_id']) ? $datos['whatsapp_message_id'] : null;

        return LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'setter',
            'content'               => (string) $datos['content'],
            'status'                => 'enviado',
            'whatsapp_message_id'   => $whatsapp_message_id,
            'whatsapp_send_error'   => isset($datos['error']) ? $datos['error'] : null,
            /* Solo se estampa si el envío se confirmó. Un mensaje que nunca salió no tiene hora de
               envío, y además LeadMessage::booted() prefiere sent_at sobre created_at para mover
               leads.last_message_at: con sent_at cargado, un envío caído haría figurar actividad
               reciente en la bandeja que en realidad nunca existió. Es el mismo criterio que usa
               LeadFollowupService::send_followup_via_template(), que deja sent_at nulo al fallar. */
            'sent_at'               => $whatsapp_message_id !== null ? AppTime::now() : null,
            /* 🔴 false a propósito: el envío de Claude no consume el cupo de max_followups del lead.
               followup_template_id se guarda igual, solo para trazabilidad de qué plantilla se usó. */
            'is_followup'           => false,
            'followup_template_id'  => isset($datos['followup_template_id']) ? $datos['followup_template_id'] : null,
            'requiere_verificacion' => false,
            /* Acá no hay admin: la request entra por claude.task.key, sin sesión de Sanctum. */
            'sent_by_admin_id'      => null,
            'sent_via'              => self::ORIGEN_CLAUDE,
        ]);
    }

    /**
     * Cierra un envío ya persistido: broadcast, log y bloque rojo si corresponde.
     *
     * @param WhatsappSendService $sender
     * @param Lead                $lead
     * @param LeadMessage         $message
     * @param string|null         $whatsapp_message_id
     * @param string|null         $error
     * @param string              $template_name
     * @param bool                $registrar_en_hilo
     *
     * @return array
     */
    protected function cerrar_envio(
        WhatsappSendService $sender,
        Lead $lead,
        LeadMessage $message,
        $whatsapp_message_id,
        $error,
        string $template_name,
        bool $registrar_en_hilo
    ): array {
        if ($whatsapp_message_id !== null) {
            /*
             * Envío confirmado: avisamos a la SPA para que la conversación se actualice en vivo.
             * 🔴 En try/catch a propósito: LeadConversationUpdated implementa ShouldBroadcastNow,
             * o sea que la llamada al broadcaster es SÍNCRONA. Si Pusher se cae, una excepción acá
             * marcaría como fallido un mensaje que sí salió y sí quedó registrado — y en el lote,
             * si es el primero, abortaría el lote entero. El mensaje ya llegó al lead: que la SPA
             * no se entere en vivo es un problema mucho menor que mentir sobre el envío.
             */
            try {
                LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);
            } catch (\Throwable $e) {
                Log::channel('daily')->warning('ClaudeLeadsOutboundController: falló el broadcast, el envío sí salió.', [
                    'lead_id'         => $lead->id,
                    'lead_message_id' => $message->id,
                    'error'           => $e->getMessage(),
                ]);
            }
        } else {
            Log::channel('daily')->warning('ClaudeLeadsOutboundController: el envío de plantilla no se confirmó.', [
                'lead_id'         => $lead->id,
                'template_name'   => $template_name,
                'lead_message_id' => $message->id,
                'error'           => $error,
            ]);

            if ($registrar_en_hilo) {
                /* Bloque rojo en el hilo, igual que el resto del sistema. Solo en el envío individual:
                   en el lote ensuciaría una conversación por cada fallo. */
                (new LeadConversationErrorLogger())->log(
                    (int) $lead->id,
                    'No se pudo enviar la plantilla que mandó Claude',
                    $error !== null ? $error : self::ERROR_GENERICO
                );
            }
        }

        return [
            'ok'                  => $whatsapp_message_id !== null,
            'whatsapp_message_id' => $whatsapp_message_id,
            'error'               => $error,
            'lead_message'        => $this->resumen_de_mensaje($message),
        ];
    }

    /**
     * Ids de los leads del lote que ya recibieron un mensaje de Claude dentro de la ventana de
     * enfriamiento.
     *
     * Es lo único que hace seguro reintentar un lote: si el POST real se corta por red después de
     * mandar 20 de 50 y alguien lo repite con los mismos ids, esos 20 no reciben el mensaje dos veces.
     *
     * @param array $lead_ids Ids únicos del lote.
     *
     * @return array Lista de ids (int) que están en enfriamiento.
     */
    protected function leads_en_cooldown(array $lead_ids): array
    {
        if (count($lead_ids) === 0) {
            return [];
        }

        /* AppTime y no Carbon::now(): LeadMessage usa UsesVirtualTime, así que sus created_at siguen
           el reloj virtual en local. Comparar contra el reloj real daría una ventana corrida. */
        $desde = AppTime::now()->subHours(self::COOLDOWN_HORAS);

        /*
         * 🔴 whereNotNull(whatsapp_message_id): el cooldown cuenta mensajes que EFECTIVAMENTE
         * salieron, no intentos.
         *
         * Sin esta condición el endpoint se autobloqueaba justo en el caso para el que existe: con
         * Meta caído, el envío falla, igual se graba la fila (a propósito, para la trazabilidad), y
         * el lead quedaba 24 hs sin poder recibir nada habiendo recibido nada. La recuperación se
         * saboteaba sola.
         *
         * Es seguro mirar whatsapp_message_id porque el bloque de persistencia de
         * enviar_plantilla_al_lead() garantiza que un envío confirmado SIEMPRE deja fila, incluso
         * si el insert completo falla. Las dos cosas van juntas: si alguna vez se afloja esa
         * garantía, este filtro deja de frenar reintentos y se duplican mensajes.
         */
        $ids = LeadMessage::query()
            ->whereIn('lead_id', $lead_ids)
            ->where('sent_via', self::ORIGEN_CLAUDE)
            ->whereNotNull('whatsapp_message_id')
            ->where('created_at', '>=', $desde)
            ->distinct()
            ->pluck('lead_id')
            ->all();

        $limpios = [];
        foreach ($ids as $id) {
            $limpios[] = (int) $id;
        }

        return $limpios;
    }

    /**
     * Resuelve las variables de un lead del lote.
     *
     * `variables_por_lead` manda para el lead que tenga entrada propia; si no la tiene, se cae a
     * `variables_desde_lead` (los mismos campos para todos). Si no hay ninguna de las dos, array vacío.
     *
     * @param Lead  $lead
     * @param array $variables_por_lead Mapa lead_id → array de variables.
     * @param array $campos_desde_lead  Campos del lead a usar como variables, en orden.
     *
     * @return array Variables posicionales ya normalizadas a string.
     */
    protected function resolver_variables_del_lote(Lead $lead, array $variables_por_lead, array $campos_desde_lead): array
    {
        $id = (int) $lead->id;

        /* Un mapa que viene de JSON puede llegar con la clave como int o como string, según cómo lo
           haya serializado el llamador: se prueban las dos. */
        if (array_key_exists($id, $variables_por_lead)) {
            return $this->normalizar_variables($variables_por_lead[$id]);
        }
        if (array_key_exists((string) $id, $variables_por_lead)) {
            return $this->normalizar_variables($variables_por_lead[(string) $id]);
        }

        if (count($campos_desde_lead) > 0) {
            $valores = [];
            foreach ($campos_desde_lead as $campo) {
                /* Un lead sin ese dato aporta string vacío: un valor faltante no rompe el lote entero. */
                $valores[] = $lead->getAttribute($campo);
            }

            return $this->normalizar_variables($valores);
        }

        return [];
    }

    /**
     * Renderiza el cuerpo del mensaje reemplazando {{1}}, {{2}}… por las variables, en orden.
     *
     * Lo que se manda a Meta son las `variables` (array posicional) y lo que se guarda en la base es
     * este texto renderizado: son dos cosas distintas y tienen que decir lo mismo.
     *
     * @param string $plantilla Texto con los placeholders de Meta.
     * @param array  $variables Variables posicionales ya normalizadas.
     *
     * @return string
     */
    protected function render_contenido(string $plantilla, array $variables): string
    {
        $texto    = $plantilla;
        $posicion = 1;
        foreach ($variables as $valor) {
            $texto = str_replace('{{' . $posicion . '}}', (string) $valor, $texto);
            $posicion++;
        }

        /* Cualquier {{n}} que quedó sin variable se borra: no se le manda un placeholder crudo al lead. */
        $limpio = preg_replace('/\{\{\s*\d+\s*\}\}/', '', $texto);

        return $limpio !== null ? $limpio : $texto;
    }

    /**
     * Normaliza un array de variables a strings posicionales.
     *
     * Nada de esto rompe el lote: un valor nulo, un array anidado o un booleano se convierten a algo
     * imprimible en vez de tirar una excepción a mitad de camino.
     *
     * @param mixed $crudas
     *
     * @return array
     */
    protected function normalizar_variables($crudas): array
    {
        if (! is_array($crudas)) {
            return [];
        }

        $limpias = [];
        foreach ($crudas as $valor) {
            if ($valor === null) {
                $limpias[] = '';
                continue;
            }
            if ($valor instanceof \DateTimeInterface) {
                /* Las fechas del lead (demo_date) están casteadas a Carbon: se muestran como las lee
                   una persona, no como un datetime completo. */
                $limpias[] = $valor->format('d/m/Y');
                continue;
            }
            if (is_bool($valor)) {
                $limpias[] = $valor ? '1' : '0';
                continue;
            }
            if (is_array($valor) || is_object($valor)) {
                $limpias[] = '';
                continue;
            }

            $limpias[] = (string) $valor;
        }

        return $limpias;
    }

    /**
     * Normaliza la lista de campos de `variables_desde_lead` (trim y descarte de vacíos).
     *
     * @param mixed $crudos
     *
     * @return array
     */
    protected function normalizar_campos_desde_lead($crudos): array
    {
        if (! is_array($crudos)) {
            return [];
        }

        $campos = [];
        foreach ($crudos as $campo) {
            $campo = trim((string) $campo);
            if ($campo === '') {
                continue;
            }
            $campos[] = $campo;
        }

        return $campos;
    }

    /**
     * Idioma de la plantilla, con el default de la casa cuando no vino o vino vacío.
     *
     * @param mixed $crudo
     *
     * @return string
     */
    protected function resolver_idioma($crudo): string
    {
        $idioma = trim((string) ($crudo ?? ''));

        return $idioma !== '' ? $idioma : self::IDIOMA_POR_DEFECTO;
    }

    /**
     * Enmascara un teléfono dejando visibles solo los últimos 4 dígitos.
     *
     * La simulación existe para revisar a quién le pega el envío, no para exportar la agenda.
     *
     * @param string $phone
     *
     * @return string
     */
    protected function enmascarar_telefono(string $phone): string
    {
        $digitos = preg_replace('/\D+/', '', $phone);
        if ($digitos === null) {
            $digitos = '';
        }
        if ($digitos === '') {
            return '';
        }
        if (strlen($digitos) <= 4) {
            return str_repeat('*', strlen($digitos));
        }

        return str_repeat('*', strlen($digitos) - 4) . substr($digitos, -4);
    }

    /**
     * Proyección explícita del LeadMessage recién creado.
     *
     * 🔴 A propósito NO se serializa el modelo: `LeadMessage::$appends` trae
     * `suggested_lead_status_label`, `pending_actions_summary` y `sent_by_admin_name`, y los dos
     * primeros pegan contra la base (el lead y LeadPipelineStatus). En un lote de 50 eso son 100
     * consultas de más por nada. Los campos que quiero los pido por nombre.
     *
     * @param LeadMessage $message
     *
     * @return array
     */
    protected function resumen_de_mensaje(LeadMessage $message): array
    {
        return [
            'id'                   => (int) $message->id,
            'lead_id'              => (int) $message->lead_id,
            'sender'               => $message->sender,
            'status'               => $message->status,
            'content'              => $message->content,
            'is_followup'          => (bool) $message->is_followup,
            'followup_template_id' => $message->followup_template_id !== null
                ? (int) $message->followup_template_id
                : null,
            'whatsapp_message_id'  => $message->whatsapp_message_id,
            'whatsapp_send_error'  => $message->whatsapp_send_error,
            'sent_by_admin_id'     => $message->sent_by_admin_id !== null ? (int) $message->sent_by_admin_id : null,
            'sent_via'             => $message->sent_via,
            'sent_at'              => $message->sent_at !== null ? (string) $message->sent_at : null,
            'created_at'           => $message->created_at !== null ? (string) $message->created_at : null,
        ];
    }
}
