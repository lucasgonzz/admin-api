<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminColumnPreference;
use Illuminate\Http\Request;

/**
 * Persiste preferencias de columnas (orden, ancho, wrap) del admin en el SPA.
 */
class ColumnPreferenceController extends Controller
{
    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, $model)
    {
        $admin = $request->user();
        if (! $admin) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }
        $row = AdminColumnPreference::query()
            ->where('admin_id', $admin->id)
            ->where('model_name', $model)
            ->first();

        return response()->json([
            'model_name' => $model,
            'properties' => $row ? $row->properties : [],
        ], 200);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $model)
    {
        $admin = $request->user();
        if (! $admin) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }
        $row = AdminColumnPreference::query()->firstOrNew([
            'admin_id' => $admin->id,
            'model_name' => (string) $model,
        ]);
        $row->properties = $request->input('properties', []);
        $row->save();

        return response()->json([
            'model_name' => $model,
            'properties' => $row->properties,
        ], 200);
    }
}
