<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\BaseController;
use App\Models\ClientApi;
use Illuminate\Http\Request;

/**
 * CRUD JSON de ClientApi (incluye alta con temporal_id cuando el Client padre aún no existe).
 */
class ClientApiController extends BaseController
{
    /**
     * Crea un endpoint; si model_id es null asigna temporal_id para enlazar al guardar el Client.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request)
    {
        /** ID del Client padre; null si el padre aún no fue persistido. */
        $client_id = $request->input('model_id');

        /** URL base sin barra final. */
        $url = rtrim((string) $request->input('url', ''), '/');
        /** Path relativo de la API. */
        $path = (string) $request->input('path', '');

        $client_api = ClientApi::create([
            'client_id'     => $client_id,
            'url'           => $url,
            'path'          => $path,
            'spa_url'       => $request->input('spa_url') ? rtrim((string) $request->input('spa_url'), '/') : null,
            'hosting_type'  => $request->input('hosting_type', 'shared_hosting'),
            'temporal_id'   => $this->get_temporal_id($request),
        ]);

        return response()->json(['model' => $client_api->fresh()], 201);
    }

    /**
     * Actualiza un ClientApi existente.
     *
     * @param  Request  $request
     * @param  int     $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_json(Request $request, $id)
    {
        $client_api = ClientApi::findOrFail($id);

        if ($request->has('url')) {
            $client_api->url = rtrim((string) $request->input('url'), '/');
        }
        if ($request->has('path')) {
            $client_api->path = (string) $request->input('path');
        }
        if ($request->has('spa_url')) {
            $spa = $request->input('spa_url');
            $client_api->spa_url = $spa ? rtrim((string) $spa, '/') : null;
        }
        if ($request->has('hosting_type')) {
            $client_api->hosting_type = (string) $request->input('hosting_type');
        }

        $client_api->save();

        return response()->json(['model' => $client_api->fresh()], 200);
    }

    /**
     * Elimina un ClientApi por id numérico.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy_json($id)
    {
        $client_api = ClientApi::findOrFail($id);
        $client_api->delete();

        return response()->json(null, 204);
    }
}
