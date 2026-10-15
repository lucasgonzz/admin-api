<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Sesión OPCIONAL del centro de recursos (`recursos.sesion.opcional`): si el pedido trae un token del
 * centro válido deja al usuario en el request, igual que `RecursosSesion`; si no trae o no sirve,
 * sigue como invitado (decisión de Lucas, 13/10/2026: sin sesión el video se ve).
 *
 * Un token vencido NO da 401 acá: la página lo detecta en la primera ruta con sesión obligatoria
 * (`recursos/yo`), lo borra y sigue como invitado. Cortar un catálogo público por un token viejo
 * sería dejar sin videos a alguien que podía verlos sin entrar.
 */
class RecursosSesionOpcional extends RecursosSesion
{
    /**
     * @param Request $request
     * @param Closure $next
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next)
    {
        $this->resolver($request);

        return $next($request);
    }
}
