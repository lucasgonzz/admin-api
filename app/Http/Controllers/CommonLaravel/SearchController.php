<?php

namespace App\Http\Controllers\CommonLaravel;

// use App\Http\Controllers\CommonLaravel\BaseController;
use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Models\Lead;
use Illuminate\Http\Request;

/**
 * Búsqueda y filtrado reutilizando el contrato de filters[] de empresa-spa.
 * Sin filtro por user_id (no hay multi-tenant en admin-api).
 */
class SearchController
{
    /**
     * Aplica filtros al query del modelo y devuelve JSON paginado o lista.
     *
     * @param \Illuminate\Http\Request $request
     * @param string $model_name_param Nombre corto (version, client, update, …)
     * @param array|null $_filters Filtros prearmados; si null se usa $request->filters
     * @param int $paginate 0 = get(), 1 = paginate
     * @param bool $return_used_filters Si true, retorna arreglo con models + used_filters
     * @param bool $return_raw_models Si true, retorna solo el query result (sin JSON)
     * @return \Illuminate\Http\JsonResponse|array|\Illuminate\Database\Eloquent\Collection|\Illuminate\Pagination\LengthAwarePaginator
     */
    public function search(
        Request $request,
        $model_name_param,
        $_filters = null,
        $paginate = 0,
        $return_used_filters = false,
        $return_raw_models = false
    ) {
        
        $model_name = GeneralHelper::getModelName($model_name_param);
        
        if ($model_name_param == 'sync-to-tn-article') {
            $model_name = 'App\\Models\\SyncToTNArticle';
        } 
        $models = $model_name::query();

        if (is_null($_filters) || $_filters == 'null') {
            $filters = $request->input('filters', []);
        } else {
            $filters = $_filters;
        }
        if (! is_array($filters)) {
            $filters = [];
        }

        if ($request->boolean('papelera')) {
            $models = $models->whereNotNull('deleted_at')
                ->withTrashed();
        }

        $used_filters = [];

        foreach ($filters as $filter) {
            if (! isset($filter['type'])) {
                continue;
            }

            if (isset($filter['ordenar_de']) && $filter['ordenar_de'] != '') {
                $models = $models->orderBy($filter['key'], $filter['ordenar_de']);
                $used_filters[] = [
                    'key' => $filter['key'],
                    'operator' => 'order_by',
                    'value' => $filter['ordenar_de'],
                    'type' => $filter['type'],
                ];
            }

            if (isset($filter['en_blanco']) && (bool) $filter['en_blanco']) {
                if (
                    $filter['type'] == 'select'
                    || $filter['type'] == 'pipeline_status'
                    || $filter['type'] == 'search'
                ) {
                    $models = $models->where(function ($subquery) use ($filter) {
                        $subquery->whereNull($filter['key'])
                            ->orWhere($filter['key'], 0);
                    });
                } else {
                    $models = $models->where(function ($subquery) use ($filter) {
                        $subquery->whereNull($filter['key'])
                            ->orWhere($filter['key'], '');
                    });
                }
                $used_filters[] = [
                    'key' => $filter['key'],
                    'operator' => 'en_blanco',
                    'value' => true,
                    'type' => $filter['type'],
                ];
            } elseif (isset($filter['key'])) {
                $key = $filter['key'];
                if ($key == 'num' && $model_name_param == 'article') {
                    $key = 'id';
                }

                if ($filter['type'] == 'number') {
                    if (isset($filter['menor_que']) && $filter['menor_que'] != '') {
                        $models = $models->where($key, '<', trim($filter['menor_que']));
                        $used_filters[] = [
                            'key' => $filter['key'],
                            'operator' => 'menor_que',
                            'value' => $filter['menor_que'],
                            'type' => $filter['type'],
                        ];
                    }
                    if (isset($filter['igual_que']) && $filter['igual_que'] != '') {
                        $models = $models->where($key, '=', trim($filter['igual_que']));
                        $used_filters[] = [
                            'key' => $filter['key'],
                            'operator' => 'igual_que',
                            'value' => $filter['igual_que'],
                            'type' => $filter['type'],
                        ];
                    }
                    if (isset($filter['mayor_que']) && $filter['mayor_que'] != '') {
                        $models = $models->where($key, '>', trim($filter['mayor_que']));
                        $used_filters[] = [
                            'key' => $filter['key'],
                            'operator' => 'mayor_que',
                            'value' => $filter['mayor_que'],
                            'type' => $filter['type'],
                        ];
                    }
                } elseif ($filter['type'] == 'text' || $filter['type'] == 'textarea') {
                    if (isset($filter['igual_que']) && $filter['igual_que'] != '') {
                        $models = $models->where($filter['key'], trim($filter['igual_que']));
                        $used_filters[] = [
                            'key' => $filter['key'],
                            'operator' => 'igual_que',
                            'value' => $filter['igual_que'],
                            'type' => $filter['type'],
                        ];
                    } elseif (isset($filter['que_contenga']) && $filter['que_contenga'] != '') {
                        $keywords = explode(' ', $filter['que_contenga']);
                        foreach ($keywords as $keyword) {
                            if ($keyword === '') {
                                continue;
                            }
                            $models = $models->whereRaw($filter['key'].' LIKE ?', ['%'.$keyword.'%']);
                        }
                        $used_filters[] = [
                            'key' => $filter['key'],
                            'operator' => 'que_contenga',
                            'value' => $filter['que_contenga'],
                            'type' => $filter['type'],
                        ];
                    }
                } elseif (
                    $filter['type'] == 'search'
                    && isset($filter['igual_que'])
                    && $filter['igual_que'] != 0
                    && $filter['igual_que'] != ''
                ) {
                    $models = $models->where($filter['key'], $filter['igual_que']);
                    $used_filters[] = [
                        'key' => $filter['key'],
                        'operator' => 'igual_que',
                        'value' => $filter['igual_que'],
                        'type' => $filter['type'],
                    ];
                } elseif (
                    ($filter['type'] == 'date' || $filter['type'] == 'day')
                    && (
                        (isset($filter['menor_que']) && $filter['menor_que'] != '')
                        || (isset($filter['igual_que']) && $filter['igual_que'] != '')
                        || (isset($filter['mayor_que']) && $filter['mayor_que'] != '')
                    )
                ) {
                    if (isset($filter['menor_que']) && $filter['menor_que'] != '') {
                        $models = $models->whereDate($filter['key'], '<', $filter['menor_que']);
                        $used_filters[] = [
                            'key' => $filter['key'],
                            'operator' => 'menor_que',
                            'value' => $filter['menor_que'],
                            'type' => $filter['type'],
                        ];
                    }
                    if (isset($filter['igual_que']) && $filter['igual_que'] != '') {
                        $models = $models->whereDate($filter['key'], $filter['igual_que']);
                        $used_filters[] = [
                            'key' => $filter['key'],
                            'operator' => 'igual_que',
                            'value' => $filter['igual_que'],
                            'type' => $filter['type'],
                        ];
                    }
                    if (isset($filter['mayor_que']) && $filter['mayor_que'] != '') {
                        $models = $models->whereDate($filter['key'], '>', $filter['mayor_que']);
                        $used_filters[] = [
                            'key' => $filter['key'],
                            'operator' => 'mayor_que',
                            'value' => $filter['mayor_que'],
                            'type' => $filter['type'],
                        ];
                    }
                } elseif (
                    ($filter['type'] == 'select' || $filter['type'] == 'pipeline_status')
                    && isset($filter['igual_que'])
                    && $filter['igual_que'] !== 0
                    && $filter['igual_que'] !== ''
                    && $filter['igual_que'] !== null
                ) {
                    $models = $models->where($filter['key'], $filter['igual_que']);
                    $used_filters[] = [
                        'key' => $filter['key'],
                        'operator' => 'igual_que',
                        'value' => $filter['igual_que'],
                        'type' => $filter['type'],
                    ];
                } elseif (
                    $filter['type'] == 'checkbox'
                    && isset($filter['checkbox'])
                    && $filter['checkbox'] != -1
                ) {
                    $checkboxKey = $filter['key'];
                    $checkboxVal = $filter['checkbox'];
                    if (in_array($checkboxVal, [0, false, '0'], true)) {
                        $models = $models->where(function ($subquery) use ($checkboxKey) {
                            $subquery->whereNull($checkboxKey)
                                ->orWhere($checkboxKey, 0);
                        });
                    } else {
                        $models = $models->where($checkboxKey, $checkboxVal);
                    }
                    $used_filters[] = [
                        'key' => $filter['key'],
                        'operator' => 'checkbox',
                        'value' => $filter['checkbox'],
                        'type' => $filter['type'],
                    ];
                }
            }
        }

        if ($model_name === Lead::class) {
            $models = $models->withAllForList();

            /** Mismo orden que index_json de leads: fijados primero y criterio activo del SPA. */
            $models->orderByRaw('CASE WHEN pinned_at IS NOT NULL THEN 0 ELSE 1 END ASC');
            $models->orderByRaw('pinned_at DESC');

            $sort_by = (string) $request->input('sort_by', 'last_message');
            if ($sort_by === 'last_message') {
                $models->orderByRaw('COALESCE(last_message_at, created_at) DESC');
            } else {
                $models->orderByDesc('created_at');
            }
        } elseif (method_exists($model_name, 'scopeWithAll')) {
            $models = $models->withAll()
                ->orderBy('created_at', 'DESC');
        } else {
            $models = $models->orderBy('created_at', 'DESC');
        }

        if ($paginate) {
            $per_page = (int) $request->input('per_page', 5);
            if ($per_page < 1) {
                $per_page = 5;
            }
            if ($per_page > 200) {
                $per_page = 200;
            }
            $models = $models->paginate($per_page);
        } else {
            $models = $models->get();
        }

        if ($return_raw_models) {
            return $models;
        }

        if ($model_name === Lead::class) {
            Lead::prepare_collection_for_list_json($models);
        }

        if ($return_used_filters) {
            return [
                'models' => $models,
                'used_filters' => $used_filters,
            ];
        }

        if (is_null($_filters)) {
            if ($paginate) {
                return response()->json($models, 200);
            }

            return response()->json(['data' => $models], 200);
        }

        return $models;
    }

