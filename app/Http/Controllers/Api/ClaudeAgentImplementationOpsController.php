<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AgentImplementation;
use App\Models\AgentImplementationEvent;
use App\Models\AgentImplementationStage;
use App\Models\Client;
use App\Services\AgentImplementationService;
use App\Services\AgentImplementationSupportWhatsappService;
use App\Services\ImplementationSupportWhatsappService;
use App\Services\SupportCodexService;
use App\Services\SupportCodexSettings;
use Illuminate\Http\Request;

/**
 * Operar las IMPLEMENTACIONES DE AGENTES DE IA desde `claude/agent-implementations/*` (misión
 * `implementacion-agentes-ia`, 28/10/2026): darlas de alta, registrar cada una de sus ocho etapas, dejar
 * notas en la bitácora, cargar los ids de Kapso y Meta, cerrarlas y mandarle WhatsApps al dueño por su
 * ticket de soporte.
 *
 * 🔴 POR QUÉ EXISTE. Conectar el número de WhatsApp de un cliente a sus Agentes de IA es un proceso de
 * días (guía al dueño, entrenamiento leyendo sus chats, conexión con Meta, plantillas, pago y verificación)
 * que hasta acá no dejaba rastro en el admin: nadie podía decir en qué etapa estaba cada cliente, y Martín
 * (el soporte de Codex) atendía al dueño sin saber que estaba en medio de eso. Con estas rutas la skill
 * `/implementar-agentes` registra cada paso, y Martín lo ve en el contexto del ticket (clave `agentes` de
 * `SupportImplementationContextService`).
 *
 * Reglas del bloque `claude/*` que se aplican acá:
 *  - Lista blanca CERRADA de parámetros: uno desconocido es 422 y no se escribe nada.
 *  - Errores con la forma única del bloque (`{error: ...}`), por los helpers de `RespuestasParaClaude`.
 *  - Solo escribe filas del admin. Lo único que sale para afuera es el WhatsApp de `.../whatsapp`, y sale
 *    con los MISMOS frenos que el de la implementación del sistema (ver `whatsapp_json`).
 *
 * Lo que NO hace: tocar el sistema del cliente (prender la extensión, cargar la conexión de Kapso), ni
 * cambiar el ruteo del webhook (un cliente con esta implementación cae a soporte como cualquiera).
 */
class ClaudeAgentImplementationOpsController extends Controller
{
    use RespuestasParaClaude;

    /** Lista blanca del listado. */
    const PARAMS_INDEX = ['client_id', 'status'];

    /** Lista blanca de la lectura por id (no acepta nada). */
    const PARAMS_SHOW = [];

    /** Lista blanca del alta. */
    const PARAMS_STORE = ['client_id', 'camino'];

    /** Lista blanca del PATCH. */
    const PARAMS_UPDATE = ['camino', 'kapso_customer_id', 'phone_number_id', 'waba_id', 'business_portfolio_id', 'contact_phone', 'status'];

    /** Lista blanca del registro de una etapa. */
    const PARAMS_STAGE = ['status', 'data', 'nota'];

    /** Lista blanca de la bitácora. */
    const PARAMS_EVENT = ['texto', 'etapa', 'kind', 'source'];

    /** Lista blanca del WhatsApp. */
    const PARAMS_WHATSAPP = ['operation_id', 'texto', 'etapa'];

    /** Estados con los que se puede cerrar por PATCH (abrir no: para eso está el alta). */
    const CLOSING_STATUSES = [AgentImplementation::STATUS_COMPLETED, AgentImplementation::STATUS_CANCELLED];

    /** Tope del listado (no pagina: son pocas por cliente y en total). */
    const INDEX_LIMIT = 100;

    /** Patrón de `operation_id`: el mismo alfabeto que el WhatsApp de la implementación del sistema. */
    const OPERATION_ID_PATTERN = '/^[A-Za-z0-9_-]+$/';

    /**
     * Las reglas de negocio de la implementación.
     *
     * @var AgentImplementationService
     */
    private $service;

    /**
     * @param AgentImplementationService $service Las reglas de la implementación de agentes.
     */
    public function __construct(AgentImplementationService $service)
    {
        $this->service = $service;
    }

