<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\ModelPropertiesHelper;
use App\Models\ProtocolEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD JSON del protocolo de ventas (entradas consumidas por Claude).
 */
class ProtocolEntryController extends Controller
{
    /**
     * Listado con paginado opcional (mismo contrato que otros recursos admin-spa).
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json(Request $request)
    {
        $per = (int) $request->input('per_page', 100);
        if ($per < 1) {
            $per = 20;
        }
        if ($per > 200) {
            $per = 200;
        }

        $query = ProtocolEntry::query()->orderBy('id', 'desc');

        if ($request->filled('categoria')) {
            $query->where('categoria', (string) $request->input('categoria'));
        }

        if ($request->has('activa')) {
            $query->where('activa', $request->boolean('activa'));
        }

        if ($request->has('page')) {
            $models = $query->paginate($per);
        } else {
            $models = $query->get();
        }

        return response()->json(['models' => $models], 200);
    }

    /**
     * Detalle de una entrada.
     *
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function show_json($id)
    {
        $model = $this->fullModel('protocol_entry', $id);
        if (! $model) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        return response()->json(['model' => $model], 200);
    }

    /**
     * Alta de entrada de protocolo.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request)
    {
        $attributes = ModelPropertiesHelper::attributes_for_create($request, 'protocol_entry');
        $entry = ProtocolEntry::create($attributes);

        return response()->json(['model' => $this->fullModel('protocol_entry', $entry->id)], 201);
    }

    /**
     * Actualización de entrada existente.
     *
     * @param Request $request
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_json(Request $request, $id)
    {
        $entry = ProtocolEntry::findOrFail($id);
        ModelPropertiesHelper::set_from_request($entry, $request, 'protocol_entry');

        return response()->json(['model' => $this->fullModel('protocol_entry', $id)], 200);
    }

    /**
     * Elimina una entrada.
     *
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy_json($id)
    {
        $entry = ProtocolEntry::findOrFail($id);
        $entry->delete();

        return response()->json(null, 204);
    }

    /**
     * Activa o desactiva una entrada sin abrir el formulario completo de edición.
     *
     * @param Request $request Debe incluir `activa` (boolean).
     * @param int|string $id
     *
     * @return JsonResponse
     */
    public function toggle_activa(Request $request, $id): JsonResponse
    {
        $entry = ProtocolEntry::findOrFail($id);
        $entry->activa = $request->boolean('activa');
        $entry->save();

        return response()->json($entry);
    }
}
