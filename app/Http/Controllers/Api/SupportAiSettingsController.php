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
     * Devuelve activación, las dos demoras y el régimen con el que nacen los tickets nuevos.
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        return response()->json(SupportAiSettings::to_array(), 200);
    }

    /**
     * Persiste activación, debounce previo a Claude, demora de envío automático y régimen inicial.
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
            // `nullable` y no `required` a propósito, igual que las dos demoras: un build viejo del
            // SPA, cacheado en el navegador de un operador, todavía no manda este campo. Con
            // `required` no podría guardar ni las demoras; con `nullable` guarda lo suyo y el
            // régimen queda como estaba, en vez de darse vuelta sin que nadie lo haya pedido.
            'require_verification' => 'nullable|boolean',
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

        $require_verification = array_key_exists('require_verification', $validated)
            && $validated['require_verification'] !== null
            ? (bool) $validated['require_verification']
            : SupportAiSettings::new_ticket_requires_verification();

        AdminSetting::set(
            SupportAiSettings::KEY_REQUIRE_VERIFICATION,
            $require_verification ? '1' : '0'
        );

        return response()->json([
            'suggestions_enabled'  => (bool) $validated['suggestions_enabled'],
            'suggestion_delay'     => $suggestion_delay,
            'auto_send_delay'      => $auto_send_delay,
            'require_verification' => $require_verification,
        ], 200);
    }
}
