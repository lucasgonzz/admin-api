<?php

namespace App\Services;

use App\Events\AdminTaskNotificationCreated;
use App\Models\Admin;
use App\Models\AdminTask;
use App\Models\AdminTaskNotification;
use Illuminate\Support\Facades\Log;

/**
 * Creación y gestión de los avisos in-app de asignación de tareas internas.
 *
 * Complementa (no reemplaza) al Web Push existente: acá se persiste el
 * estado "visto/no visto" por (tarea, admin) y se dispara el broadcast en
 * tiempo real para el admin que esté conectado en ese momento.
 *
 * Uso típico: AdminTaskNotificationService::create_for_task($task);
 */
class AdminTaskNotificationService
{
    /**
     * Crea los avisos de una tarea para sus destinatarios y dispara broadcast + push.
     *
     * Reglas de negocio:
     *  - Si no se pasan $admin_ids explícitos, se toman los admins asignados a la tarea.
     *  - Si la tarea no tiene ningún asignado, se notifica a TODOS los admins (una tarea
     *    sin asignar la puede tomar cualquiera).
     *  - Nunca se notifica al creador de la tarea.
     *  - Es idempotente: llamar dos veces para la misma tarea/admin no duplica filas
     *    (se apoya en firstOrCreate + el unique de la migración).
     *
     * @param  \App\Models\AdminTask $task      Tarea sobre la que se generan los avisos.
     * @param  array|null            $admin_ids Ids de admins a notificar; null = resolver de la tarea.
     * @return array                            Ids de admins efectivamente notificados.
     */
    public static function create_for_task(AdminTask $task, array $admin_ids = null): array
    {
        // Resolver destinatarios: los explícitos si vinieron, o los asignados de la tarea.
        if ($admin_ids === null) {
            $admin_ids = $task->assigned_admins()->pluck('admins.id')->map(function ($id) {
                return (int) $id;
            })->toArray();
        }

        // Tarea sin ningún asignado: el destinatario es "todos los admins", porque
        // cualquiera puede tomarla.
        if (empty($admin_ids)) {
            $admin_ids = Admin::query()->pluck('id')->map(function ($id) {
                return (int) $id;
            })->toArray();
        }

        // Nunca notificar al creador de la tarea.
        $admin_ids = array_values(array_filter($admin_ids, function ($id) use ($task) {
            return (int) $id !== (int) $task->created_by_admin_id;
        }));

        // Si después de excluir al creador no queda nadie, no hay nada para hacer.
        if (empty($admin_ids)) {
            return [];
        }

        // Ids de admins notificados con éxito (para logging del llamador).
        $notified_admin_ids = [];

        foreach ($admin_ids as $admin_id) {
            // firstOrCreate garantiza idempotencia: si ya existe la fila para este
            // (tarea, admin), no se duplica ni se pisa un seen_at ya seteado.
            $notification = AdminTaskNotification::firstOrCreate(
                [
                    'admin_task_id' => $task->id,
                    'admin_id'      => $admin_id,
                ],
                [
                    'seen_at' => null,
                ]
            );

            // Broadcast en tiempo real: si Pusher falla no debe romper la creación
            // de la tarea, solo se loguea. El admin la va a ver igual al recargar
            // porque el estado ya quedó persistido arriba.
            try {
                event(new AdminTaskNotificationCreated($notification->id));
            } catch (\Throwable $e) {
                Log::error('AdminTaskNotificationService: fallo al emitir broadcast.', [
                    'notification_id' => $notification->id,
                    'admin_id'        => $admin_id,
                    'error'           => $e->getMessage(),
                ]);
            }

            // Web Push como refuerzo, también aislado: un fallo acá no debe afectar
            // ni la tarea ni el broadcast ya disparado.
            try {
                AdminPushNotificationService::send_to_admin(
                    $admin_id,
                    'Nueva tarea asignada',
                    $task->title,
                    ['url' => '/tasks']
                );
            } catch (\Throwable $e) {
                Log::error('AdminTaskNotificationService: fallo al enviar Web Push.', [
                    'admin_id' => $admin_id,
                    'task_id'  => $task->id,
                    'error'    => $e->getMessage(),
                ]);
            }

            $notified_admin_ids[] = $admin_id;
        }

        return $notified_admin_ids;
    }

    /**
     * Marca un aviso puntual como visto, solo si pertenece al admin indicado.
     *
     * @param  int $notification_id Id de la notificación a cerrar.
     * @param  int $admin_id        Id del admin autenticado que intenta cerrarla.
     * @return bool                 false si no existe o no le pertenece a ese admin.
     */
    public static function mark_seen(int $notification_id, int $admin_id): bool
    {
        // Buscar la notificación asegurando que sea del admin que la pide: evita que
        // un admin cierre el aviso de otro por id.
        $notification = AdminTaskNotification::query()
            ->where('id', $notification_id)
            ->where('admin_id', $admin_id)
            ->first();

        if (!$notification) {
            return false;
        }

        $notification->seen_at = now();
        $notification->save();

        return true;
    }
}
