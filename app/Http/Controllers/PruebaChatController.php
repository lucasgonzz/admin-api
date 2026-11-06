<?php

namespace App\Http\Controllers;

use App\Services\DemoChatService;
use Illuminate\Http\Request;

class PruebaChatController extends Controller
{
    public function index(Request $request, DemoChatService $service)
    {
        $lead = $request->attributes->get('prueba_chat_lead');

        return response()->json($service->snapshot($lead, false, $lead->promoted_client_id));
    }

    public function iniciar(Request $request, DemoChatService $service)
    {
        $lead = $request->attributes->get('prueba_chat_lead');
        $service->iniciar($lead, $lead->promoted_client_id);

        return response()->json($service->snapshot($lead, false, $lead->promoted_client_id));
    }

    public function mensaje(Request $request, DemoChatService $service)
    {
        $datos = $request->validate(['contenido' => 'required|string|max:4000', 'cliente_uuid' => 'required|uuid']);
        $lead = $request->attributes->get('prueba_chat_lead');
        $service->recibir($lead, trim($datos['contenido']), $datos['cliente_uuid'], null, $lead->promoted_client_id);

        return response()->json($service->snapshot($lead, false, $lead->promoted_client_id), 202);
    }
}