    /**
     * Mensajes de validación de este controlador: los del trait que usa más `between` y `regex`, que el
     * trait no traduce. 🔴 Una regla sin mensaje en español contesta en inglés, en silencio: si sumás una
     * regla, sumá su mensaje acá.
     *
     * @return array<string, string>
     */
    protected function mensajes_de_validacion()
    {
        return [
            'required' => 'El parámetro :attribute es obligatorio.',
            'array'    => 'El parámetro :attribute tiene que ser un objeto JSON.',
            'integer'  => 'El parámetro :attribute tiene que ser un número entero.',
            'string'   => 'El parámetro :attribute tiene que ser texto.',
            'in'       => 'El parámetro :attribute tiene un valor que no está permitido. Mirá GET claude/catalog para ver los válidos.',
            'min'      => 'El parámetro :attribute está por debajo del mínimo permitido.',
            'max'      => 'El parámetro :attribute supera el máximo permitido.',
            'between'  => 'El parámetro :attribute tiene que estar entre :min y :max.',
            'regex'    => 'El parámetro :attribute no tiene un formato válido.',
        ];
    }

    /* ==============================================================================================
     | GET claude/agent-implementations — listado
     |============================================================================================= */

    /**
     * Las implementaciones de agentes, más nuevas primero, filtradas por cliente y/o estado. Cada una con
     * el estado completo (`impl`). Sin filtros devuelve las últimas 100.
     *
     * @param Request $request Query: client_id?, status?.
     *
     * @return \Illuminate\Http\JsonResponse 200 `{data: [impl]}`.
     */
    public function index_json(Request $request)
    {
        $rejection = $this->reject_extra_params($request, self::PARAMS_INDEX, 'GET claude/agent-implementations');
        if ($rejection !== null) {
            return $rejection;
        }

        $invalid = $this->validar_o_422($request, [
            'client_id' => 'nullable|integer|min:1',
            'status'    => 'nullable|string|in:' . implode(',', AgentImplementation::STATUSES),
        ]);
        if ($invalid !== null) {
            return $invalid;
        }

        $query = AgentImplementation::query()->orderByDesc('id')->limit(self::INDEX_LIMIT);

        $client_id = $this->entero_o_null($request->input('client_id'));
        if ($client_id !== null) {
            $query->where('client_id', $client_id);
        }

        $status = $this->texto_o_null($request->input('status'));
        if ($status !== null) {
            $query->where('status', $status);
        }

        $data = [];
        foreach ($query->get() as $implementation) {
            $data[] = $this->service->to_payload($implementation);
        }

        return response()->json(['data' => $data], 200);
    }

    /* ==============================================================================================
     | GET claude/agent-implementations/{id} — una
     |============================================================================================= */

    /**
     * El estado completo de una implementación.
     *
     * @param Request    $request La request (no acepta parámetros).
     * @param int|string $id      Id de la implementación.
     *
     * @return \Illuminate\Http\JsonResponse 200 `{model: impl}` · 404.
     */
    public function show_json(Request $request, $id)
    {
        $rejection = $this->reject_extra_params($request, self::PARAMS_SHOW, 'GET claude/agent-implementations/{id}');
        if ($rejection !== null) {
            return $rejection;
        }

        $implementation = AgentImplementation::find((int) $id);
        if ($implementation === null) {
            return $this->error_404('no existe la implementación de agentes ' . (int) $id);
        }

        return response()->json(['model' => $this->service->to_payload($implementation)], 200);
    }

    /* ==============================================================================================
     | POST claude/agent-implementations — alta
     |============================================================================================= */

    /**
     * Da de alta la implementación de agentes de un cliente con sus ocho etapas (la 1 en curso).
     *
     * 🔴 Una sola abierta por cliente: si ya tiene una `in_progress`, 409 `{error: 'ya_tiene_una_abierta',
     * id}` y no se crea nada (para empezar de nuevo, se cancela la abierta con PATCH).
     *
     * @param Request $request Body: client_id (req), camino (req: nuevo | propio).
     *
     * @return \Illuminate\Http\JsonResponse 201 `{model}` · 409 · 404 cliente · 422.
     */
    public function store_json(Request $request)
    {
        $rejection = $this->reject_extra_params($request, self::PARAMS_STORE, 'POST claude/agent-implementations');
        if ($rejection !== null) {
            return $rejection;
        }

        $invalid = $this->validar_o_422($request, [
            'client_id' => 'required|integer|min:1',
            'camino'    => 'required|string|in:' . implode(',', AgentImplementation::CAMINOS),
        ]);
        if ($invalid !== null) {
            return $invalid;
        }

        $client = Client::find((int) $request->input('client_id'));
        if ($client === null) {
            return $this->error_404('no existe el cliente ' . (int) $request->input('client_id'));
        }

        $result = $this->service->create($client, (string) $request->input('camino'));

        if (! $result['created']) {
            return response()->json([
                'error' => 'ya_tiene_una_abierta',
                'id'    => (int) $result['existing']->id,
                'ayuda' => 'El cliente ya tiene una implementación de agentes en curso: seguí con esa (GET claude/agent-implementations/'
                    . (int) $result['existing']->id . ') o cancelala con PATCH status=cancelled antes de abrir otra. No se creó nada.',
            ], 409);
        }

        return response()->json(['model' => $this->service->to_payload($result['implementation'])], 201);
    }

