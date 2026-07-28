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

        /** URL base normalizada (sin barra final ni sufijos "/public" repetidos). */
        $url = $this->normalize_api_url($request->input('url', ''));
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
            $client_api->url = $this->normalize_api_url($request->input('url'));
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

    /**
     * Normaliza la URL base de un ClientApi antes de guardarla.
     *
     * La columna "client_apis.url" debe quedar SIEMPRE sin el sufijo "/public": ese sufijo es
     * responsabilidad exclusiva de get_api_url_for_env() (hoy unificado en
     * ClientEmpresaApiUrlResolver::build_api_url_for_env()), que decide agregarlo o no según el
     * hosting_type de cada ClientApi. Guardar el sufijo también acá duplicaría esa decisión en dos
     * capas (grupo 237, mismo problema ya resuelto del lado de empresa-api en el grupo 230).
     *
     * Usa un "while" (no un "if") para sacar TODAS las repeticiones finales de "/public", porque
     * columnas cargadas a mano en el pasado pueden tener "/public/public" o más.
     *
     * No aplicar a "spa_url": esa URL nunca lleva "/public" y no hay que asumir nada sobre ella.
     *
     * @param  mixed  $url  Valor crudo recibido del request.
     * @return string  URL normalizada, sin barra final ni sufijos "/public" repetidos.
     */
    protected function normalize_api_url($url)
    {
        // Cast a string y limpieza de espacios y barra final.
        $url = trim((string) $url);
        $url = rtrim($url, '/');

        // Saca todas las repeticiones finales de "/public" (no solo una).
        while (substr($url, -7) === '/public') {
            $url = rtrim(substr($url, 0, -7), '/');
        }

        return $url;
    }
}
