<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Lead;
use App\Services\Prueba\PruebaCicloService;
use App\Services\Prueba\PruebaVencimientoService;
use App\Services\PruebaDelClienteService;
use App\Services\PruebaWhatsappService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API JSON (Sanctum) del SEGUIMIENTO de la prueba en la pestaña «Prueba» del modal del lead y de la ficha
 * del cliente en admin-spa (misión cruzada prueba-martin-importacion, 3/11/2026): cuántos días quedan, en qué
 * hito va (C3, por el puente del admin, con fallback a los eventos de C2), qué WhatsApps de la prueba salieron,
 * y el botón «Avisar a Tomás».
 *
 * Rutas: `GET client/{id}/prueba/seguimiento`, `GET lead/{id}/prueba/seguimiento` y `POST .../prueba/avisar-a-tomas`.
 * La solapa en sí (estado, URL, instancia y los días editables) es `GET/PUT .../prueba` de `PruebaInstanciaController`
 * (misión prueba-alta-instancia): `PruebaSeguimiento.vue` se monta adentro de esa solapa.
 *
 * Molde: `ClientSessionLockController` (GET/PUT client/{clientId}/candado-sesion).
 *
 * 🔴 El GET le pega EN VIVO a la instancia del cliente (C3) al abrirse la pestaña, igual que Imágenes e
 * IA: como el modal monta las solapas recién cuando se abren, esa consulta no sale si nadie entra acá.
 */
class PruebaController extends Controller
{
    private const MAX_CUPO_IMAGENES = 10000;

    /**
     * El estado de la prueba de un cliente.
     *
     * @param int|string $clientId Id numérico o uuid del cliente.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function client_json($clientId)
    {
        $client = $this->find_client_by_route_id($clientId);

        return response()->json($this->payload($client, null));
    }

    /**
     * El estado de la prueba de un lead (su cliente en prueba, cruzado por `promoted_client_id` o teléfono).
     *
     * @param int $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function lead_json($id)
    {
        $lead = Lead::findOrFail($id);

        return response()->json($this->payload(null, $lead));
    }

    public function cupo_imagenes_client_json(Request $request, $clientId): JsonResponse
    {
        return $this->cambiar_cupo_imagenes($request, $this->find_client_by_route_id($clientId));
    }

    public function cupo_imagenes_lead_json(Request $request, $id): JsonResponse
    {
        $lead = Lead::findOrFail($id);
        $client = app(PruebaDelClienteService::class)->cliente_del_lead($lead);

        return $this->cambiar_cupo_imagenes($request, $client);
    }

    private function cambiar_cupo_imagenes(Request $request, ?Client $client): JsonResponse
    {
        if (! PruebaCicloService::esta_en_prueba($client)) {
            return response()->json(['message' => 'Este negocio no está en prueba gratuita.'], 422);
        }

        $tope = $request->input('tope');
        if (! is_int($tope) || $tope < 0 || $tope > self::MAX_CUPO_IMAGENES) {
            $mensaje = 'Ingresá un número entero entre 0 y ' . self::MAX_CUPO_IMAGENES . '.';

            return response()->json(['message' => $mensaje, 'errors' => ['tope' => [$mensaje]]], 422);
        }

        $respuesta = app(PruebaVencimientoService::class)->enviar_cupo_imagenes($client, $tope);
        $estado = $respuesta['estado'];
        $imagenes = PruebaVencimientoService::leer_cupo_imagenes($respuesta);

        if ($estado === PruebaVencimientoService::ESTADO_SIN_RUTA || ($estado === PruebaVencimientoService::ESTADO_OK && $imagenes === null)) {
            return response()->json([
                'estado'  => 'version_vieja',
                'message' => 'La versión del sistema del negocio todavía no permite editar el cupo de imágenes.',
            ], 409);
        }

        if ($estado !== PruebaVencimientoService::ESTADO_OK || $imagenes['tope'] !== $tope) {
            return response()->json(['message' => 'El sistema del negocio no confirmó el cambio de cupo. Actualizá la prueba e intentá de nuevo.'], 502);
        }

        return response()->json(['imagenes' => $imagenes]);
    }

    /**
     * Botón «Avisar a Tomás» desde la ficha del cliente.
     *
     * @param Request    $request
     * @param int|string $clientId
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function avisar_a_tomas_client_json(Request $request, $clientId)
    {
        $client = $this->find_client_by_route_id($clientId);

        return $this->avisar($client, null);
    }

    /**
     * Botón «Avisar a Tomás» desde el modal del lead.
     *
     * @param Request $request
     * @param int     $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function avisar_a_tomas_lead_json(Request $request, $id)
    {
        $lead = Lead::findOrFail($id);

        return $this->avisar(null, $lead);
    }

    /**
     * El payload de la pestaña, para un cliente o para un lead.
     *
     * @param Client|null $client
     * @param Lead|null   $lead
     *
     * @return array<string, mixed>
     */
    protected function payload(?Client $client, ?Lead $lead): array
    {
        $pruebas = app(PruebaDelClienteService::class);

        if ($client === null && $lead !== null) {
            $client = $pruebas->cliente_del_lead($lead);
        } elseif ($lead === null && $client !== null) {
            $lead = $pruebas->lead_del_cliente($client);
        }

        if ($client === null || ! $pruebas->es_cliente_en_prueba($client)) {
            /* Un lead en `prueba_activa` cuya instancia todavía se está armando (M2) no tiene cliente: se dice. */
            $armando = $lead !== null && (string) $lead->status === PruebaDelClienteService::LEAD_STATUS_PRUEBA;

            return [
                'es_prueba' => false,
                'armando'   => $armando,
                'lead_id'   => $lead !== null ? (int) $lead->id : null,
                'client_id' => $client !== null ? (int) $client->id : null,
                'mensaje'   => $armando
                    ? 'El lead está en prueba, pero su sistema todavía no figura como cliente en prueba (se está armando).'
                    : 'No está en prueba gratuita.',
            ];
        }

        $resumen = $pruebas->resumen($client, $lead, true);

        $resumen['client_nombre'] = $client->resolve_display_name();
        $resumen['lead_nombre']   = $lead !== null ? trim((string) $lead->contact_name) : null;
        $resumen['lead_status']   = $lead !== null ? (string) $lead->status : null;
        $resumen['hitos_catalogo'] = PruebaDelClienteService::HITOS;
        $resumen['avisos_tomas']  = array_values(array_filter($resumen['envios'], function ($envio) {
            return $envio['plantilla'] === \App\Models\PruebaWhatsappEnvio::PLANTILLA_AVISO_TOMAS;
        }));

        return $resumen;
    }

