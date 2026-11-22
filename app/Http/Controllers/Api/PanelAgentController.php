<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PanelAgent\PanelAgentAuditLogger;
use App\Services\PanelAgent\PanelAgentCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lectura del acceso de agentes al panel (misión admin-control-total): catálogo de operaciones y auditoría.
 *
 * Sin lógica propia: el catálogo lo arma `PanelAgentCatalog` y la auditoría la lee `PanelAgentAuditLogger`.
 * Sin `validate()`: los filtros se normalizan en los servicios y uno ilegible se ignora.
 */
class PanelAgentController extends Controller
{
    /**
     * Operaciones del panel con su categoría y si están frenadas.
     *
     * @param  Request           $request Query: buscar, categoria, metodo.
     * @param  PanelAgentCatalog $catalog Catálogo derivado del router.
     * @return JsonResponse {operaciones, resumen, como_confirmar}
     */
    public function operations_json(Request $request, PanelAgentCatalog $catalog): JsonResponse
    {
        return response()->json($catalog->build($request->only(['buscar', 'categoria', 'metodo'])), 200);
    }

    /**
     * Auditoría de los pedidos de los agentes, de la más nueva a la más vieja.
     *
     * @param  Request                $request Query: admin_id, resultado, categoria, desde, limite.
     * @param  PanelAgentAuditLogger  $logger  Auditoría.
     * @return JsonResponse {auditoria: [...]}
     */
    public function audit_json(Request $request, PanelAgentAuditLogger $logger): JsonResponse
    {
        return response()->json(
            ['auditoria' => $logger->search($request->only(['admin_id', 'resultado', 'categoria', 'desde', 'limite']))],
            200
        );
    }
}
