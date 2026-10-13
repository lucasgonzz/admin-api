<?php

namespace App\Http\Middleware;

use App\Models\RecursosSesion as RecursosSesionModelo;
use Closure;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Limitador de las rutas del centro de recursos (`recursos.throttle:<max>,<minutos>,<cubeta>,<por>`).
 *
 * Uso: `recursos.throttle:60,1,catalogo,ip` (60 por minuto por IP en la cubeta "catalogo") o
 * `recursos.throttle:60,1,escritura,token` (60 por minuto por token del centro).
 *
 * POR QUÉ un limitador propio y no `throttle:60,1`:
 *  - El de Laravel arma la firma con `$request->user()`, y el usuario del centro a propósito NO está
 *    ahí (ver `RecursosSesion`): sin esto no habría forma de limitar "por token".
 *  - Las cubetas tienen que ser distintas por grupo de rutas: con `throttle:60,1` pelado todas las
 *    rutas por IP comparten la misma, y abrir la página (catálogo + video + yo) se comería el cupo
 *    de la sesión. El nombre de la cubeta va en el prefijo de la clave.
 *  - Los limitadores con nombre (`RateLimiter::for`) viven en `RouteServiceProvider`, que esta
 *    misión no toca, y definirlos en `routes/api.php` no sobrevive a un `route:cache`.
 *
 * Por token se cuenta sobre el sha256 del token (el token en claro no va a la caché), y solo si es de
 * una sesión vigente. Un pedido sin token, o con uno que no es de ninguna sesión vigente, en una ruta
 * "por token" cae en la cubeta de su IP; igual la sesión obligatoria lo corta con 401 después.
 */
class RecursosThrottle extends ThrottleRequests
{
    /**
     * Cómo se arma la firma en este pedido: 'ip' o 'token'.
     *
     * @var string
     */
    protected $por = 'ip';

    /**
     * @param \Illuminate\Http\Request $request
     * @param Closure                  $next
     * @param int|string               $maxAttempts
     * @param float|int|string         $decayMinutes
     * @param string                   $prefix       Nombre de la cubeta.
     * @param string                   $por          'ip' | 'token'.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '', $por = 'ip')
    {
        $this->por = $por === 'token' ? 'token' : 'ip';

        return parent::handle($request, $next, $maxAttempts, $decayMinutes, 'recursos|' . $prefix . '|');
    }

    /**
     * La firma del pedido: el token del centro (hasheado) o la IP.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return string
     */
    protected function resolveRequestSignature($request)
    {
        if ($this->por === 'token') {
            $token = RecursosSesion::token_del_pedido($request);

            /* 🔴 Solo un token que es de una sesión VIGENTE tiene cubeta propia. Si cualquier texto en el
               header armara su propia cubeta, inventar un token distinto en cada pedido sería tener cupo
               infinito; esos caen en la cubeta de su IP, como un pedido sin token. */
            if ($token !== '' && self::sesion_vigente($token)) {
                return sha1('token|' . hash('sha256', $token));
            }
        }

        return sha1('ip|' . $request->ip());
    }

    /**
     * Si el token es de una sesión del centro que no venció.
     *
     * @param string $token
     *
     * @return bool
     */
    protected static function sesion_vigente(string $token): bool
    {
        return RecursosSesionModelo::where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->exists();
    }
}
