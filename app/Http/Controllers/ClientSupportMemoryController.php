<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientSupportMemoryEntry;
use App\Services\ClientMemory\ClientSupportMemoryService;
use App\Services\ClientMemory\FichaClienteException;
use Illuminate\Http\Request;

/**
 * API JSON (Sanctum) de la sección «Ficha de soporte» del cliente en admin-spa (misión cruzada `ficha-del-cliente`,
 * 11/11/2026): Lucas ve, corrige y completa la memoria que Martín va escribiendo de cada cliente, y edita la
 * `ficha_operativa` (su prosa).
 *
 * Molde: `ClientScheduleController` (`client/{clientId}/horarios`): `{clientId}` numérico o uuid. Las reglas viven en
 * `ClientSupportMemoryService` (las mismas que usa Martín): «editar» = reemplazar con `source: lucas` (la vieja queda
 * `reemplazada`), «dar de baja» = archivar con motivo. Los frenos del servicio salen como `{error, motivo, message}`
 * con su status (422 `ficha_llena`, `dato_sensible`, `entrada_ajena`, `entrada_no_vigente`...); la validación, con la
 * forma estándar del panel (`{message, errors}`).
 *
 * Todas las respuestas de escritura devuelven el mismo payload que el GET (ya releído), para que la SPA repinte sin
 * otro pedido.
 */
class ClientSupportMemoryController extends Controller
{
    /** @var ClientSupportMemoryService */
    private $memoria;

    /**
     * @param ClientSupportMemoryService $memoria
     */
    public function __construct(ClientSupportMemoryService $memoria)
    {
        $this->memoria = $memoria;
    }

    /**
     * `GET admin/client/{clientId}/support-memory[?incluir_historial=0]` — por defecto TODAS las entradas (con su
     * `status`), las reemplazadas y archivadas incluidas, más la ficha operativa y los tickets del cliente.
     *
     * @param Request    $request  El pedido.
     * @param int|string $clientId Id o uuid del cliente.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request, $clientId)
    {
        $client = $this->cliente($clientId);

        return response()->json($this->payload($client, $request->has('incluir_historial') ? $request->boolean('incluir_historial') : true));
    }

    /**
     * `POST admin/client/{clientId}/support-memory` — agregar: `{categoria, texto, vigente_hasta?}`.
     *
     * @param Request    $request  El pedido.
     * @param int|string $clientId Id o uuid del cliente.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request, $clientId)
    {
        $client = $this->cliente($clientId);
        $datos  = $request->validate([
            'categoria'     => 'required|string|in:' . implode(',', ClientSupportMemoryEntry::CATEGORIAS),
            'texto'         => 'required|string|max:' . ClientSupportMemoryService::LARGO_TEXTO,
            'vigente_hasta' => 'sometimes|nullable|date_format:Y-m-d',
        ]);

        return $this->operar($request, $client, array_merge(['accion' => 'agregar'], $datos), 201);
    }

    /**
     * `PUT admin/client/{clientId}/support-memory/{entry}` — editar = reemplazar: `{texto, categoria?, vigente_hasta?}`.
     *
     * @param Request    $request  El pedido.
     * @param int|string $clientId Id o uuid del cliente.
     * @param int|string $entry    La entrada.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $clientId, $entry)
    {
        $client = $this->cliente($clientId);
        $datos  = $request->validate([
            'texto'         => 'required|string|max:' . ClientSupportMemoryService::LARGO_TEXTO,
            'categoria'     => 'sometimes|nullable|string|in:' . implode(',', ClientSupportMemoryEntry::CATEGORIAS),
            'vigente_hasta' => 'sometimes|nullable|date_format:Y-m-d',
        ]);

        return $this->operar($request, $client, array_merge(['accion' => 'reemplazar', 'id' => (int) $entry], $datos), 200);
    }

    /**
     * `POST admin/client/{clientId}/support-memory/{entry}/archive` — dar de baja: `{motivo}`.
     *
     * @param Request    $request  El pedido.
     * @param int|string $clientId Id o uuid del cliente.
     * @param int|string $entry    La entrada.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function archive(Request $request, $clientId, $entry)
    {
        $client = $this->cliente($clientId);
        $datos  = $request->validate([
            'motivo' => 'required|string|max:' . ClientSupportMemoryService::LARGO_MOTIVO,
        ]);

        return $this->operar($request, $client, ['accion' => 'archivar', 'id' => (int) $entry, 'motivo' => $datos['motivo']], 200);
    }

    /**
     * `GET admin/client/{clientId}/support-memory/ficha-operativa`.
     *
     * @param int|string $clientId Id o uuid del cliente.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ficha_operativa($clientId)
    {
        $client = $this->cliente($clientId);

        return response()->json([
            'client_id'       => (int) $client->id,
            'ficha_operativa' => \App\Models\ClientSupportContext::ficha_operativa_de_cliente((int) $client->id),
        ]);
    }

    /**
     * `PUT admin/client/{clientId}/support-memory/ficha-operativa` — `{ficha_operativa: string|null}` (≤ 20.000;
     * vacío = la borra). Solo esa columna: las notas internas no se tocan.
     *
     * @param Request    $request  El pedido.
     * @param int|string $clientId Id o uuid del cliente.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function guardar_ficha_operativa(Request $request, $clientId)
    {
        $client = $this->cliente($clientId);
        $request->validate([
            'ficha_operativa' => 'present|nullable|string|max:20000',
        ]);

        return response()->json([
            'client_id'       => (int) $client->id,
            'ficha_operativa' => $this->memoria->guardar_ficha_operativa((int) $client->id, $request->input('ficha_operativa')),
        ]);
    }

    /**
     * Una operación de Lucas y el payload del GET releído.
     *
     * @param Request              $request   El pedido (para el admin).
     * @param Client               $client    El cliente.
     * @param array<string, mixed> $operacion La operación.
     * @param int                  $status    Status del éxito.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function operar(Request $request, Client $client, array $operacion, $status)
    {
        $admin = $request->user();

        try {
            $resultado = $this->memoria->operar_como_lucas((int) $client->id, [$operacion], $admin === null ? null : (int) $admin->id);
        } catch (FichaClienteException $e) {
            return response()->json($e->cuerpo(), $e->status());
        }

        return response()->json(array_merge(
            ['ok' => true, 'resultado' => $resultado['resultados'][0]],
            $this->payload($client, true)
        ), $status);
    }

    /**
     * @param Client $client            El cliente.
     * @param bool   $incluir_historial true = también las reemplazadas y archivadas.
     *
     * @return array<string, mixed>
     */
    private function payload(Client $client, $incluir_historial)
    {
        return array_merge($this->memoria->listar((int) $client->id, $incluir_historial), [
            'tickets' => $this->memoria->tickets_para_el_panel((int) $client->id),
        ]);
    }

    /**
     * @param int|string $route_id Id numérico o uuid.
     *
     * @return Client
     */
    private function cliente($route_id)
    {
        if (is_numeric($route_id)) {
            return Client::findOrFail((int) $route_id);
        }

        return Client::where('uuid', (string) $route_id)->firstOrFail();
    }
}
