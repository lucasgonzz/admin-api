<?php

namespace App\Http\Controllers;

use App\Models\PipelineStage;
use App\Services\Pipelines\PipelineConfigService;
use App\Services\Pipelines\PipelinePresenter;
use App\Services\Pipelines\PipelineRuleException;
use App\Services\Pipelines\PipelineValidator;
use Illuminate\Http\Request;

/**
 * Edición y borrado de etapas del CRM de pipelines (misión pipelines-crm, 27/9/2026), endpoints
 * #8 y #9 del contrato. El alta de una etapa va por `POST pipelines/{id}/stages`
 * (`PipelineController`), porque necesita el pipeline.
 *
 * Las reglas (no cambiar el tipo con oportunidades adentro, no dejar al pipeline sin etapa
 * abierta, no borrar una etapa con gente) viven en `PipelineConfigService`.
 */
class PipelineStageController extends Controller
{
    /**
     * #8 `PUT pipeline-stages/{id}` — edita nombre, color, tipo y/o campos de una etapa.
     *
     * 422 si cambia el `type` con oportunidades adentro (el estado de cada una sale de su etapa) o
     * si deja al pipeline sin ninguna etapa abierta.
     *
     * @param Request               $request   {name?, color?, type?, fields?}
     * @param int                   $id
     * @param PipelineConfigService $config
     * @param PipelinePresenter     $presenter
     *
     * @return \Illuminate\Http\JsonResponse {stage}
     */
    public function update_json(Request $request, $id, PipelineConfigService $config, PipelinePresenter $presenter)
    {
        $etapa = $this->etapa_o_404($id);

        $datos = PipelineValidator::validar($request->all(), [
            'name'   => ['sometimes', 'required', 'string', 'max:120'],
            'color'  => ['sometimes', 'nullable', 'string', PipelineController::REGLA_COLOR],
            'type'   => ['sometimes', 'nullable', 'string', 'in:open,won,lost'],
            'fields' => ['sometimes', 'nullable'],
        ]);

        $etapa = $config->actualizar_etapa($etapa, $datos);

        return response()->json(['stage' => $presenter->stage($etapa)]);
    }

    /**
     * #9 `DELETE pipeline-stages/{id}` — borra una etapa vacía. 422 si tiene oportunidades o si es
     * la última abierta del pipeline.
     *
     * @param int                   $id
     * @param PipelineConfigService $config
     *
     * @return \Illuminate\Http\JsonResponse {ok: true}
     */
    public function destroy_json($id, PipelineConfigService $config)
    {
        $config->borrar_etapa($this->etapa_o_404($id));

        return response()->json(['ok' => true]);
    }

    /**
     * La etapa, o 404 con mensaje legible.
     *
     * @param int $id
     *
     * @return PipelineStage
     *
     * @throws PipelineRuleException
     */
    private function etapa_o_404($id)
    {
        $etapa = PipelineStage::query()->find((int) $id);

        if ($etapa === null) {
            throw PipelineRuleException::no_existe('La etapa');
        }

        return $etapa;
    }
}
