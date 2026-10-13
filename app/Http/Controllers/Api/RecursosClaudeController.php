<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RecursosEncuesta;
use App\Models\RecursosPregunta;
use App\Models\RecursosProgreso;
use App\Models\RecursosUsuario;
use App\Models\RecursosVideo;
use App\Models\RecursosVoto;
use App\Services\RecursosCatalogoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El centro de recursos para Claude (misión centro-de-recursos-clientes, 13/10/2026), bajo
 * `claude.task.key`:
 *
 *   POST claude/recursos/catalogo — carga el catálogo (lo manda `publicar_centro.py` después de subir
 *                                   los videos a R2). Upsert por `codigo`; con `desactivar_faltantes`
 *                                   los activos que no vinieron quedan inactivos (nunca se borran).
 *   GET  claude/recursos/resumen  — los totales del centro, para mirarlo sin entrar al panel.
 *
 * La carga NO toca el sistema de ningún cliente: escribe filas del admin y borra la caché del
 * catálogo público.
 */
class RecursosClaudeController extends Controller
{
    /**
     * Claves que acepta la carga. Cualquier otra es 422: un nombre mal escrito
     * (`desactivar_faltante`) no se ignora en silencio.
     */
    const CLAVES = ['carpetas', 'videos', 'desactivar_faltantes'];

    /**
     * POST claude/recursos/catalogo — `{carpetas, videos, desactivar_faltantes}` →
     * `{creados, actualizados, sin_cambios, desactivados}`.
     *
     * @param Request                 $request
     * @param RecursosCatalogoService $catalogo
     *
     * @return JsonResponse
     */
    public function catalogo_json(Request $request, RecursosCatalogoService $catalogo): JsonResponse
    {
        $sobrantes = array_values(array_diff(array_keys($request->all()), self::CLAVES));

        if ($sobrantes !== []) {
            return response()->json([
                'error'   => 'datos_invalidos',
                'errores' => ['parametros' => 'No se aceptan: ' . implode(', ', $sobrantes) . '. Los parámetros son: ' . implode(', ', self::CLAVES) . '.'],
            ], 422);
        }

        $desactivar = $request->input('desactivar_faltantes', false);

        if (! is_bool($desactivar) && ! in_array($desactivar, [0, 1, '0', '1'], true)) {
            return response()->json([
                'error'   => 'datos_invalidos',
                'errores' => ['desactivar_faltantes' => 'Tiene que ser true o false.'],
            ], 422);
        }

        $validado = $catalogo->validar($request->input('carpetas'), $request->input('videos'));

        if ($validado['errores'] !== []) {
            return response()->json([
                'error'   => 'datos_invalidos',
                'errores' => $validado['errores'],
            ], 422);
        }

        return response()->json($catalogo->cargar($validado['videos'], (bool) $desactivar));
    }

    /**
     * GET claude/recursos/resumen — totales del centro.
     *
     * @return JsonResponse
     */
    public function resumen_json(): JsonResponse
    {
        $hace_7_dias = now()->subDays(7);

        return response()->json([
            'videos' => [
                'activos'   => RecursosVideo::where('activo', true)->count(),
                'inactivos' => RecursosVideo::where('activo', false)->count(),
            ],
            'usuarios' => [
                'total'           => RecursosUsuario::count(),
                'duenos'          => RecursosUsuario::where('es_dueno', true)->count(),
                'clientes'        => RecursosUsuario::distinct()->count('client_id'),
                'activos_7_dias'  => RecursosUsuario::where('ultimo_ingreso_at', '>=', $hace_7_dias)->count(),
            ],
            'vistas' => [
                'videos_empezados'  => RecursosProgreso::count(),
                'videos_terminados' => RecursosProgreso::whereNotNull('terminado_at')->count(),
            ],
            'votos' => [
                'positivos' => RecursosVoto::where('valor', 1)->count(),
                'negativos' => RecursosVoto::where('valor', -1)->count(),
            ],
            'encuestas' => RecursosEncuesta::count(),
            'preguntas' => [
                'pendientes'  => RecursosPregunta::where('oculta', false)->whereNull('respuesta')->count(),
                'respondidas' => RecursosPregunta::where('oculta', false)->whereNotNull('respuesta')->count(),
                'ocultas'     => RecursosPregunta::where('oculta', true)->count(),
            ],
        ]);
    }
}
