<?php

namespace App\Http\Controllers\Api;

use App\Helpers\AppTime;
use App\Http\Controllers\Controller;
use App\Services\AudienciasMetaService;
use Illuminate\Http\Request;

/**
 * `GET claude/audiencias`: los públicos personalizados para Meta armados con el pipeline (misión
 * origen-del-lead-y-conversiones-meta, 13/10/2026). Lo consume M6b para subirlos a Meta.
 *
 * Contrato:
 *
 *     GET claude/audiencias?etapa=<slug>&dias=14&formato=json|csv   (header X-Claude-Task-Key)
 *     JSON → {etapa, dias, cantidad, filas: [{phone_sha256, email_sha256}], descripcion}
 *     CSV  → encabezado `phone,email`, los dos ya hasheados
 *     422  → con `etapas_validas` si la etapa no existe
 *
 * 🔴 Solo lectura, y nada viaja en claro: teléfono y mail salen como SHA-256 (ver
 * AudienciasMetaService).
 */
class ClaudeAudienciasController extends Controller
{
    /**
     * @param Request               $request
     * @param AudienciasMetaService $service
     *
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function index(Request $request, AudienciasMetaService $service)
    {
        $etapa = $request->query('etapa');
        if (! is_string($etapa) || ! in_array(trim($etapa), AudienciasMetaService::ETAPAS, true)) {
            return response()->json([
                'error'          => 'La etapa "' . (is_string($etapa) ? $etapa : '') . '" no existe.',
                'etapas_validas' => AudienciasMetaService::ETAPAS,
                'descripciones'  => AudienciasMetaService::descripciones(),
            ], 422);
        }
        $etapa = trim($etapa);

        $dias_crudo = $request->query('dias');
        $dias       = AudienciasMetaService::DIAS_POR_DEFECTO;
        if ($dias_crudo !== null && $dias_crudo !== '') {
            if (! is_string($dias_crudo) || ! ctype_digit($dias_crudo)
                || (int) $dias_crudo < 1 || (int) $dias_crudo > AudienciasMetaService::DIAS_MAXIMO
            ) {
                return response()->json([
                    'error' => '`dias` tiene que ser un entero entre 1 y ' . AudienciasMetaService::DIAS_MAXIMO . '.',
                ], 422);
            }
            $dias = (int) $dias_crudo;
        }

        $formato = $request->query('formato');
        $formato = is_string($formato) && trim($formato) !== '' ? strtolower(trim($formato)) : 'json';
        if (! in_array($formato, ['json', 'csv'], true)) {
            return response()->json([
                'error'            => 'El formato "' . $formato . '" no existe.',
                'formatos_validos' => ['json', 'csv'],
            ], 422);
        }

        $filas = $service->filas($etapa, $dias);

        if ($formato === 'csv') {
            $archivo = 'audiencia-' . $etapa . '-' . AppTime::now()->format('Ymd-His') . '.csv';

            return response($service->a_csv($filas), 200, [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $archivo . '"',
            ]);
        }

        $descripciones = AudienciasMetaService::descripciones();

        return response()->json([
            'etapa'       => $etapa,
            'dias'        => $dias,
            'cantidad'    => count($filas),
            'filas'       => $filas,
            'descripcion' => $descripciones[$etapa],
        ], 200);
    }
}
