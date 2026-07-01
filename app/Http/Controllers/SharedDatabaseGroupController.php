<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\SharedDatabaseGroup;
use Illuminate\Http\Request;

/**
 * API JSON para gestionar grupos de base de datos compartida entre clientes.
 */
class SharedDatabaseGroupController extends Controller
{
    /**
     * Lista todos los grupos con sus clientes (id y name).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json()
    {
        $groups = SharedDatabaseGroup::query()
            ->with(['clients' => function ($query) {
                $query->select('id', 'name', 'shared_database_group_id')->orderBy('name');
            }])
            ->orderBy('id')
            ->get();

        $models = [];
        foreach ($groups as $group) {
            $models[] = $this->serialize_group($group);
        }

        return response()->json(['models' => $models], 200);
    }

    /**
     * Crea un grupo de BD compartida.
     *
     * @param Request $request Body: { name }
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request)
    {
        $name = $request->input('name');
        $name = is_string($name) ? trim($name) : null;
        if ($name === '') {
            $name = null;
        }

        $group = SharedDatabaseGroup::create([
            'name' => $name,
        ]);

        $group->load(['clients' => function ($query) {
            $query->select('id', 'name', 'shared_database_group_id')->orderBy('name');
        }]);

        return response()->json(['model' => $this->serialize_group($group)], 201);
    }

    /**
     * Elimina un grupo (los clientes quedan con shared_database_group_id en null por nullOnDelete).
     *
     * @param int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy_json($id)
    {
        $group = SharedDatabaseGroup::findOrFail($id);
        $group->delete();

        return response()->json(null, 204);
    }

    /**
     * Asigna un cliente al grupo indicado en el body ({ shared_database_group_id }).
     *
     * @param Request $request
     * @param int|string $id ID del cliente
     * @return \Illuminate\Http\JsonResponse
     */
    public function assign_client_json(Request $request, $id)
    {
        $client = Client::findOrFail($id);
        $group_id = $request->input('shared_database_group_id');

        if ($group_id === null || $group_id === '') {
            return response()->json(['message' => 'shared_database_group_id es requerido.'], 422);
        }

        SharedDatabaseGroup::findOrFail((int) $group_id);

        $client->update([
            'shared_database_group_id' => (int) $group_id,
        ]);

        return response()->json(['model' => $this->fullModel('client', $client->id)], 200);
    }

    /**
     * Quita al cliente del grupo de BD compartida (pone shared_database_group_id en null).
     *
     * @param int|string $id ID del cliente
     * @return \Illuminate\Http\JsonResponse
     */
    public function remove_client_json($id)
    {
        $client = Client::findOrFail($id);
        $client->update([
            'shared_database_group_id' => null,
        ]);

        return response()->json(['model' => $this->fullModel('client', $client->id)], 200);
    }

    /**
     * Serializa un grupo con clientes reducidos a id y name.
     *
     * @param SharedDatabaseGroup $group
     * @return array<string, mixed>
     */
    protected function serialize_group(SharedDatabaseGroup $group)
    {
        $clients = [];
        foreach ($group->clients as $client) {
            $clients[] = [
                'id'   => (int) $client->id,
                'name' => $client->name,
            ];
        }

        return [
            'id'         => (int) $group->id,
            'name'       => $group->name,
            'created_at' => $group->created_at,
            'updated_at' => $group->updated_at,
            'clients'    => $clients,
        ];
    }
}
