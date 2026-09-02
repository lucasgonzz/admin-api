<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadPipelineStatus;
use App\Services\LeadBroadcastService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Escritura del estado del pipeline de un lead desde los endpoints `claude/*`.
 *
 * Por qué existe: hasta ahora el bloque `claude/*` podía LEER todo el pipeline y ENVIAR plantillas,
 * pero no podía mover un lead de estado. Eso dejaba media tarea sin hacer cada vez que se repasan
 * las conversaciones: se detecta que un lead está en `closer_activo` hace dos meses sin gestión, o
 * que se despidió y sigue en `en_pausa`, y no había forma de corregirlo sin entrar a mano al panel.
 *
 * 🔴 Estas rutas tocan leads REALES en producción. Los frenos, en el orden en que corren:
 *   1. El slug destino tiene que existir en el catálogo (`LeadPipelineStatus`). Nada de inventar estados.
 *   2. `cerrado_ganado` NO se puede asignar desde acá, y un lead ganado o ya promovido a cliente NO se
 *      puede mover. Ese tramo es la promoción a Client y tiene su propio flujo con contrato y alta.
 *   3. En el lote, `dry_run` por defecto: devuelve qué cambiaría y nada más.
 *   4. `dry_run=false` exige `confirm_count` exacto y `confirm_token` del conjunto simulado.
 *
 * A diferencia del lote de envío, acá no hay llamada externa ni presupuesto de tiempo: es una
 * escritura local por lead. Por eso el tope por llamada es más alto y no hay `no_procesados`.
 */
class ClaudeLeadsPipelineController extends Controller
{
    /**
     * Tope duro de leads por llamada al lote. Alto a propósito (no hay I/O externo por lead), pero
     * acotado para que un error de armado no barra la tabla entera de una.
     */
    const MAX_BATCH = 200;

    /**
     * Estados que dan la conversación por terminada. Al pasar a uno de estos se apagan los flags de
     * seguimiento, igual que hace LeadFollowupService::pause_lead() en el pase automático a En Pausa.
     */
    const ESTADOS_TERMINALES = ['en_pausa', 'cerrado_perdido'];

    /**
     * Slug que este endpoint no asigna nunca. `cerrado_ganado` cuelga de la promoción a Client
     * (contrato, alta del cliente, `promoted_client_id`): marcarlo a mano acá dejaría un lead
     * "ganado" sin cliente detrás.
     */
    const SLUG_PROHIBIDO = 'cerrado_ganado';

    /**
     * Cambia el estado de un lead. Directo, sin simulación: es un solo lead y se nombra explícito.
     *
     * @param Request    $request Body: status (req), motivo, registrar_evento.
     * @param int|string $id      Lead objetivo.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_status_json(Request $request, $id)
    {
        /* validate() dentro del try para garantizar 422 JSON y no el 302 del redirect web. */
        try {
            $request->validate([
                'status'            => 'required|string|max:80',
                'motivo'            => 'nullable|string|max:300',
                'registrar_evento'  => 'nullable|boolean',
            ], [
                'status.required' => 'status es obligatorio: es el slug del estado destino (ver claude/schema).',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Parámetros inválidos. No se cambió nada.',
                'errors'  => $e->errors(),
            ], 422);
        }

        $slug_destino = LeadPipelineStatus::normalize_slug((string) $request->input('status'));
        $error = $this->validar_slug_destino($slug_destino);
        if ($error !== null) {
            return response()->json($error, 422);
        }

        $lead = Lead::find((int) $id);
        if ($lead === null) {
            return response()->json(['message' => 'No existe el lead ' . (int) $id . '.'], 404);
        }

        $bloqueo = $this->motivo_de_bloqueo($lead);
        if ($bloqueo !== null) {
            return response()->json(['message' => $bloqueo, 'lead_id' => (int) $lead->id], 422);
        }

        $status_anterior = (string) $lead->status;
        if ($status_anterior === $slug_destino) {
            return response()->json([
                'lead_id'    => (int) $lead->id,
                'status'     => $slug_destino,
                'cambio'     => false,
                'nota'       => 'El lead ya estaba en "' . $slug_destino . '": no se escribió nada ni se registró evento.',
            ], 200);
        }

        $registrar = $request->filled('registrar_evento') ? $request->boolean('registrar_evento') : true;
        $this->aplicar_cambio($lead, $slug_destino, (string) $request->input('motivo', ''), $registrar);

