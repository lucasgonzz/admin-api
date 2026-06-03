<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
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
            'title'              => 'required|string|max:500',
            'content'            => 'nullable|string',
            'assigned_admin_id'  => 'nullable|integer',
            'todos'              => 'nullable|array',
            'todos.*.text'       => 'required|string|max:500',
            'todos.*.done'       => 'boolean',
        ]);

        // Obtener el admin autenticado como creador.
        $admin = $request->user();

        // Crear la tarea; sort_order 0 la coloca al inicio.
        $task = AdminTask::create([
            'created_by_admin_id' => $admin->id,
            'assigned_admin_id'   => $request->input('assigned_admin_id'),
            'title'               => $request->input('title'),
            'content'             => $request->input('content'),
            'todos'               => $request->input('todos') ?: null,
            'is_done'             => false,
            'sort_order'          => 0,
        ]);

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
            'title'              => 'sometimes|required|string|max:500',
            'content'            => 'nullable|string',
            'assigned_admin_id'  => 'nullable|integer',
            'todos'              => 'nullable|array',
            'todos.*.text'       => 'required|string|max:500',
            'todos.*.done'       => 'boolean',
            'is_done'            => 'sometimes|boolean',
        ]);

        // Actualizar solo los campos enviados en la petición.
        $fillable = ['title', 'content', 'assigned_admin_id', 'todos', 'is_done'];
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
}
