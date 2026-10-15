<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\ClientExtensionesAccesoService;

/**
 * Botón «Gestionar extensiones» de la pestaña Extensiones de la ficha del cliente (misión
 * `extensiones-desde-el-admin`, 15/10/2026). La lógica vive en `ClientExtensionesAccesoService`.
 *
 * Responde SIEMPRE HTTP 200 con `{estado, mensaje, url}`: el estado dice qué pasó y el SPA decide qué mostrar.
 */
class ClientExtensionesAccesoController extends Controller
{
    /**
     * Pide al cliente un acceso de un solo uso al configurador de extensiones.
     *
     * @param  ClientExtensionesAccesoService $servicio  Servicio que habla con el empresa-api del cliente.
     * @param  int|string                     $clientId  Id numérico o uuid del cliente.
     * @return \Illuminate\Http\JsonResponse
     */
    public function acceso_json(ClientExtensionesAccesoService $servicio, $clientId)
    {
        $client = is_numeric($clientId)
            ? Client::findOrFail((int) $clientId)
            : Client::where('uuid', (string) $clientId)->firstOrFail();

        return response()->json($servicio->pedir_acceso($client));
    }
}
