<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\LeadPipelineStatus;
use App\Models\SupportTicket;
use App\Services\LeadTareaVendedorService;
use App\Services\PruebaDelClienteService;

/**
 * `POST claude/support-codex/tickets/{id}/implementacion-pedida` (misión embudo-juan-pipeline, E2): el dueño de una prueba sin
 * implementación la pide por Soporte.
 *
 *  - El lead del cliente pasa a `implementacion_pedida` (con el evento de estado) y se crea la tarea del embudo para Juan, una sola
 *    vez; la tarjeta vuelve a «Interesados» (lo hace el tablero al cambiar el estado).
 *  - Si el negocio ya la tiene (`mitad`, `completa` o `incluida`): 200 `{estado: 'ya_contratada'}`, sin efectos.
 *  - Si el cliente no salió de un lead: 422 `{estado: 'sin_lead'}`.
 */
class ClaudeImplementacionPedidaController extends Controller
{
    /**
     * Registra el pedido de implementación del cliente del ticket.
     *
     * @param int|string           $id       Id del ticket.
     * @param PruebaDelClienteService $pruebas Para encontrar el lead del cliente.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function pedida_json($id, PruebaDelClienteService $pruebas)
    {
        $ticket = SupportTicket::query()->with('client')->findOrFail((int) $id);
        $client = $ticket->client;

        if (! $client instanceof Client) {
            return response()->json(['estado' => 'sin_lead', 'mensaje' => 'El ticket no tiene un cliente.'], 422);
        }

        if (in_array((string) $client->implementacion_contratada, ['mitad', 'completa', 'incluida'], true)) {
            return response()->json(['estado' => 'ya_contratada', 'mensaje' => 'El negocio ya tiene la implementación incluida o contratada.'], 200);
        }

        $lead = $pruebas->lead_del_cliente($client);
        if ($lead === null) {
            return response()->json(['estado' => 'sin_lead', 'mensaje' => 'No hay un lead de este cliente al que pedirle la implementación.'], 422);
        }

        if ((string) $lead->status !== 'implementacion_pedida') {
            LeadPipelineStatus::ensure_exists('implementacion_pedida');
            $lead->status = 'implementacion_pedida';
            $lead->save();
        }

        LeadTareaVendedorService::crear($lead->fresh(), LeadTareaVendedorService::MOTIVO_IMPLEMENTACION_PEDIDA);

        return response()->json(['estado' => 'ok', 'lead_status' => (string) $lead->fresh()->status], 200);
    }
}
