<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportCodexCase;
use App\Models\SupportCodexOperation;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Services\SupportCodexDiagnosticService;
use App\Services\SupportCodexService;
use App\Services\SupportCodexSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Puente del monitor local. Todo acceso conserva el cliente que figura en el ticket. */
class ClaudeSupportCodexController extends Controller
{
    public function settings()
    {
        return response()->json(SupportCodexSettings::to_array());
    }

    public function update_settings(Request $request)
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        return response()->json(SupportCodexSettings::set_enabled((bool) $data['enabled']));
    }

    public function pending(SupportCodexService $service)
    {
        return response()->json($service->pending());
    }

    public function claim(Request $request, $id, SupportCodexService $service)
    {
        $data = $request->validate([
            'worker_id' => 'required|string|max:150',
            'expected_message_id' => 'required|integer|min:1',
            'lease_token' => 'sometimes|string|min:32|max:128',
            'thread_id' => 'sometimes|string|max:150',
        ]);
        return response()->json($service->claim((int) $id, $data));
    }

    public function show($id, SupportCodexService $service)
    {
        return response()->json(['ticket' => $service->context(SupportTicket::findOrFail($id))]);
    }

    /**
     * Los tickets anteriores del cliente del ticket (hasta 5, sin el actual, cualquier estado), con sus últimos
     * 30 mensajes reales y sus adjuntos. Si el contacto es un empleado, solo los de ese empleado.
     *
     * Solo lectura y sin reserva (misión `hitos-de-implementacion-por-soporte`, 26/10/2026): Martín la pide
     * cuando el resumen `previous_tickets` del contexto le dice que hay conversaciones anteriores que le
     * sirven (dónde quedó la implementación, un problema que vuelve). El cliente sale SIEMPRE del ticket, no
     * de un parámetro. 404 si el ticket no existe.
     *
     * @param int|string          $id      Id del ticket actual.
     * @param SupportCodexService $service El servicio del puente.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function history($id, SupportCodexService $service)
    {
        return response()->json($service->history(SupportTicket::findOrFail($id)));
    }

    /**
     * Martín deja asentada una nota en la implementación del cliente del ticket (solo agrega a `data.actions[]` de
     * la etapa; no cambia estados ni manda nada). Exige la reserva vigente del ticket. El cliente y la
     * implementación salen del ticket, nunca del body. Frenos y forma en `SupportCodexService::implementation_note()`.
     *
     * Campo opcional `tipo` (misión `implementacion-agentes-ia`, 28/10/2026): `sistema` (default: sin `tipo` se
     * comporta exactamente como antes) o `agentes`, que deja la nota en la bitácora de la implementación de
     * Agentes de IA abierta del cliente (etapa 1..8). La etapa máxima depende del tipo: 7 del sistema, 8 de agentes.
     *
     * @param Request             $request Body: lease_token, texto (1..1000), etapa (opcional: la actual), tipo (opcional).
     * @param int|string          $id      Id del ticket.
     * @param SupportCodexService $service El servicio del puente.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function implementation_note(Request $request, $id, SupportCodexService $service)
    {
        $es_de_agentes = $request->input('tipo') === SupportCodexService::NOTE_TYPE_AGENTES;
        $data = $request->validate([
            'lease_token' => 'required|string|min:32|max:128',
            'texto' => 'required|string|min:1|max:1000',
            'etapa' => 'sometimes|nullable|integer|between:1,'
                . ($es_de_agentes ? \App\Models\AgentImplementation::LAST_STAGE : \App\Models\Implementation::ULTIMA_ETAPA),
            'tipo' => 'sometimes|nullable|string|in:' . SupportCodexService::NOTE_TYPE_SISTEMA . ',' . SupportCodexService::NOTE_TYPE_AGENTES,
        ]);
        $resultado = $service->implementation_note((int) $id, $data);

        return response()->json($resultado, $resultado['registrada'] ? 201 : 200);
    }

    public function reply(Request $request, $id, SupportCodexService $service)
    {
        $data = $request->validate([
            'lease_token' => 'required|string|min:32|max:128',
            'expected_message_id' => 'required|integer|min:1',
            'operation_id' => 'required|string|max:120|regex:/^[a-zA-Z0-9_-]+$/',
            'action' => 'required|in:responder,solicitar_confirmacion,cerrar,escalar,pedir_revision',
            'body' => 'required|string|max:3500',
            'mission' => 'sometimes|nullable|string|max:30000',
            'review_reason' => 'required_if:action,pedir_revision|nullable|string|max:500',
            'thread_id' => 'sometimes|string|max:150',
            'confirmation_message_id' => 'sometimes|nullable|integer|min:1',
        ]);
        return response()->json($service->reply((int) $id, $data));
    }

    public function release(Request $request, $id, SupportCodexService $service)
    {
        $data = $request->validate([
            'lease_token' => 'required|string|min:32|max:128',
            'thread_id' => 'sometimes|string|max:150',
            'reason' => 'sometimes|nullable|string|max:500',
            'manual_review' => 'sometimes|boolean',
        ]);
        return response()->json($service->release((int) $id, $data));
    }

    public function operation($operation_id, SupportCodexService $service)
    {
        return response()->json($service->operation_result(
            SupportCodexOperation::where('operation_id', $operation_id)->firstOrFail()
        ));
    }

    public function diagnostic(Request $request, $id, SupportCodexService $service, SupportCodexDiagnosticService $diagnostic)
    {
        $data = $request->validate([
            'lease_token' => 'required|string|min:32|max:128',
            'query' => 'required|string|max:1500',
        ]);
        $ticket = SupportTicket::with('client', 'client_employee')->findOrFail($id);
        $service->assert_lease(SupportCodexCase::where('support_ticket_id', $id)->first(), $data['lease_token']);
        abort_unless(SupportCodexSettings::manages($ticket) && $ticket->status === 'open', 409, 'Ticket fuera de atención automática.');
        abort_unless(\App\Services\SistemaQueryService::client_employee_can_query($ticket->client, $ticket->client_employee), 403, 'Este contacto no tiene permiso para consultar datos del negocio.');
        // El ID de cliente/owner/URL no se toma del body: no hay forma de saltar a otra empresa.
        $result = $diagnostic->read($ticket->client, $data['query']);
        if ($result === null) {
            return response()->json(['error' => 'diagnostic_unavailable', 'message' => 'No se pudo consultar el sistema. No se infiere que no tenga datos.'], 502);
        }
        return response()->json(['data' => $result, 'queried_at' => now()->toIso8601String()]);
    }

    public function resume(Request $request, $id, SupportCodexService $service)
    {
        $data = $request->validate([
            'expected_message_id' => 'required|integer|min:1',
            'reason' => 'required|string|max:500',
            'request_confirmation' => 'sometimes|boolean',
            'continue_pending' => 'sometimes|boolean',
        ]);
        return response()->json($service->resume((int) $id, $data));
    }

    public function mission_started(Request $request, $id, SupportCodexService $service)
    {
        $data = $request->validate([
            'mission_thread_id' => 'required|string|max:150|regex:/^[a-zA-Z0-9_-]+$/',
            'mission_hash' => 'required|string|size:64|regex:/^[a-f0-9]{64}$/',
        ]);
        return response()->json($service->mission_started((int) $id, $data));
    }

    public function reconcile(Request $request, $operation_id, SupportCodexService $service)
    {
        $data = $request->validate([
            'outcome' => 'required|in:sent,not_sent',
            'reason' => 'required|string|min:10|max:2000',
            'confirmed_by' => 'required|in:Lucas González',
            'whatsapp_message_id' => 'required_if:outcome,sent|nullable|string|max:255',
            'evidence' => 'required_if:outcome,sent|nullable|string|min:15|max:3000',
        ]);
        return response()->json($service->reconcile((string) $operation_id, $data));
    }

    public function attachment($id, $attachment_id)
    {
        $attachment = SupportMessageAttachment::where('id', $attachment_id)
            ->whereHas('message', function ($query) use ($id) {
                $query->where('support_ticket_id', $id);
            })->firstOrFail();
        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);
        return $disk->download($attachment->path, 'adjunto-' . (int) $attachment->id, [
            'Content-Type' => $attachment->mime ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
