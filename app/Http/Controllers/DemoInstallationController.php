<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\BaseController;
use App\Jobs\RunDemoInstallationJob;
use App\Models\DemoInstallation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * CRUD JSON del recurso DemoInstallation para admin-spa.
 *
 * Mismo contrato que DemoUpdateController: las corridas no se editan, sólo se crean (disparando el
 * job) o se borran. Lo único que cambia es que el alta acepta además `env_manual_values`, que son
 * las credenciales de la base que el operador creó a mano en hPanel y que el pipeline escribe en
 * el .env de la demo.
 */
class DemoInstallationController extends BaseController
{
    /**
     * Lista las instalaciones con sus relaciones, de la más reciente a la más vieja.
     *
     * Acepta `demo_id` para filtrar por demo (es lo que usa la solapa Sistema del módulo de
     * Instalaciones cuando se abre parado en una demo) y paginado opcional.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json(Request $request)
    {
        // Tamaño de página configurable por la grilla, con topes fijos por protección.
        $per_page = (int) $request->input('per_page', 100);
        if ($per_page < 1) {
            $per_page = 20;
        }
        if ($per_page > 200) {
            $per_page = 200;
        }

        $query = DemoInstallation::withAll()->orderBy('id', 'desc');

        $demo_id = $request->input('demo_id');
        if ($demo_id !== null && $demo_id !== '') {
            $query->where('demo_id', (int) $demo_id);
        }

        if ($request->has('page')) {
            $models = $query->paginate($per_page);
        } else {
            $models = $query->get();
        }

        return response()->json(['models' => $models], 200);
    }

    /**
     * Devuelve una corrida puntual con todas sus relaciones, incluido el log completo.
     *
     * Es el endpoint contra el que hace polling el panel de Operaciones mientras la instalación
     * corre: la relación `logs` de scopeWithAll() es la consola en vivo.
     *
     * @param  int|string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show_json($id)
    {
        $model = $this->fullModel('demo_installation', $id);
        if (! $model) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        return response()->json(['model' => $model], 200);
    }

    /**
     * Crea una instalación en estado `pendiente` y despacha el job que corre el pipeline.
     *
     * 🔴 El pipeline es DESTRUCTIVO: su etapa `run_demo_setup` le hace `migrate:fresh` a la base de
     * la demo. Por eso este endpoint es lo único que lo dispara y lo hace UNA vez por fila: no hay
     * un `start` aparte como en client-installations, así que no existe la secuencia "crear, mirar,
     * volver a arrancar la misma fila" que en una demo sería un segundo migrate:fresh.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request)
    {
        $request->validate([
            'demo_id'           => 'required|integer|exists:demos,id',
            'version_id'        => 'required|integer|exists:versions,id',
            'env_manual_values' => 'nullable|array',
        ]);

        // Admin autenticado que inicia el proceso (null si no hay sesión).
        $admin    = Auth::guard('sanctum')->user();
        $admin_id = $admin ? $admin->id : null;

        $env_manual_values = $request->input('env_manual_values');
        if (! is_array($env_manual_values)) {
            $env_manual_values = null;
        }

        $installation = DemoInstallation::create([
            'demo_id'             => (int) $request->input('demo_id'),
            'version_id'          => (int) $request->input('version_id'),
            'created_by_admin_id' => $admin_id,
            'status'              => DemoInstallation::STATUS_PENDIENTE,
            'env_manual_values'   => $env_manual_values,
        ]);

        RunDemoInstallationJob::dispatch($installation);

        $created = DemoInstallation::withAll()->find($installation->id);

        return response()->json(['model' => $created], 201);
    }

    /**
     * Borra una corrida y sus líneas de log.
     *
     * 🔴 No se borra una corrida que está `instalando`: el job sigue vivo del otro lado y su
     * `failed()` (y el propio service) buscan la fila para cerrarla. Sin ella, el pipeline sigue
     * escribiendo en un servidor real sin que quede registro de nada.
     *
     * El log se borra explícitamente porque `demo_installation_logs` no tiene FK con
     * `ON DELETE CASCADE` — el proyecto no usa FK en la base — así que si no se hace acá, esas
     * filas quedan huérfanas para siempre.
     *
     * @param  int|string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy_json($id)
    {
        $installation = DemoInstallation::findOrFail($id);

        if ($installation->status === DemoInstallation::STATUS_INSTALANDO) {
            return response()->json([
                'message' => 'No se puede borrar una instalación que está corriendo. Esperá a que '
                    . 'termine (o falle) y borrala después.',
            ], 422);
        }

        $installation->logs()->delete();
        $installation->delete();

        return response()->json(null, 204);
    }
}
