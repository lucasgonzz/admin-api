<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\CommonLaravel\BaseController;
use App\Http\Controllers\CommonLaravel\Helpers\ModelPropertiesHelper;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * CRUD de usuarios admin (equipo interno de ComercioCity).
 *
 * Permite al panel admin-spa crear, editar, listar y eliminar usuarios
 * del equipo. Sigue el patrón exacto de DemoController: extiende BaseController
 * y usa ModelPropertiesHelper para el mapeo declarativo de campos.
 */
class AdminUserController extends BaseController
{
    /**
     * Lista todos los admins ordenados por nombre para la grilla de admin-spa.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json(Request $request)
    {
        // Tamaño de página configurable por la grilla; límites de seguridad.
        $per_page = (int) $request->input('per_page', 100);
        if ($per_page < 1) {
            $per_page = 20;
        }
        if ($per_page > 200) {
            $per_page = 200;
        }

        // Query base de admins ordenados por nombre.
        $query = Admin::query()->orderBy('name');

        if ($request->has('page')) {
            $models = $query->paginate($per_page);
        } else {
            $models = $query->get();
        }

        return response()->json(['models' => $models], 200);
    }

    /**
     * Retorna un admin puntual para edición en el modal CRUD.
     *
     * @param int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show_json($id)
    {
        // Modelo completo siguiendo contrato estándar fullModel.
        $model = $this->fullModel('admin_user', $id);
        if (! $model) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        return response()->json(['model' => $model], 200);
    }

    /**
     * Crea un nuevo usuario admin desde admin-spa.
     * Requiere name, email y password.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request)
    {
        // Validación básica de campos obligatorios.
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:admins,email',
            'password' => 'required|string|min:8',
        ]);

        // Atributos según las properties del modelo.
        $attributes = ModelPropertiesHelper::attributes_for_create($request, 'admin_user');

        // Hashear la contraseña antes de persistir; nunca guardar en texto plano.
        $attributes['password'] = Hash::make($request->input('password'));

        // Persistencia del nuevo admin.
        $admin = Admin::create($attributes);

        return response()->json(['model' => $this->fullModel('admin_user', $admin->id)], 201);
    }

    /**
     * Actualiza un admin existente desde admin-spa.
     * La contraseña solo se actualiza si viene en el request.
     *
     * @param Request $request
     * @param int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_json(Request $request, $id)
    {
        // Admin objetivo de la edición.
        $admin = Admin::findOrFail($id);

        // Aplicar seteo base por contrato declarativo de ModelProperties.
        ModelPropertiesHelper::set_from_request($admin, $request, 'admin_user');

        // Si se envía contraseña nueva, hashearla y actualizar.
        if ($request->filled('password')) {
            $admin->password = Hash::make($request->input('password'));
            $admin->save();
        }

        return response()->json(['model' => $this->fullModel('admin_user', $id)], 200);
    }

    /**
     * Elimina un admin por su ID.
     * No se puede eliminar el admin autenticado actualmente.
     *
     * @param Request    $request
     * @param int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy_json(Request $request, $id)
    {
        // Admin objetivo de la eliminación.
        $admin = Admin::findOrFail($id);

        // Protección: no permitir que un admin se elimine a sí mismo.
        if ($request->user() && (int) $request->user()->id === (int) $admin->id) {
            return response()->json(['message' => 'No podés eliminar tu propia cuenta.'], 422);
        }

        $admin->delete();

        return response()->json(null, 204);
    }
}
