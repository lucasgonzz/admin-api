<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\ClientModelosIaSyncService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * API JSON (Sanctum) de la solapa "Inteligencia artificial" de la ficha del cliente (misión
 * modelos-ia-por-cliente, 30/9/2026): qué modelo usa cada tarea de IA en el sistema del cliente.
 *
 * Molde: `ClientImagenesController` — los dos endpoints salen a la red y le preguntan EN VIVO al
 * `empresa-api` del cliente. Por eso la respuesta es siempre `{estado, mensaje, datos, errores}` con
 * HTTP 200 cuando el admin hizo bien su parte (el cliente pudo estar viejo —`no_soportado`— o caído
 * —`failed`— y la solapa lo dice), y solo es un 4xx cuando el problema es del pedido al admin (422
 * sin ninguna tarea, 404 cliente inexistente).
 *
 * 🔴 **No se persiste nada en el admin** (decisión 2 de Lucas: "los dos, gana el último"). El dueño
 * puede cambiar su asistente desde su propio sistema en cualquier momento; lo que vale es lo que
 * quedó escrito allá. Ver el docblock de `ClientModelosIaSyncService`.
 */
class ClientModelosIaController extends Controller
{
    /**
     * GET client/{clientId}/modelos-ia
     *
     * Lo que el cliente tiene elegido por tarea y lo que corre de verdad (con el fallback por falta
     * de clave a la vista).
     *
     * @param int|string                 $clientId Id numérico o uuid del cliente.
     * @param ClientModelosIaSyncService $servicio Inyectado por el IoC de Laravel.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function show_json($clientId, ClientModelosIaSyncService $servicio)
    {
        $client = $this->find_client_by_route_id($clientId);

        return response()->json($servicio->traer($client));
    }

    /**
     * PUT client/{clientId}/modelos-ia
     *
     * Body: cualquier subconjunto de `asistente`, `whatsapp`, `imagenes`, `excel` con el id de la
     * opción elegida. Viajan solo las tareas que vinieron (una ausente no se toca del otro lado).
     *
     * 🔴 El admin NO valida que la opción exista ni que valga para la tarea: el catálogo vive en el
     * `empresa-api` y su 422 es la respuesta autorizada (se muestra tal cual en la fila). Duplicar la
     * lista acá dejaría al admin rechazando un modelo nuevo que el cliente ya acepta, o al revés.
     * Solo se exige lo que no depende del catálogo: que sea texto corto y que venga al menos una.
     *
     * @param Request                    $request  Pedido con las tareas a cambiar.
     * @param int|string                 $clientId Id numérico o uuid del cliente.
     * @param ClientModelosIaSyncService $servicio Inyectado por el IoC de Laravel.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_json(Request $request, $clientId, ClientModelosIaSyncService $servicio)
    {
        $client = $this->find_client_by_route_id($clientId);

        $reglas = [];

        foreach (ClientModelosIaSyncService::TAREAS as $tarea) {
            // 30 caracteres: el mismo largo de las columnas `ia_modelo_*` del lado de empresa.
            $reglas[$tarea] = ['sometimes', 'required', 'string', 'max:30'];
        }

        $datos = $request->validate($reglas);

        if ($datos === []) {
            // Un PUT vacío no cambiaría nada del otro lado: es un error del pedido, no un "guardado".
            throw ValidationException::withMessages([
                'tareas' => ['Elegí el modelo de al menos una tarea (asistente, whatsapp, imagenes o excel).'],
            ]);
        }

        return response()->json($servicio->enviar($client, $datos));
    }

    /**
     * Busca el Client por id numérico o uuid (mismo criterio que `ClientImagenesController`).
     *
     * @param int|string $route_id Id numérico o uuid.
     *
     * @return Client
     */
    private function find_client_by_route_id($route_id)
    {
        if (is_numeric($route_id)) {
            return Client::findOrFail((int) $route_id);
        }

        return Client::where('uuid', (string) $route_id)->firstOrFail();
    }
}
