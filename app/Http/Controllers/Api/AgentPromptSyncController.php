<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgentPromptSyncService;
use Illuminate\Http\JsonResponse;

/**
 * POST /settings/agent-prompts/sync
 * Descarga los archivos de prompt desde GitHub y actualiza la BD.
 */
class AgentPromptSyncController extends Controller
{
    /**
     * Ejecuta la sincronización de prompts de agentes desde GitHub.
     *
     * @param AgentPromptSyncService $service Servicio que descarga y persiste los archivos
     * @return JsonResponse Resultado por archivo y flag ok global
     */
    public function sync(AgentPromptSyncService $service): JsonResponse
    {
        $results = $service->sync();

        // Éxito global solo si todos los archivos se sincronizaron correctamente.
        $all_ok = collect($results)->every(function ($r) {
            return $r['ok'];
        });

        return response()->json([
            'ok'      => $all_ok,
            'results' => $results,
        ], $all_ok ? 200 : 500);
    }

    /**
     * GET /settings/agent-prompts/files
     * Devuelve la lista real de archivos sincronizables (fuente: AgentPromptSyncService::FILES),
     * para que el frontend la muestre sin texto hardcodeado.
     *
     * @return JsonResponse
     */
    public function files(): JsonResponse
    {
        return response()->json([
            'files' => AgentPromptSyncService::files_summary(),
        ]);
    }
}
