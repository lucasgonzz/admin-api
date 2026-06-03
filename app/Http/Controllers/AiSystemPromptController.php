<?php

namespace App\Http\Controllers;

use App\Models\AiSystemPrompt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API JSON para leer y editar el system prompt activo de Claude.
 */
class AiSystemPromptController extends Controller
{
    /**
     * Devuelve el prompt activo (o null si aún no se sembró la BD).
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $prompt = AiSystemPrompt::obtener_activo();

        return response()->json($prompt);
    }

    /**
     * Actualiza el contenido del prompt activo; crea uno si no existe.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'contenido'   => 'required|string',
            'descripcion' => 'nullable|string|max:255',
        ]);

        $prompt = AiSystemPrompt::obtener_activo();

        if (! $prompt) {
            $prompt = new AiSystemPrompt();
            $prompt->activa = true;
        }

        $prompt->contenido   = $request->input('contenido');
        $prompt->descripcion = $request->input(
            'descripcion',
            $prompt->descripcion ?: 'System prompt principal'
        );
        $prompt->save();

        return response()->json($prompt);
    }
}
