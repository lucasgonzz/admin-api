<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CotizadorSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Expone GET y PUT para gestionar la configuración del cotizador del sistema desde el admin.
 *
 * Delega toda la lógica de persistencia y defaults a CotizadorSettings, igual que
 * LeadDemoSettingsController con LeadDemoSettings.
 *
 * 🔴 El GET devuelve `mercado_pago_configurado`, que es un booleano derivado de si el access token
 * está cargado en el `.env`. El token NUNCA viaja, ni entero ni parcial ni un prefijo: el front
 * usa ese booleano para deshabilitar el botón con un motivo, en vez de dejar que el usuario
 * descubra el error recién al apretarlo.
 */
class CotizadorSettingsController extends Controller
{
    /**
     * Devuelve la configuración actual del cotizador (precios por defecto, descuento, vencimiento).
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        return response()->json(CotizadorSettings::to_array(), 200);
    }

    /**
     * Persiste los parámetros configurables del cotizador.
     *
     * 🔴 Las cinco claves van `sometimes` y no `required`: el despliegue de la API y el del SPA no
     * son atómicos, así que un front que todavía no manda una clave no tiene que comerse un 422 ni
     * borrar el valor guardado. Mismo criterio que los campos nuevos de LeadDemoSettingsController.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            /* Los tres precios en dólares. `numeric` y no `integer`: un precio puede tener
               centavos. El techo es la guarda contra el dedo pegado — ver MAX_PRECIO. */
            'precio_gestion'          => 'sometimes|numeric|min:'.CotizadorSettings::MIN_PRECIO.'|max:'.CotizadorSettings::MAX_PRECIO,
            'precio_ecommerce'        => 'sometimes|numeric|min:'.CotizadorSettings::MIN_PRECIO.'|max:'.CotizadorSettings::MAX_PRECIO,
            'precio_agentes'          => 'sometimes|numeric|min:'.CotizadorSettings::MIN_PRECIO.'|max:'.CotizadorSettings::MAX_PRECIO,
            /* Descuento por transferencia directa, en porcentaje. Rango propio 0-100: es un
               porcentaje, no un precio, y 0 (sin descuento) es un valor válido. */
            'descuento_transferencia' => 'sometimes|numeric|min:'.CotizadorSettings::MIN_DESCUENTO.'|max:'.CotizadorSettings::MAX_DESCUENTO,
            /* Días de vigencia del link de pago. Mínimo 1: un link que nace vencido no sirve. */
            'link_vence_dias'         => 'sometimes|integer|min:'.CotizadorSettings::MIN_LINK_VENCE_DIAS.'|max:'.CotizadorSettings::MAX_LINK_VENCE_DIAS,
        ]);

        CotizadorSettings::persist_from_request($validated);

        return response()->json(CotizadorSettings::to_array(), 200);
    }
}