    /* ==============================================================================================
     | PATCH claude/agent-implementations/{id} — datos y cierre
     |============================================================================================= */

    /**
     * Cambia el camino, carga los ids de Kapso y Meta y el teléfono de contacto, y/o cierra la implementación
     * (`completed` o `cancelled`). Todo opcional: solo se toca lo que vino (un null explícito borra el valor).
     *
     * `contact_phone` es el teléfono al que `.../whatsapp` le escribe al dueño en vez del de la ficha: en el
     * camino `propio`, cuando el dueño elimina su cuenta para conectar el número del negocio a Meta, ese número
     * deja de tener la app. Acá no se valida contra los contactos del cliente: lo valida el envío (422
     * `telefono_ajeno`), que es donde importa y donde los contactos están al día.
     *
     * 🔴 Una implementación cerrada (`completed` o `cancelled`) no cambia de estado: 409 `implementacion_cerrada`
     * (reabrirla o recerrarla escondería el cierre; para empezar de nuevo está el alta). Mandar el mismo estado
     * que ya tiene es un no-op (un reintento del cierre no falla). Los demás campos se pueden corregir igual.
     *
     * @param Request    $request Body: camino?, kapso_customer_id?, phone_number_id?, waba_id?,
     *                            business_portfolio_id?, contact_phone?, status? (completed | cancelled).
     * @param int|string $id      Id de la implementación.
     *
     * @return \Illuminate\Http\JsonResponse 200 `{model}` · 404 · 409 · 422.
     */
    public function update_json(Request $request, $id)
    {
        $rejection = $this->reject_extra_params($request, self::PARAMS_UPDATE, 'PATCH claude/agent-implementations/{id}');
        if ($rejection !== null) {
            return $rejection;
        }

        $invalid = $this->validar_o_422($request, [
            'camino'                => 'nullable|string|in:' . implode(',', AgentImplementation::CAMINOS),
            'kapso_customer_id'     => 'nullable|string|max:80',
            'phone_number_id'       => 'nullable|string|max:60',
            'waba_id'               => 'nullable|string|max:60',
            'business_portfolio_id' => 'nullable|string|max:60',
            'contact_phone'         => 'nullable|string|max:30',
            'status'                => 'nullable|string|in:' . implode(',', self::CLOSING_STATUSES),
        ]);
        if ($invalid !== null) {
            return $invalid;
        }

        $implementation = AgentImplementation::find((int) $id);
        if ($implementation === null) {
            return $this->error_404('no existe la implementación de agentes ' . (int) $id);
        }

        $status = $this->texto_o_null($request->input('status'));
        if ($status !== null && ! $implementation->is_open() && $status !== (string) $implementation->status) {
            return $this->closed_response($implementation);
        }

        /* Solo las claves que vinieron: `has()` distingue «no mandé el id» de «mandé null para borrarlo». */
        $fields = [];
        foreach (self::PARAMS_UPDATE as $key) {
            if ($request->has($key)) {
                $fields[$key] = $request->input($key);
            }
        }

        return response()->json(['model' => $this->service->to_payload($this->service->update_fields($implementation, $fields))], 200);
    }

    /* ==============================================================================================
     | POST claude/agent-implementations/{id}/stages/{n} — registrar una etapa
     |============================================================================================= */

