<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;

/**
 * Middleware `admin.solo_agente` (misión wa-personal-coexistencia): deja pasar únicamente a un `Admin` con
 * `es_agente = true` (Claude Code, OpenClaw por el MCP).
 *
 * Un humano, un admin con el perfil «Sólo Leads» o un token sin ese flag recibe **404**, como si la ruta no
 * existiera: no se admite que hay un WhatsApp personal. Va ANTES de `admin.solo_leads` para que un `solo_leads`
 * también reciba 404 y no un 403 que delate la ruta, y DESPUÉS de autenticar.
 */
class SoloAgenteDelPanel
{
    /**
     * Corta con 404 todo lo que no sea un agente.
     *
     * @param Request $request Pedido ya autenticado.
     * @param Closure $next    Siguiente eslabón.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $usuario = $request->user();

        if (! $usuario instanceof Admin || ! (bool) $usuario->es_agente) {
            return response()->json(['message' => 'Not Found.'], 404);
        }

        return $next($request);
    }
}
