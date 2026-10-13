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
 *     etapa → conversaron_sin_agendar | agendaron_sin_entrar | agendaron | clientes
 *     dias  → 0..365, default 14; 0 = sin límite de tiempo (solo conversaron_sin_agendar la usa)
 *     JSON  → {etapa, dias, cantidad, filas: [{phone_sha256, email_sha256, phone_sha256_sin_9}],
 *              descripcion, descartados_por_telefono}
 *     CSV   → encabezado `phone,email`, los dos ya hasheados; un celular argentino sale en dos
 *              filas (con y sin el 9, el mail repetido)
 *     422   → con `etapas_validas` si la etapa no existe
 *
 * Todo lo que se sumó después del primer contrato (`phone_sha256_sin_9`, la etapa `agendaron`,
 * `dias=0`, `descartados_por_telefono`) es agregado: nada se renombró ni se sacó.
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
            /* 0 vale: «sin límite de tiempo» (M6b lo usa para «escribieron alguna vez»). */
            if (! is_string($dias_crudo) || ! ctype_digit($dias_crudo)
                || (int) $dias_crudo > AudienciasMetaService::DIAS_MAXIMO
            ) {
                return response()->json([
                    'error' => '`dias` tiene que ser un entero entre 0 (sin límite) y ' . AudienciasMetaService::DIAS_MAXIMO . '.',
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

        $audiencia = $service->audiencia($etapa, $dias);
        $filas     = $audiencia['filas'];

        if ($formato === 'csv') {
            $archivo = 'audiencia-' . $etapa . '-' . AppTime::now()->format('Ymd-His') . '.csv';

            return response($service->a_csv($filas), 200, [
                'Content-Type'                => 'text/csv; charset=UTF-8',
                'Content-Disposition'         => 'attachment; filename="' . $archivo . '"',
                /* El CSV no tiene dónde decirlo: va en un header, igual que en el JSON. */
                'X-Descartados-Por-Telefono'  => (string) $audiencia['descartados_por_telefono'],
            ]);
        }

        $descripciones = AudienciasMetaService::descripciones();

        return response()->json([
            'etapa'                    => $etapa,
            'dias'                     => $dias,
            'cantidad'                 => count($filas),
            'filas'                    => $filas,
            'descripcion'              => $descripciones[$etapa],
            /* Contactos con un teléfono cargado que no encaja en ningún formato: no se adivina, se
               deja afuera (si tienen mail, entran por el mail). */
            'descartados_por_telefono' => $audiencia['descartados_por_telefono'],
        ], 200);
    }
}
