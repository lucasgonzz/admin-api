<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LeadDemoSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Expone GET y PUT para gestionar la configuración de demos desde el admin.
 *
 * Delega toda la lógica de persistencia y defaults a LeadDemoSettings.
 */
class LeadDemoSettingsController extends Controller
{
    /**
     * Devuelve la configuración actual de demos (duración, márgenes, automatizaciones).
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        return response()->json(LeadDemoSettings::to_array(), 200);
    }

    /**
     * Persiste los seis parámetros configurables de demos.
     *
     * Todos los valores son enteros entre 0 y 240 minutos.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        /* Validar que todos los campos sean enteros dentro del rango permitido. */
        $validated = $request->validate([
            'duracion_minutos'           => 'required|integer|min:'.LeadDemoSettings::MIN_MINUTOS.'|max:'.LeadDemoSettings::MAX_MINUTOS,
            'setup_minutos_antes'        => 'required|integer|min:'.LeadDemoSettings::MIN_MINUTOS.'|max:'.LeadDemoSettings::MAX_MINUTOS,
            'gracia_minutos_post'        => 'required|integer|min:'.LeadDemoSettings::MIN_MINUTOS.'|max:'.LeadDemoSettings::MAX_MINUTOS,
            'recordatorio_minutos_antes' => 'required|integer|min:'.LeadDemoSettings::MIN_MINUTOS.'|max:'.LeadDemoSettings::MAX_MINUTOS,
            'check_ingreso_minutos_post' => 'required|integer|min:'.LeadDemoSettings::MIN_MINUTOS.'|max:'.LeadDemoSettings::MAX_MINUTOS,
            'resumen_minutos_antes_fin'  => 'required|integer|min:'.LeadDemoSettings::MIN_MINUTOS.'|max:'.LeadDemoSettings::MAX_MINUTOS,
        ]);

        /* Persistir todos los valores validados. */
        LeadDemoSettings::persist_from_request($validated);

        return response()->json(LeadDemoSettings::to_array(), 200);
    }
}
