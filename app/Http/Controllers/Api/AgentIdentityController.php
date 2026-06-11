<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Expone GET y PUT para gestionar la identidad del agente Martín desde el admin.
 *
 * Opera siempre sobre el único registro activo de agent_identities.
 */
class AgentIdentityController extends Controller
{
    /**
     * Devuelve el registro activo con id, name y description.
     * Retorna 404 si no existe ningún registro activo.
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        /* Obtener el registro activo o retornar 404. */
        $identity = AgentIdentity::obtener_activo();

        if (! $identity) {
            return response()->json(['message' => 'No hay identidad de agente configurada.'], 404);
        }

        return response()->json([
            'id'          => $identity->id,
            'name'        => $identity->name,
            'description' => $identity->description,
        ], 200);
    }

    /**
     * Actualiza name y description del registro activo.
     * Retorna 404 si no existe ningún registro activo.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        /* Validar campos requeridos antes de persistir. */
        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'required|string',
        ]);

        /* Obtener el registro activo o retornar 404. */
        $identity = AgentIdentity::obtener_activo();

        if (! $identity) {
            return response()->json(['message' => 'No hay identidad de agente configurada.'], 404);
        }

        $identity->update([
            'name'        => $validated['name'],
            'description' => $validated['description'],
        ]);

        return response()->json([
            'id'          => $identity->id,
            'name'        => $identity->name,
            'description' => $identity->description,
        ], 200);
    }
}
