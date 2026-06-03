<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Services\SupportAiSuggestionService;
use Illuminate\Http\JsonResponse;

/**
 * Endpoint de sugerencias IA para operadores de soporte.
 */
class SupportAiSuggestionController extends Controller
{
    /**
     * Genera una respuesta sugerida para el ticket indicado.
     *
     * @param int|string                $ticket_id
     * @param SupportAiSuggestionService $suggestion_service
     *
     * @return JsonResponse
     */
    public function suggest($ticket_id, SupportAiSuggestionService $suggestion_service): JsonResponse
    {
        $ticket = SupportTicket::query()
            ->with('client')
            ->find($ticket_id);

        if ($ticket === null) {
            return response()->json(['message' => 'Ticket no encontrado.'], 404);
        }

        if ($ticket->status !== 'open') {
            return response()->json(['message' => 'El ticket está cerrado.'], 422);
        }

        $result = $suggestion_service->generate($ticket);

        return response()->json($result, 200);
    }
}
