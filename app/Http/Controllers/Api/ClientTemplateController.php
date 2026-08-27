<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientTemplate;
use App\Models\SupportTicket;
use App\Services\SupportTemplateSendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lo que el SPA de soporte necesita de las plantillas de cliente: leerlas y mandarlas.
 *
 * El alta no vive acá: la hace Claude desde afuera con la clave del header
 * (ClaudeClientTemplatesController). Este controlador es el lado Sanctum, el que consume la
 * bandeja.
 *
 * 🔴 Solo plantillas de CLIENTE. Las de lead (`followup_templates`) son otro juego, las levanta el
 * motor de seguimiento automático y no tienen por qué aparecer en un ticket de soporte.
 */
class ClientTemplateController extends Controller
{
    /**
     * Lista las plantillas para el selector, ya ordenadas por grupo.
     *
     * Por defecto vienen solo las activas: el selector no tiene por qué ofrecerle al operador una
     * plantilla que alguien apagó porque Meta la desaprobó. Con `?incluir_inactivas=1` vienen
     * todas, que es lo que necesita cualquier pantalla de administración de las plantillas.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function index_json(Request $request): JsonResponse
    {
        $query = ClientTemplate::query()
            ->orderBy('categoria_orden')
            ->orderBy('categoria')
            ->orderBy('template_name');

        if (! $request->boolean('incluir_inactivas')) {
            $query->where('activa', true);
        }

        return response()->json(['models' => $query->get()], 200);
    }

    /**
     * Manda una plantilla al teléfono del ticket y la deja en el hilo.
     *
     * 🔴 No se bloquea con la ventana de 24hs abierta: una plantilla se puede mandar siempre, y
     * decidir por el operador cuándo le conviene usarla no es tarea de este endpoint.
     *
     * @param Request                    $request
     * @param int|string                 $id           Id del ticket.
     * @param SupportTemplateSendService $send_service Servicio que arma, manda y persiste.
     *
     * @return JsonResponse
     */
    public function send_to_ticket_json(Request $request, $id, SupportTemplateSendService $send_service): JsonResponse
    {
        $request->validate([
            'client_template_id' => 'required|integer|exists:client_templates,id',
            'variables'          => 'nullable|array',
            'variables.*'        => 'nullable|string|max:600',
        ]);

        $ticket = SupportTicket::findOrFail($id);
        $template = ClientTemplate::findOrFail((int) $request->input('client_template_id'));

        // Las cuatro guardas van en este orden porque así se leen: primero si el hilo sigue vivo,
        // después si el canal admite plantillas, después si hay a dónde mandarla, y recién al final
        // si la plantilla elegida sirve.
        if ($ticket->status !== 'open') {
            return response()->json(['error' => 'El ticket está cerrado.'], 422);
        }

        if ($ticket->source !== 'whatsapp') {
            return response()->json([
                'error' => 'Este ticket no es de WhatsApp: las plantillas solo se mandan por ese canal.',
            ], 422);
        }

        if (trim((string) $ticket->whatsapp_phone) === '') {
            return response()->json(['error' => 'El ticket no tiene teléfono cargado.'], 422);
        }

        if ($template->activa === false) {
            return response()->json(['error' => 'Esa plantilla está desactivada.'], 422);
        }

        // Valores de las variables en el orden en que los cargó el operador. Se re-indexa porque
        // un objeto JSON con claves salteadas ("0", "2") desordenaría los {{n}} sin avisar.
        $variables = array_values((array) $request->input('variables', []));

        $resultado = $send_service->enviar($ticket, $template, $variables, $request->user());

        return response()->json([
            'model'    => $resultado['message'],
            'delivery' => $resultado['delivery'],
            'error'    => $resultado['error'],
        ], 201);
    }
}
