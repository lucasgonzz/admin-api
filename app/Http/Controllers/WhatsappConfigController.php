<?php

namespace App\Http\Controllers;

use App\Models\WhatsappConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API JSON para leer y editar la configuración activa de WhatsApp (Kapso).
 * Solo accesible para admins autenticados vía Sanctum.
 */
class WhatsappConfigController extends Controller
{
    /**
     * Devuelve la configuración activa de WhatsApp, o null si aún no se configuró.
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        $config = WhatsappConfig::getActive();

        return response()->json($config);
    }

    /**
     * Actualiza la configuración activa; crea el registro si no existe.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kapso_api_key'   => 'required|string|max:255',
            'phone_number_id' => 'required|string|max:255',
            /* `sometimes` y no `required`: el panel viejo no manda este campo, y exigirlo haría
               fallar el guardado de la configuración desde una SPA que todavía no se desplegó.
               Las dos puntas no llegan a producción al mismo tiempo. */
            'waba_id'         => 'sometimes|nullable|string|max:40',
            'webhook_secret'  => 'required|string|max:255',
            'test_mode'       => 'sometimes|boolean',
        ]);

        $config = WhatsappConfig::getActive();

        if (! $config) {
            $config = new WhatsappConfig();
            $config->is_active = true;
        }

        $config->kapso_api_key   = $validated['kapso_api_key'];
        $config->phone_number_id = $validated['phone_number_id'];
        $config->webhook_secret  = $validated['webhook_secret'];
        /* Igual que test_mode: solo se escribe si el panel lo mandó. Una SPA vieja que no conoce el
           campo guardaría `waba_id` en null y dejaría al admin sin poder crear ni consultar
           plantillas, sin que nada lo avise hasta la próxima corrida de templates:sync-meta. */
        if ($request->has('waba_id')) {
            $crudo = trim((string) $request->input('waba_id'));
            $config->waba_id = $crudo !== '' ? $crudo : null;
        }
        // Solo actualizamos test_mode si el panel lo envió, para no resetearlo en updates parciales.
        if ($request->has('test_mode')) {
            $config->test_mode = $request->boolean('test_mode');
        }
        $config->save();

        return response()->json($config);
    }
}