    /**
     * Registra una etapa: su estado, lo que se suma a su `data` (se MEZCLA con lo que ya tenía) y una nota
     * opcional. Recalcula `current_stage` (la primera que no está completed ni skipped).
     *
     * @param Request    $request Body: status (req), data (objeto, opcional), nota (≤ 1000, opcional).
     * @param int|string $id      Id de la implementación.
     * @param int|string $n       Número de etapa (1 a 8; fuera de rango, 422).
     *
     * @return \Illuminate\Http\JsonResponse 200 `{model}` · 404 · 409 `implementacion_cerrada` · 422.
     */
    public function stage_json(Request $request, $id, $n)
    {
        $rejection = $this->reject_extra_params($request, self::PARAMS_STAGE, 'POST claude/agent-implementations/{id}/stages/{n}');
        if ($rejection !== null) {
            return $rejection;
        }

        $stage_number = (int) $n;
        if ($stage_number < AgentImplementation::FIRST_STAGE || $stage_number > AgentImplementation::LAST_STAGE) {
            return $this->error_422(
                'La etapa ' . $stage_number . ' no existe: las implementaciones de agentes tienen las etapas '
                    . AgentImplementation::FIRST_STAGE . ' a ' . AgentImplementation::LAST_STAGE . '. No se escribió nada.',
                ['motivo' => 'etapa_fuera_de_rango']
            );
        }

        $invalid = $this->validar_o_422($request, [
            'status' => 'required|string|in:' . implode(',', AgentImplementationStage::STATUSES),
            'data'   => 'nullable|array',
            'nota'   => 'nullable|string|max:' . AgentImplementationEvent::MAX_TEXTO,
        ]);
        if ($invalid !== null) {
            return $invalid;
        }

        $data = $request->input('data');
        if (is_array($data) && count($data) > 0) {
            /* `data` es un OBJETO: una lista ([1, 2]) se mezclaría con claves 0, 1... que no significan nada. */
            if (array_keys($data) === range(0, count($data) - 1)) {
                return $this->error_422('El parámetro data tiene que ser un objeto JSON ({"clave": valor}), no una lista. No se escribió nada.', [
                    'motivo' => 'data_no_es_objeto',
                ]);
            }

            /* 🔴 `whatsapp_ops` es la memoria de la idempotencia de los envíos: pisarla dejaría mandar dos veces. */
            if (array_key_exists(AgentImplementationStage::DATA_KEY_WHATSAPP_OPS, $data)) {
                return $this->error_422('La clave whatsapp_ops de data es interna (la escribe POST .../whatsapp) y no se puede mandar. No se escribió nada.', [
                    'motivo' => 'clave_reservada',
                ]);
            }
        }

        $implementation = AgentImplementation::find((int) $id);
        if ($implementation === null) {
            return $this->error_404('no existe la implementación de agentes ' . (int) $id);
        }

        /* 🔴 Cerrada no se registran etapas: cambiaría `current_stage` de algo terminado y Martín leería un
           avance que no fue. Lo que haga falta anotar después del cierre va a la bitácora (`.../events`). */
        if (! $implementation->is_open()) {
            return $this->closed_response($implementation);
        }

        $updated = $this->service->update_stage(
            $implementation,
            $stage_number,
            (string) $request->input('status'),
            is_array($data) ? $data : null,
            $this->texto_o_null($request->input('nota'))
        );

        return response()->json(['model' => $this->service->to_payload($updated)], 200);
    }

    /* ==============================================================================================
     | POST claude/agent-implementations/{id}/events — bitácora
     |============================================================================================= */

    /**
     * Agrega un evento a la bitácora (una nota, un dato, un avance contado con palabras).
     *
     * @param Request    $request Body: texto (req, ≤ 1000), etapa (1 a 8, opcional), kind (default nota),
     *                            source (default claude).
     * @param int|string $id      Id de la implementación.
     *
     * @return \Illuminate\Http\JsonResponse 201 `{event}` · 404 · 422.
     */
    public function event_json(Request $request, $id)
    {
        $rejection = $this->reject_extra_params($request, self::PARAMS_EVENT, 'POST claude/agent-implementations/{id}/events');
        if ($rejection !== null) {
            return $rejection;
        }

        $invalid = $this->validar_o_422($request, [
            'texto'  => 'required|string|max:' . AgentImplementationEvent::MAX_TEXTO,
            'etapa'  => 'nullable|integer|between:' . AgentImplementation::FIRST_STAGE . ',' . AgentImplementation::LAST_STAGE,
            'kind'   => 'nullable|string|in:' . implode(',', AgentImplementationEvent::KINDS),
            'source' => 'nullable|string|in:' . implode(',', AgentImplementationEvent::SOURCES),
        ]);
        if ($invalid !== null) {
            return $invalid;
        }

        $texto = trim((string) $request->input('texto'));
        if ($texto === '') {
            return $this->error_422('El texto no puede estar vacío. No se escribió nada.', ['motivo' => 'texto_vacio']);
        }

        $implementation = AgentImplementation::find((int) $id);
        if ($implementation === null) {
            return $this->error_404('no existe la implementación de agentes ' . (int) $id);
        }

        $event = $this->service->add_event(
            $implementation,
            $texto,
            $this->entero_o_null($request->input('etapa')),
            $this->texto_con_default($request, 'kind', AgentImplementationEvent::KIND_NOTA),
            $this->texto_con_default($request, 'source', AgentImplementationEvent::SOURCE_CLAUDE)
        );

        return response()->json(['event' => $this->service->event_payload($event)], 201);
    }

