<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD JSON de variantes de mensajes de onboarding (módulo Agente / A/B testing).
 */
class MessageVariantController extends Controller
{
    /**
     * Lista todas las variantes ordenadas por tipo y slug.
     *
     * @return JsonResponse
     */
    public function index_json(): JsonResponse
    {
        $models = MessageVariant::query()
            ->orderBy('message_type')
            ->orderBy('slug')
            ->get();

        return response()->json(['models' => $models], 200);
    }

    /**
     * Crea una variante nueva de mensaje.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function store_json(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'slug'         => 'required|string|max:60|unique:message_variants,slug',
            'name'         => 'required|string|max:150',
            'message_type' => 'required|string|max:40',
            'body'         => 'required|string',
            'active'       => 'sometimes|boolean',
            'notes'        => 'nullable|string',
        ]);

        $variant = MessageVariant::create([
            'slug'         => trim((string) $validated['slug']),
            'name'         => trim((string) $validated['name']),
            'message_type' => trim((string) $validated['message_type']),
            'body'         => trim((string) $validated['body']),
            'active'       => array_key_exists('active', $validated) ? (bool) $validated['active'] : true,
            'notes'        => isset($validated['notes']) ? trim((string) $validated['notes']) : null,
        ]);

        return response()->json(['model' => $variant], 201);
    }

    /**
     * Actualiza campos editables de una variante existente.
     *
     * @param Request    $request
     * @param int|string $id
     *
     * @return JsonResponse
     */
    public function update_json(Request $request, $id): JsonResponse
    {
        $variant = MessageVariant::findOrFail($id);

        $validated = $request->validate([
            'slug'         => 'sometimes|string|max:60|unique:message_variants,slug,'.$variant->id,
            'name'         => 'sometimes|string|max:150',
            'message_type' => 'sometimes|string|max:40',
            'body'         => 'sometimes|string',
            'active'       => 'sometimes|boolean',
            'notes'        => 'nullable|string',
        ]);

        if (array_key_exists('slug', $validated)) {
            $variant->slug = trim((string) $validated['slug']);
        }
        if (array_key_exists('name', $validated)) {
            $variant->name = trim((string) $validated['name']);
        }
        if (array_key_exists('message_type', $validated)) {
            $variant->message_type = trim((string) $validated['message_type']);
        }
        if (array_key_exists('body', $validated)) {
            $variant->body = trim((string) $validated['body']);
        }
        if (array_key_exists('active', $validated)) {
            $variant->active = (bool) $validated['active'];
        }
        if (array_key_exists('notes', $validated)) {
            $raw_notes = $validated['notes'];
            $variant->notes = ($raw_notes === null || trim((string) $raw_notes) === '')
                ? null
                : trim((string) $raw_notes);
        }

        $variant->save();

        return response()->json(['model' => $variant], 200);
    }

    /**
     * Elimina o archiva una variante según si ya fue enviada a leads.
     *
     * @param int|string $id
     *
     * @return JsonResponse
     */
    public function destroy_json($id): JsonResponse
    {
        $variant = MessageVariant::findOrFail($id);

        if ((int) $variant->sent_count > 0) {
            $variant->active = false;
            $variant->save();

            return response()->json(['archived' => true], 200);
        }

        $variant->delete();

        return response()->json(null, 204);
    }
}
