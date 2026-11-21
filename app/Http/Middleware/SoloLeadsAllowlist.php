<?php

namespace App\Http\Middleware;

use App\Services\AdminSoloLeadsPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Middleware `admin.solo_leads` (misión embudo-whatsapp-precios, etapa E5): hace cumplir el perfil
 * «Sólo Leads» en el servidor.
 *
 * Va DESPUÉS de autenticar (`['auth:sanctum', 'admin.solo_leads']`, `['auth', 'admin.solo_leads']`), para que el
 * usuario ya esté resuelto, y cuelga de TODA ruta autenticada: un test recorre `Route::getRoutes()` y rompe si
 * una ruta autenticada nueva se olvida de él (así queda cerrada para el perfil por defecto).
 *
 * Comportamiento:
 * - Sin usuario o sin el perfil: deja pasar sin tocar nada (un admin normal no nota cambio alguno).
 * - Con el perfil: si la ruta no está en la lista blanca de `AdminSoloLeadsPolicy`, responde 403 con
 *   `{"message", "code": "solo_leads"}` y no ejecuta el controlador (cero efectos); si la ruta tiene campos
 *   editables (`PUT lead/{id}`), descarta del pedido todo lo que no sea esos campos y sigue.
 *
 * No decide nada por su cuenta: la lista, las restricciones y los campos viven sólo en la política.
 */
class SoloLeadsAllowlist
{
    /**
     * Política con la lista blanca del perfil.
     *
     * @var AdminSoloLeadsPolicy
     */
    private $policy;

    /**
     * @param AdminSoloLeadsPolicy $policy Política del perfil (se resuelve por el contenedor).
     */
    public function __construct(AdminSoloLeadsPolicy $policy)
    {
        $this->policy = $policy;
    }

    /**
     * Aplica el perfil al pedido.
     *
     * @param Request $request Pedido ya autenticado.
     * @param Closure $next    Siguiente eslabón.
     *
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $usuario = $request->user();

        // Primera línea: quien no tiene el perfil pasa de largo, sin tocar el pedido.
        if (! $this->policy->tiene_perfil($usuario)) {
            return $next($request);
        }

        $ruta = $request->route();

        // Uri con marcadores y parámetros de la ruta resuelta; sin ruta (no debería pasar) se cierra.
        $uri        = $ruta ? $ruta->uri() : '';
        $parametros = $ruta ? $ruta->parameters() : [];
        $metodo     = $request->getMethod();

        if ($ruta === null || ! $this->policy->permite($metodo, $uri, $parametros)) {
            Log::warning('Perfil Sólo Leads: pedido fuera de la lista permitida', [
                'admin_id' => $usuario->id,
                'metodo'   => $metodo,
                'uri'      => $uri,
            ]);

            return response()->json([
                'message' => AdminSoloLeadsPolicy::MENSAJE_DE_RESPUESTA,
                'code'    => AdminSoloLeadsPolicy::CODIGO_DE_RESPUESTA,
            ], 403);
        }

        // Escritura acotada (estado y nota del lead): se descarta el resto del cuerpo, no se rechaza. El
        // endpoint sólo pisa las claves presentes, así que lo que no viene queda como estaba. También se
        // filtran la query y los archivos: el controlador lee de las tres fuentes.
        if ($this->policy->tiene_campos_editables($metodo, $uri)) {
            $request->replace($this->policy->filtrar_cuerpo($metodo, $uri, $request->input()));
            $request->query->replace([]);
            $request->files->replace([]);
        }

        return $next($request);
    }
}
