<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Actualización masiva por criterio de búsqueda o por ids seleccionados.
 * Contrato alineado con empresa-api: update_form[], from_filter, filter_form o filters, models_id.
 */
class UpdateController extends BaseController
{
    /**
     * Aplica update_form a cada modelo obtenido por filtro o por models_id.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $model_name_param)
    {
        $formated_model_name = GeneralHelper::getModelName($model_name_param);
        $from_filter = (bool) $request->input('from_filter', false);
        $search = new SearchController;

        if ($from_filter) {
            $filter_payload = $request->input('filter_form', $request->input('filters', []));
            $res = $search->search($request, $model_name_param, $filter_payload, 0, true, true);
            $models = $res['models'];
            $used_filters = $res['used_filters'];
            $effective_filters = array_filter($used_filters, function ($filter) {
                return isset($filter['operator']) && $filter['operator'] != 'order_by';
            });
            if (count($effective_filters) == 0) {
                Log::info('UpdateController: se rechaza actualización masiva sin criterios efectivos.');

                return response()->json([
                    'message' => 'No se permite actualizar por filtro si no hay criterios de filtrado.',
                ], 422);
            }
        } else {
            if (! is_array($request->input('models_id')) || count($request->input('models_id', [])) == 0) {
                Log::info('UpdateController: actualización por selección sin models_id.');

                return response()->json([
                    'message' => 'No se permite actualizar sin selección de registros.',
                ], 422);
            }
            $models = [];
            foreach ($request->input('models_id') as $id) {
                $m = $formated_model_name::find($id);
                if ($m) {
                    $models[] = $m;
                }
            }
            $used_filters = [['key' => 'manual_selection', 'operator' => 'ids', 'value' => $request->input('models_id')]];
        }

        if (count($models) > 3000) {
            return response()->json(['message' => 'Demasiados registros afectados (límite 3000).'], 422);
        }

        $models_response = [];
        $update_form = $request->input('update_form', []);

        foreach ($models as $model) {
            foreach ($update_form as $form) {
                if (! isset($form['type'], $form['key'])) {
                    continue;
                }
                if ($form['type'] == 'number' && is_string($form['key'] ?? null) && strpos($form['key'], 'decrement') === 0 && ($form['value'] ?? '') !== '') {
                    $field = substr($form['key'], 10);
                    $value = $model->{$field} * (float) $form['value'] / 100;
                    $model->{$field} -= $value;
                    if (! empty($form['round'])) {
                        $model->{$field} = round($model->{$field}, 0, PHP_ROUND_HALF_UP);
                    }
                } elseif ($form['type'] == 'number' && is_string($form['key'] ?? null) && strpos($form['key'], 'increment') === 0 && ($form['value'] ?? '') !== '') {
                    $field = substr($form['key'], 10);
                    $value = $model->{$field} * (float) $form['value'] / 100;
                    $model->{$field} += $value;
                    if (! empty($form['round'])) {
                        $model->{$field} = round($model->{$field}, 0, PHP_ROUND_HALF_UP);
                    }
                } elseif ($form['type'] == 'number' && is_string($form['key'] ?? null) && strpos($form['key'], 'set_') === 0 && ($form['value'] ?? '') !== '') {
                    $field = substr($form['key'], 4);
                    $model->{$field} = (float) $form['value'];
                } elseif (in_array($form['type'], ['search', 'select'], true) && strpos($form['key'] ?? '', '_id') !== false && ($form['value'] ?? '') !== '' && ($form['value'] ?? 0) != 0) {
                    $model->{$form['key']} = $form['value'];
                } elseif ($form['type'] == 'checkbox' && array_key_exists('value', $form) && $form['value'] !== '') {
                    $model->{$form['key']} = (int) $form['value'];
                } elseif ($form['type'] == 'text' && ($form['value'] ?? '') !== '') {
                    $model->{$form['key']} = $form['value'];
                }
            }
            $model->save();
            $models_response[] = $this->fullModel($model_name_param, $model->id);
        }

        return response()->json(['models' => $models_response], 200);
    }
}
