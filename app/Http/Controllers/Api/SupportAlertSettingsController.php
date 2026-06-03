<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuración de alertas de tiempo de respuesta en soporte.
 */
class SupportAlertSettingsController extends Controller
{
    /**
     * Devuelve el umbral en minutos para alertas de demora en respuesta.
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        $value = (int) AdminSetting::get('support_alert_minutes', 30);

        return response()->json(['value' => $value], 200);
    }

    /**
     * Persiste el umbral en minutos (5–1440).
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'value' => 'required|integer|min:5|max:1440',
        ]);

        AdminSetting::set('support_alert_minutes', (string) $validated['value']);

        return response()->json(['value' => (int) $validated['value']], 200);
    }
}
