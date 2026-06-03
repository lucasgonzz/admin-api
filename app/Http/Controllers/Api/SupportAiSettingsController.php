<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use App\Services\SupportAiSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuración global de sugerencias IA automáticas en soporte WhatsApp.
 */
class SupportAiSettingsController extends Controller
{
    /**
     * Devuelve activación, demora antes de consultar a Claude y demora antes del envío automático.
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        return response()->json(SupportAiSettings::to_array(), 200);
    }

    /**
     * Persiste activación, debounce previo a Claude y demora antes del envío automático.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'suggestions_enabled' => 'required|boolean',
            'suggestion_delay'    => 'nullable|integer|min:'.SupportAiSettings::SUGGESTION_DELAY_MIN_SECONDS.'|max:'.SupportAiSettings::SUGGESTION_DELAY_MAX_SECONDS,
            'auto_send_delay'     => 'nullable|integer|min:'.SupportAiSettings::AUTO_SEND_DELAY_MIN_SECONDS.'|max:'.SupportAiSettings::AUTO_SEND_DELAY_MAX_SECONDS,
        ]);

        AdminSetting::set(
            SupportAiSettings::KEY_SUGGESTIONS_ENABLED,
            $validated['suggestions_enabled'] ? '1' : '0'
        );

        $suggestion_delay = SupportAiSettings::clamp_suggestion_delay(
            (int) ($validated['suggestion_delay'] ?? SupportAiSettings::get_suggestion_delay_seconds())
        );
        AdminSetting::set(
            SupportAiSettings::KEY_SUGGESTION_DELAY_SECONDS,
            (string) $suggestion_delay
        );

        $auto_send_delay = SupportAiSettings::clamp_auto_send_delay(
            (int) ($validated['auto_send_delay'] ?? SupportAiSettings::get_auto_send_delay_seconds())
        );
        AdminSetting::set(
            SupportAiSettings::KEY_AUTO_SEND_DELAY_SECONDS,
            (string) $auto_send_delay
        );

        return response()->json([
            'suggestions_enabled' => (bool) $validated['suggestions_enabled'],
            'suggestion_delay'    => $suggestion_delay,
            'auto_send_delay'     => $auto_send_delay,
        ], 200);
    }
}
