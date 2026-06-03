<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuración global de sugerencias IA automáticas en soporte WhatsApp.
 */
class SupportAiSettingsController extends Controller
{
    /**
     * Devuelve si las sugerencias automáticas están activas y el delay de envío (segundos).
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        $suggestions_enabled = filter_var(
            AdminSetting::get('support_ai_suggestions_enabled', false),
            FILTER_VALIDATE_BOOLEAN
        );

        $delay_raw = AdminSetting::get('support_ai_auto_send_delay', null);
        $auto_send_delay = 0;
        if ($delay_raw !== null && $delay_raw !== '') {
            $auto_send_delay = (int) $delay_raw;
        }

        return response()->json([
            'suggestions_enabled' => $suggestions_enabled,
            'auto_send_delay'     => $auto_send_delay,
        ], 200);
    }

    /**
     * Persiste activación de sugerencias automáticas y demora antes del envío automático.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'suggestions_enabled' => 'required|boolean',
            'auto_send_delay'     => 'nullable|integer|min:0|max:3600',
        ]);

        AdminSetting::set(
            'support_ai_suggestions_enabled',
            $validated['suggestions_enabled'] ? '1' : '0'
        );

        $delay = $validated['auto_send_delay'] ?? 0;
        AdminSetting::set('support_ai_auto_send_delay', (string) (int) $delay);

        return response()->json([
            'suggestions_enabled' => (bool) $validated['suggestions_enabled'],
            'auto_send_delay'     => (int) $delay,
        ], 200);
    }
}
