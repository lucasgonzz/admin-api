<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\LeadAtribucionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LeadAtribucionController extends Controller
{
    public function emitir(Request $request, LeadAtribucionService $service)
    {
        // La lista blanca y sus reglas viven en el servicio: la reserva de la landing usa las mismas.
        $reglas = LeadAtribucionService::reglas_de_los_datos();
        // Lista blanca también al nivel superior: nunca se acepta PII ni un lead elegido por el navegador.
        if (array_diff(array_keys($request->all()), array_keys($reglas))) {
            throw ValidationException::withMessages(['payload' => ['Hay campos no admitidos.']]);
        }
        $datos = $request->validate($reglas);
        return response()->json($service->emitir(LeadAtribucionService::sin_vacios($datos)), 201);
    }

    public function detalle($id, LeadAtribucionService $service)
    {
        return response()->json($service->detalle(Lead::query()->findOrFail($id)));
    }

    public function metricas(Request $request, LeadAtribucionService $service)
    {
        $datos = $request->validate([
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ]);
        $desde = Carbon::parse($datos['desde'])->startOfDay();
        $hasta = Carbon::parse($datos['hasta'])->endOfDay();
        if ($desde->diffInDays($hasta) > 366) {
            throw ValidationException::withMessages(['hasta' => ['El período no puede superar 366 días.']]);
        }
        return response()->json($service->metricas($desde->toDateTimeString(), $hasta->toDateTimeString()));
    }
}
