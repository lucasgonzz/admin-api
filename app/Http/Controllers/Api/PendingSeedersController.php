<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PendingSeedersService;
use Illuminate\Http\JsonResponse;

/**
 * Chequeo y ejecución de seeders pendientes desde el panel de admin.
 *
 * GET  /api/admin/pending-seeders      → lista de seeders no ejecutados
 * POST /api/admin/pending-seeders/run  → ejecuta todos los pendientes
 */
class PendingSeedersController extends Controller
{
    /**
     * Devuelve la lista de seeders que aún no han sido ejecutados en producción.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        /* Obtiene todos los seeders cuya condición is_pending devuelve true. */
        $pending = PendingSeedersService::get_pending();

        return response()->json([
            'pending' => $pending,
            'count'   => count($pending),
        ]);
    }

    /**
     * Ejecuta todos los seeders pendientes y devuelve el resultado de cada uno.
     *
     * @return JsonResponse
     */
    public function run(): JsonResponse
    {
        /* Ejecuta cada seeder pendiente y recopila resultados individuales. */
        $results = PendingSeedersService::run_all_pending();

        /* Cuenta cuántos seeders terminaron con éxito y cuántos fallaron. */
        $ok_count    = count(array_filter($results, function ($r) { return $r['status'] === 'ok'; }));
        $error_count = count(array_filter($results, function ($r) { return $r['status'] === 'error'; }));

        return response()->json([
            'results'     => $results,
            'ok_count'    => $ok_count,
            'error_count' => $error_count,
        ]);
    }
}
