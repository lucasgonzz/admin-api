<?php

namespace App\Http\Middleware;

use App\Models\RecursosSesion as RecursosSesionModelo;
use Closure;
use Illuminate\Http\Request;

/**
 * Corta con 403 las rutas del centro de recursos que ESCRIBEN cuando la sesión es de solo lectura
 * (`recursos.escritura`; misión centro-recursos-soporte-y-tema, 14/10/2026).
 *
 * Una sesión de solo lectura la abre un pase de login maestro (soporte entrando como el dueño): se ve
 * el avance del dueño, pero no se guarda nada a su nombre —ni avance, ni votos, ni preguntas, ni me
 * gusta, ni encuesta—. La página ya no manda esos pedidos en ese modo; esto es la reja del servidor,
 * por si algo se escapa.
 *
 * 🔴 Va DESPUÉS de `recursos.sesion`: necesita la sesión ya resuelta en el request
 * (`RecursosSesion::ATRIBUTO_SESION`). Sin sesión no decide nada y deja pasar —el 401 ya lo dio el
 * middleware de sesión—. `yo` y `salir` NO pasan por acá: soporte puede leer y puede salir.
 */
class RecursosEscritura
{
    /**
     * @param Request $request
     * @param Closure $next
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next)
    {
        $sesion = $request->attributes->get(RecursosSesion::ATRIBUTO_SESION);

        if ($sesion instanceof RecursosSesionModelo && $sesion->solo_lectura) {
            return response()->json(['error' => 'solo_lectura'], 403);
        }

        return $next($request);
    }
}
