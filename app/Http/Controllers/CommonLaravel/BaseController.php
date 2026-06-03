<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;

/**
 * Base para controladores del admin API: fullModel, userId nulo (sin multi-tenant).
 */
class BaseController extends Controller
{
    /**
     * userId multi-tenant (empresa-api); en admin no aplica: retorna null.
     *
     * @return int|null
     */
    protected function userId()
    {
        return null;
    }

    /**
     * Carga un registro por id con withAll() si existe el scope.
     *
     * @param string $model_name Nombre corto (p. ej. "version", "client", "update")
     * @param int|string $id
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    protected function fullModel($model_name, $id)
    {
        $class = GeneralHelper::getModelName($model_name);
        $q = $class::query()->where('id', $id);
        if (method_exists($class, 'scopeWithAll')) {
            $q = $q->withAll();
        }

        return $q->first();
    }

    /**
     * Genera temporal_id cuando el hijo se crea sin FK al padre (model_id null).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function get_temporal_id(Request $request)
    {
        if ($request->input('model_id') === null || $request->input('model_id') === '') {
            return (string) (time() . rand(0, 9999));
        }

        return null;
    }

    /**
     * Enlaza modelos hijos creados con temporal_id al padre recién persistido.
     *
     * @param  string  $model_name  nombre corto del padre (ej. client)
     * @param  int     $model_id    id del padre
     * @param  array|null  $childrens  [{ model_name, temporal_id }, ...]
     * @return void
     */
    protected function update_relations_created($model_name, $model_id, $childrens)
    {
        if (! is_array($childrens)) {
            return;
        }

        foreach ($childrens as $children) {
            if (empty($children['model_name']) || empty($children['temporal_id'])) {
                continue;
            }

            $child_class = GeneralHelper::getModelName($children['model_name']);
            $fk_column = $model_name . '_id';

            $relation_model = $child_class::query()
                ->whereNull($fk_column)
                ->where('temporal_id', $children['temporal_id'])
                ->first();

            if ($relation_model) {
                $relation_model->{$fk_column} = $model_id;
                $relation_model->temporal_id = null;
                $relation_model->save();
            }
        }
    }
}