    /* ==============================================================================================
     | POST claude/agent-implementations/{id}/whatsapp — WhatsApp al dueño por su ticket de soporte
     |============================================================================================= */

    /**
     * Manda un WhatsApp al DUEÑO del cliente (a su `contact_phone` si la implementación lo tiene) por su ticket
     * de soporte (número de Kapso del admin), así la conversación queda donde la lee Martín, y deja en la
     * bitácora un evento `kind: whatsapp` que dice que se mandó, sin el cuerpo (el texto queda en el ticket).
     *
     * 🔴 Mismos frenos que `POST claude/implementations/{id}/whatsapp` (la cuenta la hace el mismo servicio,
     * `ImplementationSupportWhatsappService::plan()`), en este orden; en ninguno se manda nada:
     *   1. Lista blanca y tipos (422). La implementación (404), el cliente (404) y activo (422 `cliente_inactivo`).
     *   2. Circuito de Codex apagado: 422 `codex_apagado` (nadie atendería la respuesta del ticket).
     *   3. El destino: el `contact_phone` de la implementación si está cargado (tiene que ser un contacto del
     *      cliente: si no, 422 `telefono_ajeno`) o, si no, el dueño con un teléfono válido en la ficha (422
     *      `sin_contacto`). Y un admin remitente (422 `sin_admin`).
     *   4. Idempotencia por `operation_id`: misma clave y mismo contenido → el resultado guardado
     *      (`repetida: true`, sin reenviar); otro contenido → 409 `operation_id_reusado`; todavía mandando →
     *      409 `envio_en_curso`.
     *   5. Ventana cerrada y texto de más de 600 → 422 `texto_largo_para_plantilla`. La respuesta del cliente
     *      no caería en el ticket (implementación del sistema en modo auto, tienda en etapa 1...) → 422
     *      `respuesta_al_hilo`. Martín con la reserva vigente → 423 `martin_atendiendo`. Un entrante esperando
     *      a Martín → 423 `martin_pendiente`.
     * Un envío que falla (Kapso, Meta) NO tira: 200 con `enviado: false` y el estado; no deja evento.
     *
     * @param Request    $request Body: operation_id (req), texto (req), etapa (1 a 8, opcional: la actual).
     * @param int|string $id      Id de la implementación.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function whatsapp_json(Request $request, $id)
    {
        /* --- Freno 1: entrada, implementación y cliente. --- */
        $rejection = $this->reject_extra_params($request, self::PARAMS_WHATSAPP, 'POST claude/agent-implementations/{id}/whatsapp');
        if ($rejection !== null) {
            return $rejection;
        }

        $invalid = $this->validar_o_422($request, [
            'operation_id' => 'required|string|min:8|max:80|regex:' . self::OPERATION_ID_PATTERN,
            'texto'        => 'required|string|max:4000',
            'etapa'        => 'nullable|integer|between:' . AgentImplementation::FIRST_STAGE . ',' . AgentImplementation::LAST_STAGE,
        ]);
        if ($invalid !== null) {
            return $invalid;
        }

        $texto = trim((string) $request->input('texto'));
        if ($texto === '') {
            return $this->error_422('El texto no puede estar vacío. No se mandó nada.', ['motivo' => 'texto_vacio']);
        }

        $implementation = AgentImplementation::find((int) $id);
        if ($implementation === null) {
            return $this->error_404('no existe la implementación de agentes ' . (int) $id);
        }

        $client = Client::find((int) $implementation->client_id);
        if ($client === null) {
            return $this->error_404('la implementación de agentes ' . (int) $id . ' apunta a un cliente que no existe');
        }

