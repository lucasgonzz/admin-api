<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\BaseController;
use App\Jobs\RunDemoUpdateJob;
use App\Models\DemoUpdate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * CRUD JSON del recurso DemoUpdate para admin-spa.
 * Los DemoUpdates no se editan; solo se crean (disparando el job) o eliminan.
 */
class DemoUpdateController extends BaseController
{
    /**
     * Lista todos los DemoUpdates con sus relaciones, ordenados por más reciente.
     * Soporta paginado opcional si la grilla envía el parámetro page.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json(Request $request)
    {
        // Tamaño de página configurable por la grilla; límites fijos para protección.
        $per_page = (int) $request->input('per_page', 100);
        if ($per_page < 1) {
            $per_page = 20;
        }
        if ($per_page > 200) {
            $per_page = 200;
        }

        // Query base con relaciones y ordenado por último creado.
        $query = DemoUpdate::withAll()->orderBy('id', 'desc');

        if ($request->has('page')) {
            $models = $query->paginate($per_page);
        } else {
            $models = $query->get();
        }

        return response()->json(['models' => $models], 200);
    }

    /**
     * Retorna un DemoUpdate puntual con todas sus relaciones para el modal de detalle.
     *
     * @param  int|string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show_json($id)
    {
        // Carga el modelo completo con relaciones (contrato estándar fullModel).
        $model = $this->fullModel('demo_update', $id);
        if (! $model) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        return response()->json(['model' => $model], 200);
    }

    /**
     * Crea un nuevo DemoUpdate en estado pendiente y despacha el job de actualización.
     * El job ejecuta el pipeline SSH/SPA/API de forma asíncrona en la cola default.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request)
    {
        // Admin autenticado que inicia el proceso (nullable si no hay sesión).
        $admin    = Auth::guard('sanctum')->user();
        $admin_id = $admin ? $admin->id : null;

        // Atributos iniciales del registro; el status siempre arranca en pendiente.
        $demo_update = DemoUpdate::create([
            'demo_id'              => $request->input('demo_id'),
            'version_id'           => $request->input('version_id'),
            'created_by_admin_id'  => $admin_id,
            'status'               => 'pendiente',
        ]);

        // Despacha el job en la cola default para ejecución asíncrona.
        RunDemoUpdateJob::dispatch($demo_update);

        // Retorna el modelo con relaciones ya cargadas para actualizar la grilla.
        $created = DemoUpdate::withAll()->find($demo_update->id);

        return response()->json(['model' => $created], 201);
    }

    /**
     * Elimina un DemoUpdate (solo registros en estado final: completado o fallido).
     *
     * @param  int|string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy_json($id)
    {
        // Registro objetivo de eliminación.
        $demo_update = DemoUpdate::findOrFail($id);
        $demo_update->delete();

        return response()->json(null, 204);
    }
}
