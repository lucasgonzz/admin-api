<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use Illuminate\Http\Request;

/**
 * Publica el esquema de propiedades (columnas, tipos) para un modelo del SPA.
 */
class MetaController extends Controller
{
    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, $model)
    {
        $class = GeneralHelper::getModelName($model);
        if (! class_exists($class) || ! method_exists($class, 'properties')) {
            return response()->json(['message' => 'Recurso desconocido.'], 404);
        }

        return response()->json([
            'model_name' => $model,
            'properties' => $class::properties(),
        ], 200);
    }
}