        if (! $client->is_active) {
            return $this->error_422(
                'El cliente ' . (int) $client->id . ' está dado de baja: el webhook no lo reconoce y su respuesta caería en el pipeline de leads. No se mandó nada.',
                ['motivo' => 'cliente_inactivo', 'client_id' => (int) $client->id]
            );
        }

        /* --- Freno 2: el circuito de Codex. --- */
        if (! SupportCodexSettings::enabled()) {
            return $this->error_422(
                'El soporte de Codex está apagado: lo que conteste el cliente quedaría en el ticket sin que nadie lo atienda. No se mandó nada.',
                [
                    'motivo' => 'codex_apagado',
                    'ayuda'  => 'Prendé el circuito (PUT claude/support-codex/settings) o que lo mande Lucas a mano desde la bandeja de soporte del admin '
                        . '(Soporte → «Nueva conversación por WhatsApp»): así la respuesta del cliente la atiende él.',
                ]
            );
        }

        $operation_id = (string) $request->input('operation_id');
        $etapa        = $this->entero_o_null($request->input('etapa'));
        $etapa        = $etapa === null ? (int) $implementation->current_stage : $etapa;

        /* --- Freno 3: el contacto y el remitente. El dueño por la ficha, salvo que la implementación tenga un
           `contact_phone` (camino `propio`, después de eliminar la cuenta del número del negocio): entonces ese,
           que tiene que ser un contacto del cliente con el MISMO criterio que el webhook (`resolver_contacto` →
           `ClientPhoneDirectory::resolve_for_client`); si no, la respuesta no caería en el ticket. --- */
        $sender   = app(AgentImplementationSupportWhatsappService::class);
        $system   = $sender->system_service();
        $resolved = $system->resolver_contacto($client, $this->texto_o_null($implementation->contact_phone));
        $contact  = $resolved['contacto'];

        if ($contact === null && $resolved['motivo'] === 'telefono_ajeno') {
            return $this->error_422(
                'El contact_phone de la implementación no es un contacto del cliente (ni el dueño, ni un empleado, ni el lead promovido). No se mandó nada.',
                [
                    'motivo' => 'telefono_ajeno',
                    'ayuda'  => 'Cargalo como empleado en la ficha del cliente en el admin (Clientes → el cliente → Empleados) o corregí el contact_phone '
                        . 'con PATCH: si no, el webhook no reconoce la respuesta y cae en el pipeline de leads.',
                ]
            );
        }

        if ($contact === null) {
            return $this->error_422(
                'El cliente no tiene un teléfono válido en la ficha. No se mandó nada.',
                [
                    'motivo' => 'sin_contacto',
                    'ayuda'  => 'Cargá el número del dueño en la ficha del cliente en el admin: si no, el webhook no reconoce la respuesta y cae en el pipeline de leads.',
                ]
            );
        }

        $admin = $this->resolve_sender_admin();
        if ($admin === null) {
            return $this->error_422('No hay ningún admin en la base para figurar como remitente del mensaje. No se mandó nada.', ['motivo' => 'sin_admin']);
        }

        $telefono = (string) $contact['phone'];
        $huella   = $sender->huella($texto, $etapa, $telefono);

        /* --- Freno 4: idempotencia (lectura). Un reintento de algo que ya terminó devuelve lo que pasó. --- */
        $previous = $sender->find_operation($implementation, $operation_id);
        if ($previous !== null) {
            return $this->previous_operation_response($previous, $huella, $operation_id);
        }

        /* --- Freno 5: ventana, adónde cae la respuesta y Martín (la MISMA cuenta que el sistema). --- */
        $plan = $system->plan($client, $contact, $texto, $admin);

        if (in_array('texto_largo_para_plantilla', $plan['impedimentos'], true)) {
            return $this->error_422(
                'La ventana de 24 h está cerrada y el texto tiene ' . $plan['largo_para_plantilla'] . ' caracteres: por plantilla el tope es '
                    . $plan['tope_para_plantilla'] . ' y le llegaría cortado. No se mandó nada.',
                [
                    'motivo'          => 'texto_largo_para_plantilla',
                    'ventana_abierta' => false,
                    'largo'           => $plan['largo_para_plantilla'],
                    'tope'            => $plan['tope_para_plantilla'],
                    'ayuda'           => 'Acortalo (un primer mensaje corto que invite a contestar) o esperá a que el cliente escriba.',
                ]
            );
        }

