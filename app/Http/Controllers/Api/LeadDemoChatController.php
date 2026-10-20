<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\DemoChatService;
use Illuminate\Http\Request;

/** Controles del operador, detrás de la misma autenticación que el resto del detalle de lead. */
class LeadDemoChatController extends Controller
{
    public function index($id, DemoChatService $service)
    {
        return response()->json($service->snapshot(Lead::findOrFail($id), true));
    }

    public function configurar($id, Request $request, DemoChatService $service)
    {
        $datos = $request->validate(['auto_responder' => 'required_without:espera_segundos|boolean',
            'espera_segundos' => 'required_without:auto_responder|integer|min:0|max:120']);
        $lead = Lead::findOrFail($id);
        $service->configurar($lead, isset($datos['auto_responder']) ? (bool) $datos['auto_responder'] : null,
            isset($datos['espera_segundos']) ? (int) $datos['espera_segundos'] : null);
        return response()->json($service->snapshot($lead, true));
    }

    public function sugerir($id, DemoChatService $service)
    {
        $lead = Lead::findOrFail($id);
        $service->sugerir($lead);
        return response()->json($service->snapshot($lead, true), 202);
    }

    public function crear($id, Request $request, DemoChatService $service)
    {
        return $this->accion($id, null, 'crear', $request, $service);
    }

    public function editar($id, $mensaje, Request $request, DemoChatService $service)
    {
        return $this->accion($id, (int) $mensaje, 'editar', $request, $service);
    }

    public function eliminar($id, $mensaje, Request $request, DemoChatService $service)
    {
        return $this->accion($id, (int) $mensaje, 'eliminar', $request, $service);
    }

    public function enviar($id, $mensaje, Request $request, DemoChatService $service)
    {
        return $this->accion($id, (int) $mensaje, 'enviar', $request, $service);
    }

    private function accion($id, ?int $mensaje, string $accion, Request $request, DemoChatService $service)
    {
        $datos = in_array($accion, ['crear', 'editar'], true) ? $request->validate([
            'contenido' => 'required|string|max:4000', 'videos' => 'sometimes|array|max:2', 'videos.*' => 'string|max:50',
        ]) : [];
        $lead = Lead::findOrFail($id);
        $usuario = $request->user();
        $service->mensaje_admin($lead, $accion, $mensaje, $datos, $usuario ? (int) $usuario->id : null);
        return response()->json($service->snapshot($lead, true));
    }
}
