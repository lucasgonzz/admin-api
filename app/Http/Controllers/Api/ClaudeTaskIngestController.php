<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminTask;
use App\Services\AdminTaskNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint de ingesta de tareas creadas por Claude desde la conversación (grupo 180).
 *
 * Protegido por el middleware `claude.task.key` (clave fija en X-Claude-Task-Key,
 * ver ClaudeTaskKey), no por Sanctum: quien llama es un proceso externo (Claude
 * Code) sin sesión de admin. Reutiliza el mismo modelo AdminTask, la pivot de
 * asignación múltiple y el servicio de notificaciones ya construidos en los
 * prompts 01-03 de este grupo.
 */
class ClaudeTaskIngestController extends Controller
{
    /**
     * Devuelve la lista de admins para que Claude pueda resolver a quién asignar
     * una tarea sin adivinar ids. Solo expone campos no sensibles (sin email,
     * sin teléfono).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function admins_json(Request $request)
    {
        $admins = Admin::orderBy('name')
            ->get(['id', 'name', 'es_setter', 'is_default_task_assignee', 'is_closer'])
            ->map(function ($admin) {
                return [
                    'id'                        => $admin->id,
                    'name'                      => $admin->name,
                    'es_setter'                 => (bool) $admin->es_setter,
                    'is_default_task_assignee'  => (bool) $admin->is_default_task_assignee,
                    'is_closer'                 => (bool) $admin->is_closer,
                ];
            })
            ->values();

        return response()->json(['admins' => $admins], 200);
    }

    /**
     * Crea una tarea de admin a partir de una request externa (Claude), con
     * asignación resuelta por id, por nombre (fuzzy) o por el flag "todos los
     * setters", y dispara las notificaciones in-app / Web Push correspondientes.
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request)
    {
        $request->validate([
            'title'                    => 'required|string|max:500',
            'content'                  => 'nullable|string',
            'todos'                    => 'nullable|array',
            'todos.*'                  => 'required|string|max:500',
            'assigned_admin_ids'       => 'nullable|array',
            'assigned_admin_ids.*'     => 'integer',
            'assigned_admin_names'     => 'nullable|array',
            'assigned_admin_names.*'   => 'string',
            'assign_to_setters'        => 'nullable|boolean',
        ]);

        // Resolver la lista final de ids de admins asignados a partir de las tres
        // fuentes posibles (ids, nombres, atajo de setters). Puede devolver una
        // JsonResponse de error 422 si un nombre no matchea o matchea ambiguo:
        // en ese caso se corta acá y no se crea la tarea.
        $resolved = $this->resolve_assigned_admin_ids_or_fail($request);
        if ($resolved instanceof \Illuminate\Http\JsonResponse) {
            return $resolved;
        }
        $assigned_ids = $resolved;

        // Admin que figura como creador de la tarea: config explícita, si no el
        // primer admin marcado como preselección, si no el primer admin de la tabla.
        $creator_admin_id = $this->resolve_creator_admin_id();
        if ($creator_admin_id === null) {
            return response()->json(['error' => 'no hay ningun admin registrado'], 422);
        }

        // Convertir todos (array de strings simples) al formato {text, done}
        // que usa AdminTask.todos, igual que TaskFromTemplatesService::build_todos_from_checklist.
        $todos = $this->build_todos_from_strings($request->input('todos'));

        // Columna legacy assigned_admin_id: se mantiene sincronizada con el primer
        // id de la lista de asignados, mismo criterio que AdminTaskController.
        $legacy_assigned_admin_id = count($assigned_ids) > 0 ? $assigned_ids[0] : null;

        $task = AdminTask::create([
            'created_by_admin_id' => $creator_admin_id,
            'assigned_admin_id'   => $legacy_assigned_admin_id,
            'title'               => $request->input('title'),
            'content'             => $request->input('content'),
            'todos'               => $todos,
            'is_done'             => false,
            'sort_order'          => 0,
            // Origen de la tarea: creada por Claude desde una conversación (fuera del panel).
            'created_via'         => 'claude',
        ]);

        // Sincronizar la pivot de asignación múltiple con los ids resueltos.
        $task->assigned_admins()->sync($assigned_ids);

        // Mantener el mismo orden relativo que el resto de las tareas (la nueva
        // entra en sort_order 0, cabeza de la lista).
        AdminTask::where('id', '!=', $task->id)->increment('sort_order');

        // Crear los avisos in-app (+ broadcast + Web Push). Envuelto en try/catch:
        // un fallo acá no debe impedir que la tarea recién creada se guarde.
        $notified_admin_ids = [];
        try {
            $notified_admin_ids = AdminTaskNotificationService::create_for_task($task);
        } catch (\Throwable $e) {
            Log::error('ClaudeTaskIngestController: fallo al crear notificaciones de tarea nueva.', [
                'task_id' => $task->id,
                'error'   => $e->getMessage(),
            ]);
        }

        // Refrescar para incluir relaciones (creador, asignados) en la respuesta.
        $task = AdminTask::withAll()->find($task->id);

        // Nombres de los admins asignados, para que Claude pueda confirmarle a
        // Lucas en el chat a quién quedó asignada la tarea.
        $assigned_to = Admin::whereIn('id', $assigned_ids)
            ->get(['id', 'name'])
            ->map(function ($admin) {
                return ['id' => $admin->id, 'name' => $admin->name];
            })
            ->values();

        return response()->json([
            'model'               => $task,
            'assigned_to'         => $assigned_to,
            'notified_admin_ids'  => $notified_admin_ids,
        ], 201);
    }

    /**
     * Resuelve la lista final de ids de admins asignados, acumulando de las tres
     * fuentes posibles del request (ids, nombres, atajo "todos los setters") y
     * deduplicando al final.
     *
     * La resolución por nombre nunca adivina: ante 0 o más de 1 coincidencia
     * devuelve directamente una JsonResponse 422 explicando el motivo, en vez de
     * devolver el array de ids.
     *
     * @param  Request $request
     * @return array|\Illuminate\Http\JsonResponse  Array de ints únicos, o una respuesta 422.
     */
    protected function resolve_assigned_admin_ids_or_fail(Request $request)
    {
        $ids = [];

        // 1) Ids explícitos: descartar los que no correspondan a un Admin existente.
        $raw_ids = $request->input('assigned_admin_ids', []);
        if (is_array($raw_ids) && !empty($raw_ids)) {
            $int_ids = array_values(array_unique(array_map(function ($value) {
                return (int) $value;
            }, array_filter($raw_ids, function ($value) {
                return is_numeric($value);
            }))));

            if (!empty($int_ids)) {
                $existing_ids = Admin::whereIn('id', $int_ids)
                    ->pluck('id')
                    ->map(function ($id) {
                        return (int) $id;
                    })
                    ->toArray();
                $ids = array_merge($ids, $existing_ids);
            }
        }

        // 2) Nombres: cada nombre debe resolver a exactamente un admin (LIKE %nombre%,
        // case-insensitive). Ambigüedad o "no encontrado" cortan acá con 422.
        $raw_names = $request->input('assigned_admin_names', []);
        if (is_array($raw_names)) {
            foreach ($raw_names as $name) {
                $name = trim((string) $name);
                if ($name === '') {
                    continue;
                }

                // Comparación case-insensitive por prefijo/contención (LIKE %nombre%),
                // usando LOWER() para no depender del collation de la columna.
                $matches = Admin::whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($name) . '%'])
                    ->get(['id', 'name']);

                if ($matches->count() === 0) {
                    return response()->json([
                        'error'  => 'admin no encontrado',
                        'name'   => $name,
                        'admins' => Admin::orderBy('name')->pluck('name'),
                    ], 422);
                }

                if ($matches->count() > 1) {
                    return response()->json([
                        'error'   => 'nombre ambiguo',
                        'name'    => $name,
                        'matches' => $matches->pluck('name')->values(),
                    ], 422);
                }

                $ids[] = (int) $matches->first()->id;
            }
        }

        // 3) Atajo: asignar a todos los admins marcados como setter.
        if ($request->boolean('assign_to_setters')) {
            $setter_ids = Admin::where('es_setter', true)
                ->pluck('id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->toArray();
            $ids = array_merge($ids, $setter_ids);
        }

        return array_values(array_unique($ids));
    }

    /**
     * Resuelve el admin que figura como creador de las tareas que crea Claude.
     * Orden de prioridad: config explícita (CLAUDE_TASK_INGEST_CREATOR_ADMIN_ID),
     * primer admin con is_default_task_assignee, primer admin de la tabla.
     *
     * @return int|null  Id del admin, o null si no hay ningún admin en la base.
     */
    protected function resolve_creator_admin_id(): ?int
    {
        $configured_id = config('services.claude_task_ingest.default_creator_admin_id');
        if (!empty($configured_id)) {
            $exists = Admin::where('id', (int) $configured_id)->exists();
            if ($exists) {
                return (int) $configured_id;
            }
        }

        $default_assignee = Admin::where('is_default_task_assignee', true)->first(['id']);
        if ($default_assignee !== null) {
            return (int) $default_assignee->id;
        }

        $first_admin = Admin::orderBy('id')->first(['id']);

        return $first_admin !== null ? (int) $first_admin->id : null;
    }

    /**
     * Convierte un array de strings simples (formato en que llegan los todos desde
     * Claude) al formato {text, done} que usa AdminTask.todos, igual que
     * TaskFromTemplatesService::build_todos_from_checklist.
     *
     * @param  array|null $todos Array de strings, o null.
     * @return array|null        Array de {text, done: false}, o null si viene vacío.
     */
    protected function build_todos_from_strings(?array $todos): ?array
    {
        if (empty($todos)) {
            return null;
        }

        $result = [];
        foreach ($todos as $item) {
            $result[] = [
                'text' => (string) $item,
                'done' => false,
            ];
        }

        return $result;
    }
}