    /**
     * Manda el aviso manual a Tomás.
     *
     * @param Client|null $client
     * @param Lead|null   $lead
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function avisar(?Client $client, ?Lead $lead)
    {
        $pruebas = app(PruebaDelClienteService::class);

        if ($client === null && $lead !== null) {
            $client = $pruebas->cliente_del_lead($lead);
        } elseif ($lead === null && $client !== null) {
            $lead = $pruebas->lead_del_cliente($client);
        }

        if ($client === null || ! $pruebas->es_cliente_en_prueba($client)) {
            return response()->json([
                'avisado' => false,
                'error'   => 'no_es_prueba',
                'message' => 'No está en prueba gratuita: no hay a quién avisar.',
            ], 422);
        }

        $resultado = app(PruebaWhatsappService::class)->avisar_a_tomas($client, $lead, PruebaWhatsappService::TOMAS_MANUAL);

        return response()->json([
            'avisado' => $resultado['estado'] === 'avisado',
            'estado'  => $resultado['estado'],
            'detalle' => $resultado['detalle'],
        ], $resultado['estado'] === 'avisado' ? 200 : 422);
    }

    /**
     * Cliente por id numérico o uuid (misma regla que las otras pestañas del modal del cliente).
     *
     * @param int|string $route_id
     *
     * @return Client
     */
    private function find_client_by_route_id($route_id)
    {
        $query = Client::query();

        if (is_numeric($route_id)) {
            return $query->findOrFail((int) $route_id);
        }

        return $query->where('uuid', (string) $route_id)->firstOrFail();
    }
}
