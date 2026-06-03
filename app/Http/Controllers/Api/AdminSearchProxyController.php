<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\CommonLaravel\SearchController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Expone POST search/{model}/null/1 con el mismo cuerpo que empresa-spa (filters, per_page).
 */
class AdminSearchProxyController extends Controller
{
    /**
     * Búsqueda con paginación (página vía ?page=).
     *
     * @return \Illuminate\Http\JsonResponse|mixed
     */
    public function search(Request $request, $model)
    {
        $c = new SearchController;

        return $c->search($request, $model, null, 1, false, false);
    }
}
