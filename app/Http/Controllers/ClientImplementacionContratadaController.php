<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\ImplementacionContratadaService;
use App\Services\Pipelines\PipelineRuleException;
use Illuminate\Http\Request;

/**
 * La implementación contratada desde la ficha del cliente (misión embudo-juan-pipeline, E2). Sin lógica de negocio: está en
 * {@see ImplementacionContratadaService}, que valida (422), pausa, reanuda y crea las tareas.
 *
 *  - `PUT client/{id}/implementacion-contratada` {estado, paquetes}: fija el estado.
 *  - `POST client/{id}/implementacion/articulos-importados`: «Artículos importados OK».
 *  - `POST client/{id}/prueba/reanudar`: «Reanudar prueba (7 días desde hoy)»; 409 si no está pausada.
 */
class ClientImplementacionContratadaController extends Controller
{
    /**
     * Fija el estado de la implementación contratada.
     *
     * @param Request                        $request `estado` y `paquetes`.
     * @param int|string                     $id      Id del cliente.
     * @param ImplementacionContratadaService $servicio
     *
     * @return \Illuminate\Http\JsonResponse 200 `{model}` · 422 `{message, errors}`
     *
     * @throws PipelineRuleException
     */
    public function fijar_json(Request $request, $id, ImplementacionContratadaService $servicio)
    {
        $client = Client::query()->findOrFail((int) $id);

        $paquetes = $request->input('paquetes', []);
        if (! is_array($paquetes)) {
            throw PipelineRuleException::validacion(['paquetes' => ['Los paquetes tienen que ser una lista.']]);
        }

        $client = $servicio->fijar($client, (string) $request->input('estado', ''), $paquetes, $request->user());

        return response()->json(['model' => $this->como_json($client, $servicio)], 200);
    }

    /**
     * «Artículos importados OK».
     *
     * @param Request                         $request
     * @param int|string                      $id
     * @param ImplementacionContratadaService $servicio
     *
     * @return \Illuminate\Http\JsonResponse 200 `{model}`
     */
    public function articulos_importados_json(Request $request, $id, ImplementacionContratadaService $servicio)
    {
        $client = Client::query()->findOrFail((int) $id);

        return response()->json(['model' => $this->como_json($servicio->articulos_importados_ok($client, $request->user()), $servicio)], 200);
    }

    /**
     * Reanuda la prueba pausada, con `desde = ahora`.
     *
     * @param int|string                      $id
     * @param ImplementacionContratadaService $servicio
     *
     * @return \Illuminate\Http\JsonResponse 200 `{model}` · 409 `{message}`
     *
     * @throws PipelineRuleException
     */
    public function reanudar_json($id, ImplementacionContratadaService $servicio)
    {
        $client = Client::query()->findOrFail((int) $id);

        return response()->json(['model' => $this->como_json($servicio->reanudar_prueba($client), $servicio)], 200);
    }

    /**
     * El cliente con los campos de la implementación contratada (y nada de sus claves).
     *
     * @param Client                          $client
     * @param ImplementacionContratadaService $servicio
     *
     * @return array<string, mixed>
     */
    private function como_json(Client $client, ImplementacionContratadaService $servicio): array
    {
        return array_merge(
            [
                'id'            => (int) $client->id,
                'estado'        => (string) $client->estado,
                'trial_days'    => $client->trial_days === null ? null : (int) $client->trial_days,
                'trial_ends_at' => $client->trial_ends_at === null ? null : \Carbon\Carbon::parse($client->trial_ends_at)->toIso8601String(),
            ],
            (array) $servicio->bloque_para_soporte($client)
        );
    }
}
