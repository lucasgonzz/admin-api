<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Autenticación del admin SPA vía Sanctum (token personal).
 */
class AuthController extends Controller
{
    /**
     * Valida email/contraseña y devuelve token Bearer + perfil mínimo del admin.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $email = (string) $request->input('email', '');
        $password = (string) $request->input('password', '');
        if ($email === '' || $password === '') {
            return response()->json(['message' => 'Credenciales inválidas.'], 422);
        }
        $admin = Admin::where('email', $email)->first();
        if (! $admin || ! Hash::check($password, $admin->password)) {
            return response()->json(['message' => 'Credenciales inválidas.'], 401);
        }
        // No revocar tokens previos: cada dispositivo conserva su sesión hasta logout manual.
        $token = $admin->createToken('admin-spa')->plainTextToken;

        return response()->json([
            'admin' => $this->admin_for_response($admin),
            'token' => $token,
        ], 200);
    }

    /**
     * Revoca el token actual.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        $user = $request->user();
        if ($user && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        return response()->json(['message' => 'Sesión cerrada.'], 200);
    }

    /**
     * Perfil del admin autenticado (Bearer).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function me(Request $request)
    {
        $admin = $request->user();
        if (! $admin) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        return response()->json(['admin' => $this->admin_for_response($admin)], 200);
    }

    /**
     * Actualiza preferencias del admin autenticado.
     * Soporta: is_default_support_owner e is_default_task_assignee.
     * Si se activa cualquiera de los flags "default único", se desactiva en los demás admins.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_profile(Request $request)
    {
        $admin = $request->user();
        if (! $admin) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $validated = $request->validate([
            'is_default_support_owner'  => 'sometimes|boolean',
            'is_default_task_assignee'  => 'sometimes|boolean',
        ]);

        DB::transaction(function () use ($admin, $validated) {
            // Si se activa como responsable por defecto de soporte, desactivar en los demás.
            if (isset($validated['is_default_support_owner'])) {
                $wants = (bool) $validated['is_default_support_owner'];
                if ($wants) {
                    Admin::where('id', '!=', $admin->id)->update(['is_default_support_owner' => false]);
                }
                $admin->is_default_support_owner = $wants;
            }

            // Si se activa como asignatario por defecto de tareas, desactivar en los demás.
            if (isset($validated['is_default_task_assignee'])) {
                $wants = (bool) $validated['is_default_task_assignee'];
                if ($wants) {
                    Admin::where('id', '!=', $admin->id)->update(['is_default_task_assignee' => false]);
                }
                $admin->is_default_task_assignee = $wants;
            }

            $admin->save();
        });

        $admin->refresh();

        return response()->json(['admin' => $this->admin_for_response($admin)], 200);
    }

    /**
     * Mapea el modelo Admin a la estructura mínima expuesta al frontend.
     *
     * @param  Admin $admin
     * @return array<string, mixed>
     */
    private function admin_for_response(Admin $admin)
    {
        return [
            'id'                        => $admin->id,
            'name'                      => $admin->name,
            'email'                     => $admin->email,
            'is_closer'                 => (bool) ($admin->is_closer ?? false),
            'is_default_support_owner'  => (bool) $admin->is_default_support_owner,
            'is_default_task_assignee'  => (bool) ($admin->is_default_task_assignee ?? false),
        ];
    }
}
