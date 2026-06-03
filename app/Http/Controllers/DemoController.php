<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\BaseController;
use App\Http\Controllers\CommonLaravel\Helpers\ModelPropertiesHelper;
use App\Models\Demo;
use Illuminate\Http\Request;

/**
 * CRUD JSON del catálogo de demos para admin-spa.
 */
class DemoController extends BaseController
{
    /**
     * Lista demos con paginado opcional para grilla de admin-spa.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json(Request $request)
    {
        // Tamaño de página configurable por la grilla.
        $per_page = (int) $request->input('per_page', 100);
        if ($per_page < 1) {
            $per_page = 20;
        }
        if ($per_page > 200) {
            $per_page = 200;
        }

        // Query base del recurso ordenado por último registro.
        $query = Demo::query()->orderBy('id', 'desc');
        if ($request->has('page')) {
            $models = $query->paginate($per_page);
        } else {
            $models = $query->get();
        }

        return response()->json(['models' => $models], 200);
    }

    /**
     * Retorna una demo puntual para edición en modal CRUD.
     *
     * @param int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show_json($id)
    {
        // Modelo completo siguiendo contrato estándar fullModel.
        $model = $this->fullModel('demo', $id);
        if (! $model) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        return response()->json(['model' => $model], 200);
    }

    /**
     * Crea una demo nueva desde admin-spa.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request)
    {
        // Atributos del create según properties() del modelo demo.
        $attributes = ModelPropertiesHelper::attributes_for_create($request, 'demo');
        // Normalización de URLs para evitar slash final inconsistente.
        $attributes = $this->normalize_url_attributes($attributes);

        // Persistencia principal de la demo.
        $demo = Demo::create($attributes);

        return response()->json(['model' => $this->fullModel('demo', $demo->id)], 201);
    }

    /**
     * Actualiza una demo existente desde admin-spa.
     *
     * @param Request $request
     * @param int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_json(Request $request, $id)
    {
        // Registro objetivo de edición.
        $demo = Demo::findOrFail($id);
        // Seteo base por contrato declarativo de ModelProperties.
        ModelPropertiesHelper::set_from_request($demo, $request, 'demo');
        // Re-normalización de URLs editadas manualmente.
        $this->normalize_model_urls_from_request($demo, $request);

        return response()->json(['model' => $this->fullModel('demo', $id)], 200);
    }

    /**
     * Elimina una demo.
     *
     * @param int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy_json($id)
    {
        // Demo objetivo de eliminación.
        $demo = Demo::findOrFail($id);
        $demo->delete();

        return response()->json(null, 204);
    }

    /**
     * Normaliza los campos URL en un array de atributos para create.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected function normalize_url_attributes(array $attributes)
    {
        // Campos URL del recurso demo que se normalizan sin slash final.
        $url_keys = ['erp_spa_url', 'erp_api_url', 'ecommerce_spa_url', 'ecommerce_api_url'];
        foreach ($url_keys as $url_key) {
            if (isset($attributes[$url_key]) && is_string($attributes[$url_key])) {
                $attributes[$url_key] = rtrim(trim($attributes[$url_key]), '/');
            }
        }
        return $attributes;
    }

    /**
     * Re-normaliza URLs solo si llegaron en el request de update.
     *
     * @param Demo $demo
     * @param Request $request
     * @return void
     */
    protected function normalize_model_urls_from_request(Demo $demo, Request $request)
    {
        // Campos URL del recurso demo que podrían venir en edición parcial.
        $url_keys = ['erp_spa_url', 'erp_api_url', 'ecommerce_spa_url', 'ecommerce_api_url'];
        // Bandera para evitar save innecesario cuando no cambia ninguna URL.
        $has_changes = false;
        foreach ($url_keys as $url_key) {
            if ($request->has($url_key) && is_string($request->input($url_key))) {
                $demo->{$url_key} = rtrim(trim($request->input($url_key)), '/');
                $has_changes = true;
            }
        }
        if ($has_changes) {
            $demo->save();
        }
    }
}
