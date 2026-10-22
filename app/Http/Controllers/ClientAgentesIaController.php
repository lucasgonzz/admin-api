<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\ClientAgentesIaService;
use Illuminate\Http\Request;

/**
 * Bloque «Agentes de IA» de la ficha del cliente (misión `agentes-ia-extension-unica`, 22/10/2026): leer si el cliente
 * tiene la extensión y activarla o quitarla. La lógica vive en `ClientAgentesIaService`.
 *
 * Responde SIEMPRE HTTP 200 con `{estado, mensaje, activa}`: el estado dice qué pasó y el SPA decide qué mostrar.
 */
class ClientAgentesIaController extends Controller
{
    /**
     * Estado actual de «Agentes de IA» en el sistema del cliente.
     *
     * @param  ClientAgentesIaService $servicio  Servicio que habla con el empresa-api del cliente.
     * @param  int|string             $clientId  Id numérico o uuid del cliente.
     * @return \Illuminate\Http\JsonResponse
     */
    public function show_json(ClientAgentesIaService $servicio, $clientId)
    {
        return response()->json($servicio->consultar($this->buscar_cliente($clientId)));
    }

    /**
     * Activa o quita «Agentes de IA» en el sistema del cliente.
     *
     * @param  Request                $request   Cuerpo `{activa: bool}`.
     * @param  ClientAgentesIaService $servicio  Servicio que habla con el empresa-api del cliente.
     * @param  int|string             $clientId  Id numérico o uuid del cliente.
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_json(Request $request, ClientAgentesIaService $servicio, $clientId)
    {
        $datos = $request->validate([
            'activa' => ['required', 'boolean'],
        ]);

        $client = $this->buscar_cliente($clientId);

        return response()->json($servicio->cambiar($client, (bool) $datos['activa']));
    }

    /**
     * @param  int|string $clientId Id numérico o uuid.
     * @return Client
     */
    protected function buscar_cliente($clientId)
    {
        return is_numeric($clientId)
            ? Client::findOrFail((int) $clientId)
            : Client::where('uuid', (string) $clientId)->firstOrFail();
    }
}
