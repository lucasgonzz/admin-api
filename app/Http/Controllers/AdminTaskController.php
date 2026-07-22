<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Gestiona las tareas internas del panel administrativo.
 * Expone un CRUD JSON para admin-spa y un endpoint de reordenamiento
 * que permite persistir el orden establecido mediante drag & drop.
 */
class AdminTaskController extends Controller
{
    /**
     * Devuelve todas las tareas ordenadas por sort_order ascendente.
     * Las tareas nuevas siempre se insertan con sort_order 0 (cabeza de la lista).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json()
    {
        // Cargar todas las tareas con sus relaciones de admin creador y asignado.
        $tasks = AdminTask::withAll()->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['models' => $tasks], 200);
    }

    /**
     * Crea una nueva tarea.
     * La nueva tarea se inserta al inicio de la lista (sort_order = 0) desplazando
     * todas las demás hacia abajo para respetar el orden de prioridad.
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request)
    {
        $request->validate([
            'title'                => 'required|string|max:500',
            'content'              => 'nullable|string',
            'assigned_admin_id'    => 'nullable|integer',
            // Asignación múltiple (formato nuevo); convive con assigned_admin_id (legacy)
            // mientras el frontend viejo siga en producción.
            'assigned_admin_ids'   => 'nullable|array',
            'assigned_admin_ids.*' => 'integer',
            'todos'                => 'nullable|array',
            'todos.*.text'         => 'required|string|max:500',
            'todos.*.done'         => 'boolean',
        ]);

        // Obtener el admin autenticado como creador.
        $admin = $request->user();

        // Resolver la lista de admins asignados a partir del request (soporta formato
        // nuevo assigned_admin_ids y legacy assigned_admin_id). Devuelve null si el
        // request no trajo ninguna de las dos claves.
        $assigned_ids = $this->resolve_assigned_admin_ids($request);

        // Si no vino ninguna forma de asignación, aplicar el default de negocio: asignar
        // automáticamente a todos los admins marcados como preselección (is_default_task_assignee).
        if ($assigned_ids === null) {
            $assigned_ids = Admin::where('is_default_task_assignee', true)
                ->pluck('id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->toArray();
        }

        // Columna legacy assigned_admin_id: se mantiene sincronizada a propósito con el
        // primer id de la lista de asignados, para no romper consumidores (ej. el
        // frontend viejo) que todavía la leen directamente en vez de assigned_admins.
        $legacy_assigned_admin_id = count($assigned_ids) > 0 ? $assigned_ids[0] : null;

        // Crear la tarea; sort_order 0 la coloca al inicio.
        $task = AdminTask::create([
            'created_by_admin_id' => $admin->id,
            'assigned_admin_id'   => $legacy_assigned_admin_id,
            'title'               => $request->input('title'),
            'content'             => $request->input('content'),
            'todos'               => $request->input('todos') ?: null,
            'is_done'             => false,
            'sort_order'          => 0,
            // Origen de la tarea: creada manualmente desde el panel de administración.
            'created_via'         => 'admin',
        ]);

        // Sincronizar la pivot de asignación múltiple con los ids resueltos.
        $task->assigned_admins()->sync($assigned_ids);

        // Incrementar sort_order de todas las demás tareas para mantener consistencia.
        AdminTask::where('id', '!=', $task->id)->increment('sort_order');

        // Refrescar para incluir relaciones en la respuesta.
        $task = AdminTask::withAll()->find($task->id);

        return response()->json(['model' => $task], 201);
    }

    /**
     * Actualiza una tarea existente (título, contenido, assignee, todos, is_done).
     *
     * @param  Request $request
     * @param  int     $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_json(Request $request, $id)
    {
        $task = AdminTask::findOrFail($id);

        $request->validate([
            'title'                => 'sometimes|required|string|max:500',
            'content'              => 'nullable|string',
            'assigned_admin_id'    => 'nullable|integer',
            'assigned_admin_ids'   => 'nullable|array',
            'assigned_admin_ids.*' => 'integer',
            'todos'                => 'nullable|array',
            'todos.*.text'         => 'required|string|max:500',
            'todos.*.done'         => 'boolean',
            'is_done'              => 'sometimes|boolean',
        ]);

        // Actualizar solo los campos enviados en la petición. La asignación
        // (assigned_admin_id/assigned_admin_ids) se resuelve aparte más abajo, porque
        // puede venir en dos formatos distintos y solo debe tocarse si vino alguno.
        $fillable = ['title', 'content', 'todos', 'is_done'];
        foreach ($fillable as $field) {
            if ($request->has($field)) {
                $task->$field = $request->input($field);
            }
        }

        // Normalizar todos: si viene null o array vacío, guardar null.
        if ($request->has('todos')) {
            $todos = $request->input('todos');
            $task->todos = (is_array($todos) && count($todos) > 0) ? $todos : null;
        }

        // Trazabilidad de completado: isDirty('is_done') detecta que el valor cambió
        // respecto del que había en base de datos (independiente de a qué cambió),
        // así no se pisan done_at/done_by_admin_id cuando is_done no vino en el request
        // o vino con el mismo valor que ya tenía.
        if ($request->has('is_done') && $task->isDirty('is_done')) {
            if ($task->is_done) {
                // Pasó de pendiente a realizada: registrar quién y cuándo la completó.
                $task->done_at = now();
                $task->done_by_admin_id = $request->user()->id;
            } else {
                // Volvió a quedar pendiente: limpiar la trazabilidad de completado.
                $task->done_at = null;
                $task->done_by_admin_id = null;
            }
        }

        // Resolver asignados: devuelve null si el request no trae ninguna clave de
        // asignación (ej. un update de solo is_done), en cuyo caso no se toca ni la
        // pivot ni la columna legacy para no borrar asignaciones por accidente.
        $assigned_ids = $this->resolve_assigned_admin_ids($request);
        if ($assigned_ids !== null) {
            // Columna legacy sincronizada a propósito con el primer id de la lista.
            $task->assigned_admin_id = count($assigned_ids) > 0 ? $assigned_ids[0] : null;
            $task->assigned_admins()->sync($assigned_ids);
        }

        $task->save();

        // Refrescar para devolver relaciones actualizadas.
        $task = AdminTask::withAll()->find($task->id);

        return response()->json(['model' => $task], 200);
    }

    /**
     * Elimina una tarea por ID.
     *
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy_json($id)
    {
        $task = AdminTask::findOrFail($id);
        $task->delete();

        return response()->json(null, 204);
    }

    /**
     * Reordena las tareas según el array de IDs recibido.
     * Cada ID recibe un sort_order igual a su posición en el array (0, 1, 2...).
     * Solo se reordenan tareas del mismo grupo (pendientes o realizadas) a la vez.
     *
     * @param  Request $request  Espera { ids: [1, 5, 3, ...] }
     * @return \Illuminate\Http\JsonResponse
     */
    public function reorder_json(Request $request)
    {
        $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'integer',
        ]);

        // Asignar sort_order basado en la posición en el array recibido.
        $ids = $request->input('ids');

        DB::transaction(function () use ($ids) {
            foreach ($ids as $position => $task_id) {
                AdminTask::where('id', $task_id)->update(['sort_order' => $position]);
            }
        });

        return response()->json(['message' => 'Orden actualizado.'], 200);
    }

    /**
     * Resuelve la lista de ids de admins asignados a partir del request, soportando
     * tanto el formato nuevo (assigned_admin_ids, array) como el legacy
     * (assigned_admin_id, entero o null) mientras convivan los dos frontends.
     *
     * Distingue explícitamente "el request no trae ninguna forma de asignación"
     * (devuelve null) de "me mandaron una lista vacía" (devuelve []), para que el
     * caller decida si debe aplicar un default de negocio o directamente no tocar
     * la asignación existente.
     *
     * @param  Request $request
     * @return array|null  Array de ints únicos, o null si no vino ninguna clave.
     */
    protected function resolve_assigned_admin_ids(Request $request)
    {
        // Formato nuevo: array de ids.
        if ($request->has('assigned_admin_ids')) {
            $raw_ids = $request->input('assigned_admin_ids');
            if (!is_array($raw_ids)) {
                $raw_ids = [];
            }

            // Descartar valores no numéricos y normalizar a enteros únicos.
            $numeric_ids = array_filter($raw_ids, function ($value) {
                return is_numeric($value);
            });
            $int_ids = array_values(array_unique(array_map(function ($value) {
                return (int) $value;
            }, $numeric_ids)));

            // Descartar ids que no correspondan a un Admin existente.
            if (empty($int_ids)) {
                return [];
            }

            return Admin::whereIn('id', $int_ids)
                ->pluck('id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->toArray();
        }

        // Formato legacy: un único id (o null para "sin asignar").
        if ($request->has('assigned_admin_id')) {
            $legacy_id = $request->input('assigned_admin_id');
            return $legacy_id ? [(int) $legacy_id] : [];
        }

        // No vino ninguna de las dos claves: el caller decide qué hacer.
        return null;
    }
}
