<?php

namespace App\Http\Controllers;

use App\Models\ComerciocityAfipConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API JSON para leer y editar la configuración fiscal (AFIP) propia de
 * ComercioCity. Es una única fila global (no por cliente); la UI de carga
 * vive en admin-spa (prompt 333). Solo accesible para admins autenticados
 * vía Sanctum.
 */
class ComerciocityAfipConfigController extends Controller
{
    /**
     * Devuelve la configuración fiscal de ComercioCity, creándola con
     * valores por defecto si todavía no existe (idempotente).
     *
     * @return JsonResponse
     */
    public function show_json(): JsonResponse
    {
        $config = ComerciocityAfipConfig::current();

        return response()->json($config);
    }

    /**
     * Actualiza la configuración fiscal de ComercioCity (crea la fila si
     * todavía no existe).
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update_json(Request $request): JsonResponse
    {
        // Validación acotada: condición IVA restringida a los dos valores soportados
        // y punto de venta numérico válido para AFIP (mínimo 1).
        $validated = $request->validate([
            'condicion_iva' => 'required|string|in:Monotributista,Responsable inscripto',
            'cuit' => 'nullable|string|max:20',
            'razon_social' => 'nullable|string|max:255',
            'domicilio_comercial' => 'nullable|string|max:255',
            'ingresos_brutos' => 'nullable|string|max:255',
            'punto_venta' => 'nullable|integer|min:1',
            'inicio_actividades' => 'nullable|date',
            'afip_produccion' => 'sometimes|boolean',
        ]);

        $config = ComerciocityAfipConfig::current();

        $config->condicion_iva = $validated['condicion_iva'];
        $config->cuit = $validated['cuit'] ?? null;
        $config->razon_social = $validated['razon_social'] ?? null;
        $config->domicilio_comercial = $validated['domicilio_comercial'] ?? null;
        $config->ingresos_brutos = $validated['ingresos_brutos'] ?? null;
        $config->punto_venta = $validated['punto_venta'] ?? null;
        $config->inicio_actividades = $validated['inicio_actividades'] ?? null;
        // Solo actualizamos afip_produccion si el panel lo envió, para no resetear
        // el ambiente (homologación/producción) en updates parciales.
        if ($request->has('afip_produccion')) {
            $config->afip_produccion = $request->boolean('afip_produccion');
        }
        $config->save();

        return response()->json($config);
    }
}