    /**
     * Búsqueda modal (SPA): OR entre columnas elegidas; en cada columna todas las palabras deben coincidir (AND de LIKE).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function searchFromModal(Request $request, $model_name_param)
    {
        $query_value = trim((string) $request->input('query_value', ''));
        $raw_props = (array) $request->input('props_to_filter', []);
        $props_to_filter = array_values(array_filter($raw_props, function ($k) {
            return is_string($k) && $k !== '' && preg_match('/^[a-z0-9_]+$/i', $k);
        }));

        if ($query_value === '') {
            return response()->json(['message' => 'Ingrese un criterio de búsqueda.'], 422);
        }
        if (count($props_to_filter) === 0) {
            return response()->json(['message' => 'Seleccione al menos una propiedad para buscar.'], 422);
        }

        $model_name = GeneralHelper::getModelName($model_name_param);
        $models = $model_name::query()->withAll();

        $models->where(function ($query) use ($query_value, $props_to_filter) {
            foreach ($props_to_filter as $prop_key) {
                $query->orWhere(function ($sub) use ($prop_key, $query_value) {
                    $keywords = preg_split('/\s+/', $query_value, -1, PREG_SPLIT_NO_EMPTY);
                    foreach ($keywords as $keyword) {
                        $sub->whereRaw($prop_key.' LIKE ?', ['%'.$keyword.'%']);
                    }
                });
            }
        });

        $per_page = (int) $request->input('per_page', 25);
        if ($per_page < 1) {
            $per_page = 25;
        }
        if ($per_page > 200) {
            $per_page = 200;
        }
        $page = (int) $request->input('page', 1);
        if ($page < 1) {
            $page = 1;
        }

        $models = $models->orderBy('id', 'desc')
            ->paginate($per_page, ['*'], 'page', $page);

        return response()->json(['models' => $models], 200);
    }
}
