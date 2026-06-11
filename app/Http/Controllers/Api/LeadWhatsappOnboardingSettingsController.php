<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LeadWhatsappOnboardingSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API para editar mensajes automáticos de onboarding WhatsApp de leads (sección Cuenta en admin-spa).
 */
class LeadWhatsappOnboardingSettingsController extends Controller
{
    /**
     * Devuelve plantillas y demora configuradas (con defaults si no hay registro en BD).
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        LeadWhatsappOnboardingSettings::seed_defaults_if_missing();

        return response()->json(LeadWhatsappOnboardingSettings::to_array(), 200);
    }

    /**
     * Persiste plantillas y demora validadas.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'auto_message_with_name'       => 'required|string|min:1|max:4000',
            'auto_message_without_name'    => 'required|string|min:1|max:4000',
            'welcome_message_with_name'    => 'required|string|min:1|max:4000',
            'welcome_message_without_name' => 'required|string|min:1|max:4000',
            'welcome_delay_seconds'        => 'required|integer|min:' . LeadWhatsappOnboardingSettings::DELAY_MIN_SECONDS
                . '|max:' . LeadWhatsappOnboardingSettings::DELAY_MAX_SECONDS,
            'ai_suggestion_delay_seconds'  => 'required|integer|min:' . LeadWhatsappOnboardingSettings::AI_SUGGESTION_DELAY_MIN_SECONDS
                . '|max:' . LeadWhatsappOnboardingSettings::AI_SUGGESTION_DELAY_MAX_SECONDS,
            'ai_suggestion_auto_send_delay_seconds' => 'required|integer|min:'
                . LeadWhatsappOnboardingSettings::AUTO_SEND_DELAY_MIN_SECONDS
                . '|max:' . LeadWhatsappOnboardingSettings::AUTO_SEND_DELAY_MAX_SECONDS,
        ]);

        // La variante con nombre debe incluir el placeholder para personalizar saludos.
        if (strpos($validated['auto_message_with_name'], LeadWhatsappOnboardingSettings::PLACEHOLDER_NOMBRE) === false) {
            return response()->json([
                'message' => 'El mensaje automático con nombre debe incluir el placeholder '
                    . LeadWhatsappOnboardingSettings::PLACEHOLDER_NOMBRE . '.',
            ], 422);
        }

        if (strpos($validated['welcome_message_with_name'], LeadWhatsappOnboardingSettings::PLACEHOLDER_NOMBRE) === false) {
            return response()->json([
                'message' => 'El mensaje de bienvenida con nombre debe incluir el placeholder '
                    . LeadWhatsappOnboardingSettings::PLACEHOLDER_NOMBRE . '.',
            ], 422);
        }

        LeadWhatsappOnboardingSettings::persist_from_request($validated);

        return response()->json(LeadWhatsappOnboardingSettings::to_array(), 200);
    }
}
