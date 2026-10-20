<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\DemoChatService;
use Illuminate\Http\Request;

/** Puente privado de la instancia. El token identifica al lead; no se admite un id en el body. */
class DemoChatController extends Controller
{
    private function lead(Request $request): Lead
    {
        $lead = $request->attributes->get('demo_eventos_lead');
        abort_unless($lead instanceof Lead, 401);
        return $lead;
    }

    public function index(Request $request, DemoChatService $service)
    {
        return response()->json($service->snapshot($this->lead($request)));
    }

    public function iniciar(Request $request, DemoChatService $service)
    {
        $lead = $this->lead($request);
        $service->iniciar($lead);
        return response()->json($service->snapshot($lead));
    }

    public function mensaje(Request $request, DemoChatService $service)
    {
        $datos = $request->validate(['contenido' => 'required|string|max:4000', 'cliente_uuid' => 'required|uuid']);
        $lead = $this->lead($request);
        $service->recibir($lead, trim($datos['contenido']), $datos['cliente_uuid']);
        return response()->json($service->snapshot($lead), 202);
    }

    public function progreso(Request $request, DemoChatService $service)
    {
        $datos = $request->validate([
            'clave' => 'required|string|max:50', 'segundos_vistos' => 'present|array|max:600',
            'segundos_vistos.*' => 'integer|min:0|max:7199', 'duracion' => 'sometimes|numeric|min:1|max:7200',
            'terminado' => 'sometimes|boolean',
        ]);
        $lead = $this->lead($request);
        $service->progreso($lead, $datos['clave'], array_map('intval', $datos['segundos_vistos']), (int) ceil($datos['duracion'] ?? 0), false, (bool) ($datos['terminado'] ?? false));
        return response()->json($service->snapshot($lead));
    }

    public function omitir(Request $request, DemoChatService $service)
    {
        $datos = $request->validate(['clave' => 'required|string|max:50']);
        $lead = $this->lead($request);
        $service->progreso($lead, $datos['clave'], [], 0, true);
        return response()->json($service->snapshot($lead));
    }
}