        if (in_array('respuesta_al_hilo', $plan['impedimentos'], true)) {
            return $this->error_422(
                'Lo que conteste el cliente caería en ' . $plan['respuesta_ira_a'] . ' y no en el ticket: la conversación quedaría partida. No se mandó nada.',
                ['motivo' => 'respuesta_al_hilo', 'respuesta_ira_a' => $plan['respuesta_ira_a']]
            );
        }

        if (in_array('martin_atendiendo', $plan['impedimentos'], true)) {
            return response()->json([
                'error'         => 'Martín está atendiendo ese ticket ahora (reserva vigente hasta ' . $plan['reserva_de_martin_hasta'] . '). No se mandó nada.',
                'motivo'        => 'martin_atendiendo',
                'ticket_id'     => $plan['ticket'] === null ? null : (int) $plan['ticket']->id,
                'reserva_hasta' => $plan['reserva_de_martin_hasta'],
                'ayuda'         => 'Probá de nuevo cuando termine (la reserva dura ' . SupportCodexService::LEASE_MINUTES . ' minutos).',
            ], 423);
        }

        if (in_array('martin_pendiente', $plan['impedimentos'], true)) {
            return response()->json([
                'error'     => 'El cliente escribió y Martín todavía no le contestó (' . $plan['martin_pendiente'] . '): el mensaje taparía ese entrante. No se mandó nada.',
                'motivo'    => 'martin_pendiente',
                'reason'    => $plan['martin_pendiente'],
                'ticket_id' => $plan['ticket'] === null ? null : (int) $plan['ticket']->id,
                'ayuda'     => 'Esperá a que Martín conteste y repetí con la misma operation_id.',
            ], 423);
        }

        /* --- La reserva (con la búsqueda repetida bajo lock), el envío y el registro. --- */
        $reservation = $sender->reserve($implementation, $etapa, $operation_id, $huella);

        if ($reservation['sin_etapa']) {
            return $this->error_422(
                'La implementación de agentes ' . (int) $implementation->id . ' no tiene la etapa ' . $etapa . ' cargada. No se mandó nada.',
                ['motivo' => 'sin_etapa', 'etapa' => $etapa]
            );
        }

        if (! $reservation['reservada']) {
            return $this->previous_operation_response($reservation['existente'], $huella, $operation_id);
        }

        $result                    = $system->enviar($client, $contact, $texto, $admin);
        $result['respuesta_ira_a'] = $plan['respuesta_ira_a'];
        $result['avisos']          = $plan['avisos'];
        $operation                 = $sender->register($implementation, $etapa, $operation_id, $texto, $result);

