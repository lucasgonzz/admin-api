<?php

namespace App\Http\Controllers;

use App\Helpers\AppTime;
use App\Http\Controllers\Controller;
use App\Models\AdminTask;
use App\Services\LeadCandidatosParaLlamadaService;
use App\Services\Pipelines\PipelineRuleException;
use Illuminate\Http\Request;

/**
 * Lo que el tablero de Juan necesita además de las tarjetas (misión embudo-juan-pipeline, E1 y E1b).
 *
 *  - `GET lead-tablero/mis-tareas`: las tareas abiertas asignadas al admin autenticado, como mucho 100.
 *  - `POST lead-tablero/tareas/{id}/hecha`: marca una tarea propia como hecha; si no es suya, 403 `{code: tarea_ajena}`.
 *  - `GET lead-tablero/candidatos` (E1b): busca el lead de una llamada por nombre, negocio o teléfono. Sólo lectura.
 *
 * Son endpoints nuevos y no `GET task` a propósito: el perfil «Sólo Leads» no puede tener `GET task` (un test de E5 lo exige
 * en 403) y `Tasks.vue` trae acciones globales (reordenar, borrar, crear) que el perfil no debe tener.
 */
class LeadTableroController extends Controller
{
    /** Cuántas tareas devuelve «Mis tareas». */
    const LIMITE_DE_TAREAS = 100;

    /**
     * Las tareas abiertas del admin autenticado.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse `{models: [{id, title, content, lead_id, created_at, lead}]}`
     */
    public function mis_tareas_json(Request $request)
    {
        $admin_id = (int) $request->user()->id;

        $tareas = $this->tareas_del_admin($admin_id)
            ->where('is_done', false)
            ->with('lead:id,contact_name,company_name')
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->limit(self::LIMITE_DE_TAREAS)
            ->get();

        $modelos = [];
        foreach ($tareas as $tarea) {
            $modelos[] = [
                'id'         => (int) $tarea->id,
                'title'      => (string) $tarea->title,
                'content'    => $tarea->content,
                'lead_id'    => $tarea->lead_id === null ? null : (int) $tarea->lead_id,
                'created_at' => $tarea->created_at ? $tarea->created_at->format('Y-m-d H:i:s') : null,
                'lead'       => $tarea->lead ? [
                    'id'           => (int) $tarea->lead->id,
                    'contact_name' => $tarea->lead->contact_name,
                    'company_name' => $tarea->lead->company_name,
                ] : null,
            ];
        }

        return response()->json(['models' => $modelos], 200);
    }

    /**
     * Marca como hecha una tarea del admin autenticado.
     *
     * @param Request    $request
     * @param int|string $id Id de la tarea.
     *
     * @return \Illuminate\Http\JsonResponse 200 `{model}` · 403 `{code: tarea_ajena}` · 404
     */
    public function tarea_hecha_json(Request $request, $id)
    {
        $admin_id = (int) $request->user()->id;

        AdminTask::query()->findOrFail((int) $id);

        $propia = $this->tareas_del_admin($admin_id)->whereKey((int) $id)->first();
        if ($propia === null) {
            return response()->json(['message' => 'Esa tarea no es tuya.', 'code' => 'tarea_ajena'], 403);
        }

        $propia->is_done          = true;
        $propia->done_at          = AppTime::now();
        $propia->done_by_admin_id = $admin_id;
        $propia->save();

        return response()->json(['model' => ['id' => (int) $propia->id, 'is_done' => true, 'done_at' => $propia->done_at ? $propia->done_at->format('Y-m-d H:i:s') : null]], 200);
    }

    /**
     * Busca el lead de una llamada (E1b). La decisión `unico` / `ambiguo` / `sin_candidatos` sale del servidor.
     *
     * @param Request                          $request `q` (≤120) y/o `telefono` (dígitos ≤20): al menos uno.
     * @param LeadCandidatosParaLlamadaService $servicio
     *
     * @return \Illuminate\Http\JsonResponse
     *
     * @throws PipelineRuleException 422 si no viene ni `q` ni `telefono`.
     */
    public function candidatos_json(Request $request, LeadCandidatosParaLlamadaService $servicio)
    {
        $texto    = trim((string) $request->query('q', ''));
        $telefono = preg_replace('/\D+/', '', (string) $request->query('telefono', ''));

        $errores = [];
        if ($texto === '' && $telefono === '') {
            $errores['q'][] = 'Mandá el nombre, el negocio o el teléfono para buscar.';
        }
        if (mb_strlen($texto) > 120) {
            $errores['q'][] = 'La búsqueda no puede tener más de 120 caracteres.';
        }
        if (strlen($telefono) > 20) {
            $errores['telefono'][] = 'El teléfono no puede tener más de 20 dígitos.';
        }
        if ($errores !== []) {
            throw PipelineRuleException::validacion($errores);
        }

        return response()->json($servicio->buscar($texto, $telefono), 200);
    }

    /**
     * Consulta de las tareas asignadas al admin, por el pivote o por la columna legacy `assigned_admin_id`.
     *
     * @param int $admin_id
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function tareas_del_admin(int $admin_id)
    {
        return AdminTask::query()->where(function ($consulta) use ($admin_id) {
            $consulta->where('assigned_admin_id', $admin_id)
                ->orWhereHas('assigned_admins', function ($pivote) use ($admin_id) {
                    $pivote->where('admins.id', $admin_id);
                });
        });
    }
}
