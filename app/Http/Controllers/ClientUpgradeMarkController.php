<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\BaseController;
use App\Models\ClientUpgradeMark;
use App\Services\ClientUpgradeMarkService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Marcas «requiere actualización» de los clientes en el panel (misión martin-descripcion-y-actualizaciones,
 * 25/11/2026, contrato C6). Las usan admin-spa (columna, botón y pestaña del cliente), OpenClaw (informe de las
 * 9:00 y 19:00) y `misiones_cc.py publicar-marcas`. La lógica vive en `ClientUpgradeMarkService`.
 *
 * Las tres rutas van en el grupo `auth:sanctum` + `admin.solo_leads`; con el token de un agente quedan `lectura`
 * (GET) y `escritura` (POST/PATCH), sin código de confirmación y auditadas por `PanelAgentGuard`.
 */
class ClientUpgradeMarkController extends BaseController
{
    /** Repos cuyos commits pueden viajar en una marca (los que se instalan en el cliente). */
    const REPOS = ['empresa-api', 'empresa-spa', 'tienda-api', 'tienda-spa'];

    /**
     * Listado agrupado por cliente: `?estado=abiertas|resueltas|todas` (default abiertas) y `?client_id=N`.
     *
     * @param Request                  $request
     * @param ClientUpgradeMarkService $service
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request, ClientUpgradeMarkService $service)
    {
        $data = $request->validate([
            'estado' => 'sometimes|in:abiertas,resueltas,todas',
            'client_id' => 'sometimes|integer|min:1',
        ]);

        return response()->json($service->listing($data['estado'] ?? 'abiertas', isset($data['client_id']) ? (int) $data['client_id'] : null));
    }

    /**
     * Alta idempotente por `external_key`: 201 `{mark, created: true}` o, si la clave ya existía, 200
     * `{mark, created: false}` sin pisar nada. La validación va acá (y no sólo en el front) porque la llaman agentes.
     *
     * El origen se deduce: una clave `cc:<slug>` viene de una misión de Claude Code; el resto es manual. Las de
     * soporte nacen solas desde la evidencia de la misión y no pasan por acá.
     *
     * @param Request                  $request
     * @param ClientUpgradeMarkService $service
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request, ClientUpgradeMarkService $service)
    {
        $data = $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'title' => 'required|string|min:3|max:255',
            'kind' => 'sometimes|in:bug,funcionalidad',
            'target' => 'sometimes|in:sistema,tienda',
            'detail' => 'sometimes|nullable|string|max:1000',
            'external_key' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9:._-]+$/'],
            'commits' => ['sometimes', 'nullable', 'array:' . implode(',', self::REPOS)],
            'commits.*' => ['required', 'string', 'regex:/^[0-9a-f]{40}$/'],
            'merged_at' => 'sometimes|nullable|date',
            'support_ticket_id' => 'sometimes|nullable|integer|min:1',
        ]);
        $key = $data['external_key'] ?? null;
        $data['source'] = $key !== null && strncmp($key, 'cc:', 3) === 0 ? 'claude_code' : 'manual';
        list($mark, $created) = $service->create_or_get($data);

        return response()->json(['mark' => $this->fullModel('client_upgrade_mark', $mark->id)->to_api_array(), 'created' => $created],
            $created ? 201 : 200);
    }

    /**
     * Resolución manual (`manual` o `descartada`, con nota opcional). Ya resuelta → 200 sin cambios.
     *
     * @param Request                  $request
     * @param int|string               $id
     * @param ClientUpgradeMarkService $service
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function resolve(Request $request, $id, ClientUpgradeMarkService $service)
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(['manual', 'descartada'])],
            'note' => 'sometimes|nullable|string|max:300',
        ]);
        $mark = $service->resolve(ClientUpgradeMark::findOrFail($id), $data['reason'], $data['note'] ?? null);

        return response()->json(['mark' => $this->fullModel('client_upgrade_mark', $mark->id)->to_api_array()]);
    }
}
