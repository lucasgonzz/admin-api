<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\ClientImageSearchLogService;
use Illuminate\Http\Request;

/**
 * API JSON (Sanctum) del registro de consultas de imágenes de un cliente, para la solapa
 * "Imágenes" de la ficha del cliente en admin-spa (misión imagenes-catalogo-completo, §12.2).
 *
 * Molde: `ClientTokensController` (mismo grupo de rutas, mismo `find_client_by_route_id`). La
 * diferencia de fondo es la que explica `ClientImageSearchLogService`: acá no hay espejo local, así
 * que LOS DOS endpoints salen a la red y le preguntan en vivo al `empresa-api` del cliente. Por eso
 * la respuesta es siempre `{estado, mensaje, datos}` con HTTP 200 cuando el admin hizo bien su
 * parte —el cliente pudo estar viejo (`no_soportado`) o caído (`error`), y la solapa lo dice—, y
 * solo es un 4xx cuando el problema es del pedido (422 rango o filtro inválido, 404 cliente
 * inexistente).
 *
 * Este controlador no decide nada: resuelve el cliente y le pasa la entrada al servicio, que valida,
 * pregunta y costea.
 */
class ClientImagenesController extends Controller
{
    /**
     * GET client/{clientId}/imagenes/resumen?desde=AAAA-MM-DD&hasta=AAAA-MM-DD
     *
     * Totales, días, modelos y asignaciones del rango, ya costeados. Sin fechas: los últimos 30 días.
     *
     * @param Request                     $request  Pedido con `desde` y `hasta` opcionales.
     * @param int|string                  $clientId Id numérico o uuid del cliente.
     * @param ClientImageSearchLogService $servicio Inyectado por el IoC de Laravel.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function resumen_json(Request $request, $clientId, ClientImageSearchLogService $servicio)
    {
        $client = $this->find_client_by_route_id($clientId);

        // Lanza 422 si el rango no sirve, antes de salir a la red.
        $rango = $servicio->resolver_rango($request->query('desde'), $request->query('hasta'));

        return response()->json($servicio->resumen($client, $rango['desde'], $rango['hasta']));
    }

    /**
     * GET client/{clientId}/imagenes/consultas?desde=&hasta=&tipo=&asignacion=&solo_errores=&page=&per_page=
     *
     * Una página del registro, consulta por consulta, con el costo de cada una.
     *
     * @param Request                     $request  Pedido con los filtros opcionales.
     * @param int|string                  $clientId Id numérico o uuid del cliente.
     * @param ClientImageSearchLogService $servicio Inyectado por el IoC de Laravel.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function consultas_json(Request $request, $clientId, ClientImageSearchLogService $servicio)
    {
        $client = $this->find_client_by_route_id($clientId);

        // Lista blanca de filtros: lo que no conoce el contrato no viaja. Lanza 422 si algo no sirve.
        $filtros = $servicio->filtros_de_consultas($request->query());

        return response()->json($servicio->consultas($client, $filtros));
    }

    /**
     * Busca el Client por id numérico o uuid (mismo criterio que `ClientTokensController`).
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
