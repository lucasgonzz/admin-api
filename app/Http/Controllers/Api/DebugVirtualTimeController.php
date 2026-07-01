<?php

namespace App\Http\Controllers\Api;

use App\Helpers\AppTime;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Endpoints REST para controlar el tiempo virtual de debug (solo entorno local).
 * En producción cada acción responde 404 vía abort_unless.
 */
class DebugVirtualTimeController extends Controller
{
    /**
     * Estado actual: si hay override, valor virtual y hora real de referencia.
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        abort_unless(config('app.env') === 'local', 404);

        $virtual = AppTime::get_virtual();
        $real    = Carbon::now('America/Argentina/Buenos_Aires')->format('Y-m-d H:i:s');

        return response()->json([
            'is_active'    => AppTime::is_active(),
            'virtual_time' => $virtual,
            'real_time'    => $real,
        ]);
    }

    /**
     * Fija el tiempo virtual a partir del body `datetime`.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function set(Request $request): JsonResponse
    {
        abort_unless(config('app.env') === 'local', 404);

        $datetime = $request->input('datetime');
        if (!$datetime) {
            return response()->json(['error' => 'datetime requerido'], 422);
        }

        // Validar que el formato sea parseable antes de persistir.
        try {
            Carbon::parse($datetime);
        } catch (\Exception $e) {
            return response()->json(['error' => 'formato de datetime inválido'], 422);
        }

        AppTime::set_virtual($datetime);

        return response()->json([
            'success'      => true,
            'virtual_time' => AppTime::get_virtual(),
        ]);
    }

    /**
     * Limpia el override y vuelve al reloj del sistema.
     *
     * @return JsonResponse
     */
    public function clear(): JsonResponse
    {
        abort_unless(config('app.env') === 'local', 404);

        AppTime::clear_virtual();

        return response()->json(['success' => true]);
    }
}
