<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\TaskTemplate;
use Illuminate\Http\Request;

/**
 * Gestiona el ABM de plantillas de tareas automáticas del panel administrativo.
 * Las plantillas definen tareas predefinidas que se crean automáticamente
 * cuando se dispara un proceso interno (ej. 'lead_a_cliente').
 */
class TaskTemplateController extends Controller
{
    /**
     * Devuelve todas las plantillas ordenadas por proceso y luego por orden ascendente.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json()
    {
        // Cargar plantillas con admin asignado para mostrar nombre en el ABM del SPA.
        $templates = TaskTemplate::query()
            ->with(['assigned_admin:id,name'])
            ->orderBy('proceso')
            ->orderBy('orden')
            ->get();

        return response()->json(['models' => $templates], 200);
    }

    /**
     * Crea una nueva plantilla de tarea.
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request)
    {
        $request->validate([
            'proceso'     => 'required|string|max:100',
            'titulo'      => 'required|string|max:500',
            'descripcion' => 'nullable|string',
            'checklist'   => 'nullable|array',
            'checklist.*' => 'required|string|max:500',
            'assigned_admin_id' => 'nullable|integer',
            'prioridad'         => 'nullable|integer|min:0',
            'orden'             => 'nullable|integer|min:0',
            'activa'            => 'nullable|boolean',
        ]);

        // Crear plantilla con los datos recibidos; asignación por ID de admin.
        $assignment = $this->build_assignment_attributes($request);
        $template = TaskTemplate::create(array_merge([
            'proceso'     => $request->input('proceso'),
            'titulo'      => $request->input('titulo'),
            'descripcion' => $request->input('descripcion'),
            'checklist'   => $request->input('checklist') ?: null,
            'prioridad'   => $request->input('prioridad', 0),
            'orden'       => $request->input('orden', 0),
            'activa'      => $request->boolean('activa', true),
        ], $assignment));

        $template->load('assigned_admin:id,name');

        return response()->json(['model' => $template], 201);
    }

    /**
     * Actualiza una plantilla existente por ID.
     *
     * @param  Request $request
     * @param  int     $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_json(Request $request, $id)
    {
        $template = TaskTemplate::findOrFail($id);

        $request->validate([
            'proceso'     => 'sometimes|required|string|max:100',
            'titulo'      => 'sometimes|required|string|max:500',
            'descripcion' => 'nullable|string',
            'checklist'   => 'nullable|array',
            'checklist.*' => 'required|string|max:500',
            'assigned_admin_id' => 'nullable|integer',
            'prioridad'         => 'nullable|integer|min:0',
            'orden'             => 'nullable|integer|min:0',
            'activa'            => 'nullable|boolean',
        ]);

        // Actualizar solo los campos enviados en la petición.
        $fillable = ['proceso', 'titulo', 'descripcion', 'prioridad', 'orden', 'activa'];
        foreach ($fillable as $field) {
            if ($request->has($field)) {
                $template->$field = $request->input($field);
            }
        }

        // Asignación de admin cuando el SPA envía assigned_admin_id.
        if ($request->has('assigned_admin_id')) {
            $assignment = $this->build_assignment_attributes($request);
            $template->assigned_admin_id = $assignment['assigned_admin_id'];
            $template->asignado_a        = $assignment['asignado_a'];
        }

        // Normalizar checklist: null si viene vacío.
        if ($request->has('checklist')) {
            $checklist = $request->input('checklist');
            $template->checklist = (is_array($checklist) && count($checklist) > 0) ? $checklist : null;
        }

        $template->save();
        $template->load('assigned_admin:id,name');

        return response()->json(['model' => $template], 200);
    }

    /**
     * Elimina una plantilla por ID.
     *
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy_json($id)
    {
        $template = TaskTemplate::findOrFail($id);
        $template->delete();

        return response()->json(null, 204);
    }

    /**
     * Alterna el estado activa/inactiva de una plantilla.
     *
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggle_active_json($id)
    {
        $template = TaskTemplate::findOrFail($id);

        // Invertir el estado actual.
        $template->activa = !$template->activa;
        $template->save();

        return response()->json(['model' => $template], 200);
    }

    /**
     * Mueve una plantilla un lugar hacia arriba dentro de su proceso
     * intercambiando el campo `orden` con la plantilla inmediatamente anterior.
     *
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function move_up_json($id)
    {
        $template = TaskTemplate::findOrFail($id);

        // Buscar la plantilla anterior en el mismo proceso (orden menor más cercano).
        $previous = TaskTemplate::where('proceso', $template->proceso)
            ->where('orden', '<', $template->orden)
            ->orderBy('orden', 'desc')
            ->first();

        if ($previous) {
            // Intercambiar valores de orden entre las dos plantillas.
            $temp_orden         = $template->orden;
            $template->orden    = $previous->orden;
            $previous->orden    = $temp_orden;
            $template->save();
            $previous->save();
        }

        // Devolver todas las plantillas del proceso para refrescar la lista en el SPA.
        $models = TaskTemplate::where('proceso', $template->proceso)
            ->orderBy('orden')
            ->get();

        return response()->json(['models' => $models], 200);
    }

    /**
     * Mueve una plantilla un lugar hacia abajo dentro de su proceso
     * intercambiando el campo `orden` con la plantilla inmediatamente siguiente.
     *
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function move_down_json($id)
    {
        $template = TaskTemplate::findOrFail($id);

        // Buscar la plantilla siguiente en el mismo proceso (orden mayor más cercano).
        $next = TaskTemplate::where('proceso', $template->proceso)
            ->where('orden', '>', $template->orden)
            ->orderBy('orden', 'asc')
            ->first();

        if ($next) {
            // Intercambiar valores de orden entre las dos plantillas.
            $temp_orden      = $template->orden;
            $template->orden = $next->orden;
            $next->orden     = $temp_orden;
            $template->save();
            $next->save();
        }

        // Devolver todas las plantillas del proceso para refrescar la lista en el SPA.
        $models = TaskTemplate::where('proceso', $template->proceso)
            ->orderBy('orden')
            ->get();

        return response()->json(['models' => $models], 200);
    }

    /**
     * Arma assigned_admin_id y asignado_a (nombre legacy) desde el request del SPA.
     *
     * @param  Request $request
     * @return array{assigned_admin_id: int|null, asignado_a: string|null}
     */
    protected function build_assignment_attributes(Request $request): array
    {
        $raw_id = $request->input('assigned_admin_id');

        if ($raw_id === null || $raw_id === '' || $raw_id === false) {
            return [
                'assigned_admin_id' => null,
                'asignado_a'        => null,
            ];
        }

        $admin = Admin::find((int) $raw_id);

        if ($admin === null) {
            return [
                'assigned_admin_id' => null,
                'asignado_a'        => null,
            ];
        }

        return [
            'assigned_admin_id' => $admin->id,
            'asignado_a'        => $admin->name,
        ];
    }
}
