<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminSetting;
use App\Services\ImplementationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuración del flujo de implementaciones: admin asignado por defecto
 * y tiempo de espera para procesar archivos recibidos (Etapa 4).
 *
 * Expone endpoints para que el panel de Account pueda leer y actualizar
 * los settings de implementación almacenados en admin_settings.
 */
class ImplementationSettingsController extends Controller
{
    /**
     * Retorna el admin actualmente configurado como responsable de implementaciones.
     *
     * Devuelve admin_id (int o null) para pre-seleccionar el valor en el select del frontend.
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        // Leer el ID del admin configurado; convertir a entero o null si no existe.
        $raw_value = AdminSetting::get('implementation_assigned_admin_id');
        $admin_id  = ($raw_value !== null && (int) $raw_value > 0) ? (int) $raw_value : null;

        return response()->json(['admin_id' => $admin_id], 200);
    }

    /**
     * Actualiza el admin asignado por defecto a nuevas implementaciones.
     *
     * Valida que el admin_id exista en la tabla admins antes de persistir.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // El admin_id debe existir en la tabla admins.
            'admin_id' => 'required|integer|exists:admins,id',
        ]);

        // Persistir o actualizar el setting con el nuevo ID de admin.
        AdminSetting::set('implementation_assigned_admin_id', (string) $validated['admin_id']);

        return response()->json(['admin_id' => (int) $validated['admin_id']], 200);
    }

    /**
     * Retorna la cantidad de segundos de espera configurada para procesar archivos
     * recibidos en la Etapa 4 (debounce de múltiples archivos).
     *
     * @return JsonResponse
     */
    public function get_file_wait(): JsonResponse
    {
        // Leer el valor actual del setting; ImplementationSettings aplica el fallback a 15.
        $seconds = ImplementationSettings::get_file_wait_seconds();

        return response()->json(['seconds' => $seconds], 200);
    }

    /**
     * Actualiza la cantidad de segundos de espera antes de procesar archivos
     * recibidos en la Etapa 4.
     *
     * Valida que el valor esté entre 1 y 120 segundos para evitar configuraciones extremas.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update_file_wait(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Mínimo 1 segundo para que el debounce tenga sentido; máximo 120 para no bloquear demasiado.
            'seconds' => 'required|integer|min:1|max:120',
        ]);

        // Persistir o actualizar el setting con el nuevo valor.
        AdminSetting::set('implementation_file_wait_seconds', (string) $validated['seconds']);

        return response()->json(['seconds' => (int) $validated['seconds']], 200);
    }
}
