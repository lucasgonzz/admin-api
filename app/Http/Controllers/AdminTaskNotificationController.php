<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminTaskNotification;
use App\Services\AdminTaskNotificationService;
use Illuminate\Http\Request;

/**
 * Expone los avisos in-app de tareas (admin_task_notifications) al admin autenticado:
 * listar los pendientes y marcarlos como vistos, individual o todos a la vez.
 */
class AdminTaskNotificationController extends Controller
{
    /**
     * Devuelve los avisos pendientes (seen_at null) del admin autenticado, con su
     * tarea cargada, ordenados por más reciente primero.
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function pending_json(Request $request)
    {
        $admin = $request->user();

        // Solo los avisos de este admin que todavía no vio, con la tarea completa.
        $notifications = AdminTaskNotification::query()
            ->pendingForAdmin($admin->id)
            ->with(['task' => function ($query) {
                $query->withAll();
            }])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['models' => $notifications], 200);
    }

    /**
     * Marca un aviso puntual como visto. Devuelve 404 si no existe o no pertenece
     * al admin autenticado (evita que un admin cierre el aviso de otro).
     *
     * @param  Request $request
     * @param  int     $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function mark_seen_json(Request $request, $id)
    {
        $admin = $request->user();

        $ok = AdminTaskNotificationService::mark_seen((int) $id, (int) $admin->id);

        if (!$ok) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        return response()->json(['message' => 'ok'], 200);
    }

    /**
     * Marca todos los avisos pendientes del admin autenticado como vistos.
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function mark_all_seen_json(Request $request)
    {
        $admin = $request->user();

        // Cantidad de filas actualizadas, para que el frontend confirme cuántas se cerraron.
        $count = AdminTaskNotification::query()
            ->pendingForAdmin($admin->id)
            ->update(['seen_at' => now()]);

        return response()->json(['message' => 'ok', 'count' => $count], 200);
    }
}
