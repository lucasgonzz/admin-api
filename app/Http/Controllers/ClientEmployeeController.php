<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\BaseController;
use App\Models\Client;
use App\Models\ClientEmployee;
use Illuminate\Http\Request;

/**
 * CRUD JSON de ClientEmployee (incluye alta con temporal_id cuando el Client padre aún no existe).
 */
class ClientEmployeeController extends BaseController
{
    /**
     * Crea un empleado; si model_id es null asigna temporal_id para enlazar al guardar el Client.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request)
    {
        /** ID del Client padre; null si el padre aún no fue persistido. */
        $client_id = $request->input('model_id');

        /** Nombre visible del contacto. */
        $name = trim((string) $request->input('name', ''));
        /** Teléfono WhatsApp del empleado. */
        $phone = trim((string) $request->input('phone', ''));
        /** Notas internas opcionales. */
        $notes = $request->input('notes');

        $client_employee = ClientEmployee::create([
            'client_id'   => $client_id,
            'name'        => $name,
            'phone'       => $phone,
            'notes'       => $notes !== null && $notes !== '' ? (string) $notes : null,
            'temporal_id' => $this->get_temporal_id($request),
        ]);

        return response()->json(['model' => $client_employee->fresh()], 201);
    }

    /**
     * Actualiza un ClientEmployee existente.
     *
     * @param Request $request
     * @param int     $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_json(Request $request, $id)
    {
        $client_employee = ClientEmployee::findOrFail($id);

        if ($request->has('name')) {
            $client_employee->name = trim((string) $request->input('name'));
        }
        if ($request->has('phone')) {
            $client_employee->phone = trim((string) $request->input('phone'));
        }
        if ($request->has('notes')) {
            $notes = $request->input('notes');
            $client_employee->notes = $notes !== null && $notes !== '' ? (string) $notes : null;
        }

        $client_employee->save();

        return response()->json(['model' => $client_employee->fresh()], 200);
    }

    /**
     * Elimina un ClientEmployee por id numérico.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy_json($id)
    {
        $client_employee = ClientEmployee::findOrFail($id);
        $client_employee->delete();

        return response()->json(null, 204);
    }

    /**
     * Crea un empleado para un cliente existente (ruta anidada por id del Client).
     *
     * @param Request $request
     * @param int|string $clientId id numérico del Client padre
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_for_client_json(Request $request, $clientId)
    {
        /** Cliente dueño del empleado, resuelto por id de ruta. */
        $client = $this->find_client_by_id($clientId);

        /** Nombre visible del contacto. */
        $name = trim((string) $request->input('name', ''));
        /** Teléfono WhatsApp del empleado. */
        $phone = trim((string) $request->input('phone', ''));
        /** Notas internas opcionales. */
        $notes = $request->input('notes');

        $client_employee = $client->client_employees()->create([
            'name'  => $name,
            'phone' => $phone,
            'notes' => $notes !== null && $notes !== '' ? (string) $notes : null,
        ]);

        return response()->json(['model' => $client_employee->fresh()], 201);
    }

    /**
     * Actualiza un empleado del cliente (ruta anidada por uuid de Client y ClientEmployee).
     *
     * @param Request $request
     * @param int|string $clientId    id numérico del Client padre
     * @param string     $employeeId  uuid del ClientEmployee
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_for_client_json(Request $request, $clientId, $employeeId)
    {
        /** Cliente y empleado validados por pertenencia. */
        $client = $this->find_client_by_id($clientId);
        $client_employee = $this->find_client_employee_for_client($client, $employeeId);

        if ($request->has('name')) {
            $client_employee->name = trim((string) $request->input('name'));
        }
        if ($request->has('phone')) {
            $client_employee->phone = trim((string) $request->input('phone'));
        }
        if ($request->has('notes')) {
            $notes = $request->input('notes');
            $client_employee->notes = $notes !== null && $notes !== '' ? (string) $notes : null;
        }

        $client_employee->save();

        return response()->json(['model' => $client_employee->fresh()], 200);
    }

    /**
     * Elimina un empleado del cliente (ruta anidada por uuid).
     *
     * @param int|string $clientId    id numérico del Client padre
     * @param string     $employeeId  uuid del ClientEmployee
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy_for_client_json($clientId, $employeeId)
    {
        /** Cliente y empleado validados por pertenencia. */
        $client = $this->find_client_by_id($clientId);
        $client_employee = $this->find_client_employee_for_client($client, $employeeId);

        $client_employee->delete();

        return response()->json(null, 204);
    }

    /**
     * Busca Client por id numérico (coherente con ClientController).
     *
     * @param int|string $id identificador interno del cliente
     *
     * @return Client
     */
    protected function find_client_by_id($id)
    {
        return Client::findOrFail($id);
    }

    /**
     * Busca ClientEmployee por uuid validando que pertenezca al cliente.
     *
     * @param Client $client        cliente dueño
     * @param string $employee_uuid uuid del empleado
     *
     * @return ClientEmployee
     */
    protected function find_client_employee_for_client(Client $client, $employee_uuid)
    {
        return ClientEmployee::where('uuid', $employee_uuid)
            ->where('client_id', $client->id)
            ->firstOrFail();
    }
}
