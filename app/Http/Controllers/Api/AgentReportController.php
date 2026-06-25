<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentDailyReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * Controlador para listar, descargar y generar manualmente los reportes diarios del agente.
 * Sirve datos al módulo Agente del panel admin-spa.
 */
class AgentReportController extends Controller
{
    /**
     * Devuelve los últimos 30 reportes ordenados por fecha descendente.
     * No incluye el contenido markdown (solo metadatos para el listado del panel).
     *
     * @return JsonResponse
     */
    public function index_json(): JsonResponse
    {
        /* Últimos 30 reportes ordenados del más reciente al más viejo. */
        $reports = AgentDailyReport::orderByDesc('report_date')
            ->limit(30)
            ->get([
                'id',
                'report_date',
                'report_type',
                'executive_summary',
                'alert_count',
                'active_leads_count',
                'metrics_snapshot',
                'created_at',
            ]);

        /* Agregar la URL de descarga a cada reporte para que el frontend pueda usarla directamente. */
        $reports->each(function ($report) {
            $report->download_url = $report->download_url();
        });

        return response()->json($reports);
    }

    /**
     * Descarga el archivo markdown de un reporte específico.
     * Retorna error 404 si el reporte no existe o el archivo fue eliminado.
     *
     * @param int $id ID del reporte.
     *
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\JsonResponse
     */
    public function download(int $id)
    {
        /* Buscar el reporte o retornar 404. */
        $report = AgentDailyReport::findOrFail($id);

        /* Verificar que el archivo físico existe en storage. */
        if (!$report->file_path || !Storage::exists($report->file_path)) {
            return response()->json([
                'error' => 'El archivo del reporte no está disponible.',
            ], 404);
        }

        /* Nombre de descarga amigable: reporte-comerciocity-YYYY-MM-DD.md */
        $download_name = 'reporte-comerciocity-' . $report->report_date->format('Y-m-d') . '.md';

        return response()->download(
            Storage::path($report->file_path),
            $download_name,
            ['Content-Type' => 'text/markdown; charset=utf-8']
        );
    }

    /**
     * Genera manualmente el reporte para una fecha específica (para testing o regeneración).
     * Retorna error si el reporte para esa fecha ya existe.
     *
     * @param Request $request Puede incluir 'date' en formato YYYY-MM-DD.
     *
     * @return JsonResponse
     */
    public function generate_json(Request $request): JsonResponse
    {
        /* Fecha a generar: la del request o ayer si no se indica. */
        $date = $request->input('date');

        /* Opciones del comando artisan. */
        $artisan_options = [];
        if ($date) {
            $artisan_options['--date'] = $date;
        }

        /* Correr el comando y capturar el resultado. */
        $exit_code = Artisan::call('agent:generate-daily-report', $artisan_options);

        if ($exit_code !== 0) {
            return response()->json([
                'error'  => 'Error generando el reporte. Revisar logs para más detalles.',
                'output' => Artisan::output(),
            ], 500);
        }

        /* Determinar la fecha efectiva para devolver el reporte creado. */
        $report_date = $date ?? now()->subDay()->toDateString();

        /* Obtener el reporte recién creado. */
        $report = AgentDailyReport::where('report_date', $report_date)->first();

        if (!$report) {
            return response()->json([
                'message' => Artisan::output(),
            ], 200);
        }

        $report->download_url = $report->download_url();

        return response()->json($report, 201);
    }
}