        return response()->json([
            'lead_id'          => (int) $lead->id,
            'status_anterior'  => $status_anterior,
            'status'           => $slug_destino,
            'cambio'           => true,
        ], 200);
    }

    /**
     * Cambia el estado de varios leads, cada uno al suyo.
     *
     * `cambios` es una LISTA de objetos {lead_id, status, motivo}, no un mapa: en un mapa
     * lead_id → status el JSON con claves numéricas correlativas se decodifica como lista y las
     * claves se corren, que es exactamente el bug que ya documenta `variables_por_lead` en el lote
     * de envío. Con una lista explícita cada fila nombra a su lead y no hay posiciones que correr.
     *
     * @param Request $request Body: cambios[] (req), dry_run, confirm_count, confirm_token, registrar_evento.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_status_batch_json(Request $request)
    {
        try {
            $request->validate([
                'cambios'            => 'required|array|min:1',
                'cambios.*.lead_id'  => 'required|integer|min:1',
                'cambios.*.status'   => 'required|string|max:80',
                'cambios.*.motivo'   => 'nullable|string|max:300',
                'dry_run'            => 'nullable|boolean',
                'confirm_count'      => 'nullable|integer|min:0',
                'confirm_token'      => 'nullable|string|max:64',
                'registrar_evento'   => 'nullable|boolean',
            ], [
                'cambios.required'          => 'cambios es obligatorio: una lista de {lead_id, status, motivo}.',
                'cambios.array'             => 'cambios tiene que ser una lista de objetos, no un mapa.',
                'cambios.*.lead_id.integer' => 'Cada fila de cambios necesita un lead_id entero.',
                'cambios.*.status.required' => 'Cada fila de cambios necesita el slug del estado destino.',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Parámetros inválidos. No se cambió nada.',
                'errors'  => $e->errors(),
            ], 422);
        }

        $filas = $request->input('cambios', []);

        /* --- Freno 1: tope duro por llamada, antes de tocar la base. --- */
        if (count($filas) > self::MAX_BATCH) {
            return response()->json([
                'message'   => 'El lote no puede superar los ' . self::MAX_BATCH . ' leads por llamada y llegaron '
                    . count($filas) . '. No se cambió nada: partilo en tandas.',
                'max_batch' => self::MAX_BATCH,
                'recibidos' => count($filas),
            ], 422);
        }

        /* --- Freno 2: los slugs destino, todos juntos y antes de resolver ningún lead. Un slug
               inventado aborta el lote entero: es un error de armado, no un lead salteable. --- */
        foreach ($filas as $fila) {
            $slug = LeadPipelineStatus::normalize_slug((string) (isset($fila['status']) ? $fila['status'] : ''));
            $error = $this->validar_slug_destino($slug);
            if ($error !== null) {
                $error['lead_id'] = isset($fila['lead_id']) ? (int) $fila['lead_id'] : null;

                return response()->json($error, 422);
            }
        }

        /* Una sola consulta para todos los leads del lote: nada de N+1. */
        $ids = [];
        foreach ($filas as $fila) {
            $ids[] = (int) $fila['lead_id'];
        }
        $leads = Lead::query()->whereIn('id', array_values(array_unique($ids)))->get()->keyBy('id');

        $cambios = [];
        $omitidos = [];
        $ya_vistos = [];

        foreach ($filas as $fila) {
            $lead_id = (int) $fila['lead_id'];
            $slug = LeadPipelineStatus::normalize_slug((string) $fila['status']);

            /* Un mismo lead nombrado dos veces con destinos distintos es un error de armado que en
               el envío no puede pasar (allá el id es la clave). Se queda el primero y se avisa. */
            if (in_array($lead_id, $ya_vistos, true)) {
                $omitidos[] = ['lead_id' => $lead_id, 'motivo' => 'el lead viene repetido en cambios; vale la primera fila'];
                continue;
            }
            $ya_vistos[] = $lead_id;

            $lead = $leads->get($lead_id);
            if ($lead === null) {
                $omitidos[] = ['lead_id' => $lead_id, 'motivo' => 'no existe el lead'];
                continue;
            }

            $bloqueo = $this->motivo_de_bloqueo($lead);
            if ($bloqueo !== null) {
                $omitidos[] = ['lead_id' => $lead_id, 'motivo' => $bloqueo];
                continue;
            }

            if ((string) $lead->status === $slug) {
                $omitidos[] = ['lead_id' => $lead_id, 'motivo' => 'ya estaba en "' . $slug . '"'];
                continue;
            }

            $cambios[] = [
                'lead_id'         => $lead_id,
                'contact_name'    => $lead->contact_name,
                'status_anterior' => (string) $lead->status,
                'status'          => $slug,
                'motivo'          => isset($fila['motivo']) ? (string) $fila['motivo'] : '',
            ];
        }

        $cambiarian = count($cambios);
        $confirm_token = $this->calcular_confirm_token($cambios);

        /* --- Freno 3: simulación, que es el default. --- */
        $dry_run = $request->filled('dry_run') ? $request->boolean('dry_run') : true;
        if ($dry_run) {
            return response()->json([
                'dry_run'       => true,
                'cambiarian'    => $cambiarian,
                'omitidos'      => $omitidos,
                'cambios'       => $cambios,
                'confirm_token' => $confirm_token,
                'nota'          => 'Simulación: no se escribió ningún lead ni se registró ningún evento. '
                    . 'REVISÁ la lista antes de seguir. Para aplicar de verdad, repetí la misma llamada con '
                    . 'dry_run=false, confirm_count=' . $cambiarian . ' y confirm_token=' . $confirm_token . '.',
            ], 200);
        }

        /* --- Freno 4: confirmación explícita del número exacto y del conjunto exacto. --- */
        if (! $request->filled('confirm_count')) {
            return response()->json([
                'message'    => 'confirm_count es obligatorio cuando dry_run es false. No se cambió nada.',
                'cambiarian' => $cambiarian,
                'omitidos'   => $omitidos,
            ], 422);
        }

        if ((int) $request->input('confirm_count') !== $cambiarian) {
            return response()->json([
                'message'    => 'confirm_count (' . (int) $request->input('confirm_count') . ') no coincide con los '
                    . $cambiarian . ' cambios reales. No se cambió nada: volvé a correr la simulación.',
                'cambiarian' => $cambiarian,
                'omitidos'   => $omitidos,
            ], 422);
        }

        $token_recibido = trim((string) $request->input('confirm_token', ''));
        if ($token_recibido === '') {
            return response()->json([
                'message'       => 'confirm_token es obligatorio cuando dry_run es false. Corré primero la simulación.',
                'confirm_token' => $confirm_token,
            ], 422);
        }

        if (! hash_equals($confirm_token, $token_recibido)) {
            return response()->json([
                'message'                => 'confirm_token no corresponde a este conjunto: la lista de leads o algún '
                    . 'estado destino cambió respecto de la simulación. No se cambió nada.',
                'confirm_token_esperado' => $confirm_token,
            ], 422);
        }

        $registrar = $request->filled('registrar_evento') ? $request->boolean('registrar_evento') : true;

        $resultados = [];
        foreach ($cambios as $cambio) {
            $lead = $leads->get($cambio['lead_id']);
            try {
                $this->aplicar_cambio($lead, $cambio['status'], $cambio['motivo'], $registrar);
                $resultados[] = [
                    'lead_id'         => $cambio['lead_id'],
                    'ok'              => true,
                    'status_anterior' => $cambio['status_anterior'],
                    'status'          => $cambio['status'],
                    'error'           => null,
                ];
            } catch (\Throwable $e) {
                Log::channel('daily')->error('ClaudeLeadsPipelineController: falló el cambio de estado.', [
                    'lead_id' => $cambio['lead_id'],
                    'destino' => $cambio['status'],
                    'error'   => $e->getMessage(),
                ]);
                $resultados[] = [
                    'lead_id'         => $cambio['lead_id'],
                    'ok'              => false,
                    'status_anterior' => $cambio['status_anterior'],
                    'status'          => $cambio['status_anterior'],
                    'error'           => $e->getMessage(),
                ];
            }
        }

        $cambiados = 0;
        foreach ($resultados as $resultado) {
            if ($resultado['ok']) {
                $cambiados++;
            }
        }

        return response()->json([
            'dry_run'    => false,
            'cambiados'  => $cambiados,
            'fallidos'   => count($resultados) - $cambiados,
            'omitidos'   => $omitidos,
            'resultados' => $resultados,
        ], 200);
    }

    /**
     * Valida que el slug destino exista en el catálogo y no sea el prohibido.
     *
     * @param string $slug Ya normalizado.
     *
     * @return array|null Cuerpo del 422, o null si el slug es válido.
     */
    protected function validar_slug_destino(string $slug)
    {
        if ($slug === '') {
            return ['message' => 'El estado destino vino vacío o sin caracteres válidos. No se cambió nada.'];
        }

        if ($slug === self::SLUG_PROHIBIDO) {
            return [
                'message' => 'No se puede asignar "' . self::SLUG_PROHIBIDO . '" desde acá: ese estado cuelga de la '
                    . 'promoción del lead a cliente (contrato y alta), que tiene su propio flujo en el panel. '
                    . 'No se cambió nada.',
            ];
        }

        /* La tabla es la fuente; DEFAULT_STATUSES es el fallback documentado del propio modelo para
           cuando el catálogo está vacío (instalación nueva, tests sin seed). */
        $existe = LeadPipelineStatus::query()->where('slug', $slug)->exists();
        if (! $existe && ! array_key_exists($slug, LeadPipelineStatus::DEFAULT_STATUSES)) {
            return [
                'message'  => 'El estado "' . $slug . '" no existe en el catálogo del pipeline. No se cambió nada.',
                'validos'  => array_keys(LeadPipelineStatus::DEFAULT_STATUSES),
            ];
        }

        return null;
    }

    /**
     * Motivo por el que un lead no se puede mover, o null si se puede.
     *
     * Un lead ya promovido a cliente, o en `cerrado_ganado`, tiene un Client colgando: moverlo de
     * estado desde acá lo dejaría inconsistente con el alta. Se bloquea siempre, sin override.
     *
     * @param Lead $lead
     *
     * @return string|null
     */
    protected function motivo_de_bloqueo(Lead $lead)
    {
        if ($lead->promoted_client_id !== null) {
            return 'el lead ya está promovido a cliente (promoted_client_id=' . (int) $lead->promoted_client_id
                . '); su estado no se toca desde acá';
        }

        if (strtolower((string) $lead->status) === self::SLUG_PROHIBIDO) {
            return 'el lead está en "' . self::SLUG_PROHIBIDO . '"; ese tramo no se mueve desde acá';
        }

        return null;
    }

    /**
     * Escribe el estado nuevo y deja el rastro en la conversación.
     *
     * Espeja lo que hace LeadFollowupService::pause_lead() en el pase automático a En Pausa: al
     * pasar a un estado terminal se apagan los flags de seguimiento, para que el lead no quede
     * pidiendo una acción que ya no corresponde. Y se limpia `pendiente_revision_at` por el mismo
     * motivo por el que lo limpia abrir la conversación en el panel: la acción ya se tomó, la fila
     * no tiene por qué seguir roja para todos.
     *
     * @param Lead   $lead
     * @param string $slug_destino
     * @param string $motivo    Texto libre que se guarda en el evento de la conversación.
     * @param bool   $registrar Si deja el LeadMessage de evento.
     *
     * @return void
     */
    protected function aplicar_cambio(Lead $lead, string $slug_destino, string $motivo, bool $registrar): void
    {
        $status_anterior = (string) $lead->status;

        $lead->status = $slug_destino;

        if (in_array($slug_destino, self::ESTADOS_TERMINALES, true)) {
            $lead->requiere_seguimiento = false;
            $lead->tiene_sugerencia_pendiente = false;
            $lead->tiene_seguimiento_sin_ver = false;
            $lead->pendiente_revision_at = null;
        }

        $lead->save();

        if ($registrar) {
            $texto = 'Estado cambiado de "' . LeadPipelineStatus::label_for($status_anterior)
                . '" a "' . LeadPipelineStatus::label_for($slug_destino) . '" por Claude.';
            if (trim($motivo) !== '') {
                $texto .= ' Motivo: ' . trim($motivo);
            }

            LeadMessage::create([
                'lead_id'               => $lead->id,
                'sender'                => 'sistema',
                'content'               => $texto,
                'status'                => 'enviado',
                'is_followup'           => false,
                /* Evento de estado: no actualiza last_message_at ni genera badge de sin leer,
                   igual que el pase automático a En Pausa. */
                'is_status_event'       => true,
                'requiere_verificacion' => false,
                'sent_via'              => LeadMessage::SENT_VIA_CLAUDE,
            ]);
        }

        /* Que la lista del panel se entere sola: es el mismo evento que emite abrir la conversación. */
        LeadBroadcastService::emit_conversation_updated((int) $lead->id);
    }

    /**
     * Huella determinista del conjunto simulado: ata la confirmación a los leads exactos y a los
     * estados destino exactos que se revisaron. Cambiar un lead, o el destino de uno solo, da otro
     * token. Misma idea (y mismo motivo) que calcular_confirm_token() del lote de envío.
     *
     * @param array $cambios Lista ya resuelta.
     *
     * @return string
     */
    protected function calcular_confirm_token(array $cambios): string
    {
        $partes = [];
        foreach ($cambios as $cambio) {
            $partes[] = (int) $cambio['lead_id'] . ':' . $cambio['status_anterior'] . '>' . $cambio['status'];
        }
        sort($partes);

        return substr(hash('sha256', 'estado|' . implode('|', $partes)), 0, 32);
    }
}
