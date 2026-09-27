<?php

namespace App\Http\Controllers;

use App\Models\PipelineActivity;
use App\Services\Pipelines\PipelineOpportunityService;
use App\Services\Pipelines\PipelineRuleException;

/**
 * Borrado de entradas del historial del CRM de pipelines (misión pipelines-crm, 27/9/2026),
 * endpoint #20 del contrato.
 *
 * Solo se borran las notas: el alta, los cambios de etapa, la próxima acción y el responsable son
 * lo que cuenta el embudo ("cuántas pasaron por cada etapa") y lo que explica cómo llegó cada
 * oportunidad a donde está. Esa regla vive en `PipelineOpportunityService::borrar_actividad()`.
 */
class PipelineActivityController extends Controller
{
    /**
     * #20 `DELETE pipeline-activities/{id}` — borra una nota. 422 si no es una nota.
     *
     * @param int                        $id
     * @param PipelineOpportunityService $servicio
     *
     * @return \Illuminate\Http\JsonResponse {ok: true}
     */
    public function destroy_json($id, PipelineOpportunityService $servicio)
    {
        $actividad = PipelineActivity::query()->find((int) $id);

        if ($actividad === null) {
            throw PipelineRuleException::no_existe('La actividad');
        }

        $servicio->borrar_actividad($actividad);

        return response()->json(['ok' => true]);
    }
}
