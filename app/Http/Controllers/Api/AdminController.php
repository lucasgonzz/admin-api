<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;

/**
 * Expone el listado de admins para ser consumido por admin-spa
 * en selectores de asignación (p. ej. al crear o editar tareas).
 */
class AdminController extends Controller
{
    /**
     * Devuelve todos los admins con los campos mínimos necesarios
     * para poblar selectores de asignación en el frontend.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        // Solo exponer campos necesarios para asignación; nunca credenciales.
        $admins = Admin::orderBy('name')
            ->get(['id', 'name', 'email', 'is_default_task_assignee']);

        return response()->json(['admins' => $admins], 200);
    }
}
