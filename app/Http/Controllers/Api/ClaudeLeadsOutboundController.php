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
     * `services.client_api.timeout` (15 s por defecto) y `send_template()` hace `->retry(2, 500)`,
     * así que UN envío que falla puede tardar ~45 s. Con Meta caído —que es exactamente el escenario
     * que este endpoint existe para recuperar— 200 leads serían horas dentro de un solo request HTTP:
     * lo mata `max_execution_time` o nginx a mitad de camino y nadie sabe qué salió y qué no.
     */
    const MAX_BATCH = 50;

    /**
     * Presupuesto de tiempo del loop de envío, en segundos.
     *
     * Cuando se agota, el lote corta LIMPIO y devuelve 200 con lo que alcanzó a enviar más los
     * `no_procesados`. Preferimos una respuesta honesta e incompleta antes que un request colgado
     * que muere sin contarle a nadie dónde quedó.
     */
    const PRESUPUESTO_SEGUNDOS = 50;

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
        $request->validate([
            'template_name'        => 'required|string|max:255',
            'language_code'        => 'nullable|string|max:20',
            'variables'            => 'nullable|array',
            'content'              => 'required|string',
            'followup_template_id' => 'nullable|integer',
            'context'              => 'nullable|string|max:500',
        ], [
            'template_name.required' => 'El nombre de la plantilla es obligatorio.',
            'template_name.string'   => 'El nombre de la plantilla tiene que ser texto.',
            'content.required'       => 'El contenido renderizado del mensaje es obligatorio.',
            'content.string'         => 'El contenido renderizado tiene que ser texto.',
            'variables.array'        => 'variables tiene que ser un array posicional ({{1}}, {{2}}…).',
            'language_code.string'   => 'language_code tiene que ser texto (ej. es_AR).',
            'followup_template_id.integer' => 'followup_template_id tiene que ser un id numérico.',
        ]);

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
            if (! $incluir_cerrados && in_array($status, self::ESTADOS_CERRADOS, true)) {
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

        /* --- Freno 3: simulación. Es el default, y no toca el sender ni crea ningún LeadMessage. --- */
        $dry_run = $request->filled('dry_run') ? $request->boolean('dry_run') : true;
        if ($dry_run) {
            return response()->json([
                'dry_run'       => true,
                'enviarian'     => $enviarian,
                'omitidos'      => $omitidos,
                'destinatarios' => $destinatarios,
                'nota'          => 'Simulación: no se envió ningún mensaje ni se creó ningún LeadMessage. '
                    . 'Para enviar de verdad, repetí la misma llamada con dry_run=false y confirm_count=' . $enviarian . '.',
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
               request colgado hasta que lo mate PHP (ahí no hay respuesta y nadie sabe dónde quedó). */
            if ($indice > 0 && (microtime(true) - $arranque) >= self::PRESUPUESTO_SEGUNDOS) {
                $abortado     = true;
                $motivo_corte = 'se agotó el presupuesto de ' . self::PRESUPUESTO_SEGUNDOS
                    . ' s del request; los leads no procesados no recibieron nada y se pueden reintentar';
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

            /* Si el PRIMER envío falla con un error que no es transitorio, el problema no es este lead:
               es Meta/Kapso. Repetir 49 veces el mismo timeout no ayuda a nadie y garantiza que el
               request se muera por tiempo. Cortamos y devolvemos los no procesados. */
            if ($indice === 0 && ! $resultado['ok'] && ! $sender->last_send_was_transient()) {
                $abortado     = true;
                $motivo_corte = 'el primer envío falló con un error no transitorio ('
                    . ($resultado['error'] !== null ? $resultado['error'] : self::ERROR_GENERICO)
                    . '); se cortó el lote para no repetir el mismo fallo en el resto';
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
               profundidad para que nada raro se lleve puesto el registro del intento. */
            $error = $e->getMessage();
            Log::channel('daily')->error('ClaudeLeadsOutboundController: excepción al enviar la plantilla.', [
                'lead_id'       => $lead->id,
                'template_name' => $template_name,
                'error'         => $e->getMessage(),
            ]);
        }

        $message = LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'setter',
            'content'               => $content,
            'status'                => 'enviado',
            'whatsapp_message_id'   => $whatsapp_message_id,
            'whatsapp_send_error'   => $error,
            'sent_at'               => AppTime::now(),
            /* 🔴 false a propósito: el envío de Claude no consume el cupo de max_followups del lead.
               followup_template_id se guarda igual, solo para trazabilidad de qué plantilla se usó. */
            'is_followup'           => false,
            'followup_template_id'  => $followup_template_id,
            'requiere_verificacion' => false,
            /* Acá no hay admin: la request entra por claude.task.key, sin sesión de Sanctum. */
            'sent_by_admin_id'      => null,
            'sent_via'              => self::ORIGEN_CLAUDE,
        ]);

        if ($whatsapp_message_id !== null) {
            /* Envío confirmado: avisamos a la SPA para que la conversación se actualice en vivo. */
            LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);
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

        $ids = LeadMessage::query()
            ->whereIn('lead_id', $lead_ids)
            ->where('sent_via', self::ORIGEN_CLAUDE)
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