        return response()->json($this->operation_response($operation, $operation_id, $etapa, false), 200);
    }

    /* ==============================================================================================
     | Helpers
     |============================================================================================= */

    /**
     * La respuesta ante una `operation_id` que ya existe: el resultado guardado si es el mismo contenido y
     * ya terminó; 409 si es otro contenido o si todavía está mandando.
     *
     * @param array{etapa: int, operacion: array<string, mixed>} $previous     La operación encontrada.
     * @param string                                              $huella       La huella del pedido de ahora.
     * @param string                                              $operation_id La clave.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function previous_operation_response(array $previous, $huella, $operation_id)
    {
        $operation = $previous['operacion'];

        if (! isset($operation['huella']) || $operation['huella'] !== $huella) {
            return response()->json([
                'error'        => 'La operation_id ' . $operation_id . ' ya se usó para OTRO mensaje (otro texto, etapa o destinatario). No se mandó nada.',
                'motivo'       => 'operation_id_reusado',
                'operation_id' => (string) $operation_id,
                'ayuda'        => 'Cada mensaje lleva su propia operation_id: generá una nueva.',
            ], 409);
        }

        if (isset($operation['estado']) && $operation['estado'] === ImplementationSupportWhatsappService::ESTADO_ENVIANDO) {
            return response()->json([
                'error'        => 'La operación ' . $operation_id . ' se está mandando (o su proceso se cortó en el medio): todavía no se sabe si salió. No se mandó nada.',
                'motivo'       => 'envio_en_curso',
                'operation_id' => (string) $operation_id,
                'reservada_at' => isset($operation['reservada_at']) ? (string) $operation['reservada_at'] : null,
                'ayuda'        => 'Esperá unos segundos y repetí con la misma operation_id. Si sigue así varios minutos, mirá el ticket del dueño en la bandeja antes de mandar nada con otra clave.',
            ], 409);
        }

        return response()->json($this->operation_response($operation, $operation_id, (int) $previous['etapa'], true), 200);
    }

    /**
     * El cuerpo de la respuesta de un envío (nuevo o repetido): las mismas claves que el WhatsApp de la
     * implementación del sistema, sin `accion` (acá no hay acciones del checklist).
     *
     * @param array<string, mixed> $operation    La operación guardada.
     * @param string               $operation_id La clave.
     * @param int                  $etapa        La etapa donde quedó.
     * @param bool                 $repeated     true = es el resultado guardado de una llamada anterior.
     *
     * @return array<string, mixed>
     */
    protected function operation_response(array $operation, $operation_id, $etapa, $repeated)
    {
        $read = function ($key) use ($operation) {
            return array_key_exists($key, $operation) ? $operation[$key] : null;
        };

        $response = [
            'dry_run'             => false,
            'repetida'            => (bool) $repeated,
            'operation_id'        => (string) $operation_id,
            'etapa'               => (int) $etapa,
            'enviado'             => (bool) $read('enviado'),
            'estado'              => $read('estado'),
            'uso_plantilla'       => $read('uso_plantilla'),
            'ventana_abierta'     => $read('ventana_abierta'),
            'ticket_id'           => $read('ticket_id'),
            'message_id'          => $read('message_id'),
            'whatsapp_message_id' => $read('whatsapp_message_id'),
            'reusado'             => $read('reusado'),
            'error'               => $read('error'),
            'respuesta_ira_a'     => $read('respuesta_ira_a'),
            'avisos'              => is_array($read('avisos')) ? $read('avisos') : [],
            'at'                  => $read('terminada_at'),
        ];

        if (array_key_exists('partes_enviadas', $operation)) {
            $response['partes_enviadas'] = $operation['partes_enviadas'];
            $response['partes_totales']  = $read('partes_totales');
        }

        return $response;
    }

    /**
     * 409 `implementacion_cerrada`: la implementación está `completed` o `cancelled` y no acepta lo que se pidió.
     *
     * @param AgentImplementation $implementation La implementación cerrada.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function closed_response(AgentImplementation $implementation)
    {
        return response()->json([
            'error'  => 'implementacion_cerrada',
            'id'     => (int) $implementation->id,
            'status' => (string) $implementation->status,
            'ayuda'  => 'La implementación de agentes ' . (int) $implementation->id . ' está cerrada (' . $implementation->status . '): no cambia de estado '
                . 'ni registra etapas. Para anotar algo, POST .../events; para empezar de nuevo, POST claude/agent-implementations. No se escribió nada.',
        ], 409);
    }

    /**
     * Freno 1 de todo endpoint: la lista blanca de parámetros (query y cuerpo). Una clave de más es 422 y
     * no se hace nada: un parámetro que el endpoint no entiende suele ser alguien esperando que haga algo
     * que no hace, e ignorarlo en silencio dejaría creer que se aplicó.
     *
     * @param Request            $request  La request.
     * @param array<int, string> $accepted Los parámetros que el endpoint acepta.
     * @param string             $endpoint "MÉTODO claude/..." para el mensaje.
     *
     * @return \Illuminate\Http\JsonResponse|null Null si no hay nada de más.
     */
    protected function reject_extra_params(Request $request, array $accepted, $endpoint)
    {
        $extra = array_values(array_diff(array_keys($request->all()), $accepted));

        if (count($extra) === 0) {
            return null;
        }

        return $this->error_422(
            'Parámetros que ' . $endpoint . ' no acepta: ' . implode(', ', $extra) . '. No se hizo nada.',
            ['parametros_aceptados' => $accepted, 'ayuda' => 'GET claude/catalog describe cada parámetro de cada endpoint.']
        );
    }

    /**
     * El admin que figura como remitente de los WhatsApp (`claude/*` no tiene sesión): el configurado en
     * `services.claude_task_ingest.default_creator_admin_id`, si no el asignado por defecto, si no el
     * primero. Mismo criterio que `ClaudeImplementationOpsController::resolver_creador()`.
     *
     * @return Admin|null
     */
    protected function resolve_sender_admin()
    {
        $configured = config('services.claude_task_ingest.default_creator_admin_id');

        if (! empty($configured)) {
            $admin = Admin::find((int) $configured);
            if ($admin !== null) {
                return $admin;
            }
        }

        $default = Admin::where('is_default_task_assignee', true)->first();
        if ($default !== null) {
            return $default;
        }

        return Admin::orderBy('id')->first();
    }
}
