<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuración del agente analizador: presupuesto Meta, hora del reporte y retención de archivos.
 */
class AgentSettingsController extends Controller
{
    /**
     * Devuelve los tres settings de configuración del agente en un único objeto.
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        return response()->json([
            /* Presupuesto diario Meta Ads en USD para calcular costo por lead. */
            'meta_daily_budget_usd'       => (float) AdminSetting::get('meta_daily_budget_usd', 7),

            /* Hora de generación del reporte diario (formato 24hs, sin minutos). */
            'agent_report_hour'           => (int) AdminSetting::get('agent_report_hour', 8),

            /* Días de retención de los archivos markdown de reporte. */
            'agent_report_retention_days' => (int) AdminSetting::get('agent_report_retention_days', 90),
        ]);
    }

    /**
     * Actualiza uno o varios settings del agente.
     *
     * @param Request $request Campos opcionales: meta_daily_budget_usd, agent_report_hour, agent_report_retention_days.
     *
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'meta_daily_budget_usd'       => 'sometimes|numeric|min:0',
            'agent_report_hour'           => 'sometimes|integer|min:0|max:23',
            'agent_report_retention_days' => 'sometimes|integer|min:7|max:365',
        ]);

        /* Persistir cada setting que venga en el request. */
        if (isset($validated['meta_daily_budget_usd'])) {
            AdminSetting::set('meta_daily_budget_usd', (string) $validated['meta_daily_budget_usd']);
        }

        if (isset($validated['agent_report_hour'])) {
            AdminSetting::set('agent_report_hour', (string) $validated['agent_report_hour']);
        }

        if (isset($validated['agent_report_retention_days'])) {
            AdminSetting::set('agent_report_retention_days', (string) $validated['agent_report_retention_days']);
        }

        /* Devolver el estado actual de los tres settings tras la actualización. */
        return response()->json([
            'meta_daily_budget_usd'       => (float) AdminSetting::get('meta_daily_budget_usd', 7),
            'agent_report_hour'           => (int) AdminSetting::get('agent_report_hour', 8),
            'agent_report_retention_days' => (int) AdminSetting::get('agent_report_retention_days', 90),
        ]);
    }
}
