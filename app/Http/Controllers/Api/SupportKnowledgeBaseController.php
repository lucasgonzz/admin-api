<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportKnowledgeBase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ABM JSON de la base de conocimiento de soporte.
 */
class SupportKnowledgeBaseController extends Controller
{
    /**
     * Lista todas las entradas ordenadas por id descendente.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $models = SupportKnowledgeBase::query()
            ->orderBy('id', 'desc')
            ->get();

        return response()->json(['models' => $models], 200);
    }

    /**
     * Crea una entrada nueva de conocimiento.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'     => 'required|string|max:255',
            'content'   => 'required|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $entry = SupportKnowledgeBase::create([
            'title'     => $validated['title'],
            'content'   => $validated['content'],
            'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true,
        ]);

        return response()->json(['model' => $entry], 201);
    }

    /**
     * Actualiza una entrada existente (incluye toggle is_active).
     *
     * @param Request    $request
     * @param int|string $id
     *
     * @return JsonResponse
     */
    public function update(Request $request, $id): JsonResponse
    {
        $entry = SupportKnowledgeBase::findOrFail($id);

        $validated = $request->validate([
            'title'     => 'sometimes|required|string|max:255',
            'content'   => 'sometimes|required|string',
            'is_active' => 'sometimes|boolean',
        ]);

        if (array_key_exists('title', $validated)) {
            $entry->title = $validated['title'];
        }
        if (array_key_exists('content', $validated)) {
            $entry->content = $validated['content'];
        }
        if (array_key_exists('is_active', $validated)) {
            $entry->is_active = (bool) $validated['is_active'];
        }

        $entry->save();

        return response()->json(['model' => $entry], 200);
    }

    /**
     * Elimina una entrada de la base de conocimiento.
     *
     * @param int|string $id
     *
     * @return JsonResponse
     */
    public function destroy($id): JsonResponse
    {
        $entry = SupportKnowledgeBase::findOrFail($id);
        $entry->delete();

        return response()->json(['ok' => true], 200);
    }
}
