<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuración del admin asignado por defecto a nuevas implementaciones.
 *
 * Expone dos endpoints para que el panel de Account pueda leer y actualizar
 * el setting 'implementation_assigned_admin_id' en admin_settings.
 */
class ImplementationSettingsController extends Controller
{
    /**
     * Retorna el admin actualmente configurado como responsable de implementaciones.
     *
     * Devuelve admin_id (int o null) y los datos básicos del admin si existe,
     * para pre-seleccionar el valor en el select del frontend.
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
}
