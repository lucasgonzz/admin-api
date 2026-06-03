<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\AdminTask;
use App\Models\TaskTemplate;
use Illuminate\Support\Facades\Log;

/**
 * Crea AdminTasks automáticamente a partir de las plantillas activas de un proceso.
 *
 * Responsabilidades:
 * - Buscar las plantillas activas del proceso dado, ordenadas por campo `orden`.
 * - Convertir el checklist de la plantilla al formato {text, done} que usa AdminTask.
 * - Resolver el Admin asignado por nombre; si no existe, deja la tarea sin asignar y loguea warning.
 * - Crear la AdminTask con los campos del modelo existente.
 */
class TaskFromTemplatesService
{
    /**
     * Crea todas las AdminTasks correspondientes a las plantillas activas del proceso indicado.
     *
     * @param  string $proceso    Identificador del proceso (ej. 'lead_a_cliente').
     * @param  Admin  $creator    Admin autenticado que dispara el proceso; se registra como creador.
     * @return void
     */
    public function create_from_templates(string $proceso, Admin $creator): void
    {
        // Obtener plantillas activas del proceso en orden ascendente.
        $templates = TaskTemplate::activeForProcess($proceso)->get();

        foreach ($templates as $template) {
            // ID de admin: columna assigned_admin_id o resolución legacy por nombre en asignado_a.
            $assigned_admin_id = $this->resolve_assigned_admin_id($template);

            // Convertir checklist (array de strings) al formato {text, done} de AdminTask.todos.
            $todos = $this->build_todos_from_checklist($template->checklist);

            AdminTask::create([
                // Admin autenticado que disparó el proceso de promoción.
                'created_by_admin_id' => $creator->id,
                // Admin asignado resuelto por nombre; puede ser null si no se encontró.
                'assigned_admin_id'   => $assigned_admin_id,
                // Título de la tarea tal como define la plantilla.
                'title'               => $template->titulo,
                // Descripción / cuerpo de la tarea.
                'content'             => $template->descripcion,
                // Subtareas convertidas al formato de AdminTask; null si el checklist está vacío.
                'todos'               => $todos,
                // Las tareas generadas automáticamente siempre arrancan como pendientes.
                'is_done'             => false,
                // El orden de la plantilla define la posición inicial en la lista de tareas.
                'sort_order'          => $template->orden,
            ]);
        }
    }

    /**
     * Resuelve el ID del admin a asignar desde la plantilla.
     * Prioriza assigned_admin_id; si no hay, intenta por nombre en asignado_a (legacy).
     *
     * @param  TaskTemplate $template
     * @return int|null
     */
    protected function resolve_assigned_admin_id(TaskTemplate $template): ?int
    {
        if ($template->assigned_admin_id) {
            return (int) $template->assigned_admin_id;
        }

        $nombre_admin = trim((string) ($template->asignado_a ?? ''));
        if ($nombre_admin === '') {
            return null;
        }

        // Compatibilidad con plantillas que solo guardaban el nombre del admin.
        $admin = Admin::where('name', $nombre_admin)->first();

        if ($admin === null) {
            Log::warning('TaskFromTemplatesService: admin no encontrado, tarea sin asignar.', [
                'nombre_buscado' => $nombre_admin,
                'template_id'    => $template->id,
            ]);
            return null;
        }

        return $admin->id;
    }

    /**
     * Convierte el checklist de la plantilla (array de strings) al formato
     * requerido por AdminTask.todos: array de objetos {text, done}.
     *
     * @param  array|null $checklist Array de strings de la plantilla.
     * @return array|null            Array de {text, done} o null si el checklist está vacío.
     */
    protected function build_todos_from_checklist(?array $checklist): ?array
    {
        if (empty($checklist)) {
            return null;
        }

        // Mapear cada string a un objeto {text, done: false}.
        $todos = [];
        foreach ($checklist as $item) {
            $todos[] = [
                'text' => (string) $item,
                'done' => false,
            ];
        }

        return $todos;
    }
}
